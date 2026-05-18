/**
 * FFC User Dashboard — Audience Bookings panel
 *
 * Collective scheduling bookings the user belongs to. Sections: upcoming /
 * past / cancelled. Filter bar, pagination, calendar export buttons.
 *
 * @since 6.5.2
 */

(function ($) {
    'use strict';

    var helpers = FFCDashboard.helpers;

    FFCDashboard.panels.audience = {
        state: null,

        load: function () {
            var $container = $('#tab-audience');
            if ($container.length === 0) return;

            if (typeof ffcDashboard.canViewAudienceBookings !== 'undefined' && !ffcDashboard.canViewAudienceBookings) {
                $container.html('<div class="ffc-error">' + ffcDashboard.strings.noPermission + '</div>');
                return;
            }

            if (this.state !== null) return;

            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            var url = ffcDashboard.restUrl + 'user/audience-bookings';
            if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

            var self = this;
            FFC.rest(url, { nonce: ffcDashboard.nonce })
                .then(function (response) {
                    self.state = response.bookings || [];
                    self.render(self.state, 1);
                })
                .catch(function () {
                    $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>');
                });
        },

        render: function (bookings, page) {
            var $container = $('#tab-audience');
            page = page || 1;
            var pageSize = helpers.getPageSize();

            var filterHtml = helpers.buildFilterBar('audience');
            var $existing = $container.find('.ffc-filter-bar');
            var fromVal = $existing.find('.ffc-filter-from').val() || '';
            var toVal = $existing.find('.ffc-filter-to').val() || '';
            var searchVal = $existing.find('.ffc-filter-search').val() || '';

            if (!bookings || bookings.length === 0) {
                $container.html(filterHtml + '<div class="ffc-empty-state"><p>' + ffcDashboard.strings.noAudienceBookings + '</p></div>');
                return;
            }

            var filtered = bookings;
            if (fromVal || toVal || searchVal) {
                filtered = bookings.filter(function (b) {
                    if (fromVal && b.booking_date_raw < fromVal) return false;
                    if (toVal && b.booking_date_raw > toVal) return false;
                    if (searchVal) {
                        var hay = (b.environment_name + ' ' + b.schedule_name + ' ' + b.description).toLowerCase();
                        if (hay.indexOf(searchVal.toLowerCase()) === -1) return false;
                    }
                    return true;
                });
            }

            var upcoming = filtered.filter(function (b) { return !b.is_past && b.status !== 'cancelled'; });
            var past = filtered.filter(function (b) { return b.is_past && b.status !== 'cancelled'; });
            var cancelled = filtered.filter(function (b) { return b.status === 'cancelled'; });

            var allOrdered = [].concat(upcoming, past, cancelled);
            var start = (page - 1) * pageSize;
            var pageItems = allOrdered.slice(start, start + pageSize);

            var html = filterHtml;
            var currentSection = '';

            pageItems.forEach(function (booking) {
                var section;
                if (booking.status === 'cancelled') section = 'cancelled';
                else if (booking.is_past) section = 'past';
                else section = 'upcoming';

                if (section !== currentSection) {
                    if (currentSection !== '') html += '</tbody></table>';
                    currentSection = section;

                    var sectionLabel, isPastSection = false;
                    if (section === 'upcoming') { sectionLabel = ffcDashboard.strings.upcoming || 'Upcoming'; }
                    else if (section === 'past') { sectionLabel = ffcDashboard.strings.past || 'Past'; isPastSection = true; }
                    else { sectionLabel = ffcDashboard.strings.cancelled || 'Cancelled'; isPastSection = true; }

                    html += '<h3' + (currentSection !== 'upcoming' ? ' style="margin-top: 30px;"' : '') + '>' + sectionLabel + '</h3>';
                    html += '<table class="ffc-audience-bookings-table' + (isPastSection ? ' past-bookings' : '') + '">';
                    html += '<thead><tr>';
                    html += '<th>' + (ffcDashboard.strings.environment || 'Environment') + '</th>';
                    html += '<th>' + (ffcDashboard.strings.date || 'Date') + '</th>';
                    html += '<th>' + (ffcDashboard.strings.time || 'Time') + '</th>';
                    html += '<th>' + (ffcDashboard.strings.description || 'Description') + '</th>';
                    html += '<th>' + (ffcDashboard.strings.audiences || 'Audiences') + '</th>';
                    html += '<th>' + (ffcDashboard.strings.actions || 'Actions') + '</th>';
                    html += '</tr></thead><tbody>';
                }

                var rowClass = '';
                if (booking.status === 'cancelled') rowClass = 'cancelled-row';
                else if (booking.is_past) rowClass = 'past-row';

                html += '<tr' + (rowClass ? ' class="' + rowClass + '"' : '') + '>';
                html += '<td>' + booking.environment_name;
                if (booking.schedule_name) html += '<br><small style="color: #666;">' + booking.schedule_name + '</small>';
                html += '</td>';
                html += '<td>' + booking.booking_date + '</td>';
                html += '<td>' + booking.start_time + ' - ' + booking.end_time + '</td>';
                html += '<td>' + (booking.description || '') + '</td>';
                html += '<td>';
                if (booking.audiences && booking.audiences.length > 0) {
                    booking.audiences.forEach(function (audience) {
                        html += '<span class="ffc-audience-tag" style="background-color: ' + helpers.esc(audience.color) + '; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 12px;">' + helpers.esc(audience.name) + '</span>';
                    });
                }
                html += '</td>';

                html += '<td>';
                if (section === 'upcoming' && booking.status !== 'cancelled') {
                    var audienceNames = booking.audiences ? booking.audiences.map(function (a) { return a.name; }).join(', ') : '';
                    var bookingEvent = {
                        uid: 'ffc-booking-' + booking.id,
                        summary: (booking.description || booking.environment_name),
                        description: (booking.environment_name ? (ffcDashboard.strings.environment || 'Ambiente') + ': ' + booking.environment_name : '') + (booking.schedule_name ? '\n' + booking.schedule_name : '') + (booking.description ? '\n' + booking.description : '') + (audienceNames ? '\n' + (ffcDashboard.strings.audiences || 'Audiencias') + ': ' + audienceNames : ''),
                        location: booking.environment_name || '',
                        date: booking.booking_date_raw,
                        startTime: booking.start_time,
                        endTime: booking.end_time || booking.start_time
                    };
                    html += FFCDashboard.calExport.buildButton(bookingEvent);
                }
                html += '</td></tr>';
            });

            if (currentSection !== '') html += '</tbody></table>';
            html += helpers.buildPagination(allOrdered.length, page, 'audience');

            $container.html(html);

            $container.find('.ffc-filter-from').val(fromVal);
            $container.find('.ffc-filter-to').val(toVal);
            $container.find('.ffc-filter-search').val(searchVal);
        }
    };

})(jQuery);
