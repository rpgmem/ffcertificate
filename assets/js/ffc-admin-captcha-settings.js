/**
 * FFC Captcha settings — show the widget panel only when it applies.
 *
 * The panel's initial state is rendered server-side, so the screen is correct
 * with JavaScript disabled; this only keeps it correct as the mode changes,
 * without a page reload.
 *
 * Hidden rather than disabled: under the math-only mode these settings have
 * no effect at all, and a greyed block of inapplicable controls is more to
 * read past than no block.
 *
 * @since 6.23.0
 */
(function () {
	'use strict';

	var MATH_ONLY = 'math';

	function panel() {
		return document.getElementById('ffc-captcha-widget-panel');
	}

	function selectedMode() {
		var checked = document.querySelector('input[name="ffc_settings[captcha_provider]"]:checked');
		return checked ? checked.value : '';
	}

	function sync() {
		var target = panel();
		if (!target) {
			return;
		}
		target.hidden = MATH_ONLY === selectedMode();
	}

	document.addEventListener('change', function (event) {
		var input = event.target;
		if (input && 'ffc_settings[captcha_provider]' === input.name) {
			sync();
		}
	});

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', sync);
	} else {
		sync();
	}
})();
