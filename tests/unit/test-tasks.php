<?php
/** Scheduling: which tasks the hourly cron tick should fire. */

KH_Test::group('Tasks — interval elapsed');

$now  = 1767225600; // fixed clock so these never flake
$hour = 8;

$daily = static fn(array $over = []) => array_merge(['enabled' => 1, 'schedule' => 'daily', 'run_hour' => null, 'last_run_ts' => 0], $over);

KH_Test::ok(Keelhob_Tasks::is_due($daily(['last_run_ts' => 0]), $now, $hour), 'a task that has never run is due');
KH_Test::ok(!Keelhob_Tasks::is_due($daily(['last_run_ts' => $now - 3600]), $now, $hour), 'a daily task run an hour ago is not due');
KH_Test::ok(Keelhob_Tasks::is_due($daily(['last_run_ts' => $now - 86400]), $now, $hour), 'a daily task run 24h ago is due');
KH_Test::ok(Keelhob_Tasks::is_due($daily(['last_run_ts' => $now - 86300]), $now, $hour), 'the 2-minute tolerance absorbs cron drift');
KH_Test::ok(!Keelhob_Tasks::is_due($daily(['last_run_ts' => $now - 80000]), $now, $hour), 'but not an arbitrarily early tick');

KH_Test::ok(Keelhob_Tasks::is_due($daily(['schedule' => 'hourly', 'last_run_ts' => $now - 3600]), $now, $hour), 'hourly fires each hour');
KH_Test::ok(!Keelhob_Tasks::is_due($daily(['schedule' => 'weekly', 'last_run_ts' => $now - 86400]), $now, $hour), 'weekly does not fire after one day');
KH_Test::ok(Keelhob_Tasks::is_due($daily(['schedule' => 'weekly', 'last_run_ts' => $now - 604800]), $now, $hour), 'weekly fires after seven days');
KH_Test::ok(Keelhob_Tasks::is_due($daily(['schedule' => 'monthly', 'last_run_ts' => $now - 2592000]), $now, $hour), 'monthly fires after thirty days');
KH_Test::ok(!Keelhob_Tasks::is_due($daily(['schedule' => 'monthly', 'last_run_ts' => $now - 604800]), $now, $hour), 'monthly does not fire after a week');

KH_Test::group('Tasks — disabled tasks never run');

KH_Test::ok(!Keelhob_Tasks::is_due($daily(['enabled' => 0, 'last_run_ts' => 0]), $now, $hour), 'a disabled task is never due, however overdue');

KH_Test::group('Tasks — preferred hour of day');

$at7 = static fn(array $over = []) => array_merge(['enabled' => 1, 'schedule' => 'daily', 'run_hour' => 7, 'last_run_ts' => $now - 86400], $over);

KH_Test::ok(Keelhob_Tasks::is_due($at7(), $now, 7), 'a 7am digest fires at 7');
KH_Test::ok(!Keelhob_Tasks::is_due($at7(), $now, 9), 'a 7am digest does not fire at 9');
KH_Test::ok(!Keelhob_Tasks::is_due($at7(['last_run_ts' => $now - 3600]), $now, 7), 'the hour alone is not enough — the interval must also have elapsed');
KH_Test::ok(Keelhob_Tasks::is_due($at7(['last_run_ts' => $now - (86400 * 3)]), $now, 15), 'a task starved by missed cron ticks eventually fires at any hour');
KH_Test::ok(Keelhob_Tasks::is_due($at7(['run_hour' => 0]), $now, 0), 'midnight (hour 0) is a real hour, not "unset"');
KH_Test::ok(!Keelhob_Tasks::is_due($at7(['run_hour' => 0]), $now, 1), 'and hour 0 does not match hour 1');

KH_Test::ok(Keelhob_Tasks::is_due($at7(['schedule' => 'hourly', 'last_run_ts' => $now - 3600]), $now, 23), 'hourly tasks ignore the preferred hour');
KH_Test::ok(Keelhob_Tasks::is_due($at7(['schedule' => 'twicedaily', 'last_run_ts' => $now - 43200]), $now, 23), 'twice-daily tasks ignore the preferred hour');

KH_Test::group('Tasks — schedule table');

foreach (Keelhob_Tasks::HOUR_AWARE as $schedule) {
    KH_Test::ok(isset(Keelhob_Tasks::INTERVALS[$schedule]), 'hour-aware schedule "' . $schedule . '" is a real interval');
    KH_Test::ok(Keelhob_Tasks::INTERVALS[$schedule] >= 86400, 'hour-of-day only makes sense for schedules of a day or longer: ' . $schedule);
}
KH_Test::ok(!in_array('hourly', Keelhob_Tasks::HOUR_AWARE, true), 'hourly is not hour-aware');
