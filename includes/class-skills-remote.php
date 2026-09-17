<?php
/**
 * Signed delivery of skills from the project's GitHub releases.
 *
 * Skills are instructions an agent follows on a live customer site. That makes
 * this the highest-consequence network path in the plugin: a breach of the
 * skills server, without the signature below, would let an attacker instruct
 * every connected agent on every customer site at once — worse than any single
 * bug in the plugin itself. Ed25519 verification is what removes the server
 * from the trust chain. A breached server can serve whatever it likes; it
 * cannot sign it, so nothing it serves is ever written.
 *
 * Three rules hold this together, and none of them is a judgement call at the
 * call site:
 *
 *   1. Fail closed. No signature, no key, no sodium — nothing is installed, and
 *      the owner is told which of those it was. There is no unsigned fallback,
 *      because a fallback is the thing an attacker would aim for.
 *   2. Markdown only. The payload is never executed and never allowed to
 *      contain a PHP open tag. The plugin does not run what it downloads.
 *   3. A refresh only ever touches rows whose source is 'remote'. Overwriting a
 *      skill the owner wrote is the same class of bug as a sync that deletes
 *      local work, so the check lives inside the one method that writes, not in
 *      the callers that are supposed to remember it.
 *
 * Failure is always a notice. If the server is down the site keeps working from
 * the cached skills — a refresh must never block anything.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Skills_Remote
{
    /**
     * Where the skills live: two static assets on the newest GitHub release.
     *
     * This used to be a REST API on a server we ran, which answered
     * /free, /pro and /bundle?names=a,b,c. That server is gone, and static
     * files cannot answer a query string - so the manifest is fetched whole and
     * the filtering that used to happen server-side now happens here. For
     * fifteen markdown files that is cheaper than the API ever was, and it
     * leaves nothing to pay for, keep alive, or breach.
     *
     * Still overridable — by constant for a whole install, by filter for a
     * single site or a test.
     */
    const DEFAULT_ENDPOINT = 'https://github.com/calflint/keelhob/releases/latest/download';

    /** The manifest: one entry per skill, with a version. */
    const MANIFEST_FILE = 'skills.json';

    /** Every skill body in one file. Small enough that splitting it costs more than it saves. */
    const BUNDLE_FILE = 'skills-bundle.json';

    /**
     * The Ed25519 public key that every bundle must verify against, base64.
     *
     * The PUBLIC half of the release signing key. It is meant to be here: it
     * only verifies, it cannot sign, and every copy of the plugin needs it.
     *
     * The secret half is held by the owner and lives only in CI. It must never
     * appear in this repository, in a zip, or in a transcript - a signing key
     * that has been seen is a signing key that has to be rotated, and rotating
     * it means every installed copy stops trusting new skills until it updates.
     */
    const PUBLIC_KEY_B64 = 'lbZ4WzEnv0/vwEx+OGBgXJcSdgTvpLdRWmUiLM9K620=';

    /** How long a cached manifest counts as current. */
    const TTL = 604800; // 7 days

    /** How long past the TTL cached skills are still used rather than mistrusted. */
    const GRACE = 2592000; // 30 days

    const OPTION_CACHE   = 'keelhob_skills_cache';
    const OPTION_HISTORY = 'keelhob_skills_history';

    /** Refreshes kept for the changelog on the Skills screen. */
    const HISTORY_KEEP = 10;

    /**
     * The skills store. Null means "the live one"; tests inject their own.
     *
     * @var object|null
     */
    public static $store = null;

    // ---- configuration ----------------------------------------------------

    public static function endpoint(): string
    {
        $base = defined('KEELHOB_SKILLS_ENDPOINT') ? (string) KEELHOB_SKILLS_ENDPOINT : self::DEFAULT_ENDPOINT;
        return untrailingslashit((string) apply_filters('keelhob_skills_endpoint', $base));
    }

    /** Raw 32-byte verification key, or '' when we have none. */
    public static function public_key(): string
    {
        $b64 = (string) apply_filters('keelhob_skills_public_key', self::PUBLIC_KEY_B64);
        if ($b64 === '') {
            return '';
        }
        $raw = base64_decode($b64, true);
        return (is_string($raw) && strlen($raw) === 32) ? $raw : '';
    }

    private static function store()
    {
        return self::$store ?: Keelhob::instance()->skills;
    }

    // ---- cache freshness (pure) -------------------------------------------

    /**
     * How much the cache can still be trusted.
     *
     * Three states rather than a boolean, because "stale" and "too old to
     * believe" call for different words to the owner and neither of them is
     * "broken". Cached skills keep working right through the grace period.
     *
     * @return string fresh | grace | expired
     */
    public static function freshness(int $fetched_at, int $now): string
    {
        if ($fetched_at <= 0) {
            return 'expired';
        }
        $age = $now - $fetched_at;
        if ($age < self::TTL) {
            return 'fresh';
        }
        return $age < (self::TTL + self::GRACE) ? 'grace' : 'expired';
    }

    /** A scheduled refresh is only worth making once the TTL has passed. */
    public static function should_refresh(int $fetched_at, int $now): bool
    {
        return self::freshness($fetched_at, $now) !== 'fresh';
    }

    // ---- payload checks (pure) --------------------------------------------

    /**
     * Is this body plain markdown we would be willing to store?
     *
     * The plugin never executes a downloaded skill, so this is defence in depth
     * rather than the only line — but a skill body ends up in the database, on
     * an admin screen, and inside an agent's context, and a PHP open tag in any
     * of those is a smell that means the payload is not what it claims to be.
     * Refusing the whole bundle is cheaper than reasoning about where it lands.
     *
     * Code spans are cut out before that search, because this is a WordPress
     * plugin whose skills teach WordPress: three of the fifteen bundled ones
     * quote a PHP open tag while explaining it, and they are documentation, not
     * payload. Searching the raw body rejected all three, which meant they
     * could never be delivered by the feed at all. A guard that blocks the
     * project's own correct content is measuring the wrong thing.
     *
     * A hostile payload can of course wrap a tag in backticks to get past this.
     * That buys it nothing: the body is still never executed, and the Ed25519
     * signature is what actually keeps strangers' content out. This check earns
     * its place by catching a file that is honestly the wrong kind of file, not
     * by holding a line against someone trying.
     */
    public static function is_markdown_safe(string $body): bool
    {
        if (trim($body) === '') {
            return false;
        }
        foreach (['<?php', '<?=', '<?', '<%'] as $tag) {
            if (stripos(self::without_code_spans($body), $tag) !== false) {
                return false;
            }
        }
        // A skill is a document. Anything binary arriving here is not one.
        return preg_match('//u', $body) === 1 && strpos($body, "\0") === false;
    }

    /**
     * The body with fenced blocks and inline code spans removed.
     *
     * Fences go first: an inline pattern run against a document that still
     * has them matches across their contents and eats prose that was never
     * inside a code span at all.
     */
    private static function without_code_spans(string $body): string
    {
        // Windows line endings first. A closing fence on a CRLF line ends in a
        // carriage return, which sits between the backticks and the end of the
        // line and stops the fence pattern closing at all - so the block is
        // never recognised, never stripped, and a skill written on Windows is
        // judged on its raw source. Cheaper to normalise a copy than to carry
        // a carriage return through every pattern below.
        $body = str_replace("\r\n", "\n", $body);

        $patterns = [
            '/^[ \t]*(`{3,}|~{3,})[^\n]*\n.*?^[ \t]*\1[ \t]*$/ms',
            '/(`+)(?:(?!\1).)*\1/s',
        ];
        foreach ($patterns as $pattern) {
            $stripped = preg_replace($pattern, ' ', $body);
            // A catastrophic backtrack returns null. Falling back to the raw
            // body keeps the guard strict rather than quietly switching it
            // off, which is the right direction for this to fail in.
            $body = is_string($stripped) ? $stripped : $body;
        }
        return $body;
    }

    /**
     * Verify a detached Ed25519 signature over the exact bytes we were sent.
     *
     * Over the raw payload string, never over a re-encoded copy of it: any
     * normalisation between verification and use reopens the gap the signature
     * was there to close.
     *
     * @return array{ok:bool, error:string}
     */
    public static function verify(string $payload, string $signature_b64): array
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return ['ok' => false, 'error' => 'This server has no libsodium, so Keelhob cannot verify that a downloaded skill really came from us. Remote skills are disabled — ask your host to enable the sodium extension.'];
        }
        $key = self::public_key();
        if ($key === '') {
            return ['ok' => false, 'error' => 'No skills signing key is configured in this build, so nothing downloaded can be verified.'];
        }
        if ($signature_b64 === '') {
            return ['ok' => false, 'error' => 'The skills server sent an unsigned response. Refusing it — Keelhob never installs an unsigned skill.'];
        }
        $sig = base64_decode($signature_b64, true);
        if (!is_string($sig) || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return ['ok' => false, 'error' => 'The signature on the skills response is malformed. Nothing was installed.'];
        }
        $ok = false;
        try {
            $ok = sodium_crypto_sign_verify_detached($sig, $payload, $key);
        } catch (Throwable $e) {
            $ok = false;
        }
        return $ok
            ? ['ok' => true, 'error' => '']
            : ['ok' => false, 'error' => 'The skills response failed signature verification. It was modified in transit or did not come from Keelhob. Nothing was installed.'];
    }

    /**
     * Unwrap a signed envelope into its decoded payload.
     *
     * @return array{ok:bool, error:string, data:array}
     */
    public static function open(string $json): array
    {
        $envelope = json_decode($json, true);
        if (!is_array($envelope) || !isset($envelope['payload'])) {
            return ['ok' => false, 'error' => 'The skills server sent a response Keelhob could not read.', 'data' => []];
        }
        $payload = (string) $envelope['payload'];
        $check   = self::verify($payload, (string) ($envelope['signature'] ?? ''));
        if (!$check['ok']) {
            return ['ok' => false, 'error' => $check['error'], 'data' => []];
        }
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'The signed payload was not valid JSON. Nothing was installed.', 'data' => []];
        }
        return ['ok' => true, 'error' => '', 'data' => $data];
    }

    /**
     * Which manifest entries this site actually needs to download.
     *
     * Pure, and the reason the manifest carries a version and a sha256 at all:
     * a refresh that re-downloads fifteen unchanged bodies is one an agent
     * cannot afford to call routinely.
     *
     * @param array<int, array> $entries   manifest entries
     * @param array<string, array> $installed name => stored row
     * @return string[] names to fetch
     */
    public static function needed(array $entries, array $installed): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $name = sanitize_title((string) ($entry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            // An entry the server marked unavailable is catalogue, not content:
            // it exists so the Skills screen can describe it, and asking for its
            // body would only earn a refusal.
            if (array_key_exists('available', $entry) && !$entry['available']) {
                continue;
            }
            $row = $installed[$name] ?? null;
            if (!$row) {
                $out[] = $name;
                continue;
            }
            // Only remote rows are refreshable, so anything else is not "needed"
            // however out of date it looks — see install_one().
            if (Keelhob_Skills::normalize_source((string) ($row['source'] ?? '')) !== Keelhob_Skills::SOURCE_REMOTE) {
                continue;
            }
            $same_version = (string) ($row['version'] ?? '') === (string) ($entry['version'] ?? '');
            $same_hash    = hash('sha256', (string) ($row['body'] ?? '')) === (string) ($entry['sha256'] ?? '');
            if (!$same_version || !$same_hash) {
                $out[] = $name;
            }
        }
        return $out;
    }

    // ---- the only writer --------------------------------------------------

    /**
     * Write one verified skill, or refuse.
     *
     * Every guard that protects the owner's own work lives in here rather than
     * in refresh(): a rule enforced at the call sites is a rule that survives
     * exactly until someone adds a call site.
     *
     * @return string installed | updated | skipped-local | rejected
     */
    private static function install_one(array $skill): string
    {
        $name = sanitize_title((string) ($skill['name'] ?? ''));
        $body = (string) ($skill['body'] ?? '');
        if ($name === '' || !self::is_markdown_safe($body)) {
            return 'rejected';
        }
        if (isset($skill['sha256']) && hash('sha256', $body) !== (string) $skill['sha256']) {
            return 'rejected';
        }

        $store    = self::store();
        $existing = $store->get_by_name($name);
        if ($existing) {
            $source = Keelhob_Skills::normalize_source((string) ($existing['source'] ?? ''));
            if ($source !== Keelhob_Skills::SOURCE_REMOTE) {
                // Bundled and local rows are somebody else's: the zip's, or the
                // owner's. A download never wins that argument.
                return 'skipped-local';
            }
        }

        $store->save([
            'name'        => $name,
            'description' => (string) ($skill['description'] ?? ''),
            'body'        => $body,
            'version'     => (string) ($skill['version'] ?? ''),
            'source'      => Keelhob_Skills::SOURCE_REMOTE,
            // Keep the owner's on/off choices across an update.
            'enabled'        => $existing ? (int) $existing['enabled'] : 1,
            'enable_agentic' => $existing ? (int) $existing['enable_agentic'] : 1,
            'enable_prompt'  => $existing ? (int) $existing['enable_prompt'] : 1,
        ]);
        return $existing ? 'updated' : 'installed';
    }

    // ---- the refresh ------------------------------------------------------

    /**
     * Fetch, verify and apply. Never throws; a failure is a notice.
     *
     * @return array{success:bool, notice:string, installed:string[], updated:string[], skipped:string[], rejected:string[]}
     */
    public static function refresh(): array
    {
        $result = [
            'success'   => false,
            'notice'    => '',
            'installed' => [],
            'updated'   => [],
            'skipped'   => [],
            'rejected'  => [],
        ];

        try {
            $manifest = self::get(self::endpoint() . '/' . self::MANIFEST_FILE);
            if (!$manifest['ok']) {
                $result['notice'] = $manifest['error'];
                return $result;
            }

            $entries   = isset($manifest['data']['skills']) && is_array($manifest['data']['skills']) ? $manifest['data']['skills'] : [];
            $installed = [];
            foreach (self::store()->all() as $row) {
                $installed[(string) $row['name']] = $row;
            }
            $wanted = self::needed($entries, $installed);

            $skills = [];
            if ($wanted) {
                $bundle = self::get(self::endpoint() . '/' . self::BUNDLE_FILE);
                if (!$bundle['ok']) {
                    $result['notice'] = $bundle['error'];
                    return $result;
                }
                $all = isset($bundle['data']['skills']) && is_array($bundle['data']['skills']) ? $bundle['data']['skills'] : [];
                // The bundle carries everything, because a static file cannot be
                // asked for a subset. Install only what the manifest said was
                // actually out of date - the rest is already on disk and
                // rewriting it would churn every row for nothing.
                $skills = array_values(array_filter(
                    $all,
                    static function ($skill) use ($wanted) {
                        return in_array(sanitize_title((string) ($skill['name'] ?? '')), $wanted, true);
                    }
                ));
            }

            foreach ($skills as $skill) {
                $name = sanitize_title((string) ($skill['name'] ?? ''));
                switch (self::install_one($skill)) {
                    case 'installed':
                        $result['installed'][] = $name;
                        break;
                    case 'updated':
                        $result['updated'][] = $name;
                        break;
                    case 'skipped-local':
                        $result['skipped'][] = $name;
                        break;
                    default:
                        $result['rejected'][] = $name;
                }
            }

            $result['success'] = true;
            $result['notice']  = self::summarize($result);
            self::remember($entries, $result);
        } catch (Throwable $e) {
            // A refresh cannot be allowed to take a page down with it. Whatever
            // went wrong, the site still has its cached skills.
            $result['notice'] = 'Could not refresh skills: ' . $e->getMessage();
        }

        self::log($result);
        return $result;
    }

    /** One GET, verified. @return array{ok:bool, error:string, data:array} */
    private static function get(string $url): array
    {
        // GitHub redirects releases/latest/download/<file> to the real asset,
        // so redirects must be followed. Nothing is trusted on the strength of
        // where it came from anyway - the signature decides.
        $args = [
            'timeout'     => 15,
            'redirection' => 5,
            'headers'     => ['Accept' => 'application/json'],
        ];

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => 'Could not reach the skills feed: ' . $response->get_error_message() . '. Your existing skills are unaffected.', 'data' => []];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return ['ok' => false, 'error' => 'The skills feed answered ' . $code . '. Your existing skills are unaffected.', 'data' => []];
        }
        return self::open((string) wp_remote_retrieve_body($response));
    }

    private static function summarize(array $r): string
    {
        $parts = [];
        if ($r['installed']) {
            $parts[] = count($r['installed']) . ' added';
        }
        if ($r['updated']) {
            $parts[] = count($r['updated']) . ' updated';
        }
        if ($r['skipped']) {
            $parts[] = count($r['skipped']) . ' left alone (edited on this site)';
        }
        if ($r['rejected']) {
            $parts[] = count($r['rejected']) . ' refused';
        }
        return $parts ? implode(', ', $parts) . '.' : 'Every skill on this site is already current.';
    }

    // ---- cache + changelog ------------------------------------------------

    /** @return array{fetched_at:int, entries:array} */
    public static function cache(): array
    {
        $cache = get_option(self::OPTION_CACHE, []);
        return [
            'fetched_at' => (int) ($cache['fetched_at'] ?? 0),
            'entries'    => is_array($cache['entries'] ?? null) ? $cache['entries'] : [],
        ];
    }

    private static function remember(array $entries, array $result): void
    {
        update_option(self::OPTION_CACHE, [
            'fetched_at' => time(),
            'entries'    => $entries,
        ], false);

        // Only a refresh that changed something is worth a changelog line —
        // "nothing happened, ten times" is not a history anyone reads.
        if (!$result['installed'] && !$result['updated']) {
            return;
        }
        $history = self::history();
        array_unshift($history, [
            'at'        => current_time('mysql'),
            'installed' => $result['installed'],
            'updated'   => $result['updated'],
        ]);
        update_option(self::OPTION_HISTORY, array_slice($history, 0, self::HISTORY_KEEP), false);
    }

    /** @return array<int, array{at:string, installed:string[], updated:string[]}> */
    public static function history(): array
    {
        $history = get_option(self::OPTION_HISTORY, []);
        return is_array($history) ? $history : [];
    }

    /** Names that arrived in the most recent refresh, for the New badge. */
    public static function newly_added(): array
    {
        $history = self::history();
        return $history ? (array) ($history[0]['installed'] ?? []) : [];
    }

    /**
     * Write the refresh to the Activity page.
     *
     * A sync that silently swaps the instructions an agent follows is not one
     * anybody should trust, so every refresh names what changed and when.
     */
    private static function log(array $result): void
    {
        if (!class_exists('Keelhob')) {
            return;
        }
        $logger = Keelhob::instance()->logger ?? null;
        if (!is_object($logger) || !method_exists($logger, 'record')) {
            return;
        }
        $logger->record('skill_refresh', [
            'endpoint'  => self::endpoint(),
            'installed' => $result['installed'],
            'updated'   => $result['updated'],
            'skipped'   => $result['skipped'],
        ], [
            'success' => $result['success'],
            'message' => $result['notice'],
        ]);
    }
}
