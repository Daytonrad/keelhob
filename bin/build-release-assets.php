<?php
/**
 * Build and sign the two skill-feed assets for a GitHub release.
 *
 * Produces build/skills.json (the manifest sites poll) and
 * build/skills-bundle.json (every skill body, fetched only when the manifest
 * says something moved). Both are signed envelopes:
 *
 *     {"payload": "<json string>", "signature": "<base64 detached Ed25519>"}
 *
 * The signature covers the payload string byte for byte, which is why the
 * payload travels as a string and is never re-encoded on the way in or out.
 *
 * Usage:
 *   php bin/build-release-assets.php
 *
 * The private key is read from SKILLS_SIGNING_SECRET_B64, taken from the
 * environment first (so CI can inject it) and from .env only as a fallback.
 * It is never printed, and it never reaches the output files.
 */

declare(strict_types=1);

$root  = dirname(__DIR__);
$out   = $root . '/build';
$fail  = static function (string $msg): void { fwrite(STDERR, "ERROR: $msg\n"); exit(1); };

if (!extension_loaded('sodium')) {
    $fail('This PHP has no sodium extension, so nothing can be signed.');
}

// ---- the key ------------------------------------------------------------

$secret_b64 = (string) getenv('SKILLS_SIGNING_SECRET_B64');
if ($secret_b64 === '' && is_file($root . '/.env')) {
    foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^SKILLS_SIGNING_SECRET_B64=(.*)$/', trim($line), $m)) {
            $secret_b64 = trim($m[1], "\"' ");
        }
    }
}
if ($secret_b64 === '') {
    $fail('No SKILLS_SIGNING_SECRET_B64 in the environment or .env. Cannot sign.');
}

$secret = base64_decode($secret_b64, true);
if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    $fail('SKILLS_SIGNING_SECRET_B64 is not a 64-byte Ed25519 secret key.');
}

// The build must fail rather than ship assets signed with a key no installed
// copy trusts. A wrong key here is invisible until every site has stopped
// receiving skills, so it is checked at the only moment it is still cheap.
$remote = file_get_contents($root . '/includes/class-skills-remote.php');
if (!preg_match("/PUBLIC_KEY_B64\s*=\s*'([^']+)'/", (string) $remote, $m)) {
    $fail('Could not read PUBLIC_KEY_B64 out of includes/class-skills-remote.php.');
}
$embedded = $m[1];
$derived  = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
if (!hash_equals($embedded, $derived)) {
    $fail(
        "The signing key does not match the public key shipped in the plugin.\n"
        . "       plugin trusts: $embedded\n"
        . "       this key gives: $derived\n"
        . '       Signing with it would break skill updates on every install.'
    );
}

// ---- version ------------------------------------------------------------

$boot = (string) file_get_contents($root . '/claudpress.php');
if (!preg_match("/define\('CLAUDPRESS_VERSION',\s*'([^']+)'\)/", $boot, $m)) {
    $fail('Could not read CLAUDPRESS_VERSION out of claudpress.php.');
}
$version = $m[1];

// ---- the skills ---------------------------------------------------------

// The real class, not a copy of its rule. A body the client would refuse
// must never be built into a bundle, or the failure shows up only as
// skills that quietly never update on anybody's site. Two copies of that
// rule would drift, and the direction it drifts is towards shipping a
// bundle nobody can install. The class loads standalone: the checks used
// here are pure static functions and touch nothing in WordPress.
if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}
require_once $root . '/includes/class-skills-remote.php';

$is_markdown_safe = static function (string $body): bool {
    return Claudpress_Skills_Remote::is_markdown_safe($body);
};

$files = glob($root . '/skills/*.md');
sort($files);
if (!$files) {
    $fail('No skills found in skills/.');
}

$manifest = [];
$bundle   = [];
$rejected = [];

foreach ($files as $file) {
    $body = (string) file_get_contents($file);
    $body = str_replace("\r\n", "\n", $body);
    $name = basename($file, '.md');
    $desc = '';

    if (preg_match('/^---\n(.*?)\n---\n/s', $body, $fm)) {
        if (preg_match('/^name:\s*(.+)$/m', $fm[1], $n)) {
            $name = trim($n[1]);
        }
        if (preg_match('/^description:\s*(.+)$/m', $fm[1], $d)) {
            $desc = trim($d[1]);
        }
    }

    if (!$is_markdown_safe($body)) {
        $rejected[] = basename($file);
        continue;
    }

    $sha = hash('sha256', $body);

    $manifest[] = [
        'name'        => $name,
        'version'     => $version,
        'sha256'      => $sha,
        'description' => $desc,
        'available'   => true,
    ];
    $bundle[] = [
        'name'    => $name,
        'version' => $version,
        'sha256'  => $sha,
        'body'    => $body,
    ];
}

if ($rejected) {
    $fail(
        "These skills would be refused by is_markdown_safe() on every site,\n"
        . "       so building them into a bundle would ship a silent failure:\n\n"
        . '         - ' . implode("\n         - ", $rejected) . "\n\n"
        . '       Fix the bodies or relax the check, then build again.'
    );
}

// ---- sign and write -----------------------------------------------------

$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

$write = static function (string $path, array $data) use ($secret, $flags, $embedded, $fail): int {
    $payload   = json_encode($data, $flags);
    $signature = base64_encode(sodium_crypto_sign_detached($payload, $secret));

    // Verify what is about to be written, with the public key rather than the
    // private one, so this proves the thing a site will actually do.
    $pub = base64_decode($embedded, true);
    if (!sodium_crypto_sign_verify_detached(base64_decode($signature, true), $payload, $pub)) {
        $fail('Self-check failed: the signature just written does not verify.');
    }

    $envelope = json_encode(['payload' => $payload, 'signature' => $signature], $flags);
    if (file_put_contents($path, $envelope) === false) {
        $fail("Could not write $path");
    }
    return strlen($envelope);
};

if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
    $fail("Could not create $out");
}

$a = $write($out . '/skills.json', ['skills' => $manifest]);
$b = $write($out . '/skills-bundle.json', ['skills' => $bundle]);

printf("Signed %d skills at version %s.\n\n", count($bundle), $version);
printf("  build/skills.json         %7s bytes\n", number_format($a));
printf("  build/skills-bundle.json  %7s bytes\n", number_format($b));
printf("\nBoth verify against the key in the plugin (%s).\n", substr($embedded, 0, 12) . '...');
echo "Upload both to the GitHub release as assets.\n";
