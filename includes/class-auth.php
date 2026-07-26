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
            $expected  = (string) ($settings['token'] ?? '');
            if ($expected === '' || !hash_equals($expected, $presented)) {
                return new WP_Error('claudpress_bad_token', 'Invalid bearer token.', ['status' => 401]);
            }
            $admin_id = self::default_admin_id();
            if (!$admin_id) {
                return new WP_Error('claudpress_no_admin', 'No administrator account found to run as.', ['status' => 500]);
            }
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
            $user = wp_authenticate_application_password(null, $username, $password);
            if (is_wp_error($user)) {
                $user = wp_authenticate($username, $password); // fallback: normal login (last resort)
            }
            if (is_wp_error($user) || !$user instanceof WP_User) {
                return new WP_Error('claudpress_auth_failed', 'Authentication failed.', ['status' => 401]);
            }
            if (!user_can($user, 'manage_options')) {
                return new WP_Error('claudpress_not_admin', 'Account lacks manage_options capability.', ['status' => 403]);
            }
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

    private static function is_local(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);
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
}
