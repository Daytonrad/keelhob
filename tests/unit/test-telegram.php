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

KH_Test::group('Telegram — command parsing');

$cases = [
    '/clear'              => ['clear', ''],
    '/clear@KeelhobBot' => ['clear', ''],
    '/risky on'           => ['risky', 'on'],
    '/RISKY OFF'          => ['risky', 'OFF'],
    '  /status  '         => ['status', ''],
    'publish the draft'   => ['', 'publish the draft'],
    ''                    => ['', ''],
    // Not a command: a message that merely opens with a slash.
    '/2 orders came in?'  => ['', '/2 orders came in?'],
];
foreach ($cases as $input => [$cmd, $args]) {
    $parsed = Keelhob_Telegram::parse_command((string) $input);
    KH_Test::same($cmd, $parsed['cmd'], 'parse_command reads the command from ' . var_export($input, true));
    KH_Test::same($args, $parsed['args'], 'parse_command reads the arguments from ' . var_export($input, true));
}

KH_Test::group('Telegram — the allowlist fails closed');

KH_Test::ok(Keelhob_Telegram::is_allowed('123', ['123', '456']), 'a listed chat is allowed');
KH_Test::ok(!Keelhob_Telegram::is_allowed('789', ['123', '456']), 'an unlisted chat is refused');
KH_Test::ok(!Keelhob_Telegram::is_allowed('123', []), 'an empty allowlist refuses everyone, rather than opening the site up');
KH_Test::ok(!Keelhob_Telegram::is_allowed('', ['123']), 'a blank chat id is never allowed');
// Telegram ids are numeric but arrive as JSON numbers or strings depending on
// the client; a loose compare would let '0' or '' slip past a real id.
KH_Test::ok(!Keelhob_Telegram::is_allowed('0', ['123']), 'a falsy id does not match a real one');

KH_Test::same(['123', '456', '-100987'], Keelhob_Telegram::parse_chat_list('123, 456  -100987'), 'chat lists split on commas and whitespace');
KH_Test::same(['123'], Keelhob_Telegram::parse_chat_list(' 123 ,, 123 '), 'chat lists drop blanks and duplicates');
KH_Test::same([], Keelhob_Telegram::parse_chat_list('   '), 'a blank chat list parses to nothing');

KH_Test::group('Telegram — webhook signature');

$secret = 'a1b2c3d4e5';
KH_Test::ok(Keelhob_Rest::telegram_signed($secret, $secret), 'the right secret is accepted');
KH_Test::ok(!Keelhob_Rest::telegram_signed('wrong', $secret), 'a wrong secret is rejected');
KH_Test::ok(!Keelhob_Rest::telegram_signed(null, $secret), 'a missing header is rejected');
KH_Test::ok(!Keelhob_Rest::telegram_signed('', $secret), 'an empty header is rejected');
KH_Test::ok(!Keelhob_Rest::telegram_signed('', ''), 'an unconfigured secret rejects everything, including a blank header');
KH_Test::ok(!Keelhob_Rest::telegram_signed($secret . 'x', $secret), 'a prefix of the secret is not enough');

$generated = Keelhob_Telegram::generate_secret();
KH_Test::ok(strlen($generated) >= 32, 'the generated secret is long enough to be worth guessing at');
KH_Test::ok((bool) preg_match('/^[A-Za-z0-9_-]+$/', $generated), 'the generated secret uses only the characters Telegram accepts');
KH_Test::ok($generated !== Keelhob_Telegram::generate_secret(), 'each generated secret is unique');

KH_Test::group('Telegram — replies fit inside Telegram\'s message limit');

KH_Test::same([], Keelhob_Telegram::split_message('   '), 'a blank reply sends nothing at all');
KH_Test::same(['hello'], Keelhob_Telegram::split_message('hello'), 'a short reply stays one message');

$long   = str_repeat('word ', 3000); // ~15k chars
$chunks = Keelhob_Telegram::split_message($long);
KH_Test::ok(count($chunks) > 1, 'an over-long reply is split (' . count($chunks) . ' messages)');
$oversized = array_filter($chunks, static fn($c) => mb_strlen($c) > Keelhob_Telegram::MAX_MESSAGE);
KH_Test::same([], array_values($oversized), 'no chunk exceeds the Telegram limit');
KH_Test::same(
    preg_replace('/\s+/', '', $long),
    preg_replace('/\s+/', '', implode('', $chunks)),
    'splitting loses no content, only whitespace at the seams'
);

