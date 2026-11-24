/**
 * Presence screen of the installable app (#2742): the byte layout, the QR,
 * activation, renewal, the events list and the rotating draw.
 *
 * The device side runs on the real WebCrypto of Node, so a signature the
 * script makes is checked with the same API a terminal uses.
 */
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { webcrypto } from 'node:crypto';
import { loadScript } from './helpers.js';

const NOW = 1800000000;
const USER = 7;

beforeAll(() => {
	if (!window.crypto || !window.crypto.subtle) {
		Object.defineProperty(window, 'crypto', { value: webcrypto, configurable: true });
	}
	loadScript('libs/js/qrcodegen-1.8.0.js');
	loadScript('assets/js/ffc-presence-app.js');
});

const P = () => window.FFCPresence;
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
const settle = async () => { for (let i = 0; i < 20; i++) { await flush(); } };

const STRINGS = {
	storage: 'storage',
	activating: 'activating',
	activated: 'activated',
	failed: 'failed',
	next: 'next %d',
	expired: 'expired',
	qrLabel: 'qr label',
	dismiss: 'dismiss',
	fillForm: 'fill form',
	current: 'Now: %1$s since %2$s.',
	otherPhone: 'another phone',
	changes: '%1$d of %2$d / %3$d',
	dayOne: '1 day',
	days: '%d days',
};

function markup() {
	document.body.innerHTML = `
		<div data-ffc-presence>
			<p data-ffc-presence-part="status"></p>
			<div data-ffc-presence-part="unsupported" data-ffc-presence-view="unsupported" hidden></div>
			<div data-ffc-presence-part="activate-view" data-ffc-presence-view="activate" hidden>
				<button type="button" data-ffc-presence-part="activate">Activate</button>
			</div>
			<div data-ffc-presence-part="code-view" data-ffc-presence-view="code" hidden>
				<ul data-ffc-presence-part="pending" hidden></ul>
				<h2 data-ffc-presence-part="title"></h2>
				<p data-ffc-presence-part="when"></p>
				<div data-ffc-presence-part="picker" hidden><select data-ffc-presence-part="event"></select></div>
				<p data-ffc-presence-part="attendance" hidden></p>
				<p data-ffc-presence-part="empty" hidden></p>
				<div data-ffc-presence-part="card"><div data-ffc-presence-part="qr"></div></div>
				<progress data-ffc-presence-part="progress"></progress>
				<p data-ffc-presence-part="countdown"></p>
			</div>
		</div>`;
	return document.querySelector('[data-ffc-presence]');
}

function memoryStore(initial) {
	const data = new Map(initial ? [['device', initial]] : []);
	return {
		data,
		get: (name) => Promise.resolve(data.get(name)),
		set: (name, value) => { data.set(name, value); return Promise.resolve(); },
	};
}

function reply(status, body) {
	return Promise.resolve({ ok: status < 400, json: () => Promise.resolve(body) });
}

const EVENTS = [
	{ target_type: 'audience_booking', target_id: 42, title: 'Library', date: '16/01', time: 'All day', ticket: '' },
	{ target_type: 'audience_booking', target_id: 43, title: 'Lab', date: '17/01', time: '', ticket: 'AQ' },
];

async function deviceState(overrides) {
	const pair = await webcrypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign', 'verify']);
	return {
		pair,
		state: Object.assign({
			userId: USER,
			deviceId: 'dev',
			key: pair.privateKey,
			keyId: 'kid',
			attestation: P().toB64url(new Uint8Array([1, 2, 3])),
			expires: NOW + 6 * 86400,
			name: 'Maria S.',
			events: EVENTS.slice(),
			chosen: '',
		}, overrides || {}),
	};
}

function rest(path) {
	return 'https://example.org/wp-json/ffc/v1/presence/' + path;
}

