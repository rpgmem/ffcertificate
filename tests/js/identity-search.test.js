// Tests for the identity queue's account-search dialog (#1397 sprint 3).
//
// The verdicts themselves are the server's and are proved there, against the
// real write path. What this file covers is the half that lives in the
// browser: that a refused account cannot be chosen, that choosing one writes
// into the move form and bars the split, and that a refusal replaces the list
// instead of being rendered as a candidate.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffcIdentitySearch = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		action: 'ffc_identity_search_accounts',
		nonce: 'search-nonce',
		strings: {
			subtitle: 'Moves the %1$s records carrying %2$s · %3$s',
			searching: 'Searching…',
			found: '%s accounts found',
			noResults: 'No account matches that.',
			chosen: 'Destination: %1$s (#%2$s)',
			move: 'Move to #%s',
			failed: 'The search could not be completed.',
		},
	};
	loadScript('assets/js/ffc-identity-search.js');
});

const DIALOG = `
<div class="ffc-identity-dialog" id="ffc-identity-dialog" hidden>
	<div class="ffc-identity-dialog-backdrop" data-ffc-dialog-dismiss></div>
	<div class="ffc-identity-dialog-panel">
		<p class="ffc-identity-dialog-sub" id="ffc-identity-dialog-sub"></p>
		<button type="button" data-ffc-dialog-dismiss id="ffc-identity-dialog-close"></button>
		<div class="ffc-identity-dialog-suggestion" id="ffc-identity-dialog-suggestion"
			data-head="Suggestion" data-none="No account files any of these."></div>
		<input type="search" id="ffc-identity-dialog-q">
		<div class="ffc-identity-dialog-results" id="ffc-identity-dialog-results"
			data-truncated="More accounts match than are shown."></div>
		<button type="button" id="ffc-identity-dialog-confirm" disabled></button>
	</div>
</div>`;

const FORM = `
<form id="move-form">
	<input type="number" id="ffc-relink-abc" name="ffc_account">
	<button type="button" class="ffc-identity-find"
		data-ffc-subject="abc" data-ffc-field="rf"
		data-ffc-input="ffc-relink-abc"
		data-ffc-split="ffc-split-form-abc"
		data-ffc-submit="ffc-relink-go-abc">Search…</button>
	<button type="submit" id="ffc-relink-go-abc">Move abc</button>
	<span class="ffc-identity-chosen" id="ffc-relink-chosen-abc" hidden></span>
</form>
<form id="ffc-split-form-abc">
	<input type="email" name="ffc_email" required>
	<button type="submit">Split off</button>
	<span class="ffc-identity-split-barred" hidden>A destination is chosen.</span>
</form>`;

/**
 * A `$.post`-shaped chain whose `.done(cb)` calls cb with the next response.
 *
 * @param {Array} queue Responses, taken in order.
 * @returns {Object}
 */
function chainFrom(queue) {
	return function () {
		const response = queue.shift();
		const chain = {
			done(cb) { if (response) { cb(response); } return chain; },
			fail(cb) { if (!response) { cb({ responseJSON: null }, 'error'); } return chain; },
			always(cb) { cb(); return chain; },
			abort() {},
		};
		return chain;
	};
}

function ok(data) {
	return { success: true, data };
}

beforeEach(() => {
	document.body.innerHTML = DIALOG + FORM;
	window.FFC.IdentitySearch.boot();
	vi.useFakeTimers();
});

afterEach(() => {
	vi.restoreAllMocks();
	vi.useRealTimers();
});

