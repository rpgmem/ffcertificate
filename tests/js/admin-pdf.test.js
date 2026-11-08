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
		// The shape of FFC.button() in ffc-core.js, for the modal's Cancel.
		button: (o) => {
			const b = document.createElement('button');
			b.type = 'button';
			b.id = o.id || '';
			b.className = 'ffc-btn ffc-btn-' + (o.category || 'neutral') + ' ffc-icon-' + o.icon;
			b.textContent = o.label;
			return b;
		},
		Admin: {
			notify: vi.fn(),
		},
		// The plugin's dialog (#2535): answers no unless a test says otherwise.
		confirmDialog: vi.fn(() => Promise.resolve(false)),
	};
}

// Answer the next plugin dialog: true/false for a question, a string for a reason field.
function answer(value) {
	window.FFC.confirmDialog.mockReturnValue(Promise.resolve(value === null ? false : value));
	return window.FFC.confirmDialog;
}
const settle = () => new Promise((r) => setTimeout(r, 0));

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
		loadScript('assets/js/ffc-code-editor-core.js');
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
		loadScript('assets/js/ffc-code-editor-core.js');
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
		loadScript('assets/js/ffc-code-editor-core.js');
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

		window.FFC.Admin.PDF.loadTemplate(5, 'Modelo 1');

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
		expect(window.FFC.Admin.notify).toHaveBeenCalledTimes(2);
	});

	// What used to sit here asserted the by-filename POST the `html/` glob fed.
	// The server stopped reading `filename` in 6.23.0 (#1087), so the test was
	// pinning a contract production would have rejected -- green, and describing
	// something that could not work. It went with the branch in #1309.

	it('still reports success when the template has no name', () => {
		stubPost({ success: true, data: '<h1>Cert</h1>' });

		// The name comes from `$(this).find('strong').text()` on the modal row,
		// so a pool template with an empty title reaches here as ''. The success
		// message read `displayName || filename || ''` and `filename` was a
		// variable of the deleted by-filename branch -- removing that branch
		// alone left a ReferenceError on exactly this path. A truthy name hides
		// it, because `||` never evaluates its right side.
		window.FFC.Admin.PDF.loadTemplate(5, '');

		expect(window.FFC.Admin.notify).toHaveBeenLastCalledWith(
			'Template "" loaded!',
			'success',
			3000
		);
	});

	it('carries the structured payload {html,bg_image} into the layout + bg field', () => {
		document.body.insertAdjacentHTML('beforeend', '<input id="ffc_bg_image_input" />');
		stubPost({
			success: true,
			data: { html: '<h1>Cert</h1>', bg_image: 'https://example.com/bg.png' },
		});

		window.FFC.Admin.PDF.loadTemplate(7, 'Modelo');

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

		window.FFC.Admin.PDF.loadTemplate(8, 'Modelo');

		expect(document.querySelector('#ffc_bg_image_input').value).toBe('');
	});

	it('carries the model page colour into the colour field, white when it has none', () => {
		document.body.insertAdjacentHTML('beforeend', '<input type="color" id="ffc_bg_color_input" value="#123456" />');
		stubPost({ success: true, data: { html: '<h1>C</h1>', bg_image: '', bg_color: '#0a3d62' } });
		window.FFC.Admin.PDF.loadTemplate(10, 'Coloured');
		expect(document.querySelector('#ffc_bg_color_input').value).toBe('#0a3d62');

		stubPost({ success: true, data: { html: '<h1>W</h1>', bg_image: '', bg_color: '' } });
		window.FFC.Admin.PDF.loadTemplate(11, 'White');
		expect(document.querySelector('#ffc_bg_color_input').value).toBe('#ffffff');
	});

	it('leaves the bg field untouched for a legacy bare-string response', () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			'<input id="ffc_bg_image_input" value="keep-me" />'
		);
		stubPost({ success: true, data: '<p>legacy</p>' });

		window.FFC.Admin.PDF.loadTemplate(9, 'Legacy');

		expect(document.querySelector('#ffc_pdf_layout').value).toBe('<p>legacy</p>');
		// A string payload has no bg_image key → the field is not written.
		expect(document.querySelector('#ffc_bg_image_input').value).toBe('keep-me');
	});

	it('surfaces a not-found error when the server responds unsuccessfully', () => {
		stubPost({ success: false });

		window.FFC.Admin.PDF.loadTemplate(999, 'Missing');

		const calls = window.FFC.Admin.notify.mock.calls;
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
		loadScript('assets/js/ffc-code-editor-core.js');
		loadScript('assets/js/ffc-admin-pdf.js');
	});

	afterEach(() => {
		reset();
	});

	it('POSTs ffc_save_template with the title typed in the plugin dialog and the current layout', async () => {
		answer('My Model');
		const jqxhr = { done(cb) { cb({ success: true, data: { id: 9 } }); return jqxhr; }, fail() { return jqxhr; } };
		window.$.post = vi.fn(() => jqxhr);

		window.$('#ffc_save_as_model_btn').trigger('click');
		await settle();

		expect(window.$.post).toHaveBeenCalled();
		const [url, data] = window.$.post.mock.calls[0];
		expect(url).toBe('/wp-admin/admin-ajax.php');
		expect(data.action).toBe('ffc_save_template');
		expect(data.nonce).toBe('test-nonce');
		expect(data.title).toBe('My Model');
		expect(data.html).toContain('{{name}}');
	});

	it('does not POST when the dialog is cancelled', async () => {
		answer(null);
		window.$.post = vi.fn();

		window.$('#ffc_save_as_model_btn').trigger('click');
		await settle();

		expect(window.$.post).not.toHaveBeenCalled();
	});

	it('includes the current background image URL so the model round-trips it', async () => {
		answer('My Model');
		document.body.insertAdjacentHTML(
			'beforeend',
			'<input id="ffc_bg_image_input" value="https://example.com/bg.png" />'
		);
		const jqxhr = { done(cb) { cb({ success: true, data: { id: 9 } }); return jqxhr; }, fail() { return jqxhr; } };
		window.$.post = vi.fn(() => jqxhr);

		window.$('#ffc_save_as_model_btn').trigger('click');
		await settle();

		const [, data] = window.$.post.mock.calls[0];
		expect(data.bg_image).toBe('https://example.com/bg.png');
	});

	it('includes the current page colour so the model round-trips it', async () => {
		answer('My Model');
		document.body.insertAdjacentHTML('beforeend', '<input type="color" id="ffc_bg_color_input" value="#0a3d62" />');
		const jqxhr = { done(cb) { cb({ success: true, data: { id: 9 } }); return jqxhr; }, fail() { return jqxhr; } };
		window.$.post = vi.fn(() => jqxhr);

		window.$('#ffc_save_as_model_btn').trigger('click');
		await settle();

		const [, data] = window.$.post.mock.calls[0];
		expect(data.bg_color).toBe('#0a3d62');
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
			'<button id="ffc_btn_media_lib">Media Library</button>' +
			'<textarea id="ffc_pdf_layout"><p>{{name}}</p></textarea>';
		window.ffc_ajax = {
			strings: {
				insertImageTitle: 'Insert Image',
				insertImageButton: 'Insert into layout',
				imageInserted: 'Image inserted!',
				wpMediaNotAvailable: 'Media unavailable',
			},
		};
		loadScript('assets/js/ffc-code-editor-core.js');
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
		expect(window.FFC.Admin.notify).toHaveBeenCalledWith(
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

	// ffc-admin-pdf.js guards wp.media at TWO click handlers with the same check:
	// #ffc_btn_insert_image (below) and #ffc_btn_media_lib, the background-image
	// picker. Only the first was covered, so the second could have lost its guard
	// and thrown a ReferenceError on any screen where wp_enqueue_media() did not
	// run, with the suite still green.
	it('warns instead of throwing when the media library button is clicked without wp.media', () => {
		expect(window.wp).toBeUndefined();

		expect(() => window.$('#ffc_btn_media_lib').trigger('click')).not.toThrow();

		const calls = window.FFC.Admin.notify.mock.calls;
		expect(calls.some((c) => c[1] === 'error')).toBe(true);
	});

	it('warns and inserts nothing when wp.media is unavailable', () => {
		// No stubWpMedia → window.wp undefined.
		window.$('#ffc_btn_insert_image').trigger('click');

		expect(document.querySelector('#ffc_pdf_layout').value).toBe('<p>{{name}}</p>');
		const calls = window.FFC.Admin.notify.mock.calls;
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
			<input id="ffc_bg_color_input" value="#ffffff" />
			<input id="title" value="Test Form" />
			<div id="ffc-fields-container"></div>
		`;
		window.ffc_ajax = {};
		// The preview click is delegated on document, which survives reset();
		// without unbinding, every beforeEach stacks another handler and one
		// click posts N times.
		window.$(document).off('click', '#ffc_btn_preview');
		loadScript('assets/js/ffc-code-editor-core.js');
		loadScript('assets/js/ffc-admin-pdf.js');
	});

	afterEach(reset);

	it('says on the screen when the layout textarea is empty', () => {
		window.$('#ffc_btn_preview').trigger('click');

		expect(window.FFC.Admin.notify).toHaveBeenCalledWith(expect.stringMatching(/empty/i), 'warning');
		expect(document.querySelector('#ffc-preview-modal')).toBeNull();
	});

	// The preview is rendered by the server and captured by FFCLayoutPreview; the
	// script only asks for the page and shows the image that comes back.
	const answer = (payload) => ({
		done(cb) { cb(payload); return this; },
		fail() { return this; },
	});
	const failure = () => ({
		done() { return this; },
		fail(cb) { cb(); return this; },
	});
	const page = { success: true, data: { html: '<div>page</div>', width: 1123, height: 794 } };
	const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

	beforeEach(() => {
		window.FFCLayoutPreview = {
			capture: vi.fn(() => Promise.resolve('data:image/png;base64,AAAA')),
		};
	});

	afterEach(() => {
		delete window.FFCLayoutPreview;
	});

	it('asks the server to render the layout with the title and the unsaved background', () => {
		const post = vi.spyOn(window.$, 'post').mockReturnValue(answer(page));
		document.querySelector('#ffc_pdf_layout').value = '<p>{{name}}</p>';
		document.querySelector('#ffc_bg_image_input').value = 'https://example.test/bg.png';

		window.$('#ffc_btn_preview').trigger('click');

		expect(post).toHaveBeenCalledTimes(1);
		const data = post.mock.calls[0][1];
		expect(data.action).toBe('ffc_preview_form_layout');
		expect(data.html).toBe('<p>{{name}}</p>');
		expect(data.title).toBe('Test Form');
		expect(data.bg_image).toBe('https://example.test/bg.png');
		post.mockRestore();
	});

	it('sends the form builder fields with their labels, so custom variables preview', () => {
		document.querySelector('#ffc-fields-container').innerHTML =
			'<div class="ffc-field-row"><input name="ffc_fields[0][name]" value="course"><input name="ffc_fields[0][label]" value="Curso"></div>' +
			'<div class="ffc-field-row"><input name="ffc_fields[1][name]" value="city"><input name="ffc_fields[1][label]" value=""></div>';
		const post = vi.spyOn(window.$, 'post').mockReturnValue(answer(page));
		document.querySelector('#ffc_pdf_layout').value = '<p>x</p>';

		window.$('#ffc_btn_preview').trigger('click');

		expect(post.mock.calls[0][1].fields).toEqual([
			{ name: 'course', label: 'Curso' },
			{ name: 'city', label: 'city' },
		]);
		post.mockRestore();
	});

	it('sends the background colour of the editor', () => {
		const post = vi.spyOn(window.$, 'post').mockReturnValue(answer(page));
		document.querySelector('#ffc_pdf_layout').value = '<p>x</p>';
		document.querySelector('#ffc_bg_color_input').value = '#f3e9d2';

		window.$('#ffc_btn_preview').trigger('click');

		expect(post.mock.calls[0][1].bg_color).toBe('#f3e9d2');
		post.mockRestore();
	});

	it('captures the server page at the page size the server reports', () => {
		const post = vi.spyOn(window.$, 'post').mockReturnValue(answer(page));
		document.querySelector('#ffc_pdf_layout').value = '<p>x</p>';

		window.$('#ffc_btn_preview').trigger('click');

		expect(window.FFCLayoutPreview.capture).toHaveBeenCalledWith('<div>page</div>', 1123, 794);
		post.mockRestore();
	});

	it('shows the captured image in the preview, not a frame', async () => {
		const post = vi.spyOn(window.$, 'post').mockReturnValue(answer(page));
		document.querySelector('#ffc_pdf_layout').value = '<p>x</p>';

		window.$('#ffc_btn_preview').trigger('click');
		await flush();

		const image = document.querySelector('#ffc-preview-modal img.ffc-preview-page');
		expect(image.getAttribute('src')).toBe('data:image/png;base64,AAAA');
		expect(document.querySelector('#ffc-preview-iframe')).toBeNull();
		post.mockRestore();
	});

	it('closes the preview when the server cannot render the layout', async () => {
		const post = vi.spyOn(window.$, 'post').mockReturnValue(failure());
		document.querySelector('#ffc_pdf_layout').value = '<p>x</p>';

		window.$('#ffc_btn_preview').trigger('click');
		await flush();

		expect(window.FFCLayoutPreview.capture).not.toHaveBeenCalled();
		post.mockRestore();
	});

	it('reports the failure in the localized words', async () => {
		window.ffc_ajax = { strings: { previewError: 'A pré-visualização falhou.' } };
		const post = vi.spyOn(window.$, 'post').mockReturnValue(failure());
		document.querySelector('#ffc_pdf_layout').value = '<p>x</p>';

		window.$('#ffc_btn_preview').trigger('click');
		await flush();

		expect(window.FFC.Admin.notify).toHaveBeenLastCalledWith('A pré-visualização falhou.', 'error', 3000);
		post.mockRestore();
	});

	it('closes the preview when the capture fails', async () => {
		window.FFCLayoutPreview.capture = vi.fn(() => Promise.reject(new Error('tainted')));
		const post = vi.spyOn(window.$, 'post').mockReturnValue(answer(page));
		document.querySelector('#ffc_pdf_layout').value = '<p>x</p>';

		window.$('#ffc_btn_preview').trigger('click');
		await flush();

		// Wait past the first animation frame: a close must hold against the fade-in.
		await new Promise((resolve) => setTimeout(resolve, 50));
		expect(document.querySelector('#ffc-preview-modal').classList.contains('ffc-preview-visible')).toBe(false);
		post.mockRestore();
	});
});

// The blocks below unbind every delegated handler before loading the script:
// each earlier loadScript() stacked its own copy on document, and those copies
// carry their own cached media frames, so one click would run several of them.
function freshLoad() {
	window.$(document).off();
	window.$.fx.off = true;
	loadScript('assets/js/ffc-code-editor-core.js');
	loadScript('assets/js/ffc-admin-pdf.js');
}

const jqAnswer = (payload) => ({
	done(cb) { cb(payload); return this; },
	fail() { return this; },
});
const jqFailure = (xhr) => ({
	done() { return this; },
	fail(cb) { cb(xhr); return this; },
});
const wait = (ms = 0) => new Promise((resolve) => setTimeout(resolve, ms));

async function waitForNotify() {
	for (let i = 0; i < 50 && window.FFC.Admin.notify.mock.calls.length === 0; i++) {
		await wait(5);
	}
}

describe('ffc-admin-pdf.js — template modal interactions', () => {
	beforeEach(() => {
		reset();
		installFFC();
		document.body.innerHTML =
			'<button id="ffc_load_template_btn">Load</button>' +
			'<textarea id="ffc_pdf_layout"></textarea>';
		window.ffc_ajax = {
			ajax_url: '/ajax',
			nonce: 'n',
			strings: { confirmLoadTemplate: 'Replace with "%s"?' },
			templates: [
				{ id: 10, label: 'Model 1', is_default: true },
				{ id: null, label: 'Mine', is_default: false },
			],
		};
		freshLoad();
		window.$('#ffc_load_template_btn').trigger('click');
	});

	afterEach(() => {
		vi.restoreAllMocks();
		window.$(document).off('keydown.ffcTemplateModal');
		reset();
	});

	it('labels default and custom templates and maps a missing id to 0', () => {
		const opts = document.querySelectorAll('.ffc-template-option');
		expect(Array.from(opts).map((o) => o.getAttribute('data-id'))).toEqual(['10', '0']);
		expect(opts[0].querySelector('.ffc-template-option-kind').textContent).toBe('Default template');
		expect(opts[1].querySelector('.ffc-template-option-kind').textContent).toBe('My template');
	});

	it('removes the modal on Cancel', () => {
		window.$('#ffc-modal-cancel').trigger('click');
		expect(document.querySelector('#ffc-template-modal')).toBeNull();
	});

	it('closes on a click on the backdrop but not on a click inside the box', () => {
		window.$('.ffc-template-modal-box').trigger('click');
		expect(document.querySelector('#ffc-template-modal')).not.toBeNull();

		window.$('#ffc-template-modal').trigger('click');
		expect(document.querySelector('#ffc-template-modal')).toBeNull();
	});

	it('closes on Escape and ignores other keys', () => {
		window.$(document).trigger(window.$.Event('keydown', { key: 'Enter' }));
		expect(document.querySelector('#ffc-template-modal')).not.toBeNull();

		window.$(document).trigger(window.$.Event('keydown', { key: 'Escape' }));
		expect(document.querySelector('#ffc-template-modal')).toBeNull();
	});

	it('asks with the localized confirmation and loads the chosen template by id', async () => {
		const confirmSpy = answer(true);
		window.$.post = vi.fn(() => jqAnswer({ success: true, data: '<p>M1</p>' }));

		window.$('.ffc-template-option').first().trigger('click');
		await settle();

		expect(document.querySelector('#ffc-template-modal')).toBeNull();
		expect(confirmSpy.mock.calls[0][0]).toMatchObject({ message: 'Replace with "Model 1"?', category: 'caution' });
		expect(window.$.post.mock.calls[0][1].template_id).toBe(10);
		expect(document.querySelector('#ffc_pdf_layout').value).toBe('<p>M1</p>');
	});

	it('loads nothing when the confirmation is declined', async () => {
		answer(false);
		window.$.post = vi.fn();

		window.$('.ffc-template-option').first().trigger('click');
		await settle();

		expect(window.$.post).not.toHaveBeenCalled();
	});

	it('falls back to the English confirmation without a localized one', async () => {
		window.ffc_ajax.strings = {};
		const confirmSpy = answer(false);

		window.$('.ffc-template-option').last().trigger('click');
		await settle();

		expect(confirmSpy.mock.calls[0][0].message).toBe('Load "Mine"? This will replace your current certificate HTML.');
	});
});

describe('ffc-admin-pdf.js — loadTemplate failures', () => {
	beforeEach(() => {
		reset();
		installFFC();
		window.ffc_ajax = { ajax_url: '/ajax', strings: {} };
		freshLoad();
	});

	afterEach(reset);

	it('reports a missing layout field after a successful load', () => {
		window.$.post = vi.fn(() => jqAnswer({ success: true, data: '<p>x</p>' }));

		window.FFC.Admin.PDF.loadTemplate(1, 'X');

		expect(window.FFC.Admin.notify).toHaveBeenLastCalledWith('HTML field not found.', 'error');
	});

	it.each([
		[403, 'Access denied. Reload the page and try again.'],
		[404, 'Template not found. It may have been removed from the template pool.'],
		[500, 'Network error. Check your connection.'],
	])('maps an HTTP %i failure to its message', (status, message) => {
		window.$.post = vi.fn(() => jqFailure({ status }));

		window.FFC.Admin.PDF.loadTemplate(1, 'X');

		expect(window.FFC.Admin.notify).toHaveBeenLastCalledWith(message, 'error', 8000);
	});
});

describe('ffc-admin-pdf.js — save as model outcomes', () => {
	beforeEach(() => {
		reset();
		installFFC();
		document.body.innerHTML =
			'<button id="ffc_save_as_model_btn">Save</button>' +
			'<textarea id="ffc_pdf_layout"><p>x</p></textarea>';
		window.ffc_ajax = { ajax_url: '/ajax', strings: {} };
		freshLoad();
	});

	afterEach(() => {
		vi.restoreAllMocks();
		reset();
	});

	it('says so on the screen and asks nothing when the layout is blank', async () => {
		document.querySelector('#ffc_pdf_layout').value = '   ';

		window.$('#ffc_save_as_model_btn').trigger('click');
		await settle();

		expect(window.FFC.Admin.notify).toHaveBeenCalledWith('The HTML editor is empty. Add a template first.', 'warning');
		expect(window.FFC.confirmDialog).not.toHaveBeenCalled();
	});

	it('does not POST without a name', async () => {
		answer('');
		window.$.post = vi.fn();

		window.$('#ffc_save_as_model_btn').trigger('click');
		await settle();

		expect(window.$.post).not.toHaveBeenCalled();
	});

	it('asks the name in a required field and reports a refused save', async () => {
		const dialog = answer('Named');
		window.$.post = vi.fn(() => jqAnswer({ success: false }));

		window.$('#ffc_save_as_model_btn').trigger('click');
		await settle();

		// The dialog trims and requires the name; the save sends what it resolved.
		expect(dialog.mock.calls[0][0].reasonField).toMatchObject({ min: 1 });
		expect(window.$.post.mock.calls[0][1].title).toBe('Named');
		expect(window.FFC.Admin.notify).toHaveBeenLastCalledWith('Error: ', 'error', 8000);
	});

	it('reports a connection failure', async () => {
		answer('Named');
		window.$.post = vi.fn(() => jqFailure({ status: 0 }));

		window.$('#ffc_save_as_model_btn').trigger('click');
		await settle();

		expect(window.FFC.Admin.notify).toHaveBeenLastCalledWith('Connection error.', 'error', 8000);
	});
});

describe('ffc-admin-pdf.js — import HTML', () => {
	function setFiles(input, files) {
		Object.defineProperty(input, 'files', { value: files, configurable: true });
	}
	const htmlFile = (body) => new File([body], 'cert.html', { type: 'text/html' });

	beforeEach(() => {
		reset();
		installFFC();
		window.ffc_ajax = { strings: {} };
		freshLoad();
	});

	afterEach(() => {
		vi.restoreAllMocks();
		reset();
	});

	it('opens the page file input when one exists', () => {
		document.body.innerHTML =
			'<button id="ffc_btn_import_html">Import</button>' +
			'<input type="file" id="ffc_import_html_file">';
		const clicked = vi.fn();
		document.querySelector('#ffc_import_html_file').addEventListener('click', clicked);

		window.$('#ffc_btn_import_html').trigger('click');

		expect(clicked).toHaveBeenCalledTimes(1);
		expect(document.querySelectorAll('input[type="file"]').length).toBe(1);
	});

	it('falls back to a file input whose name mentions import', () => {
		document.body.innerHTML =
			'<button id="ffc_btn_import_html">Import</button>' +
			'<input type="file" name="ffc_import_layout">';
		const clicked = vi.fn();
		document.querySelector('input[name="ffc_import_layout"]').addEventListener('click', clicked);

		window.$('#ffc_btn_import_html').trigger('click');

		expect(clicked).toHaveBeenCalledTimes(1);
	});

	it('reads a file chosen in the page input into the layout and resets the input', async () => {
		document.body.innerHTML =
			'<input type="file" id="ffc_import_html_file">' +
			'<textarea id="ffc_pdf_layout"></textarea>';
		const input = document.querySelector('#ffc_import_html_file');
		setFiles(input, [htmlFile('<h1>Imported</h1>')]);
		const valSpy = vi.spyOn(window.$.fn, 'val');

		window.$(input).trigger('change');
		await waitForNotify();

		expect(document.querySelector('#ffc_pdf_layout').value).toBe('<h1>Imported</h1>');
		expect(window.FFC.Admin.notify).toHaveBeenCalledWith('HTML imported successfully!', 'success');
		expect(valSpy).toHaveBeenCalledWith('');
	});

	it('reports a missing textarea when the page input is read', async () => {
		document.body.innerHTML = '<input type="file" id="ffc_import_html_file">';
		const input = document.querySelector('#ffc_import_html_file');
		setFiles(input, [htmlFile('<p>x</p>')]);

		window.$(input).trigger('change');
		await waitForNotify();

		expect(window.FFC.Admin.notify).toHaveBeenCalledWith('Error: HTML textarea not found', 'error');
	});

	it('reports a read error from the page input', async () => {
		document.body.innerHTML = '<input type="file" id="ffc_import_html_file">';
		const input = document.querySelector('#ffc_import_html_file');
		setFiles(input, [htmlFile('<p>x</p>')]);
		vi.spyOn(window.FileReader.prototype, 'readAsText').mockImplementation(function () {
			this.onerror();
		});

		window.$(input).trigger('change');

		expect(window.FFC.Admin.notify).toHaveBeenCalledWith('Error reading file', 'error');
	});

	it('ignores a change with no file chosen', () => {
		document.body.innerHTML = '<input type="file" id="ffc_import_html_file">';
		const input = document.querySelector('#ffc_import_html_file');
		setFiles(input, []);
		const readSpy = vi.spyOn(window.FileReader.prototype, 'readAsText');

		window.$(input).trigger('change');

		expect(readSpy).not.toHaveBeenCalled();
	});

	describe('without a file input on the page', () => {
		function openTempInput() {
			document.body.insertAdjacentHTML('afterbegin', '<button id="ffc_btn_import_html">Import</button>');
			window.$('#ffc_btn_import_html').trigger('click');
			return document.querySelector('input[type="file"][hidden]');
		}

		it('creates a hidden .html input and imports the chosen file, then removes it', async () => {
			document.body.innerHTML = '<textarea id="ffc_pdf_layout"></textarea>';
			const temp = openTempInput();
			expect(temp).not.toBeNull();
			expect(temp.getAttribute('accept')).toBe('.html');

			setFiles(temp, [htmlFile('<h2>Temp</h2>')]);
			window.$(temp).trigger('change');
			await waitForNotify();

			expect(document.querySelector('#ffc_pdf_layout').value).toBe('<h2>Temp</h2>');
			expect(window.FFC.Admin.notify).toHaveBeenCalledWith('HTML imported successfully!', 'success');
			expect(document.querySelector('input[type="file"][hidden]')).toBeNull();
		});

		it('accepts a .html name even when the browser reports another type', async () => {
			document.body.innerHTML = '<textarea id="ffc_pdf_layout"></textarea>';
			const temp = openTempInput();
			setFiles(temp, [new File(['<b>t</b>'], 'cert.html', { type: '' })]);

			window.$(temp).trigger('change');
			await waitForNotify();

			expect(document.querySelector('#ffc_pdf_layout').value).toBe('<b>t</b>');
		});

		it('warns and reads nothing for a file that is not HTML', () => {
			const temp = openTempInput();
			const readSpy = vi.spyOn(window.FileReader.prototype, 'readAsText');
			setFiles(temp, [new File(['x'], 'notes.txt', { type: 'text/plain' })]);

			window.$(temp).trigger('change');

			expect(readSpy).not.toHaveBeenCalled();
			expect(window.FFC.Admin.notify).toHaveBeenCalledWith('Please select an HTML file.', 'warning');
		});

		it('reports a missing layout field after reading', async () => {
			const temp = openTempInput();
			setFiles(temp, [htmlFile('<p>x</p>')]);

			window.$(temp).trigger('change');
			await waitForNotify();

			expect(window.FFC.Admin.notify).toHaveBeenCalledWith('Error: HTML field not found.', 'error');
		});

		it('does nothing when the dialog closes with no file', () => {
			const temp = openTempInput();
			setFiles(temp, []);
			const readSpy = vi.spyOn(window.FileReader.prototype, 'readAsText');

			window.$(temp).trigger('change');

			expect(readSpy).not.toHaveBeenCalled();
			expect(window.FFC.Admin.notify).not.toHaveBeenCalled();
		});
	});
});

describe('ffc-admin-pdf.js — preview closing', () => {
	beforeEach(() => {
		reset();
		installFFC();
		document.body.innerHTML =
			'<button id="ffc_btn_preview">Preview</button>' +
			'<textarea id="ffc_pdf_layout"><p>x</p></textarea>';
		window.ffc_ajax = {};
		window.FFCLayoutPreview = { capture: vi.fn(() => new Promise(() => {})) };
		freshLoad();
	});

	afterEach(() => {
		vi.restoreAllMocks();
		window.$(document).off('keydown.ffcPreview');
		delete window.FFCLayoutPreview;
		reset();
	});

	it('removes the modal after the fade when the close button is clicked', async () => {
		window.$.post = vi.fn(() => jqAnswer({ success: true, data: { html: 'h', width: 1, height: 1 } }));
		window.$('#ffc_btn_preview').trigger('click');

		window.$('.ffc-preview-close').trigger('click');
		expect(document.querySelector('#ffc-preview-modal')).not.toBeNull();
		await wait(250);

		expect(document.querySelector('#ffc-preview-modal')).toBeNull();
	});

	it('closes on Escape only', async () => {
		window.$.post = vi.fn(() => jqAnswer({ success: true, data: { html: 'h', width: 1, height: 1 } }));
		window.$('#ffc_btn_preview').trigger('click');

		window.$(document).trigger(window.$.Event('keydown', { key: 'a' }));
		await wait(250);
		expect(document.querySelector('#ffc-preview-modal')).not.toBeNull();

		window.$(document).trigger(window.$.Event('keydown', { key: 'Escape' }));
		await wait(250);
		expect(document.querySelector('#ffc-preview-modal')).toBeNull();
	});

	it('fails without capturing when the server answers unsuccessfully', () => {
		window.$.post = vi.fn(() => jqAnswer({ success: false }));

		window.$('#ffc_btn_preview').trigger('click');

		expect(window.FFCLayoutPreview.capture).not.toHaveBeenCalled();
		expect(window.FFC.Admin.notify).toHaveBeenLastCalledWith(
			'The preview could not be rendered.',
			'error',
			3000
		);
	});

	it('fails when the capture module is not loaded', () => {
		delete window.FFCLayoutPreview;
		window.$.post = vi.fn(() => jqAnswer({ success: true, data: { html: 'h', width: 1, height: 1 } }));

		window.$('#ffc_btn_preview').trigger('click');

		expect(window.FFC.Admin.notify).toHaveBeenLastCalledWith(
			'The preview could not be rendered.',
			'error',
			3000
		);
	});
});

describe('ffc-admin-pdf.js — media frames', () => {
	function stubFrame(attachment) {
		const frame = {
			_select: null,
			on: vi.fn(function (evt, cb) {
				if (evt === 'select') {
					this._select = cb;
				}
				return this;
			}),
			open: vi.fn(function () {
				if (this._select) {
					this._select();
				}
			}),
			state: () => ({ get: () => ({ first: () => ({ toJSON: () => attachment }) }) }),
		};
		window.wp = { media: vi.fn(() => frame) };
		return frame;
	}

	beforeEach(() => {
		reset();
		installFFC();
		window.ffc_ajax = { strings: {} };
		freshLoad();
	});

	afterEach(() => {
		delete window.wp;
		reset();
	});

	it('writes the chosen background URL and a decorative preview image', () => {
		document.body.innerHTML =
			'<button id="ffc_btn_media_lib">Media</button>' +
			'<input name="ffc_config[bg_image]" value="">' +
			'<div id="ffc_bg_image_preview"><span>old</span></div>';
		stubFrame({ url: 'https://example.test/bg.jpg' });

		window.$('#ffc_btn_media_lib').trigger('click');

		expect(window.wp.media).toHaveBeenCalledWith({
			title: 'Choose Background Image',
			button: { text: 'Use this image' },
			multiple: false,
		});
		expect(document.querySelector('input[name="ffc_config[bg_image]"]').value).toBe(
			'https://example.test/bg.jpg'
		);
		const img = document.querySelector('#ffc_bg_image_preview img');
		expect(document.querySelector('#ffc_bg_image_preview span')).toBeNull();
		expect(img.getAttribute('src')).toBe('https://example.test/bg.jpg');
		expect(img.getAttribute('alt')).toBe('');
		expect(img.className).toBe('ffc-bg-image-preview-img');
		expect(window.FFC.Admin.notify).toHaveBeenCalledWith('Background image selected!', 'success');
	});

	it('still confirms the selection on a screen with no URL field or preview', () => {
		document.body.innerHTML = '<button id="ffc_btn_media_lib">Media</button>';
		stubFrame({ url: 'https://example.test/bg.jpg' });

		window.$('#ffc_btn_media_lib').trigger('click');

		expect(window.FFC.Admin.notify).toHaveBeenCalledWith('Background image selected!', 'success');
	});

	it('reuses the background frame on a second click', () => {
		document.body.innerHTML = '<button id="ffc_btn_media_lib">Media</button>';
		const frame = stubFrame({ url: 'u' });

		window.$('#ffc_btn_media_lib').trigger('click');
		window.$('#ffc_btn_media_lib').trigger('click');

		expect(window.wp.media).toHaveBeenCalledTimes(1);
		expect(frame.open).toHaveBeenCalledTimes(2);
	});

	it('reuses the image-insert frame on a second click', () => {
		document.body.innerHTML =
			'<button id="ffc_btn_insert_image">Insert</button><textarea id="ffc_pdf_layout"></textarea>';
		const frame = stubFrame({ url: 'https://example.test/a.png' });

		window.$('#ffc_btn_insert_image').trigger('click');
		window.$('#ffc_btn_insert_image').trigger('click');

		expect(window.wp.media).toHaveBeenCalledTimes(1);
		expect(frame.open).toHaveBeenCalledTimes(2);
	});

	it('inserts nothing for an attachment without a URL', () => {
		document.body.innerHTML =
			'<button id="ffc_btn_insert_image">Insert</button><textarea id="ffc_pdf_layout">keep</textarea>';
		stubFrame({ url: '' });

		window.$('#ffc_btn_insert_image').trigger('click');

		expect(document.querySelector('#ffc_pdf_layout').value).toBe('keep');
		expect(window.FFC.Admin.notify).not.toHaveBeenCalled();
	});

	it('reports a missing editor when neither layout textarea is on the screen', () => {
		document.body.innerHTML = '<button id="ffc_btn_insert_image">Insert</button>';
		stubFrame({ url: 'https://example.test/a.png' });

		window.$('#ffc_btn_insert_image').trigger('click');

		expect(window.FFC.Admin.notify).toHaveBeenCalledWith('Error: HTML textarea not found', 'error');
	});
});
