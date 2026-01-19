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

    console.log('[FFC Admin] Initializing v3.1.0...');

    // ==========================================================================
    // NOTIFICATION SYSTEM - Replace alerts with inline messages
    // ==========================================================================

    function showNotification(message, type, duration) {
        type = type || 'info';
        duration = duration || 5000;

        $('.ffc-admin-notification').remove();

        var icons = {success: 'yes-alt', error: 'dismiss', warning: 'warning', info: 'info'};
        var colors = {success: 'notice-success', error: 'notice-error', warning: 'notice-warning', info: 'notice-info'};

        var $notif = $('<div class="ffc-admin-notification notice ' + colors[type] + ' is-dismissible">' +
            '<p><span class="dashicons dashicons-' + icons[type] + '"></span> ' + message + '</p>' +
            '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>' +
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
        console.log('[FFC] Generate Tickets clicked');

        // Read from input field instead of prompt
        var quantity = $('#ffc_qty_codes').val();

        if (!quantity || isNaN(quantity) || quantity < 1) {
            // Show inline error instead of alert
            $('#ffc_gen_status').text('Please enter a valid number.').css('color', 'red');
            $('#ffc_qty_codes').focus();
            return;
        }

        var $btn = $(this);
        var $status = $('#ffc_gen_status');
        var originalText = $btn.text();

        $btn.prop('disabled', true).text('Generating...');
        $status.text('Generating tickets...').css('color', '#999');

        // Use nonce from ffc_ajax (localized by class-ffc-admin.php)
        var nonce = (typeof ffc_ajax !== 'undefined') ? ffc_ajax.nonce : '';

        console.log('[FFC] Using nonce for tickets:', nonce ? nonce.substring(0, 10) + '...' : 'NOT FOUND');

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
                if (response.success) {
                    // Use the correct field ID: #ffc_generated_list
                    var $codesField = $('#ffc_generated_list');

                    if ($codesField.length) {
                        var currentCodes = $codesField.val();
                        var newCodes = currentCodes ? currentCodes + '\n' + response.data.codes : response.data.codes;
                        $codesField.val(newCodes);
                        console.log('[FFC] Codes added to field: ffc_generated_list');

                        // Inline message instead of alert
                        $status.text('✓ ' + quantity + ' tickets generated successfully!').css('color', 'green');

                        // Clear message after 5 seconds
                        setTimeout(function() {
                            $status.text('');
                        }, 5000);
                    } else {
                        console.warn('[FFC] Generated codes field not found');
                        $status.text('✗ Error: codes field not found').css('color', 'red');
                    }
                } else {
                    $status.text('✗ Error: ' + (response.data || 'Unknown error')).css('color', 'red');
                }

                $btn.prop('disabled', false).text(originalText);
            },
            error: function(xhr) {
                console.error('[FFC] AJAX error:', xhr.status, xhr.statusText, xhr.responseText);

                var errorMsg = '✗ ';
                if (xhr.status === 403) {
                    errorMsg += 'Permission denied. Please reload the page.';
                } else if (xhr.status === 400) {
                    errorMsg += 'Bad request. Check console.';
                } else {
                    errorMsg += 'Server error (Status: ' + xhr.status + ')';
                }

                $status.text(errorMsg).css('color', 'red');
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });

    // ==========================================================================
    // INITIALIZE ON DOCUMENT READY
    // ==========================================================================

    $(document).ready(function() {
        console.log('[FFC Admin] Document ready');

        // Initialize form builder module if on edit page
        if ($('#ffc-fields-container').length) {
            if (window.FFC && window.FFC.Admin && window.FFC.Admin.FieldBuilder) {
                window.FFC.Admin.FieldBuilder.init();
                console.log('[FFC Admin] Field Builder module initialized');
            } else {
                console.warn('[FFC Admin] Field Builder module not loaded');
            }
        }

        console.log('[FFC Admin] Initialization complete');
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

            // Debug
            console.log('Migration menu opened');
        }

        /**
         * Close menu
         */
        function closeMenu() {
            $menu.removeClass('ffc-visible');
            $overlay.removeClass('ffc-visible');

            // Debug
            console.log('Migration menu closed');
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

        // Debug: log when loaded
        console.log('FFC Migration Dropdown initialized', {
            button: $btn.length,
            menu: $menu.length,
            overlay: $overlay.length
        });

        // Show/hide restriction fields based on checkboxes
        function toggleRestrictionField(checkbox, fieldId) {
            if ($(checkbox).is(':checked')) {
                $(fieldId).slideDown();
            } else {
                $(fieldId).slideUp();
            }
        }

        // Password field
        $('#ffc_restriction_password').on('change', function() {
            toggleRestrictionField(this, '#ffc_password_field');
        }).trigger('change');

        // Allowlist field
        $('#ffc_restriction_allowlist').on('change', function() {
            toggleRestrictionField(this, '#ffc_allowlist_field');
        }).trigger('change');

        // Denylist field
        $('#ffc_restriction_denylist').on('change', function() {
            toggleRestrictionField(this, '#ffc_denylist_field');
        }).trigger('change');

        // Ticket field
        $('#ffc_restriction_ticket').on('change', function() {
            toggleRestrictionField(this, '#ffc_ticket_field');
        }).trigger('change');

        console.log('[FFC Admin] Restriction field toggles initialized');
    });

})(jQuery);
