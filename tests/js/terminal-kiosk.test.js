/**
 * Check-in terminal page (#2744): pairing with a `P-` code, the lock the
 * site answers with, and the pairing the page keeps across a reload.
 */
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { webcrypto } from 'node:crypto';
import { loadScript } from './helpers.js';

const NOW = 1800000000;
const TOKEN = 'a'.repeat(64);

beforeAll(() => {
	loadScript('assets/js/ffc-terminal-kiosk.js');
});

const T = () => window.FFCTerminal;
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
const settle = async () => { for (let i = 0; i < 10; i++) { await flush(); } };

const CONFIG = {
	rest: 'https://example.com/wp-json/ffc/v1/terminal/',
	strings: {
		storage: 'storage',
		pairing: 'pairing',
		paired: 'paired',
		failed: 'failed',
		locked: 'locked %s',
		events: 'events %d',
		until: 'until %s',
		expired: 'expired',
	},
};

function markup() {
	document.body.innerHTML = `
		<main data-ffc-terminal>
			<p data-ffc-terminal-part="status"></p>
			<p data-ffc-terminal-part="error" hidden></p>
			<section data-ffc-terminal-view="pairing" hidden>
				<form data-ffc-terminal-part="form"><input data-ffc-terminal-part="code"><button type="submit">Pair</button></form>
			</section>
			<section data-ffc-terminal-view="locked" hidden><p data-ffc-terminal-part="countdown"></p></section>
			<section data-ffc-terminal-view="paired" hidden>
				<p data-ffc-terminal-part="label"></p>
				<p data-ffc-terminal-part="events"></p>
				<p data-ffc-terminal-part="until"></p>
			</section>
		</main>`;
	return document.querySelector('[data-ffc-terminal]');
}

function memoryStorage(initial = {}) {
	const data = new Map(Object.entries(initial));
	return {
		data,
		getItem: (k) => (data.has(k) ? data.get(k) : null),
		setItem: (k, v) => { data.set(k, String(v)); },
		removeItem: (k) => { data.delete(k); },
	};
}

function env(overrides = {}) {
	const clock = { now: NOW * 1000 };
	const timers = [];
	return {
		clock,
		timers,
		now: () => clock.now,
		storage: memoryStorage(),
		crypto: webcrypto,
		fetch: () => Promise.reject(new Error('no network')),
		setInterval: (fn) => { timers.push(fn); return timers.length; },
		clearInterval: () => {},
		...overrides,
	};
}

function respond(status, body) {
	return () => Promise.resolve({ status, json: () => Promise.resolve(body) });
}

const visible = (el) => Array.from(el.querySelectorAll('[data-ffc-terminal-view]')).filter((v) => !v.hidden).map((v) => v.getAttribute('data-ffc-terminal-view'));

describe('helpers', () => {
	it('keeps only what a pairing code is made of', () => {
		expect(T().cleanCode(' p-ab12-cd34-ef56\n')).toBe('P-AB12-CD34-EF56');
		expect(T().cleanCode('<script>')).toBe('SCRIPT');
		expect(T().cleanCode(null)).toBe('');
	});

	it('writes seconds as m:ss', () => {
		expect(T().clock(899.2)).toBe('15:00');
		expect(T().clock(61)).toBe('1:01');
		expect(T().clock(-4)).toBe('0:00');
	});

	it('makes a device id of 32 URL-safe characters', () => {
		const id = T().newDeviceId(webcrypto);
		expect(id).toMatch(/^[A-Za-z0-9_-]{32}$/);
		expect(T().newDeviceId(webcrypto)).not.toBe(id);
	});

	it('reads a stored pairing only while it holds a token and has not expired', () => {
		expect(T().readState(JSON.stringify({ token: TOKEN, expires_at: NOW + 1 }), NOW)).not.toBeNull();
		expect(T().readState(JSON.stringify({ token: TOKEN, expires_at: NOW }), NOW)).toBeNull();
		expect(T().readState(JSON.stringify({ token: 'short', expires_at: NOW + 1 }), NOW)).toBeNull();
		expect(T().readState('{not json', NOW)).toBeNull();
		expect(T().readState(null, NOW)).toBeNull();
	});
});

