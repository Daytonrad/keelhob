<?php
/**
 * Give the clean rig a site's worth of real data, so the handover has something
 * to carry. Distinct row counts per table, so a table that ends up holding
 * another table's rows is visible instantly.
 */
global $wpdb;
$p = $wpdb->prefix . 'claudpress_';

$rows = [
    'agent_runs'        => 3,
    'chat'              => 6,
    'feedback'          => 4,
    'log'               => 11,
    'snippet_log'       => 14,
    'snippet_revisions' => 7,
    'snippets'          => 5,
    'tasks'             => 2,
];

$make = static function (string $base, int $i): array {
    switch ($base) {
        case 'agent_runs':        return ['context' => 'rig', 'model' => 'mock', 'instruction' => "run {$i}", 'status' => 'done', 'steps' => $i];
        case 'chat':              return ['chat_id' => '12345', 'role' => 'user', 'content' => "message {$i}"];
        case 'feedback':          return ['friction' => 'tool_missing', 'tool' => "tool_{$i}", 'note' => "note {$i}", 'source' => 'auto'];
        case 'log':               return ['tool' => "tool_{$i}", 'args_json' => '{}', 'ok' => 1, 'summary' => "did thing {$i}"];
        case 'snippet_log':       return ['snippet_uid' => "uid-{$i}", 'snippet_name' => "snip {$i}", 'event' => 'save', 'actor' => 'agent'];
        case 'snippet_revisions': return ['snippet_id' => $i, 'snippet_uid' => "uid-{$i}", 'name' => "snip {$i}", 'code' => "// rev {$i}"];
        case 'snippets':          return ['name' => "snip {$i}", 'code' => "// body {$i}", 'snippet_type' => 'php', 'location' => 'everywhere', 'active' => 1, 'uid' => "uid-{$i}"];
        case 'tasks':             return ['name' => "task {$i}", 'instruction' => "do {$i}", 'mode' => 'report', 'schedule' => 'daily', 'enabled' => 1];
    }
    return [];
};

foreach ($rows as $base => $n) {
    for ($i = 1; $i <= $n; $i++) {
        if (!$wpdb->insert($p . $base, $make($base, $i))) {
            echo "SEED FAILED on {$base}: " . $wpdb->last_error . "\n";
            exit(1);
        }
    }
}

// A memory key the site owner wrote, on top of the eight defaults.
$wpdb->insert($p . 'memory', ['mem_key' => 'rig_owner_note', 'mem_value' => 'written by the owner, must survive', 'category' => 'general']);

// Settings that look like a connected site. These are rig values, not secrets.
$settings = get_option('claudpress_settings', []);
$settings = is_array($settings) ? $settings : [];
$settings['agent_api_key']     = 'rig-not-a-real-key-' . str_repeat('x', 40);
$settings['agent_provider']    = 'anthropic';
$settings['abilities_enabled'] = 1;
$settings['token_hash']        = hash('sha256', 'rig-token');
update_option('claudpress_settings', $settings);
update_option('claudpress_site_id', wp_generate_uuid4());  // 36 chars: Updater::site_id() rejects anything else.

foreach (['agent_runs','chat','feedback','log','memory','skills','snippet_log','snippet_revisions','snippets','tasks'] as $b) {
    printf("%-18s %s\n", $b, $wpdb->get_var("SELECT COUNT(*) FROM `{$p}{$b}`"));
}
echo "settings keys: " . count($settings) . "\n";
