// Tests for `assets/js/ffc-webview-warning.js`.
//
// The script is a pure IIFE that:
//   1. Bails fast unless the UA matches an Android WebView or iOS in-app
//      browser (Facebook, Instagram, TikTok, WhatsApp, LinkedIn, Line,
//      Twitter for iPhone).
//   2. Bails if sessionStorage flag is set (user dismissed previously).
//   3. Anchors a banner above the first matching `.ffc-form-wrapper`,
//      `.ffc-public-csv-download`, or `.ffc-submission-form` element.
//   4. Adds a dismiss button that sets the sessionStorage flag.
//
// Sprint C of #168.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const STORAGE_KEY = 'ffc_webview_warning_dismissed';

function setUA(ua) {
	Object.defineProperty(navigator, 'userAgent', { value: ua, configurable: true });
}

const ORIG_UA = navigator.userAgent;

beforeEach(() => {
	window.sessionStorage.clear();
	document.body.innerHTML = `<div class="ffc-form-wrapper"><form class="ffc-submission-form"></form></div>`;
	setUA(ORIG_UA);
});

describe('ffc-webview-warning', () => {
	it('renders no banner on a plain desktop browser UA', () => {
		setUA('Mozilla/5.0 (Windows NT 10.0; Win64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36');
		loadScript('assets/js/ffc-webview-warning.js');
		expect(document.querySelector('.ffc-webview-warning')).toBeNull();
	});

	it('renders the banner on an Android WebView UA', () => {
		setUA('Mozilla/5.0 (Linux; Android 13; Pixel 7; wv) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36');
		loadScript('assets/js/ffc-webview-warning.js');
		const banner = document.querySelector('.ffc-webview-warning');
		expect(banner).not.toBeNull();
		expect(banner.querySelector('h3')).not.toBeNull();
		expect(banner.querySelector('.ffc-webview-warning-open')).not.toBeNull();
		expect(banner.querySelector('.ffc-webview-warning-dismiss')).not.toBeNull();
	});

	it('renders the banner on an Instagram in-app UA', () => {
		setUA('Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 Instagram 310.0.0');
		loadScript('assets/js/ffc-webview-warning.js');
		expect(document.querySelector('.ffc-webview-warning')).not.toBeNull();
	});

	it('renders the banner on a Facebook in-app UA (FBAN marker)', () => {
		setUA('Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 FBAN/FBIOS;FBAV/440.0.0');
		loadScript('assets/js/ffc-webview-warning.js');
		expect(document.querySelector('.ffc-webview-warning')).not.toBeNull();
	});

	it('does NOT render when the dismissed flag is already set in sessionStorage', () => {
		setUA('Mozilla/5.0 (Linux; Android 13; wv) AppleWebKit/537.36');
		window.sessionStorage.setItem(STORAGE_KEY, '1');
		loadScript('assets/js/ffc-webview-warning.js');
		expect(document.querySelector('.ffc-webview-warning')).toBeNull();
	});

	it('dismisses by removing the banner and setting the sessionStorage flag', () => {
		setUA('Mozilla/5.0 (Linux; Android 13; wv) AppleWebKit/537.36');
		loadScript('assets/js/ffc-webview-warning.js');
		const dismiss = document.querySelector('.ffc-webview-warning-dismiss');
		expect(dismiss).not.toBeNull();
		dismiss.click();
		expect(document.querySelector('.ffc-webview-warning')).toBeNull();
		expect(window.sessionStorage.getItem(STORAGE_KEY)).toBe('1');
	});

	it('anchors the banner above the .ffc-form-wrapper', () => {
		setUA('Mozilla/5.0 (Linux; Android 13; wv) AppleWebKit/537.36');
		loadScript('assets/js/ffc-webview-warning.js');
		const wrapper = document.querySelector('.ffc-form-wrapper');
		expect(wrapper.previousElementSibling).not.toBeNull();
		expect(wrapper.previousElementSibling.classList.contains('ffc-webview-warning')).toBe(true);
	});

	it('does nothing when no anchor element is present in the DOM', () => {
		document.body.innerHTML = '<div>nothing to anchor against</div>';
		setUA('Mozilla/5.0 (Linux; Android 13; wv) AppleWebKit/537.36');
		loadScript('assets/js/ffc-webview-warning.js');
		expect(document.querySelector('.ffc-webview-warning')).toBeNull();
	});

	it('defers rendering to DOMContentLoaded while the document is loading', () => {
		setUA('Mozilla/5.0 (Linux; Android 13; wv) AppleWebKit/537.36');
		const readyStateSpy = vi
			.spyOn(document, 'readyState', 'get')
			.mockReturnValue('loading');
		const addSpy = vi.spyOn(document, 'addEventListener');

		loadScript('assets/js/ffc-webview-warning.js');

		// Not rendered yet — deferred.
		expect(document.querySelector('.ffc-webview-warning')).toBeNull();
		const dclCall = addSpy.mock.calls.find((c) => c[0] === 'DOMContentLoaded');
		expect(dclCall).toBeTruthy();
		readyStateSpy.mockRestore();
		dclCall[1]();
		expect(document.querySelector('.ffc-webview-warning')).not.toBeNull();
		addSpy.mockRestore();
	});

	describe('"Open in browser" CTA', () => {
		let savedLocation;
		beforeEach(() => {
			savedLocation = window.location;
		});
		afterEach(() => {
			Object.defineProperty(window, 'location', {
				value: savedLocation,
				configurable: true,
				writable: true,
			});
			vi.restoreAllMocks();
		});

		it('Android WebView: navigates to an intent:// Chrome deep-link', () => {
			setUA('Mozilla/5.0 (Linux; Android 13; wv) AppleWebKit/537.36');
			// Replace location with a settable stub.
			Object.defineProperty(window, 'location', {
				value: { href: 'https://example.com/page', replace: vi.fn() },
				configurable: true,
				writable: true,
			});
			loadScript('assets/js/ffc-webview-warning.js');

			document.querySelector('.ffc-webview-warning-open').click();

			expect(window.location.href).toBe(
				'intent://example.com/page#Intent;scheme=https;package=com.android.chrome;end'
			);
		});

		it('Android WebView: alerts the iOS hint when intent navigation throws', () => {
			setUA('Mozilla/5.0 (Linux; Android 13; wv) AppleWebKit/537.36');
			const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
			// A location whose href setter throws → falls back to the alert.
			Object.defineProperty(window, 'location', {
				configurable: true,
				value: {
					get href() {
						return 'https://example.com/p';
					},
					set href(v) {
						throw new Error('blocked');
					},
				},
			});
			loadScript('assets/js/ffc-webview-warning.js');

			document.querySelector('.ffc-webview-warning-open').click();
			expect(alertSpy).toHaveBeenCalled();
		});

		it('iOS in-app browser: alerts the manual-menu instructions', () => {
			setUA('Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) Instagram 310.0.0');
			const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
			loadScript('assets/js/ffc-webview-warning.js');

			document.querySelector('.ffc-webview-warning-open').click();
			expect(alertSpy).toHaveBeenCalledWith(
				expect.stringContaining('Open in Safari')
			);
		});
	});
});
