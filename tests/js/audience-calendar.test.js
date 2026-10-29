// Deep coverage for assets/js/ffc-audience-calendar.js — the month grid,
// schedule/environment selects, the audience select, fetchMonthData REST
// conversation, the event-list panel (bookings + holidays, sorting,
// collapse/multiple-audiences badge), getBookingCount and
// checkWithinBookingWindow. Drives the methods registered back on
// window.FFCAudience by the module (updateEnvironmentSelect,
// populateAudienceSelect, renderCalendar) with a synthetic state +
// mocked FFC.rest, complementing the shallow init smoke in
// audience-smoke.test.js.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

let api;

beforeAll(() => {
	window.ffcAudience = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		restUrl: '/wp-json/ffc/v1/audience/',
		nonce: 'n',
		locale: 'pt-BR',
		multipleAudiencesColor: '#999',
		strings: {
			booking: 'booking',
			bookings: 'bookings',
			allEnvironments: 'All Environments',
			all: 'All',
			events: 'Events',
			noEvents: 'No events this month.',
			holiday: 'Holiday',
			closed: 'Closed',
			allDay: 'All Day',
			multipleAudiences: 'Multiple audiences',
			months: ['January', 'February', 'March', 'April', 'May', 'June',
				'July', 'August', 'September', 'October', 'November', 'December'],
		},
	};
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', strings: {} };
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-audience.js');
	loadScript('assets/js/ffc-audience-calendar.js');
	api = window.FFCAudience;
});

function calendarFixture() {
	document.body.innerHTML = `
		<span class="ffc-current-month"></span>
		<select id="ffc-schedule-select"></select>
		<select id="ffc-environment-select"><option value="0">placeholder</option></select>
		<div id="booking-audiences"></div>
		<div id="ffc-calendar-days"></div>
		<div id="ffc-event-list-panel">
			<div class="ffc-event-list-header"><h3></h3></div>
		</div>
		<div id="ffc-event-list-content"></div>
	`;
}

beforeEach(() => {
	window.$.fx.off = true;
	calendarFixture();
	api.state.config = {};
	api.state.selectedSchedule = 0;
	api.state.selectedEnvironment = 0;
	api.state.bookings = {};
	api.state.holidays = {};
	api.state.closedWeekdays = [];
	api.state.fetchId = 0;
	api.openDayModal = vi.fn();
	// Default: REST resolves an empty payload so renderCalendar completes.
	vi.spyOn(window.FFC, 'rest').mockResolvedValue({ bookings: [], holidays: [], closed_weekdays: [] });
});

afterEach(() => {
	vi.restoreAllMocks();
	document.body.innerHTML = '';
});

function flush() {
	return Promise.resolve().then(() => Promise.resolve()).then(() => Promise.resolve());
}

describe('ffc-audience-calendar — updateEnvironmentSelect', () => {
	it('lists all environments across schedules when no schedule is selected', () => {
		api.state.config = {
			schedules: [
				{ id: 1, environments: [{ id: 10, name: 'Env A', color: '#abc' }] },
				{ id: 2, environments: [{ id: 20, name: 'Env B' }] },
			],
		};
		api.state.selectedSchedule = 0;
		api.updateEnvironmentSelect();
		const opts = window.$('#ffc-environment-select option').map((_, el) => el.textContent).get();
		expect(opts).toContain('Env A');
		expect(opts).toContain('Env B');
		expect(opts[0]).toBe('All Environments'); // placeholder relabelled
	});

	it('limits to the selected schedule and applies its custom plural label', () => {
		api.state.config = {
			schedules: [
				{ id: 1, environmentLabelPlural: 'Rooms', environments: [{ id: 10, name: 'Env A' }] },
				{ id: 2, environments: [{ id: 20, name: 'Env B' }] },
			],
		};
		api.state.selectedSchedule = 1;
		api.updateEnvironmentSelect();
		const opts = window.$('#ffc-environment-select option').map((_, el) => el.textContent).get();
		expect(opts).toContain('Env A');
		expect(opts).not.toContain('Env B');
		// "All Rooms" composed from strings.all + the plural label.
		expect(opts[0]).toBe('All Rooms');
	});

	it('preselects the current environment when one is set', () => {
		api.state.config = { schedules: [{ id: 1, environments: [{ id: 10, name: 'Env A' }] }] };
		api.state.selectedSchedule = 1;
		api.state.selectedEnvironment = 10;
		api.updateEnvironmentSelect();
		expect(window.$('#ffc-environment-select').val()).toBe('10');
	});
});

