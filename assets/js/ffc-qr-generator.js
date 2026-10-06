/**
 * Short URLs → QR Code Generator (#1563).
 *
 * Shows the fields of the chosen content type, sends them with the design
 * (collected by `FFC.QrDesign.collect`) to `ffc_qr_generate` on every change,
 * and saves what comes back: the SVG as is, the PNG rasterised by
 * `FFC.QrRaster`, in the format chosen beside the preview; "Print" sends
 * the code alone to the printer (#1570). For a website or a social profile,
 * "Create a short URL" (on by default) draws a fixed example short URL in
 * the preview and creates the real one on the first download or print,
 * reusing it until the destination or the title changes (#1586). A
 * download remembers the design for this user (never the content), and
 * "Reset to default" forgets it and puts the global design back (#1568).
 * Selector-guarded: a no-op on any other screen.
 */
(function ($) {
	'use strict';

	var cfg = window.ffcQrGenerator || {};
	var i18n = cfg.i18n || {};
	var timer = null;
	var sequence = 0;
	var current = null;
	// The short URL the code carries: created on a download, or picked with
	// "Use this"; valid while `key` matches the content it was made for.
	var shortUrl = { code: '', key: '' };
	// What had the focus when the saved overlay opened, to give it back.
	var savedOpener = null;
	// What the operator asked for, kept while a circular address locks the switch.
	var wantShort = true;
	var SHORTENABLE = ['url', 'social'];

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
		showWifiEnterprise($form);
		showShort($form);
	}

	/**
	 * Whether the active type can carry a short URL.
	 *
	 * @param {jQuery} $form Generator form.
	 * @returns {boolean}
	 */
	function shortenable($form) {
		return SHORTENABLE.indexOf(activeType($form)) !== -1;
	}

	/**
	 * Show the short URL block for the types that have one, and its title
	 * row only while the switch is on (the duplicate row follows the server).
	 *
	 * @param {jQuery} $form Generator form.
	 */
	function showShort($form) {
		var on = shortenable($form) && $form.find('#ffc-qr-short').is(':checked');
		$form.find('[data-ffc-qr-short-for]').each(function () {
			this.hidden = !shortenable($form);
		});
		$form.find('[data-ffc-qr-short-on]').each(function () {
			if (!on) {
				this.hidden = true;
			} else if (this.id !== 'ffc-qr-short-duplicates') {
				this.hidden = false;
			}
		});
	}

	/**
	 * What the short URL was made for: the type, its fields and the title.
	 *
	 * @param {jQuery} $form Generator form.
	 * @returns {string}
	 */
	function contentKey($form) {
		var type = activeType($form);
		var fields = {};
		$form.find('[data-ffc-qr-field^="' + type + ':"]').each(function () {
			fields[String($(this).attr('data-ffc-qr-field'))] = String($(this).val() || '');
		});
		return JSON.stringify([type, fields, String($form.find('#ffc-qr-short-title').val() || '').trim()]);
	}

	/**
	 * The message an error carries: a 4xx reply arrives through the request's
	 * failure path, so its message sits in `data`, not in `message`.
	 *
	 * @param {Error} err Rejection from FFC.request.
	 * @returns {string}
	 */
	function errorMessage(err) {
		if (err && err.data && typeof err.data.message === 'string' && err.data.message) {
			return err.data.message;
		}
		return (err && err.fromServer && err.message) || i18n.error || '';
	}

	/**
	 * Show the Enterprise sign-in rows only for an Enterprise network.
	 *
	 * @param {jQuery} $form Generator form.
	 */
	function showWifiEnterprise($form) {
		var enterprise = String($form.find('[data-ffc-qr-field="wifi:security"]').val() || '') === 'WPA2-EAP';
		$form.find('[data-ffc-qr-wifi-enterprise]').each(function () {
			this.hidden = !enterprise;
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
		if (shortenable($form)) {
			if (shortUrl.code && shortUrl.key !== contentKey($form)) {
				shortUrl = { code: '', key: '' };
				showSaved(null);
			}
			request.short = wantShort ? '1' : '';
			request.short_code = shortUrl.code;
		}
		return request;
	}

	/**
	 * Reflect the server's short URL state: the circular lock, the list of
	 * short URLs already sending to the address, and the one in the code.
	 *
	 * @param {jQuery}      $form Generator form.
	 * @param {Object|null} state `data.short` from ffc_qr_generate.
	 */
	function showShortState($form, state) {
		var $switch = $form.find('#ffc-qr-short');
		var circular = !!(state && state.circular);
		$switch.prop('disabled', circular).prop('checked', circular ? false : wantShort);
		$form.find('#ffc-qr-short-circular').prop('hidden', !circular);
		showShort($form);

		var duplicates = (state && state.example && state.duplicates) || [];
		var $list = $form.find('#ffc-qr-short-duplicate-list').empty();
		duplicates.forEach(function (item) {
			var $li = $('<li>');
			$('<strong>').text(item.title || i18n.untitled || '').appendTo($li);
			$li.append(' ');
			$('<code>').text(String(item.url || '')).appendTo($li);
			$li.append(document.createTextNode(' ' + fill(i18n.duplicateMeta, [item.created, item.clicks]) + ' '));
			$('<button type="button" class="button button-small ffc-qr-short__use">')
				.attr('data-code', String(item.code || ''))
				.text(i18n.useThis || '')
				.appendTo($li);
			$list.append($li);
		});
		$form.find('#ffc-qr-short-duplicates').prop('hidden', !duplicates.length || !$switch.is(':checked'));

		var url = (state && state.code && state.url) || '';
		$form.find('#ffc-qr-short-result').text(url);
		$form.find('#ffc-qr-short-result-row').prop('hidden', !url);
		$form.find('#ffc-qr-short-copied').text('');
	}

	/**
	 * Before a download or a print: create the short URL when the switch is
	 * on and the code still carries the example. Resolves once the preview
	 * carries the real one; rejects, with the reason shown, when it cannot.
	 * Resolves with `{ url, title }` only when this call saved a record, so
	 * the caller can say so; with nothing when no record was written.
	 *
	 * @param {jQuery} $form Generator form.
	 * @returns {Promise<Object|undefined>}
	 */
	function ensureShort($form) {
		var state = current && current.short;
		if (!state || state.circular || !state.example || !wantShort) {
			return Promise.resolve();
		}
		var $status = $('#ffc-qr-generator-status');
		var $title = $form.find('#ffc-qr-short-title');
		var title = String($title.val() || '').trim();
		if (!title) {
			$title.addClass('is-invalid').trigger('focus');
			$status.removeClass('is-warning').addClass('is-error').text(i18n.titleRequired || '');
			return Promise.reject(new Error('title'));
		}
		var acknowledged = $form.find('#ffc-qr-short-ack').is(':checked');
		if ((state.duplicates || []).length && !acknowledged) {
			$status.removeClass('is-warning').addClass('is-error').text(i18n.acknowledge || '');
			return Promise.reject(new Error('acknowledge'));
		}
		var request = collect($form);
		return window.FFC.request(
			cfg.shorten,
			{ type: request.type, fields: request.fields, title: title, acknowledge: acknowledged ? '1' : '' },
			{ nonce: cfg.shortenNonce, ajaxUrl: cfg.ajaxUrl }
		)
			.then(function (data) {
				shortUrl = { code: String(data.short_code || ''), key: contentKey($form) };
				var saved = { url: String(data.short_url || ''), title: title };
				return refresh($form).then(function () { return saved; });
			})
			.catch(function (err) {
				$status.removeClass('is-warning').addClass('is-error').text(errorMessage(err));
				throw err;
			});
	}

	/**
	 * The overlay confirming that a short URL record was written, with the
	 * link ready to copy. Opened only after the server answered the create
	 * call with success; closed as soon as the content moves away from it.
	 *
	 * @param {Object|null} saved `{ url, title }` of the record, or null to close.
	 */
	function showSaved(saved) {
		var $saved = $('#ffc-qr-short-saved');
		if (!saved) {
			closeSaved();
			return;
		}
		$('#ffc-qr-short-saved-text').text(fill(i18n.saved, [saved.title]));
		$('#ffc-qr-short-saved-url').val(saved.url);
		$('#ffc-qr-short-saved-copied').text('');
		savedOpener = document.activeElement;
		$saved.prop('hidden', false);
		$('#ffc-qr-short-saved-copy').trigger('focus');
	}

	/**
	 * Close the overlay and give the focus back to what opened it.
	 */
	function closeSaved() {
		var $saved = $('#ffc-qr-short-saved');
		if ($saved.prop('hidden') !== false) {
			return;
		}
		$saved.prop('hidden', true);
		if (savedOpener && typeof savedOpener.focus === 'function') {
			savedOpener.focus();
		}
		savedOpener = null;
	}

	/**
	 * Copy text to the clipboard, with the old selection trick where the
	 * asynchronous API is unavailable (plain-HTTP sites).
	 *
	 * @param {string} text Text.
	 * @returns {Promise}
	 */
	function copy(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		var area = document.createElement('textarea');
		area.value = text;
		area.setAttribute('readonly', '');
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild(area);
		area.select();
		var ok = document.execCommand && document.execCommand('copy');
		area.remove();
		return ok ? Promise.resolve() : Promise.reject(new Error('copy'));
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
		} else if (checks.transparent) {
			$out.addClass('is-warning').text(i18n.transparent || '');
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
		var $buttons = $('#ffc-qr-download, #ffc-qr-print');

		return window.FFC.request(cfg.generate, collect($form), { nonce: cfg.generateNonce, ajaxUrl: cfg.ajaxUrl })
			.then(function (data) {
				if (mine !== sequence) {
					return;
				}
				current = data;
				$preview.html(String(data.svg || ''));
				showShortState($form, data.short || null);
				var usage = data.usage || {};
				$usage.text(usage.forced
					? fill(i18n.usageForced, [usage.percent, usage.remaining])
					: fill(i18n.usage, [usage.percent, usage.remaining, usage.level]));
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
				$status.removeClass('is-warning').addClass('is-error').text(errorMessage(err));
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

	/**
	 * Remember the current design for this user; best effort, silent.
	 *
	 * @param {jQuery} $form Generator form.
	 * @returns {Promise}
	 */
	function remember($form) {
		return window.FFC.request(cfg.remember, window.FFC.QrDesign.collect($form), { nonce: cfg.rememberNonce, ajaxUrl: cfg.ajaxUrl })
			.catch(function () {});
	}

	/**
	 * Put a design state into the design fields.
	 *
	 * @param {jQuery} $form Generator form.
	 * @param {Object} state Keys are the fields' `data-ffc-qr-design` names, plus margin and error_level.
	 * @param {string} thumb Logo thumbnail URL, '' for none.
	 */
	function apply($form, state, thumb) {
		Object.keys(state || {}).forEach(function (key) {
			var value = state[key];
			if (key === 'margin') {
				$form.find('#qr_default_margin').val(String(value));
				return;
			}
			if (key === 'error_level') {
				$form.find('#qr_default_error_level').val(String(value));
				return;
			}
			var $field = $form.find('[data-ffc-qr-design="' + key + '"]');
			if ($field.is(':checkbox')) {
				$field.prop('checked', !!value);
			} else if ($field.is(':radio')) {
				// A tile picker: check the tile of that value.
				$field.filter(function () { return this.value === String(value); }).prop('checked', true);
			} else {
				$field.val(String(value));
				$form.find('[data-ffc-qr-hex-for="' + key + '"]').val(String(value));
			}
		});
		$form.find('#ffc-qr-logo-thumb').attr('src', thumb || '').prop('hidden', !thumb);
	}

	/**
	 * Print just the code: a hidden frame holding the SVG alone, so the page
	 * around it never reaches the printer and no pop-up is opened.
	 *
	 * @param {string} svg SVG markup from the server.
	 * @returns {HTMLIFrameElement}
	 */
	function print(svg) {
		var frame = document.createElement('iframe');
		frame.setAttribute('aria-hidden', 'true');
		frame.setAttribute('tabindex', '-1');
		frame.style.position = 'fixed';
		frame.style.width = '0';
		frame.style.height = '0';
		frame.style.border = '0';
		document.body.appendChild(frame);

		var doc = frame.contentWindow.document;
		doc.open();
		doc.write('<!doctype html><html><head><title>QR</title><style>@page{margin:15mm}html,body{margin:0}body{display:flex;justify-content:center}svg{width:80mm;height:auto}</style></head><body>' + svg + '</body></html>');
		doc.close();

		var win = frame.contentWindow;
		win.focus();
		win.print();
		// Removed on the next turn: some browsers print asynchronously.
		setTimeout(function () { frame.remove(); }, 1000);
		return frame;
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
		// A redraw still pending from an earlier form must not fire against this
		// one: it would collect the old fields and drop the short URL state.
		clearTimeout(timer);
		timer = null;
		shortUrl = { code: '', key: '' };
		savedOpener = null;
		wantShort = !$form.find('#ffc-qr-short').length || $form.find('#ffc-qr-short').is(':checked');

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
		$form.on('change', '[data-ffc-qr-field="wifi:security"]', function () {
			showWifiEnterprise($form);
		});

		// Size applies to the PNG only: an SVG has no pixels to choose.
		$('#ffc-qr-format').on('change', function () {
			$('#ffc-qr-png-width').prop('disabled', $(this).val() === 'svg');
		});
		$('#ffc-qr-download').on('click', function () {
			if (!current || !current.svg) {
				return;
			}
			// Read at the click: the short URL is created asynchronously, and
			// the file must be the one asked for, not whatever is selected later.
			var format = $('#ffc-qr-format').val();
			var width = Number($('#ffc-qr-png-width').val()) || 1000;
			ensureShort($form).then(function (saved) {
				if (saved) {
					showSaved(saved);
				}
				if (!current || !current.svg) {
					return;
				}
				if (format === 'svg') {
					window.FFC.QrRaster.download(window.FFC.QrRaster.encode(current.svg), filename($form, 'svg'), 'image/svg+xml');
					remember($form);
					return;
				}
				return window.FFC.QrRaster.toPng(window.FFC.QrRaster.encode(current.svg), width)
					.then(function (png) {
						window.FFC.QrRaster.download(png, filename($form, 'png'), 'image/png');
						remember($form);
					})
					.catch(function () { $('#ffc-qr-generator-status').addClass('is-error').text(i18n.error || ''); });
			}).catch(function () {});
		});
		$('#ffc-qr-print').on('click', function () {
			if (!current || !current.svg) {
				return;
			}
			ensureShort($form).then(function (saved) {
				if (saved) {
					showSaved(saved);
				}
				if (current && current.svg) {
					print(current.svg);
				}
			}).catch(function () {});
		});

		$form.on('change', '#ffc-qr-short', function () {
			wantShort = this.checked;
			showShort($form);
			refresh($form);
		});
		$form.on('input', '#ffc-qr-short-title', function () {
			$(this).removeClass('is-invalid');
			schedule($form);
		});
		$form.on('click', '.ffc-qr-short__use', function () {
			shortUrl = { code: String($(this).attr('data-code') || ''), key: contentKey($form) };
			refresh($form);
		});
		$(document).off('.ffcQrSaved')
			.on('click.ffcQrSaved', '[data-ffc-qr-saved-close]', closeSaved)
			.on('keydown.ffcQrSaved', function (e) {
				if (e.key === 'Escape') {
					closeSaved();
				}
			})
			.on('click.ffcQrSaved', '#ffc-qr-short-saved-copy', function () {
				var $copied = $('#ffc-qr-short-saved-copied');
				copy(String($('#ffc-qr-short-saved-url').val() || ''))
					.then(function () { $copied.text(i18n.copied || ''); })
					.catch(function () { $copied.text(i18n.copyFailed || ''); });
			});
		$form.on('click', '#ffc-qr-short-result', function () {
			var $copied = $form.find('#ffc-qr-short-copied');
			copy(String($(this).text() || ''))
				.then(function () { $copied.text(i18n.copied || ''); })
				.catch(function () { $copied.text(i18n.copyFailed || ''); });
		});

		$('#ffc-qr-design-reset').on('click', function () {
			var $btn = $(this);
			$btn.prop('disabled', true);
			window.FFC.request(cfg.remember, { reset: '1' }, { nonce: cfg.rememberNonce, ajaxUrl: cfg.ajaxUrl })
				.then(function (data) {
					$btn.prop('disabled', false);
					apply($form, data.state, String(data.logo_thumb || ''));
					return refresh($form).then(function () {
						$('#ffc-qr-generator-status').text(i18n.reset || '');
					});
				})
				.catch(function (err) {
					$btn.prop('disabled', false);
					$('#ffc-qr-generator-status').addClass('is-error').text(errorMessage(err));
				});
		});

		showType($form);
	}

	window.FFC = window.FFC || {};
	window.FFC.QrGenerator = { init: init, collect: collect, refresh: refresh, fill: fill, apply: apply, remember: remember, print: print, ensureShort: ensureShort, errorMessage: errorMessage };

	$(init);
})(jQuery);
