<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ---------------------------------------------------------------------------
 * Address book (up to 5 saved addresses: Home / Work / Other, one default)
 * - Manage them in My Account > Addresses.
 * - Pick one at checkout, or add a new one and save it for next time.
 * - The default address is mirrored into WooCommerce's billing fields, so
 *   every other part of the shop (prefill, emails, profile meter) keeps working.
 * Checkout is the classic template so the address picker and the order
 * summary with product photos can be controlled; delivery always goes to the
 * chosen address (no separate shipping form).
 * ---------------------------------------------------------------------------
 */

const BSG_ADDR_META = 'bsg_addresses';
const BSG_ADDR_MAX  = 5;

function bsg_addr_labels() {
    return array('home' => 'Home', 'work' => 'Work', 'other' => 'Other');
}

function bsg_get_addresses($uid) {
    $list = get_user_meta($uid, BSG_ADDR_META, true);
    return is_array($list) ? $list : array();
}

/** Writes the list, guarantees one default, and mirrors it into billing_* meta. */
function bsg_save_addresses($uid, array $list) {
    $has_default = false;
    foreach ($list as $id => $a) {
        if (!empty($a['default'])) {
            if ($has_default) { $list[$id]['default'] = false; }
            $has_default = true;
        }
    }
    if (!$has_default && $list) {
        $first = array_key_first($list);
        $list[$first]['default'] = true;
    }
    update_user_meta($uid, BSG_ADDR_META, $list);

    foreach ($list as $a) {
        if (empty($a['default'])) continue;
        $parts = preg_split('/\s+/', trim($a['name']), 2);
        update_user_meta($uid, 'billing_first_name', $parts[0]);
        update_user_meta($uid, 'billing_last_name', isset($parts[1]) ? $parts[1] : '');
        update_user_meta($uid, 'billing_phone', $a['phone']);
        update_user_meta($uid, 'billing_address_1', $a['line1']);
        update_user_meta($uid, 'billing_address_2', $a['line2']);
        update_user_meta($uid, 'billing_city', $a['city']);
        update_user_meta($uid, 'billing_state', $a['state']);
        update_user_meta($uid, 'billing_postcode', $a['pincode']);
        update_user_meta($uid, 'billing_country', 'IN');
    }
}

/** Cleans a posted address; returns array($address, $errors). */
function bsg_clean_address(array $src) {
    $errors = array();
    $labels = bsg_addr_labels();
    $label  = isset($src['label']) && isset($labels[$src['label']]) ? $src['label'] : 'home';
    $a = array(
        'label'   => $label,
        'name'    => trim(sanitize_text_field(isset($src['name']) ? $src['name'] : '')),
        'phone'   => bsg_clean_mobile(isset($src['phone']) ? $src['phone'] : ''),
        'line1'   => trim(sanitize_text_field(isset($src['line1']) ? $src['line1'] : '')),
        'line2'   => trim(sanitize_text_field(isset($src['line2']) ? $src['line2'] : '')),
        'city'    => trim(sanitize_text_field(isset($src['city']) ? $src['city'] : '')),
        'state'   => strtoupper(sanitize_text_field(isset($src['state']) ? $src['state'] : '')),
        'pincode' => preg_replace('/\D+/', '', isset($src['pincode']) ? $src['pincode'] : ''),
        'default' => !empty($src['default']),
    );
    $states = WC()->countries->get_states('IN');
    if (mb_strlen($a['name']) < 2)                         $errors[] = 'Please enter the full name of the person receiving the order.';
    if ($a['phone'] === '')                                 $errors[] = 'Please enter a valid 10-digit mobile number.';
    if (mb_strlen($a['line1']) < 6)                         $errors[] = 'Please enter the house / flat number and street.';
    if ($a['city'] === '')                                  $errors[] = 'Please enter the city.';
    if (!is_array($states) || !isset($states[$a['state']])) $errors[] = 'Please choose the state.';
    if (!preg_match('/^[1-9]\d{5}$/', $a['pincode']))       $errors[] = 'Please enter a valid 6-digit pincode.';
    return array($a, $errors);
}

function bsg_addr_format(array $a) {
    $states = WC()->countries->get_states('IN');
    $state  = isset($states[$a['state']]) ? $states[$a['state']] : $a['state'];
    $lines  = array_filter(array($a['line1'], $a['line2'], $a['city'] . ', ' . $state . ' ' . $a['pincode']));
    return implode(', ', $lines);
}


