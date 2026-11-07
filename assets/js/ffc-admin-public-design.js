/**
 * Settings → Public Design: the live contrast warning and the colour inputs
 * that follow the switch (#2341).
 *
 * The warning is the server's own (`PublicDesign::contrast_warning()`),
 * asked for as the colours change, so this file holds no contrast maths.
 * While the design is off its colours are not used, so their inputs are
 * disabled; they come back as soon as the switch is on.
 */
(function ($) {
	'use strict';

	var cfg = window.ffcPublicDesign || {};
	var FIELDS = ['accent', 'border', 'muted', 'error', 'info'];
	var DELAY = 300;

	function input(field) {
		return $('#public_design_' + field);
	}

	function enabled() {
		return $('#public_design').is(':checked');
	}

	function setColoursEnabled(on) {
		FIELDS.forEach(function (field) {
			input(field).prop('disabled', !on);
		});
	}

	function show(warning) {
		var $region = $('.ffc-public-design-contrast');
		$region.empty();
		if (warning && window.FFC && typeof window.FFC.notice === 'function') {
			$region.append(window.FFC.notice('warning', 'warning', warning, '', { role: 'status' }));
		}
	}

	var timer = null;
	var seq = 0;

	function check() {
		if (!enabled()) {
			show('');
			return;
		}
		var data = { action: cfg.action, nonce: cfg.nonce };
		FIELDS.forEach(function (field) {
			data[field] = input(field).val();
		});
		var mine = ++seq;
		$.post(cfg.ajaxUrl, data).done(function (res) {
			// An older answer arriving after a newer one is dropped.
			if (mine !== seq || !res || !res.success) {
				return;
			}
			show(res.data && res.data.warning);
		});
	}

	function schedule() {
		clearTimeout(timer);
		timer = setTimeout(check, DELAY);
	}

	$(function () {
		if (!$('.ffc-public-design-contrast').length) {
			return;
		}
		setColoursEnabled(enabled());
		$('#public_design').on('change', function () {
			setColoursEnabled(enabled());
			schedule();
		});
		FIELDS.forEach(function (field) {
			input(field).on('input change', schedule);
		});
	});
})(jQuery);
