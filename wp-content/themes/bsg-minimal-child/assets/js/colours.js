/* Product page: colour / pattern picture swatches that swap the photo gallery. */
( function ( $ ) {
	'use strict';

	var cfg = window.bsgColours;
	if ( ! cfg || ! cfg.items ) return;

	var $form = $( 'form.variations_form' );
	var $select = $form.find( 'select[name="' + cfg.attr + '"]' );
	var wrap = document.querySelector( '.woocommerce-product-gallery__wrapper' );
	if ( ! $form.length || ! $select.length ) return;

	var originalGallery = wrap ? wrap.innerHTML : '';
	var buttons = {};
	var list = document.createElement( 'div' );
	list.className = 'bsg-colours';
	list.setAttribute( 'role', 'radiogroup' );
	list.setAttribute( 'aria-label', 'Colour' );

	var slugs = Object.keys( cfg.items );
	slugs.forEach( function ( slug ) {
		var item = cfg.items[ slug ];
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'bsg-colour';
		b.setAttribute( 'role', 'radio' );
		b.setAttribute( 'aria-checked', 'false' );
		b.setAttribute( 'aria-label', item.name );
		b.title = item.name;
		b.dataset.slug = slug;
		if ( item.photos && item.photos.length ) {
			var img = new Image();
			img.src = item.photos[ 0 ].src;
			img.alt = '';
			img.loading = 'lazy';
			img.decoding = 'async';
			b.appendChild( img );
		} else {
			b.classList.add( 'bsg-colour--text' );
			b.textContent = item.name;
		}
		b.addEventListener( 'click', function () {
			if ( b.disabled ) return;
			$select.val( slug ).trigger( 'change' );
		} );
		list.appendChild( b );
		buttons[ slug ] = b;
	} );

	// Put the swatches where the dropdown was, and keep the dropdown (hidden) for WooCommerce.
	$select.before( list );
	$select.addClass( 'bsg-colour-select' );
	$select.closest( 'td, .value' ).find( '.variable-items-wrapper' ).hide();

	// "Colour: Olive Khaki" in the row label.
	var labelEl = document.querySelector( 'label[for="' + $select.attr( 'id' ) + '"]' );
	var chosenEl = document.createElement( 'span' );
	chosenEl.className = 'bsg-colour-chosen';
	if ( labelEl ) labelEl.appendChild( chosenEl );

	function current() {
		return $select.val() || '';
	}

	// True when at least one variation in this colour is in stock for the other choices already made (e.g. the size).
	function hasStock( slug ) {
		var all = $form.data( 'product_variations' );
		if ( ! all || ! all.length ) return true;
		var others = [];
		$form.find( 'select[name^="attribute_"]' ).not( $select ).each( function () {
			if ( this.value ) others.push( [ this.name, this.value ] );
		} );
		return all.some( function ( v ) {
			if ( ! v.is_in_stock ) return false;
			var c = v.attributes[ cfg.attr ];
			if ( c && c !== slug ) return false;
			return others.every( function ( o ) {
				var a = v.attributes[ o[ 0 ] ];
				return ! a || a === o[ 1 ];
			} );
		} );
	}

	function sync() {
		var val = current();
		slugs.forEach( function ( slug ) {
			var b = buttons[ slug ];
			// WooCommerce removes colours that are not available for the other choices, and
			// we also grey out colours that are sold out in the size already chosen.
			var available = $select.find( 'option[value="' + slug + '"]' ).length > 0 && hasStock( slug );
			b.disabled = ! available && slug !== val;
			b.classList.toggle( 'is-unavailable', b.disabled );
			b.classList.toggle( 'is-on', slug === val );
			b.setAttribute( 'aria-checked', slug === val ? 'true' : 'false' );
		} );
		// The swatches plugin already prints the chosen value after the label; only add ours if it did not.
		var pluginShows = labelEl && labelEl.querySelector( '.woo-selected-variation-item-name' );
		chosenEl.textContent = ! pluginShows && val && cfg.items[ val ] ? ': ' + cfg.items[ val ].name : '';
	}

	function slide( p, i ) {
		var d = document.createElement( 'div' );
		d.className = 'woocommerce-product-gallery__image';
		d.setAttribute( 'data-thumb', p.thumb );
		var a = document.createElement( 'a' );
		a.href = p.full;
		var img = new Image();
		img.width = p.w;
		img.height = p.h;
		img.src = p.src;
		if ( p.srcset ) {
			img.srcset = p.srcset;
			img.sizes = p.sizes;
		}
		img.alt = p.alt;
		img.className = i === 0 ? 'wp-post-image' : '';
		img.loading = i === 0 ? 'eager' : 'lazy';
		img.decoding = 'async';
		img.setAttribute( 'data-src', p.full );
		img.setAttribute( 'data-large_image', p.full );
		img.setAttribute( 'data-large_image_width', p.fw );
		img.setAttribute( 'data-large_image_height', p.fh );
		a.appendChild( img );
		d.appendChild( a );
		return d;
	}

	function rebuildDots() {
		if ( typeof window.bsgRebuildGalleryDots === 'function' ) window.bsgRebuildGalleryDots();
	}

	function showGallery() {
		if ( ! wrap ) return;
		var item = cfg.items[ current() ];
		if ( item && item.photos && item.photos.length ) {
			while ( wrap.firstChild ) wrap.removeChild( wrap.firstChild );
			item.photos.forEach( function ( p, i ) { wrap.appendChild( slide( p, i ) ); } );
		} else {
			wrap.innerHTML = originalGallery;
		}
		wrap.scrollLeft = 0;
		rebuildDots();
	}

	$select.on( 'change', function () {
		sync();
		showGallery();
	} );

	// WooCommerce swaps the first photo itself once size and colour are both chosen; put ours back.
	$form.on( 'found_variation', function () {
		window.setTimeout( function () { sync(); showGallery(); }, 0 );
	} );
	$form.on( 'reset_data hide_variation', function () {
		window.setTimeout( function () {
			sync();
			if ( ! current() ) { wrap.innerHTML = originalGallery; rebuildDots(); }
		}, 0 );
	} );
	$form.on( 'woocommerce_update_variation_values', sync );

	sync();
	if ( current() ) showGallery();
}( jQuery ) );
