<?php
/**
 * Telegram chat front-end.
 *
 * The webhook is a public URL that can drive a whole WordPress site, so the
 * expensive bugs here are the access ones: an allowlist that fails open, a
 * signature check that passes on an empty header, a /risky unlock that survives
 * the switch being turned off. Those are all pure decisions, and they are what
 * this file pins down.
 *
 * The rest is message plumbing — command parsing, 4096-char splitting, history
 * trimming — where a mistake is visible but silent (a reply that never arrives,
 * a conversation that opens mid-answer).
 */

CP_Test::group('Telegram — command parsing');

$cases = [
    '/clear'              => ['clear', ''],
    '/clear@ClaudpressBot' => ['clear', ''],
    '/risky on'           => ['risky', 'on'],
    '/RISKY OFF'          => ['risky', 'OFF'],
    '  /status  '         => ['status', ''],
    'publish the draft'   => ['', 'publish the draft'],
    ''                    => ['', ''],
    // Not a command: a message that merely opens with a slash.
    '/2 orders came in?'  => ['', '/2 orders came in?'],
];
foreach ($cases as $input => [$cmd, $args]) {
    $parsed = Claudpress_Telegram::parse_command((string) $input);
    CP_Test::same($cmd, $parsed['cmd'], 'parse_command reads the command from ' . var_export($input, true));
    CP_Test::same($args, $parsed['args'], 'parse_command reads the arguments from ' . var_export($input, true));
}

CP_Test::group('Telegram — the allowlist fails closed');

CP_Test::ok(Claudpress_Telegram::is_allowed('123', ['123', '456']), 'a listed chat is allowed');
CP_Test::ok(!Claudpress_Telegram::is_allowed('789', ['123', '456']), 'an unlisted chat is refused');
CP_Test::ok(!Claudpress_Telegram::is_allowed('123', []), 'an empty allowlist refuses everyone, rather than opening the site up');
CP_Test::ok(!Claudpress_Telegram::is_allowed('', ['123']), 'a blank chat id is never allowed');
// Telegram ids are numeric but arrive as JSON numbers or strings depending on
// the client; a loose compare would let '0' or '' slip past a real id.
CP_Test::ok(!Claudpress_Telegram::is_allowed('0', ['123']), 'a falsy id does not match a real one');

CP_Test::same(['123', '456', '-100987'], Claudpress_Telegram::parse_chat_list('123, 456  -100987'), 'chat lists split on commas and whitespace');
CP_Test::same(['123'], Claudpress_Telegram::parse_chat_list(' 123 ,, 123 '), 'chat lists drop blanks and duplicates');
CP_Test::same([], Claudpress_Telegram::parse_chat_list('   '), 'a blank chat list parses to nothing');

CP_Test::group('Telegram — webhook signature');

$secret = 'a1b2c3d4e5';
CP_Test::ok(Claudpress_Rest::telegram_signed($secret, $secret), 'the right secret is accepted');
CP_Test::ok(!Claudpress_Rest::telegram_signed('wrong', $secret), 'a wrong secret is rejected');
CP_Test::ok(!Claudpress_Rest::telegram_signed(null, $secret), 'a missing header is rejected');
CP_Test::ok(!Claudpress_Rest::telegram_signed('', $secret), 'an empty header is rejected');
CP_Test::ok(!Claudpress_Rest::telegram_signed('', ''), 'an unconfigured secret rejects everything, including a blank header');
CP_Test::ok(!Claudpress_Rest::telegram_signed($secret . 'x', $secret), 'a prefix of the secret is not enough');

$generated = Claudpress_Telegram::generate_secret();
CP_Test::ok(strlen($generated) >= 32, 'the generated secret is long enough to be worth guessing at');
CP_Test::ok((bool) preg_match('/^[A-Za-z0-9_-]+$/', $generated), 'the generated secret uses only the characters Telegram accepts');
CP_Test::ok($generated !== Claudpress_Telegram::generate_secret(), 'each generated secret is unique');

CP_Test::group('Telegram — replies fit inside Telegram\'s message limit');

CP_Test::same([], Claudpress_Telegram::split_message('   '), 'a blank reply sends nothing at all');
CP_Test::same(['hello'], Claudpress_Telegram::split_message('hello'), 'a short reply stays one message');

$long   = str_repeat('word ', 3000); // ~15k chars
$chunks = Claudpress_Telegram::split_message($long);
CP_Test::ok(count($chunks) > 1, 'an over-long reply is split (' . count($chunks) . ' messages)');
$oversized = array_filter($chunks, static fn($c) => mb_strlen($c) > Claudpress_Telegram::MAX_MESSAGE);
CP_Test::same([], array_values($oversized), 'no chunk exceeds the Telegram limit');
CP_Test::same(
    preg_replace('/\s+/', '', $long),
    preg_replace('/\s+/', '', implode('', $chunks)),
    'splitting loses no content, only whitespace at the seams'
);

