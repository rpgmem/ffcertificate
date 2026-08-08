// Tests for `assets/js/ffc-admin-pdf.js`.
//
// PDF template management on the admin form-editor screen. Exposes
// `window.FFC.Admin.PDF.loadTemplate` and binds click handlers for the
// load-template / preview / import flows.
import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { loadScript } from './helpers.js';

function installFFC() {
	window.FFC = {
		version: '6.6.1',
		registerModule: vi.fn(),
		Admin: {
			showNotification: vi.fn(),
		},
	};
}

function reset() {
	document.body.innerHTML = '';
	delete window.FFC;
	delete window.ffc_ajax;
	if (window.fetch && window.fetch.mockRestore) {
		window.fetch.mockRestore();
	}
	delete window.fetch;
}

describe('ffc-admin-pdf.js — module shape', () => {
	beforeEach(() => {
		reset();
		installFFC();
		loadScript('assets/js/ffc-admin-pdf.js');
	});

	afterEach(reset);

	it('exposes FFC.Admin.PDF.loadTemplate on the window', () => {
		expect(typeof window.FFC.Admin.PDF.loadTemplate).toBe('function');
	});

	it('registers the Admin.PDF module with FFC.registerModule', () => {
		expect(window.FFC.registerModule).toHaveBeenCalledWith(
			'Admin.PDF',
			'6.6.1'
		);
	});
});

describe('ffc-admin-pdf.js — load-template button', () => {
	beforeEach(() => {
		reset();
		installFFC();
		document.body.innerHTML =
			'<button id="ffc_load_template_btn">Load</button>';
		window.ffc_ajax = {
			strings: {
				selectTemplate: 'Choose a template',
				cancel: 'Cancel',
			},
			// PHP localizes the DB-backed pool now (#865): each entry is keyed
			// by post id (defaults first), with an empty `file` for pool rows.
			templates: [
				{ id: 10, label: 'Certificate model 1', is_default: true, file: '' },
				{ id: 11, label: 'Certificate model 2', is_default: true, file: '' },
				{ id: 20, label: 'My Certificate Template', is_default: false, file: '' },
			],
		};
		loadScript('assets/js/ffc-admin-pdf.js');
	});

	afterEach(reset);

	it('appends a modal with localized strings on click', () => {
		window.$('#ffc_load_template_btn').trigger('click');

		const modal = document.querySelector('#ffc-template-modal');
		expect(modal).not.toBeNull();
		expect(modal.textContent).toContain('Choose a template');
		expect(modal.querySelector('#ffc-modal-cancel').textContent).toBe(
			'Cancel'
		);
	});

	it('lists the localized template catalog from ffc_ajax.templates keyed by id', () => {
		window.$('#ffc_load_template_btn').trigger('click');

		// loadScript is re-evaluated in each beforeEach, so additional click
		// handlers stack — assert "at least one set" rather than the exact
		// count and verify the pool post ids make it through as data-id.
		const options = document.querySelectorAll('.ffc-template-option');
		expect(options.length).toBeGreaterThanOrEqual(3);
		const ids = Array.from(options).map((o) => o.getAttribute('data-id'));
		expect(ids).toContain('10');
		expect(ids).toContain('20');
	});
});

