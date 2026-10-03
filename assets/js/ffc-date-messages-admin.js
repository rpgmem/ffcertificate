/**
 * Date Messages admin screen (#1538): recipient preview, test send and the
 * confirmations on destructive forms.
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

	$(document).on('click', '.ffc-dm-test-button', function () {
		run($(this), cfg.testAction, cfg.testNonce, function ($out, data) {
			message($out, data.message || '', false);
		});
	});
});
