<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * ---------------------------------------------------------------------------
 * 1. Assets — styles, fonts, scripts
 * ---------------------------------------------------------------------------
 */
function bsg_enqueue_minimal_assets() {
    // Google Fonts.
    wp_enqueue_style(
        'bsg-fonts',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap',
        array(),
        null
    );

    // Enqueue Parent Theme Style
    wp_enqueue_style('parent-style', get_template_directory_uri() . '/style.css');

    // Enqueue Child Theme Custom CSS — filemtime as version so edits always bust cache.
    wp_enqueue_style(
        'bsg-shop-custom',
        get_stylesheet_directory_uri() . '/assets/css/shop-custom.css',
        array('parent-style', 'bsg-fonts'),
        filemtime(get_stylesheet_directory() . '/assets/css/shop-custom.css')
    );

    // Desktop layout (768px and up) — loaded last so it can refine everything above.
    wp_enqueue_style(
        'bsg-desktop',
        get_stylesheet_directory_uri() . '/assets/css/desktop.css',
        array('bsg-shop-custom'),
        filemtime(get_stylesheet_directory() . '/assets/css/desktop.css')
    );

    // Core interactions (header, cart drawer, animations) — every page.
    wp_enqueue_script(
        'bsg-site-js',
        get_stylesheet_directory_uri() . '/assets/js/site.js',
        array(),
        filemtime(get_stylesheet_directory() . '/assets/js/site.js'),
        true
    );

    wp_localize_script('bsg-site-js', 'bsgSettings', array(
        'ajaxUrl'          => admin_url('admin-ajax.php'),
        'nonce'            => wp_create_nonce('bsg_quick_add'),
        'loadMoreNonce'    => wp_create_nonce('bsg_load_more'),
        'wishlistNonce'    => wp_create_nonce('bsg_wishlist'),
        'newsletterNonce'  => wp_create_nonce('bsg_newsletter'),
        'productsApi'      => esc_url_raw(rest_url('wc/store/v1/products')),
        'isLoggedIn'       => is_user_logged_in(),
        'freeShippingGoal' => 999,
        'currencySymbol'   => html_entity_decode(get_woocommerce_currency_symbol()),
    ));

    // Enqueue PDP JS on single product pages only
    if (is_product()) {
        wp_enqueue_script(
            'bsg-custom-pdp-js',
            get_stylesheet_directory_uri() . '/assets/js/custom-pdp.js',
            array(),
            filemtime(get_stylesheet_directory() . '/assets/js/custom-pdp.js'),
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'bsg_enqueue_minimal_assets');

add_action('wp_head', function () {
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
});

// Runs before the stylesheet paints, so a returning visitor's saved dark/light
// choice applies immediately instead of flashing the other theme first.
add_action('wp_head', function () {
    ?>
    <script>
    (function () {
        try {
            var saved = localStorage.getItem('bsg-theme');
            if (saved === 'dark' || saved === 'light') {
                document.documentElement.setAttribute('data-theme', saved);
            }
        } catch (e) {}
    })();
    </script>
    <?php
}, 1);

/**
 * ---------------------------------------------------------------------------
 * 2. Currency Formatting: Remove decimals for Indian Rupee (₹1,499)
 * ---------------------------------------------------------------------------
 */
add_filter('woocommerce_price_trim_zeros', '__return_true');

add_filter('wc_price_args', function ($args) {
    $args['decimals'] = 0;
    return $args;
});

/**
 * ---------------------------------------------------------------------------
 * 3. Announcement bar
 * ---------------------------------------------------------------------------
 */
function bsg_announcement_bar() {
    ?>
    <div class="bsg-announcement-bar">
        <p>FREE Shipping on orders over ₹999 &nbsp;|&nbsp; Use Code <strong>BSGFIRST</strong> for 10% OFF</p>
    </div>
    <?php
}
add_action('wp_body_open', 'bsg_announcement_bar', 5);

/**
 * ---------------------------------------------------------------------------
 * 3b. PDP rating badge — above the fold, directly under the title.
 * Core's own woocommerce_template_single_rating already runs at this point
 * in the hook order (priority 10, same as price), but it renders nothing at
 * all when a product has zero reviews. We replace it with a version that
 * shows a neutral "No reviews yet" line instead of leaving blank space.
 * ---------------------------------------------------------------------------
 */
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10);

function bsg_pdp_rating_badge() {
    global $product;
    if (!$product || !wc_review_ratings_enabled()) return;

    $rating_count = $product->get_rating_count();
    $average = $product->get_average_rating();
    if ($rating_count < 1) return; // stars only once there are real reviews
    ?>
    <div class="bsg-pdp-rating">
        <?php if ($rating_count > 0) : ?>
            <?php echo wc_get_rating_html($average, $rating_count); ?>
            <?php if (comments_open()) : ?>
                <a href="#reviews" class="bsg-pdp-rating__link" rel="nofollow">
                    (<?php echo esc_html($product->get_review_count()); ?> <?php echo esc_html(_n('review', 'reviews', $product->get_review_count(), 'woocommerce')); ?>)
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}
add_action('woocommerce_single_product_summary', 'bsg_pdp_rating_badge', 6);

/**
 * ---------------------------------------------------------------------------
 * 4. PDP accordions, pincode estimator, trust badges
 * ---------------------------------------------------------------------------
 */
/**
 * Optional per-product content (edited in the "BSG product details" box on
 * the product edit screen). Every field is optional; products without them
 * keep working with the generic fallbacks below.
 */
function bsg_detail_fields() {
    return array(
        'highlights' => array('label' => 'Highlights (one per line, up to 4)', 'type' => 'textarea'),
        'fabric'     => array('label' => 'Fabric', 'type' => 'textarea'),
        'fit'        => array('label' => 'Fit', 'type' => 'text'),
        'occasion'   => array('label' => 'Occasion', 'type' => 'text'),
        'care'       => array('label' => 'Care instructions', 'type' => 'textarea'),
        'model_info' => array('label' => 'Model info (e.g. Model is 6\'0" and wears M)', 'type' => 'text'),
    );
}

function bsg_product_detail($product_id, $key) {
    return trim((string) get_post_meta($product_id, '_bsg_' . $key, true));
}

add_action('add_meta_boxes', function () {
    add_meta_box('bsg_product_details', 'BSG product details', 'bsg_product_details_box', 'product', 'normal', 'default');
});

function bsg_product_details_box($post) {
    wp_nonce_field('bsg_product_details', 'bsg_product_details_nonce');
    echo '<p style="color:#666">Shown on the product page. Leave blank to use the default text.</p>';
    foreach (bsg_detail_fields() as $key => $field) {
        $value = bsg_product_detail($post->ID, $key);
        echo '<p><label for="bsg_' . esc_attr($key) . '"><strong>' . esc_html($field['label']) . '</strong></label><br>';
        if ($field['type'] === 'textarea') {
            echo '<textarea id="bsg_' . esc_attr($key) . '" name="bsg_' . esc_attr($key) . '" rows="3" style="width:100%">' . esc_textarea($value) . '</textarea>';
        } else {
            echo '<input type="text" id="bsg_' . esc_attr($key) . '" name="bsg_' . esc_attr($key) . '" value="' . esc_attr($value) . '" style="width:100%">';
        }
        echo '</p>';
    }
}

add_action('save_post_product', function ($post_id) {
    if (!isset($_POST['bsg_product_details_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bsg_product_details_nonce'])), 'bsg_product_details')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    foreach (bsg_detail_fields() as $key => $field) {
        $raw = isset($_POST['bsg_' . $key]) ? wp_unslash($_POST['bsg_' . $key]) : '';
        $value = $field['type'] === 'textarea' ? sanitize_textarea_field($raw) : sanitize_text_field($raw);
        if ($value === '') {
            delete_post_meta($post_id, '_bsg_' . $key);
        } else {
            update_post_meta($post_id, '_bsg_' . $key, $value);
        }
    }
});

/**
 * Product page price row: price plus a "% OFF" badge when on sale.
 */
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10);

function bsg_pdp_price_row() {
    global $product;
    if (!$product) return;

    $pct = 0;
    if ($product->is_on_sale()) {
        if ($product->is_type('variable')) {
            $reg  = (float) $product->get_variation_regular_price('min');
            $sale = (float) $product->get_variation_sale_price('min');
        } else {
            $reg  = (float) $product->get_regular_price();
            $sale = (float) $product->get_sale_price();
        }
        if ($reg > 0 && $sale > 0 && $sale < $reg) {
            $pct = (int) round((($reg - $sale) / $reg) * 100);
        }
    }
    echo '<div class="bsg-pdp-pricerow"><p class="' . esc_attr(apply_filters('woocommerce_product_price_class', 'price')) . '">' . $product->get_price_html() . '</p>';
    if ($pct > 0) {
        echo '<span class="bsg-pdp-off">' . esc_html($pct) . '% OFF</span>';
    }
    echo '</div>';
}
add_action('woocommerce_single_product_summary', 'bsg_pdp_price_row', 10);

/**
 * Short highlights under the price (from the optional product field).
 */
function bsg_pdp_highlights() {
    global $product;
    if (!$product) return;
    $raw = bsg_product_detail($product->get_id(), 'highlights');
    if ($raw === '') return;
    $lines = array_slice(array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw)))), 0, 4);
    if (!$lines) return;
    echo '<ul class="bsg-pdp-highlights">';
    foreach ($lines as $line) {
        echo '<li>' . esc_html($line) . '</li>';
    }
    echo '</ul>';
}
add_action('woocommerce_single_product_summary', 'bsg_pdp_highlights', 15);

/**
 * Description / Additional information / Reviews live in the accordion group
 * below instead of a separate tab strip.
 */
remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10);

function bsg_accordion_item($id, $title, $html, $open = false) {
    if (trim((string) $html) === '') return;
    printf(
        '<details class="bsg-accordion-item" id="%s"%s><summary class="bsg-accordion-header"><span>%s</span><span class="icon">+</span></summary><div class="bsg-accordion-content">%s</div></details>',
        esc_attr($id),
        $open ? ' open' : '',
        esc_html($title),
        $html // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts or WooCommerce template output
    );
}

