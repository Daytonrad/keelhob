<?php
/**
 * Scheduled tasks and the digest, against real WordPress — IN A REAL TIMEZONE.
 *
 * WHY THIS FILE EXISTS. An HOURLY digest was arriving every four to five hours
 * on a live site, for over a week, and nothing could see it:
 *
 *   - the unit suite stubs current_time() to plain UTC, so the bug cannot exist
 *     there;
 *   - the rig, CI, and every other test WordPress runs at UTC, where the bug is
 *     arithmetically invisible (the offset it depends on is zero).
 *
 * The cause is a timezone round-trip. record_run() stores current_time('mysql'),
 * the site's LOCAL wall clock. WordPress pins PHP's default timezone to UTC, so
 * a bare strtotime() on that string reads "14:00 in Dubai" as "14:00 UTC" — a
 * timestamp four hours in the FUTURE. is_due() computes elapsed = now - last =
 * -4h and refuses to run until it climbs back past the interval, so the task
 * fires every offset + interval instead of every interval.
 *
 * So this file DELIBERATELY MOVES THE SITE OFF UTC. That is the only condition
 * under which the failure is reachable, and it is the condition every real site
 * outside London is in.
 *
 * The timezone is restored in the same run that asserts on it, pass or fail.
 *
 * Run: wp eval-file tests/rig/checks-cron.php
 */

global $wpdb;

$GLOBALS['cp_rig_pass'] = 0;
$GLOBALS['cp_rig_fail'] = 0;
function check($label, $ok, $detail = '') {
    $ok ? $GLOBALS['cp_rig_pass']++ : $GLOBALS['cp_rig_fail']++;
    printf("%s  %s%s\n", $ok ? 'PASS' : 'FAIL', str_pad($label, 56), $detail);
}

wp_set_current_user(1);

$orig_tz     = get_option('timezone_string');
$orig_offset = get_option('gmt_offset');
$table       = Claudpress_Tasks::table();
$made        = 0;

