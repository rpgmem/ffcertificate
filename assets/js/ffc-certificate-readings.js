/**
 * Certificate terminal readings: the Export CSV button (#2892).
 *
 * Drives the shared window.FFCBatchedExport engine through the unified
 * `ffc_export_*` dispatcher, with the form and the filters the screen was
 * drawn with, which the button carries.
 *
 * @since 6.53.0
 */
(function (root) {
    'use strict';

    function config() {
        return root.ffcCertificateReadings || {};
    }

    /**
     * What the button asks the export for.
     *
     * @param {HTMLElement} button The Export CSV button.
     * @return {{form_id: string, result: string, s: string}}
     */
    function startData(button) {
        return {
            form_id: button.getAttribute('data-form-id') || '',
            result: button.getAttribute('data-result') || '',
            s: button.getAttribute('data-search') || ''
        };
    }

    /**
     * Start the export for a button.
     *
     * @param {HTMLElement} button The Export CSV button.
     * @return {boolean} Whether an export started.
     */
    function exportCsv(button) {
        var c = config();
        var s = c.strings || {};
        if (!root.FFCBatchedExport || !c.nonce) {
            return false;
        }
        root.FFCBatchedExport.run({
            type: 'certificate_readings',
            ajaxUrl: c.ajaxUrl,
            nonce: c.nonce,
            button: root.jQuery ? root.jQuery(button) : button,
            overlay: true,
            strings: {
                preparing: s.preparing,
                exporting: s.exporting,
                downloading: s.done,
                error: s.error
            },
            startData: startData(button)
        });
        return true;
    }

    root.FFCCertificateReadings = { startData: startData, exportCsv: exportCsv };

    if (root.document) {
        root.document.addEventListener('click', function (event) {
            var target = event.target && event.target.closest ? event.target.closest('#ffc-readings-export') : null;
            if (target) {
                event.preventDefault();
                exportCsv(target);
            }
        });
    }
}(typeof window !== 'undefined' ? window : this));