function bsg_capture_tab($tab, $key) {
    if (empty($tab['callback']) || !is_callable($tab['callback'])) return '';
    ob_start();
    call_user_func($tab['callback'], $key, $tab);
    return ob_get_clean();
}

function bsg_add_pdp_accordions() {
    global $product;
    if (!$product) return;
    $pid  = $product->get_id();
    $cats = wp_get_post_terms($pid, 'product_cat', array('fields' => 'slugs'));
    $is_accessory = in_array('accessories', (array) $cats, true);
    ?>
    <div class="bsg-pincode-estimator-slot">
        <div class="bsg-pincode-box">
            <input type="text" id="bsg-pincode-input" placeholder="Enter delivery pincode" maxlength="6" inputmode="numeric" autocomplete="postal-code" aria-label="Delivery pincode">
            <button type="button" id="bsg-pincode-check-btn">Check</button>
        </div>
        <div id="bsg-pincode-result" class="bsg-pincode-result" role="status"></div>
    </div>

    <?php $pay = bsg_payment_labels(); if ($pay) : ?>
    <div class="bsg-trust-payment-badges">
        <span class="bsg-trust-title">Pay by:</span>
        <div class="bsg-badges-row">
            <?php foreach ($pay as $label) : ?><span class="badge-pill"><?php echo esc_html($label); ?></span><?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="bsg-pdp-accordions">
        <?php
        $tabs = apply_filters('woocommerce_product_tabs', array());

        if (isset($tabs['description'])) {
            bsg_accordion_item('bsg-acc-description', 'Description', bsg_capture_tab($tabs['description'], 'description'), true);
        }
        if (isset($tabs['additional_information'])) {
            bsg_accordion_item('bsg-acc-additional', 'Additional information', bsg_capture_tab($tabs['additional_information'], 'additional_information'));
        }

        $fabric   = bsg_product_detail($pid, 'fabric');
        $care     = bsg_product_detail($pid, 'care');
        $occasion = bsg_product_detail($pid, 'occasion');
        $care_html = '';
        if ($fabric !== '') $care_html .= '<p><strong>Fabric:</strong> ' . esc_html($fabric) . '</p>';
        if ($occasion !== '') $care_html .= '<p><strong>Occasion:</strong> ' . esc_html($occasion) . '</p>';
        if ($care !== '') {
            $care_html .= '<p><strong>Care:</strong> ' . esc_html($care) . '</p>';
        } elseif (!$is_accessory) {
            $care_html .= '<p>Machine wash cold with like colours. Do not bleach. Tumble dry low or line dry in shade. Warm iron if necessary.</p>';
        }
        bsg_accordion_item('bsg-acc-care', 'Fabric & Care', $care_html);

        $fit        = bsg_product_detail($pid, 'fit');
        $model_info = bsg_product_detail($pid, 'model_info');
        $fit_html   = '<p><strong>Fit:</strong> ' . esc_html($fit !== '' ? $fit : 'Modern tailored Indian fit.') . '</p>';
        if ($model_info !== '') $fit_html .= '<p>' . esc_html($model_info) . '</p>';
        if (!$is_accessory) {
            bsg_accordion_item('bsg-acc-fit', 'Fit & Sizing', $fit_html);
        }

        bsg_accordion_item(
            'bsg-acc-shipping',
            'Shipping & ' . (int) bsg_store()['return_days'] . '-Day Exchanges',
            '<p>Dispatched within 24–48 hours. Free pan-India shipping on orders over ₹999. Easy ' . (int) bsg_store()['return_days'] . '-day size exchange &amp; returns policy.</p>'
        );

        if (isset($tabs['reviews'])) {
            bsg_accordion_item('bsg-acc-reviews', $tabs['reviews']['title'], bsg_capture_tab($tabs['reviews'], 'reviews'));
        }
        ?>
    </div>
    <?php
}
add_action('woocommerce_single_product_summary', 'bsg_add_pdp_accordions', 35);

/**
 * Size guide modal — one chart for tops (kurtas/shirts), one for
 * bottoms (jeans & trousers). Only rendered for variable products with
 * a size attribute; JS inserts the trigger link next to the size select.
 */
function bsg_size_guide_modal() {
    global $product;
    if (!$product || !$product->is_type('variable')) return;
    if (defined('PWGC_PRODUCT_TYPE_SLUG') && $product->get_type() === PWGC_PRODUCT_TYPE_SLUG) return;

    $categories = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'slugs'));
    $is_bottoms = in_array('jeans-trousers', $categories, true);
    ?>
    <div class="bsg-size-guide-overlay" id="bsg-size-guide-overlay"></div>
    <div class="bsg-size-guide-modal" id="bsg-size-guide-modal" aria-hidden="true">
        <div class="bsg-size-guide-modal__header">
            <span>Size Guide</span>
            <button type="button" class="bsg-size-guide-modal__close" id="bsg-size-guide-close" aria-label="Close size guide">&times;</button>
        </div>
        <div class="bsg-size-guide-modal__body">
            <?php if ($is_bottoms) : ?>
                <table class="bsg-size-table">
                    <thead>
                        <tr><th>Size</th><th>Waist (in)</th><th>Hip (in)</th><th>Inseam (in)</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>S</td><td>30</td><td>38</td><td>40</td></tr>
                        <tr><td>M</td><td>32</td><td>40</td><td>40.5</td></tr>
                        <tr><td>L</td><td>34</td><td>42</td><td>41</td></tr>
                        <tr><td>XL</td><td>36</td><td>44</td><td>41.5</td></tr>
                        <tr><td>XXL</td><td>38</td><td>46</td><td>42</td></tr>
                    </tbody>
                </table>
            <?php else : ?>
                <table class="bsg-size-table">
                    <thead>
                        <tr><th>Size</th><th>Chest (in)</th><th>Shoulder (in)</th><th>Length (in)</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>S</td><td>38</td><td>17</td><td>27</td></tr>
                        <tr><td>M</td><td>40</td><td>17.5</td><td>27.5</td></tr>
                        <tr><td>L</td><td>42</td><td>18</td><td>28</td></tr>
                        <tr><td>XL</td><td>44</td><td>18.5</td><td>28.5</td></tr>
                        <tr><td>XXL</td><td>46</td><td>19</td><td>29</td></tr>
                    </tbody>
                </table>
            <?php endif; ?>
            <p class="bsg-size-guide-modal__note">Measurements are body measurements in inches. For a relaxed fit, size up.</p>
        </div>
    </div>
    <?php
}
add_action('woocommerce_single_product_summary', 'bsg_size_guide_modal', 6);

/**
 * Inline offer chip on the PDP — reuses the same real, working BSGFIRST
 * coupon advertised in the announcement bar (not a fabricated second code).
 */
function bsg_pdp_offer_chip() {
    if (!is_product()) return;
    $coupon = new WC_Coupon('bsgfirst');
    if (!$coupon->get_id()) return;
    ?>
    <div class="bsg-offer-chip" data-code="BSGFIRST">
        <span class="bsg-offer-chip__code">BSGFIRST</span>
        <span class="bsg-offer-chip__desc">10% off your first order</span>
        <button type="button" class="bsg-offer-chip__copy">Copy</button>
    </div>
    <?php
}
add_action('woocommerce_after_add_to_cart_button', 'bsg_pdp_offer_chip');

/**
 * Relabel the related-products heading to match the spec's "You may also like".
 */
add_filter('woocommerce_product_related_products_heading', function () {
    return 'You May Also Like';
});

/**
 * Recently Viewed — the actual view-tracking and rendering happens client
 * side (localStorage, see initRecentlyViewed in site.js) since it's a
 * per-visitor history with no reason to touch the server until we need the
 * real product cards back. This just outputs the empty shell for JS to
 * fill in, below the related-products rail, plus the current product's ID
 * so the tracking script knows what was just viewed.
 */
function bsg_recently_viewed_section() {
    global $product;
    ?>
    <section class="bsg-recently-viewed-section woocommerce" id="bsg-recently-viewed" style="display:none;">
        <h2 class="bsg-section__title">Recently Viewed</h2>
        <ul class="products" id="bsg-recently-viewed-grid"></ul>
    </section>
    <?php if ($product) : ?>
        <script>window.bsgCurrentProductId = <?php echo (int) $product->get_id(); ?>;</script>
    <?php endif; ?>
    <?php
}
add_action('woocommerce_after_single_product_summary', 'bsg_recently_viewed_section', 25);

/**
 * ---------------------------------------------------------------------------
 * 5. Mobile Sticky Add-to-Cart Bar (PDP)
 * ---------------------------------------------------------------------------
 */
function bsg_mobile_sticky_cta() {
    if (!is_product()) return;
    global $product;
    $has_variations = $product->is_type('variable');
    ?>
    <div class="bsg-mobile-sticky-bar" id="bsg-mobile-sticky-bar">
        <div class="sticky-price-title">
            <span class="sticky-title"><?php echo esc_html($product->get_name()); ?></span>
            <span class="sticky-meta">
                <span class="sticky-price"><?php echo wp_kses_post($product->get_price_html()); ?></span>
                <?php if ($has_variations) : ?>
                    <span class="sticky-size" id="bsg-sticky-size">Select size</span>
                <?php endif; ?>
            </span>
        </div>
        <button type="button" class="bsg-sticky-buy-btn" id="bsg-sticky-buy-btn">
            Add to Bag
        </button>
    </div>
    <?php
}
add_action('wp_footer', 'bsg_mobile_sticky_cta');

/**
 * ---------------------------------------------------------------------------
 * 5b. Fixed bottom tab bar (mobile only). Skipped on the product page since
 * the sticky Add to Bag bar already occupies that space — showing both
 * stacked would eat too much screen real estate.
 * ---------------------------------------------------------------------------
 */
