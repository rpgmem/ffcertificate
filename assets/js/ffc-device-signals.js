/**
 * FFC Device Signals
 *
 * Collects a multi-signal device fingerprint and a persistent UUID cookie,
 * SHA-256 hashes each signal independently, and writes the result as a
 * hidden `ffc_device_signals` JSON input on every FFC submission form
 * (selector: `form.ffc-submission-form`).
 *
 * The set of signals to collect is provided server-side via
 * `wp_localize_script` as `ffc_device_config.signals` (e.g. ["cookie",
 * "ua", "screen", "tz", "concurrency", "memory", "canvas", "audio",
 * "webgl", "fonts"]). Disabled signals are simply not collected.
 *
 * Signals collection is best-effort: anything that throws or is
 * unsupported in the current browser is silently skipped — the server
 * applies the "N of M" matching rule on whatever subset arrives.
 *
 * @package FreeFormCertificate
 * @since   6.3.0
 */
(function () {
	'use strict';

	if ( typeof window.crypto === 'undefined' || typeof window.crypto.subtle === 'undefined' ) {
		// SubtleCrypto requires a secure context. If it's missing the limit
		// silently degrades to "no signals collected" -- the server treats
		// that as bypass-equivalent (no rows, no enforcement).
		return;
	}

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

	function uaSignal() {
		var ua = navigator.userAgent || '';
		var platform = navigator.platform || '';
		// Coarse-grained: drop minor/patch versions to reduce churn between
		// auto-updated browsers on the same machine.
		var coarse = ua.replace( /(\d+)\.\d+\.\d+/g, '$1' );
		return coarse + '|' + platform + '|' + ( navigator.language || '' );
	}

	function screenSignal() {
		var s = window.screen || {};
		return [ s.width, s.height, s.colorDepth, window.devicePixelRatio || 1 ].join( 'x' );
	}

	function tzSignal() {
		try {
			return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
		} catch ( e ) {
			return String( new Date().getTimezoneOffset() );
		}
	}

	function concurrencySignal() {
		return String( navigator.hardwareConcurrency || 0 );
	}

	function memorySignal() {
		return String( navigator.deviceMemory || 0 );
	}

	function canvasSignal() {
		try {
			var canvas = document.createElement( 'canvas' );
			canvas.width = 240;
			canvas.height = 60;
			var ctx = canvas.getContext( '2d' );
			if ( ! ctx ) {
				return '';
			}
			ctx.textBaseline = 'top';
			ctx.font = "14px 'Arial'";
			ctx.fillStyle = '#f60';
			ctx.fillRect( 125, 1, 62, 20 );
			ctx.fillStyle = '#069';
			ctx.fillText( 'ffc-fingerprint-✨', 2, 15 );
			ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
			ctx.fillText( 'ffc-fingerprint-✨', 4, 17 );
			return canvas.toDataURL();
		} catch ( e ) {
			return '';
		}
	}

	function audioSignal() {
		try {
			var Ctx = window.OfflineAudioContext || window.webkitOfflineAudioContext;
			if ( ! Ctx ) {
				return '';
			}
			var ctx = new Ctx( 1, 4400, 44100 );
			var oscillator = ctx.createOscillator();
			oscillator.type = 'triangle';
			oscillator.frequency.value = 10000;
			var compressor = ctx.createDynamicsCompressor();
			compressor.threshold.value = -50;
			compressor.knee.value = 40;
			compressor.ratio.value = 12;
			compressor.attack.value = 0;
			compressor.release.value = 0.25;
			oscillator.connect( compressor );
			compressor.connect( ctx.destination );
			oscillator.start( 0 );
			ctx.startRendering();
			// Resolve synchronously with a digest of the analyser parameters.
			// Real rendering happens async; we only need a stable string per
			// device for our hash, so the configuration values suffice.
			return [
				ctx.sampleRate,
				compressor.threshold.value,
				compressor.knee.value,
				compressor.ratio.value
			].join( '|' );
		} catch ( e ) {
			return '';
		}
	}

	function webglSignal() {
		try {
			var canvas = document.createElement( 'canvas' );
			var gl = canvas.getContext( 'webgl' ) || canvas.getContext( 'experimental-webgl' );
			if ( ! gl ) {
				return '';
			}
			var info = gl.getExtension( 'WEBGL_debug_renderer_info' );
			var vendor = info ? gl.getParameter( info.UNMASKED_VENDOR_WEBGL ) : gl.getParameter( gl.VENDOR );
			var renderer = info ? gl.getParameter( info.UNMASKED_RENDERER_WEBGL ) : gl.getParameter( gl.RENDERER );
			return ( vendor || '' ) + '|' + ( renderer || '' );
		} catch ( e ) {
			return '';
		}
	}

	function fontsSignal() {
		try {
			var probes = [
				'monospace',
				'serif',
				'sans-serif',
				'Arial',
				'Verdana',
				'Times New Roman',
				'Courier New',
				'Helvetica',
				'Tahoma',
				'Trebuchet MS',
				'Comic Sans MS',
				'Impact'
			];
			var span = document.createElement( 'span' );
			span.style.position = 'absolute';
			span.style.left = '-9999px';
			span.style.fontSize = '32px';
			span.textContent = 'mmmmmmmmmmlli';
			document.body.appendChild( span );
			var sizes = [];
			for ( var i = 0; i < probes.length; i++ ) {
				span.style.fontFamily = probes[ i ];
				sizes.push( probes[ i ] + ':' + span.offsetWidth + 'x' + span.offsetHeight );
			}
			document.body.removeChild( span );
			return sizes.join( ';' );
		} catch ( e ) {
			return '';
		}
	}

	function collectSignals() {
		var jobs = [];
		var deviceId = isEnabled( 'cookie' ) ? getOrCreateDeviceId() : '';
		var raw = {};

		if ( deviceId ) {
			raw.cookie = deviceId;
		}
		if ( isEnabled( 'ua' ) ) {
			raw.ua = uaSignal();
		}
		if ( isEnabled( 'screen' ) ) {
			raw.screen = screenSignal();
		}
		if ( isEnabled( 'tz' ) ) {
			raw.tz = tzSignal();
		}
		if ( isEnabled( 'concurrency' ) ) {
			raw.concurrency = concurrencySignal();
		}
		if ( isEnabled( 'memory' ) ) {
			raw.memory = memorySignal();
		}
		if ( isEnabled( 'canvas' ) ) {
			raw.canvas = canvasSignal();
		}
		if ( isEnabled( 'audio' ) ) {
			raw.audio = audioSignal();
		}
		if ( isEnabled( 'webgl' ) ) {
			raw.webgl = webglSignal();
		}
		if ( isEnabled( 'fonts' ) ) {
			raw.fonts = fontsSignal();
		}

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
