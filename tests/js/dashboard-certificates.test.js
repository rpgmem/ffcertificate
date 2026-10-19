// Render tests for the Certificates panel
// (assets/js/ffc-user-dashboard-certificates.js).
//
// The panel reads from #ffc-tabpanel-certificates and writes a filter bar + a
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
	// FFCDashboard.init() normally binds the panel's delegated handlers.
	window.FFCDashboard.panels.certificates.bindEvents();
});

beforeEach(() => {
	// Reset the container between tests — pagination / filter state
	// leaks via input values otherwise.
	document.getElementById('ffc-tabpanel-certificates').innerHTML = '';
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
		const container = document.getElementById('ffc-tabpanel-certificates');
		expect(container.querySelector('.ffc-empty-state')).not.toBeNull();
		expect(container.textContent).toContain('No certificates');
		expect(container.querySelector('table')).toBeNull();
	});

	it('renders one row per certificate', () => {
		const certs = [makeCert({ auth_code: 'A' }), makeCert({ auth_code: 'B' }), makeCert({ auth_code: 'C' })];
		panel().render(certs, 1);
		const rows = document.querySelectorAll('#ffc-tabpanel-certificates table tbody tr');
		expect(rows.length).toBe(3);
	});

	it('paginates to page size 10 with 25 certificates across 3 pages', () => {
		const certs = Array.from({ length: 25 }, (_, i) => makeCert({ auth_code: 'C' + i }));
		panel().render(certs, 1);
		expect(document.querySelectorAll('#ffc-tabpanel-certificates table tbody tr').length).toBe(10);

		panel().render(certs, 3);
		// Page 3 of 25 / 10 = 5 remaining rows.
		expect(document.querySelectorAll('#ffc-tabpanel-certificates table tbody tr').length).toBe(5);
	});

	it('renders the Download PDF button when magic_link is present, omits it otherwise', () => {
		panel().render([makeCert({ magic_link: 'https://x.test/y' }), makeCert({ magic_link: '' })], 1);
		const buttons = document.querySelectorAll('#ffc-tabpanel-certificates .ffc-btn-pdf');
		expect(buttons.length).toBe(1);
		expect(buttons[0].getAttribute('href')).toBe('https://x.test/y');
		// The icon is the registry's download class (#1826), not a data: mask.
		expect(buttons[0].classList.contains('ffc-icon-download')).toBe(true);
	});

	it('escapes HTML in text cells so injected markup cannot execute', () => {
		panel().render([makeCert({ form_title: '<img src=x onerror=alert(1)>', email: '<b>e</b>@x.test' })], 1);
		const container = document.getElementById('ffc-tabpanel-certificates');
		// The payload must not materialise as real DOM nodes…
		expect(container.querySelector('img')).toBeNull();
		expect(container.querySelector('table b')).toBeNull();
		// …it survives as inert text instead.
		expect(container.textContent).toContain('<img src=x onerror=alert(1)>');
	});

	it('keeps a quote-breakout magic_link inside the href attribute', () => {
		panel().render([makeCert({ magic_link: 'https://x.test/"><img src=x onerror=alert(1)>' })], 1);
		const buttons = document.querySelectorAll('#ffc-tabpanel-certificates .ffc-btn-pdf');
		// escAttr() neutralises the closing quote: exactly one anchor and no
		// smuggled <img> sibling in the actions cell.
		expect(buttons.length).toBe(1);
		expect(document.querySelector('#ffc-tabpanel-certificates td img')).toBeNull();
	});

	it('adds rel="noopener noreferrer" to the target=_blank PDF link', () => {
		panel().render([makeCert({ magic_link: 'https://x.test/y' })], 1);
		const link = document.querySelector('#ffc-tabpanel-certificates .ffc-btn-pdf');
		expect(link.getAttribute('target')).toBe('_blank');
		expect(link.getAttribute('rel')).toBe('noopener noreferrer');
	});

	it("applies 'ffc-consent-yes' / 'ffc-consent-no' classes based on consent_given", () => {
		panel().render([makeCert({ consent_given: 1, auth_code: 'A' }), makeCert({ consent_given: 0, auth_code: 'B' })], 1);
		expect(document.querySelectorAll('#ffc-tabpanel-certificates .ffc-consent-yes').length).toBe(1);
		expect(document.querySelectorAll('#ffc-tabpanel-certificates .ffc-consent-no').length).toBe(1);
	});

	it('always renders the filter bar, even on the empty state', () => {
		panel().render([], 1);
		expect(document.querySelector('#ffc-tabpanel-certificates .ffc-filter-bar')).not.toBeNull();

		panel().render([makeCert()], 1);
		expect(document.querySelector('#ffc-tabpanel-certificates .ffc-filter-bar')).not.toBeNull();
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
		document.querySelector('#ffc-tabpanel-certificates .ffc-filter-search').value = 'beta';
		panel().render(certs, 1);
		const rows = document.querySelectorAll('#ffc-tabpanel-certificates table tbody tr');
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
		document.querySelector('#ffc-tabpanel-certificates .ffc-filter-from').value = '2026-03-01';
		document.querySelector('#ffc-tabpanel-certificates .ffc-filter-to').value = '2026-09-01';
		panel().render(certs, 1);
		const rows = document.querySelectorAll('#ffc-tabpanel-certificates table tbody tr');
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

	it('bails when #ffc-tabpanel-certificates is missing', async () => {
		document.getElementById('ffc-tabpanel-certificates').remove();
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
		document.getElementById('ffc-dashboard').insertAdjacentHTML(
			'beforeend',
			'<div id="ffc-tabpanel-certificates" class="ffc-tab-content"></div>'
		);
	});

	it('shows the noPermission notice when canViewCertificates is false', async () => {
		window.ffcDashboard.canViewCertificates = false;
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
		expect(document.getElementById('ffc-tabpanel-certificates').innerHTML).toContain('No permission');
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
		expect(document.getElementById('ffc-tabpanel-certificates').textContent).toContain('X');
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
		expect(document.querySelector('#ffc-tabpanel-certificates .ffc-empty-state')).not.toBeNull();
	});

	it('renders the error notice when the AJAX call fails', async () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error();
			return {};
		});

		panel().load();
		await flushPromises();

		expect(document.getElementById('ffc-tabpanel-certificates').innerHTML).toContain('Error');
		const notice = document.querySelector('#ffc-tabpanel-certificates .ffc-notice');
		expect(notice.className).toBe('ffc-notice ffc-notice-danger');
		expect(notice.getAttribute('role')).toBe('alert');
		expect(notice.querySelector('.ffc-icon-error')).not.toBeNull();
		expect(notice.outerHTML).not.toContain('style=');
	});

	it('prints the error string as text, never as markup (#1842)', async () => {
		const saved = window.ffcDashboard.strings.error;
		window.ffcDashboard.strings.error = '<img src=x onerror=alert(1)>';
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error();
			return {};
		});

		panel().load();
		await flushPromises();

		const panelEl = document.getElementById('ffc-tabpanel-certificates');
		expect(panelEl.querySelector('img')).toBeNull();
		expect(panelEl.querySelector('.ffc-notice-text').textContent).toBe('<img src=x onerror=alert(1)>');
		window.ffcDashboard.strings.error = saved;
	});
});

