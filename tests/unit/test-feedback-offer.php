<?php
/**
 * When the agent may offer, and what the report is allowed to say.
 *
 * The trigger is the part the first draft of this feature got wrong. It said
 * "at the end of the session"; MCP has no end-of-session event, so there was no
 * trigger at all and the whole delivery mechanism was imaginary. What replaced
 * it is two conditions, and both are load-bearing:
 *
 *   - something actually went wrong, or there is nothing to say
 *   - and it has been a week, or this is a nag
 *
 * A nag is what gets the setting switched off, which costs more reports than
 * the nagging ever gained. That is why the clock resets on a refusal too.
 */

CP_Test::group('Feedback offer — the clock');

$week = Claudpress_Feedback::OFFER_INTERVAL;
CP_Test::same(604800, $week, 'the interval is seven days');

$now = 1_700_000_000;
CP_Test::ok(Claudpress_Feedback::offer_due(0, $now), 'a site that has never been asked is due');
CP_Test::ok(!Claudpress_Feedback::offer_due($now, $now), 'asked just now is not due again');
CP_Test::ok(!Claudpress_Feedback::offer_due($now - 60, $now), 'asked a minute ago is not due');
CP_Test::ok(!Claudpress_Feedback::offer_due($now - ($week - 1), $now), 'one second short of a week is still not due');
CP_Test::ok(Claudpress_Feedback::offer_due($now - $week, $now), 'exactly a week later is due');
CP_Test::ok(Claudpress_Feedback::offer_due($now - ($week * 3), $now), 'and three weeks later, obviously');

CP_Test::group('Feedback offer — a refusal is recorded, not ignored');

// The clock is reset whether the answer was yes or no. Asking again next
// session because somebody said no is precisely the behaviour that makes people
// switch the whole feature off.
Claudpress_Feedback::mark_offered($now);
CP_Test::same($now, Claudpress_Feedback::last_offer(), 'the offer is remembered');
CP_Test::ok(!Claudpress_Feedback::offer_due(Claudpress_Feedback::last_offer(), $now + 3600), 'an hour after being asked, nothing is due');

CP_Test::group('Feedback report — five short lines, and honest about what it left out');

CP_Test::same(5, Claudpress_Feedback::MAX_EVENTS, 'at most five events — a forty-line approval prompt is not read, and an approval nobody reads is not consent');
CP_Test::same(200, Claudpress_Feedback::NOTE_MAX, 'each line is at most 200 characters');

$rows = [];
for ($i = 1; $i <= 9; $i++) {
    $rows[] = [
        'friction' => 'tool_failed',
        'tool'     => 'tool_' . $i,
        'skill'    => '',
        'attempts' => $i,                 // 9 is the worst, 1 the least important
        'note'     => 'problem number ' . $i,
        'sent'     => 0,
    ];
}
$report = Claudpress_Feedback::build_report($rows);

CP_Test::same(5, count($report['events']), 'nine problems become five events');
CP_Test::same(4, $report['omitted'], 'and the report says the other four exist');
CP_Test::same('tool_9', $report['events'][0]['tool'], 'the worst problem goes first');
CP_Test::same('tool_5', $report['events'][4]['tool'], 'and the five that travel are the five worst, not the five newest');

// Silently dropping them would make the report a lie about its own
// completeness, which is a strange thing for a feature built on consent.
$text = Claudpress_Tools_Feedback::draft_text($report);
CP_Test::contains('4 more not included', $text, 'the draft the user reads says how many were left out');

CP_Test::group('Feedback report — nothing already sent is sent twice');

$mixed = [
    ['friction' => 'docs_gap', 'tool' => 'a', 'skill' => '', 'attempts' => 9, 'note' => 'old', 'sent' => 1],
    ['friction' => 'docs_gap', 'tool' => 'b', 'skill' => '', 'attempts' => 1, 'note' => 'new', 'sent' => 0],
];
$r2 = Claudpress_Feedback::build_report($mixed);
CP_Test::same(1, count($r2['events']), 'a row already sent is not included again');
CP_Test::same('b', $r2['events'][0]['tool'], 'and the one that travels is the unsent one');

CP_Test::group('Feedback report — redaction runs again on the way out');

// A second pass, because the first one keeps the owner's database clean and
// this one still holds if a future code path ever writes a row another way.
$dirty = [[
    'friction' => 'wrong_visual_result',
    'tool'     => 'elementor_page',
    'skill'    => '',
    'attempts' => 1,
    'note'     => 'hero on https://acme-dental.com, key sk-ant-api03-AbCdEf0123456789xyz',
    'sent'     => 0,
]];
$out = Claudpress_Feedback::build_report($dirty)['events'][0]['note'];
CP_Test::ok(strpos($out, 'acme-dental.com') === false, 'a domain that somehow reached the table does not leave it');
CP_Test::ok(strpos($out, 'sk-ant-api03') === false, 'and neither does a key');

CP_Test::group('Feedback report — the draft shows every field, nothing folded away');

$draft = Claudpress_Tools_Feedback::draft_text(Claudpress_Feedback::build_report([[
    'friction' => 'wrong_visual_result',
    'tool'     => 'elementor_page',
    'skill'    => 'web-design-master',
    'attempts' => 3,
    'note'     => 'section IDs are not returned, so each edit re-reads the whole page',
    'sent'     => 0,
]]));

foreach ([
    'wrong_visual_result' => 'the friction type is shown',
    'elementor_page'      => 'the tool is shown',
    'web-design-master'   => 'the skill is shown',
    'x3'                  => 'the attempt count is shown',
    'section IDs'         => 'the note is shown in full',
    'No site address'     => 'the assurance is repeated at the point of asking, not only in the settings screen',
] as $needle => $why) {
    CP_Test::contains($needle, $draft, $why);
}

CP_Test::group('Feedback payload — the closed list of fields');

$payload = Claudpress_Feedback::payload(['events' => [], 'omitted' => 0]);
CP_Test::same(
    ['site_id', 'plugin_version', 'events', 'omitted'],
    array_keys($payload),
    'exactly four top-level fields leave the site — adding one here should fail this test, the way it does for the update check'
);

$event = Claudpress_Feedback::build_report([[
    'friction' => 'docs_gap', 'tool' => 'seo_meta', 'skill' => 'seo-optimization',
    'attempts' => 2, 'note' => 'nothing said which slot maps to body text', 'sent' => 0,
]])['events'][0];
CP_Test::same(
    ['friction', 'tool', 'skill', 'attempts', 'note'],
    array_keys($event),
    'and exactly five per event — no timestamps, no user, no URL, no IDs'
);
