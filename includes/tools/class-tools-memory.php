<?php
/**
 * Memory tools — cross-session persistence. The agent decides what to remember.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Tools_Memory
{
    public static function register(Keelhob_Tool_Registry $r): void
    {
        $r->add('memory_save', 'Save a fact to this site\'s persistent memory so it survives across sessions and is shared by every connected agent. Use for project conventions, decisions, brand rules, and pitfalls (e.g. key="brand.primary_color", value="#f8ca50"). Overwrites an existing key.', [
            'type' => 'object',
            'properties' => [
                'key'      => ['type' => 'string', 'description' => 'Short stable identifier, e.g. "brand.font" or "deploy.notes".'],
                'value'    => ['type' => 'string', 'description' => 'The fact to remember.'],
                'category' => ['type' => 'string', 'default' => 'general'],
            ],
            'required' => ['key', 'value'],
        ], [self::class, 'save']);

        $r->add('memory_list', 'List all memory entries stored for this site (keys, categories, values). Read-only; works even while abilities are disabled.', [
            'type' => 'object', 'properties' => (object) [],
        ], [self::class, 'list'], ['readOnlyHint' => true]);

        $r->add('memory_get', 'Read a single memory entry by key.', [
            'type' => 'object',
            'properties' => ['key' => ['type' => 'string']],
            'required' => ['key'],
        ], [self::class, 'get'], ['readOnlyHint' => true]);

        $r->add('memory_delete', 'Delete a memory entry by key.', [
            'type' => 'object',
            'properties' => ['key' => ['type' => 'string']],
            'required' => ['key'],
        ], [self::class, 'delete'], ['destructiveHint' => true]);

        /*
         * Lessons are not memory, and the difference is the whole point.
         *
         * Memory is a store you have to remember to read. A lesson is injected
         * into the description of the tool it is about, so the next agent reads
         * it whether it went looking or not. Use memory for facts about the
         * site; use a lesson for "this tool behaves like THIS here".
         */
        $r->add('lesson_save', 'Save a one-line lesson about a specific tool, so every future agent reads it in that tool\'s own description without having to look it up. Use it the moment you work out WHY something failed or what this site needs doing differently — e.g. topic="seo_meta", lesson="Rank Math is the SEO plugin here, not Yoast." The topic is normally a tool name; use "general" for something true of the whole site. Max 300 characters, plain text, no markup. The owner can read, edit and delete every lesson, and it is recorded as having come from the agent.', [
            'type' => 'object',
            'properties' => [
                'topic'  => ['type' => 'string', 'description' => 'A tool name, or "general" for the whole site.'],
                'lesson' => ['type' => 'string', 'description' => 'One sentence, 300 characters or fewer.'],
            ],
            'required' => ['topic', 'lesson'],
        ], [self::class, 'lesson_save']);

        $r->add('lesson_list', 'List the lessons saved on this site, with the tool each one is attached to and whether the owner or an agent wrote it. Read-only.', [
            'type' => 'object',
            'properties' => ['topic' => ['type' => 'string', 'description' => 'Optional: only this topic.']],
        ], [self::class, 'lesson_list'], ['readOnlyHint' => true]);
    }

    public static function save(array $a): array
    {
        return Keelhob::instance()->memory->save((string) ($a['key'] ?? ''), (string) ($a['value'] ?? ''), (string) ($a['category'] ?? 'general'));
    }

    public static function list(array $a): array
    {
        $all = Keelhob::instance()->memory->all();
        return ['success' => true, 'count' => count($all), 'memory' => $all];
    }

    public static function get(array $a): array
    {
        $m = Keelhob::instance()->memory->get((string) ($a['key'] ?? ''));
        return $m ? ['success' => true, 'entry' => $m] : ['success' => false, 'error' => 'No memory for that key.'];
    }

    public static function delete(array $a): array
    {
        return Keelhob::instance()->memory->delete((string) ($a['key'] ?? ''));
    }

    /**
     * The source is hard-coded to 'agent' and is NOT read from the arguments.
     *
     * A lesson becomes a standing instruction to something that can change a
     * live site, so "who said this?" has to have a truthful answer. If the
     * agent could claim a lesson came from the owner, the attribution shown in
     * the admin would be worth nothing, and the attribution is one of the four
     * guards on this surface. See the header of class-lessons.php.
     */
    public static function lesson_save(array $a): array
    {
        return Keelhob_Lessons::save(
            (string) ($a['topic'] ?? ''),
            (string) ($a['lesson'] ?? ''),
            'agent'
        );
    }

    public static function lesson_list(array $a): array
    {
        $topic = trim((string) ($a['topic'] ?? ''));
        $rows  = $topic !== ''
            ? Keelhob_Lessons::for_topic($topic, Keelhob_Lessons::MAX_PER_TOPIC)
            : Keelhob_Lessons::all(200);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'      => (int) $row['id'],
                'topic'   => (string) $row['topic'],
                'lesson'  => (string) $row['lesson'],
                'source'  => (string) $row['source'],
                'created' => (string) ($row['created_at'] ?? ''),
            ];
        }

        // Stating the count is not padding: a model once read 44 rows as 28 and
        // acted on the short list. Every list tool here says its own size.
        return ['success' => true, 'count' => count($out), 'lessons' => $out];
    }
}
