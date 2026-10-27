<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Order screen: a "Shipment (India Post)" box, order actions (Book / Refresh tracking / Get label),
 * and the label download. Works with HPOS and classic orders.
 */
class BSG_IP_Admin {

    public static function init() {
        add_action('add_meta_boxes', array(__CLASS__, 'box'));
        add_filter('woocommerce_order_actions', array(__CLASS__, 'actions'));
        add_action('woocommerce_order_action_bsg_ip_book', array(__CLASS__, 'do_book'));
        add_action('woocommerce_order_action_bsg_ip_track', array(__CLASS__, 'do_track'));
        add_action('woocommerce_order_action_bsg_ip_label', array(__CLASS__, 'do_label'));
        add_action('admin_post_bsg_ip_get_label', array(__CLASS__, 'download_label'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'front_css'));
        add_action('admin_notices', array(__CLASS__, 'low_barcodes'));
    }

    public static function box() {
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
        add_meta_box('bsg-ip-box', 'Shipment (India Post)', array(__CLASS__, 'render_box'), $screen, 'side', 'default');
    }

    public static function render_box($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order) return;
        $code = $order->get_meta('_bsg_ip_barcode');
        if (!BSG_IP_Settings::is_ready() && !$code) {
            echo '<p>India Post is not set up yet. <a href="' . esc_url(admin_url('admin.php?page=bsg-india-post')) . '">Open settings</a></p>';
            return;
        }
        if (!$code) {
            $p = BSG_IP_Booking::parcel($order);
            echo '<p>Not booked. Parcel estimate: <strong>' . (int) $p['weight_g'] . ' g</strong>, ' . (int) $p['l'] . '×' . (int) $p['w'] . '×' . (int) $p['h'] . ' cm.</p>';
            echo '<p class="description">Use the <em>Order actions</em> box: "Book with India Post".</p>';
            return;
        }
        $labels = BSG_IP_Tracking::states();
        $state = $order->get_meta('_bsg_ip_state') ?: 'booked';
        echo '<p><strong>' . esc_html($code) . '</strong><br>' . esc_html($labels[$state] ?? $state) . '</p>';
        if ($t = $order->get_meta('_bsg_ip_tariff')) {
            echo '<p>India Post charge: ₹' . esc_html(number_format((float) $t, 2)) . '</p>';
        }
        if ($f = $order->get_meta('_bsg_ip_label')) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=bsg_ip_get_label&order=' . $order->get_id()), 'bsg_ip_label_' . $order->get_id());
            echo '<p><a class="button" href="' . esc_url($url) . '" target="_blank" rel="noopener">Download label (PDF)</a></p>';
        } else {
            echo '<p class="description">No label yet. Order actions: "Get India Post label".</p>';
        }
        $events = array_slice((array) $order->get_meta('_bsg_ip_events'), 0, 5);
        if ($events) {
            echo '<ul style="margin:8px 0 0;padding-left:16px;list-style:disc">';
            foreach ($events as $e) {
                echo '<li>' . esc_html($e['description'] ?? '') . ' <span class="description">' . esc_html($e['when'] ?? '') . '</span></li>';
            }
            echo '</ul>';
        }
    }

    public static function actions($actions) {
        if (BSG_IP_Settings::is_ready()) {
            $actions['bsg_ip_book']  = 'Book with India Post';
            $actions['bsg_ip_label'] = 'Get India Post label';
            $actions['bsg_ip_track'] = 'Refresh India Post tracking';
        }
        return $actions;
    }

    private static function flash($order, $ok, $msg) {
        $order->add_order_note(($ok ? 'India Post: ' : 'India Post problem: ') . $msg, 0, true);
    }

    public static function do_book($order) {
        list($ok, $msg) = BSG_IP_Booking::book($order);
        if (!$ok) { self::flash($order, false, $msg); }
    }

    public static function do_label($order) {
        list($ok, $msg) = BSG_IP_Booking::make_label($order);
        self::flash($order, $ok, $msg);
    }

    public static function do_track($order) {
        list($ok, $msg) = BSG_IP_Tracking::refresh($order);
        self::flash($order, $ok, $msg);
    }

    public static function download_label() {
        $id = isset($_GET['order']) ? (int) $_GET['order'] : 0;
        if (!$id || !current_user_can('edit_shop_orders') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bsg_ip_label_' . $id)) {
            wp_die('Not allowed', '', array('response' => 403));
        }
        $order = wc_get_order($id);
        $file = $order ? basename((string) $order->get_meta('_bsg_ip_label')) : '';
        $path = $file ? BSG_IP_Booking::label_dir() . '/' . $file : '';
        if (!$path || !is_readable($path)) {
            wp_die('Label not found');
        }
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $file . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public static function low_barcodes() {
        if (!current_user_can('manage_woocommerce') || !BSG_IP_Settings::is_ready()) return;
        $left = BSG_IP_Barcode::remaining();
        if ($left > 0 && $left <= 50) {
            echo '<div class="notice notice-warning"><p>India Post: only ' . (int) $left . ' article numbers left in your range. Ask India Post for a new range.</p></div>';
        } elseif ($left === 0) {
            echo '<div class="notice notice-error"><p>India Post: no article numbers available. Enter the assigned range in WooCommerce &gt; India Post.</p></div>';
        }
    }

    public static function front_css() {
        if (!function_exists('is_account_page') || !(is_account_page() || is_wc_endpoint_url('order-received'))) return;
        wp_enqueue_style('bsg-ip-front', BSG_IP_URL . 'assets/front.css', array(), BSG_IP_VERSION);
    }
}
