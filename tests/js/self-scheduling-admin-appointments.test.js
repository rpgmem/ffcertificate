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

	it('does not redirect when the reason is shorter than 5 chars', () => {
		window.prompt = vi.fn(() => 'abc');
		delete window.location;
		window.location = 'INITIAL';
		loadScript(SCRIPT);

		document.querySelector('.ffc-appointment-cancel').click();

		expect(window.location).toBe('INITIAL');
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
		expect(arg.startData).toEqual({
			calendar_id: '3',
			status: 'confirmed',
			start_date: '',
			end_date: '',
		});
		expect(typeof arg.callbacks.onComplete).toBe('function');
	});

	it('is a no-op when the batched-export driver is unavailable', () => {
		mountButton();
		window.ffcAppointmentsExport = { exportNonce: 'n', ajaxUrl: '/x' };
		loadScript(SCRIPT);
		expect(() => document.getElementById('ffc-appointments-export-btn').click()).not.toThrow();
	});
});
