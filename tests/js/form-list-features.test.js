// Tests for assets/js/ffc-form-list-features.js — the per-row
// inline-toggle handler on the Forms list table.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'admin-nonce',
		strings: {},
	};
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-admin.js');
});

beforeEach(() => {
	window.ffcFormListFeatures = {
		nonce: 'form-features-nonce',
		strings: {
			saving: 'Saving…',
			saved:  'Saved',
			error:  'Save failed',
		},
	};
	document.body.innerHTML = `
		<table>
			<tr id="row-42">
				<td>
					<div class="ffc-features-cell">
						<label class="ffc-toggle ffc-features-toggle">
							<input type="checkbox" data-ffc-form-id="42" data-ffc-feature="csv_public_enabled">
						</label>
						<label class="ffc-toggle ffc-features-toggle">
							<input type="checkbox" data-ffc-form-id="42" data-ffc-feature="quiz_enabled" checked>
						</label>
						<span class="ffc-features-badge" hidden></span>
					</div>
				</td>
			</tr>
		</table>
	`;
	loadScript('assets/js/ffc-form-list-features.js');
});

afterEach(() => {
	vi.restoreAllMocks();
});

describe('Forms-list features toggle', () => {
	it('posts ffc_update_form_feature with the form id, feature, value, and nonce', async () => {
		const requestSpy = vi.spyOn(window.FFC, 'request').mockResolvedValue({});

		const $csv = window.$('input[data-ffc-feature="csv_public_enabled"]');
		$csv.prop('checked', true).trigger('change');
		await Promise.resolve(); await Promise.resolve();

		expect(requestSpy).toHaveBeenCalledWith('ffc_update_form_feature', {
			form_id: 42,
			feature: 'csv_public_enabled',
			value:   '1',
			nonce:   'form-features-nonce',
		});
	});

	it('shows the Saving badge while in-flight, then Saved', async () => {
		let resolveFn;
		vi.spyOn(window.FFC, 'request').mockReturnValue(new Promise((r) => { resolveFn = r; }));

		const $row = window.$('#row-42');
		const $badge = $row.find('.ffc-features-badge');
		const $csv = $row.find('input[data-ffc-feature="csv_public_enabled"]');
		$csv.prop('checked', true).trigger('change');

		expect($badge.text()).toBe('Saving…');
		expect($badge.hasClass('ffc-features-badge--saving')).toBe(true);
		expect($badge.attr('hidden')).toBeUndefined();
		expect($csv.prop('disabled')).toBe(true);

		resolveFn({ feature: 'csv_public_enabled' });
		await Promise.resolve(); await Promise.resolve();

		expect($badge.text()).toBe('Saved');
		expect($badge.hasClass('ffc-features-badge--saved')).toBe(true);
		expect($csv.prop('disabled')).toBe(false);
	});

	it('on error: rolls the toggle back and shows the server message', async () => {
		vi.spyOn(window.FFC, 'request').mockRejectedValue(new Error('You do not have permission'));

		const $row = window.$('#row-42');
		const $quiz = $row.find('input[data-ffc-feature="quiz_enabled"]');
		expect($quiz.is(':checked')).toBe(true); // starts on
		$quiz.prop('checked', false).trigger('change');

		await Promise.resolve(); await Promise.resolve();

		// Rolled back to ON.
		expect($quiz.is(':checked')).toBe(true);
		expect($quiz.prop('disabled')).toBe(false);
		const $badge = $row.find('.ffc-features-badge');
		expect($badge.text()).toBe('You do not have permission');
		expect($badge.hasClass('ffc-features-badge--error')).toBe(true);
	});

	it('falls back to the localised error string when the response has no message', async () => {
		vi.spyOn(window.FFC, 'request').mockRejectedValue({});

		const $row = window.$('#row-42');
		const $csv = $row.find('input[data-ffc-feature="csv_public_enabled"]');
		$csv.prop('checked', true).trigger('change');
		await Promise.resolve(); await Promise.resolve();

		expect($row.find('.ffc-features-badge').text()).toBe('Save failed');
	});
});
