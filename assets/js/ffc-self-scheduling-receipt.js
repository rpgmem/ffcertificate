/**
 * Self-scheduling appointment receipt — "Download PDF" button.
 *
 * Extracted from a wp_add_inline_script block in
 * AppointmentReceiptHandler::display_receipt(). Wires #ffc-download-pdf-btn
 * to window.ffcGeneratePDF (from ffc-pdf-generator), preferring the
 * server-built pdfData and falling back to the rendered receipt HTML. All
 * data arrives via the localized `ffcReceiptData` object — no PHP
 * interpolation in this file.
 */
(function ($) {
	'use strict';

	$(function () {
		var data = window.ffcReceiptData || {};

		$('#ffc-download-pdf-btn').on('click', function () {
			if (typeof window.ffcGeneratePDF !== 'function') {
				window.console.error('FFC PDF Generator not loaded');
				window.alert(data.errorMsg);
				return;
			}

			if (data.pdfData && data.pdfData.html) {
				window.ffcGeneratePDF(data.pdfData, data.pdfData.filename || 'appointment_receipt.pdf');
				return;
			}

			var htmlContent = $('#ffc-receipt-content').html();
			var filename = data.validationCode
				? 'Appointment_Receipt_' + data.validationCode + '.pdf'
				: 'Appointment_Receipt_' + data.appointmentId + '.pdf';
			window.ffcGeneratePDF({ html: htmlContent, bg_image: null }, filename);
		});
	});
})(jQuery);
