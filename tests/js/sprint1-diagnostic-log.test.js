// 6.6.4 Sprint 1 — diagnostic log in FFCGeofence.init.
//
// Emits console.info lines for:
//   - navigator.serviceWorker.getRegistrations()
//   - navigator.permissions.query({name: 'clipboard-write'})
//
// Pure observability — no UI, no behavior change on the form gates.
// Pinned because support relies on these breadcrumbs when triaging
// "Security check failed" / "form didn't appear" reports.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-geofence-frontend.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
	window.ffcGeofenceConfig = undefined;
});

afterEach(() => {
	vi.restoreAllMocks();
});

describe('FFCGeofence diagnostic log (Sprint 1)', () => {
	it('logs service worker registrations and clipboard permission on init', async () => {
		const infoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});

		// Stub navigator.serviceWorker.
		Object.defineProperty(navigator, 'serviceWorker', {
			configurable: true,
			value: {
				getRegistrations: () => Promise.resolve([
					{ scope: 'https://example.org/sw-scope-1/' },
				]),
			},
		});

		// Stub navigator.permissions.query (Chromium style).
		Object.defineProperty(navigator, 'permissions', {
			configurable: true,
			value: {
				query: ({ name }) => {
					if (name === 'clipboard-write') {
						return Promise.resolve({ state: 'granted' });
					}
					return Promise.reject(new TypeError('Unknown permission'));
				},
			},
		});

		window.ffcGeofenceConfig = { _global: { debug: false } };
		window.FFCGeofence.init();

		// Both API touches return promises — flush microtasks.
		await Promise.resolve();
		await Promise.resolve();
		await Promise.resolve();

		expect(infoSpy).toHaveBeenCalledWith(
			'[FFC Diagnostics] Service workers:', 1, ['https://example.org/sw-scope-1/']
		);
		expect(infoSpy).toHaveBeenCalledWith(
			'[FFC Diagnostics] Clipboard write permission:', 'granted'
		);
	});

	it('silently degrades when navigator.serviceWorker is unavailable (legacy mobile Safari)', async () => {
		const infoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});

		// Remove the API.
		Object.defineProperty(navigator, 'serviceWorker', {
			configurable: true,
			value: undefined,
		});
		Object.defineProperty(navigator, 'permissions', {
			configurable: true,
			value: undefined,
		});

		window.ffcGeofenceConfig = { _global: { debug: false } };
		window.FFCGeofence.init();
		await Promise.resolve();
		await Promise.resolve();

		// Must NOT throw. Each line falls back to the "not available" branch.
		expect(infoSpy).toHaveBeenCalledWith('[FFC Diagnostics] Service workers: API not available');
		expect(infoSpy).toHaveBeenCalledWith('[FFC Diagnostics] Permissions API: not available');
	});
});
