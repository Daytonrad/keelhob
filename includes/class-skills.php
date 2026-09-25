<?php
/**
 * Skills engine. Markdown playbooks (YAML frontmatter + body) the agent loads on
 * demand when a task matches the description. Backed by a custom table so skills
 * live inside WordPress and are shared by every connected agent.
 *
 * The idea of loadable, description-matched playbooks is not original to this
 * project; other MCP plugins offer something similar, and bundling expert ones
 * with the product is a common pattern. The schema, the table, the parser and
 * every skill shipped here were written for Keelhob and share no code with
 * anything else. See seed_bundled_skills().
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Skills
{
    /** Shipped in the plugin zip. */
    const SOURCE_BUNDLED = 'bundled';

    /** Fetched from the skills server. Only these rows may be refreshed. */
    const SOURCE_REMOTE = 'remote';

    /** Written here — by the owner in wp-admin, or by an agent via skill_save. */
    const SOURCE_LOCAL = 'local';

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'keelhob_skills';
    }

    /** Rolling record of which skills were loaded, for sessions that are not agent runs. */
    public const LOADS_OPTION = 'keelhob_skill_loads';

    /** How many loads to keep. Enough to cover a working session, small enough to stay one cheap option. */
    public const LOADS_KEEP = 60;

    /**
     * Record that a skill was loaded, so it can be checked later.
     *
     * Rule 13 asks the agent to name the skills it used. An agent's own
     * account of what it followed is the least reliable evidence there is - it
     * can honestly believe it worked from a playbook it never opened. The tool
     * registry already captured this, but only in a per-request static that
     * only an unattended run ever read, so for interactive MCP - which is how
     * most real work happens - nothing was recorded at all.
     *
     * An option rather than a table: skill_get is called a handful of times in
     * a session, not thousands, so this is a rare write and does not deserve
     * its own schema or a migration.
     *
     * Deliberately absent: anything about the conversation, the instruction,
     * or what the agent then did. This is a list of skill names and times.
     */
    public static function record_load(string $name): void
    {
        $name = sanitize_key($name);
        if ($name === '') {
            return;
        }
        $loads = get_option(self::LOADS_OPTION, []);
        if (!is_array($loads)) {
            $loads = [];
        }
        $loads[] = [
            'skill' => $name,
            'at'    => current_time('mysql'),
            // Which door it came through, so "0 agent runs but these skills
            // loaded" is readable rather than contradictory.
            'via'   => defined('REST_REQUEST') && REST_REQUEST ? 'mcp' : 'internal',
        ];
        if (count($loads) > self::LOADS_KEEP) {
            $loads = array_slice($loads, -self::LOADS_KEEP);
        }
        // autoload off: this is read when someone asks, never on every page.
        update_option(self::LOADS_OPTION, $loads, false);
    }

    /**
     * The recent loads, newest first.
     *
     * @return array<int, array{skill: string, at: string, via: string}>
     */
    public static function recent_loads(int $limit = 30): array
    {
        $loads = get_option(self::LOADS_OPTION, []);
        if (!is_array($loads) || !$loads) {
            return [];
        }
        $loads = array_reverse($loads);
        return array_slice($loads, 0, max(1, min(self::LOADS_KEEP, $limit)));
    }

    public static function install_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // `source` and `version` are additive columns; dbDelta adds them to an
        // existing table on upgrade without touching the rows. The default is
        // 'local' because that is the only answer that is safe when wrong: a
        // row nobody can prove came from us is a row a refresh must not touch.
        //
        // `role`, `requires`, `next_skills` and `verified_by` arrive the same
        // way. They default to a lone procedure with no edges, which is exactly
        // what a skill written before the standard existed is: it still works,
        // it just does not connect to anything until someone declares that it
        // does. `next_skills` rather than `next` because `next` is reserved in
        // several SQL dialects and this value becomes a column name.
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            description TEXT NOT NULL,
            body LONGTEXT NOT NULL,
            enable_agentic TINYINT(1) NOT NULL DEFAULT 1,
            enable_prompt TINYINT(1) NOT NULL DEFAULT 1,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            is_bundled TINYINT(1) NOT NULL DEFAULT 0,
            bundled_hash VARCHAR(32) NOT NULL DEFAULT '',
            source VARCHAR(20) NOT NULL DEFAULT 'local',
            version VARCHAR(32) NOT NULL DEFAULT '',
            role VARCHAR(20) NOT NULL DEFAULT 'procedure',
            surface VARCHAR(20) NOT NULL DEFAULT 'any',
            requires VARCHAR(500) NOT NULL DEFAULT '',
            next_skills VARCHAR(500) NOT NULL DEFAULT '',
            verified_by VARCHAR(500) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY name (name)
        ) {$charset};");
    }

    /**
     * Give pre-2.0 rows a source, once, after install_table() has added the column.
     *
     * Guessing here is not optional — every row that keeps an empty source would
     * be treated as unknown forever, and the refresh guard would have nothing to
     * decide on. `bundled_hash` is the evidence: Keelhob only ever wrote it
     * when it installed a bundled skill, so a row that has one came from us and
     * a row that does not was authored on this site.
     */
    public static function backfill_sources(): void
    {
        global $wpdb;
        $table = self::table();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET source = %s WHERE (source IS NULL OR source = '') AND bundled_hash <> ''",
            self::SOURCE_BUNDLED
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET source = %s WHERE source IS NULL OR source = ''",
            self::SOURCE_LOCAL
        ));
    }

    /** Anything we do not recognise is treated as the owner's own work. */
    public static function normalize_source(string $source): string
    {
        $source = strtolower(trim($source));
        return in_array($source, [self::SOURCE_BUNDLED, self::SOURCE_REMOTE, self::SOURCE_LOCAL], true)
            ? $source
            : self::SOURCE_LOCAL;
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

        $exists = $this->get_by_name($name);

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
        // The tree, as declared by the skill itself. Absent keys are written as
        // empty rather than left alone on purpose: if an edit removes a
        // `verified_by` line, the row must lose the edge too, or the site keeps
        // enforcing a link the author deleted.
        $row['role']        = Keelhob_Skill_Standard::role($args);
        $row['surface']     = Keelhob_Skill_Standard::surface($args);
        $row['requires']    = implode(', ', Keelhob_Skill_Standard::slugs((string) ($args['requires'] ?? '')));
        $row['next_skills'] = implode(', ', Keelhob_Skill_Standard::slugs((string) ($args['next_skills'] ?? '')));
        $row['verified_by'] = implode(', ', Keelhob_Skill_Standard::slugs((string) ($args['verified_by'] ?? '')));

        if (array_key_exists('bundled_hash', $args)) {
            $row['bundled_hash'] = (string) $args['bundled_hash'];
        }
        if (array_key_exists('version', $args)) {
            $row['version'] = substr((string) $args['version'], 0, 32);
        }

        // Source is set on creation and only ever changed by a caller that says
        // so explicitly. An ordinary edit — an agent rewriting a remote skill,
        // the owner fixing a typo — must not silently reclassify the row, or the
        // refresh guard starts answering a different question than it was asked.
        if (array_key_exists('source', $args)) {
            $row['source'] = self::normalize_source((string) $args['source']);
        } elseif (!$exists) {
            $row['source'] = self::SOURCE_LOCAL;
        }

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

    /**
     * Skills exposed to the agent's automatic catalog.
     *
     * Descriptions and edges, never bodies. The edges cost about 50 bytes a
     * skill and turn the catalog from a list of names into a map: the agent can
     * see that finishing one skill implies another, and that some work is not
     * done until a gate has run, before it has loaded a single body.
     */
    public function agentic_catalog(): array
    {
        $out = [];
        foreach ($this->all() as $s) {
            if ((int) $s['enabled'] === 1 && (int) $s['enable_agentic'] === 1) {
                $out[] = [
                    'name'        => $s['name'],
                    'description' => $s['description'],
                    'role'        => Keelhob_Skill_Standard::role($s),
                    'surface'     => Keelhob_Skill_Standard::surface($s),
                    'next_skills' => Keelhob_Skill_Standard::slugs((string) ($s['next_skills'] ?? '')),
                    'verified_by' => Keelhob_Skill_Standard::slugs((string) ($s['verified_by'] ?? '')),
                ];
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
                    // The skill's own place in the ecosystem, as data rather
                    // than prose. Before these existed the only way one skill
                    // could point at another was a sentence in its body, which
                    // no script can read — so nothing could detect a loop, cost
                    // a chain, or generate a router. Kept as raw single-line
                    // strings here; Keelhob_Skill_Standard::slugs() is what
                    // turns them into lists, because the front-matter parser
                    // has no concept of one.
                    case 'role':
                    // Which kind of session can finish this skill. Chat and an
                    // unattended task cannot drive a browser, so a skill that
                    // needs one has to say so before an agent starts it there.
                    case 'surface':
                    case 'requires':
                    case 'next_skills':
                    case 'verified_by':
                        $fields[$key] = $val;
                        break;
                    // The display name, for the Skills admin screen. It exists
                    // because "seo-audit-and-fix" cannot be turned into "SEO
                    // audit and fix" by any rule that also handles "WooCommerce"
                    // and "Solace header & footer" — so the skill says how it
                    // wants to be called, and the catalogue is generated from
                    // that instead of being kept by hand in a second file.
                    case 'title':
                        $fields['title'] = $val;
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
    /**
     * Install the skills that ship with the plugin, and refresh the ones the
     * owner has not touched.
     *
     * The original rule was "never overwrite an existing skill", which is right
     * for a skill somebody edited and wrong for every other case: an improved
     * playbook shipped in a plugin update would never reach a site that had
     * been running since the previous release, so the bundled skills silently
     * froze at whatever version was installed first.
     *
     * `bundled_hash` is what tells the two apart. It records the body Keelhob
     * last wrote. If the stored body still hashes to it, nobody has edited the
     * skill and it is safe to replace with the newer text. If it differs, the
     * owner (or an agent) rewrote it, and their version is left alone.
     *
     * @param bool $force Replace bundled skills even when they look edited.
     *                    Only ever set from an explicit owner-driven refresh.
     * @return array{installed: string[], updated: string[], kept: string[]}
     */
    public static function seed_bundled_skills(bool $force = false): array
    {
        $report = ['installed' => [], 'updated' => [], 'kept' => []];
        $skills = self::instance_free();
        $dir    = KEELHOB_DIR . 'skills';
        if (!is_dir($dir)) {
            return $report;
        }
        foreach (glob($dir . '/*.md') as $file) {
            $doc    = file_get_contents($file);
            $parsed = self::parse_markdown($doc);
            $name   = sanitize_title($parsed['name'] ?? basename($file, '.md'));
            if ($name === '') {
                continue;
            }

            $parsed['is_bundled']   = 1;
            $parsed['name']         = $name;
            $parsed['bundled_hash'] = md5((string) ($parsed['body'] ?? ''));
            $parsed['source']       = self::SOURCE_BUNDLED;
            $parsed['version']      = defined('KEELHOB_VERSION') ? (string) KEELHOB_VERSION : '';

            $existing = $skills->get_by_name($name);
            switch (self::bundled_action($existing, $parsed['bundled_hash'], $force)) {
                case 'install':
                    $skills->save($parsed);
                    $report['installed'][] = $name;
                    break;

                case 'update':
                    // Preserve the owner's on/off choices; only the text changes.
                    $parsed['enabled']        = (int) $existing['enabled'];
                    $parsed['enable_agentic'] = (int) $existing['enable_agentic'];
                    $parsed['enable_prompt']  = (int) $existing['enable_prompt'];
                    $skills->save($parsed);
                    $report['updated'][] = $name;
                    break;

                case 'keep':
                    // Stamp the hash from the body that is on the site right now,
                    // so the *next* update can tell edited from untouched and this
                    // one-time ambiguity does not repeat forever.
                    if ((string) ($existing['bundled_hash'] ?? '') === '') {
                        $GLOBALS['wpdb']->update(
                            self::table(),
                            ['bundled_hash' => md5((string) $existing['body'])],
                            ['name' => $name]
                        );
                    }
                    $report['kept'][] = $name;
                    break;

                case 'current':
                    // The BODY is unchanged — but the front matter may not be,
                    // and bundled_hash only covers the body.
                    //
                    // This was a silent hole. `surface: interactive` was added
                    // to site-build-kickoff without touching a word of its
                    // body, so every existing install would have compared the
                    // same body hash, decided "current", and never stored the
                    // new value. The field whose entire job is stopping an
                    // unattended run from starting a skill that needs a human
                    // would have worked only on fresh installs.
                    //
                    // The same applies to every metadata-only change: a fixed
                    // description, a new edge, a corrected role. Those are the
                    // cheapest and most common kind of skill edit, and none of
                    // them shipped.
                    //
                    // Body hash stays the edit-detector — it is what tells an
                    // owner's rewrite apart from ours, and that has to keep
                    // working. This is a second, narrower comparison layered on
                    // top: same body, different declaration, so update the
                    // declaration and leave everything else alone.
                    if (self::metadata_drifted($existing, $parsed)) {
                        $parsed['enabled']        = (int) $existing['enabled'];
                        $parsed['enable_agentic'] = (int) $existing['enable_agentic'];
                        $parsed['enable_prompt']  = (int) $existing['enable_prompt'];
                        $skills->save($parsed);
                        $report['updated'][] = $name;
                    }
                    break;
            }
        }
        return $report;
    }

    /**
     * Has the shipped front matter changed, for a body that has not?
     *
     * Compared through the same normalisers save() uses, so a cosmetic
     * difference — "seo-optimization, web-design-master" against
     * "seo-optimization,web-design-master" — is not mistaken for a real change
     * and does not rewrite the row on every single page load.
     *
     * The owner's own switches are deliberately NOT compared. enabled,
     * enable_agentic and enable_prompt are their decisions, not ours, and a
     * skill they switched off must stay off through an update.
     *
     * @param array<string, mixed> $existing the stored row
     * @param array<string, mixed> $parsed   the shipped document
     */
    public static function metadata_drifted(array $existing, array $parsed): bool
    {
        $scalars = ['description', 'title'];
        foreach ($scalars as $key) {
            if (trim((string) ($existing[$key] ?? '')) !== trim((string) ($parsed[$key] ?? ''))) {
                return true;
            }
        }

        if (Keelhob_Skill_Standard::role($existing) !== Keelhob_Skill_Standard::role($parsed)) {
            return true;
        }
        if (Keelhob_Skill_Standard::surface($existing) !== Keelhob_Skill_Standard::surface($parsed)) {
            return true;
        }

        foreach (['requires', 'next_skills', 'verified_by'] as $edge) {
            $was = Keelhob_Skill_Standard::slugs((string) ($existing[$edge] ?? ''));
            $now = Keelhob_Skill_Standard::slugs((string) ($parsed[$edge] ?? ''));
            if ($was !== $now) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide what to do with one bundled skill. Pure, so the rules are testable.
     *
     * @param array<string, mixed>|null $existing     the stored row, if any
     * @param string                    $shipped_hash md5 of the body being shipped
     * @return string install | update | keep | current
     */
    public static function bundled_action(?array $existing, string $shipped_hash, bool $force = false): string
    {
        if (!$existing) {
            return 'install';
        }
        $stored_hash = (string) ($existing['bundled_hash'] ?? '');
        $untouched   = $stored_hash !== '' && $stored_hash === md5((string) ($existing['body'] ?? ''));

        if (!$untouched) {
            return $force ? 'update' : 'keep';
        }
        return $stored_hash === $shipped_hash ? 'current' : 'update';
    }

    /** Static helper so activation (no booted singleton) can use the store. */
    private static function instance_free(): Keelhob_Skills
    {
        return new self();
    }
}
