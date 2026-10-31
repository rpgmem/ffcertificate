/**
 * FFC User Dashboard — Audience Bookings panel
 *
 * Collective scheduling bookings the user belongs to. Sections: upcoming /
 * past / cancelled, each with its own pager (#1836). Filter bar, calendar
 * export buttons.
 *
 * @since 6.5.2
 */

(function ($) {
    'use strict';

    var helpers = FFCDashboard.helpers;

    var SECTION_ORDER = ['upcoming', 'past', 'cancelled'];

    function sectionLabel(key) {
        var s = ffcDashboard.strings;
        if (key === 'upcoming') return s.upcoming || 'Upcoming';
        if (key === 'past') return s.past || 'Past';
        return s.cancelled || 'Cancelled';
    }

    /**
     * The post-event form waiting for this person (#2028): a button with
     * their own link and when the form closes. Without a link (someone
     * viewing as the user) it only says the form waits.
     *
     * @param {Object|null} postEvent The booking's `post_event` from the REST payload.
     * @returns {string}
     */
    function postEventHtml(postEvent) {
        if (!postEvent) return '';
        var s = ffcDashboard.strings;
        var html = '<div class="ffc-post-event-pending">';
        if (postEvent.url) {
            html += FFC.buttonHtml({
                label: s.fillPostEventForm || 'Fill in the form',
                icon: 'award',
                category: 'primary',
                href: postEvent.url,
                className: 'ffc-btn-post-event',
                attrs: { title: postEvent.form_title || '' }
            });
        } else {
            html += '<span class="ffc-post-event-waiting">' + helpers.esc((s.postEventWaiting || 'Form waiting: %s').replace('%s', postEvent.form_title || '')) + '</span>';
        }
        html += '<small class="ffc-post-event-closes">' + helpers.esc((s.postEventCloses || 'Open until %s').replace('%s', postEvent.closes_label || '')) + '</small>';
        return html + '</div>';
    }

    /**
     * One booking row.
     *
     * @param {Object} booking Booking row from the REST payload.
     * @param {string} section Section key the row is drawn in.
     * @returns {string}
     */
    function buildRow(booking, section) {
        var rowClass = '';
        if (booking.status === 'cancelled') rowClass = 'ffc-row-cancelled';
        else if (booking.is_past) rowClass = 'ffc-row-past';

        // Every field is text, whoever wrote it: a booker's description, an
        // admin's space name (#2243).
        var esc = helpers.esc;
        var html = '<tr' + (rowClass ? ' class="' + rowClass + '"' : '') + '>';
        html += '<td>' + esc(booking.environment_name || '');
        if (booking.schedule_name) html += '<br><small class="ffc-booking-schedule">' + esc(booking.schedule_name) + '</small>';
        html += '</td>';
        html += '<td>' + esc(booking.booking_date || '') + '</td>';
        html += '<td>' + esc(booking.time_label || (booking.start_time + ' - ' + booking.end_time)) + '</td>';
        html += '<td>' + esc(booking.description || '');
        // Why it was cancelled, in the canceller's words (#2243).
        if (booking.cancellation_reason) {
            html += '<br><small class="ffc-booking-cancel-reason">' + helpers.esc((ffcDashboard.strings.cancelReason || 'Reason: %s').replace('%s', booking.cancellation_reason)) + '</small>';
        }
        html += '</td>';
        html += '<td>';
        if (booking.audiences && booking.audiences.length > 0) {
            booking.audiences.forEach(function (audience) {
                html += FFCDashboard.helpers.rowColorTag('ffc-audience-tag', audience);
            });
        }
        html += '</td>';

        html += '<td>';
        if (section === 'upcoming' && booking.status !== 'cancelled') {
            var audienceNames = booking.audiences ? booking.audiences.map(function (a) { return a.name; }).join(', ') : '';
            var bookingEvent = {
                uid: 'ffc-booking-' + booking.id,
                summary: (booking.description || booking.environment_name),
                description: (booking.environment_name ? (ffcDashboard.strings.environment || 'Environment') + ': ' + booking.environment_name : '') + (booking.schedule_name ? '\n' + booking.schedule_name : '') + (booking.description ? '\n' + booking.description : '') + (audienceNames ? '\n' + (ffcDashboard.strings.audiences || 'Audiences') + ': ' + audienceNames : ''),
                location: booking.environment_name || '',
                // A range is one event over every booked day (#1946).
                date: (booking.ics && booking.ics.date) || booking.booking_date_raw,
                endDate: booking.ics && booking.ics.end_date,
                rrule: booking.ics && booking.ics.rrule,
                exdates: booking.ics && booking.ics.exdates,
                startTime: booking.start_time,
                endTime: booking.end_time || booking.start_time
            };
            html += FFCDashboard.calExport.buildButton(bookingEvent);
        }
        html += postEventHtml(booking.post_event);
        html += '</td></tr>';
        return html;
    }

    FFCDashboard.panels.audience = {
        state: null,

        load: function () {
            var $container = $('#ffc-tabpanel-audience');
            if ($container.length === 0) return;

            if (typeof ffcDashboard.canViewAudienceBookings !== 'undefined' && !ffcDashboard.canViewAudienceBookings) {
                $container.empty().append(FFC.notice('danger', 'error', ffcDashboard.strings.noPermission, '', { role: 'alert' }));
                return;
            }

            if (this.state !== null) return;

            $container.html('<div class="ffc-loading" role="status">' + ffcDashboard.strings.loading + '</div>');

            var url = ffcDashboard.restUrl + 'user/audience-bookings';
            if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

            var self = this;
            FFC.rest(url, { nonce: ffcDashboard.nonce })
                .then(function (response) {
                    self.state = response.bookings || [];
                    self.pendingLabel = response.pending_label || '';
                    self.render(self.state, 1);
                })
                .catch(function () {
                    $container.empty().append(FFC.notice('danger', 'error', ffcDashboard.strings.error, '', { role: 'alert' }));
                });
        },

        // The count of post-event forms waiting, as the server worded it.
        pendingLabel: '',

        // The page each section shows; `render()` resets them, a pager
        // click moves one (core's `handleSectionPage`).
        sectionPages: { upcoming: 1, past: 1, cancelled: 1 },

        // The filtered items of each section, from the last `render()`.
        sections: { upcoming: [], past: [], cancelled: [] },

        // A full render: on load, on Filter and on Clear. Every section
        // goes back to its first page (#1836). The core dispatch passes a
        // page as well; this tab pages per section and ignores it.
        render: function (bookings) {
            var $container = $('#ffc-tabpanel-audience');

            var filterHtml = helpers.buildFilterBar('audience');
            var $existing = $container.find('.ffc-filter-bar');
            var fromVal = $existing.find('.ffc-filter-from').val() || '';
            var toVal = $existing.find('.ffc-filter-to').val() || '';
            var searchVal = $existing.find('.ffc-filter-search').val() || '';

            this.sectionPages = { upcoming: 1, past: 1, cancelled: 1 };

            if (!bookings || bookings.length === 0) {
                this.sections = { upcoming: [], past: [], cancelled: [] };
                $container.html(filterHtml + '<div class="ffc-empty-state"><p>' + ffcDashboard.strings.noAudienceBookings + '</p></div>');
                return;
            }

            var filtered = bookings;
            if (fromVal || toVal || searchVal) {
                filtered = bookings.filter(function (b) {
                    // A range matches when any of its days falls in the filter.
                    if (fromVal && (b.last_date_raw || b.booking_date_raw) < fromVal) return false;
                    if (toVal && b.booking_date_raw > toVal) return false;
                    if (searchVal) {
                        var hay = (b.environment_name + ' ' + b.schedule_name + ' ' + b.description).toLowerCase();
                        if (hay.indexOf(searchVal.toLowerCase()) === -1) return false;
                    }
                    return true;
                });
            }

            this.sections = {
                upcoming: filtered.filter(function (b) { return !b.is_past && b.status !== 'cancelled'; }),
                past: filtered.filter(function (b) { return b.is_past && b.status !== 'cancelled'; }),
                cancelled: filtered.filter(function (b) { return b.status === 'cancelled'; })
            };

            var html = filterHtml;
            if (filtered.length === 0) html += helpers.buildNoResults('audience');
            if (this.pendingLabel) {
                html += FFC.notice('info', 'award', this.pendingLabel, '', { hook: 'post-event-pending' }).outerHTML;
            }
            var self = this;
            SECTION_ORDER.forEach(function (key) {
                html += self.buildSectionHtml(key);
            });

            $container.html(html);

            $container.find('.ffc-filter-from').val(fromVal);
            $container.find('.ffc-filter-to').val(toVal);
            $container.find('.ffc-filter-search').val(searchVal);
        },

        // One section's markup at its current page. A section without
        // items is not drawn.
        buildSectionHtml: function (key) {
            var items = this.sections[key] || [];
            if (items.length === 0) return '';
            var s = ffcDashboard.strings;

            return helpers.buildSection({
                target: 'audience',
                key: key,
                label: sectionLabel(key),
                items: items,
                page: this.sectionPages[key] || 1,
                tableClass: 'ffc-audience-bookings-table ffc-dashboard-section-table' + (key === 'upcoming' ? '' : ' ffc-table-past'),
                headHtml:
                    '<th class="ffc-col-environment">' + (s.environment || 'Environment') + '</th>' +
                    '<th class="ffc-col-date">' + (s.date || 'Date') + '</th>' +
                    '<th class="ffc-col-time">' + (s.time || 'Time') + '</th>' +
                    '<th class="ffc-col-description">' + (s.description || 'Description') + '</th>' +
                    '<th class="ffc-col-audiences">' + (s.audiences || 'Audiences') + '</th>' +
                    '<th class="ffc-col-actions">' + (s.actions || 'Actions') + '</th>',
                rowHtml: function (booking) { return buildRow(booking, key); }
            });
        }
    };

})(jQuery);
