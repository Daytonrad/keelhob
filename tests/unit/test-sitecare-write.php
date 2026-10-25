<?php
/**
 * The Site-Care tools that WRITE.
 *
 * These five change real data. `image_compress` overwrites the owner's original
 * uploaded files; `db_cleanup` deletes rows and cannot be undone by anybody;
 * `link_fix` rewrites published pages and Elementor layouts; `plugin_install`
 * downloads code and can switch it on. So the things pinned here are not the
 * happy paths — they are the REFUSALS and the ARITHMETIC:
 *
 *   - the caps (ten images, fifty alt texts, 2000 revisions) are enforced here
 *     rather than trusted, and the items that fall off the end are REPORTED
 *     rather than silently dropped;
 *   - keep-newest-N per parent is the sum that decides whether somebody's only
 *     remaining copy of a page gets deleted, so it is tested at the boundary;
 *   - a slug is a slug, never a URL and never a path, because it is resolved
 *     into a download that gets unpacked into wp-content/plugins;
 *   - a replacement URL is never `javascript:`, because writing one into an
 *     href is stored XSS with a friendly name;
 *   - `db_cleanup` refuses to delete anything at all until a preview has been
 *     taken, and refuses the whole call on a single unrecognised item name.
 *
 * The round-trip proof for the file backup itself lives in
 * tests/unit/test-undo-file-backup.php (same sha256 back out) and the live
 * proofs — a real image compressed on the rig and then undone, a plugin
 * activation rolled back, Elementor data reached in both its escaped and plain
 * forms — are in the BUILD-NOTES record, because none of them can be done
 * without a WordPress install.
 */

require_once KEELHOB_DIR . 'includes/tools/class-tools-sitecare.php';
require_once KEELHOB_DIR . 'includes/tools/class-tools-sitecare-write.php';

/**
 * Shorthand: the real class name is long and appears on nearly every line
 * below. An alias rather than a subclass, because the class is final — and it
 * is final on purpose, since a subclass could override the refusals.
 */
class_alias(Keelhob_Tools_Sitecare_Write::class, 'KH_SCW');

KH_Test::group('Site care (write) — the clamps, at the boundary');

KH_Test::same(2048, KH_SCW::clamp_max_width(0), 'no max_width means 2048');
KH_Test::same(2048, KH_SCW::clamp_max_width(null), 'and so does nothing at all');
KH_Test::same(800, KH_SCW::clamp_max_width(10), 'a silly 10px is clamped up to the 800px floor, not refused');
KH_Test::same(800, KH_SCW::clamp_max_width(800), '800 is the floor and survives');
KH_Test::same(4000, KH_SCW::clamp_max_width(4000), '4000 is the ceiling and survives');
KH_Test::same(4000, KH_SCW::clamp_max_width(99999), 'and 99999 is clamped down to it');
KH_Test::same(1600, KH_SCW::clamp_max_width('1600'), 'a numeric string is accepted — models send both');

KH_Test::same(82, KH_SCW::clamp_quality(0), 'no quality means 82');
KH_Test::same(60, KH_SCW::clamp_quality(5), 'quality 5 is clamped to 60 — below that it is visibly mushy');
KH_Test::same(60, KH_SCW::clamp_quality(60), '60 is the floor');
KH_Test::same(95, KH_SCW::clamp_quality(95), '95 is the ceiling');
KH_Test::same(95, KH_SCW::clamp_quality(100), 'and 100 is clamped to it, because above 95 saves nothing');

KH_Test::group('Site care (write) — ten images a call, and the eleventh is reported');

$kh_w_ids = KH_SCW::image_ids([12, 34, 12, 56]);
KH_Test::same([12, 34, 56], $kh_w_ids['ids'], 'ids are de-duplicated and keep their order');
KH_Test::same([], $kh_w_ids['rejected'], 'with nothing rejected');

$kh_w_eleven = KH_SCW::image_ids(range(1, 11));
KH_Test::same(10, count($kh_w_eleven['ids']), 'ten images per call, never eleven');
KH_Test::same(1, $kh_w_eleven['over_cap'], 'and the one that did not fit is counted, not forgotten');

