// Tests for the Email texts hub per-email selector (#976 B2),
// `assets/js/ffc-email-texts.js`: a grouped <select> shows only the chosen
// email's editor and initializes TinyMCE on demand (wp.editor.initialize /
// .remove), so the ~15 editors no longer all boot at once.
//
// Covered:
//   - No-op when the picker is absent.
//   - Opens on the placeholder: nothing shown, nothing initialized.
//   - Degraded (no wp.editor API): show/hide still swaps the visible item.
//   - Full API: initialize on show, remove + textarea sync on switch.
//   - Back to the placeholder tears the live editor down.
//   - Re-picking the live email is a no-op (no double initialize).
//   - A browser-restored selection is honoured on load.
//   - The editor API is re-probed per call, not captured at parse time.
//   - Submit flushes the live TinyMCE via tinymce.triggerSave().

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// Mirrors the template: a placeholder option selected, every item hidden.
const FIXTURE = `
	<form id="hub-form">
		<select id="ffc-email-texts-select">
			<option value="" selected>— Choose an email —</option>
			<optgroup label="G1">
				<option value="ed_a">A</option>
				<option value="ed_b">B</option>
			</optgroup>
		</select>
		<p class="description ffc-email-texts-empty">pick one</p>
		<div class="ffc-email-body-hub__item" id="ed_a_item" data-editor="ed_a" style="display:none;">
			<textarea id="ed_a"></textarea>
		</div>
		<div class="ffc-email-body-hub__item" id="ed_b_item" data-editor="ed_b" style="display:none;">
			<textarea id="ed_b"></textarea>
		</div>
	</form>
`;

// The script defers its work to DOM ready, and jQuery resolves `$(fn)`
// asynchronously even when the document is already parsed — so a boot must be
// awaited before asserting anything (same idiom as the calendar-editor tests).
async function boot() {
	loadScript('assets/js/ffc-email-texts.js');
	await new Promise((r) => setTimeout(r, 0));
}

function pick(value) {
	window.jQuery('#ffc-email-texts-select').val(value).trigger('change');
}

const shown = (id) => document.getElementById(id).style.display !== 'none';

beforeEach(() => {
	document.body.innerHTML = '';
	window.ffcEmailTexts = { editorSettings: { mediaButtons: false } };
});

afterEach(() => {
	delete window.wp;
	delete window.tinymce;
	delete window.ffcEmailTexts;
});

describe('ffc-email-texts: selector', () => {
	it('no-ops when the picker is absent', async () => {
		document.body.innerHTML = '<div>nothing here</div>';
		await expect(boot()).resolves.toBeUndefined();
	});

	it('opens with no email selected and every editor hidden', async () => {
		document.body.innerHTML = FIXTURE;
		await boot();

		expect(shown('ed_a_item')).toBe(false);
		expect(shown('ed_b_item')).toBe(false);
		expect(document.querySelector('.ffc-email-texts-empty').style.display).not.toBe('none');
	});

	it('never initializes an editor on a fresh load', async () => {
		const initialize = vi.fn();
		window.wp = { editor: { initialize, remove: vi.fn() } };
		window.tinymce = { get: vi.fn(() => null), triggerSave: vi.fn() };

		document.body.innerHTML = FIXTURE;
		await boot();

		expect(initialize).not.toHaveBeenCalled();
	});

	it('shows the chosen editor and hides the empty state (degraded, no editor API)', async () => {
		document.body.innerHTML = FIXTURE;
		await boot();
		pick('ed_a');

		expect(shown('ed_a_item')).toBe(true);
		expect(shown('ed_b_item')).toBe(false);
		expect(document.querySelector('.ffc-email-texts-empty').style.display).toBe('none');
	});

	it('swaps the visible editor when the selection changes', async () => {
		document.body.innerHTML = FIXTURE;
		await boot();
		pick('ed_a');
		pick('ed_b');

		expect(shown('ed_a_item')).toBe(false);
		expect(shown('ed_b_item')).toBe(true);
	});

	it('initializes and tears down TinyMCE on demand when the editor API is present', async () => {
		const initialize = vi.fn();
		const remove = vi.fn();
		const save = vi.fn();
		window.wp = { editor: { initialize, remove } };
		window.tinymce = { get: vi.fn(() => ({ save })), triggerSave: vi.fn() };

		document.body.innerHTML = FIXTURE;
		await boot();

		pick('ed_a');
		expect(initialize).toHaveBeenCalledWith('ed_a', expect.any(Object));

		// Switching syncs + removes the old editor, then initializes the new one.
		pick('ed_b');
		expect(save).toHaveBeenCalled();
		expect(remove).toHaveBeenCalledWith('ed_a');
		expect(initialize).toHaveBeenCalledWith('ed_b', expect.any(Object));
	});

	it('returns to the empty state when the placeholder is re-selected', async () => {
		const remove = vi.fn();
		window.wp = { editor: { initialize: vi.fn(), remove } };
		window.tinymce = { get: vi.fn(() => null), triggerSave: vi.fn() };

		document.body.innerHTML = FIXTURE;
		await boot();
		pick('ed_a');
		pick('');

		expect(remove).toHaveBeenCalledWith('ed_a');
		expect(shown('ed_a_item')).toBe(false);
		expect(document.querySelector('.ffc-email-texts-empty').style.display).not.toBe('none');
	});

	it('does not re-initialize when the same email is picked again', async () => {
		const initialize = vi.fn();
		window.wp = { editor: { initialize, remove: vi.fn() } };

		document.body.innerHTML = FIXTURE;
		await boot();
		pick('ed_a');
		pick('ed_a');

		expect(initialize).toHaveBeenCalledTimes(1);
	});

	it('honours a selection the browser restored on a soft reload', async () => {
		const initialize = vi.fn();
		window.wp = { editor: { initialize, remove: vi.fn() } };

		document.body.innerHTML = FIXTURE;
		// A soft reload can restore the previous choice before the script runs.
		document.getElementById('ffc-email-texts-select').value = 'ed_b';
		await boot();

		expect(shown('ed_b_item')).toBe(true);
		expect(initialize).toHaveBeenCalledWith('ed_b', expect.any(Object));
	});

	it('re-probes the editor API instead of capturing it at parse time', async () => {
		// The real regression: wp.editor was reachable but the TinyMCE runtime
		// arrived later. A boolean captured on parse would leave every editor
		// plain for the life of the page.
		document.body.innerHTML = FIXTURE;
		await boot();

		const initialize = vi.fn();
		window.wp = { editor: { initialize, remove: vi.fn() } };
		pick('ed_a');

		expect(initialize).toHaveBeenCalledWith('ed_a', expect.any(Object));
	});

	it('flushes the live TinyMCE to its textarea on submit', async () => {
		const triggerSave = vi.fn();
		window.tinymce = { get: vi.fn(() => null), triggerSave };

		document.body.innerHTML = FIXTURE;
		await boot();
		window.jQuery('#hub-form').trigger('submit');

		expect(triggerSave).toHaveBeenCalled();
	});
});
