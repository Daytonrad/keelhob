<?php
/**
 * Site-care / Optimize tools — the half that WRITES.
 *
 * The reports live in class-tools-sitecare.php and change nothing. These five
 * act on what those reports found, and they are a different kind of tool:
 *
 *   image_compress   overwrites the owner's original uploaded files
 *   db_cleanup       deletes rows and CANNOT BE UNDONE BY ANYONE
 *   link_fix         rewrites post content and Elementor layouts
 *   media_set_alt    writes alt text on up to 50 attachments
 *   plugin_install   downloads code from WordPress.org and can switch it on
 *
 * WHY THEY ARE A SEPARATE FILE AND A SEPARATE CAPABILITY GROUP.
 * tests/unit/test-sitecare.php greps the read-only file for every write call it
 * must never contain — wp_delete_post, unlink(, rename(, update_post_meta,
 * activate_plugin — and that assertion is the thing that keeps the read-only
 * half honest. Putting a tool that renames files into that file would have
 * meant deleting the assertion, i.e. trading a proof for a promise. So the
 * writers sit here, in `sitecare_fix`, which is sensitive and ships SWITCHED
 * OFF (Keelhob_Capabilities::DISABLED_BY_DEFAULT). A fresh install can always
 * ask what is wrong; it cannot resize someone's photographs unasked.
 *
 * THE FOUR RULES EVERY TOOL BELOW FOLLOWS.
 *
 *   1. BACK UP BEFORE, NOT AFTER. image_compress calls
 *      Keelhob_Undo::record_file() before it touches a pixel and treats a
 *      `false` as "there is no undo for this image, so do not write it". A
 *      backup taken after the write backs up the damage.
 *   2. WRITE TO A TEMP FILE AND KEEP IT ONLY IF IT IS BETTER. A re-encode is
 *      not automatically smaller — a 2048px PNG screenshot re-saved at quality
 *      82 is routinely BIGGER. Replacing the original with a bigger file would
 *      be a tool that makes the site slower while reporting success.
 *   3. STATE YOUR OWN COUNTS, AND NAME WHAT CHANGED. Every result carries
 *      `count` plus a per-item list with the before and after numbers. A model
 *      once miscounted 44 rows as 28 and told the owner so.
 *   4. VALIDATE EVERYTHING THE MODEL SENDS. A slug is a slug (never a URL,
 *      never a path), a URL is http(s) or root-relative, an id is a positive
 *      integer, and every cap is enforced here rather than trusted.
 *
 * Same shape as its read-only sibling: the DECIDERS are pure (values in,
 * decisions out, no WordPress call inside them) so the unit suite can exercise
 * the judgement — the caps, the clamps, the keep-newest-N arithmetic, the link
 * rewriting — without a database or a disk. The DOERS below them are the thin,
 * boring, WordPress-dependent half.
 *
 * NEVER USE THE COLUMN NAME $wpdb SILENTLY CASTS TO INT — the one on a meta
 * table that WordPress treats as an integer and stores a string into as 0.
 * Nothing here names it, a test greps this file to prove it, and that is why
 * the name is not written out even in this comment: the test is a dumb text
 * search, on purpose, and a comment that mentions the word fails it.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Tools_Sitecare_Write
{
    // ---- image_compress ---------------------------------------------------

    /** Images per call. Ten re-encodes is already most of a request's budget. */
    public const IMAGES_PER_CALL = 10;

    public const WIDTH_MIN     = 800;
    public const WIDTH_MAX     = 4000;
    public const WIDTH_DEFAULT = 2048;

    public const QUALITY_MIN     = 60;
    public const QUALITY_MAX     = 95;
    public const QUALITY_DEFAULT = 82;

    /**
     * The undo scope image_compress and link_fix open for themselves when
     * nothing else is recording.
     *
     * Keelhob_Undo only records inside a scope, and the Telegram router is the
     * only thing that opens one — so over plain MCP record_file() would always
     * refuse and these tools could never write at all. They therefore open one
     * fixed scope each (never a unique one per call: Keelhob_Undo::KEEP prunes
     * the oldest turns per scope, and a new scope name every time would leave
     * every backup on disk forever until the 128 MB store cap quietly stopped
     * the tool working).
     *
     * When something IS already recording — a Telegram turn — these tools
     * record into that scope instead, so the owner's own /undo puts it back.
     * They never call start() over a live scope: start() resets the buffer, and
     * that would throw away everything the turn had banked so far.
     */
    public const UNDO_SCOPE_IMAGES = 'tool:image_compress';
    public const UNDO_SCOPE_LINKS  = 'tool:link_fix';

    // ---- media_set_alt ----------------------------------------------------

    public const ALT_PER_CALL  = 50;
    public const ALT_MAX_CHARS = 1000;

    // ---- db_cleanup -------------------------------------------------------

    /** Everything db_cleanup knows how to remove. Anything else is refused. */
    public const CLEANUP_ITEMS = [
        'revisions', 'auto_drafts', 'trash', 'spam_comments',
        'trash_comments', 'expired_transients', 'orphan_postmeta',
    ];

    public const KEEP_REVISIONS_DEFAULT = 3;
    public const KEEP_REVISIONS_MAX     = 50;

    /**
     * Caps per call, from the tuned list. They exist because this is the one
     * tool with no undo: a run that deletes 40,000 rows and times out halfway
     * leaves the owner with no way to know what went.
     */
    public const CAP_REVISIONS   = 2000;
    public const CAP_AUTO_DRAFTS = 1000;
    public const CAP_TRASH       = 1000;
    public const CAP_COMMENTS    = 2000;

    /**
     * Auto-drafts and trashed posts are only removed once they are a week old.
     * An auto-draft is WordPress reserving an ID for a post somebody is writing
     * RIGHT NOW; deleting today's would delete work in progress out from under
     * the person doing it.
     */
    public const OLDER_THAN_DAYS = 7;

    public static function register(Keelhob_Tool_Registry $r): void
    {
        $r->add(
            'image_compress',
            'Shrink oversized images IN PLACE: resizes anything wider than max_width (800-4000, default 2048) and re-saves it at quality 60-95 (default 82). Up to 10 images per call, ids from speed_audit. The original file is copied into the backup folder first so it can be put back, and an image that would not actually get smaller is left exactly as it was. Thumbnails are NOT regenerated — the other image sizes keep their existing files.',
            [
                'type' => 'object',
                'properties' => [
                    'ids'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Attachment IDs, up to 10 per call. speed_audit lists the biggest ones first.'],
                    'max_width' => ['type' => 'integer', 'description' => 'Resize anything wider than this. 800-4000, default 2048.'],
                    'quality'   => ['type' => 'integer', 'description' => 'JPEG/WebP quality, 60-95, default 82.'],
                ],
                'required' => ['ids'],
            ],
            [self::class, 'image_compress'],
            ['destructiveHint' => true]
        );

        $r->add(
            'db_cleanup',
            'Delete database junk. THIS CANNOT BE UNDONE — not by Keelhob, not by WordPress. Run db_cleanup_preview first (this tool refuses until you have), show the owner the numbers, and tell them to take a host backup before saying yes. items picks what to clean: revisions, auto_drafts, trash, spam_comments, trash_comments, expired_transients, orphan_postmeta (default: all of them). Keeps the newest keep_revisions (default 3) per post, and only removes auto-drafts and trashed posts older than 7 days. Capped per call (2000 revisions, 1000 auto-drafts, 1000 trashed posts, 2000 comments) — it returns what is left, so run it again if anything remains.',
            [
                'type' => 'object',
                'properties' => [
                    'items'           => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Which kinds to remove. Omit for all of them.'],
                    'keep_revisions'  => ['type' => 'integer', 'description' => 'How many of the newest revisions to keep per post. Default 3, maximum 50.'],
                ],
                'required' => [],
            ],
            [self::class, 'db_cleanup'],
            ['destructiveHint' => true]
        );

        $r->add(
            'link_fix',
            'Replace a broken link on one page or post with a new address, or remove the link but keep its text by leaving new_url empty. Works in normal content AND in Elementor layouts (both the plain and the escaped form of the URL). The post is backed up first. It errors rather than guessing if the old link is not actually on that page.',
            [
                'type' => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer', 'description' => 'The page or post to fix, as reported by broken_links.'],
                    'old_url' => ['type' => 'string', 'description' => 'The exact broken address to replace.'],
                    'new_url' => ['type' => 'string', 'description' => 'The replacement address (http(s) or root-relative). Leave empty to delete the link and keep the words.'],
                ],
                'required' => ['post_id', 'old_url'],
            ],
            [self::class, 'link_fix'],
            ['destructiveHint' => true]
        );

        $r->add(
            'media_set_alt',
            'Set alt text on up to 50 images at once. items is a list of {id, alt}. Write alt text that describes the image for someone who cannot see it — what is in it, not "image of".',
            [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'  => ['type' => 'integer', 'description' => 'Attachment ID.'],
                                'alt' => ['type' => 'string', 'description' => 'The alt text. Empty clears it.'],
                            ],
                        ],
                        'description' => 'Up to 50 {id, alt} pairs. media_list with missing_alt finds the ones that need it.',
                    ],
                ],
                'required' => ['items'],
            ],
            [self::class, 'media_set_alt'],
            ['destructiveHint' => true]
        );

        $r->add(
            'plugin_install',
            'Install a plugin from the official WordPress.org directory by its slug (e.g. "seo-by-rank-math"). A slug only — never a URL, never a ZIP, never a path. It is installed SWITCHED OFF unless activate is true; activating loads the homepage straight afterwards and switches the plugin off again if the site broke, reporting rolled_back. Refuses if the plugin is already installed.',
            [
                'type' => 'object',
                'properties' => [
                    'slug'     => ['type' => 'string', 'description' => 'The WordPress.org directory slug, e.g. "classic-editor". Lowercase letters, numbers and hyphens.'],
                    'activate' => ['type' => 'boolean', 'description' => 'Switch it on after installing (default false). The homepage is checked and the plugin switched off again if it breaks.'],
                ],
                'required' => ['slug'],
            ],
            [self::class, 'plugin_install'],
            ['destructiveHint' => true]
        );
    }

    // =========================================================================
    // DECIDERS — pure. No WordPress function may be called below this line,
    // and none above the DOERS marker.
    // =========================================================================

    /**
     * max_width, clamped into the range the tool advertises.
     *
     * Clamped rather than refused: a model that asks for 600px has made a
     * judgement about the site, not a mistake, and resizing to the nearest
     * width the tool supports is a better answer than an error the agent then
     * has to explain. The result says what was actually used.
     */
    public static function clamp_max_width($raw): int
    {
        $v = (int) $raw;
        if ($v <= 0) {
            return self::WIDTH_DEFAULT;
        }
        return max(self::WIDTH_MIN, min(self::WIDTH_MAX, $v));
    }

    /** quality, clamped to 60-95. Below 60 is visibly mushy; above 95 saves nothing. */
    public static function clamp_quality($raw): int
    {
        $v = (int) $raw;
        if ($v <= 0) {
            return self::QUALITY_DEFAULT;
        }
        return max(self::QUALITY_MIN, min(self::QUALITY_MAX, $v));
    }

    /**
     * Attachment ids from the model: positive integers, de-duplicated, in the
     * order given, capped at ten.
     *
     * The ids that do not survive are RETURNED rather than dropped silently.
     * A tool that is handed eleven ids and reports on ten has told the owner
     * their last image was done when it was not.
     *
     * @param mixed $raw
     * @return array{ids:array<int,int>, rejected:array<int,string>, over_cap:int}
     */
    public static function image_ids($raw): array
    {
        $ids      = [];
        $rejected = [];
        $over     = 0;

        if (is_string($raw)) {
            // A JSON array or a comma list, because models send both.
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : explode(',', $raw);
        }
        if (!is_array($raw)) {
            return ['ids' => [], 'rejected' => ['ids must be a list of attachment IDs'], 'over_cap' => 0];
        }

        foreach ($raw as $one) {
            if (is_array($one)) {
                $one = $one['id'] ?? null;
            }
            if (is_string($one)) {
                $one = trim($one);
            }
            if (!is_numeric($one) || (int) $one <= 0) {
                $rejected[] = 'not an attachment ID: ' . substr(is_scalar($one) ? (string) $one : gettype($one), 0, 40);
                continue;
            }
            $id = (int) $one;
            if (in_array($id, $ids, true)) {
                continue;
            }
            if (count($ids) >= self::IMAGES_PER_CALL) {
                $over++;
                continue;
            }
            $ids[] = $id;
        }

        return ['ids' => $ids, 'rejected' => $rejected, 'over_cap' => $over];
    }

    /**
     * {id, alt} pairs from the model, capped at fifty.
     *
     * @param mixed $raw
     * @return array{items:array<int,array{id:int,alt:string}>, rejected:array<int,string>, over_cap:int}
     */
    public static function alt_items($raw): array
    {
        $items    = [];
        $rejected = [];
        $over     = 0;

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return ['items' => [], 'rejected' => ['items must be a list of {id, alt}'], 'over_cap' => 0];
        }

        /*
         * Also accept {"12": "alt text"}, which is what a model produces when
         * it decides a list of pairs is really a map. Told apart from a LIST by
         * its keys, not by the shape of each value: in a list the keys are
         * 0..n-1, and reading `["a", "b"]` as "set attachment 1's alt to b"
         * would write alt text onto whatever image happens to have that id.
         */
        $is_list = array_keys($raw) === range(0, count($raw) - 1);

        foreach ($raw as $key => $one) {
            if (!is_array($one)) {
                if ($is_list) {
                    $rejected[] = 'each item must be {id, alt}';
                    continue;
                }
                $one = ['id' => $key, 'alt' => $one];
            }

            $id = $one['id'] ?? 0;
            if (!is_numeric($id) || (int) $id <= 0) {
                $rejected[] = 'not an attachment ID: ' . substr(is_scalar($id) ? (string) $id : gettype($id), 0, 40);
                continue;
            }
            if (count($items) >= self::ALT_PER_CALL) {
                $over++;
                continue;
            }

            $alt = $one['alt'] ?? '';
            $items[] = [
                'id'  => (int) $id,
                'alt' => substr(is_scalar($alt) ? (string) $alt : '', 0, self::ALT_MAX_CHARS),
            ];
        }

        return ['items' => $items, 'rejected' => $rejected, 'over_cap' => $over];
    }

    /** keep_revisions, clamped. Zero is allowed and means "keep none". */
    public static function clamp_keep_revisions($raw): int
    {
        if ($raw === null || $raw === '') {
            return self::KEEP_REVISIONS_DEFAULT;
        }
        if (!is_numeric($raw)) {
            return self::KEEP_REVISIONS_DEFAULT;
        }
        return max(0, min(self::KEEP_REVISIONS_MAX, (int) $raw));
    }

    /**
     * Which kinds of junk to remove. An unrecognised name is REFUSED, not
     * ignored: "clean up everything except the comments" sent as a typo would
     * otherwise clean up the comments.
     *
     * @param mixed $raw
     * @return array{items:array<int,string>, unknown:array<int,string>}
     */
    public static function cleanup_items($raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return ['items' => self::CLEANUP_ITEMS, 'unknown' => []];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : explode(',', $raw);
        }
        if (!is_array($raw)) {
            return ['items' => [], 'unknown' => ['items must be a list of names']];
        }

        $items   = [];
        $unknown = [];
        foreach ($raw as $one) {
            if (!is_string($one) && !is_numeric($one)) {
                $unknown[] = gettype($one);
                continue;
            }
            $name = strtolower(trim((string) $one));
            if ($name === '') {
                continue;
            }
            if (!in_array($name, self::CLEANUP_ITEMS, true)) {
                $unknown[] = $name;
                continue;
            }
            if (!in_array($name, $items, true)) {
                $items[] = $name;
            }
        }
        return ['items' => $items, 'unknown' => $unknown];
    }

    /**
     * Which revision ids to delete, keeping the newest $keep per parent.
     *
     * Pure, and tested at the boundary, because this is the arithmetic that
     * decides whether somebody's only remaining copy of a page gets deleted.
     * Rows arrive ordered newest-first within each parent (ID DESC), so the
     * first $keep of each parent survive and the rest go.
     *
     * @param array<int, array{id:int, parent:int}> $rows newest first per parent
     * @param int                                   $keep how many to keep per parent
     * @param int                                   $cap  hard limit on one call
     * @return array{delete:array<int,int>, kept:int, capped:bool}
     */
    public static function revisions_to_delete(array $rows, int $keep, int $cap): array
    {
        $seen   = [];
        $delete = [];
        $kept   = 0;
        $capped = false;

        foreach ($rows as $row) {
            $id     = (int) ($row['id'] ?? 0);
            $parent = (int) ($row['parent'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $seen[$parent] = ($seen[$parent] ?? 0) + 1;
            if ($seen[$parent] <= $keep) {
                $kept++;
                continue;
            }
            if (count($delete) >= $cap) {
                $capped = true;
                break;
            }
            $delete[] = $id;
        }

        return ['delete' => $delete, 'kept' => $kept, 'capped' => $capped];
    }

    /**
     * A WordPress.org plugin slug, or ''.
     *
     * The ONLY thing plugin_install accepts. A URL, a path, a .zip, an absolute
     * Windows path, anything with a dot or a slash in it: all refused, because
     * the slug goes straight into a plugins_api lookup whose download_link is
     * then fetched and unpacked into wp-content/plugins.
     */
    public static function wporg_slug($raw): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $slug = strtolower(trim($raw));
        return preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug) === 1 ? $slug : '';
    }

    /**
     * Whether a replacement URL is one we are willing to write into a page.
     *
     * http(s) absolute, or root-relative. Not javascript:, not data:, not
     * vbscript: — a tool that writes a model-supplied `javascript:` into an
     * href is a stored-XSS vector with a friendly name. mailto: and tel: are
     * refused too: this tool fixes broken links, and "the new address is an
     * email" is a content decision for update_post.
     */
    public static function valid_new_url(string $url): bool
    {
        if ($url === '') {
            return true;    // empty means "remove the link, keep the text"
        }
        if (preg_match('~[\x00-\x1f\x7f]~', $url)) {
            return false;
        }
        if (strpos($url, '//') === 0) {
            return false;   // protocol-relative: ambiguous, and easy to get wrong
        }
        if ($url[0] === '/') {
            return true;
        }
        return preg_match('~^https?://[^\s"\'<>]+$~i', $url) === 1;
    }

    /** The old URL must at least be a plausible link, and must not be empty. */
    public static function valid_old_url(string $url): bool
    {
        if ($url === '' || strlen($url) > 2000) {
            return false;
        }
        return !preg_match('~[\x00-\x1f\x7f\s"\'<>]~', $url);
    }

    /**
     * Rewrite or remove one link in post content.
     *
     * Two jobs, because "fix" and "unlink" are the same request with an empty
     * new address:
     *
     *   new_url given  -> href="old" becomes href="new", both quote styles
     *   new_url empty  -> <a href="old" …>words</a> becomes words
     *
     * The removal case matches the whole anchor rather than just the href,
     * which is the point: blanking the href would leave a dead `<a href="">`
     * that still looks like a link and goes nowhere.
     *
     * @return array{content:string, changes:int}
     */
    public static function fix_link_in_content(string $content, string $old, string $new): array
    {
        if ($content === '' || $old === '') {
            return ['content' => $content, 'changes' => 0];
        }
        $quoted  = preg_quote($old, '~');
        $changes = 0;

        if ($new === '') {
            $out = preg_replace_callback(
                '~<a\b[^>]*href\s*=\s*(["\'])' . $quoted . '\1[^>]*>(.*?)</a\s*>~is',
                static function (array $m) use (&$changes): string {
                    $changes++;
                    return $m[2];
                },
                $content
            );
            return ['content' => is_string($out) ? $out : $content, 'changes' => $changes];
        }

        $out = preg_replace_callback(
            '~(href\s*=\s*)(["\'])' . $quoted . '\2~i',
            static function (array $m) use (&$changes, $new): string {
                $changes++;
                return $m[1] . $m[2] . $new . $m[2];
            },
            $content
        );
        return ['content' => is_string($out) ? $out : $content, 'changes' => $changes];
    }

    /**
     * Rewrite one link inside `_elementor_data`.
     *
     * Elementor stores the whole layout as JSON with every forward slash
     * escaped, so the same URL is in there as BOTH
     * `https://example.com/page` and `https:\/\/example.com\/page`, depending
     * on whether it sat in a `url` setting or inside an HTML widget. A fixer
     * that replaces only the plain form leaves the layout pointing at the
     * broken address while reporting success — which is exactly the failure
     * broken_links was built to stop telling the owner about.
     *
     * String replacement, not a JSON decode-edit-encode round trip: re-encoding
     * Elementor's data normalises slashes and unicode escapes across the whole
     * document, and a layout that was not what Elementor wrote is a layout that
     * can stop rendering for reasons nobody can see in a diff.
     *
     * An empty $new removes the anchor in escaped HTML and empties the `url`
     * value in settings, which is how Elementor itself represents "no link".
     *
     * @return array{json:string, changes:int}
     */
    public static function fix_link_in_elementor(string $json, string $old, string $new): array
    {
        if ($json === '' || $old === '') {
            return ['json' => $json, 'changes' => 0];
        }

        $esc_old = str_replace('/', '\\/', $old);
        $esc_new = str_replace('/', '\\/', $new);
        $changes = 0;

        if ($new === '') {
            // Escaped anchors first: <a href=\"old\">words<\/a> -> words
            $json = (string) preg_replace_callback(
                '~<a\b[^>]*href\s*=\s*\\\\?["\'](?:' . preg_quote($esc_old, '~') . '|' . preg_quote($old, '~') . ')\\\\?["\'][^>]*>(.*?)<\\\\?/a\s*>~is',
                static function (array $m) use (&$changes): string {
                    $changes++;
                    return $m[1];
                },
                $json
            );
        }

        // Then the plain and escaped forms of the URL itself, wherever they sit
        // (a `url` setting, a background, a button). Longest first, so the
        // escaped form is not half-eaten by the plain one.
        foreach ([$esc_old, $old] as $needle) {
            if ($needle === '' || strpos($json, $needle) === false) {
                continue;
            }
            $replacement = $needle === $esc_old ? $esc_new : $new;
            $hits        = substr_count($json, $needle);
            $json        = str_replace($needle, $replacement, $json);
            $changes    += $hits;
        }

        return ['json' => $json, 'changes' => $changes];
    }

    /**
     * Whether the re-encoded file is worth keeping.
     *
     * Strictly smaller. Not "within a few bytes", not "the same": re-saving a
     * file for a 12-byte gain spends the owner's only original on nothing.
     */
    public static function is_worth_keeping(int $original_bytes, int $new_bytes): bool
    {
        return $new_bytes > 0 && $original_bytes > 0 && $new_bytes < $original_bytes;
    }

    // =========================================================================
    // DOERS — WordPress-dependent. Everything above this line is pure.
    // =========================================================================

    /**
     * Resize and re-save up to ten images in place.
     *
     * The order of operations is the whole safety story and it is deliberate:
     *
     *   1. bank a copy      (Keelhob_Undo::record_file; false => do not write)
     *   2. edit in memory   (wp_get_image_editor)
     *   3. save to a TEMP file next to the original
     *   4. compare sizes; a file that is not smaller is deleted and the
     *      original is left untouched
     *   5. only then rename the temp file over the original
     *   6. update the attachment metadata's width/height/filesize
     *
     * Thumbnails are NOT regenerated, and the result says so. Regenerating them
     * would rewrite every size of every image in the call — far more destruction
     * than "compress this image" asks for, and the existing thumbnails are
     * already the right pixel sizes.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function image_compress(array $args): array
    {
        $parsed    = self::image_ids($args['ids'] ?? null);
        $ids       = $parsed['ids'];
        $max_width = self::clamp_max_width($args['max_width'] ?? 0);
        $quality   = self::clamp_quality($args['quality'] ?? 0);

        if ($ids === []) {
            return [
                'success' => false,
                'error'   => 'No usable attachment IDs. Pass ids as a list of attachment IDs, e.g. [12, 34]. speed_audit lists the biggest images first.',
                'rejected' => $parsed['rejected'],
            ];
        }

        $own_scope = !Keelhob_Undo::recording();
        if ($own_scope) {
            Keelhob_Undo::start(self::UNDO_SCOPE_IMAGES);
        }

        $done     = [];
        $skipped  = [];
        $saved    = 0;

        try {
            foreach ($ids as $id) {
                $result = self::compress_one($id, $max_width, $quality);
                if ($result['ok']) {
                    $done[] = $result['row'];
                    $saved += (int) ($result['row']['saved_bytes'] ?? 0);
                } else {
                    $skipped[] = $result['row'];
                }
            }
        } finally {
            if ($own_scope) {
                Keelhob_Undo::finish();
            }
        }

        foreach ($parsed['rejected'] as $bad) {
            $skipped[] = ['id' => 0, 'reason' => $bad];
        }
        if ($parsed['over_cap'] > 0) {
            $skipped[] = [
                'id'     => 0,
                'reason' => $parsed['over_cap'] . ' more image(s) were not touched: this tool does '
                    . self::IMAGES_PER_CALL . ' per call. Call it again with the rest.',
            ];
        }

        return [
            'success'       => $done !== [] || $skipped === [],
            'count'         => count($done),
            'compressed'    => $done,
            'skipped'       => $skipped,
            'skipped_count' => count($skipped),
            'bytes_saved'   => $saved,
            'kb_saved'      => (int) round($saved / 1024),
            'settings'      => ['max_width' => $max_width, 'quality' => $quality],
            'undo'          => $own_scope
                ? 'The originals are banked in the Keelhob backup folder under the scope "' . self::UNDO_SCOPE_IMAGES . '", and the last ' . Keelhob_Undo::KEEP . ' calls can be put back byte for byte.'
                : 'The originals are banked in this turn, so /undo puts them back byte for byte.',
            'note'          => 'Thumbnails and the other registered image sizes were NOT regenerated — only the full-size original file was rewritten, and its width, height and filesize in the attachment metadata were updated to match. If a layout needs the smaller sizes rebuilt, that is a separate job.',
        ];
    }

    /**
     * One image, start to finish.
     *
     * @return array{ok:bool, row:array<string, mixed>}
     */
    private static function compress_one(int $id, int $max_width, int $quality): array
    {
        $no = static function (int $id, string $file, string $reason): array {
            return ['ok' => false, 'row' => ['id' => $id, 'file' => $file, 'reason' => $reason]];
        };

        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment') {
            return $no($id, '', 'not an attachment');
        }
        $mime = (string) get_post_mime_type($id);
        if (strpos($mime, 'image/') !== 0) {
            return $no($id, '', 'not an image (' . ($mime !== '' ? $mime : 'unknown type') . ')');
        }
        // An SVG is text, not pixels: there is nothing to re-encode and an
        // image editor would either fail or rasterise it.
        if ($mime === 'image/svg+xml') {
            return $no($id, '', 'an SVG has no pixels to compress');
        }

        $path = (string) get_attached_file($id);
        if ($path === '' || !is_file($path)) {
            return $no($id, $path, 'the file is missing from disk');
        }
        if (!is_writable($path) || !is_writable(dirname($path))) {
            return $no($id, basename($path), 'the file or its folder is not writable');
        }

        $before = (int) filesize($path);

        /*
         * THE GUARD. record_file() returns false rather than taking a partial
         * backup — too big, no writable store, store full, nothing recording.
         * False means there is no way back for this file, so it is not written.
         * Refusing an image is a nuisance; overwriting someone's only original
         * with no copy is not recoverable.
         */
        if (!Keelhob_Undo::record_file($path, $id)) {
            return $no($id, basename($path), 'REFUSED: the original could not be backed up, so it was not touched. It may be over ' . (int) round(Keelhob_Undo::FILE_MAX_BYTES / (1024 * 1024)) . ' MB, or the backup folder may be full or unwritable.');
        }

        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            return $no($id, basename($path), 'no image editor could open it: ' . $editor->get_error_message());
        }

        $size      = $editor->get_size();
        $old_w     = (int) ($size['width'] ?? 0);
        $old_h     = (int) ($size['height'] ?? 0);
        $resized   = false;

        if ($old_w > $max_width) {
            $res = $editor->resize($max_width, null, false);
            if (is_wp_error($res)) {
                return $no($id, basename($path), 'could not resize: ' . $res->get_error_message());
            }
            $resized = true;
        }
        $editor->set_quality($quality);

        /*
         * A temp file NEXT TO the original, not in the system temp directory:
         * rename() is only atomic within one filesystem, and uploads is
         * routinely a different mount from /tmp. The name carries the right
         * extension because some editors choose their format from it.
         */
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $tmp = dirname($path) . '/kh-compress-' . uniqid('', false) . ($ext !== '' ? '.' . $ext : '');

        $saved = $editor->save($tmp, $mime);
        if (is_wp_error($saved)) {
            @unlink($tmp);
            return $no($id, basename($path), 'could not write the compressed copy: ' . $saved->get_error_message());
        }
        $written = (string) ($saved['path'] ?? $tmp);
        if (!is_file($written)) {
            return $no($id, basename($path), 'the image editor reported success but wrote no file');
        }

        $after = (int) filesize($written);

        if (!self::is_worth_keeping($before, $after)) {
            @unlink($written);
            return $no(
                $id,
                basename($path),
                'left alone: re-encoding made it ' . ($after >= $before ? 'no smaller' : 'unreadable')
                    . ' (' . $before . ' bytes before, ' . $after . ' after), so the original was kept exactly as it was'
            );
        }

        /*
         * Rename over the original. Windows refuses rename() onto an existing
         * file, and the rig runs on Windows, so a copy is the documented
         * fallback — same end state, one extra read.
         */
        if (!@rename($written, $path)) {
            if (!@copy($written, $path)) {
                @unlink($written);
                return $no($id, basename($path), 'could not put the smaller file in place — the original is untouched');
            }
            @unlink($written);
        }

        $new_w = $resized ? (int) ($saved['width'] ?? $max_width) : $old_w;
        $new_h = $resized ? (int) ($saved['height'] ?? 0) : $old_h;

        $meta = wp_get_attachment_metadata($id);
        if (is_array($meta)) {
            $meta['width']    = $new_w > 0 ? $new_w : ($meta['width'] ?? 0);
            $meta['height']   = $new_h > 0 ? $new_h : ($meta['height'] ?? 0);
            $meta['filesize'] = (int) filesize($path);
            wp_update_attachment_metadata($id, $meta);
        }

        return ['ok' => true, 'row' => [
            'id'            => $id,
            'file'          => basename($path),
            'bytes_before'  => $before,
            'bytes_after'   => (int) filesize($path),
            'saved_bytes'   => $before - (int) filesize($path),
            'saved_percent' => $before > 0 ? (int) round((($before - (int) filesize($path)) / $before) * 100) : 0,
            'width_before'  => $old_w,
            'width_after'   => $new_w,
            'height_before' => $old_h,
            'height_after'  => $new_h,
            'resized'       => $resized,
            'quality'       => $quality,
        ]];
    }

    /**
     * Delete database junk. The one tool with no undo at all.
     *
     * Two guards in front of it, both from the tuned list:
     *
     *   1. db_cleanup_preview must have run (within the last
     *      Keelhob_Tools_Sitecare::PREVIEW_WINDOW seconds). The owner has to
     *      have seen a number before anything is deleted, and the agent has to
     *      have shown them one.
     *   2. Every result repeats, in words, that this cannot be undone and that
     *      a host backup is the only way back.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function db_cleanup(array $args): array
    {
        $previewed = get_transient(Keelhob_Tools_Sitecare::PREVIEW_MARKER);
        if (empty($previewed)) {
            return [
                'success' => false,
                'error'   => 'Refused: run db_cleanup_preview first. Nothing was deleted.',
                'what_to_do' => 'Call db_cleanup_preview, show the owner the counts it returns, tell them this CANNOT be undone and that they should take a host backup, and only call db_cleanup once they have said yes. The preview unlocks this tool for '
                    . (int) round(Keelhob_Tools_Sitecare::PREVIEW_WINDOW / 60) . ' minutes.',
                'deleted' => 0,
                'count'   => 0,
            ];
        }

        $chosen = self::cleanup_items($args['items'] ?? null);
        if ($chosen['unknown'] !== []) {
            return [
                'success' => false,
                'error'   => 'Refused: unknown item(s) ' . implode(', ', $chosen['unknown'])
                    . '. Nothing was deleted, because a typo in this list would have deleted the wrong thing.',
                'allowed' => self::CLEANUP_ITEMS,
                'deleted' => 0,
                'count'   => 0,
            ];
        }
        if ($chosen['items'] === []) {
            return ['success' => false, 'error' => 'Refused: items was empty after validation. Nothing was deleted.', 'allowed' => self::CLEANUP_ITEMS, 'deleted' => 0, 'count' => 0];
        }

        $keep    = self::clamp_keep_revisions($args['keep_revisions'] ?? null);
        $before  = Keelhob_Tools_Sitecare::count_junk();
        $removed = [];
        $capped  = [];

        foreach ($chosen['items'] as $item) {
            switch ($item) {
                case 'revisions':
                    $r = self::delete_revisions($keep);
                    $removed['revisions'] = $r['deleted'];
                    if ($r['capped']) {
                        $capped[] = 'revisions (' . self::CAP_REVISIONS . ' per call)';
                    }
                    break;

                case 'auto_drafts':
                    $r = self::delete_old_posts('auto-draft', self::CAP_AUTO_DRAFTS);
                    $removed['auto_drafts'] = $r['deleted'];
                    if ($r['capped']) {
                        $capped[] = 'auto_drafts (' . self::CAP_AUTO_DRAFTS . ' per call)';
                    }
                    break;

                case 'trash':
                    $r = self::delete_old_posts('trash', self::CAP_TRASH);
                    $removed['trash'] = $r['deleted'];
                    if ($r['capped']) {
                        $capped[] = 'trash (' . self::CAP_TRASH . ' per call)';
                    }
                    break;

                case 'spam_comments':
                case 'trash_comments':
                    // One shared budget: the cap in the tuned list is 2000
                    // comments per call, not 2000 of each.
                    $budget = self::CAP_COMMENTS - (int) ($removed['spam_comments'] ?? 0) - (int) ($removed['trash_comments'] ?? 0);
                    $status = $item === 'spam_comments' ? 'spam' : 'trash';
                    $r      = self::delete_comments($status, max(0, $budget));
                    $removed[$item] = $r['deleted'];
                    if ($r['capped']) {
                        $capped[] = 'comments (' . self::CAP_COMMENTS . ' per call, spam and trash together)';
                    }
                    break;

                case 'expired_transients':
                    delete_expired_transients(true);
                    // Counted by difference: delete_expired_transients() reports
                    // nothing, and inventing a number would be worse than
                    // measuring one.
                    $after_t = Keelhob_Tools_Sitecare::count_junk();
                    $removed['expired_transients'] = max(0, (int) $before['expired_transients'] - (int) $after_t['expired_transients']);
                    break;

                case 'orphan_postmeta':
                    $removed['orphan_postmeta'] = self::delete_orphan_postmeta();
                    break;
            }
        }

        $after = Keelhob_Tools_Sitecare::count_junk();
        $total = array_sum(array_map('intval', $removed));

        return [
            'success'        => true,
            'count'          => $total,
            'deleted'        => $total,
            'removed'        => $removed,
            'remaining'      => $after,
            'before'         => $before,
            'cleaned'        => $chosen['items'],
            'keep_revisions' => $keep,
            'capped'         => $capped,
            'more_to_do'     => $capped !== [],
            'note'           => 'This CANNOT be undone — not by Keelhob and not by WordPress. '
                . $total . ' row(s) were deleted; the remaining counts are above. '
                . ($capped !== [] ? 'Some kinds hit their per-call cap (' . implode('; ', $capped) . '), so run db_cleanup again to continue. ' : '')
                . 'Thumbnails, revisions kept and published content were not touched.',
        ];
    }

    /**
     * Revisions, keeping the newest $keep per parent.
     *
     * wp_delete_post_revision() rather than a DELETE: it fires the hooks that
     * clear the revision's own meta and caches, and this is not the place to
     * find out what else listens.
     *
     * @return array{deleted:int, capped:bool}
     */
    private static function delete_revisions(int $keep): array
    {
        global $wpdb;

        // ORDER BY post_parent, ID DESC gives the pure decider exactly what it
        // documents: newest first within each parent.
        $rows = $wpdb->get_results(
            "SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type = 'revision' ORDER BY post_parent ASC, ID DESC",
            ARRAY_A
        );

        $shaped = [];
        foreach ((is_array($rows) ? $rows : []) as $row) {
            $shaped[] = ['id' => (int) ($row['ID'] ?? 0), 'parent' => (int) ($row['post_parent'] ?? 0)];
        }

        $plan    = self::revisions_to_delete($shaped, $keep, self::CAP_REVISIONS);
        $deleted = 0;
        foreach ($plan['delete'] as $id) {
            if (wp_delete_post_revision($id)) {
                $deleted++;
            }
        }
        return ['deleted' => $deleted, 'capped' => $plan['capped']];
    }

    /**
     * Auto-drafts or trashed posts, but only ones older than a week.
     *
     * @return array{deleted:int, capped:bool}
     */
    private static function delete_old_posts(string $status, int $cap): array
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', time() - (self::OLDER_THAN_DAYS * 86400));
        $ids    = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND post_modified_gmt < %s ORDER BY ID ASC LIMIT %d",
            $status,
            $cutoff,
            $cap + 1
        ));

        $ids    = array_map('intval', is_array($ids) ? $ids : []);
        $capped = count($ids) > $cap;
        $ids    = array_slice($ids, 0, $cap);

        $deleted = 0;
        foreach ($ids as $id) {
            if (wp_delete_post($id, true)) {
                $deleted++;
            }
        }
        return ['deleted' => $deleted, 'capped' => $capped];
    }

    /**
     * Spam or trashed comments.
     *
     * @return array{deleted:int, capped:bool}
     */
    private static function delete_comments(string $status, int $budget): array
    {
        global $wpdb;

        if ($budget <= 0) {
            return ['deleted' => 0, 'capped' => true];
        }

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s ORDER BY comment_ID ASC LIMIT %d",
            $status,
            $budget + 1
        ));

        $ids    = array_map('intval', is_array($ids) ? $ids : []);
        $capped = count($ids) > $budget;
        $ids    = array_slice($ids, 0, $budget);

        $deleted = 0;
        foreach ($ids as $id) {
            if (wp_delete_comment($id, true)) {
                $deleted++;
            }
        }
        return ['deleted' => $deleted, 'capped' => $capped];
    }

    /**
     * Post meta whose post no longer exists.
     *
     * `post_id NOT IN (SELECT ID …)` rather than a LEFT JOIN, the same form
     * db_cleanup_preview counts with: it is the one shape that works on MySQL
     * and on an SQLite-backed install, and the rig is SQLite. A DELETE with a
     * JOIN is MySQL-only syntax and would simply error there.
     */
    private static function delete_orphan_postmeta(): int
    {
        global $wpdb;

        $rows = $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})");
        return is_numeric($rows) ? (int) $rows : 0;
    }

    /**
     * Fix or remove one link on one post, content and Elementor layout both.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function link_fix(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        $old     = is_string($args['old_url'] ?? null) ? trim((string) $args['old_url']) : '';
        $new     = is_string($args['new_url'] ?? null) ? trim((string) $args['new_url']) : '';

        if ($post_id <= 0) {
            return ['success' => false, 'error' => 'Pass post_id — the page or post to fix. broken_links reports it with every broken link.'];
        }
        if (!self::valid_old_url($old)) {
            return ['success' => false, 'error' => 'old_url must be the exact address to replace, with no spaces or quotes in it.'];
        }
        if (!self::valid_new_url($new)) {
            return ['success' => false, 'error' => 'new_url must be an http:// or https:// address, or a root-relative path starting with "/", or empty to remove the link and keep its text. javascript:, data:, mailto: and protocol-relative (//) addresses are refused.'];
        }
        if ($new !== '' && $new === $old) {
            return ['success' => false, 'error' => 'new_url is the same as old_url, so there is nothing to change.'];
        }

        $post = get_post($post_id);
        if (!$post) {
            return ['success' => false, 'error' => 'No post or page with ID ' . $post_id . '.'];
        }

        $content   = (string) $post->post_content;
        $elementor = (string) get_post_meta($post_id, '_elementor_data', true);

        $in_content   = self::fix_link_in_content($content, $old, $new);
        $in_elementor = self::fix_link_in_elementor($elementor, $old, $new);
        $changes      = $in_content['changes'] + $in_elementor['changes'];

        if ($changes === 0) {
            return [
                'success' => false,
                'error'   => 'That link is not on post ' . $post_id . ' — neither in its content nor in its Elementor layout. Nothing was changed.',
                'looked_in' => $elementor !== '' ? ['post_content', '_elementor_data'] : ['post_content'],
                'hint'    => 'Run broken_links again for the current address: a link that has already been fixed, or that differs by a trailing slash or by http vs https, will not match.',
            ];
        }

        // Back up BEFORE writing. Keelhob_Undo snapshots post_content off the
        // core hooks, so the post half is covered as long as a scope is open —
        // over MCP nothing opens one, so one is opened here.
        $own_scope = !Keelhob_Undo::recording();
        if ($own_scope) {
            Keelhob_Undo::start(self::UNDO_SCOPE_LINKS);
        }

        $backed_up = [];
        try {
            // A revision as well as the undo snapshot: a revision survives the
            // undo window and is restorable from the editor by the owner, with
            // no agent involved.
            if (function_exists('wp_save_post_revision')) {
                wp_save_post_revision($post_id);
                $backed_up[] = 'a post revision';
            }

            if ($in_content['changes'] > 0) {
                $res = wp_update_post(['ID' => $post_id, 'post_content' => $in_content['content']], true);
                if (is_wp_error($res)) {
                    return ['success' => false, 'error' => 'Could not save the post: ' . $res->get_error_message()];
                }
                $backed_up[] = 'the previous post_content (undo)';
            }

            if ($in_elementor['changes'] > 0) {
                /*
                 * WordPress has no undo for post meta, and a revision does not
                 * carry it either — so the previous layout is kept in one named
                 * meta row. Without it, "backed up first" would be false for
                 * exactly the case this tool exists to handle: an Elementor
                 * site, which is what Keelhob builds.
                 */
                /*
                 * wp_slash() BOTH, and this was found by running it rather
                 * than by reading it. update_post_meta() expects SLASHED data
                 * and unslashes what it is given, so writing Elementor's JSON
                 * through it raw silently eats every backslash in the
                 * document: `https:\/\/x` becomes `https://x` and, worse,
                 * every `\"` inside an HTML widget becomes a bare `"`, which
                 * leaves the meta value as a string that is no longer valid
                 * JSON at all. Elementor then renders nothing. Elementor's own
                 * save path slashes it for exactly this reason.
                 */
                update_post_meta($post_id, '_keelhob_elementor_backup', wp_slash($elementor));
                update_post_meta($post_id, '_elementor_data', wp_slash($in_elementor['json']));
                $backed_up[] = 'the previous _elementor_data (in _keelhob_elementor_backup)';

                // Elementor serves a cached copy of the rendered page.
                if (did_action('elementor/loaded') && class_exists('\\Elementor\\Plugin')) {
                    delete_post_meta($post_id, '_elementor_css');
                }
            }
        } finally {
            if ($own_scope) {
                Keelhob_Undo::finish();
            }
        }

        return [
            'success'            => true,
            'count'              => $changes,
            'post_id'            => $post_id,
            'title'              => (string) $post->post_title,
            'action'             => $new === '' ? 'removed the link and kept its text' : 'replaced the link',
            'old_url'            => $old,
            'new_url'            => $new,
            'content_changes'    => $in_content['changes'],
            'elementor_changes'  => $in_elementor['changes'],
            'backed_up'          => $backed_up,
            'note'               => $in_elementor['changes'] > 0
                ? 'The Elementor layout was edited as a string, both the plain and the \\/-escaped form of the address, and its previous value is in the post meta _keelhob_elementor_backup. Load the page to confirm it still renders.'
                : 'Only post_content needed changing. Load the page to confirm it still renders.',
        ];
    }

    /**
     * Alt text on up to fifty attachments.
     *
     * The gentlest tool in this file: it writes one meta value per image and
     * nothing it writes can break a page. It is still in the sensitive group,
     * because it writes, and because being able to rewrite every alt text on a
     * site is a thing an owner should have said yes to.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function media_set_alt(array $args): array
    {
        $parsed = self::alt_items($args['items'] ?? null);

        if ($parsed['items'] === []) {
            return [
                'success'  => false,
                'error'    => 'No usable items. Pass items as a list of {id, alt}, e.g. [{"id": 12, "alt": "A red kayak on a grey beach"}].',
                'rejected' => $parsed['rejected'],
            ];
        }

        $updated = [];
        $skipped = [];

        foreach ($parsed['items'] as $item) {
            $id   = $item['id'];
            $post = get_post($id);
            if (!$post || $post->post_type !== 'attachment') {
                $skipped[] = ['id' => $id, 'reason' => 'not an attachment'];
                continue;
            }
            $alt = sanitize_text_field($item['alt']);
            $was = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
            if ($alt === $was) {
                $skipped[] = ['id' => $id, 'reason' => 'already had exactly that alt text'];
                continue;
            }
            // Slashed, the same way core's own media screen writes this
            // field: update_post_meta() unslashes, so alt text containing a
            // quote or a backslash would otherwise come back mangled.
            update_post_meta($id, '_wp_attachment_image_alt', wp_slash($alt));
            $updated[] = [
                'id'   => $id,
                'file' => basename((string) get_attached_file($id)),
                'was'  => $was,
                'now'  => $alt,
            ];
        }

        foreach ($parsed['rejected'] as $bad) {
            $skipped[] = ['id' => 0, 'reason' => $bad];
        }
        if ($parsed['over_cap'] > 0) {
            $skipped[] = ['id' => 0, 'reason' => $parsed['over_cap'] . ' more item(s) were not set: this tool does ' . self::ALT_PER_CALL . ' per call. Call it again with the rest.'];
        }

        return [
            'success'       => $updated !== [],
            'count'         => count($updated),
            'updated'       => $updated,
            'skipped'       => $skipped,
            'skipped_count' => count($skipped),
            'note'          => 'Alt text is stored in the _wp_attachment_image_alt meta, which is what themes and screen readers read. Images already carrying the same text were left alone rather than rewritten.',
        ];
    }

    /**
     * Install a plugin from WordPress.org, and optionally switch it on.
     *
     * WordPress.org only, by slug, and nothing else — no URL, no uploaded ZIP,
     * no path. `plugins_api` resolves the slug to a download_link that is then
     * unpacked into wp-content/plugins, so the slug is the whole trust boundary
     * and it is validated to `[a-z0-9-]` before it is used.
     *
     * Activation goes through the back-up-then-check path every risky write in
     * this plugin shares: activate inside a try/catch (a plugin that fatals on
     * activation would otherwise take the MCP request down with it), then load
     * the homepage as a logged-out visitor, and if that broke, switch the
     * plugin off again and say `rolled_back: true`.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function plugin_install(array $args): array
    {
        $slug = self::wporg_slug($args['slug'] ?? null);
        if ($slug === '') {
            return [
                'success' => false,
                'error'   => 'slug must be a WordPress.org directory slug — lowercase letters, numbers and hyphens, e.g. "classic-editor". A URL, a .zip, a path or an uploaded file is refused: this tool installs from the official directory and nothing else.',
            ];
        }
        $activate = !empty($args['activate']);

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $existing = self::installed_file_for($slug);
        if ($existing !== '') {
            return [
                'success'   => false,
                'error'     => 'Refused: ' . $slug . ' is already installed as ' . $existing . '.',
                'installed' => true,
                'plugin'    => $existing,
                'active'    => is_plugin_active($existing),
                'what_to_do' => is_plugin_active($existing)
                    ? 'It is already active. Nothing to do.'
                    : 'Use manage_plugin with action=activate and plugin=' . $existing . ' to switch it on, or manage_updates to update it.',
            ];
        }

        // The filesystem must be genuinely writable. Without this, the upgrader
        // asks for FTP credentials through a form nobody is looking at and the
        // request hangs or dies with no explanation.
        if (!WP_Filesystem()) {
            return ['success' => false, 'error' => 'WordPress cannot write to the filesystem on this host (WP_Filesystem() failed), so nothing was installed. This usually means the plugins folder is not writable by the web server, or the host requires FTP credentials.'];
        }

        $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
        if (is_wp_error($api)) {
            return ['success' => false, 'error' => 'WordPress.org does not have a plugin with the slug "' . $slug . '": ' . $api->get_error_message()];
        }
        $link = (string) ($api->download_link ?? '');
        if ($link === '') {
            return ['success' => false, 'error' => 'WordPress.org returned no download for "' . $slug . '".'];
        }

        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result   = $upgrader->install($link);

        if (is_wp_error($result)) {
            return ['success' => false, 'error' => 'Install failed: ' . $result->get_error_message()];
        }
        if ($skin->get_errors()->has_errors()) {
            return ['success' => false, 'error' => 'Install failed: ' . implode(' ', (array) $skin->get_error_messages())];
        }

        $file = self::installed_file_for($slug);
        if ($file === '') {
            return ['success' => false, 'error' => 'The download unpacked but no plugin file was found under a "' . $slug . '" folder. Nothing was activated.'];
        }

        $out = [
            'success'   => true,
            'count'     => 1,
            'installed' => $slug,
            'plugin'    => $file,
            'name'      => (string) ($api->name ?? $slug),
            'version'   => (string) ($api->version ?? ''),
            'active'    => false,
            'note'      => 'Installed from WordPress.org and left SWITCHED OFF. Nothing on the site changed yet.',
        ];

        if (!$activate) {
            $out['what_to_do'] = 'Call plugin_install again with activate=true, or manage_plugin action=activate plugin=' . $file . ', when the owner wants it on.';
            return $out;
        }

        return self::activate_with_check($out, $file);
    }

    /**
     * Switch a freshly installed plugin on, and undo that if the site breaks.
     *
     * The same decision tree as Keelhob_Tools_Site::manage_plugin's activate
     * branch — see the CONTRACT in class-health.php. guard() runs the revert at
     * most once as its control probe, so this method has to decide what that
     * means afterwards:
     *
     *   front_end_check FAILED -> deactivating fixed the site, so it stays off
     *                             and `rolled_back` is true
     *   front_end_check passed -> the revert ran and did NOT help, so this
     *                             plugin was innocent and is switched back on
     *
     * @param array<string, mixed> $out
     * @return array<string, mixed>
     */
    private static function activate_with_check(array $out, string $file): array
    {
        // Keelhob must never deactivate itself. The revert closure below is the
        // one place that could, and if it ever did the owner would be left with
        // no MCP endpoint and therefore no way to put it back — every way of
        // undoing it is HTTP through this plugin.
        if (self::is_self($file)) {
            $out['active'] = is_plugin_active($file);
            $out['note']   = 'Keelhob does not switch itself on or off. Nothing was changed.';
            return $out;
        }

        try {
            $res = activate_plugin($file);
        } catch (Throwable $e) {
            // A plugin that fatals in its activation hook would otherwise take
            // the whole MCP request with it, and the agent would see a dead
            // connection rather than a reason.
            deactivate_plugins($file, true);
            $out['active']      = false;
            $out['rolled_back'] = true;
            $out['error']       = 'The plugin crashed while activating (' . $e->getMessage() . '), so it was switched off again. It is installed but inactive.';
            $out['note']        = 'Installed, activation reverted.';
            return $out;
        }

        if (is_wp_error($res)) {
            $out['active'] = false;
            $out['error']  = 'Installed, but it refused to activate: ' . $res->get_error_message();
            return $out;
        }

        $out['active'] = true;
        $out['note']   = 'Installed from WordPress.org and switched on.';

        $out = Keelhob_Health::guard($out, 'activating ' . $file, static function () use ($file): void {
            deactivate_plugins($file, true);
        });

        if (($out['front_end_check'] ?? null) === 'FAILED') {
            $out['active']      = false;
            $out['rolled_back'] = true;
            $out['note']        = 'Installed, but switching it on broke the front page for logged-out visitors — so it was switched off again. It is still installed and can be activated manually once the cause is known.';
        } elseif (($out['front_end_check'] ?? null) === 'passed') {
            // The control probe ran and did not help: this plugin was not the
            // cause, so leaving it reverted would punish an innocent
            // activation. Same resolution as manage_plugin's activate branch.
            activate_plugin($file);
            $out['active']      = true;
            $out['rolled_back'] = false;
        } else {
            $out['rolled_back'] = false;
        }

        return $out;
    }

    /** Whether a plugin file is Keelhob itself. */
    private static function is_self(string $file): bool
    {
        if (defined('KEELHOB_BASENAME') && $file === (string) constant('KEELHOB_BASENAME')) {
            return true;
        }
        $dir = strtolower(explode('/', str_replace('\\', '/', $file))[0]);
        return $dir === 'keelhob';
    }

    /**
     * The installed plugin file under a given slug folder, or ''.
     *
     * Matched on the FOLDER name, which is what a WordPress.org slug maps to.
     * A slug is never concatenated into a path here: get_plugins() supplies the
     * real file names and this only picks one of them.
     */
    private static function installed_file_for(string $slug): string
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all = get_plugins();
        foreach ((is_array($all) ? $all : []) as $file => $data) {
            $parts = explode('/', str_replace('\\', '/', (string) $file));
            if (count($parts) > 1 && strtolower($parts[0]) === $slug) {
                return (string) $file;
            }
        }
        return '';
    }
}
