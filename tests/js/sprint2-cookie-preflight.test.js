// 6.6.4 Sprint 2 — cookie sanity check between datetime and GPS.
//
// Pinned behavior:
//   - When document.cookie write+read roundtrip succeeds, the gate
//     passes silently and the next priority (geo) runs.
//   - When the roundtrip fails OR navigator.cookieEnabled === false,
//     a yellow banner appears with the title/body/instructions/CTA
//     strings, the form is hidden, geo does NOT run.
//   - "Try anyway" button removes the banner and re-enters the geo
//     pipeline (calls processGeoOrShow).
//   - adminBypass=true skips the cookie check entirely.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-geofence-frontend.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
	window.ffcGeofenceConfig = undefined;
	window.$.fx.off = true;
	// Default UA = desktop; tests override.
	Object.defineProperty(navigator, 'userAgent', {
		configurable: true,
		value: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
	});
	Object.defineProperty(navigator, 'cookieEnabled', { configurable: true, value: true });
});

afterEach(() => {
	vi.restoreAllMocks();
});

function setupGeofenceForm(formId, config) {
	document.body.innerHTML =
		'<div id="ffc-form-' + formId + '" class="ffc-form-wrapper">' +
		'<form class="ffc-submission-form"></form>' +
		'</div>';
	window.ffcGeofenceConfig = {
		[formId]: config,
		_global: {
			debug: false,
			strings: {
				cookieBlockedTitle: 'Cookies blocked',
				cookieBlockedBody: 'Without cookies, the submit may fail.',
				cookieBlockedHowIos: 'iOS instructions here',
				cookieBlockedHowAndroid: 'Android instructions here',
				cookieBlockedHowDesktop: 'Desktop instructions here',
				cookieTryAnyway: 'Try anyway',
			},
		},
	};
}

describe('Sprint 2 — checkCookieSupport probe', () => {
	it('returns true when cookieEnabled is true and the probe roundtrips', () => {
		// jsdom's document.cookie roundtrips for first-party.
		const ok = window.FFCGeofence.checkCookieSupport();
		expect(ok).toBe(true);
	});

	it('returns false when navigator.cookieEnabled is explicitly false', () => {
		Object.defineProperty(navigator, 'cookieEnabled', { configurable: true, value: false });
		const ok = window.FFCGeofence.checkCookieSupport();
		expect(ok).toBe(false);
	});

	it('returns false when document.cookie write throws (sandboxed iframe simulation)', () => {
		// Replace the document.cookie setter to throw.
		const original = Object.getOwnPropertyDescriptor(Document.prototype, 'cookie')
			|| Object.getOwnPropertyDescriptor(window.HTMLDocument.prototype, 'cookie');
		Object.defineProperty(document, 'cookie', {
			configurable: true,
			get: () => '',
			set: () => { throw new Error('sandboxed'); },
		});
		const ok = window.FFCGeofence.checkCookieSupport();
		expect(ok).toBe(false);
		// Restore for subsequent tests.
		if (original) Object.defineProperty(document, 'cookie', original);
	});
});

describe('Sprint 2 — handleCookieBlocked banner', () => {
	it('paints the banner with title, body, platform-specific instructions, and CTA', () => {
		setupGeofenceForm(42, {
			adminBypass: false,
			datetime: { enabled: false },
			geo: { enabled: false },
		});
		// Force the cookie check to fail.
		Object.defineProperty(navigator, 'cookieEnabled', { configurable: true, value: false });

		window.FFCGeofence.init();

		const banner = document.querySelector('.ffc-preflight-banner.ffc-preflight-cookies');
		expect(banner).not.toBeNull();
		expect(banner.getAttribute('role')).toBe('alert');
		expect(banner.getAttribute('aria-live')).toBe('assertive');
		expect(banner.querySelector('.ffc-preflight-banner-title').textContent).toBe('Cookies blocked');
		expect(banner.querySelector('.ffc-preflight-banner-body').textContent).toContain('the submit may fail');
		// Desktop UA → desktop instructions.
		expect(banner.querySelector('.ffc-preflight-banner-how').textContent).toBe('Desktop instructions here');
		expect(banner.querySelector('.ffc-preflight-try-anyway').textContent).toBe('Try anyway');

		// Form is hidden behind the banner.
		expect(window.$('.ffc-submission-form').css('display')).toBe('none');
	});

	it('shows iOS instructions when the UA is iPhone', () => {
		Object.defineProperty(navigator, 'userAgent', {
			configurable: true,
			value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
		});
		Object.defineProperty(navigator, 'cookieEnabled', { configurable: true, value: false });
		setupGeofenceForm(42, { datetime: { enabled: false }, geo: { enabled: false } });

		window.FFCGeofence.init();

		expect(document.querySelector('.ffc-preflight-banner-how').textContent).toBe('iOS instructions here');
	});

	it('skips the cookie check entirely when adminBypass is true', () => {
		Object.defineProperty(navigator, 'cookieEnabled', { configurable: true, value: false });
		setupGeofenceForm(42, {
			adminBypass: true,
			bypassInfo: null,
			datetime: { enabled: false },
			geo: { enabled: false },
		});

		window.FFCGeofence.init();

		expect(document.querySelector('.ffc-preflight-banner')).toBeNull();
	});

	it('"Try anyway" removes the banner and re-enters the geo pipeline', () => {
		Object.defineProperty(navigator, 'cookieEnabled', { configurable: true, value: false });
		setupGeofenceForm(42, {
			adminBypass: false,
			datetime: { enabled: false },
			geo: { enabled: false }, // No geo → processGeoOrShow falls through to showForm.
		});

		window.FFCGeofence.init();
		expect(document.querySelector('.ffc-preflight-banner')).not.toBeNull();

		// Click "Try anyway".
		window.$('.ffc-preflight-try-anyway').trigger('click');

		// Banner gone, form revealed.
		expect(document.querySelector('.ffc-preflight-banner')).toBeNull();
		expect(window.$('.ffc-submission-form').css('display')).not.toBe('none');
	});

	it('does NOT run cookie check when datetime gate fails (early return)', () => {
		Object.defineProperty(navigator, 'cookieEnabled', { configurable: true, value: false });
		setupGeofenceForm(42, {
			adminBypass: false,
			datetime: {
				enabled: true,
				// A datetime config that fails (start in the future).
				dateStart: '2099-01-01',
				dateEnd: '2099-12-31',
				timeStart: '00:00',
				timeEnd: '23:59',
				timeMode: 'span',
				hideModeBefore: 'message',
				message: 'Form not yet available',
			},
			geo: { enabled: false },
		});

		window.FFCGeofence.init();

		// Datetime banner fires; cookie banner does NOT.
		expect(document.querySelector('.ffc-preflight-cookies')).toBeNull();
	});
});
