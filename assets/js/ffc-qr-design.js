/**
 * Live preview of the global QR design on the QR Code settings tab (#1563).
 *
 * Sends the form's UNSAVED values to `ffc_qr_design_preview`, which draws
 * them with the same renderer and normaliser the plugin uses, and shows the
 * SVG plus the scan checks (low contrast, inverted colours). The markup comes
 * from the server built out of allowlisted shapes and `#rrggbb` colours only.
 * Selector-guarded: a no-op on any other screen.
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
			return String($root.find('[data-ffc-qr-design="' + key + '"]').val() || '');
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
				frame_color: val('qr_design_frame_color')
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

	function init() {
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
	window.FFC.QrDesign = { init: init, collect: collect, refresh: refresh };

	$(init);
})(jQuery);