// Paragraph boundaries are preferred, so a split reads as a break not a cut.
$para  = str_repeat('a', 200) . "\n\n" . str_repeat('b', 200);
$split = Claudpress_Telegram::split_message($para, 260);
CP_Test::same(2, count($split), 'a paragraph boundary inside the window is used as the split point');
CP_Test::same(str_repeat('a', 200), $split[0], 'the first chunk ends exactly at the paragraph break');

// A wall of characters with nowhere good to break must still be split.
$wall = str_repeat('x', 500);
CP_Test::ok(count(Claudpress_Telegram::split_message($wall, 100)) === 5, 'an unbreakable wall of text is still chopped to size');

CP_Test::group('Telegram — conversation history');

$turns = [
    ['role' => 'user', 'text' => 'one'],
    ['role' => 'assistant', 'text' => 'two'],
    ['role' => 'user', 'text' => 'three'],
    ['role' => 'assistant', 'text' => 'four'],
];

CP_Test::same([], Claudpress_Telegram::trim_history($turns, 0), 'a memory of zero turns replays nothing');
CP_Test::same(4, count(Claudpress_Telegram::trim_history($turns, 10)), 'a memory larger than the conversation replays all of it');

// Keeping the last 3 would open on an assistant turn — a reply to a question the
// model can no longer see. It drops to the next user turn instead.
$trimmed = Claudpress_Telegram::trim_history($turns, 3);
CP_Test::same('user', $trimmed[0]['role'], 'replayed history always opens on a user turn');
CP_Test::same('three', $trimmed[0]['text'], 'trimming drops the orphaned assistant turn, not the user turn after it');

CP_Test::group('Telegram — history becomes provider messages');

$msgs = Claudpress_Agent_Runtime::history_messages(Claudpress_Provider_Anthropic::class, [
    ['role' => 'user', 'text' => 'how many orders?'],
    ['role' => 'assistant', 'text' => 'three today'],
    ['role' => 'assistant', 'text' => '   '],
]);
CP_Test::same(2, count($msgs), 'a blank turn is dropped rather than sent as an empty message');
CP_Test::same('user', $msgs[0]['role'], 'the user turn keeps its role');
CP_Test::same('assistant', $msgs[1]['role'], 'the assistant turn keeps its role');
CP_Test::same('three today', $msgs[1]['content'], 'the assistant turn carries its text');

// Gemini calls the assistant "model" and wraps text in parts; replaying history
// through the wrong shape is a 400 from the API, not a soft failure.
$gmsgs = Claudpress_Agent_Runtime::history_messages(Claudpress_Provider_Google::class, [
    ['role' => 'user', 'text' => 'hi'],
    ['role' => 'assistant', 'text' => 'hello'],
]);
CP_Test::same('model', $gmsgs[1]['role'], 'Gemini history uses the model role, not assistant');
CP_Test::same('hello', $gmsgs[1]['parts'][0]['text'], 'Gemini history wraps text in a parts entry');

foreach (Claudpress_Provider::providers() as $pid => $cls) {
    $m = $cls::assistant_text_message('replayed');
    CP_Test::ok(is_array($m) && !empty($m['role']), $pid . ' can replay an assistant turn as plain text');
}

CP_Test::group('Telegram — the risky unlock needs both switches');

cp_test_reset_options();

// Chat opted in, but the owner never armed the setting.
update_option(Claudpress_Telegram::OPTION_RISKY, ['555' => time() + 600]);
Claudpress::instance()->update(['telegram_allow_risky' => false]);
CP_Test::ok(!Claudpress_Telegram::risky_allowed('555'), 'a chat opt-in alone does not unlock destructive tools');

// Owner armed it, and the opt-in is live.
Claudpress::instance()->update(['telegram_allow_risky' => true]);
CP_Test::ok(Claudpress_Telegram::risky_allowed('555'), 'armed setting plus a live opt-in unlocks');
CP_Test::ok(!Claudpress_Telegram::risky_allowed('999'), 'the unlock is per chat, not global');

// A lapsed opt-in must not still be honoured.
update_option(Claudpress_Telegram::OPTION_RISKY, ['555' => time() - 1]);
CP_Test::ok(!Claudpress_Telegram::risky_allowed('555'), 'an expired opt-in has lapsed');

