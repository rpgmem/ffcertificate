// Tests for `assets/js/ffc-self-scheduling-admin-appointments.js` — the row
// "Cancel" action on the admin appointments list (prompt for a reason, then
// redirect to the nonce-signed cancel URL with the reason appended).
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-self-scheduling-admin-appointments.js';
const URL = 'https://x.test/admin.php?ffc_action=cancel&appointment=7&_wpnonce=abc';

let originalLocation;
let originalPrompt;

beforeEach(() => {
	originalLocation = window.location;
	originalPrompt   = window.prompt;
	document.body.innerHTML =
		'<a href="#" class="ffc-appointment-cancel delete-link" ' +
		'data-cancel-url="' + URL + '" data-prompt="Reason?">Cancel</a>';
});

afterEach(() => {
	window.location = originalLocation;
	window.prompt   = originalPrompt;
	document.body.innerHTML = '';
});

describe('ffc-self-scheduling-admin-appointments', () => {
	it('redirects with the encoded reason when prompt returns >= 5 chars', () => {
		window.prompt = vi.fn(() => 'valid reason');
		delete window.location;
		window.location = 'INITIAL';
		loadScript(SCRIPT);

		document.querySelector('.ffc-appointment-cancel').click();

		expect(window.prompt).toHaveBeenCalledWith('Reason?');
		expect(window.location).toBe(URL + '&reason=valid%20reason');
	});

	it('does not redirect when the reason is shorter than 5 chars, and says why', () => {
		document.querySelector('.ffc-appointment-cancel').setAttribute('data-too-short', 'Too short.');
		window.prompt = vi.fn(() => '  abc  ');
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		delete window.location;
		window.location = 'INITIAL';
		loadScript(SCRIPT);

		document.querySelector('.ffc-appointment-cancel').click();

		expect(window.location).toBe('INITIAL');
		expect(alertSpy).toHaveBeenCalledWith('Too short.');
		alertSpy.mockRestore();
	});

	it('does not redirect when the prompt is cancelled (null)', () => {
		window.prompt = vi.fn(() => null);
		delete window.location;
		window.location = 'INITIAL';
		loadScript(SCRIPT);

		document.querySelector('.ffc-appointment-cancel').click();

		expect(window.location).toBe('INITIAL');
	});

	it('defers binding to DOMContentLoaded while the document is still loading', () => {
		window.prompt = vi.fn(() => 'valid reason');
		delete window.location;
		window.location = 'INITIAL';
		const readyStateSpy = vi
			.spyOn(document, 'readyState', 'get')
			.mockReturnValue('loading');
		const addSpy = vi.spyOn(document, 'addEventListener');

		loadScript(SCRIPT);

		const dclCall = addSpy.mock.calls.find((c) => c[0] === 'DOMContentLoaded');
		expect(dclCall).toBeTruthy();
		readyStateSpy.mockRestore();
		dclCall[1]();

		document.querySelector('.ffc-appointment-cancel').click();
		expect(window.location).toBe(URL + '&reason=valid%20reason');

		readyStateSpy.mockRestore();
		addSpy.mockRestore();
	});
});

describe('ffc-self-scheduling-admin-appointments — batched CSV export (#772)', () => {
	afterEach(() => {
		delete window.FFCBatchedExport;
		delete window.ffcAppointmentsExport;
	});

	function mountButton() {
		document.body.innerHTML =
			'<button type="button" id="ffc-appointments-export-btn" ' +
			'data-calendar_id="3" data-status="confirmed">Export CSV</button>' +
			'<span id="ffc-appointments-export-progress" style="display:none;"></span>';
	}

	it('drives window.FFCBatchedExport.run with type + current filters + job nonce', () => {
		mountButton();
		const runSpy = vi.fn();
		window.FFCBatchedExport = { run: runSpy };
		window.ffcAppointmentsExport = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			exportNonce: 'export-nonce',
			strings: { exportPreparing: 'Preparing…' },
		};

		loadScript(SCRIPT);
		document.getElementById('ffc-appointments-export-btn').click();

		expect(runSpy).toHaveBeenCalledTimes(1);
		const arg = runSpy.mock.calls[0][0];
		expect(arg.type).toBe('appointments');
		expect(arg.ajaxUrl).toBe('/wp-admin/admin-ajax.php');
		expect(arg.nonce).toBe('export-nonce');
		// The list's filters, the date range and search included (#2244), so
		// the file holds what the screen shows.
		expect(arg.startData).toEqual({
			calendar_id: '3',
			status: 'confirmed',
			start_date: '',
			end_date: '',
			s: '',
		});
		// Progress is now driven by the shared overlay (#786): the caller passes
		// overlay:true + the button, not per-call callbacks.
		expect(arg.overlay).toBe(true);
		expect(arg.button).toBeTruthy();
	});

	it('is a no-op when the batched-export driver is unavailable', () => {
		mountButton();
		window.ffcAppointmentsExport = { exportNonce: 'n', ajaxUrl: '/x' };
		loadScript(SCRIPT);
		expect(() => document.getElementById('ffc-appointments-export-btn').click()).not.toThrow();
	});
});

