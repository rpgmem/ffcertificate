// Render tests for the Audience bookings panel
// (assets/js/ffc-user-dashboard-audience.js).
//
// The panel reads from #ffc-tabpanel-audience and writes a filter bar + one
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
	document.getElementById('ffc-tabpanel-audience').innerHTML = '';
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
		const container = document.getElementById('ffc-tabpanel-audience');
		expect(container.querySelector('.ffc-empty-state')).not.toBeNull();
		expect(container.textContent).toContain('No audience bookings');
		expect(container.querySelector('table')).toBeNull();
	});

	it('renders one row per booking with environment + description columns', () => {
		panel().render([
			makeBooking({ environment_name: 'Room A', description: 'Yoga' }),
			makeBooking({ environment_name: 'Room B', description: 'Pilates' }),
		], 1);
		const rows = document.querySelectorAll('#ffc-tabpanel-audience table tbody tr');
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
		const tags = document.querySelectorAll('#ffc-tabpanel-audience .ffc-audience-tag');
		expect(tags.length).toBe(3);
		expect(tags[0].textContent).toBe('Adults');
		expect(tags[1].textContent).toBe('Children');
	});

	// #1826: the text over an operator's colour comes from the payload's
	// `text_color` (ContrastColor::on() server-side), never a fixed white.
	it('paints each tag with the payload colour pair through .ffc-pill-row-color', () => {
		panel().render([
			makeBooking({
				schedule_name: 'Morning',
				audiences: [
					{ name: 'Pale', color: '#ffeb3b', text_color: '#000000' },
					{ name: 'Bad',  color: 'red;x', text_color: '#000000' },
				],
			}),
		], 1);
		const tags = document.querySelectorAll('#ffc-tabpanel-audience .ffc-audience-tag');
		expect(tags[0].classList.contains('ffc-pill-row-color')).toBe(true);
		expect(tags[0].getAttribute('style')).toBe('--ffc-badge-row-bg:#ffeb3b;--ffc-badge-row-text:#000000;');
		expect(tags[1].hasAttribute('style')).toBe(false);
		expect(document.querySelector('#ffc-tabpanel-audience .ffc-booking-schedule').textContent).toBe('Morning');
		const html = document.getElementById('ffc-tabpanel-audience').innerHTML;
		expect(html).not.toMatch(/color:\s*#|#fff\b|margin-top/);
	});

	it('splits into Upcoming / Past / Cancelled sections', () => {
		panel().render([
			makeBooking({ is_past: false, status: 'confirmed' }),
			makeBooking({ is_past: true,  status: 'confirmed' }),
			makeBooking({ is_past: false, status: 'cancelled' }),
		], 1);
		const headers = Array.from(document.querySelectorAll('#ffc-tabpanel-audience h3')).map((h) => h.textContent);
		// Each heading carries its section's total (#1836).
		expect(headers).toEqual(['Upcoming (1)', 'Past (1)', 'Cancelled (1)']);
	});

	it("applies 'ffc-row-past' and 'ffc-row-cancelled' classes appropriately", () => {
		panel().render([
			makeBooking({ is_past: true,  status: 'confirmed' }),
			makeBooking({ is_past: false, status: 'cancelled' }),
		], 1);
		expect(document.querySelectorAll('#ffc-tabpanel-audience tr.ffc-row-past').length).toBe(1);
		expect(document.querySelectorAll('#ffc-tabpanel-audience tr.ffc-row-cancelled').length).toBe(1);
	});

	it('filters by search query (environment / schedule / description substring)', () => {
		const bookings = [
			makeBooking({ environment_name: 'Alpha hall', description: 'Morning yoga' }),
			makeBooking({ environment_name: 'Beta hall',  description: 'Evening pilates' }),
		];
		// First render to materialise the filter bar.
		panel().render(bookings, 1);
		document.querySelector('#ffc-tabpanel-audience .ffc-filter-search').value = 'pilates';
		panel().render(bookings, 1);
		const rows = document.querySelectorAll('#ffc-tabpanel-audience table tbody tr');
		expect(rows.length).toBe(1);
		expect(rows[0].textContent).toContain('Beta hall');
	});

	it('shows a cancelled booking\'s reason, escaped (#2243)', () => {
		panel().render([
			makeBooking({ status: 'cancelled', description: 'Yoga', cancellation_reason: 'Room <b>flooded</b>' }),
		], 1);
		const reason = document.querySelector('#ffc-tabpanel-audience .ffc-booking-cancel-reason');
		expect(reason.textContent).toBe('Reason: Room <b>flooded</b>');
		expect(reason.querySelector('b')).toBeNull();
	});

	it('prints every booking field as text, never as markup (#2243)', () => {
		panel().render([
			makeBooking({
				environment_name: '<img src=x onerror=alert(1)>',
				schedule_name: '<b>Sched</b>',
				description: '<script>x</script>',
				booking_date: '<i>d</i>',
				time_label: '<u>t</u>',
			}),
		], 1);
		const row = document.querySelector('#ffc-tabpanel-audience table tbody tr');
		expect(row.querySelector('img, b, script, i, u')).toBeNull();
		expect(row.textContent).toContain('<img src=x onerror=alert(1)>');
		expect(row.textContent).toContain('<script>x</script>');
		expect(row.textContent).toContain('<u>t</u>');
	});

	it('draws no reason line for a booking without one', () => {
		panel().render([makeBooking({ description: 'Yoga' })], 1);
		expect(document.querySelector('#ffc-tabpanel-audience .ffc-booking-cancel-reason')).toBeNull();
	});

	it('renders the schedule_name sub-label when present', () => {
		panel().render([
			makeBooking({ environment_name: 'Room A', schedule_name: 'Morning slot' }),
		], 1);
		const cell = document.querySelector('#ffc-tabpanel-audience table tbody tr td');
		expect(cell.textContent).toContain('Room A');
		expect(cell.querySelector('small')).not.toBeNull();
		expect(cell.textContent).toContain('Morning slot');
	});

	it('keeps a range that started before the From date but runs into it (#1946)', () => {
		const bookings = [
			makeBooking({ booking_date_raw: '2026-02-25', last_date_raw: '2026-03-03', environment_name: 'RunningHall' }),
			makeBooking({ booking_date_raw: '2026-02-01', last_date_raw: '2026-02-02', environment_name: 'EndedHall' }),
		];
		panel().render(bookings, 1);
		document.querySelector('#ffc-tabpanel-audience .ffc-filter-from').value = '2026-03-01';
		panel().render(bookings, 1);
		const text = document.querySelector('#ffc-tabpanel-audience').textContent;
		expect(text).toContain('RunningHall');
		expect(text).not.toContain('EndedHall');
	});

	it('shows the time label and exports every day of a range (#1946)', () => {
		panel().render([
			makeBooking({
				time_label: 'From 14:00 on the first day to 10:00 on the last day',
				ics: { date: '2999-01-01', end_date: '2999-01-03' },
			}),
		], 1);
		const row = document.querySelector('#ffc-tabpanel-audience table tbody tr');
		expect(row.textContent).toContain('From 14:00 on the first day to 10:00 on the last day');
		const event = JSON.parse(row.querySelector('.ffc-cal-export-ics').getAttribute('data-event'));
		expect(event.date).toBe('2999-01-01');
		expect(event.endDate).toBe('2999-01-03');
	});

	it('filters out rows outside the from/to date range', () => {
		const bookings = [
			makeBooking({ booking_date_raw: '2026-01-01', environment_name: 'EarlyHall' }),
			makeBooking({ booking_date_raw: '2026-06-15', environment_name: 'MidHall' }),
			makeBooking({ booking_date_raw: '2026-12-31', environment_name: 'LateHall' }),
		];
		panel().render(bookings, 1);
		document.querySelector('#ffc-tabpanel-audience .ffc-filter-from').value = '2026-03-01';
		document.querySelector('#ffc-tabpanel-audience .ffc-filter-to').value = '2026-09-01';
		panel().render(bookings, 1);
		const rows = document.querySelectorAll('#ffc-tabpanel-audience table tbody tr');
		expect(rows.length).toBe(1);
		expect(rows[0].textContent).toContain('MidHall');
	});
});

