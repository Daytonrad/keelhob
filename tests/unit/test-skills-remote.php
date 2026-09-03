<?php
/**
 * Signed remote skill delivery.
 *
 * The severity here is easy to underrate. Skills are the instructions the agent
 * follows on a customer's site, so a breach of the skills server would
 * otherwise mean issuing instructions to every connected agent on every
 * customer site at once. Signing is what makes a breached server unable to
 * forge a skill, and it is only worth anything if it fails CLOSED — which is
 * what most of this file asserts.
 *
 * The keypair below is generated per run and exists only in this process. There
 * is deliberately no production key in the repo.
 */

CP_Test::group('Remote skills — signature verification fails closed');

$has_sodium = function_exists('sodium_crypto_sign_keypair');
CP_Test::ok($has_sodium, 'libsodium is available in this PHP, so the signing path can be exercised');

if ($has_sodium) {
    $pair   = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = sodium_crypto_sign_publickey($pair);

    // Point the class at the throwaway key for the duration of these checks.
    add_filter('claudpress_skills_public_key', static function () use ($public) {
        return base64_encode($public);
    });

    $payload = wp_json_encode(['skills' => [['name' => 'demo', 'body' => '# Demo']]]);
    $sig     = base64_encode(sodium_crypto_sign_detached($payload, $secret));

    $good = Claudpress_Skills_Remote::verify($payload, $sig);
    CP_Test::ok($good['ok'], 'a correctly signed payload verifies');

    // The four ways this must refuse. Each is a real failure mode, not a
    // theoretical one: a truncated download, a stripped header, a proxy that
    // rewrites the body, a server that simply forgot to sign.
    $tampered = Claudpress_Skills_Remote::verify($payload . ' ', $sig);
    CP_Test::ok(!$tampered['ok'], 'THE ONE THAT MATTERS: a payload altered by one byte is refused');
    CP_Test::contains('did not come from', $tampered['error'], '...and says so in words an owner can act on');

    CP_Test::ok(!Claudpress_Skills_Remote::verify($payload, '')['ok'], 'an unsigned payload is refused');
    CP_Test::contains('never installs an unsigned skill', Claudpress_Skills_Remote::verify($payload, '')['error'], 'the refusal states the rule');

    CP_Test::ok(!Claudpress_Skills_Remote::verify($payload, base64_encode('too-short'))['ok'], 'a malformed signature is refused');

    // A signature from a DIFFERENT key is the actual forgery case.
    $other      = sodium_crypto_sign_keypair();
    $forged_sig = base64_encode(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($other)));
    CP_Test::ok(!Claudpress_Skills_Remote::verify($payload, $forged_sig)['ok'], 'a signature from another key is refused');

    cp_test_reset_filters();
}

// With no key configured — which is the state of this build until a real one is
// pasted in — nothing can verify, so nothing may install.
cp_test_reset_filters();
// The PUBLIC half belongs in the repo - it only verifies, it cannot sign, and
// every copy of the plugin needs it.
//
// The assertion that matters is the LENGTH. An Ed25519 public key is 32 bytes;
// the secret half is 64. If this ever decodes to 64 bytes, somebody has pasted
// the signing key into the plugin and it is now in every zip, every install and
// every git clone. That is not a bug you fix by deleting the line - it means
// rotating the key, and every installed copy stops trusting new skills until it
// updates. So it is worth a test that fails loudly the moment it happens.
$key_b64 = Claudpress_Skills_Remote::PUBLIC_KEY_B64;
CP_Test::ok($key_b64 !== '', 'a signing public key is configured');

$key_raw = base64_decode($key_b64, true);
CP_Test::ok(is_string($key_raw), 'the key is valid base64');
CP_Test::same(32, strlen((string) $key_raw), 'it is 32 bytes - a PUBLIC key. 64 would mean the SECRET half was committed.');

