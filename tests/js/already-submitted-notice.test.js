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
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const STORAGE_KEY = 'ffc_submitted_forms';
const DISMISS_PREFIX = 'ffc_already_submitted_dismissed_';

beforeEach(() => {
	window.localStorage.clear();
	window.sessionStorage.clear();
	// Each loadScript binds a document-level `ajaxComplete` tracker; clear
	// accumulated bindings so a prior test's tracker (closed over a prior
	// $form) can't remember the wrong form ID in the tracker tests below.
	if (window.$) { window.$(document).off('ajaxComplete'); }
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

	it('renders no notice when the form_id is not a positive integer', async () => {
		installForm(0);
		window.localStorage.setItem(STORAGE_KEY, JSON.stringify([0]));
		await loadOnReady();
		expect(document.querySelector('.ffc-already-submitted-notice')).toBeNull();
	});

	it('treats a sessionStorage read failure as not-dismissed (still renders)', async () => {
		installForm(33);
		window.localStorage.setItem(STORAGE_KEY, JSON.stringify([33]));
		// jsdom returns the same Storage instance per window but the script
		// reads via `window.sessionStorage.getItem` — spy on the shared
		// Storage.prototype so the dismissal probe throws. Only the
		// dismissal key throws; every other read (incl. localStorage's
		// submitted-forms list) delegates to the real implementation.
		const realGet = Storage.prototype.getItem;
		const spy = vi
			.spyOn(Storage.prototype, 'getItem')
			.mockImplementation(function (key) {
				if (typeof key === 'string' && key.indexOf('ffc_already_submitted_dismissed_') === 0) {
					throw new Error('blocked');
				}
				return realGet.call(this, key);
			});
		try {
			await loadOnReady();
			// The catch in isDismissedThisSession returns false → notice renders.
			expect(document.querySelector('.ffc-already-submitted-notice')).not.toBeNull();
		} finally {
			spy.mockRestore();
		}
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

	it('treats a non-array localStorage value as empty', async () => {
		installForm(1);
		window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ not: 'an array' }));
		await loadOnReady();
		expect(document.querySelector('.ffc-already-submitted-notice')).toBeNull();
	});

	it('uses custom strings from window.ffc_already_submitted', async () => {
		installForm(88);
		window.localStorage.setItem(STORAGE_KEY, JSON.stringify([88]));
		window.ffc_already_submitted = {
			strings: { title: 'Custom T', body: 'Custom B', dismiss: 'OK!' },
		};
		await loadOnReady();
		const notice = document.querySelector('.ffc-already-submitted-notice');
		expect(notice.querySelector('h3').textContent).toBe('Custom T');
		expect(notice.querySelector('p').textContent).toBe('Custom B');
		expect(notice.querySelector('.ffc-already-submitted-dismiss').textContent).toBe('OK!');
		delete window.ffc_already_submitted;
	});

	it('inserts the notice directly above the form when there is no wrapper', async () => {
		document.body.innerHTML = `
			<form class="ffc-submission-form">
				<input type="hidden" name="form_id" value="77" />
			</form>
		`;
		window.localStorage.setItem(STORAGE_KEY, JSON.stringify([77]));
		await loadOnReady();
		const notice = document.querySelector('.ffc-already-submitted-notice');
		expect(notice).not.toBeNull();
		// Inserted as the form's previous sibling.
		expect(notice.nextElementSibling.classList.contains('ffc-submission-form')).toBe(true);
	});

	it('remembers the form ID when a successful ffc_submit_form AJAX completes', async () => {
		installForm(55);
		await loadOnReady();
		const jqXHR = { responseJSON: { success: true } };
		const settings = { data: 'foo=bar&action=ffc_submit_form&x=1' };
		window.$(document).trigger('ajaxComplete', [jqXHR, settings]);
		expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY))).toContain(55);
	});

	it('ignores ajaxComplete for unrelated action, failure, or non-string data', async () => {
		installForm(56);
		await loadOnReady();
		// Unrelated action.
		window.$(document).trigger('ajaxComplete', [{ responseJSON: { success: true } }, { data: 'action=other' }]);
		// Matching action but unsuccessful response.
		window.$(document).trigger('ajaxComplete', [{ responseJSON: { success: false } }, { data: 'action=ffc_submit_form' }]);
		// Matching action but non-string settings.data.
		window.$(document).trigger('ajaxComplete', [{ responseJSON: { success: true } }, { data: { action: 'ffc_submit_form' } }]);
		expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull();
	});

	it('LRU-caps the remembered list at 50 and de-dupes on repeat', async () => {
		installForm(60);
		// Pre-seed 50 distinct IDs (none is 60).
		const seed = Array.from({ length: 50 }, (_, i) => i + 100);
		window.localStorage.setItem(STORAGE_KEY, JSON.stringify(seed));
		await loadOnReady();

		const ok = { responseJSON: { success: true } };
		const settings = { data: 'action=ffc_submit_form' };

		window.$(document).trigger('ajaxComplete', [ok, settings]);
		let list = JSON.parse(window.localStorage.getItem(STORAGE_KEY));
		expect(list.length).toBe(50);              // capped (was 51 after push)
		expect(list[list.length - 1]).toBe(60);    // newest at the tail
		expect(list).not.toContain(100);           // oldest evicted

		// Fire again → de-dupe bump, length unchanged, 60 stays newest.
		window.$(document).trigger('ajaxComplete', [ok, settings]);
		list = JSON.parse(window.localStorage.getItem(STORAGE_KEY));
		expect(list.length).toBe(50);
		expect(list[list.length - 1]).toBe(60);
	});
});
