/**
 * Check-in terminal page (#2744, #2745): pairing with a `P-` code, the lock
 * the site answers with, the pairing the page keeps across a reload, reading
 * presence codes and the operator menu.
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
		operator: 'by %s',
		ended: 'ended',
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
			<section data-ffc-terminal-view="ready" hidden>
				<div data-ffc-terminal-part="result">
					<div role="status" data-ffc-terminal-part="result-ok"></div>
					<div role="alert" data-ffc-terminal-part="result-refused"></div>
				</div>
				<form data-ffc-terminal-part="reading-form"><input data-ffc-terminal-part="reading"><button type="submit">Check in</button></form>
				<p data-ffc-terminal-part="label"></p>
				<p data-ffc-terminal-part="events"></p>
				<p data-ffc-terminal-part="until"></p>
			</section>
			<section data-ffc-terminal-view="menu" hidden>
				<p data-ffc-terminal-part="operator"></p>
				<div data-ffc-terminal-part="menu-ask">
					<button type="button" data-ffc-terminal-part="menu-close">Close</button>
					<button type="button" data-ffc-terminal-part="menu-end">End</button>
				</div>
				<div data-ffc-terminal-part="menu-confirm" hidden>
					<button type="button" data-ffc-terminal-part="menu-keep">Keep</button>
					<button type="button" data-ffc-terminal-part="menu-confirm-end">End</button>
				</div>
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
	const timeouts = [];
	return {
		clock,
		timers,
		timeouts,
		now: () => clock.now,
		storage: memoryStorage(),
		crypto: webcrypto,
		fetch: () => Promise.reject(new Error('no network')),
		setInterval: (fn) => { timers.push(fn); return timers.length; },
		clearInterval: () => {},
		setTimeout: (fn, ms) => { timeouts.push([fn, ms]); return timeouts.length; },
		clearTimeout: () => {},
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

	it('keeps a reading whole, minus the reader\'s line end', () => {
		expect(T().cleanReading('  ffp1ab $%*+-./:\r\n')).toBe('FFP1AB $%*+-./:');
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
		expect(visible(el)).toEqual(['ready']);
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
		expect(visible(el)).toEqual(['ready']);
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

describe('reading', () => {
	let el;
	const STATE = JSON.stringify({ token: TOKEN, label: 'Entrance A', bookings: [11], expires_at: NOW + 3600, expires_label: 'later' });

	function paired(routes) {
		el = markup();
		const calls = [];
		const e = env({
			storage: memoryStorage({ ffcTerminal: STATE }),
			fetch: (url, options) => {
				const path = url.replace(CONFIG.rest, '');
				calls.push([options.method, path, options]);
				const answer = routes[options.method + ' ' + path];
				return answer ? respond(answer[0], answer[1])() : Promise.reject(new Error('offline'));
			},
		});
		const controls = T().start(el, CONFIG, e);
		return { e, calls, controls };
	}

	const submit = async (text) => {
		el.querySelector('[data-ffc-terminal-part="reading"]').value = text;
		el.querySelector('[data-ffc-terminal-part="reading-form"]').dispatchEvent(new window.Event('submit', { cancelable: true }));
		await settle();
	};
	const ok = () => el.querySelector('[data-ffc-terminal-part="result-ok"]').textContent;
	const refused = () => el.querySelector('[data-ffc-terminal-part="result-refused"]').textContent;

	it('opens ready with the reading field focused', () => {
		paired({});
		expect(visible(el)).toEqual(['ready']);
		expect(document.activeElement).toBe(el.querySelector('[data-ffc-terminal-part="reading"]'));
	});

	it('sends the reading with the terminal token and announces the short name', async () => {
		const { e, calls } = paired({ 'POST readings': [200, { status: 'recorded', code: 'ffc_terminal_recorded', message: 'Attendance recorded.', name: 'Maria S.' }] });
		await submit('FFP1ABC\r\n');

		expect(JSON.parse(calls[0][2].body)).toEqual({ code: 'FFP1ABC' });
		expect(calls[0][2].headers['X-FFC-Terminal']).toBe(TOKEN);
		expect(calls[0][2].credentials).toBe('omit');
		expect(ok()).toBe('Maria S. — Attendance recorded.');
		expect(refused()).toBe('');
		expect(el.querySelector('[data-ffc-terminal-part="result"]').className).toContain('ffc-icon-checkmark');
		expect(el.querySelector('[data-ffc-terminal-part="reading"]').value).toBe('');

		const [clear, ms] = e.timeouts[e.timeouts.length - 1];
		expect(ms).toBe(5000);
		clear();
		expect(ok()).toBe('');
	});

	it('writes a refusal in the alert region and never a name', async () => {
		paired({ 'POST readings': [200, { status: 'refused', code: 'ffc_terminal_other_event', message: 'Another event.', name: '' }] });
		await submit('FFP1ABC');
		expect(refused()).toBe('Another event.');
		expect(ok()).toBe('');
		expect(el.querySelector('[data-ffc-terminal-part="result"]').className).toContain('ffc-icon-warning');
	});

	it('says the network failed when the site cannot be reached', async () => {
		paired({});
		await submit('FFP1ABC');
		expect(refused()).toBe('failed');
	});

	it('goes back to pairing when the site no longer knows the token', async () => {
		const { e } = paired({ 'POST readings': [401, { message: 'unpaired' }] });
		await submit('FFP1ABC');
		expect(visible(el)).toEqual(['pairing']);
		expect(e.storage.data.has('ffcTerminal')).toBe(false);
		expect(el.querySelector('[data-ffc-terminal-part="error"]').textContent).toBe('expired');
	});

	it('notices on its own when the pairing expires', () => {
		const { e } = paired({});
		e.clock.now = (NOW + 3601) * 1000;
		e.timers[0]();
		expect(visible(el)).toEqual(['pairing']);
	});

	it('opens the menu on an operator code and locks it again after two minutes', async () => {
		const { e, calls } = paired({ 'POST unlock': [200, { name: 'Ana L.', expires: NOW + 120 }] });
		await submit('FFO1OPERATOR');
		expect(calls[0][1]).toBe('unlock');
		expect(visible(el)).toEqual(['menu']);
		expect(el.querySelector('[data-ffc-terminal-part="operator"]').textContent).toBe('by Ana L.');

		const [lock, ms] = e.timeouts[e.timeouts.length - 1];
		expect(ms).toBe(120000);
		lock();
		expect(visible(el)).toEqual(['ready']);
	});

	it('refuses an operator code the site does not accept', async () => {
		paired({ 'POST unlock': [403, { message: 'expired code' }] });
		await submit('FFO1OPERATOR');
		expect(visible(el)).toEqual(['ready']);
		expect(refused()).toBe('expired code');
	});

	it('ends the terminal only after the inline confirmation, with the operator code', async () => {
		const { e, calls } = paired({ 'POST unlock': [200, { name: 'Ana L.' }], 'DELETE self': [200, { revoked: 4 }] });
		await submit('FFO1OPERATOR');
		const button = (n) => el.querySelector('[data-ffc-terminal-part="' + n + '"]');

		button('menu-end').click();
		expect(button('menu-confirm').hidden).toBe(false);
		expect(document.activeElement).toBe(button('menu-keep'));
		button('menu-keep').click();
		expect(button('menu-confirm').hidden).toBe(true);
		expect(calls.some((c) => c[0] === 'DELETE')).toBe(false);

		button('menu-end').click();
		button('menu-confirm-end').click();
		await settle();
		const ended = calls.find((c) => c[0] === 'DELETE');
		expect(JSON.parse(ended[2].body)).toEqual({ code: 'FFO1OPERATOR' });
		expect(visible(el)).toEqual(['pairing']);
		expect(e.storage.data.has('ffcTerminal')).toBe(false);
		expect(el.querySelector('[data-ffc-terminal-part="status"]').textContent).toBe('ended');
	});

	it('keeps the terminal and says why when ending is refused', async () => {
		paired({ 'POST unlock': [200, { name: 'Ana L.' }], 'DELETE self': [403, { message: 'This operator code has expired.' }] });
		await submit('FFO1OPERATOR');
		el.querySelector('[data-ffc-terminal-part="menu-end"]').click();
		el.querySelector('[data-ffc-terminal-part="menu-confirm-end"]').click();
		await settle();
		expect(visible(el)).toEqual(['menu']);
		expect(el.querySelector('[data-ffc-terminal-part="error"]').textContent).toBe('This operator code has expired.');
	});
});

describe('failures and the page boot', () => {
	let el;
	beforeEach(() => { el = markup(); });

	it('starts on pairing when the storage refuses to be read', () => {
		const storage = { getItem: () => { throw new Error('blocked'); }, setItem: () => {}, removeItem: () => { throw new Error('blocked'); } };
		T().start(el, CONFIG, env({ storage }));
		expect(visible(el)).toEqual(['pairing']);
	});

	it('refuses to show paired when the token cannot be kept', async () => {
		const data = new Map();
		const storage = {
			getItem: (k) => (data.has(k) ? data.get(k) : null),
			setItem: (k, v) => { if ('ffcTerminal' === k) { throw new Error('quota'); } data.set(k, v); },
			removeItem: (k) => { data.delete(k); },
		};
		const controls = T().start(el, CONFIG, env({ storage, fetch: respond(201, { token: TOKEN, bookings: [1], expires_at: NOW + 60 }) }));
		await controls.pair('P-AB12-CD34-EF56');
		expect(visible(el)).toEqual(['pairing']);
		expect(el.querySelector('[data-ffc-terminal-part="error"]').textContent).toBe('storage');
	});

	it('reads an answer that is not JSON as a refusal', async () => {
		const fetch = () => Promise.resolve({ status: 502, json: () => Promise.reject(new Error('html')) });
		const controls = T().start(el, CONFIG, env({ fetch }));
		await controls.pair('P-AB12-CD34-EF56');
		expect(el.querySelector('[data-ffc-terminal-part="error"]').textContent).toBe('failed');
	});

	it('restarts the countdown when a second lock arrives', async () => {
		const e = env({ fetch: respond(429, { retry_after: 900 }) });
		const controls = T().start(el, CONFIG, e);
		await controls.pair('P-WRON-GCOD-E000');
		await controls.pair('P-WRON-GCOD-E001');
		expect(el.querySelector('[data-ffc-terminal-part="countdown"]').textContent).toBe('locked 15:00');
	});

	it('boots from the localized config', () => {
		window.ffcTerminal = CONFIG;
		try {
			loadScript('assets/js/ffc-terminal-kiosk.js');
			expect(visible(el)).toEqual(['pairing']);
		} finally {
			delete window.ffcTerminal;
		}
	});
});
