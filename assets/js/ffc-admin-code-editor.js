/**
 * FFC — CodeMirror initializer for the certificate HTML template textarea.
 *
 * Wraps the native #ffc_pdf_layout textarea with WordPress's bundled CodeMirror
 * so admins get HTML syntax highlighting plus a custom overlay that paints
 * `{{placeholder}}` tokens in a distinct color.
 *
 * The underlying textarea is preserved and its value continues to be synced on
 * every change, so form submission behaviour is byte-for-byte identical to the
 * plain-textarea path. If `wp_enqueue_code_editor()` returned false (the user
 * disabled Syntax Highlighting in their profile) we render a subtle inline
 * notice and do nothing else — the textarea remains fully functional.
 *
 * @since 5.4.1
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $textarea = $( '#ffc_pdf_layout' );
		if ( ! $textarea.length ) {
			return;
		}

		var config = window.ffcCodeEditor || {};

		if ( ! config.enabled || ! config.settings || 'undefined' === typeof window.wp || ! window.wp.codeEditor ) {
			renderDisabledNotice( $textarea, config );
			return;
		}

		var placeholderOverlay = {
			token: function ( stream ) {
				if ( stream.match( /\{\{[^}]+\}\}/ ) ) {
					return 'ffc-placeholder-token';
				}
				while ( stream.next() != null ) {
					if ( stream.eol() ) {
						break;
					}
					if ( stream.peek() === '{' ) {
						break;
					}
				}
				return null;
			},
		};

		var editor;
		try {
			editor = window.wp.codeEditor.initialize( 'ffc_pdf_layout', config.settings );
		} catch ( err ) {
			renderDisabledNotice( $textarea, config );
			return;
		}

		if ( ! editor || ! editor.codemirror ) {
			return;
		}

		var cm = editor.codemirror;
		cm.addOverlay( placeholderOverlay );

		// Keep the underlying textarea value in sync so the form submit carries
		// the current editor content even if the browser skips CodeMirror's own
		// save hook.
		cm.on( 'change', function () {
			cm.save();
		} );

		$textarea.closest( 'form' ).on( 'submit', function () {
			cm.save();
		} );
	} );

	function renderDisabledNotice( $textarea, config ) {
		if ( $textarea.data( 'ffc-syntax-notice-rendered' ) ) {
			return;
		}
		$textarea.data( 'ffc-syntax-notice-rendered', true );

		var strings    = ( config && config.strings ) || {};
		var profileUrl = ( config && config.profileUrl ) || '';
		var notice     = $( '<p class="description ffc-code-editor-notice"></p>' );

		notice.text( strings.syntaxDisabledNotice || '' );
		if ( profileUrl ) {
			notice
				.append( document.createTextNode( ' ' ) )
				.append(
					$( '<a>' )
						.attr( 'href', profileUrl )
						.text( strings.openProfile || profileUrl )
				);
		}
		$textarea.after( notice );
	}
} )( jQuery );
