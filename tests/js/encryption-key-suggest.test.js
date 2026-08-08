// Tests for `assets/js/ffc-encryption-key-suggest.js` (#851).
//
// A plain-DOM IIFE that fills the Encryption Key Health card's read-only
// input with a `define( 'FFC_ENCRYPTION_KEY', '…' )` snippet, regenerates it
// client-side (crypto.getRandomValues), and offers a copy button. The pure
// generator is exposed on `window.FFCEncryptionKeySuggest` for testing.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-encryption-key-suggest.js';

// Chars that must never appear (unsafe in a PHP single-quoted string, or the
// space/backtick we also exclude).
const FORBIDDEN = ["'", '"', '\\', '$', '`', ' '];

function widgetMarkup(constName) {
	return `
		<div class="ffc-key-suggest-widget" data-ffc-const="${constName}">
			<textarea class="ffc-key-suggest-input" readonly rows="8"></textarea>
			<button type="button" class="ffc-key-suggest-regenerate">Generate another</button>
			<button type="button" class="ffc-key-suggest-copy">Copy</button>
			<span class="ffc-key-suggest-status"></span>
		</div>
	`;
}

function installCard(consts = ['FFC_ENCRYPTION_KEY']) {
	document.body.innerHTML =
		'<div class="ffc-encryption-key-suggest">' + consts.map(widgetMarkup).join('') + '</div>';
}

function widget(i = 0) {
	return document.querySelectorAll('.ffc-key-suggest-widget')[i];
}
function inputIn(i = 0) {
	return widget(i).querySelector('.ffc-key-suggest-input');
}

afterEach(() => {
	document.body.innerHTML = '';
	delete window.FFCEncryptionKeySuggest;
	delete window.ffcEncKeySuggest;
	vi.restoreAllMocks();
});

describe('ffc-encryption-key-suggest', () => {
	it('exposes the pure generator even with no card on the page', () => {
		document.body.innerHTML = '<div>no card</div>';
		expect(() => loadScript(SCRIPT)).not.toThrow();
		expect(typeof window.FFCEncryptionKeySuggest.generateKey).toBe('function');
		expect(typeof window.FFCEncryptionKeySuggest.buildSnippet).toBe('function');
	});

	describe('generateKey', () => {
		beforeEach(() => {
			loadScript(SCRIPT);
		});

		it('defaults to a 64-char key', () => {
			const key = window.FFCEncryptionKeySuggest.generateKey();
			expect(key).toHaveLength(64);
			expect(window.FFCEncryptionKeySuggest.KEY_LENGTH).toBe(64);
		});

		it('honours an explicit length', () => {
			expect(window.FFCEncryptionKeySuggest.generateKey(80)).toHaveLength(80);
			// Non-positive length falls back to the default.
			expect(window.FFCEncryptionKeySuggest.generateKey(0)).toHaveLength(64);
		});

		it('only emits safe charset characters', () => {
			const key = window.FFCEncryptionKeySuggest.generateKey(500);
			for (const ch of FORBIDDEN) {
				expect(key.includes(ch)).toBe(false);
			}
			// Every char is from the declared charset.
			const charset = window.FFCEncryptionKeySuggest.CHARSET;
			for (const ch of key) {
				expect(charset.includes(ch)).toBe(true);
			}
		});

		it('produces a different value on each call', () => {
			const a = window.FFCEncryptionKeySuggest.generateKey();
			const b = window.FFCEncryptionKeySuggest.generateKey();
			expect(a).not.toBe(b);
		});
	});

	describe('buildSnippet', () => {
		it('wraps the key in a wp-config define, defaulting to FFC_ENCRYPTION_KEY', () => {
			loadScript(SCRIPT);
			expect(window.FFCEncryptionKeySuggest.buildSnippet('ABC123')).toBe(
				"define( 'FFC_ENCRYPTION_KEY', 'ABC123' );"
			);
		});

		it('honours an explicit constant name (e.g. FFC_HASH_SALT)', () => {
			loadScript(SCRIPT);
			expect(window.FFCEncryptionKeySuggest.buildSnippet('ABC123', 'FFC_HASH_SALT')).toBe(
				"define( 'FFC_HASH_SALT', 'ABC123' );"
			);
		});

		it('prepends a phpdoc comment block when a comment is given', () => {
			loadScript(SCRIPT);
			expect(
				window.FFCEncryptionKeySuggest.buildSnippet('K', 'FFC_HASH_SALT', 'line one\nline two')
			).toBe("/**\n * line one\n * line two\n */\ndefine( 'FFC_HASH_SALT', 'K' );");
		});
	});

	describe('card wiring', () => {
		it('fills the input with a define snippet on load', () => {
			installCard();
			loadScript(SCRIPT);
			expect(inputIn(0).value).toMatch(/^define\( 'FFC_ENCRYPTION_KEY', '.{64}' \);$/);
		});

		it('wires each widget to its own constant (key + salt)', () => {
			installCard(['FFC_ENCRYPTION_KEY', 'FFC_HASH_SALT']);
			loadScript(SCRIPT);
			expect(inputIn(0).value).toMatch(/^define\( 'FFC_ENCRYPTION_KEY', '.{64}' \);$/);
			expect(inputIn(1).value).toMatch(/^define\( 'FFC_HASH_SALT', '.{64}' \);$/);
		});

		it('prepends the localized comment to the snippet when defs are provided', () => {
			installCard(['FFC_ENCRYPTION_KEY']);
			window.ffcEncKeySuggest = { defs: { FFC_ENCRYPTION_KEY: 'purpose line' } };
			loadScript(SCRIPT);
			const value = inputIn(0).value;
			expect(value.startsWith('/**\n * purpose line\n */\n')).toBe(true);
			expect(value).toMatch(/define\( 'FFC_ENCRYPTION_KEY', '.{64}' \);$/);
		});

		it('regenerates a different value when "Generate another" is clicked', () => {
			installCard();
			loadScript(SCRIPT);
			const input = inputIn(0);
			const before = input.value;
			widget(0).querySelector('.ffc-key-suggest-regenerate').click();
			expect(input.value).not.toBe(before);
			expect(input.value).toMatch(/^define\( 'FFC_ENCRYPTION_KEY', '.{64}' \);$/);
		});

		it('reports success feedback when copy succeeds', () => {
			installCard();
			window.ffcEncKeySuggest = { copied: 'Copied!', copyFail: 'Use Ctrl/Cmd+C' };
			document.execCommand = vi.fn(() => true);
			loadScript(SCRIPT);
			widget(0).querySelector('.ffc-key-suggest-copy').click();
			expect(document.execCommand).toHaveBeenCalledWith('copy');
			expect(widget(0).querySelector('.ffc-key-suggest-status').textContent).toBe('Copied!');
		});

		it('reports the fallback message when copy throws', () => {
			installCard();
			document.execCommand = vi.fn(() => {
				throw new Error('unsupported');
			});
			loadScript(SCRIPT);
			widget(0).querySelector('.ffc-key-suggest-copy').click();
			expect(widget(0).querySelector('.ffc-key-suggest-status').textContent).toBe(
				'Press Ctrl/Cmd+C to copy.'
			);
		});
	});
});
