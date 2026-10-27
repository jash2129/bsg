<?php
/**
 * Plugin Name: BSG India Post Shipping
 * Description: India Post (Department of Posts) integration for WooCommerce: serviceability, tariff, booking with barcodes, address labels and live tracking. Built to the "External Integrations Approach Document" (updated 30.07.2026). Stays switched off until credentials are entered.
 * Version:     1.0.0
 * Author:      BSG Garments
 * Requires Plugins: woocommerce
 * Text Domain: bsg-india-post
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BSG_IP_VERSION', '1.0.0');
define('BSG_IP_DIR', plugin_dir_path(__FILE__));
define('BSG_IP_URL', plugin_dir_url(__FILE__));

require_once BSG_IP_DIR . 'includes/class-settings.php';
require_once BSG_IP_DIR . 'includes/class-client.php';
require_once BSG_IP_DIR . 'includes/class-barcode.php';
require_once BSG_IP_DIR . 'includes/class-booking.php';
require_once BSG_IP_DIR . 'includes/class-tracking.php';
require_once BSG_IP_DIR . 'includes/class-track-page.php';
require_once BSG_IP_DIR . 'includes/class-admin.php';

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return;
    }
    BSG_IP_Settings::init();
    BSG_IP_Tracking::init();
    BSG_IP_Track_Page::init();
    BSG_IP_Admin::init();
});

register_activation_hook(__FILE__, function () {
    BSG_IP_Settings::ensure_defaults();
    BSG_IP_Tracking::schedule();
});
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('bsg_ip_poll_tracking');
});
