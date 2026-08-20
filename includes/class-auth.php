<?php
/**
 * Authentication for the MCP endpoint.
 *
 * Two supported credentials, both requiring an administrator-capable identity:
 *   1. Bearer token  — `Authorization: Bearer cp_xxx` (generated in settings).
 *      Simplest for Claude Code. Runs as the site's first admin.
 *   2. Application Password — `Authorization: Basic base64(user:app_password)`.
 *      Standard WordPress credential; runs as that user.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Auth
{
    /**
     * Authenticate the current REST request.
     *
     * @return true|WP_Error  True (and sets current user) on success.
     */
    public static function authenticate()
    {
        $settings = Claudpress::instance()->settings();

        // S4: throttle before doing any credential work. Without this the MCP
        // route is an unlimited password-guessing oracle that bypasses both the
        // login page and every 2FA plugin, because those hook wp-login.php and
        // never see a custom REST route.
        if (self::is_locked_out()) {
            return new WP_Error(
                'claudpress_locked_out',
                'Too many failed attempts. Try again in a few minutes.',
                ['status' => 429]
            );
        }

        if (!empty($settings['require_https']) && !is_ssl() && !self::is_local()) {
            return new WP_Error('claudpress_https_required', 'HTTPS is required to connect to Claudpress.', ['status' => 403]);
        }

        $header = self::auth_header();
        if ($header === '') {
            return new WP_Error('claudpress_no_auth', 'Missing Authorization header.', ['status' => 401]);
        }

        // Bearer token path.
        if (stripos($header, 'Bearer ') === 0) {
            $presented = trim(substr($header, 7));
            if (!self::token_matches($presented, $settings)) {
                self::record_failure();
                return new WP_Error('claudpress_bad_token', 'Invalid bearer token.', ['status' => 401]);
            }
            if (self::token_expired($settings)) {
                return new WP_Error('claudpress_token_expired', 'This token has expired. Generate a new one in Claudpress > Connect.', ['status' => 401]);
            }
            $admin_id = self::default_admin_id();
            if (!$admin_id) {
                return new WP_Error('claudpress_no_admin', 'No administrator account found to run as.', ['status' => 500]);
            }
            self::clear_failures();
            self::touch_token();
            wp_set_current_user($admin_id);
            return true;
        }

        // Basic / Application Password path.
        if (stripos($header, 'Basic ') === 0) {
            if (empty($settings['allow_app_passwords'])) {
                return new WP_Error('claudpress_basic_disabled', 'Application Password auth is disabled.', ['status' => 401]);
            }
            $decoded = base64_decode(trim(substr($header, 6)), true);
            if ($decoded === false || strpos($decoded, ':') === false) {
                return new WP_Error('claudpress_bad_basic', 'Malformed Basic credentials.', ['status' => 401]);
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
                return new WP_Error('claudpress_auth_failed', 'Authentication failed. Use an Application Password, not your account password.', ['status' => 401]);
            }
            if (!user_can($user, 'manage_options')) {
                self::record_failure();
                return new WP_Error('claudpress_not_admin', 'Account lacks manage_options capability.', ['status' => 403]);
            }
            self::clear_failures();
            wp_set_current_user($user->ID);
            return true;
        }

        return new WP_Error('claudpress_unsupported_auth', 'Unsupported Authorization scheme.', ['status' => 401]);
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
    const MAX_FAILS_GLOBAL = 100;

    private static function fail_key_ip(): string
    {
        return 'claudpress_authfail_' . md5(self::client_ip());
    }

    private static function fail_key_global(): string
    {
        return 'claudpress_authfail_global';
    }

    public static function is_locked_out(): bool
    {
        return (int) get_transient(self::fail_key_ip()) >= self::MAX_FAILS_PER_IP
            || (int) get_transient(self::fail_key_global()) >= self::MAX_FAILS_GLOBAL;
    }

    private static function record_failure(): void
    {
        foreach ([[self::fail_key_ip(), self::MAX_FAILS_PER_IP], [self::fail_key_global(), self::MAX_FAILS_GLOBAL]] as [$key, $_max]) {
            $count = (int) get_transient($key);
            // Always re-set with the full window so a sustained attack stays
            // locked out rather than trickling through as the window slides.
            set_transient($key, $count + 1, self::LOCKOUT_WINDOW);
        }
    }

    private static function clear_failures(): void
    {
        delete_transient(self::fail_key_ip());
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
        return (string) apply_filters('claudpress_client_ip', $ip);
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
            Claudpress::instance()->update([
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
        $last = (int) (Claudpress::instance()->get('token_last_used', 0));
        if (time() - $last < 60) {
            return;
        }
        Claudpress::instance()->update(['token_last_used' => time()]);
    }
}