describe('ffc-audience-calendar — environment picker (#2201)', () => {
	function setup(selected = 0) {
		window.$('#ffc-environment-select').attr('aria-label', 'Filter by Room');
		api.state.config = {
			schedules: [{ id: 1, environments: [
				{ id: 10, name: 'Hall', color: '#0a7d55' },
				{ id: 20, name: 'Lab', color: 'red; background: url(x)' },
			] }],
		};
		api.state.selectedSchedule = 0;
		api.state.selectedEnvironment = selected;
		api.updateEnvironmentSelect();
		return {
			$select: window.$('#ffc-environment-select'),
			$button: window.$('.ffc-env-picker-button'),
			$list: window.$('.ffc-env-picker-list'),
		};
	}

	function key($el, name) {
		$el.trigger(window.$.Event('keydown', { key: name }));
	}

	it('mirrors the select with a colour dot per environment and none on "All"', () => {
		const { $select, $list } = setup();
		const $options = $list.children('[role="option"]');
		expect($options).toHaveLength(3);
		expect($options.eq(0).find('.ffc-color-dot')).toHaveLength(0);
		expect($options.eq(0).find('.ffc-env-picker-nodot')).toHaveLength(1);
		expect($options.eq(1).find('.ffc-color-dot').attr('style')).toBe('--ffc-color: #0a7d55;');
		// The select stays as the value, hidden from assistive technology.
		expect($select.attr('aria-hidden')).toBe('true');
		expect($select.attr('tabindex')).toBe('-1');
		expect($list.attr('aria-label')).toBe('Filter by Room');
	});

	it('drops a colour that is not a hex value instead of writing it into the style', () => {
		const { $list } = setup();
		const $dot = $list.children().eq(2).find('.ffc-color-dot');
		expect($dot).toHaveLength(1);
		expect($dot.attr('style')).toBeUndefined();
	});

	it('shows the current environment, with its dot, on the closed button', () => {
		const { $button } = setup(10);
		expect($button.find('.ffc-env-picker-name').text()).toBe('Hall');
		expect($button.find('.ffc-color-dot').attr('style')).toBe('--ffc-color: #0a7d55;');
		expect($button.attr('aria-label')).toBe('Filter by Room: Hall');
		expect($button.attr('aria-expanded')).toBe('false');
	});

	it('opens on click, chooses on click, and drives the select change', () => {
		const { $select, $button, $list } = setup();
		const onChange = vi.fn();
		$select.on('change', onChange);

		$button.trigger('click');
		expect($list.prop('hidden')).toBe(false);
		expect($button.attr('aria-expanded')).toBe('true');
		expect($list.attr('aria-activedescendant')).toBe($list.children().eq(0).attr('id'));

		$list.children().eq(2).trigger('click');
		expect($select.val()).toBe('20');
		expect(onChange).toHaveBeenCalledTimes(1);
		expect($list.prop('hidden')).toBe(true);
		expect(window.$('.ffc-env-picker-button .ffc-env-picker-name').text()).toBe('Lab');
		expect(window.$('.ffc-env-picker-list [aria-selected="true"]').attr('data-value')).toBe('20');
	});

	it('moves with the arrow keys, Home and End, and chooses with Enter', () => {
		const { $select, $button, $list } = setup();
		key($button, 'ArrowDown');
		expect($list.prop('hidden')).toBe(false);
		key($list, 'ArrowDown');
		expect($list.children('.is-active').attr('data-value')).toBe('10');
		key($list, 'End');
		expect($list.children('.is-active').attr('data-value')).toBe('20');
		key($list, 'ArrowDown');
		expect($list.children('.is-active').attr('data-value')).toBe('20');
		key($list, 'Home');
		key($list, 'ArrowUp');
		expect($list.children('.is-active').attr('data-value')).toBe('');
		key($list, 'ArrowDown');
		key($list, 'Enter');
		expect($select.val()).toBe('10');
		expect($list.prop('hidden')).toBe(true);
	});

	it('closes without choosing on Escape, Tab and a click outside', () => {
		const { $select, $button, $list } = setup();
		const onChange = vi.fn();
		$select.on('change', onChange);

		$button.trigger('click');
		key($list, 'ArrowDown');
		key($list, 'Escape');
		expect($list.prop('hidden')).toBe(true);

		$button.trigger('click');
		key($list, 'Tab');
		expect($list.prop('hidden')).toBe(true);

		$button.trigger('click');
		window.$(document.body).trigger('click');
		expect($list.prop('hidden')).toBe(true);
		$button.trigger('click');
		$button.trigger('click');
		expect($list.prop('hidden')).toBe(true);
		expect(onChange).not.toHaveBeenCalled();
	});

	it('does not fire change when the chosen value is the current one', () => {
		const { $select, $button, $list } = setup(10);
		const onChange = vi.fn();
		$select.on('change', onChange);
		$button.trigger('click');
		key($list, 'Enter');
		expect(onChange).not.toHaveBeenCalled();
	});

	it('rebuilds once, keeping one picker, when the select is refilled', () => {
		setup();
		api.state.selectedSchedule = 1;
		api.updateEnvironmentSelect();
		expect(window.$('.ffc-env-picker')).toHaveLength(1);
		expect(window.$('.ffc-env-picker-list').children()).toHaveLength(3);
	});
});

