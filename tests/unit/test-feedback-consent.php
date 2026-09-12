<?php
/**
 * Consent — the half that is not code.
 *
 * This feature changes a promise people chose this plugin for. PRIVACY.md says
 * there is no analytics and no error reporting anywhere; PostHog was rejected
 * for the plugin on that exact sentence, and this sends more sensitive data
 * than PostHog would have. So the bar is higher, not lower.
 *
 * The design answer is that a person reads the whole thing and says yes. That
 * answer only holds if three things are true, and this file checks all three:
 * the rule lives somewhere a site cannot switch off, the wording is on the
 * screen rather than in a document nobody opens, and "send" is a separate act
 * from "offer".
 */

CP_Test::group('Consent — the rule is in the protocol, where a site cannot switch it off');

$protocol = Claudpress_MCP_Server::operating_protocol();

foreach ([
    '15.'                => 'rule 15 exists',
    'feedback_status'    => 'it names the tool that drafts',
    'feedback_send'      => 'and the separate tool that sends',
    'WHOLE draft'        => 'it demands the whole draft, not a summary',
    'explicit yes'       => 'it requires an explicit yes before sending',
    'approved=false'     => 'it says how to record a refusal',
    'not raise it again' => 'a refusal ends it for the session',
    'never argue'        => 'and the agent may not push back on a no',
] as $needle => $why) {
    CP_Test::contains($needle, $protocol, $why);
}

// A skill can be switched off by the owner or rewritten by a well-meaning
// agent. Consent must not be a thing that can be edited away, which is the
// same argument that put rules 9 to 14 here.
CP_Test::ok(
    strpos($protocol, '14.') < strpos($protocol, '15.'),
    'rule 15 follows rule 14 rather than being inserted somewhere the numbering breaks'
);

CP_Test::group('Consent — the wording is on the screen, not only in PRIVACY.md');

$screen = file_get_contents(CLAUDPRESS_DIR . 'includes/class-admin-screens.php');

foreach ([
    'Help improve Claudpress'      => 'the checkbox has a plain label',
    'note it locally'              => 'it says what happens on the site',
    'approve it first'             => 'it says the user approves before anything leaves',
    'No site address'              => 'it names what is never sent',
    'no keys'                      => 'including keys',
    'nothing is recorded at all'   => 'and it states the off switch as a total stop, not as "recorded but not sent"',
] as $needle => $why) {
    CP_Test::contains($needle, $screen, $why);
}

// A promise the owner cannot check is a promise. The log has to be readable.
CP_Test::contains('Feedback log', $screen, 'the owner can see the log itself');
CP_Test::contains('clear_feedback', $screen, 'and wipe it');
CP_Test::contains('forget_feedback', $screen, 'and ask the server to delete what it already holds');

CP_Test::group('Consent — offering and sending are two different acts');

// The setting means "the agent may OFFER". If it ever comes to mean "the agent
// may SEND", this is telemetry and the answer is no.
$registry = new Claudpress_Tool_Registry();
Claudpress_Tools_Feedback::register($registry);

CP_Test::ok($registry->has('feedback_status'), 'feedback_status is registered');
CP_Test::ok($registry->has('feedback_send'), 'feedback_send is registered');

$defs = [];
foreach ($registry->list() as $d) {
    $defs[$d['name']] = $d;
}
CP_Test::ok(
    !empty($defs['feedback_status']['annotations']['readOnlyHint']),
    'the drafting tool is marked read-only, so a client that respects annotations knows it cannot send'
);
CP_Test::ok(
    empty($defs['feedback_send']['annotations']['readOnlyHint']),
    'and the sending tool is not'
);
CP_Test::ok(
    in_array('approved', $defs['feedback_send']['inputSchema']['required'] ?? [], true),
    'feedback_send cannot be called without saying whether the user agreed'
);

// Both are always available. A report the owner has to switch a capability on
// to give is a report nobody gives, and neither tool can change the site.
$always = Claudpress_Capabilities::always_on();
CP_Test::ok(in_array('feedback_status', $always, true), 'feedback_status is not behind a capability fence');
CP_Test::ok(in_array('feedback_send', $always, true), 'nor is feedback_send');

CP_Test::group('Consent — a refusal sends nothing and resets the clock');

Claudpress::instance()->update(['feedback_log' => true]);
$before = Claudpress_Feedback::last_offer();
$result = Claudpress_Tools_Feedback::send(['approved' => false]);

CP_Test::ok(empty($result['sent']), 'saying no sends nothing');
CP_Test::ok(Claudpress_Feedback::last_offer() >= $before, 'and the clock still moves, so they are not asked again next session');
CP_Test::contains('Nothing was sent', (string) $result['message'], 'the agent is told plainly that nothing went');

CP_Test::group('Consent — the setting is on by default, and that is a deliberate choice');

// Recorded as a decision rather than as an accident. It is defensible ONLY
// because of the two rules underneath it: nothing is recorded when it is off,
// and nothing is ever sent without a person reading the draft. Default-ON
// SENDING would not be defensible and is not what this is.
CP_Test::ok(Claudpress_Feedback::default_enabled(), 'the default is on');

$fresh = Claudpress::default_settings();
CP_Test::ok(array_key_exists('feedback_log', $fresh), 'a new install gets the setting');
CP_Test::ok(!empty($fresh['feedback_log']), 'and it starts on');
