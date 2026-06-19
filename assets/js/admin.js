/* BD Partial COD — admin settings (QR image media uploader) */
(function ($) {
	'use strict';

	$(function () {
		var frame;

		$(document).on('click', '.bd-pcod-image-upload', function (e) {
			e.preventDefault();
			var $field = $(this).closest('.bd-pcod-image-field');
			var $input = $field.find('.bd-pcod-image-url');
			var $preview = $field.find('.bd-pcod-image-preview');

			if (frame) {
				frame.off('select');
			}

			frame = wp.media({
				title: 'Select QR image',
				button: { text: 'Use this image' },
				library: { type: 'image' },
				multiple: false
			});

			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				$input.val(attachment.url);
				$preview.html(
					$('<img>').attr('src', attachment.url).css({
						maxWidth: '160px',
						height: 'auto',
						display: 'block',
						marginTop: '8px'
					})
				);
			});

			frame.open();
		});

		$(document).on('click', '.bd-pcod-image-remove', function (e) {
			e.preventDefault();
			var $field = $(this).closest('.bd-pcod-image-field');
			$field.find('.bd-pcod-image-url').val('');
			$field.find('.bd-pcod-image-preview').empty();
		});
	});
})(jQuery);
