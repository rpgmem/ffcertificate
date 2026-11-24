/**
 * The operator's terminal screens in the app (#2744): generating a pairing
 * code, ending a terminal behind an inline confirmation, and the operator's
 * unlock code renewed before it expires; filling the events from one place
 * and changing the events of a paired terminal (#2746).
 */
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

const NOW = 1800000000;

beforeAll(() => {
	loadScript('assets/js/ffc-terminal-operator.js');
});

const O = () => window.FFCTerminalOperator;
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
const settle = async () => { for (let i = 0; i < 10; i++) { await flush(); } };

const CONFIG = {
	rest: 'https://example.com/wp-json/ffc/v1/terminal/',
	nonce: 'nonce',
	maxBookings: 2,
	autoEnd: 2,
	strings: {
		loading: 'loading',
		failed: 'failed',
		pickEvent: 'pick',
		tooMany: 'at most %d',
		generating: 'generating',
		full: 'full',
		held: 'held %d',
		codeExpires: 'expires %s',
		pairSummary: '%1$d events, %2$d h',
		expiredCode: 'expired',
		qrLabel: 'qr',
		unnamed: 'Terminal %d',
		events: 'events %d',
		seen: 'seen %s',
		neverSeen: 'never',
		until: 'until %s',
		ended: 'ended',
		changed: 'changed',
		summary: '%1$d of %2$d, %3$d per event',
		summaryAll: 'all %1$d of %2$d, %3$d per event',
		operatorExpires: 'new in %s',
	},
};

function screen(mode) {
	const parts = {
		pair: `
			<form data-ffc-op-part="form">
				<div data-ffc-op-part="queue-mode" hidden>
					<label><input type="radio" name="ffc_terminal_queue" value="pick" checked>pick</label>
					<label><input type="radio" name="ffc_terminal_queue" value="place">place</label>
					<p data-ffc-op-part="by-place" hidden><select data-ffc-op-part="place"></select></p>
				</div>
				<div data-ffc-op-part="events"></div>
				<p data-ffc-op-part="events-empty" hidden></p>
				<label><input type="radio" name="ffc_terminal_auto_end" value="1">1</label>
				<label><input type="radio" name="ffc_terminal_auto_end" value="2" checked>2</label>
				<label><input type="radio" name="ffc_terminal_auto_end" value="4">4</label>
				<input data-ffc-op-part="label">
				<button type="submit" data-ffc-op-part="generate">Generate</button>
			</form>
			<section data-ffc-op-part="result" hidden>
				<div data-ffc-op-part="qr"></div>
				<p data-ffc-op-part="code"></p>
				<p data-ffc-op-part="countdown"></p>
				<p data-ffc-op-part="summary"></p>
				<button type="button" data-ffc-op-part="again">Again</button>
			</section>`,
		terminals: `
			<p data-ffc-op-part="summary"></p>
			<ul data-ffc-op-part="list"></ul>
			<p data-ffc-op-part="list-empty" hidden></p>
			<template data-ffc-op-part="row"><li>
				<p data-ffc-op-item="label"></p><p data-ffc-op-item="events"></p><p data-ffc-op-item="seen"></p><p data-ffc-op-item="until"></p>
				<div data-ffc-op-item="ask"><button type="button" data-ffc-op-item="edit">Change</button><button type="button" data-ffc-op-item="end">End</button></div>
				<fieldset data-ffc-op-item="editor" hidden>
					<div data-ffc-op-item="choices"></div>
					<button type="button" data-ffc-op-item="cancel">Cancel</button>
					<button type="button" data-ffc-op-item="save">Save</button>
				</fieldset>
				<div data-ffc-op-item="confirm" hidden>
					<button type="button" data-ffc-op-item="keep">Keep</button>
					<button type="button" data-ffc-op-item="confirm-end">End</button>
				</div>
			</li></template>`,
		qr: '<div data-ffc-op-part="qr"></div><p data-ffc-op-part="countdown"></p>',
	};
	document.body.innerHTML = `<div data-ffc-terminal-operator="${mode}">
		<p data-ffc-op-part="status"></p><p data-ffc-op-part="error" hidden></p>${parts[mode]}</div>`;
	return document.querySelector('[data-ffc-terminal-operator]');
}

