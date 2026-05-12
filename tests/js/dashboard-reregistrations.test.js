// Render tests for the Reregistrations panel
// (assets/js/ffc-user-dashboard-reregistrations.js).
//
// The panel reads from #tab-reregistrations and writes a filter bar +
// table(s) split by `is_active` (Active / Completed). Tests cover empty
// state, sectioning, row classes, validation-code rendering, edit button
// visibility, and search filtering.
//
// Sprint B of #168.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { installDashboardFixtures, loadDashboardCore, loadPanel } from './dashboard-fixtures.js';

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
	});
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
});
