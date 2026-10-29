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

    function esc(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

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
                var $btn = $(this);
                var pdfData = $btn.data('pdfData');
                if (pdfData && typeof window.ffcGeneratePDF === 'function') {
                    self.downloadReceipt($btn.closest('.ffc-receipt-actions'), pdfData, $btn.data('appointmentId'));
                }
            });
        },

        /**
         * Select a time slot
         */
        selectTimeSlot: function($slot) {
            var time = $slot.data('time');

            $('.ffc-timeslot').removeClass('is-selected').attr('aria-selected', 'false');
            $slot.addClass('is-selected').attr('aria-selected', 'true');

            this.selectedTime = time;
            // Whether the chosen slot is a waitlist join (full slot, queue open).
            this.selectedWaitlist = !!$slot.data('waitlist');
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
            $('.ffc-timeslot').removeClass('is-selected').attr('aria-selected', 'false');
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

            FFC.request('ffc_get_available_slots', { calendar_id: calendarId, date: date }, { nonce: ffcCalendar.nonce, ajaxUrl: ffcCalendar.ajaxurl })
                .then(function (data) {
                    $loading.hide();
                    if (data && data.slots) {
                        self.renderTimeSlots(data.slots);
                    } else {
                        $container.html('').append($('<p class="ffc-no-slots">').text(ffcCalendar.strings.noSlots));
                    }
                })
                .catch(function () {
                    $loading.hide();
                    $container.html('').append(FFC.notice('danger', 'error', ffcCalendar.strings.error, '', { role: 'alert' }));
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
                $container.html('').append($('<p class="ffc-no-slots">').text(ffcCalendar.strings.noSlots));
                return;
            }

            $.each(slots, function(index, slot) {
                var isFull = slot.available === 0;
                // A full slot with a waitlist stays selectable (joins the queue);
                // a full slot without one is disabled (#941 phase 2).
                var canWaitlist = isFull && !!slot.waitlist_available;
                var selectable = !isFull || canWaitlist;

                var availableText = canWaitlist
                    ? (ffcCalendar.strings.joinWaitlist || 'Join waitlist')
                    : (slot.available + ' / ' + slot.total);

                var stateClass = '';
                if (isFull) {
                    stateClass = canWaitlist ? ' ffc-timeslot-waitlist' : ' ffc-timeslot-full';
                }
                var tabIdx = selectable ? ' tabindex="0"' : '';
                var ariaDisabled = selectable ? '' : ' aria-disabled="true"';
                var waitAttr = canWaitlist ? ' data-waitlist="1"' : '';

                html += '<div class="ffc-timeslot' + stateClass + '" role="option" aria-selected="false"' + tabIdx + ariaDisabled + waitAttr + ' data-time="' + slot.time + '">';
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

            // Waitlist notice (#941 phase 2): this slot is full, so the booking
            // joins the queue. Show/hide a banner atop the form accordingly.
            var $formWrapper = $('.ffc-booking-form-wrapper');
            var $notice = $formWrapper.find('[data-ffc-notice="waitlist"]');
            if (this.selectedWaitlist) {
                if (!$notice.length) {
                    $notice = $(FFC.notice(
                        'neutral',
                        'users',
                        ffcCalendar.strings.waitlistNotice ||
                        'This time is full. Submitting adds you to the waitlist — you will be notified automatically if a spot opens up.',
                        '',
                        { role: 'status', hook: 'waitlist' }
                    ));
                    $formWrapper.prepend($notice);
                }
                $notice.show();
            } else if ($notice.length) {
                $notice.hide();
            }

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
         *
         * A bypass holder is first told which rules the booking breaks
         * (#1859): the server lists them, one warning shows them all with a
         * countdown, and only an "I agree" submits — with the confirmation
         * the server requires. Asking first keeps the captcha for the real
         * submission.
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

            if (!(self.config && self.config.canBypass)) {
                self.sendBooking($form, false);
                return;
            }

            FFC.request('ffc_check_booking_override', {
                calendar_id: $form.find('[name="calendar_id"]').val(),
                date: $form.find('[name="date"]').val(),
                time: $form.find('[name="time"]').val()
            }, { nonce: ffcCalendar.nonce, ajaxUrl: ffcCalendar.ajaxurl })
                .then(function(data) {
                    var reasons = (data && data.reasons) || [];
                    if (!reasons.length) {
                        self.sendBooking($form, false);
                        return;
                    }
                    self.confirmOverride($form, reasons);
                })
                .catch(function() {
                    // The server decides on the submission either way.
                    self.sendBooking($form, false);
                });
        },

        /**
         * Open the override warning; on "I agree", submit with the
         * confirmation (#1859).
         */
        confirmOverride: function($form, reasons) {
            var self = this;
            var $submitBtn = $form.find('button[type="submit"]');
            FFC.overrideConfirm({ reasons: reasons, strings: (ffcCalendar.strings || {}).override })
                .then(function(agreed) {
                    if (agreed) {
                        self.sendBooking($form, true);
                        return;
                    }
                    $submitBtn.data('submitting', false);
                    $submitBtn.prop('disabled', false).text(ffcCalendar.strings.submit || 'Book Appointment');
                    $submitBtn.trigger('focus');
                });
        },

        /**
         * Send the booking.
         *
         * @param {jQuery}  $form     The booking form.
         * @param {boolean} confirmed Carry the override confirmation (#1859).
         */
        sendBooking: function($form, confirmed) {
            var self = this;
            var $submitBtn = $form.find('button[type="submit"]');
            var formData = $form.serialize();
            if (confirmed) {
                formData += '&confirm_override=1';
            }
            // The form carries `action` and `nonce` as hidden fields. Pull
            // `action` out to pass explicitly to FFC.request (the helper
            // re-appends it), and drop the form's baked-in `nonce` too:
            // FFC.request unconditionally appends `&nonce=<resolved>` to a
            // string payload, and with no explicit nonce that resolved value
            // is the frontend nonce (or empty). As the LAST `nonce=` param it
            // overrode the self-scheduling nonce the form field carried, so
            // check_ajax_referer() rejected every booking with a 403. Pass the
            // live `ffcCalendar.nonce` instead (refreshed by
            // ffc-dynamic-fragments on cached pages), matching the slot/month
            // read calls.
            var actionMatch = formData.match(/(?:^|&)action=([^&]*)/);
            var action = actionMatch ? decodeURIComponent(actionMatch[1]) : '';
            formData = formData
                .replace(/(^|&)action=[^&]*/, '')
                .replace(/(^|&)nonce=[^&]*/, '');

            FFC.request(action, formData, { nonce: ffcCalendar.nonce, ajaxUrl: ffcCalendar.ajaxurl, timeout: 30000 })
                .then(function (data) {
                    $submitBtn.data('submitting', false);
                    self.showConfirmation(data);
                })
                .catch(function (err) {
                    $submitBtn.data('submitting', false);
                    if (err && err.fromServer && err.data && err.data.code === 'override_confirmation_required' && !confirmed) {
                        // The rules changed between the check and the submit:
                        // show what the server listed, then ask again.
                        if (err.data.refresh_captcha) {
                            FFC.Frontend.UI.refreshCaptcha($form, err.data);
                        }
                        $submitBtn.data('submitting', true);
                        self.confirmOverride($form, err.data.reasons || []);
                        return;
                    }
                    if (err && err.fromServer) {
                        if (err.data && err.data.refresh_captcha) {
                            // The shared implementation, not a private copy:
                            // this bundle already declares `ffc-frontend-helpers`
                            // as a dependency, so the duplicate it used to carry
                            // was the reason the ALTCHA fix had to be written
                            // twice (#1305).
                            FFC.Frontend.UI.refreshCaptcha($form, err.data);
                        }
                        self.showError(err.message || ffcCalendar.strings.error);
                    } else {
                        var errorMsg = ffcCalendar.strings.error;
                        var xhr = err && err.xhr;
                        if (xhr && xhr.statusText === 'timeout') {
                            errorMsg = ffcCalendar.strings.timeout || 'Connection timeout. Please try again.';
                        } else if (xhr && xhr.status === 0) {
                            errorMsg = ffcCalendar.strings.networkError || 'Network error. Please check your connection and try again.';
                        }
                        self.showError(errorMsg);
                    }
                    $submitBtn.prop('disabled', false).text(ffcCalendar.strings.submit || 'Book Appointment');
                });
        },

        /**
         * Validate form
         */
        validateForm: function($form) {
            // Each empty required field says so under itself, in the
            // field-message standard (#1844); the form-level message still
            // sums it up next to the submit button.
            var firstInvalid = null;

            $form.find('[required], #ffc-booking-consent').each(function() {
                var empty = this.type === 'checkbox' ? !this.checked : !$(this).val() || $(this).val().trim() === '';
                if (empty) {
                    var message = this.id === 'ffc-booking-consent'
                        ? ffcCalendar.strings.consentRequired
                        : FFC.requiredMessage();
                    FFC.fieldMessage(this, 'error', message);
                    $(this).data('ffcEmptyRequired', true);
                    firstInvalid = firstInvalid || this;
                } else if ($(this).data('ffcEmptyRequired')) {
                    // Only the message this check put there is withdrawn,
                    // so the CPF/RF mask's verdict is left standing.
                    FFC.clearFieldMessage(this);
                    $(this).removeData('ffcEmptyRequired');
                }
            });

            if (!firstInvalid) {
                return true;
            }

            var consentOnly = firstInvalid.id === 'ffc-booking-consent';
            this.showError(consentOnly ? ffcCalendar.strings.consentRequired : ffcCalendar.strings.fillRequired);
            $(firstInvalid).trigger('focus');
            return false;
        },

        /**
         * Show error message
         */
        showError: function(message) {
            var $messages = $('.ffc-form-messages');
            $messages.html('').append(FFC.notice('danger', 'error', message, '', { role: 'note' }));

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
            detailsHtml += '<p><strong>' + esc(ffcCalendar.strings.name) + ':</strong> ' + esc($('#ffc-booking-name').val()) + '</p>';
            detailsHtml += '<p><strong>' + esc(ffcCalendar.strings.email) + ':</strong> ' + esc($('#ffc-booking-email').val()) + '</p>';

            if (data.waitlisted) {
                // Full slot → queued (#941 phase 2).
                detailsHtml += '<p><strong>' + ffcCalendar.strings.status + ':</strong> ' + (ffcCalendar.strings.waitlistStatus || 'On waitlist') + '</p>';
            } else if (data.requires_approval) {
                detailsHtml += '<p><strong>' + ffcCalendar.strings.status + ':</strong> ' + ffcCalendar.strings.pendingApproval + '</p>';
            } else {
                detailsHtml += '<p><strong>' + ffcCalendar.strings.status + ':</strong> ' + ffcCalendar.strings.confirmed + '</p>';
            }
            detailsHtml += '</div>';

            // Validation code
            if (data.validation_code) {
                detailsHtml += '<div class="ffc-confirmation-code">';
                detailsHtml += '<p><strong>' + (ffcCalendar.strings.validationCode || 'Validation Code') + ':</strong></p>';
                detailsHtml += '<p class="ffc-code-value">' + esc(data.validation_code) + '</p>';
                detailsHtml += '<p class="ffc-code-help">' + ffcCalendar.strings.confirmationCodeHelp + '</p>';
                detailsHtml += '</div>';
            }

            // Receipt actions (show only one button: pdf_data takes priority over receipt_url)
            detailsHtml += '<div class="ffc-receipt-actions">';
            // Either control is the same action, so both are the primary button.
            if (data.pdf_data) {
                detailsHtml += FFC.buttonHtml({
                    label: ffcCalendar.strings.downloadReceipt,
                    category: 'primary',
                    icon: 'download',
                    className: 'ffc-download-receipt-btn'
                });
            } else if (data.receipt_url) {
                detailsHtml += FFC.buttonHtml({
                    label: ffcCalendar.strings.downloadReceipt,
                    category: 'primary',
                    icon: 'download',
                    href: data.receipt_url,
                    attrs: { target: '_blank', rel: 'noopener noreferrer' }
                });
            }
            detailsHtml += '</div>';

            $('.ffc-appointment-details').html(detailsHtml);

            // Attach pdf_data to download button
            if (data.pdf_data) {
                $('.ffc-download-receipt-btn').data('pdfData', data.pdf_data).data('appointmentId', data.appointment_id);
            }

            // Show email confirmation notice only if email was actually sent
            if (data.email_sent) {
                $('.ffc-confirmation-email').show();
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
                    self.downloadReceipt($('.ffc-receipt-actions'), data.pdf_data, data.appointment_id);
                }, 500);
            }
        },

        /**
         * Download the booking's receipt PDF, after the visitor pre-flight's
         * browser step (#2040): an app's own browser often cannot save the
         * file, so it is asked to switch, with "Continue anyway", and the
         * button waits behind the notice.
         *
         * @param {jQuery} $actions     The receipt actions (the notice goes here).
         * @param {object} pdfData      The receipt's PDF data.
         * @param {number} appointmentId Appointment, for the log.
         */
        downloadReceipt: function($actions, pdfData, appointmentId) {
            var go = function() {
                window.ffcGeneratePDF(pdfData, pdfData.filename || 'appointment_receipt.pdf');
            };
            var preflight = window.FFC && window.FFC.Preflight;
            if (!preflight || typeof preflight.beforeDownload !== 'function') {
                go();
                return;
            }
            var cfg = (window.ffcCalendar && window.ffcCalendar.preflight) || {};
            preflight.beforeDownload({
                wrapper: $actions,
                trigger: '.ffc-download-receipt-btn',
                telemetry: { type: 'receipt', id: appointmentId },
                browserCheck: cfg.browserCheck,
                strings: cfg.strings,
                go: go
            });
        },


        /**
         * Back to time slots (within modal)
         */
        backToTimeSlots: function() {
            var self = this;

            // Switch modal view: hide form, show time slots
            $('.ffc-booking-form-wrapper').hide();
            $('.ffc-timeslots-wrapper').show();
            $('.ffc-timeslot').removeClass('is-selected');
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
            $('.ffc-timeslot').removeClass('is-selected');

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

        // Kept for the booking submit, which reads `canBypass` (#1859).
        self.config = config;

        var calendarId = config.calendarId;
        var workingDays = config.workingDays || [];
        var minDateHours = config.minDateHours || 0;
        var maxDateDays = config.maxDateDays || 30;
        var scheduleType = config.scheduleType || 'regular';
        var enabledDates = config.enabledDates || [];

        // Calculate disabled days (weekdays not in workingDays). In custom mode
        // (#941) the weekly pattern doesn't apply — the enabledDates allowlist
        // drives which days are bookable — so no weekday is disabled.
        var disabledDays = [];
        if (scheduleType !== 'custom') {
            for (var i = 0; i < 7; i++) {
                if (workingDays.indexOf(i) === -1) {
                    disabledDays.push(i);
                }
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

        // Custom mode (#941): the bookable dates are explicit, so extend the
        // navigable range to the last configured date rather than capping it
        // at advance_booking_max (which does not apply in custom mode).
        if (scheduleType === 'custom' && enabledDates.length) {
            var lastDate = new Date(enabledDates[enabledDates.length - 1] + 'T23:59:59');
            if (!isNaN(lastDate.getTime())) {
                maxDate = lastDate;
            }
        }

        // A working day inside the booking window, and not a holiday: what
        // the cell marks as available and may name (#2221).
        function isAvailable(dateStr, date) {
            if (calendarHolidays[dateStr]) {
                return false;
            }
            return workingDays.indexOf(date.getDay()) !== -1 && date >= minDate && date <= maxDate;
        }

        // A closed date's names, full and short, by kind (#2202).
        function kindNames(kind) {
            var fallback = ffcCalendar.strings.holiday || 'Holiday';
            return ((ffcCalendar.strings.closedDateKinds || {})[kind]) || { label: fallback, short: fallback };
        }

        function escapeAttr(text) {
            return $('<span>').text(String(text)).html().replace(/"/g, '&quot;');
        }

        // Store booking counts, holidays and tracking
        var bookingCounts = {};
        var calendarHolidays = {};
        var calendarHolidayKinds = {};
        var fetchId = 0;

        // Function to fetch booking counts and holidays for a month
        function fetchBookingCounts(year, month, callback) {
            var thisId = ++fetchId;

            // Clear stale data immediately so old badges disappear
            bookingCounts = {};

            FFC.request(
                'ffc_get_month_bookings',
                { calendar_id: calendarId, year: year, month: month },
                { nonce: ffcCalendar.nonce, ajaxUrl: ffcCalendar.ajaxurl }
            )
                .then(function (data) {
                    if (thisId !== fetchId) return;
                    if (data && data.counts) {
                        bookingCounts = data.counts;
                    }
                    calendarHolidayKinds = (data && data.holiday_kinds) || {};
                    if (data && data.holidays) {
                        calendarHolidays = data.holidays;
                        if (calendar) {
                            calendar.options.holidays = calendarHolidays;
                        }
                    }
                    if (callback) callback();
                })
                .catch(function () {
                    if (thisId !== fetchId) return;
                    if (callback) callback();
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
            enabledDates: enabledDates,
            strings: ffcCalendar.strings,
            legendItems: [
                { 'class': 'ffc-available', label: ffcCalendar.strings.available || 'Available' },
                { 'class': 'ffc-booked', label: ffcCalendar.strings.booked || 'Booked' },
                { 'class': 'ffc-holiday', label: ffcCalendar.strings.holiday || 'Holiday' },
                { 'class': 'ffc-optional-holiday', label: kindNames('optional').label },
                { 'class': 'ffc-closed', label: ffcCalendar.strings.closed || 'Closed' }
            ],
            getDayClasses: function(dateStr, date) {
                return isAvailable(dateStr, date) ? ['ffc-available'] : [];
            },
            getDayContent: function(dateStr, date, isHoliday, isClosed) {
                if (isHoliday) {
                    // The cell shows the short name and says the full one to a
                    // screen reader and on hover (#2202).
                    var optional = calendarHolidayKinds[dateStr] === 'optional';
                    var names = kindNames(optional ? 'optional' : 'holiday');
                    return '<span class="ffc-day-badge ffc-badge-holiday' + (optional ? ' ffc-badge-optional-holiday' : '') + '"' +
                        ' role="img" aria-label="' + escapeAttr(names.label) + '" title="' + escapeAttr(names.label) + '">' +
                        escapeAttr(names.short) + '</span>';
                }

                // A closed day carries the cell name set with the other
                // calendar states, unless the switch hides it (#2218).
                if (isClosed) {
                    if (ffcCalendar.strings.showClosedLabel === false) {
                        return '';
                    }
                    return '<span class="ffc-day-badge ffc-badge-closed">' +
                        escapeAttr(ffcCalendar.strings.closedCell || ffcCalendar.strings.closed || 'Closed') + '</span>';
                }

                var count = bookingCounts[dateStr] ? bookingCounts[dateStr] : 0;
                if (count > 0) {
                    var singularLabel = ffcCalendar.strings.booking || 'booking';
                    var pluralLabel = ffcCalendar.strings.bookings || 'bookings';
                    var label = count === 1 ? singularLabel : pluralLabel;
                    return '<span class="ffc-day-badge ffc-badge-bookings">' + count + ' ' + escapeAttr(label) + '</span>';
                }
                if (ffcCalendar.strings.availableCell && isAvailable(dateStr, date)) {
                    return '<span class="ffc-day-badge ffc-badge-available">' + escapeAttr(ffcCalendar.strings.availableCell) + '</span>';
                }
                return '';
            },
            onMonthChange: function(year, month) {
                fetchBookingCounts(year, month, function() {
                    calendar.refresh();
                });
            },
            onDayClick: function(dateStr, _$day) {
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
