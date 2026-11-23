/**
 * Calendar screen of the installable app (#2781): the day cards, today,
 * the chips, the inline actions, the folded past and the Book view.
 */
import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const STRINGS = {
	loading: 'loading', failed: 'failed', empty: 'empty', past: 'Held (%d)', today: 'Today', confirmed: 'Confirmed',
	enrolled: 'Enrolled', pending: 'Pending', presence: 'Presence QR', form: 'Form · until %s', receipt: 'Receipt',
	newOne: 'New booking', noBook: 'no book',
};
const DATES = {
	months: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
	weekdaysShort: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
};

const APPOINTMENTS = [
	{ id: 1, calendar_title: 'School supervision', session_name: '', appointment_date_raw: '2026-11-21', start_time: '16:30', end_time: '17:00', status: 'confirmed', is_past: false, receipt_url: 'https://example.org/r/1' },
	{ id: 2, calendar_title: 'Cancelled one', appointment_date_raw: '2026-11-22', status: 'cancelled', is_past: false },
];
const BOOKINGS = [
	{ id: 7, description: 'Teaching meeting', environment_name: 'Auditorium', booking_date_raw: '2026-11-25', last_date_raw: '2026-11-25', time_label: '14:00 – 17:00', status: 'active', is_past: false, attendance: null, post_event: null },
	{ id: 6, description: 'Coordinators', environment_name: 'Hall', booking_date_raw: '2026-11-18', last_date_raw: '2026-11-18', time_label: '09:00', status: 'active', is_past: true, attendance: { met: true, detail: 'Present · terminal 13:41' }, post_event: { url: 'https://example.org/f', closes_label: '28/11' } },
];

beforeAll(() => {
	loadScript('assets/js/ffc-app-agenda.js');
});

function markup() {
	document.body.innerHTML = `
		<div data-ffc-app-agenda>
			<button data-ffc-app-view="mine" aria-selected="true"></button>
			<button data-ffc-app-view="book" aria-selected="false"></button>
			<p data-ffc-app-part="status"></p>
			<div data-ffc-app-panel="mine">
				<h2 data-ffc-app-part="month"></h2>
				<details data-ffc-app-part="past" hidden><summary data-ffc-app-part="past-label"></summary><ol data-ffc-app-part="past-list"></ol></details>
				<ol data-ffc-app-part="list"></ol>
				<button data-ffc-app-part="new"></button>
			</div>
			<div data-ffc-app-panel="book" hidden><ul data-ffc-app-part="book"></ul></div>
		</div>`;
	return document.querySelector('[data-ffc-app-agenda]');
}

const reply = (status, body) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
const settle = async () => { for (let i = 0; i < 10; i++) { await new Promise((r) => setTimeout(r, 0)); } };
const routes = { appointments: 'https://example.org/a', audience: 'https://example.org/b' };
const fetchBoth = () => vi.fn((url) => reply(200, url === routes.appointments ? { appointments: APPOINTMENTS } : { bookings: BOOKINGS }));

