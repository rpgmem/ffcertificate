// Deep coverage for assets/js/ffc-audience-bookings.js — the day modal,
// day-bookings list rendering (active/cancelled/all-day/timed, audience
// tags, cancel action gating) and the cancel-booking flow. Drives the
// methods registered back on window.FFCAudience by the module.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

let api;

beforeAll(() => {
	window.ffcAudience = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		restUrl: '/wp-json/ffc/v1/audience/',
		nonce: 'n',
		locale: 'pt-BR',
		strings: {
			booking: 'booking', bookings: 'bookings',
			noBookings: 'No bookings for this day.',
			noActiveBookings: 'No active bookings for this day.',
			allDay: 'All Day',
			cancelled: 'Cancelled',
			cancel: 'Cancel',
			cancelReason: 'Reason?',
			bookingCancelled: 'Booking cancelled.',
			error: 'Error',
			environmentLabel: 'Environment',
		},
	};
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', strings: {} };
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-audience.js');
	loadScript('assets/js/ffc-audience-bookings.js');
	api = window.FFCAudience;
});

function dayModalFixture() {
	document.body.innerHTML = `
		<div id="ffc-day-modal" style="display:none">
			<div class="ffc-day-modal-title"></div>
			<button class="ffc-modal-close">x</button>
			<input type="checkbox" id="ffc-show-cancelled" />
			<div id="ffc-day-bookings"></div>
		</div>
	`;
}

const DATE = '2026-06-10';

function seedBookings() {
	api.state.config = {
		canBook: true,
		audiences: [{ id: 1, name: 'Group', children: [{ id: 11, name: 'Sub' }] }],
		schedules: [{ environmentLabel: 'Room', environments: [{ id: 10, name: 'Env A', color: '#abc' }] }],
	};
	api.state.bookings = {
		[DATE]: [
			{ id: 1, status: 'active', is_all_day: 0, start_time: '09:00:00', end_time: '10:00:00',
			  description: 'Timed <b>one</b>', environment_id: 10, environment_name: 'Env A',
			  audiences: [{ id: 11, name: 'Sub', color: '#f00' }] },
			{ id: 2, status: 'active', is_all_day: 1, start_time: '00:00:00', end_time: '23:59:00',
			  description: 'All day', environment_id: 10, environment_name: 'Env A', audiences: [] },
			{ id: 3, status: 'cancelled', is_all_day: 0, start_time: '14:00:00', end_time: '15:00:00',
			  description: 'Gone', environment_id: 10, environment_name: 'Env A', audiences: [] },
		],
	};
}

beforeEach(() => {
	window.$.fx.off = true;
	dayModalFixture();
	seedBookings();
	api.renderCalendar = vi.fn(); // defined in the calendar module (not loaded here)
});

afterEach(() => {
	vi.restoreAllMocks();
	document.body.innerHTML = '';
});

describe('ffc-audience-bookings — loadDayBookings render', () => {
	it('renders only active bookings by default (cancelled hidden)', () => {
		api.loadDayBookings(DATE);
		const $items = window.$('#ffc-day-bookings .ffc-booking-item');
		expect($items.length).toBe(2);
		const html = window.$('#ffc-day-bookings').html();
		expect(html).toContain('09:00 - 10:00');
		expect(html).toContain('All Day');
		expect(html).toContain('Timed &lt;b&gt;one&lt;/b&gt;');
		expect(html).toContain('ffc-audience-tag');
		expect(html).toContain('Sub');
		expect(window.$('#ffc-day-bookings .ffc-cancel-booking').length).toBe(2);
	});

	it('includes cancelled bookings and marks them when show-cancelled is on', () => {
		window.$('#ffc-show-cancelled').prop('checked', true);
		api.loadDayBookings(DATE);
		expect(window.$('#ffc-day-bookings .ffc-booking-item').length).toBe(3);
		expect(window.$('#ffc-day-bookings .ffc-booking-cancelled').length).toBe(1);
		expect(window.$('#ffc-day-bookings').html()).toContain('Cancelled');
	});

	it('omits the cancel button when canBook is false', () => {
		api.state.config.canBook = false;
		api.loadDayBookings(DATE);
		expect(window.$('#ffc-day-bookings .ffc-cancel-booking').length).toBe(0);
	});

	it('shows the no-bookings message when the day is empty', () => {
		api.state.bookings = { [DATE]: [] };
		api.loadDayBookings(DATE);
		expect(window.$('#ffc-day-bookings .ffc-no-bookings').text()).toBe('No bookings for this day.');
	});

	it('shows the no-active message when all bookings are cancelled and hidden', () => {
		api.state.bookings = { [DATE]: [
			{ id: 9, status: 'cancelled', is_all_day: 0, start_time: '08:00:00', end_time: '09:00:00',
			  description: 'x', environment_id: 10, environment_name: 'Env A', audiences: [] },
		] };
		api.loadDayBookings(DATE);
		expect(window.$('#ffc-day-bookings .ffc-no-bookings').text()).toBe('No active bookings for this day.');
	});
});

