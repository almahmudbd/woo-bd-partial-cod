/* BD Partial COD — front-end payment page behaviour */
(function ($) {
	'use strict';

	$(function () {
		// Copy-to-clipboard for the merchant number.
		$(document).on('click', '.bd-pcod-copy', function () {
			var $btn = $(this);
			var text = $btn.data('copy');

			var done = function () {
				var original = bdPcod.copy;
				$btn.addClass('is-copied').text(bdPcod.copied);
				setTimeout(function () {
					$btn.removeClass('is-copied').text(original);
				}, 1500);
			};

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(String(text)).then(done).catch(function () {
					fallbackCopy(String(text));
					done();
				});
			} else {
				fallbackCopy(String(text));
				done();
			}
		});

		function fallbackCopy(text) {
			var $tmp = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
			$tmp[0].select();
			try {
				document.execCommand('copy');
			} catch (e) {}
			$tmp.remove();
		}

		// AJAX submission of the sender details.
		var $form = $('#bd-pcod-form');
		if (!$form.length) {
			return;
		}

		// Instantly show only the selected method's details.
		function showSelectedMethod() {
			var selected = String($form.find('[name="method"]').val());
			$form.find('.bd-pcod-method-panel').each(function () {
				var $panel = $(this);
				$panel.prop('hidden', String($panel.data('method')) !== selected);
			});
		}

		$form.on('change', '[name="method"]', showSelectedMethod);
		showSelectedMethod();

		// Warn the customer if they try to leave before confirming payment.
		var paid = false;
		$(window).on('beforeunload.bdpcod', function () {
			if (!paid) {
				return bdPcod.leaveWarning;
			}
		});

		$form.on('submit', function (e) {
			e.preventDefault();

			var $msg = $form.find('.bd-pcod-form__message');
			var $submit = $form.find('.bd-pcod-submit');

			var method = $form.find('[name="method"]').val();
			var sender = $.trim($form.find('[name="sender_number"]').val());

			$msg.removeClass('is-error is-success').text('');

			if (!method || !sender) {
				$msg.addClass('is-error').text(bdPcod.required);
				return;
			}

			// Basic 11-digit BD number check (allow optional country code digits).
			var digits = sender.replace(/\D/g, '');
			if (digits.length > 11 && digits.indexOf('880') === 0) {
				digits = '0' + digits.slice(3);
			}
			if (!/^01[3-9]\d{8}$/.test(digits)) {
				$msg.addClass('is-error').text(bdPcod.invalidPhone);
				return;
			}

			$submit.prop('disabled', true);

			$.post(bdPcod.ajaxUrl, {
				action: 'bd_pcod_submit',
				nonce: $form.find('[name="nonce"]').val(),
				order_id: $form.find('[name="order_id"]').val(),
				order_key: $form.find('[name="order_key"]').val(),
				method: method,
				sender_number: sender
			})
				.done(function (res) {
					if (res && res.success) {
						$msg.addClass('is-success').text(res.data.message);
						if (res.data.submitted) {
							paid = true;
							$(window).off('beforeunload.bdpcod');
							$form.find('input, select, button').prop('disabled', true);
							// Send the customer to the order-received (thank-you) page.
							if (res.data.redirect) {
								window.location.href = res.data.redirect;
							}
						}
					} else {
						var m = res && res.data && res.data.message ? res.data.message : 'Error';
						$msg.addClass('is-error').text(m);
						$submit.prop('disabled', false);
					}
				})
				.fail(function (xhr) {
					var m = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
						? xhr.responseJSON.data.message
						: 'Something went wrong. Please try again.';
					$msg.addClass('is-error').text(m);
					$submit.prop('disabled', false);
				});
		});
	});
})(jQuery);
