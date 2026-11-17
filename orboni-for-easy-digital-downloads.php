<?php

/**
 * Plugin Name:       Orboni for Easy Digital Downloads
 * Plugin URI:        https://orboni.com/docs/easy-digital-downloads
 * Description:       Accept crypto payments — USDT, USDC, BTC, ETH and more — straight to your own wallets. Orboni never holds your money.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  easy-digital-downloads
 * Author:            Orboni
 * Author URI:        https://orboni.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       orboni-for-easy-digital-downloads
 */
defined('ABSPATH') || exit;

define('ORBONI_EDD_VERSION', '1.0.0');
define('ORBONI_EDD_FILE', __FILE__);
define('ORBONI_EDD_DIR', plugin_dir_path(__FILE__));

/** The Easy Digital Downloads this gateway is written against: orders in their own tables. */
define('ORBONI_EDD_MIN_EDD', '3.1');

add_action('plugins_loaded', 'orboni_edd_init');

/** Loads the gateway once Easy Digital Downloads 3.1+ is there. */
function orboni_edd_init()
{
    if (! defined('EDD_VERSION') || version_compare(EDD_VERSION, ORBONI_EDD_MIN_EDD, '<') || ! function_exists('edd_get_order')) {
        add_action('admin_notices', 'orboni_edd_missing_notice');

        return;
    }

    require_once ORBONI_EDD_DIR.'includes/class-orboni-edd-log.php';
    require_once ORBONI_EDD_DIR.'includes/class-orboni-edd-api.php';
    require_once ORBONI_EDD_DIR.'includes/class-orboni-edd-signature.php';
    require_once ORBONI_EDD_DIR.'includes/class-orboni-edd-order-sync.php';
    require_once ORBONI_EDD_DIR.'includes/class-orboni-edd-gateway.php';
    require_once ORBONI_EDD_DIR.'includes/class-orboni-edd-webhook.php';

    Orboni_EDD_Gateway::register();
    Orboni_EDD_Webhook::register();
    Orboni_EDD_Order_Sync::register();
}

/** Shown to whoever can activate plugins, while Easy Digital Downloads is missing or older than 3.1. */
function orboni_edd_missing_notice()
{
    if (! current_user_can('activate_plugins')) {
        return;
    }

    echo '<div class="notice notice-warning"><p>'.esc_html(sprintf(
        /* translators: %s: the least Easy Digital Downloads version, e.g. 3.1 */
        __('Orboni for Easy Digital Downloads needs Easy Digital Downloads %s or later, active.', 'orboni-for-easy-digital-downloads'),
        ORBONI_EDD_MIN_EDD
    )).'</p></div>';
}

add_filter('plugin_action_links_'.plugin_basename(__FILE__), static function ($links) {
    $url = admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways&section=orboni');
    array_unshift($links, '<a href="'.esc_url($url).'">'.esc_html__('Settings', 'orboni-for-easy-digital-downloads').'</a>');

    return $links;
});
