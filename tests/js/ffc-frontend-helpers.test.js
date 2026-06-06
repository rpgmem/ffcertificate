// Tests for the public helpers exposed by:
//   - assets/js/ffc-core.js              → window.FFC.{getString, log, ajax, ...}
//   - assets/js/ffc-frontend-helpers.js  → window.FFC.Frontend.{Validation, Masks, UI}
//
// Both modules already publish their helpers on the FFC global namespace,
// so this commit is a pure "test in place" pass — no extraction needed,
// contrary to what Sprint E of #170 originally scoped.
//
// Sprint E of #170.
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

beforeAll(() => {
	// ffc-core.js needs `window.ffc_ajax` (and friends) set for `getString`
	// / `getAjaxUrl`. The actual values don't matter — just their presence.
	window.ffc_ajax = {
		ajax_url: '/wp-admin/admin-ajax.php',
		nonce: 'test-nonce',
		strings: {
			greeting: 'Hello',
		},
	};
	loadScript('assets/js/ffc-core.js');
	loadScript('assets/js/ffc-frontend-helpers.js');
});

beforeEach(() => {
	document.body.innerHTML = '';
	// Disable jQuery animation queueing so fadeIn/fadeOut apply synchronously
	// (jsdom has no rAF clock the way a browser does).
	if (window.$) { window.$.fx.off = true; }
});

// Always restore real timers between tests so a fake-timer test that throws
// mid-body can't leak its clock into the next test (which would hang on a
// real `setTimeout`).
afterEach(() => {
	vi.useRealTimers();
});

// ----------------------------------------------------------------------
// window.FFC core helpers
// ----------------------------------------------------------------------

describe('FFC.getString', () => {
	it('returns the localized value when the key exists', () => {
		expect(window.FFC.getString('greeting', 'Hi')).toBe('Hello');
	});

	it('returns the default when the key is missing', () => {
		expect(window.FFC.getString('absentKey', 'fallback')).toBe('fallback');
	});

	it('returns the default when `ffc_ajax.strings` is missing entirely', () => {
		const saved = window.ffc_ajax;
		window.ffc_ajax = { ajax_url: '/x', nonce: 'n' }; // no strings
		try {
			expect(window.FFC.getString('any', 'def')).toBe('def');
		} finally {
			window.ffc_ajax = saved;
		}
	});
});

describe('FFC.getAjaxUrl / getNonce', () => {
	it('returns the ajax_url from the localized object', () => {
		expect(window.FFC.getAjaxUrl()).toBe('/wp-admin/admin-ajax.php');
	});

	it('returns the nonce from the localized object', () => {
		expect(window.FFC.getNonce()).toBe('test-nonce');
	});
});

describe('FFC.isModuleLoaded', () => {
	it('returns true when FFC.<module> exists', () => {
		// FFC.Frontend was registered by ffc-frontend-helpers.js.
		expect(window.FFC.isModuleLoaded('Frontend')).toBe(true);
	});

	it('returns false when the module is not registered', () => {
		expect(window.FFC.isModuleLoaded('Nonexistent')).toBe(false);
	});
});

// ----------------------------------------------------------------------
// FFC.Frontend.Validation
// ----------------------------------------------------------------------

describe('FFC.Frontend.Validation.validateCPF', () => {
	const V = () => window.FFC.Frontend.Validation;

	it('accepts a valid CPF (with formatting)', () => {
		// 529.982.247-25 is a known-valid test CPF.
		expect(V().validateCPF('529.982.247-25')).toBe(true);
	});

	it('accepts a valid CPF (digits only)', () => {
		expect(V().validateCPF('52998224725')).toBe(true);
	});

	it('rejects a CPF that is too short', () => {
		expect(V().validateCPF('12345')).toBe(false);
	});

	it('rejects all-same-digit CPFs (e.g. 111.111.111-11)', () => {
		expect(V().validateCPF('11111111111')).toBe(false);
		expect(V().validateCPF('00000000000')).toBe(false);
	});

	it('rejects a CPF with an invalid first check digit', () => {
		expect(V().validateCPF('52998224715')).toBe(false); // last 2 digits wrong
	});

	it('rejects a CPF with an invalid second check digit', () => {
		expect(V().validateCPF('52998224724')).toBe(false); // last digit off by 1
	});

	it('accepts a valid CPF whose second check digit collapses from >=10 to 0', () => {
		// 100.000.002-80 is valid and its second digit calc yields >=10 → 0
		// (exercises the `if (digit2 >= 10) digit2 = 0` branch).
		expect(V().validateCPF('10000000280')).toBe(true);
	});
});