/*
 * THE FAILURE THIS GUARDS. A tool handed eleven ids that reports on ten has
 * told the owner their last image was done when it was not — and the only
 * place that is visible is the result, so the result has to say so.
 */
KH_Test::contains(
    'per call. Call it again with the rest.',
    (string) file_get_contents(KEELHOB_DIR . 'includes/tools/class-tools-sitecare-write.php'),
    'and image_compress puts that fact in its own result rather than leaving the agent to notice'
);

$kh_w_junk = KH_SCW::image_ids(['12', 'not-a-number', -4, 0, ['id' => 77]]);
KH_Test::same([12, 77], $kh_w_junk['ids'], 'numeric strings and {id: n} objects are understood; zero and negatives are not');
KH_Test::same(3, count($kh_w_junk['rejected']), 'and every unusable value is named back to the agent');

KH_Test::same([5, 6], KH_SCW::image_ids('5, 6')['ids'], 'a comma-separated string is accepted');
KH_Test::same([7, 8], KH_SCW::image_ids('[7,8]')['ids'], 'as is a JSON array sent as a string');
KH_Test::same([], KH_SCW::image_ids(null)['ids'], 'and nothing at all yields nothing');

$kh_w_none = KH_SCW::image_compress(['ids' => []]);
KH_Test::same(false, $kh_w_none['success'], 'image_compress with no usable ids refuses rather than reporting a successful nothing');
KH_Test::contains('speed_audit', (string) $kh_w_none['error'], 'and points the agent at the tool that lists the ids');

KH_Test::group('Site care (write) — fifty alt texts a call');

$kh_w_alt = KH_SCW::alt_items([['id' => 3, 'alt' => 'A red kayak'], ['id' => '4', 'alt' => 'Nothing']]);
KH_Test::same([['id' => 3, 'alt' => 'A red kayak'], ['id' => 4, 'alt' => 'Nothing']], $kh_w_alt['items'], '{id, alt} pairs come through as integers and strings');

$kh_w_map = KH_SCW::alt_items(['9' => 'from a map']);
KH_Test::same([['id' => 9, 'alt' => 'from a map']], $kh_w_map['items'], 'a {"9": "alt"} map is understood too, because a model will send one');

$kh_w_many = [];
for ($kh_i = 1; $kh_i <= 51; $kh_i++) {
    $kh_w_many[] = ['id' => $kh_i, 'alt' => 'x'];
}
$kh_w_capped = KH_SCW::alt_items($kh_w_many);
KH_Test::same(50, count($kh_w_capped['items']), 'fifty per call');
KH_Test::same(1, $kh_w_capped['over_cap'], 'and the fifty-first is reported');

$kh_w_long = KH_SCW::alt_items([['id' => 1, 'alt' => str_repeat('a', 5000)]]);
KH_Test::same(KH_SCW::ALT_MAX_CHARS, strlen($kh_w_long['items'][0]['alt']), 'an absurd alt text is trimmed rather than stored whole');

KH_Test::same(false, KH_SCW::media_set_alt(['items' => []])['success'], 'media_set_alt with nothing usable refuses');
KH_Test::contains('{id, alt}', (string) KH_SCW::media_set_alt(['items' => 'nonsense'])['error'], 'and says what shape it wanted');

KH_Test::group('Site care (write) — which junk, and how many revisions to keep');

KH_Test::same(KH_SCW::CLEANUP_ITEMS, KH_SCW::cleanup_items(null)['items'], 'no items means every kind');
KH_Test::same(KH_SCW::CLEANUP_ITEMS, KH_SCW::cleanup_items([])['items'], 'an empty list means the same');
KH_Test::same(['revisions'], KH_SCW::cleanup_items(['revisions'])['items'], 'one named kind means just that one');
KH_Test::same(['revisions', 'trash'], KH_SCW::cleanup_items(['revisions', 'REVISIONS', ' trash '])['items'], 'case and whitespace are forgiven, duplicates collapse');

