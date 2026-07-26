<?php
/**
 * Skills engine. Markdown playbooks (YAML frontmatter + body) the agent loads on
 * demand when a task matches the description. Backed by a custom table so skills
 * live inside WordPress and are shared by every connected agent.
 *
 * This replicates (and extends, for free) Novamira's Skills + the Pro idea of
 * bundled expert "specializations" — see seed_bundled_skills().
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Skills
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'claudpress_skills';
    }

    public static function install_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            description TEXT NOT NULL,
            body LONGTEXT NOT NULL,
            enable_agentic TINYINT(1) NOT NULL DEFAULT 1,
            enable_prompt TINYINT(1) NOT NULL DEFAULT 1,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            is_bundled TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY name (name)
        ) {$charset};");
    }

    /**
     * Save (upsert) a skill. Accepts either a raw markdown document (frontmatter
     * + body) via $markdown, or explicit fields.
     */
    public function save(array $args): array
    {
        global $wpdb;

        if (!empty($args['markdown'])) {
            $parsed = self::parse_markdown((string) $args['markdown']);
            $args   = array_merge($parsed, array_diff_key($args, ['markdown' => 1]));
        }

        $name = sanitize_title((string) ($args['name'] ?? ''));
        if ($name === '' && !empty($args['description'])) {
            $name = sanitize_title(wp_trim_words((string) $args['description'], 5, ''));
        }
        if ($name === '') {
            return ['success' => false, 'error' => 'Skill needs a name or description.'];
        }

        $row = [
            'name'           => $name,
            'description'    => (string) ($args['description'] ?? ''),
            'body'           => (string) ($args['body'] ?? ''),
            'enable_agentic' => array_key_exists('enable_agentic', $args) ? (int) (bool) $args['enable_agentic'] : 1,
            'enable_prompt'  => array_key_exists('enable_prompt', $args) ? (int) (bool) $args['enable_prompt'] : 1,
            'enabled'        => array_key_exists('enabled', $args) ? (int) (bool) $args['enabled'] : 1,
            'is_bundled'     => (int) (bool) ($args['is_bundled'] ?? 0),
            'updated_at'     => current_time('mysql'),
        ];

        $exists = $this->get_by_name($name);
        if ($exists) {
            $wpdb->update(self::table(), $row, ['name' => $name]);
        } else {
            $wpdb->insert(self::table(), $row);
        }
        return ['success' => true, 'name' => $name];
    }

    public function get_by_name(string $name): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE name = %s",
            sanitize_title($name)
        ), ARRAY_A);
        return $row ?: null;
    }

    public function delete(string $name): array
    {
        global $wpdb;
        $deleted = $wpdb->delete(self::table(), ['name' => sanitize_title($name)]);
        return ['success' => (bool) $deleted, 'name' => $name];
    }

    /** @return array<int, array> */
    public function all(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM " . self::table() . " ORDER BY name", ARRAY_A);
        return $rows ?: [];
    }

    /** Skills exposed to the agent's automatic catalog (description only). */
    public function agentic_catalog(): array
    {
        $out = [];
        foreach ($this->all() as $s) {
            if ((int) $s['enabled'] === 1 && (int) $s['enable_agentic'] === 1) {
                $out[] = ['name' => $s['name'], 'description' => $s['description']];
            }
        }
        return $out;
    }

    /** Skills exposed as MCP prompts. */
    public function prompt_catalog(): array
    {
        $out = [];
        foreach ($this->all() as $s) {
            if ((int) $s['enabled'] === 1 && (int) $s['enable_prompt'] === 1) {
                $out[] = ['name' => $s['name'], 'description' => $s['description']];
            }
        }
        return $out;
    }

    /**
     * Parse a skill markdown document into fields.
     * @return array{name?:string,description?:string,body:string,enable_agentic?:bool,enable_prompt?:bool}
     */
    public static function parse_markdown(string $doc): array
    {
        $doc = str_replace(["\r\n", "\r"], "\n", $doc);
        $fields = ['body' => $doc];

        if (preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $doc, $m)) {
            $yaml = $m[1];
            $fields['body'] = ltrim($m[2], "\n");
            foreach (explode("\n", $yaml) as $line) {
                if (!preg_match('/^([a-zA-Z_]+)\s*:\s*(.*)$/', trim($line), $kv)) {
                    continue;
                }
                $key = strtolower($kv[1]);
                $val = trim($kv[2], " \"'");
                switch ($key) {
                    case 'name':
                        $fields['name'] = $val;
                        break;
                    case 'description':
                        $fields['description'] = $val;
                        break;
                    case 'enable_agentic':
                        $fields['enable_agentic'] = in_array(strtolower($val), ['1', 'true', 'yes'], true);
                        break;
                    case 'enable_prompt':
                        $fields['enable_prompt'] = in_array(strtolower($val), ['1', 'true', 'yes'], true);
                        break;
                }
            }
        }
        return $fields;
    }

    /**
     * Ship a set of expert skills out of the box — this is the "Pro
     * specializations" idea, unlocked and free. Only seeds on activation and
     * only when a same-named skill does not already exist (so user edits win).
     */
    public static function seed_bundled_skills(): void
    {
        $skills   = self::instance_free();
        $dir      = CLAUDPRESS_DIR . 'skills';
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.md') as $file) {
            $doc    = file_get_contents($file);
            $parsed = self::parse_markdown($doc);
            $name   = sanitize_title($parsed['name'] ?? basename($file, '.md'));
            if ($name === '') {
                continue;
            }
            if ($skills->get_by_name($name)) {
                continue; // never overwrite an existing (possibly user-edited) skill
            }
            $parsed['is_bundled'] = 1;
            $parsed['name']       = $name;
            $skills->save($parsed);
        }
    }

    /** Static helper so activation (no booted singleton) can use the store. */
    private static function instance_free(): Claudpress_Skills
    {
        return new self();
    }
}