/**
 * My Account > Addresses endpoint.
 */
add_action('init', function () {
    add_rewrite_endpoint('address-book', EP_ROOT | EP_PAGES);
    // One-time rewrite flush so the new URL works without a manual Permalinks save.
    if (get_option('bsg_rewrite_version') !== '2') {
        flush_rewrite_rules(false);
        update_option('bsg_rewrite_version', '2');
    }
});

add_filter('woocommerce_get_query_vars', function ($vars) {
    $vars['address-book'] = 'address-book';
    return $vars;
});

add_filter('woocommerce_account_menu_items', function ($items) {
    $out = array();
    foreach ($items as $k => $label) {
        if ($k === 'edit-address') {
            $out['address-book'] = 'Addresses';
        } else {
            $out[$k] = $label;
        }
    }
    return $out;
}, 20);

add_filter('woocommerce_endpoint_address-book_title', function () { return 'Saved addresses'; });

// The old single-address screen now leads to the address book.
add_action('template_redirect', function () {
    if (is_user_logged_in() && function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('edit-address')) {
        wp_safe_redirect(wc_get_account_endpoint_url('address-book'));
        exit;
    }
});

/** Handles add / edit / delete / make-default posts from the address book page. */
add_action('template_redirect', function () {
    if (!is_user_logged_in() || !function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('address-book')) return;
    if (empty($_POST['bsg_addr_action'])) return;
    if (!isset($_POST['_bsg_addr']) || !wp_verify_nonce(wp_unslash($_POST['_bsg_addr']), 'bsg_addr')) {
        wc_add_notice('Your session expired. Please try again.', 'error');
        wp_safe_redirect(wc_get_account_endpoint_url('address-book'));
        exit;
    }

    $uid    = get_current_user_id();
    $list   = bsg_get_addresses($uid);
    $action = sanitize_key($_POST['bsg_addr_action']);
    $id     = isset($_POST['addr_id']) ? sanitize_key($_POST['addr_id']) : '';
    $back   = wc_get_account_endpoint_url('address-book');

    if ($action === 'delete' && isset($list[$id])) {
        $was_default = !empty($list[$id]['default']);
        unset($list[$id]);
        if ($was_default && $list) { $list[array_key_first($list)]['default'] = true; }
        bsg_save_addresses($uid, $list);
        wc_add_notice('Address removed.');
    } elseif ($action === 'default' && isset($list[$id])) {
        foreach ($list as $k => $a) { $list[$k]['default'] = ($k === $id); }
        bsg_save_addresses($uid, $list);
        wc_add_notice('Default address updated.');
    } elseif ($action === 'save') {
        list($a, $errors) = bsg_clean_address(wp_unslash($_POST));
        $editing = $id !== '' && isset($list[$id]);
        if (!$editing && count($list) >= BSG_ADDR_MAX) {
            $errors[] = 'You can save up to ' . BSG_ADDR_MAX . ' addresses. Remove one to add another.';
        }
        if ($errors) {
            foreach ($errors as $e) { wc_add_notice($e, 'error'); }
            return; // re-render the form with the posted values and the notices
        }
        if (!$editing) { $id = 'a' . substr(md5(uniqid('', true)), 0, 8); }
        if ($a['default'] || !$list) {
            foreach ($list as $k => $x) { $list[$k]['default'] = false; }
            $a['default'] = true;
        }
        $list[$id] = $a;
        bsg_save_addresses($uid, $list);
        wc_add_notice($editing ? 'Address updated.' : 'Address saved.');
    }
    wp_safe_redirect($back);
    exit;
}, 9);

