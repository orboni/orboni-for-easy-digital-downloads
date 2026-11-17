<?php

defined('ABSPATH') || exit;

/**
 * Easy Digital Downloads' own debug log (Tools → Debug Log), each line
 * marked "Orboni:". Requests and webhooks are written while the gateway's
 * debug setting is on; errors always. Never given a key or a signing secret.
 */
class Orboni_EDD_Log
{
    public static function info($message)
    {
        if (edd_get_option('orboni_debug', false)) {
            self::write($message);
        }
    }

    public static function error($message)
    {
        // A merchant asking why crypto failed needs these whatever the setting.
        self::write($message);
    }

    private static function write($message)
    {
        if (function_exists('edd_debug_log')) {
            edd_debug_log('Orboni: '.$message, true);
        }
    }
}
