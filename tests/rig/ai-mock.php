<?php
/**
 * Plugin Name: Claudpress rig — AI mock
 * Description: Intercepts outbound AI provider calls and replays canned responses. FOR THE TEST RIG ONLY.
 * Version: 1.0.0
 *
 * Copy this into the rig's wp-content/mu-plugins/ directory. It must NEVER be
 * inside the plugin repo's shipped tree — hence living under tests/rig/ and
 * being copied in by hand.
 *
 * Why this exists: the AI paths (Ask AI, Compose, the autonomous agent) are the
 * hardest parts of the plugin to get right and the easiest to leave untested,
 * because exercising them normally costs real money against a real key. There
 * is no key on this rig and inventing one is not an option, so outbound calls
 * are answered locally instead.
 *
 * What it does NOT do is fake the plugin's own logic. Everything from the
 * request being built to the response being parsed, validated, linted and saved
 * runs for real — only the network hop is replaced. That is the part worth
 * testing, and it is exactly the part a hand-written stub of the plugin's own
 * classes would have skipped.
 *
 * Set CLAUDPRESS_MOCK_FAIL to make every call fail, to exercise error handling.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('pre_http_request', 'claudpress_rig_ai_mock', 10, 3);

function claudpress_rig_ai_mock($preempt, $args, $url) {
    $hosts = ['api.anthropic.com', 'api.openai.com', 'generativelanguage.googleapis.com'];
    $host  = (string) wp_parse_url($url, PHP_URL_HOST);

    if (!in_array($host, $hosts, true)) {
        return $preempt;
    }

    if (defined('CLAUDPRESS_MOCK_FAIL') && CLAUDPRESS_MOCK_FAIL) {
        return new WP_Error('http_request_failed', 'Mocked network failure.');
    }

    // The model list endpoints, used by the settings screen's Refresh button.
    if (strpos($url, '/models') !== false && ($args['method'] ?? 'GET') === 'GET') {
        return claudpress_rig_response([
            'data' => [
                ['id' => 'mock-model-newest', 'created' => time(),         'created_at' => gmdate('c')],
                ['id' => 'mock-model-older',  'created' => time() - 86400, 'created_at' => gmdate('c', time() - 86400)],
            ],
        ]);
    }

    $sent   = json_decode((string) ($args['body'] ?? '{}'), true) ?: [];
    $prompt = claudpress_rig_last_user_text($sent);
    $text   = claudpress_rig_answer($prompt);

    // Answer in the shape the requesting provider's parse() expects.
    if ($host === 'api.anthropic.com') {
        return claudpress_rig_response([
            'id'    => 'msg_mock',
            'model' => $sent['model'] ?? 'mock-model-newest',
            'role'  => 'assistant',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ]);
    }

    if ($host === 'api.openai.com') {
        return claudpress_rig_response([
            'id'      => 'chatcmpl-mock',
            'model'   => $sent['model'] ?? 'mock-model-newest',
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $text]]],
            'usage'   => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
        ]);
    }

    return claudpress_rig_response([
        'candidates' => [['content' => ['parts' => [['text' => $text]], 'role' => 'model'], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 50],
    ]);
}

/**
 * Answer in kind: a request that asks for JSON gets JSON, and one that asks for
 * a snippet gets code. Returning the same blob to everything would make every
 * flow "pass" while proving nothing about whether the plugin can read a real
 * answer.
 */
function claudpress_rig_answer(string $prompt): string {
    $p = strtolower($prompt);

    if (strpos($p, 'verdict') !== false || strpos($p, 'review') !== false || strpos($p, 'safe') !== false) {
        return wp_json_encode([
            'summary'      => 'Adds a filter that appends a line to post content. Low risk.',
            'verdict'      => 'caution',
            'what_it_does' => 'Hooks the_content and appends a string.',
            'site_impact'  => 'Visible on every single post on the front end.',
            'risks'        => ['Runs on every request', 'No capability check'],
            'recommended_type'     => 'php',
            'recommended_location' => 'everywhere',
            'suggested_title'      => 'Append note to content',
            'suggested_tags'       => ['content', 'filter'],
            'should_activate'      => false,
            'notes'                => 'Check a post on the front end after switching it on.',
        ]);
    }

    if (strpos($p, 'plan') !== false && strpos($p, 'step') !== false) {
        return wp_json_encode([
            'summary' => 'Add a small PHP snippet that appends a note to post content.',
            'steps'   => ['Hook the_content', 'Append the note', 'Return the content'],
            'risks'   => ['Runs on every front-end request'],
            'type'    => 'php',
            'location' => 'everywhere',
        ]);
    }

    // Default: a snippet. Deliberately valid PHP, so the lint gate passes and the
    // test exercises the SUCCESS path. Flip a character in a test to exercise
    // the refusal path instead.
    return "<?php\nadd_filter('the_content', function (\$content) {\n    return \$content . '<!-- mocked snippet -->';\n});\n";
}

function claudpress_rig_last_user_text(array $sent): string {
    foreach (['messages', 'contents'] as $key) {
        if (empty($sent[$key]) || !is_array($sent[$key])) {
            continue;
        }
        $last = end($sent[$key]);
        if (is_string($last['content'] ?? null)) {
            return $last['content'];
        }
        foreach ((array) ($last['content'] ?? $last['parts'] ?? []) as $part) {
            if (is_string($part['text'] ?? null)) {
                return $part['text'];
            }
        }
    }
    return (string) ($sent['system'] ?? '');
}

function claudpress_rig_response(array $body): array {
    return [
        'headers'  => [],
        'body'     => wp_json_encode($body),
        'response' => ['code' => 200, 'message' => 'OK'],
        'cookies'  => [],
        'filename' => null,
    ];
}
