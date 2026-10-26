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
        $('#booking-date').val(date);
        $('#booking-end-date').val('').attr('min', date);
        $('input[name="booking_span"][value="single"]').prop('checked', true);
        $('input[name="time_mode"][value="daily"]').prop('checked', true);
        $('#booking-skip-blocked').prop('checked', false);
        setAllDay(false);
        setRangeOptions(false);
        state.selectedUsers = {};
        updateSelectedUsers();
        $('#booking-audience-search').val('');
        filterAudiences('');
        updateAudienceSummary();
        closeAudiencePicker(false);
        resetConflicts();
        setSubmitStep('check');
        $('#desc-char-count').text('0');

        // Set values
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
        setBookingType('audience');

        state._triggerElement = document.activeElement;
        $modal.show();
        $modal.find('.ffc-modal-close').focus();
        trapFocus($modal);
    }

    /**
     * The short label of a reason code (#1859).
     */
    /**
     * Render one date the way the modal's heading does (weekday, month name).
     *
     * @param {string} date Y-m-d.
     * @return {string}
     */
    function formatBookingDate(date) {
        return parseDate(date).toLocaleDateString(ffcAudience.locale, {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    }

    /**
     * Whether the dialog books several days (#2008): the "Several days"
     * segment is chosen.
     *
     * @return {boolean}
     */
    function isRange() {
        return $('input[name="booking_span"]:checked').val() === 'range';
    }

    /**
     * The chosen way of reading the hours of a range: `daily` or `span`.
     *
     * @return {string}
     */
    function timeMode() {
        return $('input[name="time_mode"]:checked').val() || 'daily';
    }

    /**
     * Show the range fields only for several days (#1946, #2008): the last
     * day, the hours on each day and the blocked-day switch. A range takes
     * its hours from the time mode, so the all-day switch is turned off and
     * hidden for one. The heading names the first and the last day.
     *
     * @param {boolean|string} range True (or a last day, Y-m-d) for several days.
     */
    function setRangeOptions(range) {
        var on = !!range;
        var date = $('#booking-date').val();
        var endDate = on ? ($('#booking-end-date').val() || '') : '';

        $('#booking-end-date-group, #booking-range-options').prop('hidden', !on);
        $('#booking-end-date').prop('required', on);
        if (on && $('#booking-all-day').is(':checked')) {
            setAllDay(false);
        }
        $('#booking-all-day-group').prop('hidden', on);

        if (date) {
            $('.ffc-booking-date-display').text(
                endDate && endDate !== date ? formatBookingDate(date) + ' – ' + formatBookingDate(endDate) : formatBookingDate(date)
            );
        }
        updateTimeModeHints();
    }

    /**
     * Turn the all-day switch on or off and follow it with the time fields:
     * a day taken whole needs no hours.
     *
     * @param {boolean} on
     */
    function setAllDay(on) {
        $('#booking-all-day').prop('checked', !!on);
        $('#booking-time-row').prop('hidden', !!on);
        $('#booking-start-time').prop('required', !on).val(on ? '00:00' : '');
        $('#booking-end-time').prop('required', !on).val(on ? '23:59' : '');
    }

    /**
     * Say under each time mode what it will book, with the times and days
     * the form holds (#2008); empty until the form holds them.
     */
    function updateTimeModeHints() {
        var S = ffcAudience.strings || {};
        var start = $('#booking-start-time').val() || '';
        var end = $('#booking-end-time').val() || '';
        var date = $('#booking-date').val() || '';
        var endDate = $('#booking-end-date').val() || '';
        var daily = '';
        var span = '';

        if (start && end) {
            daily = (S.hintDaily || '%1$s–%2$s on each day').replace('%1$s', start).replace('%2$s', end);
            if (date && endDate) {
                span = (S.hintSpan || 'From %1$s on %2$s to %3$s on %4$s')
                    .replace('%1$s', start)
                    .replace('%2$s', shortDate(date))
                    .replace('%3$s', end)
                    .replace('%4$s', shortDate(endDate));
            }
        }
        $('[data-ffc-time-mode-hint="daily"]').text(daily);
        $('[data-ffc-time-mode-hint="span"]').text(span);
    }

    /**
     * A day and month, the way the locale writes them (22/10).
     *
     * @param {string} date Y-m-d.
     * @return {string}
     */
    function shortDate(date) {
        return parseDate(date).toLocaleDateString(ffcAudience.locale, { day: '2-digit', month: '2-digit' });
    }

    /**
     * The chosen booking type: `audience` or `individual`.
     *
     * @return {string}
     */
    function bookingType() {
        return $('input[name="booking_type"]:checked').val() || 'audience';
    }

    /**
     * Choose a booking type and show the picker that goes with it.
     *
     * @param {string} [type] `audience` or `individual`; the chosen one when left out.
     */
    function setBookingType(type) {
        if (type) {
            $('input[name="booking_type"][value="' + type + '"]').prop('checked', true);
        }
        var isAudience = bookingType() === 'audience';
        $('#audience-select-group').prop('hidden', !isAudience);
        $('#user-select-group').prop('hidden', isAudience);
    }

    /* ----- Audience picker (#2008) ----- */

    /**
     * The audience checkboxes the calendar module rendered.
     *
     * @return {jQuery}
     */
    function audienceInputs() {
        return $('#booking-audiences input[type="checkbox"]');
    }

    /**
     * The ids of the checked audiences.
     *
     * @return {number[]}
     */
    function selectedAudienceIds() {
        return audienceInputs().filter(':checked').map(function() {
            return parseInt(this.value, 10);
        }).get();
    }

    /**
     * Every audience under one, at any depth.
     *
     * @param {string|number} id
     * @return {jQuery}
     */
    function audienceDescendants(id) {
        var $out = $();
        audienceInputs().filter('[data-parent="' + parseInt(id, 10) + '"]').each(function() {
            $out = $out.add(this).add(audienceDescendants(this.value));
        });
        return $out;
    }

    /**
     * Follow one checkbox: a parent takes its sub-audiences with it, on and
     * off, as the select it replaced did.
     *
     * @param {HTMLInputElement} input
     */
    function toggleAudience(input) {
        audienceDescendants(input.value).prop('checked', input.checked);
        updateAudienceSummary();
    }

    /**
     * Draw what is chosen: a chip per audience under the list, and the
     * summary the phone screen shows in place of the list.
     */
    function updateAudienceSummary() {
        var S = ffcAudience.strings || {};
        var $checked = audienceInputs().filter(':checked');
        var $chips = $('#booking-audience-chips').empty();
        var $value = $('#booking-audience-summary-value').empty();

        if (!$checked.length) {
            $value.text(S.noneSelected || 'None selected');
            $chips.prop('hidden', true);
            return;
        }

        $checked.each(function(i) {
            var $row = $(this).closest('.ffc-audience-picker-option');
            var name = $row.find('.ffc-audience-picker-name').text();
            var $dot = $row.find('.ffc-color-dot').first().clone();
            var remove = FFC.button({
                label: (S.removeAudience || 'Remove %s').replace('%s', name),
                category: 'neutral',
                icon: 'cross',
                iconOnly: true,
                className: 'ffc-audience-picker-chip-remove',
                attrs: { 'data-id': this.value }
            });
            $chips.append($('<span class="ffc-audience-picker-chip"></span>').append($dot, $('<span></span>').text(name), remove));

            if (i < 2) {
                $value.append($('<span class="ffc-audience-picker-summary-item"></span>').append($dot.clone(), document.createTextNode(name)));
            }
        });
        if ($checked.length > 2) {
            $value.append($('<span class="ffc-audience-picker-summary-more"></span>').text(
                (S.moreAudiences || '+%d more').replace('%d', String($checked.length - 2))
            ));
        }
        $chips.prop('hidden', false);
    }

    /**
     * Keep the audiences whose name holds the query; accents and case do
     * not count.
     *
     * @param {string} query
     */
    function filterAudiences(query) {
        var needle = fold(query);
        $('#booking-audiences .ffc-audience-picker-option').each(function() {
            var name = fold($(this).find('.ffc-audience-picker-name').text());
            $(this).prop('hidden', needle !== '' && name.indexOf(needle) === -1);
        });
    }

    /**
     * Lower case, without accents.
     *
     * @param {string} text
     * @return {string}
     */
    function fold(text) {
        var out = String(text || '').toLowerCase().trim();
        return typeof out.normalize === 'function' ? out.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : out;
    }

    /**
     * Open the picker as its own screen (a phone, where the list does not
     * fit beside the form); on a wider screen it is already in the form.
     */
    function openAudiencePicker() {
        $('#ffc-booking-modal .ffc-booking-dialog').addClass('is-picking-audiences');
        $('#booking-audience-summary').attr('aria-expanded', 'true');
        $('#booking-audience-search').trigger('focus');
    }

    /**
     * Close the picker screen.
     *
     * @param {boolean} [refocus] Give the focus back to the summary.
     */
    function closeAudiencePicker(refocus) {
        $('#ffc-booking-modal .ffc-booking-dialog').removeClass('is-picking-audiences');
        $('#booking-audience-summary').attr('aria-expanded', 'false');
        if (refocus) {
            $('#booking-audience-summary').trigger('focus');
        }
    }

    /* ----- The one submit button (#2008) ----- */

    /**
     * Set the dialog's one button to a step: `check` asks for the conflicts,
     * `create` makes the booking. The label and the category follow.
     *
     * @param {string} step `check` or `create`.
     */
    function setSubmitStep(step) {
        var S = ffcAudience.strings || {};
        var create = step === 'create';
        $('#ffc-booking-submit')
            .attr('data-step', create ? 'create' : 'check')
            .toggleClass('ffc-btn-primary ffc-icon-search', !create)
            .toggleClass('ffc-btn-create ffc-icon-plus', create)
            .removeClass('ffc-btn-loading')
            .prop('disabled', false)
            .text(create ? (S.createBooking || 'Create Booking') : (S.checkConflicts || 'Check Conflicts'));
    }

    /**
     * Mark the button busy while a request runs: the label stays.
     *
     * @param {boolean} busy
     */
    function setSubmitBusy(busy) {
        $('#ffc-booking-submit').toggleClass('ffc-btn-loading', busy).prop('disabled', busy);
    }

    /**
     * The button's action: check first, create once the check has passed.
     */
    function submitBooking() {
        if ($('#ffc-booking-submit').attr('data-step') === 'create') {
            createBooking();
        } else {
            checkConflicts();
        }
    }

    /**
     * A change to what is being booked undoes a check made for something
     * else: the conflicts go and the button asks for a new check.
     */
    function invalidateCheck() {
        if ($('#ffc-booking-submit').attr('data-step') !== 'create') {
            return;
        }
        resetConflicts();
        setSubmitStep('check');
    }

    /**
     * Hide both conflict notices and untick the acknowledgement.
     */
    function resetConflicts() {
        $('#ffc-conflict-warning, #ffc-conflict-error').prop('hidden', true);
        $('#ffc-conflict-acknowledge').prop('checked', false);
    }

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

        resetConflicts();
        setSubmitBusy(true);

        FFC.rest(ffcAudience.restUrl + 'conflicts', {
            method: 'POST',
            timeout: 30000, // 30 second timeout
            nonce: ffcAudience.nonce,
            data: {
                environment_id: data.environment_id,
                booking_date: data.booking_date,
                start_time: data.start_time,
                end_time: data.end_time,
                end_date: data.end_date,
                time_mode: data.time_mode,
                skip_blocked: data.skip_blocked,
                audience_ids: data.audience_ids,
                user_ids: data.user_ids
            }
        })
            .then(function(response) {
                try {
                    if (response && response.success) {
                        var conflicts = response.conflicts || {};
                        var isHardConflict = (conflicts.type === 'environment');

                        if (isHardConflict && conflicts.can_override) {
                            // HARD CONFLICT the person may override (#1859):
                            // offer the booking; the warning lists it again.
                            var overrideTimes = conflicts.bookings.map(function(b) { return b.start_time + '–' + b.end_time; }).join(', ');
                            $('#ffc-conflict-error')
                                .empty()
                                .append(FFC.notice('warning', 'warning', overrideTimes, ffcAudience.strings.hardConflict || 'This time slot is already booked for this environment.', { role: 'status', hook: 'conflict-error' }))
                                .prop('hidden', false);
                            setSubmitStep('create');
                        } else if (isHardConflict) {
                            // HARD CONFLICT — block booking
                            var errorMsg = ffcAudience.strings.hardConflict || 'This time slot is already booked for this environment.';
                            var times = conflicts.bookings.map(function(b) { return b.start_time + '–' + b.end_time; }).join(', ');

                            $('#ffc-conflict-error')
                                .empty()
                                .append(FFC.notice('danger', 'error', times, errorMsg, { role: 'alert', hook: 'conflict-error' }))
                                .prop('hidden', false);

                            // No booking here: the button stays a check, for
                            // after the time or the space has changed.
                            setSubmitStep('check');
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

                            setSubmitStep('create');

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
                                    .prop('hidden', false);
                                // The booking waits for the acknowledgement.
                                $('#ffc-booking-submit').prop('disabled', true);
                            }
                        }
                    } else {
                        setSubmitStep('check');
                        alert((response && response.message) || ffcAudience.strings.error);
                    }
                } catch (e) {
                    console.error('Error processing conflict response:', e);
                    setSubmitStep('check');
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
                setSubmitStep('check');
                alert(message);
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

        var $btn = $('#ffc-booking-submit');
        function restore() {
            setSubmitStep('create');
        }

        setSubmitBusy(true);

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
                    var reasons = (body.reasons || []).slice();
                    if (body.colliding_dates && body.colliding_dates.length) {
                        reasons.push({
                            code: 'colliding_dates',
                            message: ((ffcAudience.strings || {}).collidingDays || 'Already booked on: %s').replace('%s', body.colliding_dates.join(', '))
                        });
                    }
                    FFC.overrideConfirm({
                        reasons: reasons,
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
        var type = bookingType();
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
            // A span runs from one day to another, so its start may come after its end.
            var lastDay = isRange() ? ($('#booking-end-date').val() || '') : '';
            var isSpan = lastDay !== '' && lastDay !== $('#booking-date').val() && timeMode() === 'span';
            check($end, !$end.val() ? fill : (!isSpan && $start.val() && $start.val() >= $end.val() ? S.invalidTime : ''));
        }

        // Several days need a last day after the first (#2008).
        var $endDate = $('#booking-end-date');
        if (isRange()) {
            var endDate = $endDate.val() || '';
            check($endDate, !endDate ? (S.endDateRequired || 'Choose the last day.') : (endDate <= $('#booking-date').val() ? (S.endDateInvalid || 'The last day must come after the first.') : ''));
        } else {
            FFC.clearFieldMessage($endDate);
        }

        var length = ($description.val() || '').trim().length;
        check($description, length < 15 || length > 300 ? S.descriptionRequired : '');

        var $audiences = $('#booking-audience-search');
        var $users = $('#booking-user-search');
        if (type === 'audience') {
            // The message goes under the list, where the choice is made; on
            // a phone the summary carries it, and opening the picker shows it.
            if (selectedAudienceIds().length === 0) {
                FFC.fieldMessage($audiences, 'error', S.selectAudience, { after: $('#booking-audiences') });
                invalid.push(window.matchMedia && window.matchMedia('(max-width: 600px)').matches ? $('#booking-audience-summary')[0] : $audiences[0]);
            } else {
                FFC.clearFieldMessage($audiences);
            }
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
        var type = bookingType();
        var isAllDay = $('#booking-all-day').is(':checked');
        var data = {
            environment_id: parseInt($('#booking-environment-id').val()),
            booking_date: $('#booking-date').val(),
            start_time: isAllDay ? '00:00' : $('#booking-start-time').val(),
            end_time: isAllDay ? '23:59' : $('#booking-end-time').val(),
            is_all_day: isAllDay ? 1 : 0,
            booking_type: type,
            description: $('#booking-description').val().trim(),
            end_date: isRange() ? ($('#booking-end-date').val() || '') : '',
            time_mode: timeMode(),
            skip_blocked: $('#booking-skip-blocked').is(':checked') ? 1 : 0,
            audience_ids: [],
            user_ids: []
        };

        if (type === 'audience') {
            data.audience_ids = selectedAudienceIds();
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
    api.setRangeOptions    = setRangeOptions;
    api.searchUsers        = searchUsers;
    api.updateSelectedUsers = updateSelectedUsers;
    api.checkConflicts     = checkConflicts;
    api.createBooking      = createBooking;
    api.updateEnvironmentStatus = updateEnvironmentStatus;
    api.isRange            = isRange;
    api.setAllDay          = setAllDay;
    api.setBookingType     = setBookingType;
    api.updateTimeModeHints = updateTimeModeHints;
    api.toggleAudience     = toggleAudience;
    api.selectedAudienceIds = selectedAudienceIds;
    api.updateAudienceSummary = updateAudienceSummary;
    api.filterAudiences    = filterAudiences;
    api.openAudiencePicker = openAudiencePicker;
    api.closeAudiencePicker = closeAudiencePicker;
    api.setSubmitStep      = setSubmitStep;
    api.submitBooking      = submitBooking;
    api.invalidateCheck    = invalidateCheck;

})(jQuery);