describe('ffc-audience-calendar — populateAudienceSelect', () => {
	const tree = () => ({
		audiences: [
			{ id: 1, name: 'Parent', color: '#d97706', members: 20, children: [
				{ id: 11, name: 'Child', color: 'red; background: url(x)', members: 1, children: [
					{ id: 111, name: '<b>Grand</b>', color: '#2563eb', members: 0 },
				] },
			] },
		],
	});

	it('renders one checkbox row per audience, its parent and depth (#2008)', () => {
		api.state.config = tree();
		api.populateAudienceSelect();
		const rows = window.$('#booking-audiences .ffc-audience-picker-option');
		expect(rows.length).toBe(3);
		expect(rows.map((_, el) => el.getAttribute('data-depth')).get()).toEqual(['0', '1', '2']);
		const inputs = rows.find('input[type="checkbox"]');
		expect(inputs.map((_, el) => el.value).get()).toEqual(['1', '11', '111']);
		expect(inputs.eq(0).attr('data-parent')).toBeUndefined();
		expect(inputs.eq(1).attr('data-parent')).toBe('1');
		expect(inputs.eq(2).attr('data-parent')).toBe('11');
		expect(inputs.eq(0).attr('name')).toBe('audience_ids[]');
	});

	it('names each audience as text, never as markup', () => {
		api.state.config = tree();
		api.populateAudienceSelect();
		expect(window.$('#booking-audiences b').length).toBe(0);
		expect(window.$('#booking-audiences .ffc-audience-picker-name').eq(2).text()).toBe('<b>Grand</b>');
	});

	it('carries a hex colour on the dot and drops anything else', () => {
		api.state.config = tree();
		api.populateAudienceSelect();
		const dots = window.$('#booking-audiences .ffc-color-dot');
		expect(dots.eq(0).attr('style')).toBe('--ffc-color: #d97706;');
		expect(dots.eq(1).attr('style')).toBeUndefined();
		expect(dots.eq(2).attr('style')).toBe('--ffc-color: #2563eb;');
	});

	it('shows the people a booking reaches, and nothing when the payload has no count', () => {
		api.state.config = tree();
		api.populateAudienceSelect();
		const counts = window.$('#booking-audiences .ffc-audience-picker-count').map((_, el) => el.textContent).get();
		expect(counts).toEqual(['20 people', '1 person', '0 people']);

		api.state.config = { audiences: [{ id: 5, name: 'No count' }] };
		api.populateAudienceSelect();
		expect(window.$('#booking-audiences .ffc-audience-picker-count').length).toBe(0);
	});
});

