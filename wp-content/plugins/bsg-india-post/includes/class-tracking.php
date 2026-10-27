<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tracking: webhook (push) + scheduled poll (pull), one shared event handler,
 * a public pincode check for the storefront, and the shipment card customers see.
 *
 * Events follow the Department of Posts "Article Flow" sheets:
 *   Pickup Request Raised / Pickup Assigned / Item Pickup Cancelled / Item Pickedup / Item inducted
 *   Item Booked > Item bagged > Item Dispatched > Bag Received > Item Received > Item received at Destination
 *   > Taken out for delivery (ITEM_INVOICE) > Item Delivered(Addressee)
 *   plus Item Kept on Hold, Item Redirected, Item Returned to Sender and Item Delivered(Sender) for returns.
 */
class BSG_IP_Tracking {
    const HOOK = 'bsg_ip_poll_tracking';

    public static function states() {
        return array(
            'booked'           => 'Booked with India Post',
            'in_transit'       => 'On the way',
            'at_destination'   => 'Reached your city',
            'out_for_delivery' => 'Out for delivery',
            'on_hold'          => 'On hold',
            'redirected'       => 'Redirected',
            'returning'        => 'Returning to us',
            'delivered'        => 'Delivered',
            'returned'         => 'Returned to us',
        );
    }

