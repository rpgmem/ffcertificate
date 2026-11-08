/**
 * Live preview of the global QR design on the QR Code settings tab (#1563).
 *
 * Sends the form's UNSAVED values to `ffc_qr_design_preview`, which draws
 * them with the same renderer and normaliser the plugin uses, and shows the
 * SVG plus every scan problem at once (low contrast, inverted colours, no
 * quiet zone). The markup comes
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
	 * Every scan problem the checks report, the most serious first.
	 *
	 * Shared with the generator, which adds its own (a dense code). A margin
	 * of 0 leaves no quiet zone, which many readers need around the code.
	 *
	 * @param {Object} checks  { inverted, transparent, caption_contrast, caption_lighter, low_contrast, min_ratio }.
	 * @param {string} margin  The margin field's value.
	 * @param {Object} strings Translated texts of the screen.
	 * @returns {Array<{state: string, text: string}>}
	 */
	function problems(checks, margin, strings) {
		var list = [];
		checks = checks || {};
		strings = strings || {};
		if (checks.inverted) {
			list.push({ state: 'error', text: String(strings.inverted || '') });
		}
		if (checks.transparent) {
			list.push({ state: 'warning', text: String(strings.transparent || '') });
		}
		if (checks.low_contrast) {
			list.push({ state: 'warning', text: String(strings.lowContrast || '').replace('%s', String(checks.min_ratio)) });
		}
		if (checks.caption_contrast === false) {
			list.push({ state: 'warning', text: String((checks.caption_lighter ? strings.captionLighter : strings.captionContrast) || '') });
		}
		if (String(margin) === '0') {
			list.push({ state: 'warning', text: String(strings.quietZone || '') });
		}
		return list;
	}

	/**
	 * Show the problems together: one line for one, a heading and a list for
	 * several, "readable" for none. Texts land through `textContent`.
	 *
	 * @param {jQuery} $out    Status element.
	 * @param {Array}  list    From problems().
	 * @param {Object} strings Translated texts (ok, several).
	 */
	function showProblems($out, list, strings) {
		strings = strings || {};
		if (!list.length) {
			window.FFC.status($out, 'success', strings.ok || '');
			return;
		}
		if (list.length === 1) {
			window.FFC.status($out, list[0].state, list[0].text);
			return;
		}
		var worst = list.some(function (p) { return p.state === 'error'; }) ? 'error' : 'warning';
		var el = window.FFC.status($out, worst, strings.several || '');
		if (!el) {
			return;
		}
		var ul = document.createElement('ul');
		ul.className = 'ffc-qr-checks-list';
		list.forEach(function (p) {
			var li = document.createElement('li');
			li.textContent = p.text;
			ul.appendChild(li);
		});
		el.appendChild(ul);
	}

	/**
	 * Render the scan checks of the settings preview.
	 *
	 * @param {jQuery} $out    Status element.
	 * @param {Object} checks  Scan checks.
	 * @param {string} margin  The margin field's value.
	 */
	function showChecks($out, checks, margin) {
		showProblems($out, problems(checks, margin, i18n), i18n);
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
				showChecks($out, data.checks || {}, collect($root).margin);
			})
			.catch(function () {
				if (mine !== sequence) {
					return;
				}
				window.FFC.status($out, 'error', i18n.error || '');
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

			// A text that is not a colour says so under the pair, in the
			// field-message standard (#1844); the picker keeps the last
			// good colour, which the box shows again on blur.
			var $pair = $hex.closest('.ffc-qr-color');
			function settle() {
				$hex.val(String($picker.val() || ''));
				window.FFC.clearFieldMessage($hex);
			}
			$picker.on('input change', settle);
			$hex.on('input', function () {
				var color = normalizeHex($hex.val());
				if (color === '' && String($hex.val()).trim() !== '') {
					window.FFC.fieldMessage($hex, 'error', $hex.attr('data-ffc-invalid-message') || '', { after: $pair });
				} else {
					window.FFC.clearFieldMessage($hex);
				}
				if (color !== '' && color !== String($picker.val()).toLowerCase()) {
					$picker.val(color).trigger('input');
				}
			});
			$hex.on('blur', settle);
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
		// The preview sits in the screen's side column, outside the form
		// (#2486), so the form is the one that holds the design fields.
		var $root = $('[data-ffc-qr-design]').first().closest('form');
		if (!$root.length) {
			return;
		}

		$root.on('change input', '[data-ffc-qr-design], #qr_default_margin, #qr_default_error_level', function () {
			clearTimeout(timer);
			timer = setTimeout(function () { refresh($root); }, 250);
		});

		refresh($root);
	}

	window.FFC = window.FFC || {};
	window.FFC.QrDesign = { init: init, collect: collect, refresh: refresh, bindColorPairs: bindColorPairs, normalizeHex: normalizeHex, problems: problems, showProblems: showProblems };

	$(init);
})(jQuery);