function bsg_bottom_tab_bar() {
    if (is_product()) return;

    $cart_count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
    $tabs = array(
        array(
            'label' => 'Home',
            'url'   => home_url('/'),
            'active' => is_front_page(),
            'icon'  => '<svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/></svg>',
        ),
        array(
            'label' => 'Shop',
            'url'   => get_permalink(wc_get_page_id('shop')),
            'active' => is_shop() || is_product_category() || is_product_taxonomy(),
            'icon'  => '<svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/></svg>',
        ),
        array(
            'label' => 'Wishlist',
            'url'   => home_url('/wishlist/'),
            'active' => is_page('wishlist'),
            'icon'  => '<svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8Z"/></svg>',
            'badge' => '<span class="bsg-wishlist-count bsg-tabbar__badge">0</span>',
        ),
        array(
            'label' => 'Cart',
            'url'   => wc_get_cart_url(),
            'active' => is_cart(),
            'icon'  => '<svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
            'badge' => '<span class="bsg-cart-count bsg-tabbar__badge">' . esc_html($cart_count) . '</span>',
            'id'    => 'bsg-tabbar-cart',
        ),
        array(
            'label' => 'Account',
            'url'   => wc_get_page_permalink('myaccount'),
            'active' => is_account_page(),
            'icon'  => '<svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6.5 8-6.5s8 2.5 8 6.5"/></svg>',
        ),
    );
    ?>
    <nav class="bsg-tabbar" aria-label="Primary">
        <?php foreach ($tabs as $tab) : ?>
            <a href="<?php echo esc_url($tab['url']); ?>"
               class="bsg-tabbar__item<?php echo $tab['active'] ? ' is-active' : ''; ?>"
               <?php echo isset($tab['id']) ? 'id="' . esc_attr($tab['id']) . '"' : ''; ?>>
                <span class="bsg-tabbar__icon">
                    <?php echo $tab['icon']; ?>
                    <?php echo isset($tab['badge']) ? $tab['badge'] : ''; ?>
                </span>
                <span class="bsg-tabbar__label"><?php echo esc_html($tab['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php
}
add_action('wp_footer', 'bsg_bottom_tab_bar');

add_filter('body_class', function ($classes) {
    if (!is_product()) {
        $classes[] = 'bsg-has-tabbar';
    }
    return $classes;
});

/**
 * ---------------------------------------------------------------------------
 * 5c. Size ordering — clothing sizes always read S, M, L, XL, XXL.
 * bsg_size_rank() is the single source of truth: Quick Add, the mobile sheet,
 * the size filter and the one-time term-order migration below all use it.
 * ---------------------------------------------------------------------------
 */
function bsg_size_rank($value) {
    static $rank = array(
        'xxs' => 1, 'xs' => 2, 's' => 3, 'm' => 4, 'l' => 5,
        'xl' => 6, 'xxl' => 7, '2xl' => 7, 'xxxl' => 8, '3xl' => 8, '4xl' => 9,
    );
    $key = strtolower(trim((string) $value));
    if (isset($rank[$key])) return $rank[$key];
    if (is_numeric($key)) return 100 + (float) $key;
    return 1000;
}

/**
 * One-time migration: switch the Size attribute to custom ordering and give
 * each term its position, so swatches, the Additional information table and
 * the variation dropdown all follow S → XXL. Stored in the database, so it is
 * a no-op after the first run and travels with a database export.
 */
function bsg_migrate_size_order() {
    if (get_option('bsg_size_order_v2') || !taxonomy_exists('pa_size')) return;

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'woocommerce_attribute_taxonomies',
        array('attribute_orderby' => 'menu_order'),
        array('attribute_name' => 'size')
    );
    delete_transient('wc_attribute_taxonomies');
    if (class_exists('WC_Cache_Helper')) {
        WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
    }

    $terms = get_terms(array('taxonomy' => 'pa_size', 'hide_empty' => false));
    if (!is_wp_error($terms)) {
        foreach ($terms as $term) {
            // WooCommerce 3.6+ sorts attribute terms by the plain "order" meta.
            update_term_meta($term->term_id, 'order', bsg_size_rank($term->slug));
            update_term_meta($term->term_id, 'order_pa_size', bsg_size_rank($term->slug));
        }
    }
    update_option('bsg_size_order_v2', 1);
}
add_action('init', 'bsg_migrate_size_order', 20);

/**
 * ---------------------------------------------------------------------------
 * 6. Product loop cards — secondary image hover-flip + quick-add size overlay
 * ---------------------------------------------------------------------------
 */
function bsg_loop_product_media() {
    global $product;
    if (!$product) return;

    $primary_id  = $product->get_image_id();
    $gallery_ids = $product->get_gallery_image_ids();

    // The first two cards on a page are above the fold: load them straight
    // away (and flag the first as high priority); everything else lazy-loads.
    static $card_index = 0;
    $card_index++;
    $primary_attrs = array(
        'class'    => 'bsg-img-primary',
        'alt'      => $product->get_name(),
        'sizes'    => '(max-width: 767px) 46vw, (max-width: 1500px) 28vw, 22vw',
        'loading'  => $card_index <= 2 ? 'eager' : 'lazy',
        'decoding' => 'async',
    );
    if ($card_index === 1) {
        $primary_attrs['fetchpriority'] = 'high';
    }

    echo '<div class="bsg-product-media">';

    echo wp_kses_post(wp_get_attachment_image(
        $primary_id,
        'woocommerce_single',
        false,
        $primary_attrs
    ));

    if (!empty($gallery_ids)) {
        echo wp_kses_post(wp_get_attachment_image(
            $gallery_ids[0],
            'woocommerce_single',
            false,
            array('class' => 'bsg-img-secondary', 'alt' => $product->get_name() . ' — alternate view')
        ));
    }

    printf(
        '<button type="button" class="bsg-wishlist-heart" data-product-id="%d" aria-label="Add to wishlist"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8Z"/></svg></button>',
        (int) $product->get_id()
    );

    // Quick-add size overlay for variable products.
    if ($product->is_type('variable')) {
        $variations = $product->get_available_variations();
        $size_options = array();

        foreach ($variations as $variation) {
            foreach ($variation['attributes'] as $attr_key => $attr_value) {
                if (false !== stripos($attr_key, 'size') && $attr_value !== '') {
                    $size_options[$attr_value] = array(
                        'variation_id' => $variation['variation_id'],
                        'in_stock'     => $variation['is_in_stock'],
                        'attr_name'    => str_replace('attribute_', '', $attr_key),
                    );
                }
            }
        }

        uksort($size_options, function ($a, $b) {
            return bsg_size_rank($a) <=> bsg_size_rank($b);
        });

        if (!empty($size_options)) {
            // Touch devices get this round button (opens the bottom sheet);
            // the hover bar below is for mouse devices only. See CSS.
            printf(
                '<button type="button" class="bsg-qa-open" data-product-id="%d" aria-label="%s"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M12 10v6M9 13h6"/></svg></button>',
                (int) $product->get_id(),
                esc_attr(sprintf('Quick add %s to bag', $product->get_name()))
            );
            echo '<div class="bsg-quick-add" data-product-id="' . esc_attr($product->get_id()) . '">';
            echo '<span class="bsg-quick-add__label">Quick Add</span>';
            echo '<div class="bsg-quick-add__sizes">';
            foreach ($size_options as $size_value => $meta) {
                printf(
                    '<button type="button" class="bsg-quick-add__size%s" data-variation-id="%d" data-attr-name="%s" data-attr-value="%s"%s>%s</button>',
                    $meta['in_stock'] ? '' : ' is-disabled',
                    (int) $meta['variation_id'],
                    esc_attr($meta['attr_name']),
                    esc_attr($size_value),
                    $meta['in_stock'] ? '' : ' disabled',
                    esc_html(strtoupper($size_value))
                );
            }
            echo '</div></div>';
        }
    } elseif ($product->is_type('simple') && $product->is_purchasable() && $product->is_in_stock()) {
        printf(
            '<button type="button" class="bsg-qa-open" data-product-id="%d" aria-label="%s"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M12 10v6M9 13h6"/></svg></button>',
            (int) $product->get_id(),
            esc_attr(sprintf('Quick add %s to bag', $product->get_name()))
        );
        printf(
            '<button type="button" class="bsg-quick-add-simple" data-product-id="%d">Quick Add</button>',
            (int) $product->get_id()
        );
    }

    echo '</div>';
}
remove_action('woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10);
add_action('woocommerce_before_shop_loop_item_title', 'bsg_loop_product_media', 10);

// Remove the static "Add to cart"/"Select options" button from grid cards —
// the quick-add overlay (hover on desktop, always-on for touch) already
// covers this, so the extra button was visual clutter.
remove_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10);

/**
 * Mobile quick-add bottom sheet. One shared element; site.js fills it from
 * the tapped card (name, price, sizes) and posts to the existing quick-add
 * AJAX endpoint.
 */
function bsg_quick_add_sheet() {
    if (is_cart() || is_checkout()) return;
    ?>
    <div class="bsg-sheet-overlay" id="bsg-qa-overlay"></div>
    <div class="bsg-sheet" id="bsg-qa-sheet" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="bsg-qa-title">
        <button type="button" class="bsg-sheet__close" id="bsg-qa-close" aria-label="Close">&times;</button>
        <h3 class="bsg-sheet__title" id="bsg-qa-title"></h3>
        <div class="bsg-sheet__price" id="bsg-qa-price"></div>
        <div class="bsg-sheet__sizes-wrap" id="bsg-qa-sizes-wrap">
            <div class="bsg-sheet__label-row">
                <span>Select size</span>
                <a href="#" class="bsg-sheet__guide" id="bsg-qa-guide">Size Guide</a>
            </div>
            <div class="bsg-sheet__sizes" id="bsg-qa-sizes" role="group" aria-label="Sizes"></div>
        </div>
        <p class="bsg-sheet__msg" id="bsg-qa-msg" role="alert"></p>
        <button type="button" class="bsg-sheet__add" id="bsg-qa-add">Add to Bag</button>
    </div>
    <?php
}
add_action('wp_footer', 'bsg_quick_add_sheet');

/**
 * Product page button wording: "Select a size" until a size is chosen, then
 * "Add to Bag". site.js flips it as the shopper picks / clears a size.
 */
add_filter('woocommerce_product_single_add_to_cart_text', function ($text, $product) {
    if ($product && $product->is_type('variable') && !$product->get_default_attributes()) {
        return 'Select a size';
    }
    return 'Add to Bag';
}, 10, 2);

/**
 * AJAX: quick-add a specific variation (or simple product) to the cart.
 */
function bsg_ajax_quick_add() {
    check_ajax_referer('bsg_quick_add', 'nonce');

    if (!function_exists('WC') || !WC()->cart) {
        wp_send_json_error(array('message' => 'Cart unavailable'));
    }

    $product_id   = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
    $attr_name    = isset($_POST['attr_name']) ? sanitize_title(wp_unslash($_POST['attr_name'])) : '';
    $attr_value   = isset($_POST['attr_value']) ? sanitize_text_field(wp_unslash($_POST['attr_value'])) : '';

    $variation_data = array();
    if ($attr_name) {
        $variation_data['attribute_' . $attr_name] = $attr_value;
    }

    $added = WC()->cart->add_to_cart($product_id, 1, $variation_id, $variation_data);

    if ($added) {
        wp_send_json_success(array(
            'fragments' => apply_filters('woocommerce_add_to_cart_fragments', array()),
            'cart_hash' => WC()->cart->get_cart_hash(),
        ));
    }

    wp_send_json_error(array('message' => 'Could not add to cart'));
}
add_action('wp_ajax_bsg_quick_add', 'bsg_ajax_quick_add');
add_action('wp_ajax_nopriv_bsg_quick_add', 'bsg_ajax_quick_add');

/**
 * ---------------------------------------------------------------------------
 * 6b. Server-side wishlist (logged-in users) — persists across devices.
 * Guests still use localStorage only, same as before; on login their local
 * list is merged into their account wishlist.
 * ---------------------------------------------------------------------------
 */
function bsg_get_user_wishlist($user_id) {
    $ids = get_user_meta($user_id, '_bsg_wishlist', true);
    return is_array($ids) ? array_map('absint', $ids) : array();
}

function bsg_ajax_wishlist_get() {
    check_ajax_referer('bsg_wishlist', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_success(array('ids' => array()));
    }
    wp_send_json_success(array('ids' => bsg_get_user_wishlist(get_current_user_id())));
}
add_action('wp_ajax_bsg_wishlist_get', 'bsg_ajax_wishlist_get');

function bsg_ajax_wishlist_toggle() {
    check_ajax_referer('bsg_wishlist', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Not logged in'), 401);
    }

    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    if (!$product_id) {
        wp_send_json_error(array('message' => 'Invalid product'));
    }

    $user_id = get_current_user_id();
    $ids = bsg_get_user_wishlist($user_id);
    $idx = array_search($product_id, $ids, true);

    if ($idx === false) {
        $ids[] = $product_id;
        $added = true;
    } else {
        array_splice($ids, $idx, 1);
        $added = false;
    }

    update_user_meta($user_id, '_bsg_wishlist', $ids);
    wp_send_json_success(array('ids' => $ids, 'added' => $added));
}
add_action('wp_ajax_bsg_wishlist_toggle', 'bsg_ajax_wishlist_toggle');

/**
 * Merge a guest's localStorage wishlist into their account on login.
 */
function bsg_ajax_wishlist_merge() {
    check_ajax_referer('bsg_wishlist', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Not logged in'), 401);
    }

    $incoming = isset($_POST['ids']) ? array_map('absint', (array) $_POST['ids']) : array();
    $user_id = get_current_user_id();
    $existing = bsg_get_user_wishlist($user_id);
    $merged = array_values(array_unique(array_merge($existing, $incoming)));

    update_user_meta($user_id, '_bsg_wishlist', $merged);
    wp_send_json_success(array('ids' => $merged));
}
add_action('wp_ajax_bsg_wishlist_merge', 'bsg_ajax_wishlist_merge');

/**
 * Renders product cards for a given list of IDs. Shared by anything that
 * only has product IDs on the client (localStorage) and needs real markup
 * back: the guest wishlist view, and Recently Viewed.
 */
function bsg_render_product_cards_by_ids($ids) {
    $ids = array_filter(array_map('absint', (array) $ids));

    ob_start();
    foreach ($ids as $id) {
        $post_obj = get_post($id);
        if (!$post_obj || $post_obj->post_type !== 'product') continue;

        global $product, $post;
        $post = $post_obj;
        setup_postdata($post);
        $product = wc_get_product($id);
        if (!$product) continue;

        wc_get_template_part('content', 'product');
    }
    wp_reset_postdata();
    return array('html' => ob_get_clean(), 'count' => count($ids));
}

function bsg_ajax_wishlist_render_guest() {
    check_ajax_referer('bsg_wishlist', 'nonce');
    $ids = isset($_POST['ids']) ? (array) $_POST['ids'] : array();
    wp_send_json_success(bsg_render_product_cards_by_ids($ids));
}
add_action('wp_ajax_bsg_wishlist_render_guest', 'bsg_ajax_wishlist_render_guest');
add_action('wp_ajax_nopriv_bsg_wishlist_render_guest', 'bsg_ajax_wishlist_render_guest');

/**
 * Recently Viewed — same "render these product IDs" need, tracked
 * client-side (localStorage) since it applies to guests and logged-in
 * users alike and doesn't need to persist server-side.
 */
function bsg_ajax_recently_viewed_render() {
    check_ajax_referer('bsg_wishlist', 'nonce');
    $ids = isset($_POST['ids']) ? (array) $_POST['ids'] : array();
    wp_send_json_success(bsg_render_product_cards_by_ids($ids));
}
add_action('wp_ajax_bsg_recently_viewed_render', 'bsg_ajax_recently_viewed_render');
add_action('wp_ajax_nopriv_bsg_recently_viewed_render', 'bsg_ajax_recently_viewed_render');

/**
 * [bsg_wishlist] — dedicated wishlist page. Server-rendered for logged-in
 * users (reads user meta); for guests, renders an empty shell that JS fills
 * in from localStorage, since guest wishlists only exist client-side.
 */
add_shortcode('bsg_wishlist', function () {
    ob_start();

    if (is_user_logged_in()) {
        $ids = bsg_get_user_wishlist(get_current_user_id());
        if (empty($ids)) {
            echo '<p class="bsg-wishlist-empty">Your wishlist is empty. <a href="' . esc_url(get_permalink(wc_get_page_id('shop'))) . '">Start browsing</a>.</p>';
        } else {
            echo '<div class="woocommerce"><ul class="products columns-4" id="bsg-wishlist-grid">';
            foreach ($ids as $id) {
                $post_obj = get_post($id);
                if (!$post_obj) continue;

                global $product, $post;
                $post = $post_obj;
                setup_postdata($post);
                $product = wc_get_product($id);
                if (!$product) continue;

                wc_get_template_part('content', 'product');
            }
            wp_reset_postdata();
            echo '</ul></div>';
        }
    } else {
        echo '<p class="bsg-wishlist-empty" id="bsg-wishlist-guest-empty">Your wishlist is empty.</p>';
        echo '<div class="woocommerce"><ul class="products columns-4" id="bsg-wishlist-grid" data-guest="1" style="display:none;"></ul></div>';
    }

    return ob_get_clean();
});

/**
 * ---------------------------------------------------------------------------
 * 7. Slide-over cart drawer
 * ---------------------------------------------------------------------------
 */
function bsg_render_shipping_bar() {
    $total     = WC()->cart ? WC()->cart->get_subtotal() : 0;
    $goal      = 999;
    $pct       = $goal > 0 ? min(100, ($total / $goal) * 100) : 100;
    $remaining = max(0, $goal - $total);
    ?>
    <div id="bsg-shipping-fill-wrap">
        <div class="bsg-shipping-progress">
            <div class="bsg-shipping-progress__fill" style="width: <?php echo esc_attr($pct); ?>%;"></div>
        </div>
        <p class="bsg-shipping-message">
            <?php if ($remaining > 0) : ?>
                Add <?php echo wp_kses_post(wc_price($remaining)); ?> more for FREE shipping
            <?php else : ?>
                You've unlocked FREE shipping!
            <?php endif; ?>
        </p>
    </div>
    <?php
}

function bsg_cart_drawer_markup() {
    ?>
    <div class="bsg-cart-drawer-overlay" id="bsg-cart-overlay"></div>
    <aside class="bsg-cart-drawer" id="bsg-cart-drawer" aria-hidden="true">
        <div class="bsg-cart-drawer__header">
            <span>Your Bag</span>
            <button type="button" class="bsg-cart-drawer__close" id="bsg-cart-close" aria-label="Close cart">&times;</button>
        </div>
        <div class="bsg-cart-drawer__shipping-bar" id="bsg-cart-shipping-bar">
            <?php bsg_render_shipping_bar(); ?>
        </div>
        <div class="bsg-cart-drawer__items" id="bsg-cart-drawer-items">
            <?php echo do_shortcode('[woocommerce_cart]'); ?>
        </div>
    </aside>
    <?php
}
add_action('wp_footer', 'bsg_cart_drawer_markup');

/**
 * Inject the free-shipping progress + drawer content into the WC fragments
 * payload so the drawer updates live after AJAX add-to-cart.
 */
function bsg_cart_fragments($fragments) {
    ob_start();
    bsg_render_shipping_bar();
    $fragments['#bsg-shipping-fill-wrap'] = ob_get_clean();

    ob_start();
    echo '<span class="bsg-cart-count">' . (WC()->cart ? WC()->cart->get_cart_contents_count() : 0) . '</span>';
    $fragments['.bsg-cart-count'] = ob_get_clean();

    ob_start();
    ?>
    <div class="bsg-cart-drawer__items" id="bsg-cart-drawer-items">
        <?php echo do_shortcode('[woocommerce_cart]'); ?>
    </div>
    <?php
    $fragments['#bsg-cart-drawer-items'] = ob_get_clean();

    return $fragments;
}
add_filter('woocommerce_add_to_cart_fragments', 'bsg_cart_fragments');

/**
 * ---------------------------------------------------------------------------
 * 8. Header row — search (left), logo (centered by CSS), account + cart (right)
 * ---------------------------------------------------------------------------
 */
function bsg_header_search() {
    // Rotating placeholder terms — pulled from real product categories, not fabricated.
    $terms = array('kurtas', 'shirts', 'jeans', 'trousers', 'accessories');
    ?>
    <form class="bsg-header-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
        <input type="hidden" name="post_type" value="product">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="search" name="s" id="bsg-header-search-input" placeholder="Search for products" value="<?php echo esc_attr(get_search_query()); ?>" data-rotate-terms="<?php echo esc_attr(implode(',', $terms)); ?>">
    </form>
    <?php
}
add_action('generate_after_header_content', 'bsg_header_search', 4);

function bsg_header_icons() {
    $count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
    ?>
    <div class="bsg-header-icons">
        <button type="button" id="bsg-search-open" class="bsg-search-open" aria-label="Search products" aria-haspopup="dialog">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        </button>
        <button type="button" id="bsg-theme-toggle" class="bsg-theme-toggle" aria-label="Switch to dark theme" aria-pressed="false">
            <svg class="icon-sun" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
            <svg class="icon-moon" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg>
        </button>
        <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="bsg-account-trigger" aria-label="My account">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6.5 8-6.5s8 2.5 8 6.5"/></svg>
        </a>
        <a href="<?php echo esc_url(home_url('/wishlist/')); ?>" id="bsg-wishlist-trigger" class="bsg-wishlist-trigger" aria-label="Wishlist">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8Z"/></svg>
            <span class="bsg-wishlist-count">0</span>
        </a>
        <button type="button" id="bsg-cart-trigger" class="bsg-cart-trigger" aria-label="Open cart">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
            <span class="bsg-cart-count"><?php echo esc_html($count); ?></span>
        </button>
    </div>
    <?php
}
add_action('generate_after_header_content', 'bsg_header_icons', 5);

/**
 * ---------------------------------------------------------------------------
 * 8c. Persistent category tab strip — every page, directly below header
 * ---------------------------------------------------------------------------
 */
function bsg_category_tab_strip() {
    $shop_url = get_permalink(wc_get_page_id('shop'));
    $categories = get_terms(array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
        'exclude'    => array(get_option('default_product_cat')),
    ));
    if (is_wp_error($categories)) {
        $categories = array();
    }

    $current_slug = is_product_category() ? get_queried_object()->slug : '';
    ?>
    <nav class="bsg-cat-tabs" aria-label="Shop by category">
        <div class="bsg-cat-tabs__scroll">
            <a href="<?php echo esc_url($shop_url); ?>" class="bsg-cat-tabs__item<?php echo (is_shop() ? ' is-active' : ''); ?>">Discover</a>
            <?php foreach ($categories as $cat) : ?>
                <a href="<?php echo esc_url(get_term_link($cat)); ?>" class="bsg-cat-tabs__item<?php echo ($current_slug === $cat->slug ? ' is-active' : ''); ?>"><?php echo esc_html($cat->name); ?></a>
            <?php endforeach; ?>
            <a href="<?php echo esc_url(add_query_arg('on_sale', '1', $shop_url)); ?>" class="bsg-cat-tabs__item bsg-cat-tabs__item--sale">Sale</a>
        </div>
    </nav>
    <?php
}
add_action('generate_after_header', 'bsg_category_tab_strip', 5);

