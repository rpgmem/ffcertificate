/**
 * FFC Audience Calendar — month grid, schedule/environment selects, event list.
 *
 * Reads shared state/helpers from window.FFCAudience (ffc-audience.js) and
 * registers the calendar-rendering methods back on the namespace.
 *
 * @since 4.5.0 (split out of ffc-audience.js)
 * @package FreeFormCertificate\Audience
 */

(function($) {
    'use strict';

    var api   = window.FFCAudience;
    var state = api.state;
    var formatDate              = api.formatDate;
    var pad                     = api.pad;
    var getBookingLabel         = api.getBookingLabel;
    var parseDate               = api.parseDate;
    var escapeHtml              = api.escapeHtml;
    var getEnvironmentColor     = api.getEnvironmentColor;
    var formatTime              = api.formatTime;
    var timesOn                 = api.timesOn;
    var collapseParentAudiences = api.collapseParentAudiences;
    var buildParentNameMap      = api.buildParentNameMap;
    var formatAudienceName      = api.formatAudienceName;

    /**
     * "All <plural label>", in the word order the translation gives (#1859).
     */
    function allLabel(plural) {
        var S = ffcAudience.strings || {};
        if (S.allOf && S.allOf.indexOf('%s') !== -1) {
            return S.allOf.replace('%s', plural);
        }
        return S.all ? S.all + ' ' + plural : plural;
    }

    /**
     * What a closed date is (#2202): `optional` for an optional holiday,
     * `holiday` for everything else, a date with no recorded kind included.
     */
    function holidayKind(dateStr) {
        return (state.holidayKinds || {})[dateStr] === 'optional' ? 'optional' : 'holiday';
    }

    /**
     * A kind's names: the full one and the one a day cell has room for.
     */
    function kindStrings(kind) {
        var S = ffcAudience.strings || {};
        var fallback = S.holiday || 'Holiday';
        return (S.closedDateKinds || {})[kind] || { label: fallback, short: fallback };
    }

    /**
     * A closed date's badge in its day cell. The cell shows the short name and
     * says the full one to a screen reader and on hover (#2202).
     */
    function holidayBadge(dateStr) {
        var kind = holidayKind(dateStr);
        var names = kindStrings(kind);
        return '<span class="ffc-day-badge ffc-badge-holiday' + (kind === 'optional' ? ' ffc-badge-optional-holiday' : '') + '"' +
            ' role="img" aria-label="' + escapeHtml(names.label) + '" title="' + escapeHtml(names.label) + '">' +
            escapeHtml(names.short) + '</span>';
    }

    /**
     * A colour dot. The colour is an operator's choice, so it stays inline as
     * the dot's custom property; anything not a hex colour is dropped and the
     * dot takes the text colour.
     */
    function colorDot(color) {
        var safe = /^#[0-9a-f]{3,8}$/i.test(color || '') ? color : '';
        return '<span class="ffc-color-dot" aria-hidden="true"' + (safe ? ' style="--ffc-color: ' + safe + ';"' : '') + '></span>';
    }

    /**
     * The environments the calendar currently describes: the one picked in
     * the filter, else those of the picked calendar, else every calendar's.
     * Each carries its calendar, so its rules can be read (#1859).
     */
    function scopeEnvironments(scheduleId, environmentId) {
        var schedules = state.config.schedules || [];
        var sid = typeof scheduleId === 'undefined' ? state.selectedSchedule : scheduleId;
        var eid = typeof environmentId === 'undefined' ? state.selectedEnvironment : environmentId;
        var out = [];
        schedules.forEach(function(schedule) {
            if (sid > 0 && parseInt(schedule.id) !== parseInt(sid)) {
                return;
            }
            (schedule.environments || []).forEach(function(env) {
                if (eid > 0 && parseInt(env.id) !== parseInt(eid)) {
                    return;
                }
                out.push({ id: parseInt(env.id), name: env.name, schedule: schedule });
            });
        });
        return out;
    }

    /**
     * Whether a date lies past a calendar's advance window.
     */
    function beyondWindow(date, schedule) {
        var limit = schedule ? schedule.futureDaysLimit : null;
        if (limit === null || typeof limit === 'undefined' || limit <= 0) {
            return false;
        }
        var maxDate = new Date();
        maxDate.setDate(maxDate.getDate() + limit);
        maxDate.setHours(23, 59, 59, 999);
        return date > maxDate;
    }

    function toMinutes(time) {
        var parts = String(time || '').split(':');
        return parseInt(parts[0], 10) * 60 + parseInt(parts[1] || '0', 10);
    }

    /**
     * Why an environment cannot be booked on a date, and — given times — at
     * those times (#1859). Codes: past_date, beyond_window, holiday,
     * closed_day, outside_hours. Empty when it can. The server applies the
     * same rules on every booking; this only tells the person beforehand.
     *
     * @param {Object} env     A scopeEnvironments() entry.
     * @param {string} dateStr Y-m-d.
     * @param {string} [start] H:i.
     * @param {string} [end]   H:i.
     */
    function environmentReasons(env, dateStr, start, end) {
        var reasons = [];
        var date = api.parseDate(dateStr);
        var today = new Date();
        today.setHours(0, 0, 0, 0);

        if (date < today) {
            reasons.push('past_date');
        }
        if (beyondWindow(date, env.schedule)) {
            reasons.push('beyond_window');
        }

        var info = state.availability ? state.availability[env.id] : null;
        var scheduleHolidays = (state.scheduleHolidays || {})[String(env.schedule.id)] || [];
        var isHoliday = (state.globalHolidays || []).indexOf(dateStr) !== -1
            || scheduleHolidays.indexOf(dateStr) !== -1;
        if (isHoliday) {
            reasons.push('holiday');
        }

        if (info) {
            var weekday = date.getDay();
            if ((info.closed_weekdays || []).indexOf(weekday) !== -1) {
                reasons.push('closed_day');
            } else if (start && end && info.hours) {
                var range = info.hours[weekday] || info.hours[String(weekday)] || null;
                if (range && (toMinutes(start) < toMinutes(range.start) || toMinutes(end) > toMinutes(range.end))) {
                    reasons.push('outside_hours');
                }
            }
        }

        return reasons;
    }

    /**
     * Whether the person may book an environment outside its rules: the
     * calendar's bypass, as the shortcode reported it (#1859).
     */
    function canBypass(env) {
        return !!(env && env.schedule && env.schedule.canBypass);
    }

    /**
     * Update environment select based on selected schedule
     */
    function updateEnvironmentSelect() {
        var $select = $('#ffc-environment-select');
        $select.find('option:not(:first)').remove();

        var schedules = state.config.schedules || [];
        var environments = [];
        var envLabelPlural = (ffcAudience.strings || {}).allEnvironments || 'All Environments';

        if (state.selectedSchedule > 0) {
            // Get environments for selected schedule
            for (var i = 0; i < schedules.length; i++) {
                // Use == for loose comparison (int vs string)
                if (parseInt(schedules[i].id) === parseInt(state.selectedSchedule)) {
                    environments = schedules[i].environments || [];
                    // Update dropdown label to match schedule's custom label:
                    // "All <plural label>" (#1859).
                    if (schedules[i].environmentLabelPlural) {
                        envLabelPlural = allLabel(schedules[i].environmentLabelPlural);
                    }
                    break;
                }
            }
        } else {
            // Get all environments
            for (var j = 0; j < schedules.length; j++) {
                var schEnvs = schedules[j].environments || [];
                for (var k = 0; k < schEnvs.length; k++) {
                    environments.push(schEnvs[k]);
                }
            }
        }

        // Update first option text with dynamic label
        $select.find('option:first').text(envLabelPlural);

        environments.forEach(function(env) {
            $select.append('<option value="' + env.id + '" data-color="' + escapeHtml(env.color || '#3788d8') + '">' + escapeHtml(env.name) + '</option>');
        });

        // Set dropdown value (0 = "All Environments" stays as default)
        if (state.selectedEnvironment > 0) {
            $select.val(state.selectedEnvironment);
        }

        syncEnvironmentPicker(envLabelPlural, environments);
    }

    /**
     * The environment filter as a list that shows each environment's colour
     * (#2201). A native option cannot draw a swatch, so the select stays as
     * the value the rest of the calendar reads and listens to, hidden from
     * sight and from assistive technology, and this listbox drives it: a
     * choice sets the select and fires its `change`.
     *
     * The list is built from the same configuration the select was filled
     * from, never read back out of the select: markup assembled from text the
     * DOM holds is how a value would be reinterpreted as HTML.
     */
    function syncEnvironmentPicker(allLabelText, environments) {
        var $select = $('#ffc-environment-select');
        if (!$select.length) {
            return;
        }

        var $picker = $('#ffc-environment-picker');
        if (!$picker.length) {
            $picker = $(
                '<div class="ffc-env-picker" id="ffc-environment-picker">' +
                    '<button type="button" class="ffc-env-picker-button" aria-haspopup="listbox" aria-expanded="false" aria-controls="ffc-environment-picker-list"></button>' +
                    '<ul class="ffc-env-picker-list" id="ffc-environment-picker-list" role="listbox" tabindex="-1" hidden></ul>' +
                '</div>'
            );
            $picker.find('.ffc-env-picker-list').attr('aria-label', $select.attr('aria-label') || '');
            $select.addClass('ffc-env-picker-native').attr({ 'aria-hidden': 'true', tabindex: '-1' }).after($picker);
            bindEnvironmentPicker($picker, $select);
        }

        var current = state.selectedEnvironment > 0 ? String(parseInt(state.selectedEnvironment, 10)) : '';
        var noDot = '<span class="ffc-env-picker-nodot" aria-hidden="true"></span>';
        // "All …" is not an environment, so it carries no dot (#2201).
        var items = [{ value: '', name: allLabelText, dot: noDot }];
        (environments || []).forEach(function(env) {
            items.push({ value: String(parseInt(env.id, 10)), name: env.name, dot: colorDot(env.color || '#3788d8') });
        });

        var $list = $picker.find('.ffc-env-picker-list').empty();
        var chosen = items[0];

        items.forEach(function(item, index) {
            var selected = item.value === current;
            $list.append(
                '<li role="option" class="ffc-env-picker-option" id="ffc-environment-option-' + index + '"' +
                ' data-value="' + escapeHtml(item.value) + '" aria-selected="' + (selected ? 'true' : 'false') + '">' +
                item.dot + '<span class="ffc-env-picker-name">' + escapeHtml(item.name) + '</span></li>'
            );
            if (selected) {
                chosen = item;
            }
        });

        var label = $select.attr('aria-label') || '';
        $picker.find('.ffc-env-picker-button')
            .attr('aria-label', label ? label + ': ' + chosen.name : chosen.name)
            .html(chosen.dot + '<span class="ffc-env-picker-name">' + escapeHtml(chosen.name) + '</span><span class="ffc-env-picker-caret ffc-icon-chevron-down" aria-hidden="true"></span>');
    }

    /**
     * Open, move through and choose from the environment list: the keys a
     * select answers to (arrows, Home, End, Enter, Space, Escape), and a
     * click outside closes it.
     */
    function bindEnvironmentPicker($picker, $select) {
        var $button = $picker.find('.ffc-env-picker-button');
        var $list = $picker.find('.ffc-env-picker-list');

        function options() {
            return $list.children('.ffc-env-picker-option');
        }

        function activate($option) {
            if (!$option.length) {
                return;
            }
            options().removeClass('is-active');
            $option.addClass('is-active');
            $list.attr('aria-activedescendant', $option.attr('id'));
            if ($option[0].scrollIntoView) {
                $option[0].scrollIntoView({ block: 'nearest' });
            }
        }

        function open() {
            $list.prop('hidden', false);
            $button.attr('aria-expanded', 'true');
            var $selected = options().filter('[aria-selected="true"]');
            activate($selected.length ? $selected : options().first());
            $list.trigger('focus');
            $(document).on('click.ffcEnvPicker', function(e) {
                if (!$.contains($picker[0], e.target)) {
                    close(false);
                }
            });
        }

        function close(refocus) {
            $list.prop('hidden', true).removeAttr('aria-activedescendant');
            $button.attr('aria-expanded', 'false');
            $(document).off('click.ffcEnvPicker');
            if (refocus) {
                $button.trigger('focus');
            }
        }

        function choose($option) {
            var value = String($option.attr('data-value') || '');
            close(true);
            if (value !== String($select.val() || '')) {
                $select.val(value).trigger('change');
            }
            // The select's change handler moves the state; reflect it.
            var current = String($select.val() || '');
            options().each(function() {
                $(this).attr('aria-selected', String($(this).attr('data-value')) === current ? 'true' : 'false');
            });
            var $chosen = options().filter('[aria-selected="true"]');
            $button.find('.ffc-env-picker-name').text($chosen.find('.ffc-env-picker-name').text());
            $button.attr('aria-label', ($list.attr('aria-label') ? $list.attr('aria-label') + ': ' : '') + $chosen.find('.ffc-env-picker-name').text());
            $button.children().first().replaceWith($chosen.children().first().clone());
        }

        $button.on('click', function() {
            if ($list.prop('hidden')) {
                open();
            } else {
                close(true);
            }
        });

        $button.on('keydown', function(e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                open();
            }
        });

        $list.on('click', '.ffc-env-picker-option', function() {
            choose($(this));
        });

        $list.on('keydown', function(e) {
            var $all = options();
            var index = $all.index($all.filter('.is-active'));
            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    activate($all.eq(Math.min(index + 1, $all.length - 1)));
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    activate($all.eq(Math.max(index - 1, 0)));
                    break;
                case 'Home':
                    e.preventDefault();
                    activate($all.first());
                    break;
                case 'End':
                    e.preventDefault();
                    activate($all.last());
                    break;
                case 'Enter':
                case ' ':
                    e.preventDefault();
                    if (index >= 0) {
                        choose($all.eq(index));
                    }
                    break;
                case 'Escape':
                    e.preventDefault();
                    close(true);
                    break;
                case 'Tab':
                    close(false);
                    break;
            }
        });
    }

    /**
     * Fill the booking dialog's audience picker (#2008): one checkbox row per
     * audience, indented under its parent, with its colour and the people a
     * booking for it reaches.
     */
    function populateAudienceSelect() {
        var $list = $('#booking-audiences');
        $list.empty();

        var S = ffcAudience.strings || {};
        var audiences = state.config.audiences || [];

        function people(count) {
            return count === 1 ? (S.onePerson || '1 person') : (S.people || '%d people').replace('%d', String(count));
        }

        function appendNodes(nodes, depth, parentId) {
            nodes.forEach(function(node) {
                var id = parseInt(node.id, 10);
                var html = '<label class="ffc-audience-picker-option" data-depth="' + Math.min(depth, 3) + '">' +
                    '<input type="checkbox" name="audience_ids[]" value="' + id + '"' + (parentId ? ' data-parent="' + parentId + '"' : '') + '>' +
                    colorDot(node.color) +
                    '<span class="ffc-audience-picker-name">' + escapeHtml(node.name) + '</span>';
                if (typeof node.members === 'number') {
                    html += '<span class="ffc-audience-picker-count">' + escapeHtml(people(node.members)) + '</span>';
                }
                $list.append(html + '</label>');
                if (node.children && node.children.length > 0) {
                    appendNodes(node.children, depth + 1, id);
                }
            });
        }

        appendNodes(audiences, 0, 0);
    }

    /**
     * Render the calendar grid
     */
    function renderCalendar() {
        var year = state.currentDate.getFullYear();
        var month = state.currentDate.getMonth();

        // Update header
        $('.ffc-current-month').text(ffcAudience.strings.months[month] + ' ' + year);

        // Update event list header
        var $eventListHeader = $('#ffc-event-list-panel .ffc-event-list-header h3');
        if ($eventListHeader.length) {
            $eventListHeader.text(((ffcAudience.strings || {}).events || 'Events') + ' - ' + ffcAudience.strings.months[month] + ' ' + year);
        }

        // Get first and last day of month
        var firstDay = new Date(year, month, 1);
        var lastDay = new Date(year, month + 1, 0);
        var startDay = firstDay.getDay();
        var daysInMonth = lastDay.getDate();

        // Get previous month days to show
        var prevMonthLastDay = new Date(year, month, 0).getDate();

        // Build calendar HTML
        var html = '';
        var day = 1;
        var nextMonthDay = 1;
        var today = new Date();
        today.setHours(0, 0, 0, 0);

        // Fetch bookings for this month
        fetchMonthData(year, month + 1, function() {
            for (var i = 0; i < 6; i++) {
                for (var j = 0; j < 7; j++) {
                    var cellDate, cellDay, classes = ['ffc-day'];
                    var dateStr = '';

                    if (i === 0 && j < startDay) {
                        // Previous month
                        cellDay = prevMonthLastDay - startDay + j + 1;
                        cellDate = new Date(year, month - 1, cellDay);
                        classes.push('ffc-other-month');
                    } else if (day > daysInMonth) {
                        // Next month
                        cellDay = nextMonthDay++;
                        cellDate = new Date(year, month + 1, cellDay);
                        classes.push('ffc-other-month');
                    } else {
                        // Current month
                        cellDay = day++;
                        cellDate = new Date(year, month, cellDay);
                    }

                    dateStr = formatDate(cellDate);

                    // Check if past
                    if (cellDate < today) {
                        classes.push('ffc-past');
                    }

                    // Check if today
                    if (cellDate.getTime() === today.getTime()) {
                        classes.push('ffc-today');
                    }

                    // Closed and holiday (#1859). With the per-space pattern
                    // the day is closed only when no space in view opens on
                    // it, and a holiday when every one is on holiday; the
                    // response of an older server falls back to the flat lists.
                    var dayState = dayAvailability(dateStr, cellDate);
                    var isHoliday = dayState.holiday;
                    var isClosed = dayState.closed;
                    var bypassable = dayState.bypassable;

                    if (isHoliday) {
                        classes.push('ffc-holiday');
                    } else if (isClosed) {
                        classes.push('ffc-closed');
                    }

                    // Mark available days (not past, not closed, not holiday, not other month, within booking window)
                    var isOtherMonth = classes.indexOf('ffc-other-month') !== -1;
                    var isPast = classes.indexOf('ffc-past') !== -1;
                    var isWithinBookingWindow = checkWithinBookingWindow(cellDate);
                    if (!isOtherMonth && !isPast && !isClosed && !isHoliday && isWithinBookingWindow) {
                        classes.push('ffc-available');
                    }

                    // A closed day stays shut, except for whoever may book a
                    // space outside its rules: for them it opens behind the
                    // override warning.
                    if (isHoliday || isClosed) {
                        classes.push(bypassable ? 'ffc-bypassable' : 'ffc-disabled');
                    } else if (bypassable && (isPast || !isWithinBookingWindow)) {
                        classes.push('ffc-bypassable');
                    }

                    // Get booking count
                    var bookingCount = getBookingCount(dateStr);

                    html += '<div class="' + classes.join(' ') + '" data-date="' + dateStr + '">';
                    html += '<span class="ffc-day-number">' + cellDay + '</span>';
                    html += '<div class="ffc-day-content">';

                    if (isHoliday) {
                        html += holidayBadge(dateStr);
                    } else if (isClosed) {
                        // The cell name and whether it shows are set with the
                        // other calendar states (#2215).
                        if (ffcAudience.strings.showClosedLabel !== false) {
                            html += '<span class="ffc-day-badge ffc-badge-closed">' + escapeHtml(ffcAudience.strings.closedCell || ffcAudience.strings.closed) + '</span>';
                        }
                    } else if (bookingCount > 0) {
                        var bLabel = bookingCount === 1 ? getBookingLabel('singular') : getBookingLabel('plural');
                        html += '<span class="ffc-day-badge ffc-badge-bookings">' + bookingCount + ' ' + escapeHtml(bLabel) + '</span>';
                    } else if (classes.indexOf('ffc-available') !== -1 && ffcAudience.strings.availableCell) {
                        // An available day carries the name set in Calendar
                        // Colors and Labels, when one is set (#2221).
                        html += '<span class="ffc-day-badge ffc-badge-available">' + escapeHtml(ffcAudience.strings.availableCell) + '</span>';
                    }

                    html += '</div></div>';
                }
            }

            $('#ffc-calendar-days').html(html);

            // Update event list panel if present
            renderEventList();
        });
    }

    /**
     * Whether a day is closed or a holiday for the spaces in view, and
     * whether the person may still open it (#1859).
     */
    function dayAvailability(dateStr, cellDate) {
        var scope = scopeEnvironments();
        var anyBypass = scope.some(canBypass);

        if (!state.availability || scope.length === 0) {
            var legacyHoliday = !!state.holidays[dateStr];
            var legacyClosed = !legacyHoliday && !!(state.closedWeekdays && state.closedWeekdays.indexOf(cellDate.getDay()) !== -1);
            return { holiday: legacyHoliday, closed: legacyClosed, bypassable: anyBypass };
        }

        var openCount = 0;
        var holidayCount = 0;
        scope.forEach(function(env) {
            var reasons = environmentReasons(env, dateStr);
            var shut = reasons.indexOf('holiday') !== -1 || reasons.indexOf('closed_day') !== -1;
            if (reasons.indexOf('holiday') !== -1) {
                holidayCount++;
            }
            if (!shut) {
                openCount++;
            }
        });

        var closedAll = openCount === 0;
        return {
            holiday: closedAll && holidayCount === scope.length,
            closed: closedAll && holidayCount !== scope.length,
            bypassable: anyBypass
        };
    }

    /**
     * Fetch bookings and holidays for a month
     */
    function fetchMonthData(year, month, callback) {
        var thisId = ++state.fetchId;

        // Clear stale data immediately so old badges/events disappear
        state.bookings = {};
        state.holidays = {};
        state.closedWeekdays = [];
        state.availability = null;
        state.scheduleHolidays = {};
        state.globalHolidays = [];

        var startDate = year + '-' + pad(month) + '-01';
        var lastDay = new Date(year, month, 0).getDate();
        var endDate = year + '-' + pad(month) + '-' + pad(lastDay);

        var params = {
            start_date: startDate,
            end_date: endDate
        };

        if (state.selectedSchedule > 0) {
            params.schedule_id = state.selectedSchedule;
        }

        if (state.selectedEnvironment > 0) {
            params.environment_id = state.selectedEnvironment;
        }

        FFC.rest(ffcAudience.restUrl + 'bookings', { data: params, nonce: ffcAudience.nonce })
            .then(function(response) {
                // Ignore stale responses — a newer fetch was already dispatched
                if (thisId !== state.fetchId) return;

                if (response.bookings) {
                    response.bookings.forEach(function(booking) {
                        // A range is listed on every day it takes; a single day has just its own date.
                        var days = booking.days && booking.days.length ? booking.days : [booking.booking_date];
                        days.forEach(function(day) {
                            if (!state.bookings[day]) {
                                state.bookings[day] = [];
                            }
                            state.bookings[day].push(booking);
                        });
                    });
                }

                state.holidayKinds = response.holiday_kinds || {};
                if (response.holidays) {
                    response.holidays.forEach(function(holiday) {
                        state.holidays[holiday.holiday_date] = holiday.description || true;
                        if (!state.holidayKinds[holiday.holiday_date] && holiday.kind) {
                            state.holidayKinds[holiday.holiday_date] = holiday.kind;
                        }
                    });
                }

                if (response.closed_weekdays) {
                    state.closedWeekdays = response.closed_weekdays;
                }

                // Which spaces open on which days (#1859).
                if (response.environments) {
                    state.availability = {};
                    response.environments.forEach(function(entry) {
                        state.availability[parseInt(entry.id)] = entry;
                    });
                }
                state.scheduleHolidays = response.schedule_holidays || {};
                state.globalHolidays = response.global_holidays || [];

                if (callback) callback();
            })
            .catch(function() {
                if (thisId !== state.fetchId) return;
                if (callback) callback();
            });
    }

    /**
     * Get booking count for a date
     */
    function getBookingCount(dateStr) {
        var bookings = state.bookings[dateStr] || [];
        return bookings.filter(function(b) { return b.status === 'active'; }).length;
    }

    /**
     * Check if a date is within the booking window (based on futureDaysLimit)
     */
    function checkWithinBookingWindow(date) {
        // Get the selected schedule's future days limit
        var schedules = state.config.schedules || [];
        var futureDaysLimit = null;

        if (state.selectedSchedule > 0) {
            // Find the selected schedule
            for (var i = 0; i < schedules.length; i++) {
                if (schedules[i].id === state.selectedSchedule) {
                    futureDaysLimit = schedules[i].futureDaysLimit;
                    break;
                }
            }
        } else {
            // No schedule selected - use the minimum limit from all schedules (if any have limits)
            for (var j = 0; j < schedules.length; j++) {
                var limit = schedules[j].futureDaysLimit;
                if (limit !== null && limit > 0) {
                    if (futureDaysLimit === null || limit < futureDaysLimit) {
                        futureDaysLimit = limit;
                    }
                }
            }
        }

        // If no limit, all future dates are within window
        if (futureDaysLimit === null || futureDaysLimit <= 0) {
            return true;
        }

        // Calculate max date
        var maxDate = new Date();
        maxDate.setDate(maxDate.getDate() + futureDaysLimit);
        maxDate.setHours(23, 59, 59, 999);

        return date <= maxDate;
    }

    /**
     * Render the event list panel with bookings for the visible month
     */
    function renderEventList() {
        var $panel = $('#ffc-event-list-content');
        if (!$panel.length) {
            return;
        }

        // Collect all events: active bookings + holidays
        var allEvents = [];
        for (var dateStr in state.bookings) {
            var dayBookings = state.bookings[dateStr];
            for (var i = 0; i < dayBookings.length; i++) {
                if (dayBookings[i].status === 'active') {
                    allEvents.push({ type: 'booking', date: dateStr, data: dayBookings[i] });
                }
            }
        }

        // Add holidays
        for (var holidayDate in state.holidays) {
            var holidayName = state.holidays[holidayDate];
            allEvents.push({
                type: 'holiday',
                date: holidayDate,
                data: { description: typeof holidayName === 'string' ? holidayName : '' }
            });
        }

        if (allEvents.length === 0) {
            $panel.html('<p class="ffc-no-events">' + ((ffcAudience.strings || {}).noEvents || 'No events this month.') + '</p>');
            return;
        }

        // Sort by date, then holidays first, then start_time
        allEvents.sort(function(a, b) {
            if (a.date < b.date) return -1;
            if (a.date > b.date) return 1;
            if (a.type === 'holiday' && b.type !== 'holiday') return -1;
            if (a.type !== 'holiday' && b.type === 'holiday') return 1;
            if (a.type === 'booking' && b.type === 'booking') {
                return timesOn(a.data, a.date).start_time.localeCompare(timesOn(b.data, b.date).start_time);
            }
            return 0;
        });

        var html = '';
        var lastDate = '';

        allEvents.forEach(function(evt) {
            // Group header for each date
            if (evt.date !== lastDate) {
                lastDate = evt.date;
                var dateObj = parseDate(evt.date);
                var dateDisplay = dateObj.toLocaleDateString(ffcAudience.locale, {
                    weekday: 'short',
                    day: 'numeric',
                    month: 'short'
                });
                html += '<div class="ffc-event-list-date">' + dateDisplay + '</div>';
            }

            if (evt.type === 'holiday') {
                // Holiday item
                var holidayOptional = holidayKind(evt.date) === 'optional';
                var holidayLabel = kindStrings(holidayOptional ? 'optional' : 'holiday').label;
                var holidayDesc = evt.data.description || '';
                html += '<div class="ffc-event-list-item ffc-event-list-holiday' + (holidayOptional ? ' ffc-event-list-optional-holiday' : '') + '" data-date="' + evt.date + '">';
                html += '<span class="ffc-event-list-time ffc-all-day">' + escapeHtml(holidayLabel) + '</span>';
                if (holidayDesc) {
                    html += '<span class="ffc-event-list-desc">' + escapeHtml(holidayDesc) + '</span>';
                }
                html += '</div>';
            } else {
                // Booking item
                var booking = evt.data;
                var evtColor = getEnvironmentColor(booking.environment_id);
                html += '<div class="ffc-event-list-item" data-date="' + evt.date + '" style="border-left: 3px solid ' + escapeHtml(evtColor) + ';">';

                // Time
                if (parseInt(booking.is_all_day)) {
                    html += '<span class="ffc-event-list-time ffc-all-day">' + ((ffcAudience.strings || {}).allDay || 'All Day') + '</span>';
                } else {
                    var times = timesOn(booking, evt.date);
                    html += '<span class="ffc-event-list-time">' + formatTime(times.start_time) + ' - ' + formatTime(times.end_time) + '</span>';
                }

                // Environment name
                html += '<span class="ffc-event-list-env">' + escapeHtml(booking.environment_name) + '</span>';

                // Description (truncated)
                var desc = booking.description || '';
                if (desc.length > 60) {
                    desc = desc.substring(0, 57) + '...';
                }
                html += '<span class="ffc-event-list-desc">' + escapeHtml(desc) + '</span>';

                // Audiences (collapse parent groups, then show summary badge when more than 2)
                if (booking.audiences && booking.audiences.length > 0) {
                    var displayAudiencesEL = collapseParentAudiences(booking.audiences);
                    var pMapEL = buildParentNameMap();
                    html += '<span class="ffc-event-list-audiences">';
                    if (displayAudiencesEL.length > 2) {
                        var maColor = ffcAudience.multipleAudiencesColor || 'var(--ffc-gray-600)';
                        html += '<span class="ffc-audience-tag-sm" style="background-color: ' + escapeHtml(maColor) + '">' + escapeHtml(ffcAudience.strings.multipleAudiences) + ' (' + displayAudiencesEL.length + ')</span>';
                    } else {
                        displayAudiencesEL.forEach(function(audience) {
                            html += '<span class="ffc-audience-tag-sm" style="background-color: ' + escapeHtml(audience.color) + '">' + escapeHtml(formatAudienceName(audience, pMapEL)) + '</span>';
                        });
                    }
                    html += '</span>';
                }

                html += '</div>';
            }
        });

        $panel.html(html);

        // Click on event item opens day modal
        $panel.find('.ffc-event-list-item').on('click', function() {
            var date = $(this).data('date');
            if (date) {
                api.openDayModal(date);
            }
        });
    }

    api.updateEnvironmentSelect = updateEnvironmentSelect;
    api.scopeEnvironments       = scopeEnvironments;
    api.environmentReasons      = environmentReasons;
    api.canBypass               = canBypass;
    api.populateAudienceSelect  = populateAudienceSelect;
    api.renderCalendar          = renderCalendar;

})(jQuery);
