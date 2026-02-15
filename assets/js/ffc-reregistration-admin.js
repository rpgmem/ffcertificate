/**
 * Reregistration Admin - Confirmation dialogs and bulk selection
 *
 * @since 4.11.0
 * @package FreeFormCertificate
 */
(function ($) {
    'use strict';

    $(function () {
        initSelectAll();
        initBulkConfirm();
        initReturnToDraftConfirm();
        initFichaDownload();
    });

    /**
     * Select-all checkbox toggles all submission checkboxes
     */
    function initSelectAll() {
        $('#cb-select-all').on('change', function () {
            var checked = $(this).is(':checked');
            $('input[name="submission_ids[]"]').prop('checked', checked);
        });
    }

    /**
     * Confirm before submitting bulk actions
     */
    function initBulkConfirm() {
        $('#ffc-submissions-form').on('submit', function (e) {
            var action = $(this).find('select[name="bulk_action"]').val();
            var checked = $('input[name="submission_ids[]"]:checked').length;

            if (!action || !checked) {
                e.preventDefault();
                return;
            }

            if (action === 'approve') {
                var msg = (window.ffcReregistrationAdmin && window.ffcReregistrationAdmin.strings)
                    ? window.ffcReregistrationAdmin.strings.confirmApprove
                    : 'Approve selected submissions?';
                if (!confirm(msg)) {
                    e.preventDefault();
                }
            }

            if (action === 'return_to_draft') {
                var msg2 = (window.ffcReregistrationAdmin && window.ffcReregistrationAdmin.strings)
                    ? window.ffcReregistrationAdmin.strings.confirmReturnToDraft
                    : 'Return selected submissions to draft? Users will be able to edit and resubmit.';
                if (!confirm(msg2)) {
                    e.preventDefault();
                }
            }
        });
    }

    /**
     * Confirm before returning a single submission to draft
     */
    function initReturnToDraftConfirm() {
        $(document).on('click', '.ffc-return-draft-btn', function (e) {
            var S = (window.ffcReregistrationAdmin && window.ffcReregistrationAdmin.strings) || {};
            var msg = S.confirmReturnToDraft
                || 'Return this submission to draft? The user will be able to edit and resubmit.';
            if (!confirm(msg)) {
                e.preventDefault();
            }
        });
    }

    /**
     * Ficha PDF download via AJAX + client-side generation
     */
    function initFichaDownload() {
        $(document).on('click', '.ffc-ficha-btn', function () {
            var $btn = $(this);
            var subId = $btn.data('submission-id');
            var S = (window.ffcReregistrationAdmin && window.ffcReregistrationAdmin.strings) || {};

            if (!subId) return;

            $btn.prop('disabled', true).text(S.generatingPdf || 'Generating PDF...');

            $.post(ffcReregistrationAdmin.ajaxUrl, {
                action: 'ffc_generate_ficha',
                nonce: ffcReregistrationAdmin.fichaNonce,
                submission_id: subId
            }, function (res) {
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-media-document" style="vertical-align:middle;font-size:14px"></span> Ficha'
                );

                if (res.success && res.data.pdf_data) {
                    if (typeof window.ffcGeneratePDF === 'function') {
                        window.ffcGeneratePDF(res.data.pdf_data, res.data.pdf_data.filename || 'ficha.pdf');
                    } else {
                        alert(S.errorGenerating || 'PDF generator not available.');
                    }
                } else {
                    alert(res.data && res.data.message ? res.data.message : S.errorGenerating || 'Error generating ficha.');
                }
            }).fail(function () {
                $btn.prop('disabled', false).html(
                    '<span class="dashicons dashicons-media-document" style="vertical-align:middle;font-size:14px"></span> Ficha'
                );
                alert(S.errorGenerating || 'Error generating ficha.');
            });
        });
    }

})(jQuery);
