// Sprint F2 — deep coverage for assets/js/ffc-audience-admin.js
// (466 LOC, previously 33.26%). Five handler groups all wired off the
// FFCAudienceAdmin singleton's init() at document.ready.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// FFC.request (the centralised admin-ajax helper this file's subject is
// migrated to use) wraps jQuery.post() in a Promise. To drive that
// Promise from a test, mock $.post and return a chain whose .done /
// .fail callback the FFC.request internals invoke.
function postChain(spec) {
	const chain = { done: () => chain, fail: () => chain };
	if (spec && 'done' in spec) chain.done = (cb) => { cb(spec.done); return chain; };
	if (spec && spec.fail) chain.fail = (cb) => { cb(); return chain; };
	return chain;
}

// FFC.rest still spies on $.ajax (FFC.rest uses jQuery.ajax internally
// with opts.success / opts.error callbacks fired by the spy).

// Microtask flush — see dashboard-fixtures.flushPromises.
function flush() { return Promise.resolve().then(() => Promise.resolve()); }

beforeAll(() => {
	window.ffcAudienceAdmin = {
		searchUsersNonce: 'search-nonce',
		schedulePermissionsNonce: 'perm-nonce',
		adminNonce: 'admin-nonce',
		ajaxUrl: '/wp-admin/admin-ajax.php',
		exportNonce: 'export-nonce',
		strings: {
			exportPreparing: 'Preparing…',
			exportProgress: 'Exporting %1$d/%2$d…',
			exportDone: 'Done!',
			allEnvironments: 'All Environments',
			loading: 'Loading…',
			error: 'Error',
			bookingDetails: 'Booking Details',
			confirmCancel: 'Cancel booking?',
			cancelReason: 'Reason?',
			cancelled: 'Cancelled',
			active: 'Active',
			audience: 'Audience',
			customUsers: 'Custom Users',
			allDay: 'All Day',
			date: 'Date', time: 'Time', environmentLabel: 'Environment',
			description: 'Description', type: 'Type', status: 'Status',
			createdBy: 'By', audiences: 'Audiences', users: 'Users',
			alreadyAdded: 'already added',
			noUsersFound: 'No users',
			errorAddingUser: 'Error adding user',
			confirmRemoveUser: 'Remove?',
			noUsersYet: 'None yet',
		},
	};
	window.ajaxurl = '/wp-admin/admin-ajax.php';
	// ffc-audience-admin.js was migrated to FFC.request — load
	// ffc-core.js so window.FFC is defined when the audience-admin IIFE
	// evaluates inside each test's reload() call.
	window.ffc_ajax = {
		ajax_url: window.ajaxurl,
		nonce: window.ffcAudienceAdmin.adminNonce,
		strings: window.ffcAudienceAdmin.strings,
	};
	loadScript('assets/js/ffc-core.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
	window.$.fx.off = true;
});

afterEach(() => {
	vi.restoreAllMocks();
	vi.useRealTimers();
	// Clean up any modal leftover from booking tests.
	document.querySelectorAll('#ffc-booking-modal').forEach((el) => el.remove());
});

async function reload() {
	loadScript('assets/js/ffc-audience-admin.js');
	await new Promise((r) => setTimeout(r, 0));
}

// ----------------------------------------------------------------------
// bindEvents — day-row time-input gate
// ----------------------------------------------------------------------

describe('audience-admin — day row time toggle', () => {
	it('checking the "closed" checkbox disables the time inputs in that row', async () => {
		document.body.innerHTML = `
			<div class="ffc-day-row">
				<input type="checkbox" class="ffc-day-closed">
				<input type="time" class="ffc-day-open">
				<input type="time" class="ffc-day-close">
			</div>
		`;
		await reload();

		window.$('.ffc-day-row input[type="checkbox"]').prop('checked', true).trigger('change');
		await flush();

		expect(window.$('input[type="time"]').first().prop('disabled')).toBe(true);
		expect(window.$('input[type="time"]').last().prop('disabled')).toBe(true);
	});

	it('unchecking enables them again', async () => {
		document.body.innerHTML = `
			<div class="ffc-day-row">
				<input type="checkbox" class="ffc-day-closed" checked>
				<input type="time" class="ffc-day-open" disabled>
			</div>
		`;
		await reload();

		window.$('.ffc-day-row input[type="checkbox"]').prop('checked', false).trigger('change');
		await flush();

		expect(window.$('input[type="time"]').prop('disabled')).toBe(false);
	});
});

// ----------------------------------------------------------------------
// initUserSearch — autocomplete for audience-group member management
// ----------------------------------------------------------------------

describe('audience-admin — user search autocomplete', () => {
	function mountSearch() {
		document.body.innerHTML = `
			<input id="user_search">
			<div id="user_results"></div>
			<div id="selected_users"></div>
			<input type="hidden" id="selected_user_ids">
		`;
	}

	it('bails when query length < 2 (no AJAX)', async () => {
		mountSearch();
		await reload();
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		window.$('#user_search').val('a').trigger('input');
		await flush();
		// Debounce window for AJAX is 300 ms; if it fired it'd land later.
		vi.useFakeTimers();
		vi.advanceTimersByTime(400);
		expect(postSpy).not.toHaveBeenCalled();
	});

	it('debounces 300 ms then POSTs ffc_search_users with the query', async () => {
		mountSearch();
		await reload();
		vi.useFakeTimers();
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: [{ id: 1, name: 'Alice', email: 'a@x' }] } }));

		window.$('#user_search').val('alice').trigger('input');
		vi.advanceTimersByTime(299);
		expect(postSpy).not.toHaveBeenCalled();
		vi.advanceTimersByTime(1);
		await flush();
		expect(postSpy).toHaveBeenCalled();
		expect(postSpy.mock.calls[0][1]).toMatchObject({
			action: 'ffc_search_users',
			query: 'alice',
			nonce: 'search-nonce',
		});

		// Results dropdown populated.
		expect(window.$('#user_results').hasClass('active')).toBe(true);
		expect(window.$('#user_results .ffc-user-result').length).toBe(1);
	});

	it('selecting a result adds the user and clears the search input', async () => {
		mountSearch();
		await reload();
		// Bypass the debounce: render a result row directly so the
		// document-level click delegate can pick it up.
		window.$('#user_results').html('<div class="ffc-user-result" data-id="9" data-name="Alice"></div>').addClass('active');

		window.$('.ffc-user-result').trigger('click');
		await flush();

		expect(window.$('#selected_user_ids').val()).toBe('9');
		expect(window.$('#selected_users').text()).toContain('Alice');
		expect(window.$('#user_search').val()).toBe('');
		expect(window.$('#user_results').hasClass('active')).toBe(false);
	});

	it('removing a selected user updates the hidden ids list', async () => {
		mountSearch();
		await reload();
		// Pre-populate selection.
		window.$('#user_results').html('<div class="ffc-user-result" data-id="3" data-name="Bob"></div>');
		window.$('.ffc-user-result').trigger('click');
		await flush();
		expect(window.$('#selected_user_ids').val()).toBe('3');

		window.$('#selected_users .ffc-selected-user .remove').trigger('click');
		await flush();

		expect(window.$('#selected_user_ids').val()).toBe('');
	});

	it('on empty response: hides the results dropdown', async () => {
		mountSearch();
		await reload();
		vi.useFakeTimers();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: [] } }));

		window.$('#user_search').val('zzz').trigger('input');
		vi.advanceTimersByTime(300);
		await flush();

		expect(window.$('#user_results').hasClass('active')).toBe(false);
		expect(window.$('#user_results').html()).toBe('');
	});
});

