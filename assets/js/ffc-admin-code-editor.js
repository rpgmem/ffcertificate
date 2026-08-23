/**
 * FFC — CodeMirror initializer for the single certificate HTML template textarea.
 *
 * Thin caller over the shared `window.FFCCodeEditor.init()`
 * ({@see assets/js/ffc-code-editor-core.js}): mounts CodeMirror on the textarea
 * whose id is supplied via `ffcCodeEditor.textareaId` (the form editor's
 * `ffc_pdf_layout` or the cert-template CPT edit screen's `ffc_template_html`),
 * with the `{{placeholder}}` overlay, the theme wrapper class and the
 * change/submit sync all owned by the core. When Syntax Highlighting is
 * disabled, the core renders the fallback notice and the plain textarea keeps
 * working.
 *
 * @since 5.4.1
 */
( function () {
	'use strict';

	jQuery( function () {
		var config = window.ffcCodeEditor || {};

		// The textarea id is supplied by the enqueuing screen (the form editor
		// uses `ffc_pdf_layout`; the cert-template CPT edit screen uses
		// `ffc_template_html`). Fall back to the form-editor id for safety.
		window.FFCCodeEditor.init(
			config.textareaId || 'ffc_pdf_layout',
			config.enabled ? config.settings : null,
			{
				theme: config.theme,
				notice: {
					strings: config.strings,
					profileUrl: config.profileUrl,
				},
			}
		);
	} );
} )();
