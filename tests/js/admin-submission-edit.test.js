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
		vi.spyOn(window, 'confirm').mockReturnValue(false);
		const form = document.querySelector('form');
		const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
		document.querySelector('.ffc-unlink-user-btn').click();
		expect(submitSpy).not.toHaveBeenCalled();
		// Hidden input untouched.
		expect(document.querySelector('input[name="linked_user_id"]').value).toBe('42');
	});

	it('empties the linked_user_id and submits the form on confirm', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const form = document.querySelector('form');
		const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
		document.querySelector('.ffc-unlink-user-btn').click();
		expect(submitSpy).toHaveBeenCalledOnce();
		expect(document.querySelector('input[name="linked_user_id"]').value).toBe('');
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

	it("alerts when the search term is shorter than 2 characters", async () => {
		globalThis.alert.mockClear?.();
		document.getElementById('ffc-user-search-input').value = 'a';
		document.querySelector('.ffc-search-user-btn').click();
		expect(globalThis.alert).toHaveBeenCalledWith('Please enter at least 2 characters.');
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

	it('alerts the search-error string when the request rejects without fromServer', async () => {
		vi.spyOn(window.$, 'post').mockImplementation(() => postChain({ fail: { status: 0 } }));
		globalThis.alert.mockClear?.();
		document.getElementById('ffc-user-search-input').value = 'qu';
		document.querySelector('.ffc-search-user-btn').click();
		await flush();
		expect(globalThis.alert).toHaveBeenCalledWith('Search error');
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

	it('clear-selection empties the hidden id and hides the preview', () => {
		document.querySelector('.ffc-search-result-item').click();
		document.querySelector('.ffc-clear-selection').click();
		expect(document.getElementById('ffc-selected-user-id').value).toBe('');
		expect(document.getElementById('ffc-selected-user-preview').style.display).toBe('none');
	});
});

// ----------------------------------------------------------------------
// Collapsible consent section
// ----------------------------------------------------------------------

describe('Collapsible consent section', () => {
	beforeEach(async () => {
		window.$.fx.off = true;
		document.body.innerHTML = `
			<div class="ffc-consent-box is-open">
				<div class="ffc-consent-header" tabindex="0" aria-expanded="true">Consent</div>
				<div class="ffc-consent-details">details</div>
			</div>
		`;
		await loadOnReady();
	});

	it('clicking the header collapses an open consent box', () => {
		const header = document.querySelector('.ffc-consent-header');
		header.click();
		const box = document.querySelector('.ffc-consent-box');
		expect(box.classList.contains('is-open')).toBe(false);
		expect(header.getAttribute('aria-expanded')).toBe('false');
	});

	it('clicking the header again re-opens a collapsed box', () => {
		const header = document.querySelector('.ffc-consent-header');
		header.click(); // close
		header.click(); // open
		const box = document.querySelector('.ffc-consent-box');
		expect(box.classList.contains('is-open')).toBe(true);
		expect(header.getAttribute('aria-expanded')).toBe('true');
	});

	it('Enter keypress toggles the section', () => {
		const header = document.querySelector('.ffc-consent-header');
		const ev = window.$.Event('keypress', { which: 13 });
		window.$(header).trigger(ev);
		expect(document.querySelector('.ffc-consent-box').classList.contains('is-open')).toBe(false);
	});

	it('ignores non-Enter/Space keypresses', () => {
		const header = document.querySelector('.ffc-consent-header');
		const ev = window.$.Event('keypress', { which: 65 });
		window.$(header).trigger(ev);
		// Still open — the handler returned early.
		expect(document.querySelector('.ffc-consent-box').classList.contains('is-open')).toBe(true);
	});
});