describe('presence app helpers', () => {
	it('writes Base45 as RFC 9285 does', () => {
		const enc = (s) => new TextEncoder().encode(s);
		expect(P().base45(enc('AB'))).toBe('BB8');
		expect(P().base45(enc('Hello!!'))).toBe('%69 VD92EX0');
		expect(P().base45(enc('base-45'))).toBe('UJCLQE7W581');
		expect(P().base45(new Uint8Array(0))).toBe('');
	});

	it('round-trips base64url and refuses text that is not', () => {
		const bytes = new Uint8Array([0, 250, 251, 252, 253, 254, 255]);
		expect(Array.from(P().fromB64url(P().toB64url(bytes)))).toEqual(Array.from(bytes));
		expect(P().fromB64url('not base64!').length).toBe(0);
		expect(P().fromB64url(undefined).length).toBe(0);
	});

	it('lays the body out as the PHP verifier reads it', () => {
		const body = P().codeBody({
			attestation: new Uint8Array([9, 9]),
			targetType: 'audience_booking',
			targetId: 258,
			readAt: 0x01020304,
			ticket: new Uint8Array([5]),
		});
		expect(Array.from(body)).toEqual([1, 0, 2, 9, 9, 1, 0, 0, 1, 2, 1, 2, 3, 4, 1, 5]);
		expect(P().codeBody({ attestation: new Uint8Array(1), targetType: 'appointment', targetId: 1, readAt: 1 })).toBeNull();
		expect(P().codeBody({ attestation: new Uint8Array(1), targetType: 'audience_booking', targetId: 1, readAt: 1, ticket: new Uint8Array(256) })).toBeNull();
	});

	it('signs the reading with the device key, in the QR alphanumeric set', async () => {
		const pair = await webcrypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign', 'verify']);
		const reading = { attestation: new Uint8Array([1, 2, 3]), targetType: 'audience_booking', targetId: 42, readAt: NOW };
		const text = await P().buildCode(reading, pair.privateKey);

		expect(text.startsWith(P().PREFIX)).toBe(true);
		expect(text).toMatch(/^[0-9A-Z $%*+\-./:]+$/);

		const body = P().codeBody(reading);
		const decoded = decodeBase45(text.slice(4));
		const signature = decoded.slice(body.length);
		const signed = concat(new TextEncoder().encode('FFC-PRESENCE-READING\u0000'), body);
		expect(signature.length).toBe(64);
		expect(await webcrypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, pair.publicKey, signature, signed)).toBe(true);
		expect(await P().buildCode(Object.assign({}, reading, { targetType: 'x' }), pair.privateKey)).toBeNull();
	});

	it('draws the code as an SVG of modules, black on white with a quiet zone', () => {
		const drawn = P().qrSvg('FFP1ABC');
		expect(drawn.version).toBe(1);
		expect(drawn.svg).toContain('viewBox="0 0 29 29"');
		expect(drawn.svg).toContain('fill="#fff"');
		expect(drawn.svg).toContain('fill="#000"');

		const lib = window.qrcodegen;
		window.qrcodegen = undefined;
		expect(P().qrSvg('FFP1')).toBeNull();
		window.qrcodegen = lib;
	});
});

describe('presence app storage', () => {
	it('is unavailable without IndexedDB', () => {
		const idb = window.indexedDB;
		window.indexedDB = undefined;
		expect(P().idbStore()).toBeNull();
		window.indexedDB = idb;
	});

	it('reads and writes one object store', async () => {
		const values = new Map();
		const request = (result) => {
			const r = { result };
			setTimeout(() => r.onsuccess && r.onsuccess());
			return r;
		};
		const fake = {
			open: vi.fn(() => {
				const r = {};
				r.result = {
					createObjectStore: vi.fn(),
					transaction: () => ({
						objectStore: () => ({
							get: (name) => request(values.get(name)),
							put: (value, name) => { values.set(name, value); return request(name); },
						}),
					}),
				};
				setTimeout(() => { r.onupgradeneeded(); r.onsuccess(); });
				return r;
			}),
		};
		const idb = window.indexedDB;
		window.indexedDB = fake;
		const store = P().idbStore();
		await store.set('device', { a: 1 });
		expect(await store.get('device')).toEqual({ a: 1 });
		expect(fake.open).toHaveBeenCalledTimes(1);
		window.indexedDB = idb;
	});

	it('reports a database that will not open', async () => {
		const idb = window.indexedDB;
		window.indexedDB = {
			open: () => {
				const r = { error: new Error('blocked') };
				setTimeout(() => r.onerror());
				return r;
			},
		};
		await expect(P().idbStore().get('device')).rejects.toThrow('blocked');
		window.indexedDB = idb;
	});

	it('reports a request that fails', async () => {
		const idb = window.indexedDB;
		window.indexedDB = {
			open: () => {
				const r = {
					result: {
						transaction: () => ({
							objectStore: () => ({
								get: () => {
									const q = { error: new Error('read') };
									setTimeout(() => q.onerror());
									return q;
								},
							}),
						}),
					},
				};
				setTimeout(() => r.onsuccess());
				return r;
			},
		};
		await expect(P().idbStore().get('device')).rejects.toThrow('read');
		window.indexedDB = idb;
	});
});

