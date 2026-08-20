<?php
/**
 * Plugin Name:       Claudpress Pro
 * Plugin URI:        https://github.com/calflint/claudpress
 * Description:       Paid add-on for Claudpress. Unlocks the Pro capability groups — orders and reports, payments, shipping, tax, users, updates, notifications, autonomy and deploy — for sites holding a valid licence.
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      8.0
 * Requires Plugins:  claudpress
 * Author:            Caleb Arthur-Flints
 * Author URI:        https://github.com/calflint
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       claudpress-pro
 *
 * This add-on ships as its own zip and is only ever downloaded by customers.
 * That separation — not the licence check inside it — is what makes the split
 * worth having: a free user has no Pro code on disk to crack, so a casual
 * bypass becomes deliberate piracy, which is a far smaller population. Nothing
 * in here should be written as though it were tamper-proof; see the tiering
 * note in the free core's includes/class-capabilities.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CLAUDPRESS_PRO_VERSION', '0.1.0');
define('CLAUDPRESS_PRO_FILE', __FILE__);
define('CLAUDPRESS_PRO_DIR', plugin_dir_path(__FILE__));
define('CLAUDPRESS_PRO_BASENAME', plugin_basename(__FILE__));

/**
 * The free core this add-on is built against.
 *
 * 1.10.1 is the first release carrying the tiering seam the add-on needs — the
 * `tier` field on every capability group, `claudpress_is_pro`, and the
 * `claudpress_register_tools` action. Anything older loads fine but silently
 * ignores everything below, which presents to the customer as "I paid and
 * nothing happened". Refusing loudly is the kinder failure.
 */
define('CLAUDPRESS_PRO_REQUIRES_CORE', '1.10.1');

/**
 * Boot on plugins_loaded at priority 10, ahead of the free core's own boot at
 * 20.
 *
 * Two reasons it cannot happen at file scope. WordPress loads active plugins in
 * path order, and 'claudpress-pro/claudpress-pro.php' sorts *before*
 * 'claudpress/claudpress.php' (a hyphen precedes a slash), so at include time
 * the core's constants and classes may not exist yet. And the filter below has
 * to be in place before Claudpress::boot() runs, because that is where tools
 * register and where is_pro() is first consulted.
 */
add_action('plugins_loaded', 'claudpress_pro_boot', 10);

function claudpress_pro_boot(): void
{
    load_plugin_textdomain('claudpress-pro', false, dirname(CLAUDPRESS_PRO_BASENAME) . '/languages');

    $problem = claudpress_pro_core_problem();
    if ($problem !== '') {
        claudpress_pro_self_deactivate($problem);
        return;
    }

    require_once CLAUDPRESS_PRO_DIR . 'includes/class-claudpress-pro-licence.php';
    require_once CLAUDPRESS_PRO_DIR . 'includes/class-claudpress-pro-tools.php';

    /**
     * The one line that turns the tier on.
     *
     * The free core defines the tiers and asks this question; the add-on is the
     * only thing that ever answers yes. Deliberately returns the licence
     * verdict rather than "the add-on is installed", so an expired or revoked
     * subscription degrades to the free tier instead of staying unlocked
     * forever on the strength of a file being present.
     */
    add_filter('claudpress_is_pro', 'claudpress_pro_is_pro');

    add_action('claudpress_register_tools', ['Claudpress_Pro_Tools', 'register']);
}

/**
 * @param bool $is_pro
 */
function claudpress_pro_is_pro($is_pro): bool
{
    return Claudpress_Pro_Licence::is_valid();
}

/**
 * Why the add-on cannot run, or '' when it can.
 *
 * `Requires Plugins` in the header covers WordPress 6.5 and up, and only for
 * plugins installed from a directory — it does nothing for a manually uploaded
 * zip, which is exactly how this add-on is delivered. So the check is repeated
 * here, where it actually fires.
 */
function claudpress_pro_core_problem(): string
{
    if (!defined('CLAUDPRESS_VERSION') || !class_exists('Claudpress_Capabilities')) {
        return __('Claudpress Pro needs the free Claudpress plugin to be installed and active.', 'claudpress-pro');
    }

    if (version_compare(CLAUDPRESS_VERSION, CLAUDPRESS_PRO_REQUIRES_CORE, '<')) {
        return sprintf(
            /* translators: 1: required Claudpress version, 2: installed Claudpress version. */
            __('Claudpress Pro needs Claudpress %1$s or newer. This site has %2$s — update Claudpress, then activate Pro again.', 'claudpress-pro'),
            CLAUDPRESS_PRO_REQUIRES_CORE,
            CLAUDPRESS_VERSION
        );
    }

    return '';
}

/**
 * Switch the add-on off and say why.
 *
 * Deactivating rather than idling is on purpose: a Pro plugin sitting in the
 * list marked "active" while doing nothing is how a customer concludes the
 * product is broken. The notice is stored rather than printed directly because
 * this runs on plugins_loaded, long before admin_notices.
 */
function claudpress_pro_self_deactivate(string $reason): void
{
    update_option('claudpress_pro_activation_error', $reason, false);

    if (!function_exists('deactivate_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    deactivate_plugins(CLAUDPRESS_PRO_BASENAME);

    add_action('admin_notices', 'claudpress_pro_activation_notice');
}

function claudpress_pro_activation_notice(): void
{
    $reason = (string) get_option('claudpress_pro_activation_error', '');
    if ($reason === '') {
        return;
    }
    delete_option('claudpress_pro_activation_error');

    printf(
        '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
        esc_html__('Claudpress Pro was deactivated.', 'claudpress-pro'),
        esc_html($reason)
    );
}

/**
 * Nothing is dropped on deactivation — the licence record has to survive a
 * deactivate/reactivate cycle, which is the first thing anyone tries when a
 * plugin misbehaves. Losing a paid customer's licence key as the reward for
 * troubleshooting would be its own support ticket.
 */
register_deactivation_hook(__FILE__, static function (): void {
    // Literal rather than Claudpress_Pro_Licence::TRANSIENT: when the guard
    // above tripped, that class was never required, and a deactivation hook
    // that fatals leaves the plugin un-deactivatable.
    delete_transient('claudpress_pro_licence_valid');
});
