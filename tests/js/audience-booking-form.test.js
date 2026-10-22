// Deep coverage for assets/js/ffc-audience-booking-form.js — the booking
// modal: open/reset + environment-select population, user search, the
// selected-users chips, form validation, form-data extraction, the
// conflict check (hard / soft-audience / soft-user / none / error) and
// the create-booking flow. Drives the methods registered on
// window.FFCAudience by the module.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

let api;

beforeAll(() => {
	window.ffcAudience = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		restUrl: '/wp-json/ffc/v1/audience/',
		nonce: 'n',
		searchUsersNonce: 'su-nonce',
		locale: 'pt-BR',
		strings: {
			createBooking: 'Create booking',
			loading: 'Loading…',
			error: 'Error',
			invalidTime: 'End must be after start.',
			fillTimeFields: 'Please fill in the time fields.',
			descriptionRequired: 'Description must be 15–300 chars.',
			selectAudience: 'Select an audience.',
			selectUser: 'Select a user.',
			hardConflict: 'Already booked for this environment.',
			audienceSameDayWarning: 'This audience already has a booking today:',
			membersOverlapping: 'member(s) overlap.',
			bookingCreated: 'Booking created.',
			timeout: 'Timed out.',
		},
	};
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', strings: {} };
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-audience.js');
	// The form reads the space rules the calendar module registers (#1859).
	loadScript('assets/js/ffc-audience-calendar.js');
	loadScript('assets/js/ffc-audience-booking-form.js');
	api = window.FFCAudience;
});

function modalFixture() {
	document.body.innerHTML = `
		<form id="ffc-booking-form">
			<div id="ffc-booking-modal" style="display:none">
				<button class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-cross ffc-modal-close" aria-label="Close"></button>
				<input type="checkbox" id="booking-all-day" />
				<div id="booking-time-row"></div>
				<input id="booking-start-time" value="09:00" />
				<input id="booking-end-time" value="10:00" />
				<input id="booking-date" />
				<span class="ffc-booking-date-display"></span>
				<select id="booking-environment-id"></select>
				<label for="booking-environment-id">Env *</label>
				<select id="booking-type">
					<option value="audience">Audience</option>
					<option value="users">Users</option>
				</select>
				<select id="booking-audiences" multiple><option value="11">Sub</option></select>
				<input id="booking-user-search" />
				<div id="booking-user-results"></div>
				<div id="booking-selected-users"></div>
				<input id="booking-user-ids" />
				<textarea id="booking-description">A valid description here</textarea>
				<span id="desc-char-count"></span>
				<div id="ffc-conflict-warning" style="display:none">
					<label class="ffc-conflict-acknowledge"><input type="checkbox" id="ffc-conflict-acknowledge" /> I am aware</label>
				</div>
				<div id="ffc-conflict-error" style="display:none"></div>
				<button id="ffc-check-conflicts-btn">Check</button>
				<button id="ffc-create-booking-btn" style="display:none">Create</button>
			</div>
		</form>
	`;
}

function setValidAudienceForm() {
	window.$('#booking-all-day').prop('checked', false);
	window.$('#booking-start-time').val('09:00');
	window.$('#booking-end-time').val('10:00');
	window.$('#booking-description').val('A valid description here');
	window.$('#booking-type').val('audience');
	window.$('#booking-audiences').val(['11']);
	window.$('#booking-environment-id').html('<option value="10">A</option>').val('10');
	window.$('#booking-date').val('2026-06-10');
}

beforeEach(() => {
	window.$.fx.off = true;
	modalFixture();
	api.state.config = {
		selectedSchedule: 0,
		audiences: [{ id: 1, name: 'Group', children: [{ id: 11, name: 'Sub' }] }],
		schedules: [
			{ id: 1, name: 'Sched A', environmentLabel: 'Room', environments: [
				{ id: 10, name: 'Env A' }, { id: 20, name: 'Env B' },
			] },
		],
	};
	api.state.selectedSchedule = 0;
	api.state.selectedEnvironment = 0;
	api.state.selectedUsers = {};
	api.closeModals = vi.fn();
	api.renderCalendar = vi.fn();
});