// ----------------------------------------------------------------------
// initEnvironmentFilter — schedule → environment cascade
// ----------------------------------------------------------------------

describe('audience-admin — schedule → environment filter cascade', () => {
	function mountCascade() {
		document.body.innerHTML = `
			<select id="filter-schedule">
				<option value="">All</option>
				<option value="7">Main</option>
			</select>
			<select id="filter-environment">
				<option value="">All Environments</option>
			</select>
		`;
	}

	it('clearing the schedule resets the environment select', async () => {
		mountCascade();
		await reload();
		window.$('#filter-schedule').val('').trigger('change');
		await flush();
		const opts = window.$('#filter-environment option').map((_, el) => el.textContent).get();
		expect(opts).toEqual(['All Environments']);
	});

	it('on schedule change: posts ffc_audience_get_environments and populates the select', async () => {
		mountCascade();
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: [{ id: 1, name: 'Env A' }, { id: 2, name: 'Env B' }] } }));

		window.$('#filter-schedule').val('7').trigger('change');
		await flush();

		const opts = window.$('#filter-environment option').map((_, el) => el.textContent).get();
		expect(opts).toEqual(['All Environments', 'Env A', 'Env B']);
	});

	it('on response.success=false: leaves the select with the all-environments placeholder', async () => {
		mountCascade();
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false } }));

		window.$('#filter-schedule').val('7').trigger('change');
		await flush();

		const opts = window.$('#filter-environment option').map((_, el) => el.textContent).get();
		expect(opts).toEqual(['All Environments']);
	});

	it('on network error: leaves the select with the all-environments placeholder', async () => {
		mountCascade();
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: true }));

		window.$('#filter-schedule').val('7').trigger('change');
		await flush();

		const opts = window.$('#filter-environment option').map((_, el) => el.textContent).get();
		expect(opts).toEqual(['All Environments']);
	});
});

