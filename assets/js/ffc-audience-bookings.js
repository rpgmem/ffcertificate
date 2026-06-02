/**
 * FFC Audience Calendar — day modal, day bookings list, cancel.
 *
 * Reads shared state/helpers from window.FFCAudience (ffc-audience.js) and
 * registers the day-bookings methods back on the namespace.
 *
 * @since 4.5.0 (split out of ffc-audience.js)
 * @package FreeFormCertificate\Audience
 */

(function($) {
    'use strict';

    var api   = window.FFCAudience;
    var state = api.state;
    var parseDate                   = api.parseDate;
    var trapFocus                   = api.trapFocus;
    var getEnvironmentColor         = api.getEnvironmentColor;
    var formatTime                  = api.formatTime;
    var escapeHtml                  = api.escapeHtml;
    var getEnvironmentLabelForBooking = api.getEnvironmentLabelForBooking;
    var collapseParentAudiences     = api.collapseParentAudiences;
    var buildParentNameMap          = api.buildParentNameMap;
    var formatAudienceName          = api.formatAudienceName;

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

        state._triggerElement = document.activeElement;
        $modal.find('.ffc-day-modal-title').text(dateDisplay);
        $modal.data('date', date);
        $modal.show();
        $modal.find('.ffc-modal-close').focus();
        trapFocus($modal);

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

            var envColor = getEnvironmentColor(booking.environment_id);
            html += '<div class="' + classes.join(' ') + '" style="border-left: 4px solid ' + envColor + ';">';
            if (parseInt(booking.is_all_day)) {
                html += '<div class="ffc-booking-time ffc-all-day">' + ((ffcAudience.strings || {}).allDay || 'All Day') + '</div>';
            } else {
                html += '<div class="ffc-booking-time">' + formatTime(booking.start_time) + ' - ' + formatTime(booking.end_time) + '</div>';
            }
            html += '<div class="ffc-booking-description">' + escapeHtml(booking.description) + '</div>';

            html += '<div class="ffc-booking-meta">';
            html += '<span><strong>' + escapeHtml(getEnvironmentLabelForBooking(booking) + ':') + '</strong> ' + escapeHtml(booking.environment_name) + '</span>';
            if (booking.status === 'cancelled') {
                html += ' <span class="ffc-status-cancelled">(' + (ffcAudience.strings.cancelled || 'Cancelled') + ')</span>';
            }
            html += '</div>';

            if (booking.audiences && booking.audiences.length > 0) {
                var displayAudiences = collapseParentAudiences(booking.audiences);
                var pMap = buildParentNameMap();
                html += '<div class="ffc-booking-audiences">';
                displayAudiences.forEach(function(audience) {
                    html += '<span class="ffc-audience-tag" style="background-color: ' + audience.color + '">' + escapeHtml(formatAudienceName(audience, pMap)) + '</span>';
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
     * Cancel booking
     */
    function cancelBooking(bookingId, date) {
        var reason = prompt(ffcAudience.strings.cancelReason);
        if (!reason || reason.trim() === '') {
            return;
        }

        FFC.rest(ffcAudience.restUrl + 'bookings/' + bookingId, {
            method: 'DELETE',
            data: { reason: reason },
            nonce: ffcAudience.nonce
        })
            .then(function(response) {
                if (response && response.success) {
                    alert(ffcAudience.strings.bookingCancelled);
                    api.renderCalendar();
                    loadDayBookings(date);
                } else {
                    alert((response && response.message) || ffcAudience.strings.error);
                }
            })
            .catch(function() {
                alert(ffcAudience.strings.error);
            });
    }

    api.openDayModal   = openDayModal;
    api.loadDayBookings = loadDayBookings;

})(jQuery);