afterEach(() => {
	vi.restoreAllMocks();
	document.body.innerHTML = '';
});

describe('ffc-audience-booking-form — openBookingModal', () => {
	// A future date: a past one lists no space to a caller without the
	// bypass (#1859).
	it('populates the environment select, sets the date display and shows the modal', () => {
		api.openBookingModal('2099-06-10');
		const $opts = window.$('#booking-environment-id option');
		expect($opts.length).toBe(2);
		expect(window.$('.ffc-booking-date-display').text().length).toBeGreaterThan(0);
		expect(window.$('#ffc-booking-modal').css('display')).not.toBe('none');
		expect(window.$('#booking-date').val()).toBe('2099-06-10');
		expect(window.$('label[for="booking-environment-id"]').html()).toContain('Room');
	});

	it('pre-selects the passed environment id', () => {
		api.openBookingModal('2099-06-10', '20');
		expect(window.$('#booking-environment-id').val()).toBe('20');
	});

	it('groups options by schedule name when multiple schedules exist', () => {
		api.state.config.schedules = [
			{ id: 1, name: 'Sched A', environments: [{ id: 10, name: 'Env A' }] },
			{ id: 2, name: 'Sched B', environments: [{ id: 20, name: 'Env B' }] },
		];
		api.openBookingModal('2099-06-10');
		const labels = window.$('#booking-environment-id option').map((_, el) => el.textContent).get();
		expect(labels).toContain('Sched A - Env A');
		expect(labels).toContain('Sched B - Env B');
	});

	it('renders a single bare option when only one environment exists', () => {
		api.state.config.schedules = [
			{ id: 1, name: 'Sched A', environments: [{ id: 10, name: 'Only Env' }] },
		];
		api.openBookingModal('2099-06-10');
		const $opts = window.$('#booking-environment-id option');
		expect($opts.length).toBe(1);
		expect($opts.eq(0).text()).toBe('Only Env');
	});

	it('resolves the environment label from the selected schedule', () => {
		api.state.config.schedules = [
			{ id: 1, name: 'Sched A', environmentLabel: 'Sala 1', environments: [{ id: 10, name: 'Env A' }] },
			{ id: 2, name: 'Sched B', environmentLabel: 'Sala 2', environments: [{ id: 20, name: 'Env B' }] },
		];
		api.state.selectedSchedule = 2;
		api.openBookingModal('2099-06-10');
		expect(window.$('label[for="booking-environment-id"]').html()).toContain('Sala 2');
	});
});

describe('ffc-audience-booking-form — updateSelectedUsers', () => {
	it('renders a chip per selected user and fills the hidden ids field', () => {
		api.state.selectedUsers = { 5: 'Alice', 7: 'Bob' };
		api.updateSelectedUsers();
		expect(window.$('#booking-selected-users .ffc-selected-user').length).toBe(2);
		expect(window.$('#booking-user-ids').val()).toBe('5,7');
	});
});

