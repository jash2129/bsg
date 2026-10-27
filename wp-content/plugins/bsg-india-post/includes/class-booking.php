<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns a WooCommerce order into an India Post booking, and fetches the address label.
 * Order meta written:
 *   _bsg_ip_barcode   article number once booked
 *   _bsg_ip_pending   article number reserved for this order (reused if a booking attempt fails)
 *   _bsg_ip_state     booked | in_transit | at_destination | out_for_delivery | on_hold | redirected | returning | delivered | returned
 *   _bsg_ip_events    list of tracking events
 *   _bsg_ip_tariff    amount India Post charged us (INR)
 *   _bsg_ip_label     file name of the saved label PDF
 */
class BSG_IP_Booking {

    /** Dimension limits per article type, from the approach document (weight in g, sizes in cm). */
    public static function limits($type) {
        if ($type === 'SP_INLAND_DOC' || $type === '24_SPEEDPOST_DOC' || $type === '48_SPEEDPOST_DOC') {
            return array('wmax' => 500, 'l' => array(1, 42), 'w' => array(1, 29), 'h' => array(1, 2));
        }
        return array('wmax' => 35000, 'l' => array(14, 150), 'w' => array(9, 150), 'h' => array(1, 150));
    }

    /** Weight in whole grams and L/W/H in cm for an order's parcel. */
    public static function parcel(WC_Order $order) {
        $s = BSG_IP_Settings::get();
        $grams = 0;
        $l = 0; $w = 0; $h = 0;
        foreach ($order->get_items() as $item) {
            $p = $item->get_product();
            $qty = max(1, (int) $item->get_quantity());
            $pw = $p ? (float) $p->get_weight() : 0;              // store unit is kg
            $unit = wc_get_weight($pw, 'g', get_option('woocommerce_weight_unit'));
            $grams += ($unit > 0 ? $unit : (int) $s['default_weight_g']) * $qty;
            $pl = $p ? (float) $p->get_length() : 0;
            $pb = $p ? (float) $p->get_width() : 0;
            $ph = $p ? (float) $p->get_height() : 0;
            $du = get_option('woocommerce_dimension_unit');
            $pl = $pl ? wc_get_dimension($pl, 'cm', $du) : (int) $s['default_l'];
            $pb = $pb ? wc_get_dimension($pb, 'cm', $du) : (int) $s['default_w'];
            $ph = $ph ? wc_get_dimension($ph, 'cm', $du) : (int) $s['default_h'];
            $l = max($l, $pl);
            $w = max($w, $pb);
            $h += $ph * $qty;                                    // items are stacked
        }
        $grams += (int) $s['packaging_g'];
        return array('weight_g' => max(1, (int) ceil($grams)), 'l' => (int) ceil($l), 'w' => (int) ceil($w), 'h' => (int) ceil($h));
    }

    private static function lines($text, $max = 80) {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        $out = array();
        while ($text !== '' && count($out) < 3) {
            $chunk = mb_substr($text, 0, $max);
            if (mb_strlen($text) > $max) {
                $cut = mb_strrpos($chunk, ' ');
                if ($cut !== false && $cut > 20) {
                    $chunk = mb_substr($chunk, 0, $cut);
                }
            }
            $out[] = trim($chunk);
            $text = trim(mb_substr($text, mb_strlen($chunk)));
        }
        return array_pad($out, 3, '');
    }

    public static function mobile($raw) {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if (strlen($d) === 12 && strpos($d, '91') === 0) $d = substr($d, 2);
        if (strlen($d) === 11 && $d[0] === '0') $d = substr($d, 1);
        return preg_match('/^[6-9]\d{9}$/', $d) ? $d : '';
    }

