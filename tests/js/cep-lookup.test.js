// The address lookup by CEP (#2234): `FFC.Fields.initCepLookup()` in
// `assets/js/ffc-field-behaviours.js`, and the Settings → General test
// (`assets/js/ffc-cep-lookup-test.js`).
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const STRINGS = {
	searching: 'Buscando…',
	filled: 'FILLED',
	cityOnly: 'CITY_ONLY',
	notFound: 'NOT_FOUND',
};

beforeAll(() => {
	if (!window.FFC || !window.FFC.setRequiredWithin) { loadScript('assets/js/ffc-core.js'); }
	if (!window.FFC.Fields) { loadScript('assets/js/ffc-field-behaviours.js'); }
	window.ffcCepLookupTest = {
		url: '/wp-admin/admin-ajax.php',
		action: 'ffc_cep_lookup_test',
		nonce: 'n',
		strings: { found: 'Respondeu', notFound: 'Não encontrado', unavailable: 'Fora do ar' },
	};
	loadScript('assets/js/ffc-cep-lookup-test.js');
});

let requests;
let ranges;

beforeEach(() => {
	document.body.innerHTML = '';
	window.$(document).off();
	ranges = window.ffcCoreConfig && window.ffcCoreConfig.cepRanges;
	window.ffcCoreConfig = Object.assign({}, window.ffcCoreConfig, {
		cepRanges: { SP: [[1000, 19999]], RJ: [[20000, 28999]] },
		cepLookup: { url: '/wp-admin/admin-ajax.php', action: 'ffc_cep_lookup', nonce: 'n', strings: STRINGS },
	});
	requests = [];
	vi.spyOn(window.$, 'ajax').mockImplementation((options) => {
		const deferred = window.$.Deferred();
		requests.push({ options, deferred });
		return deferred.promise();
	});
	vi.useFakeTimers();
	vi.setSystemTime(0);
});

afterEach(() => {
	vi.useRealTimers();
	vi.restoreAllMocks();
	window.ffcCoreConfig.cepRanges = ranges;
	delete window.ffcCoreConfig.cepLookup;
});

function form(values = {}) {
	const v = Object.assign({ cep: '', endereco: '', numero: '', complemento: '', bairro: '', cidade: '', uf: '' }, values);
	document.body.innerHTML = `
		<div id="f">
			<div data-field-key="cep"><input id="cep" value="${v.cep}"></div>
			<div data-field-key="endereco"><input id="endereco" value="${v.endereco}"></div>
			<div data-field-key="endereco_numero"><input id="numero" value="${v.numero}"></div>
			<div data-field-key="endereco_complemento"><input id="complemento" value="${v.complemento}"></div>
			<div data-field-key="bairro"><input id="bairro" value="${v.bairro}"></div>
			<div data-field-key="cidade"><input id="cidade" value="${v.cidade}"></div>
			<div data-field-key="uf"><select id="uf"><option value=""></option><option value="SP">SP</option><option value="RJ">RJ</option></select></div>
		</div>`;
	window.$('#uf').val(v.uf);
	window.FFC.Fields.initCepLookup(window.$('#f'));
}

function typeCep(value) {
	window.$('#cep').val(value).trigger('input');
}

function answer(data, index = 0) {
	requests[index].deferred.resolve({ success: true, data });
}

const SE = { street: 'Praça da Sé', neighborhood: 'Sé', city: 'São Paulo', uf: 'SP', complement: 'lado ímpar' };

