<?php
/**
 * Plugin Name:       Keelhob
 * Plugin URI:        https://github.com/calflint/keelhob
 * Description:        Self-contained MCP server that gives an AI agent (Claude Code, Claude Desktop, Cursor, Codex…) full, programmatic control of WordPress & WooCommerce — PHP execution, filesystem, content, store, SEO, a code-snippets engine, Elementor building, updates/email, AI/stock images, cross-session memory and a skills engine. <strong>The snippets engine also works on its own:</strong> write PHP, CSS, JS or HTML by hand under Keelhob → Snippets, with a real code editor, a per-snippet on/off switch and a syntax check that will not let broken PHP go live — no AI connected, no abilities switched on. No external dependencies. For development & staging sites.
 * Version:           2.1.0
 * Requires at least: 6.3
 * Requires PHP:      8.0
 * Author:            Caleb Sammy Arthur-Flints
 * Author URI:        https://github.com/calflint
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       keelhob
 *
 * WARNING: Keelhob gives connected AI agents unrestricted control of this
 * WordPress installation (arbitrary PHP, database, filesystem). Only enable AI
 * abilities on development or staging sites you can afford to break. Never
 * activate abilities on a production site without a full backup and review flow.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KEELHOB_VERSION', '2.1.0');
define('KEELHOB_FILE', __FILE__);
define('KEELHOB_DIR', plugin_dir_path(__FILE__));
define('KEELHOB_URL', plugin_dir_url(__FILE__));
define('KEELHOB_BASENAME', plugin_basename(__FILE__));

/**
 * Where the project lives, in one place.
 *
 * Defined rather than hard-coded at each call site so a handle change is one
 * edit, and so an empty value hides its button instead of rendering a dead
 * link - a Donate button that 404s is worse than no Donate button.
 */
if (!defined('KEELHOB_DONATE_URL')) {
    define('KEELHOB_DONATE_URL', 'https://ko-fi.com/keelhob');
}
if (!defined('KEELHOB_SERVICES_URL')) {
    define('KEELHOB_SERVICES_URL', 'https://keelhob.com/services');
}
if (!defined('KEELHOB_REPO_URL')) {
    define('KEELHOB_REPO_URL', 'https://github.com/calflint/keelhob');
}

// Namespace for the MCP REST route: /wp-json/keelhob/v1/mcp
define('KEELHOB_REST_NAMESPACE', 'keelhob/v1');

// Persistent sandbox directory for AI-written PHP that must survive requests.
define('KEELHOB_SANDBOX_DIR', WP_CONTENT_DIR . '/keelhob-sandbox');

// How long (seconds) a single execute_php / tool call may run.
if (!defined('KEELHOB_MAX_EXECUTION_TIME')) {
    define('KEELHOB_MAX_EXECUTION_TIME', 120);
}

/**
 * Dependency-free autoloader. Every class lives under includes/.
 *
 * There is no Composer autoloader here on purpose. The plugin executes
 * arbitrary PHP for a living, so having no supply chain at all — no vendor/,
 * nothing to audit, no second autoloader racing another plugin's — is worth
 * more than the convenience. Composer is used in this repository for developer
 * tooling only (PHPStan); nothing it installs is ever shipped.
 *
 * The mapping is the file-naming convention the plugin already follows:
 *
 *   Keelhob                ->  includes/class-keelhob.php
 *   Keelhob_Skills_Remote  ->  includes/class-skills-remote.php
 *   Keelhob_Tools_Core     ->  includes/tools/class-tools-core.php
 *
 * Underscores become hyphens, the name is lowercased (PHP class names are
 * case-insensitive, so `Keelhob_Tools_Seo` must find `Keelhob_Tools_SEO`),
 * and includes/ is tried before includes/tools/.
 *
 * $map is the escape hatch for a class the convention cannot reach — a file
 * holding more than one class, or a name that does not follow the rule. It is
 * empty, and the intention is that it stays that way: every class under
 * includes/ is named so the convention finds it. tests/unit/test-integrity.php
 * proves that from the source, so a stale entry here cannot go unnoticed.
 */
spl_autoload_register(static function ($class) {
    if (strncasecmp((string) $class, 'Keelhob', strlen('Keelhob')) !== 0 || strpos((string) $class, '\\') !== false) {
        return;
    }

    /** @var array<string,string> lowercased class name => path relative to KEELHOB_DIR. */
    static $map = [];

    $lc = strtolower((string) $class);

    if (isset($map[$lc])) {
        require_once KEELHOB_DIR . $map[$lc];
        return;
    }

    if ($lc === 'keelhob') {
        $slug = 'keelhob';
    } elseif (strncmp($lc, 'keelhob_', strlen('keelhob_')) === 0) {
        $slug = str_replace('_', '-', substr($lc, strlen('keelhob_')));
    } else {
        return;
    }

    if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
        return;
    }

    foreach (['includes/class-', 'includes/tools/class-'] as $dir) {
        $file = KEELHOB_DIR . $dir . $slug . '.php';
        if (is_readable($file)) {
            require_once $file;
            return;
        }
    }
});

/**
 * Boot the plugin once all plugins are loaded (so WooCommerce detection works).
 *
 * Nothing above this line loads a class file. The autoloader pulls each one in
 * the first time it is named, which is why the boot order below is the only
 * order that now matters.
 */
add_action('plugins_loaded', static function () {
    load_plugin_textdomain('keelhob', false, dirname(KEELHOB_BASENAME) . '/languages');
    Keelhob::instance()->boot();
    Keelhob_Updater::init();
    Keelhob_Donate::init();

    $studio = new Keelhob_Studio();
    add_action('admin_menu', [$studio, 'menu'], 20);
    (new Keelhob_Studio_Actions())->hooks();
}, 20);

register_activation_hook(__FILE__, ['Keelhob', 'on_activate']);
register_deactivation_hook(__FILE__, ['Keelhob', 'on_deactivate']);