    /** Builds the booking article for an order. Returns array($article, $errors). */
    public static function build_article(WC_Order $order, $barcode) {
        $s = BSG_IP_Settings::get();
        $sd = $s['sender'];
        $errors = array();
        $parcel = self::parcel($order);
        $type = $s['article_type'];
        if (in_array($type, array('SP_INLAND_DOC'), true) && $parcel['weight_g'] > 500) {
            $type = 'SP_INLAND_PARCEL';
        }
        $lim = self::limits($type);
        if ($parcel['weight_g'] > $lim['wmax']) {
            $errors[] = 'Parcel weighs ' . $parcel['weight_g'] . ' g; the limit for this article type is ' . $lim['wmax'] . ' g.';
        }
        foreach (array('l', 'w', 'h') as $k) {
            $parcel[$k] = max($lim[$k][0], min($lim[$k][1], $parcel[$k] ?: $lim[$k][0]));
        }

        $contract = $s['contracts'][$type] ?? '';
        if (!preg_match('/^\d{8}$/', (string) $contract)) $errors[] = 'No 8-digit contract ID set for ' . $type . '.';
        $cust = BSG_IP_Settings::customer_for($type);
        if (!preg_match('/^\d{10}$/', $cust)) $errors[] = 'No 10-digit customer ID set for ' . $type . '.';
        if (!preg_match('/^\d{8}$/', (string) $s['office_id'])) $errors[] = 'Post office ID must be 8 digits.';
        if (!BSG_IP_Barcode::is_valid($barcode)) $errors[] = 'No valid barcode available. Check the barcode range in the settings.';
        foreach (array('name' => 'Sender name', 'line1' => 'Sender address', 'city' => 'Sender city', 'company' => 'Sender company') as $k => $lbl) {
            if (mb_strlen(trim($sd[$k])) < 3) $errors[] = $lbl . ' is missing.';
        }
        if (!preg_match('/^\d{6}$/', $sd['pincode'])) $errors[] = 'Sender pincode must be 6 digits.';
        if (self::mobile($sd['mobile']) === '') $errors[] = 'Sender mobile must be a 10-digit number starting 6-9.';

        $name   = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
        $name   = $name !== '' ? $name : trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $addr1  = $order->get_shipping_address_1() ?: $order->get_billing_address_1();
        $addr2  = $order->get_shipping_address_2() ?: $order->get_billing_address_2();
        $city   = $order->get_shipping_city() ?: $order->get_billing_city();
        $scode  = $order->get_shipping_state() ?: $order->get_billing_state();
        $pin    = preg_replace('/\D+/', '', $order->get_shipping_postcode() ?: $order->get_billing_postcode());
        $phone  = self::mobile($order->get_shipping_phone() ?: $order->get_billing_phone());
        $states = WC()->countries->get_states('IN');
        $state  = $states[$scode] ?? $scode;
        list($r1, $r2, $r3) = self::lines(trim($addr1 . ' ' . $addr2), 80);

        if (mb_strlen($name) < 3) $errors[] = 'Receiver name needs at least 3 characters.';
        if ($r1 === '') $errors[] = 'Receiver address is missing.';
        if (mb_strlen($city) < 3) $errors[] = 'Receiver city needs at least 3 characters.';
        if (!preg_match('/^\d{6}$/', $pin)) $errors[] = 'Receiver pincode must be 6 digits.';
        if ($phone === '') $errors[] = 'Receiver mobile must be a 10-digit number starting 6-9.';

        $cod = $order->get_payment_method() === 'cod';
        $total = (float) $order->get_total();
        $insure = (int) $s['insure_over'] > 0 && $total >= (int) $s['insure_over'];
        $pickup = $s['mode'] === 'PICKUP';
        $doc = in_array($type, array('SP_INLAND_DOC', '24_SPEEDPOST_DOC', '48_SPEEDPOST_DOC'), true);
        $sl = self::lines($sd['line1'] . ' ' . $sd['line2'], 80);

        $article = array(
            'bulk_customer_id' => $cust,
            'contract_id' => (string) $contract,
            'barcode_no' => $barcode,
            'pickup_or_dropoff' => $pickup ? 'PICKUP' : 'DROPOFF',
            'pickup_dropoff_office_id' => (int) $s['office_id'],
            'article_type' => $type,
            'physical_weight' => $parcel['weight_g'],
            'shape_of_article' => $doc ? 'DOC' : 'NROL',
            'length' => $parcel['l'],
            'breadth_diameter' => $parcel['w'],
            'height' => $parcel['h'],
            'priority_flag' => 'FALSE',
            'sender_name' => mb_substr($sd['name'], 0, 80),
            'sender_company' => mb_substr($sd['company'], 0, 80),
            'sender_add_line_1' => $sl[0], 'sender_add_line_2' => $sl[1], 'sender_add_line_3' => $sl[2],
            'sender_city' => mb_substr($sd['city'], 0, 80),
            'sender_state' => mb_substr($sd['state'], 0, 80),
            'sender_pincode' => $sd['pincode'],
            'sender_emailid' => mb_substr($sd['email'], 0, 80),
            'receiver_name' => mb_substr($name, 0, 80),
            'receiver_company' => 'Individual',
            'receiver_add_line_1' => $r1, 'receiver_add_line_2' => $r2, 'receiver_add_line_3' => $r3,
            'receiver_city' => mb_substr($city, 0, 80),
            'receiver_state' => mb_substr($state, 0, 80),
            'receiver_pincode' => $pin,
            'receiver_emailid' => mb_substr($order->get_billing_email(), 0, 80),
            'alt_address_flag' => 'FALSE',
            'pickup_address_flag' => $pickup ? 'TRUE' : 'FALSE',
            'drop_off_pincode' => $pickup ? '' : $sd['pincode'],
            'sender_mobile_no' => self::mobile($sd['mobile']),
            'receiver_mobile_no' => $phone,
            'codr_cod' => $cod ? 'COD' : '',
            'value_for_codr_cod' => $cod ? round($total, 2) : '',
            'insurance_type' => $insure ? 'DOP' : '',
            'value_of_insurance' => $insure ? round($total, 2) : '',
            'Ack' => 'FALSE',
            'Reg' => 'FALSE',
            'Otp' => $type === '24_SPP_PARSPL' ? 'TRUE' : 'FALSE',
            'bulk_reference' => mb_substr((string) $order->get_order_number(), 0, 50),
        );
        if ($pickup) {
            $when = new DateTime('tomorrow 10:00', wp_timezone());
            $article += array(
                'pickup_addressee_name' => $article['sender_name'],
                'pickup_company_name' => $article['sender_company'],
                'pickup_address_line1' => $sl[0], 'pickup_address_line2' => $sl[1], 'pickup_address_line3' => $sl[2],
                'pickup_city' => $article['sender_city'], 'pickup_state' => $article['sender_state'],
                'pickup_pincode' => $sd['pincode'], 'pickup_email_id' => $article['sender_emailid'],
                'pickup_mobile_no' => $article['sender_mobile_no'],
                'pickup_schedule_slot' => $s['pickup_slot'],
                'pickup_schedule_date' => $when->format('m/d/Y h:i:s A'),
            );
        }
        return array($article, $errors, $parcel);
    }

