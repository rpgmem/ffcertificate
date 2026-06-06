// Coverage for assets/js/ffc-audience.js — the window.FFCAudience
// singleton (canonical core-helpers suite; absorbed the former
// audience-core-helpers.test.js, whose cases were a strict subset). Two parts:
//
//   1. The pure leaf helpers (date/time/pad, escapeHtml, booking label,
//      the audience-tree utilities, environment colour/label lookup,
//      closeModals/trapFocus) driven directly against the singleton with
//      a synthetic state.config.
//
//   2. The bindEvents() delegated handler bodies — the all-day toggle,
//      booking-type toggle, audience parent→descendant auto-select,
//      description counter, user-search debounce, user-result select,
//      selected-user remove, conflict-acknowledge gate and the
//      new-booking button. These run end-to-end against a full fixture so
//      each handler's body (not just its registration) executes.
//
// Complements the shallow init smoke in audience-smoke.test.js.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

let api;

beforeAll(() => {
	window.ffcAudience = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		restUrl: '/wp-json/ffc/v1/audience/',
		nonce: 'n',
		locale: 'pt_BR',
		strings: { booking: 'booking', bookings: 'bookings', environmentLabel: 'Environment' },
	};
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', strings: {} };
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-audience.js');
	api = window.FFCAudience;
});

beforeEach(() => {
	window.$.fx.off = true;
	api.state.config = {};
	api.state._triggerElement = null;
	api.state.selectedUsers = {};
	document.body.innerHTML = '';
});

afterEach(() => {
	vi.restoreAllMocks();
});

// ----------------------------------------------------------------------
// Pure leaf helpers
// ----------------------------------------------------------------------

describe('FFCAudience helpers — date / time / pad', () => {
	it('pad adds a leading zero below 10 only', () => {
		expect(api.pad(5)).toBe('05');
		expect(api.pad(12)).toBe('12');
	});

	it('formatDate renders YYYY-MM-DD with padding', () => {
		expect(api.formatDate(new Date(2026, 5, 9))).toBe('2026-06-09');
		expect(api.formatDate(new Date(2026, 11, 25))).toBe('2026-12-25');
	});

	it('parseDate builds a local-timezone date (no UTC shift)', () => {
		const d = api.parseDate('2026-06-09');
		expect(d.getFullYear()).toBe(2026);
		expect(d.getMonth()).toBe(5);
		expect(d.getDate()).toBe(9);
	});

	it('formatTime trims to HH:MM and tolerates empty', () => {
		expect(api.formatTime('09:30:00')).toBe('09:30');
		expect(api.formatTime('')).toBe('');
	});
});

describe('FFCAudience helpers — escapeHtml', () => {
	it('escapes the five entities', () => {
		expect(api.escapeHtml('<a href="x">&\'')).toBe('&lt;a href=&quot;x&quot;&gt;&amp;&#39;');
	});

	it('returns empty string for falsy input', () => {
		expect(api.escapeHtml('')).toBe('');
		expect(api.escapeHtml(null)).toBe('');
	});
});

describe('FFCAudience helpers — booking label', () => {
	it('prefers the schedule-level custom label', () => {
		api.state.config = { schedules: [{ bookingLabelSingular: 'Aula', bookingLabelPlural: 'Aulas' }] };
		expect(api.getBookingLabel('singular')).toBe('Aula');
		expect(api.getBookingLabel('plural')).toBe('Aulas');
	});

	it('falls back to the global strings when no schedule label', () => {
		api.state.config = { schedules: [{}] };
		expect(api.getBookingLabel('singular')).toBe('booking');
		expect(api.getBookingLabel('plural')).toBe('bookings');
	});
});

