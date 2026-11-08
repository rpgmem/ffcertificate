// Tests for `assets/js/ffc-admin-submission-edit.js`.
//
// Script uses direct `$(selector).on(...)` binding (not delegated), so
// the elements must exist BEFORE the script loads. Each describe block
// sets up its own DOM and re-loads the script in beforeEach.
//
// Sprint O of #175.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// FFC.request — the migration target — wraps jQuery.post() in a Promise.
// Mock $.post and return a chain whose .done / .fail callback the
// FFC.request internals invoke.
function postChain(spec) {
	const chain = { done: () => chain, fail: () => chain };
	if (spec && 'done' in spec) chain.done = (cb) => { cb(spec.done); return chain; };
	if (spec && spec.fail) chain.fail = (cb) => { cb(spec.fail === true ? undefined : spec.fail); return chain; };
	return chain;
}

// Microtask flush so .then/.catch reactions run before assertions.
function flush() { return Promise.resolve().then(() => Promise.resolve()); }


beforeEach(() => {
	window.ffc_submission_edit = {
		copied_text: 'Copied!',
		search_min_chars: 'Please enter at least 2 characters.',
		no_users_found: 'No users found.',
		search_error: 'Search error',
	};
	window.ajaxurl = '/wp-admin/admin-ajax.php';
	document.body.innerHTML = '';
});

afterEach(() => {
	// Restore spies between tests. Several tests do vi.spyOn(window.$, 'post')
	// without restoring; under Vitest 4 a repeat spyOn on an already-spied
	// method returns the SAME accumulating mock, so a later test's spy would
	// carry the earlier tests' call counts (e.g. the Enter-keypress test saw
	// 4 calls instead of 1). Restoring here gives each test a fresh spy.
	vi.restoreAllMocks();
});

async function loadOnReady() {
	if (!window.FFC) { loadScript('assets/js/ffc-core.js'); }
	loadScript('assets/js/ffc-admin-submission-edit.js');
	await new Promise((r) => setTimeout(r, 0));
}

// ----------------------------------------------------------------------
// Copy magic link
// ----------------------------------------------------------------------

describe('Copy magic link button', () => {
	beforeEach(async () => {
		document.body.innerHTML = `
			<button class="ffc-copy-magic-link" data-url="https://x.test/magic">Copy</button>
		`;
		// Stub document.execCommand — jsdom doesn't implement 'copy'.
		document.execCommand = vi.fn(() => true);
		await loadOnReady();
	});

	it('calls execCommand("copy") on click', async () => {
		document.querySelector('.ffc-copy-magic-link').click();
		expect(document.execCommand).toHaveBeenCalledWith('copy');
	});

	it("changes the button text to the localized 'copied' string", async () => {
		const btn = document.querySelector('.ffc-copy-magic-link');
		btn.click();
		expect(btn.textContent).toBe('Copied!');
		expect(btn.disabled).toBe(true);
	});

	it('restores the original text after a 2-second timeout', async () => {
		vi.useFakeTimers();
		const btn = document.querySelector('.ffc-copy-magic-link');
		const originalText = btn.textContent;
		btn.click();
		vi.advanceTimersByTime(2100);
		expect(btn.textContent).toBe(originalText);
		expect(btn.disabled).toBe(false);
		vi.useRealTimers();
	});
});

// ----------------------------------------------------------------------
// Unlink user button (with confirm prompt)
//
// WHAT THESE TESTS CANNOT SEE, AND WHY IT MATTERS.
//
// They mock `form.submit`, so they observe that it was CALLED and never what a
// real submit sends. Unlinking was broken for as long as the button existed
// because a native `form.submit()` activates no button, and the marker the PHP
// handler gates on was the submit button's `name` -- absent from the payload.
//
// The PHP tests set that marker by hand; these mock the submit away. Both ends
// of the wire were covered, each with the other end stubbed, and the defect
// lived exactly in between. What pins it now is on the PHP side and at the level
// the defect lived at: the marker must be a FIELD of the form
// (`AdminSubmissionEditPageTest::test_render_carries_the_save_marker_as_a_field_not_a_button_name`).
// ----------------------------------------------------------------------

