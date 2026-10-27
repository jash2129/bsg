<?php
if (!defined('ABSPATH')) {
    exit;
}

global $product;
?>
<div class="product_meta bsg-minimal-meta">
    <?php do_action('woocommerce_product_meta_start'); ?>

    <?php if (wc_product_sku_enabled() && ($product->get_sku() || $product->is_type('variable'))) : ?>
        <span class="sku_wrapper" style="font-size: 12px; color: #888888;">
            SKU: <span class="sku"><?php echo ($sku = $product->get_sku()) ? $sku : esc_html__('N/A', 'woocommerce'); ?></span>
        </span>
    <?php endif; ?>

    <?php do_action('woocommerce_product_meta_end'); ?>
</div>
