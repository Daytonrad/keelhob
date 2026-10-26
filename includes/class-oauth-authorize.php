<?php
/**
 * The authorize endpoint and the consent screen.
 *
 * THIS IS THE ONLY PART OF OAUTH A HUMAN EVER SEES, AND THE ONLY PART THAT CAN
 * SEND ONE SOMEWHERE.
 *
 * Three handlers, in the order a connection uses them:
 *
 *   1. `admin_post_nopriv_keelhob_authorize` — nobody is signed in. Send them
 *      to wp-login.php and have WordPress bring them back. That round-trip is
 *      the entire reason this lives on admin-post.php and not on a REST route.
 *   2. `admin_post_keelhob_authorize` — signed in. Validate everything, then
 *      render a consent page. Nothing is issued here.
 *   3. `admin_post_keelhob_authorize_decision` — the form came back. Check the
 *      nonce, mint a code, redirect.
 *
 * THE RULE THAT SHAPES THIS WHOLE FILE: an error is SHOWN, never redirected.
 *
 * It is tempting to hand every failure back to the client as
 * `?error=invalid_request`, because that is what the spec describes for a
 * normal OAuth server and it makes a nicer client experience. It is also how an
 * authorization server becomes an open redirect: the thing you would be
 * redirecting to is the attacker-supplied `redirect_uri`, and you would be
 * redirecting to it precisely in the cases where you have decided not to trust
 * it. So a failure that happens BEFORE the redirect_uri has been matched
 * against a registration renders an HTML page and stops. Only `access_denied`
 * — the owner pressing Cancel, after everything has already validated — is
 * handed back to the client, and it goes to the REGISTERED spelling of the URI
 * rather than the presented one.
 *
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_OAuth_Authorize
{
    /** The nonce action for the consent form. */
    const NONCE = 'keelhob_oauth_consent';

    /** Authorization codes live ten minutes. They are meant to be used in two. */
    const CODE_TTL = 600;

    /** Transient prefix. `keelhob_` so uninstall.php's sweep collects it. */
    const CODE_PREFIX = 'keelhob_oauth_code_';

    public static function hooks(): void
    {
        add_action('admin_post_nopriv_keelhob_authorize', [__CLASS__, 'handle_nopriv']);
        add_action('admin_post_keelhob_authorize', [__CLASS__, 'handle_authorize']);
        add_action('admin_post_keelhob_authorize_decision', [__CLASS__, 'handle_decision']);
    }

    /* ------------------------------------------------------------- step 1 */

    /**
     * Not signed in: hand the visitor to WordPress and ask for them back.
     *
     * `redirect_to` is this exact URL with its query string intact, built from
     * our own `admin_url()` and the parameters we recognise — never from
     * anything the caller supplied as a URL. wp_login_url() is what validates
     * and re-emits it, which is the point of using it: WordPress already knows
     * how to come back to an admin-post.php action safely, and this file does
     * not need to learn.
     */
    public static function handle_nopriv(): void
    {
        $back = add_query_arg(self::passthrough_args(), admin_url('admin-post.php'));
        wp_safe_redirect(wp_login_url($back));
        exit;
    }

    /* ------------------------------------------------------------- step 2 */

    /**
     * Signed in: validate, then render consent. Nothing is issued here.
     */
    public static function handle_authorize(): void
    {
        if (!Keelhob_OAuth::enabled()) {
            self::render_error(
                __('Connecting by sign-in is switched off', 'keelhob'),
                __('This site has not enabled OAuth. The owner can turn it on under Keelhob > Connect, or connect this client with a bearer token instead.', 'keelhob'),
                404
            );
        }

        /*
         * Rule 4, first half. Checked before anything is validated, because a
         * subscriber who reaches this screen must not be able to learn which
         * client IDs exist by comparing error messages.
         */
        if (!current_user_can('manage_options')) {
            self::render_error(
                __('This account cannot connect an AI client', 'keelhob'),
                __('Only an administrator can approve a connection to this site. Sign in with an administrator account and try again.', 'keelhob'),
                403
            );
        }

        $req = self::read_request();
        if (is_wp_error($req)) {
            self::render_error(
                __('This connection request is not valid', 'keelhob'),
                (string) $req->get_error_message(),
                400
            );
        }

        self::render_consent($req);
    }

    /**
     * Read and validate every parameter. Returns the validated request or a
     * WP_Error whose message is written to be read by the site owner on screen.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function read_request()
    {
        $client_id = isset($_GET['client_id']) ? sanitize_text_field(wp_unslash($_GET['client_id'])) : '';
        $client    = Keelhob_OAuth_Clients::get($client_id);
        if ($client === null) {
            return new WP_Error(
                'unknown_client',
                __('The app that sent you here is not registered with this site. This usually means the app was removed from Keelhob > Connect, or that the request did not come from an app at all.', 'keelhob')
            );
        }

        if ((isset($_GET['response_type']) ? (string) $_GET['response_type'] : '') !== 'code') {
            return new WP_Error(
                'unsupported_response_type',
                __('This app asked for a kind of login this site does not support. Only the authorization-code flow is offered.', 'keelhob')
            );
        }

        /*
         * Rule 2. The presented value is matched against the registration, and
         * from here on only the REGISTERED spelling is used — see
         * resolve_redirect_uri().
         */
        $presented = isset($_GET['redirect_uri']) ? esc_url_raw(wp_unslash($_GET['redirect_uri'])) : '';
        if (!Keelhob_OAuth_Clients::redirect_uri_allowed($client, $presented)) {
            return new WP_Error(
                'invalid_redirect_uri',
                __('This app asked to be sent back to an address it never registered. That is the shape of an attempt to steal the connection, so nothing has been approved and you have not been redirected anywhere.', 'keelhob')
            );
        }
        $redirect_uri = Keelhob_OAuth_Clients::resolve_redirect_uri($client, $presented);
        if ($redirect_uri === '') {
            return new WP_Error('invalid_redirect_uri', __('This app did not give a usable address to return to.', 'keelhob'));
        }

        /*
         * Rule 1, at the front door. A missing challenge is refused here rather
         * than at the token endpoint, so a flow that could not possibly finish
         * never gets as far as asking a human to approve it.
         */
        $method = isset($_GET['code_challenge_method']) ? (string) $_GET['code_challenge_method'] : '';
        if ($method !== 'S256') {
            return new WP_Error(
                'invalid_request',
                __('This app tried to connect without the proof-of-possession step (PKCE S256) that this site requires. An app that cannot do S256 cannot connect by sign-in; it can still be given a bearer token by hand.', 'keelhob')
            );
        }
        $challenge = isset($_GET['code_challenge']) ? sanitize_text_field(wp_unslash($_GET['code_challenge'])) : '';
        if (!self::is_valid_challenge($challenge)) {
            return new WP_Error('invalid_request', __('This app sent a malformed proof-of-possession value.', 'keelhob'));
        }

        return [
            'client'       => $client,
            'client_id'    => $client_id,
            'redirect_uri' => $redirect_uri,
            'challenge'    => $challenge,
            'state'        => isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '',
        ];
    }

    /**
     * A base64url sha256 digest: 43 characters, no padding.
     *
     * Pinned to the exact length rather than "looks base64url-ish". A challenge
     * of the wrong length cannot be the digest of anything, and accepting a
     * short one is how a weak challenge gets through.
     */
    public static function is_valid_challenge(string $challenge): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge);
    }

    /* ------------------------------------------------------------- step 3 */

    /**
     * The form came back. Mint a code, or hand back access_denied.
     */
    public static function handle_decision(): void
    {
        if (!Keelhob_OAuth::enabled()) {
            self::render_error(__('Connecting by sign-in is switched off', 'keelhob'), '', 404);
        }
        if (!current_user_can('manage_options')) {
            self::render_error(
                __('This account cannot connect an AI client', 'keelhob'),
                __('Only an administrator can approve a connection to this site.', 'keelhob'),
                403
            );
        }

        /*
         * The nonce is what stops a third-party page from submitting this form
         * on a signed-in administrator's behalf. Without it, "Allow" is one
         * hidden auto-submitting form on any site the owner visits while logged
         * in to their own WordPress.
         */
        check_admin_referer(self::NONCE);

        /*
         * Re-read and re-validate from scratch. The hidden fields in the form
         * are attacker-reachable in exactly the same way the query string was,
         * so they get exactly the same treatment: nothing is carried over from
         * step 2 on trust.
         */
        $_GET = array_merge($_GET, wp_unslash($_POST));
        $req  = self::read_request();
        if (is_wp_error($req)) {
            self::render_error(__('This connection request is not valid', 'keelhob'), (string) $req->get_error_message(), 400);
        }

        $approved = isset($_POST['keelhob_decision']) && $_POST['keelhob_decision'] === 'allow';
        if (!$approved) {
            // The ONE error that is handed back to the client, and only because
            // everything validated first. The registered spelling is used.
            self::redirect_to_client($req['redirect_uri'], [
                'error'             => 'access_denied',
                'error_description' => 'The site owner declined the connection.',
                'state'             => $req['state'],
            ]);
        }

        $code = bin2hex(random_bytes(32));
        /*
         * Rule 3 applies to codes too, and the reference implementation misses
         * this: a transient whose NAME contains the code puts the secret in the
         * options table in plaintext, where a database leak or a careless
         * `SELECT option_name` hands it over. The name holds a hash; the code
         * itself is only ever in this variable and in the redirect.
         */
        set_transient(
            self::CODE_PREFIX . Keelhob_OAuth::hash($code),
            [
                'client_id'    => $req['client_id'],
                'redirect_uri' => $req['redirect_uri'],
                'challenge'    => $req['challenge'],
                'user_id'      => get_current_user_id(),
                'issued_at'    => time(),
            ],
            self::CODE_TTL
        );

        self::redirect_to_client($req['redirect_uri'], [
            'code'  => $code,
            'state' => $req['state'],
        ]);
    }

    /**
     * Send the browser back to the client.
     *
     * `wp_redirect`, NOT `wp_safe_redirect`. That is deliberate and it is the
     * one place in this plugin where it is correct: safe_redirect restricts the
     * destination to this site's own host, and the entire job here is to return
     * to a third-party client. What makes it safe is not the function — it is
     * that `$uri` came out of `resolve_redirect_uri()`, so it is a string this
     * site stored at registration time and validated again a few lines ago.
     * Never pass a presented value to this method.
     *
     * @param array<string, string> $args
     */
    private static function redirect_to_client(string $uri, array $args): void
    {
        $args = array_filter($args, static fn($v): bool => $v !== '' && $v !== null);
        wp_redirect(add_query_arg(array_map('rawurlencode', $args), $uri));
        exit;
    }

    /**
     * The parameters this endpoint recognises, for rebuilding its own URL.
     *
     * An allowlist, not `$_GET`. Passing the whole query string through would
     * let a caller smuggle anything it liked into the URL that wp_login_url()
     * is asked to return to.
     *
     * @return array<string, string>
     */
    private static function passthrough_args(): array
    {
        $args = ['action' => 'keelhob_authorize'];
        foreach (['client_id', 'redirect_uri', 'response_type', 'state', 'code_challenge', 'code_challenge_method', 'scope'] as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key])) {
                $args[$key] = sanitize_text_field(wp_unslash($_GET[$key]));
            }
        }

        return $args;
    }

    /* ------------------------------------------------------------ the page */

    /**
     * What the owner is actually agreeing to, in plain words.
     *
     * This is the sentence the whole feature rests on. A consent screen that
     * says "this app would like to access your site" has told the owner
     * nothing; Keelhob can run arbitrary PHP, so the screen has to say whether
     * it can, and say it differently when it can.
     *
     * @return array{tone:string, text:string}
     */
    public static function consent_summary(): array
    {
        if (Keelhob_Capabilities::is_group_enabled('code_exec')) {
            return [
                'tone' => 'alarm',
                'text' => __('Code execution is switched ON. This app will be able to run any PHP or SQL on this site, which means it can change or delete anything, including things no other setting covers.', 'keelhob'),
            ];
        }

        $on = 0;
        foreach (array_keys(Keelhob_Capabilities::groups()) as $group) {
            if (Keelhob_Capabilities::is_group_enabled($group)) {
                $on++;
            }
        }

        return [
            'tone' => 'calm',
            'text' => sprintf(
                /* translators: %d: number of capability groups switched on. */
                _n(
                    'This app will be able to use the %d area you have switched on, and nothing else. Risky writes are backed up and can be undone.',
                    'This app will be able to use the %d areas you have switched on, and nothing else. Risky writes are backed up and can be undone.',
                    $on,
                    'keelhob'
                ),
                $on
            ),
        ];
    }

    /**
     * The consent page.
     *
     * Standalone, not inside wp-admin. A consent decision should not be made on
     * a screen full of other buttons, and the admin chrome would put this site's
     * menu around a page whose whole job is one question.
     *
     * @param array<string, mixed> $req
     */
    private static function render_consent(array $req): void
    {
        $user    = wp_get_current_user();
        $summary = self::consent_summary();
        $client  = $req['client'];

        self::page_open(__('Allow this app to connect?', 'keelhob'));
        ?>
        <h1><?php esc_html_e('Allow this app to connect?', 'keelhob'); ?></h1>

        <p class="kh-pill"><span class="kh-dot" aria-hidden="true"></span><?php echo esc_html((string) $client['client_name']); ?></p>

        <p class="kh-lede">
            <?php
            printf(
                /* translators: 1: site name, 2: the WordPress user name. */
                esc_html__('wants to connect to %1$s as %2$s.', 'keelhob'),
                '<strong>' . esc_html(get_bloginfo('name')) . '</strong>',
                '<strong>' . esc_html($user->display_name !== '' ? $user->display_name : $user->user_login) . '</strong>'
            );
            ?>
        </p>

        <div class="kh-box kh-box--<?php echo esc_attr($summary['tone']); ?>">
            <?php echo esc_html($summary['text']); ?>
        </div>

        <p class="kh-fine"><?php esc_html_e('You can disconnect at any time from Keelhob > Connect.', 'keelhob'); ?></p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="keelhob_authorize_decision">
            <?php wp_nonce_field(self::NONCE); ?>
            <input type="hidden" name="client_id" value="<?php echo esc_attr((string) $req['client_id']); ?>">
            <input type="hidden" name="redirect_uri" value="<?php echo esc_attr((string) $req['redirect_uri']); ?>">
            <input type="hidden" name="response_type" value="code">
            <input type="hidden" name="code_challenge" value="<?php echo esc_attr((string) $req['challenge']); ?>">
            <input type="hidden" name="code_challenge_method" value="S256">
            <input type="hidden" name="state" value="<?php echo esc_attr((string) $req['state']); ?>">
            <div class="kh-acts">
                <button type="submit" name="keelhob_decision" value="deny" class="kh-btn"><?php esc_html_e('Cancel', 'keelhob'); ?></button>
                <button type="submit" name="keelhob_decision" value="allow" class="kh-btn kh-btn--go"><?php esc_html_e('Allow', 'keelhob'); ?></button>
            </div>
        </form>
        <?php
        self::page_close();
    }

    /**
     * An error, on screen, with nothing issued and nobody redirected.
     *
     * See the file header for why this is a page and not a redirect. `exit` is
     * unconditional: every caller treats this as terminal, and a version of
     * this that returned would be one missing `return` away from rendering an
     * error and then approving the request anyway.
     */
    private static function render_error(string $title, string $detail, int $status): void
    {
        status_header($status);
        nocache_headers();
        self::page_open($title);
        ?>
        <h1><?php echo esc_html($title); ?></h1>
        <?php if ($detail !== '') : ?>
            <p class="kh-lede"><?php echo esc_html($detail); ?></p>
        <?php endif; ?>
        <p class="kh-fine"><?php esc_html_e('Nothing has been approved and no connection has been made.', 'keelhob'); ?></p>
        <p class="kh-acts">
            <a class="kh-btn" href="<?php echo esc_url(admin_url('admin.php?page=keelhob')); ?>"><?php esc_html_e('Go to Keelhob', 'keelhob'); ?></a>
        </p>
        <?php
        self::page_close();
        exit;
    }

    /**
     * The page shell.
     *
     * Inline CSS on purpose. This page is served outside wp-admin, often to a
     * browser that has never loaded this site before, and it must render
     * correctly on the first paint with no second request: a consent screen
     * that arrives unstyled for 200ms is a consent screen somebody clicks
     * through without reading. The palette is Keelhob's own tokens, copied as
     * literals because assets/css/admin.css is not loaded here.
     */
    private static function page_open(string $title): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Frame-Options: DENY');
            header("Content-Security-Policy: frame-ancestors 'none'");
        }
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html($title); ?></title>
<style>
:root{--kh-ink:#121517;--kh-ink-2:#2a2e31;--kh-muted:#6b5f57;--kh-line:#e3ddd6;--kh-soft:#f6f1ea;--kh-bg:#f2eee9;--kh-card:#fff;--kh-accent:#c87941;--kh-accent-ink:#a85c2a}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
background:var(--kh-bg);color:var(--kh-ink);
font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.kh-card{width:100%;max-width:440px;background:var(--kh-card);border:1px solid var(--kh-line);
border-radius:18px;padding:28px;box-shadow:0 10px 30px rgba(18,21,23,.07)}
h1{margin:0 0 14px;font-size:21px;line-height:1.3;color:var(--kh-ink)}
.kh-pill{display:inline-flex;align-items:center;gap:8px;margin:0 0 10px;padding:6px 12px;
border:1px solid var(--kh-line);border-radius:999px;background:var(--kh-soft);font-weight:600;font-size:14px}
.kh-dot{width:8px;height:8px;border-radius:50%;background:#2e9aa3}
.kh-lede{margin:0 0 16px;color:var(--kh-ink-2)}
.kh-box{margin:0 0 16px;padding:12px 14px;border-radius:12px;border:1px solid var(--kh-line);
background:var(--kh-soft);color:var(--kh-ink)}
.kh-box--alarm{border-color:var(--kh-accent);background:#fdf3ec}
.kh-fine{margin:0 0 20px;font-size:13px;color:var(--kh-muted)}
.kh-acts{display:flex;gap:10px;justify-content:flex-end;margin:0}
.kh-btn{appearance:none;cursor:pointer;text-decoration:none;display:inline-block;
padding:10px 18px;border-radius:10px;border:1px solid var(--kh-line);
background:var(--kh-card);color:var(--kh-ink);font:inherit;font-weight:600}
.kh-btn--go{background:var(--kh-ink);border-color:var(--kh-ink);color:#fff}
.kh-btn:focus-visible{outline:2px solid var(--kh-accent);outline-offset:2px}
@media (prefers-color-scheme:dark){
:root{--kh-ink:#f3efe9;--kh-ink-2:#d8d2ca;--kh-muted:#a89c92;--kh-line:#33383c;--kh-soft:#1d2124;--kh-bg:#131618;--kh-card:#191d20}
.kh-box--alarm{background:#2a1d14}
.kh-btn--go{background:var(--kh-accent);border-color:var(--kh-accent);color:#16191b}
}
</style>
</head>
<body>
<main class="kh-card">
        <?php
    }

    private static function page_close(): void
    {
        ?>
</main>
</body>
</html>
        <?php
    }
}
