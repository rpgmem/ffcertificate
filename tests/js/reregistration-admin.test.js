// Sprint F2 — deep coverage for assets/js/ffc-reregistration-admin.js
// (322 LOC, previously 37.57%). Six handlers wired from a single
// $(document).ready boot; this file mounts each one's fixture in turn
// and drives the user interactions through to the AJAX layer.
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


beforeAll(() => {
	window.ffcReregistrationAdmin = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		recordNonce: 'record-nonce',
		viewDetailsNonce: 'details-nonce',
		adminNonce: 'admin-nonce',
		exportNonce: 'export-nonce',
		strings: {
			exportPreparing: 'Preparing…',
			exportProgress: 'Exporting %1$d/%2$d…',
			exportDone: 'Done!',
			exportError: 'An error occurred.',
			confirmApprove: 'Approve?',
			reviewRejectTitle: 'Reject submission',
			reviewReturnTitle: 'Return to draft',
			reviewRejectIntro: 'Reject %d.',
			reviewReturnIntro: 'Return %d.',
			generatingPdf: 'Generating…',
			record: 'Record',
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
	if (!window.FFC) { loadScript('assets/js/ffc-core.js'); }
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
		await flush();

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
		await flush();

		expect(window.$('input[name="submission_ids[]"]:checked').length).toBe(0);
	});
});

// ----------------------------------------------------------------------
// initBulkConfirm
// ----------------------------------------------------------------------