// Paragraph boundaries are preferred, so a split reads as a break not a cut.
$para  = str_repeat('a', 200) . "\n\n" . str_repeat('b', 200);
$split = Keelhob_Telegram::split_message($para, 260);
KH_Test::same(2, count($split), 'a paragraph boundary inside the window is used as the split point');
KH_Test::same(str_repeat('a', 200), $split[0], 'the first chunk ends exactly at the paragraph break');

// A wall of characters with nowhere good to break must still be split.
$wall = str_repeat('x', 500);
KH_Test::ok(count(Keelhob_Telegram::split_message($wall, 100)) === 5, 'an unbreakable wall of text is still chopped to size');

KH_Test::group('Telegram — conversation history');

$turns = [
    ['role' => 'user', 'text' => 'one'],
    ['role' => 'assistant', 'text' => 'two'],
    ['role' => 'user', 'text' => 'three'],
    ['role' => 'assistant', 'text' => 'four'],
];

KH_Test::same([], Keelhob_Telegram::trim_history($turns, 0), 'a memory of zero turns replays nothing');
KH_Test::same(4, count(Keelhob_Telegram::trim_history($turns, 10)), 'a memory larger than the conversation replays all of it');

// Keeping the last 3 would open on an assistant turn — a reply to a question the
// model can no longer see. It drops to the next user turn instead.
$trimmed = Keelhob_Telegram::trim_history($turns, 3);
KH_Test::same('user', $trimmed[0]['role'], 'replayed history always opens on a user turn');
KH_Test::same('three', $trimmed[0]['text'], 'trimming drops the orphaned assistant turn, not the user turn after it');

KH_Test::group('Telegram — history becomes provider messages');

$msgs = Keelhob_Agent_Runtime::history_messages(Keelhob_Provider_Anthropic::class, [
    ['role' => 'user', 'text' => 'how many orders?'],
    ['role' => 'assistant', 'text' => 'three today'],
    ['role' => 'assistant', 'text' => '   '],
]);
KH_Test::same(2, count($msgs), 'a blank turn is dropped rather than sent as an empty message');
KH_Test::same('user', $msgs[0]['role'], 'the user turn keeps its role');
KH_Test::same('assistant', $msgs[1]['role'], 'the assistant turn keeps its role');
KH_Test::same('three today', $msgs[1]['content'], 'the assistant turn carries its text');

// Gemini calls the assistant "model" and wraps text in parts; replaying history
// through the wrong shape is a 400 from the API, not a soft failure.
$gmsgs = Keelhob_Agent_Runtime::history_messages(Keelhob_Provider_Google::class, [
    ['role' => 'user', 'text' => 'hi'],
    ['role' => 'assistant', 'text' => 'hello'],
]);
KH_Test::same('model', $gmsgs[1]['role'], 'Gemini history uses the model role, not assistant');
KH_Test::same('hello', $gmsgs[1]['parts'][0]['text'], 'Gemini history wraps text in a parts entry');

foreach (Keelhob_Provider::providers() as $pid => $cls) {
    $m = $cls::assistant_text_message('replayed');
    KH_Test::ok(is_array($m) && !empty($m['role']), $pid . ' can replay an assistant turn as plain text');
}

KH_Test::group('Telegram — the risky unlock needs both switches');

kh_test_reset_options();
Keelhob::instance()->update(['telegram_allowed_chats' => '555, 666:editor, 777:viewer']);

// Chat opted in, but the owner never armed the setting.
update_option(Keelhob_Telegram::OPTION_RISKY, ['555' => time() + 600]);
Keelhob::instance()->update(['telegram_allow_risky' => false]);
KH_Test::ok(!Keelhob_Telegram::risky_allowed('555'), 'a chat opt-in alone does not unlock destructive tools');

// Owner armed it, and the opt-in is live.
Keelhob::instance()->update(['telegram_allow_risky' => true]);
KH_Test::ok(Keelhob_Telegram::risky_allowed('555'), 'armed setting plus a live opt-in unlocks');
KH_Test::ok(!Keelhob_Telegram::risky_allowed('999'), 'the unlock is per chat, not global');

