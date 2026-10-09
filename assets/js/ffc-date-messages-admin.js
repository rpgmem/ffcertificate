/**
 * Date Messages admin screen (#1538): recipient preview, message preview,
 * test send, the body appearance controls (#1660) and the confirmations on
 * destructive forms.
 *
 * The preview posts either the editor's unsaved values (`data-ffc-dm-preview
 * ="form"`) or a saved rule's id (`="saved"`, the Send now tab) and renders
 * what the server returns: totals per decision, and the people by name only
 * when the server sent them -- the server decides that from the operator's
 * capability, never this script. Every value is written with `.text()`.
 * Selector-guarded: a no-op on any other screen.
 */
jQuery(function ($) {
	'use strict';

	var cfg = window.ffcDateMessages || {};
	var strings = cfg.strings || {};
	var labels = cfg.decisions || {};

	$(document).on('submit', 'form[data-ffc-confirm]', function (e) {
		if (!window.confirm(String($(this).data('ffc-confirm')))) {
			e.preventDefault();
		}
	});

	initAppearance();

	if (!$('.ffc-dm-preview').length || !window.FFC || typeof window.FFC.request !== 'function') {
		return;
	}

	function formPayload($box) {
		if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
			window.tinymce.triggerSave();
		}
		var rule = $('#ffc-dm-rule-form').find(':input[name^="rule["]').serialize();
		return rule
			+ '&rule_id=' + encodeURIComponent(String($('#ffc-dm-rule-form').find('[name="rule[id]"]').val() || '0'))
			+ '&from=' + encodeURIComponent(String($box.find('.ffc-dm-from').val() || ''))
			+ '&to=' + encodeURIComponent(String($box.find('.ffc-dm-to').val() || ''));
	}

	function savedPayload($box) {
		return {
			rule_id: String($box.find('.ffc-dm-rule').val() || ''),
			from: String($box.find('.ffc-dm-from').val() || ''),
			to: String($box.find('.ffc-dm-to').val() || ''),
		};
	}

	function payload($box) {
		return 'form' === String($box.data('ffc-dm-preview')) ? formPayload($box) : savedPayload($box);
	}

	function message($out, text, isError) {
		$out.empty().append(
			$('<div class="notice inline"></div>')
				.addClass(isError ? 'notice-error' : 'notice-success')
				.append($('<p></p>').text(text))
		);
	}

	function render($out, data) {
		var totals = data.totals || {};
		var $list = $('<ul></ul>');
		Object.keys(labels).forEach(function (key) {
			$list.append($('<li></li>').text(labels[key] + ': ' + (totals[key] || 0)));
		});
		$out.empty().append($list);

		var rows = Array.isArray(data.rows) ? data.rows : [];
		if (!data.pii) {
			return;
		}
		if (!rows.length) {
			$out.append($('<p></p>').text(strings.none || ''));
			return;
		}
		var $tbody = $('<tbody></tbody>');
		rows.forEach(function (row) {
			$tbody.append(
				$('<tr></tr>')
					.append($('<td></td>').text(row.name || ''))
					.append($('<td></td>').text(row.email || ''))
					.append($('<td></td>').text(row.date || ''))
					.append($('<td></td>').text(labels[row.decision] || row.decision || ''))
			);
		});
		$out.append($('<table class="widefat striped"></table>').append($tbody));
		if (data.truncated) {
			$out.append($('<p class="description"></p>').text(strings.truncated || ''));
		}
	}

	function run($button, action, nonce, onSuccess) {
		var $box = $button.closest('.ffc-dm-preview');
		var $out = $box.find('.ffc-dm-preview-result');
		$button.prop('disabled', true);
		$out.empty().append($('<p></p>').text(strings.loading || ''));

		window.FFC.request(action, payload($box), { nonce: nonce, ajaxUrl: cfg.ajaxUrl })
			.then(function (data) {
				onSuccess($out, data || {});
			})
			.catch(function (err) {
				message($out, (err && err.message) || strings.failed || '', true);
			})
			.then(function () {
				$button.prop('disabled', false);
			});
	}

	$(document).on('click', '.ffc-dm-preview-button', function () {
		run($(this), cfg.previewAction, cfg.previewNonce, render);
	});

	$(document).on('click', '.ffc-dm-message-button', function () {
		run($(this), cfg.messageAction, cfg.messageNonce, function ($out, data) {
			var $box = $out.closest('.ffc-dm-preview');
			var $panel = $box.find('.ffc-dm-message');
			$out.empty();
			$panel.find('.ffc-dm-message-subject').text(data.subject || '');
			// The e-mail is a whole document of its own; srcdoc keeps it out
			// of this page, and the frame's empty sandbox runs nothing in it.
			$panel.find('.ffc-dm-message-frame').attr('srcdoc', String(data.html || ''));
			$panel.removeClass('ffc-hidden');
		});
	});

	$(document).on('click', '.ffc-dm-message-size', function () {
		var $button = $(this);
		$button.closest('.ffc-dm-message').find('.ffc-dm-message-frame').attr('width', String($button.data('width')));
		$button.siblings('.ffc-dm-message-size').attr('aria-pressed', 'false');
		$button.attr('aria-pressed', 'true');
	});

	$(document).on('click', '.ffc-dm-test-button', function () {
		run($(this), cfg.testAction, cfg.testNonce, function ($out, data) {
			message($out, data.message || '', false);
		});
	});

	/**
	 * Relative luminance of a #rgb / #rrggbb colour (WCAG 2), or null.
	 *
	 * @param {string} hex Colour.
	 * @return {number|null}
	 */
	function luminance(hex) {
		var m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(hex || '').trim());
		if (!m) {
			return null;
		}
		var h = m[1].length === 3 ? m[1].replace(/./g, '$&$&') : m[1];
		var channels = [0, 2, 4].map(function (i) {
			var c = parseInt(h.substr(i, 2), 16) / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
		});
		return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
	}

	/**
	 * WCAG contrast ratio of two colours, rounded to one decimal, or null.
	 *
	 * @param {string} a First colour.
	 * @param {string} b Second colour.
	 * @return {number|null}
	 */
	function contrast(a, b) {
		var la = luminance(a);
		var lb = luminance(b);
		if (null === la || null === lb) {
			return null;
		}
		return Math.round(((Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)) * 10) / 10;
	}

	/**
	 * Body appearance card (#1660): image picker, colours, live contrast,
	 * and the fallback colour required once an image is chosen. The server
	 * validates all of it again; this only keeps the form honest early.
	 */
	function initAppearance() {
		var $card = $('.ffc-dm-appearance');
		if (!$card.length) {
			return;
		}
		var $id = $card.find('.ffc-dm-image-id');
		var $fallback = $card.find('.ffc-dm-fallback');
		var $text = $card.find('.ffc-dm-text');
		var $contrast = $card.find('.ffc-dm-contrast');

		function update() {
			var hasImage = parseInt($id.val(), 10) > 0;
			$fallback.prop('required', hasImage);
			$card.find('.ffc-dm-image-preview, .ffc-dm-image-remove').toggleClass('ffc-hidden', !hasImage);

			var ratio = contrast(
				String($text.val() || '') || String($card.data('model-text') || ''),
				String($fallback.val() || '') || String($card.data('model-bg') || '')
			);
			if (null === ratio) {
				$contrast.text('');
				return;
			}
			var shown = String(ratio).replace('.', String(cfg.decimal || '.'));
			$contrast.text(String($contrast.data(ratio >= 4.5 ? 'ok' : 'low')).replace('%s', shown));
		}

		if (typeof $.fn.wpColorPicker === 'function') {
			$card.find('.ffc-dm-color').wpColorPicker({ change: function () { setTimeout(update, 0); }, clear: function () { setTimeout(update, 0); } });
		}
		$card.on('input change', '.ffc-dm-color', update);

		$card.on('click', '.ffc-dm-image-choose', function (e) {
			e.preventDefault();
			if (!window.wp || !window.wp.media) {
				return;
			}
			var $button = $(this);
			var frame = window.wp.media({
				title: String($button.data('title') || ''),
				button: { text: String($button.data('button') || '') },
				library: { type: 'image' },
				multiple: false,
			});
			frame.on('select', function () {
				var item = frame.state().get('selection').first().toJSON();
				var thumb = item.sizes && item.sizes.medium ? item.sizes.medium.url : item.url;
				var meta = [item.filename, item.width + ' × ' + item.height];
				if (item.filesizeHumanReadable) {
					meta.push(item.filesizeHumanReadable);
				}
				$id.val(String(item.id));
				$card.find('.ffc-dm-image-thumb').attr('src', thumb);
				$card.find('.ffc-dm-image-url').val(item.url);
				$card.find('.ffc-dm-image-meta').text(meta.join(' · '));
				update();
			});
			frame.open();
		});

		$card.on('click', '.ffc-dm-image-remove', function (e) {
			e.preventDefault();
			$id.val('0');
			$card.find('.ffc-dm-image-thumb').attr('src', '');
			$card.find('.ffc-dm-image-url').val('');
			$card.find('.ffc-dm-image-meta').text('');
			update();
		});

		update();
	}
});
