<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ---------------------------------------------------------------------------
 * Colour / pattern picker
 * One product, many colours or patterns, chosen like sizes.
 *  - A "Colour" product attribute (created automatically, plain dropdown type).
 *  - Each colour gets its own photos, set once in the "Photos by colour" box on the
 *    product screen. The first photo doubles as the small swatch picture.
 *  - On the product page the dropdown is replaced by picture swatches. Choosing one
 *    swaps the whole gallery to that colour's photos. Without JavaScript the
 *    ordinary dropdown still works.
 * ---------------------------------------------------------------------------
 */

const BSG_COLOUR_META = '_bsg_colour_photos';

/** Creates the Colour attribute once, so it exists on every environment. */
add_action('init', function () {
    if (!function_exists('wc_create_attribute') || get_option('bsg_colour_attr') === '1') {
        return;
    }
    if (!wc_attribute_taxonomy_id_by_name('colour')) {
        wc_create_attribute(array(
            'name'         => 'Colour',
            'slug'         => 'colour',
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ));
    }
    update_option('bsg_colour_attr', '1', false);
}, 20);

function bsg_colour_photo_ids($product_id) {
    $map = get_post_meta($product_id, BSG_COLOUR_META, true);
    return is_array($map) ? $map : array();
}

/* ---------------------------------------------------------------------
   Admin: "Photos by colour" box on the product screen
   --------------------------------------------------------------------- */
add_action('add_meta_boxes', function () {
    add_meta_box('bsg-colour-photos', 'Photos by colour', 'bsg_colour_photos_box', 'product', 'normal', 'default');
});

function bsg_colour_photos_box($post) {
    $terms = taxonomy_exists('pa_colour') ? wp_get_post_terms($post->ID, 'pa_colour') : array();
    $map   = bsg_colour_photo_ids($post->ID);
    wp_nonce_field('bsg_colour_photos', 'bsg_colour_photos_nonce');
    if (!$terms || is_wp_error($terms)) {
        echo '<p>First add the <strong>Colour</strong> attribute in <em>Product data &rarr; Attributes</em> (tick "Used for variations"), choose your colours, save, and they appear here.</p>';
        return;
    }
    echo '<p class="description">Add the photos for each colour. The first photo is also the small swatch picture. The product\'s normal gallery shows until a customer picks a colour.</p>';
    echo '<div class="bsg-cp">';
    foreach ($terms as $t) {
        $ids = isset($map[$t->slug]) ? array_map('absint', (array) $map[$t->slug]) : array();
        echo '<div class="bsg-cp-row" data-slug="' . esc_attr($t->slug) . '">';
        echo '<strong class="bsg-cp-name">' . esc_html($t->name) . '</strong>';
        echo '<input type="hidden" class="bsg-cp-input" name="bsg_colour_photos[' . esc_attr($t->slug) . ']" value="' . esc_attr(implode(',', $ids)) . '">';
        echo '<ul class="bsg-cp-list">';
        foreach ($ids as $id) {
            echo '<li data-id="' . (int) $id . '">' . wp_get_attachment_image($id, array(70, 90)) . '<button type="button" class="bsg-cp-remove" aria-label="Remove photo">&times;</button></li>';
        }
        echo '</ul>';
        echo '<button type="button" class="button bsg-cp-add">Add photos</button>';
        echo '</div>';
    }
    echo '</div>';
}

add_action('save_post_product', function ($post_id) {
    if (!isset($_POST['bsg_colour_photos_nonce']) || !wp_verify_nonce(wp_unslash($_POST['bsg_colour_photos_nonce']), 'bsg_colour_photos')) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    $in  = isset($_POST['bsg_colour_photos']) ? (array) wp_unslash($_POST['bsg_colour_photos']) : array();
    $map = array();
    foreach ($in as $slug => $csv) {
        $slug = sanitize_title($slug);
        $ids  = array_values(array_filter(array_map('absint', explode(',', (string) $csv))));
        if ($slug !== '' && $ids) {
            $map[$slug] = $ids;
        }
    }
    update_post_meta($post_id, BSG_COLOUR_META, $map);
});

