/**
 * Per-form certificate email — Global/Custom toggle behaviour (#964).
 *
 * The form editor's email metabox renders a `.ffc-toggle` (`#ffc_email_custom_mode`)
 * that chooses between the shared GLOBAL default text and a per-form CUSTOM copy.
 * The toggle is UI-only — the effective mode is re-derived server-side from the
 * stored subject/body on every load, so nothing here needs to be persisted:
 *
 *  - Flip to CUSTOM: reveal the subject + body fields; if either is empty, seed
 *    it with the current effective global so the operator edits from real text.
 *  - Flip to GLOBAL: confirm, then clear the subject + body (an empty stored
 *    value makes the send path fall back to the global) and hide the fields. On
 *    cancel, the toggle snaps back to Custom.
 *
 * Global values + the confirm string come from `window.ffcCertEmailGlobal`
 * (localised by FormEditorEmailMetabox). The body lives in a teeny `wp_editor`
 * (`#ffc_email_body`); we drive TinyMCE when present and fall back to the raw
 * textarea in the Text tab.
 */
( function ( $ ) {
	'use strict';

	var EDITOR_ID = 'ffc_email_body';

	function bodyValue() {
		if ( window.tinymce && window.tinymce.get( EDITOR_ID ) ) {
			return window.tinymce.get( EDITOR_ID ).getContent();
		}
		return $( '#' + EDITOR_ID ).val() || '';
	}

	function setBodyValue( html ) {
		if ( window.tinymce && window.tinymce.get( EDITOR_ID ) ) {
			var ed = window.tinymce.get( EDITOR_ID );
			ed.setContent( html || '' );
			ed.save(); // sync to the underlying textarea so the POST carries it.
			return;
		}
		$( '#' + EDITOR_ID ).val( html || '' );
	}

	// True when the value is visually empty (blank, whitespace, or an empty
	// TinyMCE paragraph) — mirrors the server-side heuristic's "empty" test.
	function isBlank( value ) {
		return '' === String( value || '' ).replace( /<[^>]*>|&nbsp;|\s+/g, '' );
	}

	$( function () {
		var $toggle = $( '#ffc_email_custom_mode' );
		if ( ! $toggle.length ) {
			return;
		}

		var globals = window.ffcCertEmailGlobal || {};
		var $fields = $( '.ffc-cert-email-custom-fields' );
		var $note = $( '.ffc-cert-email-global-note' );
		var $subject = $( 'input[name="ffc_config[email_subject]"]' );

		function showCustom() {
			$fields.show();
			$note.hide();
			if ( isBlank( $subject.val() ) ) {
				$subject.val( globals.subject || '' );
			}
			if ( isBlank( bodyValue() ) ) {
				setBodyValue( globals.body || '' );
			}
		}

		function showGlobal() {
			$subject.val( '' );
			setBodyValue( '' );
			$fields.hide();
			$note.show();
		}

		$toggle.on( 'change', function () {
			if ( $( this ).is( ':checked' ) ) {
				showCustom();
				return;
			}
			var message = globals.confirmReset ||
				'Discard this form’s custom email text and use the shared global default instead?';
			if ( window.confirm( message ) ) {
				showGlobal();
			} else {
				// Revert — the operator kept the custom text.
				$( this ).prop( 'checked', true );
			}
		} );
	} );
}( jQuery ) );
