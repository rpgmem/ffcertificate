// The attendance screen and the projected QR (#1947): both poll one action;
// the screen marks who arrived, the projection swaps the code at the
// boundary the server names and stops when check-in closes.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function respond(data) {
	return Promise.resolve({ json: () => Promise.resolve({ success: true, data: data }) });
}

async function flush() {
	for (let i = 0; i < 5; i++) {
		await Promise.resolve();
	}
}

describe('ffc-audience-attendance.js', () => {
	beforeEach(() => {
		vi.useFakeTimers();
	});

	afterEach(() => {
		vi.useRealTimers();
		delete window.fetch;
	});

	it('marks a participant who checked in since the page loaded', async () => {
		document.body.innerHTML =
			'<div class="ffc-attendance-participants"><strong class="ffc-attendance-present">0</strong>' +
			'<table><tr data-user-id="7"><td class="ffc-attendance-cell">—</td><td><button class="ffc-attendance-mark">Mark</button></td></tr></table></div>';
		window.ffcAttendance = { ajaxUrl: '/ajax', action: 'a', nonce: 'n', bookingId: 5, date: '2027-03-01', projection: false, strings: { byHand: 'by hand', qr: 'QR' } };
		window.fetch = vi.fn(() => respond({ present: 1, total: 1, open: true, rows: [{ user_id: 7, time: '09:30', method: 'qr' }] }));
		loadScript('assets/js/ffc-audience-attendance.js');

		vi.advanceTimersByTime(10000);
		await flush();

		const body = window.fetch.mock.calls[0][1].body;
		expect(body.get('booking_id')).toBe('5');
		expect(body.has('qr')).toBe(false);
		expect(document.querySelector('.ffc-attendance-present').textContent).toBe('1');
		expect(document.querySelector('.ffc-attendance-cell').textContent).toBe('09:30 QR');
		expect(document.querySelector('.ffc-attendance-mark').disabled).toBe(true);
	});

	it('swaps the projected code at the boundary and stops when check-in closes', async () => {
		document.body.innerHTML =
			'<main class="ffc-attendance-stage"><div class="ffc-attendance-qr"><svg id="first"></svg></div>' +
			'<strong class="ffc-attendance-present">0</strong><progress class="ffc-attendance-timer" max="60" value="3"></progress></main>';
		window.ffcAttendance = { ajaxUrl: '/ajax', action: 'a', nonce: 'n', bookingId: 5, date: '2027-03-01', projection: true, strings: { closed: 'Closed.' } };
		window.fetch = vi
			.fn()
			.mockImplementationOnce(() => respond({ present: 2, total: 9, open: true, qr: '<svg id="second"></svg>', seconds_remaining: 60, rotation: 60 }))
			.mockImplementation(() => respond({ present: 2, total: 9, open: false }));
		loadScript('assets/js/ffc-audience-attendance.js');

		vi.advanceTimersByTime(3000);
		await flush();

		expect(window.fetch.mock.calls[0][1].body.get('qr')).toBe('1');
		expect(document.getElementById('second')).not.toBeNull();
		expect(document.querySelector('.ffc-attendance-present').textContent).toBe('2');
		expect(document.querySelector('.ffc-attendance-timer').value).toBe(60);

		vi.advanceTimersByTime(60000);
		await flush();

		expect(document.querySelector('.ffc-attendance-qr').textContent).toBe('Closed.');
		expect(document.querySelector('.ffc-attendance-timer').hidden).toBe(true);
	});
});