describe('app agenda screen', () => {
	beforeEach(() => {
		window.ffcAppAgenda = { routes, nonce: 'n', today: '2026-11-21', dates: DATES, presence: true, book: [{ title: 'Supervision', url: 'https://example.org/book' }], strings: STRINGS };
	});

	it('draws upcoming day cards in order, today highlighted, cancelled left out', async () => {
		const el = markup();
		const fetch = fetchBoth();
		window.FFCAppAgenda.boot({ fetch, presenceUrl: 'https://example.org/app/s/presence/' });
		await settle();
		expect(fetch).toHaveBeenCalledTimes(2);
		expect(fetch.mock.calls[0][1].headers['X-WP-Nonce']).toBe('n');
		const cards = el.querySelectorAll('[data-ffc-app-part="list"] > li');
		expect(cards).toHaveLength(2);
		expect(cards[0].classList.contains('ffc-app-agenda-today')).toBe(true);
		expect(cards[0].querySelector('.ffc-app-agenda-weekday').textContent).toBe('Sat');
		expect(cards[0].querySelector('.ffc-app-agenda-number').textContent).toBe('21');
		expect(Array.from(cards[0].querySelectorAll('.ffc-app-agenda-chip')).map((c) => c.textContent)).toEqual(['Today', 'Confirmed']);
		expect(cards[0].querySelector('.ffc-icon-file').getAttribute('href')).toBe('https://example.org/r/1');
		expect(cards[1].querySelector('h3').textContent).toBe('Teaching meeting');
		expect(cards[1].querySelector('.ffc-app-agenda-chip').textContent).toBe('Enrolled');
		expect(cards[1].querySelector('.ffc-icon-qr').getAttribute('href')).toBe('https://example.org/app/s/presence/');
		expect(el.querySelector('[data-ffc-app-part="month"]').textContent).toBe('November 2026');
	});

	it('folds the past with its count, attendance chip and open form', async () => {
		const el = markup();
		window.FFCAppAgenda.boot({ fetch: fetchBoth(), presenceUrl: 'x' });
		await settle();
		const past = el.querySelector('[data-ffc-app-part="past"]');
		expect(past.hidden).toBe(false);
		expect(el.querySelector('[data-ffc-app-part="past-label"]').textContent).toBe('Held (1)');
		const card = el.querySelector('[data-ffc-app-part="past-list"] > li');
		expect(card.querySelector('.ffc-app-agenda-chip-success').textContent).toBe('Present · terminal 13:41');
		expect(card.querySelector('.ffc-icon-edit').textContent).toBe('Form · until 28/11');
		expect(card.querySelector('.ffc-icon-qr')).toBeNull();
	});

	it('switches to Book from the tab or from New booking, and lists the calendars', async () => {
		const el = markup();
		window.FFCAppAgenda.boot({ fetch: fetchBoth(), presenceUrl: 'x' });
		await settle();
		el.querySelector('[data-ffc-app-part="new"]').click();
		expect(el.querySelector('[data-ffc-app-panel="book"]').hidden).toBe(false);
		expect(el.querySelector('[data-ffc-app-panel="mine"]').hidden).toBe(true);
		expect(el.querySelector('[data-ffc-app-view="book"]').getAttribute('aria-selected')).toBe('true');
		expect(el.querySelector('.ffc-app-agenda-book-link').getAttribute('href')).toBe('https://example.org/book');
		el.querySelector('[data-ffc-app-view="mine"]').click();
		expect(el.querySelector('[data-ffc-app-panel="mine"]').hidden).toBe(false);
	});

	it('reads only the routes it is given, says when nothing is ahead, and when the site fails', async () => {
		window.ffcAppAgenda.routes = { audience: routes.audience };
		window.ffcAppAgenda.presence = false;
		window.ffcAppAgenda.book = [];
		let el = markup();
		const fetch = vi.fn(() => reply(200, { bookings: [] }));
		window.FFCAppAgenda.boot({ fetch, presenceUrl: 'x' });
		await settle();
		expect(fetch).toHaveBeenCalledTimes(1);
		expect(el.querySelector('[data-ffc-app-part="status"]').textContent).toBe('empty');
		expect(el.querySelector('[data-ffc-app-part="book"]').textContent).toBe('no book');

		el = markup();
		window.FFCAppAgenda.boot({ fetch: () => reply(500, null), presenceUrl: 'x' });
		await settle();
		expect(el.querySelector('[data-ffc-app-part="status"]').textContent).toBe('failed');

		delete window.ffcAppAgenda;
		expect(window.FFCAppAgenda.boot()).toEqual([]);
	});

	it('reads a wall-clock date without a timezone shift', () => {
		const d = window.FFCAppAgenda.wallDate('2026-01-01');
		expect([d.getFullYear(), d.getMonth(), d.getDate()]).toEqual([2026, 0, 1]);
		expect(window.FFCAppAgenda.wallDate('')).toBeNull();
	});
});
