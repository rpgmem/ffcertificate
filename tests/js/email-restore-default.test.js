// Tests for `assets/js/ffc-email-restore-default.js`.
//
// Generic "Restore Default Text" button: restores a wp_editor's content to the
// localized default (after a confirm), in both TinyMCE and textarea modes.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-email-restore-default.js';

async function loadOnReady() {
	loadScript(SCRIPT);
	await new Promise((r) => setTimeout(r, 0));
}

function installDom() {
	document.body.innerHTML = `
		<button type="button" class="ffc-email-restore-default" data-editor="ffc_rs_body" data-default-key="recruitment_body">Restore</button>
		<textarea id="ffc_rs_body">edited by user</textarea>
	`;
}

beforeEach(() => {
	window.$.fx.off = true;
	document.body.innerHTML = '';
	window.ffcEmailRestoreDefaults = {
		recruitment_body: { body: 'DEFAULT_TEMPLATE', confirm: 'Sure?' },
	};
	window.confirm = () => true;
});

afterEach(() => {
	delete window.ffcEmailRestoreDefaults;
	delete window.tinymce;
	delete window.confirm;
});

describe('ffc-email-restore-default', () => {
	it('no-ops (no error) when no button is present', async () => {
		document.body.innerHTML = '<div>nothing here</div>';
		await expect(loadOnReady()).resolves.toBeUndefined();
	});

	it('writes the default into the textarea when confirmed (Text mode)', async () => {
		installDom();
		await loadOnReady();
		window.$('.ffc-email-restore-default').trigger('click');
		expect(window.$('#ffc_rs_body').val()).toBe('DEFAULT_TEMPLATE');
	});

	it('does nothing when the confirm is cancelled', async () => {
		installDom();
		window.confirm = () => false;
		await loadOnReady();
		window.$('.ffc-email-restore-default').trigger('click');
		expect(window.$('#ffc_rs_body').val()).toBe('edited by user');
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
		window.$('.ffc-email-restore-default').trigger('click');
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
		window.$('.ffc-email-restore-default').trigger('click');
		expect(window.$('#ffc_rs_body').val()).toBe('DEFAULT_TEMPLATE');
	});
});
