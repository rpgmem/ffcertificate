// 6.6.4 follow-up (#361 Sprint 1) — diagnostic log gated by
// `debug_browser_env` toggle and moved from ffc-geofence-frontend.js
// to ffc-frontend.js.
//
// Pinned behavior:
//   - Toggle OFF (default) → console silent on script load
//   - Toggle ON → both [FFC Diagnostics] lines emit
//   - Module no longer fires from FFCGeofence.init (regression guard
//     against re-introducing the always-on behavior in geofence module)
//
// Notes on test isolation:
//   The IIFE inside ffc-frontend.js registers a jQuery ready handler
//   that touches `FFC.Frontend.Masks` (line ~614). Once the script is
//   loaded into the jsdom window, that handler is queued and fires on
//   the next microtask. If a subsequent test `delete window.FFC` before
//   the handler runs, the deferred handler throws `FFC is not defined`,
//   which Vitest's CI runner (with coverage instrumentation) elevates
//   to a test failure even though the assertion itself would pass.
//
//   So we DO NOT delete `window.FFC`. Each test sets up its own
//   `window.ffc_ajax` BEFORE loadScript so the IIFE's reads pick the
//   right state, and the FFC namespace from a prior test stays in
//   place (its handlers are idempotent).
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeEach(() => {
	delete window.ffc_ajax;
	delete window.ffcGeofenceConfig;
	delete window.FFCGeofence;
	document.body.innerHTML = '';
});

afterEach(async () => {
	// The diagnostic log path awaits navigator.serviceWorker / permissions
	// promises, so a debug-ON test (test 2) emits its [FFC Diagnostics]
	// console.info calls on later microtasks. Drain them here — while the
	// emitting test's own spy is still installed — so they don't bleed into
	// the next test's spy. Vitest 4's tightened inter-test flushing exposed
	// this; v2 happened to settle the calls within each test.
	await new Promise((r) => setTimeout(r, 0));
	vi.restoreAllMocks();
});

function setupModernAPIs() {
	Object.defineProperty(navigator, 'serviceWorker', {
		configurable: true,
		// One registration so the `regs.map(r => r.scope)` callback runs.
		value: { getRegistrations: () => Promise.resolve([{ scope: 'https://example.com/' }]) },
	});
	Object.defineProperty(navigator, 'permissions', {
		configurable: true,
		value: { query: () => Promise.resolve({ state: 'granted' }) },
	});
}

describe('browser-env diagnostic log gating (#361)', () => {
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

		// Filter for our specific lines — the IIFE can also emit other
		// console.info calls unrelated to this test (e.g. FFC namespace
		// init noise from other modules previously loaded in the same
		// jsdom window).
		const diagnosticCalls = infoSpy.mock.calls.filter(c =>
			typeof c[0] === 'string' && c[0].startsWith('[FFC Diagnostics]')
		);
		expect(diagnosticCalls).toHaveLength(0);
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
			'[FFC Diagnostics] Service workers:', 1, ['https://example.com/']
		);
		expect(infoSpy).toHaveBeenCalledWith(
			'[FFC Diagnostics] Clipboard write permission:', 'granted'
		);
	});

	it('reports "API not available" when the modern browser APIs are absent', async () => {
		// Force both APIs undefined so the else-branches fire.
		Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: undefined });
		Object.defineProperty(navigator, 'permissions', { configurable: true, value: undefined });
		window.ffc_ajax = { ajax_url: '/x', nonce: 'n', debug_browser_env: true, strings: {} };
		const infoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});

		loadScript('assets/js/ffc-core.js');
		loadScript('assets/js/ffc-frontend-helpers.js');
		loadScript('assets/js/ffc-frontend.js');
		await Promise.resolve();
		await Promise.resolve();

		expect(infoSpy).toHaveBeenCalledWith('[FFC Diagnostics] Service workers: API not available');
		expect(infoSpy).toHaveBeenCalledWith('[FFC Diagnostics] Permissions API: not available');
	});

	it('reports "not queryable" when the clipboard permission query rejects', async () => {
		Object.defineProperty(navigator, 'serviceWorker', {
			configurable: true,
			value: { getRegistrations: () => Promise.resolve([]) },
		});
		Object.defineProperty(navigator, 'permissions', {
			configurable: true,
			value: { query: () => Promise.reject(new Error('nope')) },
		});
		window.ffc_ajax = { ajax_url: '/x', nonce: 'n', debug_browser_env: true, strings: {} };
		const infoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});

		loadScript('assets/js/ffc-core.js');
		loadScript('assets/js/ffc-frontend-helpers.js');
		loadScript('assets/js/ffc-frontend.js');
		await Promise.resolve();
		await Promise.resolve();
		await Promise.resolve();

		expect(infoSpy).toHaveBeenCalledWith('[FFC Diagnostics] Clipboard write permission: not queryable');
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
		loadScript('assets/js/ffc-geofence-datetime.js');
		loadScript('assets/js/ffc-geofence-gps.js');
		loadScript('assets/js/ffc-geofence-preflight.js');
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
