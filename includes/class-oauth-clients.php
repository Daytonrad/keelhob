<?php
/**
 * OAuth clients: the store, and the redirect-URI rules.
 *
 * WHY THIS IS A FILE OF ITS OWN.
 *
 * Dynamic client registration is an UNAUTHENTICATED endpoint that writes to the
 * database. That is unusual enough to deserve its own file, and two separate
 * things in here are the ones that go wrong:
 *
 *   1. `redirect_uri` validation. This is the open-redirect surface, and it is
 *      the single most dangerous function in the OAuth feature. Every known
 *      bypass is a thing that LOOKS like a URL check and is not one.
 *
 *   2. The cap. An unauthenticated write needs a bound, and the obvious bound —
 *      "keep 100, drop the oldest" — is itself an attack: register 100 clients
 *      and the owner's real, working client is evicted and their AI stops
 *      connecting. See `make_room()` for what this does instead.
 *
 * Registration is deliberately unauthenticated. That is what makes "paste the
 * site URL and press Sign in" work — the client has nobody to ask for a
 * credential yet. Registering is not authorization: a registered client can do
 * nothing at all until a human has signed in to WordPress and pressed Allow.
 *
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_OAuth_Clients
{
    /**
     * The cap on stored clients.
     *
     * 100 is the reference implementation's number and it is fine. What
     * matters is not the number but which one is dropped when it is reached —
     * see `make_room()`.
     */
    const MAX_CLIENTS = 100;

    /** `khc_` + 24 hex characters. Public, not a secret; it only has to be unique. */
    const ID_PREFIX = 'khc_';

    /**
     * The auth methods a client may register.
     *
     * `none` is the normal case for a desktop AI client: it is a public client,
     * it cannot keep a secret on the user's machine, and pretending otherwise
     * would be theatre. PKCE is what protects a public client, which is why
     * rule 1 has no exceptions.
     */
    const AUTH_METHODS = ['none', 'client_secret_post', 'client_secret_basic'];

    /* ---------------------------------------------------------- the store */

    /**
     * Every registered client, keyed by client_id.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $clients = get_option(Keelhob_OAuth::OPTION_CLIENTS, []);

        return is_array($clients) ? $clients : [];
    }

    /**
     * One client, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $client_id): ?array
    {
        if ($client_id === '') {
            return null;
        }
        $all = self::all();
        $row = $all[$client_id] ?? null;

        return is_array($row) ? $row : null;
    }

    /** @param array<string, array<string, mixed>> $clients */
    private static function save(array $clients): void
    {
        /*
         * autoload NO. This option can hold 100 clients and is read on exactly
         * three endpoints; autoloading it would put it in memory on every
         * request to the front end of the site, for every visitor, forever.
         */
        update_option(Keelhob_OAuth::OPTION_CLIENTS, $clients, false);
    }

    /* ------------------------------------------------------- registration */

    /**
     * Register a client.
     *
     * Returns the metadata to send back on success, or a WP_Error whose
     * message is safe to show a stranger — it says what is wrong with the
     * request and nothing about this site.
     *
     * @param array<string, mixed> $request The decoded JSON body.
     * @return array<string, mixed>|WP_Error
     */
    public static function register(array $request)
    {
        if (!Keelhob_OAuth::enabled()) {
            return new WP_Error('keelhob_oauth_disabled', 'OAuth is not enabled on this site.', ['status' => 404]);
        }

        $uris = self::redirect_uris_from($request);
        if (is_wp_error($uris)) {
            return $uris;
        }

        $method = isset($request['token_endpoint_auth_method'])
            ? (string) $request['token_endpoint_auth_method']
            : 'none';
        if (!in_array($method, self::AUTH_METHODS, true)) {
            return new WP_Error(
                'invalid_client_metadata',
                'Unsupported token_endpoint_auth_method.',
                ['status' => 400]
            );
        }

        /*
         * The name is shown to the owner on the consent screen, so it is the
         * one field in this request an attacker would like to control. It is
         * stored as plain text, truncated, and escaped at the point of output
         * — never trusted here.
         */
        $name = isset($request['client_name']) ? (string) $request['client_name'] : '';
        $name = trim(sanitize_text_field($name));
        if ($name === '') {
            $name = 'An AI client';
        }
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 80);
        } else {
            $name = substr($name, 0, 80);
        }

        $clients = self::make_room(self::all());
        if (is_wp_error($clients)) {
            return $clients;
        }

        $client_id = self::ID_PREFIX . bin2hex(random_bytes(12));
        $secret    = $method === 'none' ? '' : Keelhob_OAuth::mint('khs_');

        $clients[$client_id] = [
            'client_id'     => $client_id,
            'client_name'   => $name,
            'redirect_uris' => $uris,
            // Rule 3: the hash, never the secret.
            'secret_hash'   => $secret === '' ? '' : Keelhob_OAuth::hash($secret),
            'auth_method'   => $method,
            'created_at'    => time(),
            // 0 until a token has actually been issued to it. `make_room()`
            // reads this, so it is load-bearing rather than decoration.
            'last_used'     => 0,
        ];
        self::save($clients);

        $out = [
            'client_id'                  => $client_id,
            'client_name'                => $name,
            'redirect_uris'              => $uris,
            'token_endpoint_auth_method' => $method,
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'client_id_issued_at'        => $clients[$client_id]['created_at'],
        ];
        if ($secret !== '') {
            // The only time the secret exists in plaintext anywhere. It is not
            // stored, not logged, and cannot be read back.
            $out['client_secret']            = $secret;
            $out['client_secret_expires_at'] = 0;
        }

        return $out;
    }

    /**
     * Make space for one more client without ever evicting a working one.
     *
     * THE REFERENCE IMPLEMENTATION DROPS THE OLDEST. That is an attack, not a
     * policy: registration needs no credential, so anyone can register 100
     * clients, evict the owner's real one, and the owner's AI simply stops
     * connecting with nothing on screen to explain it. Denial of service by
     * filling a queue.
     *
     * So the oldest client that has NEVER been used is dropped first. A client
     * that has held a token is never evicted by a stranger. If all 100 have
     * been used, registration is refused rather than breaking one of them —
     * refusing a new connection is recoverable in a way that silently killing a
     * working one is not, and the owner can remove clients on the Connect
     * screen.
     *
     * @param array<string, array<string, mixed>> $clients
     * @return array<string, array<string, mixed>>|WP_Error
     */
    public static function make_room(array $clients)
    {
        if (count($clients) < self::MAX_CLIENTS) {
            return $clients;
        }

        while (count($clients) >= self::MAX_CLIENTS) {
            $victim  = '';
            $oldest  = PHP_INT_MAX;
            foreach ($clients as $id => $row) {
                if ((int) ($row['last_used'] ?? 0) !== 0) {
                    continue; // in use — not a candidate, at any age
                }
                $created = (int) ($row['created_at'] ?? 0);
                if ($created < $oldest) {
                    $oldest = $created;
                    $victim = (string) $id;
                }
            }
            if ($victim === '') {
                return new WP_Error(
                    'too_many_clients',
                    'This site has too many connected clients. Remove one in Keelhob > Connect.',
                    ['status' => 400]
                );
            }
            unset($clients[$victim]);
        }

        return $clients;
    }

    /** Record that a client has been issued a token. Used by `make_room()`. */
    public static function touch(string $client_id): void
    {
        $clients = self::all();
        if (!isset($clients[$client_id])) {
            return;
        }
        $now  = time();
        $last = (int) ($clients[$client_id]['last_used'] ?? 0);
        /*
         * At most one write every 5 minutes. Without this, a client refreshing
         * its token in a loop writes a row to the options table on every call,
         * and this option is not small.
         */
        if ($now - $last < 300) {
            return;
        }
        $clients[$client_id]['last_used'] = $now;
        self::save($clients);
    }

    public static function remove(string $client_id): bool
    {
        $clients = self::all();
        if (!isset($clients[$client_id])) {
            return false;
        }
        unset($clients[$client_id]);
        self::save($clients);

        return true;
    }

    /* ------------------------------------------------- the dangerous part */

    /**
     * Pull `redirect_uris` out of a registration request and validate every one.
     *
     * @param array<string, mixed> $request
     * @return array<int, string>|WP_Error
     */
    private static function redirect_uris_from(array $request)
    {
        $raw = $request['redirect_uris'] ?? null;
        if (!is_array($raw) || $raw === []) {
            return new WP_Error(
                'invalid_redirect_uri',
                'redirect_uris is required and must be a non-empty array.',
                ['status' => 400]
            );
        }
        if (count($raw) > 5) {
            return new WP_Error('invalid_redirect_uri', 'Too many redirect_uris.', ['status' => 400]);
        }

        $uris = [];
        foreach ($raw as $candidate) {
            if (!is_string($candidate)) {
                return new WP_Error('invalid_redirect_uri', 'Each redirect_uri must be a string.', ['status' => 400]);
            }
            if (!self::is_valid_redirect_uri($candidate)) {
                /*
                 * Deliberately does not say WHICH rule failed. The caller is
                 * unauthenticated, and a validator that explains itself is a
                 * validator you can probe until it tells you the shape of the
                 * thing it will accept.
                 */
                return new WP_Error(
                    'invalid_redirect_uri',
                    'redirect_uri must be an absolute https URL with no fragment (http is allowed only on localhost).',
                    ['status' => 400]
                );
            }
            $uris[] = $candidate;
        }

        return array_values(array_unique($uris));
    }

    /**
     * Is this a redirect URI we are willing to send a human to?
     *
     * THE MOST DANGEROUS FUNCTION IN THIS FEATURE. Everything it rejects is a
     * real bypass, and every one of them looks like a URL to a casual reader:
     *
     * - **Userinfo.** `https://evil.test@good.test/` — `parse_url()` reports
     *   host `good.test`, and a browser sends the user to `evil.test`. Any
     *   check that reads the host without rejecting userinfo is wrong. So is
     *   any check that uses strpos() on the string.
     * - **A fragment.** The authorization code is appended as a query
     *   parameter. A registered fragment lets a client restructure where that
     *   code lands in the URL, and fragments are not sent to servers — they are
     *   read by script in the page.
     * - **A scheme that is not http(s).** `javascript:`, `data:` and
     *   `vbscript:` are URLs, parse cleanly, and execute. A custom scheme like
     *   `myapp://` is legitimate in OAuth for native apps, and is refused here
     *   anyway: Keelhob's clients are desktop AI apps that listen on
     *   `http://127.0.0.1`, so supporting custom schemes would widen the
     *   surface for no caller that exists.
     * - **http on anything but loopback.** The code travels in this URL.
     * - **A hostname that merely CONTAINS "localhost".** `localhost.evil.test`
     *   is a public domain. The host must EQUAL a loopback name.
     * - **No host at all.** `https:///path` parses.
     *
     * Note what this function does not do: it never compares a candidate
     * against a registered URI. That is a separate job with a separate rule
     * (exact match, rule 2) and it lives in `redirect_uri_allowed()` so the two
     * cannot be confused for each other.
     */
    public static function is_valid_redirect_uri(string $uri): bool
    {
        $uri = trim($uri);
        if ($uri === '' || strlen($uri) > 2000) {
            return false;
        }

        /*
         * Control characters and whitespace anywhere in the string. A newline
         * in a Location header is a response-splitting bug, and a tab or a null
         * byte is how a parser is persuaded to disagree with a browser.
         */
        if (preg_match('/[\x00-\x20\x7f]/', $uri)) {
            return false;
        }

        $parts = wp_parse_url($uri);
        if (!is_array($parts)) {
            return false;
        }

        // A fragment, even an empty one, is refused.
        if (isset($parts['fragment']) || strpos($uri, '#') !== false) {
            return false;
        }

        // Userinfo. Checked on the PARSED value and on the raw string, because
        // the whole class of bug here is a parser disagreeing with a browser.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host   = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        if ($host === '') {
            return false;
        }
        // The authority must start right after the scheme, so `https:/evil` and
        // `https:evil` are out.
        if (strpos($uri, $scheme . '://') !== 0) {
            return false;
        }
        // And the authority must be exactly the host (plus an optional port):
        // anything else before the first `/` is userinfo by another name.
        $authority = $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
        if (strncasecmp(substr($uri, strlen($scheme . '://')), $authority, strlen($authority)) !== 0) {
            return false;
        }

        if ($scheme === 'https') {
            return true;
        }
        if ($scheme === 'http') {
            return self::is_loopback_host($host);
        }

        return false;
    }

    /**
     * Loopback only, by exact match.
     *
     * `localhost.evil.test` resolves on the public internet, so "contains" is
     * not a test. IPv6 arrives from `wp_parse_url()` with its brackets already
     * removed, so both forms are listed.
     */
    public static function is_loopback_host(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    /**
     * Rule 2: is this exactly one of the URIs the client registered?
     *
     * Byte-for-byte, with `hash_equals`. No prefix matching, no "starts with",
     * no normalising, no ignoring a trailing slash. Every one of those is a way
     * to send a human somewhere the client never registered:
     * `https://good.test/cb` matched by prefix also matches
     * `https://good.test/cb.evil.test`.
     *
     * The one concession the spec allows, and the reference implementation
     * relies on, is treating a MISSING redirect_uri as the client's single
     * registered URI when it registered exactly one. That is in the spec, it is
     * unambiguous, and it does not widen anything — the value still comes from
     * the registration, never from the request.
     *
     * @param array<string, mixed> $client
     */
    public static function redirect_uri_allowed(array $client, string $presented): bool
    {
        $registered = $client['redirect_uris'] ?? [];
        if (!is_array($registered) || $registered === []) {
            return false;
        }

        if ($presented === '') {
            return count($registered) === 1;
        }

        foreach ($registered as $uri) {
            if (is_string($uri) && hash_equals($uri, $presented)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The URI to actually redirect to, given what the client presented.
     *
     * Always returns a REGISTERED string, never the presented one, even when
     * the two are equal. That way a normalisation difference can never make the
     * value sent to a browser differ from the value that was checked.
     *
     * @param array<string, mixed> $client
     */
    public static function resolve_redirect_uri(array $client, string $presented): string
    {
        $registered = $client['redirect_uris'] ?? [];
        if (!is_array($registered) || $registered === []) {
            return '';
        }
        if ($presented === '') {
            return count($registered) === 1 ? (string) reset($registered) : '';
        }
        foreach ($registered as $uri) {
            if (is_string($uri) && hash_equals($uri, $presented)) {
                return $uri;
            }
        }

        return '';
    }
}
