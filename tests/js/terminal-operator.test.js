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
		follows: 'follows %s',
	},
};

function screen(mode) {
	const parts = {
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
		qr: '<div data-ffc-op-part="qr"></div><progress data-ffc-op-part="progress"></progress><p data-ffc-op-part="countdown"></p>',
	};
	document.body.innerHTML = `<div data-ffc-terminal-operator="${mode}">
		<p data-ffc-op-part="status"></p><p data-ffc-op-part="error" hidden></p>${parts[mode]}</div>`;
	return document.querySelector('[data-ffc-terminal-operator]');
}

// GETs carry a unique `_ffc` stamp so no cache between the app and the site
// can answer them; the routes match the address without it (#2832).
function unstamp(url) {
	return url.replace(/[?&]_ffc=\d+$/, '');
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
			calls.raw = (calls.raw || []).concat([url]);
			const path = unstamp(url).replace(CONFIG.rest, '');
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

	it('says the code expired when the clock passes it before a new one arrives', async () => {
		const el = screen('qr');
		const e = env({ 'POST operator-code': [200, { code: 'FFO1CODE', expires_at: NOW + 120 }] });
		await O().start(el, CONFIG, e).ready;
		expect(part(el, 'progress').getAttribute('max')).toBe('120');
		expect(part(el, 'progress').getAttribute('value')).toBe('120');
		e.clock.now = (NOW + 121) * 1000;
		e.intervals[e.intervals.length - 1]();
		expect(part(el, 'countdown').textContent).toBe('expired');
		expect(part(el, 'progress').getAttribute('value')).toBe('0');
	});

	it('clears the code when the site cannot sign one', async () => {
		const el = screen('qr');
		await O().start(el, CONFIG, env({ 'POST operator-code': [500, { message: 'could not sign' }] })).ready;
		expect(part(el, 'qr').innerHTML).toBe('');
		expect(part(el, 'error').textContent).toBe('could not sign');
	});
});

describe('failures and the page boot', () => {
	it('says why the terminal list could not load', async () => {
		const el = screen('terminals');
		await O().start(el, CONFIG, env({ 'GET terminals': [403, {}] })).ready;
		expect(part(el, 'error').textContent).toBe('failed');
	});

	it('treats an unreachable site and an unreadable answer alike', async () => {
		let el = screen('terminals');
		const offline = env({});
		await O().start(el, CONFIG, offline).ready;
		expect(part(el, 'error').textContent).toBe('failed');

		el = screen('qr');
		const garbled = env({});
		garbled.fetch = () => Promise.resolve({ ok: false, status: 502, json: () => Promise.reject(new Error('not json')) });
		await O().start(el, CONFIG, garbled).ready;
		expect(part(el, 'error').textContent).toBe('failed');
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

	it('keeps a certificate form\'s key whole when it saves the events (#2890)', async () => {
		const el = screen('terminals');
		const sent = [];
		const e = env({
			'GET terminals': [200, { terminals: [{ id: 4, label: 'A', bookings: [11], expires_label: 'x' }], limits: {} }],
			'GET bookings': [200, { bookings: [{ id: 11, description: 'Talk' }, { id: 'certificate_form:12', description: 'Seminar' }] }],
			'PUT terminals/4': (options) => { sent.push(JSON.parse(options.body)); return [200, { id: 4, bookings: [11, 'certificate_form:12'] }]; },
		});
		await O().start(el, CONFIG, e).ready;
		const item = part(el, 'list').querySelector('li');
		const get = (n) => item.querySelector('[data-ffc-op-item="' + n + '"]');
		get('edit').click();
		await settle();
		get('choices').querySelectorAll('input')[1].checked = true;
		get('save').click();
		await settle();
		expect(sent).toEqual([{ bookings: [11, 'certificate_form:12'] }]);
	});

	it('names the place a terminal follows and offers no event change for it (#2900)', async () => {
		const el = screen('terminals');
		await O().start(el, CONFIG, env({
			'GET terminals': [200, { terminals: [{ id: 5, label: 'Gate', bookings: [11], location: 'EMEF Paulo Freire', expires_label: 'x' }], limits: {} }],
		})).ready;
		const item = part(el, 'list').querySelector('li');
		expect(item.querySelector('[data-ffc-op-item="events"]').textContent).toBe('follows EMEF Paulo Freire');
		expect(item.querySelector('[data-ffc-op-item="edit"]').hidden).toBe(true);
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
