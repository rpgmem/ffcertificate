// Tests for `assets/js/ffc-admin-code-editor.js`.
//
// Wraps the WordPress code-editor (CodeMirror) around #ffc_pdf_layout
// when wp_enqueue_code_editor() provided settings. Falls back to a
// "syntax highlighting disabled" notice otherwise.
//
// We can't run the real CodeMirror in jsdom, so the tests focus on:
//   - bail when #ffc_pdf_layout is absent;
//   - disabled-notice path (no window.wp.codeEditor);
//   - CodeMirror init path uses the provided settings;
//   - submit handler invokes cm.save() so the textarea carries the
//     latest editor content.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

function reset() {
	document.body.innerHTML = '';
	delete window.ffcCodeEditor;
	delete window.wp;
}

async function loadOnReady() {
	loadScript('assets/js/ffc-admin-code-editor.js');
	// $( handler ) defers to a microtask in jQuery 4 even when ready.
	await new Promise((r) => setTimeout(r, 0));
}

describe('ffc-admin-code-editor.js — bail paths', () => {
	beforeEach(reset);

	it('does not throw when #ffc_pdf_layout is absent', async () => {
		await expect(loadOnReady()).resolves.not.toThrow();
	});

	it('renders the disabled notice when ffcCodeEditor.enabled is false', async () => {
		document.body.innerHTML =
			'<form><textarea id="ffc_pdf_layout">html</textarea></form>';
		window.ffcCodeEditor = {
			enabled: false,
			settings: {},
			strings: {
				syntaxDisabledNotice: 'Syntax highlighting is disabled.',
				openProfile: 'Open profile',
			},
			profileUrl: 'https://example.test/profile',
		};

		await loadOnReady();

		const notice = document.querySelector('.ffc-code-editor-notice');
		expect(notice).not.toBeNull();
		expect(notice.textContent).toContain('Syntax highlighting is disabled.');
		expect(notice.querySelector('a').getAttribute('href')).toBe(
			'https://example.test/profile'
		);
	});

	it('renders the disabled notice when window.wp.codeEditor is missing', async () => {
		document.body.innerHTML =
			'<form><textarea id="ffc_pdf_layout">html</textarea></form>';
		window.ffcCodeEditor = {
			enabled: true,
			settings: { codemirror: {} },
			strings: { syntaxDisabledNotice: 'Disabled.' },
		};

		await loadOnReady();

		expect(document.querySelector('.ffc-code-editor-notice')).not.toBeNull();
	});

	it('renders the disabled notice when initialize() throws', async () => {
		document.body.innerHTML =
			'<form><textarea id="ffc_pdf_layout">html</textarea></form>';
		window.ffcCodeEditor = {
			enabled: true,
			settings: { codemirror: {} },
			strings: { syntaxDisabledNotice: 'Disabled.' },
		};
		window.wp = {
			codeEditor: {
				initialize: () => {
					throw new Error('boom');
				},
			},
		};

		await loadOnReady();

		expect(document.querySelector('.ffc-code-editor-notice')).not.toBeNull();
	});
});

describe('ffc-admin-code-editor.js — CodeMirror init path', () => {
	beforeEach(reset);

	it('initializes CodeMirror with the provided settings', async () => {
		document.body.innerHTML =
			'<form><div class="ffc-code-editor-wrapper"><textarea id="ffc_pdf_layout">html</textarea></div></form>';
		const initialize = vi.fn(() => ({
			codemirror: {
				addOverlay: vi.fn(),
				on: vi.fn(),
				save: vi.fn(),
			},
		}));
		window.ffcCodeEditor = {
			enabled: true,
			settings: { codemirror: { lineNumbers: true } },
			theme: 'dark',
			strings: {},
		};
		window.wp = { codeEditor: { initialize } };

		await loadOnReady();

		expect(initialize).toHaveBeenCalledWith(
			'ffc_pdf_layout',
			window.ffcCodeEditor.settings
		);
		expect(
			document
				.querySelector('.ffc-code-editor-wrapper')
				.classList.contains('ffc-code-editor-theme-dark')
		).toBe(true);
	});

	it('syncs the textarea on form submit via cm.save()', async () => {
		document.body.innerHTML =
			'<form id="f"><textarea id="ffc_pdf_layout">html</textarea></form>';
		const save = vi.fn();
		window.ffcCodeEditor = {
			enabled: true,
			settings: { codemirror: {} },
			strings: {},
		};
		window.wp = {
			codeEditor: {
				initialize: () => ({
					codemirror: { addOverlay: vi.fn(), on: vi.fn(), save },
				}),
			},
		};

		await loadOnReady();

		window.$('#f').trigger('submit');

		expect(save).toHaveBeenCalled();
	});

	it('bails silently when initialize() returns nothing usable', async () => {
		document.body.innerHTML =
			'<form><textarea id="ffc_pdf_layout"></textarea></form>';
		window.ffcCodeEditor = {
			enabled: true,
			settings: { codemirror: {} },
			strings: {},
		};
		window.wp = {
			codeEditor: { initialize: () => null },
		};

		await expect(loadOnReady()).resolves.not.toThrow();
		expect(document.querySelector('.ffc-code-editor-notice')).toBeNull();
	});
});
