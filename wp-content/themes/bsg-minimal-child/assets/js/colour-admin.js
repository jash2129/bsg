/* Product screen: "Photos by colour" picker. */
jQuery(function ($) {
	'use strict';

	function sync($row) {
		var ids = $row.find('.bsg-cp-list li').map(function () { return $(this).data('id'); }).get();
		$row.find('.bsg-cp-input').val(ids.join(','));
	}

	$(document).on('click', '.bsg-cp-add', function (e) {
		e.preventDefault();
		var $row = $(this).closest('.bsg-cp-row');
		var frame = wp.media({ title: 'Photos for ' + $row.find('.bsg-cp-name').text(), button: { text: 'Use these photos' }, multiple: 'add', library: { type: 'image' } });
		frame.on('select', function () {
			frame.state().get('selection').each(function (att) {
				var a = att.toJSON();
				if ($row.find('.bsg-cp-list li[data-id="' + a.id + '"]').length) return;
				var src = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
				var $li = $('<li>').attr('data-id', a.id)
					.append($('<img>').attr({ src: src, width: 70, height: 90 }).css('object-fit', 'cover'))
					.append($('<button type="button" class="bsg-cp-remove" aria-label="Remove photo">&times;</button>'));
				$row.find('.bsg-cp-list').append($li);
			});
			sync($row);
		});
		frame.open();
	});

	$(document).on('click', '.bsg-cp-remove', function () {
		var $row = $(this).closest('.bsg-cp-row');
		$(this).closest('li').remove();
		sync($row);
	});
});