describe('ffc-admin-pdf.js — loadTemplate (ajax path)', () => {
	beforeEach(() => {
		reset();
		installFFC();
		document.body.innerHTML = '<textarea id="ffc_pdf_layout"></textarea>';
		window.ffc_ajax = {
			ajax_url: '/wp-admin/admin-ajax.php',
			nonce: 'test-nonce',
			strings: {
				loadingTemplate: 'Loading…',
				templateLoadedSuccess: 'Template "%s" loaded!',
				templateFileNotFound: 'Template file not found.',
			},
		};
		loadScript('assets/js/ffc-admin-pdf.js');
	});

	afterEach(reset);

	function stubPost(response) {
		const jqxhr = {
			done(cb) {
				cb(response);
				return jqxhr;
			},
			fail() {
				return jqxhr;
			},
		};
		window.$.post = vi.fn(() => jqxhr);
		return jqxhr;
	}

	it('POSTs ffc_load_template with template_id and writes the pool HTML', () => {
		stubPost({ success: true, data: '<h1>Certificado</h1>' });

		window.FFC.Admin.PDF.loadTemplate(5, '', 'Modelo 1');

		expect(window.$.post).toHaveBeenCalled();
		const [url, data] = window.$.post.mock.calls[0];
		expect(url).toBe('/wp-admin/admin-ajax.php');
		expect(data.action).toBe('ffc_load_template');
		expect(data.nonce).toBe('test-nonce');
		expect(data.template_id).toBe(5);
		expect(data.filename).toBeUndefined();

		expect(document.querySelector('#ffc_pdf_layout').value).toBe(
			'<h1>Certificado</h1>'
		);
		// Loading + success notifications.
		expect(window.FFC.Admin.showNotification).toHaveBeenCalledTimes(2);
	});

	it('posts the legacy filename when the id is 0 (deprecated fallback)', () => {
		stubPost({ success: true, data: '<p>legacy</p>' });

		window.FFC.Admin.PDF.loadTemplate(0, 'legacy_certificate.html', 'Legacy');

		const [, data] = window.$.post.mock.calls[0];
		expect(data.template_id).toBeUndefined();
		expect(data.filename).toBe('legacy_certificate.html');
	});

	it('carries the structured payload {html,bg_image} into the layout + bg field', () => {
		document.body.insertAdjacentHTML('beforeend', '<input id="ffc_bg_image_input" />');
		stubPost({
			success: true,
			data: { html: '<h1>Cert</h1>', bg_image: 'https://example.com/bg.png' },
		});

		window.FFC.Admin.PDF.loadTemplate(7, '', 'Modelo');

		expect(document.querySelector('#ffc_pdf_layout').value).toBe('<h1>Cert</h1>');
		expect(document.querySelector('#ffc_bg_image_input').value).toBe(
			'https://example.com/bg.png'
		);
	});

	it('clears the bg field when the loaded template carries an empty bg_image', () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			'<input id="ffc_bg_image_input" value="https://old.example/bg.png" />'
		);
		stubPost({ success: true, data: { html: '<h1>X</h1>', bg_image: '' } });

		window.FFC.Admin.PDF.loadTemplate(8, '', 'Modelo');

		expect(document.querySelector('#ffc_bg_image_input').value).toBe('');
	});

	it('leaves the bg field untouched for a legacy bare-string response', () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			'<input id="ffc_bg_image_input" value="keep-me" />'
		);
		stubPost({ success: true, data: '<p>legacy</p>' });

		window.FFC.Admin.PDF.loadTemplate(9, '', 'Legacy');

		expect(document.querySelector('#ffc_pdf_layout').value).toBe('<p>legacy</p>');
		// A string payload has no bg_image key → the field is not written.
		expect(document.querySelector('#ffc_bg_image_input').value).toBe('keep-me');
	});

	it('surfaces a not-found error when the server responds unsuccessfully', () => {
		stubPost({ success: false });

		window.FFC.Admin.PDF.loadTemplate(999, '', 'Missing');

		const calls = window.FFC.Admin.showNotification.mock.calls;
		const errorCall = calls.find((c) => c[1] === 'error');
		expect(errorCall).toBeDefined();
		expect(errorCall[0]).toMatch(/not found/i);
	});
});

describe('ffc-admin-pdf.js — save as model', () => {
	beforeEach(() => {
		reset();
		installFFC();
		document.body.innerHTML =
			'<button id="ffc_save_as_model_btn">Save as model</button>' +
			'<textarea id="ffc_pdf_layout"><div>{{name}}</div></textarea>';
		window.ffc_ajax = {
			ajax_url: '/wp-admin/admin-ajax.php',
			nonce: 'test-nonce',
			strings: { saveModelPrompt: 'Name?', templateSaved: 'Saved!' },
		};
		loadScript('assets/js/ffc-admin-pdf.js');
	});

	afterEach(() => {
		if (window.prompt && window.prompt.mockRestore) {
			window.prompt.mockRestore();
		}
		reset();
	});

	it('POSTs ffc_save_template with the prompted title and current layout', () => {
		vi.spyOn(window, 'prompt').mockReturnValue('My Model');
		const jqxhr = { done(cb) { cb({ success: true, data: { id: 9 } }); return jqxhr; }, fail() { return jqxhr; } };
		window.$.post = vi.fn(() => jqxhr);

		window.$('#ffc_save_as_model_btn').trigger('click');

		expect(window.$.post).toHaveBeenCalled();
		const [url, data] = window.$.post.mock.calls[0];
		expect(url).toBe('/wp-admin/admin-ajax.php');
		expect(data.action).toBe('ffc_save_template');
		expect(data.nonce).toBe('test-nonce');
		expect(data.title).toBe('My Model');
		expect(data.html).toContain('{{name}}');
	});

	it('does not POST when the prompt is cancelled', () => {
		vi.spyOn(window, 'prompt').mockReturnValue(null);
		window.$.post = vi.fn();

		window.$('#ffc_save_as_model_btn').trigger('click');

		expect(window.$.post).not.toHaveBeenCalled();
	});

	it('includes the current background image URL so the model round-trips it', () => {
		vi.spyOn(window, 'prompt').mockReturnValue('My Model');
		document.body.insertAdjacentHTML(
			'beforeend',
			'<input id="ffc_bg_image_input" value="https://example.com/bg.png" />'
		);
		const jqxhr = { done(cb) { cb({ success: true, data: { id: 9 } }); return jqxhr; }, fail() { return jqxhr; } };
		window.$.post = vi.fn(() => jqxhr);

		window.$('#ffc_save_as_model_btn').trigger('click');

		const [, data] = window.$.post.mock.calls[0];
		expect(data.bg_image).toBe('https://example.com/bg.png');
	});
});

