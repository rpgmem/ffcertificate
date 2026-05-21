// 6.6.4 Sprint 3 — GPS Permissions API pre-check.
//
// Wraps the existing validateGeolocation getCurrentPosition call with
// a `navigator.permissions.query({name: 'geolocation'})` that handles
// granted / prompt / denied states BEFORE the native prompt fires.
// iOS Safari <16 falls through to the existing native flow.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-geofence-frontend.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
	window.ffcGeofenceConfig = undefined;
	window.$.fx.off = true;
	Object.defineProperty(navigator, 'userAgent', {
		configurable: true,
		value: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
	});
	Object.defineProperty(navigator, 'cookieEnabled', { configurable: true, value: true });
	// HTTPS expected for validateGeolocation.
	delete window.location;
	window.location = { protocol: 'https:', hostname: 'example.org' };
	// Default: geolocation API present (we never reach it in pre-check tests).
	Object.defineProperty(navigator, 'geolocation', {
		configurable: true,
		value: { getCurrentPosition: vi.fn() },
	});
});

afterEach(() => {
	vi.restoreAllMocks();
	// Don't leak permissions stub.
	Object.defineProperty(navigator, 'permissions', { configurable: true, value: undefined });
});

function setupForm(formId, geoConfig) {
	document.body.innerHTML =
		'<div id="ffc-form-' + formId + '" class="ffc-form-wrapper">' +
		'<form class="ffc-submission-form"></form>' +
		'</div>';
	window.ffcGeofenceConfig = {
		[formId]: {
			adminBypass: false,
			datetime: { enabled: false },
			geo: { enabled: true, gpsEnabled: true, ...geoConfig },
		},
		_global: {
			debug: false,
			strings: {
				gpsDeniedTitle: 'Location blocked',
				gpsDeniedBody: 'Browser blocked location.',
				gpsDeniedHowIos: 'iOS instructions',
				gpsDeniedHowAndroid: 'Android instructions',
				gpsDeniedHowDesktop: 'Desktop instructions',
				gpsTryAnyway: 'Try anyway',
				gpsPromptTitle: 'We need your location',
				gpsPromptBody: 'After you tap Continue, the browser will ask.',
				gpsPromptContinue: 'Continue',
			},
		},
	};
}

function stubPermissions(state, { rejects = false } = {}) {
	const status = { state, onchange: null };
	Object.defineProperty(navigator, 'permissions', {
		configurable: true,
		value: {
			query: vi.fn(() => rejects
				? Promise.reject(new TypeError('Unknown'))
				: Promise.resolve(status)),
		},
	});
	return status;
}

describe('Sprint 3 — Permissions API pre-check', () => {
	it('granted state: skips banner, falls through to native flow', async () => {
		stubPermissions('granted');
		setupForm(7);
		window.FFCGeofence.init();
		await Promise.resolve();
		await Promise.resolve();

		expect(document.querySelector('.ffc-preflight-gps')).toBeNull();
		// validateGeolocation continued — form is now in loading state.
		expect(document.querySelector('.ffc-form-wrapper').classList.contains('ffc-geofence-loading')).toBe(true);
	});

	it('denied state: paints the "Location blocked" banner with instructions, native flow NOT called', async () => {
		stubPermissions('denied');
		setupForm(7);
		window.FFCGeofence.init();
		await Promise.resolve();
		await Promise.resolve();

		const banner = document.querySelector('.ffc-preflight-gps');
		expect(banner).not.toBeNull();
		expect(banner.getAttribute('role')).toBe('alert');
		expect(banner.querySelector('.ffc-preflight-banner-title').textContent).toBe('Location blocked');
		expect(banner.querySelector('.ffc-preflight-banner-how').textContent).toBe('Desktop instructions');
		// getCurrentPosition was NOT called.
		expect(navigator.geolocation.getCurrentPosition).not.toHaveBeenCalled();
	});

	it('denied state on iOS UA: surfaces iOS-specific instructions', async () => {
		Object.defineProperty(navigator, 'userAgent', {
			configurable: true,
			value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
		});
		stubPermissions('denied');
		setupForm(7);
		window.FFCGeofence.init();
		await Promise.resolve();
		await Promise.resolve();

		expect(document.querySelector('.ffc-preflight-banner-how').textContent).toBe('iOS instructions');
	});

	it('prompt state: paints the explainer banner with Continue button', async () => {
		stubPermissions('prompt');
		setupForm(7);
		window.FFCGeofence.init();
		await Promise.resolve();
		await Promise.resolve();

		const banner = document.querySelector('.ffc-preflight-gps');
		expect(banner).not.toBeNull();
		expect(banner.querySelector('.ffc-preflight-banner-title').textContent).toBe('We need your location');
		expect(banner.querySelector('.ffc-preflight-try-anyway').textContent).toBe('Continue');
		// Native prompt not yet fired.
		expect(navigator.geolocation.getCurrentPosition).not.toHaveBeenCalled();
	});

	it('prompt → Continue button click triggers the native flow', async () => {
		stubPermissions('prompt');
		setupForm(7);
		window.FFCGeofence.init();
		await Promise.resolve();
		await Promise.resolve();

		window.$('.ffc-preflight-try-anyway').trigger('click');

		// Banner removed.
		expect(document.querySelector('.ffc-preflight-gps')).toBeNull();
		// validateGeolocation re-entered with preflightDone=true → flow advanced.
		expect(document.querySelector('.ffc-form-wrapper').classList.contains('ffc-geofence-loading')).toBe(true);
	});

	it('iOS Safari <16 (permissions.query rejects): falls through silently to native flow', async () => {
		stubPermissions('denied', { rejects: true });
		setupForm(7);
		window.FFCGeofence.init();
		await Promise.resolve();
		await Promise.resolve();

		expect(document.querySelector('.ffc-preflight-gps')).toBeNull();
		expect(document.querySelector('.ffc-form-wrapper').classList.contains('ffc-geofence-loading')).toBe(true);
	});

	it('no Permissions API at all: falls through silently to native flow', async () => {
		Object.defineProperty(navigator, 'permissions', { configurable: true, value: undefined });
		setupForm(7);
		window.FFCGeofence.init();
		await Promise.resolve();

		expect(document.querySelector('.ffc-preflight-gps')).toBeNull();
		expect(document.querySelector('.ffc-form-wrapper').classList.contains('ffc-geofence-loading')).toBe(true);
	});

	it('PermissionStatus.onchange to granted: removes banner and continues', async () => {
		const status = stubPermissions('denied');
		setupForm(7);
		window.FFCGeofence.init();
		await Promise.resolve();
		await Promise.resolve();

		expect(document.querySelector('.ffc-preflight-gps')).not.toBeNull();
		expect(typeof status.onchange).toBe('function');

		// User goes to Settings, grants location, comes back.
		status.state = 'granted';
		status.onchange();

		expect(document.querySelector('.ffc-preflight-gps')).toBeNull();
		expect(document.querySelector('.ffc-form-wrapper').classList.contains('ffc-geofence-loading')).toBe(true);
	});
});