describe('pairing', () => {
	let el;
	beforeEach(() => { el = markup(); });

	it('starts on Waiting for pairing with the field focused', () => {
		T().start(el, CONFIG, env());
		expect(visible(el)).toEqual(['pairing']);
		expect(document.activeElement).toBe(el.querySelector('[data-ffc-terminal-part="code"]'));
	});

	it('sends the code with its own id and keeps the token the site returns', async () => {
		const calls = [];
		const e = env({
			fetch: (url, options) => {
				calls.push([url, options]);
				return respond(201, { token: TOKEN, terminal_id: 4, label: 'Entrance A', bookings: [11, 12], expires_at: NOW + 3600, expires_label: '15/01/2027 18:00', keys: { k1: 'x' } })();
			},
		});
		T().start(el, CONFIG, e);
		el.querySelector('[data-ffc-terminal-part="code"]').value = 'p-ab12-cd34-ef56';
		el.querySelector('form').dispatchEvent(new window.Event('submit', { cancelable: true }));
		await settle();

		expect(calls[0][0]).toBe(CONFIG.rest + 'pair');
		expect(calls[0][1].credentials).toBe('omit');
		const sent = JSON.parse(calls[0][1].body);
		expect(sent.code).toBe('P-AB12-CD34-EF56');
		expect(sent.device).toBe(e.storage.data.get('ffcTerminalDevice'));
		expect(visible(el)).toEqual(['paired']);
		expect(el.querySelector('[data-ffc-terminal-part="label"]').textContent).toBe('Entrance A');
		expect(el.querySelector('[data-ffc-terminal-part="events"]').textContent).toBe('events 2');
		expect(el.querySelector('[data-ffc-terminal-part="until"]').textContent).toBe('until 15/01/2027 18:00');
		expect(JSON.parse(e.storage.data.get('ffcTerminal')).token).toBe(TOKEN);
	});

	it('counts down a lock and remembers it across a reload', async () => {
		const e = env({ fetch: respond(429, { message: 'wait', retry_after: 900 }) });
		await T().start(el, CONFIG, e).pair('P-WRON-GCOD-E000');
		expect(visible(el)).toEqual(['locked']);
		expect(el.querySelector('[data-ffc-terminal-part="countdown"]').textContent).toBe('locked 15:00');
		expect(e.storage.data.get('ffcTerminalLock')).toBe(String(NOW + 900));

		const reloaded = markup();
		const again = env({ storage: e.storage });
		again.clock.now = (NOW + 600) * 1000;
		T().start(reloaded, CONFIG, again);
		expect(visible(reloaded)).toEqual(['locked']);
		expect(reloaded.querySelector('[data-ffc-terminal-part="countdown"]').textContent).toBe('locked 5:00');

		again.clock.now = (NOW + 901) * 1000;
		again.timers[again.timers.length - 1]();
		expect(visible(reloaded)).toEqual(['pairing']);
		expect(again.storage.data.has('ffcTerminalLock')).toBe(false);
	});

	it('shows the site\'s refusal and stays on pairing', async () => {
		const controls = T().start(el, CONFIG, env({ fetch: respond(400, { message: 'This code has expired.' }) }));
		await controls.pair('P-AB12-CD34-EF56');
		const error = el.querySelector('[data-ffc-terminal-part="error"]');
		expect(error.hidden).toBe(false);
		expect(error.textContent).toBe('This code has expired.');
		expect(visible(el)).toEqual(['pairing']);
	});

	it('says the network failed when the site cannot be reached', async () => {
		const controls = T().start(el, CONFIG, env());
		await controls.pair('P-AB12-CD34-EF56');
		expect(el.querySelector('[data-ffc-terminal-part="error"]').textContent).toBe('failed');
	});

	it('refuses to pair where it cannot keep the pairing', async () => {
		const storage = { getItem: () => null, setItem: () => { throw new Error('quota'); }, removeItem: () => {} };
		let fetched = false;
		const controls = T().start(el, CONFIG, env({ storage, fetch: () => { fetched = true; return respond(201, {})(); } }));
		await controls.pair('P-AB12-CD34-EF56');
		expect(fetched).toBe(false);
		expect(el.querySelector('[data-ffc-terminal-part="error"]').textContent).toBe('storage');
	});

	it('opens on the stored pairing, and drops one that expired', () => {
		const storage = memoryStorage({ ffcTerminal: JSON.stringify({ token: TOKEN, label: '', bookings: [1], expires_at: NOW + 10, expires_label: 'soon' }) });
		T().start(el, CONFIG, env({ storage }));
		expect(visible(el)).toEqual(['paired']);
		expect(el.querySelector('[data-ffc-terminal-part="label"]').hidden).toBe(true);

		const later = env({ storage });
		later.clock.now = (NOW + 11) * 1000;
		const reloaded = markup();
		T().start(reloaded, CONFIG, later);
		expect(visible(reloaded)).toEqual(['pairing']);
		expect(reloaded.querySelector('[data-ffc-terminal-part="error"]').textContent).toBe('expired');
		expect(storage.data.has('ffcTerminal')).toBe(false);
	});
});
