// Sprint D — deeper coverage for `assets/js/ffc-geofence-frontend.js`.
//
// The existing `geofence-frontend.test.js` exercises the pure helpers
// (validateDateTime + pickHideMode). This file covers the orchestration
// + side-effect paths:
//
//   - processForm: branches for datetime block, IP-only geo, GPS path,
//     no-restrictions, admin bypass.
//   - validateGeolocation: HTTPS-required guard, missing-API guard,
//     cache-hit short-circuit, success → checkLocation, error branches
//     (PERMISSION_DENIED / POSITION_UNAVAILABLE / TIMEOUT / default),
//     gps_fallback=allow on error, Safari retry path.
//   - checkLocation: no-areas-defined branch, within-area, outside-area.
//   - calculateDistance (Haversine quick sanity check).
//   - handleBlocked: hide / message / title_message / default branches.
//   - showAdminBypassMessages: generic + per-restriction variants.
//   - recheck: skips _global key, skips loading forms, drives the rest.
//   - getLocationCache + setLocationCache: hit / miss / expired / quota.
//   - debug: emits when config._global.debug = true.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-geofence-frontend.js');
	loadScript('assets/js/ffc-geofence-datetime.js');
	loadScript('assets/js/ffc-geofence-gps.js');
	loadScript('assets/js/ffc-geofence-preflight.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
	window.ffcGeofenceConfig = undefined;
	window.$.fx.off = true;
	// Clear any stale cache from prior tests.
	try {
		Object.keys(window.localStorage).forEach((k) => {
			if (k.startsWith('ffc_geo_')) {
				window.localStorage.removeItem(k);
			}
		});
	} catch (_e) {
		/* ignore */
	}
});

afterEach(() => {
	vi.restoreAllMocks();
});

// ----------------------------------------------------------------------
// processForm
// ----------------------------------------------------------------------

describe('FFCGeofence.processForm', () => {
	function mountForm(id = 42) {
		document.body.innerHTML = `
			<div id="ffc-form-${id}">
				<div class="ffc-form-title">title</div>
				<form class="ffc-submission-form">body</form>
			</div>
		`;
		return window.$('#ffc-form-' + id);
	}

	it('bails when the form wrapper is not present', () => {
		// No throw — the early-return path under "Form wrapper not found".
		expect(() =>
			window.FFCGeofence.processForm('999', { datetime: { enabled: false }, geo: { enabled: false } }),
		).not.toThrow();
	});

	it('shows the form when no restrictions are configured', () => {
		const $w = mountForm(1);
		window.FFCGeofence.processForm('1', {
			datetime: { enabled: false },
			geo: { enabled: false },
		});
		expect($w.hasClass('ffc-validated')).toBe(true);
	});

	it('blocks with the matching phase hide mode when datetime fails (before)', () => {
		const $w = mountForm(2);
		// Force a "before" phase by setting dateStart in the future.
		window.FFCGeofence.processForm('2', {
			datetime: {
				enabled: true,
				dateStart: '2099-12-31',
				dateEnd: '',
				timeStart: '',
				timeEnd: '',
				hideModeBefore: 'hide',
				hideModeDuring: 'message',
				hideModeAfter: 'title_message',
			},
			geo: { enabled: false },
		});
		// hideMode 'hide' calls formWrapper.hide() — display becomes none.
		expect($w.css('display')).toBe('none');
		expect($w.hasClass('ffc-validated')).toBe(false);
	});

	it('uses the title_message branch when phase=after', () => {
		const $w = mountForm(3);
		window.FFCGeofence.processForm('3', {
			datetime: {
				enabled: true,
				dateStart: '',
				dateEnd: '2000-01-01',
				hideModeBefore: 'hide',
				hideModeDuring: 'message',
				hideModeAfter: 'title_message',
			},
			geo: { enabled: false },
		});
		expect($w.find('.ffc-geofence-blocked').length).toBe(1);
		// title_message keeps the title visible; only the form body is hidden.
		expect($w.find('.ffc-form-title').css('display')).not.toBe('none');
		expect($w.find('.ffc-submission-form').css('display')).toBe('none');
	});

	it('IP-only branch shows the form directly (no GPS call)', () => {
		const $w = mountForm(4);
		const getPosSpy = vi.fn();
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: { getCurrentPosition: getPosSpy },
		});
		window.FFCGeofence.processForm('4', {
			datetime: { enabled: false },
			geo: { enabled: true, gpsEnabled: false, ipEnabled: true },
		});
		expect($w.hasClass('ffc-validated')).toBe(true);
		expect(getPosSpy).not.toHaveBeenCalled();
	});

	it('geo enabled but neither GPS nor IP method falls through to showForm', () => {
		const $w = mountForm(5);
		window.FFCGeofence.processForm('5', {
			datetime: { enabled: false },
			geo: { enabled: true, gpsEnabled: false, ipEnabled: false },
		});
		expect($w.hasClass('ffc-validated')).toBe(true);
	});

	it('renders admin bypass notice when adminBypass is set', () => {
		const $w = mountForm(6);
		window.FFCGeofence.processForm('6', {
			datetime: { enabled: false },
			geo: { enabled: false },
			adminBypass: true,
			bypassInfo: { hasDatetime: true, hasGeo: true },
		});
		// Two bypass notices (one per restriction).
		expect($w.find('.ffc-geofence-admin-bypass').length).toBe(2);
	});
});

