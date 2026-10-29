// Coverage for the field behaviours shared by the reregistration form and
// the wp-admin user screen (`assets/js/ffc-field-behaviours.js`), and for the
// admin glue that wires them (`assets/js/ffc-admin-user-fields.js`).
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	window.ffcAdminUserFields = {
		strings: {
			dualPostShowValue: 'Acumulo',
			select: 'Escolha',
			invalidCpf: 'CPF inválido.',
			invalidEmail: 'E-mail inválido.',
		},
	};
	if (!window.FFC || !window.FFC.setRequiredWithin) { loadScript('assets/js/ffc-core.js'); }
	loadScript('assets/js/ffc-field-behaviours.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
	window.$.fx.off = true;
	window.$(document).off();
});

function type($input, value) {
	$input.val(value).trigger('input');
	return $input.val();
}

describe('FFC.Fields masks', () => {
	const cases = [
		['cpf', '12345678909', '123.456.789-09'],
		['cpf', '1234', '123.4'],
		['phone', '11987654321', '(11) 98765-4321'],
		['phone', '1133334444', '(11) 3333-4444'],
		['phone', '119876543210000', '(11) 98765-4321'],
		['phone', '119', '(11) 9'],
		['cep', '01310100', '01310-100'],
		['rf', '1234567', '123.456-7'],
		['number', 'a1b2', '12'],
		['cin', '123456789', '12.345.678-9'],
		['cin', '12345678x', '12.345.678-X'],
		['cin', '1X2345678', '12.345.678'],
	];

	it.each(cases)('%s masks %s as %s', (mask, raw, expected) => {
		document.body.innerHTML = `<div id="c"><input data-mask="${mask}"></div>`;
		window.FFC.Fields.initMasks(window.$('#c'));
		expect(type(window.$('#c input'), raw)).toBe(expected);
	});

	it('shapes a value the field already holds when it loads', () => {
		document.body.innerHTML = '<div id="c"><input data-mask="cpf" value="52998224725"><input data-mask="rf" value="8026335"><input data-mask="cep" value=""></div>';
		window.FFC.Fields.initMasks(window.$('#c'));
		const $inputs = window.$('#c input');
		expect($inputs.eq(0).val()).toBe('529.982.247-25');
		expect($inputs.eq(1).val()).toBe('802.633-5');
		expect($inputs.eq(2).val()).toBe('');
	});

	it('leaves a stored phone as it is, so an international number keeps its code', () => {
		document.body.innerHTML = '<div id="c"><input data-mask="phone" value="+55 11 98765-4321"></div>';
		window.FFC.Fields.initMasks(window.$('#c'));
		expect(window.$('#c input').val()).toBe('+55 11 98765-4321');
	});

	it('leaves an unknown mask alone', () => {
		document.body.innerHTML = '<div id="c"><input data-mask="nope"></div>';
		window.FFC.Fields.initMasks(window.$('#c'));
		expect(type(window.$('#c input'), 'abc-123')).toBe('abc-123');
	});

	it('masks only inside the container it was given', () => {
		document.body.innerHTML = '<div id="c"></div><input id="out" data-mask="cpf">';
		window.FFC.Fields.initMasks(window.$('#c'));
		expect(type(window.$('#out'), '12345678909')).toBe('12345678909');
	});
});

describe('FFC.Fields format checks', () => {
	const S = { invalidCpf: 'bad cpf', invalidRf: 'bad rf', invalidEmail: 'bad email', invalidPhone: 'bad phone', invalidFormat: 'bad' };

	it('accepts a valid CPF and refuses wrong digits or a repeated run', () => {
		expect(window.FFC.Fields.validateCpf('529.982.247-25')).toBe(true);
		expect(window.FFC.Fields.validateCpf('529.982.247-26')).toBe(false);
		expect(window.FFC.Fields.validateCpf('111.111.111-11')).toBe(false);
		expect(window.FFC.Fields.validateCpf('123')).toBe(false);
	});

	it('names the broken format and passes an empty value', () => {
		expect(window.FFC.Fields.formatError('', 'cpf', S)).toBe('');
		expect(window.FFC.Fields.formatError('123.456.789-00', 'cpf', S)).toBe('bad cpf');
		expect(window.FFC.Fields.formatError('a@b', 'email', S)).toBe('bad email');
		expect(window.FFC.Fields.formatError('a@b.co', 'email', S)).toBe('');
		expect(window.FFC.Fields.formatError('(11) 98765-4321', 'phone', S)).toBe('');
		expect(window.FFC.Fields.formatError('123', 'phone', S)).toBe('bad phone');
		expect(window.FFC.Fields.formatError('x', 'unknown', S)).toBe('');
		expect(window.FFC.Fields.formatError('518.178-0', 'rf', S)).toBe('');
		expect(window.FFC.Fields.formatError('51817', 'rf', S)).toBe('bad rf');
	});

	it('checks a custom pattern with its own message, and ignores a broken one', () => {
		expect(window.FFC.Fields.formatError('abc', 'custom_regex', S, { pattern: '^\\d+$', message: 'digits only' })).toBe('digits only');
		expect(window.FFC.Fields.formatError('abc', 'custom_regex', S, { pattern: '^\\d+$' })).toBe('bad');
		expect(window.FFC.Fields.formatError('abc', 'custom_regex', S, { pattern: '(' })).toBe('');
	});
});

