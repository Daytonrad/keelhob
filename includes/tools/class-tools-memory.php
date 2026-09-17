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
}
