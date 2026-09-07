<?php
/**
 * Filesystem tools, confined to a configurable base directory (default ABSPATH).
 * read/write/edit/delete/list plus a persistent-sandbox writer.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Filesystem
{
    /**
     * Per-file cap on what gets backed up. Source files an agent legitimately
     * edits — templates, style.css, functions.php — are KB to low-single-digit-MB;
     * 5 MB comfortably covers those (and a generous minified bundle) without
     * doubling disk usage for the media/build assets this feature isn't for.
     */
    private const BACKUP_MAX_FILE_BYTES = 5 * 1024 * 1024;

    /**
     * Cap on the whole backup store, not per file, so it can never fill the
     * disk regardless of how many distinct paths get backed up. 50 MB is ten
     * files at the per-file cap, or thousands of the handful-of-KB source
     * files this is actually for — negligible on any real host.
     */
    private const BACKUP_MAX_TOTAL_BYTES = 50 * 1024 * 1024;

    public static function register(Claudpress_Tool_Registry $r): void
    {
        $r->add('read_file', 'Read a text file (confined to the Claudpress filesystem base, default the WordPress root). Returns file contents.', [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string', 'description' => 'Absolute path or path relative to the WordPress root.']],
            'required' => ['path'],
        ], [self::class, 'read_file'], ['readOnlyHint' => true]);

        $r->add('write_file', 'Create or overwrite a text file (confined to the filesystem base). Parent directories are created as needed. For an existing .php file, prefer edit_file with a targeted old_string/new_string over retyping the whole file here — a full rewrite risks a transcription slip (e.g. an escaped bracket) that a partial edit can\'t introduce. Any .php content is syntax-checked before it touches disk; a broken write is rejected with the parse error instead of being saved, so this can\'t brick a live, always-loaded plugin file.', [
            'type' => 'object',
            'properties' => [
                'path'    => ['type' => 'string'],
                'content' => ['type' => 'string'],
            ],
            'required' => ['path', 'content'],
        ], [self::class, 'write_file'], ['destructiveHint' => true]);

        $r->add('edit_file', 'Edit a file by replacing an exact string. `old_string` must match exactly and uniquely unless replace_all is true. The preferred way to change an existing .php file — a targeted replacement can\'t corrupt the surrounding file the way a full rewrite can. Result is syntax-checked before it touches disk; a broken edit is rejected with the parse error instead of being saved.', [
            'type' => 'object',
            'properties' => [
                'path'        => ['type' => 'string'],
                'old_string'  => ['type' => 'string'],
                'new_string'  => ['type' => 'string'],
                'replace_all' => ['type' => 'boolean', 'default' => false],
            ],
            'required' => ['path', 'old_string', 'new_string'],
        ], [self::class, 'edit_file'], ['destructiveHint' => true]);

        $r->add('delete_file', 'Delete a file (confined to the filesystem base).', [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string']],
            'required' => ['path'],
        ], [self::class, 'delete_file'], ['destructiveHint' => true]);

        $r->add('list_directory', 'List the contents of a directory (confined to the filesystem base). Returns files and subdirectories with sizes.', [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string', 'description' => 'Directory path. Defaults to the WordPress root.']],
        ], [self::class, 'list_directory'], ['readOnlyHint' => true]);

        $r->add('write_sandbox_file', 'Write a PHP file into the Claudpress sandbox (wp-content/claudpress-sandbox). Sandbox files are auto-loaded on every request so custom hooks/functions persist. Content is syntax-checked before it touches disk (rejected on a parse error, nothing written); a file that still fatals at runtime for some other reason is auto-quarantined on the next request so the site stays up. Include the opening <?php tag.', [
            'type' => 'object',
            'properties' => [
                'filename' => ['type' => 'string', 'description' => 'File name, e.g. my-feature.php'],
                'content'  => ['type' => 'string', 'description' => 'Full PHP file contents, including <?php.'],
            ],
            'required' => ['filename', 'content'],
        ], [self::class, 'write_sandbox_file'], ['destructiveHint' => true]);
    }

    /**
     * Reject a .php write/edit before it reaches disk if the resulting content
     * is broken. Core plugin files are require_once'd unconditionally on every
     * boot — unlike sandbox files, nothing catches a problem in them, so a bad
     * write here doesn't fail gracefully, it fatals (or silently no-ops) the
     * whole site on the very next request.
     *
     * Two checks, because they catch different failures:
     *  - Missing/mangled opening tag (e.g. "&lt;?php" from an HTML-escaping
     *    slip while composing the write): PHP treats a file with no real
     *    <?php as one giant literal-text token and raises no parse error at
     *    all — the class inside it simply never gets defined, so this can't
     *    be caught by a parser check and needs its own guard.
     *  - Genuine syntax errors (unbalanced braces, stray tokens, unterminated
     *    strings): token_get_all(..., TOKEN_PARSE) throws a ParseError,
     *    without needing a shell_exec('php -l ...') that many hosts disable.
     *
     * @return string|null Problem description, or null if the content looks safe to write.
     */
    private static function php_syntax_error(string $path, string $content): ?string
    {
        if (!str_ends_with(strtolower($path), '.php')) {
            return null;
        }
        $head = ltrim($content, "\xEF\xBB\xBF \t\r\n");
        if (stripos($head, '<?php') !== 0) {
            return 'File does not start with a real <?php opening tag — got "' . mb_substr($head, 0, 20)
                . '...". PHP would treat this whole file as literal text instead of code (no PHP class or logic'
                . ' would actually run), and it would raise no parse error to warn you. If this looks like'
                . ' "<?php" was HTML-escaped to "&lt;php" while composing the write, fix the source and retry.';
        }
        try {
            token_get_all($content, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return $e->getMessage() . ' on line ' . $e->getLine();
        }
        return null;
    }

    /**
     * The backup store: one hashed file per resolved path, most recent only.
     * Same hardening as the sandbox dir (index.php + .htaccess deny) since a
     * backup can hold the previous contents of a live plugin/theme PHP file —
     * the hashed name means the store gives nothing away even without it.
     */
    private static function backups_dir(): string
    {
        $dir = WP_CONTENT_DIR . '/uploads/claudpress-backups';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
            @file_put_contents($dir . '/.htaccess', "Deny from all\n");
        }
        return $dir;
    }

    /** Deterministic per-path name: writing a new backup for the same path always overwrites this same file, so only the most recent ever exists. */
    private static function backup_path(string $path): string
    {
        return self::backups_dir() . '/' . hash('sha256', $path) . '.bak';
    }

    /** Bytes currently held in the store, optionally excluding the file about to be overwritten so re-backing-up the same path never counts against itself. */
    private static function backups_total_bytes(string $exclude = ''): int
    {
        $total = 0;
        foreach ((glob(self::backups_dir() . '/*.bak') ?: []) as $file) {
            if ($file === $exclude) {
                continue;
            }
            $total += (int) filesize($file);
        }
        return $total;
    }

    /**
     * Save $path's current content before it changes, if it exists and fits
     * the size limits. Returns the backup file path on success, or null when
     * there is nothing usable to revert from — the file didn't exist yet, is
     * over the per-file cap, or the store is already at its total cap. Never
     * errors: a skipped backup means guard() gets no automatic revert for
     * this write, not that the write itself is blocked.
     */
    private static function backup_before_write(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $size = (int) filesize($path);
        if ($size > self::BACKUP_MAX_FILE_BYTES) {
            return null;
        }
        $backup = self::backup_path($path);
        if (self::backups_total_bytes($backup) + $size > self::BACKUP_MAX_TOTAL_BYTES) {
            return null;
        }
        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }
        if (file_put_contents($backup, $content) === false) {
            return null;
        }
        return $backup;
    }

    /** Byte-exact read of a saved backup — file_get_contents()/file_put_contents() are binary-safe, so CRLF and trailing newlines round-trip exactly. */
    private static function restore_backup(string $backup): ?string
    {
        if (!is_file($backup)) {
            return null;
        }
        $content = file_get_contents($backup);
        return $content === false ? null : $content;
    }

    /**
     * Wrap a completed write/delete with Claudpress_Health::guard(), and
     * follow its contract exactly (see guard()'s docblock):
     *
     *  - front_end_check === 'FAILED' with a revert given → the revert fixed
     *    the site, so this write caused the breakage. Leave it reverted and
     *    say plainly that nothing was written — success:true next to a note
     *    saying it was rolled back would be worse than either alone.
     *  - front_end_check === 'passed' with a revert given → the revert ran
     *    and did not help, so this write was innocent. Re-apply it, or the
     *    owner's change is silently lost while being told it succeeded.
     *  - no revert available (new file too recent to matter, existing file
     *    too large, or the backup store is full) → guard() can only report,
     *    never fix, so its verdict is left exactly as returned.
     *
     * @param string      $path        the file just written or deleted
     * @param bool        $existed     whether $path had content before this write
     * @param string|null $backup      backup file path from backup_before_write(), or null
     * @param string|null $new_content the content just written, to re-apply if the
     *                                 write turns out innocent; null for a delete
     *                                 (re-apply of a delete is deleting it again)
     * @param array<string,mixed> $result the tool result so far
     */
    private static function guard_write(string $path, bool $existed, ?string $backup, ?string $new_content, array $result, string $subject): array
    {
        $revert = null;
        if (!$existed) {
            // A new file's revert is deleting it.
            $revert = static function () use ($path) {
                @unlink($path);
            };
        } elseif ($backup !== null) {
            $revert = static function () use ($path, $backup) {
                $original = self::restore_backup($backup);
                if ($original !== null) {
                    file_put_contents($path, $original);
                }
            };
        }

        $result = Claudpress_Health::guard($result, $subject, $revert);

        if ($revert === null) {
            return $result;
        }

        if (($result['front_end_check'] ?? '') === 'FAILED') {
            $result['success'] = false;
            unset($result['bytes'], $result['replacements']);
            $result['error'] = 'Reverted automatically: ' . (string) ($result['note'] ?? ($subject . ' broke the front end.'));
            return $result;
        }

        if (($result['front_end_check'] ?? '') === 'passed') {
            if ($new_content === null) {
                @unlink($path);
            } else {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    wp_mkdir_p($dir);
                }
                file_put_contents($path, $new_content);
            }
        }

        return $result;
    }

    /**
     * Resolve + confine a path to the configured base directory.
     *
     * Three traps here, all of which were live bugs before 1.10.1:
     *
     *  1. wp_normalize_path() does NOT resolve '..'. It only fixes slashes, so
     *     'wp-content/../../etc/cron.d/x' survives normalization completely
     *     intact and looks, to a naive prefix check, like it is inside the base.
     *  2. realpath() returns false for a path that does not exist yet — which is
     *     every file write_file() creates. Falling back to the *unresolved*
     *     string in that case meant the prefix check passed and the operating
     *     system resolved the '..' later, at file_put_contents() time, landing
     *     the file outside the base. Writing PHP outside the base is remote code
     *     execution, so this was the most serious bug in the plugin.
     *  3. Comparing against the base without its trailing slash meant a base of
     *     '/var/www/html' also matched '/var/www/html-backup'.
     *
     * The fix: reject '..' outright, walk up to the nearest ancestor that really
     * exists (write_file is allowed to create missing directories, so neither
     * the path nor its immediate parent may exist yet), and compare
     * directory-style against the base *with* its trailing slash.
     *
     * Resolving through realpath() also collapses symlinks, so a symlink planted
     * inside the base that points outside it is rejected too.
     */
    private static function resolve(string $path): array
    {
        $base = (string) Claudpress::instance()->get('fs_base', ABSPATH);
        $base = rtrim(wp_normalize_path(realpath($base) ?: $base), '/') . '/';

        $path = wp_normalize_path($path);
        if ($path === '' || $path === '.') {
            $path = $base;
        }
        // Relative → resolve against base.
        if (!preg_match('#^([a-zA-Z]:/|/)#', $path)) {
            $path = $base . ltrim($path, '/');
        }

        // No '..' in any position. Cheap, total, and impossible to sneak past.
        // A legitimate tool call never needs one — callers pass either a path
        // relative to the base or an absolute path.
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return [null, 'Path may not contain "..".'];
            }
        }

        // Walk up until something exists, remembering the not-yet-existing tail.
        $probe  = $path;
        $suffix = '';
        while (($real = realpath($probe)) === false) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                return [null, 'Path could not be resolved: ' . $path];
            }
            $suffix = basename($probe) . ('' === $suffix ? '' : '/' . $suffix);
            $probe  = $parent;
        }

        $check = rtrim(wp_normalize_path($real), '/');
        if ('' !== $suffix) {
            $check .= '/' . $suffix;
        }
        $check .= '/'; // Compare directory-style so prefixes cannot straddle a name.

        if (strpos($check, $base) !== 0) {
            return [null, 'Path is outside the allowed filesystem base (' . $base . ').'];
        }

        return [$path, null];
    }

    public static function read_file(array $args): array
    {
        [$path, $err] = self::resolve((string) ($args['path'] ?? ''));
        if ($err) {
            return ['success' => false, 'error' => $err];
        }
        if (!is_file($path)) {
            return ['success' => false, 'error' => 'Not a file: ' . $path];
        }
        $content = file_get_contents($path);
        return ['success' => true, 'path' => $path, 'content' => $content, 'bytes' => strlen($content)];
    }

    public static function write_file(array $args): array
    {
        [$path, $err] = self::resolve((string) ($args['path'] ?? ''));
        if ($err) {
            return ['success' => false, 'error' => $err];
        }
        $content = (string) ($args['content'] ?? '');
        $syntax_error = self::php_syntax_error($path, $content);
        if ($syntax_error !== null) {
            return ['success' => false, 'error' => 'PHP syntax error, nothing written: ' . $syntax_error];
        }
        $existed = is_file($path);
        $backup  = $existed ? self::backup_before_write($path) : null;

        $dir = dirname($path);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $bytes = file_put_contents($path, $content);
        if ($bytes === false) {
            return ['success' => false, 'error' => 'Failed to write ' . $path];
        }
        $result = ['success' => true, 'path' => $path, 'bytes' => $bytes];
        return self::guard_write($path, $existed, $backup, $content, $result, 'this file write');
    }

    public static function edit_file(array $args): array
    {
        [$path, $err] = self::resolve((string) ($args['path'] ?? ''));
        if ($err) {
            return ['success' => false, 'error' => $err];
        }
        if (!is_file($path)) {
            return ['success' => false, 'error' => 'Not a file: ' . $path];
        }
        $content = file_get_contents($path);
        $old = (string) ($args['old_string'] ?? '');
        $new = (string) ($args['new_string'] ?? '');
        $replace_all = (bool) ($args['replace_all'] ?? false);

        $count = substr_count($content, $old);
        if ($count === 0) {
            return ['success' => false, 'error' => 'old_string not found in file.'];
        }
        if ($count > 1 && !$replace_all) {
            return ['success' => false, 'error' => "old_string is not unique ($count matches). Provide more context or set replace_all=true."];
        }
        $updated = $replace_all ? str_replace($old, $new, $content) : self::replace_first($content, $old, $new);
        $syntax_error = self::php_syntax_error($path, $updated);
        if ($syntax_error !== null) {
            return ['success' => false, 'error' => 'PHP syntax error, nothing written: ' . $syntax_error];
        }
        $backup = self::backup_before_write($path);
        file_put_contents($path, $updated);
        $result = ['success' => true, 'path' => $path, 'replacements' => $replace_all ? $count : 1];
        return self::guard_write($path, true, $backup, $updated, $result, 'this file edit');
    }

    private static function replace_first(string $haystack, string $needle, string $replace): string
    {
        $pos = strpos($haystack, $needle);
        if ($pos === false) {
            return $haystack;
        }
        return substr_replace($haystack, $replace, $pos, strlen($needle));
    }

    public static function delete_file(array $args): array
    {
        [$path, $err] = self::resolve((string) ($args['path'] ?? ''));
        if ($err) {
            return ['success' => false, 'error' => $err];
        }
        if (!is_file($path)) {
            return ['success' => false, 'error' => 'Not a file: ' . $path];
        }
        $backup  = self::backup_before_write($path);
        $deleted = (bool) @unlink($path);
        if (!$deleted) {
            return ['success' => false, 'path' => $path];
        }
        $result = ['success' => true, 'path' => $path];
        return self::guard_write($path, true, $backup, null, $result, 'this file delete');
    }

    public static function list_directory(array $args): array
    {
        [$path, $err] = self::resolve((string) ($args['path'] ?? ''));
        if ($err) {
            return ['success' => false, 'error' => $err];
        }
        if (!is_dir($path)) {
            return ['success' => false, 'error' => 'Not a directory: ' . $path];
        }
        $entries = [];
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = rtrim($path, '/') . '/' . $entry;
            $entries[] = [
                'name'  => $entry,
                'type'  => is_dir($full) ? 'dir' : 'file',
                'size'  => is_file($full) ? filesize($full) : null,
            ];
        }
        return ['success' => true, 'path' => $path, 'entries' => $entries];
    }

    public static function write_sandbox_file(array $args): array
    {
        $filename = basename((string) ($args['filename'] ?? ''));
        if ($filename === '' || !str_ends_with($filename, '.php')) {
            return ['success' => false, 'error' => 'filename must end in .php'];
        }
        $content = (string) ($args['content'] ?? '');
        $syntax_error = self::php_syntax_error($filename, $content);
        if ($syntax_error !== null) {
            return ['success' => false, 'error' => 'PHP syntax error, nothing written: ' . $syntax_error];
        }
        if (!is_dir(CLAUDPRESS_SANDBOX_DIR)) {
            wp_mkdir_p(CLAUDPRESS_SANDBOX_DIR);
        }
        $path    = CLAUDPRESS_SANDBOX_DIR . '/' . $filename;
        $existed = is_file($path);
        $backup  = $existed ? self::backup_before_write($path) : null;

        $bytes = file_put_contents($path, $content);
        if ($bytes === false) {
            return ['success' => false, 'error' => 'Failed to write sandbox file.'];
        }
        $result = ['success' => true, 'path' => $path, 'bytes' => $bytes, 'note' => 'Loaded automatically on the next request. If it still fatals at runtime for a non-syntax reason, it is auto-quarantined to .php.disabled.'];

        // Guarded despite the quarantine that already exists, because the two
        // catch different failures. Quarantine fires when the file FATALS; this
        // fires when it runs exactly as written and takes the site down anyway
        // — a redirect, a 503, a die() on the front end. Nothing errors, so
        // quarantine never sees it.
        return self::guard_write($path, $existed, $backup, $content, $result, 'this sandbox file');
    }
}
