<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings: WooCommerce > India Post.
 *
 * Credentials can live in wp-config.php instead of the database (recommended):
 *   define('BSG_IP_USERNAME', '...');
 *   define('BSG_IP_PASSWORD', '...');
 * A constant always wins over the saved value and hides the field.
 */
class BSG_IP_Settings {
    const OPTION = 'bsg_ip_settings';

    /** Article types the approach document lists, with the contract each one books under. */
    public static function article_types() {
        return array(
            'SP_INLAND_PARCEL' => 'Speed Post Parcel (over 500 g)',
            'SP_INLAND_DOC'    => 'Speed Post Document (up to 500 g)',
            'BUSINESS_PARCEL'  => 'Business Parcel (contract)',
            '24_SPP_PARSPL'    => '24-hour Parcel Special',
            '24_SPEEDPOST_DOC' => '24-hour Speed Post Document',
            '48_SPEEDPOST_DOC' => '48-hour Speed Post Document',
        );
    }

    public static function defaults() {
        return array(
            'enabled'      => 'no',
            'environment'  => 'uat',
            'base_url_uat' => 'https://test.cept.gov.in/beextcustomer',
            'base_url_live' => 'https://app.indiapost.gov.in/beextcustomer',
            'username'     => '',
            'password'     => '',
            'customer_sp'  => '',
            'customer_bp'  => '',
            'contracts'    => array(),
            'article_type' => 'SP_INLAND_PARCEL',
            'mode'         => 'DROPOFF',
            'office_id'    => '',
            'office_name'  => '',
            'office_pin'   => '',
            'pickup_slot'  => '10:00-13:00',
            'barcode_prefix' => 'EK',
            'barcode_next'   => '',
            'barcode_end'    => '',
            'sender'       => array('name' => '', 'company' => 'BSG Garments', 'line1' => '', 'line2' => '', 'city' => '', 'state' => '', 'pincode' => '', 'mobile' => '', 'email' => ''),
            'default_weight_g' => 500,
            'default_l' => 30,
            'default_w' => 25,
            'default_h' => 5,
            'packaging_g'  => 50,
            'insure_over'  => 0,
            'auto_book'    => 'no',
            'webhook_key'  => '',
            'webhook_ips'  => '',
        );
    }

    public static function ensure_defaults() {
        $s = get_option(self::OPTION);
        if (!is_array($s)) {
            $s = array();
        }
        $s = array_replace_recursive(self::defaults(), $s);
        if ($s['webhook_key'] === '') {
            $s['webhook_key'] = wp_generate_password(32, false);
        }
        update_option(self::OPTION, $s, false);
    }

    public static function get($key = null) {
        $s = get_option(self::OPTION);
        $s = is_array($s) ? array_replace_recursive(self::defaults(), $s) : self::defaults();
        if (defined('BSG_IP_USERNAME')) { $s['username'] = BSG_IP_USERNAME; }
        if (defined('BSG_IP_PASSWORD')) { $s['password'] = BSG_IP_PASSWORD; }
        return $key === null ? $s : ($s[$key] ?? null);
    }

    /** The bulk customer ID India Post issued for this article type (Speed Post and Business Parcel have their own). */
    public static function customer_for($type) {
        $s = self::get();
        return $type === 'BUSINESS_PARCEL' ? (string) $s['customer_bp'] : (string) $s['customer_sp'];
    }

    public static function base_url() {
        $s = self::get();
        $url = $s['environment'] === 'production' ? ($s['base_url_live'] ?: 'https://app.indiapost.gov.in/beextcustomer') : $s['base_url_uat'];
        return untrailingslashit(self::safe_base($url));
    }

    /** Only https hosts under gov.in are accepted, so credentials can never be sent anywhere else. */
    public static function safe_base($url) {
        $p = wp_parse_url((string) $url);
        if (empty($p['scheme']) || empty($p['host']) || $p['scheme'] !== 'https') {
            return '';
        }
        return preg_match('/(^|\.)gov\.in$/i', $p['host']) ? esc_url_raw($url) : '';
    }