describe('the identity account-search dialog', () => {
	it('opens on the move form\'s Search button and names what would move', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom([
			ok({ records: 4, prefix: 'a71b02f4e9dd', field: 'rf', suggestion: null }),
		]));

		window.$('.ffc-identity-find').trigger('click');

		expect(window.$('#ffc-identity-dialog').prop('hidden')).toBe(false);
		expect(window.$('#ffc-identity-dialog-sub').text())
			.toBe('Moves the 4 records carrying a71b02f4e9dd · RF');
	});

	it('states plainly that there is nothing to suggest rather than showing an empty box', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom([
			ok({ records: 1, prefix: 'abc', field: 'cpf', suggestion: null }),
		]));

		window.$('.ffc-identity-find').trigger('click');

		expect(window.$('#ffc-identity-dialog-suggestion').text())
			.toContain('No account files any of these.');
	});

	it('leads with the suggested account when the server names one', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom([
			ok({
				records: 2,
				prefix: 'abc',
				field: 'cpf',
				suggestion: { id: 2208, name: 'Clarice', email: 'c@example.org', allowed: true, label: 'Agrees by CPF', reason: '' },
			}),
		]));

		window.$('.ffc-identity-find').trigger('click');

		const $box = window.$('#ffc-identity-dialog-suggestion');
		expect($box.text()).toContain('Clarice');
		expect($box.find('input[type="radio"]').prop('disabled')).toBe(false);
	});

	it('renders a refused account disabled, with the reason attached', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom([
			ok({ records: 1, prefix: 'abc', field: 'rf', suggestion: null }),
			ok({
				accounts: [
					{ id: 990, name: 'Mirabel', email: 'm@example.org', allowed: false, label: 'Holds a different value — refused', reason: 'They hold different values for: CPF.' },
				],
			}),
		]));

		window.$('.ffc-identity-find').trigger('click');
		window.$('#ffc-identity-dialog-q').val('mira').trigger('input');
		vi.advanceTimersByTime(400);

		const $row = window.$('#ffc-identity-dialog-results .ffc-identity-dialog-result');
		expect($row.hasClass('ffc-identity-dialog-result-refused')).toBe(true);
		expect($row.find('input[type="radio"]').prop('disabled')).toBe(true);
		expect($row.text()).toContain('They hold different values for: CPF.');
		// Never selectable, so the confirm stays barred.
		expect(window.$('#ffc-identity-dialog-confirm').prop('disabled')).toBe(true);
	});

	it('writes the chosen account into the move form and bars the split', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom([
			ok({ records: 3, prefix: 'abc', field: 'rf', suggestion: null }),
			ok({
				accounts: [
					{ id: 2208, name: 'Clarice', email: 'c@example.org', allowed: true, label: 'Agrees by CPF', reason: '' },
				],
			}),
		]));

		window.$('.ffc-identity-find').trigger('click');
		window.$('#ffc-identity-dialog-q').val('cla').trigger('input');
		vi.advanceTimersByTime(400);

		window.$('#ffc-identity-dialog-results input[type="radio"]').trigger('change');
		expect(window.$('#ffc-identity-dialog-confirm').prop('disabled')).toBe(false);

		window.$('#ffc-identity-dialog-confirm').trigger('click');

		expect(window.$('#ffc-relink-abc').val()).toBe('2208');
		expect(window.$('#ffc-relink-go-abc').text()).toBe('Move to #2208');
		expect(window.$('#ffc-relink-chosen-abc').prop('hidden')).toBe(false);
		expect(window.$('#ffc-relink-chosen-abc').text()).toBe('Destination: Clarice (#2208)');

		// Disabled, not merely hidden: a hidden `required` control blocks the
		// submit against something nobody can see (#1114).
		expect(window.$('#ffc-split-form-abc input[name="ffc_email"]').prop('disabled')).toBe(true);
		expect(window.$('#ffc-split-form-abc .ffc-identity-split-barred').prop('hidden')).toBe(false);
		expect(window.$('#ffc-identity-dialog').prop('hidden')).toBe(true);
	});

	it('replaces the list with the refusal when no destination could serve', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom([
			ok({ records: 1, prefix: 'abc', field: 'rf', suggestion: null }),
			undefined, // the search fails
		]));

		window.$('.ffc-identity-find').trigger('click');
		window.$('#ffc-identity-dialog-q').val('x').trigger('input');
		vi.advanceTimersByTime(400);

		const $results = window.$('#ffc-identity-dialog-results');
		expect($results.find('.notice-error').length).toBe(1);
		expect($results.find('.ffc-identity-dialog-result').length).toBe(0);
	});

	it('says so when nothing matched rather than leaving the region empty', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom([
			ok({ records: 1, prefix: 'abc', field: 'rf', suggestion: null }),
			ok({ accounts: [] }),
		]));

		window.$('.ffc-identity-find').trigger('click');
		window.$('#ffc-identity-dialog-q').val('zzz').trigger('input');
		vi.advanceTimersByTime(400);

		expect(window.$('#ffc-identity-dialog-results').text()).toContain('No account matches that.');
	});

	it('reports a capped result set instead of presenting it as complete', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom([
			ok({ records: 1, prefix: 'abc', field: 'rf', suggestion: null }),
			ok({
				accounts: [
					{ id: 1, name: 'A', email: 'a@example.org', allowed: true, label: 'Agrees by CPF', reason: '' },
				],
				truncated: true,
			}),
		]));

		window.$('.ffc-identity-find').trigger('click');
		window.$('#ffc-identity-dialog-q').val('a').trigger('input');
		vi.advanceTimersByTime(400);

		expect(window.$('#ffc-identity-dialog-results').text())
			.toContain('More accounts match than are shown.');
		expect(window.$('#ffc-identity-dialog-results').text()).toContain('1 accounts found');
	});

	it('an empty search clears the results rather than asking the server', () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(chainFrom([
			ok({ records: 1, prefix: 'abc', field: 'rf', suggestion: null }),
		]));

		window.$('.ffc-identity-find').trigger('click');
		postSpy.mockClear();

		window.$('#ffc-identity-dialog-q').val('   ').trigger('input');
		vi.advanceTimersByTime(400);

		expect(postSpy).not.toHaveBeenCalled();
		expect(window.$('#ffc-identity-dialog-results').children().length).toBe(0);
	});

	it('closes on Escape and on the dismiss controls', () => {
		vi.spyOn(window.$, 'post').mockImplementation(chainFrom([
			ok({ records: 1, prefix: 'abc', field: 'rf', suggestion: null }),
			ok({ records: 1, prefix: 'abc', field: 'rf', suggestion: null }),
		]));

		window.$('.ffc-identity-find').trigger('click');
		window.$('#ffc-identity-dialog-close').trigger('click');
		expect(window.$('#ffc-identity-dialog').prop('hidden')).toBe(true);

		window.$('.ffc-identity-find').trigger('click');
		const escape = window.$.Event('keydown');
		escape.which = 27;
		window.$(document).trigger(escape);
		expect(window.$('#ffc-identity-dialog').prop('hidden')).toBe(true);
	});

	it('booting twice does not double the handlers', () => {
		const postSpy = vi.spyOn(window.$, 'post').mockImplementation(chainFrom([
			ok({ records: 1, prefix: 'abc', field: 'rf', suggestion: null }),
		]));

		window.FFC.IdentitySearch.boot();
		window.$('.ffc-identity-find').trigger('click');

		expect(postSpy).toHaveBeenCalledTimes(1);
	});

	it('binds nothing when the screen prints no dialog', () => {
		document.body.innerHTML = FORM;
		expect(window.FFC.IdentitySearch.boot()).toBe(false);
	});
});