    /** Books one order. Returns array(ok, message). */
    /** Books one order. A number India Post says is already used is skipped and the next one tried (up to 6 times). */
    public static function book(WC_Order $order, $depth = 0) {
        if (!BSG_IP_Settings::is_ready()) {
            return array(false, 'India Post is not enabled or credentials are missing.');
        }
        if ($order->get_meta('_bsg_ip_barcode')) {
            return array(false, 'Already booked: ' . $order->get_meta('_bsg_ip_barcode'));
        }
        $barcode = $order->get_meta('_bsg_ip_pending');
        if (!$barcode) {
            $barcode = BSG_IP_Barcode::next();
            if ($barcode) {
                $order->update_meta_data('_bsg_ip_pending', $barcode);
                $order->save();
            }
        }
        list($article, $errors, $parcel) = self::build_article($order, $barcode);
        if ($errors) {
            $order->add_order_note('India Post booking not sent: ' . implode(' ', $errors));
            return array(false, implode(' ', $errors));
        }

        $client = new BSG_IP_Client($article['bulk_customer_id']);
        $r = $client->book(array($article), $article['bulk_customer_id']);
        $data = is_array($r['data']) ? $r['data'] : array();
        if (!empty($data['valid_articles'])) {
            $v = $data['valid_articles'][0];
            $order->update_meta_data('_bsg_ip_barcode', $barcode);
            $order->update_meta_data('_bsg_ip_state', 'booked');
            $order->update_meta_data('_bsg_ip_type', $article['article_type']);
            $order->update_meta_data('_bsg_ip_customer', $article['bulk_customer_id']);
            $order->update_meta_data('_bsg_ip_tariff', (float) ($v['calculated_tariff'] ?? 0));
            $order->update_meta_data('_bsg_ip_batch', (string) ($data['batch_id'] ?? ''));
            $order->update_meta_data('_bsg_ip_events', array());
            $order->delete_meta_data('_bsg_ip_pending');
            $order->save();
            $order->add_order_note(sprintf('Booked with India Post. Article %s, %d g, tariff ₹%s.', $barcode, $parcel['weight_g'], (string) ($v['calculated_tariff'] ?? '?')));
            BSG_IP_Track_Page::notify_shipped($order);
            return array(true, 'Booked: ' . $barcode);
        }
        $msg = $r['error'];
        if (!empty($data['error_articles'][0]['errors'])) {
            $msg = implode('; ', (array) $data['error_articles'][0]['errors']);
        }
        if (stripos((string) $msg, 'duplicate') !== false && $depth < 6) {
            $order->add_order_note('India Post: article number ' . $barcode . ' was already in use. Trying the next number.');
            $order->delete_meta_data('_bsg_ip_pending');
            $order->save();
            return self::book($order, $depth + 1);
        }
        $order->add_order_note('India Post booking failed: ' . ($msg ?: 'unknown error'));
        return array(false, $msg ?: 'Booking failed.');
    }