describe('presence app screen', () => {
	let clock;

	beforeEach(() => {
		clock = NOW * 1000;
		window.ffcPresence = { rest: rest(''), nonce: 'n', userId: USER, strings: STRINGS };
	});

	afterEach(() => {
		delete window.ffcPresence;
		document.body.innerHTML = '';
	});

	const boot = (store, fetch) => P().boot({ store, fetch, now: () => clock });

	it('does nothing without its configuration', () => {
		delete window.ffcPresence;
		markup();
		expect(P().boot()).toEqual([]);
	});

	it('says so when the browser cannot keep a key', async () => {
		const el = markup();
		boot(null, vi.fn());
		await settle();
		expect(el.querySelector('[data-ffc-presence-view="unsupported"]').hidden).toBe(false);
	});

	it('says so when the storage fails', async () => {
		const el = markup();
		boot({ get: () => Promise.reject(new Error('x')), set: vi.fn() }, vi.fn());
		await settle();
		expect(el.querySelector('[data-ffc-presence-part="status"]').textContent).toBe('storage');
		expect(el.querySelector('[data-ffc-presence-view="unsupported"]').hidden).toBe(false);
	});

	it('asks to activate a phone it has never seen, or one kept for another account', async () => {
		const { state } = await deviceState({ userId: 99 });
		const el = markup();
		boot(memoryStore(state), vi.fn());
		await settle();
		expect(el.querySelector('[data-ffc-presence-view="activate"]').hidden).toBe(false);
		expect(el.querySelector('[data-ffc-presence-view="code"]').hidden).toBe(true);
	});

	it('activates with a proof the site can verify, then shows the code', async () => {
		const el = markup();
		const store = memoryStore();
		let sent;
		const fetch = vi.fn((url, options) => {
			if (url === rest('enrol')) {
				sent = JSON.parse(options.body);
				expect(options.headers['X-WP-Nonce']).toBe('n');
				return reply(200, { key_id: 'kid', attestation: P().toB64url(new Uint8Array([1])), expires: NOW + 7 * 86400, name: 'Maria S.' });
			}
			return reply(200, { events: EVENTS });
		});
		const intervals = vi.spyOn(window, 'setInterval');
		const [app] = boot(store, fetch);
		await settle();

		el.querySelector('[data-ffc-presence-part="activate"]').click();
		await settle();
		expect(intervals).toHaveBeenCalledTimes(1);
		intervals.mockRestore();

		const publicKey = await webcrypto.subtle.importKey('raw', P().fromB64url(sent.public_key), { name: 'ECDSA', namedCurve: 'P-256' }, false, ['verify']);
		const statement = new TextEncoder().encode('FFC-PRESENCE-ENROL\u0000' + USER + '|' + sent.device_id + '|' + NOW);
		expect(await webcrypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, publicKey, P().fromB64url(sent.proof), statement)).toBe(true);
		expect(sent.timestamp).toBe(NOW);

		expect(store.data.get('device').keyId).toBe('kid');
		expect(el.querySelector('[data-ffc-presence-view="code"]').hidden).toBe(false);
		expect(el.querySelector('[data-ffc-presence-part="qr"] svg')).not.toBeNull();
		expect(el.querySelector('[data-ffc-presence-part="qr"]').getAttribute('aria-label')).toBe('qr label');
		expect(el.querySelector('[data-ffc-presence-part="countdown"]').textContent).toMatch(/ · Maria S\.$/);
		expect(el.querySelector('[data-ffc-presence-part="picker"]').hidden).toBe(false, 'two events: the picker shows');
		expect(el.querySelectorAll('option').length).toBe(2);
		app.stop();
	});

	it('keeps the device id and gives the button back when activation fails', async () => {
		const el = markup();
		const store = memoryStore();
		const fetch = vi.fn(() => reply(403, { code: 'ffc_presence_reserved', message: 'reserved' }));
		const [app] = boot(store, fetch);
		await settle();

		const button = el.querySelector('[data-ffc-presence-part="activate"]');
		button.click();
		await settle();
		expect(el.querySelector('[data-ffc-presence-part="status"]').textContent).toBe('reserved');
		expect(button.disabled).toBe(false);
		const first = app.state.deviceId;
		expect(first).toMatch(/^[A-Za-z0-9_-]{24}$/);

		fetch.mockImplementation(() => Promise.reject(new Error('')));
		button.click();
		await settle();
		expect(el.querySelector('[data-ffc-presence-part="status"]').textContent).toBe('failed');
		expect(app.state.deviceId).toBe(first);
	});

	it('names an error the site gives without a message plainly', async () => {
		markup();
		const fetch = vi.fn(() => reply(500, null));
		const [app] = boot(memoryStore(), fetch);
		await settle();
		await expect(app.request('GET', 'events')).rejects.toThrow('request failed');
	});

	it('renews an attestation close to expiry before reading the events', async () => {
		const { state } = await deviceState({ expires: NOW + 86400, events: [] });
		markup();
		const store = memoryStore(state);
		const paths = [];
		const fetch = vi.fn((url) => {
			paths.push(url);
			if (url === rest('renew')) {
				return reply(200, { attestation: 'AQID', expires: NOW + 7 * 86400, name: 'Maria S.' });
			}
			return reply(200, { events: EVENTS });
		});
		const [app] = boot(store, fetch);
		await settle();
		expect(paths).toEqual([rest('renew'), rest('events')]);
		expect(store.data.get('device').expires).toBe(NOW + 7 * 86400);
		expect(store.data.get('device').events.length).toBe(2);
		app.stop();
	});

	it('goes back to activation when the site says the key was replaced', async () => {
		const { state } = await deviceState();
		const el = markup();
		const store = memoryStore(state);
		const fetch = vi.fn(() => reply(403, { code: 'ffc_presence_revoked', message: 'replaced' }));
		const [app] = boot(store, fetch);
		await settle();
		expect(el.querySelector('[data-ffc-presence-view="activate"]').hidden).toBe(false);
		expect(el.querySelector('[data-ffc-presence-part="status"]').textContent).toBe('replaced');
		expect(store.data.get('device')).toEqual({ userId: USER, deviceId: 'dev', clockOffset: 0 });
		expect(app.timer).toBeNull();
	});

	it('keeps showing the code offline', async () => {
		const { state } = await deviceState();
		const el = markup();
		const store = memoryStore(state);
		const [app] = boot(store, vi.fn(() => Promise.reject(new TypeError('offline'))));
		await settle();
		expect(el.querySelector('[data-ffc-presence-part="qr"] svg')).not.toBeNull();
		expect(store.data.get('device').events.length).toBe(2);
		app.stop();
	});

	it('draws once per rotation, for the chosen event, and counts down', async () => {
		const { state } = await deviceState();
		const el = markup();
		const [app] = boot(memoryStore(state), vi.fn(() => Promise.reject(new Error('offline'))));
		await settle();
		const holder = el.querySelector('[data-ffc-presence-part="qr"]');
		expect(app.drawn).toBe('42@' + (NOW - (NOW % 15)));
		expect(el.querySelector('[data-ffc-presence-part="countdown"]').textContent.split(' · ')[0]).toBe('next ' + (15 - (NOW % 15)));
		expect(el.querySelector('[data-ffc-presence-part="progress"]').getAttribute('value')).toBe(String(15 - (NOW % 15)), 'the bar drains with the rotation');
		expect(el.querySelector('[data-ffc-presence-part="progress"]').getAttribute('max')).toBe('15');

		const first = holder.innerHTML;
		expect(await app.draw()).toBeNull();
		expect(holder.innerHTML).toBe(first);

		clock += 30000;
		expect(await app.draw()).toMatch(/^FFP1/);

		const select = el.querySelector('[data-ffc-presence-part="event"]');
		select.value = '43';
		select.dispatchEvent(new Event('change'));
		await settle();
		expect(app.state.chosen).toBe('43');
		expect(el.querySelector('[data-ffc-presence-part="title"]').textContent).toBe(app.current().title || '', 'the heading follows the chosen event');
		expect(app.drawn.startsWith('43@')).toBe(true);
		app.stop();
	});

	it('falls back to the first event when the chosen one is gone, and shows the empty note without any', async () => {
		const { state } = await deviceState({ chosen: '99' });
		const el = markup();
		const [app] = boot(memoryStore(state), vi.fn(() => Promise.reject(new Error('offline'))));
		await settle();
		expect(app.current().target_id).toBe(42);

		app.state.events = [];
		app.listEvents();
		expect(el.querySelector('[data-ffc-presence-part="empty"]').hidden).toBe(false);
		expect(el.querySelector('[data-ffc-presence-part="event"]').hidden).toBe(true);
		expect(el.querySelector('[data-ffc-presence-part="picker"]').hidden).toBe(true);
		expect(el.querySelector('[data-ffc-presence-part="card"]').hidden).toBe(true, 'no event, no code card');
		expect(el.querySelector('[data-ffc-presence-part="title"]').hidden).toBe(true);
		app.drawn = '';
		expect(await app.draw()).toBeNull();
		expect(el.querySelector('[data-ffc-presence-part="qr"]').textContent).toBe('');
		app.stop();
	});

	it('stops drawing and says so once the attestation has expired', async () => {
		const { state } = await deviceState({ expires: NOW - 1 });
		const el = markup();
		const [app] = boot(memoryStore(state), vi.fn(() => Promise.reject(new Error('offline'))));
		await settle();
		expect(el.querySelector('[data-ffc-presence-part="status"]').textContent).toBe('expired');
		expect(el.querySelector('[data-ffc-presence-part="qr"]').innerHTML).toBe('');
		app.stop();
	});

	it('runs without the optional parts', async () => {
		const { state } = await deviceState();
		document.body.innerHTML = '<div data-ffc-presence></div>';
		const [app] = boot(memoryStore(state), vi.fn(() => Promise.reject(new Error('offline'))));
		await settle();
		app.say('x');
		app.listEvents();
		expect(await app.draw()).toBeNull();
		app.stop();
		app.stop();
	});

	it('enrols without a button on the page', async () => {
		document.body.innerHTML = '<div data-ffc-presence></div>';
		const fetch = vi.fn(() => reply(403, { message: 'no' }));
		const [app] = boot(memoryStore(), fetch);
		await settle();
		await app.enrol();
		expect(fetch.mock.calls.filter(([url]) => url === rest('enrol'))).toHaveLength(1);
	});

	it('names the phone it replaces, the change budget and the offline days', async () => {
		const el = markup();
		el.querySelector('[data-ffc-presence-view="activate"]').insertAdjacentHTML('beforeend', `
			<p data-ffc-presence-part="current" hidden></p>
			<dl data-ffc-presence-part="facts" hidden><dd data-ffc-presence-part="changes"></dd><dd data-ffc-presence-part="offline"></dd></dl>`);
		const fetch = vi.fn(() => reply(200, {
			current: { device_id: 'device-other', label: 'Android · Chrome', since: '02/11' },
			changes: 1, max_changes: 3, window_days: 30, offline_days: 7,
		}));
		boot(memoryStore(), fetch);
		await settle();
		expect(fetch.mock.calls[0][0]).toBe(rest('device'));
		const current = el.querySelector('[data-ffc-presence-part="current"]');
		expect(current.hidden).toBe(false);
		expect(current.querySelector('strong').textContent).toBe('Android · Chrome');
		expect(current.textContent).toBe('Now: Android · Chrome since 02/11.');
		expect(el.querySelector('[data-ffc-presence-part="changes"]').textContent).toBe('1 of 3 / 30');
		expect(el.querySelector('[data-ffc-presence-part="offline"]').textContent).toBe('7 days');
		expect(el.querySelector('[data-ffc-presence-part="facts"]').hidden).toBe(false);
	});

	it('leaves the facts out offline and the current phone out when it is this one', async () => {
		const el = markup();
		el.querySelector('[data-ffc-presence-view="activate"]').insertAdjacentHTML('beforeend', `
			<p data-ffc-presence-part="current" hidden></p>
			<dl data-ffc-presence-part="facts" hidden><dd data-ffc-presence-part="changes"></dd><dd data-ffc-presence-part="offline"></dd></dl>`);
		const fetch = vi.fn(() => Promise.reject(new Error('offline')));
		boot(memoryStore(), fetch);
		await settle();
		expect(el.querySelector('[data-ffc-presence-part="facts"]').hidden).toBe(true);
		expect(el.querySelector('[data-ffc-presence-view="activate"]').hidden).toBe(false);

		const { state } = await deviceState({ userId: USER, attestation: '' });
		state.deviceId = 'device-mine';
		fetch.mockImplementation(() => reply(200, {
			current: { device_id: 'device-mine', label: '', since: '02/11' },
			changes: 0, max_changes: 3, window_days: 30, offline_days: 1,
		}));
		boot(memoryStore(state), fetch);
		await settle();
		const parts = el.querySelectorAll('[data-ffc-presence-part="current"]');
		expect(parts[parts.length - 1].hidden).toBe(true);
	});
});

