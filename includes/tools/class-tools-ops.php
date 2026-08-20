<?php
/**
 * Operations tools: WordPress core/plugin/theme updates, admin email
 * notifications, and images for content — free Openverse lookup (no key) plus
 * optional AI image generation via a provider key configured in settings.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Ops
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        $r->add('image_search', 'Find a real, openly-licensed image for a post/product from Openverse (free, no API key, Creative Commons / public-domain). Returns candidate images; set attach_to to download the top match into the media library and (optionally) set it as the featured image. Use this whenever you need an image and no generation key is configured.', [
            'type' => 'object',
            'properties' => [
                'query'      => ['type' => 'string'],
                'per_page'   => ['type' => 'integer', 'default' => 5],
                'attach_to'  => ['type' => 'integer', 'description' => 'Post ID to import the top result into.'],
                'set_featured' => ['type' => 'boolean', 'default' => false],
                'orientation' => ['type' => 'string', 'enum' => ['tall', 'wide', 'square'], 'description' => 'Aspect ratio filter.'],
            ],
            'required' => ['query'],
        ], [self::class, 'image_search'], ['readOnlyHint' => false]);

        $r->add('image_generate', 'Generate an image with AI from a text prompt, using the image API key configured in Claudpress settings (OpenAI-compatible by default). Optionally import it into the media library and set it as a post\'s featured image. If no key is configured, this returns guidance and you should use image_search instead.', [
            'type' => 'object',
            'properties' => [
                'prompt'     => ['type' => 'string'],
                'size'       => ['type' => 'string', 'default' => '1024x1024'],
                'attach_to'  => ['type' => 'integer'],
                'set_featured' => ['type' => 'boolean', 'default' => false],
                'filename'   => ['type' => 'string'],
            ],
            'required' => ['prompt'],
        ], [self::class, 'image_generate'], ['destructiveHint' => true]);
    }

    // ---- updates ----------------------------------------------------------


    // ---- images -----------------------------------------------------------

    public static function image_search(array $a): array
    {
        $query = (string) ($a['query'] ?? '');
        if ($query === '') {
            return ['success' => false, 'error' => 'query required.'];
        }
        $args = [
            'q'            => $query,
            'page_size'    => min(20, (int) ($a['per_page'] ?? 5)),
            'license_type' => 'all',
            'mature'       => 'false',
        ];
        if (!empty($a['orientation'])) {
            $args['aspect_ratio'] = $a['orientation'];
        }
        $url  = 'https://api.openverse.org/v1/images/?' . http_build_query($args);
        $resp = wp_remote_get($url, ['timeout' => 20, 'headers' => ['User-Agent' => 'Claudpress/' . CLAUDPRESS_VERSION]]);
        if (is_wp_error($resp)) {
            return ['success' => false, 'error' => $resp->get_error_message()];
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        $results = [];
        foreach ((array) ($body['results'] ?? []) as $img) {
            $results[] = [
                'title'    => $img['title'] ?? '',
                'url'      => $img['url'] ?? '',
                'creator'  => $img['creator'] ?? '',
                'license'  => trim(($img['license'] ?? '') . ' ' . ($img['license_version'] ?? '')),
                'source'   => $img['source'] ?? '',
                'foreign_landing_url' => $img['foreign_landing_url'] ?? '',
            ];
        }
        if (empty($results)) {
            return ['success' => false, 'error' => 'No images found for that query.'];
        }

        $imported = null;
        if (!empty($a['attach_to'])) {
            $imported = self::sideload($results[0]['url'], (int) $a['attach_to'], $results[0]['title'], !empty($a['set_featured']));
        }
        return ['success' => true, 'query' => $query, 'results' => $results, 'imported' => $imported];
    }

    public static function image_generate(array $a): array
    {
        $settings = Claudpress::instance()->settings();
        $key      = (string) ($settings['image_api_key'] ?? '');
        if ($key === '') {
            return ['success' => false, 'error' => 'No image generation key configured. Add one in WP Admin > Claudpress > Automation, or use image_search (Openverse) for free stock images instead.'];
        }

        /** @var class-string<Claudpress_Image_Provider> $provider */
        $provider = Claudpress_Image_Provider::current();
        $endpoint = trim((string) ($settings['image_api_endpoint'] ?? '')) ?: $provider::default_endpoint();
        $model    = trim((string) ($settings['image_api_model'] ?? '')) ?: $provider::default_model();

        $resp = wp_remote_post($provider::url($endpoint, $model), [
            'timeout' => 120,
            'headers' => $provider::headers($key),
            'body'    => wp_json_encode($provider::body($model, (string) $a['prompt'], (string) ($a['size'] ?? '1024x1024'))),
        ]);
        if (is_wp_error($resp)) {
            return ['success' => false, 'error' => $resp->get_error_message()];
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($body)) {
            return ['success' => false, 'error' => 'Malformed image API response.'];
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            $msg = (string) ($body['error']['message'] ?? wp_remote_retrieve_body($resp));
            return ['success' => false, 'error' => 'Image API error HTTP ' . $code . ': ' . mb_substr($msg, 0, 400)];
        }

        $filename = sanitize_file_name(($a['filename'] ?? 'generated-' . time()) . '.png');

        // Vendors either inline the bytes or hand back a short-lived URL.
        $bytes = $provider::extract_bytes($body);
        if ($bytes === null) {
            $url = $provider::extract_url($body);
            if ($url !== null) {
                $dl = wp_remote_get($url, ['timeout' => 60]);
                if (!is_wp_error($dl)) {
                    $bytes = wp_remote_retrieve_body($dl);
                }
            }
        }
        if (!$bytes) {
            return ['success' => false, 'error' => 'Could not retrieve generated image bytes.'];
        }

        $upload = wp_upload_bits($filename, null, $bytes);
        if (!empty($upload['error'])) {
            return ['success' => false, 'error' => $upload['error']];
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_to = (int) ($a['attach_to'] ?? 0);
        $att_id = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => $a['filename'] ?? 'Generated image', 'post_status' => 'inherit'], $upload['file'], $attach_to);
        wp_update_attachment_metadata($att_id, wp_generate_attachment_metadata($att_id, $upload['file']));
        if ($attach_to && !empty($a['set_featured'])) {
            set_post_thumbnail($attach_to, $att_id);
        }
        return ['success' => true, 'attachment_id' => $att_id, 'url' => wp_get_attachment_url($att_id)];
    }

    private static function sideload(string $url, int $post_id, ?string $title, bool $set_featured): array
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $att = media_sideload_image($url, $post_id, $title, 'id');
        if (is_wp_error($att)) {
            return ['success' => false, 'error' => $att->get_error_message()];
        }
        if ($set_featured) {
            set_post_thumbnail($post_id, $att);
        }
        return ['success' => true, 'attachment_id' => $att, 'url' => wp_get_attachment_url($att), 'featured' => $set_featured];
    }
}
