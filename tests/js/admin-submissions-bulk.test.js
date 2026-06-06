// Tests for assets/js/ffc-admin-submissions-bulk.js — inline AJAX for
// the WP-list-table bulk form + per-row buttons on the Submissions
// list. Replaces the GET-redirect roundtrip with a JSON call and a
// row-fade-out animation.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'admin-nonce',
		strings: {},
	};
	// Avoid jQuery animation queueing so fadeOut applies immediately —
	// the test asserts on DOM state, not visual timing.
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-admin.js');
	loadScript('assets/js/ffc-admin-submissions-bulk.js');
	window.$.fx.off = true;
});

beforeEach(() => {
	window.ffcSubmissionsBulk = {
		nonce: 'subs-nonce',
		strings: {
			error:              'Action failed.',
			confirmDelete:      'Permanently delete this submission?',
			confirmBulkDelete:  'Permanently delete the selected submissions?',
		},
	};

	document.body.innerHTML = `
		<form>
			<input type="hidden" name="page" value="ffc-submissions">
			<select name="action">
				<option value="-1" selected>—</option>
				<option value="bulk_trash">Trash</option>
				<option value="bulk_restore">Restore</option>
				<option value="bulk_delete">Delete</option>
				<option value="move_to_form">Move to form…</option>
			</select>
			<select name="action2">
				<option value="-1" selected>—</option>
				<option value="bulk_trash">Trash</option>
			</select>
			<table class="wp-list-table widefat fixed striped">
				<tbody>
					<tr id="row-1"><td><input type="checkbox" name="submission[]" value="1"></td><td class="actions">
						<a href="edit.php?page=ffc-submissions&action=trash&submission_id=1&_wpnonce=n1" class="button button-small">Trash</a>
					</td></tr>
					<tr id="row-2"><td><input type="checkbox" name="submission[]" value="2"></td><td class="actions">
						<a href="edit.php?page=ffc-submissions&action=delete&submission_id=2&_wpnonce=n2" class="button button-small ffc-delete-btn" data-confirm="Permanently delete?">Delete</a>
					</td></tr>
					<tr id="row-3"><td><input type="checkbox" name="submission[]" value="3"></td><td></td></tr>
				</tbody>
			</table>
		</form>
	`;
});

afterEach(() => {
	vi.restoreAllMocks();
});

function dispatchSubmit() {
	document.querySelector('form').dispatchEvent(
		new Event('submit', { bubbles: true, cancelable: true })
	);
}

describe('Submissions bulk — form interception', () => {
	it('posts ffc_submissions_bulk_action with normalised action + checked ids', async () => {
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({ message: '2 submissions trashed.' });

		window.$('select[name="action"]').val('bulk_trash');
		window.$('input[name="submission[]"][value="1"]').prop('checked', true);
		window.$('input[name="submission[]"][value="3"]').prop('checked', true);

		dispatchSubmit();
		await Promise.resolve(); await Promise.resolve();

		expect(requestSpy).toHaveBeenCalledWith('ffc_submissions_bulk_action', {
			action_name: 'trash',
			ids:         [1, 3],
			nonce:       'subs-nonce',
		});
	});

	it('falls back to action2 (bottom select) when the top is -1', async () => {
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({});

		window.$('select[name="action2"]').val('bulk_trash');
		window.$('input[name="submission[]"][value="2"]').prop('checked', true);

		dispatchSubmit();
		await Promise.resolve(); await Promise.resolve();

		expect(requestSpy.mock.calls[0][1].action_name).toBe('trash');
		expect(requestSpy.mock.calls[0][1].ids).toEqual([2]);
	});

	it('fades out the affected rows on success + pops a toast', async () => {
		vi.spyOn(window.FFC, 'request').mockResolvedValue({ message: '2 submissions moved to trash.' });
		const notifySpy = vi.spyOn(window.FFC.Admin, 'showNotification').mockImplementation(() => {});

		window.$('select[name="action"]').val('bulk_trash');
		window.$('input[name="submission[]"][value="1"]').prop('checked', true);
		window.$('input[name="submission[]"][value="3"]').prop('checked', true);

		dispatchSubmit();
		await Promise.resolve(); await Promise.resolve();

		expect(document.querySelector('#row-1')).toBeNull();
		expect(document.querySelector('#row-3')).toBeNull();
		// Untouched row stays.
		expect(document.querySelector('#row-2')).not.toBeNull();
		expect(notifySpy).toHaveBeenCalledWith('2 submissions moved to trash.', 'success');
	});

	it('on error: restores the row opacity + toasts the server message', async () => {
		vi.spyOn(window.FFC, 'request').mockRejectedValue(new Error('DB conflict'));
		const notifySpy = vi.spyOn(window.FFC.Admin, 'showNotification').mockImplementation(() => {});

		window.$('select[name="action"]').val('bulk_trash');
		window.$('input[name="submission[]"][value="1"]').prop('checked', true);

		dispatchSubmit();
		await Promise.resolve(); await Promise.resolve();

		// Row still in the DOM.
		expect(document.querySelector('#row-1')).not.toBeNull();
		expect(notifySpy).toHaveBeenCalledWith('DB conflict', 'error');
	});

	it('falls through to native submit when action is move_to_form (out of scope)', () => {
		const requestSpy = vi.spyOn(window.FFC, 'request');

		window.$('select[name="action"]').val('move_to_form');
		window.$('input[name="submission[]"][value="1"]').prop('checked', true);

		const ev = new Event('submit', { bubbles: true, cancelable: true });
		document.querySelector('form').dispatchEvent(ev);

		expect(requestSpy).not.toHaveBeenCalled();
		// The handler returned without preventing default — `move_to_form`
		// keeps its own modal flow (handled by a different module).
		expect(ev.defaultPrevented).toBe(false);
	});

	it('confirms before bulk_delete and bails on cancel', () => {
		const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
		const requestSpy = vi.spyOn(window.FFC, 'request');

		window.$('select[name="action"]').val('bulk_delete');
		window.$('input[name="submission[]"][value="2"]').prop('checked', true);

		dispatchSubmit();

		expect(confirmSpy).toHaveBeenCalledWith('Permanently delete the selected submissions?');
		expect(requestSpy).not.toHaveBeenCalled();
	});

	it('no-op when nothing is checked (lets the native form submit so WP shows its notice)', () => {
		const requestSpy = vi.spyOn(window.FFC, 'request');

		window.$('select[name="action"]').val('bulk_trash');
		// No checkboxes checked.

		dispatchSubmit();

		expect(requestSpy).not.toHaveBeenCalled();
	});

	it('normalises bulk_restore to the restore action', async () => {
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({});
		window.$('select[name="action"]').val('bulk_restore');
		window.$('input[name="submission[]"][value="1"]').prop('checked', true);
		dispatchSubmit();
		await Promise.resolve(); await Promise.resolve();
		expect(requestSpy.mock.calls[0][1].action_name).toBe('restore');
	});

	it('lets the form submit natively when both action selects are -1', () => {
		const requestSpy = vi.spyOn(window.FFC, 'request');
		window.$('select[name="action"]').val('-1');
		window.$('select[name="action2"]').val('-1');
		window.$('input[name="submission[]"][value="1"]').prop('checked', true);
		const ev = new Event('submit', { bubbles: true, cancelable: true });
		document.querySelector('form').dispatchEvent(ev);
		expect(requestSpy).not.toHaveBeenCalled();
		expect(ev.defaultPrevented).toBe(false);
	});
});

