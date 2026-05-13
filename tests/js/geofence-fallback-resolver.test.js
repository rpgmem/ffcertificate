// Tests for the per-case GPS fallback resolver introduced when the
// admin "When GPS fails" setting was upgraded from a single string to
// a preset + per-case matrix. The frontend now receives an object on
// `config.gpsFallback` and decides per failure case whether to call
// showForm or handleBlocked.
//
// Also covers:
//   - The "Reload page" button rendered under the blocked message for
//     transient GPS failures (denied / unavailable / timeout / safety).
//   - Backward compatibility with the legacy 'allow' | 'block' string
//     payload from pre-presets servers.
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
		value: { protocol: 'https:', hostname: 'example.com', pathname: '/', reload: vi.fn() },
	});
	return () => {
		Object.defineProperty(window, 'location', {
			configurable: true,
			writable: true,
			value: original,
		});
	};
}

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
// shouldAllow — direct
// ----------------------------------------------------------------------

describe('FFCGeofence.shouldAllow', () => {
	it('returns the matching case flag when gpsFallback is an object', () => {
		const config = {
			gpsFallback: {
				permissionDenied: true,
				noApi: true,
				positionUnavailable: false,
				timeout: false,
				safetyTimer: false,
			},
		};
		expect(window.FFCGeofence.shouldAllow(config, 'permissionDenied')).toBe(true);
		expect(window.FFCGeofence.shouldAllow(config, 'noApi')).toBe(true);
		expect(window.FFCGeofence.shouldAllow(config, 'positionUnavailable')).toBe(false);
		expect(window.FFCGeofence.shouldAllow(config, 'timeout')).toBe(false);
		expect(window.FFCGeofence.shouldAllow(config, 'safetyTimer')).toBe(false);
	});

	it('falls back to the legacy "allow" / "block" string semantics when gpsFallback is a string', () => {
		expect(window.FFCGeofence.shouldAllow({ gpsFallback: 'allow' }, 'timeout')).toBe(true);
		expect(window.FFCGeofence.shouldAllow({ gpsFallback: 'block' }, 'timeout')).toBe(false);
		expect(window.FFCGeofence.shouldAllow({ gpsFallback: 'allow' }, 'permissionDenied')).toBe(true);
	});

	it('treats missing gpsFallback as block-by-default', () => {
		expect(window.FFCGeofence.shouldAllow({}, 'timeout')).toBe(false);
		expect(window.FFCGeofence.shouldAllow({ gpsFallback: null }, 'permissionDenied')).toBe(false);
		expect(window.FFCGeofence.shouldAllow(null, 'timeout')).toBe(false);
	});

	it('treats an unknown case key as block', () => {
		const config = { gpsFallback: { permissionDenied: true } };
		expect(window.FFCGeofence.shouldAllow(config, 'somethingElse')).toBe(false);
	});
});

// ----------------------------------------------------------------------
// validateGeolocation honours per-case fallback (no-API + GPS errors)
// ----------------------------------------------------------------------