describe('ffc-admin-pdf.js — inline image insert', () => {
	// Stub wp.media into a frame whose select callback yields a chosen
	// attachment. Mirrors the real API surface the picker touches:
	// frame.on('select', cb) · frame.open() · frame.state().get('selection')
	// .first().toJSON().
	function stubWpMedia(attachment) {
		const frame = {
			_selectCb: null,
			on(evt, cb) {
				if (evt === 'select') {
					this._selectCb = cb;
				}
				return this;
			},
			open() {
				if (this._selectCb) {
					this._selectCb();
				}
			},
			state() {
				return {
					get() {
						return { first: () => ({ toJSON: () => attachment }) };
					},
				};
			},
		};
		window.wp = { media: vi.fn(() => frame) };
		return frame;
	}

	beforeEach(() => {
		reset();
		installFFC();
		document.body.innerHTML =
			'<button id="ffc_btn_insert_image">Insert Image</button>' +
			'<textarea id="ffc_pdf_layout"><p>{{name}}</p></textarea>';
		window.ffc_ajax = {
			strings: {
				insertImageTitle: 'Insert Image',
				insertImageButton: 'Insert into layout',
				imageInserted: 'Image inserted!',
				wpMediaNotAvailable: 'Media unavailable',
			},
		};
		loadScript('assets/js/ffc-admin-pdf.js');
	});

	afterEach(() => {
		delete window.wp;
		reset();
	});

	it('appends an <img> with the selected Media Library URL to the layout', () => {
		stubWpMedia({ url: 'https://example.com/wp-content/uploads/logo.png', alt: '' });

		window.$('#ffc_btn_insert_image').trigger('click');

		const value = document.querySelector('#ffc_pdf_layout').value;
		expect(value).toContain(
			'<img src="https://example.com/wp-content/uploads/logo.png" />'
		);
		// Original content is preserved (insert, not replace).
		expect(value).toContain('{{name}}');
		expect(window.FFC.Admin.showNotification).toHaveBeenCalledWith(
			'Image inserted!',
			'success'
		);
	});

	it('carries the attachment alt text into the img tag, quote-escaped', () => {
		stubWpMedia({
			url: 'https://example.com/a.png',
			alt: 'Some "quoted" logo',
		});

		window.$('#ffc_btn_insert_image').trigger('click');

		const value = document.querySelector('#ffc_pdf_layout').value;
		expect(value).toContain(
			'<img src="https://example.com/a.png" alt="Some &quot;quoted&quot; logo" />'
		);
	});

	it('warns and inserts nothing when wp.media is unavailable', () => {
		// No stubWpMedia → window.wp undefined.
		window.$('#ffc_btn_insert_image').trigger('click');

		expect(document.querySelector('#ffc_pdf_layout').value).toBe('<p>{{name}}</p>');
		const calls = window.FFC.Admin.showNotification.mock.calls;
		expect(calls.some((c) => c[1] === 'error')).toBe(true);
	});

	it('targets #ffc_template_html when the form layout textarea is absent (template screen)', () => {
		// The cert-template edit screen has no #ffc_pdf_layout — the handler
		// resolves the active editor generically and inserts into the template
		// textarea instead. Delegated on document, so no script reload is needed.
		document.body.innerHTML =
			'<button id="ffc_btn_insert_image">Insert Image</button>' +
			'<textarea id="ffc_template_html"><p>{{name}}</p></textarea>';
		stubWpMedia({ url: 'https://example.com/uploads/logo.png', alt: '' });

		window.$('#ffc_btn_insert_image').trigger('click');

		const value = document.querySelector('#ffc_template_html').value;
		expect(value).toContain(
			'<img src="https://example.com/uploads/logo.png" />'
		);
		expect(value).toContain('{{name}}');
	});
});