function env(routes) {
	const clock = { now: NOW * 1000 };
	const calls = [];
	const timeouts = [];
	return {
		clock,
		calls,
		timeouts,
		now: () => clock.now,
		fetch: (url, options) => {
			const path = url.replace(CONFIG.rest, '');
			calls.push([options.method, path, options]);
			const answer = routes[options.method + ' ' + path];
			if (!answer) {
				return Promise.reject(new Error('no route ' + path));
			}
			const [status, body] = typeof answer === 'function' ? answer(options) : answer;
			return Promise.resolve({ ok: status < 300, status, json: () => Promise.resolve(body) });
		},
		intervals: [],
		setInterval(fn) { this.intervals.push(fn); return this.intervals.length; },
		clearInterval: () => {},
		setTimeout: (fn, ms) => { timeouts.push([fn, ms]); return timeouts.length; },
		clearTimeout: () => {},
		qrSvg: (text) => ({ svg: '<svg data-text="' + text + '"></svg>', version: 2 }),
	};
}

const part = (el, name) => el.querySelector('[data-ffc-op-part="' + name + '"]');

describe('helpers', () => {
	it('fills placeholders in order and by position', () => {
		expect(O().fill('%s of %d', ['a', 2])).toBe('a of 2');
		expect(O().fill('%2$d then %1$s', ['a', 2])).toBe('2 then a');
		expect(O().fill('%s', [])).toBe('');
		expect(O().clock(125)).toBe('2:05');
	});
});

describe('Pair terminal', () => {
	const BOOKINGS = [200, { bookings: [
		{ id: 11, description: 'Workshop', place: 'Room 1', when: 'Jan 15, 09:00–17:00', terminals: 1, full: false },
		{ id: 12, description: 'Full one', place: '', when: 'Jan 16', terminals: 3, full: true },
		{ id: 13, description: 'Third', place: '', when: 'Jan 17', terminals: 0, full: false },
	], max: 10 }];
	let el;
	beforeEach(() => { el = screen('pair'); });

	it('lists the events and leaves a full one unchecked and disabled', async () => {
		const e = env({ 'GET bookings': BOOKINGS });
		await O().start(el, CONFIG, e).ready;

		const boxes = part(el, 'events').querySelectorAll('input');
		expect(boxes).toHaveLength(3);
		expect(boxes[1].disabled).toBe(true);
		expect(part(el, 'events').textContent).toContain('Jan 15, 09:00–17:00 · Room 1 · held 1');
		expect(part(el, 'events').textContent).toContain('full');
		expect(e.calls[0][2].headers['X-WP-Nonce']).toBe('nonce');
		expect(e.calls[0][2].credentials).toBe('same-origin');
	});

	it('asks for an event, and for no more than the limit', async () => {
		const e = env({ 'GET bookings': BOOKINGS });
		const controls = O().start(el, CONFIG, e);
		await controls.ready;
		await controls.generate();
		expect(part(el, 'error').textContent).toBe('pick');

		const boxes = part(el, 'events').querySelectorAll('input');
		boxes[0].checked = true;
		boxes[1].checked = true;
		boxes[2].checked = true;
		await controls.generate();
		expect(part(el, 'error').textContent).toBe('at most 2');
		expect(e.calls.filter((c) => c[1] === 'pairings')).toHaveLength(0);
	});

	it('generates a code and shows it as a QR with its countdown', async () => {
		const e = env({
			'GET bookings': BOOKINGS,
			'POST pairings': [201, { code: 'P-AB12-CD34-EF56', expires_at: NOW + 300, bookings: [11] }],
		});
		const controls = O().start(el, CONFIG, e);
		await controls.ready;
		part(el, 'events').querySelector('input').checked = true;
		part(el, 'label').value = 'Entrance A';
		await controls.generate();

		expect(JSON.parse(e.calls[1][2].body)).toEqual({ bookings: [11], label: 'Entrance A', auto_end: 2 });
		expect(part(el, 'form').hidden).toBe(true);
		expect(part(el, 'result').hidden).toBe(false);
		expect(part(el, 'code').textContent).toBe('P-AB12-CD34-EF56');
		expect(part(el, 'qr').querySelector('svg').getAttribute('data-text')).toBe('P-AB12-CD34-EF56');
		expect(part(el, 'qr').querySelector('svg').getAttribute('aria-label')).toBe('qr');
		expect(part(el, 'countdown').textContent).toBe('expires 5:00');

		await controls.again();
		expect(part(el, 'form').hidden).toBe(false);
		expect(part(el, 'code').textContent).toBe('');
	});

	it('shows the site\'s refusal', async () => {
		const e = env({ 'GET bookings': BOOKINGS, 'POST pairings': [409, { message: 'This event already has 3 terminals.' }] });
		const controls = O().start(el, CONFIG, e);
		await controls.ready;
		part(el, 'events').querySelector('input').checked = true;
		await controls.generate();
		expect(part(el, 'error').textContent).toBe('This event already has 3 terminals.');
		expect(part(el, 'result').hidden).toBe(true);
	});

	it('says when no event can take a terminal', async () => {
		await O().start(el, CONFIG, env({ 'GET bookings': [200, { bookings: [] }] })).ready;
		expect(part(el, 'events-empty').hidden).toBe(false);
	});
});