add_action('woocommerce_account_address-book_endpoint', function () {
    $uid    = get_current_user_id();
    $list   = bsg_get_addresses($uid);
    $labels = bsg_addr_labels();
    $states = WC()->countries->get_states('IN');
    $edit   = isset($_GET['edit']) ? sanitize_key($_GET['edit']) : '';
    $adding = isset($_GET['add']) || $edit !== '' || isset($_POST['bsg_addr_action']) || !$list;
    $base   = wc_get_account_endpoint_url('address-book');

    // Values to show in the form (a saved address, or what was just posted with errors).
    $cur = array('label' => 'home', 'name' => '', 'phone' => '', 'line1' => '', 'line2' => '', 'city' => '', 'state' => '', 'pincode' => '', 'default' => !$list);
    if ($edit !== '' && isset($list[$edit])) { $cur = array_merge($cur, $list[$edit]); }
    if (isset($_POST['bsg_addr_action'])) {
        $p = wp_unslash($_POST);
        foreach ($cur as $k => $v) { if (isset($p[$k])) $cur[$k] = $p[$k]; }
        $cur['default'] = !empty($p['default']);
        $edit = isset($p['addr_id']) ? sanitize_key($p['addr_id']) : '';
    }
    if ($edit === '' && !$cur['name']) {
        $u = wp_get_current_user();
        $cur['name']  = trim($u->first_name . ' ' . $u->last_name);
        $cur['phone'] = get_user_meta($uid, 'billing_phone', true);
    }
    ?>
    <div class="bsg-book">
        <?php if ($list) : ?>
            <div class="bsg-book-head">
                <p class="bsg-book-count"><?php echo count($list); ?> of <?php echo (int) BSG_ADDR_MAX; ?> saved</p>
                <?php if (count($list) < BSG_ADDR_MAX && !$adding) : ?>
                    <a class="bsg-btn-solid" href="<?php echo esc_url(add_query_arg('add', '1', $base)); ?>">+ Add new address</a>
                <?php endif; ?>
            </div>
            <div class="bsg-addr-list">
                <?php foreach ($list as $id => $a) : ?>
                    <article class="bsg-addr-card<?php echo !empty($a['default']) ? ' is-default' : ''; ?>">
                        <header>
                            <span class="bsg-addr-tag bsg-addr-tag--<?php echo esc_attr($a['label']); ?>"><?php echo esc_html($labels[$a['label']]); ?></span>
                            <?php if (!empty($a['default'])) : ?><span class="bsg-addr-default">Default</span><?php endif; ?>
                        </header>
                        <p class="bsg-addr-name"><strong><?php echo esc_html($a['name']); ?></strong> <span><?php echo esc_html($a['phone']); ?></span></p>
                        <p class="bsg-addr-text"><?php echo esc_html(bsg_addr_format($a)); ?></p>
                        <footer>
                            <a href="<?php echo esc_url(add_query_arg('edit', $id, $base)); ?>">Edit</a>
                            <form method="post" action="<?php echo esc_url($base); ?>" onsubmit="return confirm('Remove this address?');">
                                <?php wp_nonce_field('bsg_addr', '_bsg_addr'); ?>
                                <input type="hidden" name="addr_id" value="<?php echo esc_attr($id); ?>">
                                <button type="submit" name="bsg_addr_action" value="delete">Remove</button>
                                <?php if (empty($a['default'])) : ?>
                                    <button type="submit" name="bsg_addr_action" value="default" formnovalidate>Make default</button>
                                <?php endif; ?>
                            </form>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <p class="bsg-book-empty">You have no saved addresses yet. Add one and checkout becomes a single tap.</p>
        <?php endif; ?>

        <?php if ($adding) : ?>
            <form class="bsg-addr-form" method="post" action="<?php echo esc_url($base); ?>">
                <h3><?php echo ($edit !== '' && isset($list[$edit])) ? 'Edit address' : 'Add a new address'; ?></h3>
                <?php wp_nonce_field('bsg_addr', '_bsg_addr'); ?>
                <input type="hidden" name="addr_id" value="<?php echo esc_attr($edit); ?>">
                <div class="bsg-label-pick" role="radiogroup" aria-label="Address type">
                    <?php foreach ($labels as $k => $l) : ?>
                        <label><input type="radio" name="label" value="<?php echo esc_attr($k); ?>" <?php checked($cur['label'], $k); ?>><span><?php echo esc_html($l); ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="bsg-addr-grid">
                    <p><label for="ab_name">Full name *</label><input id="ab_name" name="name" type="text" autocomplete="name" value="<?php echo esc_attr($cur['name']); ?>" required></p>
                    <p><label for="ab_phone">Mobile number *</label><input id="ab_phone" name="phone" type="tel" inputmode="numeric" maxlength="14" autocomplete="tel-national" value="<?php echo esc_attr($cur['phone']); ?>" required></p>
                    <p class="wide"><label for="ab_l1">House / flat no., building, street *</label><input id="ab_l1" name="line1" type="text" autocomplete="address-line1" value="<?php echo esc_attr($cur['line1']); ?>" required></p>
                    <p class="wide"><label for="ab_l2">Area, landmark (optional)</label><input id="ab_l2" name="line2" type="text" autocomplete="address-line2" value="<?php echo esc_attr($cur['line2']); ?>"></p>
                    <p><label for="ab_pin">Pincode *</label><input id="ab_pin" name="pincode" type="text" inputmode="numeric" maxlength="6" autocomplete="postal-code" value="<?php echo esc_attr($cur['pincode']); ?>" required></p>
                    <p><label for="ab_city">City *</label><input id="ab_city" name="city" type="text" autocomplete="address-level2" value="<?php echo esc_attr($cur['city']); ?>" required></p>
                    <p class="wide"><label for="ab_state">State *</label>
                        <select id="ab_state" name="state" required>
                            <option value="">Select state</option>
                            <?php foreach ($states as $code => $name) : ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($cur['state'], $code); ?>><?php echo esc_html($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                </div>
                <p class="bsg-check"><label><input type="checkbox" name="default" value="1" <?php checked(!empty($cur['default'])); ?>> Make this my default address</label></p>
                <div class="bsg-addr-actions">
                    <button type="submit" name="bsg_addr_action" value="save" class="bsg-btn-solid">Save address</button>
                    <?php if ($list) : ?><a class="bsg-link" href="<?php echo esc_url($base); ?>">Cancel</a><?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <?php
});


/**
 * Checkout: classic template, delivering to the chosen address.
 */
add_filter('render_block_woocommerce/checkout', function () {
    return do_shortcode('[woocommerce_checkout]');
});
add_filter('pre_option_woocommerce_ship_to_destination', function () { return 'billing'; });
add_filter('woocommerce_cart_needs_shipping_address', '__return_false');

// Address picker above the billing fields.
add_action('woocommerce_before_checkout_billing_form', function () {
    $uid = get_current_user_id();
    $list = bsg_get_addresses($uid);
    $labels = bsg_addr_labels();
    ?>
    <div class="bsg-pick" id="bsg-pick">
        <h3 class="bsg-pick-title"><?php echo $list ? 'Deliver to' : 'Delivery address'; ?></h3>
        <?php if ($list) : ?>
            <div class="bsg-pick-list" role="radiogroup" aria-label="Saved addresses">
                <?php foreach ($list as $id => $a) : ?>
                    <label class="bsg-pick-card">
                        <input type="radio" name="bsg_pick" value="<?php echo esc_attr($id); ?>" <?php checked(!empty($a['default'])); ?>>
                        <span class="bsg-pick-body">
                            <span class="bsg-pick-top"><span class="bsg-addr-tag bsg-addr-tag--<?php echo esc_attr($a['label']); ?>"><?php echo esc_html($labels[$a['label']]); ?></span><?php if (!empty($a['default'])) echo '<em>Default</em>'; ?></span>
                            <strong><?php echo esc_html($a['name']); ?></strong> <small><?php echo esc_html($a['phone']); ?></small>
                            <span class="bsg-pick-text"><?php echo esc_html(bsg_addr_format($a)); ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
                <label class="bsg-pick-card bsg-pick-new">
                    <input type="radio" name="bsg_pick" value="new">
                    <span class="bsg-pick-body"><strong>+ Deliver to a new address</strong></span>
                </label>
            </div>
        <?php endif; ?>
    </div>
    <?php
});

// "Save to my address book" under the billing fields.
add_action('woocommerce_after_checkout_billing_form', function () {
    $labels = bsg_addr_labels();
    ?>
    <div class="bsg-save-addr" id="bsg-save-addr">
        <label class="bsg-check"><input type="checkbox" name="bsg_save_addr" value="1" checked> Save this address for next time</label>
        <span class="bsg-save-as">
            <label for="bsg_addr_label" class="screen-reader-text">Address type</label>
            <select name="bsg_addr_label" id="bsg_addr_label">
                <?php foreach ($labels as $k => $l) : ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($l); ?></option><?php endforeach; ?>
            </select>
        </span>
    </div>
    <?php
});

// A new address used at checkout is saved to the book (first one becomes the default).
add_action('woocommerce_checkout_update_user_meta', function ($customer_id, $posted) {
    if (empty($_POST['bsg_save_addr']) || !$customer_id) return;
    $new = array(
        'label'   => isset($_POST['bsg_addr_label']) ? sanitize_key($_POST['bsg_addr_label']) : 'home',
        'name'    => trim(($posted['billing_first_name'] ?? '') . ' ' . ($posted['billing_last_name'] ?? '')),
        'phone'   => $posted['billing_phone'] ?? '',
        'line1'   => $posted['billing_address_1'] ?? '',
        'line2'   => $posted['billing_address_2'] ?? '',
        'city'    => $posted['billing_city'] ?? '',
        'state'   => $posted['billing_state'] ?? '',
        'pincode' => $posted['billing_postcode'] ?? '',
    );
    list($a, $errors) = bsg_clean_address($new);
    if ($errors) return;
    $list = bsg_get_addresses($customer_id);
    foreach ($list as $x) {
        if (strcasecmp($x['line1'], $a['line1']) === 0 && $x['pincode'] === $a['pincode']) return; // already saved
    }
    if (count($list) >= BSG_ADDR_MAX) return;
    $a['default'] = !$list;
    $list['a' . substr(md5(uniqid('', true)), 0, 8)] = $a;
    bsg_save_addresses($customer_id, $list);
}, 20, 2);

// Server-side check of the address shape on classic checkout.
add_action('woocommerce_after_checkout_validation', function ($data, $errors) {
    if (($data['billing_country'] ?? '') !== 'IN') return;
    if (!preg_match('/^[1-9]\d{5}$/', trim($data['billing_postcode'] ?? ''))) {
        $errors->add('bsg_pincode', 'Please enter a valid 6-digit pincode.');
    }
    if (mb_strlen(trim($data['billing_address_1'] ?? '')) < 6) {
        $errors->add('bsg_address', 'Please enter your full delivery address (house / flat, street, area).');
    }
}, 10, 2);


/**
 * Order summary: product photo (20%) beside the details (80%).
 */
// Only the checkout order summary gets the photo-beside-details layout; the slide-out bag
// (which also renders on the checkout page) keeps its own thumbnails.
$GLOBALS['bsg_in_review_order'] = false;
add_action('woocommerce_review_order_before_cart_contents', function () { $GLOBALS['bsg_in_review_order'] = true; }, 1);
add_action('woocommerce_review_order_after_cart_contents', function () { $GLOBALS['bsg_in_review_order'] = false; }, 99);

add_filter('woocommerce_cart_item_name', function ($name, $item, $key) {
    if (empty($GLOBALS['bsg_in_review_order'])) return $name;
    if (!function_exists('is_checkout') || !is_checkout() || is_wc_endpoint_url('order-received')) return $name;
    $product = $item['data'];
    $thumb = $product->get_image('woocommerce_thumbnail', array('class' => 'bsg-co-img', 'loading' => 'lazy', 'alt' => wp_strip_all_tags($product->get_name())));
    return '<span class="bsg-co-thumb">' . $thumb . '</span><span class="bsg-co-name">' . wp_strip_all_tags($name) . '</span>';
}, 10, 3);

add_action('wp_enqueue_scripts', function () {
    if (!function_exists('is_checkout') || !is_checkout() || is_wc_endpoint_url('order-received')) return;
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    wp_enqueue_script('bsg-checkout', $uri . '/assets/js/checkout.js', array('jquery'), filemtime($dir . '/assets/js/checkout.js'), true);
    wp_localize_script('bsg-checkout', 'bsgAddresses', array_values(array_map(function ($id, $a) {
        $parts = preg_split('/\s+/', trim($a['name']), 2);
        return array(
            'id' => $id, 'first' => $parts[0], 'last' => isset($parts[1]) ? $parts[1] : '',
            'phone' => $a['phone'], 'line1' => $a['line1'], 'line2' => $a['line2'],
            'city' => $a['city'], 'state' => $a['state'], 'pincode' => $a['pincode'],
        );
    }, array_keys(bsg_get_addresses(get_current_user_id())), bsg_get_addresses(get_current_user_id()))));
}, 25);

add_action('wp_enqueue_scripts', function () {
    if (!function_exists('is_checkout') || !(is_checkout() || is_account_page())) return;
    $dir = get_stylesheet_directory();
    wp_enqueue_style('bsg-addresses', get_stylesheet_directory_uri() . '/assets/css/addresses.css', array('bsg-account'), filemtime($dir . '/assets/css/addresses.css'));
}, 30);
