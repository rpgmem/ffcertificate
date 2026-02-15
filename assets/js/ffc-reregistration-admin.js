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
        });
    }

})(jQuery);
