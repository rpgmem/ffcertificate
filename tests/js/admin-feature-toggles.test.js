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

// FFC.request — the migration target — wraps jQuery.post() in a Promise.
// Mock $.post and return a chain whose .done / .fail callback the
// FFC.request internals invoke.
function postChain(spec) {
	const chain = { done: () => chain, fail: () => chain };
	if (spec && 'done' in spec) chain.done = (cb) => { cb(spec.done); return chain; };
	if (spec && spec.fail) chain.fail = (cb) => { cb(spec.fail === true ? undefined : spec.fail); return chain; };
	return chain;
}

// Microtask flush so .then/.catch reactions run before assertions.
function flush() { return Promise.resolve().then(() => Promise.resolve()); }


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
		if (!window.FFC) { loadScript('assets/js/ffc-core.js'); }
		loadScript('assets/js/ffc-batched-export.js');
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

	it('appends generated codes to #ffc_generated_list on success', async () => {
		setupForm();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: { codes: 'AAAA-1111\nBBBB-2222\nCCCC-3333' } } }));

		window.$('#ffc_btn_generate_codes').trigger('click');
		await flush();

		expect(window.$('#ffc_generated_list').val()).toBe('AAAA-1111\nBBBB-2222\nCCCC-3333');
		expect(window.$('#ffc_gen_status').text()).toContain('tickets generated successfully!');
	});

	it('appends rather than replaces when the textarea already has codes', async () => {
		setupForm();
		window.$('#ffc_generated_list').val('EXISTING-CODE');
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: { codes: 'NEW-CODE' } } }));

		window.$('#ffc_btn_generate_codes').trigger('click');
		await flush();

		expect(window.$('#ffc_generated_list').val()).toBe('EXISTING-CODE\nNEW-CODE');
	});

	it('shows the field-not-found error when #ffc_generated_list is missing', async () => {
		document.body.innerHTML = `
			<input id="ffc_qty_codes" type="text" value="3">
			<span id="ffc_gen_status"></span>
			<button id="ffc_btn_generate_codes" type="button">Generate</button>
		`;
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: { codes: 'X' } } }));

		window.$('#ffc_btn_generate_codes').trigger('click');
		await flush();

		expect(window.$('#ffc_gen_status').text()).toContain('Error: codes field not found');
	});

	it('shows the inline error when the server returns success=false', async () => {
		setupForm();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false, data: 'Quota exceeded' } }));

		window.$('#ffc_btn_generate_codes').trigger('click');
		await flush();

		expect(window.$('#ffc_gen_status').text()).toContain('Error: Quota exceeded');
	});

	it('maps xhr.status=403 to the permission-denied string', async () => {
		setupForm();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: { status: 403, statusText: 'Forbidden' } }));

		window.$('#ffc_btn_generate_codes').trigger('click');
		await flush();

		expect(window.$('#ffc_gen_status').text()).toContain('Permission denied.');
	});

	it('maps xhr.status=400 to the bad-request string', async () => {
		setupForm();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: { status: 400, statusText: 'Bad Request' } }));

		window.$('#ffc_btn_generate_codes').trigger('click');
		await flush();

		expect(window.$('#ffc_gen_status').text()).toContain('Bad request.');
	});

	it('maps other xhr statuses to the templated server-error string', async () => {
		setupForm();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: { status: 502, statusText: 'Bad Gateway' } }));

		window.$('#ffc_btn_generate_codes').trigger('click');
		await flush();

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
		await flush();

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
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation((url, payload) => {
			calls.push(payload);
			return postChain({ done: responses.shift() });
		});

		window.$('#ffc-csv-export-btn').trigger('click');
		await flush();

		// At this point three $.post calls fired synchronously (mocked).
		expect(postSpy).toHaveBeenCalledTimes(3);
		expect(calls[0]).toMatchObject({
			action: 'ffc_export_start',
			type: 'submissions',
			nonce: 'exp-nonce',
			status: 'publish',
			form_ids: [1, 2],
		});
		expect(calls[1].action).toBe('ffc_export_batch');
		expect(calls[2].action).toBe('ffc_export_batch');

		// Progress is now driven by the shared overlay (#786): the bar reaches
		// 100% on the done batch and the download iframe is inserted.
		expect(window.$('.ffc-csv-progress-percent').text()).toContain('100');
		expect(window.$('iframe[src*="ffc_export_download"]').length).toBe(1);
	});

	it('shows the error message when start returns success=false', async () => {
		window.ffc_ajax = { export_nonce: 'n', strings: { error: 'Error' } };
		window.ajaxurl = '/wp-admin/admin-ajax.php';
		setupExport();

		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false, data: 'No rows match' } }));

		window.$('#ffc-csv-export-btn').trigger('click');
		await flush();

		expect(window.$('.ffc-csv-progress-error').text()).toBe('No rows match');
	});

	it('shows the error message when a batch returns success=false', async () => {
		window.ffc_ajax = { export_nonce: 'n', strings: {} };
		window.ajaxurl = '/wp-admin/admin-ajax.php';
		setupExport();

		const responses = [
			{ success: true, data: { job_id: 'job-1', total: 5 } },
			{ success: false, data: 'Batch failed' },
		];
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: responses.shift() }));

		window.$('#ffc-csv-export-btn').trigger('click');
		await flush();

		expect(window.$('.ffc-csv-progress-error').text()).toBe('Batch failed');
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
		if (!window.FFC) { loadScript('assets/js/ffc-core.js'); }
		loadScript('assets/js/ffc-batched-export.js');
		loadScript('assets/js/ffc-admin.js');
		await new Promise((r) => setTimeout(r, 0));
	});

	it('clicking #ffc-migrations-btn toggles the menu visible', async () => {
		window.$('#ffc-migrations-btn').trigger('click');
		await flush();
		expect(window.$('#ffc-migrations-menu').hasClass('ffc-visible')).toBe(true);

		window.$('#ffc-migrations-btn').trigger('click');
		await flush();
		expect(window.$('#ffc-migrations-menu').hasClass('ffc-visible')).toBe(false);
	});

	it('ESC closes the menu when it is open', async () => {
		window.$('#ffc-migrations-btn').trigger('click');
		await flush();
		expect(window.$('#ffc-migrations-menu').hasClass('ffc-visible')).toBe(true);

		const ev = window.$.Event('keydown', { key: 'Escape' });
		window.$(document).trigger(ev);
		await flush();

		expect(window.$('#ffc-migrations-menu').hasClass('ffc-visible')).toBe(false);
	});

	it('clicking the overlay closes the menu', async () => {
		window.$('#ffc-migrations-btn').trigger('click');
		await flush();
		expect(window.$('#ffc-migrations-menu').hasClass('ffc-visible')).toBe(true);

		window.$('#ffc-migrations-overlay').trigger('click');
		await flush();

		expect(window.$('#ffc-migrations-menu').hasClass('ffc-visible')).toBe(false);
	});

	it('clicking inside the menu keeps it open (stopPropagation)', async () => {
		window.$('#ffc-migrations-btn').trigger('click');
		await flush();
		expect(window.$('#ffc-migrations-menu').hasClass('ffc-visible')).toBe(true);

		// A click inside the menu must not bubble to the document handler
		// that would otherwise close it.
		window.$('#ffc-migrations-menu').trigger('click');
		await flush();
		expect(window.$('#ffc-migrations-menu').hasClass('ffc-visible')).toBe(true);
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
		if (!window.FFC) { loadScript('assets/js/ffc-core.js'); }
		loadScript('assets/js/ffc-batched-export.js');
		loadScript('assets/js/ffc-admin.js');
		await new Promise((r) => setTimeout(r, 0));
	});

	it('open button shows the overlay', async () => {
		document.getElementById('ffc-open-filter-overlay').click();
		expect(document.getElementById('ffc-filter-overlay').style.display).toBe('flex');
	});

	it('close button hides the overlay', async () => {
		document.getElementById('ffc-filter-overlay').style.display = 'flex';
		document.querySelector('.ffc-filter-overlay-close').click();
		expect(document.getElementById('ffc-filter-overlay').style.display).toBe('none');
	});

	it('clicking the overlay background hides it (target === overlay)', async () => {
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
		if (!window.FFC) { loadScript('assets/js/ffc-core.js'); }
		loadScript('assets/js/ffc-batched-export.js');
		loadScript('assets/js/ffc-admin.js');
		await new Promise((r) => setTimeout(r, 0));
	});

	it('enabling shows quiz settings and points', async () => {
		window.$('#ffc_quiz_enabled').prop('checked', true).trigger('change');
		await flush();

		expect(window.$('.ffc-quiz-setting').hasClass('ffc-hidden')).toBe(false);
		expect(window.$('.ffc-quiz-points').hasClass('ffc-hidden')).toBe(false);
	});

	it('disabling hides them again', async () => {
		window.$('#ffc_quiz_enabled').prop('checked', false).trigger('change');
		await flush();

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
		if (!window.FFC) { loadScript('assets/js/ffc-core.js'); }
		loadScript('assets/js/ffc-batched-export.js');
		loadScript('assets/js/ffc-admin.js');
		await new Promise((r) => setTimeout(r, 0));
	});

	it('collapses the sub-options wrapper when the toggle is off', async () => {
		window.$('#ffc_csv_public_enabled').prop('checked', false).trigger('change');
		await flush();
		expect(window.$('.ffc-collapsed-target').hasClass('ffc-collapsed')).toBe(true);
	});

	it('reveals the sub-options wrapper when the toggle is on', async () => {
		window.$('#ffc_csv_public_enabled').prop('checked', true).trigger('change');
		await flush();
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
		if (!window.FFC) { loadScript('assets/js/ffc-core.js'); }
		loadScript('assets/js/ffc-batched-export.js');
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
		if (!window.FFC) { loadScript('assets/js/ffc-core.js'); }
		loadScript('assets/js/ffc-batched-export.js');
		loadScript('assets/js/ffc-admin.js');
		await new Promise((r) => setTimeout(r, 0));

		window.$('#ffc_device_limit_enabled').prop('checked', true).trigger('change');
		await flush();
		expect(window.$('.ffc-collapsed-target').hasClass('ffc-collapsed')).toBe(false);

		window.$('#ffc_device_limit_enabled').prop('checked', false).trigger('change');
		await flush();
		expect(window.$('.ffc-collapsed-target').hasClass('ffc-collapsed')).toBe(true);
	});
});

