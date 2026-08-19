/**
 * FFC — Shared CodeMirror initializer for every plugin HTML editor.
 *
 * One place owns the CodeMirror wiring that every FFC template editor needs:
 * the `{{placeholder}}` token overlay, the theme wrapper class, the
 * change/submit → textarea sync, and the "enable Syntax Highlighting" fallback
 * notice. Callers — the single-textarea form-editor / cert-template wrapper in
 * `ffc-admin-code-editor.js` — init one *or many* textareas through
 * `window.FFCCodeEditor.init()` instead of each hand-rolling the same setup.
 *
 * The underlying textarea is preserved and kept in sync on every change, so
 * form submission is byte-for-byte identical to the plain-textarea path. When
 * `wp_enqueue_code_editor()` returned false (Syntax Highlighting disabled in the
 * user profile) a subtle inline notice is rendered and the plain textarea keeps
 * working.
 *
 * @since 6.20.0
 */
( function ( $ ) {
	'use strict';

	/**
	 * Overlay that paints `{{placeholder}}` tokens in a distinct color.
	 *
	 * @type {Object}
	 */
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

	/**
	 * Mount CodeMirror on a textarea and wire the shared behaviour.
	 *
	 * @param {string}      textareaId Id of the textarea to enhance.
	 * @param {Object|null} settings   `wp_enqueue_code_editor()` settings, or
	 *                                 null/false when Syntax Highlighting is off.
	 * @param {Object}      [opts]     Options.
	 * @param {string}      [opts.theme] Resolved theme ('light'|'dark') for the
	 *                                 wrapper class.
	 * @param {boolean}     [opts.placeholderOverlay=true] Paint `{{token}}` overlay.
	 * @param {Object}      [opts.notice] Disabled-state notice config.
	 * @param {Object}      [opts.notice.strings] Notice strings.
	 * @param {string}      [opts.notice.profileUrl] Link to the profile page.
	 * @return {Object|null} CodeMirror instance, or null when not initialized.
	 */
	function init( textareaId, settings, opts ) {
		opts = opts || {};
		var $textarea = $( '#' + textareaId );
		if ( ! $textarea.length ) {
			return null;
		}

		if ( ! settings || 'undefined' === typeof window.wp || ! window.wp.codeEditor ) {
			renderDisabledNotice( $textarea, opts.notice );
			return null;
		}

		var editor;
		try {
			editor = window.wp.codeEditor.initialize( textareaId, settings );
		} catch ( err ) {
			renderDisabledNotice( $textarea, opts.notice );
			return null;
		}

		if ( ! editor || ! editor.codemirror ) {
			return null;
		}

		var cm = editor.codemirror;

		if ( false !== opts.placeholderOverlay ) {
			cm.addOverlay( placeholderOverlay );
		}

		// Flag the wrapper so theme-dependent selectors (e.g. the dark-border
		// override) can target only the active theme.
		var $wrapper = $textarea.closest( '.ffc-code-editor-wrapper' );
		if ( $wrapper.length ) {
			$wrapper.addClass( 'ffc-code-editor-theme-' + ( opts.theme || 'light' ) );
		}

		// Keep the underlying textarea value in sync so the form submit carries
		// the current editor content even if the browser skips CodeMirror's own
		// save hook.
		cm.on( 'change', function () {
			cm.save();
		} );

		$textarea.closest( 'form' ).on( 'submit', function () {
			cm.save();
		} );

		return cm;
	}

	/**
	 * Render the subtle inline "enable Syntax Highlighting" fallback notice
	 * once, after the textarea.
	 *
	 * @param {Object} $textarea jQuery-wrapped textarea.
	 * @param {Object} [notice]  { strings, profileUrl }.
	 * @return {void}
	 */
	function renderDisabledNotice( $textarea, notice ) {
		if ( ! notice || $textarea.data( 'ffc-syntax-notice-rendered' ) ) {
			return;
		}
		$textarea.data( 'ffc-syntax-notice-rendered', true );

		var strings    = notice.strings || {};
		var profileUrl = notice.profileUrl || '';
		var $notice    = $( '<p class="description ffc-code-editor-notice"></p>' );

		$notice.text( strings.syntaxDisabledNotice || '' );
		if ( profileUrl ) {
			$notice
				.append( document.createTextNode( ' ' ) )
				.append(
					$( '<a>' )
						.attr( 'href', profileUrl )
						.text( strings.openProfile || profileUrl )
				);
		}
		$textarea.after( $notice );
	}

	/**
	 * Resolve the live CodeMirror instance mounted on a textarea, if any.
	 *
	 * WordPress's `wp.codeEditor` inserts a `.CodeMirror` element as the
	 * textarea's next sibling and hangs the instance off `el.CodeMirror`. These
	 * DOM helpers reach it by traversal (rather than a retained reference) so
	 * screens that only hold the textarea — the form-editor layout box and the
	 * cert-template edit screen — can flush/write through the one editor without
	 * threading the instance around.
	 *
	 * @param {Object} $textarea jQuery-wrapped textarea.
	 * @return {Object|null} CodeMirror instance, or null when none is mounted.
	 */
	function instanceOf( $textarea ) {
		var $cm = $textarea.nextAll( '.CodeMirror' ).first();
		return $cm.length && $cm[0].CodeMirror ? $cm[0].CodeMirror : null;
	}

	/**
	 * Flush the mounted CodeMirror buffer into its textarea so a following
	 * `.val()` read sees the latest edits. No-op when no editor is mounted (the
	 * textarea already carries its own value).
	 *
	 * @param {Object} $textarea jQuery-wrapped textarea.
	 * @return {void}
	 */
	function flush( $textarea ) {
		var cm = instanceOf( $textarea );
		if ( cm && 'function' === typeof cm.save ) {
			cm.save();
		}
	}

	/**
	 * Write content into the editor (and its textarea). Routes through the
	 * CodeMirror API when mounted — a direct `$textarea.val()` write is NOT
	 * reflected by the visible editor — and falls back to the plain textarea
	 * otherwise. Fires `change` either way.
	 *
	 * @param {Object} $textarea jQuery-wrapped textarea.
	 * @param {string} content   New content.
	 * @return {void}
	 */
	function setContent( $textarea, content ) {
		var cm = instanceOf( $textarea );
		if ( cm && 'function' === typeof cm.setValue ) {
			cm.setValue( content );
			cm.save();
		} else {
			$textarea.val( content );
		}
		$textarea.trigger( 'change' );
	}

	/**
	 * Insert a snippet at the editor's cursor (so it lands where the user is
	 * typing) via CodeMirror, or append to the plain textarea when no editor is
	 * mounted (JS-lite / tests).
	 *
	 * @param {Object} $textarea jQuery-wrapped textarea.
	 * @param {string} snippet   HTML to insert.
	 * @return {void}
	 */
	function insertAtCursor( $textarea, snippet ) {
		var cm = instanceOf( $textarea );
		if ( cm && 'function' === typeof cm.replaceSelection ) {
			cm.replaceSelection( snippet );
			cm.save();
			$textarea.trigger( 'change' );
			cm.focus();
			return;
		}
		setContent( $textarea, ( $textarea.val() || '' ) + snippet );
	}

	window.FFCCodeEditor = {
		init: init,
		flush: flush,
		setContent: setContent,
		insertAtCursor: insertAtCursor,
	};
} )( jQuery );