function concat(a, b) {
	const out = new Uint8Array(a.length + b.length);
	out.set(a, 0);
	out.set(b, a.length);
	return out;
}

function decodeBase45(text) {
	const alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
	const out = [];
	for (let i = 0; i < text.length; i += 3) {
		const c = alphabet.indexOf(text[i]);
		const d = alphabet.indexOf(text[i + 1]);
		if (i + 2 < text.length) {
			const n = c + d * 45 + alphabet.indexOf(text[i + 2]) * 2025;
			out.push(n >> 8, n & 255);
		} else {
			out.push(c + d * 45);
		}
	}
	return new Uint8Array(out);
}

describe('presence app attendance (#2749)', () => {
	const PENDING = [
		{ type: 'confirmed', key: 'confirmed:42:2027-01-16', title: 'Library', text: 'Attendance confirmed at Library', detail: 'Self-service terminal · 16/01 09:12' },
		{ type: 'form', key: 'form:41', title: 'Lab', text: 'Post-event form · until 20/01', detail: 'Feedback', url: 'https://example.org/form/?t=x' },
		{ type: 'form', key: 'form:40', title: 'Old', text: 'Post-event form · until 19/01', detail: '', url: 'javascript:alert(1)' },
	];

	beforeEach(() => {
		window.ffcPresence = { rest: rest(''), nonce: 'n', userId: USER, strings: STRINGS };
	});

	afterEach(() => {
		delete window.ffcPresence;
		document.body.innerHTML = '';
	});

	const items = (el) => Array.from(el.querySelectorAll('[data-ffc-presence-part="pending"] li'));

	it('dates its codes by the site clock measured on each answer (#2797)', async () => {
		const { state } = await deviceState();
		markup();
		const store = memoryStore(state);
		const site = new Date((NOW + 40) * 1000).toUTCString();
		const fetch = vi.fn(() => Promise.resolve({
			ok: true,
			headers: { get: (name) => ('Date' === name ? site : null) },
			json: () => Promise.resolve({ events: EVENTS, pending: [] }),
		}));
		const [app] = P().boot({ store, fetch, now: () => NOW * 1000 });
		await settle();
		expect(app.offset).toBe(40500, 'the middle of the site\'s second, against the middle of the round trip');
		expect(store.data.get('device').clockOffset).toBe(40500);
		const site15 = NOW + 40;
		expect(app.drawn).toBe('42@' + (site15 - (site15 % 15)));
		app.stop();
	});

	it('reads the site clock only from a Date header, and ignores a gap under a second', () => {
		expect(P().clockOffset({ headers: { get: () => null } }, 0, 0)).toBeNull();
		expect(P().clockOffset({}, 0, 0)).toBeNull();
		expect(P().clockOffset({ headers: { get: () => 'garbage' } }, 0, 0)).toBeNull();
		expect(P().clockOffset({ headers: { get: () => new Date(10000).toUTCString() } }, 9000, 11000)).toBe(500);
	});

	it('lists what waits and says Present on the chosen event', async () => {
		const { state } = await deviceState();
		const el = markup();
		const store = memoryStore(state);
		const events = [Object.assign({}, EVENTS[0], { attendance: 'Present: 2 of 3 days' }), EVENTS[1]];
		const fetch = vi.fn(() => reply(200, { events, pending: PENDING }));
		const [app] = P().boot({ store, fetch, now: () => NOW * 1000 });
		await settle();

		const list = el.querySelector('[data-ffc-presence-part="pending"]');
		expect(list.hidden).toBe(false);
		expect(items(el).map((li) => li.querySelector('strong').textContent)).toEqual(PENDING.map((p) => p.text));
		const link = items(el)[1].querySelector('a');
		expect(link.getAttribute('href')).toBe('https://example.org/form/?t=x');
		expect(link.textContent).toBe('fill form');
		expect(items(el)[2].querySelector('a')).toBeNull();
		expect(store.data.get('device').pending).toHaveLength(3);

		const attendance = el.querySelector('[data-ffc-presence-part="attendance"]');
		expect(attendance.hidden).toBe(false);
		expect(attendance.textContent).toBe('Present: 2 of 3 days');

		const select = el.querySelector('[data-ffc-presence-part="event"]');
		select.value = '43';
		select.dispatchEvent(new Event('change'));
		await settle();
		expect(attendance.hidden).toBe(true);
		app.stop();
	});

	it('keeps a dismissed confirmation away, and forgets it once the site stops sending it', async () => {
		const { state } = await deviceState();
		const el = markup();
		const store = memoryStore(state);
		let pending = PENDING;
		const fetch = vi.fn(() => reply(200, { events: EVENTS, pending }));
		const [app] = P().boot({ store, fetch, now: () => NOW * 1000 });
		await settle();

		const dismiss = items(el)[0].querySelector('button');
		expect(dismiss.getAttribute('aria-label')).toBe('dismiss');
		dismiss.click();
		await settle();
		expect(items(el)).toHaveLength(2);
		expect(store.data.get('device').dismissed).toEqual(['confirmed:42:2027-01-16']);

		await app.refresh();
		expect(items(el)).toHaveLength(2);

		pending = [];
		await app.refresh();
		expect(el.querySelector('[data-ffc-presence-part="pending"]').hidden).toBe(true);
		expect(store.data.get('device').dismissed).toEqual([]);
		app.stop();
	});

	it('shows the kept list while offline', async () => {
		const { state } = await deviceState({ pending: PENDING.slice(0, 1) });
		const el = markup();
		const fetch = vi.fn(() => Promise.reject(new Error('offline')));
		const [app] = P().boot({ store: memoryStore(state), fetch, now: () => NOW * 1000 });
		await settle();
		expect(items(el)).toHaveLength(1);
		app.stop();
	});
});

