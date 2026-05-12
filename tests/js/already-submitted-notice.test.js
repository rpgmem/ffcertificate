// Tests for `assets/js/ffc-already-submitted-notice.js`.
//
// The script is a `$(document).ready(...)` IIFE that:
//   1. Reads localStorage `ffc_submitted_forms` (JSON array of form IDs).
//   2. Finds the first `form.ffc-submission-form` on the page.
//   3. If the form's `[name="form_id"]` value is in the list AND the
//      session-storage dismissal flag isn't set, inserts a friendly
//      notice above the form wrapper.
//   4. Dismiss button sets the sessionStorage flag and removes the
//      notice.
//
// Sprint C of #168.
import { describe, it, expect, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

const STORAGE_KEY = 'ffc_submitted_forms';
const DISMISS_PREFIX = 'ffc_already_submitted_dismissed_';

beforeEach(() => {
	window.localStorage.clear();
	window.sessionStorage.clear();
});

async function loadOnReady() {
	loadScript('assets/js/ffc-already-submitted-notice.js');
	// `$(document).ready(cb)` defers to a microtask in jQuery 4 even when
	// the document is already complete.
	await new Promise((r) => setTimeout(r, 0));
}

function installForm(formId) {
	document.body.innerHTML = `
		<div class="ffc-form-wrapper">
			<form class="ffc-submission-form">
				<input type="hidden" name="form_id" value="${formId}" />
			</form>
		</div>
	`;
}

describe('ffc-already-submitted-notice', () => {
	it('renders no notice when the form ID is not in localStorage', async () => {
		installForm(7);
		window.localStorage.setItem(STORAGE_KEY, JSON.stringify([1, 2, 3]));
		await loadOnReady();
		expect(document.querySelector('.ffc-already-submitted-notice')).toBeNull();
	});

	it('renders the notice when the form ID is in localStorage', async () => {
		installForm(42);
		window.localStorage.setItem(STORAGE_KEY, JSON.stringify([42]));
		await loadOnReady();
		const notice = document.querySelector('.ffc-already-submitted-notice');
		expect(notice).not.toBeNull();
		expect(notice.querySelector('h3')).not.toBeNull();
		expect(notice.querySelector('.ffc-already-submitted-dismiss')).not.toBeNull();
	});

	it('does NOT render when the dismissal flag is already set for this form', async () => {
		installForm(42);
		window.localStorage.setItem(STORAGE_KEY, JSON.stringify([42]));
		window.sessionStorage.setItem(DISMISS_PREFIX + '42', '1');
		await loadOnReady();
		expect(document.querySelector('.ffc-already-submitted-notice')).toBeNull();
	});

	it('dismiss button removes the notice and sets the sessionStorage flag', async () => {
		installForm(99);
		window.localStorage.setItem(STORAGE_KEY, JSON.stringify([99]));
		await loadOnReady();
		const dismiss = document.querySelector('.ffc-already-submitted-dismiss');
		expect(dismiss).not.toBeNull();
		dismiss.click();
		expect(document.querySelector('.ffc-already-submitted-notice')).toBeNull();
		expect(window.sessionStorage.getItem(DISMISS_PREFIX + '99')).toBe('1');
	});

	it('does nothing when no .ffc-submission-form is on the page', async () => {
		document.body.innerHTML = '<div>no form here</div>';
		window.localStorage.setItem(STORAGE_KEY, JSON.stringify([1]));
		await loadOnReady();
		expect(document.querySelector('.ffc-already-submitted-notice')).toBeNull();
	});

	it('survives malformed JSON in localStorage', async () => {
		installForm(1);
		window.localStorage.setItem(STORAGE_KEY, 'not-json{');
		await loadOnReady();
		// Should not throw; just renders nothing (since the parsed list
		// is treated as empty).
		expect(document.querySelector('.ffc-already-submitted-notice')).toBeNull();
	});
});
