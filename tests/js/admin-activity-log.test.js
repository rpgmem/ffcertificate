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
		nonce:       'log-nonce',
		ajaxUrl:     '/wp-admin/admin-ajax.php',
		exportNonce: 'export-nonce',
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
			exportPreparing: 'Preparing…',
			exportProgress:  'Exporting %1$d/%2$d…',
			exportDone:      'Done!',
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
					<button type="button" id="ffc-activitylog-export-btn" class="button"
						data-level="info" data-log_action="" data-s="bob">Export CSV</button>
					<span id="ffc-activitylog-export-progress" style="display:none;"></span>
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

describe('Activity Log — Export CSV (batched engine #772)', () => {
	afterEach(() => {
		delete window.FFCBatchedExport;
	});

	it('drives window.FFCBatchedExport.run with type + current filters + job nonce', () => {
		const runSpy = vi.fn();
		window.FFCBatchedExport = { run: runSpy };

		window.$('#ffc-activitylog-export-btn').trigger('click');

		expect(runSpy).toHaveBeenCalledTimes(1);
		const arg = runSpy.mock.calls[0][0];
		expect(arg.type).toBe('activity_log');
		expect(arg.ajaxUrl).toBe('/wp-admin/admin-ajax.php');
		expect(arg.nonce).toBe('export-nonce');
		expect(arg.startData).toEqual({ level: 'info', log_action: '', s: 'bob' });
		// Progress is now driven by the shared overlay (#786): the caller passes
		// overlay:true + the button, not per-call callbacks.
		expect(arg.overlay).toBe(true);
		expect(arg.button).toBeTruthy();
	});

	it('is a no-op when the batched-export driver is unavailable', () => {
		// No window.FFCBatchedExport defined → handler returns early, no throw.
		expect(() => window.$('#ffc-activitylog-export-btn').trigger('click')).not.toThrow();
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

	it('falls back to window.alert when showNotification is unavailable', async () => {
		vi.spyOn(window.FFC, 'request').mockRejectedValue(new Error('Net down'));
		// Temporarily remove the showNotification helper.
		const saved = window.FFC.Admin.showNotification;
		window.FFC.Admin.showNotification = undefined;
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		document.querySelector('.tablenav.top .alignleft form')
			.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
		await Promise.resolve(); await Promise.resolve();

		expect(alertSpy).toHaveBeenCalledWith('Net down');
		window.FFC.Admin.showNotification = saved;
	});
});

describe('Activity Log — applyResponse table-shell rebuild', () => {
	it('builds the table shell when the table container has no <tbody>', async () => {
		// Replace the table block with a bare container (no <table>/<tbody>).
		window.$('#ffc-activity-log-table').html('');
		vi.spyOn(window.FFC, 'request').mockResolvedValue({
			is_empty: false,
			table_html: '<tr><td>REBUILT</td></tr>',
			pagination_html: '<div class="pg">1</div>',
		});

		document.querySelector('.tablenav.top .alignleft form')
			.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
		await Promise.resolve(); await Promise.resolve();

		// The shell was injected and the row landed inside the new tbody.
		expect(window.$('#ffc-activity-log-table table thead th').length).toBe(6);
		expect(window.$('#ffc-activity-log-table tbody').html()).toContain('REBUILT');
	});

	it('does nothing when the activity-log table container is absent', async () => {
		window.$('#ffc-activity-log-table').remove();
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({ is_empty: false, table_html: '<tr></tr>' });
		// Filter submit handler short-circuits because $table() is empty.
		document.querySelector('.tablenav.top .alignleft form')
			.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
		await Promise.resolve(); await Promise.resolve();
		expect(requestSpy).not.toHaveBeenCalled();
	});

	it('applyResponse bails when the table vanishes after the fetch starts', async () => {
		// The table exists at submit time (so the handler proceeds) but is
		// removed before the deferred response resolves, exercising the
		// `if (!$tbl.length) return;` guard inside applyResponse.
		let resolveFn;
		vi.spyOn(window.FFC, 'request').mockReturnValue(new Promise((res) => { resolveFn = res; }));
		document.querySelector('.tablenav.top .alignleft form')
			.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
		// Remove the table while the request is in flight.
		window.$('#ffc-activity-log-table').remove();
		resolveFn({ is_empty: false, table_html: '<tr><td>X</td></tr>' });
		await Promise.resolve(); await Promise.resolve();
		// No table re-created — applyResponse returned early.
		expect(document.getElementById('ffc-activity-log-table')).toBeNull();
	});
});

describe('Activity Log — pagination + clear-filter guards', () => {
	it('ignores pagination clicks whose href has no paged param', async () => {
		window.$('#ffc-activity-log-pagination').html('<a href="?foo=bar">no-page</a>');
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({ is_empty: false, table_html: '' });
		window.$('#ffc-activity-log-pagination a').trigger('click');
		await Promise.resolve(); await Promise.resolve();
		expect(requestSpy).not.toHaveBeenCalled();
	});

	it('does NOT intercept a filtered link (href carries level/log_action/s)', async () => {
		// A button whose href already has filters is the "active filter"
		// link, not Clear Filters — the handler must let it through.
		window.$('.tablenav.top .alignleft form').append(
			'<a href="?post_type=ffc_form&page=ffc-activity-log&level=warning" class="button">Filtered</a>'
		);
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({ is_empty: false, table_html: '' });
		const ev = window.$.Event('click');
		window.$('.tablenav.top .alignleft a.button[href*="level=warning"]').trigger(ev);
		await Promise.resolve(); await Promise.resolve();
		expect(requestSpy).not.toHaveBeenCalled();
		expect(ev.isDefaultPrevented()).toBe(false);
	});
});

describe('Activity Log — popstate restore', () => {
	it('restores filters from the history state and refetches without pushing', async () => {
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({ is_empty: false, table_html: '<tr></tr>', pagination_html: '' });

		const popEvent = window.$.Event('popstate');
		popEvent.originalEvent = {
			state: { filters: { level: 'warning', log_action: 'submission_created', search: 'alice' }, paged: 3 },
		};
		window.$(window).trigger(popEvent);
		await Promise.resolve(); await Promise.resolve();

		expect(window.$('#filter-by-level').val()).toBe('warning');
		expect(window.$('#filter-by-action').val()).toBe('submission_created');
		expect(window.$('#ffc-log-search').val()).toBe('alice');
		expect(requestSpy).toHaveBeenCalledWith('ffc_activity_log_fetch', expect.objectContaining({
			level: 'warning', log_action: 'submission_created', search: 'alice', paged: 3,
		}));
	});

	it('ignores popstate events with no saved filters', async () => {
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({ is_empty: false, table_html: '' });
		const popEvent = window.$.Event('popstate');
		popEvent.originalEvent = { state: null };
		window.$(window).trigger(popEvent);
		await Promise.resolve(); await Promise.resolve();
		expect(requestSpy).not.toHaveBeenCalled();
	});
});