describe('FFCAudience helpers — audience tree', () => {
	const tree = [
		{ id: 1, name: 'Parent', children: [
			{ id: 11, name: 'Child A', children: [{ id: 111, name: 'Grand' }] },
			{ id: 12, name: 'Child B' },
		] },
	];

	it('buildParentNameMap maps every child to its parent name', () => {
		api.state.config = { audiences: tree };
		const map = api.buildParentNameMap();
		expect(map[11]).toBe('Parent');
		expect(map[12]).toBe('Parent');
		expect(map[111]).toBe('Child A');
	});

	it('formatAudienceName prefixes the parent only in parent_name mode', () => {
		api.state.config = { audiences: tree, audienceBadgeFormat: 'parent_name' };
		const map = api.buildParentNameMap();
		expect(api.formatAudienceName({ id: 11, name: 'Child A' }, map)).toBe('Parent: Child A');
		expect(api.formatAudienceName({ id: 1, name: 'Parent' }, map)).toBe('Parent');
		api.state.config.audienceBadgeFormat = 'plain';
		expect(api.formatAudienceName({ id: 11, name: 'Child A' }, map)).toBe('Child A');
	});

	it('collapseParentAudiences hides children when the parent + all descendants are present', () => {
		api.state.config = { audiences: tree };
		const input = [{ id: 1 }, { id: 11 }, { id: 111 }, { id: 12 }];
		expect(api.collapseParentAudiences(input).map((a) => a.id)).toEqual([1]);
	});

	it('collapseParentAudiences keeps children when not every descendant is present', () => {
		api.state.config = { audiences: tree };
		const input = [{ id: 1 }, { id: 11 }]; // 12 + 111 missing
		expect(api.collapseParentAudiences(input).map((a) => a.id)).toEqual([1, 11]);
	});

	it('collapseParentAudiences returns the input unchanged when empty', () => {
		expect(api.collapseParentAudiences([])).toEqual([]);
	});
});

describe('FFCAudience helpers — environment colour / label', () => {
	beforeEach(() => {
		api.state.config = { schedules: [
			{ environmentLabel: 'Sala', environments: [
				{ id: 10, name: 'A', color: '#abcdef' },
				{ id: 11, name: 'B' },
			] },
		] };
	});

	it('getEnvironmentColor returns the env colour, else the default', () => {
		expect(api.getEnvironmentColor(10)).toBe('#abcdef');
		expect(api.getEnvironmentColor(11)).toBe('#3788d8');
		expect(api.getEnvironmentColor(999)).toBe('#3788d8');
	});

	it('getEnvironmentLabelForBooking resolves the owning schedule label', () => {
		expect(api.getEnvironmentLabelForBooking({ environment_id: 10 })).toBe('Sala');
		expect(api.getEnvironmentLabelForBooking({ environment_id: 999 })).toBe('Environment');
	});
});

describe('FFCAudience helpers — closeModals + trapFocus', () => {
	it('closeModals hides both modals and restores focus to the trigger', () => {
		document.body.innerHTML = `
			<button id="trigger">open</button>
			<div id="ffc-booking-modal" style="display:block"></div>
			<div id="ffc-day-modal" style="display:block"></div>
		`;
		api.state._triggerElement = document.getElementById('trigger');
		api.closeModals();
		expect(window.$('#ffc-booking-modal').css('display')).toBe('none');
		expect(window.$('#ffc-day-modal').css('display')).toBe('none');
		expect(api.state._triggerElement).toBeNull();
	});

	it('trapFocus binds a Tab handler that runs without throwing', () => {
		document.body.innerHTML = '<div id="m"><button id="b1">1</button><button id="b2">2</button></div>';
		const $m = window.$('#m');
		api.trapFocus($m);
		expect(() => {
			$m.trigger(window.$.Event('keydown', { key: 'a' }));
			$m.trigger(window.$.Event('keydown', { key: 'Tab', shiftKey: false }));
			$m.trigger(window.$.Event('keydown', { key: 'Tab', shiftKey: true }));
		}).not.toThrow();
	});
});

// ----------------------------------------------------------------------
// bindEvents() handler bodies — driven on a full fixture after init().
// ----------------------------------------------------------------------

