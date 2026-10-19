<?php
/**
 * Plugin Name: APISUNAT Wordpress
 * Plugin URI:  https://github.com/kamilml/apisunat-for-woocommerce
 * Description: Emite comprobantes electrónicos SUNAT desde WooCommerce con Action Scheduler.
 * Version:     2.0.0
 * Author:      APISUNAT
 * Author URI:  https://apisunat.com/
 * License:     GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: apisunatv2
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 */

defined('ABSPATH') || exit;

define('APISUNATWP_VERSION', '2.0.1');

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')), true)) {
    add_action('admin_notices', function () {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            wp_kses_post(__(
                '<strong>APISUNAT</strong> requiere <a href="https://wordpress.org/plugins/woocommerce/">WooCommerce</a> activado.',
                'apisunatv2'
            ))
        );
    });
    return;
}

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook(__FILE__, [Atm\Apisunatwp\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [Atm\Apisunatwp\Deactivator::class, 'deactivate']);

add_action('plugins_loaded', function () {
    Atm\Apisunatwp\Init::run();
});
