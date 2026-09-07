<?php
/**
 * MCP protocol handler. Implements the JSON-RPC 2.0 methods an MCP client sends
 * over Streamable HTTP: initialize, tools/list, tools/call, prompts/list,
 * prompts/get, resources/list, resources/read, ping, plus initialized notice.
 *
 * We answer with a single application/json JSON-RPC response per request (the
 * simplest valid Streamable-HTTP mode), which every MCP client accepts.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_MCP_Server
{
    const PROTOCOL_VERSION = '2025-06-18';

    /** @var Claudpress_Tool_Registry */
    private $registry;

    public function __construct(Claudpress_Tool_Registry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Handle a decoded JSON-RPC message (single object). Returns the JSON-RPC
     * response array, or null for notifications (no response body).
     */
    public function handle(array $message): ?array
    {
        $id     = $message['id'] ?? null;
        $method = (string) ($message['method'] ?? '');
        $params = isset($message['params']) && is_array($message['params']) ? $message['params'] : [];

        // Notifications (no id) get no response.
        if ($id === null && str_starts_with($method, 'notifications/')) {
            return null;
        }

        try {
            switch ($method) {
                case 'initialize':
                    return $this->result($id, $this->initialize($params));

                case 'ping':
                    return $this->result($id, (object) []);

                case 'tools/list':
                    return $this->result($id, ['tools' => $this->visible_tools()]);

                case 'tools/call':
                    return $this->result($id, $this->call_tool($params));

                case 'prompts/list':
                    return $this->result($id, ['prompts' => $this->prompts_list()]);

                case 'prompts/get':
                    return $this->result($id, $this->prompts_get($params));

                case 'resources/list':
                    return $this->result($id, ['resources' => $this->resources_list()]);

                case 'resources/read':
                    return $this->result($id, $this->resources_read($params));

                case 'resources/templates/list':
                    return $this->result($id, ['resourceTemplates' => []]);

                default:
                    if ($id === null) {
                        return null; // unknown notification
                    }
                    return $this->error($id, -32601, 'Method not found: ' . $method);
            }
        } catch (Throwable $e) {
            return $this->error($id, -32603, 'Internal error: ' . $e->getMessage());
        }
    }

    private function initialize(array $params): array
    {
        $client_version = (string) ($params['protocolVersion'] ?? self::PROTOCOL_VERSION);
        return [
            'protocolVersion' => $client_version ?: self::PROTOCOL_VERSION,
            'capabilities'    => [
                'tools'     => ['listChanged' => false],
                'prompts'   => ['listChanged' => false],
                'resources' => ['listChanged' => false],
                'logging'   => (object) [],
            ],
            'serverInfo' => [
                'name'    => 'Claudpress',
                'title'   => 'Claudpress — WordPress control for AI',
                'version' => CLAUDPRESS_VERSION,
            ],
            'instructions' => $this->server_instructions(),
        ];
    }

    /**
     * How a connected agent should work on a live site, and what "done" means.
     *
     * Everything else in the session instructions tells the agent what it *can*
     * do. This tells it how to do the job well, and it exists because the tools
     * here are unusually sharp: execute_php and execute_sql have no undo, a bad
     * snippet takes the site down for real visitors, and an autonomous run has
     * nobody watching. An agent optimising only for "complete the request" will
     * happily rewrite a working template, delete rows to fix a constraint error,
     * or invent a plugin the owner never asked for.
     *
     * It also carries the definition of done and the no-invented-facts rule
     * (see docs/plans/build-standard.md §"Phase 1"). Those obligations must
     * never be optional, so they live here — in PHP, injected into every
     * session — rather than in a skill, which a site can disable.
     *
     * Shared verbatim by interactive MCP sessions and unattended runs, so the
     * site behaves the same whichever way it is driven.
     *
     * Filter 'claudpress_operating_protocol' to tune this for your own site.
     */
    public static function operating_protocol(): string
    {
        $rules = [
            'HOW TO WORK ON THIS SITE — this is a real website, and some of these tools have no undo.',
            '',
            '1. Look before you touch. Read the current post/option/file/product before you change it. Never overwrite content you have not read, and never guess a structure you could have queried. When you are about to change something you did not create, say what it currently is first.',
            '',
            '2. Smallest change that does the job. Change only what the request needs. Do not "improve" adjacent content, refactor working code, restyle pages you were not asked about, or install/activate plugins nobody requested. If you notice an unrelated problem, mention it in your summary — do not fix it uninvited.',
            '',
            '3. Prefer the specific tool over the sharp one. Use the dedicated tool (create_post, wc_product, seo_meta, manage_option) whenever one fits. Reach for execute_php or execute_sql only for things genuinely not covered — they bypass validation, hooks and caches that the dedicated tools handle for you. Never use them to route around a capability the owner switched off.',
            '',
            '4. Destructive work needs a reason and a plan. Deleting, bulk-updating, refunding, dropping, overwriting a template, or touching anything money-related: state what will be affected and how many rows/items, do the narrowest version, and verify. If a destructive step is the only way and you are running unattended, prefer to stop and report rather than guess.',
            '',
            '5. Verify what you changed. After a write, read it back — fetch the post, re-query the option, load the page, re-run the report. "The tool returned success" is not verification. If you wrote code (snippet_save, write_file), confirm the site still renders before moving on.',
            '',
            '6. Live code is the highest-risk action on this site. A snippet runs for real visitors on every page load. Keep snippets small and single-purpose, guard for the context they run in (is_admin(), function_exists(), post type checks), and never leave debugging output, error suppression, or a fatal path in shipped code.',
            '',
            '6a. NEVER send a 4xx or 5xx status from the front end of a live site. Not for a coming-soon page, not for maintenance mode, not for a paywall. Managed and containerised hosts poll the home page to decide whether the site is alive; a 503 there is read as "this server is dead" and the host stops routing traffic to it — taking wp-admin and this MCP endpoint down with it. You would then have no way to undo your own change, because every way of undoing it is HTTP. This has actually happened. Serve the alternative page with status 200 and use <meta name="robots" content="noindex"> to keep it out of search results; that achieves the same thing and cannot take the site off the internet.',
            '',
            '6b. Never let a snippet block wp-login.php, /wp-admin, /wp-json, wp-cron.php or xmlrpc.php. Anything that gates the front end must let those through, or the owner is locked out of their own site and you are locked out with them. If you install anything that changes what a logged-out visitor sees, verify it as a logged-out visitor before you report it as done: fetch the home page with no cookies and confirm the status code is 200. If you cannot verify it, deactivate it. The owner\'s last resort is adding define(\'CLAUDPRESS_SNIPPETS_OFF\', true); to wp-config.php, which disables every snippet — tell them that line exists if a snippet you installed ever breaks their site.',
            '',
            '7. Say what you are unsure about. If the request is ambiguous, if two readings would produce different sites, or if the safe interpretation is much smaller than the literal one — do the safe part and name the ambiguity in your summary. Do not silently pick the interpretation that involves more destruction.',
            '',
            '8. Report honestly. Say what you actually changed, what you skipped, and what failed — including partial failures. Never describe work you did not verify as done. If you could not finish, say so plainly and say why.',
            '',
            '9. Definition of done. A build is not finished when it looks right. Before reporting any site or page as done, run the site_readiness tool against your own work and resolve every Blocker and High. Then run the pre-launch-audit skill as well, for the judgement layer site_readiness cannot provide - do not score your own design work, which is the exact failure this rule exists to prevent. You may never lower a finding\'s severity to make it pass. If something can only be decided by the owner, say so explicitly and list it - do not silently accept it.',
            '',
            '10. Never invent facts. No statistic, review, testimonial, customer count, credential, award, staff member, or photograph presented as a real person or customer, unless the site can substantiate it. A store with no orders has no customers.',
            '',
            '11. Load the build-standard skill before building anything. It lists what "done" requires and which skill to use for each part of the job.',
        ];

        return (string) apply_filters('claudpress_operating_protocol', implode("\n", $rules));
    }

    /**
     * High-level guidance injected at session start. Includes the abilities
     * switch state and a compact catalog of connected skills (descriptions only).
     */
    private function server_instructions(): string
    {
        $enabled = Claudpress::instance()->abilities_enabled();
        $lines = [];
        $lines[] = 'Claudpress connects you to a WordPress site with full programmatic control.';
        $lines[] = $enabled
            ? 'AI abilities are ENABLED — you may read and modify this site.'
            : 'AI abilities are DISABLED. Ask the site owner to enable them in WP Admin > Claudpress before making changes. Read-only introspection may still work.';
        $lines[] = '';
        $lines[] = 'The universal escape hatch is execute_php: it runs arbitrary PHP in the full WordPress runtime ($wpdb, WP_Query, WooCommerce, every plugin API). Prefer the dedicated high-level tools for common work, and fall back to execute_php for anything not covered.';
        $lines[] = 'Powers include: content and blogging (create_post, upload_media), the WooCommerce catalogue (wc_product/wc_coupon/wc_settings), SEO (seo_meta/seo_schema/seo_sitemap), live code that changes the site (snippet_save - PHP/CSS/JS/HTML, incl. pixel-perfect custom page templates), Elementor page building (elementor_page + elementor_settings prepare_for_ai), the filesystem (read_file/write_file/edit_file), raw execution (execute_php/execute_sql), images (image_search free / image_generate), a full status snapshot (get_dashboard), and a read-only build grade (site_readiness). Whatever tools/list returns is the whole truth about what you can do here - do not assume a tool exists because it is normal for WordPress.';
        $lines[] = 'START HERE: call memory_list once at the beginning — it holds this site\'s operating context (build mode, builder, conventions). Persist new durable decisions with memory_save. Memory and skills are shared by every connected agent.';
        $lines[] = '';
        $lines[] = self::operating_protocol();
        $builder = Claudpress::instance()->get('builder', 'auto');
        if ($builder && $builder !== 'auto') {
            $lines[] = 'Configured page builder: ' . $builder . '. Build with this unless told otherwise.';
        }
        $off = Claudpress_Capabilities::disabled_labels();
        if (!empty($off)) {
            $lines[] = '';
            $lines[] = 'OFF-LIMITS: the owner has switched these capabilities OFF — their tools are hidden and will be refused. Do not attempt them, and do not use execute_php/execute_sql/snippets/file writes to work around them. If one is needed, say plainly that the owner can enable it under Claudpress > Settings > Capabilities. Off: ' . implode('; ', $off) . '.';
        }

        // Agentic skill catalog: descriptions only (bodies load on demand via prompts/get or skill_get).
        $skills = Claudpress::instance()->skills->agentic_catalog();
        if (!empty($skills)) {
            $lines[] = '';
            $lines[] = 'Available skills (load the full body with the skill_get tool, or invoke as a prompt, when a task matches):';
            foreach ($skills as $s) {
                $lines[] = sprintf('- %s: %s', $s['name'], $s['description']);
            }
        }
        return implode("\n", $lines);
    }


    /** tools/list filtered by the owner's capability toggles. */
    private function visible_tools(): array
    {
        $out = [];
        foreach ($this->registry->list() as $tool) {
            if (Claudpress_Capabilities::tool_allowed($tool['name'])) {
                $out[] = $tool;
            }
        }
        return $out;
    }

    private function call_tool(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];

        if ($name === '') {
            return $this->tool_error('No tool name provided.');
        }
        if (!$this->registry->has($name)) {
            return $this->tool_error(sprintf('Unknown tool "%s".', $name));
        }

        // Master safety switch: only a small allowlist works while abilities are off.
        if (!Claudpress::instance()->abilities_enabled() && !in_array($name, self::always_allowed(), true)) {
            return $this->tool_error('AI abilities are disabled on this site. Enable them in WP Admin > Claudpress to use this tool.');
        }

        // Capability toggle: the owner has switched this area off-limits.
        if (!Claudpress_Capabilities::tool_allowed($name)) {
            $group = Claudpress_Capabilities::tool_group($name);
            $label = $group ? (Claudpress_Capabilities::groups()[$group]['label'] ?? $group) : $name;
            return $this->tool_error(sprintf(
                'The "%s" capability is switched OFF for this site, so "%s" is off-limits. Do not retry, and do not reach the same outcome through execute_php, execute_sql, snippets or file writes — that is working around a decision the owner made on purpose. Tell the owner they can enable the "%s" group under Claudpress > Settings > Capabilities.',
                $label,
                $name,
                $group ?? $name
            ));
        }

        // Policy guardrails (interactive context).
        $blocked = Claudpress_Policy::check($name, $args, 'interactive');
        if ($blocked !== null) {
            return $this->tool_error($blocked);
        }

        @set_time_limit(CLAUDPRESS_MAX_EXECUTION_TIME);

        try {
            $data = $this->registry->call($name, $args);
        } catch (Throwable $e) {
            return $this->tool_error($e->getMessage());
        }

        $is_error = (isset($data['success']) && $data['success'] === false);
        $text     = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'content'           => [['type' => 'text', 'text' => $text]],
            'structuredContent' => $data,
            'isError'           => $is_error,
        ];
    }

    /** Tools that remain available even when abilities are disabled (read-only introspection). */
    private static function always_allowed(): array
    {
        return ['get_site_info', 'memory_list', 'skill_list', 'get_dashboard', 'task_list'];
    }

    private function tool_error(string $message): array
    {
        return [
            'content'  => [['type' => 'text', 'text' => wp_json_encode(['success' => false, 'error' => $message])]],
            'isError'  => true,
        ];
    }

    // ---- Prompts (backed by skills with enable_prompt) --------------------

    private function prompts_list(): array
    {
        $out = [];
        foreach (Claudpress::instance()->skills->prompt_catalog() as $s) {
            $out[] = [
                'name'        => $s['name'],
                'description' => $s['description'],
                'arguments'   => [],
            ];
        }
        return $out;
    }

    private function prompts_get(array $params): array
    {
        $name  = (string) ($params['name'] ?? '');
        $skill = Claudpress::instance()->skills->get_by_name($name);
        if (!$skill) {
            throw new Exception('Unknown prompt: ' . $name);
        }
        return [
            'description' => $skill['description'],
            'messages'    => [[
                'role'    => 'user',
                'content' => ['type' => 'text', 'text' => $skill['body']],
            ]],
        ];
    }

    // ---- Resources (memory + skills exposed as readable resources) --------

    private function resources_list(): array
    {
        $out = [];
        foreach (Claudpress::instance()->memory->all() as $m) {
            $out[] = [
                'uri'      => 'claudpress://memory/' . $m['key'],
                'name'     => 'Memory: ' . $m['key'],
                'mimeType' => 'text/plain',
            ];
        }
        foreach (Claudpress::instance()->skills->all() as $s) {
            $out[] = [
                'uri'      => 'claudpress://skill/' . $s['name'],
                'name'     => 'Skill: ' . $s['name'],
                'mimeType' => 'text/markdown',
            ];
        }
        return $out;
    }

    private function resources_read(array $params): array
    {
        $uri = (string) ($params['uri'] ?? '');
        if (str_starts_with($uri, 'claudpress://memory/')) {
            $key = substr($uri, strlen('claudpress://memory/'));
            $m   = Claudpress::instance()->memory->get($key);
            $text = $m ? (string) $m['value'] : '';
            return ['contents' => [['uri' => $uri, 'mimeType' => 'text/plain', 'text' => $text]]];
        }
        if (str_starts_with($uri, 'claudpress://skill/')) {
            $name = substr($uri, strlen('claudpress://skill/'));
            $s    = Claudpress::instance()->skills->get_by_name($name);
            $text = $s ? (string) $s['body'] : '';
            return ['contents' => [['uri' => $uri, 'mimeType' => 'text/markdown', 'text' => $text]]];
        }
        throw new Exception('Unknown resource: ' . $uri);
    }

    // ---- JSON-RPC envelope helpers ----------------------------------------

    private function result($id, $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error($id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
