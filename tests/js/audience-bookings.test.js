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
			<button class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-cross ffc-modal-close" aria-label="Close"></button>
			<input type="checkbox" id="ffc-show-cancelled" />
			<div id="ffc-day-status" aria-live="polite"></div>
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
	const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
	const status = () => window.$('#ffc-day-status .ffc-notice');

	it('asks the reason in the confirmation dialog, and opens no prompt (#2534)', () => {
		const confirmSpy = vi.spyOn(window.FFC, 'confirmRangeCancel').mockResolvedValue(false);
		const promptSpy = vi.spyOn(window, 'prompt').mockImplementation(() => null);
		api.loadDayBookings(DATE);
		window.$('#ffc-day-bookings .ffc-cancel-booking').first().trigger('click');

		expect(confirmSpy.mock.calls[0][0].reasonField).toEqual({ label: expect.any(String), min: 1 });
		expect(promptSpy).not.toHaveBeenCalled();
	});

	it('bails without a REST call when the dialog is dismissed', async () => {
		const restSpy = vi.spyOn(window.FFC, 'rest');
		vi.spyOn(window.FFC, 'confirmRangeCancel').mockResolvedValue(false);
		api.loadDayBookings(DATE);
		window.$('#ffc-day-bookings .ffc-cancel-booking').first().trigger('click');
		await tick();
		expect(restSpy).not.toHaveBeenCalled();
	});

	it('DELETEs the booking with the reason typed in the dialog and says it on the panel', async () => {
		vi.spyOn(window.FFC, 'confirmRangeCancel').mockResolvedValue({ choice: 'this', reason: 'Double booked' });
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		const restSpy = vi.spyOn(window.FFC, 'rest').mockResolvedValue({ success: true });

		api.loadDayBookings(DATE);
		window.$('#ffc-day-bookings .ffc-cancel-booking[data-id="1"]').trigger('click');
		await tick();

		const [url, opts] = restSpy.mock.calls[0];
		expect(url).toContain('/bookings/1');
		expect(opts.method).toBe('DELETE');
		expect(opts.data).toEqual({ reason: 'Double booked', scope: 'this' });
		expect(alertSpy).not.toHaveBeenCalled();
		expect(status().hasClass('ffc-notice-success')).toBe(true);
		expect(status().attr('role')).toBe('status');
		expect(status().text()).toContain('Booking cancelled.');
		expect(api.renderCalendar).toHaveBeenCalled();
	});

	it('sends the series scope the dialog chose and shows how many dates went (#2243)', async () => {
		vi.spyOn(window.FFC, 'confirmRangeCancel').mockResolvedValue({ choice: 'following', reason: 'Course ended' });
		const restSpy = vi.spyOn(window.FFC, 'rest').mockResolvedValue({ success: true, cancelled: [1, 5], message: '2 dates of the series cancelled.' });

		api.loadDayBookings(DATE);
		window.$('#ffc-day-bookings .ffc-cancel-booking[data-id="1"]').trigger('click');
		await tick();

		expect(restSpy.mock.calls[0][1].data).toEqual({ reason: 'Course ended', scope: 'following' });
		expect(status().text()).toContain('2 dates of the series cancelled.');
	});

	it('shows the server message as an alert notice when the cancel reports failure', async () => {
		vi.spyOn(window.FFC, 'confirmRangeCancel').mockResolvedValue({ choice: 'this', reason: 'reason' });
		vi.spyOn(window.FFC, 'rest').mockResolvedValue({ success: false, message: 'Nope' });

		api.loadDayBookings(DATE);
		window.$('#ffc-day-bookings .ffc-cancel-booking').first().trigger('click');
		await tick();

		expect(status().hasClass('ffc-notice-danger')).toBe(true);
		expect(status().attr('role')).toBe('alert');
		expect(status().text()).toContain('Nope');
	});

	it('shows the refusal the server explains, else the generic error, on a rejected request', async () => {
		vi.spyOn(window.FFC, 'confirmRangeCancel').mockResolvedValue({ choice: 'this', reason: 'reason' });
		const rest = vi.spyOn(window.FFC, 'rest').mockRejectedValue({ xhr: { responseJSON: { message: 'Too late to cancel.' } } });

		api.loadDayBookings(DATE);
		window.$('#ffc-day-bookings .ffc-cancel-booking').first().trigger('click');
		await tick();
		expect(status().text()).toContain('Too late to cancel.');

		rest.mockRejectedValue(new Error('net'));
		window.$('#ffc-day-bookings .ffc-cancel-booking').first().trigger('click');
		await tick();
		expect(status().length).toBe(1);
		expect(status().text()).toContain('Error');
	});

	it('clears a result said for another day when the panel opens again', async () => {
		vi.spyOn(window.FFC, 'confirmRangeCancel').mockResolvedValue({ choice: 'this', reason: 'reason' });
		vi.spyOn(window.FFC, 'rest').mockResolvedValue({ success: true });
		api.loadDayBookings(DATE);
		window.$('#ffc-day-bookings .ffc-cancel-booking').first().trigger('click');
		await tick();
		expect(status().length).toBe(1);

		api.openDayModal(DATE);
		expect(status().length).toBe(0);
	});
});

// Edit sits beside Cancel for whoever may cancel the booking (#2243).
describe('ffc-audience-bookings — Edit', () => {
	it('shows Edit only on a booking the person may cancel, and opens the dialog on it', () => {
		api.state.bookings[DATE][0].can_cancel = true;
		api.state.bookings[DATE][1].can_cancel = false;
		api.openEditModal = vi.fn();

		api.loadDayBookings(DATE);
		const $edits = window.$('#ffc-day-bookings .ffc-edit-booking');
		expect($edits.length).toBe(1);
		expect($edits.attr('data-id')).toBe('1');

		$edits.trigger('click');
		expect(api.openEditModal).toHaveBeenCalledWith(api.state.bookings[DATE][0]);
	});
});
