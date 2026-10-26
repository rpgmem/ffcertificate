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

	function choose(input, files) {
		Object.defineProperty(input, 'files', { value: files, configurable: true });
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
		// A file is chosen: the check button follows the field (#2038).
		choose(document.querySelector('#ffc-bulk-issue input[type="file"]'), [new File(['name\n'], 'people.csv', { type: 'text/csv' })]);
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

	it('sends the issue-date choice shown on the mapping step with the file', async () => {
		respond({ success: true, data: { html: '', label: 'Preview' } });
		loadScript('assets/js/ffc-bulk-issue.js');
		document.querySelector('.ffc-bulk-issue-result').innerHTML = `
			<select name="ffc_issue_mode"><option value="event" selected>One date</option></select>
			<input type="date" name="ffc_issue_date" value="2026-05-20">
			<select name="ffc_issue_date_column"><option value="">Not in this file</option></select>`;

		click();
		await flush();

		const body = fetchMock.mock.calls[0][1].body;
		expect(body.get('ffc_issue_mode')).toBe('event');
		expect(body.get('ffc_issue_date')).toBe('2026-05-20');
		expect(body.get('ffc_issue_date_column')).toBe('');
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
	it('starts the job and runs batches until the server reports it done', async () => {
		const replies = [
			{ success: true, data: { html: '<p><button type="button" class="button ffc-bulk-issue-start">Issue 2</button></p><p class="ffc-bulk-issue-progress"></p>', label: 'Preview' } },
			{ success: true, data: { job_id: 3 } },
			{ success: true, data: { done: false, text: '1 of 2 line(s) handled' } },
			{ success: true, data: { done: true, text: 'Done: 2 certificate(s) issued, 0 line(s) skipped.' } },
		];
		fetchMock = vi.fn(() => Promise.resolve({ json: () => Promise.resolve(replies.shift()) }));
		window.fetch = fetchMock;
		window.ffcBulkIssue.runNonce = 'run';
		loadScript('assets/js/ffc-bulk-issue.js');

		click();
		await flush();
		document.querySelector('.ffc-bulk-issue-start').click();
		for (let i = 0; i < 8; i++) {
			await flush();
		}

		const actions = fetchMock.mock.calls.map(([, options]) => options.body.get('action'));
		expect(actions).toEqual([
			'ffc_bulk_issue_preview',
			'ffc_bulk_issue_start',
			'ffc_bulk_issue_batch',
			'ffc_bulk_issue_batch',
		]);
		expect(fetchMock.mock.calls[2][1].body.get('job_id')).toBe('3');
		expect(fetchMock.mock.calls[2][1].body.get('nonce')).toBe('run');
		expect(document.querySelector('.ffc-bulk-issue-progress').textContent).toBe('Done: 2 certificate(s) issued, 0 line(s) skipped.');
		expect(document.querySelector('.ffc-bulk-issue-start').disabled).toBe(false);
	});

	it('sends the mapping and the choices the preview carries as hidden fields when the job starts', async () => {
		const preview = '<input type="hidden" name="ffc_map[name]" value="Nome">'
			+ '<input type="hidden" name="ffc_issue_mode" value="column">'
			+ '<p><button type="button" class="ffc-bulk-issue-start">Issue 1</button></p>';
		const replies = [
			{ success: true, data: { html: preview, label: 'Preview' } },
			{ success: false, data: { message: 'stop' } },
		];
		fetchMock = vi.fn(() => Promise.resolve({ json: () => Promise.resolve(replies.shift()) }));
		window.fetch = fetchMock;
		window.ffcBulkIssue.runNonce = 'run';
		loadScript('assets/js/ffc-bulk-issue.js');

		click();
		await flush();
		document.querySelector('.ffc-bulk-issue-start').click();
		await flush();

		const start = fetchMock.mock.calls[1][1].body;
		expect(start.get('action')).toBe('ffc_bulk_issue_start');
		expect(start.get('ffc_map[name]')).toBe('Nome');
		expect(start.get('ffc_issue_mode')).toBe('column');
	});


	describe('the check button follows the file field (#2038)', () => {
		it('is disabled until a file is chosen, then enabled', () => {
			const input = document.querySelector('#ffc-bulk-issue input[type="file"]');
			choose(input, []);
			respond({ success: true, data: { html: '', label: 'Preview' } });
			loadScript('assets/js/ffc-bulk-issue.js');

			const button = document.querySelector('#ffc-bulk-issue button');
			expect(button.disabled).toBe(true);
			click();
			expect(fetchMock).not.toHaveBeenCalled();

			choose(input, [new File(['name\n'], 'people.csv', { type: 'text/csv' })]);
			input.dispatchEvent(new Event('change'));
			expect(button.disabled).toBe(false);

			choose(input, []);
			input.dispatchEvent(new Event('change'));
			expect(button.disabled).toBe(true);
		});

		it('stays disabled after a request when the file was cleared meanwhile', async () => {
			respond({ success: true, data: { html: '', label: 'Preview' } });
			loadScript('assets/js/ffc-bulk-issue.js');
			click();
			choose(document.querySelector('#ffc-bulk-issue input[type="file"]'), []);
			await flush();

			expect(document.querySelector('#ffc-bulk-issue button').disabled).toBe(true);
		});
	});
});