/*
 * An unrecognised name is REFUSED, not ignored. "everything except the
 * comments", sent with a typo in one name, would otherwise quietly become
 * "everything" — and this is the tool with no undo.
 */
$kh_w_typo = KH_SCW::cleanup_items(['revisions', 'spam_coments']);
KH_Test::same(['spam_coments'], $kh_w_typo['unknown'], 'a typo is reported as unknown');
KH_Test::same(['revisions'], $kh_w_typo['items'], 'and is not silently treated as something else');

KH_Test::same(3, KH_SCW::clamp_keep_revisions(null), 'keep_revisions defaults to 3');
KH_Test::same(0, KH_SCW::clamp_keep_revisions(0), 'zero is a real answer — keep none');
KH_Test::same(0, KH_SCW::clamp_keep_revisions(-5), 'and negative means the same, not "keep minus five"');
KH_Test::same(50, KH_SCW::clamp_keep_revisions(9999), 'the ceiling is 50');
KH_Test::same(3, KH_SCW::clamp_keep_revisions('nonsense'), 'and nonsense falls back to the default rather than to zero');

KH_Test::group('Site care (write) — keeping the newest N revisions per post');

/*
 * THE ARITHMETIC THAT MATTERS MOST IN THIS FILE. Rows arrive newest-first
 * within each parent (ID DESC), so the first N of each parent survive. Get the
 * grouping wrong and this keeps three revisions IN TOTAL across the site while
 * reporting that it kept three per post.
 */
$kh_w_rows = [
    ['id' => 109, 'parent' => 1], ['id' => 108, 'parent' => 1], ['id' => 107, 'parent' => 1],
    ['id' => 106, 'parent' => 1], ['id' => 105, 'parent' => 1],
    ['id' => 204, 'parent' => 2], ['id' => 203, 'parent' => 2],
    ['id' => 300, 'parent' => 3],
];

$kh_w_keep3 = KH_SCW::revisions_to_delete($kh_w_rows, 3, 2000);
KH_Test::same([106, 105], $kh_w_keep3['delete'], 'keeping three per post deletes only the fourth and fifth of the post that has five');
KH_Test::same(6, $kh_w_keep3['kept'], 'and keeps six: three, two and one');
KH_Test::same(false, $kh_w_keep3['capped'], 'nothing near the cap');

KH_Test::same([], KH_SCW::revisions_to_delete($kh_w_rows, 5, 2000)['delete'], 'keeping five deletes nothing here');
KH_Test::same(8, count(KH_SCW::revisions_to_delete($kh_w_rows, 0, 2000)['delete']), 'keeping none deletes all eight');
KH_Test::same(
    [109, 108, 107, 106, 105, 204, 203, 300],
    KH_SCW::revisions_to_delete($kh_w_rows, 0, 2000)['delete'],
    'and every parent is visited, not just the first'
);

$kh_w_keep1 = KH_SCW::revisions_to_delete($kh_w_rows, 1, 2000);
KH_Test::same([108, 107, 106, 105, 203], $kh_w_keep1['delete'], 'keeping one per post keeps the NEWEST one — the highest ID — for each');
KH_Test::same(3, $kh_w_keep1['kept'], 'which is three posts, one each');

$kh_w_cap = KH_SCW::revisions_to_delete($kh_w_rows, 0, 3);
KH_Test::same(3, count($kh_w_cap['delete']), 'the per-call cap is obeyed exactly');
KH_Test::same(true, $kh_w_cap['capped'], 'and the result says there is more to do');

KH_Test::same([], KH_SCW::revisions_to_delete([], 3, 2000)['delete'], 'a site with no revisions deletes nothing');
KH_Test::same([], KH_SCW::revisions_to_delete([['id' => 0, 'parent' => 1]], 0, 2000)['delete'], 'a row with no id is skipped rather than deleting post 0');

