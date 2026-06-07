// Tests for the unified progressive-loading + cache-hit min-display
// behaviour added on top of #191/#192.
//
// What changed in the script:
//   - All platforms now get a progressive loading message during
//     `validateGeolocation`; Safari keeps the longer 0/8s/20s timing
//     because the iOS permission prompt takes longer, while every
//     other browser advances at 0/3s/10s.
//   - The cache-hit path no longer skips the loading UI. It mounts the
//     same spinner, waits FFCGeofence.MIN_LOADING_MS (600 ms) so the
//     user gets a visual "verifying location" tick, then releases the
//     form via `showForm` (the cache now holds a pass token, so no
//     distance re-check is needed).
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-geofence-frontend.js');
	loadScript('assets/js/ffc-geofence-datetime.js');
	loadScript('assets/js/ffc-geofence-gps.js');
	loadScript('assets/js/ffc-geofence-preflight.js');
});

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

function installUa(ua) {
	const original = navigator.userAgent;
	Object.defineProperty(navigator, 'userAgent', {
		configurable: true,
		value: ua,
	});
	return () => {
		Object.defineProperty(navigator, 'userAgent', {
			configurable: true,
			value: original,
		});
	};
}

const CHROME_UA =
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
const SAFARI_UA =
	'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

function mountForm(id) {
	document.body.innerHTML = `
		<div id="ffc-form-${id}" class="ffc-shortcode ffc-form-wrapper ffc-has-geofence">
			<h2 class="ffc-form-title">Title</h2>
			<form class="ffc-submission-form">body</form>
		</div>
	`;
	return window.$('#ffc-form-' + id);
}

beforeEach(() => {
	document.body.innerHTML = '';
	try {
		Object.keys(window.localStorage).forEach((k) => {
			if (k.startsWith('ffc_geo_')) {
				window.localStorage.removeItem(k);
			}
		});
	} catch (_) {
		/* ignore */
	}
});

afterEach(() => {
	vi.restoreAllMocks();
	vi.useRealTimers();
});

// ----------------------------------------------------------------------
// Phase-2 / phase-3 timing on non-Safari (formerly only had stage 1)
// ----------------------------------------------------------------------

describe('progressive loading — non-Safari gets phase-2 at 3 s and phase-3 at 10 s', () => {
	it('advances the loading message at 3 s and 10 s on Chrome', () => {
		vi.useFakeTimers();
		const restoreUa = installUa(CHROME_UA);
		const restoreLoc = installLocationHttps();
		// getCurrentPosition never resolves so we can watch the message
		// progression on its own.
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: { getCurrentPosition: () => {} },
		});
		const $w = mountForm(1001);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [{ lat: 0, lng: 0, radius: 1 }],
		});

		// Phase 1 message present immediately.
		expect($w.find('.ffc-geofence-loading-msg p').text()).toContain('Verifying');

		// Just before 3 s — still phase 1.
		vi.advanceTimersByTime(2999);
		expect($w.find('.ffc-geofence-loading-msg p').text()).toContain('Verifying');

		// At 3 s — phase 2 swap.
		vi.advanceTimersByTime(1);
		expect($w.find('.ffc-geofence-loading-msg p').text()).toContain('Waiting for location permission');

		// At 10 s — phase 3 swap.
		vi.advanceTimersByTime(7000);
		expect($w.find('.ffc-geofence-loading-msg p').text()).toContain('Still trying');

		restoreLoc();
		restoreUa();
	});

	it('keeps Safari on the longer 0/8/20 s cadence', () => {
		vi.useFakeTimers();
		const restoreUa = installUa(SAFARI_UA);
		const restoreLoc = installLocationHttps();
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: { getCurrentPosition: () => {} },
		});
		const $w = mountForm(1002);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [{ lat: 0, lng: 0, radius: 1 }],
		});

		expect($w.find('.ffc-geofence-loading-msg p').text()).toContain('tap "Allow"');

		// At 3 s — Safari is still on phase 1 (non-Safari timing would have
		// advanced; this confirms the platform branching survived the
		// unification).
		vi.advanceTimersByTime(3000);
		expect($w.find('.ffc-geofence-loading-msg p').text()).toContain('tap "Allow"');

		// At 8 s — Safari phase 2.
		vi.advanceTimersByTime(5000);
		expect($w.find('.ffc-geofence-loading-msg p').text()).toContain('Waiting for location permission');

		// At 20 s — Safari phase 3.
		vi.advanceTimersByTime(12000);
		expect($w.find('.ffc-geofence-loading-msg p').text()).toContain('Settings > Privacy & Security');

		restoreLoc();
		restoreUa();
	});
});

// ----------------------------------------------------------------------
// Cache-hit minimum loading time
// ----------------------------------------------------------------------

