// Tests for `assets/js/ffc-admin-field-builder.js`.
//
// Public surface: window.FFC.Admin.FieldBuilder.{init, addField, updateJSON}.
// Sprint N of #175.
import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'test-nonce',
		strings: {
			remove: 'Remove',
			fieldType: 'Field Type:',
			label: 'Label:',
			fieldLabel: 'Field Label',
			nameVariable: 'Name:',
			fieldName: 'field_name',
			required: 'Required:',
			options: 'Options:',
			separateWithCommas: 'Separate with commas',
			content: 'Content:',
			contentPlaceholder: 'Display text',
			titleOptional: 'Title:',
			embedUrl: 'URL:',
			embedUrlPlaceholder: 'https://...',
			captionOptional: 'Caption:',
		},
	};
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-admin-field-builder.js');
});

beforeEach(() => {
	document.body.innerHTML = `
		<div id="ffc-fields-container"></div>
		<input type="hidden" id="ffc-form-fields-json" />
	`;
});

const FB = () => window.FFC.Admin.FieldBuilder;

describe('FFC.Admin.FieldBuilder public API', () => {
	it('exposes init / addField / updateJSON', () => {
		expect(typeof FB().init).toBe('function');
		expect(typeof FB().addField).toBe('function');
		expect(typeof FB().updateJSON).toBe('function');
	});
});

describe('FFC.Admin.FieldBuilder.addField', () => {
	it("appends a .ffc-field-row to #ffc-fields-container for type='text'", () => {
		FB().addField('text');
		const rows = document.querySelectorAll('#ffc-fields-container .ffc-field-row');
		expect(rows.length).toBe(1);
		// Header strong contains the uppercased type.
		expect(rows[0].querySelector('.ffc-field-title strong').textContent).toBe('TEXT');
	});

	it("appends an info-style row with the content textarea visible", () => {
		FB().addField('info');
		const row = document.querySelector('#ffc-fields-container .ffc-field-row');
		const contentRow = row.querySelector('.ffc-content-row');
		// Info rows show the content textarea (no inline display:none).
		expect(contentRow.style.display).not.toBe('none');
		// Standard rows are hidden for display-only fields.
		const standardRows = row.querySelectorAll('.ffc-standard-row');
		standardRows.forEach((r) => expect(r.style.display).toBe('none'));
	});

	it("appends an embed-style row with the URL input visible", () => {
		FB().addField('embed');
		const row = document.querySelector('#ffc-fields-container .ffc-field-row');
		const embedRow = row.querySelector('.ffc-embed-row');
		expect(embedRow.style.display).not.toBe('none');
		// Content row hidden for embed.
		expect(row.querySelector('.ffc-content-row').style.display).toBe('none');
	});

	it('writes the new field into the hidden JSON input', () => {
		FB().addField('text');
		const json = document.getElementById('ffc-form-fields-json').value;
		const parsed = JSON.parse(json);
		expect(parsed.length).toBe(1);
		expect(parsed[0].type).toBe('text');
	});

	it('increments an internal counter — multiple fields land with unique indices', () => {
		FB().addField('text');
		FB().addField('email');
		FB().addField('select');
		const rows = document.querySelectorAll('#ffc-fields-container .ffc-field-row');
		expect(rows.length).toBe(3);
		// data-index values should be different.
		const indices = Array.from(rows).map((r) => r.getAttribute('data-index'));
		expect(new Set(indices).size).toBe(3);
	});

	it('selects the correct option in the .ffc-field-type dropdown for the requested type', () => {
		FB().addField('select');
		const dropdown = document.querySelector('#ffc-fields-container .ffc-field-row .ffc-field-type');
		expect(dropdown.value).toBe('select');
	});
});

describe('FFC.Admin.FieldBuilder.updateJSON', () => {
	it('reads the form values from each row and serialises them as JSON', () => {
		FB().addField('text');
		FB().addField('email');

		// Fill out fields.
		const rows = document.querySelectorAll('#ffc-fields-container .ffc-field-row');
		rows[0].querySelector('.ffc-field-label').value = 'Name';
		rows[0].querySelector('.ffc-field-name').value = 'name';
		rows[1].querySelector('.ffc-field-label').value = 'Email';
		rows[1].querySelector('.ffc-field-name').value = 'email';

		FB().updateJSON();
		const parsed = JSON.parse(document.getElementById('ffc-form-fields-json').value);
		expect(parsed.length).toBe(2);
		expect(parsed[0].label).toBe('Name');
		expect(parsed[0].name).toBe('name');
		expect(parsed[1].label).toBe('Email');
		expect(parsed[1].name).toBe('email');
	});

	it('captures the `required` checkbox state', () => {
		FB().addField('text');
		document.querySelector('.ffc-field-required').checked = true;
		FB().updateJSON();
		const parsed = JSON.parse(document.getElementById('ffc-form-fields-json').value);
		expect(parsed[0].required).toBe(true);
	});

	it('emits an empty array when no rows are present', () => {
		// Container exists but no fields.
		FB().updateJSON();
		expect(document.getElementById('ffc-form-fields-json').value).toBe('[]');
	});

	it('falls back to input[name=ffc_form_fields] when #ffc-form-fields-json is absent', () => {
		document.body.innerHTML = `
			<div id="ffc-fields-container"></div>
			<input type="hidden" name="ffc_form_fields" />
		`;
		FB().addField('text');
		FB().updateJSON();
		const fallback = document.querySelector('input[name="ffc_form_fields"]');
		const parsed = JSON.parse(fallback.value);
		expect(parsed.length).toBe(1);
		expect(parsed[0].type).toBe('text');
	});
});

describe('FFC.Admin.FieldBuilder.init — document delegates', () => {
	it('wires the .ffc-remove-field click that fades and removes the row + updates JSON', async () => {
		// The handler asks for confirmation before removing — stub
		// window.confirm to accept.
		const confirmStub = vi.spyOn(window, 'confirm').mockReturnValue(true);
		FB().init();
		FB().addField('text');
		document.querySelector('.ffc-remove-field').click();
		// fadeOut animates over 300ms — wait for the animation to finish
		// AND the post-animation `$(this).remove()` + JSON update.
		await new Promise((r) => setTimeout(r, 600));
		expect(document.querySelectorAll('#ffc-fields-container .ffc-field-row').length).toBe(0);
		confirmStub.mockRestore();
	});
});