KH_Test::same(2000, KH_SCW::CAP_REVISIONS, 'the revision cap is 2000 per call');
KH_Test::same(1000, KH_SCW::CAP_AUTO_DRAFTS, 'auto-drafts 1000');
KH_Test::same(1000, KH_SCW::CAP_TRASH, 'trashed posts 1000');
KH_Test::same(2000, KH_SCW::CAP_COMMENTS, 'and spam plus trashed comments 2000 between them');
KH_Test::same(7, KH_SCW::OLDER_THAN_DAYS, 'auto-drafts and trash are only touched once a week old — an auto-draft is often work in progress');

KH_Test::group('Site care (write) — db_cleanup will not delete without a preview');

/*
 * THE GUARD. This is the only tool in the plugin with no undo of any kind, so
 * it refuses outright until db_cleanup_preview has left its marker. The owner
 * has to have been shown a number before anything is deleted.
 */
delete_transient(Keelhob_Tools_Sitecare::PREVIEW_MARKER);
$kh_w_refused = KH_SCW::db_cleanup([]);
KH_Test::same(false, $kh_w_refused['success'], 'with no preview on record, db_cleanup refuses');
KH_Test::same(0, $kh_w_refused['deleted'], 'and deletes nothing');
KH_Test::contains('db_cleanup_preview', (string) $kh_w_refused['error'], 'naming the tool to run first');
KH_Test::contains('host backup', (string) $kh_w_refused['what_to_do'], 'and telling the agent to ask for a host backup');
KH_Test::contains('CANNOT be undone', strtoupper(substr((string) $kh_w_refused['what_to_do'], 0)) === strtoupper((string) $kh_w_refused['what_to_do']) ? (string) $kh_w_refused['what_to_do'] : (string) $kh_w_refused['what_to_do'], 'in the words that matter');

// With a preview on record it gets past that guard — and straight into the
// second one, which refuses the WHOLE call over a single bad item name.
set_transient(Keelhob_Tools_Sitecare::PREVIEW_MARKER, time(), Keelhob_Tools_Sitecare::PREVIEW_WINDOW);
$kh_w_bad_item = KH_SCW::db_cleanup(['items' => ['revisions', 'everythnig']]);
KH_Test::same(false, $kh_w_bad_item['success'], 'an unknown item name refuses the whole call');
KH_Test::same(0, $kh_w_bad_item['deleted'], 'with nothing deleted, not even the item it did understand');
KH_Test::contains('everythnig', (string) $kh_w_bad_item['error'], 'naming the typo');
KH_Test::same(KH_SCW::CLEANUP_ITEMS, $kh_w_bad_item['allowed'], 'and listing what it would have accepted');

$kh_w_empty_item = KH_SCW::db_cleanup(['items' => ['', '  ']]);
KH_Test::same(false, $kh_w_empty_item['success'], 'a list of blanks is refused rather than read as "everything"');

delete_transient(Keelhob_Tools_Sitecare::PREVIEW_MARKER);
KH_Test::same(900, Keelhob_Tools_Sitecare::PREVIEW_WINDOW, 'a preview authorises a cleanup for fifteen minutes, not forever');

KH_Test::group('Site care (write) — a plugin slug is never a URL');

KH_Test::same('classic-editor', KH_SCW::wporg_slug('classic-editor'), 'a plain slug is accepted');
KH_Test::same('classic-editor', KH_SCW::wporg_slug('  Classic-Editor '), 'trimmed and lowercased');

/*
 * Everything below is refused because the slug is resolved into a download that
 * is then unpacked into wp-content/plugins. "Install this URL" is a different
 * and far more dangerous tool, and this is not it.
 */
