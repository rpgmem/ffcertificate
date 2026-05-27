/**
 * FFC Certificates Dashboard
 *
 * Renders a monthly calendar of forms keyed by GeoFence start date (with a
 * fallback to post_date) and a side list of forms for the selected day.
 *
 * Depends on FFCCalendarCore (assets/js/ffc-calendar-core.js).
 *
 * @since 6.4.0
 */

(function ($) {
    'use strict';

    if (typeof FFCCalendarCore === 'undefined' || typeof window.ffcCertificatesDashboard === 'undefined') {
        return;
    }

    $(function () {
        var $container = $('#ffc-certificates-calendar');
        if (!$container.length) {
            return;
        }

        var settings = window.ffcCertificatesDashboard;
        var i18n = settings.i18n || {};
        var $list = $('#ffc-certificates-day-list');
        var $emptyMessage = $('.ffc-certificates-side-empty');
        var $sideTitle = $('.ffc-certificates-side-title');
        var sideTitleBase = $sideTitle.text();

        // events keyed by Y-m-d → array of entries
        var eventsByDate = {};
        var fetchId = 0;
        var calendarInstance = null;

        function formatDateLabel(dateStr) {
            // dateStr is Y-m-d. Build a Date in the *local* TZ to avoid a
            // one-day drift when toLocaleDateString interprets a UTC string.
            var parts = dateStr.split('-');
            var date = new Date(
                parseInt(parts[0], 10),
                parseInt(parts[1], 10) - 1,
                parseInt(parts[2], 10)
            );
            try {
                return date.toLocaleDateString(undefined, {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
            } catch (e) {
                return dateStr;
            }
        }

        function fetchMonth(year, month) {
            var thisFetch = ++fetchId;
            var url = settings.restUrl + 'certificates/calendar?year=' + year + '&month=' + month;

            // Reset month state immediately so stale badges disappear during the request.
            eventsByDate = {};
            if (calendarInstance) {
                calendarInstance.refresh();
            }

            FFC.rest(url, { nonce: settings.nonce }).then(function (response) {
                if (thisFetch !== fetchId) {
                    return;
                }
                eventsByDate = {};
                // `$.isArray` was removed in jQuery 4; native Array.isArray
                // is the modern equivalent and works in every supported env.
                if (Array.isArray(response)) {
                    for (var i = 0; i < response.length; i++) {
                        var entry = response[i];
                        if (!entry || !entry.date) {
                            continue;
                        }
                        if (!eventsByDate[entry.date]) {
                            eventsByDate[entry.date] = [];
                        }
                        eventsByDate[entry.date].push(entry);
                    }
                }
                if (calendarInstance) {
                    calendarInstance.refresh();
                }
            });
        }

        function getDayContent(dateStr) {
            var entries = eventsByDate[dateStr];
            if (!entries || !entries.length) {
                return '';
            }
            return '<span class="ffc-cert-count">' + entries.length + '</span>';
        }

        function getDayClasses(dateStr) {
            var entries = eventsByDate[dateStr];
            if (!entries || !entries.length) {
                return [];
            }

            var hasGeofence = false;
            var hasFallback = false;
            for (var i = 0; i < entries.length; i++) {
                if (entries[i].source === 'geofence') {
                    hasGeofence = true;
                } else {
                    hasFallback = true;
                }
            }

            var classes = ['ffc-cert-day'];
            if (hasGeofence) {
                classes.push('ffc-cert-has-geofence');
            }
            if (hasFallback) {
                classes.push('ffc-cert-has-fallback');
            }
            return classes;
        }

        function renderSideList(dateStr) {
            var entries = eventsByDate[dateStr] || [];

            if (sideTitleBase) {
                $sideTitle.text(sideTitleBase + ' — ' + formatDateLabel(dateStr));
            }

            if (!entries.length) {
                $list.attr('hidden', 'hidden').empty();
                $emptyMessage
                    .text(i18n.noFormsForDay || 'No forms scheduled for this day.')
                    .show();
                return;
            }

            $emptyMessage.hide();
            $list.removeAttr('hidden').empty();

            entries.sort(function (a, b) {
                return (a.title || '').localeCompare(b.title || '');
            });

            for (var i = 0; i < entries.length; i++) {
                var entry = entries[i];
                var sourceLabel = entry.source === 'geofence'
                    ? (i18n.sourceGeofence || 'GeoFence')
                    : (i18n.sourcePostDate || 'Publication date');
                var sourceClass = entry.source === 'geofence' ? 'is-geofence' : 'is-fallback';
                var statusLabel = entry.status ? entry.status : '';

                var $item = $('<li class="ffc-certificates-day-item"></li>');
                $item.append(
                    $('<span class="ffc-certificates-source-badge"></span>')
                        .addClass(sourceClass)
                        .text(sourceLabel)
                );
                if (entry.edit_url) {
                    $item.append(
                        $('<a class="ffc-certificates-day-title"></a>')
                            .attr('href', entry.edit_url)
                            .text(entry.title || ('#' + entry.id))
                    );
                } else {
                    $item.append(
                        $('<span class="ffc-certificates-day-title"></span>')
                            .text(entry.title || ('#' + entry.id))
                    );
                }
                if (settings.submissionsUrlBase && entry.id) {
                    $item.append(
                        $('<a class="ffc-certificates-submissions-link"></a>')
                            .attr(
                                'href',
                                settings.submissionsUrlBase +
                                    '&filter_form_id[0]=' +
                                    encodeURIComponent(entry.id)
                            )
                            .attr('title', i18n.viewSubmissions || 'View submissions for this form')
                            .attr('aria-label', i18n.viewSubmissions || 'View submissions for this form')
                            .append(
                                $('<span class="dashicons dashicons-list-view" aria-hidden="true"></span>')
                            )
                    );
                }
                if (statusLabel && statusLabel !== 'publish') {
                    $item.append(
                        $('<span class="ffc-certificates-status"></span>')
                            .text('(' + statusLabel + ')')
                    );
                }
                $list.append($item);
            }
        }

        calendarInstance = new FFCCalendarCore($container, {
            showLegend: true,
            showTodayButton: true,
            showFilters: false,
            strings: i18n.calendarStrings || undefined,
            legendItems: [
                { 'class': 'ffc-cert-has-geofence', label: i18n.legendGeofence || 'GeoFence start' },
                { 'class': 'ffc-cert-has-fallback', label: i18n.legendFallback || 'Publication date (fallback)' }
            ],
            getDayContent: function (dateStr) {
                return getDayContent(dateStr);
            },
            getDayClasses: function (dateStr) {
                return getDayClasses(dateStr);
            },
            onMonthChange: function (year, month) {
                fetchMonth(year, month);
            },
            onDayClick: function (dateStr) {
                renderSideList(dateStr);
            }
        });
    });

})(jQuery);
