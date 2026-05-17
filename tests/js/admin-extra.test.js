// Sprint E coverage for `assets/js/ffc-admin.js` — covers paths that
// `admin-core.test.js` deferred:
//
//   - Generate codes (#ffc_btn_generate_codes) — success + 403 / 400 / 500 branches
//   - CSV export click (#ffc-csv-export-btn) — start + batch + iframe download
//   - Migration menu (toggle, ESC, overlay click)
//   - Filter overlay (open / close / backdrop)
//   - Quiz Mode toggle (#ffc_quiz_enabled)
//   - CSV Public toggle (#ffc_csv_public_enabled)
//   - Device Fingerprint Limit toggle (#ffc_device_limit_enabled), incl.
//     the globally-off branch
//
// The admin.js IIFE wraps three jQuery `.ready` blocks. The Migration
// Manager + Restriction toggle block requires `#ffc-migrations-btn` /
// `#ffc-migrations-menu` to be present at load time (otherwise it bails
// before reaching the restriction handlers — that's also why
// admin-core.test.js seeds those nodes). The other IIFE blocks are
// guarded by `.length` checks on specific IDs, so each suite below
// re-loads the script in a beforeAll with the exact fixture it wants.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeEach(() => {
	// fx.off makes slideUp/slideDown synchronous so CSS-state assertions
	// can read final display values.
	window.$.fx.off = true;
});

afterEach(() => {
	vi.restoreAllMocks();
});

// ----------------------------------------------------------------------
// Generate codes — success + error branches
// ----------------------------------------------------------------------

describe('admin generate-codes — AJAX result branches', () => {
	beforeAll(async () => {
		window.ffc_ajax = {
			nonce: 'codes-nonce',
			strings: {
				generatingTickets: 'Generating tickets...',
				ticketsGeneratedSuccess: 'tickets generated successfully!',
				codesFieldNotFound: 'Error: codes field not found',
				permissionDenied: 'Permission denied.',
				badRequest: 'Bad request.',
				serverError: 'Server error (Status: %d)',
				error: 'Error: ',
			},
		};
		window.ajaxurl = '/wp-admin/admin-ajax.php';
		document.body.innerHTML = `
			<button id="ffc-migrations-btn"></button>
			<div id="ffc-migrations-menu"></div>
		`;
		loadScript('assets/js/ffc-admin.js');
		await new Promise((r) => setTimeout(r, 0));
	});

	function setupForm() {
		document.body.innerHTML = `
			<input id="ffc_qty_codes" type="text" value="3">
			<span id="ffc_gen_status"></span>
			<textarea id="ffc_generated_list"></textarea>
			<button id="ffc_btn_generate_codes" type="button">Generate</button>
		`;
	}

	it('appends generated codes to #ffc_generated_list on success', () => {
		setupForm();
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({ success: true, data: { codes: 'AAAA-1111\nBBBB-2222\nCCCC-3333' } });
		});

		window.$('#ffc_btn_generate_codes').trigger('click');

		expect(window.$('#ffc_generated_list').val()).toBe('AAAA-1111\nBBBB-2222\nCCCC-3333');
		expect(window.$('#ffc_gen_status').text()).toContain('tickets generated successfully!');
	});

	it('appends rather than replaces when the textarea already has codes', () => {
		setupForm();
		window.$('#ffc_generated_list').val('EXISTING-CODE');
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({ success: true, data: { codes: 'NEW-CODE' } });
		});

		window.$('#ffc_btn_generate_codes').trigger('click');

		expect(window.$('#ffc_generated_list').val()).toBe('EXISTING-CODE\nNEW-CODE');
	});

	it('shows the field-not-found error when #ffc_generated_list is missing', () => {
		document.body.innerHTML = `
			<input id="ffc_qty_codes" type="text" value="3">
			<span id="ffc_gen_status"></span>
			<button id="ffc_btn_generate_codes" type="button">Generate</button>
		`;
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({ success: true, data: { codes: 'X' } });
		});

		window.$('#ffc_btn_generate_codes').trigger('click');

		expect(window.$('#ffc_gen_status').text()).toContain('Error: codes field not found');
	});

	it('shows the inline error when the server returns success=false', () => {
		setupForm();
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({ success: false, data: 'Quota exceeded' });
		});

		window.$('#ffc_btn_generate_codes').trigger('click');

		expect(window.$('#ffc_gen_status').text()).toContain('Error: Quota exceeded');
	});

	it('maps xhr.status=403 to the permission-denied string', () => {
		setupForm();
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error({ status: 403, statusText: 'Forbidden', responseText: '' });
		});

		window.$('#ffc_btn_generate_codes').trigger('click');

		expect(window.$('#ffc_gen_status').text()).toContain('Permission denied.');
	});

	it('maps xhr.status=400 to the bad-request string', () => {
		setupForm();
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error({ status: 400, statusText: 'Bad', responseText: '' });
		});

		window.$('#ffc_btn_generate_codes').trigger('click');

		expect(window.$('#ffc_gen_status').text()).toContain('Bad request.');
	});

	it('maps other xhr statuses to the templated server-error string', () => {
		setupForm();
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.error({ status: 502, statusText: 'Bad Gateway', responseText: '' });
		});

		window.$('#ffc_btn_generate_codes').trigger('click');

		expect(window.$('#ffc_gen_status').text()).toContain('Server error (Status: 502)');
	});
});

