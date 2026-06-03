/**
 * FFC Audience Calendar — booking modal, user search, conflicts, create.
 *
 * Reads shared state/helpers from window.FFCAudience (ffc-audience.js) and
 * registers the booking-form methods back on the namespace.
 *
 * @since 4.5.0 (split out of ffc-audience.js)
 * @package FreeFormCertificate\Audience
 */

(function($) {
    'use strict';

    var api   = window.FFCAudience;
    var state = api.state;
    var parseDate  = api.parseDate;
    var escapeHtml = api.escapeHtml;
    var trapFocus  = api.trapFocus;

    /**
     * Open booking modal
     */
    function openBookingModal(date, environmentId) {
        var $modal = $('#ffc-booking-modal');
        var dateObj = parseDate(date);
        var dateDisplay = dateObj.toLocaleDateString(ffcAudience.locale, {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });

        // Reset form
        $('#ffc-booking-form')[0].reset();
        $('#booking-all-day').prop('checked', false);
        $('#booking-time-row').show();
        $('#booking-start-time').attr('required', 'required');
        $('#booking-end-time').attr('required', 'required');
        state.selectedUsers = {};
        updateSelectedUsers();
        $('#ffc-conflict-warning').hide();
        $('#ffc-conflict-error').hide();
        $('#ffc-conflict-acknowledge').prop('checked', false);
        $('#ffc-check-conflicts-btn').show();
        $('#ffc-create-booking-btn').hide().prop('disabled', false).text(ffcAudience.strings.createBooking);
        $('#desc-char-count').text('0');

        // Set values
        $('#booking-date').val(date);
        $('.ffc-booking-date-display').text(dateDisplay);

        // Populate environment select
        var $envSelect = $('#booking-environment-id');
        $envSelect.empty();

        var schedules = state.config.schedules || [];
        var allEnvironments = [];

        // Get all environments from all schedules
        for (var i = 0; i < schedules.length; i++) {
            var envs = schedules[i].environments || [];
            for (var j = 0; j < envs.length; j++) {
                allEnvironments.push({
                    id: envs[j].id,
                    name: envs[j].name,
                    scheduleName: schedules[i].name
                });
            }
        }

        // Add options to select
        if (allEnvironments.length > 1) {
            // Group by schedule if there are multiple schedules
            var hasMultipleSchedules = schedules.length > 1;
            allEnvironments.forEach(function(env) {
                var label = hasMultipleSchedules ? env.scheduleName + ' - ' + env.name : env.name;
                $envSelect.append('<option value="' + env.id + '">' + label + '</option>');
            });
        } else if (allEnvironments.length === 1) {
            $envSelect.append('<option value="' + allEnvironments[0].id + '">' + allEnvironments[0].name + '</option>');
        }

        // Update environment label in the booking form
        var currentSchedule = null;
        if (state.selectedSchedule > 0) {
            for (var s = 0; s < schedules.length; s++) {
                if (parseInt(schedules[s].id) === parseInt(state.selectedSchedule)) {
                    currentSchedule = schedules[s];
                    break;
                }
            }
        } else if (schedules.length === 1) {
            currentSchedule = schedules[0];
        }
        if (currentSchedule && currentSchedule.environmentLabel) {
            $('label[for="booking-environment-id"]').html(escapeHtml(currentSchedule.environmentLabel) + ' *');
        }

        // Set selected environment
        var selectedEnv = environmentId || state.selectedEnvironment || '';
        $envSelect.val(selectedEnv);

        // Show audience select by default
        $('#booking-type').val('audience').trigger('change');

        state._triggerElement = document.activeElement;
        $modal.show();
        $modal.find('.ffc-modal-close').focus();
        trapFocus($modal);
    }

    /**
     * Search users
     */
    function searchUsers(query) {
        FFC.request('ffc_search_users', { query: query }, { nonce: ffcAudience.searchUsersNonce })
            .then(function(data) {
                if (data && data.length > 0) {
                    var html = '';
                    data.forEach(function(user) {
                        if (!state.selectedUsers[user.id]) {
                            html += '<div class="ffc-user-result" data-id="' + user.id + '" data-name="' + escapeHtml(user.name) + '">' + escapeHtml(user.name) + ' (' + escapeHtml(user.email) + ')</div>';
                        }
                    });
                    $('#booking-user-results').html(html).addClass('active');
                } else {
                    $('#booking-user-results').removeClass('active').empty();
                }
            })
            .catch(function() {
                $('#booking-user-results').removeClass('active').empty();
            });
    }

    /**
     * Update selected users display
     */
    function updateSelectedUsers() {
        var html = '';
        var ids = [];
        for (var id in state.selectedUsers) {
            html += '<span class="ffc-selected-user">' + escapeHtml(state.selectedUsers[id]) + '<span class="remove" data-id="' + id + '">&times;</span></span>';
            ids.push(id);
        }
        $('#booking-selected-users').html(html);
        $('#booking-user-ids').val(ids.join(','));
    }

    /**
     * Check for conflicts
     */
    function checkConflicts() {
        if (!validateBookingForm()) {
            return;
        }

        var data = getBookingFormData();
        var $btn = $('#ffc-check-conflicts-btn');
        var originalText = $btn.text();

        $btn.prop('disabled', true).text(ffcAudience.strings.loading);

        FFC.rest(ffcAudience.restUrl + 'conflicts', {
            method: 'POST',
            timeout: 30000, // 30 second timeout
            nonce: ffcAudience.nonce,
            data: {
                environment_id: data.environment_id,
                booking_date: data.booking_date,
                start_time: data.start_time,
                end_time: data.end_time,
                audience_ids: data.audience_ids,
                user_ids: data.user_ids
            }
        })
            .then(function(response) {
                try {
                    if (response && response.success) {
                        var conflicts = response.conflicts || {};
                        var isHardConflict = (conflicts.type === 'environment');

                        // Reset conflict UI
                        $('#ffc-conflict-warning').hide();
                        $('#ffc-conflict-error').hide();
                        $('#ffc-conflict-acknowledge').prop('checked', false);

                        if (isHardConflict) {
                            // HARD CONFLICT — block booking
                            var errorMsg = ffcAudience.strings.hardConflict || 'This time slot is already booked for this environment.';
                            var times = conflicts.bookings.map(function(b) { return escapeHtml(b.start_time) + '–' + escapeHtml(b.end_time); }).join(', ');
                            var errorHtml = '<p><strong>' + escapeHtml(errorMsg) + '</strong></p><p>' + times + '</p>';

                            $('#ffc-conflict-error-details').html(errorHtml);
                            $('#ffc-conflict-error').show();

                            // Hide check button, do NOT show create button
                            $btn.hide();
                            $('#ffc-create-booking-btn').hide();
                        } else {
                            // Check for soft conflicts
                            var softWarnings = [];

                            // Same audience group already booked on this day
                            if (conflicts.audience_same_day && conflicts.audience_same_day.length > 0) {
                                var grouped = {};
                                conflicts.audience_same_day.forEach(function(b) {
                                    if (!grouped[b.audience_name]) {
                                        grouped[b.audience_name] = [];
                                    }
                                    grouped[b.audience_name].push(b.start_time + '–' + b.end_time);
                                });
                                var lines = [];
                                for (var name in grouped) {
                                    lines.push('<strong>' + escapeHtml(name) + '</strong>: ' + grouped[name].join(', '));
                                }
                                var sameDayMsg = ffcAudience.strings.audienceSameDayWarning || 'This audience group already has a booking on this day:';
                                softWarnings.push(sameDayMsg + '<br>' + lines.join('<br>'));
                            }

                            // User member overlap
                            if (conflicts.bookings && conflicts.bookings.length > 0 && conflicts.type === 'user') {
                                var count = conflicts.affected_users ? conflicts.affected_users.length : 0;
                                softWarnings.push(count + ' ' + (ffcAudience.strings.membersOverlapping || 'member(s) have overlapping bookings.'));
                            }

                            $btn.hide();

                            if (softWarnings.length > 0) {
                                // SOFT CONFLICT: show warning + require acknowledgment
                                $('#ffc-conflict-details').html(softWarnings.join('<br><br>'));
                                $('#ffc-conflict-warning').show();
                                $('#ffc-create-booking-btn').show().prop('disabled', true);
                            } else {
                                // NO CONFLICT: proceed directly
                                $('#ffc-create-booking-btn').show().prop('disabled', false);
                            }
                        }
                    } else {
                        alert((response && response.message) || ffcAudience.strings.error);
                    }
                } catch (e) {
                    console.error('Error processing conflict response:', e);
                    alert(ffcAudience.strings.error);
                }
            })
            .catch(function(err) {
                var xhr = err && err.xhr;
                console.error('Conflict check error:', err && err.message, xhr && xhr.responseText);
                var message = ffcAudience.strings.error;
                if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                    message = xhr.responseJSON.message;
                } else if (xhr && xhr.statusText === 'timeout') {
                    message = ffcAudience.strings.timeout;
                }
                alert(message);
            })
            .then(function() {
                // .finally equivalent — always restore the button state.
                // Chaining a no-error .then after .catch lets us run this
                // on both success + handled-error branches without needing
                // Promise.prototype.finally polyfills on legacy browsers.
                $btn.prop('disabled', false).text(originalText);
            });
    }

    /**
     * Create booking
     */
    function createBooking() {
        if (!validateBookingForm()) {
            return;
        }

        var data = getBookingFormData();

        $('#ffc-create-booking-btn').prop('disabled', true).text(ffcAudience.strings.loading);

        FFC.rest(ffcAudience.restUrl + 'bookings', {
            method: 'POST',
            data: data,
            nonce: ffcAudience.nonce
        })
            .then(function(response) {
                if (response && response.success) {
                    alert(ffcAudience.strings.bookingCreated);
                    api.closeModals();
                    api.renderCalendar();
                } else {
                    $('#ffc-create-booking-btn').prop('disabled', false).text(ffcAudience.strings.createBooking);
                    alert((response && response.message) || ffcAudience.strings.error);
                }
            })
            .catch(function() {
                $('#ffc-create-booking-btn').prop('disabled', false).text(ffcAudience.strings.createBooking);
                alert(ffcAudience.strings.error);
            });
    }

    /**
     * Validate booking form
     */
    function validateBookingForm() {
        var isAllDay = $('#booking-all-day').is(':checked');
        var startTime = $('#booking-start-time').val();
        var endTime = $('#booking-end-time').val();
        var description = $('#booking-description').val().trim();
        var bookingType = $('#booking-type').val();

        if (!isAllDay) {
            if (!startTime || !endTime) {
                alert(ffcAudience.strings.fillTimeFields || 'Please fill in the time fields.');
                return false;
            }

            if (startTime >= endTime) {
                alert(ffcAudience.strings.invalidTime);
                return false;
            }
        }

        if (description.length < 15 || description.length > 300) {
            alert(ffcAudience.strings.descriptionRequired);
            return false;
        }

        if (bookingType === 'audience') {
            var audiences = $('#booking-audiences').val();
            if (!audiences || audiences.length === 0) {
                alert(ffcAudience.strings.selectAudience);
                return false;
            }
        } else {
            var userIds = $('#booking-user-ids').val();
            if (!userIds || userIds.trim() === '') {
                alert(ffcAudience.strings.selectUser);
                return false;
            }
        }

        return true;
    }

    /**
     * Get booking form data
     */
    function getBookingFormData() {
        var bookingType = $('#booking-type').val();
        var isAllDay = $('#booking-all-day').is(':checked');
        var data = {
            environment_id: parseInt($('#booking-environment-id').val()),
            booking_date: $('#booking-date').val(),
            start_time: isAllDay ? '00:00' : $('#booking-start-time').val(),
            end_time: isAllDay ? '23:59' : $('#booking-end-time').val(),
            is_all_day: isAllDay ? 1 : 0,
            booking_type: bookingType,
            description: $('#booking-description').val().trim(),
            audience_ids: [],
            user_ids: []
        };

        if (bookingType === 'audience') {
            data.audience_ids = ($('#booking-audiences').val() || []).map(function(id) {
                return parseInt(id);
            });
        } else {
            data.user_ids = ($('#booking-user-ids').val() || '').split(',').filter(function(id) {
                return id.trim() !== '';
            }).map(function(id) {
                return parseInt(id);
            });
        }

        return data;
    }

    api.openBookingModal   = openBookingModal;
    api.searchUsers        = searchUsers;
    api.updateSelectedUsers = updateSelectedUsers;
    api.checkConflicts     = checkConflicts;
    api.createBooking      = createBooking;

})(jQuery);
