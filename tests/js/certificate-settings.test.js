// Tests for `assets/js/ffc-certificate-settings.js` — the "Required data"
// table on Settings → Certificates (#1741, #1759).
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function row(index, name, opts) {
	const o = opts || {};
	const form = o.generated
		? '<span>—</span>'
		: `<label class="ffc-control-toggle"><input type="checkbox" class="ffc-required-data-form" name="ffc_settings[required_data][${index}][form]" value="1"${o.form ? ' checked' : ''}${o.locked ? ' disabled' : ''}></label>`;
	return `<tr class="ffc-required-data-row">
		<td><input type="text" class="ffc-required-data-name" name="ffc_settings[required_data][${index}][name]" value="${name}" pattern="[a-z0-9_]+"></td>
		<td><input type="text" class="ffc-required-data-label" name="ffc_settings[required_data][${index}][label]" value="${o.label || ''}"></td>
		<td><select class="ffc-required-data-type" name="ffc_settings[required_data][${index}][type]"><option value="text">Text</option><option value="date"${o.type === 'date' ? ' selected' : ''}>Date</option></select></td>
		<td>${form}</td>
		<td><label class="ffc-control-toggle"><input type="checkbox" class="ffc-required-data-html" name="ffc_settings[required_data][${index}][html]" value="1"${o.html ? ' checked' : ''}></label></td>
		<td><button type="button" class="button ffc-required-data-remove"${o.locked ? ' disabled' : ''}>Remove</button></td>
	</tr>`;
}

function makeChain(impl) {
	const chain = {};
	chain.done = function (cb) { cb(impl()); return chain; };
	chain.fail = function () { return chain; };
	return chain;
}

beforeAll(() => {
	window.ffc_ajax = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'admin-nonce', strings: {} };
	window.ffcAdminAutosave = { nonce: 'autosave-nonce', strings: {} };
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-admin.js');
	loadScript('assets/js/ffc-admin-autosave.js');
	loadScript('assets/js/ffc-certificate-settings.js');
});

beforeEach(() => {
	document.body.innerHTML = `
		<form><table class="ffc-required-data-table"><tbody id="ffc-required-data-rows" data-ffc-next-index="3" data-ffc-autosave-key="required_data" data-ffc-autosave-debounce="400">${row(0, 'name', { label: 'Full Name', form: true, html: true })}${row(1, 'email', { label: 'Email', form: true, locked: true })}${row(2, 'schedule', { generated: true, html: true })}</tbody></table></form>
		<template id="ffc-required-data-template">${row('__index__', '', { form: true })}</template>
		<button type="button" id="ffc-required-data-add">Add</button>
	`;
	vi.useFakeTimers();
});

afterEach(() => {
	vi.restoreAllMocks();
	vi.useRealTimers();
});

const rows = () => Array.from(document.querySelectorAll('#ffc-required-data-rows .ffc-required-data-row'));
const serialize = () => JSON.parse(window.FFC.Admin.autoSaveTransforms.required_data(window.$('#ffc-required-data-rows')));

describe('required-data table', () => {
	it('adds a row under the next free index', () => {
		window.$('#ffc-required-data-add').trigger('click');
		window.$('#ffc-required-data-add').trigger('click');

		expect(rows()).toHaveLength(5);
		expect(document.querySelector('[name="ffc_settings[required_data][3][name]"]')).not.toBeNull();
		expect(document.querySelector('[name="ffc_settings[required_data][4][html]"]')).not.toBeNull();
		expect(document.getElementById('ffc-required-data-rows').getAttribute('data-ffc-next-index')).toBe('5');
	});

	it('removes a row, but never a locked one', () => {
		window.$('.ffc-required-data-remove').eq(0).trigger('click');
		expect(rows()).toHaveLength(2);

		window.$('.ffc-required-data-remove').eq(0).trigger('click');
		expect(rows()).toHaveLength(2);
		expect(rows()[0].querySelector('input').value).toBe('email');
	});

	it('does nothing without the table', () => {
		document.body.innerHTML = '<button type="button" id="ffc-required-data-add">Add</button>';
		window.$('#ffc-required-data-add').trigger('click');
		expect(document.querySelectorAll('.ffc-required-data-row')).toHaveLength(0);
	});

	it('reads the whole table as the rows the server stores', () => {
		window.$('[name="ffc_settings[required_data][0][type]"]').val('date');

		expect(serialize()).toEqual([
			{ name: 'name', label: 'Full Name', type: 'date', form: true, html: true },
			{ name: 'email', label: 'Email', type: 'text', form: true, html: false },
			// A generated tag has no form switch, so it reads as off.
			{ name: 'schedule', label: '', type: 'text', form: false, html: true },
		]);
	});
});

describe('required-data autosave', () => {
	function boot() {
		const post = vi.spyOn(window.$, 'post').mockImplementation(() => makeChain(() => ({ success: true, data: {} })));
		window.FFC.Admin.bootAutoSaveFields();
		return post;
	}

	it('saves the whole table as one JSON field when a switch flips', () => {
		const post = boot();

		window.$('[name="ffc_settings[required_data][0][html]"]').prop('checked', false).trigger('change');
		vi.advanceTimersByTime(400);

		expect(post).toHaveBeenCalledTimes(1);
		const payload = post.mock.calls[0][1];
		expect(payload).toMatchObject({ action: 'ffc_update_setting', key: 'required_data', nonce: 'autosave-nonce' });
		expect(JSON.parse(payload.value)[0]).toEqual({ name: 'name', label: 'Full Name', type: 'text', form: true, html: false });
	});

	it('saves when a label is typed and when a row is removed', () => {
		const post = boot();

		window.$('[name="ffc_settings[required_data][0][label]"]').val('Name').trigger('input');
		vi.advanceTimersByTime(400);
		expect(JSON.parse(post.mock.calls[0][1].value)[0].label).toBe('Name');

		window.$('.ffc-required-data-remove').eq(0).trigger('click');
		vi.advanceTimersByTime(400);
		expect(post).toHaveBeenCalledTimes(2);
		expect(JSON.parse(post.mock.calls[1][1].value).map((r) => r.name)).toEqual(['email', 'schedule']);
	});

	it('puts the badge after the table, not inside it', () => {
		boot();
		const table = document.querySelector('.ffc-required-data-table');
		expect(table.nextElementSibling.classList.contains('ffc-autosave-badge')).toBe(true);
		expect(table.querySelector('.ffc-autosave-badge')).toBeNull();
	});

	it('refuses to save while a name is not a field key', () => {
		const post = boot();

		window.$('[name="ffc_settings[required_data][0][name]"]').val('Not a key').trigger('input');
		vi.advanceTimersByTime(400);

		expect(post).not.toHaveBeenCalled();
		expect(window.$('.ffc-autosave-badge').hasClass('ffc-autosave-badge--error')).toBe(true);
	});
});