describe('Unlink user button', () => {
	beforeEach(async () => {
		document.body.innerHTML = `
			<form>
				<input name="linked_user_id" value="42" />
				<button type="button" class="ffc-unlink-user-btn" data-confirm="Sure?">Unlink</button>
			</form>
		`;
		await loadOnReady();
	});

	it('does nothing when the user declines the confirmation', async () => {
		vi.spyOn(window.FFC, 'confirmDialog').mockReturnValue(Promise.resolve(false));
		const form = document.querySelector('form');
		const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
		document.querySelector('.ffc-unlink-user-btn').click();
		await Promise.resolve();
		expect(submitSpy).not.toHaveBeenCalled();
		// Hidden input untouched.
		expect(document.querySelector('input[name="linked_user_id"]').value).toBe('42');
	});

	it('empties the linked_user_id and submits the form on confirm', async () => {
		vi.spyOn(window.FFC, 'confirmDialog').mockReturnValue(Promise.resolve(true));
		const form = document.querySelector('form');
		const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
		document.querySelector('.ffc-unlink-user-btn').click();
		await Promise.resolve();
		await Promise.resolve();
		expect(submitSpy).toHaveBeenCalledOnce();
		expect(document.querySelector('input[name="linked_user_id"]').value).toBe('');
	});
});

// ----------------------------------------------------------------------
// Link to another user — revealing the search on an already-linked submission
// ----------------------------------------------------------------------

describe('Link to another user button', () => {
	beforeEach(async () => {
		document.body.innerHTML = `
			<form>
				<input name="linked_user_id" id="ffc-selected-user-id" value="__keep__" />
				<button type="button" class="ffc-relink-user-btn">Link to Another User</button>
				<div class="ffc-user-search-container ffc-hidden">
					<input type="text" id="ffc-user-search-input" />
				</div>
			</form>
		`;
		await loadOnReady();
	});

	it('reveals the search container and disables itself', () => {
		const container = document.querySelector('.ffc-user-search-container');
		const button = document.querySelector('.ffc-relink-user-btn');

		expect(container.classList.contains('ffc-hidden')).toBe(true);

		button.click();

		// Visibility is the shared `.ffc-hidden` class (#1614).
		expect(container.classList.contains('ffc-hidden')).toBe(false);
		expect(button.disabled).toBe(true);
	});

	it('leaves the decision field alone: revealing is not choosing', () => {
		document.querySelector('.ffc-relink-user-btn').click();

		// Showing the search must not itself change who owns the submission;
		// only picking a result does, and only the save applies it.
		expect(document.getElementById('ffc-selected-user-id').value).toBe('__keep__');
	});
});

// ----------------------------------------------------------------------
// User search — AJAX
// ----------------------------------------------------------------------