foreach ([
    'https://example.com/evil.zip' => 'a URL',
    '//example.com/evil'           => 'a protocol-relative URL',
    'evil.zip'                     => 'a ZIP filename',
    '../../../etc/passwd'          => 'a traversal',
    'C:\\Windows\\System32'        => 'a Windows path',
    'akismet/akismet.php'          => 'a plugin FILE rather than a slug',
    'two words'                    => 'anything with a space',
    'under_score'                  => 'an underscore, which no directory slug has',
    '-leading-hyphen'              => 'a leading hyphen',
    ''                             => 'an empty string',
] as $kh_bad => $kh_why) {
    KH_Test::same('', KH_SCW::wporg_slug($kh_bad), 'refused: ' . $kh_why);
}
KH_Test::same('', KH_SCW::wporg_slug(['classic-editor']), 'and an array is not a slug either');

$kh_w_badslug = KH_SCW::plugin_install(['slug' => 'https://example.com/x.zip']);
KH_Test::same(false, $kh_w_badslug['success'], 'plugin_install refuses a URL outright');
KH_Test::contains('WordPress.org', (string) $kh_w_badslug['error'], 'and says where it will install from instead');

KH_Test::group('Site care (write) — what may be written into an href');

KH_Test::same(true, KH_SCW::valid_new_url(''), 'empty is allowed: it means remove the link and keep the words');
KH_Test::same(true, KH_SCW::valid_new_url('https://example.com/page'), 'https is allowed');
KH_Test::same(true, KH_SCW::valid_new_url('http://example.com/page?a=b&c=d'), 'so is http with a query string');
KH_Test::same(true, KH_SCW::valid_new_url('/contact'), 'and a root-relative path');

/*
 * A tool that writes a model-supplied `javascript:` into an href is stored XSS
 * with a friendly name. Refused at the door, not sanitised later.
 */
foreach ([
    'javascript:alert(1)'               => 'javascript:',
    'JavaScript:alert(1)'               => 'javascript: in any case',
    'data:text/html;base64,PHNjcmlwdD4=' => 'a data: URL',
    'vbscript:msgbox'                   => 'vbscript:',
    '//example.com/x'                   => 'protocol-relative, which is ambiguous',
    'mailto:a@b.c'                      => 'mailto:, which is a content decision not a link fix',
    "https://example.com/\n<script>"    => 'a control character smuggled in',
    'not a url at all'                  => 'free text',
] as $kh_url => $kh_why) {
    KH_Test::same(false, KH_SCW::valid_new_url($kh_url), 'refused as a replacement: ' . $kh_why);
}

KH_Test::same(false, KH_SCW::valid_old_url(''), 'an empty old_url is refused — it would match everything');
KH_Test::same(false, KH_SCW::valid_old_url('has a space'), 'and so is one with a space');
KH_Test::same(false, KH_SCW::valid_old_url(str_repeat('a', 2001)), 'or one 2001 characters long');
KH_Test::same(true, KH_SCW::valid_old_url('https://gone.example/page'), 'a normal broken address is fine');

$kh_w_bad_new = KH_SCW::link_fix(['post_id' => 1, 'old_url' => 'https://a.test/x', 'new_url' => 'javascript:alert(1)']);
KH_Test::same(false, $kh_w_bad_new['success'], 'link_fix refuses a javascript: replacement before it reads the post');
KH_Test::same(false, KH_SCW::link_fix(['post_id' => 0, 'old_url' => 'https://a.test/x'])['success'], 'and refuses without a post_id');
KH_Test::same(false, KH_SCW::link_fix(['post_id' => 1, 'old_url' => 'https://a.test/x', 'new_url' => 'https://a.test/x'])['success'], 'and refuses to "replace" a link with itself');

KH_Test::group('Site care (write) — rewriting a link in post content');

$kh_w_content = '<p>Read <a href="https://gone.test/old" class="x">the guide</a> and '
    . "<a href='https://gone.test/old'>again</a>, but not "
    . '<a href="https://gone.test/other">this one</a>.</p>';

$kh_w_fixed = KH_SCW::fix_link_in_content($kh_w_content, 'https://gone.test/old', 'https://new.test/new');
KH_Test::same(2, $kh_w_fixed['changes'], 'both quote styles are replaced');
KH_Test::contains('href="https://new.test/new" class="x"', $kh_w_fixed['content'], 'the double-quoted one keeps its other attributes');
KH_Test::contains("href='https://new.test/new'", $kh_w_fixed['content'], 'and the single-quoted one keeps its quote style');
KH_Test::contains('https://gone.test/other', $kh_w_fixed['content'], 'a different link on the same page is left alone');
KH_Test::contains('the guide', $kh_w_fixed['content'], 'and the words are untouched');

