<?php
/**
 * Scheduled tasks on a site that is not in London.
 *
 * THE BUG THIS PINS, reported from a live site in Asia/Dubai (UTC+4): a digest
 * set to HOURLY arrived every four to five hours, for over a week.
 *
 * record_run() stores current_time('mysql') — the site's LOCAL wall clock.
 * WordPress pins PHP's default timezone to UTC, so reading that string back
 * with a bare strtotime() interprets "14:00 in Dubai" as "14:00 UTC": a
 * timestamp four hours in the FUTURE. is_due() then computed
 * elapsed = now - last = -4h and refused to run until it climbed back past the
 * interval, so the task fired every offset + interval.
 *
 * It was invisible everywhere it could have been caught. The unit stub returned
 * plain UTC, and the rig, CI and every other test WordPress runs at UTC — where
 * the offset the bug depends on is zero. The only way to see it is to put the
 * site somewhere real, which is what these tests do.
 */

CP_Test::group('Tasks — the local-time round trip (UTC+4)');

$GLOBALS['cp_test_gmt_offset'] = 4;   // Asia/Dubai, where it was reported

$now    = time();
$stored = current_time('mysql');                 // exactly what record_run() writes
$naive  = (int) strtotime($stored);              // exactly what the code used to do
$fixed  = Claudpress_Tasks::last_run_ts($stored);

// The stored string really is four hours ahead of UTC. If this fails the stub
// is not modelling a timezone and everything below is meaningless.
CP_Test::ok(abs(($naive - $now) - 4 * 3600) <= 5, 'a naive read puts last_run 4 hours in the FUTURE — the bug');
CP_Test::ok(abs($fixed - $now) <= 5, 'last_run_ts() reads it back as the real moment');

CP_Test::same(0, Claudpress_Tasks::last_run_ts(null), 'a task that never ran reads as 0, not as "now minus the offset"');
CP_Test::same(0, Claudpress_Tasks::last_run_ts(''), 'and so does an empty string');

CP_Test::group('Tasks — an hourly task fires hourly, not offset + hourly');

$hourly = Claudpress_Tasks::INTERVALS['hourly'];
$task   = ['enabled' => 1, 'schedule' => 'hourly'];

CP_Test::ok(
    !Claudpress_Tasks::is_due($task + ['last_run_ts' => $fixed], $now, 0),
    'a task that just ran is not due again immediately'
);
CP_Test::ok(
    Claudpress_Tasks::is_due($task + ['last_run_ts' => $fixed], $now + $hourly, 0),
    'and IS due one hour later — the whole fix'
);

// The old reading, stated as the number the owner actually lived with.
CP_Test::ok(
    !Claudpress_Tasks::is_due($task + ['last_run_ts' => $naive], $now + $hourly, 0),
    'with the naive reading it was still not due after an hour'
);
CP_Test::ok(
    !Claudpress_Tasks::is_due($task + ['last_run_ts' => $naive], $now + 4 * 3600, 0),
    '...nor after four hours'
);
CP_Test::ok(
    Claudpress_Tasks::is_due($task + ['last_run_ts' => $naive], $now + 5 * 3600, 0),
    '...it took five, which is exactly the "4 to 5 hours" that was reported'
);

CP_Test::group('Tasks — the fix does not disturb a UTC site');

// The offset is zero on a UTC site, so the two readings must agree exactly.
// If they ever diverge here, the conversion has been applied twice.
$GLOBALS['cp_test_gmt_offset'] = 0;
$utc_stored = current_time('mysql');
CP_Test::same((int) strtotime($utc_stored), Claudpress_Tasks::last_run_ts($utc_stored), 'on UTC the conversion is a no-op');

CP_Test::group('Tasks — a site WEST of UTC is not broken the other way');

// The mirror image, and the one a fix written only for +offsets gets wrong:
// last_run reads as the PAST, so the task would fire EARLY and send duplicates.
$GLOBALS['cp_test_gmt_offset'] = -5;   // New York
$west_now    = time();
$west_stored = current_time('mysql');
$west_fixed  = Claudpress_Tasks::last_run_ts($west_stored);

CP_Test::ok(abs($west_fixed - $west_now) <= 5, 'a negative offset also reads back as the real moment');
CP_Test::ok(
    !Claudpress_Tasks::is_due(['enabled' => 1, 'schedule' => 'hourly', 'last_run_ts' => $west_fixed], $west_now, 0),
    'so it does not fire early and send duplicates'
);

$GLOBALS['cp_test_gmt_offset'] = 0;   // leave the suite where it was found
