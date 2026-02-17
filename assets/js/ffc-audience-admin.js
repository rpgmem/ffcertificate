/**
 * FFC Audience Admin
 *
 * Admin-side scripts for the audience scheduling system.
 *
 * @since 4.5.0
 * @package FreeFormCertificate\Audience
 */

(function($) {
    'use strict';

    // Escape HTML entities to prevent XSS
    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;')
                          .replace(/</g, '&lt;')
                          .replace(/>/g, '&gt;')
                          .replace(/"/g, '&quot;')
                          .replace(/'/g, '&#39;');
    }

    const FFCAudienceAdmin = {

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Day row: toggle time inputs when "closed" checkbox changes
            $(document).on('change', '.ffc-day-row input[type="checkbox"]', function() {
                var row = $(this).closest('.ffc-day-row');
                var inputs = row.find('input[type="time"]');
                inputs.prop('disabled', $(this).is(':checked'));
            });

            // User search (audience group member management)
            this.initUserSearch();

            // Cascading environment filter on bookings page
            this.initEnvironmentFilter();

            // Booking actions (view/cancel) on bookings page
            this.initBookingActions();
        },

        /**
         * Live user search with autocomplete for audience group members
         */
        initUserSearch: function() {
            var $searchInput = $('#user_search');
            if (!$searchInput.length) {
                return;
            }

            var selectedUsers = {};
            var searchTimeout;
            var nonce = typeof ffcAudienceAdmin !== 'undefined' ? ffcAudienceAdmin.searchUsersNonce : '';

            $searchInput.on('input', function() {
                clearTimeout(searchTimeout);
                var query = $(this).val();
                if (query.length < 2) {
                    $('#user_results').removeClass('active').empty();
                    return;
                }

                searchTimeout = setTimeout(function() {
                    $.ajax({
                        url: ajaxurl,
                        data: {
                            action: 'ffc_search_users',
                            query: query,
                            nonce: nonce
                        },
                        success: function(response) {
                            if (response.success && response.data.length > 0) {
                                var html = '';
                                response.data.forEach(function(user) {
                                    if (!selectedUsers[user.id]) {
                                        html += '<div class="ffc-user-result" data-id="' + user.id + '" data-name="' + escHtml(user.name) + '">' + escHtml(user.name) + ' (' + escHtml(user.email) + ')</div>';
                                    }
                                });
                                $('#user_results').html(html).addClass('active');
                            } else {
                                $('#user_results').removeClass('active').empty();
                            }
                        }
                    });
                }, 300);
            });

            $(document).on('click', '.ffc-user-result', function() {
                var id = $(this).data('id');
                var name = $(this).data('name');
                selectedUsers[id] = name;
                updateSelectedUsers();
                $('#user_results').removeClass('active').empty();
                $searchInput.val('');
            });

            $(document).on('click', '.ffc-selected-user .remove', function() {
                var id = $(this).data('id');
                delete selectedUsers[id];
                updateSelectedUsers();
            });

            function updateSelectedUsers() {
                var html = '';
                var ids = [];
                for (var id in selectedUsers) {
                    html += '<span class="ffc-selected-user">' + escHtml(selectedUsers[id]) + '<span class="remove" data-id="' + id + '">&times;</span></span>';
                    ids.push(id);
                }
                $('#selected_users').html(html);
                $('#selected_user_ids').val(ids.join(','));
            }
        },

        /**
         * Cascading schedule → environment filter on bookings page
         */
        initEnvironmentFilter: function() {
            var $scheduleSelect = $('#filter-schedule');
            var $environmentSelect = $('#filter-environment');
            if (!$scheduleSelect.length || !$environmentSelect.length) {
                return;
            }

            var strings = typeof ffcAudienceAdmin !== 'undefined' ? ffcAudienceAdmin.strings : {};
            var adminNonce = typeof ffcAudienceAdmin !== 'undefined' ? ffcAudienceAdmin.adminNonce : '';
            var allEnvironmentsText = strings.allEnvironments || 'All Environments';
            var loadingText = strings.loading || 'Loading...';

            $scheduleSelect.on('change', function() {
                var scheduleId = $(this).val();

                if (!scheduleId) {
                    $environmentSelect.html('<option value="">' + allEnvironmentsText + '</option>');
                    return;
                }

                $environmentSelect.html('<option value="">' + loadingText + '</option>');

                $.ajax({
                    url: ajaxurl,
                    type: 'GET',
                    data: {
                        action: 'ffc_audience_get_environments',
                        schedule_id: scheduleId,
                        nonce: adminNonce
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            var html = '<option value="">' + allEnvironmentsText + '</option>';
                            $.each(response.data, function(i, env) {
                                html += '<option value="' + env.id + '">' + $('<div/>').text(env.name).html() + '</option>';
                            });
                            $environmentSelect.html(html);
                        } else {
                            $environmentSelect.html('<option value="">' + allEnvironmentsText + '</option>');
                        }
                    },
                    error: function() {
                        $environmentSelect.html('<option value="">' + allEnvironmentsText + '</option>');
                    }
                });
            });
        },

        /**
         * View and Cancel booking actions on the bookings admin page
         */
        initBookingActions: function() {
            if (!$('.ffc-view-booking').length && !$('.ffc-cancel-booking').length) {
                return;
            }

            var strings = typeof ffcAudienceAdmin !== 'undefined' ? ffcAudienceAdmin.strings : {};
            var adminNonce = typeof ffcAudienceAdmin !== 'undefined' ? ffcAudienceAdmin.adminNonce : '';

            // Escape HTML helper
            function esc(str) {
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str || ''));
                return div.innerHTML;
            }

            // View booking
            $(document).on('click', '.ffc-view-booking', function(e) {
                e.preventDefault();
                var bookingId = $(this).data('booking-id');

                // Remove existing modal
                $('#ffc-booking-modal').remove();

                // Show loading modal
                var $modal = $('<div id="ffc-booking-modal" class="ffc-admin-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ffc-admin-modal-title">' +
                    '<div class="ffc-admin-modal">' +
                    '<div class="ffc-admin-modal-header"><h3 id="ffc-admin-modal-title">' + esc(strings.bookingDetails || 'Booking Details') + '</h3><button type="button" class="ffc-admin-modal-close" aria-label="Close">&times;</button></div>' +
                    '<div class="ffc-admin-modal-body"><p>' + esc(strings.loading || 'Loading...') + '</p></div>' +
                    '</div></div>');
                $('body').append($modal);
                $modal.show();
                $modal.find('.ffc-admin-modal-close').focus();

                $.ajax({
                    url: ajaxurl,
                    type: 'GET',
                    data: {
                        action: 'ffc_audience_get_booking',
                        booking_id: bookingId,
                        nonce: adminNonce
                    },
                    success: function(response) {
                        if (!response.success) {
                            $modal.find('.ffc-admin-modal-body').html('<p>' + esc(response.data.message || strings.error) + '</p>');
                            return;
                        }
                        var b = response.data;
                        var timeDisplay = parseInt(b.is_all_day) ? (strings.allDay || 'All Day') : (b.start_time + ' - ' + b.end_time);
                        var typeDisplay = b.booking_type === 'audience' ? (strings.audience || 'Audience') : (strings.customUsers || 'Custom Users');
                        var statusDisplay = b.status === 'active' ? (strings.active || 'Active') : (strings.cancelled || 'Cancelled');
                        var statusClass = b.status === 'active' ? 'status-active' : 'status-cancelled';

                        var html = '<table class="widefat fixed"><tbody>';
                        html += '<tr><th>' + esc(strings.date || 'Date') + '</th><td>' + esc(b.booking_date) + '</td></tr>';
                        html += '<tr><th>' + esc(strings.time || 'Time') + '</th><td>' + esc(timeDisplay) + '</td></tr>';
                        html += '<tr><th>' + esc(strings.environmentLabel || 'Environment') + '</th><td>' + esc(b.environment_name) + '</td></tr>';
                        html += '<tr><th>' + esc(strings.description || 'Description') + '</th><td>' + esc(b.description) + '</td></tr>';
                        html += '<tr><th>' + esc(strings.type || 'Type') + '</th><td>' + esc(typeDisplay) + '</td></tr>';
                        html += '<tr><th>' + esc(strings.status || 'Status') + '</th><td><span class="' + statusClass + '">' + esc(statusDisplay) + '</span></td></tr>';
                        html += '<tr><th>' + esc(strings.createdBy || 'Created By') + '</th><td>' + esc(b.created_by) + '</td></tr>';

                        if (b.audiences && b.audiences.length > 0) {
                            var audNames = b.audiences.map(function(a) { return a.name; }).join(', ');
                            html += '<tr><th>' + esc(strings.audiences || 'Audiences') + '</th><td>' + esc(audNames) + '</td></tr>';
                        }

                        if (b.users && b.users.length > 0) {
                            var userList = b.users.map(function(u) { return u.name + ' (' + u.email + ')'; }).join(', ');
                            html += '<tr><th>' + esc(strings.users || 'Users') + '</th><td>' + esc(userList) + '</td></tr>';
                        }

                        if (b.status === 'cancelled' && b.cancel_reason) {
                            html += '<tr><th>' + esc(strings.cancelReason || 'Cancel Reason') + '</th><td>' + esc(b.cancel_reason) + '</td></tr>';
                        }

                        html += '</tbody></table>';
                        $modal.find('.ffc-admin-modal-body').html(html);
                    },
                    error: function() {
                        $modal.find('.ffc-admin-modal-body').html('<p>' + esc(strings.error || 'An error occurred.') + '</p>');
                    }
                });
            });

            // Close modal
            $(document).on('click', '.ffc-admin-modal-close, .ffc-admin-modal-overlay', function(e) {
                if (e.target === this) {
                    $('#ffc-booking-modal').remove();
                }
            });

            // Close modal on Escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $('#ffc-booking-modal').length) {
                    $('#ffc-booking-modal').remove();
                }
            });

            // Cancel booking
            $(document).on('click', '.ffc-cancel-booking', function(e) {
                e.preventDefault();
                var $link = $(this);
                var bookingId = $link.data('booking-id');

                if (!confirm(strings.confirmCancel || 'Are you sure you want to cancel this booking?')) {
                    return;
                }

                var reason = prompt(strings.cancelReason || 'Please provide a reason for cancellation:');
                if (reason === null) {
                    return;
                }

                $link.css('pointer-events', 'none').css('opacity', '0.5');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ffc_audience_cancel_booking',
                        booking_id: bookingId,
                        reason: reason,
                        nonce: adminNonce
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update the row status and remove cancel link
                            var $row = $link.closest('tr');
                            $row.find('.status-active').removeClass('status-active').addClass('status-cancelled').text(strings.cancelled || 'Cancelled');
                            $link.prev().remove(); // remove "|" separator
                            $link.remove();
                        } else {
                            alert(response.data.message || strings.error);
                            $link.css('pointer-events', '').css('opacity', '');
                        }
                    },
                    error: function() {
                        alert(strings.error || 'An error occurred.');
                        $link.css('pointer-events', '').css('opacity', '');
                    }
                });
            });
        }
    };

    $(document).ready(function() {
        FFCAudienceAdmin.init();
    });

})(jQuery);
