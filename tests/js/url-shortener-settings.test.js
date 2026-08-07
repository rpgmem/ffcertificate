// Coverage for ffc-url-shortener-settings.js (#886): the Expose↔Shorten
// checkbox coupling and the batched backfill AJAX driver.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// jQuery $.post(...).done(...).fail(...) chain double. `done` fires the
// provided response synchronously; `fail` fires when spec.fail is set.
function postChain(spec) {
	const chain = { done: () => chain, fail: () => chain };
	if (spec && 'done' in spec) chain.done = (cb) => { cb(spec.done); return chain; };
	if (spec && spec.fail) chain.fail = (cb) => { cb(spec.fail === true ? undefined : spec.fail); return chain; };
	return chain;
}

beforeAll(() => {
	window.$.fx.off = true;
});

beforeEach(() => {
	// loadScript re-runs the IIFE each test, which re-binds the delegated
	// document handlers; clear them so a click fires exactly one handler.
	if (window.$) { window.$(document).off('click').off('change'); }
	document.body.innerHTML = '';
	window.ffcUrlShortenerSettings = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		i18n: {
			working: 'Generating…',
			done: 'Done.',
			createdSuffix: 'short URLs created.',
			error: 'An error occurred.',
		},
	};
});

afterEach(() => {
	vi.restoreAllMocks();
	delete window.$.post;
});

async function loadSettings() {
	loadScript('assets/js/ffc-url-shortener-settings.js');
	await new Promise((r) => setTimeout(r, 0));
}

// ----------------------------------------------------------------------
// Expose ↔ Shorten coupling
// ----------------------------------------------------------------------

describe('url-shortener settings — expose coupling', () => {
	function renderRow(shortenChecked) {
		document.body.innerHTML = `
			<table><tbody>
				<tr>
					<td><input type="checkbox" class="ffc-shorten-type" value="post" ${shortenChecked ? 'checked' : ''}></td>
					<td><input type="checkbox" class="ffc-expose-type" value="post" ${shortenChecked ? '' : 'disabled'}></td>
				</tr>
			</tbody></table>`;
	}

	it('disables and unchecks Expose when Shorten is unchecked', async () => {
		renderRow(true);
		await loadSettings();

		const $expose = window.$('.ffc-expose-type');
		$expose.prop('checked', true);

		window.$('.ffc-shorten-type').prop('checked', false).trigger('change');

		expect($expose.prop('disabled')).toBe(true);
		expect($expose.prop('checked')).toBe(false);
	});

	it('enables Expose when Shorten is checked', async () => {
		renderRow(false);
		await loadSettings();

		window.$('.ffc-shorten-type').prop('checked', true).trigger('change');

		expect(window.$('.ffc-expose-type').prop('disabled')).toBe(false);
	});
});

// ----------------------------------------------------------------------
// Backfill driver
// ----------------------------------------------------------------------

describe('url-shortener settings — backfill', () => {
	function renderBackfill() {
		document.body.innerHTML = `
			<input type="hidden" id="ffc_url_shortener_backfill_nonce" value="bf-nonce">
			<button id="ffc-url-shortener-backfill">Generate</button>
			<span id="ffc-url-shortener-backfill-status"></span>`;
	}

	it('loops cursor→next_cursor until done and reports the total created', async () => {
		renderBackfill();
		await loadSettings();

		window.$.post = vi.fn()
			.mockImplementationOnce(() => postChain({ done: { success: true, data: { created: 2, total: 5, done: false, next_cursor: 8 } } }))
			.mockImplementationOnce(() => postChain({ done: { success: true, data: { created: 3, done: true, next_cursor: 0 } } }));

		window.$('#ffc-url-shortener-backfill').trigger('click');

		// Two batches: first page (cursor 0) then the continuation (cursor 8).
		expect(window.$.post).toHaveBeenCalledTimes(2);
		expect(window.$.post.mock.calls[0][1].cursor).toBe(0);
		expect(window.$.post.mock.calls[0][1].nonce).toBe('bf-nonce');
		expect(window.$.post.mock.calls[1][1].cursor).toBe(8);

		const status = window.$('#ffc-url-shortener-backfill-status').text();
		expect(status).toContain('Done.');
		expect(status).toContain('5'); // 2 + 3 created.
		expect(window.$('#ffc-url-shortener-backfill').prop('disabled')).toBe(false);
	});

	it('shows an error and re-enables the button on request failure', async () => {
		renderBackfill();
		await loadSettings();

		window.$.post = vi.fn(() => postChain({ fail: true }));

		window.$('#ffc-url-shortener-backfill').trigger('click');

		expect(window.$('#ffc-url-shortener-backfill-status').text()).toContain('An error occurred.');
		expect(window.$('#ffc-url-shortener-backfill').prop('disabled')).toBe(false);
	});
});