// ----------------------------------------------------------------------
// initBookingActions — view + cancel
// ----------------------------------------------------------------------

describe('audience-admin — booking actions', () => {
	function mountBookings() {
		document.body.innerHTML = `
			<table>
				<tr>
					<td>
						<a class="ffc-view-booking" data-booking-id="42" href="#">View</a> |
						<a class="ffc-cancel-booking" data-booking-id="42" href="#">Cancel</a>
						<span class="status-active">Active</span>
					</td>
				</tr>
			</table>
		`;
	}

	it('clicking view opens the modal and renders the booking fields', async () => {
		mountBookings();
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: {
					booking_date: '2026-06-01', is_all_day: 0,
					start_time: '08:00', end_time: '18:00',
					environment_name: 'Hall', description: 'Big event',
					booking_type: 'audience', status: 'active',
					created_by: 'Alice', audiences: [{ name: 'G1' }], users: [],
				},
			} }));

		window.$('.ffc-view-booking').trigger('click');
		await flush();

		expect(window.$('#ffc-booking-modal').length).toBe(1);
		const text = window.$('#ffc-booking-modal').text();
		expect(text).toContain('Hall');
		expect(text).toContain('08:00 - 18:00');
		expect(text).toContain('Big event');
		expect(text).toContain('Active');
	});

	it('all-day bookings render the localised "All Day" text', async () => {
		mountBookings();
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: {
					booking_date: '2026-06-01', is_all_day: 1,
					start_time: '', end_time: '',
					environment_name: 'Hall', description: '',
					booking_type: 'users', status: 'active',
					created_by: 'Bob', audiences: [], users: [{ name: 'U1', email: 'u@x' }],
				},
			} }));

		window.$('.ffc-view-booking').trigger('click');
		await flush();

		const text = window.$('#ffc-booking-modal').text();
		expect(text).toContain('All Day');
		expect(text).toContain('Custom Users');
		expect(text).toContain('U1');
	});

	it('cancelled bookings include the cancel reason line', async () => {
		mountBookings();
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: {
					booking_date: '2026-06-01', is_all_day: 1,
					start_time: '', end_time: '',
					environment_name: 'Hall', description: '',
					booking_type: 'audience', status: 'cancelled',
					created_by: 'Bob', audiences: [], users: [],
					cancel_reason: 'Bad weather',
				},
			} }));

		window.$('.ffc-view-booking').trigger('click');
		await flush();

		expect(window.$('#ffc-booking-modal').text()).toContain('Bad weather');
		expect(window.$('#ffc-booking-modal').text()).toContain('Cancelled');
	});

	it('on view AJAX response.success=false: shows the error message in the modal body', async () => {
		mountBookings();
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false, data: { message: 'Booking missing' } } }));

		window.$('.ffc-view-booking').trigger('click');
		await flush();

		expect(window.$('#ffc-booking-modal .ffc-admin-modal-body').text()).toContain('Booking missing');
	});

	it('on view network error: renders the generic error message', async () => {
		mountBookings();
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: true }));

		window.$('.ffc-view-booking').trigger('click');
		await flush();

		expect(window.$('#ffc-booking-modal .ffc-admin-modal-body').text()).toContain('Error');
	});

	it('clicking the close button removes the modal', async () => {
		mountBookings();
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: {
					booking_date: '2026-06-01', is_all_day: 1,
					start_time: '', end_time: '',
					environment_name: '', description: '',
					booking_type: 'audience', status: 'active',
					created_by: '', audiences: [], users: [],
				},
			} }));
		window.$('.ffc-view-booking').trigger('click');
		await flush();
		expect(window.$('#ffc-booking-modal').length).toBe(1);

		window.$('.ffc-admin-modal-close').trigger('click');
		await flush();

		expect(window.$('#ffc-booking-modal').length).toBe(0);
	});

	it('Escape key removes the modal', async () => {
		mountBookings();
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: {
					booking_date: '2026-06-01', is_all_day: 1,
					start_time: '', end_time: '',
					environment_name: '', description: '',
					booking_type: 'audience', status: 'active',
					created_by: '', audiences: [], users: [],
				},
			} }));
		window.$('.ffc-view-booking').trigger('click');
		await flush();

		const ev = window.$.Event('keydown', { key: 'Escape' });
		window.$(document).trigger(ev);
		await flush();

		expect(window.$('#ffc-booking-modal').length).toBe(0);
	});

	it('cancel: bails when the user declines the confirm', async () => {
		mountBookings();
		await reload();
		vi.spyOn(window, 'confirm').mockReturnValue(false);
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		window.$('.ffc-cancel-booking').trigger('click');
		await flush();

		expect(postSpy).not.toHaveBeenCalled();
	});

	it('cancel: bails when the user dismisses the reason prompt', async () => {
		mountBookings();
		await reload();
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window, 'prompt').mockReturnValue(null);
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		window.$('.ffc-cancel-booking').trigger('click');
		await flush();

		expect(postSpy).not.toHaveBeenCalled();
	});

	it('cancel: on success, replaces status pill + removes the cancel link', async () => {
		mountBookings();
		await reload();
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window, 'prompt').mockReturnValue('Weather');
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true } }));

		window.$('.ffc-cancel-booking').trigger('click');
		await flush();

		expect(window.$('.status-active').length).toBe(0);
		expect(window.$('.status-cancelled').text()).toBe('Cancelled');
		expect(window.$('.ffc-cancel-booking').length).toBe(0);
	});

	it('cancel: on response.success=false alerts the server message', async () => {
		mountBookings();
		await reload();
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window, 'prompt').mockReturnValue('R');
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false, data: { message: 'Already cancelled' } } }));
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-cancel-booking').trigger('click');
		await flush();

		expect(alertSpy).toHaveBeenCalledWith('Already cancelled');
		// Cancel link still present + visually restored.
		expect(window.$('.ffc-cancel-booking').length).toBe(1);
	});

	it('cancel: on network error, alerts the generic error', async () => {
		mountBookings();
		await reload();
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window, 'prompt').mockReturnValue('R');
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: true }));
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-cancel-booking').trigger('click');
		await flush();

		expect(alertSpy).toHaveBeenCalledWith('Error');
	});
});