describe('ffc-audience-calendar — renderCalendar grid', () => {
	it('writes the month header and builds 42 day cells', async () => {
		api.state.config = { schedules: [{ id: 1, environments: [{ id: 10, name: 'A' }] }] };
		api.state.currentDate = new Date(2026, 5, 15); // June 2026
		api.renderCalendar();
		await flush();
		expect(window.$('.ffc-current-month').text()).toBe('June 2026');
		expect(window.$('#ffc-event-list-panel h3').text()).toBe('Events - June 2026');
		expect(window.$('#ffc-calendar-days .ffc-day').length).toBe(42);
	});

	it('marks holiday + closed days and renders their badges', async () => {
		api.state.config = { schedules: [] };
		api.state.currentDate = new Date(2026, 5, 1);
		// Resolve REST with a holiday on the 10th and closed Sundays (0).
		window.FFC.rest.mockResolvedValue({
			bookings: [],
			holidays: [{ holiday_date: '2026-06-10', description: 'Feriado' }],
			closed_weekdays: [0],
		});
		api.renderCalendar();
		await flush();
		expect(window.$('.ffc-day.ffc-holiday').length).toBeGreaterThanOrEqual(1);
		expect(window.$('.ffc-badge-holiday').length).toBeGreaterThanOrEqual(1);
		expect(window.$('.ffc-day.ffc-closed').length).toBeGreaterThanOrEqual(1);
		expect(window.$('.ffc-badge-closed').length).toBeGreaterThanOrEqual(1);
	});

	it('labels an optional holiday apart in its cell and the event list (#2202)', async () => {
		window.ffcAudience.strings.closedDateKinds = {
			holiday: { label: 'Holiday', short: 'Holiday' },
			optional: { label: 'Optional Holiday', short: 'Optional' },
		};
		api.state.config = { schedules: [] };
		api.state.currentDate = new Date(2026, 5, 1);
		window.FFC.rest.mockResolvedValue({
			bookings: [],
			holidays: [
				{ holiday_date: '2026-06-04', description: 'Corpus Christi', kind: 'optional' },
				{ holiday_date: '2026-06-10', description: 'Feriado', kind: 'holiday' },
			],
			holiday_kinds: { '2026-06-11': 'optional' },
			closed_weekdays: [],
		});
		api.renderCalendar();
		await flush();

		const $optional = window.$('.ffc-day[data-date="2026-06-04"] .ffc-badge-holiday');
		expect($optional.hasClass('ffc-badge-optional-holiday')).toBe(true);
		expect($optional.text()).toBe('Optional');
		expect($optional.attr('aria-label')).toBe('Optional Holiday');
		expect($optional.attr('title')).toBe('Optional Holiday');

		const $holiday = window.$('.ffc-day[data-date="2026-06-10"] .ffc-badge-holiday');
		expect($holiday.hasClass('ffc-badge-optional-holiday')).toBe(false);
		expect($holiday.text()).toBe('Holiday');

		const $items = window.$('#ffc-event-list-content .ffc-event-list-holiday');
		expect($items.filter('[data-date="2026-06-04"]').hasClass('ffc-event-list-optional-holiday')).toBe(true);
		expect($items.filter('[data-date="2026-06-04"]').find('.ffc-event-list-time').text()).toBe('Optional Holiday');
		expect($items.filter('[data-date="2026-06-10"]').hasClass('ffc-event-list-optional-holiday')).toBe(false);
		expect(api.state.holidayKinds['2026-06-11']).toBe('optional');

		delete window.ffcAudience.strings.closedDateKinds;
	});

	it('falls back to the plain holiday label when the kinds are not localized', async () => {
		api.state.config = { schedules: [] };
		api.state.currentDate = new Date(2026, 5, 1);
		window.FFC.rest.mockResolvedValue({
			bookings: [],
			holidays: [{ holiday_date: '2026-06-04', description: '', kind: 'optional' }],
			closed_weekdays: [],
		});
		api.renderCalendar();
		await flush();
		expect(window.$('.ffc-day[data-date="2026-06-04"] .ffc-badge-holiday').text()).toBe('Holiday');
	});

	it('renders a bookings badge counting only active bookings on a day', async () => {
		api.state.config = { schedules: [{ bookingLabelSingular: 'Aula', bookingLabelPlural: 'Aulas' }] };
		api.state.currentDate = new Date(2026, 5, 1);
		window.FFC.rest.mockResolvedValue({
			bookings: [
				{ booking_date: '2026-06-15', status: 'active', start_time: '09:00:00', end_time: '10:00:00', environment_id: 10, is_all_day: 0, description: '', audiences: [] },
				{ booking_date: '2026-06-15', status: 'active', start_time: '11:00:00', end_time: '12:00:00', environment_id: 10, is_all_day: 0, description: '', audiences: [] },
				{ booking_date: '2026-06-15', status: 'cancelled', start_time: '13:00:00', end_time: '14:00:00', environment_id: 10, is_all_day: 0, description: '', audiences: [] },
			],
			holidays: [],
			closed_weekdays: [],
		});
		api.renderCalendar();
		await flush();
		// 2 active → plural custom label "Aulas".
		const badge = window.$('.ffc-day[data-date="2026-06-15"] .ffc-badge-bookings');
		expect(badge.length).toBe(1);
		expect(badge.text()).toContain('2 Aulas');
	});

	it('renders a singular booking label for a single active booking', async () => {
		api.state.config = { schedules: [{ bookingLabelSingular: 'Aula', bookingLabelPlural: 'Aulas' }] };
		api.state.currentDate = new Date(2026, 5, 1);
		window.FFC.rest.mockResolvedValue({
			bookings: [
				{ booking_date: '2026-06-16', status: 'active', start_time: '09:00:00', end_time: '10:00:00', environment_id: 10, is_all_day: 0, description: '', audiences: [] },
			],
			holidays: [],
			closed_weekdays: [],
		});
		api.renderCalendar();
		await flush();
		expect(window.$('.ffc-day[data-date="2026-06-16"] .ffc-badge-bookings').text()).toContain('1 Aula');
	});

	it('marks future days outside the schedule booking window as not available', async () => {
		// futureDaysLimit very small → a day far in the future is excluded.
		api.state.config = { schedules: [{ id: 1, futureDaysLimit: 1, environments: [] }] };
		api.state.selectedSchedule = 1;
		// Use a fixed "now" so the window math is deterministic.
		const future = new Date();
		future.setMonth(future.getMonth() + 2);
		api.state.currentDate = future;
		api.renderCalendar();
		await flush();
		// No current-month, non-past day should be available given the 1-day window.
		expect(window.$('.ffc-day.ffc-available').length).toBe(0);
	});

	it('uses the minimum future-days limit across schedules when none is selected', async () => {
		api.state.config = { schedules: [
			{ id: 1, futureDaysLimit: 60, environments: [] },
			{ id: 2, futureDaysLimit: 1, environments: [] },
		] };
		api.state.selectedSchedule = 0;
		const future = new Date();
		future.setMonth(future.getMonth() + 3);
		api.state.currentDate = future;
		api.renderCalendar();
		await flush();
		expect(window.$('.ffc-day.ffc-available').length).toBe(0);
	});

	it('aborts a stale fetch when a newer one supersedes it', async () => {
		api.state.config = { schedules: [] };
		api.state.currentDate = new Date(2026, 5, 1);
		let resolveFirst;
		window.FFC.rest.mockReturnValueOnce(new Promise((res) => { resolveFirst = res; }));
		api.renderCalendar(); // fetchId = 1, pending
		// Second render bumps fetchId to 2 and resolves immediately.
		window.FFC.rest.mockResolvedValue({ bookings: [], holidays: [], closed_weekdays: [] });
		api.renderCalendar();
		await flush();
		// Now resolve the stale first fetch — it should be ignored (no throw).
		resolveFirst({ bookings: [{ booking_date: '2026-06-01', status: 'active', start_time: '09:00:00', end_time: '10:00:00', environment_id: 10, is_all_day: 0, description: '', audiences: [] }], holidays: [], closed_weekdays: [] });
		await flush();
		expect(() => api.state.bookings).not.toThrow();
	});

	it('passes schedule_id and environment_id params when filters are set', async () => {
		api.state.config = { schedules: [] };
		api.state.currentDate = new Date(2026, 5, 1);
		api.state.selectedSchedule = 7;
		api.state.selectedEnvironment = 3;
		api.renderCalendar();
		await flush();
		const opts = window.FFC.rest.mock.calls[0][1];
		expect(opts.data.schedule_id).toBe(7);
		expect(opts.data.environment_id).toBe(3);
	});

	it('still completes rendering when the REST fetch rejects', async () => {
		api.state.config = { schedules: [] };
		api.state.currentDate = new Date(2026, 5, 1);
		window.FFC.rest.mockRejectedValue(new Error('net'));
		api.renderCalendar();
		await flush();
		// Grid is unaffected (header still set, no events).
		expect(window.$('.ffc-current-month').text()).toBe('June 2026');
	});
});