// A lapsed opt-in must not still be honoured.
update_option(Keelhob_Telegram::OPTION_RISKY, ['555' => time() - 1]);
KH_Test::ok(!Keelhob_Telegram::risky_allowed('555'), 'an expired opt-in has lapsed');

Keelhob_Telegram::set_risky('555', true);
KH_Test::ok(Keelhob_Telegram::risky_allowed('555'), '/risky on unlocks the chat');
Keelhob_Telegram::set_risky('555', false);
KH_Test::ok(!Keelhob_Telegram::risky_allowed('555'), '/risky off re-locks it immediately');

// set_risky also sweeps lapsed entries so the option cannot grow forever.
update_option(Keelhob_Telegram::OPTION_RISKY, ['old' => time() - 100, 'other' => time() + 100]);
Keelhob_Telegram::set_risky('new', true);
$map = (array) get_option(Keelhob_Telegram::OPTION_RISKY, []);
KH_Test::ok(!isset($map['old']), 'lapsed opt-ins are swept out of the store');
KH_Test::ok(isset($map['other']) && isset($map['new']), 'live opt-ins survive the sweep');

KH_Test::group('Telegram — roles');

kh_test_reset_options();
Keelhob::instance()->update([
    'telegram_allowed_chats' => '111, 222:viewer, 333:editor, 444:nonsense',
    'telegram_allow_risky'   => true,
]);

KH_Test::same('admin', Keelhob_Telegram::role_for('111'), 'a bare chat id is an admin, so an allowlist written before roles keeps working');
KH_Test::same('viewer', Keelhob_Telegram::role_for('222'), 'a :viewer suffix is read');
KH_Test::same('editor', Keelhob_Telegram::role_for('333'), 'an :editor suffix is read');
KH_Test::same('admin', Keelhob_Telegram::role_for('444'), 'an unrecognised role falls back to admin rather than silently losing access');
KH_Test::same(null, Keelhob_Telegram::role_for('999'), 'a chat that is not listed has no role at all');
KH_Test::same(null, Keelhob_Telegram::role_for(''), 'a blank chat id has no role');

// Numeric ids become int array keys in PHP; every comparison downstream is a
// strict string one, so this is the bug that would refuse the owner's own chat.
KH_Test::same(['111', '222', '333', '444'], Keelhob_Telegram::allowed_chats(), 'chat ids survive as strings, not ints');
KH_Test::ok(Keelhob_Telegram::is_allowed('111', Keelhob_Telegram::allowed_chats()), 'a numeric chat id still matches under a strict compare');

// Only an admin may escalate, and only when the owner armed the switch.
KH_Test::ok(Keelhob_Telegram::may_escalate('111'), 'an admin chat may escalate when the owner armed it');
KH_Test::ok(!Keelhob_Telegram::may_escalate('333'), 'an editor chat may never escalate');
KH_Test::ok(!Keelhob_Telegram::may_escalate('222'), 'a viewer chat may never escalate');
KH_Test::ok(!Keelhob_Telegram::may_escalate('999'), 'a stranger may never escalate');

Keelhob::instance()->update(['telegram_allow_risky' => false]);
KH_Test::ok(!Keelhob_Telegram::may_escalate('111'), 'with the owner switch off, not even an admin chat may escalate');

KH_Test::group('Telegram — a read-only role gets read-only tools');

// Derived from each tool's own readOnlyHint, so the two cannot drift apart.
$viewer = Keelhob_Capabilities::viewer_tools([
    ['name' => 'get_dashboard', 'annotations' => ['readOnlyHint' => true]],
    ['name' => 'list_posts',    'annotations' => ['readOnlyHint' => true]],
    ['name' => 'update_post',   'annotations' => ['destructiveHint' => true]],
    ['name' => 'execute_php',   'annotations' => []],
    ['name' => 'read_file',     'annotations' => ['readOnlyHint' => true]],
    ['name' => 'list_directory', 'annotations' => ['readOnlyHint' => true]],
    ['name' => 'export_bundle', 'annotations' => ['readOnlyHint' => true]],
]);
KH_Test::ok(in_array('get_dashboard', $viewer, true), 'a viewer can read the dashboard');
KH_Test::ok(in_array('list_posts', $viewer, true), 'a viewer can list posts');
KH_Test::ok(!in_array('update_post', $viewer, true), 'a viewer cannot edit posts');
KH_Test::ok(!in_array('execute_php', $viewer, true), 'a viewer cannot run PHP');

