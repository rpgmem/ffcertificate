/**
 * Check-in terminal page (#2744, #2745): pairing with a `P-` code, the lock
 * the site answers with, the pairing the page keeps across a reload, reading
 * presence codes and the operator menu; the event it checks in now or
 * next, from the schedule the site hands it (#2746); reading with the
 * device camera through BarcodeDetector (#2747); keeping the screen on
 * while it has an event (#2775).
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
		idleRefused: 'idle refused',
		offlinePairing: 'pairing needs a connection',
		storage: 'storage',
		pairing: 'pairing',
		paired: 'paired',
		failed: 'failed',
		events: 'events %d',
		until: 'until %s',
		expired: 'expired',
		revoked: 'revoked, queue sent',
		operator: 'by %s',
		clockOff: 'clock off %d s',
		ended: 'ended',
		queued: 'Saved.',
		alreadyQueued: 'Already saved.',
		queue: 'waiting %d',
		sent: 'sent %d',
		nowChecking: 'Now',
		nextEvent: 'Next',
		openUntil: 'open until %s',
		notOpen: 'not open yet',
		opensAt: 'opens %s',
		noEvents: 'no more events',
		terminalTitle: 'Terminal',
		notPaired: 'Not paired',
		checkinUntil: 'check-in until %s',
		present: '%1$d of %2$d present',
		presentOnly: '%d present',
		opensIn: 'opens at %s, in',
		readyTitle: 'Show the code',
		readyLead: 'Open the app',
		cameraTitle: 'Show it to the camera',
		cameraLead: 'Inside the frame',
		recordedTitle: 'Recorded',
		alreadyTitle: 'Already',
		queuedTitle: 'Saved here',
		refusedTitle: 'Refused',
		recordedDetail: 'certificate later',
		openBadge: 'no enrolment',
		openDetail: 'open event',
		help: 'ask the team',
		nextPerson: 'next in %d s',
		unlockWait: 'back in %d s',
		connIdle: 'online',
		connQueue: 'online, %d to send',
		connOffline: 'offline, %d kept',
		connCamera: 'camera',
		setup: { operator: 'by %s', count: '%1$d of %2$d chosen', full: 'full' },
		queueEdit: { now: 'Now', remove: 'Remove', confirmRemove: 'Tap again', add: 'Add', full: 'Full', saved: 'Updated.', summary: '%1$d of %2$d events', max: 10 },
		titles: { ffc_terminal_other_event: 'Other event', ffc_presence_code_revoked: 'Phone replaced' },
		wakeUnsupported: 'cannot keep the screen on',
		wakeRefused: 'screen on refused',
		check: {
			secureLabel: 'HTTPS', cameraLabel: 'Camera', wakeLabel: 'Screen', browserLabel: 'Browser', screenLabel: 'Visible',
			yes: 'yes', no: 'no', cameraOn: 'camera on', cameraOff: 'camera off', cameraInsecure: 'camera needs https',
			cameraUnsupported: 'camera unsupported', wakeActive: 'screen active', wakeAvailable: 'screen available', wakeUnsupported: 'screen unsupported',
		},
		offline: {
			invalid: 'not a valid code',
			ffc_presence_code_revoked: 'phone replaced',
			ffc_terminal_other_event: 'other event',
			full: 'not a valid code',
		},
	},
};

const resultLines = (el, name) => Array.from(el.querySelectorAll('[data-ffc-terminal-part="' + name + '"] p')).map((p) => p.textContent);
// A recorded result as "name — what happened", from the screen's lines (#2780).
const okLine = (el) => {
	const l = resultLines(el, 'result-ok');
	return l.length ? l[1] + ' — ' + l[l.length - 1].split(' · ').slice(1).join(' · ') : '';
};
const refusedLine = (el) => resultLines(el, 'result-refused')[1] || '';

function markup() {
	document.body.innerHTML = `
		<main data-ffc-terminal data-ffc-terminal-tone="neutral">
			<header>
				<h1 data-ffc-terminal-part="head-title"></h1>
				<p data-ffc-terminal-part="head-sub"></p>
				<p data-ffc-terminal-part="clock"></p>
				<p data-ffc-terminal-part="head-note"></p>
			</header>
			<p data-ffc-terminal-part="status"></p>
			<p data-ffc-terminal-part="error" hidden></p>
			<p data-ffc-terminal-part="offline" hidden></p>
			<form data-ffc-terminal-part="reading-form"><input data-ffc-terminal-part="reading"><button type="submit">Check in</button></form>
			<section data-ffc-terminal-view="idle" hidden></section>
			<section data-ffc-terminal-view="pairing" hidden>
				<div data-ffc-terminal-camera-slot="pairing"></div>
				<div data-ffc-terminal-camera-art></div>
				<form data-ffc-terminal-part="form"><input data-ffc-terminal-part="code"><button type="submit">Pair</button></form>
				<button type="button" data-ffc-terminal-part="pairing-cancel">Cancel</button>
			</section>
			<section data-ffc-terminal-view="setup" hidden>
				<p data-ffc-terminal-part="setup-operator"></p>
				<form data-ffc-terminal-part="setup-form">
					<select data-ffc-terminal-part="setup-place"><option value="">One by one</option></select>
					<p data-ffc-terminal-part="setup-count"></p>
					<ul data-ffc-terminal-part="setup-list"></ul>
					<p data-ffc-terminal-part="setup-none" hidden></p>
					<input data-ffc-terminal-part="setup-label">
					<p data-ffc-terminal-part="setup-error" hidden></p>
					<button type="submit" data-ffc-terminal-part="setup-submit">Pair</button>
					<button type="button" data-ffc-terminal-part="setup-cancel">Cancel</button>
				</form>
			</section>
			<section data-ffc-terminal-view="locked" hidden><p data-ffc-terminal-part="countdown"></p></section>
			<section data-ffc-terminal-view="ready" hidden>
				<div class="ffc-terminal-ready">
					<div data-ffc-terminal-camera-slot="ready"><div data-ffc-terminal-part="viewfinder" hidden><video data-ffc-terminal-part="camera-video"></video><canvas data-ffc-terminal-part="camera-still" hidden></canvas></div></div>
					<div data-ffc-terminal-part="art"></div>
					<h2 data-ffc-terminal-part="ready-title"></h2>
					<p data-ffc-terminal-part="ready-lead"></p>
					<ul data-ffc-terminal-part="camera-tips" hidden></ul>
					<div data-ffc-terminal-part="camera" hidden>
						<p data-ffc-terminal-part="camera-note"></p>
						<button type="button" data-ffc-terminal-part="camera-start">Use camera</button>
						<button type="button" data-ffc-terminal-part="camera-stop" hidden>Stop</button>
					</div>
				</div>
			</section>
			<section data-ffc-terminal-view="between" hidden>
				<h2 data-ffc-terminal-part="next-title"></h2>
				<p data-ffc-terminal-part="next-where"></p>
				<p data-ffc-terminal-part="next-opens"></p>
				<p data-ffc-terminal-part="next-countdown"></p>
			</section>
			<section data-ffc-terminal-view="result" data-ffc-terminal-part="result" hidden>
				<div role="status" data-ffc-terminal-part="result-ok"></div>
				<div role="alert" data-ffc-terminal-part="result-refused"></div>
				<div data-ffc-terminal-part="result-next"><span><span data-ffc-terminal-part="result-bar"></span></span><p data-ffc-terminal-part="result-wait"></p></div>
			</section>
			<section data-ffc-terminal-view="unlock" hidden>
				<div data-ffc-terminal-camera-slot="unlock"></div>
				<div data-ffc-terminal-camera-art></div>
				<p data-ffc-terminal-part="unlock-wait"></p>
				<button type="button" data-ffc-terminal-part="unlock-cancel">Cancel</button>
				<button type="button" data-ffc-terminal-part="unlock-code" hidden>I have a pairing code</button>
			</section>
			<section data-ffc-terminal-view="menu" hidden>
				<h2 data-ffc-terminal-part="operator"></h2>
				<p data-ffc-terminal-part="queue"></p>
				<p data-ffc-terminal-part="wake-note" hidden></p>
				<p data-ffc-terminal-part="clock-note" hidden></p>
				<dl data-ffc-terminal-part="device-check"></dl>
				<div data-ffc-terminal-part="menu-ask">
					<button type="button" data-ffc-terminal-part="menu-close">Back</button>
					<button type="button" data-ffc-terminal-part="menu-sync">Send</button>
					<button type="button" data-ffc-terminal-part="menu-end">End</button>
				</div>
				<div data-ffc-terminal-part="menu-confirm" hidden>
					<button type="button" data-ffc-terminal-part="menu-keep">Keep</button>
					<button type="button" data-ffc-terminal-part="menu-confirm-end">End</button>
				</div>
				<p data-ffc-terminal-part="label"></p>
				<p data-ffc-terminal-part="events"></p>
				<p data-ffc-terminal-part="until"></p>
				<p data-ffc-terminal-part="queue-summary"></p>
				<ul data-ffc-terminal-part="queue-list"></ul>
				<p data-ffc-terminal-part="queue-error" hidden></p>
				<p data-ffc-terminal-part="queue-locked" hidden></p>
				<div data-ffc-terminal-part="queue-edit" hidden><button type="button" data-ffc-terminal-part="queue-open-add">Add event</button></div>
				<div data-ffc-terminal-part="queue-add" hidden>
					<ul data-ffc-terminal-part="queue-offered"></ul>
					<p data-ffc-terminal-part="queue-none" hidden></p>
					<button type="button" data-ffc-terminal-part="queue-close-add">Close</button>
				</div>
			</section>
			<footer data-ffc-terminal-part="foot" hidden>
				<p data-ffc-terminal-part="conn"></p>
				<button type="button" data-ffc-terminal-part="lock" aria-label="Operator area"></button>
			</footer>
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
	const sent = [];
	return {
		clock,
		timers,
		timeouts,
		sent,
		now: () => clock.now,
		storage: memoryStorage(),
		crypto: webcrypto,
		fetch: () => Promise.reject(new Error('no network')),
		setInterval: (fn) => { timers.push(fn); return timers.length; },
		clearInterval: () => {},
		setTimeout: (fn, ms) => { timeouts.push([fn, ms]); return timeouts.length; },
		clearTimeout: () => {},
		...overrides,
		// GETs carry a unique `_ffc` stamp so no cache can answer them
		// (#2832); the doubles see the address without it.
		fetch: (url, options) => (sent.push([String(url), options]), overrides.fetch || (() => Promise.reject(new Error('no network'))))(String(url).replace(/[?&]_ffc=\d+$/, ''), options),
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

	it('starts locked without events, and reaches the pairing code only from the lock (#2794)', () => {
		const controls = T().start(el, CONFIG, env());
		expect(visible(el)).toEqual(['idle']);
		expect(el.querySelector('[data-ffc-terminal-part="foot"]').hidden).toBe(false);
		expect(document.activeElement).toBe(el.querySelector('[data-ffc-terminal-part="reading"]'));
		el.querySelector('[data-ffc-terminal-part="lock"]').click();
		expect(controls.screen()).toBe('unlock');
		const link = el.querySelector('[data-ffc-terminal-part="unlock-code"]');
		expect(link.hidden).toBe(false);
		link.click();
		expect(visible(el)).toEqual(['pairing']);
		expect(document.activeElement).toBe(el.querySelector('[data-ffc-terminal-part="code"]'));
		el.querySelector('[data-ffc-terminal-part="pairing-cancel"]').click();
		expect(visible(el)).toEqual(['idle']);
	});

	it('refuses a presence code while locked, and opens setup on an operator code (#2794)', async () => {
		const calls = [];
		const controls = T().start(el, CONFIG, env({
			fetch: (url, options) => {
				calls.push(url.replace(CONFIG.rest, ''));
				return respond(200, { max: 10, name: 'Ana L.', bookings: [] })(url, options);
			},
		}));
		const form = el.querySelector('[data-ffc-terminal-part="reading-form"]');
		el.querySelector('[data-ffc-terminal-part="reading"]').value = 'FFP1SOMEONE';
		form.dispatchEvent(new Event('submit', { cancelable: true }));
		expect(el.querySelector('[data-ffc-terminal-part="result-refused"]').textContent).toContain(CONFIG.strings.idleRefused);
		expect(calls).toEqual([]);
		await controls.startSetup('FFO1OPERATOR');
		expect(calls).toEqual(['setup/events']);
	});

	it('is set up on the terminal with an operator code: events chosen here, no pairing code shown', async () => {
		const calls = [];
		const events = [
			{ id: 11, description: 'Workshop', place: 'Hall', place_id: 3, when: 'Mon' },
			{ id: 12, description: 'Seminar', place: 'Hall', place_id: 3, when: 'Tue' },
			{ id: 13, description: 'Board', place: 'Room 2', place_id: 4, when: 'Wed' },
			{ id: 14, description: 'Packed', place: 'Hall', place_id: 3, full: true },
		];
		const e = env({
			fetch: (url, options) => {
				const path = url.replace(CONFIG.rest, '');
				calls.push([path, options.body ? JSON.parse(options.body) : null]);
				if ('setup/events' === path) {
					return respond(200, { max: 10, name: 'Ana L.', bookings: events })();
				}
				return respond(201, { token: TOKEN, terminal_id: 7, label: 'Entrance', bookings: [11, 12], expires_at: NOW + 3600, expires_label: 'tonight', keys: {} })();
			},
		});
		T().start(el, CONFIG, e);
		// A wedge reader types the operator code into the pairing field.
		el.querySelector('[data-ffc-terminal-part="code"]').value = 'FFO1ABC$%';
		el.querySelector('[data-ffc-terminal-part="form"]').dispatchEvent(new window.Event('submit', { cancelable: true }));
		await settle();
		expect(calls[0]).toEqual(['setup/events', { code: 'FFO1ABC$%' }]);
		expect(visible(el)).toEqual(['setup']);
		expect(el.querySelector('[data-ffc-terminal-part="setup-operator"]').textContent).toBe('by Ana L.');
		const place = el.querySelector('[data-ffc-terminal-part="setup-place"]');
		expect(Array.from(place.options).map((o) => o.textContent)).toEqual(['One by one', 'Hall', 'Room 2']);

		place.value = '3';
		place.dispatchEvent(new window.Event('change'));
		const checked = Array.from(el.querySelectorAll('[data-ffc-terminal-part="setup-list"] input')).filter((b) => b.checked).map((b) => b.value);
		expect(checked).toEqual(['11', '12'], 'one place fills the queue, a full event left out');
		expect(el.querySelector('[data-ffc-terminal-part="setup-count"]').textContent).toBe('2 of 10 chosen');
		expect(el.querySelector('[data-ffc-terminal-part="setup-list"] input[value="14"]').disabled).toBe(true);

		el.querySelector('[data-ffc-terminal-part="setup-label"]').value = 'Entrance';
		el.querySelector('[data-ffc-terminal-part="setup-form"]').dispatchEvent(new window.Event('submit', { cancelable: true }));
		await settle();
		expect(calls[1][0]).toBe('setup');
		expect(calls[1][1].bookings).toEqual([11, 12]);
		expect(calls[1][1].label).toBe('Entrance');
		expect(calls[1][1].code).toBe('FFO1ABC$%');
		expect(calls[1][1].device).toMatch(/^[A-Za-z0-9_-]{16,64}$/);
		expect(calls.some((c) => 'pair' === c[0])).toBe(false);
		expect(JSON.parse(e.storage.getItem('ffcTerminal')).token).toBe(TOKEN);
		expect(visible(el)).not.toContain('setup');
	});

	it('says why the site refused the setup and keeps the choices', async () => {
		const e = env({
			fetch: (url) => (url.endsWith('setup/events')
				? respond(200, { max: 10, name: 'Ana L.', bookings: [{ id: 11, description: 'Workshop' }] })()
				: respond(409, { code: 'ffc_terminal_overlap', message: 'Crosses Workshop.' })()),
		});
		const controls = T().start(el, CONFIG, e);
		await controls.startSetup('FFO1ABC');
		el.querySelector('[data-ffc-terminal-part="setup-list"] input').checked = true;
		await controls.finishSetup();
		expect(visible(el)).toEqual(['setup']);
		expect(el.querySelector('[data-ffc-terminal-part="setup-error"]').textContent).toBe('Crosses Workshop.');
		expect(el.querySelector('[data-ffc-terminal-part="setup-list"] input').checked).toBe(true);

		el.querySelector('[data-ffc-terminal-part="setup-cancel"]').click();
		expect(visible(el)).toEqual(['idle']);
	});

	it('stays on the pairing screen when the operator may not set terminals up', async () => {
		const e = env({ fetch: () => respond(403, { code: 'ffc_operator_queue_not_allowed', message: 'Not allowed.' })() });
		const controls = T().start(el, CONFIG, e);
		await controls.startSetup('FFO1ABC');
		expect(visible(el)).toEqual(['idle']);
		expect(el.querySelector('[data-ffc-terminal-part="error"]').textContent).toBe('Not allowed.');
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
		el.querySelector('[data-ffc-terminal-part="form"]').dispatchEvent(new window.Event('submit', { cancelable: true }));
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
		expect(el.querySelector('[data-ffc-terminal-part="countdown"]').textContent).toBe('15:00');
		expect(el.getAttribute('data-ffc-terminal-tone')).toBe('warning');
		expect(el.querySelector('[data-ffc-terminal-part="head-note"]').textContent).toBe('Not paired');
		expect(e.storage.data.get('ffcTerminalLock')).toBe(String(NOW + 900));

		const reloaded = markup();
		const again = env({ storage: e.storage });
		again.clock.now = (NOW + 600) * 1000;
		T().start(reloaded, CONFIG, again);
		expect(visible(reloaded)).toEqual(['locked']);
		expect(reloaded.querySelector('[data-ffc-terminal-part="countdown"]').textContent).toBe('5:00');

		again.clock.now = (NOW + 901) * 1000;
		again.timers[again.timers.length - 1]();
		expect(visible(reloaded)).toEqual(['idle']);
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
		expect(visible(reloaded)).toEqual(['idle']);
		expect(reloaded.querySelector('[data-ffc-terminal-part="error"]').textContent).toBe('expired');
		expect(storage.data.has('ffcTerminal')).toBe(false);
	});
});

describe('a paired terminal with no event left (#2794)', () => {
	it('rests locked, keeps the lock, and hides the pairing-code link', () => {
		const el = markup();
		const state = JSON.stringify({ token: TOKEN, label: 'Entrance A', bookings: [], schedule: [], expires_at: NOW + 3600, expires_label: 'later' });
		const controls = T().start(el, CONFIG, env({ storage: memoryStorage({ ffcTerminal: state }), fetch: () => Promise.reject(new Error('offline')) }));
		expect(visible(el)).toEqual(['idle']);
		expect(el.querySelector('[data-ffc-terminal-part="foot"]').hidden).toBe(false);
		controls.askUnlock();
		expect(controls.screen()).toBe('unlock');
		expect(el.querySelector('[data-ffc-terminal-part="unlock-code"]').hidden).toBe(true);
		el.querySelector('[data-ffc-terminal-part="unlock-cancel"]').click();
		expect(visible(el)).toEqual(['idle']);
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
	const text = (name) => el.querySelector('[data-ffc-terminal-part="' + name + '"]').textContent;
	const lines = (name) => Array.from(el.querySelectorAll('[data-ffc-terminal-part="' + name + '"] p')).map((p) => p.textContent);
	const ok = () => el.querySelector('[data-ffc-terminal-part="result-ok"]').textContent;
	const refused = () => {
		const found = lines('result-refused');
		return found.length ? found[1] : '';
	};

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
		expect(lines('result-ok')).toEqual(['Recorded', 'Maria S.', T().wallClock(NOW * 1000) + ' · certificate later']);
		expect(refused()).toBe('');
		expect(visible(el)).toEqual(['result']);
		expect(el.getAttribute('data-ffc-terminal-tone')).toBe('ok');
		expect(text('result-wait')).toBe('next in 5 s');
		expect(el.querySelector('[data-ffc-terminal-part="reading"]').value).toBe('');

		const [clear, ms] = e.timeouts[e.timeouts.length - 1];
		expect(ms).toBe(5000);
		clear();
		expect(ok()).toBe('');
		expect(visible(el)).toEqual(['ready']);
		expect(el.getAttribute('data-ffc-terminal-tone')).toBe('neutral');
	});

	it('says an open event recorded someone not enrolled, and keeps the queued tone apart', async () => {
		paired({ 'POST readings': [200, { status: 'recorded', code: 'ffc_terminal_recorded', message: 'Attendance recorded.', name: 'Rui P.', open: true, time: '13:52' }] });
		await submit('FFP1ABC');
		expect(lines('result-ok')).toEqual(['Recorded', 'Rui P.', 'no enrolment', '13:52 · open event']);
		expect(el.querySelector('[data-ffc-terminal-part="result-open"]')).not.toBeNull();

		paired({ 'POST readings': [200, { status: 'already', code: 'ffc_terminal_already', message: 'Already recorded at 13:40.', name: 'Rui P.', time: '13:52' }] });
		await submit('FFP1ABC');
		expect(lines('result-ok')[0]).toBe('Already');
		expect(lines('result-ok')[2]).toBe('13:52 · Already recorded at 13:40.');
	});

	it('writes a refusal in the alert region and never a name', async () => {
		paired({ 'POST readings': [200, { status: 'refused', code: 'ffc_terminal_other_event', message: 'Another event.', name: '' }] });
		await submit('FFP1ABC');
		expect(lines('result-refused')).toEqual(['Other event', 'Another event.', 'ask the team']);
		expect(ok()).toBe('');
		expect(el.getAttribute('data-ffc-terminal-tone')).toBe('refused');
	});

	it('says the network failed when the site cannot be reached', async () => {
		paired({});
		await submit('FFP1ABC');
		expect(refused()).toBe('failed');
	});

	it('goes back to pairing when the site no longer knows the token', async () => {
		const { e } = paired({ 'POST readings': [401, { message: 'unpaired' }] });
		await submit('FFP1ABC');
		expect(visible(el)).toEqual(['idle']);
		expect(e.storage.data.has('ffcTerminal')).toBe(false);
		expect(el.querySelector('[data-ffc-terminal-part="error"]').textContent).toBe('expired');
	});

	it('notices on its own when the pairing expires', () => {
		const { e } = paired({});
		e.clock.now = (NOW + 3601) * 1000;
		e.timers[0]();
		expect(visible(el)).toEqual(['idle']);
	});

	it('follows the site clock from each answer and says so in the menu when it was off (#2797)', async () => {
		el = markup();
		const site = new Date((NOW + 40) * 1000).toUTCString();
		const e = env({
			storage: memoryStorage({ ffcTerminal: STATE }),
			fetch: () => Promise.resolve({
				status: 200,
				headers: { get: (name) => ('Date' === name ? site : null) },
				json: () => Promise.resolve({ name: 'Ana L.', expires: NOW + 160 }),
			}),
		});
		T().start(el, CONFIG, e);
		await submit('FFO1OPERATOR');
		expect(visible(el)).toEqual(['menu']);
		expect(e.storage.data.get('ffcTerminalClock')).toBe('40500');
		const note = el.querySelector('[data-ffc-terminal-part="clock-note"]');
		expect(note.hidden).toBe(false);
		expect(note.textContent).toBe('clock off 41 s');
	});

	it('keeps a gap within the tolerance to itself, and an answer without a Date header changes nothing', async () => {
		el = markup();
		const e = env({
			storage: memoryStorage({ ffcTerminal: STATE, ffcTerminalClock: '12000' }),
			fetch: respond(200, { name: 'Ana L.', expires: NOW + 160 }),
		});
		T().start(el, CONFIG, e);
		await submit('FFO1OPERATOR');
		expect(visible(el)).toEqual(['menu']);
		expect(e.storage.data.get('ffcTerminalClock')).toBe('12000');
		expect(el.querySelector('[data-ffc-terminal-part="clock-note"]').hidden).toBe(true);
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

	it('lists the terminal\'s events in the menu and lets only an operator who may pair change them', async () => {
		let c = paired({ 'POST unlock': [200, { name: 'Ana L.', may_edit: false }] });
		await submit('FFO1OPERATOR');
		expect(text('queue-summary')).toBe('1 of 10 events');
		expect(el.querySelector('[data-ffc-terminal-part="queue-locked"]').hidden).toBe(false);
		expect(el.querySelector('[data-ffc-terminal-part="queue-edit"]').hidden).toBe(true);
		expect(el.querySelectorAll('[data-ffc-terminal-remove]')).toHaveLength(0);

		const schedule = [
			{ booking_id: 11, title: 'Workshop', place: 'Hall', hours: '09:00–11:00', open: true },
			{ booking_id: 12, title: 'Seminar', place: 'Room 2', hours: '14:00–16:00', open: false },
		];
		const after = { label: 'Entrance', bookings: [11, 12], schedule, expires_at: NOW + 7200, expires_label: 'tonight' };
		c = paired({
			'POST unlock': [200, { name: 'Ana L.', may_edit: true }],
			'POST self/events': [200, { max: 10, bookings: [{ id: 11, description: 'Workshop' }, { id: 12, description: 'Seminar', place: 'Room 2', when: 'Tue' }, { id: 13, description: 'Packed', full: true }] }],
			'PUT self': [200, after],
		});
		await submit('FFO1OPERATOR');
		expect(el.querySelector('[data-ffc-terminal-part="queue-edit"]').hidden).toBe(false);

		el.querySelector('[data-ffc-terminal-part="queue-open-add"]').click();
		await settle();
		const add = el.querySelectorAll('[data-ffc-terminal-add]');
		expect(Array.from(add).map((b) => b.getAttribute('data-ffc-terminal-add'))).toEqual(['12', '13'], 'an event already held is not offered');
		expect(add[1].disabled).toBe(true);
		expect(add[1].textContent).toBe('Full');
		add[0].click();
		await settle();
		const put = c.calls.filter((call) => 'PUT' === call[0]).pop();
		expect(JSON.parse(put[2].body)).toEqual({ code: 'FFO1OPERATOR', bookings: [11, 12] });
		expect(text('queue-summary')).toBe('2 of 10 events');
		expect(el.querySelector('.ffc-terminal-queue-item.is-open strong').textContent).toBe('Workshop');
		expect(text('events')).toBe('events 2');

		const remove = el.querySelector('[data-ffc-terminal-remove="12"]');
		remove.click();
		expect(remove.textContent).toBe('Tap again');
		expect(c.calls.filter((call) => 'PUT' === call[0])).toHaveLength(1);
		remove.click();
		await settle();
		const second = c.calls.filter((call) => 'PUT' === call[0]).pop();
		expect(JSON.parse(second[2].body).bookings).toEqual([11]);
	});

	it('says why the site refused a change to the events', async () => {
		paired({
			'POST unlock': [200, { name: 'Ana L.', may_edit: true }],
			'POST self/events': [200, { max: 10, bookings: [{ id: 12, description: 'Seminar' }] }],
			'PUT self': [409, { code: 'ffc_terminal_overlap', message: 'Its check-in crosses Workshop.' }],
		});
		await submit('FFO1OPERATOR');
		el.querySelector('[data-ffc-terminal-part="queue-open-add"]').click();
		await settle();
		el.querySelector('[data-ffc-terminal-add="12"]').click();
		await settle();
		expect(el.querySelector('[data-ffc-terminal-part="queue-error"]').hidden).toBe(false);
		expect(text('queue-error')).toBe('Its check-in crosses Workshop.');
	});

	it('refuses an operator code the site does not accept', async () => {
		paired({ 'POST unlock': [403, { message: 'expired code' }] });
		await submit('FFO1OPERATOR');
		expect(visible(el)).toEqual(['result']);
		expect(lines('result-refused')).toEqual(['Refused', 'expired code', 'ask the team']);
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
		expect(visible(el)).toEqual(['idle']);
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
		expect(visible(el)).toEqual(['idle']);
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
		expect(visible(el)).toEqual(['idle']);
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
		expect(el.querySelector('[data-ffc-terminal-part="countdown"]').textContent).toBe('15:00');
	});

	it('boots from the localized config', () => {
		window.ffcTerminal = CONFIG;
		try {
			loadScript('assets/js/ffc-terminal-kiosk.js');
			expect(visible(el)).toEqual(['idle']);
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

	const ok = () => okLine(el);
	const refused = () => refusedLine(el);
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

	it('sends what it read before a revocation, then ends and says so (#2802)', async () => {
		const { controls, storage } = offline({
			online: true,
			routes: {
				'POST sync': (options) => {
					const results = {};
					JSON.parse(options.body).readings.forEach((r) => { results[r.id] = { status: 'recorded' }; });
					return respond(200, { results, revoked: true })();
				},
			},
		});
		await controls.read(CODE);
		expect(await controls.sync()).toBe(1);
		expect(storage.data.has('ffcTerminal')).toBe(false);
		expect(storage.data.has('ffcTerminalQueue')).toBe(false);
		expect(visible(el)).toEqual(['idle']);
		expect(el.querySelector('[data-ffc-terminal-part="error"]').textContent).toBe('revoked, queue sent');
	});

	it('sends the queue before ending when the site refuses the token elsewhere (#2802)', async () => {
		const sent = [];
		const run = offline({
			online: true,
			routes: {
				'POST readings': [401, { message: 'unpaired' }],
				'POST sync': (options) => {
					const results = {};
					JSON.parse(options.body).readings.forEach((r) => { sent.push(r.id); results[r.id] = { status: 'recorded' }; });
					return respond(200, { results, revoked: true })();
				},
			},
			extra: { ffcTerminalQueue: JSON.stringify([{ id: 'q1', code: CODE, at: NOW, key: 'k1' }]) },
		});
		await run.controls.read(CODE);
		await settle();
		expect(sent).toEqual(['q1']);
		expect(run.storage.data.has('ffcTerminal')).toBe(false);
		expect(el.querySelector('[data-ffc-terminal-part="error"]').textContent).toBe('revoked, queue sent');
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

	it('asks the site itself for the revocation list, never a cached answer', async () => {
		const { controls, e } = offline({
			online: true,
			routes: { 'GET revocations': [200, { keys: {}, revoked: [], generated: NOW }] },
		});
		await controls.refreshRevocations();
		const [address, options] = e.sent.find((s) => s[0].includes('revocations'));
		expect(address).toMatch(/revocations\?_ffc=\d+$/);
		expect(options.cache).toBe('no-store');
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

describe('event queue', () => {
	let el;
	const SCHEDULE = [
		{ booking_id: 11, title: 'Morning talk', place: 'Hall', opens: NOW - 600, closes: NOW + 1200, opens_label: '09:50', closes_label: '10:20', open: true, present: 38, enrolled: 120 },
		{ booking_id: 12, title: 'Workshop', place: 'Room 3', hours: '11:30–13:00', opens: NOW + 3600, closes: NOW + 7200, opens_label: '15/01 11:00', opens_time: '11:00', closes_label: '12:00', open: false },
	];
	const text = (name) => el.querySelector('[data-ffc-terminal-part="' + name + '"]').textContent;

	function paired(state, routes = {}) {
		el = markup();
		const calls = [];
		const e = env({
			storage: memoryStorage({ ffcTerminal: JSON.stringify({ token: TOKEN, label: 'Entrance A', bookings: [11, 12], expires_at: NOW + 86400, expires_label: 'later', ...state }) }),
			online: () => true,
			fetch: (url, options) => {
				const path = url.replace(CONFIG.rest, '');
				calls.push([options.method, path]);
				const answer = routes[options.method + ' ' + path];
				return answer ? respond(answer[0], answer[1])() : Promise.reject(new Error('offline'));
			},
		});
		const controls = T().start(el, CONFIG, e);
		return { e, calls, controls };
	}

	it('picks the window that holds the moment, preferring an opened one, and the next to open', () => {
		const closedTwin = { ...SCHEDULE[0], booking_id: 13, open: false };
		expect(T().whatNow([closedTwin, SCHEDULE[0], SCHEDULE[1]], NOW)).toEqual({ current: SCHEDULE[0], next: SCHEDULE[1] });
		expect(T().whatNow(SCHEDULE, NOW + 1200)).toEqual({ current: null, next: SCHEDULE[1] });
		expect(T().whatNow(SCHEDULE, NOW + 7200)).toEqual({ current: null, next: null });
		expect(T().whatNow(undefined, NOW)).toEqual({ current: null, next: null });
	});

	it('shows only the current event during its window, with its place and the present count', () => {
		paired({ schedule: SCHEDULE });
		expect(visible(el)).toEqual(['ready']);
		expect(text('head-title')).toBe('Morning talk');
		expect(text('head-sub')).toBe('Hall · check-in until 10:20');
		expect(text('head-note')).toBe('38 of 120 present');
		expect(el.querySelector('[data-ffc-terminal-part="foot"]').hidden).toBe(false);
		expect(text('conn')).toBe('online');
	});

	it('says a planned window waits for the organiser until it is opened', () => {
		paired({ schedule: [{ ...SCHEDULE[0], open: false, present: 0, enrolled: 0 }] });
		expect(text('head-sub')).toBe('Hall · not open yet');
		expect(el.querySelector('[data-ffc-terminal-part="head-note"]').hidden).toBe(true);
	});

	it('counts down to the next event between windows, and switches when it opens', () => {
		const { e } = paired({ schedule: SCHEDULE });
		e.clock.now = (NOW + 1800) * 1000;
		e.timers.forEach((fn) => fn());
		expect(visible(el)).toEqual(['between']);
		expect(text('next-title')).toBe('Workshop');
		expect(text('next-where')).toBe('Room 3 · 11:30–13:00');
		expect(text('next-opens')).toBe('opens at 11:00, in');
		expect(text('next-countdown')).toBe('30:00');

		e.clock.now = (NOW + 3600) * 1000;
		e.timers.forEach((fn) => fn());
		expect(visible(el)).toEqual(['ready']);
		expect(text('head-title')).toBe('Workshop');
	});

	it('says there is nothing left, and names the terminal before it has a schedule', () => {
		paired({ schedule: [] });
		expect(text('head-sub')).toBe('no more events');
		paired({});
		expect(text('head-title')).toBe('Entrance A');
		expect(el.querySelector('[data-ffc-terminal-part="head-sub"]').hidden).toBe(true);
	});

	it('asks for the operator QR behind the lock, and goes back to reading on its own', () => {
		const { e } = paired({ schedule: SCHEDULE });
		el.querySelector('[data-ffc-terminal-part="lock"]').click();
		expect(visible(el)).toEqual(['unlock']);
		expect(text('unlock-wait')).toBe('back in 30 s');
		expect(document.activeElement).toBe(el.querySelector('[data-ffc-terminal-part="reading"]'));
		el.querySelector('[data-ffc-terminal-part="unlock-cancel"]').click();
		expect(visible(el)).toEqual(['ready']);

		el.querySelector('[data-ffc-terminal-part="lock"]').click();
		e.clock.now += 31000;
		e.timers[e.timers.length - 1]();
		expect(visible(el)).toEqual(['ready']);
	});

	it('follows a change made in the app on its next request', async () => {
		const { calls, controls } = paired({ schedule: SCHEDULE }, {
			'GET self': [200, { label: 'Entrance B', bookings: [12], expires_at: NOW + 9000, expires_label: 'sooner', schedule: [SCHEDULE[1]] }],
		});
		await settle();
		expect(calls.some((c) => c[0] === 'GET' && c[1] === 'self')).toBe(true);
		expect(controls.state().bookings).toEqual([12]);
		expect(controls.state().expires_at).toBe(NOW + 9000);
		expect(text('label')).toBe('Entrance B');
		expect(text('events')).toBe('events 1');
		expect(text('until')).toBe('until sooner');
		expect(text('next-title')).toBe('Workshop');
	});

	it('keeps the schedule it has when the site does not answer, and asks nothing offline', async () => {
		const { controls } = paired({ schedule: SCHEDULE }, { 'GET self': [500, {}] });
		await settle();
		expect(await controls.refreshSchedule()).toBe(false);
		expect(controls.state().schedule).toEqual(SCHEDULE);

		el = markup();
		const off = T().start(el, CONFIG, env({ storage: memoryStorage({ ffcTerminal: JSON.stringify({ token: TOKEN, expires_at: NOW + 60, schedule: SCHEDULE }) }), online: () => false }));
		expect(await off.refreshSchedule()).toBe(false);
	});

	it('refreshes the schedule when the connection returns', async () => {
		let reconnect = null;
		const calls = [];
		el = markup();
		T().start(el, CONFIG, env({
			storage: memoryStorage({ ffcTerminal: JSON.stringify({ token: TOKEN, expires_at: NOW + 600 }) }),
			online: () => true,
			onOnline: (fn) => { reconnect = fn; },
			fetch: (url, options) => { calls.push(url.replace(CONFIG.rest, '')); return Promise.reject(new Error('offline')); },
		}));
		calls.length = 0;
		reconnect();
		await settle();
		expect(calls).toContain('self');
	});
});

describe('camera', () => {
	let el;
	const CAMERA = {
		ready: 'allow the camera',
		reading: 'hold it in the frame',
		insecure: 'needs https',
		unsupported: 'cannot read qr',
		denied: 'not allowed',
		failed: 'could not start',
	};
	const config = (camera = true) => ({ ...CONFIG, camera, strings: { ...CONFIG.strings, camera: CAMERA } });
	const text = (name) => el.querySelector('[data-ffc-terminal-part="' + name + '"]').textContent;
	const node = (name) => el.querySelector('[data-ffc-terminal-part="' + name + '"]');
	beforeEach(() => { window.HTMLMediaElement.prototype.play = () => Promise.resolve(); });

	// A frame that holds a code flashes for CAPTURE_MS before the code is
	// handled (#2786): scan, let the flash start, then end it.
	async function scanned(e, controls) {
		const pending = controls.camera.scan();
		await settle();
		const flash = e.timeouts.filter((t) => 450 === t[1]).pop();
		if (flash && !flash.fired) {
			flash.fired = true;
			flash[0]();
		}
		return pending;
	}

	function detectorClass(formats, frames) {
		return class {
			static getSupportedFormats() { return formats; }
			constructor(options) { this.options = options; }
			detect() { return Promise.resolve(frames.length ? frames.shift() : []); }
		};
	}

	function stream() {
		const tracks = [{ stopped: false, stop() { this.stopped = true; } }];
		return { tracks, getTracks: () => tracks };
	}

	function paired(overrides = {}, routes = {}, cfg = config()) {
		el = markup();
		const calls = [];
		const e = env({
			storage: memoryStorage({ ffcTerminal: JSON.stringify({ token: TOKEN, bookings: [11], expires_at: NOW + 3600 }) }),
			online: () => true,
			secure: true,
			fetch: (url, options) => {
				const path = url.replace(CONFIG.rest, '');
				calls.push([options.method, path, options.body]);
				const answer = routes[options.method + ' ' + path];
				return answer ? respond(answer[0], answer[1])() : Promise.reject(new Error('offline'));
			},
			...overrides,
		});
		const controls = T().start(el, cfg, e);
		return { e, calls, controls };
	}

	it('stays hidden unless the site allows the camera', async () => {
		paired({}, {}, config(false));
		expect(node('camera').hidden).toBe(true);
	});

	it('says why the camera cannot be used, from what the browser offers', async () => {
		let c = paired({ secure: false });
		expect(await c.controls.camera.ready).toBe('insecure');
		expect(text('camera-note')).toBe('needs https');
		expect(node('camera-start').disabled).toBe(true);

		c = paired({});
		expect(await c.controls.camera.ready).toBe('unsupported');
		expect(text('camera-note')).toBe('cannot read qr');

		c = paired({ BarcodeDetector: detectorClass(Promise.resolve(['ean_13']), []), mediaDevices: { getUserMedia: () => Promise.reject(new Error('x')) } });
		expect(await c.controls.camera.ready).toBe('unsupported');

		c = paired({ BarcodeDetector: detectorClass(Promise.reject(new Error('no')), []), mediaDevices: { getUserMedia: () => Promise.reject(new Error('x')) } });
		expect(await c.controls.camera.ready).toBe('unsupported');
	});

	it('explains the permission prompt, then reads a code from the viewfinder like the reader would', async () => {
		const feed = stream();
		const frames = [[], [{ rawValue: 'FFP1ABC' }], [{ rawValue: 'FFP1ABC' }]];
		const { e, calls, controls } = paired(
			{ BarcodeDetector: detectorClass(Promise.resolve(['qr_code']), frames), mediaDevices: { getUserMedia: () => Promise.resolve(feed) } },
			{ 'POST readings': [200, { status: 'recorded', message: 'Recorded.', name: 'Maria S.' }] }
		);
		expect(await controls.camera.ready).toBe('ok');

		// The ready screen starts the camera by itself (#2780).
		await settle();
		expect(text('ready-title')).toBe('Show it to the camera');
		expect(node('camera-tips').hidden).toBe(false);
		expect(node('art').hidden).toBe(true);
		expect(el.querySelector('.ffc-terminal-ready').classList.contains('has-camera')).toBe(true);
		expect(node('viewfinder').hidden).toBe(false);
		expect(node('camera-stop').hidden).toBe(false);
		expect(node('camera-start').hidden).toBe(true);
		expect(text('camera-note')).toBe('hold it in the frame');
		expect(node('camera-video').srcObject).toBe(feed);

		await controls.camera.scan();
		expect(calls.filter((c) => c[1] === 'readings')).toHaveLength(0);
		const pending = controls.camera.scan();
		await settle();
		// The capture flashes before the code is sent (#2786).
		expect(node('viewfinder').classList.contains('is-captured')).toBe(true);
		expect(calls.filter((c) => c[1] === 'readings')).toHaveLength(0);
		e.timeouts.filter((t) => 450 === t[1]).pop()[0]();
		await pending;
		await settle();
		expect(node('viewfinder').classList.contains('is-captured')).toBe(false);
		expect(node('camera-still').hidden).toBe(true);
		expect(calls.filter((c) => c[1] === 'readings')).toHaveLength(1);
		expect(okLine(el)).toBe('Maria S. — certificate later');

		await controls.camera.scan();
		expect(calls.filter((c) => c[1] === 'readings')).toHaveLength(1);
		e.clock.now += 6000;
		frames.push([{ rawValue: 'FFP1ABC' }]);
		await scanned(e, controls);
		await settle();
		expect(calls.filter((c) => c[1] === 'readings')).toHaveLength(2);

		node('camera-stop').click();
		expect(feed.tracks[0].stopped).toBe(true);
		expect(node('viewfinder').hidden).toBe(true);
		expect(node('camera-start').hidden).toBe(false);
		expect(await controls.camera.scan()).toBe(null);
	});

	it('sends an operator code to the unlock, and keeps scanning when a frame fails', async () => {
		const frames = [[{ rawValue: 'FFO1XYZ' }]];
		const Detector = detectorClass(Promise.resolve(['qr_code']), frames);
		Detector.prototype.detect = function () { return frames.length ? Promise.resolve(frames.shift()) : Promise.reject(new Error('blur')); };
		const { e, calls, controls } = paired(
			{ BarcodeDetector: Detector, mediaDevices: { getUserMedia: () => Promise.resolve(stream()) } },
			{ 'POST unlock': [200, { name: 'Ana L.', expires: NOW + 120 }] }
		);
		await controls.camera.ready;
		await controls.camera.start();
		await scanned(e, controls);
		await settle();
		expect(calls.some((c) => c[1] === 'unlock')).toBe(true);
		const before = e.timeouts.length;
		expect(await controls.camera.scan()).toBe(null);
		expect(e.timeouts.length).toBeGreaterThan(before);
	});

	it('reads a frame the camera hands over mirrored by flipping it back', async () => {
		const seen = [];
		const Detector = class {
			static getSupportedFormats() { return Promise.resolve(['qr_code']); }
			detect(source) {
				seen.push(source.tagName);
				return Promise.resolve('CANVAS' === source.tagName ? [{ rawValue: 'FFP1ABC' }] : []);
			}
		};
		const drawn = [];
		const context = { setTransform: (...m) => drawn.push(m), drawImage: () => drawn.push('draw') };
		const getContext = window.HTMLCanvasElement.prototype.getContext;
		window.HTMLCanvasElement.prototype.getContext = () => context;
		try {
			const { e, calls, controls } = paired(
				{ BarcodeDetector: Detector, mediaDevices: { getUserMedia: () => Promise.resolve(stream()) } },
				{ 'POST readings': [200, { status: 'recorded', message: 'Recorded.', name: 'Maria S.' }] }
			);
			await controls.camera.ready;
			await settle();
			const video = node('camera-video');
			Object.defineProperty(video, 'videoWidth', { value: 640 });
			Object.defineProperty(video, 'videoHeight', { value: 480 });
			await scanned(e, controls);
			await settle();
			expect(seen).toEqual(['VIDEO', 'CANVAS']);
			expect(drawn[0]).toEqual([-1, 0, 0, 1, 640, 0]);
			expect(drawn).toContainEqual([1, 0, 0, 1, 0, 0]);
			expect(calls.filter((c) => c[1] === 'readings')).toHaveLength(1);
		} finally {
			window.HTMLCanvasElement.prototype.getContext = getContext;
		}
	});

	it('pairs from the camera on the pairing screen and reads the operator code on the unlock screen', async () => {
		el = markup();
		const frames = [[{ rawValue: 'p-ab12-cd34-ef56' }]];
		const calls = [];
		const e = env({
			secure: true,
			online: () => true,
			BarcodeDetector: detectorClass(Promise.resolve(['qr_code']), frames),
			mediaDevices: { getUserMedia: () => Promise.resolve(stream()) },
			fetch: (url, options) => {
				calls.push([options.method, url.replace(CONFIG.rest, ''), options.body]);
				return Promise.reject(new Error('offline'));
			},
		});
		const controls = T().start(el, config(), e);
		await controls.camera.ready;
		await settle();
		expect(controls.screen()).toBe('idle');
		controls.askUnlock();
		await settle();
		expect(node('viewfinder').parentNode.getAttribute('data-ffc-terminal-camera-slot')).toBe('unlock');
		expect(el.querySelector('[data-ffc-terminal-view="unlock"] [data-ffc-terminal-camera-art]').hidden).toBe(true);
		await scanned(e, controls);
		await settle();
		expect(calls[0][1]).toBe('pair');
		expect(JSON.parse(calls[0][2]).code).toBe('P-AB12-CD34-EF56');
	});

	it('moves the viewfinder to the unlock screen', async () => {
		const { e, controls } = paired({ BarcodeDetector: detectorClass(Promise.resolve(['qr_code']), []), mediaDevices: { getUserMedia: () => Promise.resolve(stream()) } });
		await controls.camera.ready;
		await settle();
		controls.askUnlock();
		expect(node('viewfinder').parentNode.getAttribute('data-ffc-terminal-camera-slot')).toBe('unlock');
		expect(el.querySelector('[data-ffc-terminal-view="unlock"] [data-ffc-terminal-camera-art]').hidden).toBe(true);
		expect(e.timeouts.length).toBeGreaterThan(0);
	});

	it('says when the camera is refused or cannot start', async () => {
		const denied = Object.assign(new Error('no'), { name: 'NotAllowedError' });
		let c = paired({ BarcodeDetector: detectorClass(Promise.resolve(['qr_code']), []), mediaDevices: { getUserMedia: () => Promise.reject(denied) } });
		await c.controls.camera.ready;
		expect(await c.controls.camera.start()).toBe(false);
		expect(text('camera-note')).toBe('not allowed');
		expect(node('camera-start').disabled).toBe(false);

		c = paired({ BarcodeDetector: detectorClass(Promise.resolve(['qr_code']), []), mediaDevices: { getUserMedia: () => Promise.reject(new Error('busy')) } });
		await c.controls.camera.ready;
		expect(await c.controls.camera.start()).toBe(false);
		expect(text('camera-note')).toBe('could not start');
	});

	it('stops the camera when the pairing ends', async () => {
		const feed = stream();
		const { e, controls } = paired({ BarcodeDetector: detectorClass(Promise.resolve(['qr_code']), []), mediaDevices: { getUserMedia: () => Promise.resolve(feed) } });
		await controls.camera.ready;
		await controls.camera.start();
		e.clock.now = (NOW + 7200) * 1000;
		e.timers.forEach((fn) => fn());
		expect(feed.tracks[0].stopped).toBe(true);
	});
});

describe('screen wake lock', () => {
	const NOW_EVENT = [{ booking_id: 11, title: 'Morning talk', opens: NOW - 600, closes: NOW + 1200, opens_label: '09:50', closes_label: '10:20', open: true }];

	function wakeLock(refuse = false) {
		const lock = { requests: 0, sentinels: [] };
		lock.request = (type) => {
			lock.requests++;
			lock.type = type;
			if (refuse) {
				return Promise.reject(new Error('NotAllowedError'));
			}
			const listeners = [];
			const sentinel = {
				released: false,
				releases: 0,
				addEventListener: (name, fn) => { if ('release' === name) { listeners.push(fn); } },
				release: () => { sentinel.released = true; sentinel.releases++; listeners.forEach((fn) => fn()); return Promise.resolve(); },
			};
			lock.sentinels.push(sentinel);
			return Promise.resolve(sentinel);
		};
		return lock;
	}

	function start(state, overrides = {}) {
		const el = markup();
		const visibleHandlers = [];
		const e = env({
			storage: memoryStorage({ ffcTerminal: JSON.stringify({ token: TOKEN, bookings: [11], expires_at: NOW + 86400, expires_label: 'later', schedule: NOW_EVENT, ...state }) }),
			onVisible: (fn) => visibleHandlers.push(fn),
			visible: () => true,
			...overrides,
		});
		const controls = T().start(el, CONFIG, e);
		return { el, e, controls, visibleHandlers };
	}

	const note = (el) => el.querySelector('[data-ffc-terminal-part="wake-note"]');

	it('keeps the screen on while paired with an event, and asks only once', async () => {
		const lock = wakeLock();
		const { controls } = start({}, { wakeLock: lock });
		await settle();

		expect(lock.requests).toBe(1);
		expect(lock.type).toBe('screen');
		await controls.keepAwake();
		expect(lock.requests).toBe(1);
	});

	it('asks again when the page shows after the browser dropped the lock', async () => {
		const lock = wakeLock();
		const { visibleHandlers } = start({}, { wakeLock: lock });
		await settle();
		lock.sentinels[0].release();

		visibleHandlers.forEach((fn) => fn());
		await settle();

		expect(lock.requests).toBe(2);
	});

	it('does not ask while the page is hidden', async () => {
		const lock = wakeLock();
		start({}, { wakeLock: lock, visible: () => false });
		await settle();

		expect(lock.requests).toBe(0);
	});

	it('lets the screen dim once no event is left', async () => {
		const lock = wakeLock();
		const { e, controls } = start({}, { wakeLock: lock });
		await settle();

		const state = JSON.parse(e.storage.getItem('ffcTerminal'));
		state.schedule = [];
		e.storage.setItem('ffcTerminal', JSON.stringify(state));
		await controls.keepAwake();

		expect(lock.sentinels[0].releases).toBe(1);
		expect(lock.requests).toBe(1);
	});

	it('lets the screen dim when the pairing ends on its own', async () => {
		const lock = wakeLock();
		const { e } = start({}, { wakeLock: lock });
		await settle();

		e.clock.now = (NOW + 86400 + 60) * 1000;
		e.timers.forEach((fn) => fn());
		await settle();

		expect(lock.sentinels[0].releases).toBe(1);
	});

	it('says in the operator menu when the browser cannot or will not keep the screen on', async () => {
		const unsupported = start({});
		await settle();
		expect(note(unsupported.el).hidden).toBe(false);
		expect(note(unsupported.el).textContent).toBe('cannot keep the screen on');

		const refused = start({}, { wakeLock: wakeLock(true) });
		await settle();
		expect(note(refused.el).textContent).toBe('screen on refused');
	});

	it('asks nothing of a terminal that is not paired', async () => {
		const lock = wakeLock();
		const el = markup();
		T().start(el, CONFIG, env({ wakeLock: lock }));
		await settle();

		expect(lock.requests).toBe(0);
	});
});

describe('device check', () => {
	const paired = { ffcTerminal: JSON.stringify({ token: TOKEN, bookings: [11], expires_at: NOW + 86400, expires_label: 'later', schedule: [] }) };
	const rows = (el) => Array.from(el.querySelectorAll('[data-ffc-terminal-part="device-check"] dd')).map((dd) => dd.textContent);

	it('reports what this browser offers, from the capabilities themselves', async () => {
		const el = markup();
		const detector = function () {};
		detector.getSupportedFormats = () => Promise.resolve(['qr_code']);
		const controls = T().start(el, { ...CONFIG, camera: true }, env({
			storage: memoryStorage(paired),
			secure: true,
			BarcodeDetector: detector,
			mediaDevices: { getUserMedia: () => Promise.reject(new Error('not now')) },
			wakeLock: { request: () => Promise.resolve({ release: () => Promise.resolve() }) },
			userAgent: 'TabletBrowser/1.0',
		}));
		await controls.deviceCheck();

		expect(rows(el)).toEqual(['yes', 'camera on', 'screen available', 'TabletBrowser/1.0']);
	});

	it('adds the visible screen in CSS pixels and its density (#2824)', async () => {
		const el = markup();
		const controls = T().start(el, CONFIG, env({ storage: memoryStorage(paired), view: { innerWidth: 1280, innerHeight: 752, devicePixelRatio: 1.333 } }));
		await controls.deviceCheck();

		expect(rows(el)[4]).toBe('1280 × 752 px · 1.33×');
		expect(el.querySelectorAll('[data-ffc-terminal-part="device-check"] dt')[4].textContent).toBe('Visible');
	});

	it('says why the camera and the screen lock are missing', async () => {
		const el = markup();
		const controls = T().start(el, CONFIG, env({ storage: memoryStorage(paired), secure: false, userAgent: 'Old/1.0' }));
		await controls.deviceCheck();

		expect(rows(el)).toEqual(['no', 'camera needs https', 'screen unsupported', 'Old/1.0']);

		const plain = markup();
		const second = T().start(plain, CONFIG, env({ storage: memoryStorage(paired), secure: true }));
		await second.deviceCheck();
		expect(rows(plain)[1]).toBe('camera unsupported');
	});

	it('names the switch when the browser can read but the site does not allow it', async () => {
		const el = markup();
		const detector = function () {};
		const controls = T().start(el, CONFIG, env({ storage: memoryStorage(paired), secure: true, BarcodeDetector: detector, mediaDevices: { getUserMedia: () => null } }));
		await controls.deviceCheck();

		expect(rows(el)[1]).toBe('camera off');
	});
});


describe('offline shell (#2783)', () => {
	beforeEach(markup);

	it('says pairing needs a connection on an unpaired terminal that is offline, and clears it once online', () => {
		const el = document.querySelector('[data-ffc-terminal]');
		const handlers = {};
		let up = false;
		const controls = T().start(el, CONFIG, env({
			online: () => up,
			onOnline: (fn) => { handlers.online = fn; },
			onOffline: (fn) => { handlers.offline = fn; },
		}));
		const note = el.querySelector('[data-ffc-terminal-part="offline"]');
		expect(controls.screen()).toBe('idle');
		expect(note.hidden).toBe(false);
		expect(note.textContent).toBe('pairing needs a connection');

		up = true;
		handlers.online();
		expect(note.hidden).toBe(true);
		expect(note.textContent).toBe('');

		up = false;
		handlers.offline();
		expect(note.hidden).toBe(false);
	});

	it('registers the worker and hands it the page\'s assets', async () => {
		const posted = [];
		const registered = [];
		const container = {
			register: (url) => { registered.push(url); return Promise.resolve({}); },
			ready: Promise.resolve({ active: { postMessage: (m) => posted.push(m) } }),
		};
		const done = await T().registerShell({ worker: 'https://example.com/terminal/sw.js' }, {
			serviceWorker: container,
			secure: true,
			assets: () => ['https://example.com/terminal/', 'https://example.com/a.js'],
		});
		expect(done).toBe(true);
		expect(registered).toEqual(['https://example.com/terminal/sw.js']);
		expect(posted).toEqual([{ type: 'ffc-terminal-keep', urls: ['https://example.com/terminal/', 'https://example.com/a.js'] }]);
	});

	it('registers nothing without a worker address, a worker API or a secure page, and survives a refusal', async () => {
		const container = { register: () => Promise.reject(new Error('denied')), ready: Promise.resolve(null) };
		const assets = () => [];
		expect(await T().registerShell({ worker: '' }, { serviceWorker: container, secure: true, assets })).toBe(false);
		expect(await T().registerShell({ worker: '/terminal/sw.js' }, { secure: true, assets })).toBe(false);
		expect(await T().registerShell({ worker: '/terminal/sw.js' }, { serviceWorker: container, secure: false, assets })).toBe(false);
		expect(await T().registerShell({ worker: '/terminal/sw.js' }, { serviceWorker: container, secure: true, assets })).toBe(false);
		const inactive = { register: () => Promise.resolve({}), ready: Promise.resolve({ active: null }) };
		expect(await T().registerShell({ worker: '/terminal/sw.js' }, { serviceWorker: inactive, secure: true, assets })).toBe(false);
	});
});
