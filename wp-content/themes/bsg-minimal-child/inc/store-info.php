<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ---------------------------------------------------------------------------
 * Store details and legal pages
 * One place for the shop's address, phone, email, hours and return window.
 * The footer, Contact page, policy pages, contact form and Google search data
 * all read from here. Change it once and every page follows.
 *
 * The policy pages stay editable in wp-admin: the text below is shown only
 * while a page still contains the old placeholder note. Edit and save a page
 * in WordPress and your own text is used instead.
 * ---------------------------------------------------------------------------
 */
function bsg_store() {
    $s = array(
        'name'        => 'BSG Garments',
        'tagline'     => "Men's clothing: shirts, jeans and more at budget-friendly prices.",
        'street'      => 'Metro Pillar No. 1569, Before Sri Chaitanya College, Orchid Hospital Lane, 1-79, Chaitanyapuri Main Rd, Prabhat Nagar, Chaitanyapuri, Dilsukhnagar',
        'short_addr'  => 'Chaitanyapuri Main Rd, Dilsukhnagar',
        'city'        => 'Hyderabad',
        'state'       => 'Telangana',
        'pincode'     => '500060',
        'email'       => 'bsggarments.online@gmail.com',
        'phone'       => '090637 01248',
        'phone_e164'  => '+919063701248',
        'hours'       => 'Opens at 10 am',
        'return_days' => 12,
    );
    return apply_filters('bsg_store_info', $s);
}

function bsg_store_address($full = true) {
    $s = bsg_store();
    return ($full ? $s['street'] . ', ' : '') . $s['city'] . ', ' . $s['state'] . ' ' . $s['pincode'];
}

function bsg_store_map_url() {
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(bsg_store()['name'] . ', ' . bsg_store_address());
}

/** The contact form mails the shop, not the WordPress admin address. */
add_filter('bsg_contact_recipient', function () {
    return bsg_store()['email'];
});

/* ---------------------------------------------------------------------
   Contact page: swap the placeholder details for the real ones.
   --------------------------------------------------------------------- */
add_filter('the_content', function ($content) {
    if (!is_page('contact-us') || !in_the_loop() || !is_main_query()) {
        return $content;
    }
    if (strpos($content, 'support@bsggarments.example') === false && strpos($content, '[PHONE NUMBER]') === false) {
        return $content;
    }
    $s = bsg_store();
    $details  = '<div class="bsg-contact-card">';
    $details .= '<p><strong>Visit us</strong>' . esc_html($s['name']) . '<br>' . esc_html(bsg_store_address()) . '<br><a href="' . esc_url(bsg_store_map_url()) . '" target="_blank" rel="noopener">Open in Google Maps</a></p>';
    $details .= '<p><strong>Call</strong><a href="tel:' . esc_attr($s['phone_e164']) . '">' . esc_html($s['phone']) . '</a></p>';
    $details .= '<p><strong>Email</strong><a href="mailto:' . esc_attr($s['email']) . '">' . esc_html($s['email']) . '</a></p>';
    $details .= '<p><strong>Hours</strong>' . esc_html($s['hours']) . '</p>';
    $details .= '</div>';
    // Replace the whole placeholder paragraph with the real details.
    return preg_replace('#<p[^>]*><strong>Email:</strong>.*?</p>#s', $details, $content, 1);
}, 9);

/* ---------------------------------------------------------------------
   Policy pages
   --------------------------------------------------------------------- */
function bsg_legal_date() {
    $d = get_option('bsg_legal_date');
    if (!$d) {
        $d = wp_date('j F Y');
        update_option('bsg_legal_date', $d, false);
    }
    return $d;
}

