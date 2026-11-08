// Coverage for ffc-url-shortener-settings.js (#886): the Expose↔Shorten
// checkbox coupling and the batched backfill AJAX driver.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

// The real status line helper (#1843); the tests replace only FFC.request.
loadScript('assets/js/ffc-core.js');
const ffcStatus = window.FFC.status;

// Mock window.FFC.request (ffc-core): resolve with each step's `resolve`
// payload in sequence, or reject when `reject` is set. The backfill driver
// awaits these, so tests flush microtasks between recursion steps.
function installFFC(sequence) {
	let i = 0;
	const request = vi.fn(() => {
		const step = sequence[Math.min(i++, sequence.length - 1)];
		return step.reject
			? Promise.reject(new Error('fail'))
			: Promise.resolve(step.resolve);
	});
	window.FFC = { request, status: ffcStatus };
	return request;
}

// Flush enough microtask rounds for the recursive promise chain to settle.
function flush() {
	let p = Promise.resolve();
	for (let n = 0; n < 6; n++) { p = p.then(() => Promise.resolve()); }
	return p;
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
			createdNone: 'Done. None needed.',
			createdOne: 'Done. 1 short URL created.',
			createdMany: 'Done. %d short URLs created.',
			saveFirst: 'Save first.',
			error: 'An error occurred.',
		},
	};
});

afterEach(() => {
	vi.restoreAllMocks();
	delete window.FFC;
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

		const request = installFFC([
			{ resolve: { created: 2, total: 5, done: false, next_cursor: 8 } },
			{ resolve: { created: 3, done: true, next_cursor: 0 } },
		]);

		window.$('#ffc-url-shortener-backfill').trigger('click');
		await flush();

		// Two batches: first page (cursor 0) then the continuation (cursor 8).
		expect(request).toHaveBeenCalledTimes(2);
		expect(request.mock.calls[0][1].cursor).toBe(0);
		expect(request.mock.calls[0][2].nonce).toBe('bf-nonce');
		expect(request.mock.calls[1][1].cursor).toBe(8);

		// 2 + 3 created, in the plural wording.
		expect(window.$('#ffc-url-shortener-backfill-status').text()).toBe('Done. 5 short URLs created.');
		expect(window.$('#ffc-url-shortener-backfill').prop('disabled')).toBe(false);
	});

	it('words the result by its count', async () => {
		await loadSettings();
		const internal = window.ffcUrlShortenerSettingsInternal;

		expect(internal.doneMessage(0)).toBe('Done. None needed.');
		expect(internal.doneMessage(1)).toBe('Done. 1 short URL created.');
		expect(internal.doneMessage(7)).toBe('Done. 7 short URLs created.');
	});

	it('asks to save first when the ticked post types changed, and sends nothing', async () => {
		document.body.innerHTML = `
			<input type="checkbox" class="ffc-shorten-type" value="post" checked>
			<input type="checkbox" class="ffc-shorten-type" value="page">
			<input type="hidden" id="ffc_url_shortener_backfill_nonce" value="bf-nonce">
			<button id="ffc-url-shortener-backfill">Generate</button>
			<span id="ffc-url-shortener-backfill-status"></span>`;
		await loadSettings();
		const request = installFFC([{ resolve: { created: 0, done: true } }]);

		window.$('.ffc-shorten-type[value="page"]').prop('checked', true);
		window.$('#ffc-url-shortener-backfill').trigger('click');
		await flush();

		expect(request).not.toHaveBeenCalled();
		expect(window.$('#ffc-url-shortener-backfill-status').text()).toBe('Save first.');
		expect(window.$('#ffc-url-shortener-backfill').prop('disabled')).toBe(false);
	});

	it('shows an error and re-enables the button on request failure', async () => {
		renderBackfill();
		await loadSettings();

		installFFC([{ reject: true }]);

		window.$('#ffc-url-shortener-backfill').trigger('click');
		await flush();

		expect(window.$('#ffc-url-shortener-backfill-status').text()).toContain('An error occurred.');
		expect(window.$('#ffc-url-shortener-backfill').prop('disabled')).toBe(false);
	});
});
