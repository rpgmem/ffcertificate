// Tests for `assets/js/ffc-certificate-settings.js` — the required-field
// table on Settings → Certificates (#1741).
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

function row(index, name, locked) {
	return `<tr class="ffc-required-field-row">
		<td><input type="text" name="ffc_settings[required_form_fields][${index}][name]" value="${name}"></td>
		<td><button type="button" class="button ffc-required-field-remove"${locked ? ' disabled' : ''}>Remove</button></td>
	</tr>`;
}

beforeAll(() => {
	loadScript('assets/js/ffc-certificate-settings.js');
});

beforeEach(() => {
	document.body.innerHTML = `
		<table><tbody id="ffc-required-fields-rows" data-ffc-next-index="2">${row(0, 'name', false)}${row(1, 'email', true)}</tbody></table>
		<template id="ffc-required-field-template">${row('__index__', '', false)}</template>
		<button type="button" id="ffc-required-field-add">Add</button>
	`;
});

const rows = () => Array.from(document.querySelectorAll('#ffc-required-fields-rows .ffc-required-field-row'));

describe('required-field table', () => {
	it('adds a row under the next free index', () => {
		window.$('#ffc-required-field-add').trigger('click');
		window.$('#ffc-required-field-add').trigger('click');

		expect(rows()).toHaveLength(4);
		expect(document.querySelector('[name="ffc_settings[required_form_fields][2][name]"]')).not.toBeNull();
		expect(document.querySelector('[name="ffc_settings[required_form_fields][3][name]"]')).not.toBeNull();
		expect(document.getElementById('ffc-required-fields-rows').getAttribute('data-ffc-next-index')).toBe('4');
	});

	it('removes a row, but never the locked Email row', () => {
		window.$('.ffc-required-field-remove').eq(0).trigger('click');
		expect(rows()).toHaveLength(1);

		window.$('.ffc-required-field-remove').eq(0).trigger('click');
		expect(rows()).toHaveLength(1);
		expect(rows()[0].querySelector('input').value).toBe('email');
	});

	it('does nothing without the table', () => {
		document.body.innerHTML = '<button type="button" id="ffc-required-field-add">Add</button>';
		window.$('#ffc-required-field-add').trigger('click');
		expect(document.querySelectorAll('.ffc-required-field-row')).toHaveLength(0);
	});
});