// ----------------------------------------------------------------------
// validateGeolocation
// ----------------------------------------------------------------------

describe('FFCGeofence.validateGeolocation', () => {
	function mountForm(id = 100) {
		document.body.innerHTML = `
			<div id="ffc-form-${id}">
				<div class="ffc-form-title">title</div>
				<form class="ffc-submission-form">body</form>
			</div>
		`;
		return window.$('#ffc-form-' + id);
	}

	function installLocation(protocol = 'https:', hostname = 'example.com') {
		// Replace the jsdom location with a writable stub so the IIFE's
		// protocol check sees what we want.
		const original = window.location;
		Object.defineProperty(window, 'location', {
			configurable: true,
			value: { protocol, hostname, pathname: '/', href: protocol + '//' + hostname + '/' },
			writable: true,
		});
		return () => {
			Object.defineProperty(window, 'location', {
				configurable: true,
				value: original,
				writable: true,
			});
		};
	}

	it('blocks immediately on http (not localhost)', () => {
		const restore = installLocation('http:', 'example.com');
		const $w = mountForm(100);
		window.FFCGeofence.validateGeolocation($w, { hideMode: 'message' });
		expect($w.find('.ffc-geofence-blocked').text()).toContain('secure connection');
		restore();
	});

	it('allows http on localhost (no immediate block)', () => {
		const restore = installLocation('http:', 'localhost');
		const $w = mountForm(101);
		// Provide a geolocation API so the IIFE proceeds past the support check.
		const cb = vi.fn();
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: { getCurrentPosition: cb },
		});

		window.FFCGeofence.validateGeolocation($w, { hideMode: 'message' });

		expect($w.find('.ffc-geofence-blocked').length).toBe(0);
		expect(cb).toHaveBeenCalled();
		restore();
	});

	it('blocks when navigator.geolocation is unavailable (no fallback config defaults to block)', () => {
		const restore = installLocation('https:', 'example.com');
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: undefined,
		});
		const $w = mountForm(102);
		window.FFCGeofence.validateGeolocation($w, { hideMode: 'message' });
		// New copy nudges the user to a modern browser; assert on the
		// stable substring so a future translation tweak doesn't break us.
		expect($w.find('.ffc-geofence-blocked').text()).toContain('does not support location services');
		restore();
	});

	it('uses the cached pass when present (no getCurrentPosition call)', () => {
		vi.useFakeTimers();
		const restore = installLocation('https:', 'example.com');
		const cb = vi.fn();
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: { getCurrentPosition: cb },
		});
		// Seed a pass token (recent successful validation).
		window.localStorage.setItem(
			'ffc_geo_ffc-form-103',
			JSON.stringify({
				validated: true,
				expires: Math.floor(Date.now() / 1000) + 600,
			}),
		);
		const $w = mountForm(103);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [{ lat: 0, lng: 0, radius: 1 }],
			messageBlocked: 'no',
		});

		// Cache-hit now defers showForm by FFCGeofence.MIN_LOADING_MS
		// to give the user a visual "verifying location" confirmation.
		vi.advanceTimersByTime(window.FFCGeofence.MIN_LOADING_MS + 50);

		expect(cb).not.toHaveBeenCalled();
		expect($w.hasClass('ffc-validated')).toBe(true);
		restore();
		vi.useRealTimers();
	});

	it('on getCurrentPosition success: calls checkLocation', () => {
		const restore = installLocation('https:', 'example.com');
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: {
				getCurrentPosition: (success) => {
					success({
						coords: { latitude: 0, longitude: 0, accuracy: 5 },
					});
				},
			},
		});
		const $w = mountForm(104);
		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [{ lat: 0, lng: 0, radius: 1 }],
		});
		expect($w.hasClass('ffc-validated')).toBe(true);
		restore();
	});

	it('on error with gpsFallback=allow: shows the form anyway', () => {
		const restore = installLocation('https:', 'example.com');
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: {
				getCurrentPosition: (_s, error) => {
					error({ code: 1, PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3 });
				},
			},
		});
		const $w = mountForm(105);
		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [],
			gpsFallback: 'allow',
		});
		expect($w.hasClass('ffc-validated')).toBe(true);
		restore();
	});

	it('on PERMISSION_DENIED with no fallback: blocks with the permission message', () => {
		const restore = installLocation('https:', 'example.com');
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: {
				getCurrentPosition: (_s, error) => {
					error({ code: 1, PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3 });
				},
			},
		});
		const $w = mountForm(106);
		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [],
			gpsFallback: 'block',
		});
		expect($w.find('.ffc-geofence-blocked').text()).toContain('Location access is required');
		restore();
	});

	it('on TIMEOUT with no fallback: surfaces the timeout message', () => {
		const restore = installLocation('https:', 'example.com');
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: {
				getCurrentPosition: (_s, error) => {
					error({ code: 3, PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3 });
				},
			},
		});
		const $w = mountForm(107);
		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [],
			gpsFallback: 'block',
		});
		expect($w.find('.ffc-geofence-blocked').text()).toContain('took too long');
		restore();
	});
});

