// The cancel confirmation of a booking (FFC.confirmRangeCancel): a range is
// cancelled whole, so the dialog names every day it covers; a single day names
// its one date. The confirm is the danger button and waits out the countdown
// shared with the override warning, so a person cannot confirm unread.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const strings = {
	title: 'Cancel this booking?',
	intro: 'Read before you confirm:',
	agree: 'Cancel booking',
	agreeIn: 'Cancel booking (%d)',
	cancel: 'Keep booking',
	countdownStart: 'You can confirm in %d seconds.',
	countdownEnd: 'You can confirm now.',
	single: 'This cancels the booking on %s.',
	range: 'This booking covers %1$d days (%2$s). Cancelling it cancels every one of them.',
	seconds: 5,
};

beforeAll(() => {
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', strings: {} };
	loadScript('assets/js/ffc-core.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
	vi.useFakeTimers();
});

afterEach(() => {
	vi.useRealTimers();
	vi.restoreAllMocks();
});

function dialog() {
	return document.querySelector('.ffc-override-dialog');
}

describe('FFC.confirmRangeCancel — what the dialog says', () => {
	it('names every day of a range with the count and the dates', () => {
		window.FFC.confirmRangeCancel({ count: 3, label: '01/04 – 03/04', strings });
		const items = Array.from(dialog().querySelectorAll('.ffc-override-reasons li')).map((li) => li.textContent);
		expect(items).toEqual(['This booking covers 3 days (01/04 – 03/04). Cancelling it cancels every one of them.']);
	});

	it('names only the date of a single-day booking', () => {
		window.FFC.confirmRangeCancel({ count: 1, label: '01/04', strings });
		const items = Array.from(dialog().querySelectorAll('.ffc-override-reasons li')).map((li) => li.textContent);
		expect(items).toEqual(['This cancels the booking on 01/04.']);
	});

	it('reads a count given as a string from a data attribute', () => {
		window.FFC.confirmRangeCancel({ count: '2', label: '01/04 – 02/04', strings });
		expect(dialog().querySelector('.ffc-override-reasons li').textContent).toContain('covers 2 days');
	});
});

describe('FFC.confirmRangeCancel — the countdown', () => {
	it('keeps the danger confirm disabled until the countdown ends', () => {
		window.FFC.confirmRangeCancel({ count: 2, label: 'x', strings });
		const agree = dialog().querySelector('[data-ffc-override="agree"]');
		expect(agree.disabled).toBe(true);
		vi.advanceTimersByTime(4000);
		expect(agree.disabled).toBe(true);
		vi.advanceTimersByTime(1000);
		expect(agree.disabled).toBe(false);
	});

	it('uses the danger category for the confirm, not the override primary', () => {
		window.FFC.confirmRangeCancel({ count: 2, label: 'x', strings });
		const agree = dialog().querySelector('[data-ffc-override="agree"]');
		expect(agree.className).toContain('ffc-btn-danger');
		expect(agree.className).not.toContain('ffc-btn-primary');
	});

	it('resolves true only when the confirm is pressed after the countdown', async () => {
		const promise = window.FFC.confirmRangeCancel({ count: 2, label: 'x', strings });
		vi.advanceTimersByTime(5000);
		dialog().querySelector('[data-ffc-override="agree"]').click();
		await expect(promise).resolves.toBe(true);
	});

	it('resolves false when the person keeps the booking, even before the countdown ends', async () => {
		const promise = window.FFC.confirmRangeCancel({ count: 2, label: 'x', strings });
		dialog().querySelector('[data-ffc-override="cancel"]').click();
		await expect(promise).resolves.toBe(false);
		expect(dialog()).toBeNull();
	});
});
