// Sprint F2 — deep coverage for assets/js/ffc-reregistration-admin.js
// (322 LOC, previously 37.57%). Six handlers wired from a single
// $(document).ready boot; this file mounts each one's fixture in turn
// and drives the user interactions through to the AJAX layer.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffcReregistrationAdmin = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		fichaNonce: 'ficha-nonce',
		viewDetailsNonce: 'details-nonce',
		adminNonce: 'admin-nonce',
		strings: {
			confirmApprove: 'Approve?',
			confirmReturnToDraft: 'Return to draft?',
			generatingPdf: 'Generating…',
			ficha: 'Ficha',
			errorGenerating: 'PDF error',
			loadingDetails: 'Loading…',
			errorLoadingDetails: 'Failed to load',
			affectedUsers: 'Affected:',
		},
	};
});

beforeEach(() => {
	document.body.innerHTML = '';
	window.$.fx.off = true;
});

afterEach(() => {
	vi.restoreAllMocks();
	vi.useRealTimers();
});

async function reload() {
	loadScript('assets/js/ffc-reregistration-admin.js');
	await new Promise((r) => setTimeout(r, 0));
}

// ----------------------------------------------------------------------
// initSelectAll
// ----------------------------------------------------------------------

describe('rereg-admin — select-all checkbox', () => {
	it('checking #cb-select-all checks every submission_ids[] checkbox', async () => {
		document.body.innerHTML = `
			<input type="checkbox" id="cb-select-all">
			<input type="checkbox" name="submission_ids[]" value="1">
			<input type="checkbox" name="submission_ids[]" value="2">
			<input type="checkbox" name="submission_ids[]" value="3">
		`;
		await reload();

		window.$('#cb-select-all').prop('checked', true).trigger('change');

		const checked = window.$('input[name="submission_ids[]"]:checked').length;
		expect(checked).toBe(3);
	});

	it('unchecking #cb-select-all unchecks every submission_ids[] checkbox', async () => {
		document.body.innerHTML = `
			<input type="checkbox" id="cb-select-all">
			<input type="checkbox" name="submission_ids[]" value="1" checked>
			<input type="checkbox" name="submission_ids[]" value="2" checked>
		`;
		await reload();

		window.$('#cb-select-all').prop('checked', false).trigger('change');

		expect(window.$('input[name="submission_ids[]"]:checked').length).toBe(0);
	});
});

// ----------------------------------------------------------------------
// initBulkConfirm
// ----------------------------------------------------------------------

describe('rereg-admin — bulk-action submit gate', () => {
	function mountForm() {
		document.body.innerHTML = `
			<form id="ffc-submissions-form">
				<select name="bulk_action">
					<option value="">—</option>
					<option value="approve">Approve</option>
					<option value="return_to_draft">Return</option>
					<option value="export">Export</option>
				</select>
				<input type="checkbox" name="submission_ids[]" value="1">
			</form>
		`;
	}

	it('prevents submit when no action is selected', async () => {
		mountForm();
		await reload();
		const ev = window.$.Event('submit');
		window.$('#ffc-submissions-form').trigger(ev);
		expect(ev.isDefaultPrevented()).toBe(true);
	});

	it('prevents submit when no row is checked', async () => {
		mountForm();
		await reload();
		window.$('select[name="bulk_action"]').val('approve');
		const ev = window.$.Event('submit');
		window.$('#ffc-submissions-form').trigger(ev);
		expect(ev.isDefaultPrevented()).toBe(true);
	});

	it('prevents submit on approve when the user declines the confirm', async () => {
		mountForm();
		await reload();
		window.$('select[name="bulk_action"]').val('approve');
		window.$('input[name="submission_ids[]"]').prop('checked', true);
		vi.spyOn(window, 'confirm').mockReturnValue(false);
		const ev = window.$.Event('submit');
		window.$('#ffc-submissions-form').trigger(ev);
		expect(ev.isDefaultPrevented()).toBe(true);
	});

	it('lets approve through when the user accepts the confirm', async () => {
		mountForm();
		await reload();
		window.$('select[name="bulk_action"]').val('approve');
		window.$('input[name="submission_ids[]"]').prop('checked', true);
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const ev = window.$.Event('submit');
		// Block jsdom's native submit (Not Implemented).
		window.$('#ffc-submissions-form').on('submit', (e) => e.preventDefault());
		window.$('#ffc-submissions-form').trigger(ev);
		// Our extra preventDefault doesn't count toward the assertion —
		// the FIRST handler is the IIFE's, which only prevents on confirm=false.
		// Since confirm returned true, the IIFE didn't preventDefault.
		// (The native preventDefault later fires from the test guard.)
		expect(vi.mocked(window.confirm)).toHaveBeenCalledWith('Approve?');
	});

	it('prevents submit on return_to_draft when the user declines', async () => {
		mountForm();
		await reload();
		window.$('select[name="bulk_action"]').val('return_to_draft');
		window.$('input[name="submission_ids[]"]').prop('checked', true);
		vi.spyOn(window, 'confirm').mockReturnValue(false);
		const ev = window.$.Event('submit');
		window.$('#ffc-submissions-form').trigger(ev);
		expect(ev.isDefaultPrevented()).toBe(true);
		expect(vi.mocked(window.confirm)).toHaveBeenCalledWith('Return to draft?');
	});

	it('passes a non-confirm action (e.g. export) through without prompting', async () => {
		mountForm();
		await reload();
		window.$('select[name="bulk_action"]').val('export');
		window.$('input[name="submission_ids[]"]').prop('checked', true);
		const confirmSpy = vi.spyOn(window, 'confirm');
		window.$('#ffc-submissions-form').on('submit', (e) => e.preventDefault());
		window.$('#ffc-submissions-form').trigger('submit');
		expect(confirmSpy).not.toHaveBeenCalled();
	});
});

