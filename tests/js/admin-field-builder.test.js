// Tests for `assets/js/ffc-admin-field-builder.js`.
//
// Public surface: window.FFC.Admin.FieldBuilder.{init, addField, applyType}.
// A new row is a copy of the server-rendered template row (#1729), so the
// fixture below mirrors the classes and `ffc_fields[TEMPLATE]` names
// `FormEditorBuilderMetabox::render_field_row()` prints.
import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function row(index) {
	return `
	<div class="ffc-field-row" data-index="${index}">
		<div class="ffc-field-row-header">
			<span class="ffc-sort-handle"><span class="ffc-field-title"><strong>Field</strong></span></span>
			<button type="button" class="ffc-remove-field">Remove</button>
		</div>
		<div class="ffc-field-row-grid">
			<input type="text" name="ffc_fields[${index}][label]" class="ffc-label-input">
			<div class="ffc-standard-row"><input type="text" name="ffc_fields[${index}][name]"></div>
			<select name="ffc_fields[${index}][type]" class="ffc-field-type-selector">
				<option value="text">Text</option><option value="textarea">Textarea</option>
				<option value="radio">Radio</option><option value="checkbox">Checkbox</option>
				<option value="number">Number</option><option value="date">Date</option>
				<option value="info">Info</option><option value="embed">Embed</option>
				<option value="hidden">Hidden</option>
			</select>
			<label for="ffc_field_required_${index}"><input type="checkbox" id="ffc_field_required_${index}" name="ffc_fields[${index}][required]"></label>
		</div>
		<p class="ffc-type-desc"></p>
		<div class="ffc-content-field ffc-hidden"></div>
		<div class="ffc-embed-field ffc-hidden"></div>
		<div class="ffc-hidden-value-field ffc-hidden"></div>
		<div class="ffc-options-field ffc-hidden">
			<ol class="ffc-choice-list">
				<li class="ffc-choice-row"><span class="ffc-choice-handle"></span><input type="text" name="ffc_fields[${index}][choices][]" class="ffc-choice-input" value=""><button type="button" class="ffc-choice-remove">x</button></li>
			</ol>
			<button type="button" class="ffc-choice-add">Add option</button>
			<label class="ffc-hidden" data-ffc-types="checkbox"><input type="number" name="ffc_fields[${index}][max_select]"></label>
			<div class="ffc-quiz-points ffc-hidden"></div>
		</div>
		<div class="ffc-field-settings" data-ffc-types="text textarea"></div>
		<div class="ffc-field-settings ffc-hidden" data-ffc-types="number"></div>
		<div class="ffc-field-settings ffc-hidden" data-ffc-types="date"></div>
	</div>`;
}

function page(savedRows) {
	document.body.innerHTML = `
		<input type="checkbox" id="ffc_quiz_enabled">
		<div id="ffc-fields-container">${(savedRows || []).map(row).join('')}</div>
		<button type="button" class="ffc-add-field">Add New Field</button>
		<div class="ffc-field-template ffc-hidden">${row('TEMPLATE')}</div>
	`;
}

beforeAll(() => {
	window.ffc_ajax = {
		strings: { chooseFieldType: 'Choose Field Type:', confirmDeleteField: 'Remove this field?' },
		fieldTypes: [
			{ value: 'checkbox', label: 'Checkbox' },
			{ value: 'text', label: 'Text' },
			{ value: 'textarea', label: 'Textarea' },
		],
		fieldTypeDescriptions: {
			text: 'One line.',
			checkbox: 'Tick one or more.',
			info: 'Collects nothing.',
		},
	};
	window.$.fx.off = true;
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-admin-field-builder.js');
});

const FB = () => window.FFC.Admin.FieldBuilder;
const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => Array.from(document.querySelectorAll(sel));

function initOn(savedRows) {
	page(savedRows);
	FB().init();
}

describe('FFC.Admin.FieldBuilder public API', () => {
	it('exposes init / addField / applyType', () => {
		expect(typeof FB().init).toBe('function');
		expect(typeof FB().addField).toBe('function');
		expect(typeof FB().applyType).toBe('function');
	});
});

describe('FieldBuilder.addField — copies the server template', () => {
	beforeEach(() => initOn([0, 1]));

	it('appends a row whose names, ids and labels carry a fresh index', () => {
		const $row = FB().addField('text');
		expect($row).not.toBeNull();
		const el = $row[0];
		expect(el.getAttribute('data-index')).toBe('2');
		expect(el.querySelector('[name="ffc_fields[2][label]"]')).not.toBeNull();
		expect(el.querySelector('[name="ffc_fields[2][choices][]"]')).not.toBeNull();
		expect(el.querySelector('#ffc_field_required_2')).not.toBeNull();
		expect(el.querySelector('label[for="ffc_field_required_2"]')).not.toBeNull();
		expect(el.outerHTML).not.toContain('TEMPLATE');
	});

	it('never reuses an index, even after the highest row is removed', () => {
		FB().addField('text');
		$$('#ffc-fields-container .ffc-field-row').pop().remove();
		const $row = FB().addField('text');
		expect($row[0].getAttribute('data-index')).toBe('3');
	});

	it('selects the chosen type and shows only what belongs to it', () => {
		const el = FB().addField('checkbox')[0];
		expect(el.querySelector('.ffc-field-type-selector').value).toBe('checkbox');
		expect(el.querySelector('.ffc-options-field').classList.contains('ffc-hidden')).toBe(false);
		expect(el.querySelector('[data-ffc-types="checkbox"]').classList.contains('ffc-hidden')).toBe(false);
		expect(el.querySelector('[data-ffc-types="text textarea"]').classList.contains('ffc-hidden')).toBe(true);
		expect(el.querySelector('.ffc-type-desc').textContent).toBe('Tick one or more.');
	});

	it('leaves the template itself untouched', () => {
		FB().addField('radio');
		expect($('.ffc-field-template [name="ffc_fields[TEMPLATE][label]"]')).not.toBeNull();
	});

	it('returns null when the page carries no template', () => {
		document.querySelector('.ffc-field-template').remove();
		expect(FB().addField('text')).toBeNull();
	});
});

