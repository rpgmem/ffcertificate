/**
 * Calendar Frontend JavaScript
 *
 * Handles calendar booking interface interactions.
 * Uses modal overlay for time slots and booking form.
 *
 * @since 4.1.0
 * @updated 4.6.0 - Modal overlay instead of inline scroll
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
                window.FFC.Frontend.Masks.applyCpfRf($('#ffc-booking-cpf-rf'));

                $(document).on('focus', '#ffc-booking-cpf-rf', function() {
                    window.FFC.Frontend.Masks.applyCpfRf($(this));
                });
            }

            // Time slot selection (click and keyboard)
            $(document).on('click', '.ffc-timeslot:not(.ffc-timeslot-full)', function() {
                self.selectTimeSlot($(this));
            });

            $(document).on('keydown', '.ffc-timeslot:not(.ffc-timeslot-full)', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    self.selectTimeSlot($(this));
                }
            });

            // Form submission
            $(document).on('submit', '#ffc-self-scheduling-form', function(e) {
                e.preventDefault();
                self.submitBooking($(this));
            });

            // Back button (form → time slots within modal)
            $(document).on('click', '.ffc-btn-back', function(e) {
                e.preventDefault();
                self.backToTimeSlots();
            });

            // New booking button
            $(document).on('click', '.ffc-btn-new-booking', function(e) {
                e.preventDefault();
                self.resetCalendar();
            });

            // Modal close handlers
            $(document).on('click', '#ffc-self-scheduling-modal .ffc-modal-close, #ffc-self-scheduling-modal .ffc-modal-backdrop', function() {
                self.closeModal();
            });

            // Prevent clicks inside modal content from closing
            $(document).on('click', '#ffc-self-scheduling-modal .ffc-modal-content', function(e) {
                e.stopPropagation();
            });

            // Close modal on Escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $('#ffc-self-scheduling-modal').is(':visible')) {
                    self.closeModal();
                }
            });

            // Manual receipt PDF download button
            $(document).on('click', '.ffc-download-receipt-btn', function(e) {
                e.preventDefault();
                var pdfData = $(this).data('pdfData');
                if (pdfData && typeof window.ffcGeneratePDF === 'function') {
                    var filename = pdfData.filename || 'appointment_receipt.pdf';
                    window.ffcGeneratePDF(pdfData, filename);
                }
            });
        },

        /**
         * Select a time slot
         */
        selectTimeSlot: function($slot) {
            var time = $slot.data('time');

            $('.ffc-timeslot').removeClass('selected').attr('aria-selected', 'false');
            $slot.addClass('selected').attr('aria-selected', 'true');

            this.selectedTime = time;
            this.showBookingForm();
        },

        /**
         * Open booking modal with focus management
         */
        openModal: function() {
            var $modal = $('#ffc-self-scheduling-modal');
            // Store the element that opened the modal for focus return
            this._triggerElement = document.activeElement;
            $modal.show();
            $('body').css('overflow', 'hidden');

            // Move focus to the modal close button
            $modal.find('.ffc-modal-close').focus();

            // Set up focus trap
            this._trapFocus($modal);
        },

        /**
         * Close booking modal and return focus
         */
        closeModal: function() {
            var $modal = $('#ffc-self-scheduling-modal');
            $modal.hide();
            $('body').css('overflow', '');

            // Reset modal to time slots view
            $('.ffc-booking-form-wrapper').hide();
            $('.ffc-timeslots-wrapper').show();
            $('.ffc-timeslot').removeClass('selected').attr('aria-selected', 'false');
            this.selectedTime = null;

            // Return focus to the trigger element
            if (this._triggerElement) {
                this._triggerElement.focus();
                this._triggerElement = null;
            }
        },

        /**
         * Trap focus within the modal
         */
        _trapFocus: function($modal) {
            $modal.off('keydown.focustrap').on('keydown.focustrap', function(e) {
                if (e.key !== 'Tab') return;

                var focusable = $modal.find('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])').filter(':visible');
                var first = focusable.first()[0];
                var last = focusable.last()[0];

                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            });
        },

        /**
         * Load available time slots for selected date
         */
        loadTimeSlots: function(calendarId, date) {
            var self = this;
            this.calendarId = calendarId;
            this.selectedDate = date;

            var $container = $('#ffc-timeslots-container');
            var $loading = $('.ffc-timeslots-loading');

            // Update modal title with selected date
            var $modal = $('#ffc-self-scheduling-modal');
            $modal.find('.ffc-modal-title').text(
                (ffcCalendar.strings.availableTimes || 'Available Times') + ' — ' + self.formatDate(date)
            );

            // Reset modal state: show time slots, hide form
            $('.ffc-timeslots-wrapper').show();
            $('.ffc-booking-form-wrapper').hide();
            $loading.show();
            $container.html('');

            // Open modal
            self.openModal();

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
         * Format date string (YYYY-MM-DD) to localized display
         */
        formatDate: function(dateStr) {
            var parts = dateStr.split('-');
            if (parts.length !== 3) return dateStr;

            var year = parseInt(parts[0], 10);
            var monthIndex = parseInt(parts[1], 10) - 1;
            var day = parseInt(parts[2], 10);

            var months = ffcCalendar.strings.months || [];
            var monthName = months[monthIndex] || parts[1];

            return day + ' ' + monthName + ' ' + year;
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
                var isFull = slot.available === 0;
                var fullClass = isFull ? ' ffc-timeslot-full' : '';
                var tabIdx = isFull ? '' : ' tabindex="0"';
                var ariaDisabled = isFull ? ' aria-disabled="true"' : '';

                html += '<div class="ffc-timeslot' + fullClass + '" role="option" aria-selected="false"' + tabIdx + ariaDisabled + ' data-time="' + slot.time + '">';
                html += '<span class="ffc-timeslot-time">' + slot.display + '</span>';
                html += '<span class="ffc-timeslot-available">' + availableText + '</span>';
                html += '</div>';
            });

            $container.html(html);
        },

        /**
         * Show booking form (step 2 inside modal)
         */
        showBookingForm: function() {
            // Set hidden fields
            $('#ffc-form-date').val(this.selectedDate);
            $('#ffc-form-time').val(this.selectedTime);

            // Switch modal view: hide time slots, show form
            $('.ffc-timeslots-wrapper').hide();
            $('.ffc-booking-form-wrapper').show();

            // Update modal title
            $('#ffc-self-scheduling-modal .ffc-modal-title').text(
                ffcCalendar.strings.yourInformation || 'Your Information'
            );

            // Apply CPF/RF mask when form becomes visible
            if (window.FFC && window.FFC.Frontend && window.FFC.Frontend.Masks && typeof window.FFC.Frontend.Masks.applyCpfRf === 'function') {
                window.FFC.Frontend.Masks.applyCpfRf($('#ffc-booking-cpf-rf'));
            }

            // Scroll modal body to top
            $('#ffc-self-scheduling-modal .ffc-modal-content').scrollTop(0);

            // Move focus to first non-readonly input in the form
            var $firstInput = $('#ffc-self-scheduling-form input:not([type="hidden"]):not([readonly]):visible').first();
            if ($firstInput.length) {
                $firstInput.focus();
            }
        },

        /**
         * Submit booking
         */
        submitBooking: function($form) {
            var self = this;
            var $messages = $('.ffc-form-messages');
            var $submitBtn = $form.find('button[type="submit"]');

            if ($submitBtn.data('submitting')) {
                return;
            }

            $messages.html('');

            if (!this.validateForm($form)) {
                return;
            }

            $submitBtn.data('submitting', true);
            $submitBtn.prop('disabled', true).text(ffcCalendar.strings.loading);

            var formData = $form.serialize();

            $.ajax({
                url: ffcCalendar.ajaxurl,
                type: 'POST',
                data: formData,
                timeout: 30000,
                success: function(response) {
                    $submitBtn.data('submitting', false);
                    if (response.success) {
                        self.showConfirmation(response.data);
                    } else {
                        if (response.data && response.data.refresh_captcha) {
                            self.refreshCaptcha(response.data.new_label, response.data.new_hash);
                        }

                        self.showError(response.data.message || ffcCalendar.strings.error);
                        $submitBtn.prop('disabled', false).text(ffcCalendar.strings.submit || 'Book Appointment');
                    }
                },
                error: function(xhr, status) {
                    $submitBtn.data('submitting', false);
                    var errorMsg = ffcCalendar.strings.error;

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

            $form.find('[required]').each(function() {
                if (!$(this).val() || $(this).val().trim() === '') {
                    isValid = false;
                }
            });

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
            $messages.html('<div class="ffc-message ffc-message-error">' + message + '</div>');

            // Scroll modal body to bottom to show the message
            var $modalContent = $('#ffc-self-scheduling-modal .ffc-modal-content');
            $modalContent.scrollTop($modalContent[0].scrollHeight);
        },

        /**
         * Show success confirmation
         */
        showConfirmation: function(data) {
            var self = this;

            // Close modal
            $('#ffc-self-scheduling-modal').hide();
            $('body').css('overflow', '');

            // Build appointment details
            var detailsHtml = '<div class="ffc-appointment-info">';
            detailsHtml += '<p><strong>' + ffcCalendar.strings.date + ':</strong> ' + self.formatDate(self.selectedDate) + '</p>';
            detailsHtml += '<p><strong>' + ffcCalendar.strings.time + ':</strong> ' + (self.selectedTime || '') + '</p>';
            detailsHtml += '<p><strong>' + ffcCalendar.strings.name + ':</strong> ' + $('#ffc-booking-name').val() + '</p>';
            detailsHtml += '<p><strong>' + ffcCalendar.strings.email + ':</strong> ' + $('#ffc-booking-email').val() + '</p>';

            if (data.requires_approval) {
                detailsHtml += '<p><strong>' + ffcCalendar.strings.status + ':</strong> ' + ffcCalendar.strings.pendingApproval + '</p>';
            } else {
                detailsHtml += '<p><strong>' + ffcCalendar.strings.status + ':</strong> ' + ffcCalendar.strings.confirmed + '</p>';
            }
            detailsHtml += '</div>';

            // Validation code
            if (data.validation_code) {
                detailsHtml += '<div class="ffc-confirmation-code">';
                detailsHtml += '<p><strong>' + (ffcCalendar.strings.validationCode || 'Validation Code') + ':</strong></p>';
                detailsHtml += '<p class="ffc-code-value">' + data.validation_code + '</p>';
                detailsHtml += '<p class="ffc-code-help">' + ffcCalendar.strings.confirmationCodeHelp + '</p>';
                detailsHtml += '</div>';
            }

            // Receipt actions
            detailsHtml += '<div class="ffc-receipt-actions">';
            if (data.pdf_data) {
                detailsHtml += '<button type="button" class="ffc-btn ffc-btn-primary ffc-download-receipt-btn">';
                detailsHtml += ffcCalendar.strings.downloadReceipt;
                detailsHtml += '</button>';
            }
            if (data.receipt_url) {
                detailsHtml += ' <a href="' + data.receipt_url + '" class="ffc-btn ffc-btn-secondary" target="_blank">';
                detailsHtml += ffcCalendar.strings.downloadReceipt;
                detailsHtml += '</a>';
            }
            detailsHtml += '</div>';

            $('.ffc-appointment-details').html(detailsHtml);

            // Attach pdf_data to download button
            if (data.pdf_data) {
                $('.ffc-download-receipt-btn').data('pdfData', data.pdf_data);
            }

            // Hide calendar, show confirmation
            $('.ffc-calendar-container').hide();
            $('.ffc-confirmation-wrapper').show();

            // Scroll to confirmation and move focus
            $('html, body').animate({
                scrollTop: $('.ffc-confirmation-wrapper').offset().top - 100
            }, 500, function() {
                $('.ffc-confirmation-success h3').attr('tabindex', '-1').focus();
            });

            // Auto-download PDF receipt if available
            if (data.pdf_data && typeof window.ffcGeneratePDF === 'function') {
                setTimeout(function() {
                    var filename = data.pdf_data.filename || 'appointment_receipt.pdf';
                    window.ffcGeneratePDF(data.pdf_data, filename);
                }, 500);
            }
        },

        /**
         * Refresh captcha with new question
         */
        refreshCaptcha: function(newLabel, newHash) {
            if (!newLabel || !newHash) {
                return;
            }

            $('.ffc-captcha-row label').html(newLabel);
            $('#ffc_captcha_hash').val(newHash);
            $('#ffc_captcha_ans').val('').focus();
        },

        /**
         * Back to time slots (within modal)
         */
        backToTimeSlots: function() {
            var self = this;

            // Switch modal view: hide form, show time slots
            $('.ffc-booking-form-wrapper').hide();
            $('.ffc-timeslots-wrapper').show();
            $('.ffc-timeslot').removeClass('selected');
            this.selectedTime = null;

            // Restore modal title
            $('#ffc-self-scheduling-modal .ffc-modal-title').text(
                (ffcCalendar.strings.availableTimes || 'Available Times') + ' — ' + self.formatDate(self.selectedDate)
            );

            // Scroll modal to top and focus first available slot
            $('#ffc-self-scheduling-modal .ffc-modal-content').scrollTop(0);
            var $firstSlot = $('.ffc-timeslot:not(.ffc-timeslot-full)').first();
            if ($firstSlot.length) {
                $firstSlot.focus();
            }
        },

        /**
         * Reset calendar to initial state
         */
        resetCalendar: function() {
            // Hide confirmation, show calendar
            $('.ffc-confirmation-wrapper').hide();
            $('.ffc-calendar-container').show();

            // Reset form
            $('#ffc-self-scheduling-form')[0].reset();
            $('.ffc-form-messages').html('');
            $('.ffc-timeslot').removeClass('selected');

            // Reset modal state
            $('.ffc-booking-form-wrapper').hide();
            $('.ffc-timeslots-wrapper').show();

            // Reset submit button
            var $submitBtn = $('#ffc-self-scheduling-form button[type="submit"]');
            $submitBtn.data('submitting', false);
            $submitBtn.prop('disabled', false).text(ffcCalendar.strings.submit || 'Book Appointment');

            // Reset selections
            this.selectedDate = null;
            this.selectedTime = null;

            // Scroll to calendar
            $('html, body').animate({
                scrollTop: $('.ffc-audience-calendar').offset().top - 50
            }, 300);
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
        var fetchId = 0;

        // Function to fetch booking counts and holidays for a month
        function fetchBookingCounts(year, month, callback) {
            var thisId = ++fetchId;

            // Clear stale data immediately so old badges disappear
            bookingCounts = {};

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
                    // Ignore stale responses — a newer fetch was already dispatched
                    if (thisId !== fetchId) return;

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
                    if (callback) callback();
                },
                error: function() {
                    if (thisId !== fetchId) return;
                    if (callback) callback();
                }
            });
        }

        // Initialize shared calendar component
        var $container = $('#ffc-calendar-container-' + calendarId);
        var calendarTitle = config.calendarTitle || '';
        var calendar = new FFCCalendarCore($container, {
            showLegend: true,
            showTodayButton: true,
            showFilters: !!calendarTitle,
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

                if (isHoliday) {
                    return classes;
                }

                var isAfterMin = date >= minDate;
                var isBeforeMax = date <= maxDate;
                var isWorkingDay = workingDays.indexOf(weekday) !== -1;

                if (isWorkingDay && isAfterMin && isBeforeMax) {
                    classes.push('ffc-available');
                }

                return classes;
            },
            getDayContent: function(dateStr, date, isHoliday) {
                if (isHoliday) {
                    return '<span class="ffc-day-badge ffc-badge-holiday">' + (ffcCalendar.strings.holiday || 'Holiday') + '</span>';
                }

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

        // Populate filters area with calendar title
        if (calendarTitle) {
            $container.find('.ffc-calendar-filters').html(
                '<span class="ffc-calendar-title">' + $('<span>').text(calendarTitle).html() + '</span>'
            );
        }

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