// ----------------------------------------------------------------------
// Copy-to-clipboard buttons (.ffc-copy-link[data-ffc-copy-target])
// ----------------------------------------------------------------------

describe('admin copy-to-clipboard', () => {
	beforeAll(() => {
		window.ffc_ajax = { strings: { copied: 'Copied!', copyFailed: 'Copy failed' } };
		if (!window.FFC) { loadScript('assets/js/ffc-core.js'); }
		// The delegated click handler is bound on document at IIFE eval —
		// already present from prior suites' loads. Ensure at least one load.
		loadScript('assets/js/ffc-batched-export.js');
		loadScript('assets/js/ffc-admin.js');
	});

	beforeEach(() => {
		document.body.innerHTML = `
			<input id="copy-src" type="text" value="https://x.test/abc">
			<button class="ffc-copy-link" data-ffc-copy-target="#copy-src">Copy</button>
		`;
	});

	function getBtn() { return document.querySelector('.ffc-copy-link'); }

	it('returns early when the target source does not exist', () => {
		document.body.innerHTML = `
			<button class="ffc-copy-link" data-ffc-copy-target="#missing">Copy</button>
		`;
		// Should not throw and should not change the label.
		getBtn().click();
		expect(getBtn().textContent).toBe('Copy');
	});

	it('uses navigator.clipboard.writeText and shows Copied! on success', async () => {
		const writeText = vi.fn().mockResolvedValue();
		Object.defineProperty(window.navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		});
		getBtn().click();
		await flush();
		expect(writeText).toHaveBeenCalledWith('https://x.test/abc');
		expect(getBtn().textContent).toBe('Copied!');
	});

	it('shows Copy failed when navigator.clipboard.writeText rejects', async () => {
		const writeText = vi.fn().mockRejectedValue(new Error('nope'));
		Object.defineProperty(window.navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		});
		getBtn().click();
		await flush();
		expect(getBtn().textContent).toBe('Copy failed');
	});

	it('falls back to execCommand when navigator.clipboard is unavailable', () => {
		Object.defineProperty(window.navigator, 'clipboard', {
			value: undefined,
			configurable: true,
		});
		document.execCommand = vi.fn().mockReturnValue(true);
		getBtn().click();
		expect(document.execCommand).toHaveBeenCalledWith('copy');
		expect(getBtn().textContent).toBe('Copied!');
	});

	it('shows Copy failed when execCommand throws in the legacy fallback', () => {
		Object.defineProperty(window.navigator, 'clipboard', {
			value: undefined,
			configurable: true,
		});
		document.execCommand = vi.fn(() => { throw new Error('boom'); });
		getBtn().click();
		expect(getBtn().textContent).toBe('Copy failed');
	});
});

