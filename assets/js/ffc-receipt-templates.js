/**
 * FFC — Appointment-receipt templates tab (Scheduling Settings → Receipt, #945).
 *
 * Per scheduling mode (Regular / Custom): a dropdown selects which pool template
 * the comprovante PDF uses, and an inline CodeMirror editor edits that template's
 * HTML. Shipped/default templates are read-only; "Duplicate to edit" creates an
 * editable copy via AJAX and selects it. Changing the dropdown live-loads the
 * chosen template's HTML.
 *
 * @since 6.20.0
 */
( function ( $ ) {
	'use strict';

	var cfg = window.ffcReceiptTemplates || {};

	$( function () {
		var modes   = cfg.modes || [ 'regular', 'custom' ];
		var editors = {};

		modes.forEach( function ( mode ) {
			var editorId = 'ffc_receipt_' + mode + '_editor';
			var $ta      = $( '#' + editorId );
			if ( ! $ta.length ) {
				return;
			}

			var cm = window.FFCCodeEditor.init( editorId, cfg.editorSettings, {
				theme: cfg.theme,
				notice: {
					strings: cfg.strings,
					profileUrl: cfg.profileUrl,
				},
			} );
			if ( cm ) {
				editors[ mode ] = cm;
				// The server rendered `readonly` for non-editable selections.
				setReadOnly( cm, $ta.prop( 'readonly' ) );
			}

			// Dropdown change → live-load the chosen template's HTML.
			$( '#ffc_receipt_' + mode ).on( 'change', function () {
				loadTemplate( mode, parseInt( $( this ).val(), 10 ) || 0, editors );
			} );

			// Duplicate the currently-selected template into an editable copy.
			$( '.ffc-receipt-duplicate[data-mode="' + mode + '"]' ).on( 'click', function () {
				duplicateTemplate( mode, editors );
			} );
		} );
	} );

	/**
	 * Toggle a CodeMirror instance's read-only state.
	 *
	 * @param {Object}  cm       CodeMirror instance.
	 * @param {boolean} readonly Whether the editor is read-only.
	 * @return {void}
	 */
	function setReadOnly( cm, readonly ) {
		cm.setOption( 'readOnly', readonly ? 'nocursor' : false );
		$( cm.getWrapperElement() ).toggleClass( 'ffc-editor-readonly', !! readonly );
	}

	/**
	 * Reflect an editable/read-only state across the editor, note and duplicate
	 * button for a mode.
	 *
	 * @param {string}  mode     Scheduling mode.
	 * @param {Object}  cm       CodeMirror instance.
	 * @param {boolean} editable Whether the selected template is editable.
	 * @return {void}
	 */
	function applyEditable( mode, cm, editable ) {
		setReadOnly( cm, ! editable );
		var strings = cfg.strings || {};
		$( '.ffc-receipt-note[data-mode="' + mode + '"]' ).text(
			editable ? ( strings.editableNote || '' ) : ( strings.readonlyNote || '' )
		);
		// The duplicate button only makes sense for a read-only selection.
		$( '.ffc-receipt-duplicate[data-mode="' + mode + '"]' ).toggle( ! editable );
	}

	/**
	 * Load a template's HTML into the mode's editor via AJAX.
	 *
	 * @param {string} mode    Scheduling mode.
	 * @param {number} id      Template id (0 = shipped default).
	 * @param {Object} editors Map of mode → CodeMirror.
	 * @return {void}
	 */
	function loadTemplate( mode, id, editors ) {
		var cm = editors[ mode ];
		if ( ! cm ) {
			return;
		}
		$.post( cfg.ajaxUrl, {
			action: cfg.loadAction,
			nonce: cfg.nonce,
			id: id,
		} )
			.done( function ( res ) {
				if ( res && res.success && res.data ) {
					cm.setValue( res.data.html || '' );
					cm.save();
					applyEditable( mode, cm, !! res.data.editable );
				}
			} );
	}

	/**
	 * Duplicate the mode's currently-selected template into an editable copy.
	 *
	 * @param {string} mode    Scheduling mode.
	 * @param {Object} editors Map of mode → CodeMirror.
	 * @return {void}
	 */
	function duplicateTemplate( mode, editors ) {
		var $select = $( '#ffc_receipt_' + mode );
		var id      = parseInt( $select.val(), 10 ) || 0;

		$.post( cfg.ajaxUrl, {
			action: cfg.dupAction,
			nonce: cfg.nonce,
			id: id,
		} )
			.done( function ( res ) {
				if ( ! res || ! res.success || ! res.data ) {
					window.alert( ( cfg.strings && cfg.strings.dupFailed ) || 'Error' );
					return;
				}
				// Add + select the new editable copy.
				$( '<option>' )
					.attr( 'value', res.data.id )
					.attr( 'data-default', '0' )
					.text( res.data.label )
					.appendTo( $select );
				$select.val( String( res.data.id ) );

				var cm = editors[ mode ];
				if ( cm ) {
					cm.setValue( res.data.html || '' );
					cm.save();
					applyEditable( mode, cm, true );
				}
			} )
			.fail( function () {
				window.alert( ( cfg.strings && cfg.strings.dupFailed ) || 'Error' );
			} );
	}
} )( jQuery );