/*
 * Removal takes the whole anchor, not just the href. Blanking the href would
 * leave a dead <a href=""> that still looks like a link and goes nowhere —
 * which is the bug, not the fix.
 */
$kh_w_removed = KH_SCW::fix_link_in_content($kh_w_content, 'https://gone.test/old', '');
KH_Test::same(2, $kh_w_removed['changes'], 'removal finds both');
KH_Test::contains('Read the guide and again,', $kh_w_removed['content'], 'the link is gone and its text stayed exactly where it was');
KH_Test::ok(strpos($kh_w_removed['content'], 'href=""') === false, 'and no empty href is left behind');
KH_Test::contains('<a href="https://gone.test/other">', $kh_w_removed['content'], 'other links are still links');

KH_Test::same(0, KH_SCW::fix_link_in_content($kh_w_content, 'https://not.here/x', '/y')['changes'], 'a link that is not there changes nothing');
KH_Test::same(0, KH_SCW::fix_link_in_content('', 'https://a/b', '/c')['changes'], 'and empty content changes nothing');

// A URL with regex metacharacters in it must not be read as a pattern.
$kh_w_meta = KH_SCW::fix_link_in_content('<a href="https://x.test/a+b(c)?d=1">m</a>', 'https://x.test/a+b(c)?d=1', '/safe');
KH_Test::same(1, $kh_w_meta['changes'], 'a URL full of regex metacharacters is matched literally, not compiled');
KH_Test::contains('href="/safe"', $kh_w_meta['content'], 'and replaced');

KH_Test::group('Site care (write) — reaching inside Elementor data');

/*
 * THE WHOLE POINT. Elementor stores the layout as JSON with every forward slash
 * escaped, so the same URL is in there as BOTH `https://x/y` and
 * `https:\/\/x\/y` depending on whether it sat in a `url` setting or inside an
 * HTML widget. A fixer that only replaces the plain form leaves the page
 * pointing at the broken address while reporting success — and Keelhob BUILDS
 * Elementor pages, so this is the normal case here, not the edge case.
 */
$kh_w_el = '[{"settings":{"link":{"url":"https:\\/\\/gone.test\\/old"}},'
    . '"editor":"<p>see <a href=\\"https:\\/\\/gone.test\\/old\\">the guide<\\/a><\\/p>"},'
    . '{"settings":{"link":{"url":"https://gone.test/old"}}}]';

$kh_w_el_fixed = KH_SCW::fix_link_in_elementor($kh_w_el, 'https://gone.test/old', 'https://new.test/new');
KH_Test::same(3, $kh_w_el_fixed['changes'], 'all three occurrences are found: two escaped, one plain');
KH_Test::contains('https:\\/\\/new.test\\/new', $kh_w_el_fixed['json'], 'the escaped form is replaced with an ESCAPED replacement, so the JSON is still JSON');
KH_Test::contains('"url":"https://new.test/new"', $kh_w_el_fixed['json'], 'and the plain form with a plain one');
KH_Test::ok(strpos($kh_w_el_fixed['json'], 'gone.test') === false, 'nothing anywhere still points at the broken address');
KH_Test::ok(is_array(json_decode($kh_w_el_fixed['json'], true)), 'and the result is still valid JSON that Elementor can read');

$kh_w_el_removed = KH_SCW::fix_link_in_elementor($kh_w_el, 'https://gone.test/old', '');
KH_Test::contains('see the guide', $kh_w_el_removed['json'], 'removing a link inside an escaped HTML widget keeps its words');
KH_Test::ok(strpos($kh_w_el_removed['json'], 'gone.test') === false, 'and clears it out of the url settings too');
KH_Test::ok(is_array(json_decode($kh_w_el_removed['json'], true)), 'still valid JSON after a removal');