describe('ffc-audience-booking-form — searchUsers', () => {
	it('renders matching users, skipping already-selected ones', async () => {
		api.state.selectedUsers = { 7: 'Bob' };
		vi.spyOn(window.FFC, 'request').mockResolvedValue([
			{ id: 5, name: 'Alice', email: 'a@x.test' },
			{ id: 7, name: 'Bob', email: 'b@x.test' },
		]);
		api.searchUsers('al');
		await Promise.resolve().then(() => Promise.resolve());
		const $results = window.$('#booking-user-results .ffc-user-result');
		expect($results.length).toBe(1);
		expect($results.attr('data-id')).toBe('5');
		expect(window.$('#booking-user-results').hasClass('is-active')).toBe(true);
	});

	it('clears the results when the search returns nothing', async () => {
		vi.spyOn(window.FFC, 'request').mockResolvedValue([]);
		window.$('#booking-user-results').addClass('is-active').html('<div>old</div>');
		api.searchUsers('zzz');
		await Promise.resolve().then(() => Promise.resolve());
		expect(window.$('#booking-user-results').hasClass('is-active')).toBe(false);
		expect(window.$('#booking-user-results').html()).toBe('');
	});

	it('clears the results on a request rejection', async () => {
		vi.spyOn(window.FFC, 'request').mockRejectedValue(new Error('net'));
		window.$('#booking-user-results').addClass('is-active').html('<div>old</div>');
		api.searchUsers('x');
		await Promise.resolve().then(() => Promise.resolve());
		expect(window.$('#booking-user-results').hasClass('is-active')).toBe(false);
	});
});

describe('ffc-audience-booking-form — validation guards (via checkConflicts)', () => {
	// Each problem is said under its field in the field-message standard,
	// never in an alert() (#1844).
	function messageFor(selector) {
		const id = window.$(selector).attr('data-ffc-message-id');
		return id ? window.$('#' + id) : window.$();
	}

	it('blocks when time fields are empty, under the empty field', () => {
		setValidAudienceForm();
		window.$('#booking-start-time').val('');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		const restSpy = vi.spyOn(window.FFC, 'rest');
		api.checkConflicts();
		expect(alertSpy).not.toHaveBeenCalled();
		expect(restSpy).not.toHaveBeenCalled();
		const $start = window.$('#booking-start-time');
		expect($start.hasClass('is-invalid')).toBe(true);
		expect($start.attr('aria-invalid')).toBe('true');
		expect(messageFor('#booking-start-time').text()).toBe('Please fill in the time fields.');
		expect(messageFor('#booking-start-time').attr('role')).toBe('alert');
		expect(window.$('#booking-end-time').hasClass('is-invalid')).toBe(false);
	});

	it('blocks when start >= end, under the end time', () => {
		setValidAudienceForm();
		// The modal is open when the visitor validates; a hidden control
		// cannot take the focus (jsdom 30.1.2 enforces it, as browsers do).
		window.$('#ffc-booking-modal').show();
		window.$('#booking-end-time').val('09:00');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		api.checkConflicts();
		expect(alertSpy).not.toHaveBeenCalled();
		expect(messageFor('#booking-end-time').text()).toBe('End must be after start.');
		expect(document.activeElement).toBe(window.$('#booking-end-time')[0]);
	});

	it('lets a span end earlier in the day than it starts and sends the range to the check (#1946)', () => {
		setValidAudienceForm();
		window.$('#ffc-booking-modal').append(
			'<input id="booking-end-date" value="2026-06-12" />' +
			'<select id="booking-time-mode"><option value="daily">daily</option><option value="span" selected>span</option></select>' +
			'<input type="checkbox" id="booking-skip-blocked" checked />'
		);
		window.$('#booking-start-time').val('14:00');
		window.$('#booking-end-time').val('10:00');
		const restSpy = vi.spyOn(window.FFC, 'rest').mockResolvedValue({ success: true, conflicts: {} });

		api.checkConflicts();

		expect(messageFor('#booking-end-time').length).toBe(0);
		expect(restSpy).toHaveBeenCalled();
		const data = restSpy.mock.calls[0][1].data;
		expect(data.end_date).toBe('2026-06-12');
		expect(data.time_mode).toBe('span');
		expect(data.skip_blocked).toBe(1);
	});

	it('still refuses an end before the start on a daily range', () => {
		setValidAudienceForm();
		window.$('#ffc-booking-modal').show().append(
			'<input id="booking-end-date" value="2026-06-12" />' +
			'<select id="booking-time-mode"><option value="daily" selected>daily</option><option value="span">span</option></select>'
		);
		window.$('#booking-end-time').val('08:00');
		const restSpy = vi.spyOn(window.FFC, 'rest');

		api.checkConflicts();

		expect(restSpy).not.toHaveBeenCalled();
		expect(messageFor('#booking-end-time').text()).toBe('End must be after start.');
	});

	it('blocks when the description is too short', () => {
		setValidAudienceForm();
		window.$('#booking-description').val('too short');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		api.checkConflicts();
		expect(alertSpy).not.toHaveBeenCalled();
		expect(messageFor('#booking-description').text()).toBe('Description must be 15–300 chars.');
	});

	it('blocks an audience booking with no audience selected', () => {
		setValidAudienceForm();
		window.$('#booking-audiences').val([]);
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		api.checkConflicts();
		expect(alertSpy).not.toHaveBeenCalled();
		expect(messageFor('#booking-audiences').text()).toBe('Select an audience.');
	});

	it('blocks a user booking with no users selected, after the chosen list', () => {
		setValidAudienceForm();
		window.$('#booking-type').val('users');
		window.$('#booking-user-ids').val('');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		api.checkConflicts();
		expect(alertSpy).not.toHaveBeenCalled();
		const $msg = messageFor('#booking-user-search');
		expect($msg.text()).toBe('Select a user.');
		if (window.$('#booking-selected-users').length) {
			expect(window.$('#booking-selected-users').next()[0]).toBe($msg[0]);
		}
	});

	it('shows every problem at once and clears them once fixed', () => {
		setValidAudienceForm();
		window.$('#booking-start-time').val('');
		window.$('#booking-description').val('short');
		api.checkConflicts();
		expect(window.$('.ffc-field-message').length).toBe(2);

		setValidAudienceForm();
		vi.spyOn(window.FFC, 'rest').mockResolvedValue({ success: true, conflicts: {} });
		api.checkConflicts();
		expect(window.$('.ffc-field-message').length).toBe(0);
		expect(window.$('#ffc-booking-form .is-invalid').length).toBe(0);
	});

	it('skips time validation for an all-day booking', () => {
		setValidAudienceForm();
		window.$('#booking-all-day').prop('checked', true);
		window.$('#booking-start-time').val('');
		const restSpy = vi.spyOn(window.FFC, 'rest').mockResolvedValue({ success: true, conflicts: {} });
		api.checkConflicts();
		expect(restSpy).toHaveBeenCalled();
		expect(restSpy.mock.calls[0][1].data.start_time).toBe('00:00');
		expect(restSpy.mock.calls[0][1].data.end_time).toBe('23:59');
	});
});

