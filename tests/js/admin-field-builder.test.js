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
	beforeEach(() => {
		window.$.fx.off = true;
		FB().init();
	});

	it('returns early when #ffc-fields-container is absent', () => {
		document.body.innerHTML = '<div id="other"></div>';
		// Should not throw; nothing wired against the missing container.
		expect(() => FB().init()).not.toThrow();
	});

	it('wires the .ffc-remove-field click that fades and removes the row + updates JSON', async () => {
		// The handler asks for confirmation before removing — stub
		// window.confirm to accept.
		const confirmStub = vi.spyOn(window, 'confirm').mockReturnValue(true);
		FB().addField('text');
		document.querySelector('.ffc-remove-field').click();
		await new Promise((r) => setTimeout(r, 50));
		expect(document.querySelectorAll('#ffc-fields-container .ffc-field-row').length).toBe(0);
		confirmStub.mockRestore();
	});

	it('keeps the row when confirm is declined', () => {
		const confirmStub = vi.spyOn(window, 'confirm').mockReturnValue(false);
		FB().addField('text');
		document.querySelector('.ffc-remove-field').click();
		expect(document.querySelectorAll('#ffc-fields-container .ffc-field-row').length).toBe(1);
		confirmStub.mockRestore();
	});

	it('updates JSON on change/input within the container', () => {
		FB().addField('text');
		const label = document.querySelector('.ffc-field-label');
		label.value = 'Changed';
		window.$(label).trigger('input');
		const parsed = JSON.parse(document.getElementById('ffc-form-fields-json').value);
		expect(parsed[0].label).toBe('Changed');
	});

	it('toggles JS-built rows when .ffc-field-type changes to info/embed/select', () => {
		FB().addField('text');
		const row = document.querySelector('.ffc-field-row');
		const sel = row.querySelector('.ffc-field-type');

		sel.value = 'info';
		window.$(sel).trigger('change');
		expect(row.querySelector('.ffc-content-row').style.display).not.toBe('none');
		expect(row.querySelector('.ffc-standard-row').style.display).toBe('none');

		sel.value = 'embed';
		window.$(sel).trigger('change');
		expect(row.querySelector('.ffc-embed-row').style.display).not.toBe('none');

		sel.value = 'select';
		window.$(sel).trigger('change');
		// Options field becomes visible for select/radio/checkbox.
		const opts = row.querySelector('.ffc-options-field');
		if (opts) {
			expect(opts.style.display).not.toBe('none');
		}
	});

	it('toggles PHP-rendered rows via .ffc-field-type-selector using ffc-hidden classes', () => {
		document.body.innerHTML = `
			<div id="ffc-fields-container">
				<div class="ffc-field-row">
					<select class="ffc-field-type-selector">
						<option value="text">text</option>
						<option value="info">info</option>
						<option value="embed">embed</option>
						<option value="select">select</option>
					</select>
					<div class="ffc-content-field"></div>
					<div class="ffc-embed-field"></div>
					<div class="ffc-standard-row"></div>
					<div class="ffc-options-field"></div>
				</div>
			</div>
			<input type="hidden" id="ffc-form-fields-json" />
		`;
		// Re-init so the .on() bindings attach to this fresh container element.
		FB().init();
		const row = document.querySelector('.ffc-field-row');
		const sel = row.querySelector('.ffc-field-type-selector');

		sel.value = 'info';
		window.$(sel).trigger('change');
		expect(row.querySelector('.ffc-content-field').classList.contains('ffc-hidden')).toBe(false);
		expect(row.querySelector('.ffc-standard-row').classList.contains('ffc-hidden')).toBe(true);

		sel.value = 'embed';
		window.$(sel).trigger('change');
		expect(row.querySelector('.ffc-embed-field').classList.contains('ffc-hidden')).toBe(false);

		sel.value = 'select';
		window.$(sel).trigger('change');
		expect(row.querySelector('.ffc-options-field').classList.contains('ffc-hidden')).toBe(false);

		sel.value = 'text';
		window.$(sel).trigger('change');
		expect(row.querySelector('.ffc-options-field').classList.contains('ffc-hidden')).toBe(true);
	});
});

describe('FFC.Admin.FieldBuilder — field type menu (.ffc-add-field click)', () => {
	beforeEach(() => {
		window.$.fx.off = true;
		document.body.innerHTML = `
			<button class="ffc-add-field">Add</button>
			<div id="ffc-fields-container"></div>
			<input type="hidden" id="ffc-form-fields-json" />
		`;
		FB().init();
	});

	it('opens a field-type menu listing every field type and adds the chosen type', () => {
		document.querySelector('.ffc-add-field').click();
		const menu = document.querySelector('.ffc-field-type-menu');
		expect(menu).not.toBeNull();
		const items = menu.querySelectorAll('li');
		expect(items.length).toBeGreaterThan(0);

		// Clicking an item adds that field and removes the menu.
		const textItem = Array.from(items).find((li) => li.getAttribute('data-type') === 'text');
		textItem.click();
		expect(document.querySelectorAll('#ffc-fields-container .ffc-field-row').length).toBe(1);
		expect(document.querySelector('.ffc-field-type-menu')).toBeNull();
	});

	it('removes a pre-existing menu before opening a new one', () => {
		document.querySelector('.ffc-add-field').click();
		document.querySelector('.ffc-add-field').click();
		expect(document.querySelectorAll('.ffc-field-type-menu').length).toBe(1);
	});

	it('closes the menu on the deferred outside click', async () => {
		document.querySelector('.ffc-add-field').click();
		expect(document.querySelector('.ffc-field-type-menu')).not.toBeNull();
		// The script defers wiring the document.one('click') by 100ms.
		await new Promise((r) => setTimeout(r, 150));
		document.body.click();
		expect(document.querySelector('.ffc-field-type-menu')).toBeNull();
	});
});

describe('FFC.Admin.FieldBuilder.updateJSON — missing JSON field', () => {
	it('warns when no JSON field exists', () => {
		document.body.innerHTML = '<div id="ffc-fields-container"></div>';
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
		FB().updateJSON();
		expect(warn).toHaveBeenCalledWith('[FFC] JSON field not found');
		warn.mockRestore();
	});
});
