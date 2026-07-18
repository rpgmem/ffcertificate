// Tests for `assets/js/ffc-email-model.js`.
//
// The Email Model box wires a client-side live preview (into an <iframe>),
// a restore-to-defaults button and a logo clear button. wp-color-picker and
// wp.media are optional (guarded), so these tests run without them.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { loadScript } from './helpers.js';

const SCRIPT = 'assets/js/ffc-email-model.js';

async function loadOnReady() {
	loadScript(SCRIPT);
	await new Promise((r) => setTimeout(r, 0));
}

function installDom() {
	document.body.innerHTML = `
		<div id="ffc-email-model">
			<form class="ffc-email-model-form">
				<input type="text" class="ffc-em-color" data-ffc-model-field="wrapper_bg" value="#f0f0f1">
				<input type="text" class="ffc-em-color" data-ffc-model-field="header_bg" value="#2271b1">
				<input type="text" class="ffc-em-color" data-ffc-model-field="header_text_color" value="#ffffff">
				<input type="text" class="ffc-em-color" data-ffc-model-field="body_bg" value="#ffffff">
				<input type="text" class="ffc-em-color" data-ffc-model-field="body_text_color" value="#333333">
				<input type="text" class="ffc-em-color" data-ffc-model-field="body_link_color" value="#2271b1">
				<input type="text" class="ffc-em-color" data-ffc-model-field="footer_bg" value="#f5f5f5">
				<input type="text" class="ffc-em-color" data-ffc-model-field="footer_text_color" value="#666666">
				<select data-ffc-model-field="header_alignment"><option value="center" selected>c</option></select>
				<select data-ffc-model-field="body_font_family"><option value="system" selected>s</option></select>
				<input type="number" data-ffc-model-field="header_padding" value="24">
				<input type="number" data-ffc-model-field="header_logo_max_width" value="180">
				<input type="number" data-ffc-model-field="body_font_size" value="14">
				<input type="number" data-ffc-model-field="body_padding" value="24">
				<input type="number" data-ffc-model-field="body_max_width" value="600">
				<input type="number" data-ffc-model-field="wrapper_border_radius" value="6">
				<input type="number" data-ffc-model-field="wrapper_padding" value="32">
				<input type="text" data-ffc-model-field="header_logo_url" value="https://x/logo.png">
				<textarea data-ffc-model-field="footer_text">Sent by {{site_title}}</textarea>
				<button type="button" class="ffc-em-logo-clear">clear</button>
				<button type="button" class="ffc-email-model-restore">restore</button>
			</form>
			<iframe class="ffc-email-model-preview-frame"></iframe>
		</div>
	`;
}

beforeEach(() => {
	window.$.fx.off = true;
	document.body.innerHTML = '';
	window.ffcEmailModel = {
		defaults: {
			wrapper_bg: '#f0f0f1',
			header_bg: '#2271b1',
			footer_text: 'Sent by {{site_title}}',
			body_max_width: 600,
		},
		fontStacks: { system: 'system-ui, sans-serif' },
		tokens: { '{{site_title}}': 'My Site' },
		siteName: 'My Site',
		sampleTitle: 'Sample email',
		sampleBody: 'Body sample',
		sampleLink: 'Link',
		confirmRestore: 'Sure?',
	};
	window.confirm = () => true;
});

afterEach(() => {
	delete window.ffcEmailModel;
	delete window.confirm;
});

describe('ffc-email-model', () => {
	it('no-ops when the box is absent', async () => {
		document.body.innerHTML = '<div>nothing</div>';
		await expect(loadOnReady()).resolves.toBeUndefined();
	});

	it('renders a live preview into the iframe on load', async () => {
		installDom();
		await loadOnReady();
		const html = document.querySelector('.ffc-email-model-preview-frame').srcdoc;
		expect(html).toContain('#2271b1'); // header bg
		expect(html).toContain('Sample email'); // sample miolo
		expect(html).toContain('Sent by My Site'); // footer token resolved
	});

	it('updates the preview when a field changes', async () => {
		installDom();
		await loadOnReady();
		window.$('[data-ffc-model-field="header_bg"]').val('#ff0000').trigger('change');
		const html = document.querySelector('.ffc-email-model-preview-frame').srcdoc;
		expect(html).toContain('#ff0000');
	});

	it('restores all fields to defaults', async () => {
		installDom();
		await loadOnReady();
		window.$('[data-ffc-model-field="header_bg"]').val('#000000');
		window.$('[data-ffc-model-field="body_max_width"]').val('900');
		window.$('.ffc-email-model-restore').trigger('click');
		expect(window.$('[data-ffc-model-field="header_bg"]').val()).toBe('#2271b1');
		expect(window.$('[data-ffc-model-field="body_max_width"]').val()).toBe('600');
	});

	it('does not restore when the confirm is cancelled', async () => {
		installDom();
		window.confirm = () => false;
		await loadOnReady();
		window.$('[data-ffc-model-field="header_bg"]').val('#000000');
		window.$('.ffc-email-model-restore').trigger('click');
		expect(window.$('[data-ffc-model-field="header_bg"]').val()).toBe('#000000');
	});

	it('clears the logo field', async () => {
		installDom();
		await loadOnReady();
		window.$('.ffc-em-logo-clear').trigger('click');
		expect(window.$('[data-ffc-model-field="header_logo_url"]').val()).toBe('');
	});
});