function bsg_legal_html($slug) {
    $s    = bsg_store();
    $date = esc_html(bsg_legal_date());
    $days = (int) $s['return_days'];
    $mail = '<a href="mailto:' . esc_attr($s['email']) . '">' . esc_html($s['email']) . '</a>';
    $tel  = '<a href="tel:' . esc_attr($s['phone_e164']) . '">' . esc_html($s['phone']) . '</a>';
    $contact = '<p>' . esc_html($s['name']) . ', ' . esc_html(bsg_store_address()) . '.<br>Email: ' . $mail . '<br>Phone: ' . $tel . '</p>';
    $top = '<p><em>Last updated: ' . $date . '</em></p>';

    if ($slug === 'refund_returns') {
        return $top . "
<h2>Delivery</h2>
<p>We ship across India with India Post (Speed Post and Business Parcel). Orders are usually packed within 1 to 2 working days and delivered in about 3 to 7 working days, depending on your pincode. Once your parcel is booked you get an email with a tracking number, and you can follow it any time on our <a href=\"" . esc_url(home_url('/track-order/')) . "\">Track your order</a> page.</p>
<p>Shipping is free on orders above ₹999. For smaller orders any delivery charge is shown at checkout before you pay.</p>

<h2>Return and exchange window</h2>
<p>You can return or exchange an item within <strong>{$days} days of delivery</strong>. The item must be unworn, unwashed and have its original tags and packaging.</p>

<h2>What cannot be returned</h2>
<p>Innerwear, socks and other hygiene items cannot be returned once opened. Items damaged after delivery, washed, altered or worn are not accepted.</p>

<h2>How to return</h2>
<ol>
<li>Email {$mail} or call {$tel} within {$days} days of delivery with your order number and the reason.</li>
<li>We confirm the return and share the address to send the parcel to.</li>
<li>Pack the item with its tags and send it back. Keep your postal receipt.</li>
</ol>
<p>If the item is wrong, damaged or has a defect, we pay the return shipping. For a size or fit exchange, or a change of mind, return shipping is paid by the customer.</p>

<h2>Refunds</h2>
<p>After we receive and check the item we refund you within 7 working days. Online payments go back to the original payment method. For Cash on Delivery orders we refund by UPI or bank transfer, so please share your UPI ID or bank details when you send the return.</p>

<h2>Cancelling an order</h2>
<p>You can cancel before the parcel is handed to India Post by contacting us. After that, the return process above applies.</p>

<h2>Contact</h2>
" . $contact;
    }

    if ($slug === 'privacy-policy') {
        return $top . "
<p>This policy explains what personal information {$s['name']} collects when you use bsggarments.com, and how we use it.</p>

<h2>1. Information we collect</h2>
<ul>
<li>Your name, mobile number, email address and delivery address when you create an account or place an order.</li>
<li>Order details and payment status. Online payments are handled by our payment partner. We do not see or store your card, UPI PIN or bank login details.</li>
<li>Technical data such as your browser, device and the pages you visit, collected through cookies.</li>
<li>Your email address if you subscribe to our newsletter or send us a message.</li>
</ul>

<h2>2. How we use it</h2>
<ul>
<li>To process, deliver and support your orders, including calling or messaging you on your mobile number to confirm Cash on Delivery orders.</li>
<li>To send order and tracking updates.</li>
<li>To answer your messages and handle returns and refunds.</li>
<li>To send offers and new arrivals, only if you subscribed. You can unsubscribe any time.</li>
<li>To keep the site secure and understand how it is used so we can improve it.</li>
</ul>

<h2>3. Who we share it with</h2>
<p>We share only what is needed to fulfil your order: India Post (name, address, mobile number and parcel details), our payment partner, and the hosting and email services that run this website. We do not sell your personal information.</p>

<h2>4. Cookies</h2>
<p>We use cookies to keep your bag and login working, remember your choices such as light or dark mode, and measure site use. You can block cookies in your browser, but parts of the site may stop working.</p>

<h2>5. How long we keep it</h2>
<p>We keep order records for as long as the law requires for accounting and tax. You can ask us to delete your account at any time.</p>

<h2>6. Security</h2>
<p>We use reasonable safeguards to protect your information. No website can promise complete security, so please use a strong password and keep it private.</p>

<h2>7. Your choices</h2>
<p>You can ask us to show, correct or delete the personal information we hold about you, or to stop marketing messages, by contacting us below.</p>

<h2>8. Changes</h2>
<p>We may update this policy. The date at the top shows the latest version.</p>

<h2>Contact</h2>
" . $contact;
    }

    if ($slug === 'terms-and-conditions') {
        return $top . "
<p>By using bsggarments.com or placing an order, you agree to these terms.</p>

<h2>1. About us</h2>
<p>{$s['name']} is a men's clothing shop in Hyderabad selling shirts, jeans and other clothing at budget-friendly prices, online and at our store.</p>

<h2>2. Using the site</h2>
<p>Use the site only for lawful purposes. Give correct details when you create an account or order, and keep your password private. You are responsible for activity on your account.</p>

<h2>3. Products and prices</h2>
<p>We try to show colours, sizes and prices accurately. Colours may look slightly different on your screen. Prices are in Indian Rupees (₹) and include applicable taxes. We may correct a pricing or stock error and cancel an affected order, and we will refund any amount you have paid.</p>

<h2>4. Orders and payment</h2>
<p>An order is accepted when we confirm it. You can pay by Cash on Delivery or by the online methods shown at checkout. We may cancel an order we cannot fulfil or that looks fraudulent. For Cash on Delivery we may call you to confirm before dispatch, and orders we cannot confirm may be cancelled.</p>

<h2>5. Delivery</h2>
<p>We deliver across India through India Post. Delivery times are estimates and can be affected by the courier. Please see our <a href=\"" . esc_url(home_url('/refund_returns/')) . "\">Shipping &amp; Returns</a> page for details.</p>

<h2>6. Returns and refunds</h2>
<p>Returns, exchanges and refunds follow our <a href=\"" . esc_url(home_url('/refund_returns/')) . "\">Shipping &amp; Returns</a> policy, including the {$days}-day return window.</p>

<h2>7. Our content</h2>
<p>The logo, photographs and text on this site belong to {$s['name']} and may not be copied or used without our written permission.</p>

<h2>8. Limit of liability</h2>
<p>To the extent the law allows, we are not liable for indirect or consequential loss arising from use of the site or products. Our liability for an order is limited to the amount you paid for it. Nothing here limits your rights under Indian consumer law.</p>

<h2>9. Governing law</h2>
<p>These terms are governed by the laws of India. Courts at Hyderabad, Telangana have jurisdiction, subject to your rights under consumer protection law.</p>

<h2>10. Changes</h2>
<p>We may update these terms. Using the site after a change means you accept the new version.</p>

<h2>Contact</h2>
" . $contact;
    }
    return '';
}