KH_Test::same(0, KH_SCW::fix_link_in_elementor('', 'https://a/b', '/c')['changes'], 'a page with no Elementor data changes nothing');
KH_Test::same(0, KH_SCW::fix_link_in_elementor($kh_w_el, 'https://not.here/x', '/y')['changes'], 'and so does a link that is not in the layout');

KH_Test::group('Site care (write) — a re-encode is only kept if it is smaller');

/*
 * A 2048px PNG screenshot re-saved at quality 82 is routinely BIGGER than the
 * original. Replacing the original with it would be a tool that makes the site
 * slower while reporting success, having spent the owner's only original to do
 * it. Strictly smaller, not "about the same".
 */
KH_Test::same(true, KH_SCW::is_worth_keeping(100000, 60000), 'smaller is kept');
KH_Test::same(false, KH_SCW::is_worth_keeping(100000, 100000), 'the same size is NOT kept');
KH_Test::same(false, KH_SCW::is_worth_keeping(100000, 100001), 'one byte bigger is not kept');
KH_Test::same(false, KH_SCW::is_worth_keeping(100000, 0), 'and a zero-byte write is never kept — that is a failed encode, not a win');
KH_Test::same(false, KH_SCW::is_worth_keeping(0, 50), 'nor is anything measured against an empty original');

KH_Test::group('Site care (write) — registered, gated OFF, and never read-only');

if (!class_exists('WooCommerce')) {
    class WooCommerce {}
}
foreach (glob(KEELHOB_DIR . 'includes/tools/class-tools-*.php') ?: [] as $kh_w_file) {
    require_once $kh_w_file;
}
$kh_w_registry = new Keelhob_Tool_Registry();
foreach ([
    'Core', 'Filesystem', 'Content', 'Site', 'WooCommerce', 'SEO', 'Memory',
    'Skills', 'Snippets', 'Elementor', 'Ops', 'Automation', 'Payments',
    'Deploy', 'Readiness', 'Sitecare', 'Sitecare_Write',
] as $kh_w_group) {
    call_user_func(['Keelhob_Tools_' . $kh_w_group, 'register'], $kh_w_registry);
}
$kh_w_list = [];
foreach ($kh_w_registry->list() as $kh_t) {
    $kh_w_list[$kh_t['name']] = $kh_t;
}

$kh_w_five = ['image_compress', 'db_cleanup', 'link_fix', 'media_set_alt', 'plugin_install'];

// Nothing stored: the state a fresh install is actually in.
kh_test_reset_options();

foreach ($kh_w_five as $kh_name) {
    KH_Test::ok(isset($kh_w_list[$kh_name]), $kh_name . ' is registered');
    KH_Test::same('sitecare_fix', Keelhob_Capabilities::tool_group($kh_name), 'and sits in the sitecare_fix capability group');
    /*
     * THE ONE THAT MATTERS. A fresh install must not be able to overwrite
     * images or empty a database unasked. The read-only reports in `sitecare`
     * ship ON; these do not.
     */
    KH_Test::same(false, Keelhob_Capabilities::tool_allowed($kh_name), 'and is NOT allowed on a fresh install, because it writes');
    KH_Test::same(true, $kh_w_list[$kh_name]['annotations']['destructiveHint'] ?? null, 'and is annotated destructive');
    KH_Test::ok(($kh_w_list[$kh_name]['annotations']['readOnlyHint'] ?? null) !== true, 'and never read-only, so a Telegram viewer cannot run it');
}

KH_Test::ok(in_array('sitecare_fix', Keelhob_Capabilities::DISABLED_BY_DEFAULT, true), 'the group is in DISABLED_BY_DEFAULT, not merely absent from the stored array');
KH_Test::same(true, Keelhob_Capabilities::groups()['sitecare_fix']['sensitive'], 'and it is marked sensitive, so the card carries the badge');
KH_Test::same(false, Keelhob_Capabilities::is_group_enabled('sitecare_fix'), 'so the group reads as off with nothing stored');

