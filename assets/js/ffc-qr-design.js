/**
 * Live preview of the global QR design on the QR Code settings tab (#1563).
 *
 * Sends the form's UNSAVED values to `ffc_qr_design_preview`, which draws
 * them with the same renderer and normaliser the plugin uses, and shows the
 * SVG plus the scan checks (low contrast, inverted colours). The markup comes
 * from the server built out of allowlisted shapes and `#rrggbb` colours only.
 * Also keeps each colour picker and its hex box in step, on both screens
 * that print the shared design fields (#1570). Selector-guarded: a no-op on
 * any other screen.
 */
(function ($) {
	'use strict';

	var cfg = window.ffcQrDesign || {};
	var i18n = cfg.i18n || {};
	var timer = null;
	var sequence = 0;

	/**
	 * Collect the design fields into the endpoint's input shape.
	 *
	 * @param {jQuery} $root Settings form.
	 * @returns {Object}
	 */
	function collect($root) {
		function val(key) {
			var $el = $root.find('[data-ffc-qr-design="' + key + '"]');
			// A tile picker is a radio group: its value is the checked one.
			if ($el.is(':radio')) {
				$el = $el.filter(':checked');
			}
			return String($el.val() || '');
		}
		return {
			design: {
				dots: val('qr_design_dots'),
				eye_frame: val('qr_design_eye_frame'),
				eye_ball: val('qr_design_eye_ball'),
				color: val('qr_design_color'),
				background: val('qr_design_background'),
				eye_frame_color: val('qr_design_eye_frame_color'),
				eye_ball_color: val('qr_design_eye_ball_color'),
				gradient: $root.find('[data-ffc-qr-design="qr_design_gradient"]').is(':checked') ? '1' : '',
				color_end: val('qr_design_color_end'),
				frame: val('qr_design_frame'),
				frame_text: val('qr_design_frame_text'),
				frame_color: val('qr_design_frame_color'),
				frame_icon: val('qr_design_frame_icon'),
				transparent: $root.find('[data-ffc-qr-design="qr_design_transparent"]').is(':checked') ? '1' : ''
			},
			logo_id: val('qr_design_logo_id'),
			margin: String($root.find('#qr_default_margin').val() || '2'),
			error_level: String($root.find('#qr_default_error_level').val() || 'M')
		};
	}

	/**
	 * Render the scan checks as one status line.
	 *
	 * @param {jQuery} $out    Status element.
	 * @param {Object} checks  { inverted, low_contrast, min_ratio, caption_contrast }.
	 */
	function showChecks($out, checks) {
		$out.removeClass('is-warning is-error');
		if (checks.inverted) {
			$out.addClass('is-error').text(i18n.inverted || '');
		} else if (checks.transparent) {
			$out.addClass('is-warning').text(i18n.transparent || '');
		} else if (checks.caption_contrast === false) {
			$out.addClass('is-warning').text(i18n.captionContrast || '');
		} else if (checks.low_contrast) {
			$out.addClass('is-warning').text(String(i18n.lowContrast || '').replace('%s', String(checks.min_ratio)));
		} else {
			$out.text(i18n.ok || '');
		}
	}

	/**
	 * Fetch and show the preview; a stale answer never overwrites a newer one.
	 *
	 * @param {jQuery} $root Settings form.
	 * @returns {Promise}
	 */
	function refresh($root) {
		var mine = ++sequence;
		var $img = $('#ffc-qr-design-preview');
		var $out = $('#ffc-qr-design-checks');

		return window.FFC.request(cfg.action, collect($root), { nonce: cfg.nonce, ajaxUrl: cfg.ajaxUrl })
			.then(function (data) {
				if (mine !== sequence) {
					return;
				}
				$img.html(String(data.svg || ''));
				showChecks($out, data.checks || {});
			})
			.catch(function () {
				if (mine !== sequence) {
					return;
				}
				$out.removeClass('is-warning').addClass('is-error').text(i18n.error || '');
			});
	}

	/**
	 * Keep each colour picker and its hex box in step (#1570). The box
	 * accepts `#rgb` or `#rrggbb`, with or without the `#`, and only a
	 * complete colour reaches the picker; the picker then announces the
	 * change, so the preview follows either one.
	 *
	 * @param {jQuery} $scope Where to look.
	 */
	function bindColorPairs($scope) {
		$scope.find('[data-ffc-qr-hex-for]').each(function () {
			var $hex = $(this);
			var $picker = $scope.find('#' + $hex.attr('data-ffc-qr-hex-for'));
			if (!$picker.length || $hex.data('ffcQrHexBound')) {
				return;
			}
			$hex.data('ffcQrHexBound', true);

			$picker.on('input change', function () {
				$hex.val(String($picker.val() || '')).removeClass('is-invalid');
			});
			$hex.on('input', function () {
				var color = normalizeHex($hex.val());
				$hex.toggleClass('is-invalid', color === '' && String($hex.val()).trim() !== '');
				if (color !== '' && color !== String($picker.val()).toLowerCase()) {
					$picker.val(color).trigger('input');
				}
			});
			$hex.on('blur', function () {
				$hex.val(String($picker.val() || '')).removeClass('is-invalid');
			});
		});
	}

	/**
	 * `#rrggbb` in lower case, or '' when the text is not a colour.
	 *
	 * @param {string} text Typed text.
	 * @returns {string}
	 */
	function normalizeHex(text) {
		var hex = String(text || '').trim().replace(/^#/, '').toLowerCase();
		if (/^[0-9a-f]{3}$/.test(hex)) {
			hex = hex.charAt(0) + hex.charAt(0) + hex.charAt(1) + hex.charAt(1) + hex.charAt(2) + hex.charAt(2);
		}
		return /^[0-9a-f]{6}$/.test(hex) ? '#' + hex : '';
	}

	function init() {
		bindColorPairs($(document));

		var $preview = $('#ffc-qr-design-preview');
		if (!$preview.length || !window.FFC || !window.FFC.request) {
			return;
		}
		var $root = $preview.closest('form');

		$root.on('change input', '[data-ffc-qr-design], #qr_default_margin, #qr_default_error_level', function () {
			clearTimeout(timer);
			timer = setTimeout(function () { refresh($root); }, 250);
		});

		refresh($root);
	}

	window.FFC = window.FFC || {};
	window.FFC.QrDesign = { init: init, collect: collect, refresh: refresh, bindColorPairs: bindColorPairs, normalizeHex: normalizeHex };

	$(init);
})(jQuery);
