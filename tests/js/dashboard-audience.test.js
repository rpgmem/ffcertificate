// Render tests for the Audience bookings panel
// (assets/js/ffc-user-dashboard-audience.js).
//
// The panel reads from #tab-audience and writes a filter bar + one
// table per section (upcoming / past / cancelled). Each row carries
// a "audiences" tag list. Tests cover: empty state, audience-tag
// rendering, sectioning, row classes, and filter-by-search.
//
// Part of S4 of #163.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { installDashboardFixtures, loadDashboardCore, loadPanel, flushPromises } from './dashboard-fixtures.js';

beforeAll(() => {
	installDashboardFixtures();
	Object.assign(window.ffcDashboard.strings, {
		upcoming: 'Upcoming',
		past: 'Past',
		environment: 'Environment',
		time: 'Time',
		description: 'Description',
		audiences: 'Audiences',
		noAudienceBookings: 'No audience bookings',
		noPermission: 'No permission',
	});
	window.ffcDashboard.restUrl = 'https://x.test/wp-json/ffc/v1/';
	window.ffcDashboard.nonce = 'rest-nonce';
	loadDashboardCore();
	loadPanel('cal-export');
	loadPanel('audience');
});

beforeEach(() => {
	document.getElementById('tab-audience').innerHTML = '';
	window.localStorage.setItem('ffc_page_size', '25');
});

const panel = () => window.FFCDashboard.panels.audience;

function makeBooking(over = {}) {
	return Object.assign({
		id: Math.random(),
		environment_name: 'Main hall',
		schedule_name: '',
		booking_date: '01/01/2999',
		booking_date_raw: '2999-01-01',
		start_time: '10:00',
		end_time: '11:00',
		description: 'A booking',
		is_past: false,
		status: 'confirmed',
		audiences: [],
	}, over);
}

describe('FFCDashboard.panels.audience.render', () => {
	it('renders the empty state when there are no bookings', () => {
		panel().render([], 1);
		const container = document.getElementById('tab-audience');
		expect(container.querySelector('.ffc-empty-state')).not.toBeNull();
		expect(container.textContent).toContain('No audience bookings');
		expect(container.querySelector('table')).toBeNull();
	});

	it('renders one row per booking with environment + description columns', () => {
		panel().render([
			makeBooking({ environment_name: 'Room A', description: 'Yoga' }),
			makeBooking({ environment_name: 'Room B', description: 'Pilates' }),
		], 1);
		const rows = document.querySelectorAll('#tab-audience table tbody tr');
		expect(rows.length).toBe(2);
		expect(rows[0].textContent).toContain('Room A');
		expect(rows[0].textContent).toContain('Yoga');
		expect(rows[1].textContent).toContain('Pilates');
	});

	it('renders an .ffc-audience-tag chip per audience', () => {
		panel().render([
			makeBooking({
				audiences: [
					{ name: 'Adults',   color: '#aa0000' },
					{ name: 'Children', color: '#00aa00' },
					{ name: 'Seniors',  color: '#0000aa' },
				],
			}),
		], 1);
		const tags = document.querySelectorAll('#tab-audience .ffc-audience-tag');
		expect(tags.length).toBe(3);
		expect(tags[0].textContent).toBe('Adults');
		expect(tags[1].textContent).toBe('Children');
	});

	it('splits into Upcoming / Past / Cancelled sections', () => {
		panel().render([
			makeBooking({ is_past: false, status: 'confirmed' }),
			makeBooking({ is_past: true,  status: 'confirmed' }),
			makeBooking({ is_past: false, status: 'cancelled' }),
		], 1);
		const headers = Array.from(document.querySelectorAll('#tab-audience h3')).map((h) => h.textContent);
		expect(headers).toEqual(['Upcoming', 'Past', 'Cancelled']);
	});

	it("applies 'past-row' and 'cancelled-row' classes appropriately", () => {
		panel().render([
			makeBooking({ is_past: true,  status: 'confirmed' }),
			makeBooking({ is_past: false, status: 'cancelled' }),
		], 1);
		expect(document.querySelectorAll('#tab-audience tr.past-row').length).toBe(1);
		expect(document.querySelectorAll('#tab-audience tr.cancelled-row').length).toBe(1);
	});

	it('filters by search query (environment / schedule / description substring)', () => {
		const bookings = [
			makeBooking({ environment_name: 'Alpha hall', description: 'Morning yoga' }),
			makeBooking({ environment_name: 'Beta hall',  description: 'Evening pilates' }),
		];
		// First render to materialise the filter bar.
		panel().render(bookings, 1);
		document.querySelector('#tab-audience .ffc-filter-search').value = 'pilates';
		panel().render(bookings, 1);
		const rows = document.querySelectorAll('#tab-audience table tbody tr');
		expect(rows.length).toBe(1);
		expect(rows[0].textContent).toContain('Beta hall');
	});

	it('renders the schedule_name sub-label when present', () => {
		panel().render([
			makeBooking({ environment_name: 'Room A', schedule_name: 'Morning slot' }),
		], 1);
		const cell = document.querySelector('#tab-audience table tbody tr td');
		expect(cell.textContent).toContain('Room A');
		expect(cell.querySelector('small')).not.toBeNull();
		expect(cell.textContent).toContain('Morning slot');
	});

	it('filters out rows outside the from/to date range', () => {
		const bookings = [
			makeBooking({ booking_date_raw: '2026-01-01', environment_name: 'EarlyHall' }),
			makeBooking({ booking_date_raw: '2026-06-15', environment_name: 'MidHall' }),
			makeBooking({ booking_date_raw: '2026-12-31', environment_name: 'LateHall' }),
		];
		panel().render(bookings, 1);
		document.querySelector('#tab-audience .ffc-filter-from').value = '2026-03-01';
		document.querySelector('#tab-audience .ffc-filter-to').value = '2026-09-01';
		panel().render(bookings, 1);
		const rows = document.querySelectorAll('#tab-audience table tbody tr');
		expect(rows.length).toBe(1);
		expect(rows[0].textContent).toContain('MidHall');
	});
});