add_action('admin_enqueue_scripts', function ($hook) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type !== 'product' || !in_array($hook, array('post.php', 'post-new.php'), true)) {
        return;
    }
    wp_enqueue_media();
    $dir = get_stylesheet_directory();
    wp_enqueue_script('bsg-colour-admin', get_stylesheet_directory_uri() . '/assets/js/colour-admin.js', array('jquery'), filemtime($dir . '/assets/js/colour-admin.js'), true);
    wp_add_inline_style('wp-admin', '.bsg-cp-row{padding:12px 0;border-top:1px solid #eee}.bsg-cp-list{display:flex;flex-wrap:wrap;gap:8px;list-style:none;margin:8px 0;padding:0}.bsg-cp-list li{position:relative}.bsg-cp-list img{display:block;border-radius:4px}.bsg-cp-remove{position:absolute;top:-6px;right:-6px;width:20px;height:20px;border:0;border-radius:50%;background:#b32d2e;color:#fff;cursor:pointer;line-height:1}');
});

/* ---------------------------------------------------------------------
   Product page: data for the swatches and gallery swap
   --------------------------------------------------------------------- */
function bsg_colour_item($id, $alt) {
    $single = wp_get_attachment_image_src($id, 'woocommerce_single');
    $full   = wp_get_attachment_image_src($id, 'full');
    if (!$single) {
        return null;
    }
    $thumb = wp_get_attachment_image_src($id, 'woocommerce_gallery_thumbnail');
    return array(
        'src'    => $single[0],
        'w'      => (int) $single[1],
        'h'      => (int) $single[2],
        'srcset' => (string) wp_get_attachment_image_srcset($id, 'woocommerce_single'),
        'sizes'  => (string) wp_get_attachment_image_sizes($id, 'woocommerce_single'),
        'full'   => $full ? $full[0] : $single[0],
        'fw'     => $full ? (int) $full[1] : (int) $single[1],
        'fh'     => $full ? (int) $full[2] : (int) $single[2],
        'thumb'  => $thumb ? $thumb[0] : $single[0],
        'alt'    => trim((string) get_post_meta($id, '_wp_attachment_image_alt', true)) ?: $alt,
    );
}

add_action('wp_enqueue_scripts', function () {
    if (!function_exists('is_product') || !is_product() || !taxonomy_exists('pa_colour')) {
        return;
    }
    $product = wc_get_product(get_the_ID());
    if (!$product || !$product->is_type('variable')) {
        return;
    }
    $terms = wc_get_product_terms($product->get_id(), 'pa_colour', array('fields' => 'all'));
    $map   = bsg_colour_photo_ids($product->get_id());
    if (!$terms) {
        return;
    }
    $colours = array();
    foreach ($terms as $t) {
        $items = array();
        foreach ((array) ($map[$t->slug] ?? array()) as $id) {
            if ($it = bsg_colour_item((int) $id, $product->get_name() . ' in ' . $t->name)) {
                $items[] = $it;
            }
        }
        $colours[$t->slug] = array('name' => $t->name, 'photos' => $items);
    }
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    wp_enqueue_style('bsg-colours', $uri . '/assets/css/colours.css', array('bsg-shop-custom'), filemtime($dir . '/assets/css/colours.css'));
    wp_enqueue_script('bsg-colours', $uri . '/assets/js/colours.js', array('jquery'), filemtime($dir . '/assets/js/colours.js'), true);
    wp_localize_script('bsg-colours', 'bsgColours', array('attr' => 'attribute_pa_colour', 'items' => $colours));
}, 30);

/* ---------------------------------------------------------------------
   Variation photos follow the colour photos, so the bag, checkout, orders
   and emails show the colour the customer chose.
   --------------------------------------------------------------------- */
function bsg_sync_variation_images($product_id) {
    $product = wc_get_product($product_id);
    if (!$product || !$product->is_type('variable')) {
        return;
    }
    $map = bsg_colour_photo_ids($product_id);
    if (!$map) {
        return;
    }
    foreach ($product->get_children() as $vid) {
        $v = wc_get_product($vid);
        if (!$v) continue;
        $slug = $v->get_attributes()['pa_colour'] ?? '';
        $first = $slug && !empty($map[$slug]) ? (int) $map[$slug][0] : 0;
        if ($first && (int) $v->get_image_id() !== $first) {
            $v->set_image_id($first);
            $v->save();
        }
    }
    wc_delete_product_transients($product_id);
}
add_action('save_post_product', function ($post_id) {
    bsg_sync_variation_images($post_id);
}, 30);
add_action('woocommerce_ajax_save_product_variations', 'bsg_sync_variation_images');
