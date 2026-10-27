<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Customer-facing tracking beyond the account area:
 *  - [bsg_track_order] page: order number + the email used at checkout, no login needed.
 *  - Shipment details in WooCommerce customer emails.
 *  - A "your parcel has shipped" email the moment an order is booked (sent as a WooCommerce customer note).
 *
 * The lookup needs BOTH the order number and the billing email to match, shows no address or phone,
 * answers every miss with the same message, and is rate-limited per visitor.
 */
class BSG_IP_Track_Page {
    const PAGE_OPTION = 'bsg_ip_track_page_id';

    public static function init() {
        add_shortcode('bsg_track_order', array(__CLASS__, 'shortcode'));
        add_action('init', array(__CLASS__, 'ensure_page'));
        add_action('woocommerce_email_after_order_table', array(__CLASS__, 'email_block'), 20, 4);
    }

    /** Creates the "Track your order" page once, so it works straight after activation. */
    public static function ensure_page() {
        $id = (int) get_option(self::PAGE_OPTION);
        if ($id && get_post_status($id)) {
            return;
        }
        $existing = get_page_by_path('track-order');
        if ($existing) {
            update_option(self::PAGE_OPTION, $existing->ID, false);
            return;
        }
        $new = wp_insert_post(array(
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'Track your order',
            'post_name'    => 'track-order',
            'post_content' => '[bsg_track_order]',
        ));
        if ($new && !is_wp_error($new)) {
            update_option(self::PAGE_OPTION, $new, false);
        }
    }

    public static function page_url() {
        $id = (int) get_option(self::PAGE_OPTION);
        return $id ? get_permalink($id) : home_url('/track-order/');
    }

    /** Where this customer should go to follow the order. */
    public static function link_for(WC_Order $order) {
        if ($order->get_customer_id()) {
            return $order->get_view_order_url();
        }
        return self::page_url();
    }

    private static function limited() {
        $key = 'bsg_ip_trk_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
        $n = (int) get_transient($key);
        if ($n >= 10) {
            return true;
        }
        set_transient($key, $n + 1, 10 * MINUTE_IN_SECONDS);
        return false;
    }

    public static function shortcode() {
        wp_enqueue_style('bsg-ip-front', BSG_IP_URL . 'assets/front.css', array(), BSG_IP_VERSION);
        $msg = '';
        $order = null;

        if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && isset($_POST['bsg_track'])) {
            $num   = preg_replace('/\D+/', '', wp_unslash($_POST['order_no'] ?? ''));
            $email = strtolower(trim(sanitize_email(wp_unslash($_POST['email'] ?? ''))));
            if (!empty($_POST['website'])) {
                $msg = 'Please try again.'; // honeypot
            } elseif (self::limited()) {
                $msg = 'Too many tries. Please wait a few minutes and try again.';
            } elseif ($num === '' || $email === '' || !is_email($email)) {
                $msg = 'Enter your order number and the email address you used at checkout.';
            } else {
                $o = wc_get_order((int) $num);
                if ($o instanceof WC_Order && !$o instanceof WC_Order_Refund && $o->get_type() === 'shop_order'
                    && hash_equals(strtolower($o->get_billing_email()), $email)) {
                    $order = $o;
                } else {
                    $msg = 'We could not find an order with those details. Check the order number and email and try again.';
                }
            }
        }

        ob_start();
        echo '<div class="bsg-track">';
        if ($order) {
            self::render_result($order);
        } else {
            if ($msg !== '') {
                echo '<p class="bsg-track-msg" role="alert">' . esc_html($msg) . '</p>';
            }
            $num = isset($_POST['order_no']) ? esc_attr(wp_unslash($_POST['order_no'])) : '';
            ?>
            <form method="post" class="bsg-track-form" action="<?php echo esc_url(self::page_url()); ?>">
                <p><label for="bsg_trk_no">Order number</label>
                    <input id="bsg_trk_no" name="order_no" type="text" inputmode="numeric" autocomplete="off" placeholder="e.g. 1042" value="<?php echo $num; ?>" required></p>
                <p><label for="bsg_trk_em">Email used at checkout</label>
                    <input id="bsg_trk_em" name="email" type="email" autocomplete="email" required></p>
                <p class="bsg-track-hp" aria-hidden="true"><label>Leave this empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
                <button type="submit" name="bsg_track" value="1">Track order</button>
                <?php if (is_user_logged_in()) : ?>
                    <p class="bsg-track-alt"><a href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>">View all my orders</a></p>
                <?php else : ?>
                    <p class="bsg-track-alt"><a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>">Sign in to see all your orders</a></p>
                <?php endif; ?>
            </form>
            <?php
        }
        echo '</div>';
        return ob_get_clean();
    }

    private static function render_result(WC_Order $order) {
        $status = wc_get_order_status_name($order->get_status());
        echo '<h2 class="bsg-track-title">Order #' . esc_html($order->get_order_number()) . '</h2>';
        echo '<p class="bsg-track-meta">' . esc_html(wc_format_datetime($order->get_date_created())) . ' &middot; ' . esc_html($status) . ' &middot; ' . esc_html(count($order->get_items())) . ' item(s)</p>';
        if ($order->get_meta('_bsg_ip_barcode')) {
            BSG_IP_Tracking::customer_card($order);
        } elseif (in_array($order->get_status(), array('cancelled', 'refunded', 'failed'), true)) {
            echo '<p class="bsg-ship-wait">This order is ' . esc_html(strtolower($status)) . '.</p>';
        } else {
            echo '<section class="bsg-ship"><p class="bsg-ship-wait">Your order is being prepared. A tracking number appears here as soon as it is handed to India Post, and we will email you too.</p></section>';
        }
        echo '<p class="bsg-track-alt"><a href="' . esc_url(self::page_url()) . '">Track another order</a></p>';
    }

    /** Tracking line in customer emails (processing, completed and so on). */
    public static function email_block($order, $sent_to_admin, $plain_text, $email) {
        if ($sent_to_admin || !$order instanceof WC_Order) {
            return;
        }
        $code = $order->get_meta('_bsg_ip_barcode');
        if (!$code) {
            return;
        }
        $labels = BSG_IP_Tracking::states();
        $state  = $order->get_meta('_bsg_ip_state') ?: 'booked';
        $status = $labels[$state] ?? $state;
        $link   = self::link_for($order);
        if ($plain_text) {
            echo "\nShipment: India Post " . $code . ' (' . $status . ")\nTrack: " . $link . "\n";
            return;
        }
        echo '<p style="margin:16px 0;"><strong>Shipment:</strong> India Post &middot; ' . esc_html($code) . ' &middot; ' . esc_html($status) . '<br>';
        echo '<a href="' . esc_url($link) . '">Track your order</a></p>';
    }

    /** Email telling the customer the parcel is on its way (a WooCommerce "customer note"). */
    public static function notify_shipped(WC_Order $order) {
        $code = $order->get_meta('_bsg_ip_barcode');
        if (!$code || $order->get_meta('_bsg_ip_notified')) {
            return;
        }
        $order->update_meta_data('_bsg_ip_notified', 1);
        $order->save();
        $note = sprintf(
            "Good news, your order #%s has been handed to India Post.\nTracking number: %s\nFollow it here: %s",
            $order->get_order_number(),
            $code,
            self::link_for($order)
        );
        $order->add_order_note($note, 1); // 1 = send to the customer
    }
}