describe('ffc-audience-calendar — renderEventList', () => {
	async function renderWith(payload) {
		api.state.config = {
			schedules: [{ environments: [{ id: 10, name: 'Env A', color: '#abc' }] }],
			audiences: [{ id: 1, name: 'Parent', children: [{ id: 11, name: 'Sub' }] }],
		};
		api.state.currentDate = new Date(2026, 5, 1);
		window.FFC.rest.mockResolvedValue(payload);
		api.renderCalendar();
		await flush();
	}

	it('shows the no-events message for an empty month', async () => {
		await renderWith({ bookings: [], holidays: [], closed_weekdays: [] });
		expect(window.$('#ffc-event-list-content .ffc-no-events').text()).toBe('No events this month.');
	});

	it('renders timed + all-day bookings with date group headers', async () => {
		await renderWith({
			bookings: [
				{ booking_date: '2026-06-10', status: 'active', is_all_day: 0, start_time: '09:00:00', end_time: '10:00:00', environment_id: 10, environment_name: 'Env A', description: 'Hello', audiences: [] },
				{ booking_date: '2026-06-10', status: 'active', is_all_day: 1, start_time: '00:00:00', end_time: '23:59:00', environment_id: 10, environment_name: 'Env A', description: '', audiences: [] },
				{ booking_date: '2026-06-12', status: 'cancelled', is_all_day: 0, start_time: '08:00:00', end_time: '09:00:00', environment_id: 10, environment_name: 'Env A', description: '', audiences: [] },
			],
			holidays: [],
			closed_weekdays: [],
		});
		const html = window.$('#ffc-event-list-content').html();
		// Cancelled booking excluded; two active items rendered.
		expect(window.$('#ffc-event-list-content .ffc-event-list-item').length).toBe(2);
		expect(html).toContain('09:00 - 10:00');
		expect(html).toContain('All Day');
		// Date group header present.
		expect(window.$('#ffc-event-list-content .ffc-event-list-date').length).toBeGreaterThanOrEqual(1);
	});

	it('truncates long descriptions to 57 chars + ellipsis', async () => {
		const long = 'x'.repeat(100);
		await renderWith({
			bookings: [
				{ booking_date: '2026-06-10', status: 'active', is_all_day: 0, start_time: '09:00:00', end_time: '10:00:00', environment_id: 10, environment_name: 'Env A', description: long, audiences: [] },
			],
			holidays: [],
			closed_weekdays: [],
		});
		const desc = window.$('#ffc-event-list-content .ffc-event-list-desc').text();
		expect(desc.endsWith('...')).toBe(true);
		expect(desc.length).toBe(60);
	});

	it('renders holiday events sorted before bookings on the same date', async () => {
		await renderWith({
			bookings: [
				{ booking_date: '2026-06-10', status: 'active', is_all_day: 0, start_time: '09:00:00', end_time: '10:00:00', environment_id: 10, environment_name: 'Env A', description: '', audiences: [] },
			],
			holidays: [{ holiday_date: '2026-06-10', description: 'Feriado X' }],
			closed_weekdays: [],
		});
		const items = window.$('#ffc-event-list-content .ffc-event-list-item');
		// Holiday item comes first within the date group.
		expect(items.eq(0).text()).toContain('Holiday');
		expect(window.$('#ffc-event-list-content').html()).toContain('Feriado X');
	});

	it('renders few audience tags individually and clicking an item opens the day modal', async () => {
		await renderWith({
			bookings: [
				{ booking_date: '2026-06-10', status: 'active', is_all_day: 0, start_time: '09:00:00', end_time: '10:00:00', environment_id: 10, environment_name: 'Env A', description: '', audiences: [{ id: 11, name: 'Sub', color: '#f00' }] },
			],
			holidays: [],
			closed_weekdays: [],
		});
		expect(window.$('#ffc-event-list-content .ffc-audience-tag-sm').length).toBe(1);
		window.$('#ffc-event-list-content .ffc-event-list-item').first().trigger('click');
		expect(api.openDayModal).toHaveBeenCalledWith('2026-06-10');
	});

	it('sorts events across multiple dates ascending (drives both comparator branches)', async () => {
		await renderWith({
			bookings: [
				// Deliberately out of date order so the comparator exercises both a<b and a>b.
				{ booking_date: '2026-06-05', status: 'active', is_all_day: 0, start_time: '09:00:00', end_time: '10:00:00', environment_id: 10, environment_name: 'Env A', description: '', audiences: [] },
				{ booking_date: '2026-06-20', status: 'active', is_all_day: 0, start_time: '09:00:00', end_time: '10:00:00', environment_id: 10, environment_name: 'Env A', description: '', audiences: [] },
				{ booking_date: '2026-06-12', status: 'active', is_all_day: 0, start_time: '08:00:00', end_time: '09:00:00', environment_id: 10, environment_name: 'Env A', description: '', audiences: [] },
			],
			holidays: [],
			closed_weekdays: [],
		});
		// Three distinct date groups, rendered ascending after the sort.
		const dates = window.$('#ffc-event-list-content .ffc-event-list-date');
		expect(dates.length).toBe(3);
	});

	it('collapses to a "multiple audiences" badge when more than two display audiences', async () => {
		api.state.config = {
			schedules: [{ environments: [{ id: 10, name: 'Env A', color: '#abc' }] }],
			audiences: [],
		};
		api.state.currentDate = new Date(2026, 5, 1);
		window.FFC.rest.mockResolvedValue({
			bookings: [
				{ booking_date: '2026-06-10', status: 'active', is_all_day: 0, start_time: '09:00:00', end_time: '10:00:00', environment_id: 10, environment_name: 'Env A', description: '',
				  audiences: [
					  { id: 1, name: 'A', color: '#1' }, { id: 2, name: 'B', color: '#2' },
					  { id: 3, name: 'C', color: '#3' }, { id: 4, name: 'D', color: '#4' },
				  ] },
			],
			holidays: [],
			closed_weekdays: [],
		});
		api.renderCalendar();
		await flush();
		const tags = window.$('#ffc-event-list-content .ffc-audience-tag-sm');
		expect(tags.length).toBe(1);
		expect(tags.text()).toContain('Multiple audiences (4)');
	});
});
