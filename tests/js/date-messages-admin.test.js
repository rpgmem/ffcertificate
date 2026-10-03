// Tests for `assets/js/ffc-date-messages-admin.js`.
//
// The preview renders what the server returned and nothing else: names only
// when the server sent them, every value as text.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-date-messages-admin.js';

async function loadOnReady() {
	loadScript(SCRIPT);
	await new Promise((r) => setTimeout(r, 0));
}

const flush = () => new Promise((r) => setTimeout(r, 0));

function installSendDom() {
	document.body.innerHTML = `
		<form id="ffc-dm-send-form" data-ffc-confirm="Send?">
			<div class="ffc-dm-preview" data-ffc-dm-preview="saved">
				<select class="ffc-dm-rule"><option value="7" selected>R</option></select>
				<input class="ffc-dm-from" value="2026-10-01">
				<input class="ffc-dm-to" value="2026-10-02">
				<button type="button" class="ffc-dm-preview-button">Preview</button>
				<div class="ffc-dm-preview-result"></div>
			</div>
		</form>
	`;
}

function installEditorDom() {
	document.body.innerHTML = `
		<form id="ffc-dm-rule-form">
			<input name="rule[id]" value="0">
			<input name="rule[name]" value="Week">
			<input name="rule[offset_days]" value="-7">
			<input name="other" value="ignored">
			<div class="ffc-dm-preview" data-ffc-dm-preview="form">
				<input class="ffc-dm-from" value="2026-10-08">
				<input class="ffc-dm-to" value="2026-10-08">
				<button type="button" class="ffc-dm-preview-button">Preview</button>
				<button type="button" class="ffc-dm-test-button">Test</button>
				<div class="ffc-dm-preview-result"></div>
			</div>
		</form>
	`;
}

let request;

beforeEach(() => {
	// The script delegates from `document`, which outlives each test; drop
	// the handlers an earlier load left there.
	window.$(document).off();
	document.body.innerHTML = '';
	request = vi.fn();
	window.FFC = { request };
	window.ffcDateMessages = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		previewAction: 'ffc_date_messages_preview',
		previewNonce: 'pn',
		testAction: 'ffc_date_messages_test_send',
		testNonce: 'tn',
		decisions: { will_send: 'Will receive', opted_out: 'Opted out' },
		strings: { loading: 'Working', failed: 'Failed', truncated: 'Truncated', none: 'Nobody' },
	};
});

describe('ffc-date-messages-admin', () => {
	it('previews a saved rule by id and renders the totals only, without names', async () => {
		installSendDom();
		request.mockResolvedValue({ totals: { will_send: 3, opted_out: 1 }, rows: [], pii: false });
		await loadOnReady();

		window.$('.ffc-dm-preview-button').trigger('click');
		await flush();

		expect(request).toHaveBeenCalledWith(
			'ffc_date_messages_preview',
			{ rule_id: '7', from: '2026-10-01', to: '2026-10-02' },
			{ nonce: 'pn', ajaxUrl: '/wp-admin/admin-ajax.php' }
		);
		const text = document.querySelector('.ffc-dm-preview-result').textContent;
		expect(text).toContain('Will receive: 3');
		expect(text).toContain('Opted out: 1');
		expect(document.querySelector('.ffc-dm-preview-result table')).toBeNull();
	});

	it('lists people as text when the server sends them, and says when the list was cut', async () => {
		installSendDom();
		request.mockResolvedValue({
			totals: { will_send: 1 },
			rows: [{ name: '<b>Ana</b>', email: 'a***@x.org', date: '2026-10-01', decision: 'will_send' }],
			pii: true,
			truncated: true,
		});
		await loadOnReady();

		window.$('.ffc-dm-preview-button').trigger('click');
		await flush();

		const cell = document.querySelector('.ffc-dm-preview-result td');
		expect(cell.textContent).toBe('<b>Ana</b>');
		expect(cell.querySelector('b')).toBeNull();
		expect(document.querySelector('.ffc-dm-preview-result').textContent).toContain('Truncated');
	});

	it('posts the unsaved editor fields, and only them, for the preview', async () => {
		installEditorDom();
		request.mockResolvedValue({ totals: {}, rows: [], pii: true });
		await loadOnReady();

		window.$('.ffc-dm-preview-button').trigger('click');
		await flush();

		const payload = request.mock.calls[0][1];
		expect(typeof payload).toBe('string');
		expect(payload).toContain('rule%5Bname%5D=Week');
		expect(payload).toContain('rule%5Boffset_days%5D=-7');
		expect(payload).toContain('from=2026-10-08');
		expect(payload).not.toContain('other=');
		expect(document.querySelector('.ffc-dm-preview-result').textContent).toContain('Nobody');
	});

	it('shows the server message on a test send, and the error on a failure', async () => {
		installEditorDom();
		request.mockResolvedValueOnce({ message: 'Sent to you' });
		await loadOnReady();

		window.$('.ffc-dm-test-button').trigger('click');
		await flush();
		expect(request.mock.calls[0][0]).toBe('ffc_date_messages_test_send');
		expect(request.mock.calls[0][2].nonce).toBe('tn');
		expect(document.querySelector('.notice-success').textContent).toBe('Sent to you');

		request.mockRejectedValueOnce(new Error('No address'));
		window.$('.ffc-dm-test-button').trigger('click');
		await flush();
		expect(document.querySelector('.notice-error').textContent).toBe('No address');
		expect(document.querySelector('.ffc-dm-test-button').disabled).toBe(false);
	});

	it('asks before submitting a form that carries a confirmation', async () => {
		installSendDom();
		await loadOnReady();
		const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);

		const event = window.$.Event('submit');
		window.$('#ffc-dm-send-form').trigger(event);

		expect(confirm).toHaveBeenCalledWith('Send?');
		expect(event.isDefaultPrevented()).toBe(true);
		confirm.mockRestore();
	});
});
