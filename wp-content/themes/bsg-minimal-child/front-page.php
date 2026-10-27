<?php
/**
 * Front page — BSG Garments homepage.
 * Structure mirrors snitch.com: 3-up hero, promo strip, category icon row,
 * mood tiles, steals, urgency banner, tabbed product grid.
 *
 * @package BSG_Minimal
 */

get_header();

$upload_base_url = wp_get_upload_dir()['baseurl'];
// Homepage photos live in the theme (assets/img/home) so they move with it.
// Old media-library file names map to the new matching photos; anything not
// listed still falls back to the uploads folder.
$home_img_url = get_stylesheet_directory_uri() . '/assets/img/home/';
$home_img_map = array(
	'bsg-11-qnHBEa.jpg'     => 'hero-kurta.webp',
	'bsg-11-OjpTYJ.jpg'     => 'kurta-offwhite.webp',
	'bsg-12-replacement.jpg' => 'kurta-grey.webp',
	'bsg-13-qfTRUC.jpg'     => 'shirt.webp',
	'bsg-13-8VLkSu.jpg'     => 'shirt.webp',
	'bsg-15-bxlof5.jpg'     => 'denim.webp',
	'bsg-15-zPvF1j.jpg'     => 'denim.webp',
	'bsg-16-hf0kV5.jpg'     => 'chinos.webp',
	'bsg-16-l5gxiA.jpg'     => 'black-jeans.webp',
	'bsg-17-FgnRhZ.jpg'     => 'black-jeans.webp',
	'bsg-18-VAUH01.jpg'     => 'belt.webp',
	'bsg-18-0y2uoB.jpg'     => 'belt.webp',
	'bsg-14-0h24QZ.jpg'     => 'urgency-banner.webp',
);
$home = function ($file) use ($home_img_url) {
	return $home_img_url . $file;
};
$img = function ($file) use ($upload_base_url, $home_img_url, $home_img_map) {
	if (isset($home_img_map[$file])) {
		return $home_img_url . $home_img_map[$file];
	}
	return $upload_base_url . '/2026/08/' . $file;
};

$cat_link = function ($slug) {
	$link = get_term_link($slug, 'product_cat');
	return is_wp_error($link) ? '#' : $link;
};
?>