    public static function init() {
        add_filter('cron_schedules', function ($s) {
            $s['bsg_two_hours'] = array('interval' => 2 * HOUR_IN_SECONDS, 'display' => 'Every 2 hours');
            return $s;
        });
        add_action('init', array(__CLASS__, 'schedule'));
        add_action(self::HOOK, array(__CLASS__, 'poll'));
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_action('woocommerce_order_details_after_order_table', array(__CLASS__, 'customer_card'), 5);
        add_action('woocommerce_thankyou', array(__CLASS__, 'customer_card'), 20);
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'maybe_auto_book'));
        add_action('bsg_ip_auto_book', array(__CLASS__, 'run_auto_book'));
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, 'bsg_two_hours', self::HOOK);
        }
    }

    /* ---------- event handling ---------- */

    /** Maps an event (description or code) to a shipment state. Returns '' for events that do not change it. */
    public static function state_for($description, $code = '') {
        $d = strtolower(trim($description));
        $c = strtoupper(trim($code));
        if ($c === 'ITEM_DELIVERY' || strpos($d, 'item delivered') === 0) {
            return strpos($d, '(sender)') !== false ? 'returned' : 'delivered';
        }
        if ($c === 'ITEM_RETURN' || strpos($d, 'returned to sender') !== false) return 'returning';
        if ($c === 'ITEM_REDIRECT' || strpos($d, 'redirected') !== false) return 'redirected';
        if ($c === 'ITEM_ONHOLD' || strpos($d, 'kept on hold') !== false) return 'on_hold';
        if ($c === 'ITEM_INVOICE' || strpos($d, 'out for delivery') !== false || strpos($d, 'invoiced') !== false) return 'out_for_delivery';
        if (strpos($d, 'received at destination') !== false) return 'at_destination';
        if (in_array($c, array('BAG_DISPATCH', 'BAG_OPEN'), true) || preg_match('/dispatch|bag received|item received|bagged|bag open/', $d)) return 'in_transit';
        if ($c === 'ITEM_BOOK' || strpos($d, 'item booked') !== false) return 'booked';
        return ''; // pickup-stage events and anything unknown are kept in the timeline only
    }

    public static function find_order($barcode) {
        $barcode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $barcode));
        if (!BSG_IP_Barcode::is_valid($barcode)) {
            return null;
        }
        $ids = wc_get_orders(array('limit' => 1, 'return' => 'ids', 'meta_query' => array(array('key' => '_bsg_ip_barcode', 'value' => $barcode))));
        return $ids ? wc_get_order($ids[0]) : null;
    }

    /** Records one event on an order and moves the shipment state. Returns true when it was new. */
    public static function apply_event(WC_Order $order, array $ev) {
        $events = (array) $order->get_meta('_bsg_ip_events');
        $key = md5(($ev['description'] ?? '') . '|' . ($ev['when'] ?? '') . '|' . ($ev['office'] ?? ''));
        foreach ($events as $e) {
            if (($e['key'] ?? '') === $key) {
                return false;
            }
        }
        $ev['key'] = $key;
        array_unshift($events, $ev);
        $events = array_slice($events, 0, 60);
        $order->update_meta_data('_bsg_ip_events', $events);

        $new = self::state_for($ev['description'] ?? '', $ev['code'] ?? '');
        $old = $order->get_meta('_bsg_ip_state');
        $final = array('delivered', 'returned');
        // Events can arrive out of order; progress only moves forward, except for the exception states.
        $rank = array('booked' => 1, 'in_transit' => 2, 'at_destination' => 3, 'out_for_delivery' => 4);
        $forward = !isset($rank[$new]) || !isset($rank[$old]) || $rank[$new] >= $rank[$old];
        if ($new !== '' && !in_array($old, $final, true) && $forward) {
            $order->update_meta_data('_bsg_ip_state', $new);
        }
        $order->save();

        $note = 'India Post: ' . ($ev['description'] ?? '') . (!empty($ev['office']) ? ' at ' . $ev['office'] : '') . (!empty($ev['reason']) ? ' (' . $ev['reason'] . ')' : '');
        $order->add_order_note($note);

        if ($new === 'delivered' && !in_array($order->get_status(), array('completed', 'cancelled', 'refunded'), true)) {
            $order->update_status('completed', 'Delivered by India Post.');
        }
        if ($new === 'returning' || $new === 'returned') {
            $order->add_order_note('Parcel is coming back to us. Check the order and decide on a refund or re-ship.');
        }
        return true;
    }

    /* ---------- pull: scheduled poll ---------- */

    public static function poll() {
        if (!BSG_IP_Settings::is_ready()) {
            return;
        }
        $ids = wc_get_orders(array(
            'limit' => 200, 'return' => 'ids', 'orderby' => 'date', 'order' => 'ASC',
            'meta_query' => array(
                array('key' => '_bsg_ip_barcode', 'compare' => 'EXISTS'),
                array('key' => '_bsg_ip_state', 'value' => array('delivered', 'returned'), 'compare' => 'NOT IN'),
            ),
        ));
        if (!$ids) {
            return;
        }
        $map = array();
        $byCustomer = array();
        foreach ($ids as $id) {
            $o = wc_get_order($id);
            if (!$o) continue;
            $code = $o->get_meta('_bsg_ip_barcode');
            $map[$code] = $o;
            $byCustomer[(string) $o->get_meta('_bsg_ip_customer')][] = $code;
        }
        foreach ($byCustomer as $cust => $codes) {
            foreach (array_chunk($codes, 500) as $chunk) {
                self::sync($chunk, $map, (string) $cust);
            }
        }
    }

    /** Refresh a single order now (admin button). */
    public static function refresh(WC_Order $order) {
        $code = $order->get_meta('_bsg_ip_barcode');
        if (!$code) {
            return array(false, 'Not booked yet.');
        }
        $n = self::sync(array($code), array($code => $order), (string) $order->get_meta('_bsg_ip_customer'));
        return array(true, $n . ' new tracking event(s).');
    }

    private static function sync(array $codes, array $map, $customer = '') {
        $r = (new BSG_IP_Client($customer))->track($codes);
        $new = 0;
        if (!$r['ok'] || empty($r['data']['data']) || !is_array($r['data']['data'])) {
            return 0;
        }
        foreach ($r['data']['data'] as $row) {
            $code = $row['booking_details']['article_number'] ?? '';
            if (!isset($map[$code])) {
                continue;
            }
            $evs = array_reverse((array) ($row['tracking_details'] ?? array())); // oldest first
            foreach ($evs as $e) {
                if (self::apply_event($map[$code], array(
                    'description' => (string) ($e['event'] ?? ''),
                    'code'        => '',
                    'when'        => (string) ($e['date'] ?? ''),
                    'office'      => (string) ($e['office'] ?? ''),
                    'reason'      => (string) ($e['remarks'] ?? ''),
                ))) {
                    $new++;
                }
            }
        }
        return $new;
    }

    /* ---------- push: webhook + public pincode check ---------- */

    public static function routes() {
        register_rest_route('bsg-india-post/v1', '/events', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'webhook'),
            'permission_callback' => array(__CLASS__, 'webhook_allowed'),
        ));
        register_rest_route('bsg-india-post/v1', '/pincode/(?P<pin>\d{6})', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'pincode'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function webhook_allowed(WP_REST_Request $req) {
        $s = BSG_IP_Settings::get();
        $key = (string) $req->get_param('k');
        if ($s['webhook_key'] === '' || !hash_equals($s['webhook_key'], $key)) {
            return false;
        }
        $ips = array_filter(array_map('trim', explode(',', (string) $s['webhook_ips'])));
        if ($ips && !in_array($_SERVER['REMOTE_ADDR'] ?? '', $ips, true)) {
            return false;
        }
        return true;
    }

    public static function webhook(WP_REST_Request $req) {
        $body = $req->get_json_params();
        if (!is_array($body)) {
            return new WP_REST_Response(array('received' => 0, 'error' => 'JSON body expected'), 400);
        }
        $list = isset($body['article_number']) ? array($body) : $body;
        $list = array_slice($list, 0, 200);
        $n = 0;
        foreach ($list as $e) {
            if (!is_array($e) || empty($e['article_number'])) continue;
            $order = self::find_order($e['article_number']);
            if (!$order) continue; // not ours or not booked here: acknowledge and ignore
            $when = trim(($e['event_date'] ?? '') . ' ' . ($e['event_time'] ?? ''));
            if (self::apply_event($order, array(
                'description' => sanitize_text_field($e['event_description'] ?? ''),
                'code'        => sanitize_text_field($e['event_code'] ?? ''),
                'when'        => sanitize_text_field($when),
                'office'      => sanitize_text_field($e['event_office_name'] ?? ''),
                'reason'      => sanitize_text_field($e['non_delivery_reason'] ?? ''),
            ))) {
                $n++;
            }
        }
        return new WP_REST_Response(array('received' => $n), 200);
    }

    /** Storefront: can India Post deliver to this pincode? Cached and rate-limited; never exposes errors. */
    public static function pincode(WP_REST_Request $req) {
        $pin = (string) $req['pin'];
        if (!BSG_IP_Settings::is_ready()) {
            return array('serviceable' => null);
        }
        $ip = md5($_SERVER['REMOTE_ADDR'] ?? '');
        $hits = (int) get_transient('bsg_ip_rl_' . $ip);
        if ($hits >= 30) {
            return new WP_REST_Response(array('serviceable' => null), 429);
        }
        set_transient('bsg_ip_rl_' . $ip, $hits + 1, MINUTE_IN_SECONDS);

        $cached = get_transient('bsg_ip_pin_' . $pin);
        if ($cached !== false) {
            return $cached;
        }
        $r = (new BSG_IP_Client(BSG_IP_Settings::get('customer_sp') ?: BSG_IP_Settings::get('customer_bp')))->pincode($pin);
        if (!$r['ok'] || !is_array($r['data'])) {
            return array('serviceable' => null);
        }
        $first = reset($r['data']);
        $out = array('serviceable' => BSG_IP_Client::can_deliver($r['data']), 'city' => $first['city_name'] ?? '', 'state' => $first['state_name'] ?? '');
        set_transient('bsg_ip_pin_' . $pin, $out, 12 * HOUR_IN_SECONDS);
        return $out;
    }

    /* ---------- auto-book ---------- */

    public static function maybe_auto_book($order_id) {
        if (BSG_IP_Settings::get('auto_book') === 'yes' && BSG_IP_Settings::is_ready()) {
            wp_schedule_single_event(time() + 30, 'bsg_ip_auto_book', array((int) $order_id));
        }
    }

    public static function run_auto_book($order_id) {
        $o = wc_get_order($order_id);
        if ($o && !$o->get_meta('_bsg_ip_barcode')) {
            BSG_IP_Booking::book($o);
        }
    }

    /* ---------- what the customer sees ---------- */

    public static function customer_card($order) {
        $order = is_numeric($order) ? wc_get_order($order) : $order;
        if (!$order instanceof WC_Order || !$order->get_meta('_bsg_ip_barcode')) {
            return;
        }
        $state = $order->get_meta('_bsg_ip_state') ?: 'booked';
        $labels = self::states();
        $events = array_slice((array) $order->get_meta('_bsg_ip_events'), 0, 12);
        echo '<section class="bsg-ship" aria-label="Shipment tracking">';
        echo '<h2 class="bsg-ship-title">Shipment</h2>';
        echo '<p class="bsg-ship-top"><span class="bsg-ship-state bsg-ship-state--' . esc_attr($state) . '">' . esc_html($labels[$state] ?? $state) . '</span>';
        echo '<span class="bsg-ship-code">India Post &middot; ' . esc_html($order->get_meta('_bsg_ip_barcode')) . '</span></p>';
        if ($events) {
            echo '<ol class="bsg-ship-list">';
            foreach ($events as $e) {
                echo '<li><strong>' . esc_html($e['description'] ?? '') . '</strong>';
                $bits = array_filter(array($e['office'] ?? '', $e['when'] ?? ''));
                if ($bits) echo '<span>' . esc_html(implode(' · ', $bits)) . '</span>';
                echo '</li>';
            }
            echo '</ol>';
        } else {
            echo '<p class="bsg-ship-wait">Your parcel is booked. Tracking updates appear here as it moves.</p>';
        }
        echo '</section>';
    }
}