describe('ffc-self-scheduling-admin-appointments — Delete permanently (#2340)', () => {
	const DEL = 'https://x.test/admin.php?ffc_action=delete_permanently&appointment=9&_wpnonce=d';

	beforeEach(() => {
		window.ffcAppointmentsExport = {
			deleteDialog: { title: 'Delete %d appointment(s) permanently?', agree: 'Delete %d permanently', intro: 'Cannot be restored.', cancel: 'Cancel', none: 'Select one.' },
		};
	});

	afterEach(() => {
		delete window.ffcAppointmentsExport;
		delete window.FFC;
	});

	it('asks first and follows the link on agree', async () => {
		document.body.innerHTML = '<a href="' + DEL + '" class="ffc-appointment-delete ffc-delete-link">Delete permanently</a>';
		window.FFC = { overrideConfirm: vi.fn(() => Promise.resolve(true)) };
		delete window.location;
		window.location = 'INITIAL';
		loadScript(SCRIPT);

		document.querySelector('.ffc-appointment-delete').click();
		expect(window.location).toBe('INITIAL');
		const opts = window.FFC.overrideConfirm.mock.calls[0][0];
		expect(opts.strings.title).toBe('Delete 1 appointment(s) permanently?');
		expect(opts.agreeCategory).toBe('danger');

		await new Promise((r) => setTimeout(r, 0));
		expect(window.location).toBe(DEL);
	});

	it('stays put when the dialog is cancelled', async () => {
		document.body.innerHTML = '<a href="' + DEL + '" class="ffc-appointment-delete">Delete permanently</a>';
		window.FFC = { overrideConfirm: vi.fn(() => Promise.resolve(false)) };
		delete window.location;
		window.location = 'INITIAL';
		loadScript(SCRIPT);

		document.querySelector('.ffc-appointment-delete').click();
		await new Promise((r) => setTimeout(r, 0));
		expect(window.location).toBe('INITIAL');
	});

	function bulkForm(checked) {
		document.body.innerHTML = `<form id="f">
			<select id="bulk-action-selector-top" name="action"><option value="-1">-</option><option value="bulk_no_show">No-show</option><option value="bulk_delete_permanently">Delete</option></select>
			<input type="submit" id="doaction" value="Apply">
			<input type="checkbox" name="appointment[]" value="1"${checked > 0 ? ' checked' : ''}>
			<input type="checkbox" name="appointment[]" value="2"${checked > 1 ? ' checked' : ''}>
		</form>`;
	}

	it('names how many are selected and submits on agree', async () => {
		bulkForm(2);
		window.FFC = { overrideConfirm: vi.fn(() => Promise.resolve(true)) };
		loadScript(SCRIPT);
		const form = document.getElementById('f');
		const submit = vi.spyOn(form, 'submit').mockImplementation(() => {});
		document.getElementById('bulk-action-selector-top').value = 'bulk_delete_permanently';

		document.getElementById('doaction').click();
		expect(window.FFC.overrideConfirm.mock.calls[0][0].strings.agree).toBe('Delete 2 permanently');
		await new Promise((r) => setTimeout(r, 0));
		expect(submit).toHaveBeenCalledTimes(1);
	});

	it('says to select one when nothing is checked, and leaves other bulk actions alone', () => {
		bulkForm(0);
		window.FFC = { overrideConfirm: vi.fn(() => Promise.resolve(true)) };
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		loadScript(SCRIPT);
		const select = document.getElementById('bulk-action-selector-top');

		select.value = 'bulk_delete_permanently';
		document.getElementById('doaction').click();
		expect(alertSpy).toHaveBeenCalledWith('Select one.');
		expect(window.FFC.overrideConfirm).not.toHaveBeenCalled();

		select.value = 'bulk_no_show';
		const ev = new MouseEvent('click', { bubbles: true, cancelable: true });
		document.getElementById('doaction').dispatchEvent(ev);
		expect(window.FFC.overrideConfirm).not.toHaveBeenCalled();
	});
});