// ----------------------------------------------------------------------
// load() — guard / AJAX flow
// ----------------------------------------------------------------------

// The post-event form waiting for the person (#2028).
describe('FFCDashboard.panels.audience post-event form', () => {
	afterEach(() => { panel().pendingLabel = ''; });

	const waiting = (over = {}) => Object.assign({
		form_title: 'Evaluation <b>',
		url: 'https://x.test/form/?ffc_invite=abc',
		closes_label: '15/01/2027 12:00',
	}, over);

	it('offers the person their own link and says when the form closes', () => {
		window.ffcDashboard.strings.fillPostEventForm = 'Fill in the form';
		window.ffcDashboard.strings.postEventCloses = 'Open until %s';
		panel().render([makeBooking({ is_past: true, post_event: waiting() })], 1);

		const link = document.querySelector('#ffc-tabpanel-audience a.ffc-btn-post-event');
		expect(link).not.toBeNull();
		expect(link.getAttribute('href')).toBe('https://x.test/form/?ffc_invite=abc');
		expect(link.classList.contains('ffc-btn-primary')).toBe(true);
		expect(link.textContent).toBe('Fill in the form');
		expect(document.querySelector('.ffc-post-event-closes').textContent).toBe('Open until 15/01/2027 12:00');
	});

	it('only says the form waits, escaped, when there is no link (view as user)', () => {
		window.ffcDashboard.strings.postEventWaiting = 'Form waiting: %s';
		panel().render([makeBooking({ is_past: true, post_event: waiting({ url: '' }) })], 1);

		expect(document.querySelector('.ffc-btn-post-event')).toBeNull();
		const text = document.querySelector('.ffc-post-event-waiting');
		expect(text.textContent).toBe('Form waiting: Evaluation <b>');
		expect(text.querySelector('b')).toBeNull();
	});

	it('draws nothing for a booking with no form waiting', () => {
		panel().render([makeBooking({ is_past: true, post_event: null })], 1);
		expect(document.querySelector('.ffc-post-event-pending')).toBeNull();
	});

	it('counts the forms waiting in a notice above the sections', () => {
		panel().pendingLabel = 'You have 1 certificate form to fill in.';
		panel().render([makeBooking({ is_past: true, post_event: waiting() })], 1);

		const notice = document.querySelector('#ffc-tabpanel-audience [data-ffc-notice="post-event-pending"]');
		expect(notice).not.toBeNull();
		expect(notice.textContent).toContain('You have 1 certificate form to fill in.');
	});
});

