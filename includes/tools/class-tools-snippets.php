<?php
/**
 * Snippet tools — let the agent write real code (PHP/CSS/JS/HTML) that runs on
 * the site, plus attach pixel-perfect custom PHP page templates to a page.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Tools_Snippets
{
    public static function register(Keelhob_Tool_Registry $r): void
    {
        $r->add('snippet_save', 'Create or update a code snippet that runs on the site — like WPCode/Code Snippets. Types: php (runs in WordPress; NO <?php tags; use add_action/add_filter/functions), css, js, html. Location controls where it runs: everywhere, admin_only, frontend_only, header (wp_head), footer (wp_footer), before_content, after_content, shortcode (use [keelhob_snippet id=N]), or page_template (serve raw PHP/HTML as a whole page for target_id — the pixel-perfect custom-coded page mode). ALWAYS fill name, tags, purpose and impact: the site owner reads them on the Keelhob > Snippets admin page and that is their only way to judge what you installed. Updates MERGE — send only the fields you are changing and everything else, including code, is preserved. PHP is parse-checked first; code that does not parse is still saved but forced inactive with the error attached. A PHP snippet that fatals at runtime is auto-deactivated so the site stays up. Set active=true to turn it on. WARNING — the auto-deactivate guard only catches FATAL ERRORS. A snippet that runs perfectly can still take the site down, and the two ways that happens are: (1) sending a 4xx/5xx status from the front end — hosts poll the home page for liveness and read a 503 there as "server dead", then stop routing to the site entirely, including wp-admin and this MCP endpoint, so you lose the ability to undo it; serve coming-soon and maintenance pages with status 200 plus a noindex meta tag instead. (2) gating the front end without letting wp-login.php, /wp-admin, /wp-json, wp-cron.php and xmlrpc.php through, which locks the owner out of their own site. After activating anything that changes what a logged-out visitor sees, fetch the home page with no cookies and confirm it returns 200 before reporting done. If a snippet does break the site, the owner recovers by adding define(\'KEELHOB_SNIPPETS_OFF\', true); to wp-config.php, which disables every snippet without needing a working site or a database client.', [
            'type' => 'object',
            'properties' => [
                'id'           => ['type' => 'integer', 'description' => 'Omit to create; include to update.'],
                'name'         => ['type' => 'string'],
                'description'  => ['type' => 'string'],
                'code'         => ['type' => 'string', 'description' => 'The snippet code. For php, do NOT include <?php tags (except page_template, where inline HTML with <?php islands is allowed).'],
                'snippet_type' => ['type' => 'string', 'enum' => ['php', 'css', 'js', 'html'], 'default' => 'php'],
                'location'     => ['type' => 'string', 'enum' => ['everywhere', 'admin_only', 'frontend_only', 'header', 'footer', 'before_content', 'after_content', 'shortcode', 'page_template'], 'default' => 'everywhere', 'description' => 'css, js and html only run from header, footer, before_content, after_content or shortcode — everywhere/admin_only/frontend_only/page_template only execute php, so a css/js/html snippet saved there is refused.'],
                'target_id'    => ['type' => 'integer', 'description' => 'For page_template: the page/post ID this template serves.'],
                'priority'     => ['type' => 'integer', 'default' => 10],
                'active'       => ['type' => 'boolean', 'default' => false],
                'conditions'   => [
                    'type'        => 'object',
                    'description' => 'Optional load rules. {enabled:true, relation:"AND"|"OR", '
                        . 'rules:[{type,operator,value}]}. type is one of user_status, user_role, '
                        . 'page_type, post_type, post_id, url_path, device. operator is "is"/"is_not" '
                        . '(or "contains"/"equals"/"starts_with" for url_path). value may be an array, '
                        . 'meaning "any of these". Example: {"enabled":true,"relation":"AND","rules":'
                        . '[{"type":"user_status","operator":"is","value":"logged_out"},'
                        . '{"type":"page_type","operator":"is","value":"front_page"}]}. '
                        . 'The older flat shape {logged_in, front_page, post_types, post_ids} is still '
                        . 'accepted and is converted on read, so existing snippets keep working.',
                ],
                'tags'         => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Short lowercase labels the owner filters by, e.g. ["seo","header"]. Max 10.'],
                'purpose'      => ['type' => 'string', 'description' => 'Why this snippet exists, in plain English for the site owner.'],
                'impact'       => ['type' => 'string', 'description' => 'What it changes on the site, and what breaks if it is switched off.'],
                'uid'          => ['type' => 'string', 'description' => 'Portable identity. Omit on new snippets — one is generated.'],
                'origin_site'  => ['type' => 'string', 'description' => 'When copying from another site: that site\'s label.'],
                'origin_uid'   => ['type' => 'string', 'description' => 'When copying from another site: the source snippet\'s uid. Re-copying with the same origin_uid UPDATES the existing copy instead of duplicating it — which also overwrites any edit the owner made here.'],
            ],
            'required' => ['code'],
        ], [self::class, 'save'], ['destructiveHint' => true, 'title' => 'Save Code Snippet']);

        $r->add('snippet_list', 'List code snippets with their tags, purpose, impact, type, location, active state, portable uid, origin and any last error. Also returns this site\'s label, which is the handle to use when copying snippets between sites. Read-only.', [
            'type' => 'object',
            'properties' => [
                'search'       => ['type' => 'string', 'description' => 'Match against name, description and purpose.'],
                'tag'          => ['type' => 'string', 'description' => 'Exact tag match.'],
                'snippet_type' => ['type' => 'string', 'enum' => ['php', 'css', 'js', 'html']],
                'active'       => ['type' => 'boolean'],
            ],
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
        $res = Keelhob_Snippets::save($a);

        // Append, never overwrite. save() puts the reachability verdict in
        // `note`, and that is the one message the agent must not miss: it is
        // either why the snippet was switched back off, or the fact that the
        // site is already returning an error for some other reason. Clobbering
        // it with this generic sentence lost exactly that, and the symptom was
        // a response reporting front_end_status 503 and no explanation at all.
        if ($res['success'] && !empty($a['active']) && ($a['snippet_type'] ?? 'php') === 'php') {
            $generic = 'Active PHP snippet. If it triggers a fatal error it will auto-deactivate; check snippet_get last_error afterwards.';
            $res['note'] = isset($res['note']) && $res['note'] !== ''
                ? $generic . ' ' . $res['note']
                : $generic;
        }
        return $res;
    }

    public static function list(array $a): array
    {
        $filters = [
            'search'       => (string) ($a['search'] ?? ''),
            'tag'          => (string) ($a['tag'] ?? ''),
            'snippet_type' => (string) ($a['snippet_type'] ?? ''),
            'active'       => array_key_exists('active', $a) ? (string) (int) (bool) $a['active'] : '',
        ];
        // '0' is a real filter (show inactive), so test for '' — not falsiness.
        $filtered = false;
        foreach ($filters as $v) {
            $filtered = $filtered || $v !== '';
        }
        $rows = $filtered ? Keelhob_Snippets::query($filters) : Keelhob_Snippets::all();
        foreach ($rows as &$row) {
            unset($row['code']);
            $row['tags'] = Keelhob_Snippets::tags_array($row['tags'] ?? '');
        }
        unset($row);

        // COUNT THE ROWS FOR THE MODEL.
        // Asked "how many snippets are there?", Gemini read a 44-row list and
        // answered 28. Counting a long JSON array is a thing language models are
        // measurably bad at, and there is no reason to make one try: the number
        // is free on our side and exact.
        $active = 0;
        foreach ($rows as $r) {
            $active += !empty($r['active']) ? 1 : 0;
        }

        return [
            'success'  => true,
            'site'     => Keelhob::site_label(),
            'count'    => count($rows),
            'active'   => $active,
            'inactive' => count($rows) - $active,
            'filtered' => $filtered,
            'snippets' => $rows,
        ];
    }

    public static function get(array $a): array
    {
        $s = Keelhob_Snippets::get((int) ($a['id'] ?? 0));
        return $s ? ['success' => true, 'snippet' => $s] : ['success' => false, 'error' => 'Snippet not found.'];
    }

    public static function toggle(array $a): array
    {
        return Keelhob_Snippets::toggle((int) ($a['id'] ?? 0), (bool) ($a['active'] ?? false));
    }

    public static function delete(array $a): array
    {
        return Keelhob_Snippets::delete((int) ($a['id'] ?? 0));
    }
}
