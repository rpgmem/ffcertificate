// Tests for assets/js/ffc-admin-activity-log.js — the AJAX
// filter/search/pagination flow that replaces the per-click page
// reload on the Activity Log admin screen.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'admin-nonce',
		strings: {},
	};
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-admin.js');
	loadScript('assets/js/ffc-admin-activity-log.js');
});

beforeEach(() => {
	window.ffcActivityLog = {
		nonce: 'log-nonce',
		strings: {
			noLogs:    'No activity logs found.',
			error:     'Failed to fetch logs.',
			preparing: 'Preparing CSV download…',
			colDate:   'Date/Time',
			colLevel:  'Level',
			colAction: 'Action',
			colUser:   'User',
			colIp:     'IP Address',
			colContext: 'Context',
		},
	};

	document.body.innerHTML = `
		<div class="wrap">
			<h1>Activity Log</h1>
			<div class="tablenav top">
				<div class="alignleft actions">
					<form method="get">
						<select id="filter-by-level" name="level">
							<option value="">All</option>
							<option value="info" selected>Info</option>
							<option value="warning">Warning</option>
						</select>
						<select id="filter-by-action" name="log_action">
							<option value="">All</option>
							<option value="submission_created">Submission Created</option>
						</select>
						<input type="submit" class="button" value="Filter">
						<a href="?post_type=ffc_form&page=ffc-activity-log" class="button">Clear Filters</a>
					</form>
				</div>
				<div class="alignright actions">
					<form>
						<input type="search" id="ffc-log-search" name="s" value="bob">
						<input type="submit" class="button" value="Search">
					</form>
					<a href="?post_type=ffc_form&page=ffc-activity-log&ffc_export_logs=1&_wpnonce=x" class="button">Export CSV</a>
				</div>
			</div>
			<div id="ffc-activity-log-table">
				<table class="wp-list-table">
					<thead><tr><th>Date</th></tr></thead>
					<tbody><tr><td>old row</td></tr></tbody>
				</table>
				<div id="ffc-activity-log-pagination">
					<a href="?paged=2">»</a>
				</div>
			</div>
		</div>
	`;
});

afterEach(() => {
	vi.restoreAllMocks();
});

describe('Activity Log — AJAX filter form', () => {
	it('intercepts the filter form submit and posts ffc_activity_log_fetch with current filters', async () => {
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({
			is_empty: false,
			table_html: '<tr><td>new row</td></tr>',
			pagination_html: '<div class="pager">»</div>',
			total_logs: 1,
			current_page: 1,
		});

		// jsdom doesn't implement HTMLFormElement.submit, so we dispatch
		// the submit event directly — handlers still fire, default is
		// prevented by the production code's e.preventDefault().
		document.querySelector('.tablenav.top .alignleft form')
			.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
		await Promise.resolve(); await Promise.resolve();

		expect(requestSpy).toHaveBeenCalledWith('ffc_activity_log_fetch', {
			level:      'info',
			log_action: '',
			search:     'bob',
			paged:      1,
			nonce:      'log-nonce',
		});
	});

	it('swaps the table body + pagination from the JSON response', async () => {
		vi.spyOn(window.FFC, 'request').mockResolvedValue({
			is_empty: false,
			table_html: '<tr><td>NEW</td></tr>',
			pagination_html: '<div class="pager-new">…</div>',
			total_logs: 1,
			current_page: 1,
		});

		// jsdom doesn't implement HTMLFormElement.submit, so we dispatch
		// the submit event directly — handlers still fire, default is
		// prevented by the production code's e.preventDefault().
		document.querySelector('.tablenav.top .alignleft form')
			.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
		await Promise.resolve(); await Promise.resolve();

		expect(window.$('#ffc-activity-log-table tbody').html()).toContain('NEW');
		expect(window.$('#ffc-activity-log-pagination').html()).toContain('pager-new');
	});

	it('on empty result: replaces the table block with the no-logs notice', async () => {
		vi.spyOn(window.FFC, 'request').mockResolvedValue({
			is_empty: true,
			table_html: '',
			pagination_html: '',
			total_logs: 0,
			current_page: 1,
		});

		// jsdom doesn't implement HTMLFormElement.submit, so we dispatch
		// the submit event directly — handlers still fire, default is
		// prevented by the production code's e.preventDefault().
		document.querySelector('.tablenav.top .alignleft form')
			.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
		await Promise.resolve(); await Promise.resolve();

		expect(window.$('#ffc-activity-log-table').text()).toContain('No activity logs found.');
		expect(window.$('#ffc-activity-log-table table').length).toBe(0);
	});
});

describe('Activity Log — pagination links', () => {
	it('extracts the page number from the href and fetches that page', async () => {
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({
			is_empty: false, table_html: '<tr></tr>', pagination_html: '', total_logs: 1, current_page: 2,
		});

		window.$('#ffc-activity-log-pagination a').trigger('click');
		await Promise.resolve(); await Promise.resolve();

		expect(requestSpy).toHaveBeenCalledWith('ffc_activity_log_fetch', expect.objectContaining({ paged: 2 }));
	});
});

describe('Activity Log — Clear Filters', () => {
	it('intercepts Clear Filters, resets selects + search, and refetches with empties', async () => {
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({
			is_empty: false, table_html: '<tr></tr>', pagination_html: '', total_logs: 1, current_page: 1,
		});

		window.$('.tablenav.top .alignleft a.button').trigger('click');
		await Promise.resolve(); await Promise.resolve();

		expect(requestSpy).toHaveBeenCalledWith('ffc_activity_log_fetch', expect.objectContaining({
			level: '', log_action: '', search: '', paged: 1,
		}));
		expect(window.$('#filter-by-level').val()).toBe('');
		expect(window.$('#ffc-log-search').val()).toBe('');
	});
});

describe('Activity Log — Export CSV', () => {
	it('pops a Preparing toast (but does NOT preventDefault — native download proceeds)', () => {
		const notifySpy = vi.spyOn(window.FFC.Admin, 'showNotification').mockImplementation(() => {});

		const ev = window.$.Event('click');
		window.$('.tablenav.top a.button[href*="ffc_export_logs"]').trigger(ev);

		expect(notifySpy).toHaveBeenCalledWith('Preparing CSV download…', 'info', 4000);
		// Click handler doesn't preventDefault — browser follows href.
		expect(ev.isDefaultPrevented()).toBe(false);
	});
});

describe('Activity Log — error path', () => {
	it('shows the server message via FFC.Admin.showNotification on error', async () => {
		vi.spyOn(window.FFC, 'request').mockRejectedValue(new Error('DB exploded'));
		const notifySpy = vi.spyOn(window.FFC.Admin, 'showNotification').mockImplementation(() => {});

		// jsdom doesn't implement HTMLFormElement.submit, so we dispatch
		// the submit event directly — handlers still fire, default is
		// prevented by the production code's e.preventDefault().
		document.querySelector('.tablenav.top .alignleft form')
			.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
		await Promise.resolve(); await Promise.resolve();

		expect(notifySpy).toHaveBeenCalledWith('DB exploded', 'error');
		// Existing rows untouched.
		expect(window.$('#ffc-activity-log-table tbody').html()).toContain('old row');
	});
});