describe('FFCDashboard.panels.audience.load', () => {
	afterEach(() => {
		vi.restoreAllMocks();
		panel().state = null;
		delete window.ffcDashboard.viewAsUserId;
		delete window.ffcDashboard.canViewAudienceBookings;
	});

	it('bails when #ffc-tabpanel-audience is missing', async () => {
		document.getElementById('ffc-tabpanel-audience').remove();
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
		document.getElementById('ffc-dashboard').insertAdjacentHTML(
			'beforeend',
			'<div id="ffc-tabpanel-audience" class="ffc-tab-content"></div>'
		);
	});

	it('shows the noPermission notice when canViewAudienceBookings is false', async () => {
		window.ffcDashboard.canViewAudienceBookings = false;
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
		expect(document.getElementById('ffc-tabpanel-audience').innerHTML).toContain('No permission');
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
			opts.success({ bookings: [makeBooking({ environment_name: 'LoadedHall' })], pending_label: 'Waiting: 1' });
			return {};
		});

		panel().load();
		await flushPromises();

		const opts = ajaxSpy.mock.calls[0][0];
		expect(opts.url).toBe('https://x.test/wp-json/ffc/v1/user/audience-bookings');
		expect(panel().state.length).toBe(1);
		expect(panel().pendingLabel).toBe('Waiting: 1');
		expect(document.getElementById('ffc-tabpanel-audience').textContent).toContain('LoadedHall');
		panel().pendingLabel = '';
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
		expect(document.querySelector('#ffc-tabpanel-audience .ffc-empty-state')).not.toBeNull();
	});

	it('renders the error notice when the AJAX call fails', async () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error();
			return {};
		});

		panel().load();
		await flushPromises();

		expect(document.getElementById('ffc-tabpanel-audience').innerHTML).toContain('Error');
	});
});