// A strictly read-only actor must not be handed any of them, however the
// viewer list is derived.
$kh_w_viewer = Keelhob_Capabilities::viewer_tools($kh_w_registry->list());
foreach ($kh_w_five as $kh_name) {
    KH_Test::ok(!in_array($kh_name, $kh_w_viewer, true), 'a viewer is never offered ' . $kh_name);
}

// The reports stay on: an owner who cannot ask "why is my site slow" without
// first finding a switch has a worse product. Only ACTING needs permission.
foreach (['speed_audit', 'db_cleanup_preview', 'broken_links'] as $kh_name) {
    KH_Test::ok(Keelhob_Capabilities::tool_allowed($kh_name), 'but the read-only ' . $kh_name . ' is still allowed, because it changes nothing');
}

// Every finding that names one of these five must name one that exists —
// speed_audit now points at image_compress and plugin_install, which it could
// not do while they were unbuilt.
$kh_w_names = $kh_w_registry->names();
foreach (['image_compress', 'plugin_install', 'db_cleanup_preview'] as $kh_name) {
    KH_Test::ok(in_array($kh_name, $kh_w_names, true), 'speed_audit can honestly name ' . $kh_name . ' as the fix');
}

KH_Test::group('Site care (write) — the source keeps its own rules');

$kh_w_src = (string) file_get_contents(KEELHOB_DIR . 'includes/tools/class-tools-sitecare-write.php');

// Never a column called object_id: $wpdb casts that name to int and silently
// stores a string as 0.
KH_Test::ok(strpos($kh_w_src, 'object_id') === false, 'no query names a column "object_id"');

// Orphaned meta is deleted with the same NOT IN (SELECT ID …) form the preview
// counts it with — a DELETE with a JOIN is MySQL-only and the rig is SQLite.
KH_Test::contains('post_id NOT IN (SELECT ID', $kh_w_src, 'orphaned post meta is deleted with NOT IN (SELECT ID …), which works on SQLite as well as MySQL');

// The backup must be taken BEFORE the write, and a refusal must stop the write.
// A backup taken after the write backs up the damage.
KH_Test::contains('Keelhob_Undo::record_file', $kh_w_src, 'image_compress banks a copy through the proven file backup type');
KH_Test::contains('!Keelhob_Undo::record_file', $kh_w_src, 'and treats a refusal as "do not write this file"');
KH_Test::ok(
    strpos($kh_w_src, 'record_file') < strpos($kh_w_src, 'wp_get_image_editor'),
    'the copy is taken before the image editor is even opened'
);

// Keelhob must never switch itself off: every way of putting it back is HTTP
// through this plugin.
KH_Test::contains('KEELHOB_BASENAME', $kh_w_src, 'plugin_install knows which plugin is Keelhob itself');
KH_Test::contains('does not switch itself', $kh_w_src, 'and refuses to activate or deactivate it');

// Activation is checked against the real front end and undone if it broke.
KH_Test::contains('Keelhob_Health::guard', $kh_w_src, 'activation is guarded by the shared front-end check');
KH_Test::contains("'rolled_back'", $kh_w_src, 'and says rolled_back when it switched the plugin off again');

// Thumbnails are deliberately not regenerated, and the result says so rather
// than leaving the owner to wonder.
KH_Test::contains('NOT regenerated', $kh_w_src, 'image_compress states that thumbnails were not regenerated');

// Each destructive tool states its own counts.
foreach (['image_compress', 'db_cleanup', 'link_fix', 'media_set_alt', 'plugin_install'] as $kh_fn) {
    if (preg_match('/public static function ' . $kh_fn . '\(array \$args\).*?\n    \}/s', $kh_w_src, $kh_m)) {
        KH_Test::contains("'count'", $kh_m[0], $kh_fn . ' states its own count in its result');
    } else {
        KH_Test::ok(false, 'could not read ' . $kh_fn . ' out of the source');
    }
}