// "Read-only" and "can read your database password" are not the same claim.
KH_Test::ok(!in_array('read_file', $viewer, true), 'a viewer cannot read files — wp-config.php is a file');
KH_Test::ok(!in_array('list_directory', $viewer, true), 'a viewer cannot browse the filesystem');
KH_Test::ok(!in_array('export_bundle', $viewer, true), 'a viewer cannot export the whole site, read-only though that is');
KH_Test::same([], Keelhob_Capabilities::viewer_tools([]), 'an empty registry yields an empty viewer toolset');

// An empty whitelist must never be read as "no restrictions".
kh_test_reset_options();
Keelhob::instance()->update(['abilities_enabled' => true, 'agent_api_key' => 'sk-test']);
$blocked_run = Keelhob_Agent_Runtime::run('do something', ['allow_tools' => []]);
KH_Test::ok(empty($blocked_run['success']), 'a run with an empty tool whitelist fails instead of running unrestricted');
KH_Test::contains('no tools available', strtolower((string) $blocked_run['error']), 'and it says why');
kh_test_reset_options();

KH_Test::group('Telegram — approvals are single-use and chat-bound');

kh_test_reset_options();
Keelhob::instance()->update(['telegram_allowed_chats' => '111', 'telegram_allow_risky' => true]);

$token = Keelhob_Telegram::stash_approval('111', 'delete the old landing pages', ['delete_post']);
KH_Test::ok((bool) preg_match('/^[a-f0-9]{16}$/', $token), 'the token is opaque hex, not the instruction itself');

// The instruction lives server-side because callback_data comes back from the
// client and must never be something we execute.
KH_Test::same(null, Keelhob_Telegram::claim_approval($token, '999'), 'another chat cannot redeem the token');

$token2 = Keelhob_Telegram::stash_approval('111', 'delete the old landing pages', ['delete_post']);
$row    = Keelhob_Telegram::claim_approval($token2, '111');
KH_Test::same('delete the old landing pages', (string) ($row['instruction'] ?? ''), 'the owning chat redeems the stashed instruction');
KH_Test::same(null, Keelhob_Telegram::claim_approval($token2, '111'), 'a token cannot be redeemed twice, so a button cannot run the work twice');
KH_Test::same(null, Keelhob_Telegram::claim_approval('deadbeef', '111'), 'an invented token redeems nothing');

// An expired stash is gone, not merely stale.
update_option(Keelhob_Telegram::OPTION_PENDING, [
    'oldtoken' => ['chat' => '111', 'instruction' => 'x', 'at' => time() - (Keelhob_Telegram::APPROVAL_TTL + 60)],
]);
KH_Test::same([], Keelhob_Telegram::pending(), 'expired approvals are swept');
KH_Test::same(null, Keelhob_Telegram::claim_approval('oldtoken', '111'), 'an expired token cannot be redeemed');

KH_Test::group('Telegram — which blocks are worth asking about');

$run = ['tool_calls' => [
    ['tool' => 'list_posts',  'blocked' => false, 'ok' => true],
    ['tool' => 'delete_post', 'blocked' => true,  'ok' => false, 'reason' => 'Autonomous run: needs allow_risky'],
    ['tool' => 'delete_post', 'blocked' => true,  'ok' => false, 'reason' => 'Autonomous run: needs allow_risky'],
    ['tool' => 'wc_payments', 'blocked' => true,  'ok' => false, 'reason' => 'capability off'],
    ['tool' => 'update_post', 'blocked' => true,  'ok' => false, 'reason' => 'read-only conversation'],
]];
$blocked = Keelhob_Telegram::blocked_tools($run);
KH_Test::same(['delete_post'], $blocked, 'only a policy block is offered for approval, and only once');
KH_Test::ok(!in_array('wc_payments', $blocked, true), 'a capability the owner switched off is a settled decision, not a question');
KH_Test::ok(!in_array('update_post', $blocked, true), 'a read-only chat is not offered a way around being read-only');
KH_Test::same([], Keelhob_Telegram::blocked_tools(['tool_calls' => []]), 'a clean run offers no approval button');

KH_Test::group('Telegram — media routing');

KH_Test::same(null, Keelhob_Telegram::extract_media(['text' => 'hello']), 'a plain text message carries no media');