// ----------------------------------------------------------------------
// initCalendarPermissions
// ----------------------------------------------------------------------

describe('audience-admin — calendar permissions', () => {
	function mountPermissions() {
		document.body.innerHTML = `
			<table id="ffc-permissions-table" data-schedule-id="42">
				<tbody>
					<tr id="ffc-no-permissions-row"><td><em>No users yet</em></td></tr>
				</tbody>
			</table>
			<input id="ffc-user-search">
			<input type="hidden" id="ffc-selected-user-id">
			<div id="ffc-user-search-results"></div>
			<button id="ffc-add-user-btn" disabled>Add</button>
		`;
	}

	it('user search: debounces 300 ms then renders results', async () => {
		mountPermissions();
		await reload();
		vi.useFakeTimers();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: [{ id: 5, name: 'Alice', email: 'a@x' }] } }));

		window.$('#ffc-user-search').val('alice').trigger('input');
		vi.advanceTimersByTime(300);
		await flush();

		// jsdom has no layout — :visible always false. Assert directly
		// on the inline display style (jQuery `.show()` sets it).
		expect(window.$('#ffc-user-search-results').css('display')).not.toBe('none');
		expect(window.$('.ffc-user-result').length).toBe(1);
	});

	it('user search: renders the no-users-found message for an empty response', async () => {
		mountPermissions();
		await reload();
		vi.useFakeTimers();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: [] } }));

		window.$('#ffc-user-search').val('xx').trigger('input');
		vi.advanceTimersByTime(300);
		await flush();

		expect(window.$('#ffc-user-search-results').text()).toContain('No users');
	});

	it('selecting a user enables the add button', async () => {
		mountPermissions();
		await reload();
		window.$('#ffc-user-search-results').html(
			'<div class="ffc-user-result" data-id="9" data-name="Alice"></div>',
		);

		window.$('#ffc-user-search-results .ffc-user-result').trigger('click');
		await flush();

		expect(window.$('#ffc-selected-user-id').val()).toBe('9');
		expect(window.$('#ffc-add-user-btn').prop('disabled')).toBe(false);
	});

	it('add: on success appends the row HTML returned by the server', async () => {
		mountPermissions();
		await reload();
		window.$('#ffc-selected-user-id').val('9');
		window.$('#ffc-add-user-btn').prop('disabled', false);
		// Simulate the JS state that pairs with the input.
		window.$('#ffc-user-search-results').html(
			'<div class="ffc-user-result" data-id="9" data-name="Alice"></div>',
		);
		window.$('.ffc-user-result').trigger('click');
		await flush();

		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: { html: '<tr data-user-id="9"><td>Alice</td></tr>' } } }));

		window.$('#ffc-add-user-btn').trigger('click');
		await flush();

		expect(window.$('#ffc-permissions-table tbody tr[data-user-id="9"]').length).toBe(1);
		expect(window.$('#ffc-no-permissions-row').length).toBe(0);
		expect(window.$('#ffc-user-search').val()).toBe('');
	});

	it('toggle permission: posts the schedule + user + perm + value', async () => {
		mountPermissions();
		await reload();
		window.$('#ffc-permissions-table tbody').append(
			'<tr data-user-id="9"><td><input type="checkbox" class="ffc-perm-toggle" data-perm="manage" checked></td></tr>',
		);
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => ({}));

		window.$('.ffc-perm-toggle').trigger('change');
		await flush();

		expect(postSpy).toHaveBeenCalled();
		const payload = postSpy.mock.calls[0][1];
		expect(payload).toMatchObject({
			action: 'ffc_audience_update_user_permission',
			schedule_id: 42,
			user_id: 9,
			permission: 'manage',
			value: 1,
		});
	});

	it('remove: bails when confirm is declined', async () => {
		mountPermissions();
		await reload();
		window.$('#ffc-permissions-table tbody').append(
			'<tr data-user-id="9"><td><button class="ffc-remove-user-btn">x</button></td></tr>',
		);
		vi.spyOn(window, 'confirm').mockReturnValue(false);
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => ({}));

		window.$('.ffc-remove-user-btn').trigger('click');
		await flush();

		expect(postSpy).not.toHaveBeenCalled();
	});

	it('remove: on success removes the row, shows the no-users-yet placeholder when empty', async () => {
		mountPermissions();
		await reload();
		window.$('#ffc-no-permissions-row').remove();
		window.$('#ffc-permissions-table tbody').append(
			'<tr data-user-id="9"><td><button class="ffc-remove-user-btn">x</button></td></tr>',
		);
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true } }));

		window.$('.ffc-remove-user-btn').trigger('click');
		await flush();

		expect(window.$('#ffc-permissions-table tbody tr[data-user-id="9"]').length).toBe(0);
		expect(window.$('#ffc-no-permissions-row').length).toBe(1);
	});
});

