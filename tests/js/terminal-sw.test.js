/**
 * Check-in terminal service worker (#2783): the page shell kept so a paired
 * terminal reopens without a connection, REST never cached, old caches
 * deleted on activate.
 */
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

const ORIGIN = window.location.origin;
const SCOPE = ORIGIN + '/terminal/';

function memoryCaches() {
	const stores = new Map();
	const open = (name) => {
		if (!stores.has(name)) {
			const entries = new Map();
			stores.set(name, {
				entries,
				put: async (key, response) => { entries.set(typeof key === 'string' ? key : key.url, response); },
				match: async (key) => entries.get(typeof key === 'string' ? key : key.url),
			});
		}
		return Promise.resolve(stores.get(name));
	};
	return {
		stores,
		open,
		keys: async () => [...stores.keys()],
		delete: async (name) => stores.delete(name),
	};
}

function ok(body) {
	return { ok: true, body, clone() { return ok(body); } };
}

const listeners = {};
let network;

beforeAll(() => {
	window.ffcTerminalCache = 'ffc-terminal-9.9.9';
	window.registration = { scope: SCOPE };
	window.skipWaiting = () => Promise.resolve();
	window.clients = { claim: () => Promise.resolve('claimed') };
	window.caches = memoryCaches();
	window.fetch = (request) => network(request);
	const add = window.addEventListener.bind(window);
	window.addEventListener = (type, fn) => {
		if (['install', 'activate', 'message', 'fetch'].includes(type)) {
			listeners[type] = fn;
			return;
		}
		add(type, fn);
	};
	globalThis.Response = globalThis.Response || function () {};
	globalThis.Response.error = () => ({ error: true });
	loadScript('assets/js/ffc-terminal-sw.js');
});

beforeEach(() => {
	window.caches.stores.clear();
	network = () => Promise.reject(new TypeError('offline'));
});

const S = () => window.FFCTerminalShell;

function fetchEvent(request) {
	const event = { request, answer: null, respondWith(p) { this.answer = p; } };
	listeners.fetch(event);
	return event;
}

