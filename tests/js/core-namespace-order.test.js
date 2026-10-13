// `ffc-core.js` must install its API even when a script printed before it
// already created `window.FFC` for its own module (#1739). Eleven scripts
// open with `window.FFC = window.FFC || {}`; when one of them loaded first,
// the core used to find the name taken, skip its object, and leave
// `FFC.request` undefined — every certificate form submit died on it.
import { describe, it, expect, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

describe('ffc-core.js after another FFC script', () => {
	beforeEach(() => {
		delete window.FFC;
		window.ffcFormFields = { strings: {} };
	});

	it('installs the core API and keeps what the earlier script put there', () => {
		loadScript('assets/js/ffc-form-fields.js');
		const earlier = window.FFC.FormFields;
		expect(earlier).toBeTruthy();
		expect(window.FFC.request).toBeUndefined();

		loadScript('assets/js/ffc-core.js');

		expect(typeof window.FFC.request).toBe('function');
		expect(typeof window.FFC.registerModule).toBe('function');
		expect(window.FFC.FormFields).toBe(earlier);
	});

	it('keeps a module registered before the core loaded', () => {
		window.FFC = { _modules: [{ name: 'Early', version: '1' }] };

		loadScript('assets/js/ffc-core.js');
		window.FFC.registerModule('Late', '2');

		expect(window.FFC.getModules().map((m) => m.name)).toEqual(['Early', 'Late']);
	});

	it('still works when nothing came first', () => {
		loadScript('assets/js/ffc-core.js');
		expect(typeof window.FFC.request).toBe('function');
		expect(window.FFC.getModules()).toEqual([]);
	});
});