describe('validateGeolocation — honours per-case fallback map', () => {
	it('shows the form when navigator.geolocation is absent AND noApi=allow', () => {
		const restoreLoc = installLocationHttps();
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: undefined,
		});
		const $w = mountForm(3001);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [{ lat: 0, lng: 0, radius: 1 }],
			gpsFallback: { noApi: true, permissionDenied: false, positionUnavailable: false, timeout: false, safetyTimer: false },
		});

		expect($w.hasClass('ffc-validated')).toBe(true);
		expect($w.find('.ffc-geofence-blocked').length).toBe(0);
		restoreLoc();
	});

	it('blocks when navigator.geolocation is absent AND noApi=block', () => {
		const restoreLoc = installLocationHttps();
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: undefined,
		});
		const $w = mountForm(3002);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [],
			gpsFallback: { noApi: false },
		});

		expect($w.hasClass('ffc-validated')).toBe(false);
		expect($w.find('.ffc-geofence-blocked').length).toBe(1);
		restoreLoc();
	});

	it('on PERMISSION_DENIED with permissionDenied=allow: shows the form', () => {
		const restoreLoc = installLocationHttps();
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: {
				getCurrentPosition: (_s, error) => {
					error({ code: 1, PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3 });
				},
			},
		});
		const $w = mountForm(3003);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [],
			gpsFallback: { permissionDenied: true, positionUnavailable: false, timeout: false, safetyTimer: false, noApi: false },
		});

		expect($w.hasClass('ffc-validated')).toBe(true);
		restoreLoc();
	});

	it('on PERMISSION_DENIED with permissionDenied=block: blocks + Reload button', () => {
		const restoreLoc = installLocationHttps();
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: {
				getCurrentPosition: (_s, error) => {
					error({ code: 1, PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3 });
				},
			},
		});
		const $w = mountForm(3004);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [],
			gpsFallback: { permissionDenied: false },
		});

		expect($w.hasClass('ffc-validated')).toBe(false);
		expect($w.find('.ffc-geofence-blocked').length).toBe(1);
		expect($w.find('.ffc-geofence-reload-btn').length).toBe(1);
		expect($w.find('.ffc-geofence-blocked').text()).toContain('Location access is required');
		restoreLoc();
	});

	it('on TIMEOUT with timeout=allow: shows the form', () => {
		const restoreLoc = installLocationHttps();
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: {
				getCurrentPosition: (_s, error) => {
					error({ code: 3, PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3 });
				},
			},
		});
		const $w = mountForm(3005);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [],
			gpsFallback: { timeout: true, permissionDenied: false, positionUnavailable: false, safetyTimer: false, noApi: false },
		});

		expect($w.hasClass('ffc-validated')).toBe(true);
		restoreLoc();
	});

	it('on POSITION_UNAVAILABLE with positionUnavailable=block: blocks with the unavailable message', () => {
		const restoreLoc = installLocationHttps();
		Object.defineProperty(window.navigator, 'geolocation', {
			configurable: true,
			value: {
				getCurrentPosition: (_s, error) => {
					error({ code: 2, PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3 });
				},
			},
		});
		const $w = mountForm(3006);

		window.FFCGeofence.validateGeolocation($w, {
			hideMode: 'message',
			areas: [],
			gpsFallback: { positionUnavailable: false },
		});

		expect($w.find('.ffc-geofence-blocked').text()).toContain("couldn't determine your location");
		expect($w.find('.ffc-geofence-reload-btn').length).toBe(1);
		restoreLoc();
	});
});

// ----------------------------------------------------------------------
// Safety timer + safetyTimer flag
// ----------------------------------------------------------------------

describe('applyGpsFallback — honours safetyTimer case', () => {
	it('safetyTimer=allow: shows the form', () => {
		const $w = mountForm(4001);
		window.FFCGeofence.applyGpsFallback($w, {
			hideMode: 'message',
			gpsFallback: { safetyTimer: true },
		});
		expect($w.hasClass('ffc-validated')).toBe(true);
	});

	it('safetyTimer=block: blocks with the safety-timeout message + Reload button', () => {
		const $w = mountForm(4002);
		window.FFCGeofence.applyGpsFallback($w, {
			hideMode: 'message',
			gpsFallback: { safetyTimer: false },
		});
		expect($w.find('.ffc-geofence-blocked').text()).toContain("didn't respond");
		expect($w.find('.ffc-geofence-reload-btn').length).toBe(1);
	});
});

// ----------------------------------------------------------------------
// Reload button — clicking calls location.reload()
// ----------------------------------------------------------------------

describe('Reload page button', () => {
	it('renders inside .ffc-geofence-blocked when handleBlocked is called with showReload=true', () => {
		const $w = mountForm(5001);
		window.FFCGeofence.handleBlocked($w, 'message', 'Test error', true);

		expect($w.find('.ffc-geofence-reload-btn').length).toBe(1);
		expect($w.find('.ffc-geofence-reload-btn').text()).toBe('Reload page');
	});

	it('does NOT render when handleBlocked is called without showReload', () => {
		const $w = mountForm(5002);
		window.FFCGeofence.handleBlocked($w, 'message', 'Test error');
		expect($w.find('.ffc-geofence-reload-btn').length).toBe(0);
	});

	it('calls window.location.reload() on click', () => {
		const restoreLoc = installLocationHttps();
		const $w = mountForm(5003);
		window.FFCGeofence.handleBlocked($w, 'message', 'Test error', true);

		window.$('.ffc-geofence-reload-btn').trigger('click');

		expect(window.location.reload).toHaveBeenCalledTimes(1);
		restoreLoc();
	});

	it('uses the localised label from ffcGeofenceConfig._global.strings.reloadPageBtn when present', () => {
		window.ffcGeofenceConfig = { _global: { strings: { reloadPageBtn: 'Recarregar página' } } };
		const $w = mountForm(5004);
		window.FFCGeofence.handleBlocked($w, 'message', 'Test', true);
		expect($w.find('.ffc-geofence-reload-btn').text()).toBe('Recarregar página');
		window.ffcGeofenceConfig = undefined;
	});
});