describe('FFC.Frontend.Validation.validateRF', () => {
	const V = () => window.FFC.Frontend.Validation;

	it('accepts a 7-digit RF', () => {
		expect(V().validateRF('1234567')).toBe(true);
	});

	it('strips formatting before validating', () => {
		expect(V().validateRF('123.456-7')).toBe(true);
	});

	it('rejects an RF that is too short', () => {
		expect(V().validateRF('123')).toBe(false);
	});

	it('rejects an RF that is too long', () => {
		expect(V().validateRF('12345678')).toBe(false);
	});

	it('rejects an RF with letters mixed in (after digit-stripping it must be 7 digits)', () => {
		expect(V().validateRF('abc1234')).toBe(false); // only 4 digits after strip
	});
});

describe('FFC.Frontend.Validation.validateEmail', () => {
	const V = () => window.FFC.Frontend.Validation;

	it('accepts a well-formed email', () => {
		expect(V().validateEmail('user@example.com')).toBe(true);
		expect(V().validateEmail('first.last+tag@sub.domain.co')).toBe(true);
	});

	it('rejects emails missing the @ sign', () => {
		expect(V().validateEmail('userexample.com')).toBe(false);
	});

	it('rejects emails missing the local part', () => {
		expect(V().validateEmail('@example.com')).toBe(false);
	});

	it('rejects emails missing the TLD', () => {
		expect(V().validateEmail('user@example')).toBe(false);
	});

	it('rejects emails with whitespace', () => {
		expect(V().validateEmail('user @example.com')).toBe(false);
	});
});

// ----------------------------------------------------------------------
// FFC.Frontend.UI — DOM injection of error / success
// ----------------------------------------------------------------------

describe('FFC.Frontend.UI.showFormError', () => {
	const U = () => window.FFC.Frontend.UI;

	beforeEach(() => {
		document.body.innerHTML = '<form id="f"><input name="x" /></form>';
	});

	it('injects an error notice into the form', () => {
		U().showFormError(window.$('#f'), 'Something went wrong');
		const notice = document.querySelector('#f .ffc-message-error, #f .ffc-error-message');
		// Different versions of the plugin use one of two class names. Just
		// assert SOMETHING was injected with the message text.
		expect(document.querySelector('#f').textContent).toContain('Something went wrong');
	});

	it('replaces a previous error rather than stacking', () => {
		U().showFormError(window.$('#f'), 'first');
		U().showFormError(window.$('#f'), 'second');
		const form = document.querySelector('#f');
		expect(form.textContent).toContain('second');
		expect(form.textContent).not.toContain('first');
	});

	it('auto-removes the error notice after 10 seconds', () => {
		vi.useFakeTimers();
		U().showFormError(window.$('#f'), 'Transient');
		expect(window.$('#f .ffc-form-error').length).toBe(1);
		// $.fx.off is true → the fadeOut callback removes the node synchronously
		// once the 10s timer fires.
		vi.advanceTimersByTime(10000);
		expect(window.$('#f .ffc-form-error').length).toBe(0);
		vi.useRealTimers();
	});
});

describe('FFC.Frontend.RateLimit._formatRemaining', () => {
	it('returns 0:00 for non-positive remaining', () => {
		expect(window.FFC.Frontend.RateLimit._formatRemaining(0)).toBe('0:00');
		expect(window.FFC.Frontend.RateLimit._formatRemaining(-5)).toBe('0:00');
	});

	it('formats minutes and zero-padded seconds', () => {
		expect(window.FFC.Frontend.RateLimit._formatRemaining(75)).toBe('1:15');
		expect(window.FFC.Frontend.RateLimit._formatRemaining(5)).toBe('0:05');
	});
});