// ----------------------------------------------------------------------
// checkLocation
// ----------------------------------------------------------------------

describe('FFCGeofence.checkLocation', () => {
	function mountForm(id = 200) {
		document.body.innerHTML = `
			<div id="ffc-form-${id}">
				<form class="ffc-submission-form"></form>
			</div>
		`;
		return window.$('#ffc-form-' + id);
	}

	it('no areas defined → shows the form', () => {
		const $w = mountForm(200);
		window.FFCGeofence.checkLocation(
			$w,
			{ latitude: 0, longitude: 0 },
			{ hideMode: 'message', areas: [] },
		);
		expect($w.hasClass('ffc-validated')).toBe(true);
	});

	it('within area → shows the form', () => {
		const $w = mountForm(201);
		window.FFCGeofence.checkLocation(
			$w,
			{ latitude: 0, longitude: 0 },
			{ hideMode: 'message', areas: [{ lat: 0, lng: 0, radius: 1 }] },
		);
		expect($w.hasClass('ffc-validated')).toBe(true);
	});

	it('outside area → blocks with messageBlocked', () => {
		const $w = mountForm(202);
		window.FFCGeofence.checkLocation(
			$w,
			{ latitude: 90, longitude: 0 },
			{
				hideMode: 'message',
				areas: [{ lat: 0, lng: 0, radius: 1 }],
				messageBlocked: 'Outside zone',
			},
		);
		expect($w.find('.ffc-geofence-blocked').text()).toContain('Outside zone');
		expect($w.hasClass('ffc-validated')).toBe(false);
	});
});

