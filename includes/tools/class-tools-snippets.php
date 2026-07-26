<?php
/**
 * Snippet tools — let the agent write real code (PHP/CSS/JS/HTML) that runs on
 * the site, plus attach pixel-perfect custom PHP page templates to a page.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Snippets
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        $r->add('snippet_save', 'Create or update a code snippet that runs on the site — like WPCode/Code Snippets. Types: php (runs in WordPress; NO <?php tags; use add_action/add_filter/functions), css, js, html. Location controls where it runs: everywhere, admin_only, frontend_only, header (wp_head), footer (wp_footer), before_content, after_content, shortcode (use [claudpress_snippet id=N]), or page_template (serve raw PHP/HTML as a whole page for target_id — the pixel-perfect custom-coded page mode). A PHP snippet that fatals is auto-deactivated so the site stays up. Set active=true to turn it on.', [
            'type' => 'object',
            'properties' => [
                'id'           => ['type' => 'integer', 'description' => 'Omit to create; include to update.'],
                'name'         => ['type' => 'string'],
                'description'  => ['type' => 'string'],
                'code'         => ['type' => 'string', 'description' => 'The snippet code. For php, do NOT include <?php tags (except page_template, where inline HTML with <?php islands is allowed).'],
                'snippet_type' => ['type' => 'string', 'enum' => ['php', 'css', 'js', 'html'], 'default' => 'php'],
                'location'     => ['type' => 'string', 'enum' => ['everywhere', 'admin_only', 'frontend_only', 'header', 'footer', 'before_content', 'after_content', 'shortcode', 'page_template'], 'default' => 'everywhere'],
                'target_id'    => ['type' => 'integer', 'description' => 'For page_template: the page/post ID this template serves.'],
                'priority'     => ['type' => 'integer', 'default' => 10],
                'active'       => ['type' => 'boolean', 'default' => false],
                'conditions'   => ['type' => 'object', 'description' => 'Optional load rules: {logged_in:bool, front_page:bool, post_types:[..], post_ids:[..]}.'],
            ],
            'required' => ['code'],
        ], [self::class, 'save'], ['destructiveHint' => true, 'title' => 'Save Code Snippet']);

        $r->add('snippet_list', 'List all code snippets with their type, location, active state, and any last error. Read-only.', [
            'type' => 'object', 'properties' => (object) [],
        ], [self::class, 'list'], ['readOnlyHint' => true]);

        $r->add('snippet_get', 'Get one snippet by ID, including its full code.', [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ], [self::class, 'get'], ['readOnlyHint' => true]);

        $r->add('snippet_toggle', 'Activate or deactivate a snippet by ID. Activating clears any prior auto-deactivation error.', [
            'type' => 'object',
            'properties' => [
                'id'     => ['type' => 'integer'],
                'active' => ['type' => 'boolean'],
            ],
            'required' => ['id', 'active'],
        ], [self::class, 'toggle'], ['destructiveHint' => true]);

        $r->add('snippet_delete', 'Delete a snippet permanently by ID.', [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ], [self::class, 'delete'], ['destructiveHint' => true]);
    }

    public static function save(array $a): array
    {
        // Guardrail: page_template needs a target page.
        if (($a['location'] ?? '') === 'page_template' && empty($a['target_id'])) {
            return ['success' => false, 'error' => 'page_template snippets require target_id (the page ID to serve).'];
        }
        $res = Claudpress_Snippets::save($a);
        if ($res['success'] && !empty($a['active']) && ($a['snippet_type'] ?? 'php') === 'php') {
            $res['note'] = 'Active PHP snippet. If it triggers a fatal error it will auto-deactivate; check snippet_get last_error afterwards.';
        }
        return $res;
    }

    public static function list(array $a): array
    {
        return ['success' => true, 'snippets' => Claudpress_Snippets::all()];
    }

    public static function get(array $a): array
    {
        $s = Claudpress_Snippets::get((int) ($a['id'] ?? 0));
        return $s ? ['success' => true, 'snippet' => $s] : ['success' => false, 'error' => 'Snippet not found.'];
    }

    public static function toggle(array $a): array
    {
        return Claudpress_Snippets::toggle((int) ($a['id'] ?? 0), (bool) ($a['active'] ?? false));
    }

    public static function delete(array $a): array
    {
        return Claudpress_Snippets::delete((int) ($a['id'] ?? 0));
    }
}
