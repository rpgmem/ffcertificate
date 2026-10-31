// Render tests for the Reregistrations panel
// (assets/js/ffc-user-dashboard-reregistrations.js).
//
// The panel reads from #ffc-tabpanel-reregistrations and writes a filter bar +
// table(s) split by `is_active` (Active / Completed). Tests cover empty
// state, sectioning, row classes, validation-code rendering, edit button
// visibility, and search filtering.
//
// Sprint B of #168.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { installDashboardFixtures, loadDashboardCore, loadPanel, flushPromises } from './dashboard-fixtures.js';

beforeAll(() => {
	installDashboardFixtures();
	// Add the strings this panel reads. The fixtures default doesn't carry
	// reregistration-specific keys.
	Object.assign(window.ffcDashboard.strings, {
		active: 'Active',
		completed: 'Completed',
		noReregistrations: 'No reregistrations found.',
		reregistrationTitle: 'Campaign',
		period: 'Period',
		status: 'Status',
		submittedAt: 'Submitted',
		validationCode: 'Validation Code',
		actions: 'Actions',
		editReregistration: 'Edit',
		downloadRecord: 'Download Record',
		noPermission: 'No permission',
	});
	window.ffcDashboard.restUrl = 'https://x.test/wp-json/ffc/v1/';
	window.ffcDashboard.nonce = 'rest-nonce';
	// Inject the panel's tab container, which install... doesn't include.
	const dash = document.getElementById('ffc-dashboard');
	if (dash && ! document.getElementById('ffc-tabpanel-reregistrations')) {
		const el = document.createElement('div');
		el.id = 'ffc-tabpanel-reregistrations';
		el.className = 'ffc-tab-content';
		dash.appendChild(el);
	}
	loadDashboardCore();
	loadPanel('reregistrations');
});

beforeEach(() => {
	document.getElementById('ffc-tabpanel-reregistrations').innerHTML = '';
	window.localStorage.setItem('ffc_page_size', '25');
});

const panel = () => window.FFCDashboard.panels.reregistrations;

function makeRereg(over = {}) {
	return Object.assign({
		reregistration_id: 1,
		title: 'Campaign',
		start_date: '2026-01-01',
		end_date: '2026-12-31',
		start_date_formatted: '01/01/2026',
		end_date_formatted: '31/12/2026',
		status: 'pending',
		status_label: 'Pending',
		submitted_at: '',
		auth_code: '',
		is_active: true,
		can_submit: true,
		can_download: false,
	}, over);
}

