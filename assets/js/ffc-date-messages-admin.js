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

	// Asked in the plugin's dialog (#2535); on yes the form is sent again,
	// through the button that sent it, so its name and value still post.
	$(document).on('submit', 'form[data-ffc-confirm]', function (e) {
		var form = this;
		if (form.getAttribute('data-ffc-confirmed') === '1') {
			form.removeAttribute('data-ffc-confirmed');
			return;
		}
		e.preventDefault();
		var submitter = e.originalEvent && e.originalEvent.submitter;
		window.FFC.confirmDialog({ message: String($(form).data('ffc-confirm')), category: 'danger', icon: 'delete' }).then(function (agreed) {
			if (!agreed) {
				return;
			}
			form.setAttribute('data-ffc-confirmed', '1');
			if (typeof form.requestSubmit === 'function') {
				form.requestSubmit(submitter || undefined);
			} else {
				form.submit();
			}
		});
	});

	initAppearance();
	initKind();
	initSendRule();
	initFacts();

	if (!$('.ffc-dm-preview').length || !window.FFC || typeof window.FFC.request !== 'function') {
		return;
	}

	// The editor's "This rule" card (#2498) follows the form before it is
	// saved: each fact is read from the field that sets it. The server
	// renders the same values first, so this only keeps them in step.
	function initFacts() {
		var $card = $('.ffc-dm-facts');
		var $form = $('#ffc-dm-rule-form');
		if (!$card.length || !$form.length) {
			return;
		}
		var facts = strings.facts || {};

		function set(key, text) {
			$card.find('[data-ffc-dm-fact="' + key + '"]').text(text);
		}

		function count(single, plural, n) {
			return String(1 === n ? single : plural).replace('%d', String(n));
		}

		function apply() {
			set('kind', $('#ffc-dm-kind option:selected').text());
			set('source', $('#ffc-dm-source option:selected').text());

			var offset = parseInt(String($('#ffc-dm-offset').val() || '0'), 10) || 0;
			if (0 === offset) {
				set('offset', String(facts.onDate || ''));
			} else if (offset < 0) {
				set('offset', count(facts.dayBefore, facts.daysBefore, -offset));
			} else {
				set('offset', count(facts.dayAfter, facts.daysAfter, offset));
			}
			set('send-at', String($('#ffc-dm-send-at').val() || '') ? String(facts.atTime || '') : String(facts.byHand || ''));

			var audiences = $form.find('input[name="rule[audience_ids][]"]').length;
			set('audiences', 0 === audiences ? String(facts.everyone || '') : count(facts.audience, facts.audiences, audiences));

			set('digest', $('#ffc-dm-digest-enabled').is(':checked') ? $('#ffc-dm-digest-mode option:selected').text() : String(facts.off || ''));
		}

		$form.on('change input', ':input', apply);
		$form.on('ffc:transfer-list-change', apply);
		apply();
	}

	// The send tab asks for dates only when the chosen rule is dated (#1949).
	function initSendRule() {
		var $rule = $('.ffc-dm-rule');
		if (!$rule.length) {
			return;
		}
		function apply() {
			var kind = String($rule.find('option:selected').attr('data-ffc-dm-kind') || '');
			$('[data-ffc-dm-dated-only]').prop('hidden', 'announcement' === kind);
		}
		$rule.on('change', apply);
		apply();
	}

	// Switching the kind swaps the default text, when the subject still holds
	// the other kind's default: the subject is plain text, so that test is
	// reliable where the editor's HTML is not. The restore button follows the
	// kind, so "Restore default text" always means the right default.
	function initKind() {
		var $kind = $('#ffc-dm-kind');
		if (!$kind.length) {
			return;
		}
		var defaults = window.ffcDateMessageDefaults || {};
		var keys = { dated: 'date_message_body', announcement: 'date_message_announcement_body' };
		var $subject = $('#ffc-dm-subject');
		var $restore = $('.ffc-email-restore-default[data-editor="ffc_dm_body"]');

		function setBody(html) {
			var editor = window.tinymce && window.tinymce.get('ffc_dm_body');
			if (editor && !editor.isHidden()) {
				editor.setContent(html);
				editor.save();
			} else {
				$('#ffc_dm_body').val(html);
			}
		}

		// Rows that only apply to one kind follow the selector (#1949).
		function applyRows(kind) {
			$('[data-ffc-dm-kind-only]').each(function () {
				$(this).prop('hidden', $(this).attr('data-ffc-dm-kind-only') !== kind);
			});
		}

		function getBody() {
			var editor = window.tinymce && window.tinymce.get('ffc_dm_body');
			if (editor && !editor.isHidden()) {
				return String(editor.getContent());
			}
			return String($('#ffc_dm_body').val() || '');
		}

		// Whitespace aside, the editor may reformat the stored HTML.
		function same(a, b) {
			return String(a || '').replace(/\s+/g, '') === String(b || '').replace(/\s+/g, '');
		}

		$kind.on('change', function () {
			var kind = String($kind.val());
			var other = 'announcement' === kind ? 'dated' : 'announcement';
			var from = defaults[other];
			var to = defaults[kind];
			applyRows(kind);
			$restore.attr('data-default-key', keys[kind] || keys.dated);
			if (!from || !to) {
				return;
			}
			// The other kind's default text, untouched: swap it silently. An
			// edited text is the operator's work, replaced only when they say
			// so (#2336).
			var untouched = $subject.val() === from.subject && same(getBody(), from.body);
			if (untouched) {
				$subject.val(to.subject);
				setBody(to.body);
				return;
			}
			window.FFC.confirmDialog({
				message: String(strings.kindReplace || 'Replace the subject and the message with the default text of this kind? Your changes will be lost.'),
				category: 'caution',
				icon: 'restore'
			}).then(function (agreed) {
				if (agreed) {
					$subject.val(to.subject);
					setBody(to.body);
				}
			});
		});
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

	// An in-content callout: the `.ffc-notice` component, built by FFC.notice()
	// so the text lands as text (#1843).
	function message($out, text, isError) {
		$out.empty().append(
			window.FFC.notice(isError ? 'danger' : 'success', isError ? 'error' : 'success', text, '', { role: isError ? 'alert' : 'status' })
		);
	}

	// The kind of the rule the preview is about: the editor's selector, or
	// the chosen rule on Send now.
	function kindOf($box) {
		if ('form' === String($box.data('ffc-dm-preview'))) {
			return String($('#ffc-dm-kind').val() || 'dated');
		}
		return String($box.find('.ffc-dm-rule option:selected').attr('data-ffc-dm-kind') || 'dated');
	}

	function render($out, data) {
		var totals = data.totals || {};
		var $list = $('<ul></ul>');
		Object.keys(labels).forEach(function (key) {
			$list.append($('<li></li>').text(labels[key] + ': ' + (totals[key] || 0)));
		});
		// Counted inside Will receive; shown apart so the operator sees who the
		// opt-out no longer protects (#1949).
		if (totals.forced) {
			$list.append($('<li></li>').text((strings.forced || 'Forced') + ': ' + totals.forced));
		}
		$out.empty().append($list);

		var rows = Array.isArray(data.rows) ? data.rows : [];
		if (!data.pii) {
			return;
		}
		if (!rows.length) {
			// An announcement has no dates to fall on: nobody listed means
			// everyone in its audiences was reached or opted out.
			var announcement = 'announcement' === kindOf($out.closest('.ffc-dm-preview'));
			$out.append($('<p></p>').text((announcement ? strings.noneAnnounce : strings.none) || ''));
			return;
		}
		var $head = $('<tr></tr>');
		(Array.isArray(strings.columns) ? strings.columns : []).forEach(function (label) {
			$head.append($('<th scope="col"></th>').text(String(label)));
		});
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
		$out.append($('<table class="widefat striped"></table>').append($('<thead></thead>').append($head), $tbody));
		if (data.truncated) {
			$out.append($('<p class="description"></p>').text(strings.truncated || ''));
		}
	}

	function run($button, action, nonce, onSuccess) {
		var $box = $button.closest('.ffc-dm-preview');
		var $out = $box.find('.ffc-dm-preview-result');
		$button.prop('disabled', true).addClass('ffc-btn-loading');
		$out.empty().append($('<p></p>').text(strings.loading || ''));

		window.FFC.request(action, payload($box), { nonce: nonce, ajaxUrl: cfg.ajaxUrl })
			.then(function (data) {
				onSuccess($out, data || {});
			})
			.catch(function (err) {
				message($out, (err && err.message) || strings.failed || '', true);
			})
			.then(function () {
				$button.prop('disabled', false).removeClass('ffc-btn-loading');
			});
	}

	// Send now asks with the facts (#2336): the rule, the dates and how many
	// people it will reach, counted by the same selection the send uses (the
	// recipient preview). Confirmed, the form submits itself, once.
	$(document).on('submit', '#ffc-dm-send-form', function (e) {
		var form = this;
		if (form.getAttribute('data-ffc-dm-confirmed') === '1') {
			return;
		}
		e.preventDefault();
		var $box = $(form).find('.ffc-dm-preview');
		var $option = $box.find('.ffc-dm-rule option:selected');
		var name = String($option.text() || '');
		var announcement = 'announcement' === String($option.attr('data-ffc-dm-kind') || '');
		var summaryOnly = '1' === String($option.attr('data-ffc-dm-summary-only') || '');
		var from = String($box.find('.ffc-dm-from').val() || '');
		var to = String($box.find('.ffc-dm-to').val() || '');
		var range = announcement ? String(strings.today || 'today') : (from === to ? from : from + ' – ' + to);

		function ask(count) {
			var template = summaryOnly ? strings.confirmSummary : strings.confirmSend;
			var text = String(template || 'Send "%1$s" for %2$s to %3$s people?')
				.replace('%1$s', name)
				.replace('%2$s', range)
				.replace('%3$s', null === count ? '?' : String(count));
			window.FFC.confirmDialog({ message: text, confirmLabel: strings.send || '', icon: 'send' }).then(function (agreed) {
				if (agreed) {
					form.setAttribute('data-ffc-dm-confirmed', '1');
					form.submit();
				}
			});
		}

		window.FFC.request(cfg.previewAction, savedPayload($box), { nonce: cfg.previewNonce, ajaxUrl: cfg.ajaxUrl })
			.then(function (data) {
				var totals = (data && data.totals) || {};
				ask(Number(totals.will_send || 0));
			})
			.catch(function () {
				ask(null);
			});
	});

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