add_filter('the_content', function ($content) {
    if (!is_page() || !in_the_loop() || !is_main_query()) {
        return $content;
    }
    $slug = get_post_field('post_name', get_the_ID());
    if (!in_array($slug, array('refund_returns', 'privacy-policy', 'terms-and-conditions'), true)) {
        return $content;
    }
    // Only while the old placeholder note is still on the page.
    if (strpos($content, 'generated during development') === false && strpos($content, '[DATE]') === false) {
        return $content;
    }
    return bsg_legal_html($slug);
}, 9);

/* ---------------------------------------------------------------------
   Footer: contact block, and a link to order tracking.
   --------------------------------------------------------------------- */
function bsg_footer_contact_col() {
    $s = bsg_store();
    ?>
    <div class="bsg-footer-col bsg-footer-visit">
        <h5>Visit &amp; contact</h5>
        <address>
            <?php echo esc_html($s['short_addr']); ?>,<br>
            <?php echo esc_html($s['city'] . ', ' . $s['state'] . ' ' . $s['pincode']); ?><br>
            <a href="<?php echo esc_url(bsg_store_map_url()); ?>" target="_blank" rel="noopener">Get directions</a>
        </address>
        <ul>
            <li><a href="tel:<?php echo esc_attr($s['phone_e164']); ?>"><?php echo esc_html($s['phone']); ?></a></li>
            <li><a href="mailto:<?php echo esc_attr($s['email']); ?>"><?php echo esc_html($s['email']); ?></a></li>
            <li class="bsg-footer-hours"><?php echo esc_html($s['hours']); ?></li>
        </ul>
    </div>
    <?php
}

/* ---------------------------------------------------------------------
   Google search data (name, address, phone) on the homepage.
   --------------------------------------------------------------------- */
add_action('wp_head', function () {
    if (!is_front_page()) {
        return;
    }
    $s = bsg_store();
    $data = array(
        '@context'  => 'https://schema.org',
        '@type'     => 'ClothingStore',
        'name'      => $s['name'],
        'description' => $s['tagline'],
        'url'       => home_url('/'),
        'telephone' => $s['phone_e164'],
        'email'     => $s['email'],
        'priceRange' => '₹',
        'address'   => array(
            '@type'           => 'PostalAddress',
            'streetAddress'   => $s['street'],
            'addressLocality' => $s['city'],
            'addressRegion'   => $s['state'],
            'postalCode'      => $s['pincode'],
            'addressCountry'  => 'IN',
        ),
    );
    echo '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
}, 6);