describe('My terminals', () => {
	const LIST = [200, {
		terminals: [
			{ id: 4, label: 'Entrance A', bookings: [11, 12], last_seen_label: '15/01 10:00', expires_label: '15/01 19:00' },
			{ id: 5, label: '', bookings: [13], last_seen_label: '', expires_label: '16/01 19:00' },
		],
		all: false,
		limits: { site: 10, booking: 3 },
	}];

	it('lists each terminal with its dates as the site formatted them', async () => {
		const el = screen('terminals');
		await O().start(el, CONFIG, env({ 'GET terminals': LIST })).ready;
		const items = part(el, 'list').querySelectorAll('li');
		expect(items).toHaveLength(2);
		expect(items[0].textContent).toContain('Entrance A');
		expect(items[0].textContent).toContain('events 2');
		expect(items[0].textContent).toContain('seen 15/01 10:00');
		expect(items[0].textContent).toContain('until 15/01 19:00');
		expect(items[1].textContent).toContain('Terminal 5');
		expect(items[1].textContent).toContain('never');
		expect(part(el, 'summary').textContent).toBe('2 of 10, 3 per event');
	});

	it('ends a terminal only after the inline confirmation', async () => {
		const el = screen('terminals');
		const e = env({ 'GET terminals': LIST, 'DELETE terminals/4': [200, { revoked: 4 }] });
		await O().start(el, CONFIG, e).ready;
		const item = part(el, 'list').querySelector('li');
		const get = (n) => item.querySelector('[data-ffc-op-item="' + n + '"]');

		get('end').click();
		expect(get('confirm').hidden).toBe(false);
		expect(document.activeElement).toBe(get('keep'));
		get('keep').click();
		expect(get('confirm').hidden).toBe(true);
		expect(e.calls.some((c) => c[0] === 'DELETE')).toBe(false);

		get('end').click();
		get('confirm-end').click();
		await settle();
		expect(e.calls.some((c) => c[0] === 'DELETE' && c[1] === 'terminals/4')).toBe(true);
		expect(part(el, 'list').querySelectorAll('li')).toHaveLength(1);
		expect(part(el, 'status').textContent).toBe('ended');
	});

	it('keeps the terminal and says why when the site refuses', async () => {
		const el = screen('terminals');
		const e = env({ 'GET terminals': LIST, 'DELETE terminals/4': [403, { message: 'Not yours.' }] });
		await O().start(el, CONFIG, e).ready;
		const item = part(el, 'list').querySelector('li');
		item.querySelector('[data-ffc-op-item="end"]').click();
		item.querySelector('[data-ffc-op-item="confirm-end"]').click();
		await settle();
		expect(part(el, 'list').querySelectorAll('li')).toHaveLength(2);
		expect(part(el, 'error').textContent).toBe('Not yours.');
	});

	it('says when there is none', async () => {
		const el = screen('terminals');
		await O().start(el, CONFIG, env({ 'GET terminals': [200, { terminals: [], all: true, limits: { site: 10, booking: 3 } }] })).ready;
		expect(part(el, 'list-empty').hidden).toBe(false);
		expect(part(el, 'summary').textContent).toBe('all 0 of 10, 3 per event');
	});
});