// ----------------------------------------------------------------------
// Residual branches — error paths + guards
// ----------------------------------------------------------------------

describe('audience-admin — residual branches', () => {
	function mountSearch() {
		document.body.innerHTML = `
			<input id="user_search">
			<div id="user_results"></div>
			<div id="selected_users"></div>
			<input type="hidden" id="selected_user_ids">
		`;
	}

	function mountPermissions(scheduleId = '42') {
		document.body.innerHTML = `
			<table id="ffc-permissions-table" data-schedule-id="${scheduleId}">
				<tbody>
					<tr data-user-id="5"><td>existing</td></tr>
				</tbody>
			</table>
			<input id="ffc-user-search">
			<input type="hidden" id="ffc-selected-user-id">
			<div id="ffc-user-search-results"></div>
			<button id="ffc-add-user-btn" disabled>Add</button>
		`;
	}

	it('user search (group members): hides results on a network error', async () => {
		mountSearch();
		await reload();
		vi.useFakeTimers();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: true }));
		window.$('#user_results').addClass('active').html('<div>old</div>');

		window.$('#user_search').val('alice').trigger('input');
		vi.advanceTimersByTime(300);
		await flush();

		expect(window.$('#user_results').hasClass('active')).toBe(false);
		expect(window.$('#user_results').html()).toBe('');
	});

	it('renders a user result even when the name is empty (escHtml falsy guard)', async () => {
		mountSearch();
		await reload();
		vi.useFakeTimers();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: [{ id: 5, name: '', email: 'a@x' }] } }));

		window.$('#user_search').val('al').trigger('input');
		vi.advanceTimersByTime(300);
		await flush();

		expect(window.$('#user_results .ffc-user-result').length).toBe(1);
	});

	it('calendar permissions: bails when the table has no schedule id', async () => {
		// data-schedule-id absent → initCalendarPermissions returns early,
		// so the user-search input never wires up an AJAX call.
		mountPermissions('');
		await reload();
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));
		vi.useFakeTimers();

		window.$('#ffc-user-search').val('alice').trigger('input');
		vi.advanceTimersByTime(400);
		expect(postSpy).not.toHaveBeenCalled();
	});

	it('calendar permissions search: under 2 chars hides the results', async () => {
		mountPermissions();
		await reload();
		window.$('#ffc-user-search-results').show().html('<div>x</div>');

		window.$('#ffc-user-search').val('a').trigger('input');
		await flush();

		expect(window.$('#ffc-user-search-results').css('display')).toBe('none');
	});

	it('calendar permissions search: marks already-added users disabled', async () => {
		mountPermissions();
		await reload();
		vi.useFakeTimers();
		// User 5 already has a row → rendered as disabled with the
		// "already added" note; user 6 is selectable.
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: [
			{ id: 5, name: 'Existing', email: 'e@x' },
			{ id: 6, name: 'New', email: 'n@x' },
		] } }));

		window.$('#ffc-user-search').val('ex').trigger('input');
		vi.advanceTimersByTime(300);
		await flush();

		expect(window.$('#ffc-user-search-results .ffc-user-exists').length).toBe(1);
		expect(window.$('#ffc-user-search-results').text()).toContain('already added');
		expect(window.$('#ffc-user-search-results .ffc-user-result').length).toBe(2);
	});

	it('calendar permissions search: hides results on a network error', async () => {
		mountPermissions();
		await reload();
		vi.useFakeTimers();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: true }));
		window.$('#ffc-user-search-results').show().html('<div>x</div>');

		window.$('#ffc-user-search').val('alice').trigger('input');
		vi.advanceTimersByTime(300);
		await flush();

		expect(window.$('#ffc-user-search-results').css('display')).toBe('none');
	});

	it('add user: alerts the error message and re-enables on a failed add', async () => {
		mountPermissions();
		await reload();
		// Select user 6 first so selectedUserId is set.
		window.$('#ffc-user-search-results').html('<div class="ffc-user-result" data-id="6" data-name="New"></div>');
		window.$('#ffc-user-search-results .ffc-user-result').trigger('click');
		await flush();

		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false, data: { message: 'Cannot add' } } }));
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('#ffc-add-user-btn').trigger('click');
		await flush();

		expect(alertSpy).toHaveBeenCalledWith('Cannot add');
		expect(window.$('#ffc-add-user-btn').prop('disabled')).toBe(false);
	});

	it('add user: no-ops when no user is selected', async () => {
		mountPermissions();
		await reload();
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));

		// selectedUserId stays 0 → the handler returns before posting.
		window.$('#ffc-add-user-btn').prop('disabled', false).trigger('click');
		await flush();

		expect(postSpy).not.toHaveBeenCalled();
	});
});

