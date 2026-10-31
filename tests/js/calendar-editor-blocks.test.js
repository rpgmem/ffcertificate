// Tests for the Blocked dates tab of `assets/js/ffc-calendar-editor.js`
// (#2244): the type control, the add request with its count confirmation,
// and the remove button.
import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const flush = () => new Promise((r) => setTimeout(r, 0));

beforeAll(async () => {
	window.ffcSelfSchedulingEditor = {
		strings: {
			blocksNonce: 'blocks-nonce',
			blockRemove: 'Remove this block?',
			errorServer: 'Error communicating with server',
			cleanup: {
				cancel: 'Cancel',
				goBack: 'Go back',
				block: {
					title: 'Block and cancel appointments',
					body: '%1$d open appointment(s) of the calendar "%2$s" fall on what you are blocking.',
					bodySilent: '%1$d open appointment(s) of "%2$s"; nobody notified.',
					confirm: 'Block and cancel %d',
					confirmSilent: 'Block and cancel %d',
				},
			},
		},
	};
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'n', strings: {} };
	loadScript('assets/js/ffc-core.js');
	document.body.innerHTML = `
		<div class="ffc-blocked-dates" data-post-id="20">
			<button type="button" class="ffc-block-delete" data-block-id="9">x</button>
			<fieldset>
				<input type="radio" name="ffc_block_type" value="full_day" checked>
				<input type="radio" name="ffc_block_type" value="time_range">
				<input type="date" id="ffc-block-start-date" value="2032-05-20">
				<label class="ffc-block-days"><input type="date" id="ffc-block-end-date" value=""></label>
				<label class="ffc-block-times" hidden><input type="time" id="ffc-block-start-time" value="10:00"></label>
				<label class="ffc-block-times" hidden><input type="time" id="ffc-block-end-time" value="12:00"></label>
				<input type="text" id="ffc-block-reason" value="Flooding">
				<button type="button" id="ffc-block-add">Add block</button>
			</fieldset>
		</div>
	`;
	loadScript('assets/js/ffc-calendar-editor.js');
	await flush();
});

beforeEach(() => {
	vi.restoreAllMocks();
	document.querySelectorAll('.ffc-cleanup-dialog').forEach((d) => d.remove());
	window.$('input[name="ffc_block_type"][value="full_day"]').prop('checked', true).trigger('change');
	window.$('#ffc-block-add').prop('disabled', false);
});

describe('blocked dates tab', () => {
	it('shows the day range or the time fields by type', () => {
		const times = () => window.$('.ffc-block-times').first().prop('hidden');
		expect(times()).toBe(true);
		window.$('input[name="ffc_block_type"][value="time_range"]').prop('checked', true).trigger('change');
		expect(times()).toBe(false);
		expect(window.$('.ffc-block-days').prop('hidden')).toBe(true);
	});

	it('posts the block and reloads when nothing needed confirming', async () => {
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		const reqSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({ message: 'Blocked.' });

		window.$('#ffc-block-add').trigger('click');
		await flush();

		expect(reqSpy).toHaveBeenCalledTimes(1);
		expect(reqSpy.mock.calls[0][0]).toBe('ffc_calendar_block_add');
		expect(reqSpy.mock.calls[0][1]).toMatchObject({ post_id: 20, block_type: 'full_day', start_date: '2032-05-20', reason: 'Flooding' });
		expect(reqSpy.mock.calls[0][2]).toEqual({ nonce: 'blocks-nonce' });
		expect(alertSpy).toHaveBeenCalledWith('Blocked.');
	});

	it('asks with the count in the cleanup dialog and resends with it on confirm', async () => {
		vi.spyOn(window, 'alert').mockImplementation(() => {});
		const reqSpy = vi.spyOn(window.FFC, 'request')
			.mockResolvedValueOnce({ needs_confirm: true, count: 3, notify: true, title: 'Clinic' })
			.mockResolvedValueOnce({ message: 'Blocked. 3 appointments were cancelled.' });

		window.$('#ffc-block-add').trigger('click');
		await flush();

		const dialog = document.querySelector('.ffc-cleanup-dialog');
		expect(dialog).not.toBeNull();
		expect(dialog.classList.contains('ffc-cleanup-dialog-caution')).toBe(true);
		expect(dialog.textContent).toContain('3 open appointment(s) of the calendar "Clinic"');
		dialog.querySelector('[data-ffc-cleanup="confirm"]').click();
		await flush();

		expect(reqSpy).toHaveBeenCalledTimes(2);
		expect(reqSpy.mock.calls[1][1]).toMatchObject({ confirm: '1', expected: 3 });
	});

	it('sends nothing more when the dialog is dismissed', async () => {
		const reqSpy = vi.spyOn(window.FFC, 'request').mockResolvedValueOnce({ needs_confirm: true, count: 2, notify: false, title: 'Clinic' });

		window.$('#ffc-block-add').trigger('click');
		await flush();
		const dialog = document.querySelector('.ffc-cleanup-dialog');
		expect(dialog.textContent).toContain('nobody notified');
		dialog.querySelector('[data-ffc-cleanup="cancel"]').click();
		await flush();

		expect(reqSpy).toHaveBeenCalledTimes(1);
		expect(window.$('#ffc-block-add').prop('disabled')).toBe(false);
	});

	it('shows the refusal the server put in the response body', async () => {
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
		const err = new Error('Connection error');
		err.fromServer = false;
		err.data = { message: 'The calendar already blocks this date or time.' };
		vi.spyOn(window.FFC, 'request').mockRejectedValue(err);

		window.$('#ffc-block-add').trigger('click');
		await flush();

		expect(alertSpy).toHaveBeenCalledWith('The calendar already blocks this date or time.');
	});

	it('removes a block only after the confirmation', async () => {
		const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
		const reqSpy = vi.spyOn(window.FFC, 'request').mockReturnValue(new Promise(() => {}));

		window.$('.ffc-block-delete').trigger('click');
		expect(reqSpy).not.toHaveBeenCalled();
		window.$('.ffc-block-delete').trigger('click');

		expect(confirmSpy).toHaveBeenCalledWith('Remove this block?');
		expect(reqSpy).toHaveBeenCalledWith('ffc_calendar_block_delete', { post_id: 20, block_id: 9 }, { nonce: 'blocks-nonce' });
	});
});