describe('ffc-admin-pdf.js — preview button', () => {
	beforeEach(() => {
		reset();
		installFFC();
		document.body.innerHTML = `
			<button id="ffc_btn_preview">Preview</button>
			<textarea id="ffc_pdf_layout"></textarea>
			<input id="ffc_bg_image_input" />
			<input id="title" value="Test Form" />
			<div id="ffc-fields-container"></div>
		`;
		// previewSamples mirrors the PHP CertificatePreviewSamples::get_map()
		// contract — the preview reads its sample catalog from there.
		window.ffc_ajax = {
			previewSamples: {
				name: 'John Doe',
				form_title: 'Título do Certificado',
				bairro: 'Centro',
				site_name: 'Sample Site',
			},
		};
		loadScript('assets/js/ffc-admin-pdf.js');
	});

	afterEach(reset);

	it('alerts when the layout textarea is empty', () => {
		const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});

		window.$('#ffc_btn_preview').trigger('click');

		expect(alertSpy).toHaveBeenCalled();
		expect(alertSpy.mock.calls[0][0]).toMatch(/empty/i);
		expect(document.querySelector('#ffc-preview-modal')).toBeNull();

		alertSpy.mockRestore();
	});

	it('opens a preview modal with the replaced placeholders', () => {
		document.querySelector('#ffc_pdf_layout').value =
			'Hello {{name}}! Your form: {{form_title}}.';

		window.$('#ffc_btn_preview').trigger('click');

		const modal = document.querySelector('#ffc-preview-modal');
		expect(modal).not.toBeNull();
		const iframe = modal.querySelector('iframe');
		expect(iframe).not.toBeNull();
		// jsdom doesn't synchronously paint the srcdoc into contentDocument,
		// so assert on the attribute directly — it carries the same iframeHtml
		// the implementation wrote.
		const body = { innerHTML: iframe.getAttribute('srcdoc') || '' };
		expect(body.innerHTML).toContain('Test Form');
		// {{name}} → "John Doe" from the hardcoded sample catalog.
		expect(body.innerHTML).toContain('John Doe');
		expect(body.innerHTML).not.toContain('{{name}}');
	});

	it('replaces a system placeholder from the PHP sample map', () => {
		document.querySelector('#ffc_pdf_layout').value =
			'Bairro: {{bairro}} — {{site_name}}';

		window.$('#ffc_btn_preview').trigger('click');

		const iframe = document.querySelector('#ffc-preview-modal iframe');
		// jsdom doesn't synchronously paint the srcdoc into contentDocument,
		// so assert on the attribute directly — it carries the same iframeHtml
		// the implementation wrote.
		const body = { innerHTML: iframe.getAttribute('srcdoc') || '' };
		expect(body.innerHTML).toContain('Centro');
		expect(body.innerHTML).toContain('Sample Site');
		expect(body.innerHTML).not.toContain('{{bairro}}');
	});

	it('overlays builder custom fields onto the PHP sample map', () => {
		window.$('#ffc-fields-container').html(
			'<div class="ffc-field-row">' +
				'<input name="ffc_fields[0][name]" value="course_name" />' +
				'<input name="ffc_fields[0][label]" value="Curso de Exemplo" />' +
				'</div>'
		);
		document.querySelector('#ffc_pdf_layout').value = '{{name}} — {{course_name}}';

		window.$('#ffc_btn_preview').trigger('click');

		const iframe = document.querySelector('#ffc-preview-modal iframe');
		// jsdom doesn't synchronously paint the srcdoc into contentDocument,
		// so assert on the attribute directly — it carries the same iframeHtml
		// the implementation wrote.
		const body = { innerHTML: iframe.getAttribute('srcdoc') || '' };
		expect(body.innerHTML).toContain('John Doe');
		expect(body.innerHTML).toContain('Curso de Exemplo');
	});

	it('expands {{qr_code}} into the placeholder SVG', () => {
		document.querySelector('#ffc_pdf_layout').value = '<p>{{qr_code}}</p>';

		window.$('#ffc_btn_preview').trigger('click');

		const iframe = document.querySelector('#ffc-preview-modal iframe');
		// jsdom doesn't synchronously paint the srcdoc into contentDocument,
		// so assert on the attribute directly — it carries the same iframeHtml
		// the implementation wrote.
		const body = { innerHTML: iframe.getAttribute('srcdoc') || '' };
		expect(body.innerHTML).toContain('<svg');
		expect(body.innerHTML).toContain('QR Code');
	});
});
