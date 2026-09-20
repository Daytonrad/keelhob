<?php
/**
 * Snippet provenance and revision history.
 *
 * Both are FREE on purpose. An owner finding out what the agent changed to
 * their site is not a premium feature, and a history only some sites keep is
 * not a history anyone can rely on. What Pro adds is the Studio screen that
 * turns that history into one-click rollback.
 */

KH_Test::group('Snippets — provenance');

KH_Test::same('manual', Keelhob_Snippets::normalize_source('manual'), 'hand-written');
KH_Test::same('ai', Keelhob_Snippets::normalize_source('ai'), 'written by the agent');
KH_Test::same('library', Keelhob_Snippets::normalize_source('library'), 'taken from the bundled library');
KH_Test::same('cloud', Keelhob_Snippets::normalize_source('cloud'), 'pulled from the private library');
KH_Test::same('ai', Keelhob_Snippets::normalize_source('AI'), 'case is not significant');
KH_Test::same('ai', Keelhob_Snippets::normalize_source('  ai  '), 'surrounding space is ignored');

// Unknown falls back to "manual" rather than being stored verbatim. The value
// drives a badge in the UI, and an unrecognised source would render as an empty
// or broken label rather than an obviously wrong one.
KH_Test::same('manual', Keelhob_Snippets::normalize_source('smuggled'), 'an unrecognised source is treated as hand-written');
KH_Test::same('manual', Keelhob_Snippets::normalize_source(''), 'so is an empty one');

KH_Test::group('Snippets — the revisions table is separate');

// Revisions live in their own table rather than as extra rows in the snippets
// table. Mixing them would mean every query that lists snippets has to remember
// to exclude history, and the first one that forgets shows the owner five
// copies of the same snippet.
KH_Test::contains('keelhob_snippet_revisions', Keelhob_Snippets::revisions_table(), 'revisions have their own table');
KH_Test::ok(Keelhob_Snippets::revisions_table() !== Keelhob_Snippets::table(), 'and it is not the snippets table');

$src = file_get_contents(KEELHOB_DIR . 'includes/class-snippets.php');

// A save that does not touch the code must not write a revision. Renaming or
// retagging a snippet ten times would otherwise bury the one entry that
// actually mattered.
KH_Test::contains('if ($existing && $code_changed) {', $src, 'a revision is only recorded when the code actually changed');
// Ordering inside save() specifically: a snapshot taken after the write would
// record the NEW code as the old version, which is worse than no history at all
// because it looks like history.
$save_body = substr($src, strpos($src, 'public static function save('));
$save_body = substr($save_body, 0, strpos($save_body, 'public static function query('));
KH_Test::ok(
    strpos($save_body, 'self::record_revision(') !== false
        && strpos($save_body, 'self::record_revision(') < strpos($save_body, '$wpdb->update('),
    'the snapshot is taken BEFORE the write'
);
KH_Test::contains('Keelhob_Snippet_Log::actor()', $src, 'and it records whether a human or the agent made the change');

KH_Test::group('Snippets — deleting a snippet takes its revisions with it');

/*
 * Every edit writes a revision, and delete() used to remove only the snippet
 * row. A snippet edited twenty times and then deleted left twenty rows keyed to
 * an id nothing would ever look up again: unreachable through any screen or
 * tool, growing without limit, and each one holding a FULL COPY OF THE CODE.
 *
 * That last part is why this is not merely untidy. Somebody who deletes a
 * snippet because it contained a key, a password or a customer's data has been
 * told the snippet is gone. It was not.
 *
 * Found by a teardown step that deleted a test snippet and counted what
 * remained: "snippets left: 0, revisions left: 7".
 *
 * Asserted against the source rather than a live database, in keeping with the
 * rest of this file — the suite has no MySQL, and the ordering (inside the
 * `$deleted && $snip` branch, so a delete that hit nothing wipes nothing) is
 * the part worth pinning.
 */
$delete_body = substr($src, strpos($src, 'public static function delete(int $id)'));
$delete_body = substr($delete_body, 0, strpos($delete_body, 'const EMITTING_LOCATIONS'));

KH_Test::contains(
    "\$wpdb->delete(self::revisions_table(), ['snippet_id' => \$id]);",
    $delete_body,
    'delete() clears the revisions for that snippet'
);
KH_Test::ok(
    strpos($delete_body, 'if ($deleted && $snip) {') < strpos($delete_body, 'self::revisions_table()'),
    '...and only when a row was actually deleted, so a miss cannot wipe another snippet\'s history'
);