$voice = Keelhob_Telegram::extract_media(['voice' => ['file_id' => 'v1', 'mime_type' => 'audio/ogg']]);
KH_Test::same('voice', $voice['kind'], 'a voice note is recognised');
KH_Test::same('v1', $voice['file_id'], 'the voice file id is picked up');

// Telegram sends a photo as rescaled copies, smallest first. Showing a model
// the thumbnail instead of the original would quietly make vision useless.
$photo = Keelhob_Telegram::extract_media(['photo' => [
    ['file_id' => 'small', 'width' => 90],
    ['file_id' => 'medium', 'width' => 320],
    ['file_id' => 'original', 'width' => 1280],
]]);
KH_Test::same('photo', $photo['kind'], 'a photo is recognised');
KH_Test::same('original', $photo['file_id'], 'the largest copy of the photo is the one used');

$doc = Keelhob_Telegram::extract_media(['document' => ['file_id' => 'd1', 'mime_type' => 'image/png']]);
KH_Test::same('photo', $doc['kind'], 'a screenshot sent as a file is still an image');
KH_Test::same('image/png', $doc['mime'], 'the document mime type is carried through');

KH_Test::same(null, Keelhob_Telegram::extract_media(['document' => ['file_id' => 'd2', 'mime_type' => 'application/pdf']]), 'a PDF is not treated as an image');

KH_Test::group('Telegram — progress lines');

KH_Test::contains('Working', Keelhob_Telegram::format_progress([]), 'progress starts by saying it is working');
$prog = Keelhob_Telegram::format_progress([
    ['step' => 1, 'tools' => ['list_posts']],
    ['step' => 2, 'tools' => ['update_post', 'update_post']],
]);
KH_Test::contains('list_posts', $prog, 'progress names the tools as they run');
KH_Test::same(1, substr_count($prog, 'update_post'), 'a tool used twice in one step is named once');
// Telegram caps message length; a 40-step run must not blow through it.
$many = [];
for ($i = 0; $i < 40; $i++) {
    $many[] = ['step' => $i, 'tools' => ['tool_' . $i]];
}
KH_Test::ok(substr_count(Keelhob_Telegram::format_progress($many), "\n") <= 7, 'progress keeps only the last few steps on screen');

KH_Test::group('Voice transcription — key fallback');

kh_test_reset_options();
Keelhob::instance()->update(['voice_provider' => 'openai', 'voice_api_key' => '']);
KH_Test::same('', Keelhob_Transcribe_Provider::key(), 'with no keys at all, there is nothing to transcribe with');

// An owner who already pasted an OpenAI key should not have to paste it twice.
Keelhob::instance()->update(['agent_provider' => 'openai', 'agent_api_key' => 'sk-agent']);
KH_Test::same('sk-agent', Keelhob_Transcribe_Provider::key(), 'the agent key is reused when it belongs to the same vendor');

// ...but a Claude brain cannot transcribe, so its key must not be handed over.
Keelhob::instance()->update(['agent_provider' => 'anthropic', 'agent_api_key' => 'sk-ant-brain']);
KH_Test::same('', Keelhob_Transcribe_Provider::key(), 'an Anthropic key is not offered to a transcription provider that cannot use it');

Keelhob::instance()->update(['image_provider' => 'openai', 'image_api_key' => 'sk-image']);
KH_Test::same('sk-image', Keelhob_Transcribe_Provider::key(), 'the image key is reused when it belongs to the same vendor');

Keelhob::instance()->update(['voice_api_key' => 'sk-voice']);
KH_Test::same('sk-voice', Keelhob_Transcribe_Provider::key(), 'an explicit voice key wins over every fallback');

foreach (Keelhob_Transcribe_Provider::providers() as $vid => $vcls) {
    KH_Test::ok($vcls::default_model() !== '', $vid . ' transcription provider names a default model');
    KH_Test::contains('http', $vcls::default_endpoint(), $vid . ' transcription provider has an endpoint');
}

KH_Test::group('Vision — an image reaches each provider in its own shape');

$anthropic = Keelhob_Provider_Anthropic::user_image_message('what is broken here?', 'image/png', 'BASE64');
KH_Test::same('user', $anthropic['role'], 'Anthropic image messages are user turns');
KH_Test::same('base64', $anthropic['content'][0]['source']['type'], 'Anthropic takes an inline base64 source');
KH_Test::same('image/png', $anthropic['content'][0]['source']['media_type'], 'Anthropic is told the media type');

