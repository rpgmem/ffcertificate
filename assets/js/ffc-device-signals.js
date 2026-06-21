/**
 * FFC Device Signals
 *
 * Collects a multi-signal device fingerprint and a persistent UUID cookie,
 * SHA-256 hashes each signal independently, and writes the result as a
 * hidden `ffc_device_signals` JSON input on every FFC submission form
 * (selector: `form.ffc-submission-form`).
 *
 * The actual signal probes are delegated to the vendored thumbmarkjs
 * library (libs/js/thumbmark-1.9.1.umd.js, MIT) which exposes its raw
 * components via `getFingerprintData()`. We map those components 1:1
 * onto our 10-column SQL schema, hash each one independently with
 * SubtleCrypto SHA-256, and ship the JSON to the server. The server
 * algorithm (RateLimiter::check_device_limit, "N of M" rule) is
 * unchanged from 6.3.0.
 *
 * Telemetry note: thumbmarkjs ships with `logging:true` by default,
 * which sends a sampling beacon to api.thumbmarkjs.com 1× per session.
 * We *unconditionally* disable that here on first load via
 * `setOption('logging', false)`. A grep test enforces the call stays
 * (tests/Unit/DeviceSignalsLoggingOffTest.php).
 *
 * The set of signals to collect is provided server-side via
 * `wp_localize_script` as `ffc_device_config.signals` (e.g. ["cookie",
 * "ua", "screen", "tz", "concurrency", "memory", "canvas", "audio",
 * "webgl", "fonts", "plugins", "permissions", "mediaqueries", "math"]).
 * Disabled signals are simply not collected.
 *
 * Signal collection is best-effort: anything that throws or is
 * unsupported in the current browser is silently skipped — the server
 * applies the "N of M" matching rule on whatever subset arrives.
 *
 * @package FreeFormCertificate
 * @since   6.3.0
 * @since   6.3.1 Delegated probes to thumbmarkjs (MIT, vendored).
 */