// ----------------------------------------------------------------------
// document.ready field-builder bootstrap block (lines ~232-243)
// ----------------------------------------------------------------------

describe('admin document.ready field-builder bootstrap', () => {
	it('warns when #ffc-fields-container is present but FieldBuilder is missing', async () => {
		document.body.innerHTML = `
			<button id="ffc-migrations-btn"></button>
			<div id="ffc-migrations-menu"></div>
			<div id="ffc-fields-container"></div>
		`;
		// Force the FieldBuilder lookup to fail.
		const savedFB = window.FFC && window.FFC.Admin ? window.FFC.Admin.FieldBuilder : undefined;
		if (window.FFC && window.FFC.Admin) { delete window.FFC.Admin.FieldBuilder; }
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
		if (!window.FFC) { loadScript('assets/js/ffc-core.js'); }
		loadScript('assets/js/ffc-batched-export.js');
		loadScript('assets/js/ffc-admin.js');
		await new Promise((r) => setTimeout(r, 0));
		expect(warn).toHaveBeenCalledWith('[FFC Admin] Field Builder module not loaded');
		if (savedFB && window.FFC && window.FFC.Admin) { window.FFC.Admin.FieldBuilder = savedFB; }
	});

	it('calls FieldBuilder.init when present', async () => {
		document.body.innerHTML = `
			<button id="ffc-migrations-btn"></button>
			<div id="ffc-migrations-menu"></div>
			<div id="ffc-fields-container"></div>
		`;
		window.FFC = window.FFC || {};
		window.FFC.Admin = window.FFC.Admin || {};
		const init = vi.fn();
		window.FFC.Admin.FieldBuilder = { init };
		loadScript('assets/js/ffc-batched-export.js');
		loadScript('assets/js/ffc-admin.js');
		await new Promise((r) => setTimeout(r, 0));
		expect(init).toHaveBeenCalled();
	});
});

