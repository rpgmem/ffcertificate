// Sprint F1 — deep coverage for ffc-admin-move-submissions.js (162 LOC,
// previously 25.92%). The existing `admin-small.test.js` covers the
// load-time bail and the bulk-action interception happy path. This file
// covers the full modal lifecycle that wasn't reached before:
//
//   - openModal: builds the markup, populates the form-select from
//     window.ffcMoveSubmissions.forms, opens with the wrapper.is-open
//     class, clears prior error + select state on reopen.
//   - showError: with/without message (sets/removes the hidden attr).
//   - confirm: rejects when no form is picked, rejects when no rows
//     are checked, appends move_to_form_id hidden input + re-submits
//     when both validations pass.
//   - close paths: cancel button, backdrop click, Escape keydown.
import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function mountFormWithRows({ rows = 2, action = 'move_to_form' } = {}) {
	var rowInputs = '';
	for (var i = 1; i <= rows; i++) {
		rowInputs += `<input type="checkbox" name="submission[]" value="${i}" checked />`;
	}
	document.body.innerHTML = `
		<form id="posts-filter">
			<select name="action">
				<option value="-1">-</option>
				<option value="move_to_form" ${action === 'move_to_form' ? 'selected' : ''}>Move</option>
			</select>
			<select name="action2">
				<option value="-1" selected>-</option>
			</select>
			${rowInputs}
			<button type="submit">Apply</button>
		</form>
	`;
}

beforeEach(() => {
	document.body.innerHTML = '';
	// Tear down any modal left from a prior test.
	document.querySelectorAll('.ffc-move-modal-wrapper').forEach((el) => el.remove());
	window.ffcMoveSubmissions = {
		forms: [
			{ id: 1, title: 'Form A' },
			{ id: 2, title: 'Form B' },
			{ id: 3, title: 'Form C' },
		],
		strings: {
			modalTitle: 'Move',
			modalIntro: 'Choose a target.',
			targetLabel: 'Target form',
			placeholder: '— select —',
			confirm: 'Move',
			cancel: 'Cancel',
			noSelection: 'Pick a form first.',
			noRowsPicked: 'Check at least one row.',
		},
	};
});

// The IIFE captures $form during its $(document).ready callback. Each
// test mounts the fixture first, then loads the script, so the capture
// matches that test's DOM.
async function mountAndLoad(opts) {
	mountFormWithRows(opts);
	loadScript('assets/js/ffc-admin-move-submissions.js');
	await new Promise((r) => setTimeout(r, 0));
}

describe('ffc-admin-move-submissions — modal build + populate', () => {
	it('builds the modal on first open and populates the select with all forms', async () => {
		await mountAndLoad();
		window.$('#posts-filter').trigger('submit');

		const $wrapper = window.$('.ffc-move-modal-wrapper');
		expect($wrapper.length).toBe(1);
		expect($wrapper.hasClass('is-open')).toBe(true);

		const options = window.$('#ffc-move-modal-select option').map((_, el) => el.textContent).get();
		// First is the placeholder, then 3 form entries.
		expect(options).toHaveLength(4);
		expect(options[0]).toBe('— select —');
		expect(options[1]).toBe('#1 — Form A');
		expect(options[3]).toBe('#3 — Form C');

		// Modal title + intro pulled from strings.
		expect(window.$('#ffc-move-modal-title').text()).toBe('Move');
		expect(window.$('.ffc-move-modal-intro').text()).toBe('Choose a target.');
	});

	it('reopens cleared (no prior error, no prior selection)', async () => {
		await mountAndLoad();
		// First open + force a no-rows error.
		window.$('#posts-filter').trigger('submit');
		window.$('#ffc-move-modal-select').val('2');
		window.$('input[name="submission[]"]').remove();
		window.$('.ffc-move-modal-confirm').trigger('click');
		expect(window.$('.ffc-move-modal-error').text()).toBe('Check at least one row.');
		window.$('.ffc-move-modal-cancel').trigger('click');

		// Re-attach rows + reopen via a fresh submit on the same form.
		window.$('#posts-filter').append('<input type="checkbox" name="submission[]" checked>');
		window.$('#posts-filter').trigger('submit');

		expect(window.$('.ffc-move-modal-error').attr('hidden')).toBe('hidden');
		expect(window.$('.ffc-move-modal-error').text()).toBe('');
		expect(window.$('#ffc-move-modal-select').val()).toBe('');
	});
});