describe('User search', () => {
	beforeEach(async () => {
		document.body.innerHTML = `
			<input id="ffc-user-search-input" type="text" />
			<button class="ffc-search-user-btn" data-nonce="search-nonce">Search</button>
			<span id="ffc-search-spinner"></span>
			<div id="ffc-user-search-results"></div>
			<div id="ffc-selected-user-preview"></div>
			<input id="ffc-selected-user-id" type="hidden" />
		`;
		await loadOnReady();
	});

	it("says in the results area when the search term is shorter than 2 characters", async () => {
		globalThis.alert.mockClear?.();
		document.getElementById('ffc-user-search-input').value = 'a';
		document.querySelector('.ffc-search-user-btn').click();
		expect(globalThis.alert).not.toHaveBeenCalled();
		const results = document.getElementById('ffc-user-search-results');
		expect(results.querySelector('[role="status"]').textContent).toBe('Please enter at least 2 characters.');
	});

	it('fires AJAX with the search term + nonce when >= 2 chars', async () => {
		const spy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));
		document.getElementById('ffc-user-search-input').value = 'maria';
		document.querySelector('.ffc-search-user-btn').click();
		await flush();
		expect(spy).toHaveBeenCalledOnce();
		// FFC.request → jQuery.post(url, payload).
		const [, payload] = spy.mock.calls[0];
		expect(payload.action).toBe('ffc_search_user');
		expect(payload.search).toBe('maria');
		expect(payload.nonce).toBe('search-nonce');
	});

	it('renders search results into #ffc-user-search-results on success', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
				success: true,
				data: {
					users: [
						{ id: 1, name: 'Maria', email: 'maria@example.com' },
						{ id: 2, name: 'João',  email: 'joao@example.com'  },
					],
				},
			} }));
		document.getElementById('ffc-user-search-input').value = 'qu';
		document.querySelector('.ffc-search-user-btn').click();
		await flush();
		expect(document.querySelectorAll('#ffc-user-search-results .ffc-search-result-item').length).toBe(2);
	});

	it("renders the no-users message when response.success is false", async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false, data: { message: 'Custom not-found' } } }));
		document.getElementById('ffc-user-search-input').value = 'qu';
		document.querySelector('.ffc-search-user-btn').click();
		await flush();
		expect(document.getElementById('ffc-user-search-results').textContent).toContain('Custom not-found');
	});

	it('also triggers the search on Enter keypress', async () => {
		const spy = vi.spyOn(window.$, 'post').mockImplementation(() => postChain({}));
		const input = document.getElementById('ffc-user-search-input');
		input.value = 'enter-key';
		// Build a real keypress event with `which === 13`.
		const ev = window.$.Event('keypress', { which: 13 });
		window.$(input).trigger(ev);
		await flush();
		expect(spy).toHaveBeenCalledOnce();
	});

	it('renders the default no-users message when the response has no users array', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: true, data: {} } }));
		document.getElementById('ffc-user-search-input').value = 'qu';
		document.querySelector('.ffc-search-user-btn').click();
		await flush();
		expect(document.getElementById('ffc-user-search-results').textContent).toContain('No users found.');
	});

	it('says the search-error string in the results area when the request rejects without fromServer', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: { status: 0 } }));
		globalThis.alert.mockClear?.();
		document.getElementById('ffc-user-search-input').value = 'qu';
		document.querySelector('.ffc-search-user-btn').click();
		await flush();
		expect(globalThis.alert).not.toHaveBeenCalled();
		expect(document.getElementById('ffc-user-search-results').textContent).toContain('Search error');
	});

	it('shows the server-provided message when the rejection comes fromServer', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: { success: false, data: { message: 'Backend down' } } }));
		document.getElementById('ffc-user-search-input').value = 'qu';
		document.querySelector('.ffc-search-user-btn').click();
		await flush();
		expect(document.getElementById('ffc-user-search-results').textContent).toContain('Backend down');
	});
});

// ----------------------------------------------------------------------
// User search — attribute-context escaping (#837 S4)
//
// display_name is user-controlled (any logged-in user can set it via the
// profile REST endpoint, sanitized only with sanitize_text_field, which keeps
// quotes) and is echoed unescaped by the admin AJAX search into a
// data-display-name="…" attribute. escapeHtml must encode the double quote so
// a value like `a" onmouseover="…"` cannot break out of the attribute and run
// an event handler in the admin's session.
// ----------------------------------------------------------------------

describe('User search result — attribute escaping (S4)', () => {
	beforeEach(async () => {
		document.body.innerHTML = `
			<input id="ffc-user-search-input" type="text" />
			<button class="ffc-search-user-btn" data-nonce="n">Search</button>
			<span id="ffc-search-spinner"></span>
			<div id="ffc-user-search-results"></div>
			<div id="ffc-selected-user-preview"></div>
			<input id="ffc-selected-user-id" type="hidden" />
		`;
		await loadOnReady();
	});

	it('encodes a double quote in display_name so it cannot break out of the data attribute', async () => {
		const payload = 'a" onmouseover="alert(document.cookie)';
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ done: {
			success: true,
			data: { users: [ { id: 9, display_name: payload, email: 'x@y.z', avatar: '' } ] },
		} }));
		document.getElementById('ffc-user-search-input').value = 'qu';
		document.querySelector('.ffc-search-user-btn').click();
		await flush();

		const item = document.querySelector('#ffc-user-search-results .ffc-search-result-item');
		expect(item).not.toBeNull();
		// Quote encoded → the raw value round-trips through the attribute and the
		// injected `onmouseover=` never becomes a real attribute.
		expect(item.getAttribute('data-display-name')).toBe(payload);
		expect(item.hasAttribute('onmouseover')).toBe(false);
	});
});

// ----------------------------------------------------------------------
// Select / clear user from results (delegated handlers)
// ----------------------------------------------------------------------