describe('rereg-admin — bulk-action submit gate', () => {
	function mountForm() {
		document.body.innerHTML = reviewModal() + `
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
		await flush();
		expect(ev.isDefaultPrevented()).toBe(true);
	});

	it('prevents submit when no row is checked', async () => {
		mountForm();
		await reload();
		window.$('select[name="bulk_action"]').val('approve');
		const ev = window.$.Event('submit');
		window.$('#ffc-submissions-form').trigger(ev);
		await flush();
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
		await flush();
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
		await flush();
		// Our extra preventDefault doesn't count toward the assertion —
		// the FIRST handler is the IIFE's, which only prevents on confirm=false.
		// Since confirm returned true, the IIFE didn't preventDefault.
		// (The native preventDefault later fires from the test guard.)
		expect(vi.mocked(window.confirm)).toHaveBeenCalledWith('Approve?');
	});

	it('sends return_to_draft to the review modal with the selection (#2242)', async () => {
		mountForm();
		await reload();
		window.$('select[name="bulk_action"]').val('return_to_draft');
		window.$('input[name="submission_ids[]"]').prop('checked', true);
		const confirmSpy = vi.spyOn(window, 'confirm');
		const ev = window.$.Event('submit');
		window.$('#ffc-submissions-form').trigger(ev);
		await flush();
		expect(ev.isDefaultPrevented()).toBe(true);
		expect(confirmSpy).not.toHaveBeenCalled();
		const $modal = window.$('#ffc-review-decision-modal');
		expect($modal.css('display')).not.toBe('none');
		expect($modal.find('input[name="decision"]').val()).toBe('return_to_draft');
		expect($modal.find('input[name="submission_ids[]"]').map((i, el) => el.value).get()).toEqual(['1']);
		expect($modal.find('.ffc-review-decision-intro').text()).toBe('Return 1.');
	});

	it('passes a non-confirm action (e.g. export) through without prompting', async () => {
		mountForm();
		await reload();
		window.$('select[name="bulk_action"]').val('export');
		window.$('input[name="submission_ids[]"]').prop('checked', true);
		const confirmSpy = vi.spyOn(window, 'confirm');
		window.$('#ffc-submissions-form').on('submit', (e) => e.preventDefault());
		window.$('#ffc-submissions-form').trigger('submit');
		await flush();
		expect(confirmSpy).not.toHaveBeenCalled();
	});
});

// ----------------------------------------------------------------------
// initReviewDecisionModal (#2242)
// ----------------------------------------------------------------------

function reviewModal() {
	return `
		<div id="ffc-review-decision-modal" class="ffc-rereg-modal" style="display:none;">
			<div class="ffc-rereg-modal-backdrop"></div>
			<div class="ffc-rereg-modal-content">
				<h2 id="ffc-review-decision-title"></h2>
				<form method="post">
					<input type="hidden" name="decision" value="">
					<div class="ffc-review-decision-ids"></div>
					<p class="ffc-review-decision-intro"></p>
					<textarea name="review_reason"></textarea>
					<button type="button" class="ffc-rereg-modal-close">Cancel</button>
					<button type="submit" class="ffc-review-decision-confirm" data-decision="reject">Reject</button>
					<button type="submit" class="ffc-review-decision-confirm" data-decision="return_to_draft">Return</button>
				</form>
			</div>
		</div>`;
}

describe('rereg-admin — review decision modal', () => {
	it('opens for one row with the decision, a cleared reason and its own confirm button', async () => {
		document.body.innerHTML = reviewModal()
			+ '<button type="button" class="ffc-review-decision-btn" data-decision="reject" data-submission-id="42">Reject</button>';
		await reload();
		const $modal = window.$('#ffc-review-decision-modal');
		$modal.find('textarea').val('left over');
		window.$('.ffc-review-decision-btn').trigger('click');
		await flush();
		expect($modal.css('display')).not.toBe('none');
		expect(window.$('#ffc-review-decision-title').text()).toBe('Reject submission');
		expect($modal.find('input[name="decision"]').val()).toBe('reject');
		expect($modal.find('input[name="submission_ids[]"]').map((i, el) => el.value).get()).toEqual(['42']);
		expect($modal.find('textarea').val()).toBe('');
		expect($modal.find('.ffc-review-decision-confirm[data-decision="reject"]').css('display')).not.toBe('none');
		expect($modal.find('.ffc-review-decision-confirm[data-decision="return_to_draft"]').css('display')).toBe('none');
		expect(document.body.classList.contains('ffc-rereg-modal-open')).toBe(true);
	});

	it('replaces the ids of a previous opening', async () => {
		document.body.innerHTML = reviewModal()
			+ '<button type="button" class="ffc-review-decision-btn" data-decision="reject" data-submission-id="1">A</button>'
			+ '<button type="button" class="ffc-review-decision-btn" data-decision="return_to_draft" data-submission-id="2">B</button>';
		await reload();
		window.$('.ffc-review-decision-btn').eq(0).trigger('click');
		window.$('.ffc-review-decision-btn').eq(1).trigger('click');
		await flush();
		const $modal = window.$('#ffc-review-decision-modal');
		expect($modal.find('input[name="submission_ids[]"]').map((i, el) => el.value).get()).toEqual(['2']);
		expect(window.$('#ffc-review-decision-title').text()).toBe('Return to draft');
	});

	it('closes on Cancel, the backdrop and Escape', async () => {
		document.body.innerHTML = reviewModal()
			+ '<button type="button" class="ffc-review-decision-btn" data-decision="reject" data-submission-id="42">Reject</button>';
		await reload();
		const $modal = window.$('#ffc-review-decision-modal');
		for (const close of [
			() => $modal.find('.ffc-rereg-modal-close').trigger('click'),
			() => $modal.find('.ffc-rereg-modal-backdrop').trigger('click'),
			() => window.$(document).trigger(window.$.Event('keydown', { key: 'Escape' })),
		]) {
			window.$('.ffc-review-decision-btn').trigger('click');
			expect($modal.css('display')).not.toBe('none');
			close();
			await flush();
			expect($modal.css('display')).toBe('none');
		}
		expect(document.body.classList.contains('ffc-rereg-modal-open')).toBe(false);
	});
});

// ----------------------------------------------------------------------
// initRecordDownload
// ----------------------------------------------------------------------

describe('rereg-admin — record PDF download', () => {
	function mountBtn() {
		document.body.innerHTML = `<button type="button" class="ffc-record-btn" data-submission-id="42">Record</button>`;
	}

	it('bails when the button has no submission-id', async () => {
		document.body.innerHTML = `<button class="ffc-record-btn">Record</button>`;
		await reload();
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));
		window.$('.ffc-record-btn').trigger('click');
		await flush();
		expect(postSpy).not.toHaveBeenCalled();
	});

	it('on success: calls window.ffcGeneratePDF with the payload', async () => {
		mountBtn();
		await reload();
		window.ffcGeneratePDF = vi.fn();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: { pdf_data: { template: 'x', filename: 'rec.pdf' } },
			} }));

		window.$('.ffc-record-btn').trigger('click');
		await flush();

		expect(window.ffcGeneratePDF).toHaveBeenCalledWith(
			{ template: 'x', filename: 'rec.pdf' },
			'rec.pdf',
		);
		// Restored as plain text: the icon is the button's class (#1904).
		const btn = document.querySelector('.ffc-record-btn');
		expect(btn.disabled).toBe(false);
		expect(btn.children.length).toBe(0);
	});

	it('on success but no window.ffcGeneratePDF: shows an alert', async () => {
		mountBtn();
		await reload();
		delete window.ffcGeneratePDF;
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: { pdf_data: { template: 'x' } } } }));
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-record-btn').trigger('click');
		await flush();

		expect(alertSpy).toHaveBeenCalledWith('PDF error');
	});

	it('on response.success=false: alerts the server message', async () => {
		mountBtn();
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false, data: { message: 'Submission not found' } } }));
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-record-btn').trigger('click');
		await flush();

		expect(alertSpy).toHaveBeenCalledWith('Submission not found');
	});

	it('on network failure: alerts the generic record-error string', async () => {
		mountBtn();
		await reload();
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: true }));
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('.ffc-record-btn').trigger('click');
		await flush();

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
				<div class="ffc-rereg-modal-backdrop"></div>
				<div class="ffc-rereg-modal-content">
					<button type="button" class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-cross ffc-rereg-modal-close" aria-label="Close"></button>
					<div class="ffc-rereg-modal-body"><p class="ffc-rereg-modal-loading"></p></div>
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
		await flush();

		expect(window.$('#ffc-submission-details-modal').css('display')).not.toBe('none');
		// jsdom reorders text outside <table> nodes; assert on the visible
		// payload rather than literal HTML preservation.
		expect(window.$('.ffc-rereg-modal-body').text()).toContain('fields');
	});

	it('bails on a view-details click when the button has no submission id', async () => {
		document.body.innerHTML = `
			<button type="button" class="ffc-view-details-btn">Details</button>
			<div id="ffc-submission-details-modal" style="display:none">
				<div class="ffc-rereg-modal-body"><p class="ffc-rereg-modal-loading"></p></div>
			</div>
		`;
		await reload();
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => ({ done: () => ({ fail: () => ({}) }) }));

		window.$('.ffc-view-details-btn').trigger('click');
		await flush();

		expect(postSpy).not.toHaveBeenCalled();
		// Modal stays hidden.
		expect(window.$('#ffc-submission-details-modal').css('display')).toBe('none');
	});

	it('renders the fallback error when the response resolves without html', async () => {
		mountModalFixture();
		await reload();
		// Resolves successfully but with no `html` field → else branch.
		vi.spyOn(window.$, 'post').mockImplementation(() => ({
			done: function (cb) {
				cb({ success: true, data: { other: 'value' } });
				return { fail: () => ({}) };
			},
		}));

		window.$('.ffc-view-details-btn').trigger('click');
		await flush();

		expect(window.$('.ffc-rereg-modal-body').text()).toContain('Failed to load');
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
		await flush();

		expect(window.$('.ffc-rereg-modal-body').text()).toContain('Forbidden');
	});

	it('writes the server error as text, never as markup (#1843)', async () => {
		mountModalFixture();
		await reload();
		window.__ffcPwned = false;
		vi.spyOn(window.$, 'post').mockImplementation(() => ({
			done: function (cb) {
				cb({ success: false, data: { message: '<img src=x onerror="window.__ffcPwned=true"><b>bold</b>' } });
				return { fail: () => ({}) };
			},
		}));

		window.$('.ffc-view-details-btn').trigger('click');
		await flush();

		const body = document.querySelector('.ffc-rereg-modal-body');
		expect(body.querySelector('img')).toBeNull();
		expect(body.querySelector('b')).toBeNull();
		expect(body.textContent).toContain('<img src=x');
		expect(window.__ffcPwned).toBe(false);
		// The standard component, not a core .notice painted as a paragraph.
		const notice = body.querySelector('.ffc-notice.ffc-notice-danger');
		expect(notice).not.toBeNull();
		expect(notice.getAttribute('role')).toBe('alert');
		expect(notice.querySelector('.ffc-notice-icon').classList.contains('ffc-icon-error')).toBe(true);
		expect(body.querySelector('.notice')).toBeNull();
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
		await flush();

		expect(window.$('.ffc-rereg-modal-body').text()).toContain('Failed to load');
	});

	it('close button hides the modal', async () => {
		mountModalFixture();
		await reload();
		// Force open
		window.$('#ffc-submission-details-modal').show();
		window.$('#ffc-submission-details-modal .ffc-rereg-modal-close').trigger('click');
		await flush();
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
			await flush();
			expect(window.$('#ffc-submission-details-modal').css('display')).toBe('none');
		} finally {
			window.$.expr.pseudos.visible = originalVisible;
		}
	});
});

// ----------------------------------------------------------------------
// Member count under the shared audience picker (#1648)
// ----------------------------------------------------------------------

describe('rereg-admin — member count under the audience picker', () => {
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
					data-selected='${JSON.stringify(selected)}'
					data-field-name="rereg_audience_ids[]" data-required="1">
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

	// The picker is its own script now; the campaign screen only counts.
	async function reloadWithPicker() {
		await reload();
		loadScript('assets/js/ffc-audience-transfer-list.js');
		await new Promise((r) => setTimeout(r, 0));
	}

	it('debounces member-count AJAX (300 ms) and renders the response', async () => {
		// Mount + reload under REAL timers so the script's $(document).ready
		// microtask resolves; then switch to fake timers BEFORE advancing.
		mountTransfer({ selected: [1] });
		await reloadWithPicker();
		vi.useFakeTimers();

		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: { count: 42 } } }));

		// Trigger a fresh debounce by toggling something that re-runs render.
		window.$('.ffc-transfer-add-all').trigger('click');
		await flush();

		// Just before 300 ms — no AJAX yet.
		vi.advanceTimersByTime(299);
		expect(postSpy).not.toHaveBeenCalled();

		// At 300 ms — AJAX fires.
		vi.advanceTimersByTime(1);
		await flush();
		expect(postSpy).toHaveBeenCalled();
		expect(window.$('.ffc-transfer-member-count').text()).toContain('42');
		vi.useRealTimers();
	});

	it('clears the count when nothing is selected', async () => {
		mountTransfer({ selected: [] });
		window.$('.ffc-transfer-member-count').html('stale');
		await reloadWithPicker();
		expect(window.$('.ffc-transfer-member-count').html()).toBe('');
	});
});

// ----------------------------------------------------------------------
// initCsvExport — batched CSV export (#772)
// ----------------------------------------------------------------------

describe('rereg-admin — CSV export', () => {
	afterEach(() => {
		delete window.FFCBatchedExport;
	});

	it('sends the filters the list was drawn with (#2242)', async () => {
		const runSpy = vi.fn();
		window.FFCBatchedExport = { run: runSpy };
		document.body.innerHTML = `
			<button type="button" id="ffc-rereg-export-btn" data-id="7" data-sub-status="not_finished" data-search="123.456.789-09" data-audience="3">Export CSV</button>
		`;
		await reload();

		window.$('#ffc-rereg-export-btn').trigger('click');

		expect(runSpy.mock.calls[0][0].startData).toEqual({
			id: 7,
			sub_status: 'not_finished',
			s: '123.456.789-09',
			audience: '3'
		});
	});

	it('drives window.FFCBatchedExport.run with type + campaign id + job nonce', async () => {
		const runSpy = vi.fn();
		window.FFCBatchedExport = { run: runSpy };
		document.body.innerHTML = `
			<button type="button" id="ffc-rereg-export-btn" data-id="7">Export CSV</button>
			<span id="ffc-rereg-export-progress" style="display:none;"></span>
		`;
		await reload();

		window.$('#ffc-rereg-export-btn').trigger('click');

		// The bindEvents delegate lives on document (persists across reload()),
		// so it may fire more than once — assert it fired and inspect the first.
		expect(runSpy).toHaveBeenCalled();
		const arg = runSpy.mock.calls[0][0];
		expect(arg.type).toBe('reregistration');
		expect(arg.ajaxUrl).toBe('/wp-admin/admin-ajax.php');
		expect(arg.nonce).toBe('export-nonce');
		expect(arg.startData).toEqual({ id: 7, sub_status: '', s: '', audience: '' });
		// Progress is now driven by the shared overlay (#786): the caller passes
		// overlay:true + the button, not per-call callbacks.
		expect(arg.overlay).toBe(true);
		expect(arg.button).toBeTruthy();
	});

	it('is a no-op when the batched-export driver is unavailable', async () => {
		document.body.innerHTML = '<button type="button" id="ffc-rereg-export-btn" data-id="7">Export CSV</button>';
		await reload();
		expect(() => window.$('#ffc-rereg-export-btn').trigger('click')).not.toThrow();
	});
});
