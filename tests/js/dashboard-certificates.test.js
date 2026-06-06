// Render tests for the Certificates panel
// (assets/js/ffc-user-dashboard-certificates.js).
//
// The panel reads from #tab-certificates and writes a filter bar + a
// table + a pagination block. Tests cover: empty state, basic rendering,
// pagination, filter-search, magic-link rendering (PDF button visibility),
// and consent badge classes.
//
// Part of S4 of #163.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { installDashboardFixtures, loadDashboardCore, loadPanel, flushPromises } from './dashboard-fixtures.js';

beforeAll(() => {
	installDashboardFixtures();
	Object.assign(window.ffcDashboard.strings, {
		noPermission: 'No permission',
	});
	window.ffcDashboard.restUrl = 'https://x.test/wp-json/ffc/v1/';
	window.ffcDashboard.nonce = 'rest-nonce';
	loadDashboardCore();
	loadPanel('certificates');
});

beforeEach(() => {
	// Reset the container between tests — pagination / filter state
	// leaks via input values otherwise.
	document.getElementById('tab-certificates').innerHTML = '';
	// Force a known page size so pagination tests are deterministic.
	window.localStorage.setItem('ffc_page_size', '10');
});

const panel = () => window.FFCDashboard.panels.certificates;

function makeCert(over = {}) {
	return Object.assign({
		form_title: 'Sample event',
		submission_date: '01/01/2026',
		submission_date_raw: '2026-01-01',
		consent_given: 1,
		email: 'user@example.com',
		auth_code: 'CODE123',
		magic_link: 'https://example.com/cert/CODE123',
	}, over);
}

describe('FFCDashboard.panels.certificates.render', () => {
	it('renders the empty state when there are no certificates', () => {
		panel().render([], 1);
		const container = document.getElementById('tab-certificates');
		expect(container.querySelector('.ffc-empty-state')).not.toBeNull();
		expect(container.textContent).toContain('No certificates');
		expect(container.querySelector('table')).toBeNull();
	});

	it('renders one row per certificate', () => {
		const certs = [makeCert({ auth_code: 'A' }), makeCert({ auth_code: 'B' }), makeCert({ auth_code: 'C' })];
		panel().render(certs, 1);
		const rows = document.querySelectorAll('#tab-certificates table tbody tr');
		expect(rows.length).toBe(3);
	});

	it('paginates to page size 10 with 25 certificates across 3 pages', () => {
		const certs = Array.from({ length: 25 }, (_, i) => makeCert({ auth_code: 'C' + i }));
		panel().render(certs, 1);
		expect(document.querySelectorAll('#tab-certificates table tbody tr').length).toBe(10);

		panel().render(certs, 3);
		// Page 3 of 25 / 10 = 5 remaining rows.
		expect(document.querySelectorAll('#tab-certificates table tbody tr').length).toBe(5);
	});

	it('renders the Download PDF button when magic_link is present, omits it otherwise', () => {
		panel().render([makeCert({ magic_link: 'https://x.test/y' }), makeCert({ magic_link: '' })], 1);
		const buttons = document.querySelectorAll('#tab-certificates .ffc-btn-pdf');
		expect(buttons.length).toBe(1);
		expect(buttons[0].getAttribute('href')).toBe('https://x.test/y');
	});

	it('escapes HTML in text cells so injected markup cannot execute', () => {
		panel().render([makeCert({ form_title: '<img src=x onerror=alert(1)>', email: '<b>e</b>@x.test' })], 1);
		const container = document.getElementById('tab-certificates');
		// The payload must not materialise as real DOM nodes…
		expect(container.querySelector('img')).toBeNull();
		expect(container.querySelector('table b')).toBeNull();
		// …it survives as inert text instead.
		expect(container.textContent).toContain('<img src=x onerror=alert(1)>');
	});

	it('keeps a quote-breakout magic_link inside the href attribute', () => {
		panel().render([makeCert({ magic_link: 'https://x.test/"><img src=x onerror=alert(1)>' })], 1);
		const buttons = document.querySelectorAll('#tab-certificates .ffc-btn-pdf');
		// escAttr() neutralises the closing quote: exactly one anchor and no
		// smuggled <img> sibling in the actions cell.
		expect(buttons.length).toBe(1);
		expect(document.querySelector('#tab-certificates td img')).toBeNull();
	});

	it('adds rel="noopener noreferrer" to the target=_blank PDF link', () => {
		panel().render([makeCert({ magic_link: 'https://x.test/y' })], 1);
		const link = document.querySelector('#tab-certificates .ffc-btn-pdf');
		expect(link.getAttribute('target')).toBe('_blank');
		expect(link.getAttribute('rel')).toBe('noopener noreferrer');
	});

	it("applies 'consent-yes' / 'consent-no' classes based on consent_given", () => {
		panel().render([makeCert({ consent_given: 1, auth_code: 'A' }), makeCert({ consent_given: 0, auth_code: 'B' })], 1);
		expect(document.querySelectorAll('#tab-certificates .consent-yes').length).toBe(1);
		expect(document.querySelectorAll('#tab-certificates .consent-no').length).toBe(1);
	});

	it('always renders the filter bar, even on the empty state', () => {
		panel().render([], 1);
		expect(document.querySelector('#tab-certificates .ffc-filter-bar')).not.toBeNull();

		panel().render([makeCert()], 1);
		expect(document.querySelector('#tab-certificates .ffc-filter-bar')).not.toBeNull();
	});

	it('filters by search query (form_title / email / auth_code substring)', () => {
		const certs = [
			makeCert({ form_title: 'Alpha course',   email: 'a@example.com', auth_code: 'AAA' }),
			makeCert({ form_title: 'Beta workshop',  email: 'b@example.com', auth_code: 'BBB' }),
			makeCert({ form_title: 'Gamma seminar',  email: 'g@example.com', auth_code: 'GGG' }),
		];
		// First render to materialise the filter bar.
		panel().render(certs, 1);
		// Populate the search input the same way the user would, then
		// re-render — the panel reads the input value on render.
		document.querySelector('#tab-certificates .ffc-filter-search').value = 'beta';
		panel().render(certs, 1);
		const rows = document.querySelectorAll('#tab-certificates table tbody tr');
		expect(rows.length).toBe(1);
		expect(rows[0].textContent).toContain('Beta');
	});

	it('filters out rows outside the from/to date range', () => {
		const certs = [
			makeCert({ submission_date_raw: '2026-01-01', auth_code: 'EARLY' }),
			makeCert({ submission_date_raw: '2026-06-15', auth_code: 'MID' }),
			makeCert({ submission_date_raw: '2026-12-31', auth_code: 'LATE' }),
		];
		panel().render(certs, 1);
		document.querySelector('#tab-certificates .ffc-filter-from').value = '2026-03-01';
		document.querySelector('#tab-certificates .ffc-filter-to').value = '2026-09-01';
		panel().render(certs, 1);
		const rows = document.querySelectorAll('#tab-certificates table tbody tr');
		expect(rows.length).toBe(1);
		expect(rows[0].textContent).toContain('MID');
	});
});

