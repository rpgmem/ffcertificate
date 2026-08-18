// Tests for `assets/js/ffc-code-editor-core.js` — the shared CodeMirror
// initializer `window.FFCCodeEditor.init()` reused by the form-editor wrapper
// and the appointment-receipt tab.
//
// Real CodeMirror can't run in jsdom, so these exercise the pure wiring the
// core owns: bail on absent textarea, the disabled-notice fallback, the
// placeholder overlay + theme wrapper class on the happy path, the
// change/submit → textarea sync, and the opt-out of the overlay/notice.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function reset() {
	document.body.innerHTML = '';
	delete window.FFCCodeEditor;
	delete window.wp;
}

function loadCore() {
	loadScript('assets/js/ffc-code-editor-core.js');
}

function fakeEditor() {
	const handlers = {};
	const cm = {
		addOverlay: vi.fn(),
		on: vi.fn((evt, fn) => {
			handlers[evt] = fn;
		}),
		save: vi.fn(),
		getWrapperElement: () => document.createElement('div'),
		setOption: vi.fn(),
		_fire: (evt) => handlers[evt] && handlers[evt](),
	};
	return cm;
}

describe('ffc-code-editor-core.js — init()', () => {
	beforeEach(reset);

	it('exposes window.FFCCodeEditor.init', () => {
		loadCore();
		expect(typeof window.FFCCodeEditor.init).toBe('function');
	});

	it('returns null and does nothing when the textarea is absent', () => {
		loadCore();
		expect(window.FFCCodeEditor.init('missing', { codemirror: {} }, {})).toBeNull();
	});

	it('renders the disabled notice when settings are falsy', () => {
		document.body.innerHTML = '<form><textarea id="ta">x</textarea></form>';
		loadCore();

		const cm = window.FFCCodeEditor.init('ta', null, {
			notice: {
				strings: { syntaxDisabledNotice: 'Disabled.', openProfile: 'Open' },
				profileUrl: 'https://example.test/profile',
			},
		});

		expect(cm).toBeNull();
		const notice = document.querySelector('.ffc-code-editor-notice');
		expect(notice).not.toBeNull();
		expect(notice.textContent).toContain('Disabled.');
		expect(notice.querySelector('a').getAttribute('href')).toBe(
			'https://example.test/profile'
		);
	});

	it('does not render a notice twice for the same textarea', () => {
		document.body.innerHTML = '<form><textarea id="ta">x</textarea></form>';
		loadCore();
		const notice = { strings: { syntaxDisabledNotice: 'Disabled.' } };

		window.FFCCodeEditor.init('ta', null, { notice });
		window.FFCCodeEditor.init('ta', null, { notice });

		expect(document.querySelectorAll('.ffc-code-editor-notice').length).toBe(1);
	});

	it('adds the placeholder overlay and theme wrapper class on the happy path', () => {
		document.body.innerHTML =
			'<form><div class="ffc-code-editor-wrapper"><textarea id="ta">x</textarea></div></form>';
		const cm = fakeEditor();
		window.wp = { codeEditor: { initialize: vi.fn(() => ({ codemirror: cm })) } };
		loadCore();

		const settings = { codemirror: { lineNumbers: true } };
		const ret = window.FFCCodeEditor.init('ta', settings, { theme: 'dark' });

		expect(window.wp.codeEditor.initialize).toHaveBeenCalledWith('ta', settings);
		expect(cm.addOverlay).toHaveBeenCalled();
		expect(
			document
				.querySelector('.ffc-code-editor-wrapper')
				.classList.contains('ffc-code-editor-theme-dark')
		).toBe(true);
		expect(ret).toBe(cm);
	});

	it('skips the overlay when placeholderOverlay is false', () => {
		document.body.innerHTML = '<form><textarea id="ta">x</textarea></form>';
		const cm = fakeEditor();
		window.wp = { codeEditor: { initialize: () => ({ codemirror: cm }) } };
		loadCore();

		window.FFCCodeEditor.init('ta', { codemirror: {} }, { placeholderOverlay: false });

		expect(cm.addOverlay).not.toHaveBeenCalled();
	});

	it('syncs the textarea on change and on form submit', () => {
		document.body.innerHTML = '<form id="f"><textarea id="ta">x</textarea></form>';
		const cm = fakeEditor();
		window.wp = { codeEditor: { initialize: () => ({ codemirror: cm }) } };
		loadCore();

		window.FFCCodeEditor.init('ta', { codemirror: {} }, {});

		cm._fire('change');
		expect(cm.save).toHaveBeenCalledTimes(1);

		window.$('#f').trigger('submit');
		expect(cm.save).toHaveBeenCalledTimes(2);
	});

	it('renders the notice when initialize() throws', () => {
		document.body.innerHTML = '<form><textarea id="ta">x</textarea></form>';
		window.wp = {
			codeEditor: {
				initialize: () => {
					throw new Error('boom');
				},
			},
		};
		loadCore();

		const cm = window.FFCCodeEditor.init('ta', { codemirror: {} }, {
			notice: { strings: { syntaxDisabledNotice: 'Disabled.' } },
		});

		expect(cm).toBeNull();
		expect(document.querySelector('.ffc-code-editor-notice')).not.toBeNull();
	});

	it('returns null without a notice when initialize() yields nothing usable', () => {
		document.body.innerHTML = '<form><textarea id="ta">x</textarea></form>';
		window.wp = { codeEditor: { initialize: () => null } };
		loadCore();

		const cm = window.FFCCodeEditor.init('ta', { codemirror: {} }, {
			notice: { strings: { syntaxDisabledNotice: 'Disabled.' } },
		});

		expect(cm).toBeNull();
		expect(document.querySelector('.ffc-code-editor-notice')).toBeNull();
	});
});
