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

            // Clear previous messages
            $messages.html('');

            // Validate
            if (!this.validateForm($form)) {
                return;
            }

            // Disable submit button
            $submitBtn.prop('disabled', true).text(ffcCalendar.strings.loading);

            // Serialize form data
            var formData = $form.serialize();

            // AJAX request
            $.ajax({
                url: ffcCalendar.ajaxurl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        self.showConfirmation(response.data);
                    } else {
                        self.showError(response.data.message || ffcCalendar.strings.error);
                        $submitBtn.prop('disabled', false).text(ffcCalendar.strings.submit || 'Book Appointment');
                    }
                },
                error: function() {
                    self.showError(ffcCalendar.strings.error);
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
            $('.ffc-calendar-datepicker-wrapper').hide();

            // Build appointment details
            var detailsHtml = '<p><strong>Date:</strong> ' + self.selectedDate + '</p>';
            detailsHtml += '<p><strong>Time:</strong> ' + $('#ffc-form-time option:selected').text() + '</p>';
            detailsHtml += '<p><strong>Name:</strong> ' + $('#ffc-booking-name').val() + '</p>';
            detailsHtml += '<p><strong>Email:</strong> ' + $('#ffc-booking-email').val() + '</p>';

            if (data.requires_approval) {
                detailsHtml += '<p><strong>Status:</strong> Pending Approval</p>';
            } else {
                detailsHtml += '<p><strong>Status:</strong> Confirmed</p>';
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
            // Hide all sections except datepicker
            $('.ffc-booking-form-wrapper').hide();
            $('.ffc-timeslots-wrapper').hide();
            $('.ffc-confirmation-wrapper').hide();
            $('.ffc-calendar-datepicker-wrapper').show();

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
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        ffcCalendarFrontend.init();
    });

})(jQuery);