    /** Label for a booked order; saved as a PDF in a protected folder. Returns array(ok, message). */
    public static function make_label(WC_Order $order) {
        $barcode = $order->get_meta('_bsg_ip_barcode');
        if (!$barcode) {
            return array(false, 'Book the order first.');
        }
        $s = BSG_IP_Settings::get();
        $sd = $s['sender'];
        $parcel = self::parcel($order);
        $type = $order->get_meta('_bsg_ip_type') ?: $s['article_type'];
        $name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()) ?: trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $addr = trim(($order->get_shipping_address_1() ?: $order->get_billing_address_1()) . ' ' . ($order->get_shipping_address_2() ?: $order->get_billing_address_2()));
        list($a1, $a2, $a3) = self::lines($addr, 80);
        list($s1, $s2, $s3) = self::lines($sd['line1'] . ' ' . $sd['line2'], 80);
        $states = WC()->countries->get_states('IN');
        $scode = $order->get_shipping_state() ?: $order->get_billing_state();
        $label = array(
            'customer_id' => (int) BSG_IP_Settings::customer_for($type),
            'destination_pin' => preg_replace('/\D+/', '', $order->get_shipping_postcode() ?: $order->get_billing_postcode()),
            'booking_datetime' => wp_date('d-m-Y H:i:s'),
            'channel_type' => 'E',
            'user_type' => 'R',
            'user_id' => (int) BSG_IP_Settings::customer_for($type),
            'barcode_no' => $barcode,
            'service_type' => $type === 'BUSINESS_PARCEL' ? 'BP' : 'SP',
            'booking_type' => 'COMMERCIAL',
            'article_length' => (string) $parcel['l'], 'article_breadth' => (string) $parcel['w'], 'article_height' => (string) $parcel['h'],
            'charged_weight' => $parcel['weight_g'], 'physical_weight' => $parcel['weight_g'],
            'recipient_name' => $name,
            'recipient_mobile' => self::mobile($order->get_shipping_phone() ?: $order->get_billing_phone()),
            'recipient_addressl1' => $a1, 'recipient_addressl2' => $a2, 'recipient_addressl3' => $a3,
            'recipient_city' => $order->get_shipping_city() ?: $order->get_billing_city(),
            'recipient_pin' => preg_replace('/\D+/', '', $order->get_shipping_postcode() ?: $order->get_billing_postcode()),
            'recipient_state' => $states[$scode] ?? $scode,
            'sender_name' => $sd['name'], 'sender_mobile' => self::mobile($sd['mobile']),
            'sender_addressl1' => $s1, 'sender_addressl2' => $s2, 'sender_addressl3' => $s3,
            'sender_city' => $sd['city'], 'sender_pin' => $sd['pincode'], 'sender_state' => $sd['state'],
            'transmission_mode' => 'S',
            'payment_mode' => 'CO',
            'booking_office_name' => $s['office_name'] ?: $sd['city'],
            'booking_office_pin' => $s['office_pin'] ?: $sd['pincode'],
            'size' => 'A6',
            'total_amount' => (float) $order->get_total(),
            'payment_status' => 'PC',
            'identifier' => 'Domestic',
            'bkg_ref_id' => (string) $order->get_order_number(),
            'priority' => false,
            'registered_flag' => false,
        );
        $r = (new BSG_IP_Client($order->get_meta('_bsg_ip_customer') ?: BSG_IP_Settings::customer_for($type)))->label(array($label));
        if (!$r['ok'] || !is_string($r['data']) || strpos($r['data'], '%PDF') !== 0) {
            return array(false, $r['error'] ?: 'India Post did not return a label.');
        }
        $dir = self::label_dir();
        if (!$dir) {
            return array(false, 'Could not create the labels folder.');
        }
        $file = 'label-' . $order->get_id() . '-' . wp_generate_password(12, false) . '.pdf';
        file_put_contents($dir . '/' . $file, $r['data']);
        $order->update_meta_data('_bsg_ip_label', $file);
        $order->save();
        return array(true, 'Label ready.');
    }

    public static function label_dir() {
        $u = wp_upload_dir();
        $dir = $u['basedir'] . '/bsg-india-post';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return '';
        }
        // Labels hold customer addresses: block direct web access; they are served only to signed-in staff.
        if (!file_exists($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!file_exists($dir . '/index.php')) {
            file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }
        return $dir;
    }
}
