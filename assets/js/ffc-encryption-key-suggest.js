/**
 * FFC_ENCRYPTION_KEY suggestion generator (#851).
 *
 * Renders a ready-to-paste `define( 'FFC_ENCRYPTION_KEY', '…' )` snippet into
 * the Encryption Key Health card (Settings → Advanced) and regenerates it on
 * demand. The key is produced in the browser with `crypto.getRandomValues`
 * and is NEVER persisted or sent to the server — the plugin never writes
 * wp-config.php (the S7a decision), this only helps an admin *adopt* a
 * decoupled key by hand.
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
	 * Wrap a key in the wp-config.php define snippet.
	 *
	 * @param {string} key The key.
	 * @return {string} The `define( … );` line.
	 */
	function buildSnippet( key ) {
		return "define( 'FFC_ENCRYPTION_KEY', '" + key + "' );";
	}

	/**
	 * Wire the card controls, if present on the page.
	 */
	function wire() {
		var input = document.getElementById( 'ffc-enc-key-suggestion' );
		if ( ! input ) {
			return;
		}
		var regen = document.getElementById( 'ffc-enc-key-regenerate' );
		var copy = document.getElementById( 'ffc-enc-key-copy' );
		var status = document.getElementById( 'ffc-enc-key-copy-status' );
		var strings = window.ffcEncKeySuggest || {};

		function refresh() {
			input.value = buildSnippet( generateKey( KEY_LENGTH ) );
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
