/**
 * Certificate-template preview overlay (#865).
 *
 * A "Preview" row action (`a.ffc-template-preview`) on the certificate-template
 * pool list opens the server-rendered sample preview page in an overlay iframe.
 * The server (admin_action `ffc_preview_cert_template`) renders the template
 * with sample data + the configured branding logos, so the JS is a thin
 * viewer — no template logic here. Graceful no-JS fallback: the link carries
 * `target="_blank"`, so with JS disabled it opens the preview page in a new tab.
 */
(function ($) {
	'use strict';

	var strings = window.ffcCertTemplatePreview || {};

	function openOverlay(url) {
		if (!url) {
			return;
		}

		var titleText = strings.title || 'Template preview';
		var closeText = strings.close || 'Close';

		var $overlay = $(
			'<div id="ffc-tpl-preview-overlay" role="dialog" aria-modal="true" ' +
			'style="position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:999999;display:flex;flex-direction:column;">' +
				'<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;background:#1d2327;color:#fff;">' +
					'<strong class="ffc-tpl-preview-title" style="font-size:14px;"></strong>' +
					'<button type="button" class="button ffc-tpl-preview-close"></button>' +
				'</div>' +
				'<div style="flex:1;overflow:auto;padding:16px;">' +
					'<iframe class="ffc-tpl-preview-frame" sandbox="allow-same-origin" ' +
					'style="width:100%;height:100%;border:0;background:#fff;"></iframe>' +
				'</div>' +
			'</div>'
		);

		$overlay.attr('aria-label', titleText);
		$overlay.find('.ffc-tpl-preview-title').text(titleText);
		$overlay.find('.ffc-tpl-preview-close').text(closeText);
		// src set via attr() (not string concat) so the URL stays a literal
		// attribute value and never a markup sink.
		$overlay.find('.ffc-tpl-preview-frame').attr('src', url);

		function close() {
			$overlay.remove();
			$(document).off('keydown.ffcTplPreview');
		}

		$overlay.find('.ffc-tpl-preview-close').on('click', close);
		$overlay.on('click', function (e) {
			if (e.target === $overlay[0]) {
				close();
			}
		});
		$(document).on('keydown.ffcTplPreview', function (e) {
			if (e.key === 'Escape') {
				close();
			}
		});

		$('body').append($overlay);
	}

	$(document).on('click', 'a.ffc-template-preview', function (e) {
		e.preventDefault();
		openOverlay($(this).attr('href'));
	});
})(jQuery);