describe('FFC.Fields dependent select', () => {
	function mount(value) {
		document.body.innerHTML = `
			<div id="c">
				<input type="hidden" id="dep" value='${value}'>
				<div class="ffc-dependent-select" data-target="dep">
					<select class="ffc-dep-parent"><option value=""></option><option value="A">A</option><option value="B">B</option></select>
					<select class="ffc-dep-child"><option value=""></option></select>
					<script type="application/json" class="ffc-dep-groups">{"A":["A1","A2"],"B":["<b>B1</b>"]}</script>
				</div>
			</div>`;
		window.FFC.Fields.initDependentSelects(window.$('#c'), { select: 'Escolha' });
	}

	it('refills the child from the parent and writes the pair as JSON', () => {
		mount('');
		window.$('.ffc-dep-parent').val('A').trigger('change');
		expect(window.$('.ffc-dep-child option').map((_, o) => o.value).get()).toEqual(['', 'A1', 'A2']);
		expect(window.$('.ffc-dep-child option').first().text()).toBe('Escolha');
		window.$('.ffc-dep-child').val('A2').trigger('change');
		expect(JSON.parse(window.$('#dep').val())).toEqual({ parent: 'A', child: 'A2' });
	});

	it('writes options as text, never as markup', () => {
		mount('');
		window.$('.ffc-dep-parent').val('B').trigger('change');
		expect(window.$('.ffc-dep-child b').length).toBe(0);
		expect(window.$('.ffc-dep-child option').last().text()).toBe('<b>B1</b>');
	});

	it('does nothing when the groups are not JSON', () => {
		mount('');
		window.$('.ffc-dep-groups').text('{nope');
		document.body.innerHTML = document.body.innerHTML; // rebuild without handlers
		window.FFC.Fields.initDependentSelects(window.$('#c'), {});
		window.$('.ffc-dep-parent').val('A').trigger('change');
		expect(window.$('.ffc-dep-child option').length).toBe(1);
	});
});

describe('FFC.Fields dual post', () => {
	function mount(selected) {
		document.body.innerHTML = `
			<table id="c"><tbody>
				<tr data-field-key="acumulo_cargos"><td><select>
					<option value="Nao acumulo">Nao acumulo</option>
					<option value="Acumulo" ${selected ? 'selected' : ''}>Acumulo</option>
				</select></td></tr>
				<tr data-field-key="jornada_acumulo"><td><input id="j" required></td></tr>
				<tr data-field-key="cargo_funcao_acumulo"><td><input id="k"></td></tr>
			</tbody></table>`;
		window.FFC.Fields.initDualPost(window.$('#c'), 'Acumulo');
	}

	it('hides the accumulation rows and lifts their required on load', () => {
		mount(false);
		expect(window.$('[data-field-key="jornada_acumulo"]').css('display')).toBe('none');
		expect(window.$('#j').prop('required')).toBe(false);
	});

	it('shows them and restores required when the post is declared', () => {
		mount(false);
		window.$('[data-field-key="acumulo_cargos"] select').val('Acumulo').trigger('change');
		expect(window.$('[data-field-key="jornada_acumulo"]').css('display')).not.toBe('none');
		expect(window.$('#j').prop('required')).toBe(true);
		expect(window.$('#k').prop('required')).toBe(false);
	});

	it('opens matching a stored "I hold"', () => {
		mount(true);
		expect(window.$('[data-field-key="cargo_funcao_acumulo"]').css('display')).not.toBe('none');
	});
});