try {
    // ---- the cron event itself -------------------------------------------
    Claudpress_Tasks::init();
    $next = wp_next_scheduled(Claudpress_Tasks::HOOK);
    check('the cron tick is scheduled', (bool) $next, $next ? gmdate('Y-m-d H:i', $next) . ' UTC' : 'NOT SCHEDULED');

    $schedules = wp_get_schedules();
    $ev = null;
    foreach ((array) _get_cron_array() as $ts => $hooks) {
        if (isset($hooks[Claudpress_Tasks::HOOK])) {
            $ev = reset($hooks[Claudpress_Tasks::HOOK]);
            break;
        }
    }
    check('  ...and it repeats hourly', ($ev['schedule'] ?? '') === 'hourly', (string) ($ev['schedule'] ?? 'none'));
    check('  ...with a 3600s interval', (int) ($ev['interval'] ?? 0) === 3600, (string) ($ev['interval'] ?? 0));

    // WP-Cron only runs when somebody visits. A site with no traffic has no
    // clock, and that is the OTHER reason a digest arrives late — worth
    // reporting separately from the bug above, because the fix is different.
    check('WP-Cron is not disabled on this site', !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON),
        defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'DISABLE_WP_CRON is true — cron needs a real system cron' : '');

    // ---- the timezone round-trip, which is the actual bug ----------------
    //
    // +04:00 because that is where the report came from, and because a whole
    // number of hours makes the arithmetic below readable.
    update_option('timezone_string', '');
    update_option('gmt_offset', 4);

    $offset = (int) (get_option('gmt_offset') * HOUR_IN_SECONDS);
    check('the site is off UTC for this test', $offset === 4 * 3600, sprintf('%+d hours', $offset / 3600));

    $stored = current_time('mysql');           // what record_run() writes
    $naive  = (int) strtotime($stored);        // what the code used to do
    $fixed  = Claudpress_Tasks::last_run_ts($stored);
    $now    = time();

    check('stored last_run is LOCAL time, not UTC', abs(($naive - $now) - $offset) <= 90,
        sprintf('naive reading is %+d min from now', (int) round(($naive - $now) / 60)));
    check('last_run_ts() reads it back as the real moment', abs($fixed - $now) <= 90,
        sprintf('%+d sec from now', $fixed - $now));

    // The symptom, stated as the number the owner actually experienced.
    $hourly = Claudpress_Tasks::INTERVALS['hourly'];
    $wait_before = 0;
    while (!Claudpress_Tasks::is_due(['enabled' => 1, 'schedule' => 'hourly', 'last_run_ts' => $naive], $now + $wait_before, 0)) {
        $wait_before += 600;
        if ($wait_before > 12 * 3600) { break; }
    }
    $wait_after = 0;
    while (!Claudpress_Tasks::is_due(['enabled' => 1, 'schedule' => 'hourly', 'last_run_ts' => $fixed], $now + $wait_after, 0)) {
        $wait_after += 600;
        if ($wait_after > 12 * 3600) { break; }
    }

    check('OLD behaviour: an hourly task waited offset + interval',
        $wait_before >= 4 * 3600,
        sprintf('%.1f hours — this is the reported 4-5 hour gap', $wait_before / 3600));
    check('NEW behaviour: an hourly task waits about an hour',
        $wait_after <= $hourly + 600,
        sprintf('%.1f hours', $wait_after / 3600));

    // ---- and through the real task row, not just the arithmetic ----------
    $saved = Claudpress_Tasks::save([
        'name'     => 'ZZ cron tz probe',
        'mode'     => 'digest',
        'schedule' => 'hourly',
        'notify'   => 0,          // never send mail from a test
        'enabled'  => 1,
    ]);
    $id = (int) ($saved['id'] ?? 0);
    $made = $id;
    check('a task can be saved', $id > 0);

    Claudpress_Tasks::run_task($id);
    $row = Claudpress_Tasks::get($id);
    check('running it records a last_run', !empty($row['last_run']), (string) ($row['last_run'] ?? ''));

    $ts = Claudpress_Tasks::last_run_ts($row['last_run']);
    check('  ...that converts to roughly now', abs($ts - time()) <= 120, sprintf('%+d sec', $ts - time()));

    $due_now = Claudpress_Tasks::is_due(
        ['enabled' => 1, 'schedule' => 'hourly', 'last_run_ts' => $ts],
        time(), (int) current_time('G')
    );
    check('  ...and it is NOT due again immediately', !$due_now);

    $due_later = Claudpress_Tasks::is_due(
        ['enabled' => 1, 'schedule' => 'hourly', 'last_run_ts' => $ts],
        time() + $hourly, (int) current_time('G')
    );
    check('  ...but IS due one hour later', $due_later, 'this is the whole fix');

    // ---- the digest itself still builds ----------------------------------
    $snap = Claudpress_Digest::snapshot();
    check('the digest snapshot builds', is_array($snap) && !empty($snap));
    $text = Claudpress_Digest::format_text($snap);
    check('  ...and formats to readable text', strlen(trim($text)) > 20, strlen($text) . ' chars');
} finally {
    // Same run that asserted on them, pass or fail.
    if ($made) {
        Claudpress_Tasks::delete($made);
    }
    update_option('timezone_string', $orig_tz);
    update_option('gmt_offset', $orig_offset);
    $left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE name LIKE 'ZZ %'");
    check('fixtures removed and timezone restored', $left === 0 && (string) get_option('timezone_string') === (string) $orig_tz,
        'tz now: "' . get_option('timezone_string') . '", offset ' . get_option('gmt_offset'));
}

printf("\n%d passed, %d failed\n", $GLOBALS['cp_rig_pass'], $GLOBALS['cp_rig_fail']);
if ($GLOBALS['cp_rig_fail'] > 0) {
    exit(1);
}
