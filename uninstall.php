<?php

// Deleted from the Plugins screen: the settings go (the secret key with them).
// Orders keep their notes and Orboni's session ids, which are the store's records.
defined('WP_UNINSTALL_PLUGIN') || exit;

$orboni_edd_settings = get_option('edd_settings');
if (is_array($orboni_edd_settings)) {
    foreach (['orboni_secret_key', 'orboni_webhook_secret', 'orboni_checkout_label', 'orboni_debug'] as $orboni_edd_key) {
        unset($orboni_edd_settings[$orboni_edd_key]);
    }
    if (isset($orboni_edd_settings['gateways']) && is_array($orboni_edd_settings['gateways'])) {
        unset($orboni_edd_settings['gateways']['orboni']);
    }
    update_option('edd_settings', $orboni_edd_settings);
}

if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('orboni_edd_check_order', [], 'orboni');
}
wp_unschedule_hook('orboni_edd_check_order');
