// Tests for the Email texts hub per-email selector (#976 B2),
// `assets/js/ffc-email-texts.js`: a grouped <select> shows only the chosen
// email's editor and initializes TinyMCE on demand (wp.editor.initialize /
// .remove), so the ~15 editors no longer all boot at once.
//
// Covered:
//   - No-op when the picker is absent.
//   - Degraded (no wp.editor API): show/hide still swaps the visible item.
//   - Full API: initialize on show, remove + textarea sync on switch.
//   - Submit flushes the live TinyMCE via tinymce.triggerSave().

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const FIXTURE = `
	<form id="hub-form">
		<select id="ffc-email-texts-select">
			<optgroup label="G1">
				<option value="ed_a">A</option>
				<option value="ed_b">B</option>
			</optgroup>
		</select>
		<div class="ffc-email-body-hub__item" id="ed_a_item" data-editor="ed_a">
			<textarea id="ed_a"></textarea>
		</div>
		<div class="ffc-email-body-hub__item" id="ed_b_item" data-editor="ed_b" style="display:none;">
			<textarea id="ed_b"></textarea>
		</div>
	</form>
`;

function boot() {
	loadScript('assets/js/ffc-email-texts.js');
}

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
	it('no-ops when the picker is absent', () => {
		document.body.innerHTML = '<div>nothing here</div>';
		expect(() => boot()).not.toThrow();
	});

	it('shows the first email and hides the rest on load (degraded, no editor API)', () => {
		document.body.innerHTML = FIXTURE;
		boot();

		expect(document.getElementById('ed_a_item').style.display).not.toBe('none');
		expect(document.getElementById('ed_b_item').style.display).toBe('none');
	});

	it('swaps the visible editor when the selection changes', () => {
		document.body.innerHTML = FIXTURE;
		boot();

		const $ = window.jQuery;
		$('#ffc-email-texts-select').val('ed_b').trigger('change');

		expect(document.getElementById('ed_a_item').style.display).toBe('none');
		expect(document.getElementById('ed_b_item').style.display).not.toBe('none');
	});

	it('initializes and tears down TinyMCE on demand when the editor API is present', () => {
		const initialize = vi.fn();
		const remove = vi.fn();
		const save = vi.fn();
		window.wp = { editor: { initialize, remove } };
		window.tinymce = { get: vi.fn(() => ({ save })), triggerSave: vi.fn() };

		document.body.innerHTML = FIXTURE;
		boot();

		// First email initialized on load.
		expect(initialize).toHaveBeenCalledWith('ed_a', expect.any(Object));

		const $ = window.jQuery;
		$('#ffc-email-texts-select').val('ed_b').trigger('change');

		// Switching syncs + removes the old editor, then initializes the new one.
		expect(save).toHaveBeenCalled();
		expect(remove).toHaveBeenCalledWith('ed_a');
		expect(initialize).toHaveBeenCalledWith('ed_b', expect.any(Object));
	});

	it('flushes the live TinyMCE to its textarea on submit', () => {
		const triggerSave = vi.fn();
		window.tinymce = { get: vi.fn(() => null), triggerSave };

		document.body.innerHTML = FIXTURE;
		boot();

		const $ = window.jQuery;
		$('#hub-form').on('submit', (e) => e.preventDefault());
		$('#hub-form').trigger('submit');

		expect(triggerSave).toHaveBeenCalled();
	});
});
