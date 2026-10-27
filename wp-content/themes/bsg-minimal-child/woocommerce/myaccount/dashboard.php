<?php
/**
 * My Account overview.
 *
 * Replaces the default "Hello, ... from your dashboard you can..." paragraph.
 * Extensions still hook into woocommerce_account_dashboard below.
 */
defined('ABSPATH') || exit;

$uid     = get_current_user_id();
$user    = wp_get_current_user();
$first   = $user->first_name ? $user->first_name : (get_user_meta($uid, 'billing_first_name', true) ?: $user->display_name);
$gaps    = bsg_profile_gaps($uid);
$pct     = (int) round($gaps['done'] / $gaps['total'] * 100);
$orders  = wc_get_orders(array('customer' => $uid, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC'));
$last    = $orders ? $orders[0] : null;
$count   = (int) wc_get_customer_order_count($uid);
$since   = date_i18n('M Y', strtotime($user->user_registered));
$orders_url = wc_get_endpoint_url('orders');
$addr_url   = wc_get_account_endpoint_url('address-book');
$acct_url   = wc_get_endpoint_url('edit-account');
?>
<section class="bsg-dash">
    <header class="bsg-dash-hero">
        <span class="bsg-dash-avatar" aria-hidden="true"><?php echo esc_html(strtoupper(mb_substr($first, 0, 1))); ?></span>
        <div>
            <p class="bsg-dash-eyebrow">Welcome back</p>
            <h2 class="bsg-dash-name"><?php echo esc_html($first); ?></h2>
            <p class="bsg-dash-meta"><?php echo esc_html($user->user_email); ?> &middot; Member since <?php echo esc_html($since); ?></p>
        </div>
    </header>

    <?php if ($gaps['missing']) : ?>
        <div class="bsg-dash-card bsg-dash-complete">
            <div class="bsg-dash-complete-top">
                <strong>Complete your profile</strong>
                <span><?php echo (int) $pct; ?>%</span>
            </div>
            <div class="bsg-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $pct; ?>"><i style="width: <?php echo (int) $pct; ?>%"></i></div>
            <p>Add <?php echo esc_html(implode(', ', $gaps['missing'])); ?> so your first order ships without delay.</p>
            <a class="bsg-btn-solid" href="<?php echo esc_url($addr_url); ?>">Add delivery details</a>
        </div>
    <?php endif; ?>

    <div class="bsg-dash-grid">
        <div class="bsg-dash-card bsg-dash-order">
            <h3>Latest order</h3>
            <?php if ($last) : ?>
                <p class="bsg-dash-order-top">
                    <strong>#<?php echo esc_html($last->get_order_number()); ?></strong>
                    <span class="bsg-status bsg-status--<?php echo esc_attr($last->get_status()); ?>"><?php echo esc_html(wc_get_order_status_name($last->get_status())); ?></span>
                </p>
                <p class="bsg-dash-sub"><?php echo esc_html(wc_format_datetime($last->get_date_created())); ?> &middot; <?php echo wp_kses_post($last->get_formatted_order_total()); ?></p>
                <a class="bsg-link" href="<?php echo esc_url($last->get_view_order_url()); ?>">View order &rarr;</a>
            <?php else : ?>
                <p class="bsg-dash-sub">No orders yet. Your first one is a few taps away.</p>
                <a class="bsg-link" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>">Start shopping &rarr;</a>
            <?php endif; ?>
        </div>

        <div class="bsg-dash-card bsg-dash-addr">
            <h3>Delivery address</h3>
            <?php
            $addr = wc_get_account_formatted_address('billing');
            $phone = get_user_meta($uid, 'billing_phone', true);
            if ($addr) : ?>
                <address><?php echo wp_kses_post($addr); ?></address>
                <?php if ($phone) : ?><p class="bsg-dash-sub">Mobile: <?php echo esc_html($phone); ?></p><?php endif; ?>
                <a class="bsg-link" href="<?php echo esc_url($addr_url); ?>">Edit &rarr;</a>
            <?php else : ?>
                <p class="bsg-dash-sub">Not added yet.</p>
                <a class="bsg-link" href="<?php echo esc_url($addr_url); ?>">Add address &rarr;</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="bsg-dash-tiles">
        <a href="<?php echo esc_url($orders_url); ?>"><strong><?php echo (int) $count; ?></strong><span><?php echo $count === 1 ? 'order' : 'orders'; ?></span></a>
        <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><strong>Shop</strong><span>New arrivals</span></a>
        <a href="<?php echo esc_url($acct_url); ?>"><strong>Profile</strong><span>Name &amp; password</span></a>
    </div>
</section>

<?php
do_action('woocommerce_account_dashboard');
do_action('woocommerce_before_my_account');
do_action('woocommerce_after_my_account');
