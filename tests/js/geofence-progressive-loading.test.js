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
//     user gets a visual "verifying location" tick, then resolves via
//     `checkLocation`.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-geofence-frontend.js');
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
	it('mounts the loading state and only calls checkLocation after the min-display delay', () => {
		vi.useFakeTimers();
		const restoreLoc = installLocationHttps();
		// Seed a valid cached location inside the test area.
		window.localStorage.setItem(
			'ffc_geo_ffc-form-2001',
			JSON.stringify({
				location: { latitude: 0, longitude: 0, accuracy: 5 },
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

		// At the min delay: loading state torn down and checkLocation
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
				location: { latitude: 0, longitude: 0, accuracy: 5 },
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