describe('FFCDashboard.panels.reregistrations.render', () => {
	it('renders the empty state when there are no items', () => {
		panel().render([], 1);
		const container = document.getElementById('ffc-tabpanel-reregistrations');
		expect(container.querySelector('.ffc-empty-state')).not.toBeNull();
		expect(container.textContent).toContain('No reregistrations found.');
	});

	it('splits into Active and Completed sections', () => {
		panel().render([
			makeRereg({ is_active: true, title: 'Open' }),
			makeRereg({ is_active: false, title: 'Done' }),
		], 1);
		const headers = Array.from(document.querySelectorAll('#ffc-tabpanel-reregistrations h3')).map((h) => h.textContent);
		expect(headers).toEqual(['Active', 'Completed']);
	});

	it('wraps each group in its own section, heading and table together (#1837)', () => {
		panel().render([
			makeRereg({ is_active: true, title: 'Open' }),
			makeRereg({ is_active: false, title: 'Done' }),
		], 1);
		const sections = Array.from(document.querySelectorAll('#ffc-tabpanel-reregistrations section.ffc-dashboard-section'));
		expect(sections.map((el) => el.getAttribute('data-section'))).toEqual(['active', 'completed']);
		sections.forEach((el) => {
			expect(el.querySelector(':scope > h3')).not.toBeNull();
			expect(el.querySelector(':scope > table.ffc-reregistrations-table')).not.toBeNull();
		});
		// The pager stays outside the sections: it pages the whole list.
		expect(document.querySelector('#ffc-tabpanel-reregistrations section .ffc-pagination')).toBeNull();
	});

	it("applies 'ffc-row-past' to completed rows", () => {
		panel().render([
			makeRereg({ is_active: false, title: 'Done' }),
		], 1);
		expect(document.querySelectorAll('#ffc-tabpanel-reregistrations tr.ffc-row-past').length).toBe(1);
	});

	it('renders the Edit button when can_submit is true, omits it otherwise', () => {
		panel().render([
			makeRereg({ can_submit: true, reregistration_id: 10 }),
			makeRereg({ can_submit: false, reregistration_id: 20 }),
		], 1);
		const buttons = document.querySelectorAll('#ffc-tabpanel-reregistrations .ffc-rereg-open-form');
		expect(buttons.length).toBe(1);
		expect(buttons[0].getAttribute('data-reregistration-id')).toBe('10');
		expect(buttons[0].classList.contains('ffc-icon-edit')).toBe(true);
	});

	it('renders the Download Record button only when can_download, with no public link (#2242)', () => {
		panel().render([
			makeRereg({ can_download: true, submission_id: 7 }),
			makeRereg({ can_download: false, submission_id: 8 }),
		], 1);
		const buttons = document.querySelectorAll('#ffc-tabpanel-reregistrations .ffc-btn-pdf');
		expect(buttons.length).toBe(1);
		expect(buttons[0].tagName).toBe('BUTTON');
		expect(buttons[0].getAttribute('href')).toBeNull();
		expect(buttons[0].classList.contains('ffc-rereg-download-record')).toBe(true);
		expect(buttons[0].getAttribute('data-submission-id')).toBe('7');
		// The icon is the registry's download class (#1826), not a data: mask.
		expect(buttons[0].classList.contains('ffc-icon-download')).toBe(true);
	});


	it('attaches the prefixed status class with the status value', () => {
		panel().render([
			makeRereg({ status: 'approved', status_label: 'Approved', reregistration_id: 1 }),
			makeRereg({ status: 'rejected', status_label: 'Rejected', reregistration_id: 2 }),
		], 1);
		expect(document.querySelectorAll('#ffc-tabpanel-reregistrations .ffc-pill.ffc-dashboard-status-approved').length).toBe(1);
		expect(document.querySelectorAll('#ffc-tabpanel-reregistrations .ffc-pill.ffc-dashboard-status-rejected').length).toBe(1);
	});

	// #1151 — the same badge, the same rename, the second of its two emitters.
	it('publishes no unprefixed status class', () => {
		panel().render([makeRereg({ status: 'approved', status_label: 'Approved' })], 1);
		expect(document.querySelectorAll('#ffc-tabpanel-reregistrations .appointment-status').length).toBe(0);
		expect(document.querySelectorAll('#ffc-tabpanel-reregistrations .status-approved').length).toBe(0);
	});

	it('renders the auth_code in a <code> tag when present, dash when absent', () => {
		panel().render([
			makeRereg({ auth_code: 'ABC123' }),
			makeRereg({ auth_code: '' }),
		], 1);
		expect(document.querySelectorAll('#ffc-tabpanel-reregistrations code.ffc-auth-code').length).toBe(1);
		expect(document.querySelectorAll('#ffc-tabpanel-reregistrations code.ffc-auth-code')[0].textContent).toBe('ABC123');
	});

	it('filters by search query (title / status_label / auth_code substring)', () => {
		const items = [
			makeRereg({ title: 'Alpha campaign', auth_code: 'AAA', reregistration_id: 1 }),
			makeRereg({ title: 'Beta campaign',  auth_code: 'BBB', reregistration_id: 2 }),
		];
		panel().render(items, 1);
		document.querySelector('#ffc-tabpanel-reregistrations .ffc-filter-search').value = 'beta';
		panel().render(items, 1);
		const rows = document.querySelectorAll('#ffc-tabpanel-reregistrations table tbody tr');
		expect(rows.length).toBe(1);
		expect(rows[0].textContent).toContain('Beta');
	});

	it('filters out rows outside the from/to date range', () => {
		const items = [
			// Excluded by the from bound (start_date < fromVal).
			makeRereg({ start_date: '2026-01-01', end_date: '2026-02-01', title: 'EarlyCamp', reregistration_id: 1 }),
			// Kept — inside both bounds.
			makeRereg({ start_date: '2026-06-01', end_date: '2026-07-01', title: 'MidCamp', reregistration_id: 2 }),
			// Excluded by the to bound (end_date > toVal).
			makeRereg({ start_date: '2026-06-01', end_date: '2026-12-31', title: 'LateCamp', reregistration_id: 3 }),
		];
		panel().render(items, 1);
		document.querySelector('#ffc-tabpanel-reregistrations .ffc-filter-from').value = '2026-03-01';
		document.querySelector('#ffc-tabpanel-reregistrations .ffc-filter-to').value = '2026-09-01';
		panel().render(items, 1);
		const rows = document.querySelectorAll('#ffc-tabpanel-reregistrations table tbody tr');
		expect(rows.length).toBe(1);
		expect(rows[0].textContent).toContain('MidCamp');
	});
});