// ----------------------------------------------------------------------
// CSV export click — chained $.post (start → batch → batch → iframe)
// ----------------------------------------------------------------------

describe('admin CSV export — batched flow', () => {
	function setupExport({ formIds = [], status = 'publish' } = {}) {
		document.body.innerHTML = `
			<button id="ffc-migrations-btn"></button>
			<div id="ffc-migrations-menu"></div>
			<button id="ffc-csv-export-btn"
				data-form-ids='${JSON.stringify(formIds)}'
				data-status="${status}">Export</button>
			<span id="ffc-csv-export-progress" style="display:none"></span>
		`;
	}

	it('aborts when ffc_ajax.export_nonce is missing', async () => {
		window.ffc_ajax = {
			export_nonce: '',
			strings: { error: 'Error: missing export nonce' },
		};
		window.ajaxurl = '/wp-admin/admin-ajax.php';
		setupExport();
		// admin.js was already loaded by the prior suite; the delegated
		// click handler is in place.
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => ({ fail: () => ({}) }));

		window.$('#ffc-csv-export-btn').trigger('click');

		expect(alertSpy).toHaveBeenCalledWith('Error: missing export nonce');
		expect(postSpy).not.toHaveBeenCalled();
	});

	it('walks start → batch → batch(done) and shows the done text', async () => {
		window.ffc_ajax = {
			export_nonce: 'exp-nonce',
			strings: {
				exportPreparing: 'Preparing…',
				exportProgress: 'Exporting %1$d/%2$d…',
				exportDone: 'Done!',
				connectionError: 'Connection error.',
			},
		};
		window.ajaxurl = '/wp-admin/admin-ajax.php';
		setupExport({ formIds: [1, 2], status: 'publish' });

		const responses = [
			{ success: true, data: { job_id: 'job-1', total: 10 } },         // start
			{ success: true, data: { processed: 5, done: false } },          // first batch
			{ success: true, data: { processed: 10, done: true } },          // second batch
		];
		const calls = [];
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation((url, data, cb) => {
			calls.push(data);
			const res = responses.shift();
			cb(res);
			return { fail: () => ({}) };
		});

		window.$('#ffc-csv-export-btn').trigger('click');

		// At this point three $.post calls fired synchronously (mocked).
		expect(postSpy).toHaveBeenCalledTimes(3);
		expect(calls[0]).toMatchObject({
			action: 'ffc_csv_export_start',
			nonce: 'exp-nonce',
			status: 'publish',
			form_ids: [1, 2],
		});
		expect(calls[1].action).toBe('ffc_csv_export_batch');
		expect(calls[2].action).toBe('ffc_csv_export_batch');

		// Progress text shows the last interim 10/10 line; iframe inserted.
		expect(window.$('#ffc-csv-export-progress').text()).toContain('10/10');
		expect(window.$('iframe[src*="ffc_csv_export_download"]').length).toBe(1);
	});

	it('shows the error message when start returns success=false', async () => {
		window.ffc_ajax = { export_nonce: 'n', strings: { error: 'Error' } };
		window.ajaxurl = '/wp-admin/admin-ajax.php';
		setupExport();

		vi.spyOn(window.$, 'post').mockImplementation((url, data, cb) => {
			cb({ success: false, data: 'No rows match' });
			return { fail: () => ({}) };
		});

		window.$('#ffc-csv-export-btn').trigger('click');

		expect(window.$('#ffc-csv-export-progress').text()).toBe('No rows match');
	});

	it('shows the error message when a batch returns success=false', async () => {
		window.ffc_ajax = { export_nonce: 'n', strings: {} };
		window.ajaxurl = '/wp-admin/admin-ajax.php';
		setupExport();

		const responses = [
			{ success: true, data: { job_id: 'job-1', total: 5 } },
			{ success: false, data: 'Batch failed' },
		];
		vi.spyOn(window.$, 'post').mockImplementation((url, data, cb) => {
			cb(responses.shift());
			return { fail: () => ({}) };
		});

		window.$('#ffc-csv-export-btn').trigger('click');

		expect(window.$('#ffc-csv-export-progress').text()).toBe('Batch failed');
	});
});

