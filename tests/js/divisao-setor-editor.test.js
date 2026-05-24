// Tests for assets/js/ffc-divisao-setor-editor.js — the admin nested
// repeater that edits the reregistration divisao_setor map in
// Settings → Reregistration.
//
// The IIFE registers delegated handlers (add/remove division, add/remove
// sector, input sync) and keeps a hidden JSON input in sync after every
// mutation. State lives entirely in the DOM + the hidden input.
import { describe, it, expect, beforeEach, beforeAll } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	loadScript('assets/js/ffc-divisao-setor-editor.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
});

function sectorMarkup(value) {
	return `
		<div class="ffc-ds-sector">
			<input type="text" class="ffc-ds-sector-name" value="${value}">
			<button type="button" class="ffc-ds-sector-remove">×</button>
		</div>`;
}

function divisionMarkup(name, sectors) {
	const inner = (sectors || []).map(sectorMarkup).join('');
	return `
		<div class="ffc-ds-division">
			<div class="ffc-ds-division-head">
				<input type="text" class="ffc-ds-division-name" value="${name}">
				<button type="button" class="ffc-ds-division-remove">Remove</button>
			</div>
			<div class="ffc-ds-sectors">${inner}</div>
			<button type="button" class="ffc-ds-sector-add">+ Add</button>
		</div>`;
}

function mount(map) {
	const divisions = Object.keys(map || {})
		.map((name) => divisionMarkup(name, map[name]))
		.join('');
	document.body.innerHTML = `
		<input type="hidden" id="ffc_ds_map_json" name="ffc_settings[divisao_setor_map_json]" value="{}">
		<div class="ffc-ds-editor" data-target="ffc_ds_map_json">
			<div class="ffc-ds-divisions">${divisions}</div>
			<button type="button" class="ffc-ds-division-add">+ Add Division</button>
		</div>`;
}

function hidden() {
	return JSON.parse(window.$('#ffc_ds_map_json').val());
}

describe('ffc-divisao-setor-editor — sync', () => {
	it('serializes named divisions and their sectors to the hidden input', () => {
		mount({ 'Div A': ['S1', 'S2'] });
		window.$('.ffc-ds-division-name').trigger('input');

		expect(hidden()).toEqual({ 'Div A': ['S1', 'S2'] });
	});

	it('omits divisions with an empty name', () => {
		mount({ '': ['orphan'] });
		window.$('.ffc-ds-division-name').trigger('input');

		expect(hidden()).toEqual({});
	});

	it('de-duplicates repeated sector names', () => {
		mount({ 'Div A': ['Dup', 'Dup', 'Unique'] });
		window.$('.ffc-ds-sector-name').first().trigger('input');

		expect(hidden()).toEqual({ 'Div A': ['Dup', 'Unique'] });
	});
});

describe('ffc-divisao-setor-editor — add', () => {
	it('adds a division block with one empty sector', () => {
		mount({});
		window.$('.ffc-ds-division-add').trigger('click');

		expect(window.$('.ffc-ds-division').length).toBe(1);
		expect(window.$('.ffc-ds-sector').length).toBe(1);
		// Unnamed division → not serialized yet.
		expect(hidden()).toEqual({});
	});

	it('adds a sector to an existing division', () => {
		mount({ 'Div A': ['S1'] });
		window.$('.ffc-ds-sector-add').trigger('click');
		// Fill the new sector and sync.
		window.$('.ffc-ds-sector-name').last().val('S2').trigger('input');

		expect(window.$('.ffc-ds-division .ffc-ds-sector').length).toBe(2);
		expect(hidden()).toEqual({ 'Div A': ['S1', 'S2'] });
	});
});

describe('ffc-divisao-setor-editor — remove', () => {
	it('removes a sector and resyncs', () => {
		mount({ 'Div A': ['S1', 'S2'] });
		window.$('.ffc-ds-sector-remove').first().trigger('click');

		expect(window.$('.ffc-ds-sector').length).toBe(1);
		expect(hidden()).toEqual({ 'Div A': ['S2'] });
	});

	it('removes an entire division and resyncs', () => {
		mount({ 'Div A': ['S1'], 'Div B': ['S2'] });
		window.$('.ffc-ds-division').first().find('.ffc-ds-division-remove').trigger('click');

		expect(window.$('.ffc-ds-division').length).toBe(1);
		expect(hidden()).toEqual({ 'Div B': ['S2'] });
	});
});