// ----------------------------------------------------------------------
// load() — guard / AJAX flow
// ----------------------------------------------------------------------

describe('FFCDashboard.panels.reregistrations.load', () => {
	afterEach(() => {
		vi.restoreAllMocks();
		panel().state = null;
		delete window.ffcDashboard.viewAsUserId;
		delete window.ffcDashboard.canViewReregistrations;
	});

	it('bails when #ffc-tabpanel-reregistrations is missing', async () => {
		document.getElementById('ffc-tabpanel-reregistrations').remove();
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
		document.getElementById('ffc-dashboard').insertAdjacentHTML(
			'beforeend',
			'<div id="ffc-tabpanel-reregistrations" class="ffc-tab-content"></div>'
		);
	});

	it('shows the noPermission notice when canViewReregistrations is false', async () => {
		window.ffcDashboard.canViewReregistrations = false;
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
		expect(document.getElementById('ffc-tabpanel-reregistrations').innerHTML).toContain('No permission');
	});

	it('short-circuits when state is already populated', async () => {
		panel().state = [];
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
	});

	it('GETs /user/reregistrations, sets X-WP-Nonce, and stores the response on state', async () => {
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			const xhr = { setRequestHeader: vi.fn() };
			opts.beforeSend(xhr);
			expect(xhr.setRequestHeader).toHaveBeenCalledWith('X-WP-Nonce', 'rest-nonce');
			opts.success({ reregistrations: [makeRereg({ title: 'LoadedCampaign' })] });
			return {};
		});

		panel().load();
		await flushPromises();

		const opts = ajaxSpy.mock.calls[0][0];
		expect(opts.url).toBe('https://x.test/wp-json/ffc/v1/user/reregistrations');
		expect(panel().state.length).toBe(1);
		expect(document.getElementById('ffc-tabpanel-reregistrations').textContent).toContain('LoadedCampaign');
	});

	it('appends viewAsUserId query string when impersonating', async () => {
		window.ffcDashboard.viewAsUserId = 7;
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy.mock.calls[0][0].url).toBe('https://x.test/wp-json/ffc/v1/user/reregistrations?viewAsUserId=7');
	});

	it('defaults state to [] when response.reregistrations is missing', async () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({});
			return {};
		});

		panel().load();
		await flushPromises();

		expect(panel().state).toEqual([]);
		expect(document.querySelector('#ffc-tabpanel-reregistrations .ffc-empty-state')).not.toBeNull();
	});

	it('renders the error notice when the AJAX call fails', async () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error();
			return {};
		});

		panel().load();
		await flushPromises();

		expect(document.getElementById('ffc-tabpanel-reregistrations').innerHTML).toContain('Error');
	});
});

// ----------------------------------------------------------------------
// View answers (#2227)
// ----------------------------------------------------------------------

