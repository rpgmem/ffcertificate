// Tests for `assets/js/ffc-cert-email-mode.js`.
//
// The per-form certificate email Global/Custom toggle: flipping to Custom
// reveals + seeds the subject/body from the global; flipping to Global clears
// them (behind a confirm) and hides the fields. UI-only — nothing persisted.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-cert-email-mode.js';

async function loadOnReady() {
	loadScript(SCRIPT);
	await new Promise((r) => setTimeout(r, 0));
}

function installDom(opts = {}) {
	const checked = opts.checked ? 'checked' : '';
	const fieldsHidden = opts.checked ? '' : 'style="display:none"';
	const noteHidden = opts.checked ? 'style="display:none"' : '';
	document.body.innerHTML = `
		<input type="checkbox" id="ffc_email_custom_mode" class="ffc-toggle" ${checked}>
		<p class="description ffc-cert-email-global-note" ${noteHidden}>global note</p>
		<div class="ffc-cert-email-custom-fields" ${fieldsHidden}>
			<input type="text" name="ffc_config[email_subject]" value="${opts.subject || ''}">
			<textarea id="ffc_email_body">${opts.body || ''}</textarea>
		</div>
	`;
}

const $ = () => window.$;

beforeEach(() => {
	window.$.fx.off = true;
	document.body.innerHTML = '';
	window.ffcCertEmailGlobal = {
		subject: 'GLOBAL SUBJECT',
		body: '<p>GLOBAL BODY</p>',
		confirmReset: 'Reset?',
	};
	window.confirm = () => true;
});

afterEach(() => {
	delete window.ffcCertEmailGlobal;
	delete window.tinymce;
	delete window.confirm;
});

describe('ffc-cert-email-mode', () => {
	it('no-ops (no error) when the toggle is absent', async () => {
		document.body.innerHTML = '<div>no toggle here</div>';
		await expect(loadOnReady()).resolves.toBeUndefined();
	});

	it('flip to Custom reveals the fields and seeds empties from the global', async () => {
		installDom({ checked: false });
		await loadOnReady();

		$()('#ffc_email_custom_mode').prop('checked', true).trigger('change');

		expect($()('input[name="ffc_config[email_subject]"]').val()).toBe('GLOBAL SUBJECT');
		expect($()('#ffc_email_body').val()).toBe('<p>GLOBAL BODY</p>');
		expect($()('.ffc-cert-email-custom-fields').css('display')).not.toBe('none');
		expect($()('.ffc-cert-email-global-note').css('display')).toBe('none');
	});

	it('flip to Custom does not overwrite existing custom text', async () => {
		installDom({ checked: false, subject: 'kept subject', body: '<p>kept body</p>' });
		await loadOnReady();

		$()('#ffc_email_custom_mode').prop('checked', true).trigger('change');

		expect($()('input[name="ffc_config[email_subject]"]').val()).toBe('kept subject');
		expect($()('#ffc_email_body').val()).toBe('<p>kept body</p>');
	});

	it('flip to Global clears the fields and hides them when confirmed', async () => {
		installDom({ checked: true, subject: 'custom subject', body: '<p>custom</p>' });
		await loadOnReady();

		$()('#ffc_email_custom_mode').prop('checked', false).trigger('change');

		expect($()('input[name="ffc_config[email_subject]"]').val()).toBe('');
		expect($()('#ffc_email_body').val()).toBe('');
		expect($()('.ffc-cert-email-custom-fields').css('display')).toBe('none');
		expect($()('.ffc-cert-email-global-note').css('display')).not.toBe('none');
	});

	it('flip to Global is cancellable — reverts to Custom and keeps the text', async () => {
		installDom({ checked: true, subject: 'custom subject', body: '<p>custom</p>' });
		window.confirm = () => false;
		await loadOnReady();

		$()('#ffc_email_custom_mode').prop('checked', false).trigger('change');

		expect($()('#ffc_email_custom_mode').is(':checked')).toBe(true);
		expect($()('input[name="ffc_config[email_subject]"]').val()).toBe('custom subject');
	});

	it('drives the TinyMCE editor when present (seed on Custom, clear on Global)', async () => {
		installDom({ checked: false });
		let content = '';
		let saved = false;
		window.tinymce = {
			get: () => ({
				getContent: () => content,
				setContent: (v) => {
					content = v;
				},
				save: () => {
					saved = true;
				},
			}),
		};
		await loadOnReady();

		// Flip to Custom: empty editor is seeded with the global body.
		$()('#ffc_email_custom_mode').prop('checked', true).trigger('change');
		expect(content).toBe('<p>GLOBAL BODY</p>');
		expect(saved).toBe(true);

		// Flip to Global: editor is cleared.
		$()('#ffc_email_custom_mode').prop('checked', false).trigger('change');
		expect(content).toBe('');
	});
});
