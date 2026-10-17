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
        FFC.clearFieldMessage($('#ffc-booking-form').find('input, select, textarea'));
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

        // Populate environment select (#1859): the spaces of the calendar in
        // view that open on this date. A bypass holder sees every space, the
        // restricted ones named with their reasons and marked.
        var $envSelect = $('#booking-environment-id');
        $envSelect.empty();

        var schedules = state.config.schedules || [];
        var scope = api.scopeEnvironments(state.selectedSchedule, 0);
        var hasMultipleSchedules = state.selectedSchedule > 0 ? false : schedules.length > 1;
        var listed = 0;

        scope.forEach(function(env) {
            var reasons = api.environmentReasons(env, date);
            if (reasons.length && !api.canBypass(env)) {
                return;
            }
            var label = hasMultipleSchedules ? env.schedule.name + ' - ' + env.name : env.name;
            var $option = $('<option></option>').val(env.id).text(label);
            if (reasons.length) {
                $option
                    .addClass('ffc-env-option-restricted')
                    .attr('data-reasons', reasons.join(' '))
                    .text(label + ' — ' + reasons.map(reasonLabel).join(', '));
            }
            $envSelect.append($option);
            listed++;
        });

        if (listed === 0) {
            FFC.fieldMessage($envSelect, 'error', ffcAudience.strings.noOpenEnvironments || 'No space is open on this date.');
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
            $('label[for="booking-environment-id"]').html(escapeHtml(currentSchedule.environmentLabel) + ' <span class="ffc-required" aria-hidden="true">*</span>');
        }

        // Set selected environment, when it is listed.
        var selectedEnv = environmentId || state.selectedEnvironment || '';
        if (selectedEnv && $envSelect.find('option[value="' + parseInt(selectedEnv) + '"]').length) {
            $envSelect.val(selectedEnv);
        }
        updateEnvironmentStatus();

        // Show audience select by default
        $('#booking-type').val('audience').trigger('change');

        state._triggerElement = document.activeElement;
        $modal.show();
        $modal.find('.ffc-modal-close').focus();
        trapFocus($modal);
    }

    /**
     * The short label of a reason code (#1859).
     */
    function reasonLabel(code) {
        var S = ffcAudience.strings || {};
        var labels = {
            closed_day: S.reasonClosed || 'Closed',
            holiday: S.reasonHoliday || 'Holiday',
            outside_hours: S.reasonOutsideHours || 'Outside hours',
            past_date: S.reasonPast || 'Past date',
            beyond_window: S.reasonBeyondWindow || 'Beyond the booking window'
        };
        return labels[code] || code;
    }

    /**
     * The environment the form has selected, as a scope entry.
     */
    function selectedEnvironment() {
        var id = parseInt($('#booking-environment-id').val());
        if (!id) {
            return null;
        }
        var matches = api.scopeEnvironments(0, id);
        return matches.length ? matches[0] : null;
    }

    /**
     * Show, under the space, why it cannot be booked at the chosen date and
     * times (#1859). Tags with token colours; hidden when nothing applies.
     */
    function updateEnvironmentStatus() {
        var $select = $('#booking-environment-id');
        var $status = $('#booking-environment-status');
        if (!$status.length) {
            $status = $('<div id="booking-environment-status" class="ffc-env-status" hidden></div>');
            $select.after($status);
        }
        $status.empty();

        var env = selectedEnvironment();
        var date = $('#booking-date').val();
        if (!env || !date) {
            $status.attr('hidden', 'hidden');
            return;
        }

        var allDay = $('#booking-all-day').is(':checked');
        var reasons = api.environmentReasons(
            env,
            date,
            allDay ? '' : $('#booking-start-time').val(),
            allDay ? '' : $('#booking-end-time').val()
        );

        if (!reasons.length) {
            $status.attr('hidden', 'hidden');
            return;
        }

        reasons.forEach(function(code) {
            $status.append($('<span></span>').addClass('ffc-env-reason-tag ffc-env-reason-' + code).text(reasonLabel(code)));
        });
        $status.removeAttr('hidden');
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
                    $('#booking-user-results').html(html).addClass('is-active');
                } else {
                    $('#booking-user-results').removeClass('is-active').empty();
                }
            })
            .catch(function() {
                $('#booking-user-results').removeClass('is-active').empty();
            });
    }

    /**
     * Update selected users display
     */
    function updateSelectedUsers() {
        var html = '';
        var ids = [];
        for (var id in state.selectedUsers) {
            html += '<span class="ffc-selected-user">' + escapeHtml(state.selectedUsers[id]) + '<span class="ffc-selected-user-remove" data-id="' + id + '">&times;</span></span>';
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

                        if (isHardConflict && conflicts.can_override) {
                            // HARD CONFLICT the person may override (#1859):
                            // offer the booking; the warning lists it again.
                            var overrideTimes = conflicts.bookings.map(function(b) { return b.start_time + '–' + b.end_time; }).join(', ');
                            $('#ffc-conflict-error')
                                .empty()
                                .append(FFC.notice('warning', 'warning', overrideTimes, ffcAudience.strings.hardConflict || 'This time slot is already booked for this environment.', { role: 'status', hook: 'conflict-error' }))
                                .show();
                            $btn.hide();
                            $('#ffc-create-booking-btn').show().prop('disabled', false);
                        } else if (isHardConflict) {
                            // HARD CONFLICT — block booking
                            var errorMsg = ffcAudience.strings.hardConflict || 'This time slot is already booked for this environment.';
                            var times = conflicts.bookings.map(function(b) { return b.start_time + '–' + b.end_time; }).join(', ');

                            $('#ffc-conflict-error')
                                .empty()
                                .append(FFC.notice('danger', 'error', times, errorMsg, { role: 'alert', hook: 'conflict-error' }))
                                .show();

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
                                softWarnings.push(ffcAudience.strings.audienceSameDayWarning || 'This audience group already has a booking on this day:');
                                for (var name in grouped) {
                                    softWarnings.push(name + ': ' + grouped[name].join(', '));
                                }
                            }

                            // User member overlap
                            if (conflicts.bookings && conflicts.bookings.length > 0 && conflicts.type === 'user') {
                                var count = conflicts.affected_users ? conflicts.affected_users.length : 0;
                                softWarnings.push(count + ' ' + (ffcAudience.strings.membersOverlapping || 'member(s) have overlapping bookings.'));
                            }

                            $btn.hide();

                            if (softWarnings.length > 0) {
                                // SOFT CONFLICT: show warning + require acknowledgment
                                // The acknowledgement checkbox is markup of the
                                // form, so it moves into the notice instead of
                                // being rebuilt: its change handler stays bound.
                                var $warning = $('#ffc-conflict-warning');
                                var ack = $warning.find('.ffc-conflict-acknowledge').detach()[0];
                                $warning
                                    .empty()
                                    .append(FFC.notice('warning', 'warning', softWarnings, '', { role: 'status', hook: 'conflict-warning', children: [ack] }))
                                    .show();
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
     *
     * A booking that breaks a rule, or overrides a conflict, comes back as a
     * 409 listing every reason (#1859). The person then reads them in one
     * warning; agreeing sends the same booking again with the confirmation.
     *
     * @param {boolean} [confirmed] Send the override confirmation.
     */
    function createBooking(confirmed) {
        if (!validateBookingForm()) {
            return;
        }

        var data = getBookingFormData();
        if (confirmed === true) {
            data.confirm_override = true;
        }

        var $btn = $('#ffc-create-booking-btn');
        function restore() {
            $btn.prop('disabled', false).text(ffcAudience.strings.createBooking);
        }

        $btn.prop('disabled', true).text(ffcAudience.strings.loading);

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
                    restore();
                    alert((response && response.message) || ffcAudience.strings.error);
                }
            })
            .catch(function(err) {
                var body = err && err.xhr && err.xhr.responseJSON;
                if (body && body.code === 'override_confirmation_required' && confirmed !== true) {
                    FFC.overrideConfirm({
                        reasons: body.reasons || [],
                        strings: (ffcAudience.strings || {}).override
                    }).then(function(agreed) {
                        if (agreed) {
                            createBooking(true);
                        } else {
                            restore();
                            $btn.trigger('focus');
                        }
                    });
                    return;
                }
                restore();
                alert((body && body.message) || ffcAudience.strings.error);
            });
    }

    /**
     * Validate booking form.
     *
     * Each problem is said under its own field, in the field-message
     * standard (#1844), instead of in an `alert()` that named one problem
     * and hid the field it was about. Every field is checked, so all the
     * problems show at once, and the first one takes the focus.
     */
    function validateBookingForm() {
        var S = ffcAudience.strings;
        var isAllDay = $('#booking-all-day').is(':checked');
        var $start = $('#booking-start-time');
        var $end = $('#booking-end-time');
        var $description = $('#booking-description');
        var bookingType = $('#booking-type').val();
        var invalid = [];

        function check($field, message) {
            if (message) {
                FFC.fieldMessage($field, 'error', message);
                invalid.push($field[0]);
            } else {
                FFC.clearFieldMessage($field);
            }
        }

        if (isAllDay) {
            FFC.clearFieldMessage($start.add($end));
        } else {
            var fill = S.fillTimeFields || 'Please fill in the time fields.';
            check($start, $start.val() ? '' : fill);
            check($end, !$end.val() ? fill : ($start.val() && $start.val() >= $end.val() ? S.invalidTime : ''));
        }

        var length = ($description.val() || '').trim().length;
        check($description, length < 15 || length > 300 ? S.descriptionRequired : '');

        var $audiences = $('#booking-audiences');
        var $users = $('#booking-user-search');
        if (bookingType === 'audience') {
            var audiences = $audiences.val();
            check($audiences, !audiences || audiences.length === 0 ? S.selectAudience : '');
            FFC.clearFieldMessage($users);
        } else {
            var userIds = $('#booking-user-ids').val();
            // The picker's chosen users sit under the search box; the
            // message goes after them, where the choice shows.
            if (!userIds || userIds.trim() === '') {
                FFC.fieldMessage($users, 'error', S.selectUser, { after: $('#booking-selected-users') });
                invalid.push($users[0]);
            } else {
                FFC.clearFieldMessage($users);
            }
            FFC.clearFieldMessage($audiences);
        }

        if (invalid.length) {
            $(invalid[0]).trigger('focus');
            return false;
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
    api.updateEnvironmentStatus = updateEnvironmentStatus;

})(jQuery);
