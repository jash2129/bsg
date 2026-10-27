<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ---------------------------------------------------------------------------
 * Accounts and checkout
 * - An account is needed to place an order, so every customer has a mobile
 *   number and a full delivery address on file for dispatch and COD calls.
 * - Sign in / Create account tabs, mobile number at sign-up.
 * - A real My Account dashboard with a profile-completion meter.
 * ---------------------------------------------------------------------------
 */

/**
 * Store rules, enforced in code so they hold on every environment
 * (local, staging and live) without depending on what is saved in the database.
 */
add_filter('pre_option_woocommerce_enable_guest_checkout', function () { return 'no'; });
add_filter('pre_option_woocommerce_enable_signup_and_login_from_checkout', function () { return 'yes'; });
add_filter('pre_option_woocommerce_enable_myaccount_registration', function () { return 'yes'; });
add_filter('pre_option_woocommerce_registration_generate_password', function () { return 'no'; });
add_filter('pre_option_woocommerce_checkout_phone_field', function () { return 'required'; });

/** Indian mobile number: returns the 10 digits, or '' when it is not valid. */
function bsg_clean_mobile($raw) {
    $d = preg_replace('/\D+/', '', (string) $raw);
    if (strlen($d) === 12 && strpos($d, '91') === 0) {
        $d = substr($d, 2);
    } elseif (strlen($d) === 11 && $d[0] === '0') {
        $d = substr($d, 1);
    }
    return preg_match('/^[6-9]\d{9}$/', $d) ? $d : '';
}

/** What a customer still has to add before we can ship to them. */
function bsg_profile_gaps($user_id) {
    $need = array(
        'first_name'         => 'your name',
        'billing_phone'      => 'mobile number',
        'billing_address_1'  => 'delivery address',
        'billing_city'       => 'city',
        'billing_state'      => 'state',
        'billing_postcode'   => 'pincode',
    );
    $missing = array();
    foreach ($need as $key => $label) {
        $val = get_user_meta($user_id, $key, true);
        if ($key === 'first_name' && $val === '') {
            $val = get_user_meta($user_id, 'billing_first_name', true);
        }
        if (trim((string) $val) === '') {
            $missing[$key] = $label;
        }
    }
    return array('missing' => $missing, 'done' => count($need) - count($missing), 'total' => count($need));
}


/**
 * Guests who go to checkout are sent to sign in / sign up first, then returned.
 */
add_action('template_redirect', function () {
    if (is_user_logged_in() || !function_exists('is_checkout') || !is_checkout()) return;
    if (is_wc_endpoint_url('order-received')) return;
    wp_safe_redirect(add_query_arg('bsg_next', 'checkout', wc_get_page_permalink('myaccount')));
    exit;
});

function bsg_auth_return_field() {
    $to = '';
    if (isset($_GET['bsg_next']) && $_GET['bsg_next'] === 'checkout') {
        $to = wc_get_checkout_url();
    }
    if (isset($_POST['redirect'])) {
        $to = wp_unslash($_POST['redirect']);
    }
    if ($to !== '') {
        echo '<input type="hidden" name="redirect" value="' . esc_url($to) . '">';
    }
}
add_action('woocommerce_login_form_end', 'bsg_auth_return_field');
add_action('woocommerce_register_form_end', 'bsg_auth_return_field');


/**
 * Registration: name and mobile number are required.
 */
add_action('woocommerce_register_form_start', function () {
    $v = function ($k) { return isset($_POST[$k]) ? esc_attr(wp_unslash($_POST[$k])) : ''; };
    ?>
    <p class="woocommerce-form-row form-row form-row-first">
        <label for="reg_first_name"><?php esc_html_e('Full name', 'woocommerce'); ?>&nbsp;<span class="required">*</span></label>
        <input type="text" class="woocommerce-Input input-text" name="bsg_name" id="reg_first_name" autocomplete="name" placeholder="As on your ID" value="<?php echo $v('bsg_name'); ?>" required>
    </p>
    <p class="woocommerce-form-row form-row form-row-last">
        <label for="reg_mobile"><?php esc_html_e('Mobile number', 'woocommerce'); ?>&nbsp;<span class="required">*</span></label>
        <input type="tel" class="woocommerce-Input input-text" name="bsg_mobile" id="reg_mobile" autocomplete="tel-national" inputmode="numeric" maxlength="14" placeholder="10-digit mobile" value="<?php echo $v('bsg_mobile'); ?>" required>
    </p>
    <div class="clear"></div>
    <?php
});

add_action('woocommerce_register_form', function () {
    echo '<p class="bsg-auth-note">We call or WhatsApp on this number to confirm Cash on Delivery orders and share tracking.</p>';
});