describe('cache-hit — spinner is held for FFCGeofence.MIN_LOADING_MS before form is released', () => {
	it('mounts the loading state and only releases the form after the min-display delay', () => {
		vi.useFakeTimers();
		const restoreLoc = installLocationHttps();
		// Seed a valid pass token (recent successful validation).
		window.localStorage.setItem(
			'ffc_geo_ffc-form-2001',
			JSON.stringify({
				validated: true,
				expires: Math.floor(Date.now() / 1000) + 600,
			}),
		);
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: { getCurrentPosition: () => {} }, // not called on cache hit
		});
		const $w = mountForm(2001);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [{ lat: 0, lng: 0, radius: 1 }],
		});

		// Right away: loading message is visible, form body hidden,
		// wrapper has the loading class — form NOT yet validated.
		expect($w.find('.ffc-geofence-loading-msg').length).toBe(1);
		expect($w.hasClass('ffc-geofence-loading')).toBe(true);
		expect($w.hasClass('ffc-validated')).toBe(false);

		// Just before the min delay elapses: still loading.
		vi.advanceTimersByTime(window.FFCGeofence.MIN_LOADING_MS - 1);
		expect($w.hasClass('ffc-validated')).toBe(false);

		// At the min delay: loading state torn down and showForm
		// has unlocked the form.
		vi.advanceTimersByTime(1);
		expect($w.find('.ffc-geofence-loading-msg').length).toBe(0);
		expect($w.hasClass('ffc-geofence-loading')).toBe(false);
		expect($w.hasClass('ffc-validated')).toBe(true);

		restoreLoc();
	});

	it('skips getCurrentPosition entirely on cache hit', () => {
		vi.useFakeTimers();
		const restoreLoc = installLocationHttps();
		window.localStorage.setItem(
			'ffc_geo_ffc-form-2002',
			JSON.stringify({
				validated: true,
				expires: Math.floor(Date.now() / 1000) + 600,
			}),
		);
		const getPosSpy = vi.fn();
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: { getCurrentPosition: getPosSpy },
		});
		const $w = mountForm(2002);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [{ lat: 0, lng: 0, radius: 1 }],
		});
		vi.advanceTimersByTime(window.FFCGeofence.MIN_LOADING_MS + 50);

		expect(getPosSpy).not.toHaveBeenCalled();
		expect($w.hasClass('ffc-validated')).toBe(true);

		restoreLoc();
	});
});

// ----------------------------------------------------------------------
// GPS success / safety-timer / Safari-retry / default-error internals
// ----------------------------------------------------------------------