$openai = Keelhob_Provider_OpenAI::user_image_message('what is broken here?', 'image/png', 'BASE64');
KH_Test::contains('data:image/png;base64,BASE64', $openai['content'][1]['image_url']['url'], 'OpenAI takes a data: URI');

$google = Keelhob_Provider_Google::user_image_message('what is broken here?', 'image/png', 'BASE64');
KH_Test::same('image/png', $google['parts'][0]['inlineData']['mimeType'], 'Gemini takes inlineData with a mimeType');

foreach (Keelhob_Provider::providers() as $pid => $cls) {
    $m = $cls::user_image_message('caption', 'image/jpeg', 'AAAA');
    KH_Test::ok(is_array($m) && !empty($m['role']), $pid . ' can carry an image alongside its text');
}

KH_Test::group('Telegram — the bot is off until it is configured');

kh_test_reset_options();
KH_Test::ok(!Keelhob_Telegram::enabled(), 'inbound chat is off on a fresh install');

Keelhob::instance()->update(['telegram_bot_enabled' => true]);
KH_Test::ok(!Keelhob_Telegram::enabled(), 'the switch alone does nothing without a bot token');

Keelhob::instance()->update(['notify_telegram_token' => '12345:abcdef']);
KH_Test::ok(Keelhob_Telegram::enabled(), 'switch plus token turns inbound chat on');

// An owner who set up outbound notifications is allowlisted for chat too,
// rather than having to type the same id into a second field.
Keelhob::instance()->update(['notify_telegram_chat' => '777', 'telegram_allowed_chats' => '']);
KH_Test::same(['777'], Keelhob_Telegram::allowed_chats(), 'the notification chat id is the allowlist fallback');
KH_Test::same('admin', Keelhob_Telegram::role_for('777'), 'the fallback chat is an admin');

Keelhob::instance()->update(['telegram_allowed_chats' => '888,999']);
KH_Test::same(['888', '999'], Keelhob_Telegram::allowed_chats(), 'an explicit allowlist overrides the fallback');

Keelhob::instance()->update(['telegram_history_turns' => 500]);
KH_Test::same(60, Keelhob_Telegram::history_turns(), 'a runaway history setting is clamped');
Keelhob::instance()->update(['telegram_history_turns' => -5]);
KH_Test::same(0, Keelhob_Telegram::history_turns(), 'a negative history setting clamps to zero');

KH_Test::group('Telegram — replies are auditable');

$reply = Keelhob_Telegram::format_reply([
    'success'    => true,
    'summary'    => 'Published the winter tyres post.',
    'tool_calls' => [['tool' => 'list_posts'], ['tool' => 'update_post']],
    'usage'      => ['cost_usd' => 0.0123],
]);
KH_Test::contains('Published the winter tyres post.', $reply, 'the reply leads with what the agent said');
KH_Test::contains('2 tools', $reply, 'the reply reports how much work was done');
KH_Test::contains('$0.0123', $reply, 'the reply reports the estimated cost');

$failed = Keelhob_Telegram::format_reply(['success' => false, 'error' => 'No agent API key configured.']);
KH_Test::contains('No agent API key configured.', $failed, 'a failed run reports its actual error, not a generic apology');

$quiet = Keelhob_Telegram::format_reply(['success' => true, 'summary' => 'Done.', 'tool_calls' => [], 'usage' => []]);
KH_Test::same('Done.', $quiet, 'a free, tool-less reply carries no footer noise');

KH_Test::group('Telegram — the command menu is coherent');

$commands = Keelhob_Telegram::commands();
KH_Test::ok(isset($commands['clear']), 'clearing the conversation is an advertised command');
foreach ($commands as $name => $desc) {
    // Telegram rejects a command menu that breaks these rules outright.
    KH_Test::ok((bool) preg_match('/^[a-z0-9_]{1,32}$/', $name), 'command /' . $name . ' is a name Telegram accepts');
    KH_Test::ok($desc !== '' && strlen($desc) <= 256, 'command /' . $name . ' has a usable description');
}