describe('FFC.Fields.initCepLookup', () => {
	it('does nothing when the lookup is off', () => {
		window.ffcCoreConfig.cepLookup = null;
		form();
		typeCep('01001-000');
		expect(requests).toHaveLength(0);
		expect(document.querySelector('.ffc-cep-status')).toBeNull();
	});

	it('asks only on eight digits that pass the offline rule', () => {
		form();
		typeCep('01001-00');
		typeCep('11111-111');
		typeCep('00999-000');
		expect(requests).toHaveLength(0);

		typeCep('01001-000');
		expect(requests).toHaveLength(1);
		expect(requests[0].options.data).toEqual({ action: 'ffc_cep_lookup', nonce: 'n', cep: '01001000' });

		// The same CEP again does not ask twice.
		window.$('#cep').trigger('change');
		expect(requests).toHaveLength(1);
	});

	it('locks the address while searching and fills it no sooner than three seconds', () => {
		form({ endereco: 'Typed street', numero: '12', complemento: 'apto 3' });
		typeCep('01001-000');

		expect(window.$('#endereco').prop('readOnly')).toBe(true);
		expect(window.$('#numero').prop('readOnly')).toBe(false);
		expect(window.$('#uf').attr('tabindex')).toBe('-1');
		expect(window.$('#uf').hasClass('ffc-cep-locked')).toBe(true);
		expect(window.$('.ffc-cep-progress').prop('hidden')).toBe(false);
		expect(window.$('.ffc-cep-status').text()).toBe('Buscando…');

		vi.advanceTimersByTime(500);
		answer({ status: 'found', address: SE });
		expect(window.$('#endereco').val()).toBe('Typed street');

		vi.advanceTimersByTime(2499);
		expect(window.$('#endereco').val()).toBe('Typed street');

		vi.advanceTimersByTime(1);
		expect(window.$('#endereco').val()).toBe('Praça da Sé');
		expect(window.$('#bairro').val()).toBe('Sé');
		expect(window.$('#cidade').val()).toBe('São Paulo');
		expect(window.$('#uf').val()).toBe('SP');
		expect(window.$('#numero').val()).toBe('12');
		expect(window.$('#complemento').val()).toBe('apto 3');

		expect(window.$('#endereco').prop('readOnly')).toBe(false);
		expect(window.$('#uf').attr('tabindex')).toBeUndefined();
		expect(window.$('.ffc-cep-progress').prop('hidden')).toBe(true);
		expect(window.$('.ffc-cep-status').text()).toBe('FILLED');
		expect(document.activeElement.id).toBe('numero');

		expect(window.$('#endereco').hasClass('ffc-cep-filled')).toBe(true);
		vi.advanceTimersByTime(1500);
		expect(window.$('#endereco').hasClass('ffc-cep-filled')).toBe(false);
	});

	it('applies an answer that arrives after three seconds at once', () => {
		form();
		typeCep('01001-000');
		vi.advanceTimersByTime(4200);
		answer({ status: 'found', address: SE });
		vi.advanceTimersByTime(0);
		expect(window.$('#endereco').val()).toBe('Praça da Sé');
	});

	it('never overwrites with an empty value and keeps a field already read-only', () => {
		form({ endereco: 'Typed street', bairro: 'Typed district' });
		window.$('#cidade').prop('readOnly', true);
		typeCep('18430-000');
		answer({ status: 'found', address: { street: '', neighborhood: '', city: 'Ribeirão Branco', uf: 'SP', complement: '' } });
		vi.advanceTimersByTime(3000);

		expect(window.$('#endereco').val()).toBe('Typed street');
		expect(window.$('#bairro').val()).toBe('Typed district');
		expect(window.$('#cidade').val()).toBe('Ribeirão Branco');
		expect(window.$('#cidade').prop('readOnly')).toBe(true);
		expect(window.$('.ffc-cep-status').text()).toBe('CITY_ONLY');
	});

	it('sets the UF only when it is one of the options', () => {
		form({ uf: 'RJ' });
		typeCep('01001-000');
		answer({ status: 'found', address: Object.assign({}, SE, { uf: 'XX' }) });
		vi.advanceTimersByTime(3000);
		expect(window.$('#uf').val()).toBe('RJ');
	});

	it('releases at once when the CEP is not found', () => {
		form();
		typeCep('01001-000');
		answer({ status: 'not_found', address: {} });

		expect(window.$('#endereco').prop('readOnly')).toBe(false);
		expect(window.$('.ffc-cep-status').text()).toBe('NOT_FOUND');
	});

	it('waits the three seconds and asks for manual entry when both providers are down', () => {
		form();
		typeCep('01001-000');
		answer({ status: 'unavailable', address: {} });
		expect(window.$('#endereco').prop('readOnly')).toBe(true);

		vi.advanceTimersByTime(3000);
		expect(window.$('#endereco').prop('readOnly')).toBe(false);
		expect(window.$('.ffc-cep-status').text()).toBe('NOT_FOUND');
	});

	it('treats a failed request like an outage and gives the server six seconds', () => {
		form();
		typeCep('01001-000');
		expect(requests[0].options.timeout).toBe(6000);
		requests[0].deferred.reject({ status: 429 });
		vi.advanceTimersByTime(3000);
		expect(window.$('#endereco').prop('readOnly')).toBe(false);
		expect(window.$('.ffc-cep-status').text()).toBe('NOT_FOUND');
	});

	it('abandons the lookup in flight when the CEP changes', () => {
		form();
		typeCep('01001-000');
		typeCep('01001-00');
		expect(window.$('#endereco').prop('readOnly')).toBe(false);
		expect(window.$('.ffc-cep-status').text()).toBe('');

		answer({ status: 'found', address: SE });
		vi.advanceTimersByTime(3000);
		expect(window.$('#endereco').val()).toBe('');
	});

	it('ignores the answer to a CEP that was replaced by another one', () => {
		form();
		typeCep('01001-000');
		typeCep('20040-002');
		expect(requests).toHaveLength(2);

		answer({ status: 'found', address: SE }, 0);
		answer({ status: 'found', address: { street: 'Av. Rio Branco', neighborhood: 'Centro', city: 'Rio de Janeiro', uf: 'RJ' } }, 1);
		vi.advanceTimersByTime(3000);
		expect(window.$('#endereco').val()).toBe('Av. Rio Branco');
		expect(window.$('#uf').val()).toBe('RJ');
	});

	it('does not look up the CEP the form loaded with', () => {
		form({ cep: '01001-000' });
		window.$('#cep').trigger('change');
		expect(requests).toHaveLength(0);
	});
});

