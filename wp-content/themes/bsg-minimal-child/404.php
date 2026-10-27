<?php
/**
 * 404 — branded "page not found" with ways back into the shop.
 *
 * @package BSG_Minimal
 */

get_header();
?>

<div id="primary" class="content-area">
	<main id="main" class="site-main">
		<div class="bsg-empty bsg-empty--page">
			<p class="bsg-empty__code" aria-hidden="true">404</p>
			<h1 class="bsg-empty__title">This page wandered off</h1>
			<p class="bsg-empty__text">The link may be old or mistyped. Try a search, or jump back into the collection.</p>
			<form class="bsg-404-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
				<input type="hidden" name="post_type" value="product">
				<label class="screen-reader-text" for="bsg-404-s">Search products</label>
				<input type="search" id="bsg-404-s" name="s" placeholder="Search kurtas, shirts, jeans…">
				<button type="submit">Search</button>
			</form>
			<?php bsg_suggest_chips(); ?>
			<a class="button" href="<?php echo esc_url(home_url('/')); ?>">Back to home</a>
		</div>
	</main>
</div>

<?php
get_footer();
