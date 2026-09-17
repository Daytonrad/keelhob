<?php
/**
 * Step 4 of tests/rig/upgrade-check.sh — compare, and say plainly what moved.
 *
 * The assertions are ordered by how badly each failure would hurt a real owner:
 * data loss first, then silently-granted permissions, then behaviour, then the
 * new features reading the old rows.
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

/*
 * THE TALLY LIVES IN A STATIC, NOT IN $GLOBALS.
 *
 * It was $GLOBALS['pass'] / $GLOBALS['fail'], incremented inside check() and
 * read back from $pass / $fail at the bottom. Under `wp eval-file` the file
 * body is eval'd INSIDE A METHOD, so its top-level variables are ordinary
 * locals and are not the same storage as $GLOBALS. The counters therefore
 * stayed at zero whatever happened: the run printed real FAIL lines and then
 * exited 0, and the wrapper announced "Upgrade check passed".
 *
 * A test that prints failures and then calls itself green is worse than no test
 * at all, so the count is kept somewhere the function can actually reach.
 */
function kh_tally(?bool $ok = null): array
{
    static $pass = 0;
    static $fail = 0;
    if ($ok === true) {
        $pass++;
    } elseif ($ok === false) {
        $fail++;
    }
    return ['pass' => $pass, 'fail' => $fail];
}

function check(string $label, bool $ok, string $detail = ''): void
{
    kh_tally($ok);
    printf("      %s  %s%s\n", $ok ? 'PASS' : 'FAIL', str_pad($label, 58), $detail);
}

$before   = (array) get_option('kh_upgrade_before', []);
$store    = (array) get_option('kh_upgrade_ids', []);
$ids      = (array) ($store['ids'] ?? []);
$neighbour = (array) get_option('kh_upgrade_neighbour', []);
$t        = Keelhob_Snippets::table();

if (empty($before)) {
    echo "      FAILED: no 'before' fingerprint — step 3 did not run.\n";
    exit(1);
}

// ---- 0. the upgrade actually happened -------------------------------------
check(
    'the plugin is running the new version',
    KEELHOB_VERSION !== (string) ($store['old_ver'] ?? ''),
    (string) ($store['old_ver'] ?? '?') . ' -> ' . KEELHOB_VERSION
);
check(
    'and the migration ran and recorded itself',
    (string) get_option('keelhob_db_version', '') === KEELHOB_VERSION
);

// ---- 1. nothing was lost ---------------------------------------------------
foreach ((array) $before['snippets'] as $label => $was) {
    $now = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $was['id']), ARRAY_A);
    if (!$now) {
        check("snippet '{$label}' survived", false, 'ROW IS GONE');
        continue;
    }
    check("snippet '{$label}' survived", true);
    check("  ...with its code byte-identical", (string) $now['code'] === (string) $was['code']);
    check("  ...and its name", (string) $now['name'] === (string) $was['name']);
    check("  ...and its type and location", (string) $now['snippet_type'] === (string) $was['snippet_type'] && (string) $now['location'] === (string) $was['location']);

    // The one that matters most. An upgrade that switches somebody's code ON is
    // worse than one that loses it: they know when something is missing.
    check("  ...and its on/off state UNCHANGED", (int) $now['active'] === (int) $was['active'], 'was ' . (int) $was['active'] . ', now ' . (int) $now['active']);

    // Rollback safety: 1.2.0 lifts the old shape at READ time and must not
    // rewrite the column, so downgrading stays possible.
    check("  ...and its stored conditions NOT rewritten", (string) $now['conditions_json'] === (string) $was['conditions_json']);
}

$rev_table = Keelhob_Snippets::revisions_table();
$revs_now  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$rev_table} WHERE snippet_id = %d", (int) ($ids['revisions'] ?? 0)));
check('revision history is intact', $revs_now >= (int) $before['counts']['revisions'], $before['counts']['revisions'] . ' -> ' . $revs_now);

check('memory rows survived', (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'keelhob_memory') >= (int) $before['counts']['memory']);
$memory = new Keelhob_Memory();
check("  ...and the value is readable", strpos((string) wp_json_encode($memory->get('up-memory-key')), 'before the upgrade') !== false);

$custom = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . "keelhob_skills WHERE name = %s", 'up-custom-skill'), ARRAY_A);
check("the owner's own skill was not overwritten", $custom && strpos((string) $custom['body'], 'This is a fixture') !== false);

check('settings survived', (string) Keelhob::instance()->get('agent_model', '') === (string) $before['settings']['agent_model']);
check('  ...including abilities being ON', (bool) Keelhob::instance()->get('abilities_enabled', false) === (bool) $before['settings']['abilities_enabled']);

// ---- 2. nothing was silently granted ---------------------------------------
$caps_now = [];
foreach (array_keys(Keelhob_Capabilities::groups()) as $g) {
    if (Keelhob_Capabilities::is_group_enabled($g)) {
        $caps_now[] = $g;
    }
}
sort($caps_now);
$gained = array_values(array_diff($caps_now, (array) $before['caps_on']));
$lost   = array_values(array_diff((array) $before['caps_on'], $caps_now));

// THE SECURITY ASSERTION. An automatic update must never hand a site abilities
// nobody asked for -- especially payments, users, comms or deploy.
check('the upgrade granted NO new capability group', empty($gained), $gained ? 'GAINED: ' . implode(', ', $gained) : 'none gained');
check('  ...and took none away either', empty($lost), $lost ? 'LOST: ' . implode(', ', $lost) : 'none lost');

// ---- 3. the site still works ----------------------------------------------
$res  = wp_remote_get(home_url('/'), ['timeout' => 30]);
$code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
$body = is_wp_error($res) ? '' : (string) wp_remote_retrieve_body($res);

