<?php
/**
 * Compare the site after the handover against the fingerprint taken before it.
 *
 * The tally is a static inside a function, NOT a $GLOBALS counter. `wp eval-file`
 * evaluates this body inside a method, so a top-level `$fails` would be a local
 * that a helper closure cannot see, and the script would print real FAIL lines
 * and then exit 0 announcing success. That has happened in this repo before.
 */

global $wpdb;

function kh_rebrand_tally(bool $ok = true, bool $read = false): int
{
    static $fails = 0;
    if (!$read && !$ok) {
        $fails++;
    }
    return $fails;
}

function kh_rebrand_check(string $label, $expected, $actual): void
{
    $ok = $expected === $actual;
    kh_rebrand_tally($ok);
    printf(
        "  %s %s%s\n",
        $ok ? "\033[32mok  \033[0m" : "\033[31mFAIL\033[0m",
        $label,
        $ok ? '' : sprintf("\n        expected: %s\n        actual:   %s", wp_json_encode($expected), wp_json_encode($actual))
    );
}

$before = json_decode((string) @file_get_contents(ABSPATH . '.rebrand-before.json'), true);
if (!is_array($before)) {
    echo "FAIL: no before-fingerprint to compare against.\n";
    exit(1);
}

$legacy = 'claud' . 'press';

/**
 * Ask whether a table can be READ, not whether a catalog lists it.
 *
 * SHOW TABLES was what this used first, and it lied. The SQLite drop-in keeps
 * its own registry, which went on listing tables that were no longer there, so
 * every "the old table is gone" check failed against a table that had in fact
 * been renamed correctly. The production code fell into the same trap, in
 * Keelhob_Legacy_Adopt::table_exists(), and was fixed the same way.
 */
$readable = static function (string $table) use ($wpdb): bool {
    $previous         = $wpdb->suppress_errors(true);
    $wpdb->last_error = '';
    $wpdb->get_var("SELECT 1 FROM `{$table}` LIMIT 1");
    $error = (string) $wpdb->last_error;
    $wpdb->suppress_errors($previous);
    return $error === '';
};

// These only ever grow: the plugin writes to them while it boots, so a count
// that went UP is correct and a count that went DOWN is data loss.
$append_only = ['log' => true, 'snippet_log' => true, 'agent_runs' => true, 'memory' => true, 'skills' => true];

