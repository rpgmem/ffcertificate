// Per-section pagination of the two calendar tabs (#1836).
//
// The Personal Calendar (appointments) and Group Calendar (audience) tabs
// page each section on its own, with fixed sizes -- Upcoming 5, Past 3,
// Cancelled 2 -- through one pager builder in the core helpers. The
// certificates tab keeps the global pager and its per-page selector.
//
// The core's delegated handlers are bound once here, so a click on a page
// button goes through the same path it takes in the browser.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { installDashboardFixtures, loadDashboardCore, loadPanel } from './dashboard-fixtures.js';

beforeAll(() => {
	installDashboardFixtures();
	Object.assign(window.ffcDashboard.strings, {
		upcoming: 'Upcoming',
		past: 'Past',
		cancelled: 'Cancelled',
		calendar: 'Calendar',
		time: 'Time',
		environment: 'Environment',
		description: 'Description',
		audiences: 'Audiences',
		noAudienceBookings: 'No audience bookings',
		sectionRange: '{start}–{end} of {total}',
		sectionPagination: 'Pagination: {section}',
		pageNumber: 'Page {page}',
		previousPage: 'Previous page',
		nextPage: 'Next page',
	});
	loadDashboardCore();
	loadPanel('cal-export');
	loadPanel('appointments');
	loadPanel('audience');
	loadPanel('certificates');
	window.FFCDashboard.bindEvents();
});

beforeEach(() => {
	['appointments', 'audience', 'certificates'].forEach((tab) => {
		document.getElementById('ffc-tabpanel-' + tab).innerHTML = '';
	});
	window.localStorage.setItem('ffc_page_size', '10');
});

const helpers = () => window.FFCDashboard.helpers;
const FAR_PAST = '2000-01-01';
const FAR_FUTURE = '2999-12-31';

function appts(n, over) {
	const out = [];
	for (let i = 1; i <= n; i++) {
		out.push(Object.assign({
			id: over.prefix + i,
			calendar_title: over.prefix + i,
			appointment_date: '01/01/2999',
			appointment_date_raw: FAR_FUTURE,
			start_time: '10:00',
			start_time_raw: '10:00',
			end_time: '11:00',
			end_time_raw: '11:00',
			status: 'confirmed',
			status_label: 'Confirmed',
			receipt_url: '',
		}, over.row));
	}
	return out;
}

function bookings(n, over) {
	const out = [];
	for (let i = 1; i <= n; i++) {
		out.push(Object.assign({
			id: over.prefix + i,
			environment_name: over.prefix + i,
			schedule_name: '',
			booking_date: '01/01/2999',
			booking_date_raw: '2999-01-01',
			start_time: '10:00',
			end_time: '11:00',
			description: '',
			is_past: false,
			status: 'confirmed',
			audiences: [],
		}, over.row));
	}
	return out;
}

const TABS = {
	appointments: {
		data: () => [].concat(
			appts(12, { prefix: 'U' }),
			appts(7, { prefix: 'P', row: { status: 'completed', appointment_date_raw: FAR_PAST, is_past: true } }),
			appts(3, { prefix: 'C', row: { status: 'cancelled' } }),
		),
	},
	audience: {
		data: () => [].concat(
			bookings(12, { prefix: 'U' }),
			bookings(7, { prefix: 'P', row: { is_past: true } }),
			bookings(3, { prefix: 'C', row: { status: 'cancelled' } }),
		),
	},
};

function section(tab, key) {
	return document.querySelector('#ffc-tabpanel-' + tab + ' .ffc-dashboard-section[data-section="' + key + '"]');
}

function rows(tab, key) {
	return Array.from(section(tab, key).querySelectorAll('tbody tr')).map((tr) => tr.cells[0].textContent);
}

function click(tab, key, page) {
	window.$(section(tab, key)).find('.ffc-section-page-btn[data-page="' + page + '"]').not(':disabled').first().trigger('click');
}

