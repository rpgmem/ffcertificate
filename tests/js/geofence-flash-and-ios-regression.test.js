// Regression tests for two issues reported in the certificate-form
// geofence flow:
//
//   1. CSS "form flash" — `.ffc-shortcode .ffc-has-geofence
//      .ffc-submission-form` (with whitespace) never matched because
//      both classes live on the same wrapper div emitted by
//      class-ffc-shortcodes.php, so the form stayed visible until JS
//      hid it. The chained-class form (no whitespace) is the fix; this
//      test pins the selector so a future edit can't silently
//      regress.
//
//   2. iOS Safari accepting stale cached positions — `firstMaxAge`
//      was 30 000 ms, which let iOS return a fix from before the user
//      walked out of the allowed area. The window was tightened to
//      5 000 ms; this test pins the value passed to
//      navigator.geolocation.getCurrentPosition.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-geofence-frontend.js');
	loadScript('assets/js/ffc-geofence-datetime.js');
	loadScript('assets/js/ffc-geofence-gps.js');
	loadScript('assets/js/ffc-geofence-preflight.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
});

afterEach(() => {
	vi.restoreAllMocks();
});

// ----------------------------------------------------------------------
// CSS selector regression (Issue #1)
// ----------------------------------------------------------------------

describe('geofence CSS — form flash regression', () => {
	function injectFrontendCss() {
		const css = fs.readFileSync(
			path.resolve(process.cwd(), 'assets/css/ffc-frontend.css'),
			'utf8',
		);
		const style = document.createElement('style');
		style.textContent = css;
		document.head.appendChild(style);
	}

	it('hides .ffc-submission-form when wrapper has both .ffc-shortcode and .ffc-has-geofence (chained, no whitespace)', () => {
		injectFrontendCss();
		document.body.innerHTML = `
			<div class="ffc-shortcode ffc-form-wrapper ffc-has-geofence" id="ffc-form-1">
				<h2 class="ffc-form-title">Title</h2>
				<form class="ffc-submission-form" id="ffc-form-element-1"></form>
			</div>
		`;
		const form = document.querySelector('.ffc-submission-form');
		expect(window.getComputedStyle(form).display).toBe('none');
	});

	it('shows .ffc-submission-form once .ffc-validated is added by the JS', () => {
		injectFrontendCss();
		document.body.innerHTML = `
			<div class="ffc-shortcode ffc-form-wrapper ffc-has-geofence ffc-validated" id="ffc-form-1">
				<form class="ffc-submission-form" id="ffc-form-element-1"></form>
			</div>
		`;
		const form = document.querySelector('.ffc-submission-form');
		expect(window.getComputedStyle(form).display).toBe('block');
	});

	it('leaves .ffc-submission-form visible when geofence is NOT active (no .ffc-has-geofence)', () => {
		injectFrontendCss();
		document.body.innerHTML = `
			<div class="ffc-shortcode ffc-form-wrapper" id="ffc-form-1">
				<form class="ffc-submission-form" id="ffc-form-element-1"></form>
			</div>
		`;
		const form = document.querySelector('.ffc-submission-form');
		// Default display from the .ffc-submission-form base rule (or
		// browser default for <form>, which is block). Anything other
		// than 'none' is correct — the flash-prevention rule must not
		// fire when the wrapper has no geofence.
		expect(window.getComputedStyle(form).display).not.toBe('none');
	});
});

// ----------------------------------------------------------------------
// iOS firstMaxAge regression (Issue #2)
// ----------------------------------------------------------------------

describe('geofence iOS — firstMaxAge tightened to 5 s', () => {
	function installSafariUa() {
		const original = navigator.userAgent;
		Object.defineProperty(navigator, 'userAgent', {
			configurable: true,
			value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
		});
		return () => {
			Object.defineProperty(navigator, 'userAgent', {
				configurable: true,
				value: original,
			});
		};
	}

	function installNonSafariUa() {
		const original = navigator.userAgent;
		Object.defineProperty(navigator, 'userAgent', {
			configurable: true,
			value: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
		});
		return () => {
			Object.defineProperty(navigator, 'userAgent', {
				configurable: true,
				value: original,
			});
		};
	}

	function installLocationHttps() {
		const original = window.location;
		Object.defineProperty(window, 'location', {
			configurable: true,
			writable: true,
			value: { protocol: 'https:', hostname: 'example.com', pathname: '/' },
		});
		return () => {
			Object.defineProperty(window, 'location', {
				configurable: true,
				writable: true,
				value: original,
			});
		};
	}

	function mountForm(id = 999) {
		document.body.innerHTML = `
			<div id="ffc-form-${id}">
				<form class="ffc-submission-form"></form>
			</div>
		`;
		return window.$('#ffc-form-' + id);
	}

	it('passes maximumAge=5000 on the first getCurrentPosition call when running on Safari/iOS', () => {
		const restoreUa = installSafariUa();
		const restoreLoc = installLocationHttps();
		const getPosSpy = vi.fn();
		Object.defineProperty(navigator, 'geolocation', {
			configurable: true,
			value: { getCurrentPosition: getPosSpy },
		});
		const $w = mountForm(999);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [{ lat: 0, lng: 0, radius: 1 }],
		});

		expect(getPosSpy).toHaveBeenCalled();
		const opts = getPosSpy.mock.calls[0][2];
		expect(opts.maximumAge).toBe(5000);

		restoreLoc();
		restoreUa();
	});

	it('keeps maximumAge=0 on the first call for non-Safari (Chrome/Android)', () => {
		const restoreUa = installNonSafariUa();
		const restoreLoc = installLocationHttps();
		const getPosSpy = vi.fn();
		Object.defineProperty(navigator, 'geolocation', {
			configurable: true,
			value: { getCurrentPosition: getPosSpy },
		});
		const $w = mountForm(998);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [{ lat: 0, lng: 0, radius: 1 }],
		});

		const opts = getPosSpy.mock.calls[0][2];
		expect(opts.maximumAge).toBe(0);

		restoreLoc();
		restoreUa();
	});
});

// ----------------------------------------------------------------------
// detectPlatformFamily — UA → 'ios' | 'android' | 'desktop'
// ----------------------------------------------------------------------

describe('FFCGeofence.detectPlatformFamily', () => {
	const origUA = navigator.userAgent;

	function setUa(ua) {
		Object.defineProperty(navigator, 'userAgent', { configurable: true, value: ua });
	}

	afterEach(() => {
		Object.defineProperty(navigator, 'userAgent', { configurable: true, value: origUA });
	});

	it('returns ios for an iPhone UA', () => {
		setUa('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1');
		expect(window.FFCGeofence.detectPlatformFamily()).toBe('ios');
	});

	it('returns android for an Android UA', () => {
		setUa('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36');
		expect(window.FFCGeofence.detectPlatformFamily()).toBe('android');
	});

	it('returns desktop for a Windows Chrome UA', () => {
		setUa('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36');
		expect(window.FFCGeofence.detectPlatformFamily()).toBe('desktop');
	});
});