add_filter('woocommerce_process_registration_errors', function ($errors, $username, $password, $email) {
    $name = isset($_POST['bsg_name']) ? trim(wp_unslash($_POST['bsg_name'])) : '';
    if ($name === '' || mb_strlen($name) < 2) {
        $errors->add('bsg_name', __('Please enter your full name.', 'woocommerce'));
    }
    if (bsg_clean_mobile(isset($_POST['bsg_mobile']) ? wp_unslash($_POST['bsg_mobile']) : '') === '') {
        $errors->add('bsg_mobile', __('Please enter a valid 10-digit Indian mobile number.', 'woocommerce'));
    }
    return $errors;
}, 10, 4);

add_action('woocommerce_created_customer', function ($customer_id) {
    $name   = isset($_POST['bsg_name']) ? sanitize_text_field(wp_unslash($_POST['bsg_name'])) : '';
    $mobile = bsg_clean_mobile(isset($_POST['bsg_mobile']) ? wp_unslash($_POST['bsg_mobile']) : '');
    if ($name !== '') {
        $parts = preg_split('/\s+/', $name, 2);
        update_user_meta($customer_id, 'first_name', $parts[0]);
        update_user_meta($customer_id, 'last_name', isset($parts[1]) ? $parts[1] : '');
        update_user_meta($customer_id, 'billing_first_name', $parts[0]);
        update_user_meta($customer_id, 'billing_last_name', isset($parts[1]) ? $parts[1] : '');
        wp_update_user(array('ID' => $customer_id, 'display_name' => $name));
    }
    if ($mobile !== '') {
        update_user_meta($customer_id, 'billing_phone', $mobile);
    }
    update_user_meta($customer_id, 'billing_country', 'IN');
});


/**
 * Checkout: mobile and address are mandatory and checked on the server too.
 * The Blocks checkout reads its required fields from WooCommerce settings
 * (phone is forced above); the shape of the values is validated here.
 */
add_action('woocommerce_store_api_checkout_update_order_from_request', function ($order) {
    $err = function ($code, $msg) {
        throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException($code, $msg, 400);
    };
    $phone = $order->get_billing_phone();
    if (bsg_clean_mobile($phone) === '') {
        $err('bsg_phone', __('Please enter a valid 10-digit mobile number so we can confirm your order.', 'woocommerce'));
    }
    $order->set_billing_phone(bsg_clean_mobile($phone));

    if ($order->get_billing_country() === 'IN') {
        if (!preg_match('/^[1-9]\d{5}$/', trim($order->get_billing_postcode()))) {
            $err('bsg_pincode', __('Please enter a valid 6-digit pincode.', 'woocommerce'));
        }
        if (strlen(trim($order->get_billing_address_1())) < 6) {
            $err('bsg_address', __('Please enter your full delivery address (house / flat, street, area).', 'woocommerce'));
        }
    }
    // Delivery uses the billing address, so keep the shipping phone in step.
    if ($order->get_shipping_phone() === '') {
        $order->set_shipping_phone($order->get_billing_phone());
    }
});

// Classic checkout and My Account > Addresses use the same rules.
add_filter('woocommerce_billing_fields', function ($fields) {
    if (isset($fields['billing_phone'])) {
        $fields['billing_phone']['required']     = true;
        $fields['billing_phone']['label']        = 'Mobile number';
        $fields['billing_phone']['placeholder']  = '10-digit mobile';
        $fields['billing_phone']['autocomplete'] = 'tel-national';
        $fields['billing_phone']['custom_attributes'] = array('inputmode' => 'numeric', 'maxlength' => 14);
    }
    if (isset($fields['billing_postcode'])) {
        $fields['billing_postcode']['custom_attributes'] = array('inputmode' => 'numeric', 'maxlength' => 6);
    }
    return $fields;
});

add_filter('woocommerce_process_myaccount_field_billing_phone', function ($value) {
    $clean = bsg_clean_mobile($value);
    if ($clean === '') {
        wc_add_notice(__('Please enter a valid 10-digit mobile number.', 'woocommerce'), 'error');
        return $value;
    }
    return $clean;
});

add_action('woocommerce_checkout_process', function () {
    if (bsg_clean_mobile(isset($_POST['billing_phone']) ? wp_unslash($_POST['billing_phone']) : '') === '') {
        wc_add_notice(__('Please enter a valid 10-digit mobile number.', 'woocommerce'), 'error');
    }
});


/**
 * Account details: mobile number lives here too.
 */