check('the front end still answers 200', $code === 200, (string) $code);
check('the live snippet STILL RUNS after the upgrade', strpos($body, 'up-live-marker') !== false);
check('  ...and no PHP error leaked onto the page', stripos($body, 'fatal error') === false && stripos($body, 'parse error') === false);

// ---- 4. the new features can read the old rows -----------------------------
// This is the part a fresh install can never test: 1.2.0's condition engine
// meeting a row written months earlier by a version that had no idea it was
// coming.
$flat_row = Keelhob_Snippets::get((int) ($ids['flat'] ?? 0));

// normalize(), NOT decode(). decode() is only a JSON parser; normalize() is the
// thing that lifts the old flat shape. Calling decode() here reported "rules: 0"
// and looked exactly like a migration that had silently dropped somebody's
// conditions — the test was wrong, not the plugin.
$lifted = Keelhob_Snippet_Conditions::normalize($flat_row['conditions_json'] ?? '');

// Rules are matched by TYPE, not by searching the JSON for a substring. The
// legacy key is "post_types" and the new type is "post_type", so a substring
// search passes on the un-lifted row too — it would have gone green against
// exactly the bug it is meant to catch.
$types = array_column((array) $lifted['rules'], 'type');

check('the old flat conditions are LIFTED into the new shape', count($types) > 0, 'rules: ' . count($types) . ' [' . implode(', ', $types) . ']');
check('  ...with a relation set', in_array((string) ($lifted['relation'] ?? ''), ['AND', 'OR'], true), (string) ($lifted['relation'] ?? '(none)'));
check('  ...and they are switched on', !empty($lifted['enabled']));
check('  ...logged-out rule carried over', in_array('user_status', $types, true));
check('  ...front-page rule carried over', in_array('page_type', $types, true));
check('  ...post-type rule carried over', in_array('post_type', $types, true));
check('  ...post-id rule carried over', in_array('post_id', $types, true));

// The exemption that keeps old snippets behaving as they did: 1.1.0 wrapped
// post_types/post_ids in is_singular(), so on an archive those rules did not
// APPLY rather than failing. Dropping that flag would silently change what an
// upgraded site shows.
$exempt = 0;
foreach ((array) $lifted['rules'] as $rule) {
    if (!empty($rule['only_singular'])) {
        $exempt++;
    }
}
check('  ...and the only_singular exemption is preserved', $exempt >= 2, $exempt . ' rule(s) carry it');

$plain_row = Keelhob_Snippets::get((int) ($ids['plain'] ?? 0));
check('a snippet with no conditions did NOT gain any', empty($plain_row['conditions_json']));

// The editor is where an owner meets their upgraded data. A row that survives
// the database and then throws on screen has still ruined their day.
ob_start();
$_GET['snippet'] = (int) ($ids['flat'] ?? 0);
$_GET['tab']     = 'edit';
wp_set_current_user(1);
Keelhob_Studio_Edit::render();
$html = (string) ob_get_clean();
check('the editor renders an upgraded snippet without dying', strlen($html) > 500 && stripos($html, 'fatal error') === false, strlen($html) . ' bytes');
check('  ...and the rule builder is on the page', strpos($html, 'kh-conditions') !== false);
// The checkbox must be TICKED. A builder that renders with conditions switched
// off tells an upgrading owner their rules are gone, whatever the row says.
check('  ...with conditions shown as switched ON', preg_match('/id="kh-cond-enabled"[^>]*checked/', $html) === 1);
check('  ...and the relation control is rendered', strpos($html, 'conditions_relation') !== false);

/*
 * THE RULES ARE HANDED TO JAVASCRIPT, NOT PRINTED.
 *
 * <div id="kh-cond-rules"> ships EMPTY; the builder draws the rows from data
 * passed through wp_localize_script. Searching the server HTML for "front_page"
 * therefore failed while the feature worked perfectly — the assertion was
 * reading the wrong layer, not finding a bug.
 *
 * So check the payload the script is actually given. That is the real seam
 * between "the row was lifted" and "the owner can see their rules".
 */
$localized = (string) wp_scripts()->get_data('keelhob-studio-edit', 'data');
check('  ...and the lifted rules are handed to the rule builder', strpos($localized, 'front_page') !== false && strpos($localized, 'user_status') !== false);
check('  ...with the post-type rule among them', strpos($localized, 'post_type') !== false);

// ---- 5. the wipe stayed in its lane ---------------------------------------
$vibe_now = (int) count((array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'vibesnip_') . '%')));
check("the neighbouring plugin's tables are untouched", $vibe_now === (int) ($neighbour['tables'] ?? -1), (int) ($neighbour['tables'] ?? -1) . ' -> ' . $vibe_now);
check('  ...and so are the posts', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}") === (int) ($neighbour['posts'] ?? -1));
check('  ...and the users', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}") === (int) ($neighbour['users'] ?? -1));

// ---- teardown -------------------------------------------------------------
// In the same run that asserted on them, pass or fail.
foreach ($ids as $id) {
    Keelhob_Snippets::delete((int) $id);
}
$memory->delete('up-memory-key');
if ($custom) {
    (new Keelhob_Skills())->delete((string) $custom['name']);
}
delete_option('kh_upgrade_before');
delete_option('kh_upgrade_ids');
delete_option('kh_upgrade_neighbour');

$left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE name LIKE 'UP %'");
check('fixtures torn down', $left === 0, $left . ' left');

$tally = kh_tally();
printf("\n      %d passed, %d failed\n", $tally['pass'], $tally['fail']);
if ($tally['fail'] > 0) {
    exit(1);
}