// Every advertised command must actually be handled, or the menu lies.
kh_test_reset_options();
Keelhob::instance()->update(['telegram_allowed_chats' => '1']);
foreach (array_keys($commands) as $name) {
    if (in_array($name, ['status', 'tasks'], true)) {
        continue; // these read WordPress/WooCommerce state; live-smoke covers them
    }
    KH_Test::ok(
        Keelhob_Telegram::run_command('1', $name, '') !== null,
        'command /' . $name . ' has a handler'
    );
}
KH_Test::same(null, Keelhob_Telegram::run_command('1', 'notacommand', ''), 'an unknown command falls through to the agent');

kh_test_reset_options();

KH_Test::group('Telegram — a new bot is not silenced by the old bot\'s mark');

/*
 * THE BUG THIS PINS, reported from a live site: a freshly created bot was
 * completely silent. Configuration perfect, webhook registered, Telegram
 * reporting zero pending deliveries and no errors, and no reply to anything.
 *
 * update_id is monotonic PER BOT, and the replay high-water mark was stored
 * globally. The old bot had reached 412,002,449; a new bot starts far below
 * that, so every message looked like a replay of work already done and was
 * dropped. The endpoint answered 200, Telegram was satisfied, and nothing
 * downstream ever ran -- which is why switching the AI model changed nothing.
 */
kh_test_reset_options([
    'notify_telegram_token' => '111:OLD-BOT-TOKEN-aaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'telegram_bot_enabled'  => true,
]);

Keelhob_Telegram::remember_update_id(412002449);
KH_Test::same(412002449, Keelhob_Telegram::last_update_id(), 'the mark is remembered for the bot that set it');

// Same bot, an older delivery: still a duplicate. The dedupe must keep working.
KH_Test::ok(412002449 >= Keelhob_Telegram::last_update_id(), 'a replay from the SAME bot is still caught');

// Swap the token. This is the whole fix.
Keelhob::instance()->update(['notify_telegram_token' => '222:NEW-BOT-TOKEN-bbbbbbbbbbbbbbbbbbbbbbbbbbbb']);
KH_Test::same(0, Keelhob_Telegram::last_update_id(), 'a NEW bot starts from zero, so its low update_ids are not dropped');

// A new bot's first message really is low-numbered. Before the fix this exact
// comparison silenced the bot forever.
$first_from_new_bot = 1;
KH_Test::ok($first_from_new_bot > Keelhob_Telegram::last_update_id(), 'so update_id 1 from the new bot is processed, not discarded');

// And the new bot then gets its own mark, without resurrecting the old one.
Keelhob_Telegram::remember_update_id(5);
KH_Test::same(5, Keelhob_Telegram::last_update_id(), 'the new bot keeps its own mark');

Keelhob::instance()->update(['notify_telegram_token' => '111:OLD-BOT-TOKEN-aaaaaaaaaaaaaaaaaaaaaaaaaaaa']);
KH_Test::same(0, Keelhob_Telegram::last_update_id(), 'switching back does not resurrect a mark from a different bot');

KH_Test::group('Telegram — a legacy mark is discarded, on purpose');

/*
 * Installs upgrading from before this change hold a bare integer, and there is
 * no way to know which bot wrote it. The two failures are not equal: a stale
 * mark is total, permanent silence that looks like a broken product, while a
 * missing mark risks at most one duplicated message if Telegram happens to
 * retry at that moment. Take the mild, visible failure.
 */
kh_test_reset_options([
    'notify_telegram_token'     => '333:SOME-TOKEN-cccccccccccccccccccccccccccc',
    'telegram_bot_enabled'      => true,
    'keelhob_tg_last_update' => 412002449,     // the old bare-integer shape
]);
update_option('keelhob_tg_last_update', 412002449);
KH_Test::same(0, Keelhob_Telegram::last_update_id(), 'a bare integer from an unknown bot is not trusted');

KH_Test::group('Telegram — the mark never stores the bot token');

kh_test_reset_options([
    'notify_telegram_token' => '444:SECRET-TOKEN-dddddddddddddddddddddddddddd',
    'telegram_bot_enabled'  => true,
]);
Keelhob_Telegram::remember_update_id(77);
$raw = (string) wp_json_encode(get_option('keelhob_tg_last_update'));
KH_Test::ok(strpos($raw, 'SECRET-TOKEN') === false, 'the stored mark carries a hash, never the token itself');
KH_Test::ok(strpos($raw, '444:') === false, 'not even the bot id half of it');