describe('ffc-audience-booking-form — getBookingFormData (via createBooking payload)', () => {
	it('maps comma-separated user ids to an int array for a user booking', async () => {
		setValidAudienceForm();
		window.$('#booking-type').val('users');
		window.$('#booking-user-ids').val('5, 7, ');
		const restSpy = vi.spyOn(window.FFC, 'rest').mockResolvedValue({ success: true });
		api.createBooking();
		await Promise.resolve().then(() => Promise.resolve());
		expect(restSpy.mock.calls[0][1].data.user_ids).toEqual([5, 7]);
		expect(restSpy.mock.calls[0][1].data.booking_type).toBe('users');
	});

	it('maps selected audience ids to an int array for an audience booking', async () => {
		setValidAudienceForm();
		const restSpy = vi.spyOn(window.FFC, 'rest').mockResolvedValue({ success: true });
		api.createBooking();
		await Promise.resolve().then(() => Promise.resolve());
		expect(restSpy.mock.calls[0][1].data.audience_ids).toEqual([11]);
	});
});

describe('ffc-audience-booking-form — checkConflicts outcomes', () => {
	beforeEach(setValidAudienceForm);

	it('shows the hard-conflict error and hides the create button', async () => {
		vi.spyOn(window.FFC, 'rest').mockResolvedValue({
			success: true,
			conflicts: { type: 'environment', bookings: [{ start_time: '09:00', end_time: '10:00' }] },
		});
		api.checkConflicts();
		await Promise.resolve().then(() => Promise.resolve());
		expect(window.$('#ffc-conflict-error').css('display')).not.toBe('none');
		const notice = document.querySelector('#ffc-conflict-error [data-ffc-notice="conflict-error"]');
		expect(notice.className).toBe('ffc-notice ffc-notice-danger');
		expect(notice.getAttribute('role')).toBe('alert');
		expect(notice.querySelector('.ffc-icon-error')).not.toBeNull();
		expect(notice.querySelector('.ffc-notice-title').textContent).toBe('Already booked for this environment.');
		expect(notice.querySelector('.ffc-notice-text').textContent).toBe('09:00–10:00');
		expect(window.$('#ffc-create-booking-btn').css('display')).toBe('none');
	});

	it('shows a soft audience-same-day warning and a disabled create button', async () => {
		vi.spyOn(window.FFC, 'rest').mockResolvedValue({
			success: true,
			conflicts: { audience_same_day: [{ audience_name: 'Sub', start_time: '11:00', end_time: '12:00' }] },
		});
		api.checkConflicts();
		await Promise.resolve().then(() => Promise.resolve());
		expect(window.$('#ffc-conflict-warning').css('display')).not.toBe('none');
		const notice = document.querySelector('#ffc-conflict-warning [data-ffc-notice="conflict-warning"]');
		expect(notice.className).toBe('ffc-notice ffc-notice-warning');
		expect(notice.querySelector('.ffc-icon-warning')).not.toBeNull();
		const lines = Array.from(notice.querySelectorAll('.ffc-notice-text')).map((p) => p.textContent);
		expect(lines).toEqual(['This audience already has a booking today:', 'Sub: 11:00–12:00']);
		// The acknowledgement moved into the notice, once, still the same node.
		expect(notice.querySelectorAll('#ffc-conflict-acknowledge').length).toBe(1);
		expect(document.querySelectorAll('#ffc-conflict-acknowledge').length).toBe(1);
		expect(window.$('#ffc-create-booking-btn').prop('disabled')).toBe(true);
	});

	it('shows a soft user-overlap warning', async () => {
		vi.spyOn(window.FFC, 'rest').mockResolvedValue({
			success: true,
			conflicts: { type: 'user', bookings: [{}], affected_users: [1, 2] },
		});
		api.checkConflicts();
		await Promise.resolve().then(() => Promise.resolve());
		expect(window.$('#ffc-conflict-warning').css('display')).not.toBe('none');
		expect(window.$('#ffc-conflict-warning .ffc-notice-text').text()).toContain('2 member(s) overlap.');
	});

	it('prints audience names and times as text, never as markup, on a re-check too', async () => {
		const rest = vi.spyOn(window.FFC, 'rest').mockResolvedValue({
			success: true,
			conflicts: { audience_same_day: [{ audience_name: '<img src=x>', start_time: '11:00', end_time: '12:00' }] },
		});
		api.checkConflicts();
		await Promise.resolve().then(() => Promise.resolve());
		api.checkConflicts();
		await Promise.resolve().then(() => Promise.resolve());
		const $warning = window.$('#ffc-conflict-warning');
		expect($warning.find('img').length).toBe(0);
		expect($warning.find('.ffc-notice').length).toBe(1);
		expect($warning.text()).toContain('<img src=x>: 11:00–12:00');
		expect($warning.find('#ffc-conflict-acknowledge').length).toBe(1);
		expect($warning.html()).not.toContain('style=');
		rest.mockRestore();
	});

	it('enables create directly when there is no conflict', async () => {
		vi.spyOn(window.FFC, 'rest').mockResolvedValue({ success: true, conflicts: {} });
		api.checkConflicts();
		await Promise.resolve().then(() => Promise.resolve());
		expect(window.$('#ffc-create-booking-btn').css('display')).not.toBe('none');
		expect(window.$('#ffc-create-booking-btn').prop('disabled')).toBe(false);
	});

	it('alerts when the server reports failure', async () => {
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		vi.spyOn(window.FFC, 'rest').mockResolvedValue({ success: false, message: 'Bad' });
		api.checkConflicts();
		await Promise.resolve().then(() => Promise.resolve());
		expect(alertSpy).toHaveBeenCalledWith('Bad');
	});

	it('alerts on a rejected conflict request (generic error)', async () => {
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		vi.spyOn(window.FFC, 'rest').mockRejectedValue({ message: 'boom', xhr: null });
		api.checkConflicts();
		await Promise.resolve().then(() => Promise.resolve());
		expect(alertSpy).toHaveBeenCalledWith('Error');
	});

	it('surfaces the xhr responseJSON message on a rejected conflict request', async () => {
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		vi.spyOn(window.FFC, 'rest').mockRejectedValue({
			message: 'http 409',
			xhr: { responseJSON: { message: 'Server says conflict' } },
		});
		api.checkConflicts();
		await Promise.resolve().then(() => Promise.resolve());
		expect(alertSpy).toHaveBeenCalledWith('Server says conflict');
	});

	it('surfaces the timeout string when the xhr reports a timeout', async () => {
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		vi.spyOn(window.FFC, 'rest').mockRejectedValue({
			message: 'timeout',
			xhr: { statusText: 'timeout', responseJSON: null },
		});
		api.checkConflicts();
		await Promise.resolve().then(() => Promise.resolve());
		expect(alertSpy).toHaveBeenCalledWith('Timed out.');
	});

	it('alerts the generic error if the response handler throws', async () => {
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		// A truthy success with audience_same_day entries whose shape makes
		// the grouping logic run; force a throw by passing a non-iterable
		// bookings value with type user.
		vi.spyOn(window.FFC, 'rest').mockResolvedValue({
			success: true,
			conflicts: { type: 'environment', bookings: null },
		});
		api.checkConflicts();
		await Promise.resolve().then(() => Promise.resolve());
		expect(alertSpy).toHaveBeenCalledWith('Error');
	});
});

