// The range fields of the booking modal (#1946): an end date shows the range
// options and the range heading, and hides the all-day switch; clearing it
// restores a single day. Drives the functions registered on window.FFCAudience.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

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
	document.body.innerHTML = `
		<div id="ffc-booking-modal">
			<div class="ffc-form-group" id="all-day-group">
				<input type="checkbox" id="booking-all-day" />
			</div>
			<input type="hidden" id="booking-date" value="2030-01-15" />
			<input type="date" id="booking-end-date" />
			<div id="booking-range-options" hidden>
				<select id="booking-time-mode"><option value="daily">daily</option><option value="span">span</option></select>
				<input type="checkbox" id="booking-skip-blocked" />
			</div>
			<span class="ffc-booking-date-display"></span>
		</div>`;
}

describe('booking modal range fields', () => {
	beforeEach(() => {
		rangeFixture();
	});

	it('shows the range options and hides the all-day switch for a range', () => {
		document.getElementById('booking-all-day').checked = true;

		api.setRangeOptions('2030-01-17');

		expect(document.getElementById('booking-range-options').hidden).toBe(false);
		expect(document.getElementById('all-day-group').style.display).toBe('none');
		expect(document.getElementById('booking-all-day').checked).toBe(false);
	});

	it('shows the first and last date in the heading for a range', () => {
		api.setRangeOptions('2030-01-17');

		const heading = document.querySelector('.ffc-booking-date-display').textContent;
		expect(heading).toContain(' – ');
		expect(heading).toContain('2030');
	});

	it('restores a single day when the end date is cleared', () => {
		api.setRangeOptions('2030-01-17');

		api.setRangeOptions('');

		expect(document.getElementById('booking-range-options').hidden).toBe(true);
		expect(document.getElementById('all-day-group').style.display).toBe('');
		expect(document.querySelector('.ffc-booking-date-display').textContent).not.toContain(' – ');
	});
});