describe('ffc-cep-lookup-test.js', () => {
	function screen() {
		document.body.innerHTML = '<input id="ffc-cep-lookup-test-cep" value="01001-000"><button id="ffc-cep-lookup-test-btn"></button><div id="ffc-cep-lookup-test-result"></div>';
	}

	it('posts the CEP and lists each provider', () => {
		screen();
		const post = vi.spyOn(window.$, 'post').mockImplementation(() => window.$.Deferred().resolve({
			success: true,
			data: { results: [
				{ provider: 'viacep', status: 'found', address: SE },
				{ provider: 'brasilapi', status: 'unavailable', address: {} },
			] },
		}).promise());

		window.FFC.CepLookupTest.run();

		expect(post).toHaveBeenCalledWith('/wp-admin/admin-ajax.php', { action: 'ffc_cep_lookup_test', nonce: 'n', cep: '01001-000' });
		const rows = window.$('#ffc-cep-lookup-test-result tbody tr');
		expect(rows).toHaveLength(2);
		expect(rows.eq(0).text()).toContain('ViaCEP');
		expect(rows.eq(0).text()).toContain('Praça da Sé, Sé, São Paulo/SP');
		expect(rows.eq(1).text()).toContain('Fora do ar');
		expect(window.$('#ffc-cep-lookup-test-btn').prop('disabled')).toBe(false);
	});

	it('shows the server message on a refusal', () => {
		screen();
		vi.spyOn(window.$, 'post').mockImplementation(() => window.$.Deferred().reject({ responseJSON: { data: { message: 'Type a valid CEP to test.' } } }).promise());

		window.FFC.CepLookupTest.run();
		expect(window.$('#ffc-cep-lookup-test-result').text()).toBe('Type a valid CEP to test.');
	});

	it('escapes what a provider returned', () => {
		screen();
		window.FFC.CepLookupTest.render(window.$('#ffc-cep-lookup-test-result'), [
			{ provider: 'viacep', status: 'found', address: { street: '<img src=x onerror=alert(1)>' } },
		]);
		expect(window.$('#ffc-cep-lookup-test-result img')).toHaveLength(0);
	});
});
