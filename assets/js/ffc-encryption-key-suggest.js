/**
 * FFC decoupling-constant suggestion generator (#851, salt field added later).
 *
 * Renders ready-to-paste `define( '<CONST>', '…' )` snippets into the
 * Encryption Key Health card (Settings → Advanced) — one widget per decoupling
 * constant (`FFC_ENCRYPTION_KEY`, `FFC_HASH_SALT`) — and regenerates them on
 * demand. Each value is produced in the browser with `crypto.getRandomValues`
 * and is NEVER persisted or sent to the server — the plugin never writes
 * wp-config.php (the S7a decision), this only helps an admin *adopt* the
 * decoupled secrets by hand.
 *
 * Exposes the pure generator on `window.FFCEncryptionKeySuggest` for testing.
 */
( function () {
	// Safe charset for a PHP single-quoted string literal: alphanumerics plus
	// punctuation that needs no escaping — deliberately EXCLUDING ' " \ $ (and
	// backtick / space) so the value drops into wp-config.php verbatim.
	var CHARSET =
		'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789' +
		'!#%&()*+,-./:;<=>?@[]^_{|}~';

	// Well above the 32-char minimum enforced by Encryption::key_health().
	var KEY_LENGTH = 64;

	// Default constant when a widget doesn't declare one (back-compat).
	var DEFAULT_CONST = 'FFC_ENCRYPTION_KEY';

	/**
	 * Generate a random key of the given length over the safe charset.
	 *
	 * @param {number} [length] Desired length; defaults to KEY_LENGTH.
	 * @return {string} The random key.
	 */
	function generateKey( length ) {
		var n = length > 0 ? length : KEY_LENGTH;
		var crypto = window.crypto || window.msCrypto;
		var bytes = new Uint32Array( n );
		crypto.getRandomValues( bytes );
		var out = '';
		for ( var i = 0; i < n; i++ ) {
			out += CHARSET.charAt( bytes[ i ] % CHARSET.length );
		}
		return out;
	}

	/**
	 * Wrap a key in the wp-config.php define snippet, optionally prefixed with a
	 * phpdoc comment that identifies the constant and its purpose (mirrors the
	 * commented WordPress salt block).
	 *
	 * @param {string} key         The key.
	 * @param {string} [constName] The constant to define; defaults to
	 *                             FFC_ENCRYPTION_KEY.
	 * @param {string} [comment]   Explanatory comment body; `\n`-separated lines
	 *                             are each rendered as a ` * …` phpdoc line.
	 * @return {string} The (optionally commented) `define( … );` snippet.
	 */
	function buildSnippet( key, constName, comment ) {
		var out = '';
		if ( comment ) {
			out += '/**\n';
			var lines = String( comment ).split( '\n' );
			for ( var i = 0; i < lines.length; i++ ) {
				out += ' * ' + lines[ i ] + '\n';
			}
			out += ' */\n';
		}
		return out + "define( '" + ( constName || DEFAULT_CONST ) + "', '" + key + "' );";
	}

	/**
	 * Wire a single suggestion widget.
	 *
	 * @param {Element} widget  The `.ffc-key-suggest-widget` container.
	 * @param {Object}  strings Localized copy-feedback strings.
	 */
	function wireWidget( widget, strings ) {
		var input = widget.querySelector( '.ffc-key-suggest-input' );
		if ( ! input ) {
			return;
		}
		var constName = widget.getAttribute( 'data-ffc-const' ) || DEFAULT_CONST;
		var regen = widget.querySelector( '.ffc-key-suggest-regenerate' );
		var copy = widget.querySelector( '.ffc-key-suggest-copy' );
		var status = widget.querySelector( '.ffc-key-suggest-status' );
		var defs = strings.defs || {};
		var comment = defs[ constName ] || '';

		function refresh() {
			input.value = buildSnippet( generateKey( KEY_LENGTH ), constName, comment );
			if ( status ) {
				status.textContent = '';
			}
		}

		refresh();

		if ( regen ) {
			regen.addEventListener( 'click', refresh );
		}

		if ( copy ) {
			copy.addEventListener( 'click', function () {
				input.focus();
				input.select();
				var ok = false;
				try {
					ok = document.execCommand( 'copy' );
				} catch ( e ) {
					ok = false;
				}
				if ( status ) {
					status.textContent = ok
						? strings.copied || 'Copied to clipboard.'
						: strings.copyFail || 'Press Ctrl/Cmd+C to copy.';
				}
			} );
		}
	}

	/**
	 * Wire every suggestion widget present on the page, if any.
	 */
	function wire() {
		var widgets = document.querySelectorAll( '.ffc-key-suggest-widget' );
		if ( ! widgets.length ) {
			return;
		}
		var strings = window.ffcEncKeySuggest || {};
		for ( var i = 0; i < widgets.length; i++ ) {
			wireWidget( widgets[ i ], strings );
		}
	}

	window.FFCEncryptionKeySuggest = {
		generateKey: generateKey,
		buildSnippet: buildSnippet,
		CHARSET: CHARSET,
		KEY_LENGTH: KEY_LENGTH
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', wire );
	} else {
		wire();
	}
}() );
