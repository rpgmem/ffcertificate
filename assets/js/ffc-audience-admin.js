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

            // Colour picker: keep the hex text printed beside it in step
            // (environment edit screen, the "Multiple Audiences" badge colour
            // in Settings → Audience Calendars).
            $(document).on('input change', 'input[type="color"]', function() {
                $(this).siblings('.ffc-color-input-value, .ffc-color-value').text(this.value);
            });

            // New audience: a group picked as parent hands its self-join
            // choice down (#1901), so the switch gives way to a line saying so.
            $(document).on('change', '#audience_parent', function() {
                var form = $(this).closest('form');
                var inherits = form.find('.ffc-self-join-inherits');
                if (!inherits.length) {
                    return;
                }
                var hasParent = '' !== $(this).val();
                inherits.prop('hidden', !hasParent);
                form.find('.ffc-self-join-own').prop('hidden', hasParent);
            });
            $('#audience_parent').trigger('change');

            // "Keep at least one group" only exists under "Visible to members
            // only" (#1918): it is disabled while that is off, and turning
            // that off turns it off too — the server stores it off then.
            $(document).on('change', '#audience_members_only', function() {
                var keep = $(this).closest('form').find('#audience_require_membership');
                keep.prop('disabled', !this.checked);
                if (!this.checked) {
                    keep.prop('checked', false);
                }
            });

            // User search (audience group member management)
            this.initUserSearch();

            // Cascading environment filter on bookings page
            this.initEnvironmentFilter();

            // Booking actions (view/cancel) on bookings page
            this.initBookingActions();

            // Calendar user access permissions
            this.initCalendarPermissions();

            // Bookings CSV export button (own method so it registers even on
            // pages where initCalendarPermissions early-returns).
            this.initBookingsExport();
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
                    $('#user_results').removeClass('is-active').empty();
                    return;
                }

                searchTimeout = setTimeout(function() {
                    FFC.request('ffc_search_users', { query: query }, { nonce: nonce })
                        .then(function(data) {
                            if (data && data.length > 0) {
                                var html = '';
                                data.forEach(function(user) {
                                    if (!selectedUsers[user.id]) {
                                        html += '<div class="ffc-user-result" data-id="' + user.id + '" data-name="' + escHtml(user.name) + '">' + escHtml(user.name) + ' (' + escHtml(user.email) + ')</div>';
                                    }
                                });
                                $('#user_results').html(html).addClass('is-active');
                            } else {
                                $('#user_results').removeClass('is-active').empty();
                            }
                        })
                        .catch(function() {
                            $('#user_results').removeClass('is-active').empty();
                        });
                }, 300);
            });

            $(document).on('click', '.ffc-user-result', function() {
                var id = $(this).data('id');
                var name = $(this).data('name');
                selectedUsers[id] = name;
                updateSelectedUsers();
                $('#user_results').removeClass('is-active').empty();
                $searchInput.val('');
            });

            $(document).on('click', '.ffc-selected-user .ffc-selected-user-remove', function() {
                var id = $(this).data('id');
                delete selectedUsers[id];
                updateSelectedUsers();
            });

            function updateSelectedUsers() {
                var html = '';
                var ids = [];
                for (var id in selectedUsers) {
                    html += '<span class="ffc-selected-user">' + escHtml(selectedUsers[id]) + '<span class="ffc-selected-user-remove" data-id="' + id + '">&times;</span></span>';
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

                FFC.request('ffc_audience_get_environments', { schedule_id: scheduleId }, { nonce: adminNonce })
                    .then(function(data) {
                        var html = '<option value="">' + allEnvironmentsText + '</option>';
                        if (data) {
                            $.each(data, function(i, env) {
                                html += '<option value="' + env.id + '">' + $('<div/>').text(env.name).html() + '</option>';
                            });
                        }
                        $environmentSelect.html(html);
                    })
                    .catch(function() {
                        $environmentSelect.html('<option value="">' + allEnvironmentsText + '</option>');
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
                var closeLabel = esc(strings.close || 'Close');
                var $modal = $('<div id="ffc-booking-modal" class="ffc-admin-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ffc-admin-modal-title">' +
                    '<div class="ffc-admin-modal">' +
                    '<div class="ffc-admin-modal-header"><h3 id="ffc-admin-modal-title">' + esc(strings.bookingDetails || 'Booking Details') + '</h3><button type="button" class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-cross ffc-admin-modal-close" aria-label="' + closeLabel + '" title="' + closeLabel + '"></button></div>' +
                    '<div class="ffc-admin-modal-body"><p>' + esc(strings.loading || 'Loading...') + '</p></div>' +
                    '</div></div>');
                $('body').append($modal);
                $modal.show();
                $modal.find('.ffc-admin-modal-close').focus();

                FFC.request('ffc_audience_get_booking', { booking_id: bookingId }, { nonce: adminNonce })
                    .then(function(b) {
                        var timeDisplay = parseInt(b.is_all_day) ? (strings.allDay || 'All Day') : (b.time_label || (b.start_time + ' - ' + b.end_time));
                        var typeDisplay = b.booking_type === 'audience' ? (strings.audience || 'Audience') : (strings.customUsers || 'Custom Users');
                        var statusDisplay = b.status === 'active' ? (strings.active || 'Active') : (strings.cancelled || 'Cancelled');
                        var statusClass = b.status === 'active' ? 'ffc-booking-status-active' : 'ffc-booking-status-cancelled';

                        var html = '<table class="widefat fixed"><tbody>';
                        var dateLabel = b.end_date && b.end_date !== b.booking_date ? b.booking_date + ' – ' + b.end_date : b.booking_date;
                        html += '<tr><th>' + esc(strings.date || 'Date') + '</th><td>' + esc(dateLabel) + '</td></tr>';
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
                            // email_html is escaped server-side: the masked address plus,
                            // for whoever may reveal it, the audited Reveal button.
                            var userList = b.users.map(function(u) { return '<div>' + esc(u.name) + ' ' + (u.email_html || esc(u.email)) + '</div>'; }).join('');
                            html += '<tr><th>' + esc(strings.users || 'Users') + '</th><td>' + userList + '</td></tr>';
                        }

                        if (b.status === 'cancelled' && b.cancel_reason) {
                            html += '<tr><th>' + esc(strings.cancelReasonLabel || 'Cancel Reason') + '</th><td>' + esc(b.cancel_reason) + '</td></tr>';
                        }

                        html += '</tbody></table>';
                        $modal.find('.ffc-admin-modal-body').html(html);
                    })
                    .catch(function(err) {
                        var msg = (err && err.fromServer) ? err.message : (strings.error || 'An error occurred.');
                        $modal.find('.ffc-admin-modal-body').html('<p>' + esc(msg) + '</p>');
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

                // A range is cancelled whole: the confirmation names every day of it.
                FFC.confirmRangeCancel({
                    count: $link.attr('data-days'),
                    label: $link.attr('data-date-label'),
                    strings: typeof ffcAudienceAdmin !== 'undefined' ? ffcAudienceAdmin.cancelConfirm : {}
                }).then(function(agreed) {
                    if (!agreed) {
                        return;
                    }

                    var reason = prompt(strings.cancelReason || 'Please provide a reason for cancellation:');
                    if (reason === null) {
                        return;
                    }

                    $link.css('pointer-events', 'none').css('opacity', '0.5');

                    FFC.request('ffc_audience_cancel_booking', { booking_id: bookingId, reason: reason }, { nonce: adminNonce })
                        .then(function() {
                            // Update the row status and remove cancel link
                            var $row = $link.closest('tr');
                            $row.find('.ffc-booking-status-active').removeClass('ffc-booking-status-active').addClass('ffc-booking-status-cancelled').text(strings.cancelled || 'Cancelled');
                            $link.prev().remove(); // remove "|" separator
                            $link.remove();
                        })
                        .catch(function(err) {
                            var msg = (err && err.fromServer) ? err.message : (strings.error || 'An error occurred.');
                            alert(msg);
                            $link.css('pointer-events', '').css('opacity', '');
                        });
                });
            });
        },

        /**
         * Calendar user access permissions (individual user search, add, toggle, remove)
         */
        initCalendarPermissions: function() {
            var $table = $('#ffc-permissions-table');
            if (!$table.length) {
                return;
            }

            var scheduleId = $table.data('schedule-id');
            if (!scheduleId) {
                return;
            }

            var data = typeof ffcAudienceAdmin !== 'undefined' ? ffcAudienceAdmin : {};
            var permNonce = data.schedulePermissionsNonce || '';
            var searchNonce = data.searchUsersNonce || '';
            var strings = data.strings || {};
            var searchTimer = null;
            var selectedUserId = 0;

            // User search
            $('#ffc-user-search').on('input', function() {
                clearTimeout(searchTimer);
                var query = $(this).val().trim();
                if (query.length < 2) {
                    $('#ffc-user-search-results').hide();
                    return;
                }
                searchTimer = setTimeout(function() {
                    FFC.request('ffc_search_users', { query: query }, { nonce: searchNonce })
                        .then(function(data) {
                            if (data && data.length > 0) {
                                var html = '';
                                var existingIds = [];
                                $table.find('tbody tr[data-user-id]').each(function() {
                                    existingIds.push(parseInt($(this).data('user-id')));
                                });
                                $.each(data, function(i, user) {
                                    var disabled = existingIds.indexOf(user.id) !== -1;
                                    html += '<div class="ffc-user-result' + (disabled ? ' ffc-user-exists' : '') + '" data-id="' + user.id + '" data-name="' + escHtml(user.name) + '">';
                                    html += '<strong>' + escHtml(user.name) + '</strong>';
                                    html += '<br><small>' + escHtml(user.email) + '</small>';
                                    if (disabled) html += ' <em>(' + escHtml(strings.alreadyAdded || 'already added') + ')</em>';
                                    html += '</div>';
                                });
                                $('#ffc-user-search-results').html(html).show();
                            } else {
                                $('#ffc-user-search-results').html('<div class="ffc-user-result-empty"><em>' + escHtml(strings.noUsersFound || 'No users found.') + '</em></div>').show();
                            }
                        })
                        .catch(function() {
                            $('#ffc-user-search-results').hide();
                        });
                }, 300);
            });

            // Select user from results
            $(document).on('click', '#ffc-user-search-results .ffc-user-result:not(.ffc-user-exists)', function() {
                selectedUserId = parseInt($(this).data('id'));
                $('#ffc-user-search').val($(this).data('name'));
                $('#ffc-selected-user-id').val(selectedUserId);
                $('#ffc-add-user-btn').prop('disabled', false);
                $('#ffc-user-search-results').hide();
            });

            // Hide results on outside click
            $(document).on('click', function(e) {
                if (!$(e.target).closest('#ffc-user-search, #ffc-user-search-results').length) {
                    $('#ffc-user-search-results').hide();
                }
            });

            // Add user
            $('#ffc-add-user-btn').on('click', function() {
                if (!selectedUserId) return;
                var btn = $(this);
                btn.prop('disabled', true);

                // The server here verifies via `check_admin_referer` on `_wpnonce`
                // (not `check_ajax_referer` on `nonce`), so we send the nonce
                // under that field name. FFC.request also injects its own
                // `nonce` payload key; the server ignores it.
                FFC.request('ffc_audience_add_user_permission', {
                    schedule_id: scheduleId,
                    user_id: selectedUserId,
                    _wpnonce: permNonce
                })
                    .then(function(data) {
                        $('#ffc-no-permissions-row').remove();
                        $table.find('tbody').append(data.html);
                        $('#ffc-user-search').val('');
                        selectedUserId = 0;
                        $('#ffc-selected-user-id').val('');
                    })
                    .catch(function(err) {
                        var msg = (err && err.fromServer) ? err.message : (strings.errorAddingUser || 'Error adding user.');
                        alert(msg);
                        btn.prop('disabled', false);
                    });
            });

            // Toggle permission
            $(document).on('change', '.ffc-perm-toggle', function() {
                var row = $(this).closest('tr');
                var userId = row.data('user-id');
                var perm = $(this).data('perm');
                var value = $(this).is(':checked') ? 1 : 0;

                // Fire-and-forget toggle update; server verifies _wpnonce.
                FFC.request('ffc_audience_update_user_permission', {
                    schedule_id: scheduleId,
                    user_id: userId,
                    permission: perm,
                    value: value,
                    _wpnonce: permNonce
                }).catch(function() { /* silent — UI state already updated optimistically */ });
            });

            // Remove user
            $(document).on('click', '.ffc-remove-user-btn', function() {
                if (!confirm(strings.confirmRemoveUser || 'Remove this user\'s access?')) return;
                var row = $(this).closest('tr');
                var userId = row.data('user-id');

                FFC.request('ffc_audience_remove_user_permission', {
                    schedule_id: scheduleId,
                    user_id: userId,
                    _wpnonce: permNonce
                })
                    .then(function() {
                        row.fadeOut(300, function() {
                            $(this).remove();
                            if ($table.find('tbody tr').length === 0) {
                                $table.find('tbody').html('<tr id="ffc-no-permissions-row"><td colspan="5"><em>' + escHtml(strings.noUsersYet || 'No users have been granted access yet.') + '</em></td></tr>');
                            }
                        });
                    })
                    .catch(function() { /* silent */ });
            });

        },

        /**
         * Batched CSV export (#772) for the bookings page. Deliberately its own
         * method — NOT folded into initCalendarPermissions, whose early-return
         * on pages without #ffc-permissions-table (e.g. the bookings list) would
         * otherwise skip registering this handler.
         */
        initBookingsExport: function() {
            // The button drives the unified ffc_export_* dispatcher via the
            // shared window.FFCBatchedExport driver, carrying the current
            // schedule/environment/status/date filters. Export order is id-DESC
            // (a stable keyset), not the on-screen sort.
            $(document).on('click', '#ffc-bookings-export-btn', function() {
                if (!window.FFCBatchedExport) { return; }
                var cfg = typeof ffcAudienceAdmin !== 'undefined' ? ffcAudienceAdmin : {};
                var s = cfg.strings || {};
                var exportNonce = cfg.exportNonce || '';
                if (!exportNonce) { return; }

                var $btn = $(this);

                // Progress is shown through the shared FFCProgressOverlay modal,
                // driven by the driver itself (overlay: true) — same UI as the
                // public download (#786).
                window.FFCBatchedExport.run({
                    type: 'audience_bookings',
                    ajaxUrl: cfg.ajaxUrl,
                    nonce: exportNonce,
                    button: $btn,
                    overlay: true,
                    strings: {
                        preparing: s.exportPreparing,
                        exporting: s.exportProgress,
                        downloading: s.exportDone,
                        error: s.error
                    },
                    startData: {
                        schedule_id:    $btn.data('schedule_id') || '',
                        environment_id: $btn.data('environment_id') || '',
                        status:         $btn.data('status') || '',
                        date_from:      $btn.data('date_from') || '',
                        date_to:        $btn.data('date_to') || ''
                    }
                });
            });
        }
    };

    /**
     * Edit a holiday or closed date in place (#2212). Each list row is
     * followed by a hidden edit row holding a real form, so saving needs no
     * script: this only swaps the two rows, and puts the stored values back
     * when the edit is cancelled. Delegated on document, so it holds on
     * every screen that prints the rows, whatever else that screen inits.
     */
    function bindHolidayEdit() {
        function rowsOf(key) {
            return {
                view: $('[data-ffc-holiday-view]').filter(function() { return $(this).attr('data-ffc-holiday-view') === key; }),
                edit: $('[data-ffc-holiday-edit]').filter(function() { return $(this).attr('data-ffc-holiday-edit') === key; })
            };
        }

        function close(key) {
            var rows = rowsOf(key);
            var form = rows.edit.find('form')[0];
            if (form) {
                form.reset();
            }
            rows.edit.prop('hidden', true);
            rows.view.prop('hidden', false);
            rows.view.find('[data-ffc-holiday-edit-open]').trigger('focus');
        }

        $(document).on('click', '[data-ffc-holiday-edit-open]', function(e) {
            e.preventDefault();
            var rows = rowsOf($(this).attr('data-ffc-holiday-edit-open'));
            rows.view.prop('hidden', true);
            rows.edit.prop('hidden', false);
            rows.edit.find('input[type="date"]').first().trigger('focus');
        });

        $(document).on('click', '[data-ffc-holiday-cancel]', function(e) {
            e.preventDefault();
            close($(this).attr('data-ffc-holiday-cancel'));
        });

        $(document).on('keydown', '.ffc-holiday-edit-row', function(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                close($(this).attr('data-ffc-holiday-edit'));
            }
        });
    }

    $(document).ready(function() {
        FFCAudienceAdmin.init();
        bindHolidayEdit();

        // The calendar colours (#2202, #2215) take the picker the Email Model uses;
        // its Default button restores the colour the plugin ships with.
        if (typeof $.fn.wpColorPicker === 'function') {
            $('.ffc-calendar-color').wpColorPicker();
        }
    });

})(jQuery);
