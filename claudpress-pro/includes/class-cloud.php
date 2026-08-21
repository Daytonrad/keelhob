<?php
/**
 * The link between this site and a Claudpress account.
 *
 * Pairing is a device-code flow, like a smart TV: the plugin shows a code, the
 * owner approves it in a browser where they are already logged in, and the
 * plugin exchanges it for a token. Nobody types their claudpress.com password
 * into WordPress — possibly a WordPress they do not own, possibly one that is
 * compromised. Never ask for a password anywhere it does not belong.
 *
 * What is stored here: an install UUID that survives a domain move, and a site
 * token, encrypted at rest with the same machinery as the API keys. The token
 * is shown to nobody and never rendered into a page.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Pro_Cloud
{
    const OPTION       = 'claudpress_cloud';
    const API_BASE     = 'https://api.claudpress.com';
    const PAIR_FLASH   = 'claudpress_pair_flash';

    /** @return array{install_uuid:string,token:string,account_id:string,site_url:string,linked_at:int} */
    public static function state(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $state = array_merge([
            'install_uuid' => '',
            'token'        => '',
            'account_id'   => '',
            'site_url'     => '',
            'linked_at'    => 0,
        ], $stored);

        if ($state['token'] !== '' && class_exists('Claudpress_Secrets')) {
            $state['token'] = Claudpress_Secrets::decrypt((string) $state['token']);
        }

        return $state;
    }

    private static function save(array $patch): void
    {
        $state = array_merge(self::state(), $patch);

        if (!empty($state['token']) && class_exists('Claudpress_Secrets')) {
            $state['token'] = Claudpress_Secrets::encrypt((string) $state['token']);
        }

        // autoload = false: this holds a credential, and there is no reason for
        // it to be in memory on every front-end request where any other plugin
        // can read it out of the options cache.
        update_option(self::OPTION, $state, false);
    }

    /**
     * This install's permanent identity.
     *
     * Generated once and kept. Deliberately NOT the site URL: URLs change when
     * a site moves from staging to live or changes domain, and if identity were
     * the URL, every such move would look like a brand new site and quietly
     * consume another seat.
     */
    public static function install_uuid(): string
    {
        $state = self::state();
        if ($state['install_uuid'] !== '') {
            return $state['install_uuid'];
        }

        $uuid = 'cpi_' . bin2hex(random_bytes(16));
        self::save(['install_uuid' => $uuid]);

        return $uuid;
    }

    public static function is_linked(): bool
    {
        $state = self::state();
        return $state['token'] !== '' && $state['account_id'] !== '';
    }

    // ---- the pairing flow -------------------------------------------------

    /**
     * Step 1. Ask for a code.
     *
     * @return array{ok:bool,code?:string,display?:string,expires_in?:int,approve_url?:string,error?:string}
     */
    public static function start_pairing(): array
    {
        $response = self::post('/v1/pair/start', [
            'install_uuid' => self::install_uuid(),
            'site_url'     => home_url(),
        ]);

        if (!$response['ok']) {
            return $response;
        }

        // Held in a transient, not an option: an abandoned pairing should
        // evaporate on its own rather than leave a stale code on the screen
        // days later, offering to link a site to whoever finds it.
        set_transient(self::PAIR_FLASH, $response['data'], 15 * MINUTE_IN_SECONDS);

        return ['ok' => true] + $response['data'];
    }

    /** The code currently being shown, if any. */
    public static function pending_code(): ?array
    {
        $flash = get_transient(self::PAIR_FLASH);
        return is_array($flash) ? $flash : null;
    }

    public static function cancel_pairing(): void
    {
        delete_transient(self::PAIR_FLASH);
    }

    /**
     * Step 3. Exchange an approved code for a token.
     *
     * Called by the browser polling this screen. Returns a status the UI can
     * show verbatim rather than a boolean, because "still waiting" and "that
     * code expired" want very different words.
     */
    public static function poll(): array
    {
        $flash = self::pending_code();
        if (!$flash || empty($flash['code'])) {
            return ['ok' => false, 'status' => 'none'];
        }

        $response = self::post('/v1/pair/poll', [
            'code'         => (string) $flash['code'],
            'install_uuid' => self::install_uuid(),
        ]);

        if (!$response['ok']) {
            return ['ok' => false, 'status' => 'error', 'error' => $response['error']];
        }

        $status = (string) ($response['data']['status'] ?? 'pending');

        if ($status === 'ready' && !empty($response['data']['token'])) {
            self::save([
                'token'      => (string) $response['data']['token'],
                'account_id' => (string) ($response['data']['account_id'] ?? ''),
                'site_url'   => home_url(),
                'linked_at'  => time(),
            ]);
            self::cancel_pairing();

            return ['ok' => true, 'status' => 'linked'];
        }

        if (in_array($status, ['expired', 'consumed', 'locked', 'unknown'], true)) {
            self::cancel_pairing();
        }

        return ['ok' => true, 'status' => $status, 'message' => $response['data']['message'] ?? ''];
    }

    /**
     * Unlink this site.
     *
     * Local only: it forgets the token here and does NOT ask the server to
     * delete anything. Unlinking is not deleting — the library stays exactly as
     * it was, and re-linking picks it straight back up. Somebody disconnecting
     * a site is saying "stop syncing here", never "destroy my work".
     */
    public static function unlink(): void
    {
        self::save(['token' => '', 'account_id' => '', 'linked_at' => 0]);
        self::cancel_pairing();
    }

    // ---- talking to the API ----------------------------------------------

    /** @return array{ok:bool,data?:array,error?:string,status?:int} */
    public static function get(string $path, array $query = []): array
    {
        $url = self::base() . $path . ($query ? '?' . http_build_query($query) : '');

        return self::request('GET', $url, null);
    }

    /** @return array{ok:bool,data?:array,error?:string,status?:int} */
    public static function post(string $path, array $body): array
    {
        return self::request('POST', self::base() . $path, $body);
    }

    private static function base(): string
    {
        $base = defined('CLAUDPRESS_API_BASE') ? (string) CLAUDPRESS_API_BASE : self::API_BASE;

        /**
         * Filter the API base, so a developer can point a site at a staging
         * backend without editing the plugin.
         */
        return rtrim((string) apply_filters('claudpress_api_base', $base), '/');
    }

    private static function request(string $method, string $url, ?array $body): array
    {
        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => ['Accept' => 'application/json'],
        ];

        $state = self::state();
        if ($state['token'] !== '') {
            $args['headers']['Authorization'] = 'Bearer ' . $state['token'];
        }

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            // Network failures are reported as-is and never retried here. The
            // caller decides whether a failure matters: a skills refresh should
            // shrug and use its cache, a pairing poll should try again shortly.
            return ['ok' => false, 'error' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data   = json_decode(wp_remote_retrieve_body($response), true);
        $data   = is_array($data) ? $data : [];

        if ($status < 200 || $status >= 300) {
            return [
                'ok'     => false,
                'status' => $status,
                'error'  => self::error_text($status, $data),
            ];
        }

        return ['ok' => true, 'status' => $status, 'data' => $data];
    }

    /**
     * Turn an HTTP failure into something an owner can act on.
     *
     * "HTTP 401" tells somebody nothing they can do anything about. "This site
     * is no longer linked" tells them exactly what to click.
     */
    private static function error_text(int $status, array $data): string
    {
        $message = (string) ($data['message'] ?? '');

        if ($status === 401) {
            return __('This site is no longer linked to your account. Link it again below.', 'claudpress-pro');
        }
        if ($status === 403) {
            return $message !== ''
                ? $message
                : __('Your plan does not allow that.', 'claudpress-pro');
        }
        if ($status === 429) {
            return __('Too many requests just now. Wait a minute and try again.', 'claudpress-pro');
        }
        if ($status >= 500) {
            return __('Claudpress is having trouble at our end. Your site is unaffected — try again shortly.', 'claudpress-pro');
        }

        return $message !== '' ? $message : sprintf(
            /* translators: %d: HTTP status code. */
            __('The request failed (HTTP %d).', 'claudpress-pro'),
            $status
        );
    }
}
