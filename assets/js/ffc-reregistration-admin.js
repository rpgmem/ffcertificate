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
        initTransferList();
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
                    '<span class="dashicons dashicons-media-document" style="vertical-align:middle;font-size:14px"></span> ' + (S.ficha || 'Record')
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
                    '<span class="dashicons dashicons-media-document" style="vertical-align:middle;font-size:14px"></span> ' + (S.ficha || 'Record')
                );
                alert(S.errorGenerating || 'Error generating ficha.');
            });
        });
    }

    /**
     * Audience transfer list (dual-column picker)
     */
    function initTransferList() {
        var $wrap = $('.ffc-transfer-list');
        if (!$wrap.length) return;

        var allAudiences = JSON.parse($wrap.attr('data-audiences') || '[]');
        var selectedIds = JSON.parse($wrap.attr('data-selected') || '[]');
        var byId = {};
        allAudiences.forEach(function (a) { byId[a.id] = a; });

        var $available = $wrap.find('.ffc-transfer-available .ffc-transfer-items');
        var $selected = $wrap.find('.ffc-transfer-selected .ffc-transfer-items');
        var $hidden = $wrap.find('.ffc-transfer-hidden-inputs');
        var $search = $wrap.find('.ffc-transfer-search');
        var $memberCount = $wrap.siblings('.ffc-transfer-member-count');
        var memberTimer = null;

        function render() {
            var filter = ($search.val() || '').toLowerCase();
            $available.empty();
            $selected.empty();
            $hidden.empty();

            allAudiences.forEach(function (a) {
                var inSelected = selectedIds.indexOf(a.id) !== -1;
                var cls = 'ffc-transfer-item' + (a.parent ? ' ffc-transfer-child' : '');
                var dot = '<span class="ffc-color-dot" style="background:' + a.color + '"></span>';
                var label = (a.parent ? '— ' : '') + a.name;
                var html = '<div class="' + cls + '" data-id="' + a.id + '">' + dot + ' ' +
                    '<span class="ffc-transfer-label">' + label + '</span></div>';

                if (inSelected) {
                    $selected.append(html);
                    $hidden.append('<input type="hidden" name="rereg_audience_ids[]" value="' + a.id + '">');
                } else {
                    if (!filter || a.name.toLowerCase().indexOf(filter) !== -1) {
                        $available.append(html);
                    }
                }
            });

            updateMemberCount();
        }

        function addAudience(id) {
            if (selectedIds.indexOf(id) !== -1) return;
            selectedIds.push(id);
            // If parent, cascade children
            var a = byId[id];
            if (a && a.children) {
                a.children.forEach(function (childId) {
                    if (selectedIds.indexOf(childId) === -1) {
                        selectedIds.push(childId);
                    }
                });
            }
        }

        function removeAudience(id) {
            var idx = selectedIds.indexOf(id);
            if (idx === -1) return;
            selectedIds.splice(idx, 1);
            // If parent, cascade-remove children
            var a = byId[id];
            if (a && a.children) {
                a.children.forEach(function (childId) {
                    var ci = selectedIds.indexOf(childId);
                    if (ci !== -1) selectedIds.splice(ci, 1);
                });
            }
        }

        function updateMemberCount() {
            if (memberTimer) clearTimeout(memberTimer);
            if (!selectedIds.length) {
                $memberCount.html('');
                return;
            }
            memberTimer = setTimeout(function () {
                $.post(ffcReregistrationAdmin.ajaxUrl, {
                    action: 'ffc_rereg_count_members',
                    nonce: ffcReregistrationAdmin.adminNonce,
                    audience_ids: selectedIds
                }, function (res) {
                    if (res.success) {
                        var S = ffcReregistrationAdmin.strings || {};
                        $memberCount.html('<strong>' + (S.affectedUsers || 'Affected users:') + '</strong> ' + res.data.count);
                    }
                });
            }, 300);
        }

        // Click to toggle highlight
        $wrap.on('click', '.ffc-transfer-item', function () {
            $(this).toggleClass('ffc-transfer-highlight');
        });

        // Double-click to move
        $available.on('dblclick', '.ffc-transfer-item', function () {
            addAudience(parseInt($(this).data('id'), 10));
            render();
        });
        $selected.on('dblclick', '.ffc-transfer-item', function () {
            removeAudience(parseInt($(this).data('id'), 10));
            render();
        });

        // Arrow buttons
        $wrap.find('.ffc-transfer-add').on('click', function () {
            $available.find('.ffc-transfer-highlight').each(function () {
                addAudience(parseInt($(this).data('id'), 10));
            });
            render();
        });
        $wrap.find('.ffc-transfer-add-all').on('click', function () {
            allAudiences.forEach(function (a) { addAudience(a.id); });
            render();
        });
        $wrap.find('.ffc-transfer-remove').on('click', function () {
            $selected.find('.ffc-transfer-highlight').each(function () {
                removeAudience(parseInt($(this).data('id'), 10));
            });
            render();
        });
        $wrap.find('.ffc-transfer-remove-all').on('click', function () {
            selectedIds = [];
            render();
        });

        // Search filter
        $search.on('input', function () { render(); });

        // Form validation — require at least one audience
        $wrap.closest('form').on('submit', function (e) {
            if (!selectedIds.length) {
                e.preventDefault();
                $wrap.find('.ffc-transfer-selected').addClass('ffc-transfer-error');
                setTimeout(function () {
                    $wrap.find('.ffc-transfer-selected').removeClass('ffc-transfer-error');
                }, 2000);
            }
        });

        render();
    }

})(jQuery);
