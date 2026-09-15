<?php
/**
 * Step 3 of tests/rig/upgrade-check.sh — record exactly what the old version
 * holds, before any new file touches the site.
 *
 * The fingerprint is stored in an option rather than printed and re-parsed, so
 * step 4 compares VALUES rather than two pieces of formatted text that happen
 * to look alike.
 *
 * `conditions_json` is captured RAW, straight out of the column. 1.2.0 lifts
 * the old flat shape into the new one at READ time and deliberately does not
 * rewrite the row — that is what makes the upgrade rollback-safe — so the raw
 * column staying byte-identical is the actual promise being tested. Reading it
 * back through the new accessor would compare the new version to itself.
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$t     = Claudpress_Snippets::table();
$store = (array) get_option('cp_upgrade_ids', []);
$ids   = (array) ($store['ids'] ?? []);

$snap = ['snippets' => [], 'counts' => [], 'live_html' => ''];

foreach ($ids as $label => $id) {
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $id), ARRAY_A);
    if (!$row) {
        printf("      FAILED: snippet '%s' (id %d) is missing before we even upgraded\n", $label, (int) $id);
        exit(1);
    }
    $snap['snippets'][$label] = [
        'id'              => (int) $row['id'],
        'name'            => (string) $row['name'],
        'code'            => (string) $row['code'],
        'snippet_type'    => (string) $row['snippet_type'],
        'location'        => (string) $row['location'],
        'active'          => (int) $row['active'],
        'conditions_json' => $row['conditions_json'],   // raw, on purpose
        'uid'             => (string) ($row['uid'] ?? ''),
    ];
}

$rev_table = Claudpress_Snippets::revisions_table();
$snap['counts'] = [
    'snippets'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}"),
    'revisions' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$rev_table} WHERE snippet_id = %d", (int) ($ids['revisions'] ?? 0))),
    'memory'    => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'claudpress_memory'),
    'skills'    => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'claudpress_skills'),
];

// The live snippet's marker, as a logged-out visitor actually sees it. This is
// the difference between "the row survived" and "the site still works".
$res = wp_remote_get(home_url('/'), ['timeout' => 30]);
$snap['live_html'] = is_wp_error($res)
    ? 'HTTP ERROR: ' . $res->get_error_message()
    : (strpos((string) wp_remote_retrieve_body($res), 'up-live-marker') !== false ? 'MARKER PRESENT' : 'MARKER ABSENT');

$snap['settings'] = [
    'agent_model'       => (string) Claudpress::instance()->get('agent_model', ''),
    'abilities_enabled' => (bool) Claudpress::instance()->get('abilities_enabled', false),
];

// Which capability groups are on BEFORE. An upgrade that quietly widens this is
// the finding that would matter most, and it is invisible without a baseline.
$snap['caps_on'] = [];
foreach (array_keys(Claudpress_Capabilities::groups()) as $g) {
    if (Claudpress_Capabilities::is_group_enabled($g)) {
        $snap['caps_on'][] = $g;
    }
}
sort($snap['caps_on']);

$snap['db_version'] = (string) get_option('claudpress_db_version', '');

update_option('cp_upgrade_before', $snap, false);

printf(
    "      %d snippet(s), %d revision(s), %d memory row(s), %d skill(s); db_version %s\n",
    $snap['counts']['snippets'],
    $snap['counts']['revisions'],
    $snap['counts']['memory'],
    $snap['counts']['skills'],
    $snap['db_version'] !== '' ? $snap['db_version'] : '(unset)'
);
printf("      %d capability group(s) on; front end: %s\n", count($snap['caps_on']), $snap['live_html']);

if ($snap['live_html'] !== 'MARKER PRESENT') {
    echo "      FAILED: the live snippet is not reaching the front end before the upgrade,\n";
    echo "              so nothing after this could tell an upgrade bug from a broken fixture.\n";
    exit(1);
}