echo "Tables\n";
foreach ($before['tables'] as $base => $count) {
    $new = $wpdb->prefix . 'keelhob_' . $base;
    $old = $wpdb->prefix . $legacy . '_' . $base;

    $new_exists = $readable($new);
    kh_rebrand_check("{$base}: exists under the new name", true, $new_exists);

    // Gone, not copied. Two surviving tables means two sources of truth, and
    // the one nothing reads any more is the one that quietly keeps the data.
    kh_rebrand_check("{$base}: the old table is gone, not duplicated", false, $readable($old));

    if ($new_exists && $count !== null) {
        $now = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$new}`");
        if (isset($append_only[$base])) {
            kh_rebrand_check("{$base}: kept its {$count} row(s) or more (now {$now})", true, $now >= (int) $count);
        } else {
            kh_rebrand_check("{$base}: still {$count} row(s)", (int) $count, $now);
        }
    }
}

echo "\nSettings, keys and token\n";
$settings = get_option('keelhob_settings', []);
$settings = is_array($settings) ? $settings : [];

kh_rebrand_check('every settings key came across', (int) $before['options']['settings_keys'], count($settings));
kh_rebrand_check('the provider API key is still present', (bool) $before['options']['has_api_key'], !empty($settings['agent_api_key']));
kh_rebrand_check('and it is the same length, so it was not truncated', (int) $before['options']['api_key_len'], isset($settings['agent_api_key']) ? strlen((string) $settings['agent_api_key']) : 0);
kh_rebrand_check('the hashed connection token is unchanged', (string) $before['options']['token_hash_head'], isset($settings['token_hash']) ? substr((string) $settings['token_hash'], 0, 8) : '');
/*
 * ABILITIES MUST BE OFF, and that is the point rather than a defect.
 *
 * Keelhob::on_deactivate() turns the master safety switch off, and step 2 of
 * this check deactivates the old plugin. So the value CANNOT survive a
 * handover, and an earlier version of this line asserted that it did -- which
 * would have made a correct safety feature look like data loss, or, worse,
 * pushed someone into "fixing" it by carrying a live write switch across a
 * plugin swap the owner has not finished yet.
 *
 * The owner re-ticks it once, deliberately, on the new plugin's settings page.
 */
kh_rebrand_check('abilities are off, because deactivating the old plugin turns them off', false, !empty($settings['abilities_enabled']));
kh_rebrand_check('the site id came across', (string) $before['options']['site_id'], (string) get_option('keelhob_site_id', ''));
kh_rebrand_check('the old settings option is gone', false, get_option($legacy . '_settings', null) !== null);

echo "\nThe marker: proof the rows MOVED and were not merely re-seeded\n";

/*
 * THE MOST IMPORTANT ASSERTION IN THIS FILE.
 *
 * Counts and bundled skill names matched perfectly on a run where nothing had
 * been adopted at all: activation created empty tables and re-seeded them with
 * the same 24 bundled skills and the same 8 default memories, while the owner's
 * real rows sat untouched in the old tables. Every content check passed and the
 * data had not moved an inch.
 *
 * A marker row with a random name cannot be re-created by seeding. Finding it
 * on the other side is the only proof that these are the same rows.
 */
$marker = (string) ($before['marker'] ?? '');
$found  = $marker === '' ? '' : (string) $wpdb->get_var($wpdb->prepare(
    'SELECT name FROM `' . $wpdb->prefix . 'keelhob_skills` WHERE name = %s',
    $marker
));
kh_rebrand_check('the planted marker row survived the handover', $marker, $found);

// Torn down in the same step that asserts on it, so a failed run never leaves a
// fake skill behind on the rig for the next person to puzzle over.
foreach (['keelhob_skills', 'claud' . 'press_skills'] as $kh_table) {
    $previous         = $wpdb->suppress_errors(true);
    $wpdb->last_error = '';
    $wpdb->query($wpdb->prepare('DELETE FROM `' . $wpdb->prefix . $kh_table . '` WHERE name = %s', $marker));
    $wpdb->suppress_errors($previous);
}


echo "\nThe plugin actually works after the handover\n";
kh_rebrand_check('the new version is the one loaded', '2.0.0', KEELHOB_VERSION);
kh_rebrand_check('adoption recorded that it ran, so it will not run again', true, get_option('keelhob_adopted_legacy', '') !== '');

$namespaces = array_map(static fn($ns): string => (string) $ns, (array) rest_get_server()->get_namespaces());
kh_rebrand_check('the MCP route is registered under the new namespace', true, in_array('keelhob/v1', $namespaces, true));
kh_rebrand_check('the old MCP namespace is gone', false, in_array($legacy . '/v1', $namespaces, true));

// Reading through the plugin's OWN classes, which is what an agent does.
/*
 * One row fewer than the fingerprint recorded, and that is arithmetic, not
 * loss: the marker was planted BEFORE the counts were taken and torn down a
 * few lines above, so it is counted there and gone here.
 */
kh_rebrand_check('Keelhob_Skills::all() can read the adopted rows', (int) $before['tables']['skills'] - 1, count((array) (new Keelhob_Skills())->all()));

$fails = kh_rebrand_tally(true, true);
printf("\n%s\n", $fails === 0
    ? "\033[32mHandover clean: nothing was lost.\033[0m"
    : "\033[31m{$fails} check(s) FAILED.\033[0m");
exit($fails === 0 ? 0 : 1);
