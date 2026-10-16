/**
 * Free Form Certificate - Admin JavaScript
 * v3.1.0 - Modular architecture (Field Builder and PDF modules extracted)
 *
 * Core admin functionality:
 * - Notification system
 * - Generate Tickets
 * - Migration Manager dropdown
 * - Restriction field toggles
 *
 * Modules (loaded separately):
 * - ffc-admin-field-builder.js - Form field creation/editing
 * - ffc-admin-pdf.js - PDF template management and downloads
 *
 * @since 3.1.0
 */

(function($) {
    'use strict';

    // console.log('[FFC Admin] Initializing v3.1.0...');

    // ==========================================================================
    // PAGE NOTICES - FFC.Admin.notify(), the JS twin of Core\AdminNotice
    // ==========================================================================

    // Each type, with its registry icon and its icon tone.
    var NOTICE_TYPES = {
        success: 'ffc-icon-success ffc-icon-tone-success',
        info: 'ffc-icon-info ffc-icon-tone-info',
        warning: 'ffc-icon-warning ffc-icon-tone-warning',
        error: 'ffc-icon-error ffc-icon-tone-danger'
    };

    /**
     * Show a page notice: WordPress's own `.notice`, after the screen's
     * heading, with the type's registry icon and the one dismissibility rule
     * `Core\AdminNotice` applies (#1843). Success and info close, and fade
     * after `duration` (5s by default); a warning or an error has no close
     * button and stays until the next notice replaces it, unless the caller
     * passes a duration.
     *
     * The message is plain text and is written with `.text()`, so a server
     * message can never inject markup. Only one notice shows at a time.
     *
     * @param {string} message  Plain text.
     * @param {string} [type]   success | info | warning | error (default info).
     * @param {number} [duration] Milliseconds before it fades; 0 keeps it.
     * @return {jQuery} The notice.
     */
    function notify(message, type, duration) {
        type = NOTICE_TYPES[type] ? type : 'info';
        var dismissible = 'success' === type || 'info' === type;
        if (typeof duration !== 'number') {
            duration = dismissible ? 5000 : 0;
        }

        $('.ffc-admin-notification').remove();

        var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};
        var dismissText = strings.dismiss || 'Dismiss';

        var $notif = $('<div class="ffc-admin-notification ffc-admin-notice notice"></div>')
            .addClass('notice-' + type)
            .attr('role', 'error' === type || 'warning' === type ? 'alert' : 'status');
        var $icon = $('<span class="ffc-admin-notice-icon" aria-hidden="true"></span>')
            .addClass(NOTICE_TYPES[type]);
        $notif.append($('<p></p>').append($icon).append(document.createTextNode(String(message))));

        function close() {
            $notif.fadeOut(200, function() { $(this).remove(); });
        }

        if (dismissible) {
            $notif.addClass('is-dismissible');
            $('<button type="button" class="notice-dismiss"></button>')
                .append($('<span class="screen-reader-text"></span>').text(dismissText))
                .on('click', close)
                .appendTo($notif);
        }

        if ($('.wrap > h1').length) {
            $('.wrap > h1').first().after($notif);
        } else {
            $('#wpbody-content').prepend($notif);
        }

        if (duration > 0) {
            setTimeout(close, duration);
        }

        return $notif;
    }

    // Export to FFC.Admin for the modules. `showNotification` is the name
    // this had before #1843 and stays as an alias for third-party callers.
    window.FFC = window.FFC || {};
    window.FFC.Admin = window.FFC.Admin || {};
    window.FFC.Admin.notify = notify;
    window.FFC.Admin.showNotification = notify;

    // ==========================================================================
    // GENERATE TICKETS
    // ==========================================================================

    $(document).on('click', '#ffc_btn_generate_codes', function(e) {
        e.preventDefault();
        // console.log('[FFC] Generate Tickets clicked');

        // Read from input field instead of prompt
        var quantity = $('#ffc_qty_codes').val();

        if (!quantity || isNaN(quantity) || quantity < 1) {
            // Show inline error instead of alert
            var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};
            var errorMsg = strings.enterValidNumber || 'Please enter a valid number.';
            FFC.status($('#ffc_gen_status'), 'error', errorMsg);
            $('#ffc_qty_codes').focus();
            return;
        }

        var $btn = $(this);
        var $status = $('#ffc_gen_status');
        var originalText = $btn.text();

        // `strings` is declared earlier in this function (line ~80) — `var`
        // hoists to function scope, so we just re-assign here without
        // redeclaring (the line-80 path only runs when quantity is invalid
        // and the function returns; this path needs its own assignment).
        strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};
        var generatingText = strings.generating || 'Generating...';
        var generatingTicketsText = strings.generatingTickets || 'Generating tickets...';

        $btn.prop('disabled', true).text(generatingText);
        FFC.status($status, 'busy', generatingTicketsText);

        // Use nonce from ffc_ajax (localized by class-ffc-admin.php)
        var nonce = (typeof ffc_ajax !== 'undefined') ? ffc_ajax.nonce : '';

        // console.log('[FFC] Using nonce for tickets:', nonce ? nonce.substring(0, 10) + '...' : 'NOT FOUND');

        // AJAX to generate tickets
        FFC.request('ffc_generate_codes', { qty: quantity }, { nonce: nonce })
            .then(function (data) {
                var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};
                var $codesField = $('#ffc_generated_list');
                if ($codesField.length) {
                    var currentCodes = $codesField.val();
                    var newCodes = currentCodes ? currentCodes + '\n' + data.codes : data.codes;
                    // Programmatic, so say it: the form editor's tab dot reads the field (#1755).
                    $codesField.val(newCodes).trigger('input');
                    var successMsg = strings.ticketsGeneratedSuccess || 'tickets generated successfully!';
                    FFC.status($status, 'success', quantity + ' ' + successMsg);
                    setTimeout(function () { FFC.status($status, '', ''); }, 5000);
                } else {
                    console.warn('[FFC] Generated codes field not found');
                    var errorMsg = strings.codesFieldNotFound || 'Error: codes field not found';
                    FFC.status($status, 'error', errorMsg);
                }
                $btn.prop('disabled', false).text(originalText);
            })
            .catch(function (err) {
                var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};
                var xhr = err && err.xhr;
                var errorMsg = '';
                if (err && err.fromServer) {
                    errorMsg += (strings.error || 'Error: ') + (err.message || 'Unknown error');
                } else if (xhr && xhr.status === 403) {
                    errorMsg += strings.permissionDenied || 'Permission denied. Please reload the page.';
                } else if (xhr && xhr.status === 400) {
                    errorMsg += strings.badRequest || 'Bad request. Check console.';
                } else {
                    var serverErrorTemplate = strings.serverError || 'Server error (Status: %d)';
                    errorMsg += serverErrorTemplate.replace('%d', xhr ? xhr.status : '?');
                }
                if (xhr) console.error('[FFC] AJAX error:', xhr.status, xhr.statusText, xhr.responseText);
                FFC.status($status, 'error', errorMsg);
                $btn.prop('disabled', false).text(originalText);
            });
    });

    // ==========================================================================
    // CSV EXPORT - AJAX-driven batched export
    // ==========================================================================

    $(document).on('click', '#ffc-csv-export-btn', function() {
        var $btn    = $(this);
        var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};
        var nonce   = (typeof ffc_ajax !== 'undefined') ? ffc_ajax.export_nonce : '';

        if (!nonce) {
            alert(strings.error || 'Error: missing export nonce');
            return;
        }

        var formIds = $btn.data('form-ids') || [];
        var status  = $btn.data('status') || 'publish';

        // Start export job via the shared batched-export driver (#772). The
        // admin path is capability-gated, so it reuses a single `nonce` for
        // start + batch + the download URL (the public path uses a per-job
        // nonce instead). Progress is shown through the shared FFCProgressOverlay
        // modal, driven by the driver itself (overlay: true) \u2014 same UI as the
        // public download (#786).
        var startData = { status: status };
        if (formIds && formIds.length) {
            startData.form_ids = formIds;
        }

        window.FFCBatchedExport.run({
            type: 'submissions',
            ajaxUrl: ajaxurl,
            nonce: nonce,
            button: $btn,
            overlay: true,
            strings: {
                preparing: strings.exportPreparing,
                exporting: strings.exportProgress,
                downloading: strings.exportDone,
                error: strings.error,
                connectionError: strings.connectionError
            },
            startData: startData
        });
    });

    // ==========================================================================
    // INITIALIZE ON DOCUMENT READY
    // ==========================================================================

    $(document).ready(function() {
        // console.log('[FFC Admin] Document ready');

        // Initialize form builder module if on edit page
        if ($('#ffc-fields-container').length) {
            if (window.FFC && window.FFC.Admin && window.FFC.Admin.FieldBuilder) {
                window.FFC.Admin.FieldBuilder.init();
                // console.log('[FFC Admin] Field Builder module initialized');
            } else {
                console.warn('[FFC Admin] Field Builder module not loaded');
            }
        }

        // console.log('[FFC Admin] Initialization complete');
    });

    // =========================================================================
    // Filter Overlay (Submissions page)
    // =========================================================================
    var overlay = document.getElementById('ffc-filter-overlay');
    if (overlay) {
        var openBtn = document.getElementById('ffc-open-filter-overlay');
        if (openBtn) {
            openBtn.addEventListener('click', function() { overlay.style.display = 'flex'; });
        }
        overlay.querySelectorAll('.ffc-filter-overlay-close, .ffc-filter-overlay-backdrop').forEach(function(el) {
            el.addEventListener('click', function() { overlay.style.display = 'none'; });
        });
        overlay.addEventListener('click', function(e) { if (e.target === overlay) overlay.style.display = 'none'; });
    }

    // =========================================================================
    // Quiz Mode Toggle (Form Editor)
    // =========================================================================
    if ($('#ffc_quiz_enabled').length) {
        function toggleQuizUI(on) {
            $('.ffc-quiz-setting').toggleClass('ffc-hidden', !on);
            // `.ffc-hidden` is `display: none`, and a hidden `required`
            // field still blocks the submit — against a control nobody can
            // see. Carry the attribute with the visibility (#1117).
            if (window.FFC && window.FFC.setRequiredWithin) {
                window.FFC.setRequiredWithin($('.ffc-quiz-setting'), on);
            }
            // The per-field quiz settings belong to the field builder,
            // which listens to the same toggle (#1730).
        }
        $('#ffc_quiz_enabled').on('change', function() {
            toggleQuizUI($(this).is(':checked'));
        });
        toggleQuizUI($('#ffc_quiz_enabled').is(':checked'));
    }

    // =========================================================================
    // Generic toggle-gated sub-option visibility (#238 / Sprint 3).
    //
    // Markup contract: any element with class `.ffc-collapsed-target` is
    // hidden whenever its master toggle is off. The master is identified
    // by `data-ffc-master="<id-of-master-input>"`. For select-driven gates
    // (e.g. the CPF whitelist row that shows only when cpf_mode === 'whitelist'),
    // add `data-ffc-master-value="<expected-value>"`; otherwise checkbox
    // checked state is used.
    //
    // Pre-#238 code used per-metabox handlers + per-input `disabled`. That
    // collapsed into this single initializer to keep behavior uniform,
    // including the formerly-save-required spots (Email send_user_email,
    // CPF whitelist mode, IP-Areas permissive).
    //
    // The form editor's restriction fields (password / allowlist / denylist /
    // ticket) are driven from here too: their master toggles carry
    // `data-ffc-master` and the dependent <tr>s collapse with `.ffc-collapsed`.
    // =========================================================================
    $('.ffc-collapsed-target').each(function() {
        var $target   = $(this);
        var masterId  = $target.data('ffc-master');
        var expected  = $target.data('ffc-master-value');
        if (!masterId) { return; }
        var $master = $('#' + masterId);
        if (!$master.length) { return; }

        function isOn() {
            if (typeof expected !== 'undefined' && expected !== null && expected !== '') {
                // Value-driven gate: a select's value, or the checked radio
                // of a steps / segmented group (whose fieldset carries the id).
                var current = $master.is('fieldset')
                    ? $master.find('input[type="radio"]:checked').val()
                    : $master.val();
                return String(current) === String(expected);
            }
            // Checkbox-driven gate: use checked state. Master may be a
            // hidden+checkbox pair (WP admin toggle widget); .is(':checked')
            // resolves to the visible checkbox in either layout.
            return $master.is(':checked');
        }

        function sync() {
            var on = isOn();
            $target.toggleClass('ffc-collapsed', !on);
            $target.attr('aria-hidden', on ? 'false' : 'true');
            $master.attr('aria-expanded', on ? 'true' : 'false');
            // A collapsed block is `display: none`, and a `required` control
            // inside it would block the submit against something nobody can
            // see (#1117). Carry the attribute with the visibility.
            if (window.FFC && typeof window.FFC.setRequiredWithin === 'function') {
                window.FFC.setRequiredWithin($target, on);
            }
        }

        $master.on('change', sync);
        // Also bind on input for selects so we update without losing focus.
        if ($master.is('select')) {
            $master.on('input', sync);
        }
        sync();
    });

    // =========================================================================
    // Public Operator Access (#1755). Regenerating the hash and resetting the
    // counter act at once, through their own AJAX action, instead of waiting
    // for the next save. Enabling the master toggle autosaves, and its first
    // enable generates the hash on the server: the response carries the new
    // link and the CPF mode, which are written into the page here.
    // =========================================================================
    function showOperatorLink(link) {
        if (!link) { return; }
        $('#ffc_csv_public_link').val(link);
        $('.ffc-csv-public-share').removeClass('ffc-hidden');
        $('.ffc-csv-public-nohash').addClass('ffc-hidden');
    }

    $(document).on('click', '.ffc-csv-public-action', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var confirmText = String($btn.data('confirm') || '');
        if (confirmText && !window.confirm(confirmText)) {
            return;
        }
        var $status = $btn.closest('.ffc-csv-public-actions').find('.ffc-csv-public-action-status');
        $btn.prop('disabled', true);
        window.FFC.request('ffc_csv_public_action',
            { post_id: $btn.data('post-id'), op: $btn.data('op') },
            { nonce: String($btn.data('nonce') || '') })
            .then(function (data) {
                if (data && data.share_link) {
                    showOperatorLink(data.share_link);
                }
                if (data && typeof data.count !== 'undefined') {
                    $('.ffc-csv-public-count').text(String(data.count));
                }
                FFC.status($status, 'success', String($btn.data('done') || ''));
            })
            .catch(function (err) {
                FFC.status($status, 'error', (err && err.message) ? err.message : '');
            })
            .then(function () {
                $btn.prop('disabled', false);
            });
    });

    document.addEventListener('ffc:setting-saved', function (e) {
        var detail = (e && e.detail) || {};
        if (detail.key !== 'csv_public_enabled' || !detail.response) {
            return;
        }
        showOperatorLink(detail.response.share_link);
        if (detail.response.cpf_mode) {
            $('#ffc_csv_public_cpf_mode input[type="radio"]').filter(function () {
                return this.value === detail.response.cpf_mode;
            }).prop('checked', true); // Already stored: no change event, so no second save.
        }
    });

    // =========================================================================
    // Per-form-meta auto-save lives in `ffc-admin-autosave.js`, together
    // with the settings autosave it used to duplicate (#1116). This file
    // carried its own handler — same work, different events, different
    // badge, its own copy of the validity guard — until the two were
    // converged into one widget reading both attributes. The form editor
    // enqueues that script and localizes `ffcFormMetaAutosave`; nothing
    // here is needed for it any more.
    // =========================================================================

    // Copy-to-clipboard buttons (`.ffc-copy-link[data-ffc-copy-target]`)
    // live in `ffc-core.js`, so a screen that loads only the core — the
    // calendar editor's shortcode box — gets them too.

})(jQuery);
