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
    var collapseParentAudiences = api.collapseParentAudiences;
    var buildParentNameMap      = api.buildParentNameMap;
    var formatAudienceName      = api.formatAudienceName;

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
                    // Update dropdown label to match schedule's custom label
                    if (schedules[i].environmentLabelPlural) {
                        envLabelPlural = (ffcAudience.strings || {}).all
                            ? (ffcAudience.strings.all + ' ' + schedules[i].environmentLabelPlural)
                            : schedules[i].environmentLabelPlural;
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
    }

    /**
     * Populate audience select
     */
    function populateAudienceSelect() {
        var $select = $('#booking-audiences');
        $select.empty();

        var audiences = state.config.audiences || [];

        function appendNodes(nodes, depth) {
            nodes.forEach(function(node) {
                var indent = new Array(depth + 1).join('   ');
                var prefix = depth > 0 ? indent + '└ ' : '';
                $select.append('<option value="' + node.id + '">' + prefix + escapeHtml(node.name) + '</option>');
                if (node.children && node.children.length > 0) {
                    appendNodes(node.children, depth + 1);
                }
            });
        }

        appendNodes(audiences, 0);
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

                    // Check for holidays
                    var isHoliday = state.holidays[dateStr];
                    if (isHoliday) {
                        classes.push('ffc-holiday');
                        classes.push('ffc-disabled');
                    }

                    // Check for closed weekdays
                    var weekday = cellDate.getDay();
                    var isClosed = state.closedWeekdays && state.closedWeekdays.indexOf(weekday) !== -1;
                    if (isClosed && !isHoliday) {
                        classes.push('ffc-closed');
                        classes.push('ffc-disabled');
                    }

                    // Mark available days (not past, not closed, not holiday, not other month, within booking window)
                    var isOtherMonth = classes.indexOf('ffc-other-month') !== -1;
                    var isPast = classes.indexOf('ffc-past') !== -1;
                    var isWithinBookingWindow = checkWithinBookingWindow(cellDate);
                    if (!isOtherMonth && !isPast && !isClosed && !isHoliday && isWithinBookingWindow) {
                        classes.push('ffc-available');
                    }

                    // Get booking count
                    var bookingCount = getBookingCount(dateStr);

                    html += '<div class="' + classes.join(' ') + '" data-date="' + dateStr + '">';
                    html += '<span class="ffc-day-number">' + cellDay + '</span>';
                    html += '<div class="ffc-day-content">';

                    if (isHoliday) {
                        html += '<span class="ffc-day-badge ffc-badge-holiday">' + ffcAudience.strings.holiday + '</span>';
                    } else if (isClosed) {
                        html += '<span class="ffc-day-badge ffc-badge-closed">' + ffcAudience.strings.closed + '</span>';
                    } else if (bookingCount > 0) {
                        var bLabel = bookingCount === 1 ? getBookingLabel('singular') : getBookingLabel('plural');
                        html += '<span class="ffc-day-badge ffc-badge-bookings">' + bookingCount + ' ' + escapeHtml(bLabel) + '</span>';
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
     * Fetch bookings and holidays for a month
     */
    function fetchMonthData(year, month, callback) {
        var thisId = ++state.fetchId;

        // Clear stale data immediately so old badges/events disappear
        state.bookings = {};
        state.holidays = {};
        state.closedWeekdays = [];

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
                        if (!state.bookings[booking.booking_date]) {
                            state.bookings[booking.booking_date] = [];
                        }
                        state.bookings[booking.booking_date].push(booking);
                    });
                }

                if (response.holidays) {
                    response.holidays.forEach(function(holiday) {
                        state.holidays[holiday.holiday_date] = holiday.description || true;
                    });
                }

                if (response.closed_weekdays) {
                    state.closedWeekdays = response.closed_weekdays;
                }

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
                    allEvents.push({ type: 'booking', date: dayBookings[i].booking_date, data: dayBookings[i] });
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
                return a.data.start_time.localeCompare(b.data.start_time);
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
                var holidayLabel = ffcAudience.strings.holiday || 'Holiday';
                var holidayDesc = evt.data.description || '';
                html += '<div class="ffc-event-list-item" data-date="' + evt.date + '" style="border-left: 3px solid var(--ffc-warning);">';
                html += '<span class="ffc-event-list-time ffc-all-day">' + escapeHtml(holidayLabel) + '</span>';
                if (holidayDesc) {
                    html += '<span class="ffc-event-list-desc">' + escapeHtml(holidayDesc) + '</span>';
                }
                html += '</div>';
            } else {
                // Booking item
                var booking = evt.data;
                var evtColor = getEnvironmentColor(booking.environment_id);
                html += '<div class="ffc-event-list-item" data-date="' + booking.booking_date + '" style="border-left: 3px solid ' + escapeHtml(evtColor) + ';">';

                // Time
                if (parseInt(booking.is_all_day)) {
                    html += '<span class="ffc-event-list-time ffc-all-day">' + ((ffcAudience.strings || {}).allDay || 'All Day') + '</span>';
                } else {
                    html += '<span class="ffc-event-list-time">' + formatTime(booking.start_time) + ' - ' + formatTime(booking.end_time) + '</span>';
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
    api.populateAudienceSelect  = populateAudienceSelect;
    api.renderCalendar          = renderCalendar;

})(jQuery);
