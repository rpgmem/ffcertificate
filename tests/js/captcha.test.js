// Tests for `assets/js/ffc-captcha.js`.
//
// The widget itself is not loaded — jsdom has no custom-element upgrade for a
// 111 KB bundle and the point here is the glue, not ALTCHA. A stand-in element
// records `reset()` calls and emits the `statechange` event the real widget
// emits, which is enough to pin the three behaviours the script owns:
// registering strings, resetting on every server rejection, and turning a bare
// error state into something a person can act on.
import { describe, it, expect, beforeEach, beforeAll, vi } from 'vitest';
import { loadScript } from './helpers.js';

// Loaded once. The module registers document-level listeners, so re-running it
// per test would stack them and make every reset assertion count the loads
// instead of the behaviour — which is exactly what a first draft of this file
// measured. It reads `window.ffcCaptcha` on use, so per-test configuration
// still applies.
beforeAll(() => {
	loadScript('assets/js/ffc-captcha.js');
});

function makeWidget() {
	const el = document.createElement('altcha-widget');
	el.reset = vi.fn();
	el.configure = vi.fn();
	return el;
}

/** The widget dispatches a non-bubbling CustomEvent on itself. */
function emitState(el, state) {
	el.dispatchEvent(new window.CustomEvent('statechange', {
		detail: { state },
		bubbles: false,
	}));
}

beforeEach(() => {
	document.body.innerHTML = '';
	delete window.ffcCaptcha;
	delete window.$altcha;
	// jsdom reports true; individual tests override where it matters.
	Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
});

describe('ffc-captcha — i18n registration', () => {
	it('registers the plugin strings under the configured language', () => {
		const set = vi.fn();
		window.$altcha = { i18n: { set } };
		window.ffcCaptcha = { language: 'pt-br', strings: { label: 'Não sou um robô' } };

		window.FFCCaptcha.registerStrings();

		expect(set).toHaveBeenCalledWith('pt-br', { label: 'Não sou um robô' });
	});

	it('does nothing when the widget bundle has not defined its store', () => {
		// Enqueue order guarantees it has, but a script error in the bundle
		// must not take the page down with a TypeError.
		window.ffcCaptcha = { language: 'pt-br', strings: { label: 'x' } };

		expect(() => window.FFCCaptcha.registerStrings()).not.toThrow();
	});

	it('re-applies the language so the widget resolves it again', () => {
		// The bundle loads first — it has to, since it creates the store this
		// writes to — so each widget has already resolved its language against
		// a store without our entry and fallen back to English. Registering
		// the strings is not enough; the widget has to be told to look again.
		// This is the smoke-test finding: payload, attribute and bundle were
		// all correct and the widget was still in English.
		const el = makeWidget();
		document.body.append(el);
		window.$altcha = { i18n: { set: vi.fn() } };
		window.ffcCaptcha = { language: 'pt-br', strings: { label: 'Não sou um robô' } };

		window.FFCCaptcha.registerStrings();

		expect(el.configure).toHaveBeenCalledWith({ language: 'pt-br' });
	});

	it('does not re-apply when the strings could not be registered', () => {
		// Nothing was added to the store, so asking the widget to resolve
		// again would only make it fall back a second time.
		const el = makeWidget();
		document.body.append(el);
		window.$altcha = { i18n: { set: () => { throw new Error('nope'); } } };
		window.ffcCaptcha = { language: 'pt-br', strings: { label: 'x' } };

		window.FFCCaptcha.registerStrings();

		expect(el.configure).not.toHaveBeenCalled();
	});

	it('does nothing when no strings were localised', () => {
		const set = vi.fn();
		window.$altcha = { i18n: { set } };
		window.ffcCaptcha = {};

		window.FFCCaptcha.registerStrings();

		expect(set).not.toHaveBeenCalled();
	});
});

describe('ffc-captcha — reset on rejection', () => {
	it('resets every widget when the server rejects any request', () => {
		// A solved challenge is spent the moment it reaches the server, so a
		// widget still showing "verified" after a refusal would send a token
		// that can never verify again.
		const a = makeWidget();
		const b = makeWidget();
		document.body.append(a, b);

		document.dispatchEvent(new window.CustomEvent('ffc:request-rejected', {
			detail: { action: 'ffc_submit_form', data: {} },
		}));

		expect(a.reset).toHaveBeenCalledTimes(1);
		expect(b.reset).toHaveBeenCalledTimes(1);
	});

	it('survives an element that has not upgraded yet', () => {
		// Custom elements upgrade asynchronously; a rejection arriving first
		// must not throw inside the listener and skip the widgets after it.
		const raw = document.createElement('altcha-widget');
		const ready = makeWidget();
		document.body.append(raw, ready);

		expect(() => {
			document.dispatchEvent(new window.CustomEvent('ffc:request-rejected', { detail: {} }));
		}).not.toThrow();
		expect(ready.reset).toHaveBeenCalledTimes(1);
	});

	it('exposes the reset for callers that need it directly', () => {
		const el = makeWidget();
		document.body.append(el);

		window.FFCCaptcha.reset();

		expect(el.reset).toHaveBeenCalledTimes(1);
	});
});

describe('ffc-captcha — legible failures', () => {
	it('explains an error state instead of leaving the widget bare', () => {
		const el = makeWidget();
		document.body.append(el);
		window.ffcCaptcha = { errorMessage: 'Não foi possível verificar.' };

		emitState(el, 'error');

		const alert = document.querySelector('.ffc-altcha-error');
		expect(alert).not.toBeNull();
		expect(alert.textContent).toBe('Não foi possível verificar.');
		expect(alert.getAttribute('role')).toBe('alert');
	});

	it('names the real cause when the page is not a secure context', () => {
		// The widget refuses to run outside a secure context, so "try again"
		// is advice that can never work — the message has to say why.
		Object.defineProperty(window, 'isSecureContext', { value: false, configurable: true });
		const el = makeWidget();
		document.body.append(el);
		window.ffcCaptcha = { errorMessage: 'genérico', insecureMessage: 'Precisa de HTTPS.' };

		emitState(el, 'error');

		expect(document.querySelector('.ffc-altcha-error').textContent).toBe('Precisa de HTTPS.');
	});

	it('clears the message once the widget recovers', () => {
		const el = makeWidget();
		document.body.append(el);
		window.ffcCaptcha = { errorMessage: 'falhou' };

		emitState(el, 'error');
		expect(document.querySelector('.ffc-altcha-error')).not.toBeNull();

		emitState(el, 'verified');
		expect(document.querySelector('.ffc-altcha-error')).toBeNull();
	});

	it('does not add one message per error', () => {
		const el = makeWidget();
		document.body.append(el);
		window.ffcCaptcha = { errorMessage: 'falhou' };

		emitState(el, 'error');
		emitState(el, 'error');

		expect(document.querySelectorAll('.ffc-altcha-error')).toHaveLength(1);
	});

	it('ignores a statechange from something that is not a widget', () => {
		const el = makeWidget();
		document.body.append(el);
		const other = document.createElement('div');
		document.body.append(other);
		window.ffcCaptcha = { errorMessage: 'falhou' };

		other.dispatchEvent(new window.CustomEvent('statechange', { detail: { state: 'error' } }));

		expect(document.querySelector('.ffc-altcha-error')).toBeNull();
	});
});
