// Coverage for `assets/js/ffc-cert-template-preview.js` (#865): the
// certificate-template pool "Preview" row action opens the server-rendered
// sample preview page in an overlay iframe. The IIFE binds one delegated
// `a.ffc-template-preview` click handler on document, so the script is loaded
// once; each test mounts a fresh link and drives the interaction.
import { describe, it, expect, beforeAll, beforeEach } from 'vitest';
import { loadScript } from './helpers.js';

describe('ffc-cert-template-preview overlay', () => {
	beforeAll(() => {
		window.ffcCertTemplatePreview = { title: 'Preview!', close: 'Fechar' };
		loadScript('assets/js/ffc-cert-template-preview.js');
	});

	beforeEach(() => {
		// Clear any overlay + lingering Escape handler between tests.
		$('#ffc-tpl-preview-overlay').remove();
		$(document).off('keydown.ffcTplPreview');
		document.body.innerHTML = '';
	});

	function mountLink(href) {
		document.body.innerHTML = href
			? '<a class="ffc-template-preview" href="' + href + '">Preview</a>'
			: '<a class="ffc-template-preview">Preview</a>';
	}

	it('opens an overlay iframe pointing at the link href, with localized title', () => {
		mountLink('/wp-admin/admin.php?action=ffc_preview_cert_template&post=5&_wpnonce=abc');
		$('a.ffc-template-preview').trigger('click');

		const overlay = document.getElementById('ffc-tpl-preview-overlay');
		expect(overlay).not.toBeNull();

		const frame = overlay.querySelector('iframe.ffc-tpl-preview-frame');
		expect(frame).not.toBeNull();
		expect(frame.getAttribute('src')).toContain('action=ffc_preview_cert_template');
		expect(frame.getAttribute('sandbox')).toBe('allow-same-origin');
		expect(overlay.querySelector('.ffc-tpl-preview-title').textContent).toBe('Preview!');
		expect(overlay.querySelector('.ffc-tpl-preview-close').textContent).toBe('Fechar');
	});

	it('closes on the close button', () => {
		mountLink('/x?post=1');
		$('a.ffc-template-preview').trigger('click');
		expect(document.getElementById('ffc-tpl-preview-overlay')).not.toBeNull();

		$('.ffc-tpl-preview-close').trigger('click');
		expect(document.getElementById('ffc-tpl-preview-overlay')).toBeNull();
	});

	it('closes on Escape', () => {
		mountLink('/x?post=1');
		$('a.ffc-template-preview').trigger('click');

		$(document).trigger($.Event('keydown', { key: 'Escape' }));
		expect(document.getElementById('ffc-tpl-preview-overlay')).toBeNull();
	});

	it('closes on a backdrop click but not on inner clicks', () => {
		mountLink('/x?post=1');
		$('a.ffc-template-preview').trigger('click');
		const overlay = document.getElementById('ffc-tpl-preview-overlay');

		// Clicking an inner element (the title) must NOT close.
		$(overlay).find('.ffc-tpl-preview-title').trigger('click');
		expect(document.getElementById('ffc-tpl-preview-overlay')).not.toBeNull();

		// Clicking the backdrop itself closes.
		$(overlay).trigger('click');
		expect(document.getElementById('ffc-tpl-preview-overlay')).toBeNull();
	});

	it('does nothing when the link has no href', () => {
		mountLink('');
		$('a.ffc-template-preview').trigger('click');
		expect(document.getElementById('ffc-tpl-preview-overlay')).toBeNull();
	});
});