describe('validateGeolocation — success, safety timer, Safari retry', () => {
	it('onSuccess re-checks the location and validates an in-area fix', () => {
		const restoreUa = installUa(CHROME_UA);
		const restoreLoc = installLocationHttps();
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: {
				getCurrentPosition: (success) => {
					success({ coords: { latitude: 0, longitude: 0, accuracy: 5 } });
				},
			},
		});
		const $w = mountForm(6001);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			// Area centred on (0,0) so the fix is inside.
			areas: [{ lat: 0, lng: 0, radius: 1000 }],
			cacheEnabled: true,
			cacheTtl: 300,
		});

		expect($w.hasClass('ffc-validated')).toBe(true);
		// cacheGeofencePass wrote a pass token.
		expect(window.localStorage.getItem('ffc_geo_ffc-form-6001')).toBeTruthy();
		restoreLoc();
		restoreUa();
	});

	it('getFreshGeoConfig prefers the live ffcGeofenceConfig geo block', () => {
		const restoreUa = installUa(CHROME_UA);
		const restoreLoc = installLocationHttps();
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: {
				getCurrentPosition: (success) => {
					success({ coords: { latitude: 0, longitude: 0, accuracy: 5 } });
				},
			},
		});
		// Authoritative geo config keyed by the form id.
		window.ffcGeofenceConfig = {
			6002: { geo: { hideMode: 'message', areas: [{ lat: 0, lng: 0, radius: 1000 }] } },
		};
		const $w = mountForm(6002);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [{ lat: 89, lng: 179, radius: 1 }], // stale fallback — would block
		});

		// getFreshGeoConfig() returned the live, in-area config → validated.
		expect($w.hasClass('ffc-validated')).toBe(true);
		delete window.ffcGeofenceConfig;
		restoreLoc();
		restoreUa();
	});

	it('fires the safety-timer fallback when geolocation never responds', () => {
		vi.useFakeTimers();
		const restoreUa = installUa(CHROME_UA);
		const restoreLoc = installLocationHttps();
		// Never calls success or error.
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: { getCurrentPosition: () => {} },
		});
		const $w = mountForm(6003);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [{ lat: 0, lng: 0, radius: 1 }],
			gpsFallback: { safetyTimer: false },
		});

		// Non-Safari safety timeout is 25 s.
		vi.advanceTimersByTime(25000);
		expect($w.hasClass('ffc-geofence-loading')).toBe(false);
		expect($w.find('.ffc-geofence-blocked').length).toBe(1);
		restoreLoc();
		restoreUa();
	});

	it('safety timer is inert once GPS already resolved', () => {
		vi.useFakeTimers();
		const restoreUa = installUa(CHROME_UA);
		const restoreLoc = installLocationHttps();
		let succeed;
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: {
				getCurrentPosition: (success) => {
					// Defer success so we control when `resolved` flips true.
					succeed = () => success({ coords: { latitude: 0, longitude: 0, accuracy: 5 } });
				},
			},
		});
		const $w = mountForm(6008);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [{ lat: 0, lng: 0, radius: 1000 }],
		});

		// Resolve via success first (sets resolved=true, clears safetyTimer),
		// then force the safety callback path by advancing far past it.
		succeed();
		expect($w.hasClass('ffc-validated')).toBe(true);
		vi.advanceTimersByTime(60000);
		// Still validated — the safety callback short-circuited on `resolved`.
		expect($w.hasClass('ffc-validated')).toBe(true);
		restoreLoc();
		restoreUa();
	});

	it('onSuccess is idempotent — a duplicate fix is ignored', () => {
		const restoreUa = installUa(CHROME_UA);
		const restoreLoc = installLocationHttps();
		let successCb;
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: {
				getCurrentPosition: (success) => {
					successCb = success;
					success({ coords: { latitude: 0, longitude: 0, accuracy: 5 } });
				},
			},
		});
		const $w = mountForm(6009);
		const checkSpy = vi.spyOn(window.FFCGeofence, 'checkLocation');

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [{ lat: 0, lng: 0, radius: 1000 }],
		});

		const callsAfterFirst = checkSpy.mock.calls.length;
		// Fire the captured success callback a second time — `resolved` guard
		// makes it a no-op (no extra checkLocation).
		successCb({ coords: { latitude: 0, longitude: 0, accuracy: 5 } });
		expect(checkSpy.mock.calls.length).toBe(callsAfterFirst);
		restoreLoc();
		restoreUa();
	});

	it('Safari retries once with relaxed settings on a TIMEOUT', () => {
		const restoreUa = installUa(SAFARI_UA);
		const restoreLoc = installLocationHttps();
		let calls = 0;
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: {
				getCurrentPosition: (success, error) => {
					calls += 1;
					if (calls === 1) {
						// First attempt times out → triggers the Safari retry.
						error({ code: 3, PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3 });
					} else {
						// Retry succeeds inside the area.
						success({ coords: { latitude: 0, longitude: 0, accuracy: 5 } });
					}
				},
			},
		});
		const $w = mountForm(6004);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [{ lat: 0, lng: 0, radius: 1000 }],
		});

		expect(calls).toBe(2);
		expect($w.hasClass('ffc-validated')).toBe(true);
		restoreLoc();
		restoreUa();
	});

	it('uses the default error branch for an unknown error code', () => {
		const restoreUa = installUa(CHROME_UA);
		const restoreLoc = installLocationHttps();
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: {
				getCurrentPosition: (_s, error) => {
					// Code 99 matches no known case → default branch.
					error({ code: 99, PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3 });
				},
			},
		});
		const $w = mountForm(6005);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [],
			messageError: 'Custom location error',
			gpsFallback: { positionUnavailable: false },
		});

		expect($w.find('.ffc-geofence-blocked').text()).toContain('Custom location error');
		restoreLoc();
		restoreUa();
	});

	it('setLocationCache swallows a localStorage write failure', () => {
		const setSpy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
			throw new Error('quota');
		});
		// Should not throw despite the storage error.
		expect(() => window.FFCGeofence.setLocationCache('ffc-form-6006', 300)).not.toThrow();
		setSpy.mockRestore();
	});

	it('permissions.query throwing synchronously falls through to the native flow', () => {
		const restoreUa = installUa(CHROME_UA);
		const restoreLoc = installLocationHttps();
		const getPos = vi.fn();
		Object.defineProperty(window.navigator, 'permissions', {
			configurable: true,
			value: {
				query: () => {
					throw new Error('sync boom');
				},
			},
		});
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: { getCurrentPosition: getPos },
		});
		const $w = mountForm(6007);

		// preflightDone defaults to false → the permissions.query block runs
		// and its synchronous throw hits the catch at line 111.
		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [{ lat: 0, lng: 0, radius: 1 }],
		});

		// The synchronous catch re-entered validateGeolocation → getCurrentPosition.
		expect(getPos).toHaveBeenCalled();
		Object.defineProperty(window.navigator, 'permissions', {
			configurable: true,
			value: undefined,
		});
		restoreLoc();
		restoreUa();
	});
});
