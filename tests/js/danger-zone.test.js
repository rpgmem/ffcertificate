// The Danger Zone delete confirmation (#2338): the button waits for a
// target, the submit opens the plugin's dialog with the target and its
// count, and the form posts only once the word is typed and the countdown
// is over — carrying the word the server checks again. Also the dialog's
// typed-word field and its configurable confirm icon.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const STRINGS = {
	word: 'DELETE',
	matchLabel: 'Type %s to confirm',
	targetAll: 'Every submission of every form — %s records',
	targetForm: 'All submissions of %1$s — %2$s records',
	reset: 'The counter is reset.',
	final: 'This cannot be undone.',
	dialog: { seconds: 15, title: 'Delete submissions?', agree: 'Delete', agreeIn: 'Delete (%d)', cancel: 'Cancel' },
};

beforeAll(() => {
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', strings: {} };
	loadScript('assets/js/ffc-core.js');
	window.ffcDangerZone = STRINGS;
	loadScript('assets/js/ffc-danger-zone.js');
});

function card({ locked = false } = {}) {
	document.body.innerHTML = `
		<form method="post" id="ffc-danger-zone-form">
			<fieldset${locked ? ' disabled' : ''}>
				<select name="delete_target" id="ffc_delete_target" required>
					<option value="">— Choose —</option>
					<option value="all" data-label="All" data-count-label="1,284">All — 1,284</option>
					<option value="7" data-label="Libras 2026" data-count-label="312">Libras 2026 — 312</option>
				</select>
				<input type="checkbox" id="ffc_reset_counter" checked>
				<input type="hidden" name="ffc_delete_confirm" id="ffc_delete_confirm" value="">
				<button type="submit" id="ffc-danger-zone-delete" disabled>Delete Data Permanently</button>
			</fieldset>
		</form>`;
	window.FFCDangerZone.init();
	return {
		form: document.getElementById('ffc-danger-zone-form'),
		select: document.getElementById('ffc_delete_target'),
		button: document.getElementById('ffc-danger-zone-delete'),
		word: document.getElementById('ffc_delete_confirm'),
	};
}

function choose(select, value) {
	select.value = value;
	select.dispatchEvent(new Event('change'));
}

function submit(form) {
	const event = new Event('submit', { cancelable: true });
	form.dispatchEvent(event);
	return event;
}

function dialog() {
	return document.querySelector('.ffc-override-dialog');
}

function type(input, text) {
	input.value = text;
	input.dispatchEvent(new Event('input'));
}

beforeEach(() => {
	vi.useFakeTimers();
});

afterEach(() => {
	vi.useRealTimers();
	document.body.innerHTML = '';
});

describe('the Danger Zone delete button', () => {
	it('waits for a target', () => {
		const { select, button } = card();
		expect(button.disabled).toBe(true);
		choose(select, '7');
		expect(button.disabled).toBe(false);
		choose(select, '');
		expect(button.disabled).toBe(true);
	});

	it('stays disabled for an account without the capability', () => {
		const { select, button } = card({ locked: true });
		choose(select, 'all');
		expect(button.disabled).toBe(true);
	});
});

describe('the Danger Zone confirmation', () => {
	it('names the target and its count, and posts nothing while open', () => {
		const { form, select, word } = card();
		choose(select, '7');
		const event = submit(form);

		expect(event.defaultPrevented).toBe(true);
		const items = Array.from(dialog().querySelectorAll('.ffc-override-reasons li')).map((li) => li.textContent);
		expect(items).toEqual(['All submissions of Libras 2026 — 312 records', 'The counter is reset.', 'This cannot be undone.']);
		expect(dialog().querySelector('.ffc-override-match label').textContent).toBe('Type DELETE to confirm');
		expect(word.value).toBe('');
	});

	it('words the all-forms target on its own and leaves out an unchecked reset', () => {
		const { form, select } = card();
		document.getElementById('ffc_reset_counter').checked = false;
		choose(select, 'all');
		submit(form);

		const items = Array.from(dialog().querySelectorAll('.ffc-override-reasons li')).map((li) => li.textContent);
		expect(items).toEqual(['Every submission of every form — 1,284 records', 'This cannot be undone.']);
	});

	it('posts with the word once it is typed exactly and the countdown is over', async () => {
		const { form, select, word } = card();
		// What the browser does: fire the submit, then post unless prevented.
		const posted = [];
		form.requestSubmit = () => {
			posted.push(submit(form).defaultPrevented);
		};
		choose(select, 'all');
		submit(form);

		const agree = dialog().querySelector('[data-ffc-override="agree"]');
		const match = dialog().querySelector('.ffc-override-match input');
		expect(agree.classList.contains('ffc-btn-danger')).toBe(true);
		expect(agree.classList.contains('ffc-icon-delete')).toBe(true);

		vi.advanceTimersByTime(15000);
		expect(agree.disabled).toBe(true, 'The countdown is over but no word was typed.');

		type(match, 'delete');
		expect(agree.disabled).toBe(true, 'Another case is another word.');
		type(match, 'DELET');
		expect(agree.disabled).toBe(true);
		type(match, 'DELETE');
		expect(agree.disabled).toBe(false);

		agree.click();
		await Promise.resolve();
		await Promise.resolve();

		expect(word.value).toBe('DELETE');
		expect(posted).toEqual([false]); // One submit, not held back by the dialog a second time.
	});

	it('keeps the button closed while the word is right but the countdown runs', () => {
		const { form, select } = card();
		choose(select, '7');
		submit(form);
		type(dialog().querySelector('.ffc-override-match input'), 'DELETE');
		vi.advanceTimersByTime(14000);
		expect(dialog().querySelector('[data-ffc-override="agree"]').disabled).toBe(true);
		vi.advanceTimersByTime(1000);
		expect(dialog().querySelector('[data-ffc-override="agree"]').disabled).toBe(false);
	});

	it('posts nothing when cancelled', async () => {
		const { form, select, word } = card();
		form.requestSubmit = vi.fn();
		choose(select, '7');
		submit(form);
		dialog().querySelector('[data-ffc-override="cancel"]').click();
		await Promise.resolve();
		await Promise.resolve();

		expect(word.value).toBe('');
		expect(form.requestSubmit).not.toHaveBeenCalled();
	});

	it('opens nothing without a target', () => {
		const { form } = card();
		const event = submit(form);
		expect(event.defaultPrevented).toBe(true);
		expect(dialog()).toBeNull();
	});
});

describe('the placeholder filler', () => {
	it('fills positional and plain placeholders', () => {
		expect(window.FFCDangerZone.fill('%2$s of %1$s', ['a', 'b'])).toBe('b of a');
		expect(window.FFCDangerZone.fill('%s and %s', ['x', 'y'])).toBe('x and y');
		expect(window.FFCDangerZone.fill('%s', [])).toBe('%s');
	});
});