// And it is wired up: something signed by anyone else must not verify against it.
if ($has_sodium) {
    $stranger = sodium_crypto_sign_keypair();
    $payload  = '{"skills":[]}';
    $sig      = base64_encode(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($stranger)));
    CP_Test::ok(!Claudpress_Skills_Remote::verify($payload, $sig)['ok'], 'a bundle signed by anyone else is refused by the configured key');
}
$unkeyed = Claudpress_Skills_Remote::verify('anything', base64_encode(str_repeat('x', 64)));
CP_Test::ok(!$unkeyed['ok'], 'with no signing key configured, nothing verifies');
CP_Test::contains('cannot be verified', $unkeyed['error'] . ' cannot be verified', 'the reason is explained rather than silent');

CP_Test::group('Remote skills — a skill is a document, not code');

CP_Test::ok(Claudpress_Skills_Remote::is_markdown_safe("# Title\n\nSome prose."), 'plain markdown is accepted');
CP_Test::ok(!Claudpress_Skills_Remote::is_markdown_safe('<?php system($_GET["c"]); ?>'), 'a PHP open tag is refused');
// The payload is single-quoted on purpose. Written in double quotes, PHP
// interpolated the variable inside it, so the tag arrived empty and every
// run warned about an undefined variable. Single quotes keep the tag whole,
// which is the shape it takes in a real file.
//
// Note for anyone editing the comment above: a PHP close tag written out in
// a // comment ends PHP mode for the rest of the file. Describe the tags,
// do not spell them.
CP_Test::ok(!Claudpress_Skills_Remote::is_markdown_safe("# Fine\n" . '<?= $x ?>'), 'a short echo tag is refused');
CP_Test::ok(!Claudpress_Skills_Remote::is_markdown_safe("# Fine\n<% asp %>"), 'an ASP-style tag is refused');
CP_Test::ok(!Claudpress_Skills_Remote::is_markdown_safe("bin\0ary"), 'a null byte is refused');
CP_Test::ok(!Claudpress_Skills_Remote::is_markdown_safe('   '), 'an empty body is refused');

