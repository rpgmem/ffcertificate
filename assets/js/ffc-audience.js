/**
 * FFC Audience Calendar
 *
 * @since 4.5.0
 * @package FreeFormCertificate\Audience
 */

(function($) {
    'use strict';

    // Ensure ffcAudience is defined with defaults
    if (typeof ffcAudience === 'undefined') {
        window.ffcAudience = {
            ajaxUrl: '/wp-admin/admin-ajax.php',
            restUrl: '/wp-json/ffc/v1/audience/',
            nonce: ''
        };
    }
    if (!ffcAudience.strings) {
        ffcAudience.strings = {};
    }
    // Default strings fallback
    var defaultStrings = {
        months: ['January', 'February', 'March', 'April', 'May', 'June',
                 'July', 'August', 'September', 'October', 'November', 'December'],
        loading: 'Loading...',
        error: 'An error occurred. Please try again.',
        noBookings: 'No bookings for this day.',
        noActiveBookings: 'No active bookings for this day.',
        bookingCreated: 'Booking created successfully!',
        bookingCancelled: 'Booking cancelled successfully.',
        confirmCancel: 'Are you sure you want to cancel this booking?',
        cancelReason: 'Please provide a reason for cancellation:',
        invalidTime: 'End time must be after start time.',
        selectAudience: 'Please select at least one audience.',
        selectUser: 'Please select at least one user.',
        descriptionRequired: 'Description is required (15-300 characters).',
        conflictWarning: 'Warning: Conflicts detected with existing bookings.',
        holiday: 'Holiday',
        closed: 'Closed',
        cancelled: 'Cancelled',
        cancel: 'Cancel',
        available: 'Available',
        booked: 'Booked',
        timeout: 'Request timed out. Please try again.',
        checkConflicts: 'Check Conflicts',
        booking: 'booking',
        bookings: 'bookings',
        createBooking: 'Create Booking',
        newBooking: 'New Booking'
    };
    for (var key in defaultStrings) {
        if (!ffcAudience.strings[key]) {
            ffcAudience.strings[key] = defaultStrings[key];
        }
    }

    // Calendar state
    var state = {
        currentDate: new Date(),
        selectedSchedule: 0,
        selectedEnvironment: 0,
        config: {},
        bookings: {},
        holidays: {},
        selectedUsers: {}
    };

    /**
     * Initialize the calendar
     */
    function init() {
        var $calendar = $('#ffc-audience-calendar');
        if (!$calendar.length) {
            return;
        }

        // Parse config from data attribute
        state.config = JSON.parse($calendar.attr('data-config') || '{}');
        state.selectedSchedule = state.config.scheduleId || 0;
        state.selectedEnvironment = state.config.environmentId || 0;

        // Initialize UI
        updateEnvironmentSelect();
        populateAudienceSelect();
        renderCalendar();
        bindEvents();
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // Navigation
        $('.ffc-prev-month').on('click', function() {
            state.currentDate.setMonth(state.currentDate.getMonth() - 1);
            renderCalendar();
        });

        $('.ffc-next-month').on('click', function() {
            state.currentDate.setMonth(state.currentDate.getMonth() + 1);
            renderCalendar();
        });

        $('.ffc-today-btn').on('click', function() {
            state.currentDate = new Date();
            renderCalendar();
        });

        // Filters
        $('#ffc-schedule-select').on('change', function() {
            state.selectedSchedule = parseInt($(this).val()) || 0;
            updateEnvironmentSelect();
            renderCalendar();
        });

        $('#ffc-environment-select').on('change', function() {
            state.selectedEnvironment = parseInt($(this).val()) || 0;
            renderCalendar();
        });

        // Day click - scoped to audience calendar only
        $('#ffc-audience-calendar').on('click', '.ffc-day:not(.ffc-past)', function() {
            var date = $(this).data('date');
            if (date) {
                openDayModal(date);
            }
        });

        // Modal controls
        $('.ffc-modal-close, .ffc-modal-cancel, .ffc-modal-backdrop').on('click', function() {
            closeModals();
        });

        // Show cancelled checkbox
        $('#ffc-show-cancelled').on('change', function() {
            var date = $('#ffc-day-modal').data('date');
            if (date) {
                loadDayBookings(date);
            }
        });

        // Booking type toggle
        $('#booking-type').on('change', function() {
            if ($(this).val() === 'audience') {
                $('#audience-select-group').show();
                $('#user-select-group').hide();
            } else {
                $('#audience-select-group').hide();
                $('#user-select-group').show();
            }
        });

        // Description character count
        $('#booking-description').on('input', function() {
            $('#desc-char-count').text($(this).val().length);
        });

        // User search
        var searchTimeout;
        $('#booking-user-search').on('input', function() {
            clearTimeout(searchTimeout);
            var query = $(this).val();
            if (query.length < 2) {
                $('#booking-user-results').removeClass('active').empty();
                return;
            }

            searchTimeout = setTimeout(function() {
                searchUsers(query);
            }, 300);
        });

        // Select user from results
        $(document).on('click', '#booking-user-results .ffc-user-result', function() {
            var id = $(this).data('id');
            var name = $(this).data('name');
            state.selectedUsers[id] = name;
            updateSelectedUsers();
            $('#booking-user-results').removeClass('active').empty();
            $('#booking-user-search').val('');
        });

        // Remove selected user
        $(document).on('click', '#booking-selected-users .remove', function() {
            var id = $(this).data('id');
            delete state.selectedUsers[id];
            updateSelectedUsers();
        });

        // Check conflicts button
        $('#ffc-check-conflicts-btn').on('click', function() {
            checkConflicts();
        });

        // Create booking button
        $('#ffc-create-booking-btn').on('click', function() {
            createBooking();
        });

        // New booking from day modal
        $('#ffc-new-booking-btn').on('click', function() {
            var date = $('#ffc-day-modal').data('date');
            closeModals();
            openBookingModal(date);
        });

        // Prevent modal close on content click
        $('.ffc-modal-content').on('click', function(e) {
            e.stopPropagation();
        });
    }

    /**
     * Update environment select based on selected schedule
     */
    function updateEnvironmentSelect() {
        var $select = $('#ffc-environment-select');
        $select.find('option:not(:first)').remove();

        var schedules = state.config.schedules || [];
        var environments = [];

        if (state.selectedSchedule > 0) {
            // Get environments for selected schedule
            for (var i = 0; i < schedules.length; i++) {
                // Use == for loose comparison (int vs string)
                if (parseInt(schedules[i].id) === parseInt(state.selectedSchedule)) {
                    environments = schedules[i].environments || [];
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

        environments.forEach(function(env) {
            $select.append('<option value="' + env.id + '">' + env.name + '</option>');
        });

        // Auto-select first environment if none selected
        if (state.selectedEnvironment === 0 && environments.length > 0) {
            state.selectedEnvironment = parseInt(environments[0].id);
            $select.val(state.selectedEnvironment);
        } else if (state.selectedEnvironment > 0) {
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

        audiences.forEach(function(audience) {
            if (audience.children && audience.children.length > 0) {
                var $group = $('<optgroup label="' + audience.name + '">');
                audience.children.forEach(function(child) {
                    $group.append('<option value="' + child.id + '">' + child.name + '</option>');
                });
                $select.append($group);
            } else {
                $select.append('<option value="' + audience.id + '">' + audience.name + '</option>');
            }
        });
    }

    /**
     * Render the calendar grid
     */
    function renderCalendar() {
        var year = state.currentDate.getFullYear();
        var month = state.currentDate.getMonth();

        // Update header
        $('.ffc-current-month').text(ffcAudience.strings.months[month] + ' ' + year);

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
                    }

                    // Check for closed weekdays
                    var weekday = cellDate.getDay();
                    var isClosed = state.closedWeekdays && state.closedWeekdays.indexOf(weekday) !== -1;
                    if (isClosed && !isHoliday) {
                        classes.push('ffc-closed');
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
                        html += '<span class="ffc-day-badge ffc-badge-holiday">' + (typeof isHoliday === 'string' ? isHoliday : ffcAudience.strings.holiday) + '</span>';
                    } else if (isClosed) {
                        html += '<span class="ffc-day-badge ffc-badge-closed">' + ffcAudience.strings.closed + '</span>';
                    } else if (bookingCount > 0) {
                        html += '<span class="ffc-day-badge ffc-badge-bookings">' + bookingCount + ' ' + (bookingCount === 1 ? ffcAudience.strings.booking : ffcAudience.strings.bookings) + '</span>';
                    }

                    html += '</div></div>';
                }
            }

            $('#ffc-calendar-days').html(html);
        });
    }

    /**
     * Fetch bookings and holidays for a month
     */
    function fetchMonthData(year, month, callback) {
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

        $.ajax({
            url: ffcAudience.restUrl + 'bookings',
            method: 'GET',
            data: params,
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', ffcAudience.nonce);
            },
            success: function(response) {
                state.bookings = {};
                state.holidays = {};
                state.closedWeekdays = [];

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
            },
            error: function() {
                if (callback) callback();
            }
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
     * Open day detail modal
     */
    function openDayModal(date) {
        var $modal = $('#ffc-day-modal');
        var dateObj = parseDate(date);
        var dateDisplay = dateObj.toLocaleDateString(ffcAudience.locale, {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });

        $modal.find('.ffc-day-modal-title').text(dateDisplay);
        $modal.data('date', date);
        $modal.show();

        // Load bookings
        loadDayBookings(date);
    }

    /**
     * Load bookings for a specific day
     */
    function loadDayBookings(date) {
        var $container = $('#ffc-day-bookings');
        var allBookings = state.bookings[date] || [];
        var showCancelled = $('#ffc-show-cancelled').is(':checked');

        // Filter bookings based on show cancelled option
        var bookings = allBookings.filter(function(b) {
            if (showCancelled) {
                return true;
            }
            return b.status === 'active';
        });

        if (bookings.length === 0) {
            var message = ffcAudience.strings.noBookings;
            if (!showCancelled && allBookings.length > 0) {
                message = ffcAudience.strings.noActiveBookings || 'No active bookings for this day.';
            }
            $container.html('<p class="ffc-no-bookings">' + message + '</p>');
            return;
        }

        var html = '';
        bookings.sort(function(a, b) {
            return a.start_time.localeCompare(b.start_time);
        });

        bookings.forEach(function(booking) {
            var classes = ['ffc-booking-item'];
            if (booking.status === 'cancelled') {
                classes.push('ffc-booking-cancelled');
            }

            html += '<div class="' + classes.join(' ') + '">';
            html += '<div class="ffc-booking-time">' + formatTime(booking.start_time) + ' - ' + formatTime(booking.end_time) + '</div>';
            html += '<div class="ffc-booking-description">' + escapeHtml(booking.description) + '</div>';

            html += '<div class="ffc-booking-meta">';
            html += '<span><strong>Environment:</strong> ' + escapeHtml(booking.environment_name) + '</span>';
            if (booking.status === 'cancelled') {
                html += ' <span class="ffc-status-cancelled">(' + (ffcAudience.strings.cancelled || 'Cancelled') + ')</span>';
            }
            html += '</div>';

            if (booking.audiences && booking.audiences.length > 0) {
                html += '<div class="ffc-booking-audiences">';
                booking.audiences.forEach(function(audience) {
                    html += '<span class="ffc-audience-tag" style="background-color: ' + audience.color + '">' + escapeHtml(audience.name) + '</span>';
                });
                html += '</div>';
            }

            if (booking.status === 'active' && state.config.canBook) {
                html += '<div class="ffc-booking-actions">';
                html += '<button type="button" class="ffc-btn ffc-btn-danger ffc-cancel-booking" data-id="' + booking.id + '">' + ffcAudience.strings.cancel + '</button>';
                html += '</div>';
            }

            html += '</div>';
        });

        $container.html(html);

        // Bind cancel handlers
        $container.find('.ffc-cancel-booking').on('click', function() {
            var bookingId = $(this).data('id');
            cancelBooking(bookingId, date);
        });
    }

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
        state.selectedUsers = {};
        updateSelectedUsers();
        $('#ffc-conflict-warning').hide();
        $('#ffc-check-conflicts-btn').show();
        $('#ffc-create-booking-btn').hide();
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

        // Set selected environment
        var selectedEnv = environmentId || state.selectedEnvironment || (allEnvironments.length > 0 ? allEnvironments[0].id : 0);
        $envSelect.val(selectedEnv);

        // Show audience select by default
        $('#booking-type').val('audience').trigger('change');

        $modal.show();
    }

    /**
     * Get environment name by ID
     */
    function getEnvironmentName(id) {
        var schedules = state.config.schedules || [];
        for (var i = 0; i < schedules.length; i++) {
            var envs = schedules[i].environments || [];
            for (var j = 0; j < envs.length; j++) {
                if (envs[j].id === id) {
                    return envs[j].name;
                }
            }
        }
        return '';
    }

    /**
     * Search users
     */
    function searchUsers(query) {
        $.ajax({
            url: ffcAudience.ajaxUrl,
            method: 'GET',
            data: {
                action: 'ffc_search_users',
                query: query,
                nonce: ffcAudience.nonce
            },
            success: function(response) {
                if (response.success && response.data.length > 0) {
                    var html = '';
                    response.data.forEach(function(user) {
                        if (!state.selectedUsers[user.id]) {
                            html += '<div class="ffc-user-result" data-id="' + user.id + '" data-name="' + escapeHtml(user.name) + '">' + escapeHtml(user.name) + ' (' + escapeHtml(user.email) + ')</div>';
                        }
                    });
                    $('#booking-user-results').html(html).addClass('active');
                } else {
                    $('#booking-user-results').removeClass('active').empty();
                }
            }
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

        $.ajax({
            url: ffcAudience.restUrl + 'conflicts',
            method: 'POST',
            contentType: 'application/json',
            timeout: 30000, // 30 second timeout
            data: JSON.stringify({
                environment_id: data.environment_id,
                booking_date: data.booking_date,
                start_time: data.start_time,
                end_time: data.end_time,
                audience_ids: data.audience_ids,
                user_ids: data.user_ids
            }),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', ffcAudience.nonce);
            },
            success: function(response) {
                try {
                    if (response.success) {
                        var conflicts = response.conflicts || {};
                        if (conflicts.bookings && conflicts.bookings.length > 0) {
                            $('#ffc-conflict-warning').show();
                            var details = (conflicts.affected_users ? conflicts.affected_users.length : 0) + ' member(s) have overlapping bookings.';
                            $('#ffc-conflict-details').text(details);
                        } else {
                            $('#ffc-conflict-warning').hide();
                        }

                        // Show create button
                        $btn.hide();
                        $('#ffc-create-booking-btn').show();
                    } else {
                        alert(response.message || ffcAudience.strings.error);
                    }
                } catch (e) {
                    console.error('Error processing conflict response:', e);
                    alert(ffcAudience.strings.error);
                }
            },
            error: function(xhr, status, error) {
                console.error('Conflict check error:', status, error, xhr.responseText);
                var message = ffcAudience.strings.error;
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    message = xhr.responseJSON.message;
                } else if (status === 'timeout') {
                    message = ffcAudience.strings.timeout;
                }
                alert(message);
            },
            complete: function() {
                // Always restore button state
                $btn.prop('disabled', false).text(originalText);
            }
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

        $.ajax({
            url: ffcAudience.restUrl + 'bookings',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(data),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', ffcAudience.nonce);
            },
            success: function(response) {
                if (response.success) {
                    alert(ffcAudience.strings.bookingCreated);
                    closeModals();
                    renderCalendar();
                } else {
                    $('#ffc-create-booking-btn').prop('disabled', false).text(ffcAudience.strings.createBooking);
                    alert(response.message || ffcAudience.strings.error);
                }
            },
            error: function() {
                $('#ffc-create-booking-btn').prop('disabled', false).text(ffcAudience.strings.createBooking);
                alert(ffcAudience.strings.error);
            }
        });
    }

    /**
     * Cancel booking
     */
    function cancelBooking(bookingId, date) {
        var reason = prompt(ffcAudience.strings.cancelReason);
        if (!reason || reason.trim() === '') {
            return;
        }

        $.ajax({
            url: ffcAudience.restUrl + 'bookings/' + bookingId,
            method: 'DELETE',
            contentType: 'application/json',
            data: JSON.stringify({ reason: reason }),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', ffcAudience.nonce);
            },
            success: function(response) {
                if (response.success) {
                    alert(ffcAudience.strings.bookingCancelled);
                    renderCalendar();
                    loadDayBookings(date);
                } else {
                    alert(response.message || ffcAudience.strings.error);
                }
            },
            error: function() {
                alert(ffcAudience.strings.error);
            }
        });
    }

    /**
     * Validate booking form
     */
    function validateBookingForm() {
        var startTime = $('#booking-start-time').val();
        var endTime = $('#booking-end-time').val();
        var description = $('#booking-description').val().trim();
        var bookingType = $('#booking-type').val();

        if (!startTime || !endTime) {
            alert('Please fill in the time fields.');
            return false;
        }

        if (startTime >= endTime) {
            alert(ffcAudience.strings.invalidTime);
            return false;
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
        var data = {
            environment_id: parseInt($('#booking-environment-id').val()),
            booking_date: $('#booking-date').val(),
            start_time: $('#booking-start-time').val(),
            end_time: $('#booking-end-time').val(),
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

    /**
     * Close all modals
     */
    function closeModals() {
        $('.ffc-modal').hide();
    }

    /**
     * Format date as YYYY-MM-DD
     */
    function formatDate(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    /**
     * Parse date string (YYYY-MM-DD) to Date object in local timezone
     * This avoids timezone issues when using new Date(string) which interprets as UTC
     */
    function parseDate(dateStr) {
        var parts = dateStr.split('-');
        return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    }

    /**
     * Format time (HH:MM:SS to HH:MM)
     */
    function formatTime(time) {
        if (!time) return '';
        return time.substring(0, 5);
    }

    /**
     * Pad number with leading zero
     */
    function pad(num) {
        return (num < 10 ? '0' : '') + num;
    }

    /**
     * Escape HTML
     */
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;')
                  .replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;')
                  .replace(/"/g, '&quot;')
                  .replace(/'/g, '&#39;');
    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