// ----------------------------------------------------------------------
// Notification + status setTimeout fadeout branches (lines 57, 118)
// ----------------------------------------------------------------------

describe('admin notification + status timers', () => {
	beforeAll(() => {
		if (!window.FFC) { loadScript('assets/js/ffc-core.js'); }
		loadScript('assets/js/ffc-batched-export.js');
		loadScript('assets/js/ffc-admin.js');
	});

	it('showNotification auto-dismisses after the duration elapses', () => {
		vi.useFakeTimers();
		document.body.innerHTML = '<div id="wpbody-content"></div>';
		window.FFC.Admin.showNotification('hi', 'success', 1000);
		expect(document.querySelectorAll('.ffc-admin-notification').length).toBe(1);
		vi.advanceTimersByTime(1300);
		expect(document.querySelectorAll('.ffc-admin-notification').length).toBe(0);
		vi.useRealTimers();
	});

	it('showNotification dismiss button removes the notice on click', () => {
		window.$.fx.off = true;
		document.body.innerHTML = '<div id="wpbody-content"></div>';
		window.FFC.Admin.showNotification('hi', 'info', 0);
		document.querySelector('.notice-dismiss').click();
		expect(document.querySelectorAll('.ffc-admin-notification').length).toBe(0);
	});

	it('renders the notice after .wrap > h1 when present', () => {
		document.body.innerHTML = '<div class="wrap"><h1>Title</h1></div><div id="wpbody-content"></div>';
		window.FFC.Admin.showNotification('hi', 'warning', 0);
		const notif = document.querySelector('.ffc-admin-notification');
		expect(notif).not.toBeNull();
		expect(notif.previousElementSibling.tagName).toBe('H1');
	});

	it('clears the generate-codes success status after 5s', async () => {
		vi.useFakeTimers();
		window.ffc_ajax = {
			nonce: 'n',
			strings: { ticketsGeneratedSuccess: 'tickets generated successfully!' },
		};
		document.body.innerHTML = `
			<input id="ffc_qty_codes" type="text" value="2">
			<span id="ffc_gen_status"></span>
			<textarea id="ffc_generated_list"></textarea>
			<button id="ffc_btn_generate_codes" type="button">Generate</button>
		`;
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: { codes: 'X' } } }));
		window.$('#ffc_btn_generate_codes').trigger('click');
		// Allow the resolved promise reaction (timers are faked but
		// microtasks still flush).
		await Promise.resolve();
		await Promise.resolve();
		expect(window.$('#ffc_gen_status').text()).not.toBe('');
		vi.advanceTimersByTime(5000);
		expect(window.$('#ffc_gen_status').text()).toBe('');
		vi.useRealTimers();
	});
});