describe('FieldBuilder.applyType', () => {
	beforeEach(() => initOn([0]));

	it('shows the content block and hides the standard row for an info block', () => {
		const $row = window.$('#ffc-fields-container .ffc-field-row');
		FB().applyType($row, 'info');
		expect($row.find('.ffc-content-field').hasClass('ffc-hidden')).toBe(false);
		expect($row.find('.ffc-standard-row').hasClass('ffc-hidden')).toBe(true);
		expect($row.find('.ffc-type-desc').text()).toBe('Collects nothing.');
	});

	it('shows the quiz points of a choice field only while quiz mode is on', () => {
		const $row = window.$('#ffc-fields-container .ffc-field-row');
		FB().applyType($row, 'radio');
		expect($row.find('.ffc-quiz-points').hasClass('ffc-hidden')).toBe(true);
		$('#ffc_quiz_enabled').checked = true;
		FB().applyType($row, 'radio');
		expect($row.find('.ffc-quiz-points').hasClass('ffc-hidden')).toBe(false);
	});

	it('switches the range block between number and date', () => {
		const $row = window.$('#ffc-fields-container .ffc-field-row');
		FB().applyType($row, 'date');
		expect($row.find('[data-ffc-types="date"]').hasClass('ffc-hidden')).toBe(false);
		expect($row.find('[data-ffc-types="number"]').hasClass('ffc-hidden')).toBe(true);
	});

	it('reacts to the type select changing', () => {
		const select = $('#ffc-fields-container .ffc-field-type-selector');
		select.value = 'checkbox';
		window.$(select).trigger('change');
		expect($('#ffc-fields-container .ffc-options-field').classList.contains('ffc-hidden')).toBe(false);
	});
});

describe('FieldBuilder — option rows', () => {
	beforeEach(() => initOn([0]));

	it('adds an empty option row on "Add option"', () => {
		$('#ffc-fields-container .ffc-choice-input').value = 'A';
		$('#ffc-fields-container .ffc-choice-add').click();
		const inputs = $$('#ffc-fields-container .ffc-choice-input');
		expect(inputs.map((i) => i.value)).toEqual(['A', '']);
		expect(inputs[1].getAttribute('name')).toBe('ffc_fields[0][choices][]');
	});

	it('removes an option row, but only empties the last one', () => {
		$('#ffc-fields-container .ffc-choice-add').click();
		const inputs = $$('#ffc-fields-container .ffc-choice-input');
		inputs[0].value = 'A';
		inputs[1].value = 'B';
		$$('#ffc-fields-container .ffc-choice-remove')[0].click();
		expect($$('#ffc-fields-container .ffc-choice-input').map((i) => i.value)).toEqual(['B']);
		$('#ffc-fields-container .ffc-choice-remove').click();
		expect($$('#ffc-fields-container .ffc-choice-input').map((i) => i.value)).toEqual(['']);
	});

	it('Enter in an option adds the next row after it instead of submitting', () => {
		$('#ffc-fields-container .ffc-choice-add').click();
		const inputs = $$('#ffc-fields-container .ffc-choice-input');
		inputs[0].value = 'A';
		inputs[1].value = 'C';
		const event = window.$.Event('keydown', { key: 'Enter' });
		window.$(inputs[0]).trigger(event);
		expect(event.isDefaultPrevented()).toBe(true);
		expect($$('#ffc-fields-container .ffc-choice-input').map((i) => i.value)).toEqual(['A', '', 'C']);
	});
});

describe('FieldBuilder — removing a field', () => {
	beforeEach(() => initOn([0, 1]));

	it('removes the row once confirmed', () => {
		const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
		$('#ffc-fields-container .ffc-remove-field').click();
		expect($$('#ffc-fields-container .ffc-field-row').length).toBe(1);
		confirm.mockRestore();
	});

	it('keeps the row when the confirmation is declined', () => {
		const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
		$('#ffc-fields-container .ffc-remove-field').click();
		expect($$('#ffc-fields-container .ffc-field-row').length).toBe(2);
		confirm.mockRestore();
	});
});

describe('FieldBuilder — type menu (.ffc-add-field)', () => {
	beforeEach(() => initOn([]));

	it('lists every localized type as a button and adds the chosen one', () => {
		$('.ffc-add-field').click();
		const options = $$('.ffc-field-type-menu .ffc-field-type-option');
		expect(options.map((b) => b.getAttribute('data-type'))).toEqual(['checkbox', 'text', 'textarea']);
		expect(options.every((b) => b.tagName === 'BUTTON')).toBe(true);
		options[1].click();
		expect($$('#ffc-fields-container .ffc-field-row').length).toBe(1);
		expect($('#ffc-fields-container .ffc-field-type-selector').value).toBe('text');
		expect($('.ffc-field-type-menu')).toBeNull();
	});

	it('opens only one menu at a time', () => {
		$('.ffc-add-field').click();
		$('.ffc-add-field').click();
		expect($$('.ffc-field-type-menu').length).toBe(1);
	});
});