describe('Submissions bulk — per-row buttons', () => {
	it('intercepts a per-row Trash click and posts a single-id payload', async () => {
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({ message: '1 submission moved to trash.' });

		window.$('#row-1 a.button-small').trigger('click');
		await Promise.resolve(); await Promise.resolve();

		expect(requestSpy).toHaveBeenCalledWith('ffc_submissions_bulk_action', {
			action_name: 'trash',
			ids:         [1],
			nonce:       'subs-nonce',
		});
		expect(document.querySelector('#row-1')).toBeNull();
	});

	it('confirms before per-row Delete and bails on cancel', () => {
		const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
		const requestSpy = vi.spyOn(window.FFC, 'request');

		window.$('#row-2 a.button-small').trigger('click');

		expect(confirmSpy).toHaveBeenCalledWith('Permanently delete?');
		expect(requestSpy).not.toHaveBeenCalled();
		// Row still there.
		expect(document.querySelector('#row-2')).not.toBeNull();
	});

	it('proceeds with per-row Delete when confirm is accepted', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({ message: '1 submission permanently deleted.' });

		window.$('#row-2 a.button-small').trigger('click');
		await Promise.resolve(); await Promise.resolve();

		expect(requestSpy.mock.calls[0][1]).toMatchObject({ action_name: 'delete', ids: [2] });
		expect(document.querySelector('#row-2')).toBeNull();
	});

	it('skips links that do not look like a submission action (e.g. Edit button)', () => {
		document.body.innerHTML = `
			<table><tr id="row-x">
				<td>
					<a href="edit.php?page=ffc-submissions&action=edit&submission_id=99" class="button-small">Edit</a>
				</td>
			</tr></table>
		`;
		const requestSpy = vi.spyOn(window.FFC, 'request');

		window.$('#row-x a.button-small').trigger('click');

		expect(requestSpy).not.toHaveBeenCalled();
	});

	it('ignores per-row links that are not on the Submissions page', () => {
		document.body.innerHTML = `
			<table><tr id="row-y">
				<td>
					<a href="edit.php?page=some-other-page&action=trash&submission_id=5" class="button button-small">Trash</a>
				</td>
			</tr></table>
		`;
		const requestSpy = vi.spyOn(window.FFC, 'request');
		window.$('#row-y a.button-small').trigger('click');
		expect(requestSpy).not.toHaveBeenCalled();
	});
});

describe('Submissions bulk — non-Submissions form guard', () => {
	it('does not intercept a form that is not the Submissions list form', () => {
		document.body.innerHTML = `
			<form id="other">
				<input type="hidden" name="page" value="some-other-page">
				<select name="action"><option value="bulk_trash" selected>Trash</option></select>
				<input type="checkbox" name="submission[]" value="1" checked>
			</form>
		`;
		const requestSpy = vi.spyOn(window.FFC, 'request');
		document.getElementById('other').dispatchEvent(
			new Event('submit', { bubbles: true, cancelable: true })
		);
		expect(requestSpy).not.toHaveBeenCalled();
	});
});