describe('FFCAudience bindEvents — handler bodies', () => {
	// A second namespace boot is not possible (the IIFE only registers
	// once), so we re-create the singleton's bindEvents wiring by calling
	// init() against a fresh fixture each time. The calendar/bookings/
	// booking-form sibling methods aren't loaded here, so we stub the
	// api methods that bindEvents handlers call.
	function fixture() {
		document.body.innerHTML = `
			<div id="ffc-audience-calendar" data-config='${JSON.stringify({
				scheduleId: 0,
				environmentId: 0,
				schedules: [{ id: 1, name: 'Main', environments: [{ id: 10, name: 'Env A' }] }],
				audiences: [
					{ id: 1, name: 'Parent', children: [
						{ id: 11, name: 'Sub A' },
						{ id: 12, name: 'Sub B' },
					] },
				],
			})}'></div>
			<button class="ffc-prev-month"></button>
			<button class="ffc-next-month"></button>
			<button class="ffc-today-btn"></button>
			<span class="ffc-current-month"></span>
			<select id="ffc-schedule-select"><option value="0">Any</option><option value="1">Main</option></select>
			<select id="ffc-environment-select"></select>
			<div id="ffc-calendar-days"></div>

			<div id="ffc-day-modal" style="display:none"></div>
			<div id="ffc-booking-modal" style="display:none">
				<input type="checkbox" id="booking-all-day" />
				<div id="booking-time-row"></div>
				<input id="booking-start-time" />
				<input id="booking-end-time" />
				<select id="booking-type">
					<option value="audience">Audience</option>
					<option value="users">Users</option>
				</select>
				<div id="audience-select-group"></div>
				<div id="user-select-group"></div>
				<select id="booking-audiences" multiple>
					<option value="1">Parent</option>
					<option value="11">Sub A</option>
					<option value="12">Sub B</option>
				</select>
				<textarea id="booking-description"></textarea>
				<span id="desc-char-count"></span>
				<input id="booking-user-search" />
				<div id="booking-user-results"></div>
				<div id="booking-selected-users"></div>
				<input id="booking-user-ids" />
				<input type="checkbox" id="ffc-conflict-acknowledge" />
				<button id="ffc-check-conflicts-btn"></button>
				<button id="ffc-create-booking-btn"></button>
			</div>
			<input type="checkbox" id="ffc-show-cancelled" />
			<button id="ffc-new-booking-btn"></button>
		`;
	}

	beforeEach(() => {
		window.$.fx.off = true;
		fixture();
		// Stub sibling-module methods the handlers call so init() + clicks
		// don't blow up (those modules aren't loaded in this file).
		api.renderCalendar = vi.fn();
		api.updateEnvironmentSelect = vi.fn();
		api.populateAudienceSelect = vi.fn();
		api.openDayModal = vi.fn();
		api.openBookingModal = vi.fn();
		api.loadDayBookings = vi.fn();
		api.searchUsers = vi.fn();
		api.updateSelectedUsers = vi.fn();
		api.checkConflicts = vi.fn();
		api.createBooking = vi.fn();
		api.init();
	});

	it('all-day toggle on hides the time row and clears required times', () => {
		window.$('#booking-all-day').prop('checked', true).trigger('change');
		expect(window.$('#booking-time-row').css('display')).toBe('none');
		expect(window.$('#booking-start-time').val()).toBe('00:00');
		expect(window.$('#booking-end-time').val()).toBe('23:59');
		expect(window.$('#booking-start-time').attr('required')).toBeUndefined();
	});

	it('all-day toggle off shows the time row and re-applies required', () => {
		window.$('#booking-all-day').prop('checked', false).trigger('change');
		expect(window.$('#booking-time-row').css('display')).not.toBe('none');
		expect(window.$('#booking-start-time').attr('required')).toBe('required');
		expect(window.$('#booking-start-time').val()).toBe('');
	});

	it('booking-type audience shows the audience group, hides users', () => {
		window.$('#booking-type').val('audience').trigger('change');
		expect(window.$('#audience-select-group').css('display')).not.toBe('none');
		expect(window.$('#user-select-group').css('display')).toBe('none');
	});

	it('booking-type users shows the user group, hides audiences', () => {
		window.$('#booking-type').val('users').trigger('change');
		expect(window.$('#user-select-group').css('display')).not.toBe('none');
		expect(window.$('#audience-select-group').css('display')).toBe('none');
	});

	it('selecting an audience parent auto-selects all descendants', () => {
		window.$('#booking-audiences').val(['1']).trigger('change');
		const selected = window.$('#booking-audiences').val();
		expect(selected).toEqual(expect.arrayContaining(['1', '11', '12']));
	});

	it('deselecting a previously-selected parent removes its descendants', () => {
		// First select parent (adds 11 + 12 as descendants).
		window.$('#booking-audiences').val(['1']).trigger('change');
		expect(window.$('#booking-audiences').val()).toEqual(expect.arrayContaining(['11', '12']));
		// Now drop the parent but leave the children selected in the raw
		// value; the handler's "parent just deselected" branch filters the
		// descendants back out.
		window.$('#booking-audiences').val(['11', '12']).trigger('change');
		expect(window.$('#booking-audiences').val()).toEqual([]);
	});

	it('selecting only a leaf child leaves the selection unchanged (equality check)', () => {
		// Selecting a non-parent leaf adds no descendants and toggles no
		// parent, so newSelected === selected — the "selection changed"
		// guard evaluates its .every() comparison and finds no change.
		window.$('#booking-audiences').val(['11']).trigger('change');
		expect(window.$('#booking-audiences').val()).toEqual(['11']);
	});

	it('description input updates the char counter', () => {
		window.$('#booking-description').val('hello').trigger('input');
		expect(window.$('#desc-char-count').text()).toBe('5');
	});

	it('user-search under 2 chars clears the results without calling searchUsers', () => {
		window.$('#booking-user-results').addClass('active').html('<div>x</div>');
		window.$('#booking-user-search').val('a').trigger('input');
		expect(window.$('#booking-user-results').hasClass('active')).toBe(false);
		expect(api.searchUsers).not.toHaveBeenCalled();
	});

	it('user-search of 2+ chars debounces then calls searchUsers', () => {
		vi.useFakeTimers();
		window.$('#booking-user-search').val('alice').trigger('input');
		expect(api.searchUsers).not.toHaveBeenCalled();
		vi.advanceTimersByTime(300);
		expect(api.searchUsers).toHaveBeenCalledWith('alice');
		vi.useRealTimers();
	});

	it('clicking a user result records the user and clears the search', () => {
		window.$('#booking-user-results')
			.html('<div class="ffc-user-result" data-id="5" data-name="Alice"></div>')
			.addClass('active');
		window.$('#booking-user-results .ffc-user-result').trigger('click');
		expect(api.state.selectedUsers[5]).toBe('Alice');
		expect(api.updateSelectedUsers).toHaveBeenCalled();
		expect(window.$('#booking-user-search').val()).toBe('');
		expect(window.$('#booking-user-results').hasClass('active')).toBe(false);
	});

	it('clicking a selected-user remove chip deletes the user', () => {
		api.state.selectedUsers = { 5: 'Alice' };
		window.$('#booking-selected-users').html('<span class="remove" data-id="5">x</span>');
		window.$('#booking-selected-users .remove').trigger('click');
		expect(api.state.selectedUsers[5]).toBeUndefined();
		expect(api.updateSelectedUsers).toHaveBeenCalled();
	});

	it('check-conflicts button calls checkConflicts', () => {
		window.$('#ffc-check-conflicts-btn').trigger('click');
		expect(api.checkConflicts).toHaveBeenCalled();
	});

	it('create-booking button calls createBooking', () => {
		window.$('#ffc-create-booking-btn').trigger('click');
		expect(api.createBooking).toHaveBeenCalled();
	});

	it('conflict-acknowledge toggle enables/disables the create button', () => {
		window.$('#ffc-conflict-acknowledge').prop('checked', true).trigger('change');
		expect(window.$('#ffc-create-booking-btn').prop('disabled')).toBe(false);
		window.$('#ffc-conflict-acknowledge').prop('checked', false).trigger('change');
		expect(window.$('#ffc-create-booking-btn').prop('disabled')).toBe(true);
	});

	it('new-booking button closes the day modal and opens the booking modal', () => {
		api.closeModals = vi.fn();
		window.$('#ffc-day-modal').data('date', '2026-06-10');
		window.$('#ffc-new-booking-btn').trigger('click');
		expect(api.closeModals).toHaveBeenCalled();
		expect(api.openBookingModal).toHaveBeenCalledWith('2026-06-10');
	});

	it('show-cancelled change reloads the day bookings when a date is set', () => {
		window.$('#ffc-day-modal').data('date', '2026-06-10');
		window.$('#ffc-show-cancelled').prop('checked', true).trigger('change');
		expect(api.loadDayBookings).toHaveBeenCalledWith('2026-06-10');
	});

	it('day click on an available cell opens the day modal', () => {
		// The day-click delegate is bound on #ffc-audience-calendar, so the
		// cell must live inside that container for the delegate to match.
		window.$('#ffc-audience-calendar').html('<div class="ffc-day" data-date="2026-06-10"></div>');
		window.$('#ffc-audience-calendar .ffc-day').trigger('click');
		expect(api.openDayModal).toHaveBeenCalledWith('2026-06-10');
	});

	it('prev / next / today navigation re-renders the calendar', () => {
		window.$('.ffc-prev-month').trigger('click');
		window.$('.ffc-next-month').trigger('click');
		window.$('.ffc-today-btn').trigger('click');
		expect(api.renderCalendar).toHaveBeenCalled();
	});

	it('schedule + environment select changes update state and re-render', () => {
		window.$('#ffc-schedule-select').val('1').trigger('change');
		expect(api.state.selectedSchedule).toBe(1);
		window.$('#ffc-environment-select').html('<option value="10">A</option>').val('10').trigger('change');
		expect(api.state.selectedEnvironment).toBe(10);
	});
});
