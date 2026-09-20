<?php
/**
 * Undo and event notifications.
 *
 * Undo is the feature most likely to do harm while looking helpful: if it
 * restores the wrong snapshot, or reports success on something it did not
 * actually put back, the owner finds out days later. So the recording decisions
 * (which changes count, which snapshot wins when a post is edited twice) and the
 * revert bookkeeping are pinned down here against fake post functions.
 *
 * Events are pinned down for the opposite reason: a notifier that repeats itself
 * gets muted, and then the one message that mattered is missed with the rest.
 */

// ---- a fake post store, just enough to exercise Keelhob_Undo -------------

$GLOBALS['kh_posts'] = [];

function kh_fake_post(int $id, array $fields = []): object
{
    $GLOBALS['kh_posts'][$id] = (object) array_merge([
        'ID'           => $id,
        'post_type'    => 'post',
        'post_title'   => 'Title ' . $id,
        'post_content' => 'Body ' . $id,
        'post_excerpt' => '',
        'post_status'  => 'publish',
        'post_name'    => 'slug-' . $id,
    ], $fields);
    return $GLOBALS['kh_posts'][$id];
}

function get_post($id = null, $output = null)
{
    return $GLOBALS['kh_posts'][(int) $id] ?? null;
}

/*
 * get_post_type() used to be declared here. It now lives in tests/bootstrap.php,
 * because the snippet-conditions engine needs one too and a second declaration
 * is a fatal redeclare, not a shadow. The shared version still reads
 * $GLOBALS['kh_posts'] first, so this file's behaviour is unchanged.
 */

function wp_update_post($data = [], $wp_error = false)
{
    $id = (int) ($data['ID'] ?? 0);
    if (!isset($GLOBALS['kh_posts'][$id])) {
        return $wp_error ? new WP_Error('missing', 'No such post') : 0;
    }
    foreach ($data as $k => $v) {
        if ($k !== 'ID') {
            $GLOBALS['kh_posts'][$id]->$k = $v;
        }
    }
    return $id;
}

function wp_trash_post($id = 0)
{
    $id = (int) $id;
    if (!isset($GLOBALS['kh_posts'][$id])) {
        return false;
    }
    $GLOBALS['kh_posts'][$id]->post_status = 'trash';
    return $GLOBALS['kh_posts'][$id];
}

function wp_untrash_post($id = 0)
{
    $id = (int) $id;
    if (!isset($GLOBALS['kh_posts'][$id])) {
        return false;
    }
    $GLOBALS['kh_posts'][$id]->post_status = 'draft';
    return $GLOBALS['kh_posts'][$id];
}

function get_edit_post_link($id = 0, $context = 'display')
{
    return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
}

// ---------------------------------------------------------------------------

KH_Test::group('Undo — what counts as an undoable change');

kh_test_reset_options();
$GLOBALS['kh_posts'] = [];

kh_fake_post(10, ['post_title' => 'Winter tyres', 'post_content' => 'original body']);
kh_fake_post(11, ['post_type' => 'revision']);
kh_fake_post(12, ['post_type' => 'attachment']);

Keelhob_Undo::start('chat-1');
KH_Test::ok(Keelhob_Undo::recording(), 'recording is on after start()');

Keelhob_Undo::on_pre_update(10, []);
Keelhob_Undo::on_pre_update(11, []); // revision — noise, not a change
Keelhob_Undo::on_insert(12, $GLOBALS['kh_posts'][12], false); // attachment
KH_Test::same(1, Keelhob_Undo::finish(), 'revisions and attachments are ignored; the real edit is recorded');
KH_Test::ok(!Keelhob_Undo::recording(), 'recording is off after finish()');
KH_Test::same(1, Keelhob_Undo::depth('chat-1'), 'the turn is banked for this chat');

// An auto-draft is WordPress reserving an ID, not the agent creating anything.
Keelhob_Undo::start('chat-1');
Keelhob_Undo::on_insert(99, (object) ['post_type' => 'post', 'post_status' => 'auto-draft', 'post_title' => ''], false);
KH_Test::same(0, Keelhob_Undo::finish(), 'an auto-draft is not an undoable creation');

// Nothing happened, so nothing should be banked — an empty /undo entry would
// silently consume the owner's one undo.
KH_Test::same(1, Keelhob_Undo::depth('chat-1'), 'a turn that changed nothing does not push an empty entry');

// A change recorded with no scope open must not attach itself to the last one.
Keelhob_Undo::on_pre_update(10, []);
KH_Test::same(1, Keelhob_Undo::depth('chat-1'), 'changes outside a recording window are not captured');

KH_Test::group('Undo — the first snapshot of a turn is the one that matters');

$GLOBALS['kh_posts'] = [];
kh_test_reset_options();
kh_fake_post(20, ['post_content' => 'before the turn']);

Keelhob_Undo::start('chat-2');
Keelhob_Undo::on_pre_update(20, []);
$GLOBALS['kh_posts'][20]->post_content = 'first edit';
Keelhob_Undo::on_pre_update(20, []);   // same post, edited again in one turn
$GLOBALS['kh_posts'][20]->post_content = 'second edit';
Keelhob_Undo::finish();

$res = Keelhob_Undo::revert('chat-2');
KH_Test::ok($res['success'], 'the revert reports success');
KH_Test::same('before the turn', $GLOBALS['kh_posts'][20]->post_content, 'undo returns the post to how it was before the turn, not to a midpoint');
KH_Test::same(1, count($res['restored']), 'a post edited twice is reported as one restoration');
KH_Test::same(0, Keelhob_Undo::depth('chat-2'), 'a redeemed undo is consumed');