// ----------------------------------------------------------------------
// CSV export — connection-error (non-fromServer) branches (lines 211, 223)
// ----------------------------------------------------------------------

describe('admin CSV export — connection errors', () => {
	function setupExport() {
		document.body.innerHTML = `
			<button id="ffc-migrations-btn"></button>
			<div id="ffc-migrations-menu"></div>
			<button id="ffc-csv-export-btn" data-form-ids='[]' data-status="publish">Export</button>
			<span id="ffc-csv-export-progress" style="display:none"></span>
		`;
	}

	beforeAll(() => {
		if (!window.FFC) { loadScript('assets/js/ffc-core.js'); }
		loadScript('assets/js/ffc-batched-export.js');
		loadScript('assets/js/ffc-admin.js');
	});

	it('shows the connection-error string when start rejects without fromServer', async () => {
		window.ffc_ajax = { export_nonce: 'n', strings: { connectionError: 'Connection error.' } };
		window.ajaxurl = '/wp-admin/admin-ajax.php';
		setupExport();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: { status: 0 } }));
		window.$('#ffc-csv-export-btn').trigger('click');
		await flush();
		expect(window.$('.ffc-csv-progress-error').text()).toBe('Connection error.');
	});

	it('shows the connection-error string when a batch rejects without fromServer', async () => {
		window.ffc_ajax = { export_nonce: 'n', strings: { connectionError: 'Connection error.' } };
		window.ajaxurl = '/wp-admin/admin-ajax.php';
		setupExport();
		vi.spyOn(window.$, 'post').mockImplementation((url, payload) => {
			if (payload.action === 'ffc_export_start') {
				return postChain({ done: { success: true, data: { job_id: 'j', total: 5 } } });
			}
			return postChain({ fail: { status: 0 } });
		});
		window.$('#ffc-csv-export-btn').trigger('click');
		for (let i = 0; i < 6; i++) { await Promise.resolve(); }
		expect(window.$('.ffc-csv-progress-error').text()).toBe('Connection error.');
	});

	it('completes the batch-done branch including the deferred iframe cleanup', async () => {
		window.ffc_ajax = {
			export_nonce: 'n',
			strings: { exportProgress: 'Exporting %1$d/%2$d…', exportDone: 'Done!' },
		};
		window.ajaxurl = '/wp-admin/admin-ajax.php';
		window.$.fx.off = true;
		setupExport();
		// Key responses by action so stacked delegated handlers (one per
		// prior admin.js load) all resolve consistently rather than racing
		// over a shift()-based queue.
		vi.spyOn(window.$, 'post').mockImplementation((url, payload) => {
			if (payload.action === 'ffc_export_start') {
				return postChain({ done: { success: true, data: { job_id: 'j', total: 3 } } });
			}
			return postChain({ done: { success: true, data: { processed: 3, done: true } } });
		});
		window.$('#ffc-csv-export-btn').trigger('click');
		for (let i = 0; i < 8; i++) { await Promise.resolve(); }
		expect(window.$('iframe[src*="ffc_export_download"]').length).toBeGreaterThanOrEqual(1);
		// While the overlay is up, its status shows the done text (mapped to the
		// "downloading" status). The 2000ms deferred block then hides the overlay
		// and removes the iframe (#786).
		expect(window.$('.ffc-csv-progress-status').text()).toContain('Done!');
		await new Promise((r) => setTimeout(r, 2200));
		expect(window.$('.ffc-csv-progress-overlay').length).toBe(0);
	});
});
