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
	titleFirst: 'first',
	titleElsewhere: 'elsewhere',
	activateHere: 'activate here',
	moveHere: 'move here',
	changes: '%1$d of %2$d / %3$d',
	dayOne: '1 day',
	days: '%d days',
	recorded: 'Recorded',
	alsoRecorded: 'Also: %s',
	upcoming: 'On %1$s: %2$s',
};

function markup() {
	document.body.innerHTML = `
		<div data-ffc-presence>
			<p data-ffc-presence-part="status"></p>
			<div data-ffc-presence-part="unsupported" data-ffc-presence-view="unsupported" hidden></div>
			<div data-ffc-presence-part="activate-view" data-ffc-presence-view="activate" hidden>
				<h2 data-ffc-presence-part="activate-title">Activate</h2>
				<p data-ffc-presence-part="current" hidden></p>
				<p data-ffc-presence-part="ios-note" hidden></p>
				<button type="button" data-ffc-presence-part="activate">Activate</button>
			</div>
			<div data-ffc-presence-part="code-view" data-ffc-presence-view="code" hidden>
				<ul data-ffc-presence-part="pending" hidden></ul>
				<h2 data-ffc-presence-part="title"></h2>
				<p data-ffc-presence-part="when"></p>
				<div data-ffc-presence-part="picker" hidden><select data-ffc-presence-part="event"></select></div>
				<p data-ffc-presence-part="attendance" hidden></p>
				<p data-ffc-presence-part="needs-ticket" hidden></p>
				<p data-ffc-presence-part="empty" hidden></p>
				<p data-ffc-presence-part="upcoming" hidden></p>
				<div data-ffc-presence-part="card"><div data-ffc-presence-part="qr"></div></div>
				<p data-ffc-presence-part="also" hidden></p>
				<ul data-ffc-presence-part="recorded" hidden></ul>
				<div data-ffc-presence-part="rotation">
					<progress data-ffc-presence-part="progress"></progress>
					<p data-ffc-presence-part="countdown"></p>
				</div>
				<ul data-ffc-presence-part="tips"></ul>
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

	// GETs carry a unique `_ffc` stamp so no cache can answer them (#2832);
	// the doubles see the address without it.
	const boot = (store, fetch) => P().boot({
		store,
		fetch: fetch ? (url, options) => fetch(url.replace(/[?&]_ffc=\d+$/, ''), options) : fetch,
		now: () => clock,
	});

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
		expect(paths).toEqual([rest('renew'), rest('events?key=kid')]);
		expect(store.data.get('device').expires).toBe(NOW + 7 * 86400);
		expect(store.data.get('device').events.length).toBe(2);
		app.stop();
	});

	it('asks the site itself, never a cached answer', async () => {
		const { state } = await deviceState();
		markup();
		const raw = vi.fn(() => reply(410, { code: 'ffc_presence_revoked', message: 'replaced' }));
		P().boot({ store: memoryStore(state), fetch: raw, now: () => clock });
		await settle();
		expect(raw.mock.calls[0][0]).toBe(rest('events?key=kid&_ffc=' + clock));
		expect(raw.mock.calls[0][1].cache).toBe('no-store');
		const posts = raw.mock.calls.filter((call) => 'POST' === call[1].method);
		posts.forEach((call) => expect(call[0]).not.toContain('_ffc='));
	});

	it('hides the code and offers to move it here once another phone took it (#2797)', async () => {
		const { state } = await deviceState();
		const el = markup();
		const store = memoryStore(state);
		const fetch = vi.fn((url) => (url.endsWith('/device')
			? reply(200, { current: { device_id: 'other', label: 'iPhone · Safari', since: '20/11' }, changes: 1, max_changes: 3, window_days: 30, offline_days: 7 })
			: reply(410, { code: 'ffc_presence_revoked', message: 'replaced' })));
		const [app] = boot(store, fetch);
		await settle();
		expect(fetch.mock.calls[0][0]).toBe(rest('events?key=kid'));
		expect(el.querySelector('[data-ffc-presence-view="activate"]').hidden).toBe(false);
		expect(el.querySelector('[data-ffc-presence-view="code"]').hidden).toBe(true);
		expect(el.querySelector('[data-ffc-presence-part="qr"]').innerHTML).toBe('');
		expect(el.querySelector('[data-ffc-presence-part="status"]').textContent).toBe('replaced');
		expect(el.querySelector('[data-ffc-presence-part="activate-title"]').textContent).toBe('elsewhere');
		expect(el.querySelector('[data-ffc-presence-part="activate"]').textContent).toBe('move here');
		expect(el.querySelector('[data-ffc-presence-part="current"]').textContent).toBe('Now: iPhone · Safari since 20/11.');
		expect(store.data.get('device')).toMatchObject({ userId: USER, deviceId: 'dev' });
		expect(store.data.get('device').attestation).toBeUndefined();
		expect(app.timer).toBeNull();
	});

	it('calls a first activation by its name when no phone holds the code', async () => {
		const el = markup();
		const fetch = vi.fn(() => reply(200, { current: null, changes: 0, max_changes: 3, window_days: 30, offline_days: 7 }));
		boot(memoryStore(), fetch);
		await settle();
		expect(el.querySelector('[data-ffc-presence-part="activate-title"]').textContent).toBe('first');
		expect(el.querySelector('[data-ffc-presence-part="activate"]').textContent).toBe('activate here');
	});

	it('says on an iPhone that Safari and the installed app are two phones', async () => {
		const agent = Object.getOwnPropertyDescriptor(window.navigator, 'userAgent');
		Object.defineProperty(window.navigator, 'userAgent', { value: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)', configurable: true });
		try {
			const el = markup();
			boot(memoryStore(), vi.fn(() => reply(200, { current: null })));
			await settle();
			expect(el.querySelector('[data-ffc-presence-part="ios-note"]').hidden).toBe(false);
		} finally {
			if (agent) {
				Object.defineProperty(window.navigator, 'userAgent', agent);
			} else {
				delete window.navigator.userAgent;
			}
		}
		const el = markup();
		boot(memoryStore(), vi.fn(() => reply(200, { current: null })));
		await settle();
		expect(el.querySelector('[data-ffc-presence-part="ios-note"]').hidden).toBe(true);
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
		expect(app.drawn).toBe('audience_booking:42@' + (NOW - (NOW % 15)));
		expect(el.querySelector('[data-ffc-presence-part="countdown"]').textContent.split(' · ')[0]).toBe('next ' + (15 - (NOW % 15)));
		expect(el.querySelector('[data-ffc-presence-part="progress"]').getAttribute('value')).toBe(String(15 - (NOW % 15)), 'the bar drains with the rotation');
		expect(el.querySelector('[data-ffc-presence-part="progress"]').getAttribute('max')).toBe('15');

		const first = holder.innerHTML;
		expect(await app.draw()).toBeNull();
		expect(holder.innerHTML).toBe(first);

		clock += 30000;
		expect(await app.draw()).toMatch(/^FFP1/);

		const select = el.querySelector('[data-ffc-presence-part="event"]');
		select.value = 'audience_booking:43';
		select.dispatchEvent(new Event('change'));
		await settle();
		expect(app.state.chosen).toBe('audience_booking:43');
		expect(el.querySelector('[data-ffc-presence-part="title"]').textContent).toBe(app.current().title || '', 'the heading follows the chosen event');
		expect(app.drawn.startsWith('audience_booking:43@')).toBe(true);
		app.stop();
	});

	it('lists a certificate form beside a booking of the same id, and says when it asks for a ticket (#2893)', async () => {
		const form = { target_type: 'certificate_form', target_id: 42, title: 'Seminar', date: '16/01', time: '09:00–12:00', ticket: 'AQ', needs_ticket: true };
		const { state } = await deviceState({ events: [EVENTS[0], form], chosen: '43' });
		const el = markup();
		const [app] = boot(memoryStore(state), vi.fn(() => Promise.reject(new Error('offline'))));
		await settle();
		const select = el.querySelector('[data-ffc-presence-part="event"]');
		expect(Array.from(select.options).map((o) => o.value)).toEqual(['audience_booking:42', 'certificate_form:42']);
		expect(el.querySelector('[data-ffc-presence-part="needs-ticket"]').hidden).toBe(true, 'the booking asks for no ticket');

		select.value = 'certificate_form:42';
		select.dispatchEvent(new Event('change'));
		await settle();
		expect(app.current()).toBe(form);
		expect(el.querySelector('[data-ffc-presence-part="title"]').textContent).toBe('Seminar');
		expect(el.querySelector('[data-ffc-presence-part="needs-ticket"]').hidden).toBe(false);
		expect(app.drawn.startsWith('certificate_form:42@')).toBe(true);
		app.stop();
	});

	it('keeps a booking chosen before items carried their type', async () => {
		const { state } = await deviceState({ chosen: '43' });
		markup();
		const [app] = boot(memoryStore(state), vi.fn(() => Promise.reject(new Error('offline'))));
		await settle();
		expect(app.current().target_id).toBe(43);
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
		expect(app.drawn).toBe('audience_booking:42@' + (site15 - (site15 % 15)));
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
		select.value = 'audience_booking:43';
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


describe('presence app: the code on the event day only (#2842)', () => {
	let clock;

	beforeEach(() => {
		clock = NOW * 1000;
		window.ffcPresence = { rest: rest(''), nonce: 'n', userId: USER, strings: STRINGS };
	});

	afterEach(() => {
		delete window.ffcPresence;
		document.body.innerHTML = '';
	});

	const ymd = (ms) => {
		const d = new Date(ms);
		const two = (n) => String(n).padStart(2, '0');
		return d.getFullYear() + '-' + two(d.getMonth() + 1) + '-' + two(d.getDate());
	};
	const day = (offset) => ymd(clock + offset * 86400000);
	const event = (id, days, recorded) => ({
		target_type: 'audience_booking',
		target_id: id,
		title: 'Event ' + id,
		date: days[0],
		time: '',
		ticket: '',
		days: days.map((date) => ({ date, label: 'L' + date })),
		recorded: recorded || [],
	});
	const part = (el, name) => el.querySelector('[data-ffc-presence-part="' + name + '"]');
	const start = async (events, fetch) => {
		const { state } = await deviceState({ events });
		const el = markup();
		const [app] = P().boot({
			store: memoryStore(state),
			fetch: fetch || vi.fn(() => Promise.reject(new Error('offline'))),
			now: () => clock,
		});
		await settle();
		return { el, app };
	};

	it('draws nothing and runs no timer without an event today, naming the next day', async () => {
		const intervals = vi.spyOn(window, 'setInterval');
		const { el, app } = await start([event(42, [day(3)]), event(43, [day(1), day(2)])]);
		expect(intervals).not.toHaveBeenCalled();
		intervals.mockRestore();
		expect(app.timer).toBeNull();
		expect(app.midnight).not.toBeNull();
		expect(part(el, 'card').hidden).toBe(true);
		expect(part(el, 'rotation').hidden).toBe(true);
		expect(part(el, 'tips').hidden).toBe(true);
		expect(part(el, 'qr').innerHTML).toBe('');
		expect(part(el, 'title').hidden).toBe(true);
		expect(part(el, 'upcoming').hidden).toBe(false);
		expect(part(el, 'upcoming').textContent).toBe('On L' + day(1) + ': Event 43');
		expect(part(el, 'empty').hidden).toBe(true);
		app.stop();
	});

	it('leaves the code once today is recorded and says so', async () => {
		const { el, app } = await start([event(42, [day(0), day(1)], [{ date: day(0), time: '09:12' }])]);
		expect(app.timer).toBeNull();
		expect(part(el, 'card').hidden).toBe(true);
		expect(part(el, 'recorded').hidden).toBe(false);
		expect(part(el, 'recorded').textContent).toBe('Recorded · Event 42 · 09:12');
		expect(part(el, 'upcoming').hidden).toBe(true, 'an event today is not an upcoming one');
		app.stop();
	});

	it('keeps the code for the events still waiting today, and names the recorded ones under it', async () => {
		const { el, app } = await start([
			event(41, [day(0)], [{ date: day(0), time: '08:00' }]),
			event(42, [day(0)]),
			event(43, [day(0)]),
			event(44, [day(1)]),
		]);
		expect(app.timer).not.toBeNull();
		expect(app.drawn.startsWith('audience_booking:42@')).toBe(true);
		expect(Array.from(el.querySelectorAll('option')).map((o) => o.value)).toEqual(['audience_booking:42', 'audience_booking:43']);
		expect(part(el, 'picker').hidden).toBe(false);
		expect(part(el, 'also').textContent).toBe('Also: Event 41');
		expect(part(el, 'recorded').hidden).toBe(true);
		app.stop();
	});

	it('takes the code away at midnight without a reload', async () => {
		const { el, app } = await start([event(42, [day(0)])]);
		expect(part(el, 'card').hidden).toBe(false);
		clock += 86400000;
		await app.draw();
		expect(part(el, 'card').hidden).toBe(true);
		expect(app.timer).toBeNull();
		expect(app.midnight).not.toBeNull();
		app.stop();
	});

	it('brings the code back when the day of an event begins', async () => {
		const timeouts = vi.spyOn(window, 'setTimeout');
		const { el, app } = await start([event(42, [day(1)])]);
		expect(part(el, 'card').hidden).toBe(true);
		const midnight = timeouts.mock.calls.find((call) => call[1] > 60000);
		timeouts.mockRestore();
		const next = new Date(clock);
		const wait = new Date(next.getFullYear(), next.getMonth(), next.getDate() + 1).getTime() - clock + 1000;
		expect(midnight[1]).toBe(wait, 'it waits for one second past midnight');
		clock += wait;
		midnight[0]();
		await settle();
		expect(part(el, 'card').hidden).toBe(false);
		expect(app.timer).not.toBeNull();
		app.stop();
	});

	it('reads the list again on coming back to the app, at most every 30 seconds', async () => {
		const fetch = vi.fn(() => reply(200, { events: [event(42, [day(0)], [{ date: day(0), time: '09:00' }])], pending: [] }));
		const { el, app } = await start([event(42, [day(0)])], fetch);
		expect(fetch).toHaveBeenCalledTimes(1);
		expect(part(el, 'recorded').hidden).toBe(false, 'the reading made at the terminal arrived');

		const visible = Object.getOwnPropertyDescriptor(Document.prototype, 'visibilityState');
		let state = 'visible';
		Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state });
		try {
			document.dispatchEvent(new Event('visibilitychange'));
			await settle();
			expect(fetch).toHaveBeenCalledTimes(1, 'within 30 s of the last read');

			clock += 30000;
			state = 'hidden';
			document.dispatchEvent(new Event('visibilitychange'));
			await settle();
			expect(fetch).toHaveBeenCalledTimes(1, 'going away reads nothing');

			state = 'visible';
			document.dispatchEvent(new Event('visibilitychange'));
			await settle();
			expect(fetch).toHaveBeenCalledTimes(2);
		} finally {
			delete document.visibilityState;
			if (visible) {
				Object.defineProperty(Document.prototype, 'visibilityState', visible);
			}
		}
		app.stop();
	});

	it('reads the day from the first and last dates of a list kept before the site sent days', async () => {
		const kept = { target_type: 'audience_booking', target_id: 42, title: 'Kept', ticket: '', first_date: day(-1), last_date: day(1) };
		const old = { target_type: 'audience_booking', target_id: 43, title: 'Old', ticket: '', first_date: day(2) };
		const { el, app } = await start([old, kept]);
		expect(app.current().target_id).toBe(42);
		expect(Array.from(el.querySelectorAll('option')).map((o) => o.value)).toEqual(['audience_booking:42']);
		app.stop();
	});
});