$again = Keelhob_Undo::revert('chat-2');
KH_Test::ok(empty($again['success']), 'there is nothing left to undo');
KH_Test::contains('Nothing to undo', (string) $again['error'], 'and it says so plainly');

KH_Test::group('Undo — each kind of change is reversed the right way');

$GLOBALS['kh_posts'] = [];
kh_test_reset_options();
kh_fake_post(30, ['post_title' => 'Edited page']);
kh_fake_post(31, ['post_title' => 'Brand new post']);
kh_fake_post(32, ['post_title' => 'Deleted post']);

Keelhob_Undo::start('chat-3');
Keelhob_Undo::on_pre_update(30, []);
Keelhob_Undo::on_insert(31, $GLOBALS['kh_posts'][31], false);
Keelhob_Undo::on_trash(32);
$GLOBALS['kh_posts'][30]->post_title = 'Edited page (changed)';
$GLOBALS['kh_posts'][32]->post_status = 'trash';
KH_Test::same(3, Keelhob_Undo::finish(), 'all three changes are recorded');

$res = Keelhob_Undo::revert('chat-3');
KH_Test::same('Edited page', $GLOBALS['kh_posts'][30]->post_title, 'an edit is reverted to its snapshot');
KH_Test::same('trash', $GLOBALS['kh_posts'][31]->post_status, 'a post the agent created is trashed, not left live');
KH_Test::same('draft', $GLOBALS['kh_posts'][32]->post_status, 'a post the agent trashed is restored');
KH_Test::same(3, count($res['restored']), 'all three are reported as restored');
KH_Test::same([], $res['failed'], 'nothing failed');

// Undoing must not record itself, or /undo twice would undo the undo.
KH_Test::same(0, Keelhob_Undo::depth('chat-3'), 'the revert did not bank itself as a new undoable turn');

KH_Test::group('Undo — it reports what it could not do');

$GLOBALS['kh_posts'] = [];
kh_test_reset_options();
kh_fake_post(40, ['post_title' => 'Here']);

Keelhob_Undo::start('chat-4');
Keelhob_Undo::on_pre_update(40, []);
Keelhob_Undo::finish();

unset($GLOBALS['kh_posts'][40]); // hard-deleted behind our back

$res = Keelhob_Undo::revert('chat-4');
KH_Test::ok(empty($res['success']), 'a revert that could not complete does not claim success');
KH_Test::same(1, count($res['failed']), 'the post it could not restore is named');
KH_Test::contains('no longer exists', $res['failed'][0], 'and it says why');

KH_Test::group('Undo — stacks are per chat and bounded');

$GLOBALS['kh_posts'] = [];
kh_test_reset_options();
kh_fake_post(50);

for ($i = 0; $i < Keelhob_Undo::KEEP + 3; $i++) {
    Keelhob_Undo::start('chat-5');
    Keelhob_Undo::on_pre_update(50, []);
    Keelhob_Undo::finish();
}
KH_Test::same(Keelhob_Undo::KEEP, Keelhob_Undo::depth('chat-5'), 'the stack keeps only the last few turns');
KH_Test::same(0, Keelhob_Undo::depth('chat-other'), 'one chat cannot undo another chat\'s work');

Keelhob_Undo::clear('chat-5');
KH_Test::same(0, Keelhob_Undo::depth('chat-5'), 'clearing a scope empties its stack');

KH_Test::group('Events — off by default, and switchable per event');

kh_test_reset_options();
KH_Test::ok(!Keelhob_Events::enabled(), 'event notifications are off on a fresh install');

Keelhob::instance()->update(['events_enabled' => true, 'events_order' => true, 'events_updates' => false]);
KH_Test::ok(Keelhob_Events::enabled(), 'the master switch turns them on');
KH_Test::ok(Keelhob_Events::enabled('order'), 'order alerts are on');
KH_Test::ok(!Keelhob_Events::enabled('updates'), 'update alerts stay off independently');

Keelhob::instance()->update(['events_enabled' => false]);
KH_Test::ok(!Keelhob_Events::enabled('order'), 'the master switch overrides an individual event being on');

KH_Test::group('Events — nothing is announced twice');

kh_test_reset_options();
KH_Test::ok(Keelhob_Events::first_time('order:99:processing'), 'the first sighting is news');
KH_Test::ok(!Keelhob_Events::first_time('order:99:processing'), 'the same sighting is not news again');

// The same order moving on IS news; the key carries the transition.
KH_Test::ok(Keelhob_Events::first_time('order:99:completed'), 'a different status change on the same order is news');
KH_Test::ok(Keelhob_Events::first_time('order:100:processing'), 'a different order is news');

// The store is trimmed, so a busy shop cannot grow the option forever.
$seen = [];
for ($i = 0; $i < 5; $i++) {
    $seen['ancient:' . $i] = time() - (40 * 86400);
}
$seen['recent'] = time();
update_option(Keelhob_Events::OPTION_SEEN, $seen);
Keelhob_Events::first_time('new-thing');

$after = (array) get_option(Keelhob_Events::OPTION_SEEN, []);
KH_Test::ok(!isset($after['ancient:0']), 'entries older than the retention window are swept');
KH_Test::ok(isset($after['recent']), 'recent entries survive the sweep');
KH_Test::ok(isset($after['new-thing']), 'the new entry is stored');

kh_test_reset_options();