    public static function is_ready() {
        $s = self::get();
        return $s['enabled'] === 'yes' && self::base_url() !== '' && ($s['environment'] === 'production' || $s['username'] !== '') && $s['password'] !== '' && ($s['customer_sp'] !== '' || $s['customer_bp'] !== '');
    }

    public static function init() {
        add_action('admin_menu', function () {
            add_submenu_page('woocommerce', 'India Post', 'India Post', 'manage_woocommerce', 'bsg-india-post', array(__CLASS__, 'render'));
        });
        add_action('admin_post_bsg_ip_save', array(__CLASS__, 'save'));
        add_action('admin_post_bsg_ip_test', array(__CLASS__, 'test_connection'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
    }

    public static function notice() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos($screen->id, 'bsg-india-post') === false || empty($_GET['bsg_ip_msg'])) {
            return;
        }
        $type = !empty($_GET['bsg_ip_ok']) ? 'success' : 'error';
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html(wp_unslash($_GET['bsg_ip_msg'])));
    }

    private static function redirect($msg, $ok) {
        wp_safe_redirect(add_query_arg(array('page' => 'bsg-india-post', 'bsg_ip_msg' => rawurlencode($msg), 'bsg_ip_ok' => $ok ? 1 : 0), admin_url('admin.php')));
        exit;
    }

    public static function save() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Not allowed');
        }
        check_admin_referer('bsg_ip_save');
        $in  = isset($_POST['s']) ? (array) wp_unslash($_POST['s']) : array();
        $old = self::get();
        $t   = function ($v) { return sanitize_text_field(is_scalar($v) ? (string) $v : ''); };

        $s = $old;
        $s['enabled']     = ($in['enabled'] ?? '') === 'yes' ? 'yes' : 'no';
        $s['environment'] = ($in['environment'] ?? '') === 'production' ? 'production' : 'uat';
        $s['base_url_uat']  = self::safe_base($in['base_url_uat'] ?? '') ?: $old['base_url_uat'];
        $s['base_url_live'] = self::safe_base($in['base_url_live'] ?? '');
        $s['username']    = $t($in['username'] ?? '');
        if (($in['password'] ?? '') !== '') {
            $s['password'] = (string) $in['password']; // blank keeps the saved one
        }
        $s['customer_sp'] = preg_replace('/\D+/', '', $in['customer_sp'] ?? '');
        $s['customer_bp'] = preg_replace('/\D+/', '', $in['customer_bp'] ?? '');
        $contracts = array();
        foreach (array_keys(self::article_types()) as $type) {
            $contracts[$type] = preg_replace('/\D+/', '', $in['contracts'][$type] ?? '');
        }
        $s['contracts']   = $contracts;
        $s['article_type'] = isset(self::article_types()[$in['article_type'] ?? '']) ? $in['article_type'] : 'SP_INLAND_PARCEL';
        $s['mode']        = ($in['mode'] ?? '') === 'PICKUP' ? 'PICKUP' : 'DROPOFF';
        $s['office_id']   = preg_replace('/\D+/', '', $in['office_id'] ?? '');
        $s['office_name'] = $t($in['office_name'] ?? '');
        $s['office_pin']  = preg_replace('/\D+/', '', $in['office_pin'] ?? '');
        $s['pickup_slot'] = in_array($in['pickup_slot'] ?? '', array('10:00-13:00', '13:00-16:00'), true) ? $in['pickup_slot'] : '10:00-13:00';
        $s['barcode_prefix'] = strtoupper(preg_replace('/[^A-Za-z]/', '', substr($in['barcode_prefix'] ?? 'EK', 0, 2)));
        $s['barcode_next']   = preg_replace('/\D+/', '', $in['barcode_next'] ?? '');
        $s['barcode_end']    = preg_replace('/\D+/', '', $in['barcode_end'] ?? '');
        foreach (array('name', 'company', 'line1', 'line2', 'city', 'state', 'email') as $k) {
            $s['sender'][$k] = $t($in['sender'][$k] ?? '');
        }
        $s['sender']['pincode'] = preg_replace('/\D+/', '', $in['sender']['pincode'] ?? '');
        $s['sender']['mobile']  = preg_replace('/\D+/', '', $in['sender']['mobile'] ?? '');
        foreach (array('default_weight_g', 'default_l', 'default_w', 'default_h', 'packaging_g', 'insure_over') as $k) {
            $s[$k] = max(0, (int) ($in[$k] ?? 0));
        }
        $s['auto_book']   = ($in['auto_book'] ?? '') === 'yes' ? 'yes' : 'no';
        $s['webhook_ips'] = $t($in['webhook_ips'] ?? '');

        update_option(self::OPTION, $s, false);
        BSG_IP_Client::forget_token();
        self::redirect('Settings saved.', true);
    }

    public static function test_connection() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Not allowed');
        }
        check_admin_referer('bsg_ip_test');
        $st = self::get();
        $targets = $st['environment'] === 'production' ? array_values(array_unique(array_filter(array($st['customer_sp'], $st['customer_bp'])))) : array('');
        if (!$targets) {
            self::redirect('Enter at least one customer ID first.', false);
        }
        $parts = array();
        foreach ($targets as $cust) {
            $client = new BSG_IP_Client($cust);
            $r = $client->login(true);
            $tag = $cust !== '' ? 'Customer ' . $cust : 'Login';
            if (!$r['ok']) {
                self::redirect($tag . ' failed: ' . $r['error'], false);
            }
            $parts[] = $tag . ' OK';
        }
        $msg = 'Connected. ' . implode('; ', $parts) . '.';
        $pin = $st['sender']['pincode'];
        if ($pin) {
            $p = $client->pincode($pin);
            $msg .= $p['ok'] ? ' Pincode ' . $pin . ' found (' . count((array) $p['data']) . ' offices).' : ' Pincode lookup failed: ' . $p['error'];
        }
        self::redirect($msg, true);
    }

    public static function render() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        self::ensure_defaults();
        $s   = self::get();
        $url = esc_url(admin_url('admin-post.php'));
        $webhook = esc_url(rest_url('bsg-india-post/v1/events') . '?k=' . $s['webhook_key']);
        $fixed_user = defined('BSG_IP_USERNAME');
        $fixed_pass = defined('BSG_IP_PASSWORD');
        $f = function ($name, $val, $type = 'text', $extra = '') {
            printf('<input type="%s" name="s[%s]" value="%s" class="regular-text" %s>', esc_attr($type), esc_attr($name), esc_attr($val), $extra);
        };
        ?>
        <div class="wrap">
            <h1>India Post shipping</h1>
            <?php if (!BSG_IP_Settings::is_ready()) : ?>
                <div class="notice notice-warning inline"><p><strong>Not active yet.</strong> Enter the credentials India Post sends you, tick "Enable" and save. Until then nothing is sent to India Post and orders ship the manual way.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo $url; ?>">
                <input type="hidden" name="action" value="bsg_ip_save">
                <?php wp_nonce_field('bsg_ip_save'); ?>

                <h2>Connection</h2>
                <table class="form-table" role="presentation">
                    <tr><th>Enable</th><td><label><input type="checkbox" name="s[enabled]" value="yes" <?php checked($s['enabled'], 'yes'); ?>> Use India Post for shipping</label></td></tr>
                    <tr><th>Environment</th><td>
                        <select name="s[environment]">
                            <option value="uat" <?php selected($s['environment'], 'uat'); ?>>UAT (testing, sandbox credentials)</option>
                            <option value="production" <?php selected($s['environment'], 'production'); ?>>Production (live)</option>
                        </select>
                    </td></tr>
                    <tr><th>UAT address</th><td><?php $f('base_url_uat', $s['base_url_uat'], 'url'); ?></td></tr>
                    <tr><th>Production address</th><td><?php $f('base_url_live', $s['base_url_live'], 'url', 'placeholder="https://… (given with production credentials)"'); ?><p class="description">Only https addresses ending in gov.in are accepted.</p></td></tr>
                    <tr><th>Username</th><td><?php if ($fixed_user) { echo '<em>Set in wp-config.php</em>'; } else { $f('username', $s['username'], 'text', 'autocomplete="off"'); } ?><p class="description">Sandbox (UAT) only. In production the customer ID below is the login name.</p></td></tr>
                    <tr><th>Password</th><td><?php if ($fixed_pass) { echo '<em>Set in wp-config.php</em>'; } else { $f('password', '', 'password', 'autocomplete="new-password" placeholder="' . ($s['password'] !== '' ? '•••••••• (saved, leave blank to keep)' : '') . '"'); ?><p class="description">Safer: define BSG_IP_USERNAME and BSG_IP_PASSWORD in wp-config.php instead.</p><?php } ?></td></tr>
                    <tr><th>Speed Post customer ID</th><td><?php $f('customer_sp', $s['customer_sp'], 'text', 'inputmode="numeric" maxlength="10"'); ?> <span class="description">10 digits, used for Speed Post and the 24h/48h products</span></td></tr>
                    <tr><th>Business Parcel customer ID</th><td><?php $f('customer_bp', $s['customer_bp'], 'text', 'inputmode="numeric" maxlength="10"'); ?> <span class="description">10 digits, used for Business Parcel</span></td></tr>
                </table>

                <h2>Contracts</h2>
                <p class="description">One 8-digit contract ID per article type. Only the types you use need one.</p>
                <table class="form-table" role="presentation">
                    <?php foreach (self::article_types() as $code => $label) : ?>
                        <tr><th><?php echo esc_html($label); ?><br><code><?php echo esc_html($code); ?></code></th><td><input type="text" name="s[contracts][<?php echo esc_attr($code); ?>]" value="<?php echo esc_attr($s['contracts'][$code] ?? ''); ?>" class="regular-text" inputmode="numeric" maxlength="8"></td></tr>
                    <?php endforeach; ?>
                </table>

                <h2>Booking</h2>
                <table class="form-table" role="presentation">
                    <tr><th>Default article type</th><td><select name="s[article_type]"><?php foreach (self::article_types() as $code => $label) { printf('<option value="%s" %s>%s</option>', esc_attr($code), selected($s['article_type'], $code, false), esc_html($label)); } ?></select>
                        <p class="description">Clothing is a parcel: use Speed Post Parcel or Business Parcel. Orders under 500 g still ship as parcels.</p></td></tr>
                    <tr><th>Hand-over</th><td><select name="s[mode]"><option value="DROPOFF" <?php selected($s['mode'], 'DROPOFF'); ?>>We drop parcels at a post office</option><option value="PICKUP" <?php selected($s['mode'], 'PICKUP'); ?>>Postman collects from us (pickup)</option></select></td></tr>
                    <tr><th>Post office ID</th><td><?php $f('office_id', $s['office_id'], 'text', 'inputmode="numeric" maxlength="8"'); ?> <span class="description">8-digit office ID for drop-off or pickup (find it with the pincode search)</span></td></tr>
                    <tr><th>Post office name and pincode</th><td><?php $f('office_name', $s['office_name'], 'text', 'placeholder="e.g. Hyderabad GPO"'); ?> <?php $f('office_pin', $s['office_pin'], 'text', 'inputmode="numeric" maxlength="6" style="width:90px" placeholder="pincode"'); ?> <span class="description">Printed on the label as the booking office</span></td></tr>
                    <tr><th>Pickup slot</th><td><select name="s[pickup_slot]"><option <?php selected($s['pickup_slot'], '10:00-13:00'); ?>>10:00-13:00</option><option <?php selected($s['pickup_slot'], '13:00-16:00'); ?>>13:00-16:00</option></select></td></tr>
                    <tr><th>Auto-book</th><td><label><input type="checkbox" name="s[auto_book]" value="yes" <?php checked($s['auto_book'], 'yes'); ?>> Book automatically when an order moves to Processing</label><p class="description">Leave off at first and use the "Book with India Post" button on each order.</p></td></tr>
                </table>

                <h2>Barcode series</h2>
                <p class="description">India Post assigns a range of article numbers (for example ET21433001 to ET21434000). Enter the 2 letters and the first and last 8-digit serials. The check digit and "IN" are added for you.</p>
                <table class="form-table" role="presentation">
                    <tr><th>Prefix (2 letters)</th><td><?php $f('barcode_prefix', $s['barcode_prefix'], 'text', 'maxlength="2" style="width:70px"'); ?></td></tr>
                    <tr><th>Next serial</th><td><?php $f('barcode_next', $s['barcode_next'], 'text', 'inputmode="numeric" maxlength="8"'); ?></td></tr>
                    <tr><th>Last serial in range</th><td><?php $f('barcode_end', $s['barcode_end'], 'text', 'inputmode="numeric" maxlength="8"'); ?></td></tr>
                </table>

                <h2>Sender (your warehouse)</h2>
                <table class="form-table" role="presentation">
                    <tr><th>Name</th><td><?php $f('sender][name', $s['sender']['name']); ?></td></tr>
                    <tr><th>Company</th><td><?php $f('sender][company', $s['sender']['company']); ?></td></tr>
                    <tr><th>Address line 1</th><td><?php $f('sender][line1', $s['sender']['line1']); ?></td></tr>
                    <tr><th>Address line 2</th><td><?php $f('sender][line2', $s['sender']['line2']); ?></td></tr>
                    <tr><th>City</th><td><?php $f('sender][city', $s['sender']['city']); ?></td></tr>
                    <tr><th>State</th><td><?php $f('sender][state', $s['sender']['state']); ?></td></tr>
                    <tr><th>Pincode</th><td><?php $f('sender][pincode', $s['sender']['pincode'], 'text', 'inputmode="numeric" maxlength="6"'); ?></td></tr>
                    <tr><th>Mobile</th><td><?php $f('sender][mobile', $s['sender']['mobile'], 'tel', 'inputmode="numeric" maxlength="10"'); ?></td></tr>
                    <tr><th>Email</th><td><?php $f('sender][email', $s['sender']['email'], 'email'); ?></td></tr>
                </table>

                <h2>Parcel defaults</h2>
                <p class="description">Used when a product has no weight or size set. India Post needs whole grams and all three dimensions for parcels.</p>
                <table class="form-table" role="presentation">
                    <tr><th>Weight (g)</th><td><?php $f('default_weight_g', $s['default_weight_g'], 'number', 'min="1" style="width:100px"'); ?></td></tr>
                    <tr><th>Size L × W × H (cm)</th><td><?php $f('default_l', $s['default_l'], 'number', 'style="width:70px"'); ?> × <?php $f('default_w', $s['default_w'], 'number', 'style="width:70px"'); ?> × <?php $f('default_h', $s['default_h'], 'number', 'style="width:70px"'); ?></td></tr>
                    <tr><th>Packaging weight (g)</th><td><?php $f('packaging_g', $s['packaging_g'], 'number', 'min="0" style="width:100px"'); ?></td></tr>
                    <tr><th>Insure orders over (₹)</th><td><?php $f('insure_over', $s['insure_over'], 'number', 'min="0" style="width:100px"'); ?> <span class="description">0 = never insure</span></td></tr>
                </table>

                <h2>Live tracking (webhook)</h2>
                <p class="description">Give India Post this address and your server's fixed IP so they can push tracking events. The key in the address is the shared secret; keep it private.</p>
                <table class="form-table" role="presentation">
                    <tr><th>Webhook address</th><td><input type="text" class="large-text code" readonly value="<?php echo $webhook; ?>" onclick="this.select()"></td></tr>
                    <tr><th>Allowed sender IPs</th><td><?php $f('webhook_ips', $s['webhook_ips']); ?> <span class="description">Optional, comma-separated. Empty = accept any IP that has the key.</span></td></tr>
                </table>

                <?php submit_button('Save settings'); ?>
            </form>

            <hr>
            <form method="post" action="<?php echo $url; ?>">
                <input type="hidden" name="action" value="bsg_ip_test">
                <?php wp_nonce_field('bsg_ip_test'); ?>
                <p>Checks the login and a pincode lookup against the selected environment.</p>
                <?php submit_button('Test connection', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }
}
