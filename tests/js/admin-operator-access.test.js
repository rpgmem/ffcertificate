// Public Operator Access on the form editor (#1755): the Regenerate hash and
// Reset counter buttons act at once through `ffc_csv_public_action`, and the
// first enable of the master toggle -- an autosave -- writes the new link and
// CPF mode the server answered with into the page.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function flush() {
	return new Promise((r) => setTimeout(r, 0));
}

function fixture() {
	document.body.innerHTML = `
		<p class="description ffc-csv-public-nohash">No hash yet.</p>
		<div class="ffc-csv-public-share ffc-hidden"><input id="ffc_csv_public_link" value=""></div>
		<p class="ffc-csv-public-actions">
			<button type="button" class="ffc-csv-public-action" data-op="regenerate" data-post-id="12" data-nonce="n1" data-confirm="Sure?" data-done="New hash generated.">Regenerate</button>
			<button type="button" class="ffc-csv-public-action" data-op="reset" data-post-id="12" data-nonce="n1" data-confirm="Sure?" data-done="Counter reset.">Reset</button>
			<span class="ffc-csv-public-action-status"></span>
		</p>
		<span class="ffc-csv-public-count">9</span>
		<fieldset id="ffc_csv_public_cpf_mode">
			<input type="radio" name="m" value="none" checked>
			<input type="radio" name="m" value="audit">
		</fieldset>
	`;
}

describe('Public Operator Access buttons', () => {
	beforeAll(() => {
		window.ffc_ajax = { nonce: 'x', strings: {} };
		window.ajaxurl = '/wp-admin/admin-ajax.php';
		document.body.innerHTML = '';
		if (!window.FFC) {
			loadScript('assets/js/ffc-core.js');
		}
		loadScript('assets/js/ffc-admin.js');
	});

	beforeEach(() => {
		fixture();
	});

	afterEach(() => {
		vi.restoreAllMocks();
	});

	it('regenerates on confirm and shows the new link', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		const request = vi.spyOn(window.FFC, 'request').mockResolvedValue({ share_link: '?form_id=12&hash=new' });

		window.$('[data-op="regenerate"]').trigger('click');
		await flush();

		expect(request).toHaveBeenCalledWith('ffc_csv_public_action', { post_id: 12, op: 'regenerate' }, { nonce: 'n1' });
		expect(window.$('#ffc_csv_public_link').val()).toBe('?form_id=12&hash=new');
		expect(window.$('.ffc-csv-public-share').hasClass('ffc-hidden')).toBe(false);
		expect(window.$('.ffc-csv-public-nohash').hasClass('ffc-hidden')).toBe(true);
		expect(window.$('.ffc-csv-public-action-status').text()).toBe('New hash generated.');
		expect(window.$('[data-op="regenerate"]').prop('disabled')).toBe(false);
	});

	it('does nothing when the confirmation is declined', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(false);
		const request = vi.spyOn(window.FFC, 'request');

		window.$('[data-op="reset"]').trigger('click');
		await flush();

		expect(request).not.toHaveBeenCalled();
		expect(window.$('.ffc-csv-public-count').text()).toBe('9');
	});

	it('resets the counter shown on the page', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window.FFC, 'request').mockResolvedValue({ count: 0 });

		window.$('[data-op="reset"]').trigger('click');
		await flush();

		expect(window.$('.ffc-csv-public-count').text()).toBe('0');
		expect(window.$('.ffc-csv-public-action-status').text()).toBe('Counter reset.');
	});

	it('shows the server error and re-enables the button', async () => {
		vi.spyOn(window, 'confirm').mockReturnValue(true);
		vi.spyOn(window.FFC, 'request').mockRejectedValue(new Error('You do not have permission to edit this form.'));

		window.$('[data-op="reset"]').trigger('click');
		await flush();

		expect(window.$('.ffc-csv-public-action-status').text()).toBe('You do not have permission to edit this form.');
		expect(window.$('[data-op="reset"]').prop('disabled')).toBe(false);
		expect(window.$('.ffc-csv-public-count').text()).toBe('9');
	});

	it('writes the link and CPF mode a first enable answered with', () => {
		document.dispatchEvent(new window.CustomEvent('ffc:setting-saved', {
			detail: { key: 'csv_public_enabled', value: '1', response: { share_link: '?form_id=12&hash=first', cpf_mode: 'audit' } },
		}));

		expect(window.$('#ffc_csv_public_link').val()).toBe('?form_id=12&hash=first');
		expect(window.$('.ffc-csv-public-share').hasClass('ffc-hidden')).toBe(false);
		expect(window.$('#ffc_csv_public_cpf_mode input:checked').val()).toBe('audit');
	});

	it('ignores a saved event for any other key', () => {
		document.dispatchEvent(new window.CustomEvent('ffc:setting-saved', {
			detail: { key: 'quiz_enabled', value: '1', response: { share_link: '?x' } },
		}));

		expect(window.$('#ffc_csv_public_link').val()).toBe('');
	});
});
