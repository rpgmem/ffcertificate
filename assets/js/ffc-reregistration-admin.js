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
        initReviewDecisionModal();
        initRecordDownload();
        initSubmissionDetailsModal();
        initCsvExport();
        initSendInvitations();
    });

    /**
     * Manual invitation button (#1190).
     *
     * The server decides who is owed an email and stamps the ones it reached,
     * so this is idempotent: a second press right after the first reports zero.
     * Delegated from `document` and registered here rather than folded into
     * another init that early-returns on this screen -- that is exactly how the
     * audience-bookings handler was silently dropped (#783).
     */
    function initSendInvitations() {
        $(document).on('click', '#ffc-rereg-send-invitations', function () {
            var $btn = $(this);
            var $msg = $btn.siblings('.ffc-rereg-invite-msg');
            var cfg = window.ffcReregistrationAdmin || {};
            var S = cfg.strings || {};

            if ($btn.prop('disabled')) { return; }
            $btn.prop('disabled', true);
            $msg.text(S.inviteSending || 'Sending…');

            FFC.request(
                'ffc_rereg_send_invitations',
                { reregistration_id: $btn.data('rereg-id') },
                { nonce: cfg.adminNonce, ajaxUrl: cfg.ajaxUrl }
            )
                .then(function (data) {
                    $msg.text((data && data.message) || '');
                })
                .catch(function (err) {
                    $msg.text((err && err.message) || S.inviteError || 'An error occurred.');
                })
                .then(function () {
                    $btn.prop('disabled', false);
                });
        });
    }

    /**
     * Batched CSV export (#772). The "Export CSV" button drives the unified
     * ffc_export_* dispatcher via the shared window.FFCBatchedExport driver,
     * carrying the campaign id. Export order is id-DESC (a stable keyset).
     */
    function initCsvExport() {
        $(document).on('click', '#ffc-rereg-export-btn', function () {
            if (!window.FFCBatchedExport) { return; }
            var cfg = window.ffcReregistrationAdmin || {};
            var s = cfg.strings || {};
            var exportNonce = cfg.exportNonce || '';
            if (!exportNonce) { return; }

            var $btn = $(this);

            // Progress is shown through the shared FFCProgressOverlay modal,
            // driven by the driver itself (overlay: true) — same UI as the
            // public download (#786).
            window.FFCBatchedExport.run({
                type: 'reregistration',
                ajaxUrl: cfg.ajaxUrl,
                nonce: exportNonce,
                button: $btn,
                overlay: true,
                strings: {
                    preparing: s.exportPreparing,
                    exporting: s.exportProgress,
                    downloading: s.exportDone,
                    error: s.exportError
                },
                startData: { id: $btn.data('id') || '' }
            });
        });
    }

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
                // Asks for the reason in the review modal instead (#2242).
                e.preventDefault();
                var ids = $('input[name="submission_ids[]"]:checked').map(function () {
                    return $(this).val();
                }).get();
                openReviewDecision('return_to_draft', ids);
            }
        });
    }

    /**
     * Reject and Return to Draft (#2242): one modal asks for the reason the
     * member reads on the dashboard and in the e-mail. Submitting it is the
     * confirmation; the server refuses a reason that is too short.
     */
    function openReviewDecision(decision, ids) {
        var $modal = $('#ffc-review-decision-modal');
        if (!$modal.length || !ids.length) return;

        var S = (window.ffcReregistrationAdmin && window.ffcReregistrationAdmin.strings) || {};
        var isReject = decision === 'reject';
        var $form = $modal.find('form');
        var $ids = $form.find('.ffc-review-decision-ids').empty();

        ids.forEach(function (id) {
            $('<input>', { type: 'hidden', name: 'submission_ids[]', value: id }).appendTo($ids);
        });
        $form.find('input[name="decision"]').val(decision);
        $form.find('textarea[name="review_reason"]').val('');
        $modal.find('#ffc-review-decision-title').text(isReject ? (S.reviewRejectTitle || 'Reject submission') : (S.reviewReturnTitle || 'Return to draft'));
        $modal.find('.ffc-review-decision-intro').text(
            (isReject ? (S.reviewRejectIntro || '') : (S.reviewReturnIntro || '')).replace('%d', String(ids.length))
        );
        $form.find('.ffc-review-decision-confirm').each(function () {
            $(this).toggle($(this).data('decision') === decision);
        });

        $modal.show();
        $('body').addClass('ffc-rereg-modal-open');
        $form.find('textarea[name="review_reason"]').trigger('focus');
    }

    function initReviewDecisionModal() {
        var $modal = $('#ffc-review-decision-modal');
        if (!$modal.length) return;

        function closeModal() {
            $modal.hide();
            $('body').removeClass('ffc-rereg-modal-open');
        }

        $(document).on('click', '.ffc-review-decision-btn', function (e) {
            e.preventDefault();
            openReviewDecision($(this).data('decision'), [String($(this).data('submission-id'))]);
        });

        $modal.on('click', '.ffc-rereg-modal-close, .ffc-rereg-modal-backdrop', function (e) {
            e.preventDefault();
            closeModal();
        });
        $(document).on('keydown.ffcReviewDecision', function (e) {
            if (e.key === 'Escape' && $modal.css('display') !== 'none') {
                closeModal();
            }
        });
    }

    /**
     * Record PDF download via AJAX + client-side generation
     */
    function initRecordDownload() {
        $(document).on('click', '.ffc-record-btn', function () {
            var $btn = $(this);
            var subId = $btn.data('submission-id');
            var S = (window.ffcReregistrationAdmin && window.ffcReregistrationAdmin.strings) || {};

            if (!subId) return;

            $btn.prop('disabled', true).text(S.generatingPdf || 'Generating PDF...');

            function restoreBtn() {
                // The icon is the button's own class (#1904), so the label is text.
                $btn.prop('disabled', false).text(S.record || 'Record');
            }

            FFC.request(
                'ffc_generate_record',
                { submission_id: subId },
                { nonce: ffcReregistrationAdmin.recordNonce, ajaxUrl: ffcReregistrationAdmin.ajaxUrl }
            )
                .then(function (data) {
                    restoreBtn();
                    if (data && data.pdf_data && typeof window.ffcGeneratePDF === 'function') {
                        window.ffcGeneratePDF(data.pdf_data, data.pdf_data.filename || 'record.pdf');
                    } else {
                        alert(S.errorGenerating || 'PDF generator not available.');
                    }
                })
                .catch(function (err) {
                    restoreBtn();
                    alert((err && err.fromServer && err.message) || S.errorGenerating || 'Error generating record.');
                });
        });
    }

    /**
     * Submission details modal — reads grouped/decrypted field values via AJAX.
     */
    function initSubmissionDetailsModal() {
        var $modal = $('#ffc-submission-details-modal');
        if (!$modal.length) return;

        var $body = $modal.find('.ffc-rereg-modal-body');

        function openModal() {
            $modal.show();
            $('body').addClass('ffc-rereg-modal-open');
        }

        function showDetailsError(text) {
            $body.empty().append(window.FFC.notice('danger', 'error', text, '', { role: 'alert' }));
        }

        function closeModal() {
            $modal.hide();
            $('body').removeClass('ffc-rereg-modal-open');
            $body.html('<p class="ffc-rereg-modal-loading"></p>');
        }

        $(document).on('click', '.ffc-view-details-btn', function (e) {
            e.preventDefault();
            var subId = $(this).data('submission-id');
            if (!subId) return;

            var S = (window.ffcReregistrationAdmin && window.ffcReregistrationAdmin.strings) || {};
            $body.html('<p class="ffc-rereg-modal-loading">' + (S.loadingDetails || 'Loading…') + '</p>');
            openModal();

            FFC.request(
                'ffc_view_submission_details',
                { submission_id: subId },
                { nonce: ffcReregistrationAdmin.viewDetailsNonce, ajaxUrl: ffcReregistrationAdmin.ajaxUrl }
            )
                .then(function (data) {
                    if (data && data.html) {
                        $body.html(data.html);
                    } else {
                        showDetailsError(S.errorLoadingDetails || 'Failed to load submission details.');
                    }
                })
                .catch(function (err) {
                    // The server's message is text: FFC.notice() writes it with
                    // textContent, so it can never become markup (#1843).
                    showDetailsError((err && err.fromServer && err.message) || S.errorLoadingDetails || 'Failed to load submission details.');
                });
        });

        // Close handlers: X button, backdrop, ESC key
        $modal.on('click', '.ffc-rereg-modal-close, .ffc-rereg-modal-backdrop', function () {
            closeModal();
        });
        $(document).on('keydown.ffcDetails', function (e) {
            if (e.key === 'Escape' && $modal.is(':visible')) {
                closeModal();
            }
        });
    }

    /**
     * Members the chosen audiences reach, under the shared audience picker
     * (#1648). The picker announces every change; this screen only counts.
     * Bound at load, before the picker's own ready handler renders the first
     * time, so the initial count is not missed.
     */
    var memberTimer = null;
    $(document).on('ffc:transfer-list-change', '.ffc-transfer-list', function (e, selectedIds) {
        var $memberCount = $(this).siblings('.ffc-transfer-member-count');
        if (!$memberCount.length) return;
        if (memberTimer) clearTimeout(memberTimer);
        if (!selectedIds || !selectedIds.length) {
            $memberCount.html('');
            return;
        }
        memberTimer = setTimeout(function () {
            FFC.request(
                'ffc_rereg_count_members',
                { audience_ids: selectedIds },
                { nonce: ffcReregistrationAdmin.adminNonce, ajaxUrl: ffcReregistrationAdmin.ajaxUrl }
            )
                .then(function (data) {
                    var S = ffcReregistrationAdmin.strings || {};
                    $memberCount.html('<strong>' + (S.affectedUsers || 'Affected users:') + '</strong> ' + data.count);
                })
                .catch(function () { /* silent — UI count is best-effort */ });
        }, 300);
    });

})(jQuery);
