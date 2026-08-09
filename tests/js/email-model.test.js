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
				<input type="text" class="ffc-email-model-color" data-ffc-model-field="wrapper_bg" value="#f0f0f1">
				<input type="text" class="ffc-email-model-color" data-ffc-model-field="header_bg" value="#2271b1">
				<input type="text" class="ffc-email-model-color" data-ffc-model-field="header_text_color" value="#ffffff">
				<input type="text" class="ffc-email-model-color" data-ffc-model-field="body_bg" value="#ffffff">
				<input type="text" class="ffc-email-model-color" data-ffc-model-field="body_text_color" value="#333333">
				<input type="text" class="ffc-email-model-color" data-ffc-model-field="body_link_color" value="#2271b1">
				<input type="text" class="ffc-email-model-color" data-ffc-model-field="footer_bg" value="#f5f5f5">
				<input type="text" class="ffc-email-model-color" data-ffc-model-field="footer_text_color" value="#666666">
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
				<button type="button" class="ffc-email-model-logo-select">select</button>
				<button type="button" class="ffc-email-model-logo-clear">clear</button>
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
	delete window.wp;
	// wp-color-picker is an optional jQuery plugin: some tests define a stub of
	// it on the shared jsdom `$.fn`. Remove it so it never leaks into the tests
	// that assert the plain-`.val()` fallback path.
	if (window.$ && window.$.fn) {
		delete window.$.fn.wpColorPicker;
	}
});

// Minimal wp-color-picker stub: enough for the init call and the
// `wpColorPicker('color', value)` restore call, mirroring the field value so
// the restore assertions can read it back through `.val()`.
function stubColorPicker() {
	window.$.fn.wpColorPicker = function (arg, value) {
		if (arg === 'color' && typeof value !== 'undefined') {
			return this.val(value);
		}
		return this;
	};
}

// Minimal wp.media stub: `wp.media(opts)` returns a frame that invokes the
// registered `select` handler when `.open()` is called, exposing one chosen
// attachment.
function stubWpMedia(url) {
	window.wp = {
		media() {
			var handlers = {};
			return {
				on(evt, cb) { handlers[evt] = cb; return this; },
				state() {
					return { get() { return { first() { return { toJSON() { return { url: url }; } }; } }; } };
				},
				open() { if (handlers.select) { handlers.select(); } },
			};
		},
	};
}

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
		expect(html).toContain('Sample email'); // sample email body
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
		window.$('.ffc-email-model-logo-clear').trigger('click');
		expect(window.$('[data-ffc-model-field="header_logo_url"]').val()).toBe('');
	});

	it('initializes wp-color-picker when available and restores colors through it', async () => {
		stubColorPicker();
		installDom();
		await loadOnReady();

		// The restore path goes through wpColorPicker('color', …) for color fields
		// (not a plain .val()); the stub mirrors the value so it reads back.
		window.$('[data-ffc-model-field="header_bg"]').val('#000000');
		window.$('.ffc-email-model-restore').trigger('click');
		expect(window.$('[data-ffc-model-field="header_bg"]').val()).toBe('#2271b1');
	});

	it('does nothing on logo-select when wp.media is unavailable', async () => {
		installDom();
		await loadOnReady();
		// No window.wp → the handler guards and returns without changing the field.
		window.$('.ffc-email-model-logo-select').trigger('click');
		expect(window.$('[data-ffc-model-field="header_logo_url"]').val()).toBe('https://x/logo.png');
	});

	it('sets the logo field from the media frame selection', async () => {
		stubWpMedia('https://cdn/new-logo.png');
		installDom();
		await loadOnReady();

		window.$('.ffc-email-model-logo-select').trigger('click');

		expect(window.$('[data-ffc-model-field="header_logo_url"]').val()).toBe('https://cdn/new-logo.png');
		// The change propagated to the live preview.
		const html = document.querySelector('.ffc-email-model-preview-frame').srcdoc;
		expect(html).toContain('https://cdn/new-logo.png');
	});
});