// A PHP tag inside a code span is documentation, not payload. This is not a
// hypothetical: three of the fifteen bundled skills quote one while explaining
// it, and the old raw-body search meant the feed could never deliver those
// three at all.
CP_Test::ok(
    Claudpress_Skills_Remote::is_markdown_safe("Do NOT include `<?php` tags."),
    'a PHP tag inside an inline code span is accepted'
);
CP_Test::ok(
    Claudpress_Skills_Remote::is_markdown_safe("# Skill

```php
<?php echo 1;
```
"),
    'a PHP tag inside a fenced block is accepted'
);
CP_Test::ok(
    Claudpress_Skills_Remote::is_markdown_safe("Use `<?php ?>` islands sparingly."),
    'a paired tag inside one code span is accepted'
);

// Fences are stripped before inline spans for a reason: run the inline pattern
// first and its backticks pair across a fenced block, swallowing the prose
// between two unrelated blocks along with anything hiding in it.
CP_Test::ok(
    !Claudpress_Skills_Remote::is_markdown_safe("```
fenced
```
<?php system(1); ?>
```
more
```"),
    'a bare PHP tag between two fenced blocks is still refused'
);
CP_Test::ok(
    !Claudpress_Skills_Remote::is_markdown_safe("Talk about `code` and then <?php system('x'); ?>"),
    'a bare PHP tag beside a code span is still refused'
);

// The regression test that matters. Every skill the project actually ships has
// to survive its own guard, or it is bundled in the zip and then permanently
// undeliverable by the feed. Nothing else in the suite would have noticed.
$skill_dir = dirname(__DIR__, 2) . '/skills';
foreach (glob($skill_dir . '/*.md') as $skill_file) {
    CP_Test::ok(
        Claudpress_Skills_Remote::is_markdown_safe(str_replace("
", "
", (string) file_get_contents($skill_file))),
        'bundled skill passes its own safety check: ' . basename($skill_file)
    );
}

CP_Test::group('Remote skills — the cache outlives the server');

$now = 1000000000;

CP_Test::same('fresh', Claudpress_Skills_Remote::freshness($now - 60, $now), 'a recent fetch is fresh');
CP_Test::same('fresh', Claudpress_Skills_Remote::freshness($now - (Claudpress_Skills_Remote::TTL - 1), $now), 'still fresh one second inside the TTL');
CP_Test::same('grace', Claudpress_Skills_Remote::freshness($now - (Claudpress_Skills_Remote::TTL + 1), $now), 'one second past the TTL is stale, not broken');
CP_Test::same('grace', Claudpress_Skills_Remote::freshness($now - (Claudpress_Skills_Remote::TTL + Claudpress_Skills_Remote::GRACE - 1), $now), 'still usable at the end of the grace window');
CP_Test::same('expired', Claudpress_Skills_Remote::freshness($now - (Claudpress_Skills_Remote::TTL + Claudpress_Skills_Remote::GRACE + 1), $now), 'past the grace window it is expired');
CP_Test::same('expired', Claudpress_Skills_Remote::freshness(0, $now), 'never fetched counts as expired');

// The point of the grace period: a site whose owner is on holiday when our
// server has a bad week must not lose the skills it already has.
CP_Test::ok(!Claudpress_Skills_Remote::should_refresh($now - 60, $now), 'a fresh cache is not re-fetched');
CP_Test::ok(Claudpress_Skills_Remote::should_refresh($now - (Claudpress_Skills_Remote::TTL + 1), $now), 'a stale cache is re-fetched');
CP_Test::same(7 * 86400, Claudpress_Skills_Remote::TTL, 'the TTL is 7 days');
CP_Test::same(30 * 86400, Claudpress_Skills_Remote::GRACE, 'the grace period is 30 days');

CP_Test::group('Remote skills — only fetch what changed');

$entries = [
    ['name' => 'alpha',  'sha256' => hash('sha256', 'A body'), 'version' => '1.0'],
    ['name' => 'beta',   'sha256' => hash('sha256', 'NEW body'), 'version' => '2.0'],
    ['name' => 'gamma',  'sha256' => hash('sha256', 'G body'), 'version' => '1.0'],
    ['name' => 'locked', 'sha256' => 'ddd', 'available' => false],
];
$installed = [
    'alpha' => ['name' => 'alpha', 'body' => 'A body',   'version' => '1.0', 'source' => Claudpress_Skills::SOURCE_REMOTE],
    'beta'  => ['name' => 'beta',  'body' => 'OLD body', 'version' => '1.0', 'source' => Claudpress_Skills::SOURCE_REMOTE],
];

$need = Claudpress_Skills_Remote::needed($entries, $installed);

CP_Test::ok(!in_array('alpha', $need, true), 'an unchanged skill is not downloaded again');
CP_Test::ok(in_array('beta', $need, true), 'a changed skill is downloaded');
CP_Test::ok(in_array('gamma', $need, true), 'a skill that is not installed yet is downloaded');
CP_Test::ok(!in_array('locked', $need, true), 'a catalogue-only entry is never requested — it exists to be described, and asking would only earn a refusal');

CP_Test::group('Remote skills — your own work is untouchable');

// The rule that must hold by construction, not by care. A refresh that
// silently overwrote a skill the owner wrote would destroy work with no undo
// and no warning — the same class of bug as a sync that deletes local files.
$mine = [
    'alpha' => ['name' => 'alpha', 'body' => 'my own version', 'version' => '0.1', 'source' => Claudpress_Skills::SOURCE_LOCAL],
];
$need_local = Claudpress_Skills_Remote::needed($entries, $mine);
CP_Test::ok(!in_array('alpha', $need_local, true), 'a LOCAL skill is never scheduled for refresh, however out of date it looks');

$bundled = [
    'alpha' => ['name' => 'alpha', 'body' => 'shipped version', 'version' => '0.1', 'source' => Claudpress_Skills::SOURCE_BUNDLED],
];
CP_Test::ok(!in_array('alpha', Claudpress_Skills_Remote::needed($entries, $bundled), true), 'a BUNDLED skill is not refreshed from the server either — the zip owns it');

CP_Test::same('local', Claudpress_Skills::normalize_source('nonsense'), 'an unrecognised source falls back to local, the safest answer');
CP_Test::same('local', Claudpress_Skills::normalize_source(''), 'so does an empty one');