describe('FFCDashboard.panels.reregistrations answers', () => {
	beforeAll(() => {
		window.ffcDashboard.strings.viewAnswers = 'View answers';
		window.ffcDashboard.strings.reveal = 'Reveal';
		panel().bindEvents();
	});

	beforeEach(() => {
		window.ffcDashboard.viewAsUserId = 0;
	});

	afterEach(() => {
		vi.restoreAllMocks();
	});

	const answers = (over = {}) => Object.assign({
		submission_id: 3,
		rows: [
			{ key: 'rf', label: 'RF', value: '802.***-5' },
			{ key: 'vinculo', label: 'Bond', value: '' },
			{ key: 'nome', label: 'Name', value: '<b>Maria</b>' },
		],
		masked: true,
		can_reveal: true,
	}, over);

	function mockAnswers(responses) {
		const urls = [];
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			urls.push(opts.url);
			opts.success(responses.shift());
			return {};
		});
		return urls;
	}

	it('offers View answers only on a sent submission', () => {
		panel().render([
			makeRereg({ submission_id: 3, can_view_answers: true, is_active: false }),
			makeRereg({ submission_id: 4, can_view_answers: false }),
		], 1);

		const buttons = document.querySelectorAll('.ffc-rereg-answers-toggle');
		expect(buttons.length).toBe(1);
		expect(buttons[0].getAttribute('data-submission-id')).toBe('3');
		expect(buttons[0].getAttribute('aria-expanded')).toBe('false');
	});

	it('opens the answers under the row, escaped, and closes them again', async () => {
		const urls = mockAnswers([answers()]);
		panel().render([makeRereg({ submission_id: 3, can_view_answers: true })], 1);

		window.$('.ffc-rereg-answers-toggle').trigger('click');
		await flushPromises();

		expect(urls[0]).toBe('https://x.test/wp-json/ffc/v1/user/reregistrations/3/answers');
		const row = document.querySelector('.ffc-rereg-answers-row');
		expect(row.previousElementSibling.querySelector('.ffc-rereg-answers-toggle')).not.toBeNull();
		const dd = row.querySelectorAll('dd');
		expect(dd[0].textContent).toBe('802.***-5');
		expect(dd[1].textContent).toBe('—');
		expect(dd[2].innerHTML).toBe('&lt;b&gt;Maria&lt;/b&gt;');
		expect(window.$('.ffc-rereg-answers-toggle').attr('aria-expanded')).toBe('true');

		window.$('.ffc-rereg-answers-toggle').trigger('click');
		expect(document.querySelector('.ffc-rereg-answers-row')).toBeNull();
		expect(window.$('.ffc-rereg-answers-toggle').attr('aria-expanded')).toBe('false');
	});

	it('reveals on request and drops the button once nothing is masked', async () => {
		window.ffcDashboard.viewAsUserId = 7;
		const urls = mockAnswers([
			answers(),
			answers({ rows: [{ key: 'rf', label: 'RF', value: '802.633-5' }], masked: false, can_reveal: false }),
		]);
		panel().render([makeRereg({ submission_id: 3, can_view_answers: true })], 1);

		window.$('.ffc-rereg-answers-toggle').trigger('click');
		await flushPromises();
		window.$('.ffc-rereg-answers-reveal').trigger('click');
		await flushPromises();

		expect(urls[1]).toBe('https://x.test/wp-json/ffc/v1/user/reregistrations/3/answers?reveal=1&viewAsUserId=7');
		expect(document.querySelector('.ffc-rereg-answers-row dd').textContent).toBe('802.633-5');
		expect(document.querySelector('.ffc-rereg-answers-reveal')).toBeNull();
	});

	it('shows an error in the row when the answers cannot load', async () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error();
			return {};
		});
		panel().render([makeRereg({ submission_id: 3, can_view_answers: true })], 1);

		window.$('.ffc-rereg-answers-toggle').trigger('click');
		await flushPromises();

		expect(document.querySelector('.ffc-rereg-answers-row').innerHTML).toContain('Error');
	});

	it('downloads the Record from the signed-in route and builds the PDF (#2242)', async () => {
		window.ffcDashboard.viewAsUserId = 0;
		const urls = mockAnswers([{ pdf_data: { html: '<p>x</p>', filename: 'record.pdf' } }]);
		window.ffcGeneratePDF = vi.fn();
		panel().render([makeRereg({ submission_id: 9, can_download: true })], 1);

		window.$('.ffc-rereg-download-record').trigger('click');
		await flushPromises();

		expect(urls[0]).toBe('https://x.test/wp-json/ffc/v1/user/reregistrations/9/record');
		expect(window.ffcGeneratePDF).toHaveBeenCalledWith({ html: '<p>x</p>', filename: 'record.pdf' }, 'record.pdf');
		const btn = document.querySelector('.ffc-rereg-download-record');
		expect(btn.disabled).toBe(false);
		expect(btn.classList.contains('ffc-btn-loading')).toBe(false);
		delete window.ffcGeneratePDF;
	});

	it('shows an error beside the button when the Record is refused', async () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error();
			return {};
		});
		window.ffcGeneratePDF = vi.fn();
		panel().render([makeRereg({ submission_id: 9, can_download: true })], 1);

		window.$('.ffc-rereg-download-record').trigger('click');
		await flushPromises();

		expect(window.ffcGeneratePDF).not.toHaveBeenCalled();
		expect(document.querySelector('.ffc-rereg-download-record').nextElementSibling.textContent).toContain('Error');
		delete window.ffcGeneratePDF;
	});
});