describe('FFCDashboard.helpers section pager', () => {
	it('declares the fixed page sizes Upcoming 5, Past 3, Cancelled 2', () => {
		expect(helpers().SECTION_PAGE_SIZES).toEqual({ upcoming: 5, past: 3, cancelled: 2 });
		expect(helpers().sectionPageSize('past')).toBe(3);
	});

	it('draws nothing when the section fits on one page', () => {
		expect(helpers().buildSectionPager(5, 1, 5, 'appointments', 'upcoming', 'Upcoming')).toBe('');
		expect(helpers().buildSectionPager(0, 1, 2, 'appointments', 'cancelled', 'Cancelled')).toBe('');
	});

	it('draws a labelled nav with the range, numbered pages and disabled ends', () => {
		document.body.insertAdjacentHTML('beforeend', '<div id="pager-probe">' + helpers().buildSectionPager(12, 1, 5, 'appointments', 'upcoming', 'Upcoming') + '</div>');
		const probe = document.getElementById('pager-probe');
		const nav = probe.querySelector('nav.ffc-section-pager');
		expect(nav.getAttribute('aria-label')).toBe('Pagination: Upcoming');
		expect(probe.querySelector('.ffc-section-pager-range').textContent).toBe('1–5 of 12');

		const buttons = Array.from(probe.querySelectorAll('.ffc-section-page-btn'));
		expect(buttons.map((b) => b.textContent)).toEqual(['‹', '1', '2', '3', '›']);
		expect(buttons[0].disabled).toBe(true);
		expect(buttons[0].getAttribute('aria-label')).toBe('Previous page');
		expect(buttons[4].disabled).toBe(false);

		const current = probe.querySelector('[aria-current="page"]');
		expect(current.textContent).toBe('1');
		// A family button (#1874); the sheet paints the current key by aria-current.
		expect(current.className).toBe('ffc-btn ffc-btn-neutral ffc-section-page-btn');
		expect(current.getAttribute('aria-label')).toBe('Page 1');
		probe.remove();
	});

	it('disables next on the last page and clamps a page past the end', () => {
		document.body.insertAdjacentHTML('beforeend', '<div id="pager-probe">' + helpers().buildSectionPager(12, 9, 5, 'appointments', 'upcoming', 'Upcoming') + '</div>');
		const probe = document.getElementById('pager-probe');
		expect(probe.querySelector('.ffc-section-pager-range').textContent).toBe('11–12 of 12');
		expect(probe.querySelector('[aria-current="page"]').textContent).toBe('3');
		const buttons = probe.querySelectorAll('.ffc-section-page-btn');
		expect(buttons[buttons.length - 1].disabled).toBe(true);
		probe.remove();
	});

	it('elides the middle pages past the button budget', () => {
		expect(helpers().sectionPageNumbers(1, 7)).toEqual([1, 2, 3, 4, 5, 6, 7]);
		expect(helpers().sectionPageNumbers(5, 10)).toEqual([1, null, 4, 5, 6, null, 10]);
		expect(helpers().sectionPageNumbers(1, 10)).toEqual([1, 2, null, 10]);
		expect(helpers().sectionPageNumbers(10, 10)).toEqual([1, null, 9, 10]);
	});

	it('writes the heading with the section count in a muted small', () => {
		const html = helpers().buildSectionHeading('Cancelled', 3);
		expect(html).toBe('<h3 class="ffc-dashboard-section-title">Cancelled <small class="ffc-dashboard-section-count">(3)</small></h3>');
	});
});