// ----------------------------------------------------------------------
// load() — guard / AJAX flow
// ----------------------------------------------------------------------

describe('FFCDashboard.panels.certificates.load', () => {
	afterEach(() => {
		vi.restoreAllMocks();
		panel().state = null;
		delete window.ffcDashboard.viewAsUserId;
		delete window.ffcDashboard.canViewCertificates;
	});

	it('bails when #tab-certificates is missing', async () => {
		document.getElementById('tab-certificates').remove();
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
		document.getElementById('ffc-dashboard').insertAdjacentHTML(
			'beforeend',
			'<div id="tab-certificates" class="ffc-tab-content"></div>'
		);
	});

	it('shows the noPermission notice when canViewCertificates is false', async () => {
		window.ffcDashboard.canViewCertificates = false;
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
		expect(document.getElementById('tab-certificates').innerHTML).toContain('No permission');
	});

	it('short-circuits when state is already populated', async () => {
		panel().state = [];
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
	});

	it('GETs /user/certificates, sets X-WP-Nonce, and stores the response on state', async () => {
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			const xhr = { setRequestHeader: vi.fn() };
			opts.beforeSend(xhr);
			expect(xhr.setRequestHeader).toHaveBeenCalledWith('X-WP-Nonce', 'rest-nonce');
			opts.success({ certificates: [makeCert({ auth_code: 'X' })] });
			return {};
		});

		panel().load();
		await flushPromises();

		const opts = ajaxSpy.mock.calls[0][0];
		expect(opts.url).toBe('https://x.test/wp-json/ffc/v1/user/certificates');
		expect(panel().state.length).toBe(1);
		expect(document.getElementById('tab-certificates').textContent).toContain('X');
	});

	it('appends viewAsUserId query string when impersonating', async () => {
		window.ffcDashboard.viewAsUserId = 99;
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy.mock.calls[0][0].url).toBe('https://x.test/wp-json/ffc/v1/user/certificates?viewAsUserId=99');
	});

	it('defaults state to [] when response.certificates is missing', async () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({});
			return {};
		});

		panel().load();
		await flushPromises();

		expect(panel().state).toEqual([]);
		expect(document.querySelector('#tab-certificates .ffc-empty-state')).not.toBeNull();
	});

	it('renders the error notice when the AJAX call fails', async () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error();
			return {};
		});

		panel().load();
		await flushPromises();

		expect(document.getElementById('tab-certificates').innerHTML).toContain('Error');
	});
});
