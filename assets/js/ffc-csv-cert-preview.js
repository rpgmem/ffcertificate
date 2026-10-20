/**
 * Public CSV Download — certificate preview modal.
 *
 * Posts ffc_public_cert_preview, which returns the page the PDF prints with
 * sample data, captures it as an image (FFCLayoutPreview: html2canvas, stopping
 * before the PDF) and shows that image in a modal. Reads shared state/helpers
 * from window.FFCCsv (ffc-csv-download.js) and registers
 * `api.onCertPreviewClick`.
 *
 * @since 5.2.0 (split out of ffc-csv-download.js)
 */
(function ($) {
	'use strict';

	var api     = window.FFCCsv;
	var strings = api.strings;
	var esc     = api.esc;

	function onCertPreviewClick() {
		var $btn = $(this);
		$btn.prop('disabled', true).text(strings.loadingPreview || 'Loading preview…');

		FFC.request('ffc_public_cert_preview', api.formData)
			.then(function (data) {
				return window.FFCLayoutPreview.capture(data.html, data.width, data.height);
			})
			.then(function (src) {
				$btn.prop('disabled', false).text(strings.previewCertificate || 'Preview Certificate');
				showCertPreviewModal(src);
			})
			.catch(function (err) {
				$btn.prop('disabled', false).text(strings.previewCertificate || 'Preview Certificate');
				alert((err && err.fromServer && err.message) || strings.connError || 'Connection error.');
			});
	}

	function showCertPreviewModal(src) {
		var previewTitle = strings.certPreviewTitle || 'Certificate Preview';
		var closeText    = strings.close || 'Close';
		var noteText     = strings.certPreviewNote || 'Placeholders replaced with sample data.';

		// The page is a PNG captured from the server's render: no frame, no markup runs.
		var $modal = $('<div id="ffc-preview-modal">' +
			'<div class="ffc-preview-backdrop"></div>' +
			'<div class="ffc-preview-container">' +
				'<div class="ffc-preview-header">' +
					'<h2>' + esc(previewTitle) + '</h2>' +
					FFC.buttonHtml({ label: closeText, category: 'neutral', icon: 'cross', iconOnly: true, className: 'ffc-preview-close' }) +
				'</div>' +
				'<div class="ffc-preview-note">' + esc(noteText) + '</div>' +
				'<div class="ffc-preview-body">' +
					'<img class="ffc-preview-page" alt="">' +
				'</div>' +
			'</div>' +
		'</div>');

		$modal.find('.ffc-preview-page').attr({ alt: previewTitle, src: src });
		$('body').append($modal);
		requestAnimationFrame(function () {
			$modal.addClass('ffc-preview-visible');
		});

		function closePreview() {
			$modal.removeClass('ffc-preview-visible');
			setTimeout(function () { $modal.remove(); }, 200);
			$(document).off('keydown.ffcCertPreview');
		}

		$modal.find('.ffc-preview-close').on('click', closePreview);
		$modal.find('.ffc-preview-backdrop').on('click', closePreview);
		$(document).on('keydown.ffcCertPreview', function (e) {
			if (e.key === 'Escape') closePreview();
		});
	}

	api.onCertPreviewClick = onCertPreviewClick;

})(jQuery);
