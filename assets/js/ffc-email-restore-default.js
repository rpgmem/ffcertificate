/**
 * Generic "Restore Default Text" wiring for email-body editors (admin).
 *
 * Any `<button class="ffc-email-restore-default" data-editor="<id>"
 * data-default-key="<key>">` restores its wp_editor's content (after a confirm)
 * to the default supplied via wp_localize_script as
 * `ffcEmailRestoreDefaults[<key>] = { body, confirm }`. Works in both TinyMCE
 * (Visual) and plain-textarea (Text) modes. Selector-guarded, so it no-ops on
 * screens without such a button. Reused across the recruitment and
 * self-scheduling template editors (#662).
 */
/* global ffcEmailRestoreDefaults */
jQuery(function ($) {
	'use strict';

	var cfg = window.ffcEmailRestoreDefaults || {};

	$('.ffc-email-restore-default').on('click', function () {
		var $btn = $(this);
		var editorId = String($btn.data('editor') || '');
		var key = String($btn.data('default-key') || '');
		var entry = cfg[key] || {};
		var body = typeof entry.body === 'string' ? entry.body : '';
		var confirmMsg = entry.confirm || '';

		if (!editorId) {
			return;
		}
		if (confirmMsg && !window.confirm(confirmMsg)) {
			return;
		}

		// Prefer the active TinyMCE instance (Visual mode); fall back to the
		// raw textarea (Text mode, or TinyMCE not initialised).
		var editor = window.tinymce && window.tinymce.get(editorId);
		if (editor && !editor.isHidden()) {
			editor.setContent(body);
			editor.save();
		} else {
			$('#' + editorId).val(body);
		}
	});
});
