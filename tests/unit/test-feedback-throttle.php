<?php
/**
 * A throttled send is a delay, not a loss.
 *
 * The endpoint allows 30 reports a day per outbound address. An agency or a
 * shared host has many sites behind one address, so the fortieth site on a busy
 * Tuesday gets a 429 — and that site did nothing wrong. Its report is still
 * good, its owner has already said yes, and the rows are still in the log.
 *
 * Everything here exists because the first version of this code treated 429
 * exactly like a network failure: it burned the site's one offer a week on a
 * request that never arrived, and told the user nothing useful. The biggest
 * callers would have been the quietest, which is the opposite of the point.
 */

CP_Test::group('Throttle — the plugin tells a 429 apart from a real failure');

$rows = [[
    'friction' => 'tool_failed', 'tool' => 'upload_media', 'skill' => '',
    'attempts' => 2, 'note' => 'timed out', 'sent' => 0,
]];

Claudpress::instance()->update(['feedback_log' => true]);

// A $wpdb that answers COUNT(*) with a real number. Without it count_unsent()
// returns 0, should_offer() says no for the wrong reason, and the assertions
// below would pass while proving nothing — which is exactly what happened on
// the first run of this file.
$cp_real_wpdb      = $GLOBALS['wpdb'];
$db                = new CP_Test_WPDB_Feedback();
$db->row_count     = 1;
$GLOBALS['wpdb']   = $db;

// Every other failure means the report is gone and there is nothing to do
// about it. 429 means the server was busy. Only the second is worth returning.
cp_test_http_queue(429, ['ok' => false]);
$r = Claudpress_Feedback::send(['events' => [['friction' => 'tool_failed', 'tool' => 'x', 'skill' => '', 'attempts' => 1, 'note' => '']]]);
CP_Test::ok(!$r['ok'], 'a 429 is not a success');
CP_Test::ok($r['throttled'], 'and it is reported as throttled');
CP_Test::same(429, $r['status'], 'the status is carried, so a future caller need not guess');

cp_test_http_queue(500, ['ok' => false]);
$r = Claudpress_Feedback::send(['events' => [['friction' => 'tool_failed', 'tool' => 'x', 'skill' => '', 'attempts' => 1, 'note' => '']]]);
CP_Test::ok(!$r['ok'] && !$r['throttled'], 'a 500 is a plain failure, not a throttle');

cp_test_http_queue(200, ['ok' => true]);
$r = Claudpress_Feedback::send(['events' => [['friction' => 'tool_failed', 'tool' => 'x', 'skill' => '', 'attempts' => 1, 'note' => '']]]);
CP_Test::ok($r['ok'] && !$r['throttled'], 'a 200 is a success');

$r = Claudpress_Feedback::send(['events' => []]);
CP_Test::ok(!$r['ok'] && !$r['throttled'], 'an empty report is not sent at all and is not a throttle');

CP_Test::group('Throttle — the weekly offer is NOT spent on a request that never arrived');

$now = 1_800_000_000;
$GLOBALS['cp_test_db_results'] = $rows;

// Start from a site that has never been asked.
update_option(Claudpress_Feedback::OPTION_LAST_OFFER, 0, false);
update_option(Claudpress_Feedback::OPTION_RETRY_AFTER, 0, false);

cp_test_http_queue(429, ['ok' => false]);
$out = Claudpress_Tools_Feedback::send(['approved' => true]);

CP_Test::ok(!empty($out['throttled']), 'the tool reports the throttle to the agent');
CP_Test::ok(empty($out['sent']), 'and says plainly that nothing was sent');
CP_Test::same(0, Claudpress_Feedback::last_offer(), 'the weekly clock was NOT started — the person was asked, but the server never heard it');
CP_Test::ok(Claudpress_Feedback::retry_after() > time(), 'a short hold was set instead');
CP_Test::ok(
    Claudpress_Feedback::retry_after() <= time() + Claudpress_Feedback::RETRY_AFTER_THROTTLED,
    'and the hold is a day, not a week'
);

// A hold means no offer today, for the right reason and not by accident.
CP_Test::ok(!Claudpress_Feedback::should_offer(), 'no second offer while the hold stands');
CP_Test::contains(
    'daily limit',
    Claudpress_Tools_Feedback::not_due_reason(),
    'and the agent is told WHY, so it does not read a busy server as a broken site'
);
CP_Test::contains(
    'not a problem with this site',
    Claudpress_Tools_Feedback::not_due_reason(),
    'in words that stop it alarming the owner'
);

// Tomorrow it is due again — a week has not had to pass.
CP_Test::ok(
    Claudpress_Feedback::offer_due(Claudpress_Feedback::last_offer(), $now),
    'the weekly condition is still satisfied, because the offer was never spent'
);
CP_Test::ok(
    Claudpress_Feedback::retry_after() < time() + Claudpress_Feedback::RETRY_AFTER_THROTTLED + 60,
    'so the report comes back tomorrow rather than next week'
);

CP_Test::group('Throttle — the three "not due" answers are told apart');

// An agent that cannot tell these apart either nags or goes silent for a week
// over a problem that cleared overnight.
update_option(Claudpress_Feedback::OPTION_RETRY_AFTER, 0, false);
$db->row_count = 0;
CP_Test::contains('Nothing has gone wrong', Claudpress_Tools_Feedback::not_due_reason(), 'an empty log says so — nothing to report beats every other reason');

$db->row_count = 1;
update_option(Claudpress_Feedback::OPTION_LAST_OFFER, time(), false);
CP_Test::contains('within the last 7 days', Claudpress_Tools_Feedback::not_due_reason(), 'a spent offer says so, and says a week rather than a day');
update_option(Claudpress_Feedback::OPTION_LAST_OFFER, 0, false);

CP_Test::group('Throttle — a real send does spend the offer');

$GLOBALS['cp_test_db_results'] = $rows;
update_option(Claudpress_Feedback::OPTION_LAST_OFFER, 0, false);
update_option(Claudpress_Feedback::OPTION_RETRY_AFTER, 0, false);

cp_test_http_queue(200, ['ok' => true]);
$out = Claudpress_Tools_Feedback::send(['approved' => true]);
CP_Test::ok(!empty($out['sent']), 'a 200 is reported as sent');
CP_Test::ok(Claudpress_Feedback::last_offer() > 0, 'and the weekly clock starts, so the next offer is a week away');

// And a plain failure also spends it: there is no retry and no queue, because a
// failed report is worth far less than a session derailed by an error about a
// report. Only 429 is worth coming back from, because only 429 says "later".
update_option(Claudpress_Feedback::OPTION_LAST_OFFER, 0, false);
cp_test_http_queue(500, ['ok' => false]);
$out = Claudpress_Tools_Feedback::send(['approved' => true]);
CP_Test::ok(empty($out['sent']) && empty($out['throttled']), 'a 500 is reported as a plain failure');
CP_Test::ok(Claudpress_Feedback::last_offer() > 0, 'and it does spend the offer — no retry, no queue');

$GLOBALS['cp_test_http'] = [];
$GLOBALS['wpdb'] = $cp_real_wpdb;
$GLOBALS['cp_test_db_results'] = [];
update_option(Claudpress_Feedback::OPTION_LAST_OFFER, 0, false);
update_option(Claudpress_Feedback::OPTION_RETRY_AFTER, 0, false);
