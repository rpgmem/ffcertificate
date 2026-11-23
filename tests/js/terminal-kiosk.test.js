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
	loadScript('assets/js/ffc-presence-verifier.js');
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
		queued: 'Saved.',
		alreadyQueued: 'Already saved.',
		queue: 'waiting %d',
		sent: 'sent %d',
		offline: {
			invalid: 'not a valid code',
			ffc_presence_code_revoked: 'phone replaced',
			ffc_terminal_other_event: 'other event',
			full: 'not a valid code',
		},
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
				<p data-ffc-terminal-part="queue"></p>
				<div data-ffc-terminal-part="menu-ask">
					<button type="button" data-ffc-terminal-part="menu-sync">Send</button>
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

		const sent = calls.find((c) => c[1] === 'readings');
		expect(JSON.parse(sent[2].body)).toEqual({ code: 'FFP1ABC' });
		expect(sent[2].headers['X-FFC-Terminal']).toBe(TOKEN);
		expect(sent[2].credentials).toBe('omit');
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
		expect(calls.some((c) => c[1] === 'unlock')).toBe(true);
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

describe('offline queue', () => {
	/** A code `ffc-presence-app.js` built, read at NOW - 10 for booking 42 of user 7, with a ticket. */
	const CODE = 'FFP1V505LJ*D0Y86000-*0K UICV$TUM.UGJF63682MR38N1VFGDZU3JC3.:M/QQV6O*SM$RSJBQ.S6E$M-/7*I9%6RQ4BRB5E7F%OKQNJGYPTQ7A3M%MMFPDUYONPD$SNA21GECMED+34Z%5QBILI2U%I.$8 HD0.33JMBXC%74SH7*25:%N1L3B 0FW2BH6QUTXR0RBW/94J5IY%OF*NS670C498QX6E+0RS180U1L1SPIE000.00FPDKOQ$AA*D0Z86000.00000700HPD/L42UR85CC/BHOL%-7RFQ2.PU3NO3V9KHNRR9/N/%1MMU*9K.4VOOBRGE8S3CE8JX4-EJ6TBD4QNVN 8MM-H$87 03J+HM*1* A7ULE1L: H8BB*J0+XVWTKBW1SYUKT1Q9BDP0NXLJ8EUGHPIG4-T5.AFDMK2KO:SH30P/BKL05%UJ+2N3KJ*PSBG5H0E7Q$OU';
	const SITE_KEY = 'BKuK2S5iFEMntdf5GCk1h7vr1968vvmBmAoDm85wcCaI3fejfA5qHwJsS_H58PyVqncMWlOm0L92WTsKUjqGCjI';
	let el;

	function offline({ online = false, bookings = [42], routes = {}, extra = {} } = {}) {
		el = markup();
		const calls = [];
		const storage = memoryStorage({ ffcTerminal: JSON.stringify({ token: TOKEN, bookings, keys: { p1: SITE_KEY }, expires_at: NOW + 3600, expires_label: 'later' }), ...extra });
		const e = env({
			storage,
			subtle: webcrypto.subtle,
			verifier: window.FFCPresenceVerifier,
			online: () => online,
			fetch: (url, options) => {
				const path = url.replace(CONFIG.rest, '');
				calls.push([options.method, path, options]);
				const answer = routes[options.method + ' ' + path];
				if (typeof answer === 'function') {
					return answer(options);
				}
				return answer ? respond(answer[0], answer[1])() : Promise.reject(new Error('offline'));
			},
		});
		const controls = T().start(el, CONFIG, e);
		return { e, calls, controls, storage };
	}

	const ok = () => el.querySelector('[data-ffc-terminal-part="result-ok"]').textContent;
	const refused = () => el.querySelector('[data-ffc-terminal-part="result-refused"]').textContent;
	const queued = (storage) => JSON.parse(storage.data.get('ffcTerminalQueue') || '[]');

	it('checks the code itself and keeps it with the moment it read it', async () => {
		const { controls, storage, calls } = offline();
		await controls.read(CODE);
		expect(ok()).toBe('Maria S. — Saved.');
		const list = queued(storage);
		expect(list).toHaveLength(1);
		expect(list[0].code).toBe(CODE);
		expect(list[0].at).toBe(NOW);
		expect(list[0].id).toMatch(/^[A-Za-z0-9_-]{24}$/);
		expect(calls.some((c) => c[1] === 'readings')).toBe(false);
	});

	it('keeps one reading per person, event and day', async () => {
		const { controls, storage } = offline();
		await controls.read(CODE);
		await controls.read(CODE);
		expect(ok()).toBe('Maria S. — Already saved.');
		expect(queued(storage)).toHaveLength(1);
	});

	it('refuses offline what the site would refuse: a replaced phone or another event', async () => {
		const verified = await window.FFCPresenceVerifier.verify(CODE, { now: NOW, keys: { p1: SITE_KEY }, revoked: [], subtle: webcrypto.subtle });
		let run = offline({ extra: { ffcTerminalRevoked: JSON.stringify({ revoked: [verified.keyId] }) } });
		await run.controls.read(CODE);
		expect(refused()).toBe('phone replaced');
		expect(queued(run.storage)).toHaveLength(0);

		run = offline({ bookings: [1] });
		await run.controls.read(CODE);
		expect(refused()).toBe('other event');

		run = offline();
		await run.controls.read('FFP1BB8');
		expect(refused()).toBe('not a valid code');
	});

	it('falls back to the queue when the site cannot be reached', async () => {
		const { controls, storage, calls } = offline({ online: true });
		await controls.read(CODE);
		expect(calls.some((c) => c[1] === 'readings')).toBe(true);
		expect(queued(storage)).toHaveLength(1);
	});

	it('sends the queue and drops what the site answered, keeping it on a failure', async () => {
		let answer = [500, {}];
		const { controls, storage } = offline({
			online: true,
			routes: {
				'POST sync': (options) => {
					const body = JSON.parse(options.body);
					expect(body.readings[0]).toEqual({ id: expect.any(String), code: CODE, at: NOW });
					const results = {};
					body.readings.forEach((r) => { results[r.id] = { status: 'recorded' }; });
					return respond(answer[0], 200 === answer[0] ? { results } : {})();
				},
			},
		});
		await controls.read(CODE);
		expect(await controls.sync()).toBe(-1);
		expect(queued(storage)).toHaveLength(1);

		answer = [200];
		expect(await controls.sync()).toBe(1);
		expect(storage.data.has('ffcTerminalQueue')).toBe(false);
		expect(await controls.sync()).toBe(0);
	});

	it('sends the queue from the operator menu and says how many went', async () => {
		const { controls } = offline({
			online: true,
			routes: {
				'POST unlock': [200, { name: 'Ana L.' }],
				'POST sync': (options) => {
					const results = {};
					JSON.parse(options.body).readings.forEach((r) => { results[r.id] = { status: 'recorded' }; });
					return respond(200, { results })();
				},
			},
		});
		await controls.read(CODE);
		await controls.unlock('FFO1OPERATOR');
		expect(el.querySelector('[data-ffc-terminal-part="queue"]').textContent).toBe('waiting 1');
		el.querySelector('[data-ffc-terminal-part="menu-sync"]').click();
		await settle();
		expect(el.querySelector('[data-ffc-terminal-part="status"]').textContent).toBe('sent 1');
		expect(el.querySelector('[data-ffc-terminal-part="queue"]').textContent).toBe('waiting 0');
	});

	it('refreshes the revocation list and the site keys with the terminal token', async () => {
		const { controls, storage, calls } = offline({
			online: true,
			routes: { 'GET revocations': [200, { keys: { p2: 'KEY2' }, revoked: ['abc'], generated: NOW }] },
		});
		expect(await controls.refreshRevocations()).toBe(true);
		const call = calls.find((c) => c[1] === 'revocations');
		expect(call[2].headers['X-FFC-Terminal']).toBe(TOKEN);
		expect(call[2].body).toBeUndefined();
		expect(JSON.parse(storage.data.get('ffcTerminalRevoked')).revoked).toEqual(['abc']);
		expect(JSON.parse(storage.data.get('ffcTerminal')).keys).toEqual({ p2: 'KEY2' });
	});

	it('drops the queue when the pairing ends, since nothing can send it any more', async () => {
		const { controls, storage, e } = offline();
		await controls.read(CODE);
		e.clock.now = (NOW + 3601) * 1000;
		e.timers[0]();
		expect(storage.data.has('ffcTerminalQueue')).toBe(false);
		expect(storage.data.has('ffcTerminalRevoked')).toBe(false);
	});

	it('treats a damaged queue or revocation list as empty, and keeps going', async () => {
		const { controls, storage } = offline({ extra: { ffcTerminalQueue: '{broken', ffcTerminalRevoked: '{broken' } });
		await controls.read(CODE);
		expect(ok()).toBe('Maria S. — Saved.');
		expect(queued(storage)).toHaveLength(1);
	});

	it('stops keeping readings when the queue is full or the storage refuses', async () => {
		const full = Array.from({ length: 2000 }, (_, i) => ({ id: 'q' + i, code: 'FFP1', at: NOW, key: 'other:' + i }));
		let run = offline({ extra: { ffcTerminalQueue: JSON.stringify(full) } });
		await run.controls.read(CODE);
		expect(refused()).toBe('not a valid code');

		run = offline();
		const setItem = run.storage.setItem;
		run.storage.setItem = (k, v) => { if ('ffcTerminalQueue' === k) { throw new Error('quota'); } setItem(k, v); };
		await run.controls.read(CODE);
		expect(refused()).toBe('storage');
	});

	it('sends a long queue in batches and never runs two syncs at once', async () => {
		const long = Array.from({ length: 70 }, (_, i) => ({ id: 'q' + i, code: 'FFP1', at: NOW, key: 'k' + i }));
		let posts = 0;
		const { controls, storage } = offline({
			online: true,
			extra: { ffcTerminalQueue: JSON.stringify(long) },
			routes: {
				'POST sync': (options) => {
					posts++;
					const results = {};
					JSON.parse(options.body).readings.forEach((r) => { results[r.id] = { status: 'recorded' }; });
					return respond(200, { results })();
				},
			},
		});
		const first = controls.sync();
		expect(controls.sync()).toBe(first);
		expect(await first).toBe(70);
		expect(posts).toBe(2);
		expect(storage.data.has('ffcTerminalQueue')).toBe(false);
	});

	it('sends the queue as soon as the connection returns', async () => {
		let back = null;
		let connected = false;
		el = markup();
		const storage = memoryStorage({
			ffcTerminal: JSON.stringify({ token: TOKEN, bookings: [42], keys: {}, expires_at: NOW + 3600 }),
			ffcTerminalQueue: JSON.stringify([{ id: 'q1', code: 'FFP1', at: NOW, key: 'k' }]),
		});
		const calls = [];
		const e = env({
			storage,
			online: () => connected,
			onOnline: (fn) => { back = fn; },
			fetch: (url, options) => {
				calls.push(url.replace(CONFIG.rest, ''));
				return respond(200, { results: { q1: { status: 'recorded' } }, revoked: [] })();
			},
		});
		T().start(el, CONFIG, e);
		await settle();
		expect(calls).not.toContain('sync');
		connected = true;
		back();
		await settle();
		expect(calls).toContain('sync');
		expect(storage.data.has('ffcTerminalQueue')).toBe(false);
	});

	it('ignores an empty reading', async () => {
		const { calls } = offline({ online: true });
		el.querySelector('[data-ffc-terminal-part="reading"]').value = '   ';
		el.querySelector('[data-ffc-terminal-part="reading-form"]').dispatchEvent(new window.Event('submit', { cancelable: true }));
		await settle();
		expect(calls.some((c) => c[1] === 'readings')).toBe(false);
	});
});
