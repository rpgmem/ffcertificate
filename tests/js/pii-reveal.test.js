// Coverage for assets/js/ffc-pii-reveal.js — the shared masked-PII reveal
// handler (#739 §3.3). Drives the input case (submission edit page), the text
// case (appointment detail page), the default-type fallback, and the error
// path. FFC.request is mocked directly; the handler only depends on it
// returning a Promise.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function flush() {
	return Promise.resolve().then(() => Promise.resolve());
}

beforeEach(() => {
	document.body.innerHTML = '';
	window.ffcPiiReveal = { reveal_error: 'Unable to reveal this value.' };
	if (!window.FFC) {
		loadScript('assets/js/ffc-core.js');
	}
});

afterEach(() => {
	vi.restoreAllMocks();
});

async function load() {
	loadScript('assets/js/ffc-pii-reveal.js');
	await new Promise((r) => setTimeout(r, 0));
}

describe('ffc-pii-reveal', () => {
	it('reveals into an input and removes the button (submission)', async () => {
		document.body.innerHTML = `<table><tr><td>
			<input data-ffc-pii-field="cpf" value="***" />
			<button class="ffc-reveal-pii" data-field="cpf" data-type="submission" data-submission-id="5" data-nonce="n">Reveal</button>
		</td></tr></table>`;
		window.FFC.request = vi.fn(() => Promise.resolve({ field: 'cpf', value: '123.456.789-01' }));

		await load();
		window.$('.ffc-reveal-pii').trigger('click');
		await flush();

		expect(window.FFC.request).toHaveBeenCalledWith(
			'ffc_reveal_pii',
			{ submission_id: 5, field: 'cpf', type: 'submission' },
			{ nonce: 'n' }
		);
		expect(window.$('[data-ffc-pii-field="cpf"]').val()).toBe('123.456.789-01');
		expect(window.$('.ffc-reveal-pii').length).toBe(0);
	});

	it('reveals into an input inside a facts list (submission edit page, #1655)', async () => {
		document.body.innerHTML = `<dl><dd>
			<input data-ffc-pii-field="cpf" value="***" readonly />
			<button class="ffc-reveal-pii" data-field="cpf" data-submission-id="12" data-nonce="n">Reveal</button>
		</dd></dl>`;
		window.FFC.request = vi.fn(() => Promise.resolve({ field: 'cpf', value: '123.456.789-01' }));

		await load();
		window.$('.ffc-reveal-pii').trigger('click');
		await flush();

		expect(window.$('[data-ffc-pii-field="cpf"]').val()).toBe('123.456.789-01');
	});

	it('enables an editable input only once revealed (submission email, #1655)', async () => {
		document.body.innerHTML = `<form><p class="ffc-edit-field">
			<input type="email" name="user_email" data-ffc-pii-field="email" data-ffc-pii-editable="1" value="m***@x.com" disabled />
			<button class="ffc-reveal-pii" data-field="email" data-submission-id="12" data-nonce="n">Reveal</button>
			<span class="ffc-pii-reveal-hint">Reveal the email to edit it.</span>
		</p></form>`;
		window.FFC.request = vi.fn(() => Promise.resolve({ field: 'email', value: 'me@x.com' }));

		await load();
		expect(window.$('form').serialize()).toBe('');
		window.$('.ffc-reveal-pii').trigger('click');
		await flush();

		const $input = window.$('[data-ffc-pii-field="email"]');
		expect($input.val()).toBe('me@x.com');
		expect($input.prop('disabled')).toBe(false);
		expect(window.$('.ffc-pii-reveal-hint').length).toBe(0);
		expect(window.$('form').serialize()).toBe('user_email=me%40x.com');
	});

	it('reveals the email from its row and drops the hint outside the row (#1760)', async () => {
		document.body.innerHTML = `<form><div class="ffc-edit-field">
			<span class="ffc-pii-row">
				<input type="email" name="user_email" data-ffc-pii-field="email" data-ffc-pii-editable="1" value="m***@x.com" disabled />
				<button class="ffc-reveal-pii" data-field="email" data-submission-id="12" data-nonce="n">Reveal</button>
			</span>
			<span class="ffc-pii-reveal-hint">Reveal the email to edit it.</span>
		</div></form>`;
		window.FFC.request = vi.fn(() => Promise.resolve({ field: 'email', value: 'me@x.com' }));

		await load();
		window.$('.ffc-reveal-pii').trigger('click');
		await flush();

		expect(window.$('[data-ffc-pii-field="email"]').prop('disabled')).toBe(false);
		expect(window.$('.ffc-pii-reveal-hint').length).toBe(0);
		expect(window.$('form').serialize()).toBe('user_email=me%40x.com');
	});

	it('reveals only the IP display whose button was clicked (#1760)', async () => {
		document.body.innerHTML = `<dl><dd><span class="ffc-pii-row">
			<input data-ffc-pii-field="ip" value="203.0.***.***" readonly />
			<button class="ffc-reveal-pii" data-field="ip" data-submission-id="12" data-nonce="n">Reveal</button>
		</span></dd></dl>
		<p class="description">IP: <span class="ffc-pii-row" id="consent">
			<input data-ffc-pii-field="ip" value="203.0.***.***" readonly />
			<button class="ffc-reveal-pii" data-field="ip" data-submission-id="12" data-nonce="n">Reveal</button>
		</span></p>`;
		window.FFC.request = vi.fn(() => Promise.resolve({ field: 'ip', value: '203.0.113.7' }));

		await load();
		window.$('#consent .ffc-reveal-pii').trigger('click');
		await flush();

		expect(window.FFC.request).toHaveBeenCalledWith(
			'ffc_reveal_pii',
			{ submission_id: 12, field: 'ip', type: 'submission' },
			{ nonce: 'n' }
		);
		expect(window.$('#consent [data-ffc-pii-field="ip"]').val()).toBe('203.0.113.7');
		expect(window.$('dd [data-ffc-pii-field="ip"]').val()).toBe('203.0.***.***');
		expect(window.$('dd .ffc-reveal-pii').length).toBe(1);
	});

	it('leaves the editable input disabled when the reveal fails', async () => {
		document.body.innerHTML = `<p class="ffc-edit-field">
			<input type="email" name="user_email" data-ffc-pii-field="email" data-ffc-pii-editable="1" value="m***@x.com" disabled />
			<button class="ffc-reveal-pii" data-field="email" data-submission-id="12" data-nonce="n">Reveal</button>
		</p>`;
		window.FFC.request = vi.fn(() => Promise.reject(new Error('nope')));
		vi.spyOn(window, 'alert').mockImplementation(() => {});

		await load();
		window.$('.ffc-reveal-pii').trigger('click');
		await flush();

		expect(window.$('[data-ffc-pii-field="email"]').prop('disabled')).toBe(true);
		expect(window.$('[data-ffc-pii-field="email"]').val()).toBe('m***@x.com');
	});

	it('reveals into a text node for an appointment field', async () => {
		document.body.innerHTML = `<table><tr><td>
			<span class="ffc-pii-value" data-field="email">m***@x.com</span>
			<button class="ffc-reveal-pii" data-field="email" data-type="appointment" data-submission-id="7" data-nonce="n2">Reveal</button>
		</td></tr></table>`;
		window.FFC.request = vi.fn(() => Promise.resolve({ field: 'email', value: 'me@x.com' }));

		await load();
		window.$('.ffc-reveal-pii').trigger('click');
		await flush();

		expect(window.FFC.request).toHaveBeenCalledWith(
			'ffc_reveal_pii',
			{ submission_id: 7, field: 'email', type: 'appointment' },
			{ nonce: 'n2' }
		);
		expect(window.$('.ffc-pii-value[data-field="email"]').text()).toBe('me@x.com');
		expect(window.$('.ffc-reveal-pii').length).toBe(0);
	});

	it('reveals only the clicked row of a list table (#1822)', async () => {
		document.body.innerHTML = `<table class="wp-list-table"><tbody>
			<tr id="r1"><td class="column-id">1</td><td class="email column-email">
				<span class="ffc-pii-value" data-field="email">a***@x.com</span>
				<button class="ffc-reveal-pii" data-type="submission" data-field="email" data-submission-id="1" data-nonce="n">Reveal</button>
			</td></tr>
			<tr id="r2"><td class="column-id">2</td><td class="email column-email">
				<span class="ffc-pii-value" data-field="email">b***@x.com</span>
				<button class="ffc-reveal-pii" data-type="submission" data-field="email" data-submission-id="2" data-nonce="n">Reveal</button>
			</td></tr>
		</tbody></table>`;
		window.FFC.request = vi.fn(() => Promise.resolve({ field: 'email', value: 'bob@x.com' }));

		await load();
		window.$('#r2 .ffc-reveal-pii').trigger('click');
		await flush();

		expect(window.FFC.request).toHaveBeenCalledWith(
			'ffc_reveal_pii',
			{ submission_id: 2, field: 'email', type: 'submission' },
			{ nonce: 'n' }
		);
		expect(window.$('#r2 .ffc-pii-value').text()).toBe('bob@x.com');
		expect(window.$('#r1 .ffc-pii-value').text()).toBe('a***@x.com');
		expect(window.$('#r1 .ffc-reveal-pii').length).toBe(1);
		expect(window.$('#r2 .ffc-reveal-pii').length).toBe(0);
	});

	it('reveals the linked account email from the actions row below its card, without touching the submission email (#1824)', async () => {
		document.body.innerHTML = `<form>
			<p class="ffc-edit-field"><span class="ffc-pii-row">
				<input type="email" name="user_email" data-ffc-pii-field="email" data-ffc-pii-editable="1" value="s***@x.com" disabled />
				<button class="ffc-reveal-pii" id="sub" data-field="email" data-submission-id="12" data-nonce="n">Reveal</button>
			</span></p>
			<div class="ffc-linked-user-display">
				<div class="ffc-current-user"><span class="ffc-user-email"><span class="ffc-pii-value" data-field="account_email">a***@x.com</span></span></div>
				<div class="ffc-linked-user-actions">
					<button class="ffc-reveal-pii" id="acct" data-field="account_email" data-submission-id="12" data-nonce="n">Reveal</button>
				</div>
			</div>
		</form>`;
		window.FFC.request = vi.fn(() => Promise.resolve({ field: 'account_email', value: 'account@x.com' }));

		await load();
		window.$('#acct').trigger('click');
		await flush();

		expect(window.FFC.request).toHaveBeenCalledWith(
			'ffc_reveal_pii',
			{ submission_id: 12, field: 'account_email', type: 'submission' },
			{ nonce: 'n' }
		);
		expect(window.$('.ffc-pii-value[data-field="account_email"]').text()).toBe('account@x.com');
		expect(window.$('[data-ffc-pii-field="email"]').val()).toBe('s***@x.com');
		expect(window.$('[data-ffc-pii-field="email"]').prop('disabled')).toBe(true);
		expect(window.$('#sub').length).toBe(1);
	});

	it('reveals a row inserted after load, once per click (#1824)', async () => {
		document.body.innerHTML = '<table><tbody id="perms"></tbody></table>';
		window.FFC.request = vi.fn(() => Promise.resolve({ field: 'email', value: 'member@x.com' }));

		await load();
		// Loading twice must not bind twice.
		await load();
		window.$('#perms').append(`<tr><td><strong>Member</strong>
			<br><span class="description"><span class="ffc-pii-row"><span class="ffc-pii-value" data-field="email">m***@x.com</span>
			<button class="ffc-reveal-pii" data-type="audience_account" data-field="email" data-submission-id="7" data-nonce="n7">Reveal</button></span></span>
		</td></tr>`);
		window.$('.ffc-reveal-pii').trigger('click');
		await flush();

		expect(window.FFC.request).toHaveBeenCalledTimes(1);
		expect(window.FFC.request).toHaveBeenCalledWith(
			'ffc_reveal_pii',
			{ submission_id: 7, field: 'email', type: 'audience_account' },
			{ nonce: 'n7' }
		);
		expect(window.$('.ffc-pii-value[data-field="email"]').text()).toBe('member@x.com');
		expect(window.$('.ffc-reveal-pii').length).toBe(0);
	});

	it('defaults type to submission when data-type is absent', async () => {
		document.body.innerHTML = `<table><tr><td>
			<span class="ffc-pii-value" data-field="rf">1*.***.***</span>
			<button class="ffc-reveal-pii" data-field="rf" data-submission-id="3" data-nonce="n3">Reveal</button>
		</td></tr></table>`;
		window.FFC.request = vi.fn(() => Promise.resolve({ field: 'rf', value: '12.345.678' }));

		await load();
		window.$('.ffc-reveal-pii').trigger('click');
		await flush();

		expect(window.FFC.request).toHaveBeenCalledWith(
			'ffc_reveal_pii',
			{ submission_id: 3, field: 'rf', type: 'submission' },
			{ nonce: 'n3' }
		);
	});

	it('re-enables the button and alerts on failure', async () => {
		document.body.innerHTML = `<table><tr><td>
			<span class="ffc-pii-value" data-field="cpf">***</span>
			<button class="ffc-reveal-pii" data-field="cpf" data-type="submission" data-submission-id="5" data-nonce="n">Reveal</button>
		</td></tr></table>`;
		window.FFC.request = vi.fn(() => Promise.reject(new Error('boom')));
		window.alert = vi.fn();

		await load();
		window.$('.ffc-reveal-pii').trigger('click');
		await flush();

		expect(window.alert).toHaveBeenCalledWith('boom');
		expect(window.$('.ffc-reveal-pii').prop('disabled')).toBe(false);
		expect(window.$('.ffc-reveal-pii').length).toBe(1);
	});

	// ── "Reveal this page" (#1863) ──────────────────────────────────────

	function listPage() {
		document.body.innerHTML = `<p><button class="ffc-reveal-pii-page" data-nonce="pn">Reveal this page</button></p>
		<table><tbody>
			<tr id="r1"><td><span class="ffc-pii-value" data-field="email">a***@x.com</span>
				<button class="ffc-reveal-pii" data-type="submission" data-field="email" data-submission-id="1" data-nonce="n">Reveal</button></td></tr>
			<tr id="r2"><td><span class="ffc-pii-value" data-field="email">b***@x.com</span>
				<button class="ffc-reveal-pii" data-type="submission" data-field="email" data-submission-id="2" data-nonce="n">Reveal</button></td></tr>
			<tr id="r3"><td><span class="ffc-pii-value" data-field="name">M*** S***</span>
				<button class="ffc-reveal-pii" data-type="date_messages_person" data-field="name" data-submission-id="3" data-nonce="n">Reveal</button></td></tr>
		</tbody></table>`;
	}

	it('sends every reveal button of the page in one request and fills each value', async () => {
		listPage();
		window.FFC.request = vi.fn(() => Promise.resolve({
			values: [
				{ type: 'submission', id: 1, field: 'email', value: 'ann@x.com' },
				{ type: 'submission', id: 2, field: 'email', value: 'bob@x.com' },
				{ type: 'date_messages_person', id: 3, field: 'name', value: 'Maria Silva' },
			],
		}));

		await load();
		window.$('.ffc-reveal-pii-page').trigger('click');
		await flush();

		expect(window.FFC.request).toHaveBeenCalledTimes(1);
		const [action, data, opts] = window.FFC.request.mock.calls[0];
		expect(action).toBe('ffc_reveal_pii_page');
		expect(opts).toEqual({ nonce: 'pn' });
		expect(JSON.parse(data.items)).toEqual([
			{ type: 'submission', id: 1, field: 'email' },
			{ type: 'submission', id: 2, field: 'email' },
			{ type: 'date_messages_person', id: 3, field: 'name' },
		]);
		expect(window.$('#r1 .ffc-pii-value').text()).toBe('ann@x.com');
		expect(window.$('#r2 .ffc-pii-value').text()).toBe('bob@x.com');
		expect(window.$('#r3 .ffc-pii-value').text()).toBe('Maria Silva');
		expect(window.$('.ffc-reveal-pii').length).toBe(0);
		expect(window.$('.ffc-reveal-pii-page').length).toBe(0);
	});

	it('leaves what the server refused masked and keeps the page button for it', async () => {
		listPage();
		window.FFC.request = vi.fn(() => Promise.resolve({
			values: [{ type: 'submission', id: 2, field: 'email', value: 'bob@x.com' }],
		}));

		await load();
		window.$('.ffc-reveal-pii-page').trigger('click');
		await flush();

		expect(window.$('#r1 .ffc-pii-value').text()).toBe('a***@x.com');
		expect(window.$('#r1 .ffc-reveal-pii').length).toBe(1);
		expect(window.$('#r2 .ffc-pii-value').text()).toBe('bob@x.com');
		expect(window.$('.ffc-reveal-pii-page').prop('disabled')).toBe(false);
	});

	it('enables an editable input revealed by the page button', async () => {
		document.body.innerHTML = `<button class="ffc-reveal-pii-page" data-nonce="pn">Reveal this page</button>
		<form><table><tr><td>
			<input type="text" name="ffc_cf_3" data-ffc-pii-field="cf_3" data-ffc-pii-editable="1" value="1***" disabled />
			<button class="ffc-reveal-pii" data-type="user_profile" data-field="cf_3" data-submission-id="9" data-nonce="n">Reveal</button>
		</td></tr></table></form>`;
		window.FFC.request = vi.fn(() => Promise.resolve({ values: [{ type: 'user_profile', id: 9, field: 'cf_3', value: '1234567' }] }));

		await load();
		window.$('.ffc-reveal-pii-page').trigger('click');
		await flush();

		expect(window.$('form').serialize()).toBe('ffc_cf_3=1234567');
	});

	it('removes the page button when there is nothing left to reveal', async () => {
		document.body.innerHTML = '<button class="ffc-reveal-pii-page" data-nonce="pn">Reveal this page</button>';
		window.FFC.request = vi.fn();

		await load();
		window.$('.ffc-reveal-pii-page').trigger('click');
		await flush();

		expect(window.FFC.request).not.toHaveBeenCalled();
		expect(window.$('.ffc-reveal-pii-page').length).toBe(0);
	});

	it('re-enables the page button and alerts when the request fails', async () => {
		listPage();
		window.FFC.request = vi.fn(() => Promise.reject(new Error('denied')));
		window.alert = vi.fn();

		await load();
		window.$('.ffc-reveal-pii-page').trigger('click');
		await flush();

		expect(window.alert).toHaveBeenCalledWith('denied');
		expect(window.$('.ffc-reveal-pii-page').prop('disabled')).toBe(false);
		expect(window.$('.ffc-reveal-pii').length).toBe(3);
	});
});