<main class="bsg-home">

	<h1 class="screen-reader-text">BSG Garments — Modern Indian Menswear</h1>

	<!-- Hero (desktop): asymmetric multi-tile layout. All tiles visible at once,
	     nothing rotates. Hidden on mobile — see .bsg-hero-mobile below. -->
	<section class="bsg-hero-bento">
		<a href="<?php echo esc_url($cat_link('kurtas')); ?>" class="bsg-hero-bento__tile bsg-hero-bento__tile--main" style="background-image: linear-gradient(180deg, rgba(0,0,0,0.05), rgba(0,0,0,0.6)), url('<?php echo esc_url($img('bsg-11-qnHBEa.jpg')); ?>');">
			<div class="bsg-banner-tile__text">
				<span class="bsg-banner-tile__title bsg-banner-tile__title--xl">ELEVATE<br>YOUR<br>EVERYDAY</span>
				<span class="bsg-banner-tile__sub">SHOP THE KURTA EDIT</span>
			</div>
		</a>
		<div class="bsg-hero-bento__side">
			<a href="<?php echo esc_url($cat_link('jeans-trousers')); ?>" class="bsg-hero-bento__tile bsg-hero-bento__tile--top" style="background-image: linear-gradient(180deg, rgba(0,0,0,0.05), rgba(0,0,0,0.65)), url('<?php echo esc_url($home('denim-hero.webp')); ?>'); background-position: 90% center;">
				<div class="bsg-banner-tile__text">
					<span class="bsg-banner-tile__title-sm">DENIM DROP</span>
					<span class="bsg-banner-tile__sub">NEW THIS WEEK</span>
				</div>
			</a>
			<a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>" class="bsg-hero-bento__tile bsg-hero-bento__tile--bottom" style="background-image: linear-gradient(180deg, rgba(0,0,0,0.05), rgba(0,0,0,0.7)), url('<?php echo esc_url($home('sale-flatlay.webp')); ?>'); background-position: 85% center;">
				<div class="bsg-banner-tile__text">
					<span class="bsg-banner-tile__title-sm">UPTO 30% OFF</span>
					<span class="bsg-banner-tile__sub bsg-banner-tile__sub--accent">SALE LIVE</span>
				</div>
			</a>
		</div>
	</section>

	<!-- Hero (mobile): single full-bleed image per slide, auto-advancing,
	     dot pagination, swipe via native scroll-snap. Hidden on desktop. -->
	<section class="bsg-hero-mobile" aria-label="Featured collections" aria-roledescription="carousel">
		<div class="bsg-hero-mobile__track" id="bsg-hero-mobile-track">
			<a href="<?php echo esc_url($cat_link('kurtas')); ?>" class="bsg-hero-mobile__slide" style="background-image: linear-gradient(180deg, rgba(0,0,0,0.1), rgba(0,0,0,0.55)); background-image: linear-gradient(180deg, rgba(0,0,0,0.1), rgba(0,0,0,0.55)), url('<?php echo esc_url($img('bsg-11-qnHBEa.jpg')); ?>');" aria-roledescription="slide" aria-label="1 of 6">
				<div class="bsg-hero-mobile__text">
					<span class="bsg-hero-mobile__title">Elevate Your Everyday</span>
					<span class="bsg-hero-mobile__sub">Shop the Kurta Edit</span>
				</div>
			</a>
			<a href="<?php echo esc_url($cat_link('jeans-trousers')); ?>" class="bsg-hero-mobile__slide" style="background-image: linear-gradient(180deg, rgba(0,0,0,0.1), rgba(0,0,0,0.55)), url('<?php echo esc_url($home('denim-hero.webp')); ?>'); background-position: 90% center;" aria-roledescription="slide" aria-label="2 of 6">
				<div class="bsg-hero-mobile__text">
					<span class="bsg-hero-mobile__title">Denim Drop</span>
					<span class="bsg-hero-mobile__sub">New This Week</span>
				</div>
			</a>
			<a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>" class="bsg-hero-mobile__slide" style="background-image: linear-gradient(180deg, rgba(0,0,0,0.1), rgba(0,0,0,0.6)), url('<?php echo esc_url($home('sale-flatlay.webp')); ?>'); background-position: 85% center;" aria-roledescription="slide" aria-label="3 of 6">
				<div class="bsg-hero-mobile__text">
					<span class="bsg-hero-mobile__title">Upto 30% Off</span>
					<span class="bsg-hero-mobile__sub bsg-hero-mobile__sub--accent">Sale Live</span>
				</div>
			</a>
			<a href="<?php echo esc_url($cat_link('shirts')); ?>" class="bsg-hero-mobile__slide" style="background-image: linear-gradient(180deg, rgba(0,0,0,0.1), rgba(0,0,0,0.55)), url('<?php echo esc_url($home('shirt-model.webp')); ?>'); background-position: center 20%;" aria-roledescription="slide" aria-label="4 of 6">
				<div class="bsg-hero-mobile__text">
					<span class="bsg-hero-mobile__title">Shirt Edit</span>
					<span class="bsg-hero-mobile__sub">Crisp Looks For Every Day</span>
				</div>
			</a>
			<a href="<?php echo esc_url($cat_link('accessories')); ?>" class="bsg-hero-mobile__slide" style="background-image: linear-gradient(180deg, rgba(0,0,0,0.1), rgba(0,0,0,0.55)), url('<?php echo esc_url($img('bsg-18-VAUH01.jpg')); ?>');" aria-roledescription="slide" aria-label="5 of 6">
				<div class="bsg-hero-mobile__text">
					<span class="bsg-hero-mobile__title">Accessories</span>
					<span class="bsg-hero-mobile__sub">Finish The Fit</span>
				</div>
			</a>
			<a href="<?php echo esc_url($cat_link('jeans-trousers')); ?>" class="bsg-hero-mobile__slide" style="background-image: linear-gradient(180deg, rgba(0,0,0,0.1), rgba(0,0,0,0.55)), url('<?php echo esc_url($img('bsg-16-hf0kV5.jpg')); ?>');" aria-roledescription="slide" aria-label="6 of 6">
				<div class="bsg-hero-mobile__text">
					<span class="bsg-hero-mobile__title">Trouser Edit</span>
					<span class="bsg-hero-mobile__sub">New Silhouettes In</span>
				</div>
			</a>
		</div>
		<div class="bsg-hero-mobile__dots" id="bsg-hero-mobile-dots"></div>
	</section>

	<!-- Promo strip -->
	<section class="bsg-banner-grid bsg-banner-grid--promo">
		<a href="<?php echo esc_url($cat_link('shirts')); ?>" class="bsg-banner-tile bsg-banner-tile--sm" style="background-image: linear-gradient(180deg, rgba(0,0,0,0.05), rgba(0,0,0,0.6)), url('<?php echo esc_url($home('shirt-model.webp')); ?>'); background-position: center 20%;">
			<div class="bsg-banner-tile__text"><span class="bsg-banner-tile__title-sm">SHIRT EDIT</span></div>
		</a>
		<a href="<?php echo esc_url($cat_link('accessories')); ?>" class="bsg-banner-tile bsg-banner-tile--sm" style="background-image: linear-gradient(180deg, rgba(0,0,0,0.05), rgba(0,0,0,0.6)), url('<?php echo esc_url($img('bsg-18-VAUH01.jpg')); ?>');">
			<div class="bsg-banner-tile__text"><span class="bsg-banner-tile__title-sm">ACCESSORIES</span></div>
		</a>
		<a href="<?php echo esc_url($cat_link('jeans-trousers')); ?>" class="bsg-banner-tile bsg-banner-tile--sm" style="background-image: linear-gradient(180deg, rgba(0,0,0,0.05), rgba(0,0,0,0.6)), url('<?php echo esc_url($img('bsg-16-hf0kV5.jpg')); ?>');">
			<div class="bsg-banner-tile__text"><span class="bsg-banner-tile__title-sm">TROUSER EDIT</span></div>
		</a>
	</section>

	<!-- Featured Categories: compact icon row -->
	<section class="bsg-section bsg-cat-row-section">
		<h2 class="bsg-section__title bsg-section__title--left">Featured Categories</h2>
		<div class="bsg-cat-row">
			<?php
			$cats = array(
				array('slug' => 'kurtas', 'label' => 'Kurtas', 'img' => $home('kurtas-marigold.webp')),
				array('slug' => 'shirts', 'label' => 'Shirts', 'img' => $home('shirt-terracotta.webp')),
				array('slug' => 'jeans-trousers', 'label' => 'Jeans', 'img' => $img('bsg-15-zPvF1j.jpg')),
				array('slug' => 'jeans-trousers', 'label' => 'Trousers', 'img' => $img('bsg-16-hf0kV5.jpg')),
				array('slug' => 'accessories', 'label' => 'Accessories', 'img' => $img('bsg-18-0y2uoB.jpg')),
			);
			foreach ($cats as $c) : ?>
				<a href="<?php echo esc_url($cat_link($c['slug'])); ?>" class="bsg-cat-row__item">
					<span class="bsg-cat-row__thumb" style="background-image: url('<?php echo esc_url($c['img']); ?>');"></span>
					<span class="bsg-cat-row__label"><?php echo esc_html($c['label']); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</section>

	<!-- Match the Mood -->
	<section class="bsg-section bsg-mood-section">
		<h2 class="bsg-section__title bsg-section__title--left">Match the Mood</h2>
		<div class="bsg-mood-grid">
			<a href="<?php echo esc_url($cat_link('shirts')); ?>" class="bsg-mood-tile" style="background-image: linear-gradient(180deg, rgba(0,0,0,0.05), rgba(0,0,0,0.75)), url('<?php echo esc_url($home('mood-formal.webp')); ?>');">
				<span>FORMAL</span>
			</a>
			<a href="<?php echo esc_url($cat_link('kurtas')); ?>" class="bsg-mood-tile" style="background-image: linear-gradient(180deg, rgba(0,0,0,0.05), rgba(0,0,0,0.75)), url('<?php echo esc_url($home('mood-casual.webp')); ?>');">
				<span>CASUAL</span>
			</a>
			<a href="<?php echo esc_url($cat_link('accessories')); ?>" class="bsg-mood-tile" style="background-image: linear-gradient(180deg, rgba(0,0,0,0.05), rgba(0,0,0,0.75)), url('<?php echo esc_url($home('mood-luxury.webp')); ?>');">
				<span>LUXURY</span>
			</a>
			<a href="<?php echo esc_url($cat_link('jeans-trousers')); ?>" class="bsg-mood-tile" style="background-image: linear-gradient(180deg, rgba(0,0,0,0.05), rgba(0,0,0,0.75)), url('<?php echo esc_url($img('bsg-16-l5gxiA.jpg')); ?>');">
				<span>BASICS</span>
			</a>
		</div>
	</section>

	<!-- Steals -->
	<section class="bsg-section bsg-steals-section">
		<h2 class="bsg-section__title bsg-section__title--left">Steals</h2>
		<?php
		// Show an even number of cards (max 4) so no card sits alone in a row.
		$bsg_sale_total = count(array_filter(wc_get_product_ids_on_sale(), function ($id) {
			$p = wc_get_product($id);
			return $p && !$p->is_type('variation') && $p->is_visible();
		}));
		$bsg_steals_limit = $bsg_sale_total >= 4 ? 4 : ($bsg_sale_total >= 2 ? $bsg_sale_total - ($bsg_sale_total % 2) : max($bsg_sale_total, 1));
		echo do_shortcode('[sale_products limit="' . (int) $bsg_steals_limit . '" columns="4"]');
		?>
	</section>

	<!-- Urgency banner -->
	<section class="bsg-urgency-banner" style="background-image: linear-gradient(90deg, rgba(0,0,0,0.75), rgba(0,0,0,0.15)), url('<?php echo esc_url($img('bsg-14-0h24QZ.jpg')); ?>');">
		<div class="bsg-urgency-banner__content">
			<span class="bsg-urgency-banner__eyebrow">Last chance!</span>
			<h2>Few Sizes Left</h2>
			<p>Selling fast across S and M. Grab yours before the restock queue.</p>
			<a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>" class="bsg-btn bsg-btn--accent">Shop Now</a>
		</div>
	</section>

	<!-- New and Popular: tabbed product grid -->
	<section class="bsg-section bsg-newpop-section">
		<h2 class="bsg-section__title">New and Popular</h2>
		<div class="bsg-tabs" data-target="#bsg-newpop-grid">
			<button type="button" class="bsg-tab is-active" data-filter="all">All</button>
			<button type="button" class="bsg-tab" data-filter="kurtas">Kurtas</button>
			<button type="button" class="bsg-tab" data-filter="shirts">Shirts</button>
			<button type="button" class="bsg-tab" data-filter="jeans-trousers">Jeans &amp; Trousers</button>
		</div>
		<div id="bsg-newpop-grid">
			<?php echo do_shortcode('[products limit="8" columns="4" orderby="date" order="DESC"]'); ?>
		</div>
	</section>

	<!-- Recently Viewed — empty shell; JS fills it in from localStorage
	     if the visitor has actually viewed any products before. -->
	<section class="bsg-recently-viewed-section woocommerce" id="bsg-recently-viewed" style="display:none;">
		<h2 class="bsg-section__title">Recently Viewed</h2>
		<ul class="products" id="bsg-recently-viewed-grid"></ul>
	</section>

	<!-- Trust / Reviews strip -->
	<?php
	global $wpdb;
	$review_stats = $wpdb->get_row(
		"SELECT COUNT(*) as cnt, AVG(cm.meta_value) as avg_rating
		 FROM {$wpdb->comments} c
		 INNER JOIN {$wpdb->commentmeta} cm ON cm.comment_id = c.comment_ID AND cm.meta_key = 'rating'
		 INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID AND p.post_type = 'product'
		 WHERE c.comment_approved = '1'"
	);
	$review_count = $review_stats ? (int) $review_stats->cnt : 0;
	$avg_rating   = $review_stats && $review_stats->avg_rating ? round((float) $review_stats->avg_rating, 1) : 0;
	?>
	<section class="bsg-section bsg-trust-section">
		<?php if ($review_count > 0) : ?>
			<div class="bsg-trust-rating">
				<span class="bsg-trust-rating__stars">
					<?php for ($i = 1; $i <= 5; $i++) : ?>
						<span class="bsg-star<?php echo $i <= round($avg_rating) ? ' is-filled' : ''; ?>">&#9733;</span>
					<?php endfor; ?>
				</span>
				<span class="bsg-trust-rating__text"><?php echo esc_html($avg_rating); ?> average rating from <?php echo esc_html($review_count); ?> review<?php echo $review_count === 1 ? '' : 's'; ?></span>
			</div>
		<?php endif; ?>
		<div class="bsg-trust-badges">
			<div class="bsg-trust-badge">
				<span class="bsg-trust-badge__icon">&#10003;</span>
				<span>100% Genuine Products</span>
			</div>
			<div class="bsg-trust-badge">
				<span class="bsg-trust-badge__icon">&#128274;</span>
				<span>Secure Checkout</span>
			</div>
			<div class="bsg-trust-badge">
				<span class="bsg-trust-badge__icon">&#8635;</span>
				<span>Easy Returns &amp; Exchange</span>
			</div>
			<div class="bsg-trust-badge">
				<span class="bsg-trust-badge__icon">&#128666;</span>
				<span>Pan-India Delivery</span>
			</div>
		</div>
	</section>

	<!-- Brand USPs -->
	<section class="bsg-section bsg-usp-section">
		<div class="bsg-usp-item">
			<span class="bsg-usp-icon">&#10003;</span>
			<h4>100% Genuine Quality</h4>
			<p>Every piece checked for fabric, fit, and finish before it ships.</p>
		</div>
		<div class="bsg-usp-item">
			<span class="bsg-usp-icon">&#8635;</span>
			<h4>30-Day Easy Returns</h4>
			<p>Changed your mind? Send it back within 30 days, no questions asked.</p>
		</div>
		<div class="bsg-usp-item">
			<span class="bsg-usp-icon">&#9992;</span>
			<h4>Express Delivery Across India</h4>
			<p>Pan-India shipping in 3–7 business days, free above ₹999.</p>
		</div>
		<div class="bsg-usp-item">
			<span class="bsg-usp-icon">&#9742;</span>
			<h4>24/7 Support</h4>
			<p>Real humans, real answers — whenever you need us.</p>
		</div>
	</section>

	<!-- Newsletter signup lives in the footer (site-wide) — a second
	     homepage-only form here would just duplicate it back-to-back. -->

</main>

<?php get_footer(); ?>