// ----------------------------------------------------------------------
// The e-mail column is masked, with a Reveal button per row (#1897)
// ----------------------------------------------------------------------

describe('FFCDashboard.panels.certificates — reveal', () => {
	beforeEach(() => {
		window.ffcDashboard.strings.reveal = 'Reveal';
		window.ffcDashboard.strings.revealError = 'Unable to reveal this value.';
	});

	afterEach(() => {
		vi.restoreAllMocks();
		panel().state = null;
		panel().canReveal = false;
		delete window.ffcDashboard.viewAsUserId;
	});

	function loadWith(certs, canReveal) {
		vi.spyOn(window.$, 'ajax').mockImplementationOnce((opts) => {
			opts.success({ certificates: certs, total: certs.length, can_reveal: canReveal });
			return {};
		});
		panel().load();
		return flushPromises().then(() => vi.restoreAllMocks());
	}

	it('draws the masked e-mail with a Reveal button on each row', async () => {
		await loadWith([makeCert({ id: 1, email: 'u***@example.com' }), makeCert({ id: 2, email: 'v***@example.com' })], true);

		const buttons = window.$('#ffc-tabpanel-certificates .ffc-certificate-reveal-btn');
		expect(buttons.map((i, b) => window.$(b).data('id')).get()).toEqual([1, 2]);
		expect(document.getElementById('ffc-tabpanel-certificates').textContent).toContain('u***@example.com');
	});

	it('draws no Reveal button when the reader may not reveal', async () => {
		await loadWith([makeCert({ id: 1, email: 'u***@example.com' })], false);

		expect(window.$('#ffc-tabpanel-certificates .ffc-certificate-reveal-btn').length).toBe(0);
	});

	it('posts the id, shows the clear e-mail and drops that row\'s button', async () => {
		await loadWith([makeCert({ id: 1, email: 'u***@example.com' }), makeCert({ id: 2, email: 'v***@example.com' })], true);
		const spy = vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({ id: 1, value: 'user@example.com' });
			return {};
		});

		window.$('.ffc-certificate-reveal-btn[data-id="1"]').trigger('click');
		await flushPromises();

		const call = spy.mock.calls[0][0];
		expect(call.method).toBe('POST');
		expect(call.url).toBe('https://x.test/wp-json/ffc/v1/user/certificates/reveal');
		expect(JSON.parse(call.data)).toEqual({ id: 1 });
		expect(document.getElementById('ffc-tabpanel-certificates').textContent).toContain('user@example.com');
		expect(window.$('.ffc-certificate-reveal-btn[data-id="1"]').length).toBe(0);
		expect(window.$('.ffc-certificate-reveal-btn[data-id="2"]').length).toBe(1);
	});

	it('names the viewed account in view as user', async () => {
		window.ffcDashboard.viewAsUserId = 5;
		await loadWith([makeCert({ id: 1, email: 'u***@example.com' })], true);
		const spy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		window.$('.ffc-certificate-reveal-btn[data-id="1"]').trigger('click');
		await flushPromises();

		expect(spy.mock.calls[0][0].url).toBe('https://x.test/wp-json/ffc/v1/user/certificates/reveal?viewAsUserId=5');
	});

	it('re-enables the button and alerts when the reveal fails', async () => {
		await loadWith([makeCert({ id: 1, email: 'u***@example.com' })], true);
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error({ status: 403, responseJSON: { message: 'No.' } }, 'error', 'Forbidden');
			return {};
		});
		window.alert = vi.fn();

		window.$('.ffc-certificate-reveal-btn[data-id="1"]').trigger('click');
		await flushPromises();

		expect(window.alert).toHaveBeenCalledWith('Unable to reveal this value.');
		expect(window.$('.ffc-certificate-reveal-btn[data-id="1"]').prop('disabled')).toBe(false);
	});
});