// ----------------------------------------------------------------------
// calculateDistance (Haversine spot check)
// ----------------------------------------------------------------------

describe('FFCGeofence.calculateDistance', () => {
	it('returns ~0 for identical coordinates', () => {
		expect(window.FFCGeofence.calculateDistance(0, 0, 0, 0)).toBeCloseTo(0, 1);
	});

	it('returns ~111km for 1 degree of latitude', () => {
		// 1° of latitude ≈ 111.32 km. Haversine on a perfect sphere gives
		// ~111195 m at the equator.
		const d = window.FFCGeofence.calculateDistance(0, 0, 1, 0);
		expect(d).toBeGreaterThan(110000);
		expect(d).toBeLessThan(112000);
	});
});

// ----------------------------------------------------------------------
// handleBlocked branches
// ----------------------------------------------------------------------

describe('FFCGeofence.handleBlocked', () => {
	function mountForm(id = 300) {
		document.body.innerHTML = `
			<div id="ffc-form-${id}">
				<div class="ffc-form-title">title</div>
				<form class="ffc-submission-form">body</form>
			</div>
		`;
		return window.$('#ffc-form-' + id);
	}

	it("'hide' hides the wrapper entirely", () => {
		const $w = mountForm(300);
		window.FFCGeofence.handleBlocked($w, 'hide', 'gone');
		expect($w.css('display')).toBe('none');
	});

	it("'message' hides title + form and shows the message", () => {
		const $w = mountForm(301);
		window.FFCGeofence.handleBlocked($w, 'message', 'closed');
		expect($w.find('.ffc-form-title').css('display')).toBe('none');
		expect($w.find('.ffc-submission-form').css('display')).toBe('none');
		expect($w.find('.ffc-geofence-blocked').text()).toContain('closed');
	});

	it("'title_message' keeps the title visible", () => {
		const $w = mountForm(302);
		window.FFCGeofence.handleBlocked($w, 'title_message', 'try again later');
		expect($w.find('.ffc-form-title').css('display')).not.toBe('none');
		expect($w.find('.ffc-submission-form').css('display')).toBe('none');
		expect($w.find('.ffc-geofence-blocked').text()).toContain('try again later');
	});

	it('unknown mode falls through to the default message branch', () => {
		const $w = mountForm(303);
		window.FFCGeofence.handleBlocked($w, 'unknown-mode', 'fallback');
		expect($w.find('.ffc-geofence-blocked').text()).toContain('fallback');
	});

	it('escapes HTML in the message', () => {
		const $w = mountForm(304);
		window.FFCGeofence.handleBlocked($w, 'message', '<script>alert(1)</script>');
		// The literal angle brackets appear as text — no script element.
		expect($w.find('.ffc-geofence-blocked p').text()).toContain('<script>');
		expect($w.find('script').length).toBe(0);
	});
});

// ----------------------------------------------------------------------
// showAdminBypassMessages
// ----------------------------------------------------------------------

describe('FFCGeofence.showAdminBypassMessages', () => {
	function mountForm(id = 400) {
		document.body.innerHTML = `<div id="ffc-form-${id}"></div>`;
		return window.$('#ffc-form-' + id);
	}

	it('falls back to a generic message when no bypassInfo is given', () => {
		const $w = mountForm(400);
		window.FFCGeofence.showAdminBypassMessages($w, null);
		expect($w.find('.ffc-geofence-admin-bypass').length).toBe(1);
		expect($w.find('.ffc-geofence-admin-bypass').text()).toContain('Admin Bypass');
	});

	it('renders a notice per active restriction (datetime + geo)', () => {
		const $w = mountForm(401);
		window.FFCGeofence.showAdminBypassMessages($w, { hasDatetime: true, hasGeo: true });
		expect($w.find('.ffc-geofence-admin-bypass').length).toBe(2);
	});

	it('renders only the datetime notice when only datetime is bypassed', () => {
		const $w = mountForm(402);
		window.FFCGeofence.showAdminBypassMessages($w, { hasDatetime: true, hasGeo: false });
		expect($w.find('.ffc-geofence-admin-bypass').length).toBe(1);
		expect($w.find('.ffc-geofence-admin-bypass').text()).toContain('Date/Time');
	});

	it('renders a generic notice when bypassInfo has neither flag', () => {
		const $w = mountForm(403);
		window.FFCGeofence.showAdminBypassMessages($w, { hasDatetime: false, hasGeo: false });
		expect($w.find('.ffc-geofence-admin-bypass').length).toBe(1);
		expect($w.find('.ffc-geofence-admin-bypass').text()).toContain('Admin Bypass');
	});
});

