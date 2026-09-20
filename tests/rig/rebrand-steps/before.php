<?php
/**
 * Fingerprint the site while it is still running under the old product name,
 * and plant a marker that only adoption could carry across.
 *
 * WHY THE MARKER EXISTS.
 *
 * The first version of this check compared row counts and the first three skill
 * names, and reported success on a run where nothing had been adopted at all.
 * Activation had created empty tables and then re-seeded them with the same 24
 * bundled skills and the same 8 default memories. Identical counts, identical
 * names, and the owner's real data still sitting in the old tables.
 *
 * A marker with a random name cannot be re-seeded. If it is in the new table
 * afterwards, the rows were genuinely moved.
 *
 * Read through $wpdb directly rather than through the plugin's own classes: the
 * plugin is about to be replaced, and a fingerprint taken through code that is
 * itself being swapped out would compare the new code against itself.
 *
 * Echoes one line of JSON, because the shell has to hold it between steps.
 */

global $wpdb;

$legacy = 'claud' . 'press';
$out    = ['tables' => [], 'options' => []];

// Plant first, so the marker is included in the counts recorded below.
$marker = 'rebrand-marker-' . wp_generate_password(12, false);
$planted = $wpdb->insert($wpdb->prefix . $legacy . '_skills', [
    'name'        => $marker,
    'description' => 'Planted by tests/rig/rebrand-check.sh. Removed by the same run.',
    'body'        => '# marker',
    'source'      => 'user',
    'enabled'     => 0,
]);

// Fail LOUDLY. The first version named a column that does not exist, the insert
// quietly did nothing, and the check on the other side then looked for a row
// that had never been planted -- reporting a failure whose real cause was in
// this file. A marker that can go missing unnoticed proves nothing.
if (!$planted) {
    echo 'MARKER_INSERT_FAILED: ' . $wpdb->last_error;
    exit(1);
}
$out['marker'] = $marker;

foreach (['agent_runs', 'chat', 'feedback', 'log', 'memory', 'skills',
          'snippet_log', 'snippet_revisions', 'snippets', 'tasks'] as $base) {
    $table = $wpdb->prefix . $legacy . '_' . $base;

    $previous         = $wpdb->suppress_errors(true);
    $wpdb->last_error = '';
    $count            = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    $exists           = $wpdb->last_error === '';
    $wpdb->suppress_errors($previous);

    $out['tables'][$base] = $exists ? (int) $count : null;
}

$settings = get_option($legacy . '_settings', []);
$settings = is_array($settings) ? $settings : [];

// Never the secret itself. A length and a hash prefix prove it survived without
// putting a provider key or a token hash into a terminal or a CI log.
$out['options'] = [
    'settings_keys'   => count($settings),
    'has_api_key'     => !empty($settings['agent_api_key']),
    'api_key_len'     => isset($settings['agent_api_key']) ? strlen((string) $settings['agent_api_key']) : 0,
    'token_hash_head' => isset($settings['token_hash']) ? substr((string) $settings['token_hash'], 0, 8) : '',
    'abilities'       => !empty($settings['abilities_enabled']),
    'site_id'         => (string) get_option($legacy . '_site_id', ''),
];

echo wp_json_encode($out);