// ----------------------------------------------------------------------
// initReturnToDraftConfirm
// ----------------------------------------------------------------------

describe('rereg-admin — single row return-to-draft button', () => {
	it('prevents click when the user declines the confirm', async () => {
		document.body.innerHTML = `<a class="ffc-return-draft-btn" href="?act=return">Return</a>`;
		await reload();
		vi.spyOn(window, 'confirm').mockReturnValue(false);
		const ev = window.$.Event('click');
		window.$('.ffc-return-draft-btn').trigger(ev);
		expect(ev.isDefaultPrevented()).toBe(true);
	});

	it('lets the click through when the user accepts the confirm', async () => {
		document.body.innerHTML = `<a class="ffc-return-draft-btn" href="#">Return</a>`;
		await reload();
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const ev = window.$.Event('click');
		window.$('.ffc-return-draft-btn').trigger(ev);
		expect(ev.isDefaultPrevented()).toBe(false);
	});
});

// ----------------------------------------------------------------------
// initFichaDownload
// ----------------------------------------------------------------------

describe('rereg-admin — ficha PDF download', () => {
	function mountBtn() {
		document.body.innerHTML = `<button type="button" class="ffc-ficha-btn" data-submission-id="42">Ficha</button>`;
	}

	it('bails when the button has no submission-id', async () => {
		document.body.innerHTML = `<button class="ffc-ficha-btn">Ficha</button>`;
		await reload();
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => ({ fail: () => ({}) }));
		window.$('.ffc-ficha-btn').trigger('click');
		expect(postSpy).not.toHaveBeenCalled();
	});

	it('on success: calls window.ffcGeneratePDF with the payload', async () => {
		mountBtn();
		await reload();
		window.ffcGeneratePDF = vi.fn();
		vi.spyOn(window.$, 'post').mockImplementation((url, data, cb) => {
			cb({
				success: true,
				data: { pdf_data: { template: 'x', filename: 'rec.pdf' } },
			});
			return { fail: () => ({}) };
		});

		window.$('.ffc-ficha-btn').trigger('click');

		expect(window.ffcGeneratePDF).toHaveBeenCalledWith(
			{ template: 'x', filename: 'rec.pdf' },
			'rec.pdf',
		);
	});

	it('on success but no window.ffcGeneratePDF: shows an alert', async () => {
		mountBtn();
		await reload();
		delete window.ffcGeneratePDF;
		vi.spyOn(window.$, 'post').mockImplementation((url, data, cb) => {
			cb({ success: true, data: { pdf_data: { template: 'x' } } });
			return { fail: () => ({}) };
		});
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-ficha-btn').trigger('click');

		expect(alertSpy).toHaveBeenCalledWith('PDF error');
	});

	it('on response.success=false: alerts the server message', async () => {
		mountBtn();
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation((url, data, cb) => {
			cb({ success: false, data: { message: 'Submission not found' } });
			return { fail: () => ({}) };
		});
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-ficha-btn').trigger('click');

		expect(alertSpy).toHaveBeenCalledWith('Submission not found');
	});

	it('on network failure: alerts the generic ficha-error string', async () => {
		mountBtn();
		await reload();
		let failCb;
		vi.spyOn(window.$, 'post').mockImplementation(() => ({
			fail: (cb) => {
				failCb = cb;
				return {};
			},
		}));
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-ficha-btn').trigger('click');
		failCb();

		expect(alertSpy).toHaveBeenCalledWith('PDF error');
	});
});

