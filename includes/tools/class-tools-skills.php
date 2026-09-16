<?php
/**
 * Skills tools — author, list, read, and manage the Markdown playbooks the agent
 * loads on demand. Includes skill_get so the agent can pull a full skill body
 * when the session-start catalog description matches the current task.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Skills
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        $r->add('skill_list', 'List every skill this site has (name, description, source, enabled/agentic/prompt flags). Every skill listed here is available — call skill_get to load its body when a task matches its description. You already receive the same names and descriptions at session start, so this is for re-checking after a skill is added or switched off. Read-only.', [
            'type' => 'object', 'properties' => (object) [],
        ], [self::class, 'list'], ['readOnlyHint' => true]);

        $r->add('skill_get', 'Load the full body of a skill by name. Call this when a task matches a skill\'s description so you can follow its playbook. The response also returns the skill\'s role and its links: `requires` (load these first), `next_skills` (consider these when you are done) and `verified_by` (the work is NOT finished until these have run).', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ], [self::class, 'get'], ['readOnlyHint' => true]);

        $r->add('skill_lint', 'Check a skill against the Claudpress skill standard and get a 0-100 score with specific findings. Pass `markdown` to check a draft before saving it, or `name` to check a skill already on this site; pass neither to check every skill and the links between them (loops, orphans, skills competing for the same request). Use it before skill_save so a new skill is actually reachable and actually connects to the others. Read-only.', [
            'type' => 'object',
            'properties' => [
                'markdown' => ['type' => 'string', 'description' => 'A full skill document to check without saving it.'],
                'name'     => ['type' => 'string', 'description' => 'A skill already on this site.'],
            ],
        ], [self::class, 'lint'], ['readOnlyHint' => true, 'title' => 'Check a Skill']);

        $r->add('skill_save', 'Create or update a skill. Provide either a full Markdown document (YAML frontmatter + body) as `markdown`, or explicit fields. Skills are shared site-wide and loaded automatically when their description matches a task.', [
            'type' => 'object',
            'properties' => [
                'markdown'       => ['type' => 'string', 'description' => 'Full skill document (--- frontmatter --- then body).'],
                'name'           => ['type' => 'string'],
                'description'    => ['type' => 'string', 'description' => 'The trigger blurb — write it so it clearly signals when to use the skill.'],
                'body'           => ['type' => 'string'],
                'enable_agentic' => ['type' => 'boolean', 'default' => true],
                'enable_prompt'  => ['type' => 'boolean', 'default' => true],
                'enabled'        => ['type' => 'boolean', 'default' => true],
            ],
        ], [self::class, 'save'], ['destructiveHint' => true]);

        $r->add('skill_refresh', 'Reinstall the expert skills that ship with Claudpress, so a plugin update\'s improved playbooks reach this site. Skills that were edited here are kept, and named in the response, unless force=true replaces them too. Reports what was installed, updated and kept.', [
            'type' => 'object',
            'properties' => [
                'force' => ['type' => 'boolean', 'default' => false, 'description' => 'Also overwrite bundled skills that look edited on this site. Destroys those edits — confirm with the owner first.'],
            ],
        ], [self::class, 'refresh'], ['destructiveHint' => true, 'title' => 'Refresh Bundled Skills']);

        $r->add('skill_delete', 'Delete a skill by name.', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ], [self::class, 'delete'], ['destructiveHint' => true]);
    }

    /**
     * Every skill on this site. Names and descriptions travel, bodies never do.
     *
     * This used to promise a second half — the skills that exist but are "not
     * available here", marked available:false with the tier carrying them —
     * from back when there was a paid tier to withhold them. There is one free
     * plugin now, every skill in it ships, and the code never returned that key
     * anyway. Describing a half that does not exist taught the agent to expect
     * skills it could not have, which is worse than saying nothing.
     */
    public static function list(array $a): array
    {
        $skills = array_map(fn($s) => [
            'name' => $s['name'], 'description' => $s['description'],
            'enabled' => (bool) $s['enabled'], 'agentic' => (bool) $s['enable_agentic'],
            'prompt' => (bool) $s['enable_prompt'], 'bundled' => (bool) $s['is_bundled'],
            'source' => Claudpress_Skills::normalize_source((string) ($s['source'] ?? '')),
            'version' => (string) ($s['version'] ?? ''),
        ], Claudpress::instance()->skills->all());

        return ['success' => true, 'count' => count($skills), 'skills' => $skills];
    }

    /**
     * The body, plus where this skill sits in the tree.
     *
     * The edges cost a handful of bytes and arrive at the one moment they are
     * most useful — the agent has just decided to follow this playbook, which
     * is exactly when it needs to know that something must be loaded first and
     * something else has to run before the work counts as done. Finding that
     * out later, buried in prose at the bottom of the body, is finding it out
     * after the decision has already been made.
     */
    public static function get(array $a): array
    {
        $s = Claudpress::instance()->skills->get_by_name((string) ($a['name'] ?? ''));
        if (!$s) {
            return ['success' => false, 'error' => 'Skill not found.'];
        }

        $edges = Claudpress_Skill_Standard::edges($s);

        return [
            'success'     => true,
            'name'        => $s['name'],
            'description' => $s['description'],
            'role'        => Claudpress_Skill_Standard::role($s),
            // Where this can be finished. The catalog only prints it when it
            // restricts something, so an agent that skipped straight to
            // skill_get would otherwise never see it at all.
            'surface'     => Claudpress_Skill_Standard::surface($s),
            'requires'    => $edges['requires'],
            'next_skills' => $edges['next_skills'],
            'verified_by' => $edges['verified_by'],
            'body'        => $s['body'],
        ];
    }

    /**
     * Save, and say what is wrong with it — but save it either way.
     *
     * The standard warns here rather than refusing on purpose. This runs on
     * somebody else's live site, and blocking an owner's save over a missing
     * heading is not our call to make; the repo is where the rules are
     * enforced. What we owe them is the finding, not a locked door.
     */
    public static function save(array $a): array
    {
        $result = Claudpress::instance()->skills->save($a);

        if (empty($result['success'])) {
            return $result;
        }

        $saved = Claudpress::instance()->skills->get_by_name((string) $result['name']);
        if (!$saved) {
            return $result;
        }

        $known    = array_column(Claudpress::instance()->skills->all(), 'name');
        $findings = Claudpress_Skill_Standard::check($saved, $known);
        if (empty($findings)) {
            return $result;
        }

        return array_merge($result, [
            'score'    => Claudpress_Skill_Standard::score($findings),
            'warnings' => array_map(
                static fn(array $f): string => $f['level'] . ': ' . $f['message'],
                $findings
            ),
            'note' => 'Saved. These are the ways it falls short of the skill standard — a skill that fails them still works, it is just harder for an agent to find and easier to leave stranded. Run skill_lint after fixing.',
        ]);
    }

    /**
     * The same judgement the repository's CI applies, available on a live site.
     *
     * Without this the standard would only ever police the eighteen skills we
     * ship, and never the ones agents write on other people's sites — which is
     * most of the skills that will ever exist.
     */
    public static function lint(array $a): array
    {
        $service = Claudpress::instance()->skills;
        $all     = [];
        foreach ($service->all() as $row) {
            $all[(string) $row['name']] = $row;
        }
        $known = array_keys($all);

        // A draft, checked without saving it.
        if (!empty($a['markdown'])) {
            $parsed = Claudpress_Skills::parse_markdown((string) $a['markdown']);
            if (empty($parsed['name'])) {
                return ['success' => false, 'error' => 'No front matter found. A skill opens with --- on the first line.'];
            }
            $findings = Claudpress_Skill_Standard::check($parsed, array_unique(array_merge($known, [$parsed['name']])));
            return self::lint_result([$parsed['name'] => $findings]);
        }

        // One skill already here.
        if (!empty($a['name'])) {
            $name = sanitize_title((string) $a['name']);
            if (!isset($all[$name])) {
                return ['success' => false, 'error' => 'Skill not found.'];
            }
            return self::lint_result([$name => Claudpress_Skill_Standard::check($all[$name], $known)]);
        }

        // Everything, including the links between them.
        $by_skill = [];
        foreach ($all as $name => $row) {
            $by_skill[$name] = Claudpress_Skill_Standard::check($row, $known);
        }
        foreach (Claudpress_Skill_Standard::check_graph($all) as $finding) {
            $by_skill[$finding['skill']][] = $finding;
        }
        return self::lint_result($by_skill);
    }

    /** @param array<string, array<int, array{level:string, message:string}>> $by_skill */
    private static function lint_result(array $by_skill): array
    {
        $flat = [];
        $out  = [];

        foreach ($by_skill as $name => $findings) {
            $flat = array_merge($flat, $findings);
            $out[] = [
                'name'     => $name,
                'score'    => Claudpress_Skill_Standard::score($findings),
                'passes'   => !Claudpress_Skill_Standard::has_errors($findings),
                'findings' => array_map(
                    static fn(array $f): string => $f['level'] . ': ' . $f['message'],
                    $findings
                ),
            ];
        }

        return [
            'success' => true,
            'score'   => Claudpress_Skill_Standard::score($flat),
            'passes'  => !Claudpress_Skill_Standard::has_errors($flat),
            'skills'  => $out,
        ];
    }

    public static function refresh(array $a): array
    {
        $report = Claudpress_Skills::seed_bundled_skills(!empty($a['force']));
        return array_merge(['success' => true], $report, [
            'note' => $report['kept']
                ? 'Kept (edited on this site, or installed before Claudpress tracked edits): ' . implode(', ', $report['kept']) . '. Re-run with force=true to replace them.'
                : 'Every bundled skill is current.',
        ]);
    }

    public static function delete(array $a): array
    {
        return Claudpress::instance()->skills->delete((string) ($a['name'] ?? ''));
    }
}