describe('User selection from results', () => {
	beforeEach(async () => {
		window.ffc_submission_edit.clear_selection = 'Clear';
		document.body.innerHTML = `
			<input id="ffc-user-search-input" type="text" value="maria" />
			<button class="ffc-search-user-btn" data-nonce="n">Search</button>
			<span id="ffc-search-spinner"></span>
			<div id="ffc-user-search-results">
				<div class="ffc-search-result-item"
					data-user-id="7"
					data-display-name="Maria"
					data-email="maria@example.com"
					data-avatar="https://x.test/a.png"></div>
			</div>
			<div id="ffc-selected-user-preview"></div>
			<input id="ffc-selected-user-id" type="hidden" />
		`;
		await loadOnReady();
	});

	it('selecting a result sets the hidden id and renders the preview', () => {
		document.querySelector('.ffc-search-result-item').click();
		expect(document.getElementById('ffc-selected-user-id').value).toBe('7');
		const preview = document.getElementById('ffc-selected-user-preview');
		expect(preview.textContent).toContain('Maria');
		expect(preview.textContent).toContain('maria@example.com');
		expect(preview.textContent).toContain('Clear');
		// Search input is cleared after selection.
		expect(document.getElementById('ffc-user-search-input').value).toBe('');
	});

	it('clear-selection restores "keep" rather than the unlink value, and hides the preview', () => {
		document.querySelector('.ffc-search-result-item').click();
		document.querySelector('.ffc-clear-selection').click();

		// One field carries three states: `__keep__` (change nothing), '' (unlink)
		// and a user id. Clearing a selection means the FIRST. It used to write ''
		// here, which the handler reads as an instruction to unlink -- harmless
		// while the field was only rendered with no user attached, and an unlink
		// on the next save now that the search also appears for a linked one.
		expect(document.getElementById('ffc-selected-user-id').value).toBe('__keep__');
		expect(document.getElementById('ffc-selected-user-preview').classList.contains('ffc-hidden')).toBe(true);
	});
});

// ----------------------------------------------------------------------
// Consent section
// ----------------------------------------------------------------------

describe('Consent section', () => {
	it('is a native <details> now: the script no longer toggles it (#1614)', async () => {
		document.body.innerHTML = `
			<details class="ffc-section"><summary>Consent</summary>
				<div class="ffc-consent-details">details</div>
			</details>
		`;
		await loadOnReady();
		const details = document.querySelector('details');
		details.querySelector('summary').dispatchEvent(new window.KeyboardEvent('keypress', { which: 13, bubbles: true }));
		expect(details.classList.contains('is-open')).toBe(false);
		expect(details.hasAttribute('style')).toBe(false);
	});
});

// An answer is corrected on its own row (#2354).
describe('answer correction on its row', () => {
	beforeEach(async () => {
		document.body.innerHTML = `
			<dl class="ffc-facts">
				<dt>Full Name</dt>
				<dd class="ffc-facts__value" id="row-name"><div class="ffc-answer-row"><span>Ana Silv</span>
					<button type="button" class="ffc-correct-open" data-field="name" data-value="Ana Silv" aria-expanded="false">p</button></div></dd>
				<dt>School</dt>
				<dd class="ffc-facts__value" id="row-school"><div class="ffc-answer-row"><span>North</span>
					<button type="button" class="ffc-correct-open" data-field="school" data-value="North" aria-expanded="false">p</button></div></dd>
			</dl>
			<div id="ffc-correct-inline" hidden>
				<input type="hidden" id="ffc-correct-field" value="">
				<textarea id="ffc-correct-value"></textarea>
				<textarea id="ffc-correct-reason"></textarea>
				<button type="button" class="ffc-correct-cancel">Cancel</button>
			</div>`;
		await loadOnReady();
	});

	it('opens under the row, names the answer and fills in its value', () => {
		const pencil = document.querySelector('[data-field="school"]');
		pencil.click();

		const editor = document.getElementById('ffc-correct-inline');
		expect(editor.hidden).toBe(false);
		expect(editor.parentElement.id).toBe('row-school');
		expect(document.getElementById('ffc-correct-field').value).toBe('school');
		expect(document.getElementById('ffc-correct-value').value).toBe('North');
		expect(pencil.getAttribute('aria-expanded')).toBe('true');
		expect(document.activeElement.id).toBe('ffc-correct-value');
	});

	it('moves to another row and clears the reason', () => {
		document.querySelector('[data-field="school"]').click();
		document.getElementById('ffc-correct-reason').value = 'half a reason';
		document.querySelector('[data-field="name"]').click();

		expect(document.getElementById('ffc-correct-inline').parentElement.id).toBe('row-name');
		expect(document.getElementById('ffc-correct-field').value).toBe('name');
		expect(document.getElementById('ffc-correct-value').value).toBe('Ana Silv');
		expect(document.getElementById('ffc-correct-reason').value).toBe('');
		expect(document.querySelector('[data-field="school"]').getAttribute('aria-expanded')).toBe('false');
	});

	it('cancel hides the editor, empties the answer and returns focus to the pencil', () => {
		const pencil = document.querySelector('[data-field="name"]');
		pencil.click();
		document.querySelector('.ffc-correct-cancel').click();

		expect(document.getElementById('ffc-correct-inline').hidden).toBe(true);
		expect(document.getElementById('ffc-correct-field').value).toBe('');
		expect(pencil.getAttribute('aria-expanded')).toBe('false');
		expect(document.activeElement).toBe(pencil);
	});
});

