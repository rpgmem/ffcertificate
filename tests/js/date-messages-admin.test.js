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

// The real notice builder: the script draws its messages through FFC.notice().
loadScript('assets/js/ffc-core.js');
const notice = window.FFC.notice;

function installSendDom() {
	document.body.innerHTML = `
		<form id="ffc-dm-send-form">
			<div class="ffc-dm-preview" data-ffc-dm-preview="saved">
				<select class="ffc-dm-rule"><option value="7" data-ffc-dm-kind="dated" data-ffc-dm-summary-only="0" selected>R</option></select>
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
	window.FFC = { request, notice };
	window.ffcDateMessages = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		previewAction: 'ffc_date_messages_preview',
		previewNonce: 'pn',
		testAction: 'ffc_date_messages_test_send',
		testNonce: 'tn',
		decisions: { will_send: 'Will receive', opted_out: 'Opted out' },
		strings: { loading: 'Working', failed: 'Failed', truncated: 'Truncated', none: 'Nobody', noneAnnounce: 'Nobody left', columns: ['Name', 'E-mail', 'Date', 'Status'] },
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

	it('heads the list of people with its columns (#2336)', async () => {
		installSendDom();
		request.mockResolvedValue({
			totals: { will_send: 1 },
			rows: [{ name: 'A***', email: 'a***@x.org', date: '2026-10-01', decision: 'will_send' }],
			pii: true,
		});
		await loadOnReady();

		window.$('.ffc-dm-preview-button').trigger('click');
		await flush();

		const heads = Array.from(document.querySelectorAll('.ffc-dm-preview-result thead th')).map((th) => th.textContent);
		expect(heads).toEqual(['Name', 'E-mail', 'Date', 'Status']);
		expect(document.querySelector('.ffc-dm-preview-result thead th').getAttribute('scope')).toBe('col');
	});

	it('says an announcement has nobody left to reach, not that nobody falls on the dates (#2336)', async () => {
		installSendDom();
		document.querySelector('.ffc-dm-rule option').setAttribute('data-ffc-dm-kind', 'announcement');
		request.mockResolvedValue({ totals: { will_send: 0 }, rows: [], pii: true });
		await loadOnReady();

		window.$('.ffc-dm-preview-button').trigger('click');
		await flush();

		const text = document.querySelector('.ffc-dm-preview-result').textContent;
		expect(text).toContain('Nobody left');
		expect(text).not.toContain('Nobody falls');
	});

	it('marks the button busy while it waits, and clears it after (#2336)', async () => {
		installSendDom();
		let resolve;
		request.mockReturnValue(new Promise((r) => { resolve = r; }));
		await loadOnReady();

		const $button = window.$('.ffc-dm-preview-button');
		$button.trigger('click');
		expect($button.hasClass('ffc-btn-loading')).toBe(true);
		expect($button.prop('disabled')).toBe(true);

		resolve({ totals: {}, rows: [], pii: false });
		await flush();
		await flush();
		expect($button.hasClass('ffc-btn-loading')).toBe(false);
		expect($button.prop('disabled')).toBe(false);
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
		expect(document.querySelector('.ffc-notice-success').textContent).toBe('Sent to you');

		request.mockRejectedValueOnce(new Error('No address'));
		window.$('.ffc-dm-test-button').trigger('click');
		await flush();
		expect(document.querySelector('.ffc-notice-danger').textContent).toBe('No address');
		expect(document.querySelector('.ffc-dm-test-button').disabled).toBe(false);
	});

	it('asks before submitting a form that carries a confirmation', async () => {
		document.body.innerHTML = '<form id="other" data-ffc-confirm="Delete?"></form>';
		await loadOnReady();
		const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);

		const event = window.$.Event('submit');
		window.$('#other').trigger(event);

		expect(confirm).toHaveBeenCalledWith('Delete?');
		expect(event.isDefaultPrevented()).toBe(true);
		confirm.mockRestore();
	});

	it('asks to send now with the rule, the dates and the count the preview gives (#2336)', async () => {
		installSendDom();
		window.ffcDateMessages.strings.confirmSend = 'Send "%1$s" for %2$s to %3$s people?';
		request.mockResolvedValue({ totals: { will_send: 12 } });
		await loadOnReady();
		const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
		const form = document.getElementById('ffc-dm-send-form');
		const submit = vi.spyOn(form, 'submit').mockImplementation(() => {});

		const event = window.$.Event('submit');
		window.$(form).trigger(event);
		await flush();

		expect(event.isDefaultPrevented()).toBe(true);
		expect(request.mock.calls[0][0]).toBe('ffc_date_messages_preview');
		expect(request.mock.calls[0][1]).toEqual({ rule_id: '7', from: '2026-10-01', to: '2026-10-02' });
		expect(confirm).toHaveBeenCalledWith('Send "R" for 2026-10-01 – 2026-10-02 to 12 people?');
		expect(submit).not.toHaveBeenCalled();

		confirm.mockReturnValue(true);
		window.$(form).trigger(window.$.Event('submit'));
		await flush();
		expect(submit).toHaveBeenCalledTimes(1);
		confirm.mockRestore();
	});

	it('says a summary-only rule e-mails nobody', async () => {
		installSendDom();
		window.$('.ffc-dm-rule option').attr('data-ffc-dm-summary-only', '1');
		window.ffcDateMessages.strings.confirmSummary = 'Run "%1$s" for %2$s; summary of %3$s.';
		request.mockResolvedValue({ totals: { will_send: 4 } });
		await loadOnReady();
		const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);

		window.$('#ffc-dm-send-form').trigger(window.$.Event('submit'));
		await flush();

		expect(confirm).toHaveBeenCalledWith('Run "R" for 2026-10-01 – 2026-10-02; summary of 4.');
		confirm.mockRestore();
	});

	it('renders the message preview in a sandboxed frame and resizes it for a phone (#1660)', async () => {
		installEditorDom();
		window.$('.ffc-dm-preview').append(`
			<button type="button" class="ffc-dm-message-button">Message</button>
			<div class="ffc-dm-message ffc-hidden">
				<strong class="ffc-dm-message-subject"></strong>
				<button type="button" class="ffc-dm-message-size" data-width="640" aria-pressed="true">Computer</button>
				<button type="button" class="ffc-dm-message-size" data-width="360" aria-pressed="false">Phone</button>
				<iframe class="ffc-dm-message-frame" sandbox="" width="640"></iframe>
			</div>
		`);
		window.ffcDateMessages.messageAction = 'ffc_date_messages_message_preview';
		window.ffcDateMessages.messageNonce = 'mn';
		request.mockResolvedValue({ subject: '<b>Hi</b>', html: '<!DOCTYPE html><p>Body</p>' });
		await loadOnReady();

		window.$('.ffc-dm-message-button').trigger('click');
		await flush();

		expect(request.mock.calls[0][0]).toBe('ffc_date_messages_message_preview');
		expect(request.mock.calls[0][2].nonce).toBe('mn');
		expect(window.$('.ffc-dm-message').hasClass('ffc-hidden')).toBe(false);
		expect(window.$('.ffc-dm-message-subject').text()).toBe('<b>Hi</b>');
		expect(window.$('.ffc-dm-message-subject').children().length).toBe(0);
		expect(window.$('.ffc-dm-message-frame').attr('srcdoc')).toBe('<!DOCTYPE html><p>Body</p>');
		expect(window.$('.ffc-dm-message-frame').attr('sandbox')).toBe('');

		window.$('.ffc-dm-message-size[data-width="360"]').trigger('click');
		expect(window.$('.ffc-dm-message-frame').attr('width')).toBe('360');
		expect(window.$('.ffc-dm-message-size[data-width="360"]').attr('aria-pressed')).toBe('true');
		expect(window.$('.ffc-dm-message-size[data-width="640"]').attr('aria-pressed')).toBe('false');
	});

	it('requires a fallback colour once an image is set and shows the contrast against it (#1660)', async () => {
		document.body.innerHTML = `
			<div class="ffc-dm-appearance" data-model-bg="#ffffff" data-model-text="#333333">
				<input type="hidden" class="ffc-dm-image-id" value="37">
				<p class="ffc-dm-image-preview"><img class="ffc-dm-image-thumb" src="t.png"><span class="ffc-dm-image-meta">art.png</span></p>
				<button type="button" class="ffc-dm-image-remove">Remove</button>
				<input type="hidden" class="ffc-dm-image-url" value="a.png">
				<input type="text" class="ffc-dm-color ffc-dm-fallback" value="#000000">
				<input type="text" class="ffc-dm-color ffc-dm-text" value="">
				<p class="ffc-dm-contrast" data-ok="OK %s:1" data-low="LOW %s:1"></p>
			</div>
		`;
		window.ffcDateMessages.decimal = ',';
		await loadOnReady();

		expect(window.$('.ffc-dm-fallback').prop('required')).toBe(true);
		// #333333 on #000000.
		expect(window.$('.ffc-dm-contrast').text()).toBe('LOW 1,7:1');

		window.$('.ffc-dm-fallback').val('').trigger('input');
		// Empty fields follow the Email Model: #333333 on #ffffff.
		expect(window.$('.ffc-dm-contrast').text()).toBe('OK 12,6:1');

		window.$('.ffc-dm-image-remove').trigger('click');
		expect(window.$('.ffc-dm-image-id').val()).toBe('0');
		expect(window.$('.ffc-dm-image-url').val()).toBe('');
		expect(window.$('.ffc-dm-fallback').prop('required')).toBe(false);
		expect(window.$('.ffc-dm-image-preview').hasClass('ffc-hidden')).toBe(true);
		expect(window.$('.ffc-dm-image-remove').hasClass('ffc-hidden')).toBe(true);
	});

	it('takes the chosen image from the Media Library (#1660)', async () => {
		document.body.innerHTML = `
			<div class="ffc-dm-appearance" data-model-bg="#ffffff" data-model-text="#333333">
				<input type="hidden" class="ffc-dm-image-id" value="0">
				<p class="ffc-dm-image-preview ffc-hidden"><img class="ffc-dm-image-thumb" src=""><span class="ffc-dm-image-meta"></span></p>
				<button type="button" class="ffc-dm-image-choose" data-title="Pick" data-button="Use">Choose</button>
				<button type="button" class="ffc-dm-image-remove ffc-hidden">Remove</button>
				<input type="hidden" class="ffc-dm-image-url" value="">
				<input type="text" class="ffc-dm-color ffc-dm-fallback" value="">
				<input type="text" class="ffc-dm-color ffc-dm-text" value="">
				<p class="ffc-dm-contrast" data-ok="OK %s:1" data-low="LOW %s:1"></p>
			</div>
		`;
		let onSelect;
		const frame = {
			on: (event, cb) => { onSelect = cb; },
			open: vi.fn(),
			state: () => ({ get: () => ({ first: () => ({ toJSON: () => ({ id: 37, url: 'full.png', filename: 'art.png', width: 600, height: 360, filesizeHumanReadable: '56 KB', sizes: { medium: { url: 'medium.png' } } }) }) }) }),
		};
		window.wp = { media: vi.fn(() => frame) };
		await loadOnReady();

		window.$('.ffc-dm-image-choose').trigger('click');
		expect(window.wp.media.mock.calls[0][0].library).toEqual({ type: 'image' });
		expect(frame.open).toHaveBeenCalled();
		onSelect();

		expect(window.$('.ffc-dm-image-id').val()).toBe('37');
		expect(window.$('.ffc-dm-image-thumb').attr('src')).toBe('medium.png');
		expect(window.$('.ffc-dm-image-meta').text()).toBe('art.png · 600 × 360 · 56 KB');
		expect(window.$('.ffc-dm-fallback').prop('required')).toBe(true);
		expect(window.$('.ffc-dm-image-preview').hasClass('ffc-hidden')).toBe(false);
		delete window.wp;
	});

	it('swaps to the announcement default when the kind changes and the subject still holds the dated one', async () => {
		installEditorDom();
		document.body.insertAdjacentHTML(
			'beforeend',
			'<select id="ffc-dm-kind"><option value="dated" selected>D</option><option value="announcement">A</option></select>' +
				'<input id="ffc-dm-subject" value="Happy birthday!">' +
				'<textarea id="ffc_dm_body">dated body</textarea>' +
				'<button type="button" class="ffc-email-restore-default" data-editor="ffc_dm_body" data-default-key="date_message_body">R</button>'
		);
		window.ffcDateMessageDefaults = {
			dated: { subject: 'Happy birthday!', body: 'dated body' },
			announcement: { subject: 'Site: announcement', body: 'announcement body' },
		};
		await loadOnReady();

		window.$('#ffc-dm-kind').val('announcement').trigger('change');
		expect(window.$('#ffc-dm-subject').val()).toBe('Site: announcement');
		expect(window.$('#ffc_dm_body').val()).toBe('announcement body');
		expect(window.$('.ffc-email-restore-default').attr('data-default-key')).toBe('date_message_announcement_body');

		// An edited text is replaced only when the operator says so (#2336).
		const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
		window.$('#ffc-dm-subject').val('Mine');
		window.$('#ffc_dm_body').val('mine');
		window.$('#ffc-dm-kind').val('dated').trigger('change');
		expect(confirm).toHaveBeenCalledTimes(1);
		expect(window.$('#ffc-dm-subject').val()).toBe('Mine');
		expect(window.$('.ffc-email-restore-default').attr('data-default-key')).toBe('date_message_body');

		confirm.mockReturnValue(true);
		window.$('#ffc-dm-kind').val('announcement').trigger('change');
		expect(window.$('#ffc-dm-subject').val()).toBe('Site: announcement');
		expect(window.$('#ffc_dm_body').val()).toBe('announcement body');
		confirm.mockRestore();
		delete window.ffcDateMessageDefaults;
	});

	it('shows the people who opted out and receive it anyway, apart from the total', async () => {
		installSendDom();
		window.ffcDateMessages.strings.forced = 'Forced past the opt-out';
		request.mockResolvedValue({ totals: { will_send: 3, opted_out: 1, forced: 2 }, rows: [], pii: false });
		await loadOnReady();

		window.$('.ffc-dm-preview-button').trigger('click');
		await flush();
		expect(window.$('.ffc-dm-preview-result').text()).toContain('Forced past the opt-out: 2');
	});

	it('shows the rows of the chosen kind and hides the others', async () => {
		installEditorDom();
		document.body.insertAdjacentHTML(
			'beforeend',
			'<select id="ffc-dm-kind"><option value="dated" selected>D</option><option value="announcement">A</option></select>' +
				'<input id="ffc-dm-subject" value="x"><textarea id="ffc_dm_body">b</textarea>' +
				'<table><tr data-ffc-dm-kind-only="dated"><td>offset</td></tr><tr data-ffc-dm-kind-only="announcement" hidden><td>send at</td></tr></table>'
		);
		window.ffcDateMessageDefaults = { dated: { subject: 'd', body: 'd' }, announcement: { subject: 'a', body: 'a' } };
		await loadOnReady();

		window.$('#ffc-dm-kind').val('announcement').trigger('change');
		expect(window.$('[data-ffc-dm-kind-only="dated"]').prop('hidden')).toBe(true);
		expect(window.$('[data-ffc-dm-kind-only="announcement"]').prop('hidden')).toBe(false);
		delete window.ffcDateMessageDefaults;
	});

	it('asks for dates only when the rule chosen on the send tab is dated', async () => {
		document.body.innerHTML = '';
		document.body.insertAdjacentHTML(
			'beforeend',
			'<select class="ffc-dm-rule"><option value="1" data-ffc-dm-kind="announcement" selected>A</option><option value="2" data-ffc-dm-kind="dated">D</option></select>' +
				'<table><tr data-ffc-dm-dated-only><td>dates</td></tr></table>'
		);
		await loadOnReady();

		expect(window.$('[data-ffc-dm-dated-only]').prop('hidden')).toBe(true);
		window.$('.ffc-dm-rule').val('2').trigger('change');
		expect(window.$('[data-ffc-dm-dated-only]').prop('hidden')).toBe(false);
		window.$('.ffc-dm-rule').val('1').trigger('change');
		expect(window.$('[data-ffc-dm-dated-only]').prop('hidden')).toBe(true);
	});

	it('keeps the "This rule" card in step with the form before it is saved (#2498)', async () => {
		document.body.innerHTML = `
			<form id="ffc-dm-rule-form">
				<select id="ffc-dm-kind"><option value="dated" selected>By date</option><option value="announcement">Announcement</option></select>
				<select id="ffc-dm-source"><option value="birthday" selected>Birthday</option></select>
				<input id="ffc-dm-offset" value="0">
				<input id="ffc-dm-send-at" value="">
				<div class="ffc-transfer-list"></div>
				<input type="checkbox" id="ffc-dm-digest-enabled">
				<select id="ffc-dm-digest-mode"><option value="summary" selected>Counts only</option></select>
				<div class="card ffc-dm-facts">
					<dd data-ffc-dm-fact="kind"></dd><dd data-ffc-dm-fact="source"></dd>
					<dd data-ffc-dm-fact="offset"></dd><dd data-ffc-dm-fact="send-at"></dd>
					<dd data-ffc-dm-fact="audiences"></dd><dd data-ffc-dm-fact="digest"></dd>
				</div>
			</form>
		`;
		window.ffcDateMessages.strings.facts = {
			onDate: 'On the date', dayBefore: '%d day before', daysBefore: '%d days before', dayAfter: '%d day after', daysAfter: '%d days after',
			atTime: 'At the time', byHand: 'By hand', everyone: 'Everyone', audience: '%d audience', audiences: '%d audiences', off: 'Off',
		};
		await loadOnReady();
		const fact = (k) => window.$(`[data-ffc-dm-fact="${k}"]`).text();

		expect([fact('kind'), fact('source'), fact('offset'), fact('send-at'), fact('audiences'), fact('digest')])
			.toEqual(['By date', 'Birthday', 'On the date', 'By hand', 'Everyone', 'Off']);

		window.$('#ffc-dm-offset').val('-1').trigger('input');
		expect(fact('offset')).toBe('1 day before');
		window.$('#ffc-dm-offset').val('7').trigger('input');
		expect(fact('offset')).toBe('7 days after');

		window.$('#ffc-dm-send-at').val('2026-12-01T09:00').trigger('change');
		expect(fact('send-at')).toBe('At the time');

		const $list = window.$('.ffc-transfer-list');
		$list.append('<input type="hidden" name="rule[audience_ids][]" value="3"><input type="hidden" name="rule[audience_ids][]" value="4">');
		$list.trigger('ffc:transfer-list-change', [[3, 4]]);
		expect(fact('audiences')).toBe('2 audiences');

		window.$('#ffc-dm-digest-enabled').prop('checked', true).trigger('change');
		expect(fact('digest')).toBe('Counts only');
	});
});
