<?php
/**
 * Plugin Name:       Claudpress
 * Plugin URI:        https://github.com/calflint/claudpress
 * Description:        Self-contained MCP server that gives an AI agent (Claude Code, Claude Desktop, Cursor, Codex…) full, programmatic control of WordPress & WooCommerce — PHP execution, filesystem, content, store, SEO, a code-snippets engine, Elementor building, updates/email, AI/stock images, cross-session memory and a skills engine. No external dependencies. For development & staging sites.
 * Version:           1.0.2
 * Requires at least: 6.3
 * Requires PHP:      8.0
 * Author:            Caleb Arthur-Flints
 * Author URI:        https://github.com/calflint
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       claudpress
 *
 * WARNING: Claudpress gives connected AI agents unrestricted control of this
 * WordPress installation (arbitrary PHP, database, filesystem). Only enable AI
 * abilities on development or staging sites you can afford to break. Never
 * activate abilities on a production site without a full backup and review flow.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CLAUDPRESS_VERSION', '1.0.2');
define('CLAUDPRESS_FILE', __FILE__);
define('CLAUDPRESS_DIR', plugin_dir_path(__FILE__));
define('CLAUDPRESS_URL', plugin_dir_url(__FILE__));
define('CLAUDPRESS_BASENAME', plugin_basename(__FILE__));

/**
 * Where the project lives, in one place.
 *
 * Defined rather than hard-coded at each call site so a handle change is one
 * edit, and so an empty value hides its button instead of rendering a dead
 * link - a Donate button that 404s is worse than no Donate button.
 */
if (!defined('CLAUDPRESS_DONATE_URL')) {
    define('CLAUDPRESS_DONATE_URL', 'https://ko-fi.com/claudpress');
}
if (!defined('CLAUDPRESS_SERVICES_URL')) {
    define('CLAUDPRESS_SERVICES_URL', 'https://claudpress.com/services');
}
if (!defined('CLAUDPRESS_REPO_URL')) {
    define('CLAUDPRESS_REPO_URL', 'https://github.com/calflint/claudpress');
}

// Namespace for the MCP REST route: /wp-json/claudpress/v1/mcp
define('CLAUDPRESS_REST_NAMESPACE', 'claudpress/v1');

// Persistent sandbox directory for AI-written PHP that must survive requests.
define('CLAUDPRESS_SANDBOX_DIR', WP_CONTENT_DIR . '/claudpress-sandbox');

// How long (seconds) a single execute_php / tool call may run.
if (!defined('CLAUDPRESS_MAX_EXECUTION_TIME')) {
    define('CLAUDPRESS_MAX_EXECUTION_TIME', 120);
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
 *   Claudpress                ->  includes/class-claudpress.php
 *   Claudpress_Skills_Remote  ->  includes/class-skills-remote.php
 *   Claudpress_Tools_Core     ->  includes/tools/class-tools-core.php
 *
 * Underscores become hyphens, the name is lowercased (PHP class names are
 * case-insensitive, so `Claudpress_Tools_Seo` must find `Claudpress_Tools_SEO`),
 * and includes/ is tried before includes/tools/.
 *
 * $map is the escape hatch for a class the convention cannot reach — a file
 * holding more than one class, or a name that does not follow the rule. It is
 * empty, and the intention is that it stays that way: every class under
 * includes/ is named so the convention finds it. tests/unit/test-integrity.php
 * proves that from the source, so a stale entry here cannot go unnoticed.
 */
spl_autoload_register(static function ($class) {
    if (strncasecmp((string) $class, 'Claudpress', 10) !== 0 || strpos((string) $class, '\\') !== false) {
        return;
    }

    /** @var array<string,string> lowercased class name => path relative to CLAUDPRESS_DIR. */
    static $map = [];

    $lc = strtolower((string) $class);

    if (isset($map[$lc])) {
        require_once CLAUDPRESS_DIR . $map[$lc];
        return;
    }

    if ($lc === 'claudpress') {
        $slug = 'claudpress';
    } elseif (strncmp($lc, 'claudpress_', 11) === 0) {
        $slug = str_replace('_', '-', substr($lc, 11));
    } else {
        return;
    }

    if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
        return;
    }

    foreach (['includes/class-', 'includes/tools/class-'] as $dir) {
        $file = CLAUDPRESS_DIR . $dir . $slug . '.php';
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
    load_plugin_textdomain('claudpress', false, dirname(CLAUDPRESS_BASENAME) . '/languages');
    Claudpress::instance()->boot();
    Claudpress_Updater::init();
    Claudpress_Donate::init();

    $studio = new Claudpress_Studio();
    add_action('admin_menu', [$studio, 'menu'], 20);
    (new Claudpress_Studio_Actions())->hooks();
}, 20);

register_activation_hook(__FILE__, ['Claudpress', 'on_activate']);
register_deactivation_hook(__FILE__, ['Claudpress', 'on_deactivate']);
