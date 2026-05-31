// Tests for the untested helpers in `assets/js/ffc-core.js`:
//   - log / error / warn console wrappers (debug gating)
//   - getAjaxUrl / getNonce / getString accessors
//   - enableDebug / disableDebug
//   - isModuleLoaded / registerModule / getModules
//   - ajax() (legacy callback helper)
//   - toggleFields() (checkbox / select / radio, slide + invert paths)
//   - the delegated [data-confirm] click guard
//
// FFC.request / FFC.rest have their own suites (ffc-request, ffc-rest,
// ffc-core-nonce-*). This file targets the remaining surface.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeEach(() => {
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'core-nonce',
		strings: { greeting: 'Olá' },
	};
	document.body.innerHTML = '';
	// jsdom has no layout — make slide animations apply display immediately.
	if (window.$ && window.$.fx) { window.$.fx.off = true; }
	// FFC persists across tests in the same jsdom window; reset debug state.
	if (window.FFC) { window.FFC.disableDebug(); }
});

afterEach(() => {
	vi.restoreAllMocks();
});

async function load() {
	loadScript('assets/js/ffc-core.js');
	await new Promise((r) => setTimeout(r, 0));
}

describe('ffc-core.js — logging helpers', () => {
	it('log() stays silent unless debug is enabled, then logs with/without data', async () => {
		await load();
		const spy = vi.spyOn(console, 'log').mockImplementation(() => {});

		window.FFC.log('quiet');
		expect(spy).not.toHaveBeenCalled();

		window.FFC.enableDebug();
		window.FFC.log('with data', { a: 1 });
		window.FFC.log('no data');
		expect(spy).toHaveBeenCalledWith('[FFC Debug]', 'with data', { a: 1 });
		expect(spy).toHaveBeenCalledWith('[FFC Debug]', 'no data');
	});

	it('error() always logs, with and without an error object', async () => {
		await load();
		const spy = vi.spyOn(console, 'error').mockImplementation(() => {});

		window.FFC.error('boom', new Error('x'));
		window.FFC.error('plain');
		expect(spy).toHaveBeenCalledWith('[FFC Error]', 'boom', expect.any(Error));
		expect(spy).toHaveBeenCalledWith('[FFC Error]', 'plain');
	});

	it('warn() always logs', async () => {
		await load();
		const spy = vi.spyOn(console, 'warn').mockImplementation(() => {});
		window.FFC.warn('heads up');
		expect(spy).toHaveBeenCalledWith('[FFC Warning]', 'heads up');
	});
});

describe('ffc-core.js — accessors and module registry', () => {
	it('exposes ajax url, live nonce, and translated strings', async () => {
		await load();
		expect(window.FFC.getAjaxUrl()).toBe('/wp-admin/admin-ajax.php');
		expect(window.FFC.getNonce()).toBe('core-nonce');
		expect(window.FFC.getString('greeting')).toBe('Olá');
		// Falls back to defaultValue, then to the key itself.
		expect(window.FFC.getString('missing', 'fallback')).toBe('fallback');
		expect(window.FFC.getString('alsoMissing')).toBe('alsoMissing');
	});

	it('enableDebug / disableDebug flip the debug flag', async () => {
		await load();
		window.FFC.enableDebug();
		expect(window.FFC.config.debug).toBe(true);
		window.FFC.disableDebug();
		expect(window.FFC.config.debug).toBe(false);
	});

	it('isModuleLoaded reflects presence; registerModule records into getModules', async () => {
		await load();
		expect(window.FFC.isModuleLoaded('NopeModule')).toBe(false);
		window.FFC.registerModule('CoreHelpersTest', '9.9.9');
		const found = window.FFC.getModules().find((m) => m.name === 'CoreHelpersTest');
		expect(found).toBeTruthy();
		expect(found.version).toBe('9.9.9');
		expect(window.FFC.isModuleLoaded('getModules')).toBe(true);
	});
});

