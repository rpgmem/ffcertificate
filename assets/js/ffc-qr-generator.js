/**
 * Short URLs → QR Code Generator (#1563).
 *
 * Shows the fields of the chosen content type, sends them with the design
 * (collected by `FFC.QrDesign.collect`) to `ffc_qr_generate` on every change,
 * and saves what comes back: the SVG as is, the PNG rasterised by
 * `FFC.QrRaster`. "Shorten" is the only write, and only on its own click.
 * Selector-guarded: a no-op on any other screen.
 */
(function ($) {
	'use strict';

	var cfg = window.ffcQrGenerator || {};
	var i18n = cfg.i18n || {};
	var timer = null;
	var sequence = 0;
	var current = null;

	/**
	 * The active content type.
	 *
	 * @param {jQuery} $form Generator form.
	 * @returns {string}
	 */
	function activeType($form) {
		return String($form.find('input[name="type"]:checked').val() || 'url');
	}

	/**
	 * Show only the active type's fields.
	 *
	 * @param {jQuery} $form Generator form.
	 */
	function showType($form) {
		var type = activeType($form);
		$form.find('[data-ffc-qr-type]').each(function () {
			this.hidden = $(this).attr('data-ffc-qr-type') !== type;
		});
	}

	/**
	 * The request for the active type: its fields plus the design.
	 *
	 * @param {jQuery} $form Generator form.
	 * @returns {Object}
	 */
	function collect($form) {
		var type = activeType($form);
		var fields = {};
		$form.find('[data-ffc-qr-field^="' + type + ':"]').each(function () {
			var key = String($(this).attr('data-ffc-qr-field')).split(':')[1];
			if (this.type === 'checkbox') {
				fields[key] = this.checked ? '1' : '';
			} else {
				fields[key] = String($(this).val() || '');
			}
		});
		var request = window.FFC.QrDesign.collect($form);
		request.type = type;
		request.fields = fields;
		return request;
	}

	/**
	 * Fill a `%d`/`%1$d`-style string.
	 *
	 * @param {string} format Format.
	 * @param {Array}  values Values in order.
	 * @returns {string}
	 */
	function fill(format, values) {
		var i = 0;
		return String(format || '')
			.replace(/%(\d)\$[ds]/g, function (m, n) { return String(values[Number(n) - 1]); })
			.replace(/%[ds]/g, function () { return String(values[i++]); })
			.replace(/%%/g, '%');
	}

	/**
	 * One status line: the most serious problem, or "readable".
	 *
	 * @param {jQuery} $out   Status element.
	 * @param {Object} data   Response data.
	 */
	function showStatus($out, data) {
		var checks = data.checks || {};
		var usage = data.usage || {};
		$out.removeClass('is-warning is-error');
		if (checks.inverted) {
			$out.addClass('is-error').text(i18n.inverted || '');
		} else if (checks.caption_contrast === false) {
			$out.addClass('is-warning').text(i18n.captionContrast || '');
		} else if (checks.low_contrast) {
			$out.addClass('is-warning').text(fill(i18n.lowContrast, [checks.min_ratio]));
		} else if (usage.dense) {
			$out.addClass('is-warning').text(fill(i18n.dense, [usage.version]));
		} else {
			$out.text(i18n.ok || '');
		}
	}

	/**
	 * Draw the code; a stale answer never overwrites a newer one.
	 *
	 * @param {jQuery} $form Generator form.
	 * @returns {Promise}
	 */
	function refresh($form) {
		var mine = ++sequence;
		var $preview = $('#ffc-qr-generator-preview');
		var $usage = $('#ffc-qr-generator-usage');
		var $status = $('#ffc-qr-generator-status');
		var $buttons = $('#ffc-qr-download-png, #ffc-qr-download-svg');

		return window.FFC.request(cfg.generate, collect($form), { nonce: cfg.generateNonce, ajaxUrl: cfg.ajaxUrl })
			.then(function (data) {
				if (mine !== sequence) {
					return;
				}
				current = data;
				$preview.html(String(data.svg || ''));
				var usage = data.usage || {};
				$usage.text(fill(i18n.usage, [usage.bytes, usage.capacity, usage.percent]));
				showStatus($status, data);
				$buttons.prop('disabled', false);
			})
			.catch(function (err) {
				if (mine !== sequence) {
					return;
				}
				current = null;
				$preview.empty();
				$usage.text('');
				$buttons.prop('disabled', true);
				$status.removeClass('is-warning').addClass('is-error')
					.text((err && err.fromServer && err.message) || i18n.error || '');
			});
	}

	/**
	 * A file name from the content type and today's date.
	 *
	 * @param {jQuery} $form Generator form.
	 * @param {string} ext   Extension.
	 * @returns {string}
	 */
	function filename($form, ext) {
		return 'qr-' + activeType($form) + '-' + new Date().toISOString().slice(0, 10) + '.' + ext;
	}

	function schedule($form) {
		clearTimeout(timer);
		timer = setTimeout(function () { refresh($form); }, 300);
	}

	function init() {
		var $form = $('#ffc-qr-generator');
		if (!$form.length || !window.FFC || !window.FFC.request || !window.FFC.QrDesign) {
			return;
		}

		$form.on('submit', function (e) { e.preventDefault(); });
		$form.on('change', 'input[name="type"]', function () {
			showType($form);
			refresh($form);
		});
		$form.on('change input', '[data-ffc-qr-field], [data-ffc-qr-design], #qr_default_margin, #qr_default_error_level', function () {
			schedule($form);
		});
		// Social: show the chosen network's prefix before the user name.
		$form.on('change', '#ffc-qr-network', function () {
			$('#ffc-qr-social-prefix').text(String($(this).find('option:selected').attr('data-ffc-qr-prefix') || ''));
		});

		$('#ffc-qr-download-svg').on('click', function () {
			if (current && current.svg) {
				window.FFC.QrRaster.download(window.FFC.QrRaster.encode(current.svg), filename($form, 'svg'), 'image/svg+xml');
			}
		});
		$('#ffc-qr-download-png').on('click', function () {
			if (!current || !current.svg) {
				return;
			}
			var width = Number($('#ffc-qr-png-width').val()) || 1000;
			window.FFC.QrRaster.toPng(window.FFC.QrRaster.encode(current.svg), width)
				.then(function (png) { window.FFC.QrRaster.download(png, filename($form, 'png'), 'image/png'); })
				.catch(function () { $('#ffc-qr-generator-status').addClass('is-error').text(i18n.error || ''); });
		});

		$('#ffc-qr-shorten').on('click', function () {
			var $btn = $(this);
			$btn.prop('disabled', true);
			window.FFC.request(
				cfg.shorten,
				{ url: String($('#ffc-qr-url').val() || ''), title: String($('#ffc-qr-url-title').val() || '') },
				{ nonce: cfg.shortenNonce, ajaxUrl: cfg.ajaxUrl }
			)
				.then(function (data) {
					$btn.prop('disabled', false);
					$('#ffc-qr-url').val(String(data.short_url || ''));
					$('#ffc-qr-generator-status').removeClass('is-warning is-error').text(i18n.shortened || '');
					return refresh($form);
				})
				.catch(function (err) {
					$btn.prop('disabled', false);
					$('#ffc-qr-generator-status').addClass('is-error')
						.text((err && err.fromServer && err.message) || i18n.error || '');
				});
		});

		showType($form);
	}

	window.FFC = window.FFC || {};
	window.FFC.QrGenerator = { init: init, collect: collect, refresh: refresh, fill: fill };

	$(init);
})(jQuery);
