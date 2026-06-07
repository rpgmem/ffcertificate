/**
 * Public CSV Download — certificate preview modal.
 *
 * Posts ffc_public_cert_preview and renders the certificate HTML inside an
 * iframe modal with sample data. Reads shared state/helpers from
 * window.FFCCsv (ffc-csv-download.js) and registers `api.onCertPreviewClick`.
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
				$btn.prop('disabled', false).text(strings.previewCertificate || 'Preview Certificate');
				showCertPreviewModal(data);
			})
			.catch(function (err) {
				$btn.prop('disabled', false).text(strings.previewCertificate || 'Preview Certificate');
				alert((err && err.fromServer && err.message) || strings.connError || 'Connection error.');
			});
	}

	function showCertPreviewModal(data) {
		var sampleData = buildSampleData(data.fields, data.previewSamples);
		var processedHtml = replacePlaceholders(data.html, sampleData);

		// Faithful preview: render at the real PDF page size (A4) for the form's
		// orientation, then scale the whole iframe to fit the modal (fitPreview).
		// Certificates default to landscape — the generator treats an unset
		// orientation as landscape — so `background-size: cover` crops exactly
		// like the generated PDF instead of being cropped by the modal's aspect.
		var isPortrait = data.orientation === 'portrait';
		var pageW = isPortrait ? 794 : 1123;
		var pageH = isPortrait ? 1123 : 794;

		var iframeHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
		iframeHtml += '<style>';
		iframeHtml += 'html, body { margin: 0; padding: 0; width: ' + pageW + 'px; height: ' + pageH + 'px; overflow: hidden; }';
		iframeHtml += 'body { font-family: Arial, Helvetica, sans-serif; position: relative; ';
		if (data.bg_image) {
			iframeHtml += 'background-image: url(' + data.bg_image + '); ';
			iframeHtml += 'background-size: cover; background-position: center; background-repeat: no-repeat; ';
		}
		iframeHtml += '}';
		iframeHtml += '</style></head><body>';
		iframeHtml += processedHtml;
		iframeHtml += '</body></html>';

		var previewTitle = strings.certPreviewTitle || 'Certificate Preview';
		var closeText    = strings.close || 'Close';
		var noteText     = strings.certPreviewNote || 'Placeholders replaced with sample data. QR code shown as placeholder.';

		var $modal = $('<div id="ffc-preview-modal">' +
			'<div class="ffc-preview-backdrop"></div>' +
			'<div class="ffc-preview-container">' +
				'<div class="ffc-preview-header">' +
					'<h2>' + esc(previewTitle) + '</h2>' +
					'<button type="button" class="ffc-preview-close" title="' + esc(closeText) + '">&times;</button>' +
				'</div>' +
				'<div class="ffc-preview-note">' + esc(noteText) + '</div>' +
				'<div class="ffc-preview-body">' +
					'<div class="ffc-preview-stage">' +
						'<iframe id="ffc-preview-iframe" frameborder="0"></iframe>' +
					'</div>' +
				'</div>' +
			'</div>' +
		'</div>');

		$('body').append($modal);

		var iframe    = $modal.find('#ffc-preview-iframe')[0];
		var iframeDoc = iframe.contentWindow || iframe.contentDocument;
		if (iframeDoc.document) {
			iframeDoc = iframeDoc.document;
		}
		iframeDoc.open();
		iframeDoc.write(iframeHtml);
		iframeDoc.close();

		// Scale the A4-sized iframe down to fit the modal body, preserving the
		// page aspect (no crop, no distortion). The stage wrapper is sized to the
		// scaled footprint so the iframe centers cleanly.
		function fitPreview() {
			var bodyEl  = $modal.find('.ffc-preview-body')[0];
			var stageEl = $modal.find('.ffc-preview-stage')[0];
			if (!bodyEl || !stageEl) { return; }
			var availW = bodyEl.clientWidth - 24;
			var availH = bodyEl.clientHeight - 24;
			if (availW <= 0 || availH <= 0) { return; }
			var scale = Math.min(availW / pageW, availH / pageH, 1);
			iframe.style.width           = pageW + 'px';
			iframe.style.height          = pageH + 'px';
			iframe.style.transformOrigin = 'top left';
			iframe.style.transform       = 'scale(' + scale + ')';
			stageEl.style.width  = Math.round(pageW * scale) + 'px';
			stageEl.style.height = Math.round(pageH * scale) + 'px';
		}

		requestAnimationFrame(function () {
			$modal.addClass('ffc-preview-visible');
			fitPreview();
		});
		$(window).on('resize.ffcCertPreview', fitPreview);

		function closePreview() {
			$modal.removeClass('ffc-preview-visible');
			setTimeout(function () { $modal.remove(); }, 200);
			$(document).off('keydown.ffcCertPreview');
			$(window).off('resize.ffcCertPreview');
		}

		$modal.find('.ffc-preview-close').on('click', closePreview);
		$modal.find('.ffc-preview-backdrop').on('click', closePreview);
		$(document).on('keydown.ffcCertPreview', function (e) {
			if (e.key === 'Escape') closePreview();
		});
	}

	// Seed the preview from the canonical PHP sample map
	// (CertificatePreviewSamples::get_map(), delivered in the AJAX payload
	// as previewSamples) so system placeholders fill the same as the real
	// generators. The form's own fields are overlaid on top.
	function buildSampleData(fields, previewSamples) {
		var data = $.extend({}, previewSamples || {});

		if (fields && fields.length) {
			for (var i = 0; i < fields.length; i++) {
				if (fields[i].name && !data[fields[i].name]) {
					data[fields[i].name] = fields[i].label || fields[i].name;
				}
			}
		}

		return data;
	}

	function replacePlaceholders(html, data) {
		html = html.replace(/\{\{(\w+)\}\}/g, function (match, key) {
			return data[key] !== undefined ? data[key] : match;
		});
		html = html.replace(/\{\{qr_code[^}]*\}\}/g,
			'<svg width="150" height="150" viewBox="0 0 150 150" xmlns="http://www.w3.org/2000/svg">' +
			'<rect width="150" height="150" fill="#f0f0f0" stroke="#ccc" stroke-width="1"/>' +
			'<text x="75" y="70" text-anchor="middle" font-size="12" fill="#999">QR Code</text>' +
			'<text x="75" y="90" text-anchor="middle" font-size="10" fill="#bbb">(preview)</text>' +
			'</svg>'
		);
		html = html.replace(/\{\{validation_url[^}]*\}\}/g,
			'<a href="#" style="color:#0073aa;">https://example.com/valid/#token=abc123</a>'
		);
		return html;
	}

	api.onCertPreviewClick = onCertPreviewClick;

})(jQuery);
