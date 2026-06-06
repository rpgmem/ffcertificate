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
	loadScript('assets/js/ffc-audience-booking-form.js');
	api = window.FFCAudience;
});

function modalFixture() {
	document.body.innerHTML = `
		<form id="ffc-booking-form">
			<div id="ffc-booking-modal" style="display:none">
				<button class="ffc-modal-close">x</button>
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
				<div id="ffc-conflict-warning" style="display:none"><div id="ffc-conflict-details"></div></div>
				<div id="ffc-conflict-error" style="display:none"><div id="ffc-conflict-error-details"></div></div>
				<input type="checkbox" id="ffc-conflict-acknowledge" />
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
	it('populates the environment select, sets the date display and shows the modal', () => {
		api.openBookingModal('2026-06-10');
		const $opts = window.$('#booking-environment-id option');
		expect($opts.length).toBe(2);
		expect(window.$('.ffc-booking-date-display').text().length).toBeGreaterThan(0);
		expect(window.$('#ffc-booking-modal').css('display')).not.toBe('none');
		expect(window.$('#booking-date').val()).toBe('2026-06-10');
		expect(window.$('label[for="booking-environment-id"]').html()).toContain('Room');
	});

	it('pre-selects the passed environment id', () => {
		api.openBookingModal('2026-06-10', '20');
		expect(window.$('#booking-environment-id').val()).toBe('20');
	});

	it('groups options by schedule name when multiple schedules exist', () => {
		api.state.config.schedules = [
			{ id: 1, name: 'Sched A', environments: [{ id: 10, name: 'Env A' }] },
			{ id: 2, name: 'Sched B', environments: [{ id: 20, name: 'Env B' }] },
		];
		api.openBookingModal('2026-06-10');
		const labels = window.$('#booking-environment-id option').map((_, el) => el.textContent).get();
		expect(labels).toContain('Sched A - Env A');
		expect(labels).toContain('Sched B - Env B');
	});

	it('renders a single bare option when only one environment exists', () => {
		api.state.config.schedules = [
			{ id: 1, name: 'Sched A', environments: [{ id: 10, name: 'Only Env' }] },
		];
		api.openBookingModal('2026-06-10');
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
		api.openBookingModal('2026-06-10');
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
		expect(window.$('#booking-user-results').hasClass('active')).toBe(true);
	});

	it('clears the results when the search returns nothing', async () => {
		vi.spyOn(window.FFC, 'request').mockResolvedValue([]);
		window.$('#booking-user-results').addClass('active').html('<div>old</div>');
		api.searchUsers('zzz');
		await Promise.resolve().then(() => Promise.resolve());
		expect(window.$('#booking-user-results').hasClass('active')).toBe(false);
		expect(window.$('#booking-user-results').html()).toBe('');
	});

	it('clears the results on a request rejection', async () => {
		vi.spyOn(window.FFC, 'request').mockRejectedValue(new Error('net'));
		window.$('#booking-user-results').addClass('active').html('<div>old</div>');
		api.searchUsers('x');
		await Promise.resolve().then(() => Promise.resolve());
		expect(window.$('#booking-user-results').hasClass('active')).toBe(false);
	});
});

describe('ffc-audience-booking-form — validation guards (via checkConflicts)', () => {
	it('blocks when time fields are empty', () => {
		setValidAudienceForm();
		window.$('#booking-start-time').val('');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		const restSpy = vi.spyOn(window.FFC, 'rest');
		api.checkConflicts();
		expect(alertSpy).toHaveBeenCalledWith('Please fill in the time fields.');
		expect(restSpy).not.toHaveBeenCalled();
	});

	it('blocks when start >= end', () => {
		setValidAudienceForm();
		window.$('#booking-end-time').val('09:00');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		api.checkConflicts();
		expect(alertSpy).toHaveBeenCalledWith('End must be after start.');
	});

	it('blocks when the description is too short', () => {
		setValidAudienceForm();
		window.$('#booking-description').val('too short');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		api.checkConflicts();
		expect(alertSpy).toHaveBeenCalledWith('Description must be 15–300 chars.');
	});

	it('blocks an audience booking with no audience selected', () => {
		setValidAudienceForm();
		window.$('#booking-audiences').val([]);
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		api.checkConflicts();
		expect(alertSpy).toHaveBeenCalledWith('Select an audience.');
	});

	it('blocks a user booking with no users selected', () => {
		setValidAudienceForm();
		window.$('#booking-type').val('users');
		window.$('#booking-user-ids').val('');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		api.checkConflicts();
		expect(alertSpy).toHaveBeenCalledWith('Select a user.');
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
		expect(window.$('#ffc-conflict-error-details').html()).toContain('09:00');
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
		expect(window.$('#ffc-conflict-details').html()).toContain('Sub');
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
		expect(window.$('#ffc-conflict-details').html()).toContain('2 member(s) overlap.');
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