/**
 * ---------------------------------------------------------------------------
 * 9. Shop sidebar — filters
 * ---------------------------------------------------------------------------
 */
add_action('widgets_init', function () {
    register_sidebar(array(
        'name'          => 'Shop Filters',
        'id'            => 'bsg-shop-filters',
        'description'   => 'Filters shown on the shop and category pages.',
        'before_widget' => '<div class="bsg-filter-widget">',
        'after_widget'  => '</div>',
        'before_title'  => '<h4 class="bsg-filter-widget__title">',
        'after_title'   => '</h4>',
    ));
});

/**
 * Quick-filter pills — under the page H1, above the sidebar+grid layout.
 * Uses real, non-fabricated distinctions (no Fit/Fabric attribute data
 * exists in the catalog yet, so those facets aren't added here or to the
 * sidebar until that data is entered).
 */
function bsg_quick_filter_pills($context = '') {
    if (!is_shop() && !is_product_category() && !is_product_tag()) return;
    $wrap_class = 'bsg-quick-filters' . ($context === 'sheet' ? ' bsg-quick-filters--sheet' : ' bsg-quick-filters--page');

    $base = is_product_category() || is_product_tag() ? get_term_link(get_queried_object()) : get_permalink(wc_get_page_id('shop'));
    if (is_wp_error($base)) $base = get_permalink(wc_get_page_id('shop'));

    $is_new  = !empty($_GET['orderby']) && $_GET['orderby'] === 'date';
    $is_sale = !empty($_GET['on_sale']);
    $is_stock = !empty($_GET['bsg_in_stock']);
    $is_all  = !$is_new && !$is_sale && !$is_stock;
    ?>
    <div class="<?php echo esc_attr($wrap_class); ?>">
        <a href="<?php echo esc_url($base); ?>" class="bsg-quick-filters__pill<?php echo $is_all ? ' is-active' : ''; ?>">All</a>
        <a href="<?php echo esc_url(add_query_arg('orderby', 'date', $base)); ?>" class="bsg-quick-filters__pill<?php echo $is_new ? ' is-active' : ''; ?>">New</a>
        <a href="<?php echo esc_url(add_query_arg('on_sale', '1', $base)); ?>" class="bsg-quick-filters__pill<?php echo $is_sale ? ' is-active' : ''; ?>">Sale</a>
        <a href="<?php echo esc_url(add_query_arg('bsg_in_stock', '1', $base)); ?>" class="bsg-quick-filters__pill<?php echo $is_stock ? ' is-active' : ''; ?>">In Stock</a>
    </div>
    <?php
}
add_action('woocommerce_archive_description', 'bsg_quick_filter_pills', 15);

