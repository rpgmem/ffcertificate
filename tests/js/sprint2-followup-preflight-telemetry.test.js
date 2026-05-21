// 6.6.4 follow-up (#361 Sprint 2) — pre-flight bail telemetry.
//
// When the cookie / GPS-denied / GPS-prompt banner renders, the
// client fires a fire-and-forget AJAX ping (ffc_log_preflight_bail)
// so an ActivityLog row gets written server-side. Admin visibility
// into the volume of UX walls is the goal.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-geofence-frontend.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
	window.ffcGeofenceConfig = undefined;
	window.$.fx.off = true;
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'test-nonce',
		strings: {},
	};
	Object.defineProperty(navigator, 'userAgent', {
		configurable: true,
		value: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
	});
	Object.defineProperty(navigator, 'cookieEnabled', { configurable: true, value: true });
	delete window.location;
	window.location = { protocol: 'https:', hostname: 'example.org' };
});

afterEach(() => {
	vi.restoreAllMocks();
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
			geo: geoConfig || { enabled: false },
		},
		_global: {
			debug: false,
			strings: {
				cookieBlockedTitle: 'Cookies blocked',
				cookieBlockedBody: 'body',
				cookieBlockedHowDesktop: 'how',
				cookieTryAnyway: 'Try',
				gpsDeniedTitle: 'GPS denied',
				gpsDeniedBody: 'body',
				gpsDeniedHowDesktop: 'how',
				gpsTryAnyway: 'Try',
				gpsPromptTitle: 'GPS prompt',
				gpsPromptBody: 'body',
				gpsPromptContinue: 'Continue',
			},
		},
	};
}

function spyOnFFCRequest() {
	const spy = vi.fn(() => Promise.resolve({}));
	window.FFC.request = spy;
	return spy;
}

describe('Sprint 2 (#361) — telemetry ping on banner render', () => {
	it('cookie banner fires ffc_log_preflight_bail with reason=cookies', () => {
		Object.defineProperty(navigator, 'cookieEnabled', { configurable: true, value: false });
		setupForm(42);
		const spy = spyOnFFCRequest();

		window.FFCGeofence.init();

		expect(spy).toHaveBeenCalledWith('ffc_log_preflight_bail', {
			form_id: 42,
			reason: 'cookies',
		});
	});

	it('GPS denied banner fires ffc_log_preflight_bail with reason=gps_denied', async () => {
		Object.defineProperty(navigator, 'permissions', {
			configurable: true,
			value: { query: () => Promise.resolve({ state: 'denied' }) },
		});
		Object.defineProperty(navigator, 'geolocation', {
			configurable: true,
			value: { getCurrentPosition: vi.fn() },
		});
		setupForm(42, { enabled: true, gpsEnabled: true });
		const spy = spyOnFFCRequest();

		window.FFCGeofence.init();
		await Promise.resolve();
		await Promise.resolve();

		const denied = spy.mock.calls.find(c => c[1] && c[1].reason === 'gps_denied');
		expect(denied).toBeDefined();
		expect(denied[1].form_id).toBe(42);
	});

	it('GPS prompt banner fires ffc_log_preflight_bail with reason=gps_prompt', async () => {
		Object.defineProperty(navigator, 'permissions', {
			configurable: true,
			value: { query: () => Promise.resolve({ state: 'prompt' }) },
		});
		Object.defineProperty(navigator, 'geolocation', {
			configurable: true,
			value: { getCurrentPosition: vi.fn() },
		});
		setupForm(42, { enabled: true, gpsEnabled: true });
		const spy = spyOnFFCRequest();

		window.FFCGeofence.init();
		await Promise.resolve();
		await Promise.resolve();

		const prompt = spy.mock.calls.find(c => c[1] && c[1].reason === 'gps_prompt');
		expect(prompt).toBeDefined();
	});

	it('happy path (no banner) does NOT fire any telemetry ping', async () => {
		// All gates pass: cookies OK, no datetime gate, no geo gate.
		setupForm(42);
		const spy = spyOnFFCRequest();

		window.FFCGeofence.init();
		await Promise.resolve();

		const calls = spy.mock.calls.filter(c => c[0] === 'ffc_log_preflight_bail');
		expect(calls).toHaveLength(0);
	});

	it('swallows errors when FFC.request rejects (fire-and-forget contract)', () => {
		Object.defineProperty(navigator, 'cookieEnabled', { configurable: true, value: false });
		setupForm(42);
		// FFC.request rejects.
		window.FFC.request = vi.fn(() => Promise.reject(new Error('network')));

		// Should not throw.
		expect(() => window.FFCGeofence.init()).not.toThrow();
	});
});