// ----------------------------------------------------------------------
// load() — guard / AJAX flow
// ----------------------------------------------------------------------

describe('FFCDashboard.panels.audience.load', () => {
	afterEach(() => {
		vi.restoreAllMocks();
		panel().state = null;
		delete window.ffcDashboard.viewAsUserId;
		delete window.ffcDashboard.canViewAudienceBookings;
	});

	it('bails when #tab-audience is missing', async () => {
		document.getElementById('tab-audience').remove();
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
		document.getElementById('ffc-dashboard').insertAdjacentHTML(
			'beforeend',
			'<div id="tab-audience" class="ffc-tab-content"></div>'
		);
	});

	it('shows the noPermission notice when canViewAudienceBookings is false', async () => {
		window.ffcDashboard.canViewAudienceBookings = false;
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
		expect(document.getElementById('tab-audience').innerHTML).toContain('No permission');
	});

	it('short-circuits when state is already populated', async () => {
		panel().state = [];
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
	});

	it('GETs /user/audience-bookings, sets X-WP-Nonce, and stores the response on state', async () => {
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			const xhr = { setRequestHeader: vi.fn() };
			opts.beforeSend(xhr);
			expect(xhr.setRequestHeader).toHaveBeenCalledWith('X-WP-Nonce', 'rest-nonce');
			opts.success({ bookings: [makeBooking({ environment_name: 'LoadedHall' })] });
			return {};
		});

		panel().load();
		await flushPromises();

		const opts = ajaxSpy.mock.calls[0][0];
		expect(opts.url).toBe('https://x.test/wp-json/ffc/v1/user/audience-bookings');
		expect(panel().state.length).toBe(1);
		expect(document.getElementById('tab-audience').textContent).toContain('LoadedHall');
	});

	it('appends viewAsUserId query string when impersonating', async () => {
		window.ffcDashboard.viewAsUserId = 42;
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy.mock.calls[0][0].url).toBe('https://x.test/wp-json/ffc/v1/user/audience-bookings?viewAsUserId=42');
	});

	it('defaults state to [] when response.bookings is missing', async () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({});
			return {};
		});

		panel().load();
		await flushPromises();

		expect(panel().state).toEqual([]);
		expect(document.querySelector('#tab-audience .ffc-empty-state')).not.toBeNull();
	});

	it('renders the error notice when the AJAX call fails', async () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error();
			return {};
		});

		panel().load();
		await flushPromises();

		expect(document.getElementById('tab-audience').innerHTML).toContain('Error');
	});
});
