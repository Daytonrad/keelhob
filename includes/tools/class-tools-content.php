<?php
/**
 * Content tools: posts, pages and any custom post type; taxonomies; media;
 * comments. High-level, structured wrappers over the WordPress content APIs.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Content
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        $r->add('create_post', 'Create a post, page, or any post type. Supports title, content (HTML or blocks), excerpt, status, slug, author, date, categories/tags (by name or ID), custom taxonomies, meta fields, featured image (URL or media ID), and page template/parent.', [
            'type' => 'object',
            'properties' => [
                'post_type'    => ['type' => 'string', 'default' => 'post'],
                'title'        => ['type' => 'string'],
                'content'      => ['type' => 'string', 'description' => 'HTML or Gutenberg block markup.'],
                'excerpt'      => ['type' => 'string'],
                'status'       => ['type' => 'string', 'enum' => ['publish', 'draft', 'pending', 'private', 'future'], 'default' => 'draft'],
                'slug'         => ['type' => 'string'],
                'author'       => ['type' => 'integer'],
                'date'         => ['type' => 'string', 'description' => 'Y-m-d H:i:s (for scheduling use status=future).'],
                'categories'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Category names or IDs.'],
                'tags'         => ['type' => 'array', 'items' => ['type' => 'string']],
                'taxonomies'   => ['type' => 'object', 'description' => 'Map of taxonomy => [terms].'],
                'meta'         => ['type' => 'object', 'description' => 'Map of meta_key => value.'],
                'featured_image' => ['type' => 'string', 'description' => 'Image URL or existing media ID.'],
                'parent'       => ['type' => 'integer'],
                'template'     => ['type' => 'string'],
            ],
            'required' => ['title'],
        ], [self::class, 'create_post'], ['destructiveHint' => true]);

        $r->add('update_post', 'Update an existing post/page/product by ID. Only the fields you pass are changed. Same field set as create_post.', [
            'type' => 'object',
            'properties' => [
                'id'      => ['type' => 'integer'],
                'title'   => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'excerpt' => ['type' => 'string'],
                'status'  => ['type' => 'string'],
                'slug'    => ['type' => 'string'],
                'categories' => ['type' => 'array', 'items' => ['type' => 'string']],
                'tags'    => ['type' => 'array', 'items' => ['type' => 'string']],
                'taxonomies' => ['type' => 'object'],
                'meta'    => ['type' => 'object'],
                'featured_image' => ['type' => 'string'],
                'parent'  => ['type' => 'integer'],
                'template' => ['type' => 'string'],
            ],
            'required' => ['id'],
        ], [self::class, 'update_post'], ['destructiveHint' => true]);

        $r->add('get_post', 'Get a single post/page/product by ID, including content, meta, taxonomies, and featured image.', [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ], [self::class, 'get_post'], ['readOnlyHint' => true]);

        $r->add('list_posts', 'List/search posts of any type with filters: post_type, status, search term, author, taxonomy term, meta query, ordering, and pagination.', [
            'type' => 'object',
            'properties' => [
                'post_type' => ['type' => 'string', 'default' => 'post'],
                'status'    => ['type' => 'string', 'default' => 'any'],
                'search'    => ['type' => 'string'],
                'author'    => ['type' => 'integer'],
                'category'  => ['type' => 'string'],
                'tag'       => ['type' => 'string'],
                'per_page'  => ['type' => 'integer', 'default' => 20],
                'page'      => ['type' => 'integer', 'default' => 1],
                'orderby'   => ['type' => 'string', 'default' => 'date'],
                'order'     => ['type' => 'string', 'default' => 'DESC'],
            ],
        ], [self::class, 'list_posts'], ['readOnlyHint' => true]);

        $r->add('delete_post', 'Delete a post/page/product by ID. Trashes by default; pass force=true to permanently delete.', [
            'type' => 'object',
            'properties' => [
                'id'    => ['type' => 'integer'],
                'force' => ['type' => 'boolean', 'default' => false],
            ],
            'required' => ['id'],
        ], [self::class, 'delete_post'], ['destructiveHint' => true]);

        $r->add('upload_media', 'Upload an image/file to the media library from a URL or base64 data, and optionally attach it to a post. Returns the attachment ID and URL.', [
            'type' => 'object',
            'properties' => [
                'url'       => ['type' => 'string', 'description' => 'Source URL to download.'],
                'data'      => ['type' => 'string', 'description' => 'Base64 file data (alternative to url).'],
                'filename'  => ['type' => 'string'],
                'title'     => ['type' => 'string'],
                'alt'       => ['type' => 'string'],
                'attach_to' => ['type' => 'integer', 'description' => 'Post ID to attach to.'],
            ],
        ], [self::class, 'upload_media'], ['destructiveHint' => true]);

        $r->add('manage_taxonomy', 'Create, update, delete, or list terms in any taxonomy (categories, tags, product_cat, custom). Actions: create, update, delete, list.', [
            'type' => 'object',
            'properties' => [
                'action'      => ['type' => 'string', 'enum' => ['create', 'update', 'delete', 'list'], 'default' => 'list'],
                'taxonomy'    => ['type' => 'string', 'default' => 'category'],
                'name'        => ['type' => 'string'],
                'term_id'     => ['type' => 'integer'],
                'slug'        => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'parent'      => ['type' => 'integer'],
            ],
            'required' => ['action'],
        ], [self::class, 'manage_taxonomy'], ['destructiveHint' => true]);
    }

    // ---- helpers ----------------------------------------------------------

    private static function apply_terms(int $post_id, $terms, string $taxonomy): void
    {
        $ids = [];
        foreach ((array) $terms as $t) {
            if (is_numeric($t)) {
                $ids[] = (int) $t;
                continue;
            }
            $existing = term_exists((string) $t, $taxonomy);
            if (!$existing) {
                $existing = wp_insert_term((string) $t, $taxonomy);
            }
            if (!is_wp_error($existing)) {
                $ids[] = (int) $existing['term_id'];
            }
        }
        if ($ids) {
            wp_set_object_terms($post_id, $ids, $taxonomy);
        }
    }

    private static function apply_featured_image(int $post_id, string $image): void
    {
        if (is_numeric($image)) {
            set_post_thumbnail($post_id, (int) $image);
            return;
        }
        $att = self::sideload($image, $post_id);
        if (!is_wp_error($att) && $att) {
            set_post_thumbnail($post_id, $att);
        }
    }

    private static function sideload(string $url, int $post_id = 0)
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        return media_sideload_image($url, $post_id, null, 'id');
    }

    private static function build_postarr(array $args): array
    {
        $map = [
            'title'   => 'post_title',
            'content' => 'post_content',
            'excerpt' => 'post_excerpt',
            'status'  => 'post_status',
            'slug'    => 'post_name',
            'author'  => 'post_author',
            'parent'  => 'post_parent',
        ];
        $postarr = [];
        foreach ($map as $arg => $field) {
            if (array_key_exists($arg, $args)) {
                $postarr[$field] = $args[$arg];
            }
        }
        if (!empty($args['post_type'])) {
            $postarr['post_type'] = sanitize_key($args['post_type']);
        }
        if (!empty($args['date'])) {
            $postarr['post_date'] = $args['date'];
        }
        return $postarr;
    }

    private static function apply_relations(int $post_id, array $args): void
    {
        if (isset($args['categories'])) {
            self::apply_terms($post_id, $args['categories'], 'category');
        }
        if (isset($args['tags'])) {
            self::apply_terms($post_id, $args['tags'], 'post_tag');
        }
        if (!empty($args['taxonomies']) && is_array($args['taxonomies'])) {
            foreach ($args['taxonomies'] as $tax => $terms) {
                self::apply_terms($post_id, $terms, sanitize_key($tax));
            }
        }
        if (!empty($args['meta']) && is_array($args['meta'])) {
            foreach ($args['meta'] as $k => $v) {
                update_post_meta($post_id, $k, $v);
            }
        }
        if (!empty($args['template'])) {
            update_post_meta($post_id, '_wp_page_template', $args['template']);
        }
        if (!empty($args['featured_image'])) {
            self::apply_featured_image($post_id, (string) $args['featured_image']);
        }
    }

    private static function serialize_post(WP_Post $p): array
    {
        return [
            'id'       => $p->ID,
            'type'     => $p->post_type,
            'title'    => $p->post_title,
            'slug'     => $p->post_name,
            'status'   => $p->post_status,
            'excerpt'  => $p->post_excerpt,
            'content'  => $p->post_content,
            'author'   => (int) $p->post_author,
            'date'     => $p->post_date,
            'modified' => $p->post_modified,
            'parent'   => (int) $p->post_parent,
            'link'     => get_permalink($p),
            'edit_link' => get_edit_post_link($p->ID, 'raw'),
            'featured_image' => get_post_thumbnail_id($p->ID) ? wp_get_attachment_url(get_post_thumbnail_id($p->ID)) : null,
            'categories' => wp_get_post_terms($p->ID, 'category', ['fields' => 'names']),
            'tags'     => wp_get_post_terms($p->ID, 'post_tag', ['fields' => 'names']),
            'meta'     => array_map(fn($v) => count($v) === 1 ? $v[0] : $v, get_post_meta($p->ID)),
        ];
    }

    // ---- tool callbacks ---------------------------------------------------

    public static function create_post(array $args): array
    {
        $postarr = self::build_postarr($args);
        if (empty($postarr['post_status'])) {
            $postarr['post_status'] = 'draft';
        }
        $id = wp_insert_post($postarr, true);
        if (is_wp_error($id)) {
            return ['success' => false, 'error' => $id->get_error_message()];
        }
        self::apply_relations($id, $args);
        return ['success' => true, 'id' => $id, 'link' => get_permalink($id), 'edit_link' => get_edit_post_link($id, 'raw')];
    }

    public static function update_post(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if (!$id || !get_post($id)) {
            return ['success' => false, 'error' => 'Post not found: ' . $id];
        }
        $postarr = self::build_postarr($args);
        $postarr['ID'] = $id;
        $res = wp_update_post($postarr, true);
        if (is_wp_error($res)) {
            return ['success' => false, 'error' => $res->get_error_message()];
        }
        self::apply_relations($id, $args);
        return ['success' => true, 'id' => $id, 'link' => get_permalink($id)];
    }

    public static function get_post(array $args): array
    {
        $p = get_post((int) ($args['id'] ?? 0));
        if (!$p) {
            return ['success' => false, 'error' => 'Post not found.'];
        }
        return ['success' => true, 'post' => self::serialize_post($p)];
    }

    public static function list_posts(array $args): array
    {
        $q = [
            'post_type'      => sanitize_key($args['post_type'] ?? 'post'),
            'post_status'    => $args['status'] ?? 'any',
            'posts_per_page' => (int) ($args['per_page'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => sanitize_key($args['orderby'] ?? 'date'),
            'order'          => strtoupper($args['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC',
        ];
        if (!empty($args['search'])) {
            $q['s'] = (string) $args['search'];
        }
        if (!empty($args['author'])) {
            $q['author'] = (int) $args['author'];
        }
        if (!empty($args['category'])) {
            $q[is_numeric($args['category']) ? 'cat' : 'category_name'] = $args['category'];
        }
        if (!empty($args['tag'])) {
            $q['tag'] = $args['tag'];
        }

        $query = new WP_Query($q);
        $items = [];
        foreach ($query->posts as $p) {
            $items[] = [
                'id' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status,
                'type' => $p->post_type, 'date' => $p->post_date, 'link' => get_permalink($p),
            ];
        }
        return ['success' => true, 'total' => (int) $query->found_posts, 'page' => $q['paged'], 'posts' => $items];
    }

    public static function delete_post(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if (!$id || !get_post($id)) {
            return ['success' => false, 'error' => 'Post not found.'];
        }
        $res = wp_delete_post($id, (bool) ($args['force'] ?? false));
        return ['success' => (bool) $res, 'id' => $id, 'permanently' => (bool) ($args['force'] ?? false)];
    }

    public static function upload_media(array $args): array
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attach_to = (int) ($args['attach_to'] ?? 0);

        if (!empty($args['url'])) {
            $id = media_sideload_image((string) $args['url'], $attach_to, $args['title'] ?? null, 'id');
            if (is_wp_error($id)) {
                return ['success' => false, 'error' => $id->get_error_message()];
            }
        } elseif (!empty($args['data'])) {
            $filename = sanitize_file_name($args['filename'] ?? ('upload-' . time()));
            $bytes    = base64_decode((string) $args['data']);
            if ($bytes === false) {
                return ['success' => false, 'error' => 'Invalid base64 data.'];
            }
            $upload = wp_upload_bits($filename, null, $bytes);
            if (!empty($upload['error'])) {
                return ['success' => false, 'error' => $upload['error']];
            }
            $id = wp_insert_attachment([
                'post_mime_type' => $upload['type'],
                'post_title'     => $args['title'] ?? pathinfo($filename, PATHINFO_FILENAME),
                'post_status'    => 'inherit',
            ], $upload['file'], $attach_to);
            wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
        } else {
            return ['success' => false, 'error' => 'Provide either url or data.'];
        }

        if (!empty($args['alt'])) {
            update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($args['alt']));
        }
        return ['success' => true, 'id' => $id, 'url' => wp_get_attachment_url($id)];
    }

    public static function manage_taxonomy(array $args): array
    {
        $action   = $args['action'] ?? 'list';
        $taxonomy = sanitize_key($args['taxonomy'] ?? 'category');

        if (!taxonomy_exists($taxonomy)) {
            return ['success' => false, 'error' => 'Unknown taxonomy: ' . $taxonomy];
        }

        switch ($action) {
            case 'create':
                $res = wp_insert_term((string) ($args['name'] ?? ''), $taxonomy, array_filter([
                    'slug'        => $args['slug'] ?? null,
                    'description' => $args['description'] ?? null,
                    'parent'      => $args['parent'] ?? null,
                ]));
                return is_wp_error($res)
                    ? ['success' => false, 'error' => $res->get_error_message()]
                    : ['success' => true, 'term_id' => (int) $res['term_id']];

            case 'update':
                $res = wp_update_term((int) ($args['term_id'] ?? 0), $taxonomy, array_filter([
                    'name'        => $args['name'] ?? null,
                    'slug'        => $args['slug'] ?? null,
                    'description' => $args['description'] ?? null,
                    'parent'      => $args['parent'] ?? null,
                ]));
                return is_wp_error($res)
                    ? ['success' => false, 'error' => $res->get_error_message()]
                    : ['success' => true, 'term_id' => (int) $res['term_id']];

            case 'delete':
                $res = wp_delete_term((int) ($args['term_id'] ?? 0), $taxonomy);
                return ['success' => (bool) $res];

            case 'list':
            default:
                $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
                if (is_wp_error($terms)) {
                    return ['success' => false, 'error' => $terms->get_error_message()];
                }
                return ['success' => true, 'terms' => array_map(fn($t) => [
                    'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count, 'parent' => $t->parent,
                ], $terms)];
        }
    }
}