describe('FFC.Frontend.UI.showFormSuccess', () => {
	const U = () => window.FFC.Frontend.UI;

	beforeEach(() => {
		document.body.innerHTML = '<form id="f"><input name="x" /></form>';
	});

	it('replaces the form HTML with the success payload', () => {
		U().showFormSuccess(window.$('#f'), '<div class="ok">Success!</div>');
		expect(document.querySelector('#f .ok')).not.toBeNull();
		expect(document.querySelector('#f').textContent).toContain('Success!');
	});
});

// ----------------------------------------------------------------------
// FFC.Frontend.Masks — input formatting
// ----------------------------------------------------------------------

describe('FFC.Frontend.Masks', () => {
	const M = () => window.FFC.Frontend.Masks;

	beforeEach(() => {
		document.body.innerHTML = '<input id="t" type="text" />';
	});

	it('applyCpfRf binds an input handler that masks CPF format (XXX.XXX.XXX-XX)', async () => {
		const $i = window.$('#t');
		M().applyCpfRf($i);
		$i.val('52998224725').trigger('input');
		await new Promise((r) => setTimeout(r, 0));
		// CPF mask: 529.982.247-25
		expect($i.val()).toBe('529.982.247-25');
	});

	it('applyAuthCode binds an input handler that masks auth-code (NNNN-NNNN)', async () => {
		const $i = window.$('#t');
		M().applyAuthCode($i);
		// Auth codes are typically 8 chars, alpha-num.
		$i.val('ABCD1234').trigger('input');
		await new Promise((r) => setTimeout(r, 0));
		// Mask inserts a dash at the midpoint: ABCD-1234.
		expect($i.val()).toBe('ABCD-1234');
	});

	it('applyAuthCode leaves a 4-or-fewer-char code unmasked', () => {
		const $i = window.$('#t');
		M().applyAuthCode($i);
		$i.val('AB1').trigger('input');
		expect($i.val()).toBe('AB1');
	});

	it('applyAuthCode masks a 12-char code as XXXX-XXXX-XXXX', () => {
		const $i = window.$('#t');
		M().applyAuthCode($i);
		$i.val('ABCD1234EFGH').trigger('input');
		expect($i.val()).toBe('ABCD-1234-EFGH');
	});

	it('applyAuthCode detects a prefix and clamps the code to 12 chars', () => {
		const $i = window.$('#t');
		M().applyAuthCode($i);
		// Leading "C" prefix + 13 code chars (>12 total) → prefix split out,
		// code clamped to 12 → "C-XXXX-XXXX-XXXX".
		$i.val('C1234567890123').trigger('input');
		expect($i.val()).toBe('C-1234-5678-9012');
	});

	it('applyAuthCode applies the mask to a pre-filled initial value', () => {
		document.body.innerHTML = '<input id="t" type="text" value="WXYZ7890" />';
		const $i = window.$('#t');
		M().applyAuthCode($i);
		expect($i.val()).toBe('WXYZ-7890');
	});

	it('applyAuthCode re-masks shortly after a paste event', () => {
		vi.useFakeTimers();
		const $i = window.$('#t');
		M().applyAuthCode($i);
		$i.val('ABCD1234');
		$i.trigger('paste');
		vi.advanceTimersByTime(10);
		expect($i.val()).toBe('ABCD-1234');
		vi.useRealTimers();
	});

	it('applyTicket re-masks shortly after a paste event', () => {
		vi.useFakeTimers();
		const $i = window.$('#t');
		M().applyTicket($i);
		$i.val('ABCD1234');
		$i.trigger('paste');
		vi.advanceTimersByTime(10);
		expect($i.val()).toBe('ABCD-1234');
		vi.useRealTimers();
	});
});
