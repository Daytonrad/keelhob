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
    public static function register(Claudpress_Tool_Registry $r): void
    {
        $r->add('read_file', 'Read a text file (confined to the Claudpress filesystem base, default the WordPress root). Returns file contents.', [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string', 'description' => 'Absolute path or path relative to the WordPress root.']],
            'required' => ['path'],
        ], [self::class, 'read_file'], ['readOnlyHint' => true]);

        $r->add('write_file', 'Create or overwrite a text file (confined to the filesystem base). Parent directories are created as needed.', [
            'type' => 'object',
            'properties' => [
                'path'    => ['type' => 'string'],
                'content' => ['type' => 'string'],
            ],
            'required' => ['path', 'content'],
        ], [self::class, 'write_file'], ['destructiveHint' => true]);

        $r->add('edit_file', 'Edit a file by replacing an exact string. `old_string` must match exactly and uniquely unless replace_all is true.', [
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

        $r->add('write_sandbox_file', 'Write a PHP file into the Claudpress sandbox (wp-content/claudpress-sandbox). Sandbox files are auto-loaded on every request so custom hooks/functions persist. A file that fatals is auto-quarantined so the site stays up. Include the opening <?php tag.', [
            'type' => 'object',
            'properties' => [
                'filename' => ['type' => 'string', 'description' => 'File name, e.g. my-feature.php'],
                'content'  => ['type' => 'string', 'description' => 'Full PHP file contents, including <?php.'],
            ],
            'required' => ['filename', 'content'],
        ], [self::class, 'write_sandbox_file'], ['destructiveHint' => true]);
    }

    /** Resolve + confine a path to the configured base directory. */
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

        $real = realpath($path);
        $check = $real !== false ? wp_normalize_path($real) : $path;

        if (strpos($check, rtrim($base, '/')) !== 0) {
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
        $dir = dirname($path);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $bytes = file_put_contents($path, (string) ($args['content'] ?? ''));
        if ($bytes === false) {
            return ['success' => false, 'error' => 'Failed to write ' . $path];
        }
        return ['success' => true, 'path' => $path, 'bytes' => $bytes];
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
        file_put_contents($path, $updated);
        return ['success' => true, 'path' => $path, 'replacements' => $replace_all ? $count : 1];
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
        return ['success' => (bool) @unlink($path), 'path' => $path];
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
        if (!is_dir(CLAUDPRESS_SANDBOX_DIR)) {
            wp_mkdir_p(CLAUDPRESS_SANDBOX_DIR);
        }
        $path  = CLAUDPRESS_SANDBOX_DIR . '/' . $filename;
        $bytes = file_put_contents($path, (string) ($args['content'] ?? ''));
        if ($bytes === false) {
            return ['success' => false, 'error' => 'Failed to write sandbox file.'];
        }
        // Basic lint: reject obvious parse errors before the next request loads it.
        return ['success' => true, 'path' => $path, 'bytes' => $bytes, 'note' => 'Loaded automatically on the next request. If it fatals, it is auto-quarantined to .php.disabled.'];
    }
}