add_action('woocommerce_before_shop_loop', 'bsg_shop_sidebar_open', 5);
function bsg_shop_sidebar_open() {
    global $wp_query;
    $total = (int) $wp_query->found_posts;
    $icon_filter = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 5h18M6 12h12M10 19h4"/></svg>';
    $icon_sort = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 4v16M7 20l-3-3M7 20l3-3M17 20V4M17 4l-3 3M17 4l3 3"/></svg>';

    // Mobile: one sticky bar with two buttons, each opening a bottom sheet.
    echo '<div class="bsg-shop-toolbar" id="bsg-shop-toolbar">';
    echo '<button type="button" class="bsg-toolbar-btn" id="bsg-filter-trigger" aria-haspopup="dialog">' . $icon_filter . '<span>Filter</span></button>';
    echo '<button type="button" class="bsg-toolbar-btn" id="bsg-sort-trigger" aria-haspopup="dialog">' . $icon_sort . '<span>Sort</span></button>';
    echo '</div>';

    echo '<div class="bsg-filter-overlay" id="bsg-filter-overlay"></div>';

    echo '<div class="bsg-sheet-overlay" id="bsg-sort-overlay"></div>';
    echo '<div class="bsg-sheet bsg-sort-sheet" id="bsg-sort-sheet" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="bsg-sort-title">';
    echo '<button type="button" class="bsg-sheet__close" id="bsg-sort-close" aria-label="Close">&times;</button>';
    echo '<h3 class="bsg-sheet__title" id="bsg-sort-title">Sort by</h3>';
    echo '<div class="bsg-sort-options" id="bsg-sort-options" role="radiogroup" aria-labelledby="bsg-sort-title"></div>';
    echo '</div>';

    echo '<div class="bsg-shop-layout"><aside class="bsg-shop-sidebar" id="bsg-shop-sidebar" role="dialog" aria-label="Filters">';
    echo '<div class="bsg-filter-drawer-header"><span>Filters</span><button type="button" class="bsg-filter-close" id="bsg-filter-close" aria-label="Close filters">&times;</button></div>';
    bsg_quick_filter_pills('sheet');
    dynamic_sidebar('bsg-shop-filters');
    do_action('bsg_shop_sidebar_widgets');
    echo '<div class="bsg-filter-apply"><button type="button" class="bsg-filter-apply__btn" id="bsg-filter-apply">Show ' . esc_html($total) . ' ' . esc_html(_n('product', 'products', $total, 'woocommerce')) . '</button></div>';
    echo '</aside><div class="bsg-shop-main">';
}
add_action('woocommerce_after_shop_loop', 'bsg_shop_sidebar_close', 100);
function bsg_shop_sidebar_close() {
    echo '</div></div>';
}

/**
 * Filters live in the custom .bsg-shop-sidebar drawer above, not
 * GeneratePress's own sidebar widget area — so on shop/category pages that
 * area renders as a large empty reserved column. Force full-width there.
 */
add_filter('generate_sidebar_layout', function ($layout) {
    if (is_shop() || is_product_taxonomy() || is_product() || is_cart() || is_checkout() || is_account_page()) {
        return 'no-sidebar';
    }
    if (is_page()) {
        return 'no-sidebar';
    }
    return $layout;
});

/**
 * ---------------------------------------------------------------------------
 * 9b. Load More (replaces numbered pagination on shop/category archives)
 * ---------------------------------------------------------------------------
 */
