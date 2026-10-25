<?php
/**
 * The two tools that let the model write a new tool.
 *
 * Both of them save and nothing else. Neither one can make code live, and there
 * is deliberately no third tool here — no `ability_approve`, no
 * `ability_rollback`, no `ability_delete` — because the moment such a tool
 * existed the approval gate would have a second door with the model's hand on
 * it. The owner's actions live in Keelhob_Admin_Abilities, behind
 * `manage_options` and a nonce, reachable only from a form a human submitted.
 *
 * These are registered only when the owner has switched authoring ON, so on a
 * site that never asked for this the model does not see them in tools/list at
 * all. See Keelhob_Abilities::authoring_enabled().
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Tools_Abilities
{
    public static function register(Keelhob_Tool_Registry $r): void
    {
        $r->add(
            'ability_create',
            'Write a brand new ability (a new tool for this site) when none of the existing ones can do the job. '
            . 'It is saved as PENDING and runs NOTHING: it only goes live after the site owner reads the code and '
            . 'approves it on Keelhob > Connect > "Abilities the AI wrote". Send `code` as a PHP function BODY with '
            . 'no <?php tag and no ?>: it is compiled as `function( array $input ) { YOUR CODE }`, so read arguments '
            . 'from $input and `return` an array. The full WordPress runtime is available. Describe the ability the '
            . 'way you would want it described to you in tools/list, because that is where you will read it back. '
            . 'Set writes=true if it changes anything on the site, and risky=true if a mistake would be hard to '
            . 'undo — the owner sees both as badges on the review card. Tell the owner afterwards that something is '
            . 'waiting for them, and where.',
            [
                'type'       => 'object',
                'properties' => [
                    'name'         => [
                        'type'        => 'string',
                        'description' => 'Lowercase letters, digits and underscores, 3-61 characters, starting with a letter, e.g. list_scheduled_posts. Must not be the name of a built-in tool.',
                    ],
                    'label'        => [
                        'type'        => 'string',
                        'description' => 'A few plain words the owner reads on the review card, e.g. "List scheduled posts".',
                    ],
                    'description'  => [
                        'type'        => 'string',
                        'description' => 'What it does and when to use it. This becomes the tool description you read in tools/list.',
                    ],
                    'input_schema' => [
                        'type'        => 'object',
                        'description' => 'JSON Schema for the arguments, e.g. {"type":"object","properties":{"limit":{"type":"integer"}},"required":[]}. Omit for an ability that takes none.',
                    ],
                    'code'         => [
                        'type'        => 'string',
                        'description' => 'The PHP function body. No <?php, no ?>. Arguments arrive in $input; return an array.',
                    ],
                    'writes'       => ['type' => 'boolean', 'description' => 'True if it changes anything on the site.'],
                    'risky'        => ['type' => 'boolean', 'description' => 'True if a mistake would be hard or impossible to undo.'],
                ],
                'required'   => ['name', 'label', 'description', 'code'],
            ],
            [self::class, 'create'],
            ['title' => 'Write a new ability', 'destructiveHint' => false, 'readOnlyHint' => false]
        );

        $r->add(
            'ability_improve',
            'Submit a better version of a CUSTOM ability — new code, description or inputs. The version the owner '
            . 'already approved keeps working until they approve this one, so nothing on the site stops working while '
            . 'it waits. The new version gets its own rating, so the owner can see whether it is actually better. Any '
            . 'field you leave out is inherited from the latest version, so an improve that only changes the code can '
            . 'send just `name`, `code` and `change_note`. Built-in tools can never be rewritten this way: save a '
            . 'lesson for those with lesson_save instead, which is read back into their description on every '
            . 'tools/list.',
            [
                'type'       => 'object',
                'properties' => [
                    'name'         => ['type' => 'string', 'description' => 'The existing custom ability to improve.'],
                    'label'        => ['type' => 'string'],
                    'description'  => ['type' => 'string'],
                    'input_schema' => ['type' => 'object'],
                    'code'         => ['type' => 'string', 'description' => 'The PHP function body. No <?php, no ?>.'],
                    'writes'       => ['type' => 'boolean'],
                    'risky'        => ['type' => 'boolean'],
                    'change_note'  => [
                        'type'        => 'string',
                        'description' => 'Required. What changed and why, in plain English. The owner reads this as "What changed" and will not approve a replacement without it.',
                    ],
                ],
                'required'   => ['name', 'change_note'],
            ],
            [self::class, 'improve'],
            ['title' => 'Improve an ability', 'destructiveHint' => false, 'readOnlyHint' => false]
        );
    }

    /** @param array<string, mixed> $args */
    public static function create(array $args): array
    {
        return Keelhob_Abilities::propose($args, true);
    }

    /** @param array<string, mixed> $args */
    public static function improve(array $args): array
    {
        return Keelhob_Abilities::propose($args, false);
    }
}