// ----------------------------------------------------------------------
// Batched CSV export (#772) — the bookings-page Export button
// ----------------------------------------------------------------------

describe('audience-admin — bookings CSV export', () => {
	afterEach(() => {
		delete window.FFCBatchedExport;
	});

	it('drives window.FFCBatchedExport.run with type + current filters + job nonce', async () => {
		const runSpy = vi.fn();
		window.FFCBatchedExport = { run: runSpy };
		document.body.innerHTML = `
			<button type="button" id="ffc-bookings-export-btn"
				data-schedule_id="4" data-environment_id="3" data-status="active"
				data-date_from="2026-05-01" data-date_to="2026-05-31">Export CSV</button>
			<span id="ffc-bookings-export-progress" style="display:none;"></span>
		`;
		await reload();

		window.$('#ffc-bookings-export-btn').trigger('click');

		// The bindEvents() delegate is attached to `document`, which persists
		// across the file's many reload() calls, so the handler may fire more
		// than once — assert it fired and inspect the first (identical) call.
		expect(runSpy).toHaveBeenCalled();
		const arg = runSpy.mock.calls[0][0];
		expect(arg.type).toBe('audience_bookings');
		expect(arg.ajaxUrl).toBe('/wp-admin/admin-ajax.php');
		expect(arg.nonce).toBe('export-nonce');
		expect(arg.startData).toEqual({
			schedule_id: 4,
			environment_id: 3,
			status: 'active',
			date_from: '2026-05-01',
			date_to: '2026-05-31',
		});
		expect(typeof arg.callbacks.onProgress).toBe('function');
		expect(typeof arg.callbacks.onComplete).toBe('function');
		expect(typeof arg.callbacks.onError).toBe('function');
	});

	it('is a no-op when the batched-export driver is unavailable', async () => {
		document.body.innerHTML = '<button type="button" id="ffc-bookings-export-btn">Export CSV</button>';
		await reload();
		expect(() => window.$('#ffc-bookings-export-btn').trigger('click')).not.toThrow();
	});
});