remove_action('woocommerce_after_shop_loop', 'woocommerce_pagination', 10);
add_action('woocommerce_after_shop_loop', 'bsg_load_more_button', 10);

function bsg_load_more_button() {
    global $wp_query;
    if ($wp_query->max_num_pages <= 1) return;

    $category = '';
    if (is_product_category()) {
        $term = get_queried_object();
        $category = $term ? $term->slug : '';
    }
    ?>
    <div class="bsg-load-more-wrap"
        data-page="1"
        data-max-pages="<?php echo esc_attr($wp_query->max_num_pages); ?>"
        data-category="<?php echo esc_attr($category); ?>"
        data-orderby="<?php echo esc_attr(isset($_GET['orderby']) ? wc_clean(wp_unslash($_GET['orderby'])) : ''); ?>"
        data-min-price="<?php echo esc_attr(isset($_GET['min_price']) ? wc_clean(wp_unslash($_GET['min_price'])) : ''); ?>"
        data-max-price="<?php echo esc_attr(isset($_GET['max_price']) ? wc_clean(wp_unslash($_GET['max_price'])) : ''); ?>"
        data-filter-size="<?php echo esc_attr(isset($_GET['filter_pa_size']) ? wc_clean(wp_unslash($_GET['filter_pa_size'])) : ''); ?>"
        data-in-stock="<?php echo esc_attr(isset($_GET['bsg_in_stock']) ? '1' : ''); ?>"
    >
        <button type="button" class="bsg-load-more-btn" id="bsg-load-more-btn">Load More</button>
    </div>
    <?php
}

function bsg_ajax_load_more() {
    check_ajax_referer('bsg_load_more', 'nonce');

    $page      = isset($_POST['page']) ? absint($_POST['page']) : 1;
    $category  = isset($_POST['category']) ? sanitize_title(wp_unslash($_POST['category'])) : '';
    $orderby   = isset($_POST['orderby']) ? sanitize_text_field(wp_unslash($_POST['orderby'])) : '';
    $min_price = isset($_POST['min_price']) && $_POST['min_price'] !== '' ? (float) $_POST['min_price'] : null;
    $max_price = isset($_POST['max_price']) && $_POST['max_price'] !== '' ? (float) $_POST['max_price'] : null;
    $sizes     = isset($_POST['filter_size']) ? array_filter(array_map('sanitize_title', explode(',', wp_unslash($_POST['filter_size'])))) : array();
    $in_stock  = !empty($_POST['in_stock']);

    $args = array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 8,
        'paged'          => $page,
    );

    $tax_query = array();
    if ($category) {
        $tax_query[] = array('taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $category);
    }
    if (!empty($sizes)) {
        $tax_query[] = array('taxonomy' => 'pa_size', 'field' => 'slug', 'terms' => $sizes);
    }
    if (!empty($tax_query)) {
        $args['tax_query'] = $tax_query; // phpcs:ignore
    }

    $meta_query = array();
    if ($min_price !== null || $max_price !== null) {
        $meta_query[] = array(
            'key'     => '_price',
            'value'   => array($min_price ?? 0, $max_price ?? 999999),
            'type'    => 'NUMERIC',
            'compare' => 'BETWEEN',
        );
    }
    if ($in_stock) {
        $meta_query[] = array('key' => '_stock_status', 'value' => 'instock', 'compare' => '=');
    }
    if (!empty($meta_query)) {
        $args['meta_query'] = $meta_query; // phpcs:ignore
    }

    switch ($orderby) {
        case 'price':
            $args['orderby'] = 'meta_value_num';
            $args['meta_key'] = '_price'; // phpcs:ignore
            $args['order'] = 'ASC';
            break;
        case 'price-desc':
            $args['orderby'] = 'meta_value_num';
            $args['meta_key'] = '_price'; // phpcs:ignore
            $args['order'] = 'DESC';
            break;
        case 'date':
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';
            break;
        case 'popularity':
            $args['orderby'] = 'meta_value_num';
            $args['meta_key'] = 'total_sales'; // phpcs:ignore
            $args['order'] = 'DESC';
            break;
        default:
            $args['orderby'] = 'menu_order title';
            $args['order'] = 'ASC';
    }

    $query = new WP_Query($args);

    ob_start();
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            global $product;
            $product = wc_get_product(get_the_ID());
            wc_get_template_part('content', 'product');
        }
    }
    wp_reset_postdata();
    $html = ob_get_clean();

    wp_send_json_success(array(
        'html'     => $html,
        'hasMore'  => $page < $query->max_num_pages,
    ));
}
add_action('wp_ajax_bsg_load_more', 'bsg_ajax_load_more');
add_action('wp_ajax_nopriv_bsg_load_more', 'bsg_ajax_load_more');

/**
 * Custom Size filter list — WooCommerce's own "Filter Products by Attribute"
 * widget silently hides itself unless a live shop query context is present,
 * so we render our own list. It reuses WC_Query's native ?filter_pa_size=
 * handling, which still filters the product query correctly.
 */
function bsg_size_filter_widget() {
    if (!is_shop() && !is_product_category() && !is_product_tag()) {
        return;
    }

    $terms = get_terms(array('taxonomy' => 'pa_size', 'hide_empty' => true));
    if (empty($terms) || is_wp_error($terms)) {
        return;
    }
    usort($terms, function ($a, $b) {
        return bsg_size_rank($a->slug) <=> bsg_size_rank($b->slug);
    });

    $current = isset($_GET['filter_pa_size']) ? explode(',', wc_clean(wp_unslash($_GET['filter_pa_size']))) : array();

    echo '<div class="bsg-filter-widget"><h4 class="bsg-filter-widget__title">Size</h4><ul class="bsg-size-filter-list">';
    foreach ($terms as $term) {
        $is_active = in_array($term->slug, $current, true);
        $new_selection = $is_active
            ? array_diff($current, array($term->slug))
            : array_merge($current, array($term->slug));

        if (empty($new_selection)) {
            $link = remove_query_arg('filter_pa_size');
        } else {
            $link = add_query_arg('filter_pa_size', implode(',', $new_selection));
        }

        printf(
            '<li><a href="%s" class="bsg-size-filter-pill%s">%s</a></li>',
            esc_url($link),
            $is_active ? ' is-active' : '',
            esc_html(strtoupper($term->name))
        );
    }
    echo '</ul></div>';
}
add_action('bsg_shop_sidebar_widgets', 'bsg_size_filter_widget', 15);

/**
 * Availability toggle — filters the shop/category query to in-stock only
 * when ?bsg_in_stock=1 is present (paired with the "Availability" widget).
 */
add_action('pre_get_posts', function ($query) {
    if (is_admin() || !$query->is_main_query()) return;
    if (!($query->is_shop() || $query->is_product_category() || $query->is_product_tag())) return;
    if (empty($_GET['bsg_in_stock'])) return;

    $meta_query = (array) $query->get('meta_query');
    $meta_query[] = array(
        'key'     => '_stock_status',
        'value'   => 'instock',
        'compare' => '=',
    );
    $query->set('meta_query', $meta_query);
});

/**
 * "Sale" category tab — filters the shop/category query to on-sale products
 * only when ?on_sale=1 is present.
 */
add_action('pre_get_posts', function ($query) {
    if (is_admin() || !$query->is_main_query()) return;
    if (!($query->is_shop() || $query->is_product_category() || $query->is_product_tag())) return;
    if (empty($_GET['on_sale'])) return;

    $sale_ids = wc_get_product_ids_on_sale();
    $query->set('post__in', !empty($sale_ids) ? $sale_ids : array(0));
});

/**
 * ---------------------------------------------------------------------------
 * 9c. Contact form (lightweight, no plugin dependency)
 * ---------------------------------------------------------------------------
 */
add_shortcode('bsg_contact_form', function () {
    $sent = false;
    $error = '';

    if (!empty($_POST['bsg_contact_submit']) && check_admin_referer('bsg_contact_form', 'bsg_contact_nonce')) {
        $name    = sanitize_text_field(wp_unslash($_POST['bsg_contact_name'] ?? ''));
        $email   = sanitize_email(wp_unslash($_POST['bsg_contact_email'] ?? ''));
        $message = sanitize_textarea_field(wp_unslash($_POST['bsg_contact_message'] ?? ''));

        if (!$name || !is_email($email) || !$message) {
            $error = 'Please fill in your name, a valid email, and a message.';
        } else {
            $sent = wp_mail(
                apply_filters('bsg_contact_recipient', get_option('admin_email')),
                'New contact form message from ' . $name,
                $message . "\n\nReply to: " . $email,
                array('Reply-To: ' . $name . ' <' . $email . '>')
            );
            if (!$sent) {
                $error = 'Something went wrong sending your message. Please email us directly instead.';
            }
        }
    }

    ob_start();
    ?>
    <form class="bsg-contact-form" method="post">
        <?php wp_nonce_field('bsg_contact_form', 'bsg_contact_nonce'); ?>

        <?php if ($sent) : ?>
            <p class="bsg-contact-form__success">Thanks — your message has been sent. We'll get back to you soon.</p>
        <?php else : ?>
            <?php if ($error) : ?>
                <p class="bsg-contact-form__error"><?php echo esc_html($error); ?></p>
            <?php endif; ?>
            <label>Name<input type="text" name="bsg_contact_name" required></label>
            <label>Email<input type="email" name="bsg_contact_email" required></label>
            <label>Message<textarea name="bsg_contact_message" rows="5" required></textarea></label>
            <button type="submit" name="bsg_contact_submit" value="1">Send Message</button>
        <?php endif; ?>
    </form>
    <?php
    return ob_get_clean();
});


/**
 * Payment methods the customer can really use right now, read from the WooCommerce gateways
 * that are switched on. The footer and product-page labels use this, so they never promise a
 * method that is not live (turn Razorpay on and UPI / Cards / NetBanking appear by themselves).
 */