Claudpress_Telegram::set_risky('555', true);
CP_Test::ok(Claudpress_Telegram::risky_allowed('555'), '/risky on unlocks the chat');
Claudpress_Telegram::set_risky('555', false);
CP_Test::ok(!Claudpress_Telegram::risky_allowed('555'), '/risky off re-locks it immediately');

// set_risky also sweeps lapsed entries so the option cannot grow forever.
update_option(Claudpress_Telegram::OPTION_RISKY, ['old' => time() - 100, 'other' => time() + 100]);
Claudpress_Telegram::set_risky('new', true);
$map = (array) get_option(Claudpress_Telegram::OPTION_RISKY, []);
CP_Test::ok(!isset($map['old']), 'lapsed opt-ins are swept out of the store');
CP_Test::ok(isset($map['other']) && isset($map['new']), 'live opt-ins survive the sweep');

CP_Test::group('Telegram — the bot is off until it is configured');

cp_test_reset_options();
CP_Test::ok(!Claudpress_Telegram::enabled(), 'inbound chat is off on a fresh install');

Claudpress::instance()->update(['telegram_bot_enabled' => true]);
CP_Test::ok(!Claudpress_Telegram::enabled(), 'the switch alone does nothing without a bot token');

Claudpress::instance()->update(['notify_telegram_token' => '12345:abcdef']);
CP_Test::ok(Claudpress_Telegram::enabled(), 'switch plus token turns inbound chat on');

// An owner who set up outbound notifications is allowlisted for chat too,
// rather than having to type the same id into a second field.
Claudpress::instance()->update(['notify_telegram_chat' => '777', 'telegram_allowed_chats' => '']);
CP_Test::same(['777'], Claudpress_Telegram::allowed_chats(), 'the notification chat id is the allowlist fallback');

Claudpress::instance()->update(['telegram_allowed_chats' => '888,999']);
CP_Test::same(['888', '999'], Claudpress_Telegram::allowed_chats(), 'an explicit allowlist overrides the fallback');

Claudpress::instance()->update(['telegram_history_turns' => 500]);
CP_Test::same(60, Claudpress_Telegram::history_turns(), 'a runaway history setting is clamped');
Claudpress::instance()->update(['telegram_history_turns' => -5]);
CP_Test::same(0, Claudpress_Telegram::history_turns(), 'a negative history setting clamps to zero');

CP_Test::group('Telegram — replies are auditable');

$reply = Claudpress_Telegram::format_reply([
    'success'    => true,
    'summary'    => 'Published the winter tyres post.',
    'tool_calls' => [['tool' => 'list_posts'], ['tool' => 'update_post']],
    'usage'      => ['cost_usd' => 0.0123],
]);
CP_Test::contains('Published the winter tyres post.', $reply, 'the reply leads with what the agent said');
CP_Test::contains('2 tools', $reply, 'the reply reports how much work was done');
CP_Test::contains('$0.0123', $reply, 'the reply reports the estimated cost');

$failed = Claudpress_Telegram::format_reply(['success' => false, 'error' => 'No agent API key configured.']);
CP_Test::contains('No agent API key configured.', $failed, 'a failed run reports its actual error, not a generic apology');

$quiet = Claudpress_Telegram::format_reply(['success' => true, 'summary' => 'Done.', 'tool_calls' => [], 'usage' => []]);
CP_Test::same('Done.', $quiet, 'a free, tool-less reply carries no footer noise');

CP_Test::group('Telegram — the command menu is coherent');

$commands = Claudpress_Telegram::commands();
CP_Test::ok(isset($commands['clear']), 'clearing the conversation is an advertised command');
foreach ($commands as $name => $desc) {
    // Telegram rejects a command menu that breaks these rules outright.
    CP_Test::ok((bool) preg_match('/^[a-z0-9_]{1,32}$/', $name), 'command /' . $name . ' is a name Telegram accepts');
    CP_Test::ok($desc !== '' && strlen($desc) <= 256, 'command /' . $name . ' has a usable description');
}

// Every advertised command must actually be handled, or the menu lies.
cp_test_reset_options();
Claudpress::instance()->update(['telegram_allowed_chats' => '1']);
foreach (array_keys($commands) as $name) {
    if (in_array($name, ['status', 'tasks'], true)) {
        continue; // these read WordPress/WooCommerce state; live-smoke covers them
    }
    CP_Test::ok(
        Claudpress_Telegram::run_command('1', $name, '') !== null,
        'command /' . $name . ' has a handler'
    );
}
CP_Test::same(null, Claudpress_Telegram::run_command('1', 'notacommand', ''), 'an unknown command falls through to the agent');

cp_test_reset_options();