// ----------------------------------------------------------------------
// Migration Manager dropdown — load with the migrations buttons present
// ----------------------------------------------------------------------

describe('admin migration manager dropdown', () => {
	beforeAll(async () => {
		document.body.innerHTML = `
			<div class="ffc-migrations-dropdown">
				<button id="ffc-migrations-btn">Migrations</button>
				<div id="ffc-migrations-menu" class="ffc-migrations-menu"></div>
			</div>
		`;
		// Reload admin.js after mounting these so the dropdown ready-block
		// finds the elements and wires its private handlers.
		loadScript('assets/js/ffc-admin.js');
		await new Promise((r) => setTimeout(r, 0));
	});

	it('clicking #ffc-migrations-btn toggles the menu visible', () => {
		window.$('#ffc-migrations-btn').trigger('click');
		expect(window.$('#ffc-migrations-menu').hasClass('ffc-visible')).toBe(true);

		window.$('#ffc-migrations-btn').trigger('click');
		expect(window.$('#ffc-migrations-menu').hasClass('ffc-visible')).toBe(false);
	});

	it('ESC closes the menu when it is open', () => {
		window.$('#ffc-migrations-btn').trigger('click');
		expect(window.$('#ffc-migrations-menu').hasClass('ffc-visible')).toBe(true);

		const ev = window.$.Event('keydown', { key: 'Escape' });
		window.$(document).trigger(ev);

		expect(window.$('#ffc-migrations-menu').hasClass('ffc-visible')).toBe(false);
	});

	it('clicking the overlay closes the menu', () => {
		window.$('#ffc-migrations-btn').trigger('click');
		expect(window.$('#ffc-migrations-menu').hasClass('ffc-visible')).toBe(true);

		window.$('#ffc-migrations-overlay').trigger('click');

		expect(window.$('#ffc-migrations-menu').hasClass('ffc-visible')).toBe(false);
	});
});

// ----------------------------------------------------------------------
// Filter Overlay (Submissions page)
// ----------------------------------------------------------------------

describe('admin filter overlay', () => {
	beforeAll(async () => {
		document.body.innerHTML = `
			<button id="ffc-migrations-btn"></button>
			<div id="ffc-migrations-menu"></div>
			<button id="ffc-open-filter-overlay">Filters</button>
			<div id="ffc-filter-overlay">
				<button class="ffc-filter-overlay-close">x</button>
				<div class="ffc-filter-overlay-backdrop"></div>
				<div class="ffc-filter-content">content</div>
			</div>
		`;
		loadScript('assets/js/ffc-admin.js');
		await new Promise((r) => setTimeout(r, 0));
	});

	it('open button shows the overlay', () => {
		document.getElementById('ffc-open-filter-overlay').click();
		expect(document.getElementById('ffc-filter-overlay').style.display).toBe('flex');
	});

	it('close button hides the overlay', () => {
		document.getElementById('ffc-filter-overlay').style.display = 'flex';
		document.querySelector('.ffc-filter-overlay-close').click();
		expect(document.getElementById('ffc-filter-overlay').style.display).toBe('none');
	});

	it('clicking the overlay background hides it (target === overlay)', () => {
		const overlay = document.getElementById('ffc-filter-overlay');
		overlay.style.display = 'flex';
		// Dispatch a click whose target is the overlay itself.
		overlay.dispatchEvent(new window.Event('click', { bubbles: true }));
		expect(overlay.style.display).toBe('none');
	});
});

// ----------------------------------------------------------------------
// Toggle blocks at the bottom of admin.js — quiz / CSV public / device
// ----------------------------------------------------------------------

