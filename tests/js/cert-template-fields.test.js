// Tests for `assets/js/ffc-cert-template-fields.js` (#2581).
import { describe, it, expect, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

describe('ffc-cert-template-fields.js', () => {
	beforeEach(() => {
		document.body.innerHTML = `
			<table><tbody class="ffc-template-fields-rows">
				<tr class="ffc-template-field-row"><td><input class="ffc-template-field-key" name="ffc_template_fields[0][key]" value="local"></td>
				<td><button class="ffc-template-field-remove">x</button></td></tr>
			</tbody></table>
			<template id="ffc-template-field-row"><tr class="ffc-template-field-row"><td><input class="ffc-template-field-key" name="ffc_template_fields[__i__][key]"></td>
			<td><button class="ffc-template-field-remove">x</button></td></tr></template>
			<button class="ffc-template-field-add">Add</button>
		`;
		window.$(document).off('click', '.ffc-template-field-add');
		window.$(document).off('click', '.ffc-template-field-remove');
		loadScript('assets/js/ffc-cert-template-fields.js');
	});

	it('adds a row with its own index and focuses its key', () => {
		window.$('.ffc-template-field-add').trigger('click');
		const rows = document.querySelectorAll('.ffc-template-fields-rows .ffc-template-field-row');
		expect(rows).toHaveLength(2);
		const name = rows[1].querySelector('input').name;
		expect(name).toMatch(/^ffc_template_fields\[\d+\]\[key\]$/);
		expect(name).not.toContain('__i__');
		expect(document.activeElement).toBe(rows[1].querySelector('input'));
	});

	it('removes a row and moves focus to the add button when it was the last', () => {
		window.$('.ffc-template-field-remove').first().trigger('click');
		expect(document.querySelectorAll('.ffc-template-fields-rows .ffc-template-field-row')).toHaveLength(0);
		expect(document.activeElement).toBe(document.querySelector('.ffc-template-field-add'));
	});
});
