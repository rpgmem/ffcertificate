/**
 * Calendar Frontend JavaScript
 *
 * Handles calendar booking interface interactions
 *
 * @since 4.1.0
 */

(function($) {
    'use strict';

    window.ffcCalendarFrontend = {

        selectedDate: null,
        selectedTime: null,
        calendarId: null,

        /**
         * Initialize calendar
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Apply CPF/RF mask if the helper function is available
            if (window.FFC && window.FFC.Frontend && window.FFC.Frontend.Masks && typeof window.FFC.Frontend.Masks.applyCpfRf === 'function') {
                // Apply mask on page load to any visible fields
                window.FFC.Frontend.Masks.applyCpfRf($('#ffc-booking-cpf-rf'));

                // Also apply on focus as a safeguard
                $(document).on('focus', '#ffc-booking-cpf-rf', function() {
                    window.FFC.Frontend.Masks.applyCpfRf($(this));
                });
            }

            // Time slot selection
            $(document).on('click', '.ffc-timeslot:not(.ffc-timeslot-full)', function() {
                var $slot = $(this);
                var time = $slot.data('time');

                // Deselect other slots
                $('.ffc-timeslot').removeClass('selected');
                $slot.addClass('selected');

                // Store selected time
                self.selectedTime = time;

                // Show booking form
                self.showBookingForm();
            });

            // Form submission
            $(document).on('submit', '#ffc-booking-form', function(e) {
                e.preventDefault();
                self.submitBooking($(this));
            });

            // Back button
            $(document).on('click', '.ffc-btn-back', function(e) {
                e.preventDefault();
                self.backToDateSelection();
            });

            // New booking button
            $(document).on('click', '.ffc-btn-new-booking', function(e) {
                e.preventDefault();
                self.resetCalendar();
            });
        },

        /**
         * Load available time slots for selected date
         */
        loadTimeSlots: function(calendarId, date) {
            var self = this;
            this.calendarId = calendarId;
            this.selectedDate = date;

            var $wrapper = $('.ffc-timeslots-wrapper');
            var $container = $('#ffc-timeslots-container');
            var $loading = $('.ffc-timeslots-loading');

            // Show wrapper and loading
            $wrapper.show();
            $loading.show();
            $container.html('');

            // Hide booking form
            $('.ffc-booking-form-wrapper').hide();

            // AJAX request
            $.ajax({
                url: ffcCalendar.ajaxurl,
                type: 'POST',
                data: {
                    action: 'ffc_get_available_slots',
                    nonce: ffcCalendar.nonce,
                    calendar_id: calendarId,
                    date: date
                },
                success: function(response) {
                    $loading.hide();

                    if (response.success && response.data.slots) {
                        self.renderTimeSlots(response.data.slots);
                    } else {
                        $container.html('<p class="ffc-no-slots">' + ffcCalendar.strings.noSlots + '</p>');
                    }
                },
                error: function() {
                    $loading.hide();
                    $container.html('<p class="ffc-message ffc-message-error">' + ffcCalendar.strings.error + '</p>');
                }
            });
        },

        /**
         * Render time slots
         */
        renderTimeSlots: function(slots) {
            var $container = $('#ffc-timeslots-container');
            var html = '';

            if (slots.length === 0) {
                $container.html('<p class="ffc-no-slots">' + ffcCalendar.strings.noSlots + '</p>');
                return;
            }

            $.each(slots, function(index, slot) {
                var availableText = slot.available + ' / ' + slot.total;
                var fullClass = slot.available === 0 ? ' ffc-timeslot-full' : '';

                html += '<div class="ffc-timeslot' + fullClass + '" data-time="' + slot.time + '">';
                html += '<span class="ffc-timeslot-time">' + slot.display + '</span>';
                html += '<span class="ffc-timeslot-available">' + availableText + '</span>';
                html += '</div>';
            });

            $container.html(html);
        },

        /**
         * Show booking form
         */
        showBookingForm: function() {
            var $wrapper = $('.ffc-booking-form-wrapper');

            // Set hidden fields
            $('#ffc-form-date').val(this.selectedDate);
            $('#ffc-form-time').val(this.selectedTime);

            // Show form
            $wrapper.show();

            // Apply CPF/RF mask when form becomes visible
            if (window.FFC && window.FFC.Frontend && window.FFC.Frontend.Masks && typeof window.FFC.Frontend.Masks.applyCpfRf === 'function') {
                window.FFC.Frontend.Masks.applyCpfRf($('#ffc-booking-cpf-rf'));
            }

            // Scroll to form
            $('html, body').animate({
                scrollTop: $wrapper.offset().top - 100
            }, 500);
        },

        /**
         * Submit booking
         */
        submitBooking: function($form) {
            var self = this;
            var $messages = $('.ffc-form-messages');
            var $submitBtn = $form.find('button[type="submit"]');

            // Prevent double submission
            if ($submitBtn.data('submitting')) {
                return;
            }

            // Clear previous messages
            $messages.html('');

            // Validate
            if (!this.validateForm($form)) {
                return;
            }

            // Mark as submitting and disable submit button
            $submitBtn.data('submitting', true);
            $submitBtn.prop('disabled', true).text(ffcCalendar.strings.loading);

            // Serialize form data
            var formData = $form.serialize();

            // AJAX request with increased timeout
            $.ajax({
                url: ffcCalendar.ajaxurl,
                type: 'POST',
                data: formData,
                timeout: 30000, // 30 seconds timeout
                success: function(response) {
                    $submitBtn.data('submitting', false);
                    if (response.success) {
                        self.showConfirmation(response.data);
                    } else {
                        // Refresh captcha if security validation failed
                        if (response.data && response.data.refresh_captcha) {
                            self.refreshCaptcha(response.data.new_label, response.data.new_hash);
                        }

                        self.showError(response.data.message || ffcCalendar.strings.error);
                        $submitBtn.prop('disabled', false).text(ffcCalendar.strings.submit || 'Book Appointment');
                    }
                },
                error: function(xhr, status, error) {
                    $submitBtn.data('submitting', false);
                    var errorMsg = ffcCalendar.strings.error;

                    // Provide more specific error messages
                    if (status === 'timeout') {
                        errorMsg = ffcCalendar.strings.timeout || 'Connection timeout. Please try again.';
                    } else if (xhr.status === 0) {
                        errorMsg = ffcCalendar.strings.networkError || 'Network error. Please check your connection and try again.';
                    }

                    self.showError(errorMsg);
                    $submitBtn.prop('disabled', false).text(ffcCalendar.strings.submit || 'Book Appointment');
                }
            });
        },

        /**
         * Validate form
         */
        validateForm: function($form) {
            var isValid = true;
            var $messages = $('.ffc-form-messages');

            // Check required fields
            $form.find('[required]').each(function() {
                if (!$(this).val() || $(this).val().trim() === '') {
                    isValid = false;
                }
            });

            // Check consent
            if (!$('#ffc-booking-consent').is(':checked')) {
                this.showError(ffcCalendar.strings.consentRequired);
                return false;
            }

            if (!isValid) {
                this.showError(ffcCalendar.strings.fillRequired);
                return false;
            }

            return true;
        },

        /**
         * Show error message
         */
        showError: function(message) {
            var $messages = $('.ffc-form-messages');
            var html = '<div class="ffc-message ffc-message-error">' + message + '</div>';
            $messages.html(html);

            // Scroll to message
            $('html, body').animate({
                scrollTop: $messages.offset().top - 100
            }, 300);
        },

        /**
         * Show success confirmation
         */
        showConfirmation: function(data) {
            var self = this;

            // Hide form
            $('.ffc-booking-form-wrapper').hide();
            $('.ffc-timeslots-wrapper').hide();
            $('.ffc-calendar-container').hide();

            // Build appointment details
            var detailsHtml = '<div class="ffc-appointment-info">';
            detailsHtml += '<p><strong>' + ffcCalendar.strings.date + ':</strong> ' + self.selectedDate + '</p>';
            detailsHtml += '<p><strong>' + ffcCalendar.strings.time + ':</strong> ' + $('#ffc-form-time option:selected').text() + '</p>';
            detailsHtml += '<p><strong>' + ffcCalendar.strings.name + ':</strong> ' + $('#ffc-booking-name').val() + '</p>';
            detailsHtml += '<p><strong>' + ffcCalendar.strings.email + ':</strong> ' + $('#ffc-booking-email').val() + '</p>';

            if (data.requires_approval) {
                detailsHtml += '<p><strong>' + ffcCalendar.strings.status + ':</strong> ' + ffcCalendar.strings.pendingApproval + '</p>';
            } else {
                detailsHtml += '<p><strong>' + ffcCalendar.strings.status + ':</strong> ' + ffcCalendar.strings.confirmed + '</p>';
            }
            detailsHtml += '</div>';

            // Add confirmation code/token if available
            if (data.confirmation_token) {
                detailsHtml += '<div class="ffc-confirmation-code">';
                detailsHtml += '<p><strong>' + ffcCalendar.strings.confirmationCode + ':</strong></p>';
                detailsHtml += '<p class="ffc-code-value">' + data.confirmation_token + '</p>';
                detailsHtml += '<p class="ffc-code-help">' + ffcCalendar.strings.confirmationCodeHelp + '</p>';
                detailsHtml += '</div>';
            }

            // Add receipt download button if available
            if (data.receipt_url) {
                detailsHtml += '<div class="ffc-receipt-actions">';
                detailsHtml += '<a href="' + data.receipt_url + '" class="ffc-btn ffc-btn-secondary" target="_blank">';
                detailsHtml += '📄 ' + ffcCalendar.strings.downloadReceipt;
                detailsHtml += '</a>';
                detailsHtml += '</div>';
            }

            $('.ffc-appointment-details').html(detailsHtml);

            // Show confirmation
            $('.ffc-confirmation-wrapper').show();

            // Scroll to top
            $('html, body').animate({
                scrollTop: $('.ffc-confirmation-wrapper').offset().top - 100
            }, 500);
        },

        /**
         * Refresh captcha with new question
         */
        refreshCaptcha: function(newLabel, newHash) {
            if (!newLabel || !newHash) {
                return;
            }

            // Update captcha label
            $('.ffc-captcha-row label').html(newLabel);

            // Update captcha hash
            $('#ffc_captcha_hash').val(newHash);

            // Clear captcha answer input
            $('#ffc_captcha_ans').val('').focus();
        },

        /**
         * Back to date selection
         */
        backToDateSelection: function() {
            $('.ffc-booking-form-wrapper').hide();
            $('.ffc-timeslots-wrapper').show();
            $('.ffc-timeslot').removeClass('selected');
            this.selectedTime = null;

            // Scroll to time slots
            $('html, body').animate({
                scrollTop: $('.ffc-timeslots-wrapper').offset().top - 100
            }, 300);
        },

        /**
         * Reset calendar to initial state
         */
        resetCalendar: function() {
            // Hide all sections except calendar
            $('.ffc-booking-form-wrapper').hide();
            $('.ffc-timeslots-wrapper').hide();
            $('.ffc-confirmation-wrapper').hide();
            $('.ffc-calendar-container').show();

            // Reset form
            $('#ffc-booking-form')[0].reset();
            $('.ffc-timeslot').removeClass('selected');

            // Reset selections
            this.selectedDate = null;
            this.selectedTime = null;

            // Scroll to top
            $('html, body').animate({
                scrollTop: $('.ffc-calendar-wrapper').offset().top - 100
            }, 500);
        },

        /**
         * Initialize calendar core component using config from PHP
         */
        initCalendarCore: function() {
        if (typeof ffcCalendar === 'undefined' || typeof FFCCalendarCore === 'undefined') {
            return;
        }

        // Read config from JSON script tag embedded by shortcode
        var self = this;
        var configEl = $('script[id^="ffc-calendar-config-"]').first();
        if (!configEl.length) {
            return;
        }

        var config;
        try {
            config = JSON.parse(configEl.text());
        } catch (e) {
            return;
        }

        var calendarId = config.calendarId;
        var workingDays = config.workingDays || [];
        var minDateHours = config.minDateHours || 0;
        var maxDateDays = config.maxDateDays || 30;

        // Calculate disabled days (weekdays not in workingDays)
        var disabledDays = [];
        for (var i = 0; i < 7; i++) {
            if (workingDays.indexOf(i) === -1) {
                disabledDays.push(i);
            }
        }

        // Calculate min/max dates
        var minDate = new Date();
        if (minDateHours > 0) {
            minDate.setHours(minDate.getHours() + minDateHours);
        }
        minDate.setHours(0, 0, 0, 0);

        var maxDate = new Date();
        maxDate.setDate(maxDate.getDate() + maxDateDays);
        maxDate.setHours(23, 59, 59, 999);

        // Store booking counts, holidays and tracking
        var bookingCounts = {};
        var calendarHolidays = {};
        var lastFetchedMonth = null;
        var isFetching = false;

        // Function to fetch booking counts and holidays for a month
        function fetchBookingCounts(year, month, callback) {
            var monthKey = year + '-' + month;

            if (isFetching) {
                return;
            }
            if (lastFetchedMonth === monthKey) {
                return;
            }

            isFetching = true;

            $.ajax({
                url: ffcCalendar.ajaxurl,
                type: 'POST',
                data: {
                    action: 'ffc_get_month_bookings',
                    nonce: ffcCalendar.nonce,
                    calendar_id: calendarId,
                    year: year,
                    month: month
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.counts) {
                            bookingCounts = response.data.counts;
                        }
                        if (response.data.holidays) {
                            calendarHolidays = response.data.holidays;
                            if (calendar) {
                                calendar.options.holidays = calendarHolidays;
                            }
                        }
                    }
                    lastFetchedMonth = monthKey;
                    isFetching = false;
                    if (callback) callback();
                },
                error: function() {
                    isFetching = false;
                    if (callback) callback();
                }
            });
        }

        // Initialize shared calendar component
        var $container = $('#ffc-calendar-container-' + calendarId);
        var calendar = new FFCCalendarCore($container, {
            showLegend: true,
            showTodayButton: true,
            minDate: minDate,
            maxDate: maxDate,
            disabledDays: disabledDays,
            strings: ffcCalendar.strings,
            legendItems: [
                { 'class': 'ffc-available', label: ffcCalendar.strings.available || 'Available' },
                { 'class': 'ffc-booked', label: ffcCalendar.strings.booked || 'Booked' },
                { 'class': 'ffc-holiday', label: ffcCalendar.strings.holiday || 'Holiday' },
                { 'class': 'ffc-closed', label: ffcCalendar.strings.closed || 'Closed' }
            ],
            getDayClasses: function(dateStr, date) {
                var classes = [];
                var weekday = date.getDay();
                var isHoliday = calendarHolidays[dateStr];

                // Holiday takes priority
                if (isHoliday) {
                    return classes;
                }

                // Check if date is within booking window
                var isAfterMin = date >= minDate;
                var isBeforeMax = date <= maxDate;
                var isWorkingDay = workingDays.indexOf(weekday) !== -1;

                // Mark working days as available (only if within booking window)
                if (isWorkingDay && isAfterMin && isBeforeMax) {
                    classes.push('ffc-available');
                }

                return classes;
            },
            getDayContent: function(dateStr, date, isHoliday) {
                // Holiday badge
                if (isHoliday) {
                    var holidayLabel = typeof isHoliday === 'string' ? isHoliday : (ffcCalendar.strings.holiday || 'Holiday');
                    return '<span class="ffc-day-badge ffc-badge-holiday">' + holidayLabel + '</span>';
                }

                // Booking count badge
                var count = bookingCounts[dateStr] ? bookingCounts[dateStr] : 0;
                if (count > 0) {
                    var singularLabel = ffcCalendar.strings.booking || 'booking';
                    var pluralLabel = ffcCalendar.strings.bookings || 'bookings';
                    var label = count === 1 ? singularLabel : pluralLabel;
                    return '<span class="ffc-day-badge ffc-badge-bookings">' + count + ' ' + label + '</span>';
                }
                return '';
            },
            onMonthChange: function(year, month) {
                fetchBookingCounts(year, month, function() {
                    calendar.refresh();
                });
            },
            onDayClick: function(dateStr, $day) {
                $('#ffc-selected-date').val(dateStr);
                self.loadTimeSlots(calendarId, dateStr);
            }
        });

        // Store calendar instance for later access
        $container.data('calendar', calendar);
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        ffcCalendarFrontend.init();
        ffcCalendarFrontend.initCalendarCore();
    });

})(jQuery);
