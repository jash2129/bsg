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
        add_action('woocommerce_process_shop_order_meta', array(__CLASS__, 'save_manual'), 20, 1);
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
        if (!$code) {
            // Ship by hand: type the number from the post-office receipt, then press Update.
            wp_nonce_field('bsg_ip_manual_' . $order->get_id(), 'bsg_ip_manual_nonce');
            echo '<p><label for="bsg_ip_manual_code"><strong>Tracking number</strong> (from your post-office receipt)</label>';
            echo '<input type="text" id="bsg_ip_manual_code" name="bsg_ip_manual_code" class="widefat" maxlength="13" placeholder="e.g. EE123456789IN" autocomplete="off" style="text-transform:uppercase"></p>';
            echo '<p><label><input type="checkbox" name="bsg_ip_manual_complete" value="1" checked> Also mark the order Completed</label></p>';
            echo '<p class="description">Press <em>Update</em>. The customer is emailed the number and can follow it on the Track your order page.</p>';
            if (BSG_IP_Settings::is_ready()) {
                $p = BSG_IP_Booking::parcel($order);
                echo '<hr><p class="description">Or book through India Post: parcel estimate <strong>' . (int) $p['weight_g'] . ' g</strong>, ' . (int) $p['l'] . '×' . (int) $p['w'] . '×' . (int) $p['h'] . ' cm. Use <em>Order actions</em> &gt; "Book with India Post".</p>';
            } else {
                echo '<hr><p class="description">India Post booking from the site is not set up. <a href="' . esc_url(admin_url('admin.php?page=bsg-india-post')) . '">Open settings</a></p>';
            }
            return;
        }
        if ($order->get_meta('_bsg_ip_manual')) {
            echo '<p><strong>' . esc_html($code) . '</strong><br>Added by hand.</p>';
            echo '<p class="description">India Post\'s API only tracks parcels booked through it. Check this number on the India Post website.</p>';
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

    /** Saves a tracking number typed into the box (runs when the order is updated). */
    public static function save_manual($order_id) {
        if (empty($_POST['bsg_ip_manual_code'])) {
            return;
        }
        $order_id = (int) $order_id;
        if (!isset($_POST['bsg_ip_manual_nonce']) || !wp_verify_nonce(wp_unslash($_POST['bsg_ip_manual_nonce']), 'bsg_ip_manual_' . $order_id)
            || !current_user_can('edit_shop_orders')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_bsg_ip_barcode')) {
            return;
        }
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', wp_unslash($_POST['bsg_ip_manual_code'])));
        if (!BSG_IP_Barcode::is_valid($code)) {
            $order->add_order_note('Tracking number "' . esc_html($code) . '" was not saved. It must be 13 characters like EE123456789IN, so please check it against the receipt.');
            return;
        }
        $order->update_meta_data('_bsg_ip_barcode', $code);
        $order->update_meta_data('_bsg_ip_state', 'in_transit');
        $order->update_meta_data('_bsg_ip_manual', 1);
        $order->update_meta_data('_bsg_ip_events', array());
        $order->save();
        $order->add_order_note('Shipped with India Post by hand. Tracking number ' . $code . '.');
        BSG_IP_Track_Page::notify_shipped($order);
        if (!empty($_POST['bsg_ip_manual_complete']) && !in_array($order->get_status(), array('completed', 'cancelled', 'refunded'), true)) {
            $order->update_status('completed', 'Marked completed when the tracking number was added.');
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