describe('ffc-admin-move-submissions — close paths', () => {
	it('cancel button removes the is-open class', async () => {
		await mountAndLoad();
		window.$('#posts-filter').trigger('submit');
		expect(window.$('.ffc-move-modal-wrapper').hasClass('is-open')).toBe(true);

		window.$('.ffc-move-modal-cancel').trigger('click');
		expect(window.$('.ffc-move-modal-wrapper').hasClass('is-open')).toBe(false);
	});

	it('backdrop click removes the is-open class', async () => {
		await mountAndLoad();
		window.$('#posts-filter').trigger('submit');
		expect(window.$('.ffc-move-modal-wrapper').hasClass('is-open')).toBe(true);

		window.$('.ffc-move-modal-backdrop').trigger('click');
		expect(window.$('.ffc-move-modal-wrapper').hasClass('is-open')).toBe(false);
	});

	it('Escape key closes the modal', async () => {
		await mountAndLoad();
		window.$('#posts-filter').trigger('submit');
		expect(window.$('.ffc-move-modal-wrapper').hasClass('is-open')).toBe(true);

		const ev = window.$.Event('keydown', { key: 'Escape' });
		window.$(document).trigger(ev);
		expect(window.$('.ffc-move-modal-wrapper').hasClass('is-open')).toBe(false);
	});

	it('Escape on a closed modal is a no-op (no throw)', async () => {
		await mountAndLoad();
		window.$('#posts-filter').trigger('submit');
		window.$('.ffc-move-modal-cancel').trigger('click');
		expect(window.$('.ffc-move-modal-wrapper').hasClass('is-open')).toBe(false);

		const ev = window.$.Event('keydown', { key: 'Escape' });
		expect(() => window.$(document).trigger(ev)).not.toThrow();
	});
});

describe('ffc-admin-move-submissions — confirm flow', () => {
	it('shows the no-selection error when the select is empty', async () => {
		await mountAndLoad();
		window.$('#posts-filter').trigger('submit');

		// Don't pick anything.
		window.$('.ffc-move-modal-confirm').trigger('click');

		expect(window.$('.ffc-move-modal-error').text()).toBe('Pick a form first.');
		expect(window.$('.ffc-move-modal-error').attr('hidden')).toBeUndefined();
		// Modal stays open.
		expect(window.$('.ffc-move-modal-wrapper').hasClass('is-open')).toBe(true);
	});

	it('shows the no-rows-picked error when no row checkboxes are checked', async () => {
		await mountAndLoad({ rows: 2 });
		window.$('input[name="submission[]"]').prop('checked', false);
		window.$('#posts-filter').trigger('submit');

		window.$('#ffc-move-modal-select').val('1');
		window.$('.ffc-move-modal-confirm').trigger('click');

		expect(window.$('.ffc-move-modal-error').text()).toBe('Check at least one row.');
		// Modal stays open.
		expect(window.$('.ffc-move-modal-wrapper').hasClass('is-open')).toBe(true);
	});

	it('on success: appends move_to_form_id, closes the modal, re-submits the form', async () => {
		await mountAndLoad({ rows: 1 });
		window.$('#posts-filter').trigger('submit');
		window.$('#ffc-move-modal-select').val('2');

		// Capture the re-submit. The IIFE removes the interceptor namespace
		// before re-triggering, but jsdom's HTMLFormElement.submit() throws
		// "Not implemented". Tap the jQuery submit pipeline and stop it.
		let nativeSubmit = false;
		window.$('#posts-filter').on('submit', function (e) {
			nativeSubmit = true;
			e.preventDefault();
		});

		window.$('.ffc-move-modal-confirm').trigger('click');

		const $hidden = window.$('#posts-filter input[name="move_to_form_id"]');
		expect($hidden.length).toBe(1);
		expect($hidden.val()).toBe('2');
		expect(window.$('.ffc-move-modal-wrapper').hasClass('is-open')).toBe(false);
		expect(nativeSubmit).toBe(true);
	});
});

describe('ffc-admin-move-submissions — action resolution', () => {
	it('does not open the modal when the top action is a different value', async () => {
		document.body.innerHTML = `
			<form id="posts-filter">
				<select name="action"><option value="delete" selected>Delete</option></select>
				<select name="action2"><option value="-1" selected>-</option></select>
				<input type="checkbox" name="submission[]" checked />
			</form>
		`;
		loadScript('assets/js/ffc-admin-move-submissions.js');
		await new Promise((r) => setTimeout(r, 0));
		window.$('#posts-filter').trigger('submit');
		expect(window.$('.ffc-move-modal-wrapper.is-open').length).toBe(0);
	});

	it("falls back to the bottom action when the top action is the '-1' placeholder", async () => {
		document.body.innerHTML = `
			<form id="posts-filter">
				<select name="action"><option value="-1" selected>-</option></select>
				<select name="action2"><option value="move_to_form" selected>Move</option></select>
				<input type="checkbox" name="submission[]" checked />
			</form>
		`;
		loadScript('assets/js/ffc-admin-move-submissions.js');
		await new Promise((r) => setTimeout(r, 0));
		window.$('#posts-filter').trigger('submit');
		expect(window.$('.ffc-move-modal-wrapper.is-open').length).toBe(1);
	});
});
