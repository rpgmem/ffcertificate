// Tests for `assets/js/ffc-admin-submission-edit.js`.
//
// Script uses direct `$(selector).on(...)` binding (not delegated), so
// the elements must exist BEFORE the script loads. Each describe block
// sets up its own DOM and re-loads the script in beforeEach.
//
// Sprint O of #175.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

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

async function loadOnReady() {
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

	it('calls execCommand("copy") on click', () => {
		document.querySelector('.ffc-copy-magic-link').click();
		expect(document.execCommand).toHaveBeenCalledWith('copy');
	});

	it("changes the button text to the localized 'copied' string", () => {
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

	it('does nothing when the user declines the confirmation', () => {
		vi.spyOn(window, 'confirm').mockReturnValue(false);
		const form = document.querySelector('form');
		const submitSpy = vi.spyOn(form, 'submit').mockImplementation(() => {});
		document.querySelector('.ffc-unlink-user-btn').click();
		expect(submitSpy).not.toHaveBeenCalled();
		// Hidden input untouched.
		expect(document.querySelector('input[name="linked_user_id"]').value).toBe('42');
	});

	it('empties the linked_user_id and submits the form on confirm', () => {
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

	it("alerts when the search term is shorter than 2 characters", () => {
		globalThis.alert.mockClear?.();
		document.getElementById('ffc-user-search-input').value = 'a';
		document.querySelector('.ffc-search-user-btn').click();
		expect(globalThis.alert).toHaveBeenCalledWith('Please enter at least 2 characters.');
	});

	it('fires AJAX with the search term + nonce when >= 2 chars', () => {
		const spy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));
		document.getElementById('ffc-user-search-input').value = 'maria';
		document.querySelector('.ffc-search-user-btn').click();
		expect(spy).toHaveBeenCalledOnce();
		const call = spy.mock.calls[0][0];
		expect(call.data.action).toBe('ffc_search_user');
		expect(call.data.search).toBe('maria');
		expect(call.data.nonce).toBe('search-nonce');
	});

	it('renders search results into #ffc-user-search-results on success', () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({
				success: true,
				data: {
					users: [
						{ id: 1, name: 'Maria', email: 'maria@example.com' },
						{ id: 2, name: 'João',  email: 'joao@example.com'  },
					],
				},
			});
			return {};
		});
		document.getElementById('ffc-user-search-input').value = 'qu';
		document.querySelector('.ffc-search-user-btn').click();
		expect(document.querySelectorAll('#ffc-user-search-results .ffc-search-result-item').length).toBe(2);
	});

	it("renders the no-users message when response.success is false", () => {
		vi.spyOn(window.$, 'ajax').mockImplementation((opts) => {
			opts.success({ success: false, data: { message: 'Custom not-found' } });
			return {};
		});
		document.getElementById('ffc-user-search-input').value = 'qu';
		document.querySelector('.ffc-search-user-btn').click();
		expect(document.getElementById('ffc-user-search-results').textContent).toContain('Custom not-found');
	});

	it('also triggers the search on Enter keypress', () => {
		const spy = vi.spyOn(window.$, 'ajax').mockImplementation(() => ({}));
		const input = document.getElementById('ffc-user-search-input');
		input.value = 'enter-key';
		// Build a real keypress event with `which === 13`.
		const ev = window.$.Event('keypress', { which: 13 });
		window.$(input).trigger(ev);
		expect(spy).toHaveBeenCalledOnce();
	});
});