describe('ffc-audience-booking-form — createBooking', () => {
	beforeEach(setValidAudienceForm);

	it('POSTs the booking and closes the modal on success', async () => {
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		const restSpy = vi.spyOn(window.FFC, 'rest').mockResolvedValue({ success: true });
		api.createBooking();
		await Promise.resolve().then(() => Promise.resolve());
		expect(restSpy.mock.calls[0][0]).toContain('/bookings');
		expect(restSpy.mock.calls[0][1].method).toBe('POST');
		expect(alertSpy).toHaveBeenCalledWith('Booking created.');
		expect(api.closeModals).toHaveBeenCalled();
		expect(api.renderCalendar).toHaveBeenCalled();
	});

	it('blocks the POST when validation fails', () => {
		setValidAudienceForm();
		window.$('#booking-description').val('short');
		const restSpy = vi.spyOn(window.FFC, 'rest');
		vi.spyOn(window, 'alert').mockImplementation(() => {});
		api.createBooking();
		expect(restSpy).not.toHaveBeenCalled();
	});

	it('re-enables the button and alerts on a server failure', async () => {
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		vi.spyOn(window.FFC, 'rest').mockResolvedValue({ success: false, message: 'Nope' });
		api.createBooking();
		await Promise.resolve().then(() => Promise.resolve());
		expect(alertSpy).toHaveBeenCalledWith('Nope');
		expect(window.$('#ffc-create-booking-btn').prop('disabled')).toBe(false);
	});

	it('alerts on a rejected create request', async () => {
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		vi.spyOn(window.FFC, 'rest').mockRejectedValue(new Error('net'));
		api.createBooking();
		await Promise.resolve().then(() => Promise.resolve());
		expect(alertSpy).toHaveBeenCalledWith('Error');
	});
});
