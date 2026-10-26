<?php
/**
 * Authentication for the MCP endpoint.
 *
 * Two supported credentials, both requiring an administrator-capable identity:
 *   1. Bearer token  — `Authorization: Bearer kh_xxx` (generated in settings).
 *      Simplest for Claude Code. Runs as the site's first admin.
 *   2. Application Password — `Authorization: Basic base64(user:app_password)`.
 *      Standard WordPress credential; runs as that user.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Auth
{
    /**
     * Authenticate the current REST request.
     *
     * @return true|WP_Error  True (and sets current user) on success.
     */
    public static function authenticate()
    {
        $settings = Keelhob::instance()->settings();

        // S4: throttle before doing any credential work. Without this the MCP
        // route is an unlimited password-guessing oracle that bypasses both the
        // login page and every 2FA plugin, because those hook wp-login.php and
        // never see a custom REST route.
        if (self::is_locked_out()) {
            // Say the real number. "A few minutes" sent somebody regenerating
            // tokens and restarting clients for an hour, because nothing on
            // screen distinguished "wait" from "still broken".
            $left = self::lock_seconds_left();
            return new WP_Error(
                'keelhob_locked_out',
                sprintf(
                    'Too many failed attempts. Try again in %d minute(s). Generating a new token in Keelhob > Connect clears this immediately.',
                    (int) max(1, ceil($left / 60))
                ),
                ['status' => 429, 'retry_after' => $left]
            );
        }

        if (!empty($settings['require_https']) && !is_ssl() && !self::is_local()) {
            return new WP_Error('keelhob_https_required', 'HTTPS is required to connect to Keelhob.', ['status' => 403]);
        }

        $header = self::auth_header();
        if ($header === '') {
            return new WP_Error('keelhob_no_auth', 'Missing Authorization header.', ['status' => 401]);
        }

        // Bearer token path.
        if (stripos($header, 'Bearer ') === 0) {
            $presented = trim(substr($header, 7));
            if (!self::token_matches($presented, $settings)) {
                self::record_failure();
                return new WP_Error('keelhob_bad_token', 'Invalid bearer token.', ['status' => 401]);
            }
            if (self::token_expired($settings)) {
                return new WP_Error('keelhob_token_expired', 'This token has expired. Generate a new one in Keelhob > Connect.', ['status' => 401]);
            }
            $admin_id = self::default_admin_id();
            if (!$admin_id) {
                return new WP_Error('keelhob_no_admin', 'No administrator account found to run as.', ['status' => 500]);
            }
            self::clear_failures();
            self::touch_token();
            wp_set_current_user($admin_id);
            return true;
        }

        // Basic / Application Password path.
        if (stripos($header, 'Basic ') === 0) {
            if (empty($settings['allow_app_passwords'])) {
                return new WP_Error('keelhob_basic_disabled', 'Application Password auth is disabled.', ['status' => 401]);
            }
            $decoded = base64_decode(trim(substr($header, 6)), true);
            if ($decoded === false || strpos($decoded, ':') === false) {
                return new WP_Error('keelhob_bad_basic', 'Malformed Basic credentials.', ['status' => 401]);
            }
            [$username, $password] = explode(':', $decoded, 2);

            // Application Passwords ONLY. The previous wp_authenticate()
            // fallback accepted a real account password here, which turned this
            // route into a password oracle that no login-page hardening or 2FA
            // plugin could see, let alone stop. An Application Password is
            // revocable on its own and never unlocks wp-admin, so it is the only
            // credential that belongs on a machine endpoint.
            $user = wp_authenticate_application_password(null, $username, $password);
            if (is_wp_error($user) || !$user instanceof WP_User) {
                self::record_failure();
                return new WP_Error('keelhob_auth_failed', 'Authentication failed. Use an Application Password, not your account password.', ['status' => 401]);
            }
            if (!user_can($user, 'manage_options')) {
                self::record_failure();
                return new WP_Error('keelhob_not_admin', 'Account lacks manage_options capability.', ['status' => 403]);
            }
            self::clear_failures();
            wp_set_current_user($user->ID);
            return true;
        }

        return new WP_Error('keelhob_unsupported_auth', 'Unsupported Authorization scheme.', ['status' => 401]);
    }

    private static function auth_header(): string
    {
        $candidates = [];
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $candidates[] = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $candidates[] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strtolower($k) === 'authorization') {
                    $candidates[] = $v;
                }
            }
        }
        foreach ($candidates as $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                return $c;
            }
        }
        return '';
    }

    private static function default_admin_id(): int
    {
        $admins = get_users([
            'role'    => 'administrator',
            'number'  => 1,
            'orderby' => 'ID',
            'order'   => 'ASC',
            'fields'  => 'ID',
        ]);
        return !empty($admins) ? (int) $admins[0] : 0;
    }

    /**
     * S7: derive the host from the site's own configured home_url(), never from
     * $_SERVER['HTTP_HOST']. That header is supplied by the caller, so sending
     * `Host: anything.local` used to be enough to switch off the HTTPS
     * requirement on a production site.
     */
    private static function is_local(): bool
    {
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $host = preg_replace('/:\d+$/', '', (string) $host);
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }
        foreach (['.local', '.test', '.localhost', '.wip', '.dev'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------- S4 ----
    // Failure throttling. Two counters, because either alone is bypassable:
    // a per-IP counter is defeated by a botnet, and a global-only counter lets
    // one noisy IP lock out everybody. Both are transients, so they expire on
    // their own and cost nothing when no one is attacking.

    const LOCKOUT_WINDOW   = 900;  // 15 minutes
    const MAX_FAILS_PER_IP = 10;

    /**
     * How many DISTINCT addresses must be failing before everyone is locked out.
     *
     * This used to be a flat count of failures from anybody, which made the
     * global lock trivially weaponisable: 100 bad requests from one machine —
     * a few seconds of curl against the unauthenticated /status route — locked
     * the real owner out of their own site for fifteen minutes, repeatable
     * forever. The counter meant to stop a botnet was a denial-of-service tool
     * with a lower cost to the attacker than to the victim.
     *
     * Counting addresses instead restores the distinction the check was for. A
     * single attacker now trips only their own per-IP lock no matter how many
     * requests they send, because they only ever contribute one entry here. A
     * real botnet, which is many addresses each staying under the per-IP
     * ceiling, is exactly what this still catches.
     */
    const MAX_FAILING_IPS = 25;

    /**
     * Ceiling on how many addresses are remembered.
     *
     * The set lives in one transient, so an attacker who can present unlimited
     * source addresses could otherwise grow a single option row without bound.
     * Well past MAX_FAILING_IPS, so the lock has always fired long before the
     * cap is reached — the cap is a memory guard, not part of the rule.
     */
    const MAX_TRACKED_IPS = 200;

    private static function fail_key_ip(): string
    {
        return self::fail_key_for(self::ip_fingerprint());
    }

    /** The per-address counter key, for any address. unlock() needs this for addresses that are not the caller's. */
    private static function fail_key_for(string $fingerprint): string
    {
        return 'keelhob_authfail_' . $fingerprint;
    }

    private static function fail_key_global(): string
    {
        return 'keelhob_authfail_ips';
    }

    /** Hashed, never raw: this set is stored, and it does not need real addresses. */
    private static function ip_fingerprint(): string
    {
        return md5(self::client_ip());
    }

    /** The remembered failing addresses, with the expired ones already dropped. */
    private static function failing_ips(): array
    {
        $set = get_transient(self::fail_key_global());
        if (!is_array($set)) {
            return [];
        }
        // Each entry expires on its own clock. Without this, one burst would
        // hold the site near the threshold for the whole window even after the
        // addresses behind it had gone quiet.
        $cutoff = time() - self::LOCKOUT_WINDOW;
        return array_filter($set, static fn($seen): bool => (int) $seen > $cutoff);
    }

    public static function is_locked_out(): bool
    {
        return (int) get_transient(self::fail_key_ip()) >= self::MAX_FAILS_PER_IP
            || count(self::failing_ips()) >= self::MAX_FAILING_IPS;
    }

    /**
     * Seconds until the lock lifts, for the message.
     *
     * Read off the transient's own expiry rather than tracked separately, so
     * the number shown cannot drift from the thing that actually unlocks.
     * Falls back to the full window when the timeout row is missing, which is
     * the safe direction: over-stating the wait annoys, under-stating it sends
     * somebody back into the retry loop that caused the lock.
     */
    public static function lock_seconds_left(): int
    {
        $at = (int) get_option('_transient_timeout_' . self::fail_key_ip(), 0);
        $left = $at - time();

        return $left > 0 ? $left : self::LOCKOUT_WINDOW;
    }

    /**
     * Drop every failure record, for every address.
     *
     * EVERY address, not just the caller's, and that is the point. The lock is
     * keyed on the IP of the MCP CLIENT; this is called from wp-admin, where
     * the browser's address is usually a different one. Clearing only "my" IP
     * would leave the client locked out and the owner staring at a button that
     * did nothing.
     *
     * Safe to expose because reaching it already requires manage_options or a
     * successful token mint — an attacker who can do either does not need to
     * bother with the lock.
     */
    public static function unlock(): void
    {
        // The global set is keyed by the SAME fingerprint the per-address
        // counters are named after, so it is already the index of everyone
        // currently failing. Walking it needs no SQL, which keeps this working
        // on any storage backend.
        $set = get_transient(self::fail_key_global());
        foreach (array_keys(is_array($set) ? $set : []) as $fingerprint) {
            delete_transient(self::fail_key_for((string) $fingerprint));
        }

        delete_transient(self::fail_key_ip());      // the caller's own, in or out of the set
        delete_transient(self::fail_key_global());  // and the global lock with it
    }

    /**
     * Rule 5: let the OAuth token endpoint feed the same lockout.
     *
     * A public wrapper rather than making record_failure() public, so the only
     * thing another class can do is report a failure — it cannot read or reset
     * the counters. Without this the token endpoint would be an unlimited
     * guessing oracle for client secrets and authorization codes, sitting right
     * next to an MCP route that is carefully rate-limited, which is the kind of
     * asymmetry an attacker looks for first.
     *
     * Named for OAuth deliberately. A generic `note_failure()` would invite
     * every future caller to share one counter without thinking about whether
     * its failures are comparable to a bad bearer token's.
     */
    public static function note_oauth_failure(): void
    {
        self::record_failure();
    }

    private static function record_failure(): void
    {
        // Per-IP: a plain counter, re-set with the full window so a sustained
        // attack stays locked out rather than trickling through as it slides.
        $count = (int) get_transient(self::fail_key_ip());
        set_transient(self::fail_key_ip(), $count + 1, self::LOCKOUT_WINDOW);

        // Global: a set of addresses, not a tally of requests.
        $set = self::failing_ips();
        $me  = self::ip_fingerprint();
        if (!isset($set[$me]) && count($set) >= self::MAX_TRACKED_IPS) {
            return; // Already far past the threshold; nothing to learn.
        }
        $set[$me] = time();
        set_transient(self::fail_key_global(), $set, self::LOCKOUT_WINDOW);
    }

    private static function clear_failures(): void
    {
        delete_transient(self::fail_key_ip());

        // Drop this address from the global set too. Without it, one fat-
        // fingered token from each of twenty-five staff over fifteen minutes
        // would lock the site even though every one of them then succeeded.
        $set = self::failing_ips();
        unset($set[self::ip_fingerprint()]);
        if (empty($set)) {
            delete_transient(self::fail_key_global());
        } else {
            set_transient(self::fail_key_global(), $set, self::LOCKOUT_WINDOW);
        }
    }

    /**
     * REMOTE_ADDR only, deliberately.
     *
     * X-Forwarded-For is attacker-controlled unless a proxy you trust rewrites
     * it, so honouring it here would let one attacker present a fresh "IP" on
     * every request and never trip the per-IP counter. Behind a CDN this makes
     * every visitor share one bucket, which is exactly what the global counter
     * is sized for. Sites with a trusted proxy can override via the filter.
     */
    private static function client_ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return (string) apply_filters('keelhob_client_ip', $ip);
    }

    // ---------------------------------------------------------------- S5 ----
    // The bearer token is stored as a SHA-256 hash, not in clear text, so a
    // database dump or a stray get_option() no longer hands over a full-admin
    // credential. The plaintext is shown exactly once, at generation time.

    /**
     * Accepts the hashed form, and transparently upgrades a legacy plaintext
     * token on first use so existing connections keep working across the
     * upgrade instead of silently breaking.
     */
    private static function token_matches(string $presented, array $settings): bool
    {
        if ($presented === '') {
            return false;
        }
        $hash = (string) ($settings['token_hash'] ?? '');
        if ($hash !== '') {
            return hash_equals($hash, hash('sha256', $presented));
        }
        $legacy = (string) ($settings['token'] ?? '');
        if ($legacy !== '' && hash_equals($legacy, $presented)) {
            Keelhob::instance()->update([
                'token'      => '',
                'token_hash' => hash('sha256', $presented),
            ]);
            return true;
        }
        return false;
    }

    /** Zero or empty means "never expires", which stays the default. */
    private static function token_expired(array $settings): bool
    {
        $expires = (int) ($settings['token_expires_at'] ?? 0);
        return $expires > 0 && time() > $expires;
    }

    /**
     * Record last use, at most once a minute.
     *
     * Writing an option on every single tool call would add a database write to
     * the hot path for information nobody needs to the second.
     */
    private static function touch_token(): void
    {
        $last = (int) (Keelhob::instance()->get('token_last_used', 0));
        if (time() - $last < 60) {
            return;
        }
        Keelhob::instance()->update(['token_last_used' => time()]);
    }
}