describe('ffc-audience-bookings — openDayModal', () => {
	it('sets the title, shows the modal, stores the date and loads bookings', () => {
		api.openDayModal(DATE);
		expect(window.$('#ffc-day-modal .ffc-day-modal-title').text().length).toBeGreaterThan(0);
		expect(window.$('#ffc-day-modal').css('display')).not.toBe('none');
		expect(window.$('#ffc-day-modal').data('date')).toBe(DATE);
		expect(window.$('#ffc-day-bookings .ffc-booking-item').length).toBe(2);
	});
});

describe('ffc-audience-bookings — cancelBooking', () => {
	it('bails without a REST call when the reason prompt is empty', () => {
		const restSpy = vi.spyOn(window.FFC, 'rest');
		vi.spyOn(window, 'prompt').mockReturnValue('   ');
		api.loadDayBookings(DATE);
		window.$('#ffc-day-bookings .ffc-cancel-booking').first().trigger('click');
		expect(restSpy).not.toHaveBeenCalled();
	});

	it('DELETEs the booking and refreshes on a successful cancel', async () => {
		vi.spyOn(window, 'prompt').mockReturnValue('Double booked');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		const restSpy = vi.spyOn(window.FFC, 'rest').mockResolvedValue({ success: true });

		api.loadDayBookings(DATE);
		window.$('#ffc-day-bookings .ffc-cancel-booking[data-id="1"]').trigger('click');
		await Promise.resolve().then(() => Promise.resolve());

		expect(restSpy).toHaveBeenCalled();
		const [url, opts] = restSpy.mock.calls[0];
		expect(url).toContain('/bookings/1');
		expect(opts.method).toBe('DELETE');
		expect(opts.data.reason).toBe('Double booked');
		expect(alertSpy).toHaveBeenCalledWith('Booking cancelled.');
		expect(api.renderCalendar).toHaveBeenCalled();
	});

	it('alerts the server message when the cancel reports failure', async () => {
		vi.spyOn(window, 'prompt').mockReturnValue('reason');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		vi.spyOn(window.FFC, 'rest').mockResolvedValue({ success: false, message: 'Nope' });

		api.loadDayBookings(DATE);
		window.$('#ffc-day-bookings .ffc-cancel-booking').first().trigger('click');
		await Promise.resolve().then(() => Promise.resolve());

		expect(alertSpy).toHaveBeenCalledWith('Nope');
	});

	it('alerts the generic error on a rejected cancel request', async () => {
		vi.spyOn(window, 'prompt').mockReturnValue('reason');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		vi.spyOn(window.FFC, 'rest').mockRejectedValue(new Error('net'));

		api.loadDayBookings(DATE);
		window.$('#ffc-day-bookings .ffc-cancel-booking').first().trigger('click');
		await Promise.resolve().then(() => Promise.resolve());

		expect(alertSpy).toHaveBeenCalledWith('Error');
	});
});
