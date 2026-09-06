<?php
/**
 * The free key-and-model check shared by the image and transcription providers.
 *
 * It lives in its own file rather than in either family because both families
 * need it and neither owns it. The class docblock below explains why the media
 * providers cannot be tested the way the chat providers are.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Does this key work?" for the image and transcription providers.
 *
 * Both families answer it the same way and neither may answer it the way the
 * chat provider does. A chat test costs a handful of tokens; generating an
 * image or transcribing audio to test a key would put a real charge on the
 * owner's account every single time they pressed the button, and the person
 * pressing it is usually pressing it repeatedly because something is wrong.
 *
 * So the test is a GET that asks the vendor to describe the chosen model. It
 * costs nothing, and it separates the two failures that actually matter: a key
 * the vendor rejects, and a model this account cannot reach.
 */
final class Claudpress_Media_Check
{
    /**
     * @param array<string,string> $headers auth only
     * @return true|WP_Error
     */
    public static function run(string $url, array $headers, string $model)
    {
        if ($url === '') {
            return new WP_Error('claudpress_no_url', __('This provider cannot be tested automatically.', 'claudpress'));
        }

        $resp = wp_remote_get($url, ['timeout' => 20, 'headers' => $headers]);
        if (is_wp_error($resp)) {
            return $resp;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code >= 200 && $code < 300) {
            return true;
        }

        $data = json_decode((string) wp_remote_retrieve_body($resp), true);
        $msg  = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';

        if ($code === 401 || $code === 403) {
            return new WP_Error('claudpress_key', __('Your API key was rejected. Check it above.', 'claudpress'));
        }
        if ($code === 404) {
            return new WP_Error('claudpress_model', sprintf(
                /* translators: %s: model id. */
                __('The key works, but your account cannot reach %s. Pick another model, or enable it with your provider.', 'claudpress'),
                $model
            ));
        }
        if ($code === 429) {
            return new WP_Error('claudpress_rate', __('The provider is rate-limiting this key. Wait a moment and test again.', 'claudpress'));
        }
        if ($msg !== '') {
            return new WP_Error('claudpress_api', $msg);
        }

        return new WP_Error('claudpress_api', sprintf(
            /* translators: %d: HTTP status code. */
            __('The provider answered with HTTP %d.', 'claudpress'),
            $code
        ));
    }
    // ---- the model list ---------------------------------------------------

    const MODELS_CACHE = 'claudpress_media_models_';
    const MODELS_SHOWN = 12;

    /**
     * What the dropdown offers: the curated list, plus anything new the vendor
     * is advertising that we have not catalogued yet.
     *
     * The curated entries ALWAYS survive. A vendor's model list is one flat
     * catalogue of everything the account can touch — chat, embeddings, speech,
     * video — with nothing in it that says "this one draws pictures". Picking
     * the image models back out of it means matching on names, and a name match
     * is a guess that goes stale. If a refresh were allowed to replace the list,
     * one renamed model would empty the dropdown, or worse, quietly drop the
     * model a working site is using.
     *
     * So a refresh only ever ADDS. That is the whole design: the catalogue is
     * the floor, the vendor is the ceiling.
     *
     * @param class-string $cls
     * @return array<int, array{id: string, note: string}>
     */
    public static function models(string $cls, string $kind, string $pid, string $keep = ''): array
    {
        $out  = [];
        $seen = [];

        foreach ($cls::models() as $m) {
            $out[]  = ['id' => (string) $m['id'], 'note' => (string) $m['note']];
            $seen[] = (string) $m['id'];
        }

        $cached = get_transient(self::MODELS_CACHE . $kind . '_' . $pid);
        if (is_array($cached)) {
            foreach ($cached as $id) {
                $id = (string) $id;
                if ($id === '' || in_array($id, $seen, true)) {
                    continue;
                }
                $out[]  = ['id' => $id, 'note' => __('Newer — offered by your provider, not yet described here.', 'claudpress')];
                $seen[] = $id;
            }
        }

        $out = array_slice($out, 0, self::MODELS_SHOWN);

        // Whatever is already saved stays on the list even when the vendor has
        // stopped advertising it. A model silently vanishing from the dropdown
        // would switch the site to a different one on the next save.
        if ($keep !== '' && !in_array($keep, array_column($out, 'id'), true)) {
            array_unshift($out, ['id' => $keep, 'note' => __('your saved model', 'claudpress')]);
        }

        return $out;
    }

    /**
     * Ask the vendor what this key can really reach, and remember it for a week.
     *
     * @param class-string $cls
     * @return array<int, array{id: string, note: string}>|WP_Error
     */
    public static function refresh(string $cls, string $kind, string $pid, string $key, string $endpoint, string $keep = '')
    {
        if (trim($key) === '') {
            return new WP_Error('claudpress_no_key', __('Add an API key first, then refresh the model list.', 'claudpress'));
        }

        $url = $cls::models_list_url($endpoint);
        if ($url === '') {
            return new WP_Error(
                'claudpress_no_model_list',
                __('This provider does not publish a model list. The models above are the ones this plugin supports.', 'claudpress')
            );
        }

        $resp = wp_remote_get($url, ['timeout' => 20, 'headers' => $cls::auth_headers($key)]);
        if (is_wp_error($resp)) {
            return $resp;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $data = json_decode((string) wp_remote_retrieve_body($resp), true);

        if ($code === 401 || $code === 403) {
            return new WP_Error('claudpress_key', __('Your API key was rejected. Check it above.', 'claudpress'));
        }
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            $msg = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            return new WP_Error('claudpress_api', $msg !== '' ? $msg : sprintf(
                /* translators: %d: HTTP status code. */
                __('The model list could not be read (HTTP %d).', 'claudpress'),
                $code
            ));
        }

        $found = $cls::parse_models($data);

        // Not an error. The key works and the vendor answered; it simply had
        // nothing this plugin has not already got. Saying "no models" here
        // would read as a failure and send somebody hunting for a broken key.
        set_transient(self::MODELS_CACHE . $kind . '_' . $pid, $found, WEEK_IN_SECONDS);

        return self::models($cls, $kind, $pid, $keep);
    }

    /** Drop every cached media list, so the next look asks the vendors again. */
    public static function forget(): void
    {
        foreach (['image', 'voice'] as $kind) {
            $all = $kind === 'image'
                ? Claudpress_Image_Provider::providers()
                : Claudpress_Transcribe_Provider::providers();
            foreach (array_keys($all) as $pid) {
                delete_transient(self::MODELS_CACHE . $kind . '_' . $pid);
            }
        }
    }

}