Object.keys(TABS).forEach((tab) => {
	const panel = () => window.FFCDashboard.panels[tab];

	describe(tab + ' tab: per-section pagination', () => {
		it('shows 5 upcoming, 3 past and 2 cancelled rows on the first page', () => {
			panel().render(TABS[tab].data(), 1);
			expect(rows(tab, 'upcoming')).toEqual(['U1', 'U2', 'U3', 'U4', 'U5']);
			expect(rows(tab, 'past')).toEqual(['P1', 'P2', 'P3']);
			expect(rows(tab, 'cancelled')).toEqual(['C1', 'C2']);
		});

		it('gives each section its own pager and no global pager or per-page selector', () => {
			panel().render(TABS[tab].data(), 1);
			const container = document.getElementById('ffc-tabpanel-' + tab);
			expect(container.querySelector('.ffc-pagination')).toBeNull();
			expect(container.querySelector('.ffc-page-size-select')).toBeNull();
			['upcoming', 'past', 'cancelled'].forEach((key) => {
				expect(section(tab, key).querySelectorAll('nav.ffc-section-pager').length).toBe(1);
			});
			expect(section(tab, 'past').querySelector('.ffc-section-pager-range').textContent).toBe('1–3 of 7');
		});

		it('hides the pager of a section that fits on one page', () => {
			const data = TABS[tab].data().filter((item) => String(item.id).charAt(0) !== 'C' || item.id === 'C1');
			panel().render(data, 1);
			expect(section(tab, 'cancelled').querySelector('.ffc-section-pager')).toBeNull();
			expect(section(tab, 'upcoming').querySelector('.ffc-section-pager')).not.toBeNull();
		});

		it('shows the plural Cancelled heading with each section count', () => {
			panel().render(TABS[tab].data(), 1);
			const titles = Array.from(document.querySelectorAll('#ffc-tabpanel-' + tab + ' .ffc-dashboard-section-title')).map((h) => h.textContent);
			expect(titles).toEqual(['Upcoming (12)', 'Past (7)', 'Cancelled (3)']);
			expect(document.querySelector('#ffc-tabpanel-' + tab + ' .ffc-dashboard-section-count').textContent).toBe('(12)');
		});

		it('changes only the clicked section and keeps the other sections pages', () => {
			panel().render(TABS[tab].data(), 1);
			click(tab, 'past', 3);
			expect(rows(tab, 'past')).toEqual(['P7']);
			expect(section(tab, 'past').querySelector('[aria-current="page"]').textContent).toBe('3');
			expect(document.activeElement).toBe(section(tab, 'past').querySelector('[aria-current="page"]'));

			click(tab, 'upcoming', 2);
			expect(rows(tab, 'upcoming')).toEqual(['U6', 'U7', 'U8', 'U9', 'U10']);
			// The past section keeps its page; cancelled was never touched.
			expect(rows(tab, 'past')).toEqual(['P7']);
			expect(rows(tab, 'cancelled')).toEqual(['C1', 'C2']);
		});

		it('moves with previous and next and ignores a disabled button', () => {
			panel().render(TABS[tab].data(), 1);
			const prev = section(tab, 'cancelled').querySelector('.ffc-section-page-btn[data-page="0"]');
			window.FFCDashboard.handleSectionPage({ preventDefault() {}, currentTarget: prev });
			expect(rows(tab, 'cancelled')).toEqual(['C1', 'C2']);

			const buttons = section(tab, 'cancelled').querySelectorAll('.ffc-section-page-btn');
			window.$(buttons[buttons.length - 1]).trigger('click');
			expect(rows(tab, 'cancelled')).toEqual(['C3']);
		});

		it('resets every section to page 1 when a filter is applied or cleared', () => {
			panel().state = TABS[tab].data();
			panel().render(panel().state, 1);
			click(tab, 'upcoming', 3);
			click(tab, 'past', 2);
			expect(rows(tab, 'upcoming')).toEqual(['U11', 'U12']);

			window.$('#ffc-tabpanel-' + tab + ' .ffc-filter-apply').trigger('click');
			expect(rows(tab, 'upcoming')).toEqual(['U1', 'U2', 'U3', 'U4', 'U5']);
			expect(rows(tab, 'past')).toEqual(['P1', 'P2', 'P3']);

			click(tab, 'upcoming', 2);
			window.$('#ffc-tabpanel-' + tab + ' .ffc-filter-clear').trigger('click');
			expect(rows(tab, 'upcoming')).toEqual(['U1', 'U2', 'U3', 'U4', 'U5']);
		});

		it('gives every table of the tab the shared column classes and no inline style', () => {
			panel().render(TABS[tab].data(), 1);
			const container = document.getElementById('ffc-tabpanel-' + tab);
			const heads = Array.from(container.querySelectorAll('table')).map((t) =>
				Array.from(t.querySelectorAll('th')).map((th) => th.className).join(' '));
			expect(heads.length).toBe(3);
			expect(new Set(heads).size).toBe(1);
			expect(heads[0]).toMatch(/^ffc-col-/);
			container.querySelectorAll('table').forEach((t) => {
				expect(t.classList.contains('ffc-dashboard-section-table')).toBe(true);
			});
			expect(container.innerHTML).not.toMatch(/style="/);
		});
	});
});

describe('certificates tab keeps its global pager', () => {
	it('still draws .ffc-pagination with the per-page selector', () => {
		const certs = [];
		for (let i = 1; i <= 12; i++) {
			certs.push({
				form_title: 'E' + i,
				submission_date: '01/01/2026',
				submission_date_raw: '2026-01-01',
				consent_given: 1,
				email: 'user@example.com',
				auth_code: 'A' + i,
				magic_link: 'https://example.com/cert/A' + i,
			});
		}
		window.FFCDashboard.panels.certificates.render(certs, 1);
		const container = document.getElementById('ffc-tabpanel-certificates');
		expect(container.querySelectorAll('tbody tr').length).toBe(10);
		expect(container.querySelector('.ffc-pagination .ffc-page-size-select')).not.toBeNull();
		expect(container.querySelector('.ffc-section-pager')).toBeNull();
	});
});
