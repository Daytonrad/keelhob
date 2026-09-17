<?php
/**
 * Sandbox loader with crash recovery.
 *
 * The agent can write persistent PHP files into KEELHOB_SANDBOX_DIR (via the
 * filesystem tools / write_sandbox_file). Those files are auto-included on every
 * request so custom functionality survives. If one throws a fatal error, we
 * catch it via a shutdown handler and disable the offending file (rename to
 * .php.disabled) so the site is never left in a boot loop.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Sandbox
{
    /** @var string|null The file currently being included, for crash attribution. */
    private static $current = null;

    public static function init(): void
    {
        // Load as early as safe, but after WP core is available.
        add_action('plugins_loaded', [self::class, 'load_files'], 30);
    }

    public static function load_files(): void
    {
        if (!Keelhob::instance()->abilities_enabled()) {
            return;
        }
        if (!is_dir(KEELHOB_SANDBOX_DIR)) {
            return;
        }
        register_shutdown_function([self::class, 'on_shutdown']);

        foreach (glob(KEELHOB_SANDBOX_DIR . '/*.php') as $file) {
            if (basename($file) === 'index.php') {
                continue;
            }
            self::$current = $file;
            include_once $file;
            self::$current = null;
        }
    }

    /**
     * If the request died with a fatal error while including a sandbox file,
     * quarantine that file so the next request boots cleanly.
     */
    public static function on_shutdown(): void
    {
        $error = error_get_last();
        if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
            return;
        }
        if (self::$current && file_exists(self::$current)) {
            @rename(self::$current, self::$current . '.disabled');
            @error_log('[Keelhob] Quarantined crashing sandbox file: ' . self::$current . ' — ' . $error['message']);
        }
    }
}
