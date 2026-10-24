<?php
/**
 * One-step undo for content changes made during a chat turn.
 *
 * The honest scope: this covers **posts** — every post type, including pages and
 * products — created, edited, or trashed while recording is on. That is where
 * "wait, put that back" actually happens, and it is the one thing WordPress
 * gives us reliable hooks for.
 *
 * It also covers **files**, but only the ones a tool explicitly hands it
 * through record_file(): an uploaded image about to be resized and written
 * over. There is no hook that can notice an arbitrary file write, so this half
 * is opt-in by the caller rather than automatic like posts. See record_file().
 *
 * It deliberately does NOT cover options, users, plugin state, live code
 * snippets, or WooCommerce settings. A half-working undo is worse than none,
 * because the owner trusts it and finds out later — so /undo says plainly what
 * it restored and what it could not.
 *
 * Recording works off WordPress core hooks rather than the tool layer, so a
 * change made through execute_php is captured just the same as one made through
 * update_post.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Undo
{
    const OPTION = 'keelhob_undo_stack';

    /** How many undoable turns to keep per scope (a scope is usually a chat id). */
    const KEEP = 5;

    /** Where file backups go, under uploads/. */
    const FILES_SUBDIR = 'keelhob-undo';

    /**
     * Caps on the file half. Per file, because a 400 MB video is not something
     * to copy inside a web request; and in total, because a backup store that
     * only grows eventually fills the account it is meant to protect.
     */
    const FILE_MAX_BYTES        = 16 * 1024 * 1024;
    const FILES_MAX_TOTAL_BYTES = 128 * 1024 * 1024;

    /** Post types whose churn is noise, not a change the owner made. */
    const IGNORED_TYPES = ['revision', 'nav_menu_item', 'oembed_cache', 'customize_changeset', 'attachment'];

    /** @var string|null the scope currently recording, or null */
    private static $scope = null;

    /** @var array<int, array> buffered entries for the current recording */
    private static $buffer = [];

    /** @var bool guards against double-hooking within one request */
    private static $hooked = false;

    /**
     * Begin recording. Idempotent per request: the hooks attach once, and the
     * buffer resets so two turns in one request cannot bleed together.
     */
    public static function start(string $scope): void
    {
        self::$scope  = $scope;
        self::$buffer = [];

        if (self::$hooked) {
            return;
        }
        // Priority 1 for pre_post_update: snapshot before anything else can
        // alter $data on the way through.
        add_action('pre_post_update', [self::class, 'on_pre_update'], 1, 2);
        add_action('wp_insert_post', [self::class, 'on_insert'], 99, 3);
        add_action('wp_trash_post', [self::class, 'on_trash'], 1, 1);
        self::$hooked = true;
    }

    /** Stop recording and push the buffer onto the scope's stack. */
    public static function finish(): int
    {
        $scope  = self::$scope;
        $buffer = self::$buffer;
        self::$scope  = null;
        self::$buffer = [];

        if ($scope === null || empty($buffer)) {
            return 0;
        }

        $stack = self::stack();
        $stack[$scope] = $stack[$scope] ?? [];
        $stack[$scope][] = ['at' => current_time('mysql'), 'entries' => array_values($buffer)];
        if (count($stack[$scope]) > self::KEEP) {
            self::drop_backups(array_slice($stack[$scope], 0, count($stack[$scope]) - self::KEEP));
        }
        $stack[$scope] = array_slice($stack[$scope], -self::KEEP);
        update_option(self::OPTION, $stack);

        return count($buffer);
    }

    public static function recording(): bool
    {
        return self::$scope !== null;
    }

    /** @return array<string, array<int, array>> */
    public static function stack(): array
    {
        $stack = get_option(self::OPTION, []);
        return is_array($stack) ? $stack : [];
    }

    /** How many undoable turns this scope has banked. */
    public static function depth(string $scope): int
    {
        return count(self::stack()[$scope] ?? []);
    }

    public static function clear(string $scope): void
    {
        $stack = self::stack();
        unset($stack[$scope]);
        update_option(self::OPTION, $stack);
    }

    // ---- recording hooks --------------------------------------------------

    /**
     * Snapshot a post's editable fields before an update lands.
     *
     * Only the first snapshot per post is kept: if a turn edits the same post
     * three times, undo should return it to how it looked before the turn, not
     * to how it looked between the second and third edit.
     */
    public static function on_pre_update($post_id, $data = null): void
    {
        if (self::$scope === null) {
            return;
        }
        $post_id = (int) $post_id;
        if (isset(self::$buffer['post:' . $post_id])) {
            return;
        }
        $post = get_post($post_id);
        if (!$post || in_array($post->post_type, self::IGNORED_TYPES, true)) {
            return;
        }
        self::$buffer['post:' . $post_id] = [
            'kind'     => 'updated',
            'id'       => $post_id,
            'type'     => $post->post_type,
            'title'    => $post->post_title,
            'snapshot' => [
                'post_title'   => $post->post_title,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'post_status'  => $post->post_status,
                'post_name'    => $post->post_name,
            ],
        ];
    }

    public static function on_insert($post_id, $post = null, $update = null): void
    {
        if (self::$scope === null || $update) {
            return;
        }
        $post_id = (int) $post_id;
        if (isset(self::$buffer['post:' . $post_id])) {
            return;
        }
        $type = is_object($post) ? (string) $post->post_type : (string) get_post_type($post_id);
        if (in_array($type, self::IGNORED_TYPES, true)) {
            return;
        }
        // auto-draft is WordPress reserving an ID, not the agent creating a post.
        if (is_object($post) && $post->post_status === 'auto-draft') {
            return;
        }
        self::$buffer['post:' . $post_id] = [
            'kind'  => 'created',
            'id'    => $post_id,
            'type'  => $type,
            'title' => is_object($post) ? (string) $post->post_title : '',
        ];
    }

    public static function on_trash($post_id): void
    {
        if (self::$scope === null) {
            return;
        }
        $post_id = (int) $post_id;
        $post    = get_post($post_id);
        if (!$post || in_array($post->post_type, self::IGNORED_TYPES, true)) {
            return;
        }
        // A trash overrides an earlier snapshot of the same post: untrashing is
        // the move that puts it back, and it restores the content with it.
        self::$buffer['post:' . $post_id] = [
            'kind'  => 'trashed',
            'id'    => $post_id,
            'type'  => $post->post_type,
            'title' => $post->post_title,
        ];
    }

    // ---- files ------------------------------------------------------------

    /**
     * Bank a copy of a file before something writes over it.
     *
     * Opt-in, unlike the post hooks above, because WordPress has no
     * "something is about to overwrite this file" hook to listen to. The
     * caller — `image_compress` is the first — calls this BEFORE it writes,
     * and treats `false` as "there is no undo for this, so do not do it".
     * That order matters: a backup taken after the write backs up the damage.
     *
     * The attachment id and its metadata are recorded with the bytes because
     * putting an image file back is only half of putting an image back. The
     * metadata carries width, height and filesize, and WordPress serves those
     * numbers to every template and every srcset; restoring a 4000px original
     * under metadata that still says 2048px gives you a site that is wrong in
     * a way nobody can see until a layout breaks.
     *
     * @param int $attachment_id 0 when the file is not an attachment.
     * @return bool true when a backup exists and undo can put this file back.
     */
    public static function record_file(string $path, int $attachment_id = 0): bool
    {
        if (self::$scope === null) {
            return false;
        }
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return false;
        }

        $key = 'file:' . $path;
        if (isset(self::$buffer[$key])) {
            // First snapshot wins, same rule as posts: undo returns the file to
            // how it was before the turn, not to how it was mid-turn.
            return true;
        }

        $size = (int) filesize($path);
        if ($size > self::FILE_MAX_BYTES) {
            return false;
        }

        $dir = self::files_dir();
        if ($dir === '') {
            return false;
        }
        if (self::store_bytes() + $size > self::FILES_MAX_TOTAL_BYTES) {
            return false;
        }

        // Unique per entry, not per path: two turns may each hold their own
        // copy of the same file, and a deterministic name would have the newer
        // turn overwrite the older one's only way back.
        $backup = $dir . '/' . hash('sha256', $path) . '-' . uniqid('', true) . '.bak';
        if (!@copy($path, $backup)) {
            return false;
        }

        $meta = [];
        if ($attachment_id > 0 && function_exists('wp_get_attachment_metadata')) {
            $m = wp_get_attachment_metadata($attachment_id);
            if (is_array($m)) {
                $meta = $m;
            }
        }

        self::$buffer[$key] = [
            'kind'          => 'file',
            'path'          => $path,
            'backup'        => $backup,
            'bytes'         => $size,
            'attachment_id' => $attachment_id,
            'meta'          => $meta,
            'title'         => basename($path),
        ];
        return true;
    }

    /**
     * Where file backups live: inside uploads, so it is writable on every host
     * that can accept an upload at all, and closed off to the web.
     *
     * Filterable because the unit suite has no uploads directory, and because
     * a host with a read-only uploads mount needs somewhere else to put them.
     */
    public static function files_dir(): string
    {
        $base = '';
        if (function_exists('wp_get_upload_dir')) {
            $up   = wp_get_upload_dir();
            $base = (string) ($up['basedir'] ?? '');
        }
        if ($base === '' && defined('WP_CONTENT_DIR')) {
            $base = rtrim((string) constant('WP_CONTENT_DIR'), '/\\') . '/uploads';
        }
        // The filter is applied even when there is no uploads directory to
        // build a default from, so a host (or the unit suite) can supply the
        // whole path rather than having it refused before it gets a say.
        $default = $base === '' ? '' : $base . '/' . self::FILES_SUBDIR;
        $dir     = (string) apply_filters('keelhob_undo_files_dir', $default);
        if ($dir === '') {
            return '';
        }

        if (!is_dir($dir)) {
            if (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($dir);
            } else {
                @mkdir($dir, 0755, true);
            }
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return '';
        }

        // A backup of a media file is a copy of the owner's content sitting at
        // a guessable path. Both guards, because hosts honour different ones.
        if (!is_file($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Deny from all\n");
        }

        return $dir;
    }

    /** Total size of the file-backup store. */
    private static function store_bytes(): int
    {
        $total = 0;
        foreach ((glob(self::files_dir() . '/*.bak') ?: []) as $file) {
            $total += (int) filesize($file);
        }
        return $total;
    }

    /**
     * Delete the file copies belonging to turns that fell out of the window.
     *
     * Without this, every backed-up file past the fifth turn stays on disk
     * forever with nothing left that could ever restore it — a backup store
     * that only grows is how a plugin fills someone's hosting account.
     *
     * @param array<int, array> $turns turns being discarded
     */
    private static function drop_backups(array $turns): void
    {
        foreach ($turns as $turn) {
            foreach ((array) ($turn['entries'] ?? []) as $entry) {
                if (($entry['kind'] ?? '') === 'file' && !empty($entry['backup']) && is_file((string) $entry['backup'])) {
                    @unlink((string) $entry['backup']);
                }
            }
        }
    }

    /**
     * Put one file back, and its attachment metadata with it.
     *
     * @param array<string, mixed> $entry
     * @return array{ok:bool, message:string}
     */
    private static function restore_file(array $entry): array
    {
        $path   = (string) ($entry['path'] ?? '');
        $backup = (string) ($entry['backup'] ?? '');
        $label  = 'file "' . basename($path) . '"';

        if ($path === '' || $backup === '' || !is_file($backup)) {
            return ['ok' => false, 'message' => $label . ' (its backup copy is no longer there)'];
        }
        if (!@copy($backup, $path)) {
            return ['ok' => false, 'message' => $label . ' (could not be written back — check file permissions)'];
        }

        $id   = (int) ($entry['attachment_id'] ?? 0);
        $meta = (array) ($entry['meta'] ?? []);
        if ($id > 0 && $meta !== [] && function_exists('wp_update_attachment_metadata')) {
            wp_update_attachment_metadata($id, $meta);
            $label .= ' and its image sizes';
        }

        // The copy has done its job, and leaving it would leak the bytes.
        @unlink($backup);

        return ['ok' => true, 'message' => 'restored ' . $label];
    }

    // ---- reverting --------------------------------------------------------

    /**
     * Undo the most recent recorded turn for a scope.
     *
     * @return array{success: bool, error?: string, at?: string, restored: array<int, string>, failed: array<int, string>}
     */
    public static function revert(string $scope): array
    {
        $stack = self::stack();
        $turns = $stack[$scope] ?? [];
        if (empty($turns)) {
            return ['success' => false, 'error' => 'Nothing to undo — I have not changed any content in this conversation.', 'restored' => [], 'failed' => []];
        }

        $turn     = array_pop($turns);
        $restored = [];
        $failed   = [];

        // Recording must be off while we revert, or the undo would record
        // itself as the next undoable turn.
        $was = self::$scope;
        self::$scope = null;

        foreach ((array) ($turn['entries'] ?? []) as $entry) {
            // Files first: a file entry has no post id, so it must not reach
            // the "does this post still exist" check below.
            if (($entry['kind'] ?? '') === 'file') {
                $res = self::restore_file((array) $entry);
                if ($res['ok']) {
                    $restored[] = $res['message'];
                } else {
                    $failed[] = $res['message'];
                }
                continue;
            }

            $id    = (int) ($entry['id'] ?? 0);
            $label = ($entry['type'] ?? 'post') . ' #' . $id . (!empty($entry['title']) ? ' "' . $entry['title'] . '"' : '');

            if ($id <= 0 || !get_post($id)) {
                $failed[] = $label . ' (no longer exists)';
                continue;
            }

            switch ($entry['kind'] ?? '') {
                case 'created':
                    if (wp_trash_post($id)) {
                        $restored[] = 'trashed the new ' . $label;
                    } else {
                        $failed[] = $label . ' (could not be trashed)';
                    }
                    break;

                case 'updated':
                    $res = wp_update_post(array_merge(['ID' => $id], (array) ($entry['snapshot'] ?? [])), true);
                    if (is_wp_error($res)) {
                        $failed[] = $label . ' (' . $res->get_error_message() . ')';
                    } else {
                        $restored[] = 'reverted ' . $label;
                    }
                    break;

                case 'trashed':
                    if (wp_untrash_post($id)) {
                        $restored[] = 'restored ' . $label;
                    } else {
                        $failed[] = $label . ' (could not be untrashed)';
                    }
                    break;

                default:
                    $failed[] = $label . ' (unknown change type)';
            }
        }

        self::$scope = $was;

        $stack[$scope] = $turns;
        update_option(self::OPTION, $stack);

        return [
            'success'  => empty($failed),
            'at'       => (string) ($turn['at'] ?? ''),
            'restored' => $restored,
            'failed'   => $failed,
        ];
    }
}