describe('Operator QR', () => {
	it('draws the code and asks for a new one before it expires', async () => {
		const el = screen('qr');
		let n = 0;
		const e = env({ 'POST operator-code': () => [200, { code: 'FFO1CODE' + (++n), expires_at: NOW + 120 }] });
		await O().start(el, CONFIG, e).ready;
		expect(part(el, 'qr').querySelector('svg').getAttribute('data-text')).toBe('FFO1CODE1');
		expect(part(el, 'countdown').textContent).toBe('new in 2:00');
		expect(e.timeouts[0][1]).toBe(100 * 1000);

		e.timeouts[0][0]();
		await settle();
		expect(part(el, 'qr').querySelector('svg').getAttribute('data-text')).toBe('FFO1CODE2');
	});

	it('clears the code when the site cannot sign one', async () => {
		const el = screen('qr');
		await O().start(el, CONFIG, env({ 'POST operator-code': [500, { message: 'could not sign' }] })).ready;
		expect(part(el, 'qr').innerHTML).toBe('');
		expect(part(el, 'error').textContent).toBe('could not sign');
	});
});

describe('failures and the page boot', () => {
	it('says why the event list or the terminal list could not load', async () => {
		let el = screen('pair');
		await O().start(el, CONFIG, env({ 'GET bookings': [500, { message: 'database down' }] })).ready;
		expect(part(el, 'error').textContent).toBe('database down');

		el = screen('terminals');
		await O().start(el, CONFIG, env({ 'GET terminals': [403, {}] })).ready;
		expect(part(el, 'error').textContent).toBe('failed');
	});

	it('treats an unreachable site and an unreadable answer alike', async () => {
		let el = screen('pair');
		const offline = env({});
		await O().start(el, CONFIG, offline).ready;
		expect(part(el, 'error').textContent).toBe('failed');

		el = screen('qr');
		const garbled = env({});
		garbled.fetch = () => Promise.resolve({ ok: false, status: 502, json: () => Promise.reject(new Error('not json')) });
		await O().start(el, CONFIG, garbled).ready;
		expect(part(el, 'error').textContent).toBe('failed');
	});

	it('says when a pairing code expires', async () => {
		const el = screen('pair');
		const e = env({
			'GET bookings': [200, { bookings: [{ id: 11, description: 'Workshop', when: 'today', terminals: 0, full: false }] }],
			'POST pairings': [201, { code: 'P-AB12-CD34-EF56', expires_at: NOW + 300 }],
		});
		await O().start(el, CONFIG, e).ready;
		part(el, 'events').querySelector('input').checked = true;
		part(el, 'form').dispatchEvent(new window.Event('submit', { cancelable: true }));
		await settle();
		expect(part(el, 'countdown').textContent).toBe('expires 5:00');

		e.clock.now = (NOW + 301) * 1000;
		e.intervals[e.intervals.length - 1]();
		expect(part(el, 'countdown').textContent).toBe('expired');

		part(el, 'again').click();
		await settle();
		expect(part(el, 'result').hidden).toBe(true);
	});

	it('boots every screen on the page from the localized config', async () => {
		const el = screen('qr');
		const realFetch = window.fetch;
		window.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ code: 'FFO1BOOT', expires_at: Math.floor(Date.now() / 1000) + 120 }) });
		window.FFCPresence = { qrSvg: (text) => ({ svg: '<svg data-text="' + text + '"></svg>' }) };
		window.ffcTerminalOperator = CONFIG;
		try {
			loadScript('assets/js/ffc-terminal-operator.js');
			await settle();
			expect(part(el, 'qr').querySelector('svg').getAttribute('data-text')).toBe('FFO1BOOT');
		} finally {
			window.fetch = realFetch;
			delete window.ffcTerminalOperator;
			delete window.FFCPresence;
		}
	});
});

