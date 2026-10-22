// The range fields of the booking modal (#1946, #2008): "Several days" shows
// the last day and the range options and hides the all-day switch; "One day"
// restores a single day. Drives the functions registered on window.FFCAudience.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';
import { bookingModalHtml } from './booking-modal-fixture.js';

let api;

beforeAll(() => {
	window.ffcAudience = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		restUrl: '/wp-json/ffc/v1/audience/',
		nonce: 'n',
		locale: 'en-US',
		strings: {},
	};
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', strings: {} };
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-audience.js');
	loadScript('assets/js/ffc-audience-calendar.js');
	loadScript('assets/js/ffc-audience-booking-form.js');
	api = window.FFCAudience;
});

function rangeFixture() {
	document.body.innerHTML = bookingModalHtml();
	document.getElementById('booking-date').value = '2030-01-15';
}

describe('booking modal range fields', () => {
	beforeEach(() => {
		rangeFixture();
	});

	it('shows the range fields and hides the all-day switch for several days', () => {
		api.setAllDay(true);

		api.setRangeOptions(true);

		expect(document.getElementById('booking-range-options').hidden).toBe(false);
		expect(document.getElementById('booking-end-date-group').hidden).toBe(false);
		expect(document.getElementById('booking-end-date').required).toBe(true);
		expect(document.getElementById('booking-all-day-group').hidden).toBe(true);
		expect(document.getElementById('booking-all-day').checked).toBe(false);
		// Turning all day off gives the hours back.
		expect(document.getElementById('booking-time-row').hidden).toBe(false);
	});

	it('shows the first and last date in the heading once the last day is set', () => {
		document.getElementById('booking-end-date').value = '2030-01-17';

		api.setRangeOptions(true);

		const heading = document.querySelector('.ffc-booking-date-display').textContent;
		expect(heading).toContain(' – ');
		expect(heading).toContain('2030');
	});

	it('restores a single day, and ignores a last day left in the field', () => {
		document.getElementById('booking-end-date').value = '2030-01-17';
		api.setRangeOptions(true);

		api.setRangeOptions(false);

		expect(document.getElementById('booking-range-options').hidden).toBe(true);
		expect(document.getElementById('booking-end-date-group').hidden).toBe(true);
		expect(document.getElementById('booking-end-date').required).toBe(false);
		expect(document.getElementById('booking-all-day-group').hidden).toBe(false);
		expect(document.querySelector('.ffc-booking-date-display').textContent).not.toContain(' – ');
	});

	it('says under each time mode what it books, once the times and days are in', () => {
		const daily = document.querySelector('[data-ffc-time-mode-hint="daily"]');
		const span = document.querySelector('[data-ffc-time-mode-hint="span"]');
		api.updateTimeModeHints();
		expect(daily.textContent).toBe('');
		expect(span.textContent).toBe('');

		document.getElementById('booking-start-time').value = '08:00';
		document.getElementById('booking-end-time').value = '12:00';
		document.getElementById('booking-end-date').value = '2030-01-17';
		api.setRangeOptions(true);

		expect(daily.textContent).toBe('08:00–12:00 on each day');
		expect(span.textContent).toMatch(/^From 08:00 on .+ to 12:00 on .+$/);
		expect(span.textContent).toContain('15');
		expect(span.textContent).toContain('17');
	});
});
