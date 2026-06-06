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