describe('admin quiz mode toggle', () => {
	beforeAll(async () => {
		document.body.innerHTML = `
			<button id="ffc-migrations-btn"></button>
			<div id="ffc-migrations-menu"></div>
			<input type="checkbox" id="ffc_quiz_enabled">
			<div class="ffc-quiz-setting ffc-hidden">setting</div>
			<div class="ffc-options-field">
				<span class="ffc-quiz-points ffc-hidden">points</span>
			</div>
		`;
		loadScript('assets/js/ffc-admin.js');
		await new Promise((r) => setTimeout(r, 0));
	});

	it('enabling shows quiz settings and points', () => {
		window.$('#ffc_quiz_enabled').prop('checked', true).trigger('change');

		expect(window.$('.ffc-quiz-setting').hasClass('ffc-hidden')).toBe(false);
		expect(window.$('.ffc-quiz-points').hasClass('ffc-hidden')).toBe(false);
	});

	it('disabling hides them again', () => {
		window.$('#ffc_quiz_enabled').prop('checked', false).trigger('change');

		expect(window.$('.ffc-quiz-setting').hasClass('ffc-hidden')).toBe(true);
		expect(window.$('.ffc-quiz-points').hasClass('ffc-hidden')).toBe(true);
	});
});

// Public CSV / Device-Limit master toggles use the unified
// `.ffc-collapsed-target` pattern after #238 Sprint 3. Sub-options are
// no longer disabled at the input level — the wrapper collapses
// visually instead. See `admin-collapsed-target.test.js` for the full
// generic-handler suite.
describe('admin CSV public toggle', () => {
	beforeAll(async () => {
		document.body.innerHTML = `
			<button id="ffc-migrations-btn"></button>
			<div id="ffc-migrations-menu"></div>
			<input type="checkbox" id="ffc_csv_public_enabled" checked>
			<div class="ffc-collapsed-target" data-ffc-master="ffc_csv_public_enabled">
				<table class="ffc-csv-public-table">
					<tr class="ffc-csv-public-sub"><td><input type="text" name="csv-sub-1"></td></tr>
				</table>
			</div>
		`;
		loadScript('assets/js/ffc-admin.js');
		await new Promise((r) => setTimeout(r, 0));
	});

	it('collapses the sub-options wrapper when the toggle is off', () => {
		window.$('#ffc_csv_public_enabled').prop('checked', false).trigger('change');
		expect(window.$('.ffc-collapsed-target').hasClass('ffc-collapsed')).toBe(true);
	});

	it('reveals the sub-options wrapper when the toggle is on', () => {
		window.$('#ffc_csv_public_enabled').prop('checked', true).trigger('change');
		expect(window.$('.ffc-collapsed-target').hasClass('ffc-collapsed')).toBe(false);
	});
});

describe('admin device-limit toggle', () => {
	it('master checkbox stays server-disabled when global subsystem is off', async () => {
		// When global is off, PHP renders the master with `disabled` attr.
		// JS does not need to enforce it; we just verify the static state.
		document.body.innerHTML = `
			<button id="ffc-migrations-btn"></button>
			<div id="ffc-migrations-menu"></div>
			<table class="ffc-device-limit-table">
				<tr><td><input type="checkbox" id="ffc_device_limit_enabled" disabled></td></tr>
			</table>
			<div class="ffc-collapsed-target ffc-collapsed" data-ffc-master="ffc_device_limit_enabled">
				<input type="text" name="dl-sub">
			</div>
		`;
		loadScript('assets/js/ffc-admin.js');
		await new Promise((r) => setTimeout(r, 0));

		expect(window.$('#ffc_device_limit_enabled').prop('disabled')).toBe(true);
		expect(window.$('.ffc-collapsed-target').hasClass('ffc-collapsed')).toBe(true);
	});

	it('collapses the sub-options wrapper when the per-form toggle is off, reveals on', async () => {
		document.body.innerHTML = `
			<button id="ffc-migrations-btn"></button>
			<div id="ffc-migrations-menu"></div>
			<input type="checkbox" id="ffc_device_limit_enabled">
			<div class="ffc-collapsed-target ffc-collapsed" data-ffc-master="ffc_device_limit_enabled">
				<input type="text" name="dl-sub">
			</div>
		`;
		loadScript('assets/js/ffc-admin.js');
		await new Promise((r) => setTimeout(r, 0));

		window.$('#ffc_device_limit_enabled').prop('checked', true).trigger('change');
		expect(window.$('.ffc-collapsed-target').hasClass('ffc-collapsed')).toBe(false);

		window.$('#ffc_device_limit_enabled').prop('checked', false).trigger('change');
		expect(window.$('.ffc-collapsed-target').hasClass('ffc-collapsed')).toBe(true);
	});
});
