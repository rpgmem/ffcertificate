// Render tests for the Reregistrations panel
// (assets/js/ffc-user-dashboard-reregistrations.js).
//
// The panel reads from #tab-reregistrations and writes a filter bar +
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
		downloadFicha: 'Download Ficha',
		noPermission: 'No permission',
	});
	window.ffcDashboard.restUrl = 'https://x.test/wp-json/ffc/v1/';
	window.ffcDashboard.nonce = 'rest-nonce';
	// Inject the panel's tab container, which install... doesn't include.
	const dash = document.getElementById('ffc-dashboard');
	if (dash && ! document.getElementById('tab-reregistrations')) {
		const el = document.createElement('div');
		el.id = 'tab-reregistrations';
		el.className = 'ffc-tab-content';
		dash.appendChild(el);
	}
	loadDashboardCore();
	loadPanel('reregistrations');
});

beforeEach(() => {
	document.getElementById('tab-reregistrations').innerHTML = '';
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
		magic_link: '',
	}, over);
}

describe('FFCDashboard.panels.reregistrations.render', () => {
	it('renders the empty state when there are no items', () => {
		panel().render([], 1);
		const container = document.getElementById('tab-reregistrations');
		expect(container.querySelector('.ffc-empty-state')).not.toBeNull();
		expect(container.textContent).toContain('No reregistrations found.');
	});

	it('splits into Active and Completed sections', () => {
		panel().render([
			makeRereg({ is_active: true, title: 'Open' }),
			makeRereg({ is_active: false, title: 'Done' }),
		], 1);
		const headers = Array.from(document.querySelectorAll('#tab-reregistrations h3')).map((h) => h.textContent);
		expect(headers).toEqual(['Active', 'Completed']);
	});

	it("applies 'past-row' to completed rows", () => {
		panel().render([
			makeRereg({ is_active: false, title: 'Done' }),
		], 1);
		expect(document.querySelectorAll('#tab-reregistrations tr.past-row').length).toBe(1);
	});

	it('renders the Edit button when can_submit is true, omits it otherwise', () => {
		panel().render([
			makeRereg({ can_submit: true, reregistration_id: 10 }),
			makeRereg({ can_submit: false, reregistration_id: 20 }),
		], 1);
		const buttons = document.querySelectorAll('#tab-reregistrations .ffc-rereg-open-form');
		expect(buttons.length).toBe(1);
		expect(buttons[0].getAttribute('data-reregistration-id')).toBe('10');
	});

	it('renders the Download Ficha link only when both can_download and magic_link present', () => {
		panel().render([
			makeRereg({ can_download: true, magic_link: 'https://x.test/m' }),
			makeRereg({ can_download: false, magic_link: 'https://x.test/m' }),
			makeRereg({ can_download: true, magic_link: '' }),
		], 1);
		const buttons = document.querySelectorAll('#tab-reregistrations .ffc-btn-pdf');
		expect(buttons.length).toBe(1);
	});

	it('renders the auth_code in a <code> tag when present, dash when absent', () => {
		panel().render([
			makeRereg({ auth_code: 'ABC123' }),
			makeRereg({ auth_code: '' }),
		], 1);
		expect(document.querySelectorAll('#tab-reregistrations code.ffc-auth-code').length).toBe(1);
		expect(document.querySelectorAll('#tab-reregistrations code.ffc-auth-code')[0].textContent).toBe('ABC123');
	});

	it('filters by search query (title / status_label / auth_code substring)', () => {
		const items = [
			makeRereg({ title: 'Alpha campaign', auth_code: 'AAA', reregistration_id: 1 }),
			makeRereg({ title: 'Beta campaign',  auth_code: 'BBB', reregistration_id: 2 }),
		];
		panel().render(items, 1);
		document.querySelector('#tab-reregistrations .ffc-filter-search').value = 'beta';
		panel().render(items, 1);
		const rows = document.querySelectorAll('#tab-reregistrations table tbody tr');
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
		document.querySelector('#tab-reregistrations .ffc-filter-from').value = '2026-03-01';
		document.querySelector('#tab-reregistrations .ffc-filter-to').value = '2026-09-01';
		panel().render(items, 1);
		const rows = document.querySelectorAll('#tab-reregistrations table tbody tr');
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

	it('bails when #tab-reregistrations is missing', async () => {
		document.getElementById('tab-reregistrations').remove();
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
		document.getElementById('ffc-dashboard').insertAdjacentHTML(
			'beforeend',
			'<div id="tab-reregistrations" class="ffc-tab-content"></div>'
		);
	});

	it('shows the noPermission notice when canViewReregistrations is false', async () => {
		window.ffcDashboard.canViewReregistrations = false;
		const ajaxSpy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));

		panel().load();
		await flushPromises();

		expect(ajaxSpy).not.toHaveBeenCalled();
		expect(document.getElementById('tab-reregistrations').innerHTML).toContain('No permission');
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
		expect(document.getElementById('tab-reregistrations').textContent).toContain('LoadedCampaign');
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
		expect(document.querySelector('#tab-reregistrations .ffc-empty-state')).not.toBeNull();
	});

	it('renders the error notice when the AJAX call fails', async () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error();
			return {};
		});

		panel().load();
		await flushPromises();

		expect(document.getElementById('tab-reregistrations').innerHTML).toContain('Error');
	});
});
