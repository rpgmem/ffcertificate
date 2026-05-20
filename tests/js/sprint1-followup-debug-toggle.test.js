// 6.6.4 follow-up (#361 Sprint 1) — diagnostic log gated by
// `debug_browser_env` toggle and moved from ffc-geofence-frontend.js
// to ffc-frontend.js.
//
// Pinned behavior:
//   - Toggle OFF (default) → console silent on script load
//   - Toggle ON → both [FFC Diagnostics] lines emit
//   - Module no longer fires from FFCGeofence.init (regression guard
//     against re-introducing the always-on behavior in geofence module)
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeEach(() => {
	delete window.FFC;
	delete window.ffc_ajax;
	delete window.ffcGeofenceConfig;
	delete window.FFCGeofence;
	document.body.innerHTML = '';
});

function setupModernAPIs() {
	Object.defineProperty(navigator, 'serviceWorker', {
		configurable: true,
		value: { getRegistrations: () => Promise.resolve([]) },
	});
	Object.defineProperty(navigator, 'permissions', {
		configurable: true,
		value: { query: () => Promise.resolve({ state: 'granted' }) },
	});
}

describe('Sprint 1 (#361) — browser-env diagnostic log gating', () => {
	it('does NOT emit anything when ffc_ajax.debug_browser_env is false (default)', async () => {
		setupModernAPIs();
		window.ffc_ajax = {
			ajax_url: '/x',
			nonce: 'n',
			debug_browser_env: false,
			strings: {},
		};
		const infoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});

		loadScript('assets/js/ffc-core.js');
		loadScript('assets/js/ffc-frontend-helpers.js');
		loadScript('assets/js/ffc-frontend.js');
		await Promise.resolve();
		await Promise.resolve();

		expect(infoSpy).not.toHaveBeenCalled();
	});

	it('emits both [FFC Diagnostics] lines when debug_browser_env is true', async () => {
		setupModernAPIs();
		window.ffc_ajax = {
			ajax_url: '/x',
			nonce: 'n',
			debug_browser_env: true,
			strings: {},
		};
		const infoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});

		loadScript('assets/js/ffc-core.js');
		loadScript('assets/js/ffc-frontend-helpers.js');
		loadScript('assets/js/ffc-frontend.js');
		await Promise.resolve();
		await Promise.resolve();
		await Promise.resolve();

		expect(infoSpy).toHaveBeenCalledWith(
			'[FFC Diagnostics] Service workers:', 0, []
		);
		expect(infoSpy).toHaveBeenCalledWith(
			'[FFC Diagnostics] Clipboard write permission:', 'granted'
		);
	});

	it('FFCGeofence.init no longer fires the diagnostic log (regression guard)', async () => {
		// The function was MOVED out of geofence-frontend.js. Loading
		// just the geofence module — even with toggle ON in ffc_ajax —
		// must NOT emit the diagnostics. The new home is ffc-frontend.js.
		setupModernAPIs();
		window.ffc_ajax = {
			ajax_url: '/x',
			debug_browser_env: true,
		};
		const infoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});

		loadScript('assets/js/ffc-geofence-frontend.js');
		window.ffcGeofenceConfig = { _global: { debug: false } };
		window.FFCGeofence.init();
		await Promise.resolve();
		await Promise.resolve();

		const diagnosticCalls = infoSpy.mock.calls.filter(c =>
			typeof c[0] === 'string' && c[0].startsWith('[FFC Diagnostics]')
		);
		expect(diagnosticCalls).toHaveLength(0);
	});
});
