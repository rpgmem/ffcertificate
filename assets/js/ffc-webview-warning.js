/**
 * FFC WebView Warning
 *
 * In-app browsers (Facebook, Instagram, WhatsApp, TikTok, LinkedIn,
 * Android WebView in general) silently fail to download PDFs even
 * with the v6.3.6 popup-blocker workarounds — most don't have a
 * built-in PDF viewer, the share sheet often lacks "save to files",
 * and `<a download>` is widely ignored.
 *
 * The v6.3.6 patch added a manual-tap fallback that catches most of
 * these cases at download time. This module adds a *preventive* layer:
 * detect the in-app browser at page render time and surface a friendly
 * banner inviting the user to open the page in Chrome / Safari before
 * they even fill the form.
 *
 * Banner is dismissible (sessionStorage flag) so users who choose to
 * proceed don't see it again on the same session.
 *
 * Two CTAs:
 *   - "Open in browser" — Android WebView gets an intent:// deep-link
 *     to Chrome (works in ~80% of WebViews); iOS in-app browsers get
 *     a modal with manual-menu instructions (no reliable JS API).
 *   - "Continue anyway" — dismisses the banner; the v6.3.6 fallback
 *     layers still catch any download issues.
 *
 * @package FreeFormCertificate
 * @since   6.3.7
 */
(function () {
	'use strict';

	var STORAGE_KEY = 'ffc_webview_warning_dismissed';
	// The banner is the shared notice, so without ffc-core there is none;
	// the in-app test is FFC.Platform's (#2030).
	var platform = window.FFC && window.FFC.Platform;
	if ( ! platform ) {
		return;
	}
	var isAndroidWebView = platform.androidWebView();
	var isInAppBrowser   = platform.inApp();

	if ( ! isInAppBrowser ) {
		return;
	}

	// Already dismissed in this session?
	try {
		if ( window.sessionStorage.getItem( STORAGE_KEY ) === '1' ) {
			return;
		}
	} catch ( e ) {
		// Private mode in some browsers throws on sessionStorage access.
		// Continue rendering; user can dismiss again per-page-load.
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', maybeRender );
	} else {
		maybeRender();
	}

	function maybeRender() {
		// The banner is the shared notice; without ffc-core there is none.
		if ( ! window.FFC || typeof window.FFC.notice !== 'function' ) {
			return;
		}
		var cfg = window.ffc_webview_warning || {};
		// Selectors to anchor the banner above. The first match wins.
		var targetSelectors = [
			'.ffc-form-wrapper',          // [ffc_form] outer wrapper (class-ffc-shortcodes.php)
			'.ffc-public-csv-download',   // [ffc_csv_download] outer container
			'.ffc-submission-form'        // fallback to the form element itself
		];
		var anchor = null;
		for ( var i = 0; i < targetSelectors.length; i++ ) {
			anchor = document.querySelector( targetSelectors[ i ] );
			if ( anchor ) {
				break;
			}
		}
		if ( ! anchor || ! anchor.parentNode ) {
			return;
		}

		renderBanner( anchor, cfg );
	}

	function renderBanner( anchor, cfg ) {
		var s = ( cfg && cfg.strings ) || {};
		var title       = s.title          || 'Download may fail in this app';
		var body        = s.body           || 'You are viewing this page inside an app browser. To make sure the certificate downloads correctly, please open the page in your main browser (Chrome or Safari).';
		var ctaOpen     = s.openInBrowser  || 'Open in browser';
		var ctaContinue = s.continueAnyway || 'Continue anyway';
		var iosHint     = s.iosInstructions || 'Tap the menu icon (•••) at the bottom of the app and choose "Open in Safari".';

		var actions = document.createElement( 'div' );
		actions.className = 'ffc-notice-actions';

		var openBtn = document.createElement( 'button' );
		openBtn.type = 'button';
		openBtn.className = 'ffc-webview-warning-open';
		openBtn.textContent = ctaOpen;
		openBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			tryOpenInBrowser( iosHint );
		} );

		var dismissBtn = document.createElement( 'button' );
		dismissBtn.type = 'button';
		dismissBtn.className = 'ffc-webview-warning-dismiss';
		dismissBtn.textContent = ctaContinue;

		actions.appendChild( openBtn );
		actions.appendChild( dismissBtn );

		// The shared notice (#1842): FFC.notice() sets every string through
		// textContent, so neither the title nor the body can become markup.
		var banner = window.FFC.notice( 'warning', 'warning', body, title, {
			role: 'alert',
			hook: 'webview-warning',
			children: [ actions ]
		} );

		dismissBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			try { window.sessionStorage.setItem( STORAGE_KEY, '1' ); } catch ( err ) {}
			if ( banner.parentNode ) {
				banner.parentNode.removeChild( banner );
			}
		} );

		anchor.parentNode.insertBefore( banner, anchor );
	}

	function tryOpenInBrowser( iosHint ) {
		if ( isAndroidWebView ) {
			// intent:// scheme hands the URL off to Chrome on Android.
			// Works in ~80% of WebViews; if blocked the user just stays
			// where they are and can fall back to the manual menu.
			var url      = window.location.href;
			var stripped = url.replace( /^https?:\/\//, '' );
			var intentUrl =
				'intent://' + stripped +
				'#Intent;scheme=https;package=com.android.chrome;end';
			try {
				window.location.href = intentUrl;
			} catch ( e ) {
				// Some WebViews refuse intent:// outright. Fall back to
				// the manual instructions.
				window.alert( iosHint );
			}
		} else {
			// iOS in-app browsers: no reliable JS API to switch.
			window.alert( iosHint );
		}
	}
}());
