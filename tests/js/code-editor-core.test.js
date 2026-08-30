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

	// The guard is `!settings || typeof window.wp === 'undefined' || !wp.codeEditor`.
	// Only the first disjunct was covered, and it short-circuits before the other
	// two are ever evaluated — so a regression in the wp probe would not have been
	// noticed. These pass settings that ARE valid, which is what forces the probe
	// to run. Same class as the #989 email-editor bug: a test that supplies the
	// global can never see the code fail without it.
	it('renders the disabled notice when wp is absent despite valid settings', () => {
		document.body.innerHTML = '<form><textarea id="ta">x</textarea></form>';
		loadCore();
		expect(window.wp).toBeUndefined();

		const cm = window.FFCCodeEditor.init('ta', { codemirror: { lineNumbers: true } }, {
			notice: { strings: { syntaxDisabledNotice: 'Disabled.' } },
		});

		expect(cm).toBeNull();
		expect(document.querySelector('.ffc-code-editor-notice').textContent).toContain('Disabled.');
	});

	it('renders the disabled notice when wp exists without codeEditor', () => {
		document.body.innerHTML = '<form><textarea id="ta">x</textarea></form>';
		window.wp = {};
		loadCore();

		const cm = window.FFCCodeEditor.init('ta', { codemirror: {} }, {
			notice: { strings: { syntaxDisabledNotice: 'Disabled.' } },
		});

		expect(cm).toBeNull();
		expect(document.querySelector('.ffc-code-editor-notice').textContent).toContain('Disabled.');
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

describe('ffc-code-editor-core.js — DOM helpers (flush/setContent/insertAtCursor)', () => {
	beforeEach(reset);

	// Insert a fake `.CodeMirror` sibling after the textarea, mimicking what
	// wp.codeEditor mounts, exposing the CodeMirror instance on `el.CodeMirror`.
	function mountFakeCm($ta, instance) {
		const el = document.createElement('div');
		el.className = 'CodeMirror';
		el.CodeMirror = instance;
		$ta[0].after(el);
	}

	it('flush() saves the mounted CodeMirror buffer', () => {
		document.body.innerHTML = '<form><textarea id="ta">x</textarea></form>';
		loadCore();
		const save = vi.fn();
		mountFakeCm(window.$('#ta'), { save });

		window.FFCCodeEditor.flush(window.$('#ta'));
		expect(save).toHaveBeenCalledTimes(1);
	});

	it('flush() is a no-op when no editor is mounted', () => {
		document.body.innerHTML = '<form><textarea id="ta">x</textarea></form>';
		loadCore();
		expect(() => window.FFCCodeEditor.flush(window.$('#ta'))).not.toThrow();
	});

	it('setContent() writes through CodeMirror when mounted', () => {
		document.body.innerHTML = '<form><textarea id="ta"></textarea></form>';
		loadCore();
		const setValue = vi.fn();
		const save = vi.fn();
		const $ta = window.$('#ta');
		mountFakeCm($ta, { setValue, save });
		const changed = vi.fn();
		$ta.on('change', changed);

		window.FFCCodeEditor.setContent($ta, '<h1>hi</h1>');

		expect(setValue).toHaveBeenCalledWith('<h1>hi</h1>');
		expect(save).toHaveBeenCalled();
		expect(changed).toHaveBeenCalled();
	});

	it('setContent() falls back to the plain textarea when no editor is mounted', () => {
		document.body.innerHTML = '<form><textarea id="ta">old</textarea></form>';
		loadCore();
		const $ta = window.$('#ta');

		window.FFCCodeEditor.setContent($ta, 'new');

		expect($ta.val()).toBe('new');
	});

	it('insertAtCursor() replaces the selection via CodeMirror when mounted', () => {
		document.body.innerHTML = '<form><textarea id="ta">x</textarea></form>';
		loadCore();
		const replaceSelection = vi.fn();
		const save = vi.fn();
		const focus = vi.fn();
		mountFakeCm(window.$('#ta'), { replaceSelection, save, focus });

		window.FFCCodeEditor.insertAtCursor(window.$('#ta'), '<img>');

		expect(replaceSelection).toHaveBeenCalledWith('<img>');
		expect(save).toHaveBeenCalled();
		expect(focus).toHaveBeenCalled();
	});

	it('insertAtCursor() appends to the textarea when no editor is mounted', () => {
		document.body.innerHTML = '<form><textarea id="ta">start</textarea></form>';
		loadCore();
		const $ta = window.$('#ta');

		window.FFCCodeEditor.insertAtCursor($ta, '-end');

		expect($ta.val()).toBe('start-end');
	});
});