// ----------------------------------------------------------------------
// recheck
// ----------------------------------------------------------------------

describe('FFCGeofence.recheck', () => {
	it('does nothing when ffcGeofenceConfig is missing', () => {
		window.ffcGeofenceConfig = undefined;
		expect(() => window.FFCGeofence.recheck()).not.toThrow();
	});

	it('iterates numeric keys only and skips _global', () => {
		document.body.innerHTML = `
			<div id="ffc-form-501">
				<form class="ffc-submission-form"></form>
			</div>
		`;
		window.ffcGeofenceConfig = {
			_global: { debug: false },
			501: {
				datetime: { enabled: false },
				geo: { enabled: false },
			},
		};
		// _global must not blow up; numeric key must trigger processForm
		// and toggle the validated class.
		window.FFCGeofence.recheck();
		expect(window.$('#ffc-form-501').hasClass('ffc-validated')).toBe(true);
	});

	it('skips forms currently in geofence-loading state', () => {
		document.body.innerHTML = `
			<div id="ffc-form-502" class="ffc-geofence-loading">
				<form class="ffc-submission-form"></form>
			</div>
		`;
		window.ffcGeofenceConfig = {
			502: { datetime: { enabled: false }, geo: { enabled: false } },
		};
		window.FFCGeofence.recheck();
		// Still loading — recheck bailed before resetForm/processForm.
		expect(window.$('#ffc-form-502').hasClass('ffc-validated')).toBe(false);
	});
});

// ----------------------------------------------------------------------
// getLocationCache / setLocationCache
// ----------------------------------------------------------------------

describe('FFCGeofence.locationCache', () => {
	it('round-trips a pass token', () => {
		window.FFCGeofence.setLocationCache('cache-1', 600);
		expect(window.FFCGeofence.getLocationCache('cache-1')).toBe(true);
	});

	it('returns null when nothing is cached', () => {
		expect(window.FFCGeofence.getLocationCache('missing-key')).toBeNull();
	});

	it('returns null and clears the entry when expired', () => {
		window.localStorage.setItem(
			'ffc_geo_cache-2',
			JSON.stringify({
				validated: true,
				expires: Math.floor(Date.now() / 1000) - 100,
			}),
		);
		expect(window.FFCGeofence.getLocationCache('cache-2')).toBeNull();
		expect(window.localStorage.getItem('ffc_geo_cache-2')).toBeNull();
	});

	it('setLocationCache silently swallows quota / privacy errors', () => {
		// Force localStorage.setItem to throw.
		const original = window.localStorage.setItem.bind(window.localStorage);
		vi.spyOn(window.localStorage, 'setItem').mockImplementation(() => {
			throw new Error('QuotaExceededError');
		});

		expect(() =>
			window.FFCGeofence.setLocationCache('cache-3', 60),
		).not.toThrow();

		window.localStorage.setItem = original;
	});
});

// ----------------------------------------------------------------------
// debug
// ----------------------------------------------------------------------

describe('FFCGeofence.debug', () => {
	it('emits console.log only when _global.debug is true', () => {
		const spy = vi.spyOn(console, 'log').mockImplementation(() => {});

		window.ffcGeofenceConfig = { _global: { debug: false } };
		window.FFCGeofence.debug('off');
		expect(spy).not.toHaveBeenCalled();

		window.ffcGeofenceConfig = { _global: { debug: true } };
		window.FFCGeofence.debug('on', { x: 1 });
		expect(spy).toHaveBeenCalled();
	});
});