describe('the terminal shell worker', () => {
	it('names its cache from the version the site prepended', () => {
		expect(S().cache).toBe('ffc-terminal-9.9.9');
	});

	it('keeps the document on every answer and serves it from the cache when the network fails', async () => {
		network = () => Promise.resolve(ok('<html>page</html>'));
		const online = fetchEvent({ method: 'GET', mode: 'navigate', url: SCOPE });
		expect((await online.answer).body).toBe('<html>page</html>');
		await new Promise((resolve) => setTimeout(resolve, 0));

		network = () => Promise.reject(new TypeError('offline'));
		const offline = fetchEvent({ method: 'GET', mode: 'navigate', url: SCOPE + '?utm=1' });
		expect((await offline.answer).body).toBe('<html>page</html>', 'any way into the page finds the kept document');
	});

	it('leaves another page under the scope to the network, so it never becomes the shell (#2813)', async () => {
		network = () => Promise.resolve(ok('<html>page</html>'));
		await fetchEvent({ method: 'GET', mode: 'navigate', url: SCOPE }).answer;
		await new Promise((resolve) => setTimeout(resolve, 0));

		network = () => Promise.resolve(ok('<html>install</html>'));
		const install = fetchEvent({ method: 'GET', mode: 'navigate', url: SCOPE + 'install/' });
		expect(install.answer).toBeNull();
		await new Promise((resolve) => setTimeout(resolve, 0));

		network = () => Promise.reject(new TypeError('offline'));
		const offline = fetchEvent({ method: 'GET', mode: 'navigate', url: SCOPE });
		expect((await offline.answer).body).toBe('<html>page</html>', 'the terminal\'s offline copy is still the terminal');
	});

		it('serves an error, not a blank answer, when nothing was kept yet', async () => {
		const event = fetchEvent({ method: 'GET', mode: 'navigate', url: SCOPE });
		expect(await event.answer).toEqual({ error: true });
	});

	it('keeps the addresses the page sends, and only same-origin ones that are not REST', async () => {
		const fetched = [];
		network = (address) => {
			fetched.push(address);
			return Promise.resolve(ok('file:' + address));
		};
		const kept = [];
		const event = {
			origin: ORIGIN,
			data: {
				type: 'ffc-terminal-keep',
				urls: [
					ORIGIN + '/wp-content/plugins/ffcertificate/assets/js/ffc-terminal-kiosk.min.js?ver=1',
					ORIGIN + '/wp-json/ffc/v1/terminal/schedule',
					ORIGIN + '/?rest_route=/ffc/v1/presence/revocations',
					'https://elsewhere.example/x.js',
					'javascript:alert(1)',
				],
			},
			waitUntil(p) { kept.push(p); },
		};
		listeners.message(event);
		await Promise.all(kept);

		const store = window.caches.stores.get('ffc-terminal-9.9.9');
		expect([...store.entries.keys()]).toEqual([ORIGIN + '/wp-content/plugins/ffcertificate/assets/js/ffc-terminal-kiosk.min.js?ver=1']);
		expect(fetched).toEqual([ORIGIN + '/wp-content/plugins/ffcertificate/assets/js/ffc-terminal-kiosk.min.js?ver=1'], 'only the kept address reaches the network');

		network = () => Promise.reject(new TypeError('offline'));
		const asset = fetchEvent({ method: 'GET', mode: 'no-cors', url: ORIGIN + '/wp-content/plugins/ffcertificate/assets/js/ffc-terminal-kiosk.min.js?ver=1' });
		expect((await asset.answer).body).toContain('ffc-terminal-kiosk');
	});

	it('ignores messages it does not know', () => {
		let waited = false;
		listeners.message({ origin: ORIGIN, data: { type: 'other' }, waitUntil() { waited = true; } });
		listeners.message({ origin: ORIGIN, waitUntil() { waited = true; } });
		expect(waited).toBe(false);
	});

	it('ignores a keep request from any origin but its own (#2809)', () => {
		let waited = false;
		const keep = { type: 'ffc-terminal-keep', urls: [ORIGIN + '/wp-content/x.js'] };
		listeners.message({ origin: 'https://elsewhere.example', data: keep, waitUntil() { waited = true; } });
		listeners.message({ origin: '', data: keep, waitUntil() { waited = true; } });
		listeners.message({ data: keep, waitUntil() { waited = true; } });
		expect(waited).toBe(false);
		expect(S().trusted(null)).toBe(false);
		expect(S().trusted({ origin: ORIGIN })).toBe(true);
	});

	it('rebuilds a kept address on its own origin, keeping only the path and the query (#2809)', () => {
		expect(S().own('/wp-content/x.js?ver=2#frag')).toBe(ORIGIN + '/wp-content/x.js?ver=2');
		expect(S().own(ORIGIN + '/a/b.css')).toBe(ORIGIN + '/a/b.css');
		expect(S().own('https://elsewhere.example/a.js')).toBe('');
		expect(S().own('//elsewhere.example/a.js')).toBe('');
		expect(S().own('javascript:alert(1)')).toBe('');
		expect(S().own(ORIGIN + '/wp-json/ffc/v1/x')).toBe('');
		expect(S().own('http://[bad')).toBe('');
		expect(S().keepable('/ok.js')).toBe(true);
	});

	it('never answers a REST call, a write or another origin', () => {
		expect(fetchEvent({ method: 'GET', mode: 'cors', url: ORIGIN + '/wp-json/ffc/v1/terminal/readings' }).answer).toBeNull();
		expect(fetchEvent({ method: 'GET', mode: 'cors', url: ORIGIN + '/?rest_route=/ffc/v1/terminal/schedule' }).answer).toBeNull();
		expect(fetchEvent({ method: 'POST', mode: 'cors', url: SCOPE }).answer).toBeNull();
		expect(fetchEvent({ method: 'GET', mode: 'no-cors', url: 'https://cdn.example/x.js' }).answer).toBeNull();
		expect(S().route(null)).toBeNull();
	});

	it('deletes the terminal caches of earlier versions on activate, and leaves other caches alone', async () => {
		await window.caches.open('ffc-terminal-9.9.8');
		await window.caches.open('ffc-terminal-9.9.9');
		await window.caches.open('someone-else');
		let done;
		listeners.activate({ waitUntil(p) { done = p; } });
		expect(await done).toBe('claimed');
		expect([...window.caches.stores.keys()].sort()).toEqual(['ffc-terminal-9.9.9', 'someone-else']);
	});

	it('takes over at once when installed', async () => {
		let done;
		listeners.install({ waitUntil(p) { done = p; } });
		await expect(done).resolves.toBeUndefined();
	});
});
