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

	it('surfaces a not-found error when the server responds unsuccessfully', () => {
		stubPost({ success: false });

		window.FFC.Admin.PDF.loadTemplate(999, '', 'Missing');

		const calls = window.FFC.Admin.showNotification.mock.calls;
		const errorCall = calls.find((c) => c[1] === 'error');
		expect(errorCall).toBeDefined();
		expect(errorCall[0]).toMatch(/not found/i);
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