describe('ffc-core.js — ajax() legacy helper', () => {
	it('errors and returns undefined when action is missing', async () => {
		await load();
		const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
		const ajaxSpy = vi.spyOn(window.jQuery, 'ajax').mockReturnValue({});

		const result = window.FFC.ajax({});
		expect(result).toBeUndefined();
		expect(errSpy).toHaveBeenCalled();
		expect(ajaxSpy).not.toHaveBeenCalled();
	});

	it('sends action + nonce by default and honours method override', async () => {
		await load();
		const ajaxSpy = vi.spyOn(window.jQuery, 'ajax').mockReturnValue({ id: 'jqxhr' });

		const ret = window.FFC.ajax({ action: 'do_thing', data: { x: 1 }, method: 'GET' });
		expect(ret).toEqual({ id: 'jqxhr' });
		const opts = ajaxSpy.mock.calls[0][0];
		expect(opts.url).toBe('/wp-admin/admin-ajax.php');
		expect(opts.type).toBe('GET');
		expect(opts.data).toEqual({ x: 1, action: 'do_thing', nonce: 'core-nonce' });
	});

	it('omits the nonce when includeNonce is false', async () => {
		await load();
		const ajaxSpy = vi.spyOn(window.jQuery, 'ajax').mockReturnValue({});

		window.FFC.ajax({ action: 'no_nonce', includeNonce: false });
		const opts = ajaxSpy.mock.calls[0][0];
		expect(opts.data.action).toBe('no_nonce');
		expect(opts.data.nonce).toBeUndefined();
	});

	it("default error callback routes through FFC.error", async () => {
		await load();
		const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
		const ajaxSpy = vi.spyOn(window.jQuery, 'ajax').mockReturnValue({});

		window.FFC.ajax({ action: 'fails' });
		const opts = ajaxSpy.mock.calls[0][0];
		// Invoke the default error handler the helper installed.
		opts.error({}, 'error', 'Server 500');
		expect(errSpy).toHaveBeenCalledWith('[FFC Error]', 'AJAX request failed', expect.objectContaining({ action: 'fails' }));
	});
});

describe('ffc-core.js — toggleFields()', () => {
	it('warns and bails when trigger or target is missing', async () => {
		await load();
		const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
		window.FFC.toggleFields('#nope-trigger', '#nope-target');
		expect(warnSpy).toHaveBeenCalledWith('[FFC Warning]', 'toggleFields: trigger or target not found');
	});

	it('shows/hides the target as a checkbox toggles', async () => {
		await load();
		document.body.innerHTML = `
			<input type="checkbox" id="cb" />
			<div id="tgt">content</div>
		`;
		window.FFC.toggleFields('#cb', '#tgt');
		// Unchecked initial → hidden.
		expect(window.$('#tgt').css('display')).toBe('none');

		window.$('#cb').prop('checked', true).trigger('change');
		expect(window.$('#tgt').css('display')).not.toBe('none');
	});

	it('uses the slide path and respects invertLogic on a select', async () => {
		await load();
		document.body.innerHTML = `
			<select id="sel"><option value="a">a</option><option value="b">b</option></select>
			<div id="tgt2">content</div>
		`;
		// invertLogic: hide when value === 'a' (first option / showValue), show otherwise.
		window.FFC.toggleFields('#sel', '#tgt2', 'a', { useSlide: true, invertLogic: true });
		// Current value 'a' matches showValue, inverted → hidden.
		expect(window.$('#tgt2').css('display')).toBe('none');

		window.$('#sel').val('b').trigger('change');
		expect(window.$('#tgt2').css('display')).not.toBe('none');
	});
});

describe('ffc-core.js — delegated [data-confirm] guard', () => {
	it('prevents default when confirm() is cancelled, allows it when accepted', async () => {
		await load();
		document.body.innerHTML = '<a href="#" data-confirm="Sure?" id="danger">x</a>';

		const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
		let ev = window.$.Event('click');
		window.$('#danger').trigger(ev);
		expect(confirmSpy).toHaveBeenCalledWith('Sure?');
		expect(ev.isDefaultPrevented()).toBe(true);

		confirmSpy.mockReturnValue(true);
		ev = window.$.Event('click');
		window.$('#danger').trigger(ev);
		expect(ev.isDefaultPrevented()).toBe(false);
	});
});