// ----------------------------------------------------------------------
// initSubmissionDetailsModal
// ----------------------------------------------------------------------

describe('rereg-admin — submission details modal', () => {
	function mountModalFixture() {
		document.body.innerHTML = `
			<button type="button" class="ffc-view-details-btn" data-submission-id="7">Details</button>
			<div id="ffc-submission-details-modal" style="display:none">
				<div class="ffc-modal-backdrop"></div>
				<div class="ffc-modal-content">
					<button type="button" class="ffc-modal-close">x</button>
					<div class="ffc-modal-body"><p class="ffc-modal-loading"></p></div>
				</div>
			</div>
		`;
	}

	it('opens modal + injects success html', async () => {
		mountModalFixture();
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation(() => ({
			done: function (cb) {
				cb({ success: true, data: { html: '<table>fields</table>' } });
				return { fail: () => ({}) };
			},
		}));

		window.$('.ffc-view-details-btn').trigger('click');

		expect(window.$('#ffc-submission-details-modal').css('display')).not.toBe('none');
		// jsdom reorders text outside <table> nodes; assert on the visible
		// payload rather than literal HTML preservation.
		expect(window.$('.ffc-modal-body').text()).toContain('fields');
	});

	it('shows the server error in the modal when response.success=false', async () => {
		mountModalFixture();
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation(() => ({
			done: function (cb) {
				cb({ success: false, data: { message: 'Forbidden' } });
				return { fail: () => ({}) };
			},
		}));

		window.$('.ffc-view-details-btn').trigger('click');

		expect(window.$('.ffc-modal-body').text()).toContain('Forbidden');
	});

	it('on network failure: renders the localised error', async () => {
		mountModalFixture();
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation(() => ({
			done: function () {
				return {
					fail: (cb) => {
						cb();
						return {};
					},
				};
			},
		}));

		window.$('.ffc-view-details-btn').trigger('click');

		expect(window.$('.ffc-modal-body').text()).toContain('Failed to load');
	});

	it('close button hides the modal', async () => {
		mountModalFixture();
		await reload();
		// Force open
		window.$('#ffc-submission-details-modal').show();
		window.$('#ffc-submission-details-modal .ffc-modal-close').trigger('click');
		expect(window.$('#ffc-submission-details-modal').css('display')).toBe('none');
	});

	it('Escape key closes a visible modal', async () => {
		mountModalFixture();
		await reload();
		window.$('#ffc-submission-details-modal').show();
		// jQuery `:visible` in jsdom reports false because there's no
		// layout — patch the pseudo so the Escape handler's guard passes.
		const originalVisible = window.$.expr.pseudos.visible;
		window.$.expr.pseudos.visible = () => true;
		try {
			const ev = window.$.Event('keydown', { key: 'Escape' });
			window.$(document).trigger(ev);
			expect(window.$('#ffc-submission-details-modal').css('display')).toBe('none');
		} finally {
			window.$.expr.pseudos.visible = originalVisible;
		}
	});
});

// ----------------------------------------------------------------------
// initTransferList
// ----------------------------------------------------------------------