add_action('woocommerce_edit_account_form_fields', function () {
    $uid = get_current_user_id();
    $v = isset($_POST['bsg_mobile']) ? wp_unslash($_POST['bsg_mobile']) : get_user_meta($uid, 'billing_phone', true);
    ?>
    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
        <label for="bsg_mobile"><?php esc_html_e('Mobile number', 'woocommerce'); ?>&nbsp;<span class="required">*</span></label>
        <input type="tel" class="woocommerce-Input input-text" name="bsg_mobile" id="bsg_mobile" autocomplete="tel-national" inputmode="numeric" maxlength="14" value="<?php echo esc_attr($v); ?>" required>
    </p>
    <?php
});

add_action('woocommerce_save_account_details_errors', function ($errors) {
    if (bsg_clean_mobile(isset($_POST['bsg_mobile']) ? wp_unslash($_POST['bsg_mobile']) : '') === '') {
        $errors->add('bsg_mobile', __('Please enter a valid 10-digit mobile number.', 'woocommerce'));
    }
});

add_action('woocommerce_save_account_details', function ($user_id) {
    $mobile = bsg_clean_mobile(isset($_POST['bsg_mobile']) ? wp_unslash($_POST['bsg_mobile']) : '');
    if ($mobile !== '') {
        update_user_meta($user_id, 'billing_phone', $mobile);
    }
});


/**
 * Login page: one card with Sign in / Create account tabs.
 */
add_action('woocommerce_before_customer_login_form', function () {
    $register = isset($_POST['register']) || (isset($_GET['mode']) && $_GET['mode'] === 'register');
    $to_checkout = isset($_GET['bsg_next']) && $_GET['bsg_next'] === 'checkout';
    ?>
    <div class="bsg-auth-head" data-mode="<?php echo $register ? 'register' : 'login'; ?>">
        <?php if ($to_checkout) : ?>
            <p class="bsg-auth-banner">Almost there. Sign in or create an account to place your order. We save your address so next time it is one tap.</p>
        <?php endif; ?>
        <div class="bsg-auth-tabs" role="tablist" aria-label="Account">
            <button type="button" role="tab" id="bsg-tab-login" aria-controls="bsg-pane-login" aria-selected="<?php echo $register ? 'false' : 'true'; ?>" data-mode="login">Sign in</button>
            <button type="button" role="tab" id="bsg-tab-register" aria-controls="bsg-pane-register" aria-selected="<?php echo $register ? 'true' : 'false'; ?>" data-mode="register">Create account</button>
        </div>
    </div>
    <?php
}, 20);

add_action('woocommerce_after_customer_login_form', function () {
    ?>
    <ul class="bsg-auth-perks">
        <li><strong>Track every order</strong><span>Live status from packing to doorstep.</span></li>
        <li><strong>Faster checkout</strong><span>Your address and mobile are saved.</span></li>
        <li><strong>Cash on Delivery</strong><span>Pay when it arrives, easy returns.</span></li>
    </ul>
    <?php
}, 5);


/**
 * My Account: navigation labels and icons.
 */
add_filter('woocommerce_account_menu_items', function ($items) {
    $out = array();
    foreach ($items as $key => $label) {
        $out[$key] = $label;
    }
    $rename = array('dashboard' => 'Overview', 'orders' => 'My orders', 'edit-address' => 'Addresses', 'edit-account' => 'Profile & password', 'customer-logout' => 'Log out');
    foreach ($rename as $k => $l) {
        if (isset($out[$k])) $out[$k] = $l;
    }
    unset($out['downloads']);
    return $out;
});

add_filter('woocommerce_endpoint_orders_title', function () { return 'My orders'; });
add_filter('woocommerce_endpoint_edit-address_title', function () { return 'Addresses'; });
add_filter('woocommerce_endpoint_edit-account_title', function () { return 'Profile & password'; });


/**
 * Assets: only where they are used.
 */
add_action('wp_enqueue_scripts', function () {
    if (!function_exists('is_account_page') || !(is_account_page() || is_checkout())) return;
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    wp_enqueue_style('bsg-account', $uri . '/assets/css/account.css', array('bsg-shop-custom'), filemtime($dir . '/assets/css/account.css'));
    if (is_account_page()) {
        wp_enqueue_script('bsg-account', $uri . '/assets/js/account.js', array(), filemtime($dir . '/assets/js/account.js'), true);
    }
}, 20);


/**
 * Password reset: same card look as sign in, with a way back.
 */
add_action('woocommerce_before_lost_password_form', function () {
    echo '<div class="bsg-reset">';
});
add_action('woocommerce_after_lost_password_form', function () {
    echo '<p class="bsg-reset-back"><a href="' . esc_url(wc_get_page_permalink('myaccount')) . '">&larr; Back to sign in</a></p></div>';
});
add_action('woocommerce_before_reset_password_form', function () {
    echo '<div class="bsg-reset">';
});
add_action('woocommerce_after_reset_password_form', function () {
    echo '</div>';
});
