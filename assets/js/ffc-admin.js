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
    // NOTIFICATION SYSTEM - Replace alerts with inline messages
    // ==========================================================================

    function showNotification(message, type, duration) {
        type = type || 'info';
        duration = duration || 5000;

        $('.ffc-admin-notification').remove();

        var icons = {success: 'yes-alt', error: 'dismiss', warning: 'warning', info: 'info'};
        var colors = {success: 'notice-success', error: 'notice-error', warning: 'notice-warning', info: 'notice-info'};

        // Get localized strings with fallbacks
        var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};
        var dismissText = strings.dismiss || 'Dismiss';

        var $notif = $('<div class="ffc-admin-notification notice ' + colors[type] + ' is-dismissible">' +
            '<p><span class="dashicons dashicons-' + icons[type] + '"></span> ' + message + '</p>' +
            '<button type="button" class="notice-dismiss"><span class="screen-reader-text">' + dismissText + '</span></button>' +
            '</div>');

        if ($('.wrap > h1').length) {
            $('.wrap > h1').after($notif);
        } else {
            $('#wpbody-content').prepend($notif);
        }

        $notif.find('.notice-dismiss').on('click', function() {
            $notif.fadeOut(200, function() { $(this).remove(); });
        });

        if (duration > 0) {
            setTimeout(function() {
                $notif.fadeOut(200, function() { $(this).remove(); });
            }, duration);
        }
    }

    // Export showNotification to FFC.Admin namespace for use by modules
    window.FFC = window.FFC || {};
    window.FFC.Admin = window.FFC.Admin || {};
    window.FFC.Admin.showNotification = showNotification;

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
            $('#ffc_gen_status').text(errorMsg).css('color', 'red');
            $('#ffc_qty_codes').focus();
            return;
        }

        var $btn = $(this);
        var $status = $('#ffc_gen_status');
        var originalText = $btn.text();

        var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};
        var generatingText = strings.generating || 'Generating...';
        var generatingTicketsText = strings.generatingTickets || 'Generating tickets...';

        $btn.prop('disabled', true).text(generatingText);
        $status.text(generatingTicketsText).css('color', '#999');

        // Use nonce from ffc_ajax (localized by class-ffc-admin.php)
        var nonce = (typeof ffc_ajax !== 'undefined') ? ffc_ajax.nonce : '';

        // console.log('[FFC] Using nonce for tickets:', nonce ? nonce.substring(0, 10) + '...' : 'NOT FOUND');

        // AJAX to generate tickets
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ffc_generate_codes',
                qty: quantity,
                nonce: nonce
            },
            success: function(response) {
                var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};

                if (response.success) {
                    // Use the correct field ID: #ffc_generated_list
                    var $codesField = $('#ffc_generated_list');

                    if ($codesField.length) {
                        var currentCodes = $codesField.val();
                        var newCodes = currentCodes ? currentCodes + '\n' + response.data.codes : response.data.codes;
                        $codesField.val(newCodes);
                        // console.log('[FFC] Codes added to field: ffc_generated_list');

                        // Inline message instead of alert
                        var successMsg = strings.ticketsGeneratedSuccess || 'tickets generated successfully!';
                        $status.text('✓ ' + quantity + ' ' + successMsg).css('color', 'green');

                        // Clear message after 5 seconds
                        setTimeout(function() {
                            $status.text('');
                        }, 5000);
                    } else {
                        console.warn('[FFC] Generated codes field not found');
                        var errorMsg = strings.codesFieldNotFound || 'Error: codes field not found';
                        $status.text('✗ ' + errorMsg).css('color', 'red');
                    }
                } else {
                    var errorText = strings.error || 'Error: ';
                    $status.text('✗ ' + errorText + (response.data || 'Unknown error')).css('color', 'red');
                }

                $btn.prop('disabled', false).text(originalText);
            },
            error: function(xhr) {
                console.error('[FFC] AJAX error:', xhr.status, xhr.statusText, xhr.responseText);

                var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};
                var errorMsg = '✗ ';

                if (xhr.status === 403) {
                    errorMsg += strings.permissionDenied || 'Permission denied. Please reload the page.';
                } else if (xhr.status === 400) {
                    errorMsg += strings.badRequest || 'Bad request. Check console.';
                } else {
                    var serverErrorTemplate = strings.serverError || 'Server error (Status: %d)';
                    errorMsg += serverErrorTemplate.replace('%d', xhr.status);
                }

                $status.text(errorMsg).css('color', 'red');
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });

    // ==========================================================================
    // CSV EXPORT - AJAX-driven batched export
    // ==========================================================================

    $(document).on('click', '#ffc-csv-export-btn', function() {
        var $btn      = $(this);
        var $progress = $('#ffc-csv-export-progress');
        var strings   = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};
        var nonce     = (typeof ffc_ajax !== 'undefined') ? ffc_ajax.export_nonce : '';

        if (!nonce) {
            alert(strings.error || 'Error: missing export nonce');
            return;
        }

        var formIds = $btn.data('form-ids') || [];
        var status  = $btn.data('status') || 'publish';

        var originalText = $btn.text();
        var preparingText = strings.exportPreparing || 'Preparing\u2026';
        $btn.prop('disabled', true).text(preparingText);
        $progress.show().text('');

        // Step 1: Start export job
        var postData = { action: 'ffc_csv_export_start', nonce: nonce, status: status };
        if (formIds && formIds.length) {
            postData.form_ids = formIds;
        }

        $.post(ajaxurl, postData, function(res) {
            if (!res.success) {
                $btn.prop('disabled', false).text(originalText);
                $progress.text(res.data || strings.error || 'Error');
                return;
            }

            var jobId = res.data.job_id;
            var total = res.data.total;
            var exportingTpl = strings.exportProgress || 'Exporting %1$d/%2$d\u2026';

            // Step 2: Process batches sequentially
            function processBatch() {
                $.post(ajaxurl, {
                    action: 'ffc_csv_export_batch',
                    nonce: nonce,
                    job_id: jobId
                }, function(batchRes) {
                    if (!batchRes.success) {
                        $btn.prop('disabled', false).text(originalText);
                        $progress.text(batchRes.data || 'Error');
                        return;
                    }

                    var processed = batchRes.data.processed;
                    var progressText = exportingTpl
                        .replace('%1$d', processed)
                        .replace('%2$d', total);
                    $progress.text(progressText);

                    if (batchRes.data.done) {
                        // Step 3: Trigger file download
                        var downloadUrl = ajaxurl
                            + '?action=ffc_csv_export_download'
                            + '&job_id=' + encodeURIComponent(jobId)
                            + '&nonce=' + encodeURIComponent(nonce);

                        // Use a hidden iframe to trigger download without navigating
                        var $iframe = $('<iframe>', { src: downloadUrl })
                            .css({ display: 'none' })
                            .appendTo('body');

                        // Cleanup UI
                        setTimeout(function() {
                            $btn.prop('disabled', false).text(originalText);
                            var doneText = strings.exportDone || 'Done!';
                            $progress.text('\u2713 ' + processed + '/' + total + ' \u2014 ' + doneText);
                            setTimeout(function() { $progress.fadeOut(); }, 5000);
                            $iframe.remove();
                        }, 2000);
                    } else {
                        // Continue to next batch
                        processBatch();
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text(originalText);
                    $progress.text(strings.connectionError || 'Connection error.');
                });
            }

            processBatch();

        }).fail(function() {
            $btn.prop('disabled', false).text(originalText);
            $progress.text(strings.connectionError || 'Connection error.');
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

    /**
     * Migration Manager Dropdown Controller
     * v2.1.0
     *
     * Controls opening/closing of migrations dropdown
     */

    jQuery(document).ready(function($) {

        // Create overlay if it doesn't exist
        if (!$('#ffc-migrations-overlay').length) {
            $('body').append('<div id="ffc-migrations-overlay" class="ffc-migrations-overlay"></div>');
        }

        var $btn = $('#ffc-migrations-btn');
        var $menu = $('#ffc-migrations-menu');
        var $overlay = $('#ffc-migrations-overlay');

        if (!$btn.length || !$menu.length) {
            return; // Elements not found
        }

        /**
         * Open menu
         */
        function openMenu() {
            // Close other WordPress dropdowns
            $('.ffc-migrations-menu').not($menu).removeClass('ffc-visible');

            // Show overlay
            $overlay.addClass('ffc-visible');

            // Show menu
            $menu.addClass('ffc-visible');

            // console.log('Migration menu opened');
        }

        /**
         * Close menu
         */
        function closeMenu() {
            $menu.removeClass('ffc-visible');
            $overlay.removeClass('ffc-visible');

            // console.log('Migration menu closed');
        }

        /**
         * Toggle menu
         */
        function toggleMenu(e) {
            e.preventDefault();
            e.stopPropagation();

            if ($menu.hasClass('ffc-visible')) {
                closeMenu();
            } else {
                openMenu();
            }
        }

        // Click on button
        $btn.on('click', toggleMenu);

        // Click on overlay closes menu
        $overlay.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            closeMenu();
        });

        // Click outside menu closes (fallback)
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.ffc-migrations-dropdown').length) {
                closeMenu();
            }
        });

        // ESC closes menu
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                if ($menu.hasClass('ffc-visible')) {
                    closeMenu();
                }
            }
        });

        // Prevent clicks inside menu from closing it
        $menu.on('click', function(e) {
            e.stopPropagation();
        });

        // console.log('FFC Migration Dropdown initialized', {
        //     button: $btn.length,
        //     menu: $menu.length,
        //     overlay: $overlay.length
        // });

        // Show/hide restriction fields based on checkboxes - Using event delegation
        function toggleRestrictionField(checkbox, fieldId) {
            if ($(checkbox).is(':checked')) {
                $(fieldId).slideDown();
            } else {
                $(fieldId).slideUp();
            }
        }

        // Password field - Using event delegation
        $(document).on('change', '#ffc_restriction_password', function() {
            toggleRestrictionField(this, '#ffc_password_field');
        });
        $('#ffc_restriction_password').trigger('change');

        // Allowlist field - Using event delegation
        $(document).on('change', '#ffc_restriction_allowlist', function() {
            toggleRestrictionField(this, '#ffc_allowlist_field');
        });
        $('#ffc_restriction_allowlist').trigger('change');

        // Denylist field - Using event delegation
        $(document).on('change', '#ffc_restriction_denylist', function() {
            toggleRestrictionField(this, '#ffc_denylist_field');
        });
        $('#ffc_restriction_denylist').trigger('change');

        // Ticket field - Using event delegation
        $(document).on('change', '#ffc_restriction_ticket', function() {
            toggleRestrictionField(this, '#ffc_ticket_field');
        });
        $('#ffc_restriction_ticket').trigger('change');

        // console.log('[FFC Admin] Restriction field toggles initialized');
    });

})(jQuery);