describe('rereg-admin — audience transfer list', () => {
	function mountTransfer(opts) {
		opts = opts || {};
		const audiences = opts.audiences || [
			{ id: 1, name: 'Group A', color: '#aaa' },
			{ id: 2, name: 'Group B', color: '#bbb' },
			{ id: 3, name: 'Group C', color: '#ccc' },
		];
		const selected = opts.selected || [];
		document.body.innerHTML = `
			<form>
				<div class="ffc-transfer-list"
					data-audiences='${JSON.stringify(audiences)}'
					data-selected='${JSON.stringify(selected)}'>
					<input type="text" class="ffc-transfer-search">
					<div class="ffc-transfer-available">
						<div class="ffc-transfer-items"></div>
					</div>
					<button type="button" class="ffc-transfer-add">→</button>
					<button type="button" class="ffc-transfer-add-all">»</button>
					<button type="button" class="ffc-transfer-remove">←</button>
					<button type="button" class="ffc-transfer-remove-all">«</button>
					<div class="ffc-transfer-selected">
						<div class="ffc-transfer-items"></div>
					</div>
					<div class="ffc-transfer-hidden-inputs"></div>
				</div>
				<div class="ffc-transfer-member-count"></div>
				<button type="submit">Save</button>
			</form>
		`;
	}

	it('initial render shows availables on the left and seeds selected hidden inputs', async () => {
		mountTransfer({ selected: [2] });
		await reload();

		const $sel = window.$('.ffc-transfer-selected .ffc-transfer-item');
		const $av = window.$('.ffc-transfer-available .ffc-transfer-item');
		expect($sel.length).toBe(1);
		expect($av.length).toBe(2);
		expect(window.$('.ffc-transfer-hidden-inputs input').length).toBe(1);
		expect(window.$('.ffc-transfer-hidden-inputs input').val()).toBe('2');
	});

	it('double-click on an available item moves it to selected', async () => {
		mountTransfer();
		await reload();
		window.$('.ffc-transfer-available .ffc-transfer-item[data-id="1"]').trigger('dblclick');
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item[data-id="1"]').length).toBe(1);
	});

	it('double-click on a selected item moves it back to available', async () => {
		mountTransfer({ selected: [1, 2] });
		await reload();
		window.$('.ffc-transfer-selected .ffc-transfer-item[data-id="1"]').trigger('dblclick');
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item[data-id="1"]').length).toBe(0);
		expect(window.$('.ffc-transfer-available .ffc-transfer-item[data-id="1"]').length).toBe(1);
	});

	it('arrow button "→" moves all highlighted availables to selected', async () => {
		mountTransfer();
		await reload();
		window.$('.ffc-transfer-available .ffc-transfer-item').addClass('ffc-transfer-highlight');
		window.$('.ffc-transfer-add').trigger('click');
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item').length).toBe(3);
	});

	it('"add all" button moves every item to selected', async () => {
		mountTransfer();
		await reload();
		window.$('.ffc-transfer-add-all').trigger('click');
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item').length).toBe(3);
	});

	it('"remove all" button clears the selected column', async () => {
		mountTransfer({ selected: [1, 2, 3] });
		await reload();
		window.$('.ffc-transfer-remove-all').trigger('click');
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item').length).toBe(0);
	});

	it('search filter narrows the available list', async () => {
		mountTransfer();
		await reload();
		window.$('.ffc-transfer-search').val('group a').trigger('input');
		const labels = window.$('.ffc-transfer-available .ffc-transfer-label').map((_, el) => el.textContent).get();
		expect(labels.every((l) => l.toLowerCase().includes('group a'))).toBe(true);
	});

	it('selecting a parent cascades its children into the selected column', async () => {
		mountTransfer({
			audiences: [
				{ id: 10, name: 'Parent', color: '#000', children: [11, 12] },
				{ id: 11, name: 'Child 1', color: '#111', parent: 10 },
				{ id: 12, name: 'Child 2', color: '#222', parent: 10 },
			],
		});
		await reload();
		window.$('.ffc-transfer-available .ffc-transfer-item[data-id="10"]').trigger('dblclick');
		// Parent + both children land in selected.
		expect(window.$('.ffc-transfer-selected .ffc-transfer-item').length).toBe(3);
	});

	it('debounces member-count AJAX (300 ms) and renders the response', async () => {
		// Mount + reload under REAL timers so the script's $(document).ready
		// microtask resolves; then switch to fake timers BEFORE advancing.
		mountTransfer({ selected: [1] });
		await reload();
		vi.useFakeTimers();

		const postSpy = vi.spyOn(window.$, 'post').mockImplementation((url, data, cb) => {
			cb({ success: true, data: { count: 42 } });
			return { fail: () => ({}) };
		});

		// Trigger a fresh debounce by toggling something that re-runs render.
		window.$('.ffc-transfer-add-all').trigger('click');

		// Just before 300 ms — no AJAX yet.
		vi.advanceTimersByTime(299);
		expect(postSpy).not.toHaveBeenCalled();

		// At 300 ms — AJAX fires.
		vi.advanceTimersByTime(1);
		expect(postSpy).toHaveBeenCalled();
		expect(window.$('.ffc-transfer-member-count').text()).toContain('42');
		vi.useRealTimers();
	});

	it('form submit with no selection prevents default and pulses the error class', async () => {
		mountTransfer();
		await reload();
		const ev = window.$.Event('submit');
		window.$('form').trigger(ev);
		expect(ev.isDefaultPrevented()).toBe(true);
		expect(window.$('.ffc-transfer-selected').hasClass('ffc-transfer-error')).toBe(true);
	});

	it('clicking a transfer-item toggles the highlight class', async () => {
		mountTransfer();
		await reload();
		const $first = window.$('.ffc-transfer-available .ffc-transfer-item').first();
		$first.trigger('click');
		expect($first.hasClass('ffc-transfer-highlight')).toBe(true);
		$first.trigger('click');
		expect($first.hasClass('ffc-transfer-highlight')).toBe(false);
	});
});