(function () {
	'use strict';

	if ( typeof window.crypto === 'undefined' || typeof window.crypto.subtle === 'undefined' ) {
		// SubtleCrypto requires a secure context. If it's missing the limit
		// silently degrades to "no signals collected" -- the server treats
		// that as bypass-equivalent (no rows, no enforcement).
		return;
	}

	if ( typeof window.ThumbmarkJS === 'undefined' ) {
		// thumbmarkjs UMD is required; if it didn't load (network error,
		// CSP block, etc.) we fail open in the same "no signals" mode.
		return;
	}

	// Hard-disable thumbmarkjs telemetry. MUST run before the first
	// getFingerprintData() call. Removing this line is treated as a
	// regression and asserted by DeviceSignalsLoggingOffTest.
	window.ThumbmarkJS.setOption( 'logging', false );

	var config = window.ffc_device_config || {};
	var enabled = Array.isArray( config.signals ) ? config.signals : [];

	function isEnabled( name ) {
		return enabled.indexOf( name ) !== -1;
	}

	function getOrCreateDeviceId() {
		try {
			var existing = window.localStorage.getItem( 'ffc_device_id' );
			if ( existing && /^[0-9a-f-]{30,40}$/i.test( existing ) ) {
				return existing;
			}
			var fresh = generateUuid();
			window.localStorage.setItem( 'ffc_device_id', fresh );
			return fresh;
		} catch ( e ) {
			// Private mode or storage disabled -- fall back to ephemeral id.
			return generateUuid();
		}
	}

	function generateUuid() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}
		// RFC4122 v4-ish fallback.
		var bytes = new Uint8Array( 16 );
		window.crypto.getRandomValues( bytes );
		bytes[ 6 ] = ( bytes[ 6 ] & 0x0f ) | 0x40;
		bytes[ 8 ] = ( bytes[ 8 ] & 0x3f ) | 0x80;
		var hex = [];
		for ( var i = 0; i < bytes.length; i++ ) {
			hex.push( ( bytes[ i ] + 0x100 ).toString( 16 ).slice( 1 ) );
		}
		return (
			hex.slice( 0, 4 ).join( '' ) + '-' +
			hex.slice( 4, 6 ).join( '' ) + '-' +
			hex.slice( 6, 8 ).join( '' ) + '-' +
			hex.slice( 8, 10 ).join( '' ) + '-' +
			hex.slice( 10, 16 ).join( '' )
		);
	}

	function sha256Hex( input ) {
		var encoder = new TextEncoder();
		var data = encoder.encode( String( input ) );
		return window.crypto.subtle.digest( 'SHA-256', data ).then( function ( buf ) {
			var bytes = new Uint8Array( buf );
			var out = '';
			for ( var i = 0; i < bytes.length; i++ ) {
				out += ( bytes[ i ] + 0x100 ).toString( 16 ).slice( 1 );
			}
			return out;
		} );
	}

	function stableStringify( value ) {
		// thumbmarkjs ships its own deterministic stringifier; prefer it
		// so component shapes hash to the same digest across page loads.
		if ( window.ThumbmarkJS && typeof window.ThumbmarkJS.stableStringify === 'function' ) {
			try {
				return window.ThumbmarkJS.stableStringify( value );
			} catch ( e ) {
				// Fall through to JSON.stringify below.
			}
		}
		try {
			return JSON.stringify( value );
		} catch ( e ) {
			return '';
		}
	}

	/**
	 * Map thumbmarkjs's `getFingerprintData()` output to the 14 keys our
	 * server schema expects (10 originals + 4 added in 6.3.2). Returns an
	 * object {key: serializableValue}; empty/missing components map to ''
	 * so they're filtered out before hashing.
	 */
	function mapComponents( data ) {
		var sys = ( data && data.system ) || {};
		var loc = ( data && data.locales ) || {};
		var hw  = ( data && data.hardware ) || {};

		// `ua` keeps semantic compatibility with the 6.3.0 hand-rolled
		// hash: useragent + platform + primary language. Coarse-grained
		// (no minor versions) so auto-updating browsers stay stable.
		var coarseUa = String( sys.useragent || '' ).replace( /(\d+)\.\d+\.\d+/g, '$1' );
		var ua = coarseUa + '|' + ( sys.platform || '' ) + '|' + ( loc.languages || '' );

		return {
			ua:           ua,
			screen:       data.screen ? stableStringify( data.screen ) : '',
			tz:           loc.timezone || '',
			concurrency:  ( sys.hardwareConcurrency != null ) ? String( sys.hardwareConcurrency ) : '',
			memory:       ( hw.deviceMemory != null ) ? String( hw.deviceMemory ) : '',
			canvas:       data.canvas ? stableStringify( data.canvas ) : '',
			audio:        data.audio ? stableStringify( data.audio ) : '',
			webgl:        data.webgl ? stableStringify( data.webgl ) : '',
			fonts:        data.fonts ? stableStringify( data.fonts ) : '',

			// 6.3.2: four additional signals exposed by thumbmarkjs.
			plugins:      data.plugins ? stableStringify( data.plugins ) : '',
			permissions:  data.permissions ? stableStringify( data.permissions ) : '',
			mediaqueries: ( data.mediaQueries || data.mediaqueries ) ? stableStringify( data.mediaQueries || data.mediaqueries ) : '',
			math:         data.math ? stableStringify( data.math ) : ''
		};
	}

	function collectSignals() {
		var raw = {};

		if ( isEnabled( 'cookie' ) ) {
			raw.cookie = getOrCreateDeviceId();
		}

		return window.ThumbmarkJS.getFingerprintData().then( function ( data ) {
			var mapped = mapComponents( data );
			Object.keys( mapped ).forEach( function ( key ) {
				if ( isEnabled( key ) && mapped[ key ] ) {
					raw[ key ] = mapped[ key ];
				}
			} );

			var jobs = [];
			var hashed = {};
			Object.keys( raw ).forEach( function ( key ) {
				if ( ! raw[ key ] ) {
					return;
				}
				jobs.push(
					sha256Hex( raw[ key ] ).then( function ( hex ) {
						hashed[ key ] = hex;
					} )
				);
			} );

			return Promise.all( jobs ).then( function () {
				return hashed;
			} );
		} ).catch( function () {
			// Best-effort: if thumbmarkjs throws, ship just the cookie hash
			// (or nothing). Server treats it as a partial-signal payload.
			if ( ! raw.cookie ) {
				return {};
			}
			return sha256Hex( raw.cookie ).then( function ( hex ) {
				return { cookie: hex };
			} );
		} );
	}

	function ensureHiddenInput( form ) {
		var input = form.querySelector( 'input[name="ffc_device_signals"]' );
		if ( ! input ) {
			input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = 'ffc_device_signals';
			input.value = '';
			form.appendChild( input );
		}
		return input;
	}

	function attachToForms() {
		var forms = document.querySelectorAll( 'form.ffc-submission-form' );
		if ( ! forms.length ) {
			return;
		}
		var signalsPromise = collectSignals();

		Array.prototype.forEach.call( forms, function ( form ) {
			var input = ensureHiddenInput( form );
			signalsPromise.then( function ( hashed ) {
				input.value = JSON.stringify( hashed );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', attachToForms );
	} else {
		attachToForms();
	}
}());
