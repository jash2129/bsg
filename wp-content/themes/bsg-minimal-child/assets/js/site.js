/**
 * BSG Minimal — site-wide interactions.
 * Cart drawer, quick-add, scroll animations. Vanilla JS, no jQuery.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		initThemeToggle();
		initCartDrawer();
		initQuickAdd();
		initQuickAddSheet();
		initPdpPurchaseUX();
		initGalleryDots();
		initAuthTilt();
		initNewsletter();
		initLiveSearch();
		initShopToolbar();
		initCatTabsFade();
		initAccordionLinks();
		initScrollAnimations();
		initStickyHeaderShadow();
		initWishlist();
		initProductTabs();
		initStickyPdpBar();
		initOutOfStockSwatches();
		initOfferChipCopy();
		initSearchPlaceholderRotation();
		initFilterDrawer();
		initCheckoutFieldAttrs();
		initSizeGuide();
		initLoadMore();
		initHeroMobileCarousel();
		initPdpAjaxAddToCart();
		initRecentlyViewed();
	} );

	/* -------------------------------------------------------------------- */
	/* Dark / light theme toggle                                           */
	/* -------------------------------------------------------------------- */

	function initThemeToggle() {
		var toggle = document.getElementById( 'bsg-theme-toggle' );
		if ( ! toggle ) return;

		var root = document.documentElement;

		function currentTheme() {
			var stamped = root.getAttribute( 'data-theme' );
			if ( stamped === 'dark' || stamped === 'light' ) return stamped;
			return window.matchMedia( '(prefers-color-scheme: dark)' ).matches ? 'dark' : 'light';
		}

		function reflect( theme ) {
			toggle.setAttribute( 'aria-pressed', theme === 'dark' ? 'true' : 'false' );
			toggle.setAttribute( 'aria-label', theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme' );
		}

		reflect( currentTheme() );

		toggle.addEventListener( 'click', function () {
			var next = currentTheme() === 'dark' ? 'light' : 'dark';
			root.setAttribute( 'data-theme', next );
			try {
				localStorage.setItem( 'bsg-theme', next );
			} catch ( e ) {}
			reflect( next );
		} );
	}

	/* -------------------------------------------------------------------- */
	/* Load more products (shop / category archives)                       */
	/* -------------------------------------------------------------------- */

	function initLoadMore() {
		var wrap = document.querySelector( '.bsg-load-more-wrap' );
		var btn = document.getElementById( 'bsg-load-more-btn' );
		var grid = document.querySelector( 'ul.products' );
		if ( ! wrap || ! btn || ! grid || typeof bsgSettings === 'undefined' ) return;

		btn.addEventListener( 'click', function () {
			var nextPage = parseInt( wrap.getAttribute( 'data-page' ), 10 ) + 1;

			btn.classList.add( 'is-loading' );
			btn.textContent = 'Loading…';

			var body = new URLSearchParams();
			body.set( 'action', 'bsg_load_more' );
			body.set( 'nonce', bsgSettings.loadMoreNonce );
			body.set( 'page', nextPage );
			body.set( 'category', wrap.getAttribute( 'data-category' ) || '' );
			body.set( 'orderby', wrap.getAttribute( 'data-orderby' ) || '' );
			body.set( 'min_price', wrap.getAttribute( 'data-min-price' ) || '' );
			body.set( 'max_price', wrap.getAttribute( 'data-max-price' ) || '' );
			body.set( 'filter_size', wrap.getAttribute( 'data-filter-size' ) || '' );
			body.set( 'in_stock', wrap.getAttribute( 'data-in-stock' ) || '' );

			fetch( bsgSettings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( json ) {
					btn.classList.remove( 'is-loading' );
					btn.textContent = 'Load More';

					if ( json.success && json.data.html ) {
						grid.insertAdjacentHTML( 'beforeend', json.data.html );
						wrap.setAttribute( 'data-page', nextPage );

						if ( ! json.data.hasMore ) {
							btn.style.display = 'none';
						}
					}
				} )
				.catch( function () {
					btn.classList.remove( 'is-loading' );
					btn.textContent = 'Load More';
				} );
		} );
	}

	/* -------------------------------------------------------------------- */
	/* Size guide modal (PDP)                                               */
	/* -------------------------------------------------------------------- */

	function initSizeGuide() {
		var modal = document.getElementById( 'bsg-size-guide-modal' );
		var overlay = document.getElementById( 'bsg-size-guide-overlay' );
		if ( ! modal || ! overlay ) return;

		var sizeSelect = document.querySelector( 'form.variations_form select[name^="attribute_"], form.cart select[name^="attribute_"]' );
		if ( sizeSelect ) {
			var trigger = document.createElement( 'button' );
			trigger.type = 'button';
			trigger.className = 'bsg-size-guide-trigger';
			trigger.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 17 17 3l4 4L7 21z"/><path d="m7 13 2 2M10 10l2 2M13 7l2 2"/></svg><span>Size Guide</span>';
			var label = sizeSelect.closest( '.value' ) || sizeSelect.parentElement;
			label.insertBefore( trigger, sizeSelect );
			trigger.addEventListener( 'click', openModal );
		}

		function openModal() {
			modal.classList.add( 'is-open' );
			overlay.classList.add( 'is-open' );
			modal.setAttribute( 'aria-hidden', 'false' );
		}
		function closeModal() {
			modal.classList.remove( 'is-open' );
			overlay.classList.remove( 'is-open' );
			modal.setAttribute( 'aria-hidden', 'true' );
		}

		if ( window.location.hash === '#size-guide' ) openModal();

		overlay.addEventListener( 'click', closeModal );
		var closeBtn = document.getElementById( 'bsg-size-guide-close' );
		if ( closeBtn ) closeBtn.addEventListener( 'click', closeModal );
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) closeModal();
		} );
	}

	/* -------------------------------------------------------------------- */
	/* Checkout field mobile attributes (Blocks-based checkout)             */
	/* -------------------------------------------------------------------- */

	function initCheckoutFieldAttrs() {
		// WooCommerce Blocks checkout renders fields client-side via React,
		// so PHP field filters (woocommerce_billing_fields) don't apply here.
		// Poll briefly for the fields to hydrate, then set inputmode/autocomplete.
		var attempts = 0;
		var applyAttrs = function () {
			var applied = false;
			document.querySelectorAll( 'input[id$="-phone"], input[name$="_phone"]' ).forEach( function ( el ) {
				el.setAttribute( 'inputmode', 'numeric' );
				if ( ! el.getAttribute( 'autocomplete' ) ) el.setAttribute( 'autocomplete', 'tel' );
				applied = true;
			} );
			document.querySelectorAll( 'input[id$="-postcode"], input[name$="_postcode"]' ).forEach( function ( el ) {
				el.setAttribute( 'inputmode', 'numeric' );
				if ( ! el.getAttribute( 'autocomplete' ) ) el.setAttribute( 'autocomplete', 'postal-code' );
				applied = true;
			} );
			attempts++;
			if ( ! applied && attempts < 20 ) {
				setTimeout( applyAttrs, 300 );
			}
		};
		applyAttrs();
	}

	/* -------------------------------------------------------------------- */
	/* Homepage mobile hero — single full-bleed slide per view, auto-       */
	/* advancing, dot pagination, native scroll-snap gives swipe for free.  */
	/* -------------------------------------------------------------------- */

	function initHeroMobileCarousel() {
		var track = document.getElementById( 'bsg-hero-mobile-track' );
		var dotsWrap = document.getElementById( 'bsg-hero-mobile-dots' );
		if ( ! track || ! dotsWrap ) return;

		var slides = Array.prototype.slice.call( track.children );
		if ( slides.length < 2 ) return;

		// Some browsers restore a scrollable element's own scroll offset
		// across reloads, so without this the carousel can silently resume
		// on whichever slide it was auto-advanced to before the last reload.
		track.scrollLeft = 0;

		slides.forEach( function ( slide, i ) {
			var dot = document.createElement( 'button' );
			dot.type = 'button';
			dot.className = 'bsg-hero-mobile__dot';
			dot.setAttribute( 'aria-label', 'Go to slide ' + ( i + 1 ) );
			if ( i === 0 ) dot.classList.add( 'is-active' );
			dot.addEventListener( 'click', function () {
				track.scrollTo( { left: slide.offsetLeft, behavior: 'smooth' } );
			} );
			dotsWrap.appendChild( dot );
		} );
		var dots = Array.prototype.slice.call( dotsWrap.children );

		var current = 0;
		function setActive( index ) {
			current = index;
			dots.forEach( function ( dot, i ) {
				dot.classList.toggle( 'is-active', i === index );
			} );
		}

		var scrollTimeout;
		track.addEventListener( 'scroll', function () {
			clearTimeout( scrollTimeout );
			scrollTimeout = setTimeout( function () {
				var index = Math.round( track.scrollLeft / track.clientWidth );
				if ( index !== current ) setActive( index );
			}, 100 );
		} );

		var AUTO_ADVANCE_MS = 6000;
		// People who ask their device for less motion get a carousel that only moves when they swipe.
		if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) return;
		var timer = setInterval( advance, AUTO_ADVANCE_MS );

		function advance() {
			var next = ( current + 1 ) % slides.length;
			track.scrollTo( { left: slides[ next ].offsetLeft, behavior: 'smooth' } );
		}

		// Pause briefly after a manual swipe so it doesn't fight the user.
		track.addEventListener( 'touchstart', function () {
			clearInterval( timer );
			timer = setInterval( advance, AUTO_ADVANCE_MS );
		}, { passive: true } );
	}

	/* -------------------------------------------------------------------- */
	/* Recently Viewed — tracks the last ~10 products a visitor viewed in   */
	/* localStorage (no login required), then renders that row wherever a   */
	/* #bsg-recently-viewed-grid shell exists (homepage, PDP).              */
	/* -------------------------------------------------------------------- */

	function initRecentlyViewed() {
		var MAX_ITEMS = 10;

		function getRecent() {
			try {
				return JSON.parse( localStorage.getItem( 'bsgRecentlyViewed' ) || '[]' );
			} catch ( e ) {
				return [];
			}
		}

		var currentId = typeof window.bsgCurrentProductId !== 'undefined' ? String( window.bsgCurrentProductId ) : null;

		if ( currentId ) {
			var recent = getRecent().filter( function ( id ) { return id !== currentId; } );
			recent.unshift( currentId );
			localStorage.setItem( 'bsgRecentlyViewed', JSON.stringify( recent.slice( 0, MAX_ITEMS ) ) );
		}

		var section = document.getElementById( 'bsg-recently-viewed' );
		var grid = document.getElementById( 'bsg-recently-viewed-grid' );
		if ( ! section || ! grid || typeof bsgSettings === 'undefined' ) return;

		var idsToShow = getRecent().filter( function ( id ) { return id !== currentId; } );
		if ( ! idsToShow.length ) return;

		var body = new URLSearchParams();
		body.set( 'action', 'bsg_recently_viewed_render' );
		body.set( 'nonce', bsgSettings.wishlistNonce );
		idsToShow.forEach( function ( id ) { body.append( 'ids[]', id ); } );

		fetch( bsgSettings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				if ( ! json || ! json.success || ! json.data.count ) return;
				grid.innerHTML = json.data.html;
				section.style.display = '';
			} );
	}

	/* -------------------------------------------------------------------- */
	/* Mobile filter drawer (shop page)                                     */
	/* -------------------------------------------------------------------- */

	function initFilterDrawer() {
		var trigger = document.getElementById( 'bsg-filter-trigger' );
		var sidebar = document.getElementById( 'bsg-shop-sidebar' );
		var overlay = document.getElementById( 'bsg-filter-overlay' );
		var closeBtn = document.getElementById( 'bsg-filter-close' );
		if ( ! trigger || ! sidebar || ! overlay ) return;

		function openDrawer() {
			sidebar.classList.add( 'is-open' );
			overlay.classList.add( 'is-open' );
			document.body.classList.add( 'bsg-drawer-open' );
			// Inline style as a hard guarantee the drawer visibly opens
			// regardless of any stylesheet-load timing/caching quirk.
			sidebar.style.transform = 'none';
			overlay.style.opacity = '1';
			overlay.style.pointerEvents = 'auto';
		}

		function closeDrawer() {
			sidebar.classList.remove( 'is-open' );
			overlay.classList.remove( 'is-open' );
			document.body.classList.remove( 'bsg-drawer-open' );
			sidebar.style.transform = '';
			overlay.style.opacity = '';
			overlay.style.pointerEvents = '';
		}

		trigger.addEventListener( 'click', openDrawer );
		overlay.addEventListener( 'click', closeDrawer );
		if ( closeBtn ) closeBtn.addEventListener( 'click', closeDrawer );

		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) closeDrawer();
		} );
	}

	/* -------------------------------------------------------------------- */
	/* Out-of-stock size swatches — struck-through in place, not omitted.  */
	/* The free Variation Swatches plugin only offers this as a Pro        */
	/* feature, so it's implemented here using WooCommerce's own           */
	/* data-product_variations JSON already present on the page.           */
	/* -------------------------------------------------------------------- */

	function initOutOfStockSwatches() {
		var form = document.querySelector( 'form.variations_form[data-product_variations]' );
		if ( ! form ) return;

		var raw = form.getAttribute( 'data-product_variations' );
		if ( ! raw || raw === 'false' ) return;

		var variations;
		try {
			variations = JSON.parse( raw );
		} catch ( e ) {
			return;
		}
		if ( ! Array.isArray( variations ) ) return;

		var oosValues = [];
		variations.forEach( function ( v ) {
			if ( v.is_in_stock ) return;
			Object.keys( v.attributes || {} ).forEach( function ( key ) {
				if ( key.indexOf( 'size' ) !== -1 && v.attributes[ key ] ) {
					oosValues.push( String( v.attributes[ key ] ).toLowerCase() );
				}
			} );
		} );

		if ( ! oosValues.length ) return;

		document.querySelectorAll( '.variable-item' ).forEach( function ( item ) {
			var val = ( item.getAttribute( 'data-value' ) || '' ).toLowerCase();
			if ( oosValues.indexOf( val ) !== -1 ) {
				item.classList.add( 'bsg-oos-swatch' );
				item.setAttribute( 'aria-disabled', 'true' );
				item.setAttribute( 'title', ( item.getAttribute( 'title' ) || val.toUpperCase() ) + ' — Sold out' );
				item.setAttribute( 'data-tooltip', 'Sold out' );
			}
		} );
	}

	/* -------------------------------------------------------------------- */
	/* Header search — rotating placeholder text                            */
	/* -------------------------------------------------------------------- */

	function initSearchPlaceholderRotation() {
		var input = document.getElementById( 'bsg-header-search-input' );
		if ( ! input ) return;

		var raw = input.getAttribute( 'data-rotate-terms' );
		if ( ! raw ) return;

		var terms = raw.split( ',' ).filter( Boolean );
		if ( terms.length < 2 ) return;

		var i = 0;
		setInterval( function () {
			if ( document.activeElement === input || input.value ) return;
			i = ( i + 1 ) % terms.length;
			input.setAttribute( 'placeholder', 'Search for "' + terms[ i ] + '"' );
		}, 2500 );
	}

	/* -------------------------------------------------------------------- */
	/* PDP offer chip — copy coupon code                                    */
	/* -------------------------------------------------------------------- */

	function initOfferChipCopy() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.bsg-offer-chip__copy' );
			if ( ! btn ) return;
			var chip = btn.closest( '.bsg-offer-chip' );
			var code = chip.getAttribute( 'data-code' );
			var restore = btn.textContent;

			var done = function () {
				btn.textContent = 'Copied!';
				setTimeout( function () { btn.textContent = restore; }, 1500 );
			};

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( code ).then( done ).catch( done );
			} else {
				done();
			}
		} );
	}

	/* -------------------------------------------------------------------- */
	/* Mobile sticky Add-to-Cart bar (PDP)                                  */
	/* -------------------------------------------------------------------- */

	function initStickyPdpBar() {
		var bar = document.getElementById( 'bsg-mobile-sticky-bar' );
		if ( ! bar ) return;

		var buyBtn = document.getElementById( 'bsg-sticky-buy-btn' );
		var sizeLabel = document.getElementById( 'bsg-sticky-size' );
		var sizeSelect = document.querySelector( 'form.variations_form select[name^="attribute_"], form.cart select[name^="attribute_"]' );

		if ( sizeSelect && sizeLabel ) {
			var syncSize = function () {
				var selected = sizeSelect.options[ sizeSelect.selectedIndex ];
				var text = selected && selected.value ? selected.text : 'Select size';
				sizeLabel.textContent = text;
			};
			sizeSelect.addEventListener( 'change', syncSize );
			syncSize();
		}

		if ( buyBtn ) {
			buyBtn.addEventListener( 'click', function () {
				var mainBtn = document.querySelector( 'form.cart button.single_add_to_cart_button, form.variations_form button.single_add_to_cart_button' );
				if ( mainBtn ) {
					mainBtn.click();
				}
			} );
		}
	}

	/* -------------------------------------------------------------------- */
	/* PDP Add to Cart — intercept the classic form submit (which would     */
	/* otherwise reload the page) and go through WooCommerce's own AJAX     */
	/* endpoint instead, so the same "open cart drawer" confirmation used   */
	/* by Quick Add fires here too. Falls back to a normal submit for any   */
	/* case this doesn't handle (validation errors, no variation selected). */
	/* -------------------------------------------------------------------- */

	function initPdpAjaxAddToCart() {
		var form = document.querySelector( 'form.cart, form.variations_form' );
		if ( ! form || typeof wc_add_to_cart_params === 'undefined' ) return;

		form.addEventListener( 'submit', function ( e ) {
			var btn = form.querySelector( '.single_add_to_cart_button' );
			if ( ! btn || btn.disabled || btn.classList.contains( 'disabled' ) ) return;

			var variationInput = form.querySelector( 'input[name="variation_id"]' );
			var productId;
			if ( variationInput ) {
				if ( ! variationInput.value || variationInput.value === '0' ) return; // let native "select an option" validation run
				productId = variationInput.value;
			} else {
				productId = btn.value || btn.getAttribute( 'value' );
			}
			if ( ! productId ) return;

			e.preventDefault();

			var qtyInput = form.querySelector( 'input.qty' );
			var quantity = qtyInput ? qtyInput.value : 1;
			// The sticky bottom bar's buy button is a separate, always-visible
			// element that just triggers this one — mirror the state onto it
			// too so the feedback is visible even if the real button has
			// scrolled off-screen.
			var stickyBtn = document.getElementById( 'bsg-sticky-buy-btn' );
			var feedbackEls = stickyBtn ? [ btn, stickyBtn ] : [ btn ];

			feedbackEls.forEach( function ( el ) {
				el.classList.add( 'bsg-btn-loading' );
				el.disabled = true;
			} );

			var body = new URLSearchParams();
			body.set( 'product_id', productId );
			body.set( 'quantity', quantity );

			fetch( wc_add_to_cart_params.wc_ajax_url.toString().replace( '%%endpoint%%', 'add_to_cart' ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( response ) {
					feedbackEls.forEach( function ( el ) {
						el.classList.remove( 'bsg-btn-loading' );
						el.disabled = false;
					} );

					if ( ! response || response.error ) {
						form.submit(); // fall back so WooCommerce's own error notice shows
						return;
					}

					applyFragments( response.fragments );
					feedbackEls.forEach( function ( el ) {
						el.classList.add( 'bsg-btn-success' );
						window.setTimeout( function () {
							el.classList.remove( 'bsg-btn-success' );
						}, 800 );
					} );
					document.body.dispatchEvent( new Event( 'added_to_cart' ) );
				} )
				.catch( function () {
					feedbackEls.forEach( function ( el ) {
						el.classList.remove( 'bsg-btn-loading' );
						el.disabled = false;
					} );
					form.submit();
				} );
		} );
	}

	/* -------------------------------------------------------------------- */
	/* Wishlist — localStorage for guests, real server persistence (user   */
	/* meta, via AJAX) for logged-in users so it follows them cross-device. */
	/* -------------------------------------------------------------------- */

	function getLocalWishlist() {
		try {
			return JSON.parse( localStorage.getItem( 'bsgWishlist' ) || '[]' );
		} catch ( e ) {
			return [];
		}
	}

	function setLocalWishlist( ids ) {
		localStorage.setItem( 'bsgWishlist', JSON.stringify( ids ) );
	}

	function setWishlistCount( count ) {
		document.querySelectorAll( '.bsg-wishlist-count' ).forEach( function ( el ) {
			el.textContent = count;
		} );
	}

	function wishlistPost( action, extra ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', bsgSettings.wishlistNonce );
		Object.keys( extra || {} ).forEach( function ( key ) {
			body.set( key, extra[ key ] );
		} );
		return fetch( bsgSettings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).then( function ( res ) { return res.json(); } );
	}

	function markActiveHearts( ids ) {
		var strIds = ids.map( String );
		document.querySelectorAll( '.bsg-wishlist-heart' ).forEach( function ( btn ) {
			var id = btn.getAttribute( 'data-product-id' );
			btn.classList.toggle( 'is-active', strIds.indexOf( id ) !== -1 );
		} );
	}

	function initWishlist() {
		if ( typeof bsgSettings === 'undefined' ) return;

		if ( bsgSettings.isLoggedIn ) {
			// Logged in: server is the source of truth. Merge in anything
			// saved locally as a guest (e.g. before this login), then clear
			// the local copy so it doesn't shadow the account going forward.
			var local = getLocalWishlist();
			var mergeStep = local.length
				? wishlistPost( 'bsg_wishlist_merge', { 'ids[]': local } )
				: Promise.resolve( null );

			mergeStep
				.then( function () {
					localStorage.removeItem( 'bsgWishlist' );
					return wishlistPost( 'bsg_wishlist_get' );
				} )
				.then( function ( json ) {
					var ids = json && json.success ? json.data.ids : [];
					markActiveHearts( ids );
					setWishlistCount( ids.length );
				} );

			document.addEventListener( 'click', function ( e ) {
				var heart = e.target.closest( '.bsg-wishlist-heart' );
				if ( ! heart ) return;
				e.preventDefault();

				var id = heart.getAttribute( 'data-product-id' );
				wishlistPost( 'bsg_wishlist_toggle', { product_id: id } ).then( function ( json ) {
					if ( ! json || ! json.success ) return;
					heart.classList.toggle( 'is-active', json.data.added );
					setWishlistCount( json.data.ids.length );
				} );
			} );
		} else {
			// Guest: localStorage only.
			var wishlist = getLocalWishlist();
			markActiveHearts( wishlist );
			setWishlistCount( wishlist.length );

			document.addEventListener( 'click', function ( e ) {
				var heart = e.target.closest( '.bsg-wishlist-heart' );
				if ( ! heart ) return;
				e.preventDefault();

				var id = heart.getAttribute( 'data-product-id' );
				var list = getLocalWishlist();
				var idx = list.indexOf( id );

				if ( idx === -1 ) {
					list.push( id );
					heart.classList.add( 'is-active' );
				} else {
					list.splice( idx, 1 );
					heart.classList.remove( 'is-active' );
				}
				setLocalWishlist( list );
				setWishlistCount( list.length );
			} );
		}

		initGuestWishlistPage();
	}

	/**
	 * On the [bsg_wishlist] page, guests have no server-side list to render
	 * from — fetch product cards for their localStorage IDs instead.
	 */
	function initGuestWishlistPage() {
		if ( bsgSettings.isLoggedIn ) return;
		var grid = document.querySelector( '#bsg-wishlist-grid[data-guest="1"]' );
		if ( ! grid ) return;

		var ids = getLocalWishlist();
		var emptyMsg = document.getElementById( 'bsg-wishlist-guest-empty' );

		if ( ! ids.length ) return;

		wishlistPost( 'bsg_wishlist_render_guest', { 'ids[]': ids } ).then( function ( json ) {
			if ( ! json || ! json.success || ! json.data.count ) return;
			grid.innerHTML = json.data.html;
			grid.style.display = '';
			if ( emptyMsg ) emptyMsg.style.display = 'none';
		} );
	}

	/* -------------------------------------------------------------------- */
	/* Product filter tabs ("New and Popular" style sections)               */
	/* -------------------------------------------------------------------- */

	function initProductTabs() {
		document.querySelectorAll( '.bsg-tabs' ).forEach( function ( tabGroup ) {
			var targetSelector = tabGroup.getAttribute( 'data-target' );
			var grid = document.querySelector( targetSelector );
			if ( ! grid ) return;

			tabGroup.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.bsg-tab' );
				if ( ! btn ) return;

				tabGroup.querySelectorAll( '.bsg-tab' ).forEach( function ( b ) {
					b.classList.remove( 'is-active' );
				} );
				btn.classList.add( 'is-active' );

				var filter = btn.getAttribute( 'data-filter' );
				grid.querySelectorAll( 'li.product' ).forEach( function ( item ) {
					var show = filter === 'all' || item.classList.contains( 'product_cat-' + filter );
					item.style.display = show ? '' : 'none';
				} );
			} );
		} );
	}

	/* -------------------------------------------------------------------- */
	/* Cart drawer                                                          */
	/* -------------------------------------------------------------------- */

	function openDrawer() {
		var drawer = document.getElementById( 'bsg-cart-drawer' );
		var overlay = document.getElementById( 'bsg-cart-overlay' );
		if ( ! drawer || ! overlay ) return;
		drawer.classList.add( 'is-open' );
		overlay.classList.add( 'is-open' );
		drawer.setAttribute( 'aria-hidden', 'false' );
		document.body.classList.add( 'bsg-drawer-open' );
	}

	function closeDrawer() {
		var drawer = document.getElementById( 'bsg-cart-drawer' );
		var overlay = document.getElementById( 'bsg-cart-overlay' );
		if ( ! drawer || ! overlay ) return;
		drawer.classList.remove( 'is-open' );
		overlay.classList.remove( 'is-open' );
		drawer.setAttribute( 'aria-hidden', 'true' );
		document.body.classList.remove( 'bsg-drawer-open' );
	}

	function initCartDrawer() {
		document.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '#bsg-cart-trigger' ) || e.target.closest( '#bsg-tabbar-cart' ) ) {
				e.preventDefault();
				openDrawer();
			}
			if ( e.target.closest( '#bsg-cart-close' ) || e.target.closest( '#bsg-cart-overlay' ) ) {
				closeDrawer();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				closeDrawer();
			}
		} );

		// Re-run WooCommerce's own cart AJAX scripts still work; we just react
		// to the fragments-refreshed event to auto-open the drawer.
		document.body.addEventListener( 'added_to_cart', function () {
			openDrawer();
		} );

		document.body.addEventListener( 'wc_fragments_refreshed', function () {
			// Fragments (shipping bar / count) already swapped in by WooCommerce core.
		} );
	}

	/* -------------------------------------------------------------------- */
	/* Quick add (loop cards)                                               */
	/* -------------------------------------------------------------------- */

	function initQuickAdd() {
		document.addEventListener( 'click', function ( e ) {
			var sizeBtn = e.target.closest( '.bsg-quick-add__size' );
			if ( sizeBtn && ! sizeBtn.disabled ) {
				e.preventDefault();
				var wrapper = sizeBtn.closest( '.bsg-quick-add' );
				quickAdd( {
					productId: wrapper.getAttribute( 'data-product-id' ),
					variationId: sizeBtn.getAttribute( 'data-variation-id' ),
					attrName: sizeBtn.getAttribute( 'data-attr-name' ),
					attrValue: sizeBtn.getAttribute( 'data-attr-value' ),
				}, sizeBtn );
				return;
			}

			var simpleBtn = e.target.closest( '.bsg-quick-add-simple' );
			if ( simpleBtn ) {
				e.preventDefault();
				quickAdd( {
					productId: simpleBtn.getAttribute( 'data-product-id' ),
					variationId: 0,
					attrName: '',
					attrValue: '',
				}, simpleBtn );
			}
		} );
	}

	/* -------------------------------------------------------------------- */
	/* Mobile quick-add bottom sheet                                        */
	/* -------------------------------------------------------------------- */

	function initQuickAddSheet() {
		var sheet = document.getElementById( 'bsg-qa-sheet' );
		var overlay = document.getElementById( 'bsg-qa-overlay' );
		if ( ! sheet || ! overlay ) return;

		var titleEl = document.getElementById( 'bsg-qa-title' );
		var priceEl = document.getElementById( 'bsg-qa-price' );
		var sizesWrap = document.getElementById( 'bsg-qa-sizes-wrap' );
		var sizesEl = document.getElementById( 'bsg-qa-sizes' );
		var guideEl = document.getElementById( 'bsg-qa-guide' );
		var msgEl = document.getElementById( 'bsg-qa-msg' );
		var addBtn = document.getElementById( 'bsg-qa-add' );
		var closeBtn = document.getElementById( 'bsg-qa-close' );
		var state = null;
		var lastFocus = null;

		function openSheet( openBtn ) {
			var card = openBtn.closest( 'li.product' );
			if ( ! card ) return;
			var titleNode = card.querySelector( '.woocommerce-loop-product__title' );
			var priceNode = card.querySelector( '.price' );
			var linkNode = card.querySelector( 'a.woocommerce-LoopProduct-link' );
			var sizeBtns = card.querySelectorAll( '.bsg-quick-add__size' );

			state = {
				productId: openBtn.getAttribute( 'data-product-id' ),
				variationId: 0,
				attrName: '',
				attrValue: '',
				simple: ! sizeBtns.length,
			};

			titleEl.textContent = titleNode ? titleNode.textContent.trim() : '';
			priceEl.innerHTML = priceNode ? priceNode.innerHTML : '';
			msgEl.textContent = '';
			sizesEl.innerHTML = '';
			sizesWrap.hidden = state.simple;

			sizeBtns.forEach( function ( src ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'bsg-sheet__size';
				b.textContent = src.textContent.trim();
				b.setAttribute( 'data-variation-id', src.getAttribute( 'data-variation-id' ) );
				b.setAttribute( 'data-attr-name', src.getAttribute( 'data-attr-name' ) );
				b.setAttribute( 'data-attr-value', src.getAttribute( 'data-attr-value' ) );
				if ( src.disabled ) {
					b.disabled = true;
					b.classList.add( 'is-disabled' );
					b.setAttribute( 'aria-label', b.textContent + ', sold out' );
				}
				sizesEl.appendChild( b );
			} );

			guideEl.hidden = state.simple;
			if ( linkNode ) guideEl.href = linkNode.href.split( '#' )[ 0 ] + '#size-guide';

			lastFocus = openBtn;
			sheet.classList.add( 'is-open' );
			overlay.classList.add( 'is-open' );
			sheet.setAttribute( 'aria-hidden', 'false' );
			document.body.classList.add( 'bsg-sheet-open' );
			closeBtn.focus();
		}

		function closeSheet() {
			sheet.classList.remove( 'is-open' );
			overlay.classList.remove( 'is-open' );
			sheet.setAttribute( 'aria-hidden', 'true' );
			document.body.classList.remove( 'bsg-sheet-open' );
			if ( lastFocus ) lastFocus.focus();
		}

		document.addEventListener( 'click', function ( e ) {
			var openBtn = e.target.closest( '.bsg-qa-open' );
			if ( openBtn ) {
				e.preventDefault();
				e.stopPropagation();
				openSheet( openBtn );
				return;
			}
			var sizeBtn = e.target.closest( '.bsg-sheet__size' );
			if ( sizeBtn && ! sizeBtn.disabled && state ) {
				sizesEl.querySelectorAll( '.bsg-sheet__size' ).forEach( function ( s ) {
					s.classList.remove( 'is-selected' );
					s.setAttribute( 'aria-pressed', 'false' );
				} );
				sizeBtn.classList.add( 'is-selected' );
				sizeBtn.setAttribute( 'aria-pressed', 'true' );
				state.variationId = sizeBtn.getAttribute( 'data-variation-id' );
				state.attrName = sizeBtn.getAttribute( 'data-attr-name' );
				state.attrValue = sizeBtn.getAttribute( 'data-attr-value' );
				msgEl.textContent = '';
			}
		} );

		addBtn.addEventListener( 'click', function () {
			if ( ! state ) return;
			if ( ! state.simple && ! state.variationId ) {
				msgEl.textContent = 'Please select a size';
				sizesEl.classList.add( 'bsg-size-attention' );
				window.setTimeout( function () { sizesEl.classList.remove( 'bsg-size-attention' ); }, 1600 );
				return;
			}
			quickAdd( {
				productId: state.productId,
				variationId: state.variationId,
				attrName: state.attrName,
				attrValue: state.attrValue,
			}, addBtn, function ( ok ) {
				if ( ok ) {
					closeSheet();
				} else {
					msgEl.textContent = 'Could not add this item. Please try again.';
				}
			} );
		} );

		overlay.addEventListener( 'click', closeSheet );
		closeBtn.addEventListener( 'click', closeSheet );
		guideEl.addEventListener( 'click', function () { closeSheet(); } );
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && sheet.classList.contains( 'is-open' ) ) closeSheet();
		} );
	}

	/* -------------------------------------------------------------------- */
	/* Live search: phone overlay + desktop dropdown (WooCommerce Store API) */
	/* -------------------------------------------------------------------- */

	function initLiveSearch() {
		if ( typeof bsgSettings === 'undefined' || ! bsgSettings.productsApi ) return;

		function decode( s ) {
			var t = document.createElement( 'textarea' );
			t.innerHTML = s;
			return t.value;
		}
		function esc( s ) {
			var d = document.createElement( 'div' );
			d.textContent = s;
			return d.innerHTML;
		}
		function money( p, key ) {
			var minor = p.prices.currency_minor_unit || 0;
			var n = Number( p.prices[ key ] ) / Math.pow( 10, minor );
			return ( p.prices.currency_prefix || '₹' ) + n.toLocaleString( 'en-IN' );
		}

		function resultsHtml( term, products, searchUrl ) {
			if ( ! products.length ) {
				return '<p class="bsg-search-hint">No products match “' + esc( term ) + '”. Try kurta, shirt or jeans.</p>';
			}
			var html = '<ul class="bsg-sr">';
			products.forEach( function ( p ) {
				var img = p.images && p.images[ 0 ] ? p.images[ 0 ].thumbnail : '';
				var price = p.on_sale
					? '<del>' + money( p, 'regular_price' ) + '</del> <strong>' + money( p, 'sale_price' ) + '</strong>'
					: '<strong>' + money( p, 'price' ) + '</strong>';
				html += '<li><a class="bsg-sr__item" href="' + esc( p.permalink ) + '">' +
					( img ? '<img src="' + esc( img ) + '" alt="" width="48" height="60">' : '<span class="bsg-sr__noimg"></span>' ) +
					'<span class="bsg-sr__text"><span class="bsg-sr__name">' + esc( decode( p.name ) ) + '</span><span class="bsg-sr__price">' + price + '</span></span></a></li>';
			} );
			html += '</ul><a class="bsg-sr__all" href="' + esc( searchUrl ) + '">See all results for “' + esc( term ) + '”</a>';
			return html;
		}

		// One search session per input: debounce, cancel stale requests, show results in a container.
		function attach( input, container, onEmpty ) {
			var timer = null;
			var ctrl = null;

			function run() {
				var term = input.value.trim();
				if ( ctrl ) ctrl.abort();
				if ( term.length < 2 ) {
					onEmpty();
					return;
				}
				ctrl = window.AbortController ? new AbortController() : null;
				var url = bsgSettings.productsApi + ( bsgSettings.productsApi.indexOf( '?' ) > -1 ? '&' : '?' ) + 'per_page=6&search=' + encodeURIComponent( term );
				var searchUrl = ( input.form ? input.form.action : '/' ) + '?post_type=product&s=' + encodeURIComponent( term );
				container.classList.add( 'is-loading' );
				fetch( url, { signal: ctrl ? ctrl.signal : undefined, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( products ) {
						container.classList.remove( 'is-loading' );
						if ( input.value.trim() !== term ) return;
						container.innerHTML = resultsHtml( term, Array.isArray( products ) ? products : [], searchUrl );
						container.hidden = false;
					} )
					.catch( function ( err ) {
						if ( err && err.name === 'AbortError' ) return;
						container.classList.remove( 'is-loading' );
					} );
			}

			input.addEventListener( 'input', function () {
				window.clearTimeout( timer );
				timer = window.setTimeout( run, 250 );
			} );

			// Arrow keys move through the results; Escape clears focus back to the input.
			input.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'ArrowDown' ) {
					var first = container.querySelector( '.bsg-sr__item' );
					if ( first ) { e.preventDefault(); first.focus(); }
				}
			} );
			container.addEventListener( 'keydown', function ( e ) {
				var items = Array.prototype.slice.call( container.querySelectorAll( '.bsg-sr__item, .bsg-sr__all' ) );
				var i = items.indexOf( document.activeElement );
				if ( i < 0 ) return;
				if ( e.key === 'ArrowDown' ) { e.preventDefault(); if ( items[ i + 1 ] ) items[ i + 1 ].focus(); }
				if ( e.key === 'ArrowUp' ) { e.preventDefault(); ( items[ i - 1 ] || input ).focus(); }
			} );
		}

		// Phone: full-screen overlay.
		var overlay = document.getElementById( 'bsg-search-overlay' );
		var openBtn = document.getElementById( 'bsg-search-open' );
		var closeBtn = document.getElementById( 'bsg-search-close' );
		var input = document.getElementById( 'bsg-search-input' );
		var body = document.getElementById( 'bsg-search-body' );
		if ( overlay && openBtn && closeBtn && input && body ) {
			var hint = body.innerHTML;
			attach( input, body, function () { body.innerHTML = hint; } );
			var open = function () {
				overlay.hidden = false;
				document.body.classList.add( 'bsg-sheet-open' );
				input.focus();
			};
			var close = function () {
				overlay.hidden = true;
				document.body.classList.remove( 'bsg-sheet-open' );
				openBtn.focus();
			};
			openBtn.addEventListener( 'click', open );
			closeBtn.addEventListener( 'click', close );
			document.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' && ! overlay.hidden ) close();
			} );
		}

		// Desktop: dropdown under the header search box.
		var headerInput = document.getElementById( 'bsg-header-search-input' );
		if ( headerInput && headerInput.form ) {
			var drop = document.createElement( 'div' );
			drop.className = 'bsg-search-suggest';
			drop.hidden = true;
			headerInput.form.style.position = 'relative';
			headerInput.form.appendChild( drop );
			attach( headerInput, drop, function () { drop.hidden = true; } );
			document.addEventListener( 'click', function ( e ) {
				if ( ! headerInput.form.contains( e.target ) ) drop.hidden = true;
			} );
			headerInput.addEventListener( 'focus', function () {
				if ( drop.innerHTML ) drop.hidden = false;
			} );
			headerInput.form.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' ) { drop.hidden = true; headerInput.focus(); }
			} );
		}
	}

	/* -------------------------------------------------------------------- */
	/* Newsletter sign-up (footer)                                          */
	/* -------------------------------------------------------------------- */

	function initNewsletter() {
		var form = document.getElementById( 'bsg-newsletter-form' );
		var msg = document.getElementById( 'bsg-newsletter-msg' );
		if ( ! form || ! msg || typeof bsgSettings === 'undefined' ) return;

		function say( text, kind ) {
			msg.textContent = text;
			msg.className = 'bsg-newsletter-msg' + ( kind ? ' is-' + kind : '' );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var email = form.querySelector( 'input[name="email"]' );
			if ( ! email.value || ! email.checkValidity() ) {
				say( 'Please enter a valid email address.', 'error' );
				email.focus();
				return;
			}

			var btn = form.querySelector( 'button' );
			btn.disabled = true;
			say( 'Subscribing…', '' );

			var body = new URLSearchParams();
			body.set( 'action', 'bsg_newsletter' );
			body.set( 'nonce', bsgSettings.newsletterNonce );
			body.set( 'email', email.value );
			body.set( 'website', form.querySelector( 'input[name="website"]' ).value );

			fetch( bsgSettings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( json ) {
					btn.disabled = false;
					if ( json && json.success ) {
						say( json.data.message, 'ok' );
						form.reset();
					} else {
						say( ( json && json.data && json.data.message ) || 'Something went wrong. Please try again.', 'error' );
					}
				} )
				.catch( function () {
					btn.disabled = false;
					say( 'Could not reach the server. Please try again.', 'error' );
				} );
		} );
	}

	/* -------------------------------------------------------------------- */
	/* Login page: 3D showcase and login card lean toward the pointer       */
	/* -------------------------------------------------------------------- */

	function initAuthTilt() {
		var stage = document.querySelector( '.bsg-auth-stage' );
		if ( ! stage ) return;
		if ( ! window.matchMedia( '(hover: hover)' ).matches ) return;
		if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) return;

		var card = document.querySelector( 'form.woocommerce-form-login' );
		var frame = 0;
		var nx = 0;
		var ny = 0;

		function apply() {
			frame = 0;
			stage.style.setProperty( '--ry', ( nx * 18 ).toFixed(1) + 'deg' );
			stage.style.setProperty( '--rx', ( -6 - ny * 10 ).toFixed(1) + 'deg' );
			if ( card ) {
				card.style.setProperty( '--card-ry', ( nx * 5 ).toFixed(1) + 'deg' );
				card.style.setProperty( '--card-rx', ( -ny * 4 ).toFixed(1) + 'deg' );
			}
		}

		document.addEventListener( 'mousemove', function ( e ) {
			nx = ( e.clientX / window.innerWidth - 0.5 ) * 2;
			ny = ( e.clientY / window.innerHeight - 0.5 ) * 2;
			if ( ! frame ) frame = window.requestAnimationFrame( apply );
		}, { passive: true } );
	}

	/* -------------------------------------------------------------------- */
	/* Shop toolbar (mobile): Sort sheet, Filter apply button, sticky offset */
	/* -------------------------------------------------------------------- */

	function initShopToolbar() {
		var toolbar = document.getElementById( 'bsg-shop-toolbar' );
		if ( ! toolbar ) return;

		// Keep the bar glued just under the sticky header.
		function setOffset() {
			var header = document.querySelector( '.site-header, header.header-bar' );
			var h = header ? header.getBoundingClientRect().height : 0;
			document.documentElement.style.setProperty( '--bsg-header-h', Math.round( h ) + 'px' );
		}
		setOffset();
		window.addEventListener( 'resize', setOffset );

		// "Show N products" closes the Filter sheet (filters apply as they are chosen).
		var applyBtn = document.getElementById( 'bsg-filter-apply' );
		var filterClose = document.getElementById( 'bsg-filter-close' );
		if ( applyBtn && filterClose ) {
			applyBtn.addEventListener( 'click', function () { filterClose.click(); } );
		}

		// Sort sheet — radio list built from WooCommerce's own sorting dropdown.
		var select = document.querySelector( '.woocommerce-ordering select.orderby' );
		var sheet = document.getElementById( 'bsg-sort-sheet' );
		var overlay = document.getElementById( 'bsg-sort-overlay' );
		var list = document.getElementById( 'bsg-sort-options' );
		var trigger = document.getElementById( 'bsg-sort-trigger' );
		var closeBtn = document.getElementById( 'bsg-sort-close' );
		if ( ! select || ! sheet || ! overlay || ! list || ! trigger ) {
			if ( trigger ) trigger.hidden = true;
			return;
		}

		Array.prototype.forEach.call( select.options, function ( opt ) {
			var label = document.createElement( 'label' );
			label.className = 'bsg-sort-option';
			var input = document.createElement( 'input' );
			input.type = 'radio';
			input.name = 'bsg_sort';
			input.value = opt.value;
			input.checked = opt.selected;
			var text = document.createElement( 'span' );
			text.textContent = opt.textContent.trim();
			label.appendChild( input );
			label.appendChild( text );
			input.addEventListener( 'change', function () {
				select.value = opt.value;
				var form = select.form;
				if ( form ) {
					if ( form.requestSubmit ) form.requestSubmit(); else form.submit();
				}
			} );
			list.appendChild( label );
		} );

		var lastFocus = null;
		function openSort() {
			lastFocus = trigger;
			sheet.classList.add( 'is-open' );
			overlay.classList.add( 'is-open' );
			sheet.setAttribute( 'aria-hidden', 'false' );
			document.body.classList.add( 'bsg-sheet-open' );
			closeBtn.focus();
		}
		function closeSort() {
			sheet.classList.remove( 'is-open' );
			overlay.classList.remove( 'is-open' );
			sheet.setAttribute( 'aria-hidden', 'true' );
			document.body.classList.remove( 'bsg-sheet-open' );
			if ( lastFocus ) lastFocus.focus();
		}
		trigger.addEventListener( 'click', openSort );
		overlay.addEventListener( 'click', closeSort );
		closeBtn.addEventListener( 'click', closeSort );
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && sheet.classList.contains( 'is-open' ) ) closeSort();
		} );
	}

	/* -------------------------------------------------------------------- */
	/* Category strip: right-edge fade hints that it scrolls sideways       */
	/* -------------------------------------------------------------------- */

	function initCatTabsFade() {
		var nav = document.querySelector( '.bsg-cat-tabs' );
		var scroller = document.querySelector( '.bsg-cat-tabs__scroll' );
		if ( ! nav || ! scroller ) return;
		function sync() {
			var atEnd = scroller.scrollLeft + scroller.clientWidth >= scroller.scrollWidth - 4;
			var canScroll = scroller.scrollWidth > scroller.clientWidth + 4;
			nav.classList.toggle( 'has-more', canScroll && ! atEnd );
		}
		scroller.addEventListener( 'scroll', sync, { passive: true } );
		window.addEventListener( 'resize', sync );
		sync();
	}

	/* -------------------------------------------------------------------- */
	/* Product page gallery dots (mobile swipe carousel)                    */
	/* -------------------------------------------------------------------- */

	function initGalleryDots() {
		var track = document.querySelector( '.woocommerce-product-gallery__wrapper' );
		if ( ! track ) return;

		// Builds (or rebuilds) the dots under the swipe gallery. The colour picker calls this
		// through window.bsgRebuildGalleryDots after it swaps the photos.
		function build() {
			var old = document.querySelector( '.bsg-gallery-dots' );
			if ( old ) old.remove();
			var slides = track.querySelectorAll( '.woocommerce-product-gallery__image' );
			if ( slides.length < 2 ) return;

			var dots = document.createElement( 'div' );
			dots.className = 'bsg-gallery-dots';
			var buttons = [];
			slides.forEach( function ( slide, i ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.setAttribute( 'aria-label', 'Show image ' + ( i + 1 ) + ' of ' + slides.length );
				b.addEventListener( 'click', function () {
					track.scrollTo( { left: i * track.clientWidth, behavior: 'smooth' } );
				} );
				dots.appendChild( b );
				buttons.push( b );
			} );
			track.insertAdjacentElement( 'afterend', dots );

			function sync() {
				var i = Math.round( track.scrollLeft / Math.max( track.clientWidth, 1 ) );
				buttons.forEach( function ( b, n ) { b.classList.toggle( 'is-active', n === i ); } );
			}
			track.onscroll = function () { window.requestAnimationFrame( sync ); };
			sync();
		}

		window.bsgRebuildGalleryDots = build;
		build();
	}

	/* -------------------------------------------------------------------- */
	/* Open the matching accordion when a link points at it (e.g. the       */
	/* "(3 reviews)" link under the title goes to #reviews)                 */
	/* -------------------------------------------------------------------- */

	function initAccordionLinks() {
		function openFor( hash ) {
			if ( hash === '#reviews' || hash === '#tab-reviews' ) {
				var acc = document.getElementById( 'bsg-acc-reviews' );
				if ( acc ) {
					acc.open = true;
					window.setTimeout( function () { acc.scrollIntoView( { behavior: 'smooth', block: 'start' } ); }, 50 );
					return true;
				}
			}
			return false;
		}
		document.addEventListener( 'click', function ( e ) {
			var a = e.target.closest( 'a[href^="#"]' );
			if ( a && openFor( a.getAttribute( 'href' ) ) ) e.preventDefault();
		} );
		if ( window.location.hash ) openFor( window.location.hash );
	}

	/* -------------------------------------------------------------------- */
	/* Product page purchase UX: button wording, size prompt, low stock,    */
	/* quantity stepper                                                     */
	/* -------------------------------------------------------------------- */

	function initPdpPurchaseUX() {
		var cartForm = document.querySelector( 'form.cart' );
		if ( ! cartForm ) return;

		var mainBtn = cartForm.querySelector( '.single_add_to_cart_button' );

		cartForm.querySelectorAll( '.quantity' ).forEach( function ( q ) {
			var input = q.querySelector( 'input.qty' );
			if ( ! input || q.querySelector( '.bsg-qty-btn' ) ) return;

			function step( dir ) {
				var min = parseInt( input.min, 10 );
				var max = parseInt( input.max, 10 );
				var val = parseInt( input.value, 10 ) || ( isNaN( min ) ? 1 : min );
				val += dir;
				if ( ! isNaN( min ) && val < min ) val = min;
				if ( ! isNaN( max ) && max > 0 && val > max ) val = max;
				input.value = val;
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
			function makeBtn( cls, label, aria, dir ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'bsg-qty-btn ' + cls;
				b.textContent = label;
				b.setAttribute( 'aria-label', aria );
				b.addEventListener( 'click', function () { step( dir ); } );
				return b;
			}
			q.insertBefore( makeBtn( 'bsg-qty-btn--minus', '−', 'Decrease quantity', -1 ), input );
			q.appendChild( makeBtn( 'bsg-qty-btn--plus', '+', 'Increase quantity', 1 ) );
		} );

		var form = document.querySelector( 'form.variations_form' );
		if ( ! form || ! mainBtn ) return;

		var variationsEl = form.querySelector( '.variations' ) || form;
		var msg = document.createElement( 'p' );
		msg.className = 'bsg-size-msg';
		msg.setAttribute( 'role', 'alert' );
		var low = document.createElement( 'p' );
		low.className = 'bsg-low-stock';
		variationsEl.insertAdjacentElement( 'afterend', low );
		variationsEl.insertAdjacentElement( 'afterend', msg );

		function needsSize() {
			var v = form.querySelector( 'input[name="variation_id"]' );
			return ! v || ! v.value || v.value === '0';
		}

		function syncLabel() {
			var label = 'Add to Bag';
			if ( needsSize() ) {
				label = 'Select a size';
			} else if ( mainBtn.classList.contains( 'wc-variation-is-unavailable' ) ) {
				label = 'Sold out';
			}
			mainBtn.textContent = label;
			mainBtn.classList.toggle( 'bsg-needs-size', label === 'Select a size' );
		}

		function promptSize() {
			variationsEl.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			variationsEl.classList.add( 'bsg-size-attention' );
			msg.textContent = 'Please select a size';
			window.setTimeout( function () {
				variationsEl.classList.remove( 'bsg-size-attention' );
			}, 1800 );
		}

		// Capture here so WooCommerce's own handler (a browser alert) never runs.
		mainBtn.addEventListener( 'click', function ( e ) {
			if ( needsSize() ) {
				e.preventDefault();
				e.stopImmediatePropagation();
				promptSize();
			}
		} );

		function showLowStock( variation ) {
			var qty = variation ? parseInt( variation.max_qty, 10 ) : NaN;
			if ( variation && variation.is_in_stock && qty > 0 && qty <= 3 ) {
				low.textContent = 'Only ' + qty + ' left';
			} else {
				low.textContent = '';
			}
		}

		var $ = window.jQuery;
		if ( $ ) {
			$( form ).on( 'found_variation', function ( e, variation ) {
				msg.textContent = '';
				showLowStock( variation );
				window.setTimeout( syncLabel, 0 );
			} );
			$( form ).on( 'reset_data hide_variation', function () {
				low.textContent = '';
				window.setTimeout( syncLabel, 0 );
			} );
			$( form ).on( 'show_variation', function () {
				window.setTimeout( syncLabel, 0 );
			} );
		}
		syncLabel();
	}

	function quickAdd( data, triggerEl, done ) {
		if ( typeof bsgSettings === 'undefined' ) return;

		triggerEl.classList.add( 'is-loading' );

		var body = new URLSearchParams();
		body.set( 'action', 'bsg_quick_add' );
		body.set( 'nonce', bsgSettings.nonce );
		body.set( 'product_id', data.productId );
		body.set( 'variation_id', data.variationId || 0 );
		body.set( 'attr_name', data.attrName || '' );
		body.set( 'attr_value', data.attrValue || '' );

		fetch( bsgSettings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				triggerEl.classList.remove( 'is-loading' );
				if ( json.success ) {
					applyFragments( json.data.fragments );
					document.body.dispatchEvent( new Event( 'added_to_cart' ) );
					triggerEl.classList.add( 'is-added' );
					window.setTimeout( function () {
						triggerEl.classList.remove( 'is-added' );
					}, 1200 );
				} else {
					triggerEl.classList.add( 'is-error' );
					window.setTimeout( function () {
						triggerEl.classList.remove( 'is-error' );
					}, 1200 );
				}
				if ( done ) done( !! json.success, json );
			} )
			.catch( function () {
				triggerEl.classList.remove( 'is-loading' );
				if ( done ) done( false );
			} );
	}

	function applyFragments( fragments ) {
		if ( ! fragments ) return;
		Object.keys( fragments ).forEach( function ( selector ) {
			document.querySelectorAll( selector ).forEach( function ( el ) {
				el.outerHTML = fragments[ selector ];
			} );
		} );
	}

	/* -------------------------------------------------------------------- */
	/* Scroll-in animations                                                 */
	/* -------------------------------------------------------------------- */

	function initScrollAnimations() {
		var targets = document.querySelectorAll(
			'.bsg-animate, ul.products li.product, .bsg-section, .bsg-usp-item, .bsg-category-card'
		);

		if ( ! ( 'IntersectionObserver' in window ) || ! targets.length ) {
			targets.forEach( function ( t ) { t.classList.add( 'is-visible' ); } );
			return;
		}

		var observer = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					entry.target.classList.add( 'is-visible' );
					observer.unobserve( entry.target );
				}
			} );
		}, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' } );

		targets.forEach( function ( t ) {
			t.classList.add( 'bsg-animate' );
			observer.observe( t );
		} );
	}

	/* -------------------------------------------------------------------- */
	/* Sticky header shadow on scroll                                       */
	/* -------------------------------------------------------------------- */

	function initStickyHeaderShadow() {
		var header = document.querySelector( '.site-header, header.header-bar' );
		if ( ! header ) return;

		window.addEventListener( 'scroll', function () {
			if ( window.scrollY > 8 ) {
				header.classList.add( 'is-scrolled' );
			} else {
				header.classList.remove( 'is-scrolled' );
			}
		}, { passive: true } );
	}
} )();