function bsg_payment_labels() {
    if (!function_exists('WC') || !WC()->payment_gateways()) {
        return array();
    }
    $labels = array();
    foreach (WC()->payment_gateways()->payment_gateways() as $id => $gw) {
        if ($gw->enabled !== 'yes') continue;
        if ($id === 'cod') {
            $labels[] = 'Cash on Delivery';
        } elseif ($id === 'razorpay') {
            array_push($labels, 'UPI', 'Cards', 'NetBanking', 'Wallets');
        } elseif ($id === 'pgppw_phonepe') {
            $labels[] = 'UPI';
        } else {
            $labels[] = wp_strip_all_tags($gw->get_title());
        }
    }
    return array_values(array_unique(array_filter($labels)));
}

/**
 * ---------------------------------------------------------------------------
 * 10. Footer — payment icons, directory, newsletter
 * ---------------------------------------------------------------------------
 */
function bsg_footer_extra() {
    ?>
    <div class="bsg-footer-extra">
        <div class="bsg-footer-newsletter">
            <h4>Stay in the loop</h4>
            <p>New drops, restocks, and 10% off your first order.</p>
            <form class="bsg-newsletter-form" id="bsg-newsletter-form" novalidate>
                <label class="screen-reader-text" for="bsg-newsletter-email">Email address</label>
                <input type="email" id="bsg-newsletter-email" name="email" placeholder="Your email address" autocomplete="email" required>
                <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">
                <button type="submit">Subscribe</button>
            </form>
            <p class="bsg-newsletter-msg" id="bsg-newsletter-msg" role="status" aria-live="polite"></p>
        </div>
        <div class="bsg-footer-columns">
            <div class="bsg-footer-col">
                <h5>Shop</h5>
                <ul>
                    <li><a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>">All Products</a></li>
                    <li><a href="<?php echo esc_url(get_term_link('kurtas', 'product_cat')); ?>">Kurtas</a></li>
                    <li><a href="<?php echo esc_url(get_term_link('shirts', 'product_cat')); ?>">Shirts</a></li>
                    <li><a href="<?php echo esc_url(get_term_link('jeans-trousers', 'product_cat')); ?>">Jeans &amp; Trousers</a></li>
                </ul>
            </div>
            <div class="bsg-footer-col">
                <h5>Help</h5>
                <ul>
                    <li><a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>">My Account</a></li>
                    <li><a href="<?php echo esc_url(wc_get_page_permalink('cart')); ?>">Cart</a></li>
                    <li><a href="<?php echo esc_url(home_url('/track-order/')); ?>">Track Order</a></li>
                    <li><a href="<?php echo esc_url(home_url('/refund_returns/')); ?>">Shipping &amp; Returns</a></li>
                    <li><a href="<?php echo esc_url(home_url('/terms-and-conditions/')); ?>">Terms &amp; Conditions</a></li>
                </ul>
            </div>
            <div class="bsg-footer-col">
                <h5>Company</h5>
                <ul>
                    <li><a href="<?php echo esc_url(home_url('/contact-us/')); ?>">Contact</a></li>
                    <li><a href="<?php echo esc_url(home_url('/privacy-policy/')); ?>">Privacy Policy</a></li>
                </ul>
            </div>
            <?php bsg_footer_contact_col(); ?>
        </div>
        <div class="bsg-footer-bottom">
            <?php $pay = bsg_payment_labels(); if ($pay) : ?>
            <div class="bsg-payment-badges" aria-label="Accepted payment methods">
                <?php foreach ($pay as $label) : ?><span><?php echo esc_html($label); ?></span><?php endforeach; ?>
            </div>
            <?php endif; ?>
            <p class="bsg-footer-copyright">&copy; <?php echo esc_html(gmdate('Y')); ?> BSG Garments. All rights reserved.</p>
        </div>
    </div>
    <?php
}
add_action('generate_before_footer', 'bsg_footer_extra');

/**
 * ---------------------------------------------------------------------------
 * 11. Theme support
 * ---------------------------------------------------------------------------
 */
add_action('after_setup_theme', function () {
    add_theme_support('wc-product-gallery-zoom');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');

    // Remove GeneratePress's default "© Year Site Name • Built with GeneratePress"
    // credit line — our own footer (bsg_footer_extra) already renders copyright.
    remove_action('generate_credits', 'generate_add_footer_info');
});

/**
 * ---------------------------------------------------------------------------
 * 12. Checkout field mobile input attributes (India defaults)
 * ---------------------------------------------------------------------------
 * Correct keyboard + autofill behaviour on mobile for phone and PIN code.
 */
function bsg_checkout_field_mobile_attrs($fields) {
    foreach (array('billing', 'shipping') as $group) {
        if (isset($fields[$group . '_phone'])) {
            $fields[$group . '_phone']['autocomplete'] = 'tel';
            $fields[$group . '_phone']['custom_attributes'] = array_merge(
                $fields[$group . '_phone']['custom_attributes'] ?? array(),
                array('inputmode' => 'numeric')
            );
        }
        if (isset($fields[$group . '_postcode'])) {
            $fields[$group . '_postcode']['autocomplete'] = 'postal-code';
            $fields[$group . '_postcode']['custom_attributes'] = array_merge(
                $fields[$group . '_postcode']['custom_attributes'] ?? array(),
                array('inputmode' => 'numeric')
            );
        }
    }
    return $fields;
}
add_filter('woocommerce_billing_fields', 'bsg_checkout_field_mobile_attrs');
add_filter('woocommerce_shipping_fields', 'bsg_checkout_field_mobile_attrs');


/**
 * ---------------------------------------------------------------------------
 * 12. Product page gallery — swipeable scroll-snap carousel
 * WooCommerce's flexslider sizes itself to the first image and leaves a grey
 * strip when images differ in shape. Turning the slider off leaves the plain
 * list of images, which CSS turns into a swipe carousel on mobile and a
 * two-column grid on desktop. Zoom / lightbox stay on.
 * ---------------------------------------------------------------------------
 */
add_action('after_setup_theme', function () {
    remove_theme_support('wc-product-gallery-slider');
}, 99);

// With the slider off, WooCommerce would load the tiny thumbnail file for every photo after the
// first (blurry once stretched). Every gallery photo is a full slide here, so use the page size.
add_filter('woocommerce_gallery_image_size', function () {
    return 'woocommerce_single';
});

// First gallery image loads immediately; the rest lazy-load.
add_filter('woocommerce_single_product_image_thumbnail_html', function ($html) {
    static $index = 0;
    if ($index++ === 0) {
        return str_replace('<img ', '<img fetchpriority="high" loading="eager" ', $html);
    }
    return str_replace('<img ', '<img loading="lazy" ', $html);
});

// Descriptive alt text for gallery and thumbnail images that have none.
add_filter('wp_get_attachment_image_attributes', function ($attr, $attachment) {
    if (empty($attr['alt'])) {
        $parent = $attachment && $attachment->post_parent ? get_the_title($attachment->post_parent) : '';
        if (!$parent && function_exists('is_product') && is_product()) {
            $parent = get_the_title();
        }
        if ($parent) $attr['alt'] = $parent;
    }
    return $attr;
}, 10, 2);

/**
 * ---------------------------------------------------------------------------
 * 13. Related products — always at least 4. Falls back to other products
 * (best sellers first) when the category does not have enough.
 * ---------------------------------------------------------------------------
 */
add_filter('woocommerce_output_related_products_args', function ($args) {
    $args['posts_per_page'] = 6;
    $args['columns'] = 4;
    return $args;
});

add_filter('woocommerce_related_products', function ($ids, $product_id) {
    $ids = array_values(array_diff(array_map('intval', (array) $ids), array((int) $product_id)));
    if (count($ids) >= 4) return $ids;

    $fill = wc_get_products(array(
        'limit'      => 8,
        'status'     => 'publish',
        'exclude'    => array_merge(array((int) $product_id), $ids),
        'orderby'    => 'popularity',
        'order'      => 'DESC',
        'stock_status' => 'instock',
        'return'     => 'ids',
    ));
    foreach ($fill as $id) {
        if (count($ids) >= 6) break;
        $ids[] = (int) $id;
    }
    return $ids;
}, 20, 2);

/**
 * Cards injected by AJAX (Recently Viewed, guest wishlist) load their images
 * straight away instead of waiting for lazy-load, which can leave an
 * off-screen card blank until the shopper scrolls.
 */
function bsg_eager_card_images($attr) {
    $attr['loading'] = 'eager';
    return $attr;
}
add_action('wp_ajax_bsg_recently_viewed_render', function () {
    add_filter('wp_get_attachment_image_attributes', 'bsg_eager_card_images', 20);
}, 1);
add_action('wp_ajax_nopriv_bsg_recently_viewed_render', function () {
    add_filter('wp_get_attachment_image_attributes', 'bsg_eager_card_images', 20);
}, 1);


/**
 * ---------------------------------------------------------------------------
 * 14. P2 — card price label and shop result count
 * ---------------------------------------------------------------------------
 */
function bsg_discount_percent($product) {
    if (!$product || !$product->is_on_sale()) return 0;
    if ($product->is_type('variable')) {
        $reg  = (float) $product->get_variation_regular_price('min');
        $sale = (float) $product->get_variation_sale_price('min');
    } else {
        $reg  = (float) $product->get_regular_price();
        $sale = (float) $product->get_sale_price();
    }
    if ($reg > 0 && $sale > 0 && $sale < $reg) {
        return (int) round((($reg - $sale) / $reg) * 100);
    }
    return 0;
}

// "20% OFF" in the accent colour right after the price on every product card.
add_action('woocommerce_after_shop_loop_item_title', function () {
    global $product;
    $pct = bsg_discount_percent($product);
    if ($pct > 0) {
        echo '<span class="bsg-card-off">' . esc_html($pct) . '% OFF</span>';
    }
}, 11);

// Small result count under the page title (mobile; desktop keeps WooCommerce's own).
add_action('woocommerce_archive_description', function () {
    if (!is_shop() && !is_product_category() && !is_product_tag()) return;
    global $wp_query;
    $n = (int) $wp_query->found_posts;
    echo '<p class="bsg-result-count">' . esc_html($n) . ' ' . esc_html(_n('product', 'products', $n, 'woocommerce')) . '</p>';
}, 12);