describe('Event queue', () => {
	const EVENTS = [200, { bookings: [
		{ id: 11, description: 'Talk', place: 'Hall', place_id: 7, when: 'Jan 15', terminals: 1, full: false },
		{ id: 12, description: 'Full', place: 'Hall', place_id: 7, when: 'Jan 15', terminals: 3, full: true },
		{ id: 13, description: 'Lab', place: 'Room 2', place_id: 8, when: 'Jan 16', terminals: 0, full: false },
		{ id: 14, description: 'Panel', place: 'Hall', place_id: 7, when: 'Jan 16', terminals: 0, full: false },
		{ id: 15, description: 'Closing', place: 'Hall', place_id: 7, when: 'Jan 17', terminals: 0, full: false },
		{ id: 16, description: 'Nowhere', place: '', place_id: 0, when: 'Jan 17', terminals: 0, full: false },
	], max: 10 }];
	const ticked = (list) => Array.from(list.querySelectorAll('input:checked')).map((b) => Number(b.value));

	const choose = (el, name, value) => {
		const radio = el.querySelector(`input[name="${name}"][value="${value}"]`);
		radio.checked = true;
		radio.dispatchEvent(new window.Event('change'));
	};

	it('fills the queue by place only once By place is chosen, soonest first, up to the limit', async () => {
		const el = screen('pair');
		const controls = O().start(el, CONFIG, env({ 'GET bookings': EVENTS }));
		await controls.ready;
		const select = part(el, 'place');
		expect(part(el, 'queue-mode').hidden).toBe(false);
		expect(part(el, 'by-place').hidden).toBe(true);
		expect(Array.from(select.options).map((o) => o.textContent)).toEqual(['Hall', 'Room 2']);
		expect(ticked(part(el, 'events'))).toEqual([]);

		choose(el, 'ffc_terminal_queue', 'place');
		expect(part(el, 'by-place').hidden).toBe(false);
		expect(ticked(part(el, 'events'))).toEqual([11, 14]);

		select.value = '8';
		select.dispatchEvent(new window.Event('change'));
		expect(ticked(part(el, 'events'))).toEqual([13]);

		choose(el, 'ffc_terminal_queue', 'pick');
		expect(part(el, 'by-place').hidden).toBe(true);
		expect(ticked(part(el, 'events'))).toEqual([13]);
		expect(controls.fillByPlace(part(el, 'events'), '')).toBe(0);
	});

	it('hides the queue mode when no event has a place', async () => {
		const el = screen('pair');
		await O().start(el, CONFIG, env({ 'GET bookings': [200, { bookings: [{ id: 16, description: 'Nowhere', place_id: 0 }] }] })).ready;
		expect(part(el, 'queue-mode').hidden).toBe(true);
	});

	it('sends the hours chosen to end by itself and sums the pairing up beside the code', async () => {
		const el = screen('pair');
		const sent = [];
		const controls = O().start(el, CONFIG, env({
			'GET bookings': EVENTS,
			'POST pairings': (options) => {
				sent.push(JSON.parse(options.body));
				return [201, { code: 'P-AB12-CD34-EF56', expires_at: NOW + 272 }];
			},
		}));
		await controls.ready;
		part(el, 'events').querySelector('input[value="11"]').checked = true;
		choose(el, 'ffc_terminal_auto_end', '4');
		await controls.generate();
		expect(sent[0]).toEqual({ bookings: [11], label: '', auto_end: 4 });
		expect(part(el, 'summary').textContent).toBe('1 events, 4 h');
		expect(part(el, 'countdown').textContent).toBe('expires 4:32');

		await controls.again();
		expect(part(el, 'summary').textContent).toBe('');
	});

	it('changes the events of a terminal, keeping one it holds even when full', async () => {
		const el = screen('terminals');
		const sent = [];
		const e = env({
			'GET terminals': [200, { terminals: [{ id: 4, label: 'A', bookings: [11, 12], expires_label: 'Jan 15' }], limits: {} }],
			'GET bookings': EVENTS,
			'PUT terminals/4': (options) => { sent.push(JSON.parse(options.body)); return [200, { id: 4, bookings: [12, 13], expires_label: 'Jan 16' }]; },
		});
		await O().start(el, CONFIG, e).ready;
		const item = part(el, 'list').querySelector('li');
		const get = (n) => item.querySelector('[data-ffc-op-item="' + n + '"]');

		get('edit').click();
		await settle();
		expect(get('editor').hidden).toBe(false);
		expect(get('ask').hidden).toBe(true);
		const boxes = get('choices').querySelectorAll('input');
		expect(ticked(get('choices'))).toEqual([11, 12]);
		expect(boxes[1].disabled).toBe(false);
		expect(get('choices').textContent).not.toContain('full');

		boxes[0].checked = false;
		boxes[2].checked = true;
		get('save').click();
		await settle();
		expect(sent).toEqual([{ bookings: [12, 13] }]);
		expect(get('events').textContent).toBe('events 2');
		expect(get('until').textContent).toBe('until Jan 16');
		expect(get('editor').hidden).toBe(true);
		expect(part(el, 'status').textContent).toBe('changed');
	});

	it('refuses an empty or oversized choice and shows the site\'s refusal', async () => {
		const el = screen('terminals');
		const e = env({
			'GET terminals': [200, { terminals: [{ id: 4, label: 'A', bookings: [11], expires_label: 'x' }], limits: {} }],
			'GET bookings': EVENTS,
			'PUT terminals/4': [409, { message: 'Crosses Talk.' }],
		});
		await O().start(el, CONFIG, e).ready;
		const item = part(el, 'list').querySelector('li');
		const get = (n) => item.querySelector('[data-ffc-op-item="' + n + '"]');
		get('edit').click();
		await settle();
		const boxes = get('choices').querySelectorAll('input');

		boxes[0].checked = false;
		get('save').click();
		await settle();
		expect(part(el, 'error').textContent).toBe('pick');

		[0, 2, 3].forEach((i) => { boxes[i].checked = true; });
		get('save').click();
		await settle();
		expect(part(el, 'error').textContent).toBe('at most 2');

		boxes[3].checked = false;
		get('save').click();
		await settle();
		expect(part(el, 'error').textContent).toBe('Crosses Talk.');
		expect(get('editor').hidden).toBe(false);

		get('cancel').click();
		expect(get('editor').hidden).toBe(true);
		expect(document.activeElement).toBe(get('edit'));
	});

	it('says why the events could not load for the editor', async () => {
		const el = screen('terminals');
		await O().start(el, CONFIG, env({
			'GET terminals': [200, { terminals: [{ id: 4, bookings: [] }], limits: {} }],
			'GET bookings': [500, { message: 'Down.' }],
		})).ready;
		part(el, 'list').querySelector('[data-ffc-op-item="edit"]').click();
		await settle();
		expect(part(el, 'error').textContent).toBe('Down.');
	});
});
