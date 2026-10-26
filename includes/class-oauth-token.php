<?php
/**
 * The token endpoint: codes in, tokens out.
 *
 * THE ONE ORDERING THAT MATTERS, AND IT IS NOT OBVIOUS.
 *
 * The authorization code is deleted BEFORE it is validated. Not after.
 *
 * The natural way to write this is: load the code, check the client, check the
 * redirect_uri, check PKCE, and delete it once everything passes. That version
 * has a hole. A code that fails validation is still there afterwards, so an
 * attacker who holds a stolen code can hammer this endpoint — wrong verifier,
 * wrong verifier, wrong verifier — until something about the request is right.
 * Every failed attempt leaves the code alive for the next one.
 *
 * Deleting first makes the code genuinely single-use: one request gets to try,
 * whatever the outcome, and a replay finds nothing. The cost is that a client
 * which botches its own token request has to start the flow again, which is
 * correct behaviour and is what every careful implementation does.
 *
 * WHY TRANSIENTS AND NOT A TABLE.
 *
 * Access tokens last an hour and refresh tokens ninety days. A table needs
 * garbage collection, and garbage collection that does not run is a table that
 * grows forever on somebody's shared host. WordPress already expires
 * transients, so expiry is enforced by code that is not ours and cannot be
 * forgotten. Every name is `keelhob_…`, so uninstall.php's prefix sweep
 * collects them.
 *
 * Rule 3 throughout: the transient NAME is a hash of the token. Nothing in the
 * database is a usable credential.
 *
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_OAuth_Token
{
    /** One hour. Short on purpose: a leaked access token stops working today. */
    const ACCESS_TTL = 3600;

    /** Ninety days, as the plan specifies. */
    const REFRESH_TTL = 7776000;

    const ACCESS_PREFIX  = 'keelhob_oauth_at_';
    const REFRESH_PREFIX = 'keelhob_oauth_rt_';

    const ACCESS_TOKEN_PREFIX  = 'kha_';
    const REFRESH_TOKEN_PREFIX = 'khr_';

    /* ------------------------------------------------------------- routes */

    public static function register_routes(): void
    {
        register_rest_route(KEELHOB_REST_NAMESPACE, '/oauth/token', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_token'],
            // Public by design: this endpoint IS the authentication. What
            // protects it is PKCE, single-use codes and the shared lockout.
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(KEELHOB_REST_NAMESPACE, '/oauth/register', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_register'],
            'permission_callback' => '__return_true',
        ]);

        // Both discovery documents, for clients that will not read
        // /.well-known/ — some only look under the API namespace.
        register_rest_route(KEELHOB_REST_NAMESPACE, '/oauth/metadata', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_metadata'],
            'permission_callback' => '__return_true',
        ]);
    }

    /* --------------------------------------------------------- the errors */

    /**
     * An OAuth error response.
     *
     * `{error, error_description}` with no-store, as RFC 6749 requires. The
     * description is written for a developer reading a client's logs, and says
     * nothing about this site that a stranger could not already learn.
     */
    private static function error(string $code, string $description, int $status = 400): WP_REST_Response
    {
        $res = new WP_REST_Response(['error' => $code, 'error_description' => $description], $status);
        $res->header('Cache-Control', 'no-store');
        $res->header('Pragma', 'no-cache');

        return $res;
    }

    private static function ok(array $body): WP_REST_Response
    {
        $res = new WP_REST_Response($body, 200);
        $res->header('Cache-Control', 'no-store');
        $res->header('Pragma', 'no-cache');

        return $res;
    }

    /* ---------------------------------------------------------- endpoints */

    public static function handle_metadata(WP_REST_Request $request)
    {
        if (!Keelhob_OAuth::enabled()) {
            return self::error('not_found', 'OAuth is not enabled on this site.', 404);
        }

        return self::ok([
            'authorization_server' => Keelhob_OAuth::authorization_server_metadata(),
            'protected_resource'   => Keelhob_OAuth::protected_resource_metadata(),
        ]);
    }

    public static function handle_register(WP_REST_Request $request)
    {
        if (!Keelhob_OAuth::enabled()) {
            return self::error('not_found', 'OAuth is not enabled on this site.', 404);
        }

        /*
         * Rule 5 covers registration too. It writes to the database without a
         * credential, so a client that keeps sending malformed bodies is
         * either broken or probing, and either way should be slowed down.
         */
        if (Keelhob_Auth::is_locked_out()) {
            return self::error('too_many_requests', 'Too many failed attempts. Try again shortly.', 429);
        }

        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = $request->get_body_params();
        }
        if (!is_array($body)) {
            $body = [];
        }

        $result = Keelhob_OAuth_Clients::register($body);
        if (is_wp_error($result)) {
            Keelhob_Auth::note_oauth_failure();
            $data   = $result->get_error_data();
            $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;

            return self::error((string) $result->get_error_code(), (string) $result->get_error_message(), $status);
        }

        $res = new WP_REST_Response($result, 201);
        $res->header('Cache-Control', 'no-store');

        return $res;
    }

    /**
     * POST /oauth/token.
     */
    public static function handle_token(WP_REST_Request $request)
    {
        if (!Keelhob_OAuth::enabled()) {
            return self::error('not_found', 'OAuth is not enabled on this site.', 404);
        }

        // Rule 5. Before any credential work, exactly as the MCP route does it.
        if (Keelhob_Auth::is_locked_out()) {
            return self::error('too_many_requests', 'Too many failed attempts. Try again shortly.', 429);
        }

        $grant = (string) $request->get_param('grant_type');
        if ($grant === 'authorization_code') {
            return self::grant_authorization_code($request);
        }
        if ($grant === 'refresh_token') {
            return self::grant_refresh_token($request);
        }

        return self::error('unsupported_grant_type', 'Only authorization_code and refresh_token are supported.');
    }

    /* ------------------------------------------------------------- grants */

    private static function grant_authorization_code(WP_REST_Request $request)
    {
        $code = (string) $request->get_param('code');
        if ($code === '') {
            Keelhob_Auth::note_oauth_failure();

            return self::error('invalid_grant', 'Missing code.');
        }

        /*
         * READ AND DELETE, IN THAT ORDER, BEFORE ANYTHING IS CHECKED.
         * See the file header. Every `return` below this line leaves the code
         * already gone, which is the point.
         */
        $key   = Keelhob_OAuth_Authorize::CODE_PREFIX . Keelhob_OAuth::hash($code);
        $stash = get_transient($key);
        delete_transient($key);

        if (!is_array($stash)) {
            Keelhob_Auth::note_oauth_failure();

            return self::error('invalid_grant', 'This code is not valid, has expired, or has already been used.');
        }

        $client = Keelhob_OAuth_Clients::get((string) ($stash['client_id'] ?? ''));
        if ($client === null) {
            Keelhob_Auth::note_oauth_failure();

            return self::error('invalid_grant', 'The client this code was issued to no longer exists.');
        }

        $auth = self::authenticate_client($request, $client);
        if ($auth !== true) {
            Keelhob_Auth::note_oauth_failure();

            return self::error('invalid_client', 'Client authentication failed.', 401);
        }

        /*
         * The redirect_uri is compared against the one STORED WITH THE CODE,
         * not re-derived from the registration. The spec requires that the
         * value presented here is identical to the one presented at authorize,
         * and binding it to the code is what makes a code issued for one of a
         * client's URIs unusable against another.
         */
        $presented = (string) $request->get_param('redirect_uri');
        if ($presented !== '' && !hash_equals((string) ($stash['redirect_uri'] ?? ''), $presented)) {
            Keelhob_Auth::note_oauth_failure();

            return self::error('invalid_grant', 'redirect_uri does not match the one this code was issued for.');
        }

        // Rule 1, the half that actually proves possession.
        $verifier = (string) $request->get_param('code_verifier');
        if (!self::verifier_matches($verifier, (string) ($stash['challenge'] ?? ''))) {
            Keelhob_Auth::note_oauth_failure();

            return self::error('invalid_grant', 'code_verifier does not match the code_challenge.');
        }

        // Rule 4, second half: still an administrator, right now.
        $user_id = (int) ($stash['user_id'] ?? 0);
        if (!self::is_still_admin($user_id)) {
            Keelhob_Auth::note_oauth_failure();

            return self::error('invalid_grant', 'The account that approved this connection is no longer an administrator.');
        }

        return self::issue($client, $user_id);
    }

    private static function grant_refresh_token(WP_REST_Request $request)
    {
        $token = (string) $request->get_param('refresh_token');
        if ($token === '') {
            Keelhob_Auth::note_oauth_failure();

            return self::error('invalid_grant', 'Missing refresh_token.');
        }

        $stash = get_transient(self::REFRESH_PREFIX . Keelhob_OAuth::hash($token));
        if (!is_array($stash)) {
            Keelhob_Auth::note_oauth_failure();

            return self::error('invalid_grant', 'This refresh token is not valid or has expired.');
        }

        $client = Keelhob_OAuth_Clients::get((string) ($stash['client_id'] ?? ''));
        if ($client === null) {
            Keelhob_Auth::note_oauth_failure();

            return self::error('invalid_grant', 'The client this token was issued to no longer exists.');
        }

        $auth = self::authenticate_client($request, $client);
        if ($auth !== true) {
            Keelhob_Auth::note_oauth_failure();

            return self::error('invalid_client', 'Client authentication failed.', 401);
        }

        /*
         * Same client only. Without this, a refresh token stolen from one
         * client could be redeemed by another registered client — and
         * registration is open, so an attacker can always have one.
         */
        if (!hash_equals((string) ($stash['client_id'] ?? ''), (string) $client['client_id'])) {
            Keelhob_Auth::note_oauth_failure();

            return self::error('invalid_grant', 'This refresh token belongs to a different client.');
        }

        // Rule 4 again. The owner may have been demoted since the grant.
        $user_id = (int) ($stash['user_id'] ?? 0);
        if (!self::is_still_admin($user_id)) {
            Keelhob_Auth::note_oauth_failure();

            return self::error('invalid_grant', 'The account that approved this connection is no longer an administrator.');
        }

        /*
         * The SAME refresh token is returned, not a new one.
         *
         * Rotation is the stronger choice on paper: a rotated token lets the
         * server notice a theft, because the old one being presented again
         * means two parties hold it. It is also the choice that locks people
         * out. This runs on arbitrary shared hosting, where a request dying
         * between "new token stored" and "response delivered" is a real
         * Tuesday — and with rotation that client is then holding a token the
         * server has already retired, with no way back except the owner
         * noticing and re-approving by hand.
         *
         * So: reuse, bounded by a ninety-day expiry and by the owner being able
         * to remove the client on the Connect screen, which revokes it at once.
         * This is a deliberate trade and not an oversight; if it is ever
         * revisited, the thing to add first is a way for a client to recover
         * from a failed rotation, not rotation on its own.
         */
        return self::issue($client, $user_id, $token);
    }

    /* ------------------------------------------------------------- issuing */

    /**
     * Mint an access token, and a refresh token unless one is being reused.
     */
    private static function issue(array $client, int $user_id, string $reuse_refresh = ''): WP_REST_Response
    {
        $access = Keelhob_OAuth::mint(self::ACCESS_TOKEN_PREFIX);
        set_transient(
            self::ACCESS_PREFIX . Keelhob_OAuth::hash($access),
            ['client_id' => (string) $client['client_id'], 'user_id' => $user_id, 'issued_at' => time()],
            self::ACCESS_TTL
        );

        $refresh = $reuse_refresh;
        if ($refresh === '') {
            $refresh = Keelhob_OAuth::mint(self::REFRESH_TOKEN_PREFIX);
            set_transient(
                self::REFRESH_PREFIX . Keelhob_OAuth::hash($refresh),
                ['client_id' => (string) $client['client_id'], 'user_id' => $user_id, 'issued_at' => time()],
                self::REFRESH_TTL
            );
        }

        Keelhob_OAuth_Clients::touch((string) $client['client_id']);
        // A successful grant clears the lockout counters for this address, the
        // same way a successful bearer auth does — otherwise a client that
        // fumbled a few requests on the way to working would stay locked out
        // after it started working.
        Keelhob_Auth::unlock();

        return self::ok([
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope'         => Keelhob_OAuth::SCOPE,
        ]);
    }

    /* ------------------------------------------------------------- helpers */

    /**
     * Rule 1: does the verifier hash to the challenge?
     *
     * base64url(sha256(verifier)), unpadded, compared with hash_equals.
     *
     * The length floor is in the spec (43–128) and it is the part people leave
     * out. A one-character verifier hashes perfectly well, so without this a
     * client could technically satisfy PKCE while proving nothing.
     */
    public static function verifier_matches(string $verifier, string $challenge): bool
    {
        if ($challenge === '') {
            return false;
        }
        $len = strlen($verifier);
        if ($len < 43 || $len > 128) {
            return false;
        }
        /*
         * Anchored with backslash-z, not a dollar sign.
         *
         * In PCRE the dollar also matches immediately before a trailing newline,
         * so the dollar spelling accepts a verifier with a newline on the end.
         * Caught by its own test. Such a verifier still had to hash correctly to
         * get any further, so the practical impact was small — but a pattern that
         * disagrees with itself about where a string ends is not something to
         * leave sitting at an auth endpoint. is_valid_challenge() had it too.
         */
        if (!preg_match('/^[A-Za-z0-9\-._~]+\z/', $verifier)) {
            return false;
        }
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($challenge, $computed);
    }

    /**
     * Client authentication, from a Basic header or from the body.
     *
     * A public client (`auth_method === 'none'`) presents no secret and that is
     * correct — PKCE is what stands in for one. But a client registered WITH a
     * secret must still present it: letting it fall through to "public" would
     * mean an attacker who knows a confidential client's id could skip the
     * secret entirely by simply not sending one.
     */
    private static function authenticate_client(WP_REST_Request $request, array $client): bool
    {
        $method = (string) ($client['auth_method'] ?? 'none');
        $stored = (string) ($client['secret_hash'] ?? '');

        if ($method === 'none') {
            // Nothing to check. The client_id is not a secret and is not
            // treated as one.
            return $stored === '';
        }

        $secret = (string) $request->get_param('client_secret');
        if ($secret === '') {
            $header = (string) $request->get_header('authorization');
            if (stripos($header, 'Basic ') === 0) {
                $decoded = base64_decode(trim(substr($header, 6)), true);
                if (is_string($decoded) && strpos($decoded, ':') !== false) {
                    [, $secret] = explode(':', $decoded, 2);
                    $secret = rawurldecode($secret);
                }
            }
        }

        return Keelhob_OAuth::hash_matches($secret, $stored);
    }

    /**
     * Rule 4: is this user still an administrator?
     *
     * Checked at every grant and at every refresh, not only at authorize. A
     * token that outlives the authority it was granted under is the whole
     * reason this is a rule — an account demoted on Monday should not still be
     * driving an AI on Tuesday.
     */
    public static function is_still_admin(int $user_id): bool
    {
        if ($user_id <= 0) {
            return false;
        }
        $user = get_userdata($user_id);

        return $user instanceof WP_User && user_can($user, 'manage_options');
    }

    /* ---------------------------------------------------------- consuming */

    /**
     * Who does this access token belong to, if anyone?
     *
     * Returns the user id, or 0. Called from Keelhob_Auth on the MCP route.
     * Returns 0 for anything that is not an OAuth access token at all, so the
     * caller can try it cheaply without having to recognise the prefix itself.
     */
    public static function user_for_access_token(string $token): int
    {
        if ($token === '' || strpos($token, self::ACCESS_TOKEN_PREFIX) !== 0) {
            return 0;
        }
        if (!Keelhob_OAuth::enabled()) {
            // A token minted before the owner switched OAuth off stops working
            // the moment they switch it off. "Off" has to mean off.
            return 0;
        }
        $stash = get_transient(self::ACCESS_PREFIX . Keelhob_OAuth::hash($token));
        if (!is_array($stash)) {
            return 0;
        }
        $user_id = (int) ($stash['user_id'] ?? 0);
        if (!self::is_still_admin($user_id)) {
            return 0;
        }
        /*
         * The client may have been removed on the Connect screen since.
         *
         * THIS CHECK IS THE REVOCATION MECHANISM, and it is why there is no
         * `revoke_for_client()` anywhere in this class. Access tokens are
         * deliberately not enumerable — their transient names are hashes, so
         * there is no list to walk and nothing to delete. Removing a client
         * instead makes every token it holds fail here, on the next call, with
         * no sweep to schedule and nothing to get wrong. If someone later adds
         * a token table "so revocation can work", this is the line they should
         * read first.
         */
        if (Keelhob_OAuth_Clients::get((string) ($stash['client_id'] ?? '')) === null) {
            return 0;
        }

        return $user_id;
    }
}