/**
 * ---------------------------------------------------------------------------
 * 15. Login page — 3D product showcase
 * A decorative rotating ring of product photos beside the login form.
 * Pure CSS 3D plus a small pointer-tilt script (site.js); no libraries.
 * ---------------------------------------------------------------------------
 */
add_action('woocommerce_before_customer_login_form', function () {
    $base = get_stylesheet_directory_uri() . '/assets/img/auth/';
    $photos = array('kurta.webp', 'denim.webp', 'luxury.webp', 'shirt.webp', 'belt.webp', 'casual.webp');
    ?>
    <div class="bsg-auth-layout">
    <div class="bsg-auth-stage" aria-hidden="true">
        <div class="bsg-auth-tilt">
            <div class="bsg-auth-floor"></div>
            <div class="bsg-auth-ring">
                <?php foreach ($photos as $i => $file) : ?>
                    <figure class="bsg-auth-panel" style="--i: <?php echo (int) $i; ?>;">
                        <img src="<?php echo esc_url($base . $file); ?>" alt="" width="480" height="600" loading="eager" decoding="async">
                    </figure>
                <?php endforeach; ?>
            </div>
            <span class="bsg-auth-orb bsg-auth-orb--a"></span>
            <span class="bsg-auth-orb bsg-auth-orb--b"></span>
            <span class="bsg-auth-orb bsg-auth-orb--c"></span>
        </div>
        <p class="bsg-auth-tag">Sign in to track orders, keep your wishlist and check out faster.</p>
    </div>
    <div class="bsg-auth-form">
    <?php
});

add_action('woocommerce_after_customer_login_form', function () {
    echo '</div></div>'; // close .bsg-auth-form and .bsg-auth-layout
});

// Accounts, sign-in tabs, My Account overview and checkout rules.
require_once get_stylesheet_directory() . '/inc/account.php';
require_once get_stylesheet_directory() . '/inc/addresses.php';
require_once get_stylesheet_directory() . '/inc/store-info.php';
require_once get_stylesheet_directory() . '/inc/colour-swatches.php';


/**
 * ---------------------------------------------------------------------------
 * 16. Newsletter sign-ups — stored as private "Subscribers" in the dashboard
 * (WordPress admin > Subscribers). No mailing plugin is required to collect
 * addresses; export or copy them into any email tool later.
 * ---------------------------------------------------------------------------
 */
add_action('init', function () {
    register_post_type('bsg_subscriber', array(
        'labels'       => array('name' => 'Subscribers', 'singular_name' => 'Subscriber', 'menu_name' => 'Subscribers'),
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => true,
        'menu_icon'    => 'dashicons-email-alt',
        'supports'     => array('title'),
        'capability_type' => 'post',
        'capabilities' => array('create_posts' => 'do_not_allow'),
        'map_meta_cap' => true,
    ));
});

function bsg_ajax_newsletter() {
    check_ajax_referer('bsg_newsletter', 'nonce');

    // Honeypot: real people never fill this hidden field.
    if (!empty($_POST['website'])) {
        wp_send_json_success(array('message' => 'Thanks for subscribing!'));
    }

    $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
    if (!is_email($email)) {
        wp_send_json_error(array('message' => 'Please enter a valid email address.'));
    }

    $key = 'bsg_nl_' . md5(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
    $tries = (int) get_transient($key);
    if ($tries >= 5) {
        wp_send_json_error(array('message' => 'Too many attempts. Please try again in an hour.'));
    }
    set_transient($key, $tries + 1, HOUR_IN_SECONDS);

    $existing = get_posts(array(
        'post_type'   => 'bsg_subscriber',
        'post_status' => 'any',
        'title'       => $email,
        'numberposts' => 1,
        'fields'      => 'ids',
    ));
    if (!$existing) {
        wp_insert_post(array('post_type' => 'bsg_subscriber', 'post_title' => $email, 'post_status' => 'publish'));
    }

    wp_send_json_success(array('message' => "You're in! Use code BSGFIRST for 10% off your first order."));
}
add_action('wp_ajax_bsg_newsletter', 'bsg_ajax_newsletter');
add_action('wp_ajax_nopriv_bsg_newsletter', 'bsg_ajax_newsletter');

/**
 * ---------------------------------------------------------------------------
 * 17. Page titles, descriptions and social-share tags
 * Only used when no SEO plugin is active (Yoast is switched off for launch).
 * ---------------------------------------------------------------------------
 */
add_filter('pre_get_document_title', function ($title) {
    if (defined('WPSEO_VERSION')) return $title;
    if (is_front_page()) {
        return 'BSG Garments — Modern Indian Menswear | Kurtas, Shirts, Jeans & More';
    }
    return $title;
});

add_action('wp_head', function () {
    if (defined('WPSEO_VERSION') || is_admin()) return;

    $site  = get_bloginfo('name');
    $title = wp_get_document_title();
    $img   = get_stylesheet_directory_uri() . '/assets/img/home/hero-kurta.jpg';
    $type  = 'website';
    $desc  = 'Shop modern Indian menswear: kurtas, shirts, jeans and trousers. Free shipping over ₹999 and easy ' . (int) bsg_store()['return_days'] . '-day exchanges.';

    if (function_exists('is_product') && is_product()) {
        $p = wc_get_product(get_the_ID());
        if ($p) {
            $text = wp_strip_all_tags($p->get_short_description() ? $p->get_short_description() : $p->get_description());
            $desc = trim($p->get_name() . ' at ₹' . wc_format_decimal($p->get_price(), 0) . '. ' . $text);
            $desc = mb_substr($desc, 0, 155);
            $big  = wp_get_attachment_image_url($p->get_image_id(), 'large');
            if ($big) $img = $big;
            $type = 'product';
        }
    } elseif (function_exists('is_product_category') && is_product_category()) {
        $term = get_queried_object();
        if ($term) {
            $d = wp_strip_all_tags(term_description($term->term_id, 'product_cat'));
            $desc = $d ? mb_substr($d, 0, 155) : 'Shop ' . $term->name . ' online at ' . $site . '. Free shipping over ₹999 and easy ' . (int) bsg_store()['return_days'] . '-day exchanges.';
        }
    }

    $url = is_singular() ? get_permalink() : home_url(add_query_arg(array(), $GLOBALS['wp']->request ? '/' . $GLOBALS['wp']->request . '/' : '/'));

    echo '<meta name="description" content="' . esc_attr($desc) . '">' . "\n";
    echo '<meta name="theme-color" content="#111111">' . "\n";
    echo '<meta property="og:type" content="' . esc_attr($type) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr($site) . '">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr($desc) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
    echo '<meta property="og:image" content="' . esc_url($img) . '">' . "\n";
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
}, 5);


/**
 * ---------------------------------------------------------------------------
 * 18. Live search (mobile overlay + desktop dropdown), empty states, 404
 * ---------------------------------------------------------------------------
 */
function bsg_suggest_chips() {
    $cats = array(
        'kurtas'         => 'Kurtas',
        'shirts'         => 'Shirts',
        'jeans-trousers' => 'Jeans & Trousers',
        'accessories'    => 'Accessories',
    );
    echo '<div class="bsg-chips">';
    foreach ($cats as $slug => $label) {
        $link = get_term_link($slug, 'product_cat');
        if (is_wp_error($link)) continue;
        echo '<a class="bsg-chip" href="' . esc_url($link) . '">' . esc_html($label) . '</a>';
    }
    echo '</div>';
}

// Search button in the header (phones only — desktop has the search box).
// No is_cart() check here: the cart drawer renders the cart template earlier in the
// footer, which makes is_cart() report true for the rest of the page.
add_action('wp_footer', function () {
    ?>
    <div class="bsg-search-overlay" id="bsg-search-overlay" role="dialog" aria-modal="true" aria-label="Search products" hidden>
        <div class="bsg-search-panel">
            <form class="bsg-search-form" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
                <input type="hidden" name="post_type" value="product">
                <label class="screen-reader-text" for="bsg-search-input">Search products</label>
                <input type="search" id="bsg-search-input" name="s" placeholder="Search kurtas, shirts, jeans…" autocomplete="off" enterkeyhint="search">
                <button type="button" class="bsg-search-close" id="bsg-search-close" aria-label="Close search">&times;</button>
            </form>
            <div class="bsg-search-body" id="bsg-search-body" aria-live="polite">
                <p class="bsg-search-hint">Popular right now</p>
                <?php bsg_suggest_chips(); ?>
            </div>
        </div>
    </div>
    <?php
});

// Friendly empty states.
add_action('woocommerce_cart_is_empty', function () {
    echo '<div class="bsg-empty"><p class="bsg-empty__text">Start with something you will love:</p>';
    bsg_suggest_chips();
    echo '</div>';
}, 20);

remove_action('woocommerce_no_products_found', 'wc_no_products_found');
add_action('woocommerce_no_products_found', function () {
    $q = get_search_query();
    echo '<div class="bsg-empty">';
    echo '<h2 class="bsg-empty__title">' . ($q ? 'No matches for “' . esc_html($q) . '”' : 'Nothing here yet') . '</h2>';
    echo '<p class="bsg-empty__text">Check the spelling or try a broader word. Popular right now:</p>';
    bsg_suggest_chips();
    echo '<a class="button" href="' . esc_url(get_permalink(wc_get_page_id('shop'))) . '">Browse all products</a>';
    echo '</div>';
});


// Start downloading the first hero photo early; it is the largest thing on the homepage.
add_action('wp_head', function () {
    if (!is_front_page()) return;
    echo '<link rel="preload" as="image" type="image/webp" href="' . esc_url(get_stylesheet_directory_uri() . '/assets/img/home/hero-kurta.webp') . '" fetchpriority="high">' . "\n";
}, 3);