// An open answer awaiting grading is amended with the grades (#2356).
describe('amending an open answer while it waits for grading', () => {
	beforeEach(async () => {
		document.body.innerHTML = `
			<dl class="ffc-facts">
				<dt>Name</dt>
				<dd class="ffc-facts__value" id="row-name"><div class="ffc-answer-row"><span>Ana</span>
					<button type="button" class="ffc-correct-open" data-field="name" data-value="Ana" data-form="ffc-correct-form" aria-expanded="false">p</button></div></dd>
				<dt>Essay</dt>
				<dd class="ffc-facts__value" id="row-essay"><div class="ffc-answer-row"><span>Because.</span>
					<button type="button" class="ffc-correct-open" data-field="essay" data-value="Because." data-form="ffc-grade-form" aria-expanded="false">p</button></div></dd>
			</dl>
			<div id="ffc-correct-inline" hidden>
				<input type="hidden" id="ffc-correct-field" form="ffc-correct-form" value="">
				<textarea id="ffc-correct-value" form="ffc-correct-form"></textarea>
				<textarea id="ffc-correct-reason" form="ffc-correct-form"></textarea>
				<button type="submit" class="ffc-amend-own" form="ffc-correct-form">Amend answer</button>
				<button type="button" class="ffc-correct-cancel">Cancel</button>
				<p class="ffc-amend-with-grade" hidden>Saved with the grades.</p>
			</div>
			<button type="submit" class="ffc-grade-save" form="ffc-grade-form" data-label-amend="Save the amendment and grades">Save grades</button>`;
		await loadOnReady();
	});

	const formOf = (id) => document.getElementById(id).getAttribute('form');

	it('moves the editor inputs onto the grading form and relabels its save', () => {
		document.querySelector('[data-field="essay"]').click();

		expect(['ffc-correct-field', 'ffc-correct-value', 'ffc-correct-reason'].map(formOf)).toEqual(['ffc-grade-form', 'ffc-grade-form', 'ffc-grade-form']);
		expect(document.querySelector('.ffc-amend-own').hidden).toBe(true);
		expect(document.querySelector('.ffc-amend-with-grade').hidden).toBe(false);
		expect(document.querySelector('.ffc-grade-save').textContent).toBe('Save the amendment and grades');
	});

	it('keeps any other answer on its own save', () => {
		document.querySelector('[data-field="essay"]').click();
		document.querySelector('[data-field="name"]').click();

		expect(formOf('ffc-correct-value')).toBe('ffc-correct-form');
		expect(document.querySelector('.ffc-amend-own').hidden).toBe(false);
		expect(document.querySelector('.ffc-amend-with-grade').hidden).toBe(true);
		expect(document.querySelector('.ffc-grade-save').textContent).toBe('Save grades');
	});

	it('cancel restores the correction form and the grading label', () => {
		document.querySelector('[data-field="essay"]').click();
		document.querySelector('.ffc-correct-cancel').click();

		expect(formOf('ffc-correct-reason')).toBe('ffc-correct-form');
		expect(document.querySelector('.ffc-grade-save').textContent).toBe('Save grades');
	});
});
