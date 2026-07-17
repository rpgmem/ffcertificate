/**
 * Form-editor email metabox UI wiring (admin).
 *
 * Adds the "Restore Default Text" button behaviour for the certificate email
 * body editor: on click (after a confirm), it replaces the current content
 * with the default template supplied via wp_localize_script. Works in both
 * TinyMCE (Visual) and plain-textarea (Text) modes. Selector-guarded, so it
 * no-ops on any screen that doesn't render the button.
 *
 * No server-side interpolation — the default body + confirm string arrive in
 * the localized `ffcEmailBodyDefault` object.
 */
jQuery(function ($) {
	var $button = $('#ffc-restore-default-email-body');
	if (!$button.length) {
		return;
	}

	var data = window.ffcEmailBodyDefault || {};
	var editorId = 'ffc_email_body';

	$button.on('click', function () {
		var body = typeof data.body === 'string' ? data.body : '';
		var confirmMsg = data.confirm || '';

		if (confirmMsg && !window.confirm(confirmMsg)) {
			return;
		}

		// Prefer the active TinyMCE instance (Visual mode); fall back to the
		// raw textarea (Text mode, or TinyMCE not initialised).
		var editor = window.tinymce && window.tinymce.get(editorId);
		if (editor && !editor.isHidden()) {
			editor.setContent(body);
			editor.save(); // Sync back into the underlying <textarea>.
		} else {
			$('#' + editorId).val(body);
		}
	});
});
