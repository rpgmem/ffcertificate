/**
 * ffc-bulk-issue.js — the Bulk issue tab of the form editor (#1967).
 *
 * fetch is stubbed: the contract under test is what the tab posts (the file,
 * the form, the nonce and the mapping shown so far) and what it writes back
 * (the server's HTML, its button label, and errors as text, never markup).
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('ffc-bulk-issue.js', () => {
	let fetchMock;

	function respond(json) {
		fetchMock = vi.fn(() => Promise.resolve({ json: () => Promise.resolve(json) }));
		window.fetch = fetchMock;
	}

	function click() {
		document.querySelector('#ffc-bulk-issue button').click();
	}

	beforeEach(() => {
		document.body.innerHTML = `
			<div id="ffc-bulk-issue" data-form-id="42">
				<input type="file" name="ffc_bulk_file">
				<button type="button">Check the file</button>
				<div class="ffc-bulk-issue-result"></div>
			</div>`;
		window.ffcBulkIssue = { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'abc', error: 'Try again.' };
	});

	afterEach(() => {
		delete window.ffcBulkIssue;
		delete window.fetch;
	});

	it('posts the action, the form and the nonce, then shows the step and its label', async () => {
		respond({ success: true, data: { html: '<select name="ffc_map[name]"></select>', label: 'Preview' } });
		loadScript('assets/js/ffc-bulk-issue.js');

		click();
		await flush();

		const [url, options] = fetchMock.mock.calls[0];
		expect(url).toBe('/wp-admin/admin-ajax.php');
		expect(options.body.get('action')).toBe('ffc_bulk_issue_preview');
		expect(options.body.get('nonce')).toBe('abc');
		expect(options.body.get('form_id')).toBe('42');

		expect(document.querySelector('.ffc-bulk-issue-result select[name="ffc_map[name]"]')).not.toBeNull();
		expect(document.querySelector('#ffc-bulk-issue button').textContent).toBe('Preview');
		expect(document.querySelector('#ffc-bulk-issue button').disabled).toBe(false);
	});

	it('sends the columns shown on the previous step back with the file', async () => {
		respond({ success: true, data: { html: '', label: 'Preview' } });
		loadScript('assets/js/ffc-bulk-issue.js');
		document.querySelector('.ffc-bulk-issue-result').innerHTML = '<select name="ffc_map[email]"><option value="E-mail" selected>E-mail</option></select>';

		click();
		await flush();

		expect(fetchMock.mock.calls[0][1].body.get('ffc_map[email]')).toBe('E-mail');
	});

	it('shows the server refusal as text, never as markup', async () => {
		respond({ success: false, data: { message: '<b>Access denied</b>' } });
		loadScript('assets/js/ffc-bulk-issue.js');

		click();
		await flush();

		const message = document.querySelector('.ffc-bulk-issue-result .notice-error p');
		expect(message.textContent).toBe('<b>Access denied</b>');
		expect(message.querySelector('b')).toBeNull();
	});

	it('shows the fallback message when the request fails', async () => {
		window.fetch = vi.fn(() => Promise.reject(new Error('offline')));
		loadScript('assets/js/ffc-bulk-issue.js');

		click();
		await flush();

		expect(document.querySelector('.ffc-bulk-issue-result .notice-error p').textContent).toBe('Try again.');
		expect(document.querySelector('#ffc-bulk-issue button').disabled).toBe(false);
	});
});
