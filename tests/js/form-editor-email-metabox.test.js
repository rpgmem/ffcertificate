// Tests for `assets/js/ffc-form-editor-email-metabox.js`.
//
// The "Restore Default Text" button replaces the certificate email body with
// the default template (from wp_localize_script), after a confirm, in both
// TinyMCE (Visual) and plain-textarea (Text) modes.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-form-editor-email-metabox.js';

async function loadOnReady() {
	loadScript(SCRIPT);
	await new Promise((r) => setTimeout(r, 0));
}

function installDom() {
	document.body.innerHTML = `
		<button type="button" id="ffc-restore-default-email-body">Restore</button>
		<textarea id="ffc_email_body">edited by user</textarea>
	`;
}

beforeEach(() => {
	window.$.fx.off = true;
	document.body.innerHTML = '';
	window.ffcEmailBodyDefault = { body: 'DEFAULT_TEMPLATE', confirm: 'Sure?' };
	window.confirm = () => true;
});

afterEach(() => {
	delete window.ffcEmailBodyDefault;
	delete window.tinymce;
	delete window.confirm;
});

describe('ffc-form-editor-email-metabox — restore default', () => {
	it('no-ops (no error) when the button is absent', async () => {
		document.body.innerHTML = '<div>nothing here</div>';
		await expect(loadOnReady()).resolves.toBeUndefined();
	});

	it('writes the default into the textarea when confirmed (Text mode)', async () => {
		installDom();
		await loadOnReady();
		window.$('#ffc-restore-default-email-body').trigger('click');
		expect(window.$('#ffc_email_body').val()).toBe('DEFAULT_TEMPLATE');
	});

	it('does nothing when the confirm is cancelled', async () => {
		installDom();
		window.confirm = () => false;
		await loadOnReady();
		window.$('#ffc-restore-default-email-body').trigger('click');
		expect(window.$('#ffc_email_body').val()).toBe('edited by user');
	});

	it('uses the active TinyMCE editor when present (Visual mode)', async () => {
		installDom();
		let setTo = null;
		let saved = false;
		window.tinymce = {
			get: () => ({
				isHidden: () => false,
				setContent: (v) => {
					setTo = v;
				},
				save: () => {
					saved = true;
				},
			}),
		};
		await loadOnReady();
		window.$('#ffc-restore-default-email-body').trigger('click');
		expect(setTo).toBe('DEFAULT_TEMPLATE');
		expect(saved).toBe(true);
	});

	it('falls back to the textarea when TinyMCE is hidden (Text mode)', async () => {
		installDom();
		window.tinymce = {
			get: () => ({
				isHidden: () => true,
				setContent: () => {
					throw new Error('should not be called when hidden');
				},
				save: () => {},
			}),
		};
		await loadOnReady();
		window.$('#ffc-restore-default-email-body').trigger('click');
		expect(window.$('#ffc_email_body').val()).toBe('DEFAULT_TEMPLATE');
	});
});
