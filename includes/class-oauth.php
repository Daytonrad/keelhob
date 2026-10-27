<?php
/**
 * OAuth 2.1 for Keelhob — the discovery half.
 *
 * WHAT THIS IS FOR.
 *
 * Today, connecting an AI client to Keelhob means: open Connect, press
 * Regenerate token, copy a `kh_…` string, paste it into a JSON config file,
 * restart the client. That is five steps and one of them is handling a secret
 * by hand. With OAuth it is: paste the site URL, press Sign in, press Allow.
 * The WordPress login screen does the authenticating, and no secret is ever
 * copied anywhere.
 *
 * WHAT THIS IS, HONESTLY.
 *
 * An authorization server inside a WordPress plugin. The failure modes are an
 * open redirect, or a code that can be used twice, and either one hands an
 * attacker a token that can run arbitrary PHP on the site. That is why it is
 * off by default, why the bearer path is untouched, and why every rule below
 * is stated as a rule rather than left to be inferred from the code.
 *
 * THE SIX RULES. These are from the plan and they are not negotiable:
 *
 *   1. PKCE is required, never optional. The code is deleted on first use,
 *      BEFORE it is validated.
 *   2. `redirect_uri` must match a registered URI exactly. No prefix matching.
 *   3. Only sha256 hashes of tokens and client secrets are stored.
 *   4. `manage_options` is required at authorize AND on every MCP call after.
 *   5. The existing auth lockout covers the token endpoint too.
 *   6. It ships behind a setting, off by default, with the bearer path
 *      untouched.
 *
 * WHY DISCOVERY IS ITS OWN COMMIT AND ITS OWN HALF OF THE FILE.
 *
 * Discovery is the only part of OAuth that answers to an unauthenticated
 * stranger before anything else exists. It is also the part that TELLS the
 * world this site has an authorization server. So when the feature is off, the
 * discovery documents must not exist — a 404, not an empty object and not a
 * document pointing at endpoints that will refuse. A site with the switch off
 * should be indistinguishable from a site running a version that never had the
 * feature.
 *
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_OAuth
{
    /**
     * The settings key. Absent means off — see `enabled()`.
     *
     * Deliberately NOT a capability group. Capability groups say what a
     * connected agent may DO; this says how an agent may connect at all, which
     * is a different question and belongs next to `allow_app_passwords` and
     * `require_https` rather than next to `payments`.
     */
    const SETTING = 'oauth_enabled';

    /** Registered clients. See class-oauth-clients.php for the shape. */
    const OPTION_CLIENTS = 'keelhob_oauth_clients';

    /**
     * The scope string. One scope, because there is one thing to grant: use of
     * this site's MCP endpoint. What the holder may then do is decided by the
     * capability fence, which the owner controls and a client cannot ask to
     * widen. A scope list that a client could negotiate would be a second,
     * weaker copy of that fence.
     */
    const SCOPE = 'keelhob';

    /**
     * Is the OAuth server switched on?
     *
     * Rule 6. `array_key_exists` is not used on purpose: unlike a capability
     * group, there is no "the owner has never said" state worth preserving
     * here. Absent, false, 0 and '' all mean off, and the only thing that means
     * on is the owner having explicitly turned it on.
     */
    public static function enabled(): bool
    {
        return (bool) Keelhob::instance()->get(self::SETTING, false);
    }

    public static function hooks(): void
    {
        /*
         * Priority 0 on `init`, which is earlier than almost anything else
         * hooks, because `/.well-known/…` is not a WordPress route. By the time
         * the rewrite rules have had their say the request is a 404 and the
         * headers are already decided. The handler returns immediately unless
         * the path matches, so the cost on every other request is one
         * strpos().
         */
        add_action('init', [__CLASS__, 'maybe_serve_discovery'], 0);
    }

    /* ------------------------------------------------------------ discovery */

    /**
     * The two discovery documents, served off `init` before WordPress routes.
     *
     * Tolerates a path SUFFIX (`/.well-known/oauth-protected-resource/wp-json/
     * keelhob/v1/mcp`) because RFC 9728 tells clients to append the resource
     * path, and real clients do. Matching on "contains" rather than "equals" is
     * what makes the real-world case work.
     *
     * `openid-configuration` is served from the same document as
     * `oauth-authorization-server`. Keelhob is not an OpenID provider and
     * issues no ID tokens, but a number of clients probe that path first and
     * give up if it 404s, so answering it is the difference between connecting
     * and not. Nothing in the document claims OpenID support.
     */
    public static function maybe_serve_discovery(): void
    {
        $path = self::request_path();
        if ($path === '' || strpos($path, '/.well-known/') === false) {
            return;
        }

        if (strpos($path, '/.well-known/oauth-protected-resource') !== false) {
            self::serve_json(self::protected_resource_metadata());
        }

        if (
            strpos($path, '/.well-known/oauth-authorization-server') !== false
            || strpos($path, '/.well-known/openid-configuration') !== false
        ) {
            self::serve_json(self::authorization_server_metadata());
        }
    }

    /**
     * What this site is, as a protected resource (RFC 9728).
     *
     * @return array<string, mixed>
     */
    public static function protected_resource_metadata(): array
    {
        return [
            'resource'                 => rest_url(KEELHOB_REST_NAMESPACE . '/mcp'),
            'authorization_servers'    => [self::issuer()],
            'bearer_methods_supported' => ['header'],
            'scopes_supported'         => [self::SCOPE],
            'resource_name'            => sprintf('%s (Keelhob)', get_bloginfo('name')),
        ];
    }

    /**
     * What this site is, as an authorization server (RFC 8414).
     *
     * `code` is the only response type and `S256` the only challenge method.
     * Listing `plain` would make rule 1 a suggestion: a client is entitled to
     * use anything advertised here, so the advertisement IS the policy.
     *
     * @return array<string, mixed>
     */
    public static function authorization_server_metadata(): array
    {
        return [
            'issuer'                                => self::issuer(),
            'authorization_endpoint'                => self::authorize_url(),
            'token_endpoint'                        => self::token_url(),
            'registration_endpoint'                 => self::register_url(),
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'      => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'scopes_supported'                      => [self::SCOPE],
        ];
    }

    /* ------------------------------------------------------------- the URLs */

    /**
     * The issuer.
     *
     * Untrailingslashed, because a client compares this string byte for byte
     * against the `issuer` it was given and a stray slash is a mismatch.
     */
    public static function issuer(): string
    {
        return untrailingslashit(home_url());
    }

    /**
     * The authorize screen.
     *
     * `admin-post.php`, not a REST route, for one reason that decides it: this
     * endpoint has to be able to send the visitor to `wp-login.php` and have
     * WordPress send them back here afterwards. That is what admin-post.php is
     * for. A REST route would have to reimplement the login round-trip, and
     * reimplementing a login round-trip is the kind of thing that grows an open
     * redirect.
     */
    public static function authorize_url(): string
    {
        return admin_url('admin-post.php?action=keelhob_authorize');
    }

    public static function token_url(): string
    {
        return rest_url(KEELHOB_REST_NAMESPACE . '/oauth/token');
    }

    public static function register_url(): string
    {
        return rest_url(KEELHOB_REST_NAMESPACE . '/oauth/register');
    }

    /* ------------------------------------------------------------- plumbing */

    /**
     * The path of the current request, with the query string removed.
     *
     * Raw `REQUEST_URI`, because this runs before WordPress has parsed
     * anything. Unslashed but NOT sanitised beyond that: the value is only ever
     * compared with strpos() against fixed literals and never echoed, stored or
     * passed to a query.
     */
    public static function request_path(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        if (!is_string($uri) || $uri === '') {
            return '';
        }
        $path = strtok($uri, '?');

        return is_string($path) ? $path : '';
    }

    /**
     * Send a discovery document and stop.
     *
     * When the feature is off this sends a 404 and nothing else. See the file
     * header: a site with the switch off must look like a site that never had
     * the feature, so there is no "enabled: false" document to find and no
     * endpoint list to probe.
     *
     * `Cache-Control: no-store` because these documents name endpoints and a
     * scope, and a CDN that cached one would keep serving it after the owner
     * switched the feature off.
     *
     * @param array<string, mixed> $doc
     */
    private static function serve_json(array $doc): void
    {
        if (!self::enabled()) {
            status_header(404);
            self::send_common_headers();
            echo wp_json_encode(['error' => 'not_found']);
            exit;
        }

        status_header(200);
        self::send_common_headers();
        echo wp_json_encode($doc);
        exit;
    }

    /**
     * Headers every OAuth response carries.
     *
     * The wildcard CORS origin is deliberate and is safe for exactly these
     * documents: they are public, unauthenticated, identical for every caller
     * and contain no secret. A browser-based client cannot discover the server
     * without it. It must NOT be copied onto the token endpoint, which does
     * carry secrets — see class-oauth-token.php, which sets its own.
     */
    public static function send_common_headers(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Protocol-Version, Mcp-Session-Id');
    }

    /* --------------------------------------------------------------- crypto */

    /**
     * The one place a token or secret becomes a stored value.
     *
     * Rule 3. Plain `hash('sha256', …)`, not `wp_hash_password()`: these are
     * 48 bytes of output from `random_bytes()`, not a password, so there is no
     * dictionary to slow down and nothing for a work factor to buy. What
     * matters is that the stored form is useless if the database leaks, and
     * that comparison is constant-time — see `hash_matches()`.
     */
    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * Compare a presented secret against a stored hash, in constant time.
     *
     * `hash_equals`, not `===`. A timing side channel on a 64-character hex
     * string is a real way to learn it one character at a time, and the cost of
     * being careful here is nothing.
     */
    public static function hash_matches(string $presented, string $stored_hash): bool
    {
        if ($presented === '' || $stored_hash === '') {
            return false;
        }

        return hash_equals($stored_hash, self::hash($presented));
    }

    /**
     * A new opaque secret: a prefix plus 48 hex characters of real randomness.
     *
     * The prefix is how a human reading a log, and `Keelhob_Auth`, can tell an
     * OAuth access token from the single bearer token the Connect screen issues
     * without having to look either of them up.
     */
    public static function mint(string $prefix): string
    {
        return $prefix . bin2hex(random_bytes(24));
    }
}