describe('ffc-admin-user-fields.js', () => {
	function mountAdmin() {
		document.body.innerHTML = `
			<form><div id="ffc-user-custom-fields"><table><tbody>
				<tr data-field-key="cpf" data-format="cpf"><td><input id="cpf" data-mask="cpf" value="123.456.789-00"></td></tr>
				<tr data-field-key="email" data-format="email"><td><input id="email" value=""></td></tr>
				<tr data-field-key="nome" data-format=""><td><input id="nome" value="x"></td></tr>
				<tr data-field-key="acumulo_cargos" data-format=""><td><select><option>Nao</option><option>Acumulo</option></select></td></tr>
				<tr data-field-key="jornada_acumulo" data-format=""><td><input id="j"></td></tr>
			</tbody></table></div></form>`;
		window.FFC.AdminUserFields.init();
	}

	beforeAll(() => {
		loadScript('assets/js/ffc-admin-user-fields.js');
	});

	it('marks a stored invalid CPF invalid on load, with the translated message', () => {
		mountAdmin();
		expect(document.getElementById('cpf').validationMessage).toBe('CPF inválido.');
		expect(document.getElementById('cpf').checkValidity()).toBe(false);
	});

	it('clears the message once the value is valid', () => {
		mountAdmin();
		type(window.$('#cpf'), '52998224725');
		expect(window.$('#cpf').val()).toBe('529.982.247-25');
		expect(document.getElementById('cpf').checkValidity()).toBe(true);
	});

	it('checks the e-mail format as it is typed, and passes an empty one', () => {
		mountAdmin();
		expect(document.getElementById('email').checkValidity()).toBe(true);
		type(window.$('#email'), 'nobody@');
		expect(document.getElementById('email').validationMessage).toBe('E-mail inválido.');
	});

	it('wires the dual-post toggle with the localized value', () => {
		mountAdmin();
		expect(window.$('[data-field-key="jornada_acumulo"]').css('display')).toBe('none');
		window.$('[data-field-key="acumulo_cargos"] select').val('Acumulo').trigger('change');
		expect(window.$('[data-field-key="jornada_acumulo"]').css('display')).not.toBe('none');
	});

	it('does nothing on a screen without the section', () => {
		document.body.innerHTML = '<input id="cpf" data-mask="cpf">';
		window.FFC.AdminUserFields.init();
		expect(type(window.$('#cpf'), '12345678909')).toBe('12345678909');
	});
});

// One CPF/RF shape and one pair of validators for every screen (#1920): the
// public forms' mask formats through these, so the cases pin what a visitor
// types as much as what the dashboard does.
describe('FFC.Fields cpf_rf mask and validators', () => {
	const masks = () => window.FFC.Fields.masks;

	it.each([
		['1234567', '123.456-7'],
		['123456', '123.456'],
		['12345678', '123.456.78'],
		['52998224725', '529.982.247-25'],
		['529982247251234', '529.982.247-25'],
		['', ''],
	])('cpf_rf formats %s as %s', (input, expected) => {
		expect(masks().cpf_rf(input)).toBe(expected);
	});

	it('cpf_rf reads up to seven digits as an RF and more as a CPF, like the cpf and rf masks', () => {
		expect(masks().cpf_rf('1234567')).toBe(masks().rf('1234567'));
		expect(masks().cpf_rf('52998224725')).toBe(masks().cpf('52998224725'));
	});

	it('validateRf wants seven digits', () => {
		window.ffcCoreConfig = {};
		expect(window.FFC.Fields.validateRf('123.456-7')).toBe(true);
		expect(window.FFC.Fields.validateRf('123456')).toBe(false);
		expect(window.FFC.Fields.validateRf('12345678')).toBe(false);
	});

	it('validateRf checks the check digit only when the site enforces it, as the server does', () => {
		// 1234560: 7*1+6*2+5*3+4*4+3*5+2*6 = 77, 77 % 11 = 0, (11 - 0) % 10 = 1.
		window.ffcCoreConfig = { rfCheckDigit: false };
		expect(window.FFC.Fields.validateRf('1234560')).toBe(true);
		window.ffcCoreConfig = { rfCheckDigit: true };
		expect(window.FFC.Fields.validateRf('1234560')).toBe(false);
		expect(window.FFC.Fields.validateRf('123.456-1')).toBe(true);
		window.ffcCoreConfig = {};
	});

	it('the public helpers answer with the shared validators', () => {
		loadScript('assets/js/ffc-frontend-helpers.js');
		const v = window.FFC.Frontend.Validation;
		expect(v.validateCPF('529.982.247-25')).toBe(window.FFC.Fields.validateCpf('529.982.247-25'));
		expect(v.validateCPF('111.111.111-11')).toBe(false);
		window.ffcCoreConfig = { rfCheckDigit: true };
		expect(v.validateRF('1234561')).toBe(true);
		expect(v.validateRF('1234560')).toBe(false);
		window.ffcCoreConfig = {};
	});

	it('the public CPF/RF mask formats through the shared one', () => {
		loadScript('assets/js/ffc-frontend-helpers.js');
		document.body.innerHTML = '<input name="cpf_rf">';
		const $input = window.$('input[name="cpf_rf"]');
		window.FFC.Frontend.Masks.applyCpfRf($input);
		const shared = window.FFC.Fields.masks.cpf_rf;
		let calls = 0;
		window.FFC.Fields.masks.cpf_rf = (v) => { calls++; return shared(v); };
		expect(type($input, '1234567')).toBe('123.456-7');
		expect(type($input, '52998224725')).toBe('529.982.247-25');
		expect(calls).toBe(2);
		window.FFC.Fields.masks.cpf_rf = shared;
	});
});
