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

        $r->add('skill_get', 'Load the full body of a skill by name. Call this when a task matches a skill\'s description so you can follow its playbook.', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ], [self::class, 'get'], ['readOnlyHint' => true]);

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

        return ['success' => true, 'skills' => $skills];
    }

    public static function get(array $a): array
    {
        $s = Claudpress::instance()->skills->get_by_name((string) ($a['name'] ?? ''));
        return $s ? ['success' => true, 'name' => $s['name'], 'description' => $s['description'], 'body' => $s['body']] : ['success' => false, 'error' => 'Skill not found.'];
    }

    public static function save(array $a): array
    {
        return Claudpress::instance()->skills->save($a);
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
