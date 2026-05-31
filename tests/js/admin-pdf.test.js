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
			// PHP localizes the actual catalog now (the script no longer
			// ships a hardcoded fallback). Provide a realistic stub for the
			// tests so we can assert on the rendered options.
			templates: [
				{ value: 'default_certificate_1.html', label: 'Default Certificate 1' },
				{ value: 'default_certificate_2.html', label: 'Default Certificate 2' },
				{ value: 'default_certificate_3.html', label: 'Default Certificate 3' },
				{ value: 'my_certificate_template.html', label: 'My Certificate Template' },
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

	it('lists the localized template catalog from ffc_ajax.templates', () => {
		window.$('#ffc_load_template_btn').trigger('click');

		// loadScript is re-evaluated in each beforeEach, so additional click
		// handlers stack — assert "at least one set" rather than the exact
		// count and verify the actual filenames make it through.
		const options = document.querySelectorAll('.ffc-template-option');
		expect(options.length).toBeGreaterThanOrEqual(4);
		const files = Array.from(options).map((o) => o.getAttribute('data-file'));
		expect(files).toContain('default_certificate_1.html');
		expect(files).toContain('my_certificate_template.html');
	});
});

describe('ffc-admin-pdf.js — loadTemplate (fetch path)', () => {
	beforeEach(() => {
		reset();
		installFFC();
		document.body.innerHTML = '<textarea id="ffc_pdf_layout"></textarea>';
		loadScript('assets/js/ffc-admin-pdf.js');
	});

	afterEach(reset);

	it('writes fetched HTML into #ffc_pdf_layout on success', async () => {
		window.fetch = vi.fn().mockResolvedValue({
			ok: true,
			status: 200,
			text: () => Promise.resolve('<h1>Certificado</h1>'),
		});

		window.FFC.Admin.PDF.loadTemplate('certificado_1.html', 'Modelo 1');
		// Flush fetch → response → text → populate chain.
		await new Promise((r) => setTimeout(r, 0));
		await new Promise((r) => setTimeout(r, 0));

		expect(document.querySelector('#ffc_pdf_layout').value).toBe(
			'<h1>Certificado</h1>'
		);
		// Success notification was fired (in addition to the initial "loading").
		expect(window.FFC.Admin.showNotification).toHaveBeenCalledTimes(2);
	});

	it('surfaces a 404 error notification via showNotification', async () => {
		window.fetch = vi.fn().mockResolvedValue({
			ok: false,
			status: 404,
			text: () => Promise.resolve(''),
		});

		window.FFC.Admin.PDF.loadTemplate('missing.html', 'Missing');
		await new Promise((r) => setTimeout(r, 0));
		await new Promise((r) => setTimeout(r, 0));

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
		// The iframe content is written via document.write(), so query the
		// iframe's own document tree.
		const body = iframe.contentDocument.body;
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
		const body = iframe.contentDocument.body;
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
		const body = iframe.contentDocument.body;
		expect(body.innerHTML).toContain('John Doe');
		expect(body.innerHTML).toContain('Curso de Exemplo');
	});

	it('expands {{qr_code}} into the placeholder SVG', () => {
		document.querySelector('#ffc_pdf_layout').value = '<p>{{qr_code}}</p>';

		window.$('#ffc_btn_preview').trigger('click');

		const iframe = document.querySelector('#ffc-preview-modal iframe');
		const body = iframe.contentDocument.body;
		expect(body.innerHTML).toContain('<svg');
		expect(body.innerHTML).toContain('QR Code');
	});
});
