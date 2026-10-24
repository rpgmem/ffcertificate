// FFC.Preflight.geoPermission() (#2048): a "denied" from the Permissions API
// is confirmed by one quick read before anyone acts on it, because Opera for
// Android reports it while it hands the position over.
import { describe, it, expect, beforeAll, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const define = (name, value) => Object.defineProperty(navigator, name, { configurable: true, value });

function permission(state) {
	define('permissions', { query: vi.fn(() => Promise.resolve({ state })) });
}

function device(read) {
	const getCurrentPosition = vi.fn(read);
	define('geolocation', { getCurrentPosition });
	return getCurrentPosition;
}

beforeAll(() => {
	loadScript('assets/js/ffc-core.js');
	delete window.FFC.Preflight;
	loadScript('assets/js/ffc-preflight.js');
});

afterEach(() => {
	vi.useRealTimers();
	define('permissions', undefined);
	define('geolocation', undefined);
});

describe('FFC.Preflight.geoPermission (#2048)', () => {
	it('passes granted and prompt through without reading the position', async () => {
		const read = device(() => {});
		for (const state of ['granted', 'prompt']) {
			permission(state);
			const answer = await window.FFC.Preflight.geoPermission();
			expect(answer.state).toBe(state);
			expect(answer.status).toEqual({ state });
		}
		expect(read).not.toHaveBeenCalled();
	});

	it('confirms a real block with one cached read', async () => {
		permission('denied');
		const read = device((success, error) => error({ code: 1 }));
		const answer = await window.FFC.Preflight.geoPermission();
		expect(answer.state).toBe('denied');
		expect(read).toHaveBeenCalledTimes(1);
		expect(read.mock.calls[0][2]).toEqual({ enableHighAccuracy: false, maximumAge: Infinity, timeout: 10000 });
	});

	it('reads granted when the device gives the position, and keeps none of it', async () => {
		permission('denied');
		device((success) => success({ coords: { latitude: -23.55, longitude: -46.63, accuracy: 22 } }));
		const answer = await window.FFC.Preflight.geoPermission();
		expect(answer.state).toBe('granted');
		expect(JSON.stringify(answer)).not.toContain('23.55');
	});

	it.each([[2, 'unavailable'], [3, 'timeout']])('reads prompt on code %i (%s), which says nothing about the permission', async (code) => {
		permission('denied');
		device((success, error) => error({ code }));
		expect((await window.FFC.Preflight.geoPermission()).state).toBe('prompt');
	});

	it('does not wait forever on a browser that never answers', async () => {
		vi.useFakeTimers();
		permission('denied');
		device(() => {});
		const pending = window.FFC.Preflight.geoPermission();
		await vi.advanceTimersByTimeAsync(11000);
		expect((await pending).state).toBe('prompt');
	});

	it('answers unknown when the browser does not say', async () => {
		expect(await window.FFC.Preflight.geoPermission()).toEqual({ state: 'unknown', status: null });

		define('permissions', { query: () => { throw new TypeError('geolocation'); } });
		expect((await window.FFC.Preflight.geoPermission()).state).toBe('unknown');

		define('permissions', { query: () => Promise.reject(new TypeError('geolocation')) });
		expect((await window.FFC.Preflight.geoPermission()).state).toBe('unknown');
	});

	it('takes a block as real when the browser has no geolocation to confirm with', async () => {
		permission('denied');
		define('geolocation', undefined);
		expect((await window.FFC.Preflight.geoPermission()).state).toBe('denied');
	});
});
