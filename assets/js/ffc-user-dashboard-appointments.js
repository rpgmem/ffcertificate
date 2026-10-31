/**
 * FFC User Dashboard — Appointments panel
 *
 * Self-scheduling appointments list with upcoming/past/cancelled sections,
 * each with its own pager (#1836), filter bar, calendar export buttons, and
 * cancel action.
 *
 * @since 6.5.2
 */

(function ($) {
    'use strict';

    var helpers = FFCDashboard.helpers;

    var MODAL_ID = 'ffc-cancel-appointment-modal';

    /**
     * Build the confirmation dialog for one appointment.
     *
     * It shows what the browser's `confirm()` could not — which appointment is
     * being cancelled — and carries the optional reason field. The endpoint has
     * always accepted `reason` (it sanitizes `$_POST['reason']` and passes it
     * on); only this caller never sent one, so a cancellation from the
     * dashboard recorded an empty reason while the same action from the e-mail
     * link recorded what the person wrote (#1140).
     *
     * The modal carries `ffc-shortcode` on itself because it is appended to
     * `<body>`, outside the dashboard container that would otherwise provide
     * that scope.
     *
     * In reschedule mode (#2244) the same dialog says the booking is
     * cancelled first and the calendar opens next; the reason is fixed, so
     * the field is not drawn.
     *
     * @param {Object}  apt        Appointment row from the panel's own state.
     * @param {boolean} reschedule Whether the dialog cancels to rebook.
     * @returns {jQuery}
     */
    function buildModal(apt, reschedule) {
        var s = ffcDashboard.strings;
        // Leaving the waitlist is the same cancellation, worded for a queue
        // place rather than a booked time (#2244).
        var waitlist = apt.status === 'waitlist';
        var confirm = reschedule
            ? window.FFC.buttonHtml({ label: s.rescheduleConfirmBtn, category: 'caution', icon: 'calendar', className: 'ffc-reschedule-confirm', attrs: { 'data-id': String(apt.id) } })
            : window.FFC.buttonHtml({ label: waitlist ? s.leaveWaitlistConfirmBtn : s.cancelConfirmBtn, category: 'danger', icon: 'cross', className: 'ffc-cancel-confirm', attrs: { 'data-id': String(apt.id) } });
        var reason = reschedule ? '' :
            '<div class="ffc-form-group">' +
                '<label for="ffc-cancel-reason">' + helpers.esc(s.cancelReasonLabel) + '</label>' +
                '<textarea id="ffc-cancel-reason" rows="3"></textarea>' +
            '</div>';
        var when = apt.appointment_date + (apt.start_time ? ' · ' + apt.start_time : '');

        var html = '' +
            '<div class="ffc-shortcode ffc-modal" id="' + MODAL_ID + '" role="dialog" aria-modal="true" aria-labelledby="' + MODAL_ID + '-title">' +
                '<div class="ffc-modal-backdrop"></div>' +
                '<div class="ffc-modal-content">' +
                    '<div class="ffc-modal-header">' +
                        '<h3 class="ffc-modal-title" id="' + MODAL_ID + '-title">' + helpers.esc(reschedule ? s.rescheduleTitle : (waitlist ? s.leaveWaitlistTitle : s.cancelTitle)) + '</h3>' +
                        '<button type="button" class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-cross ffc-modal-close" aria-label="' + helpers.escAttr(s.close) + '" title="' + helpers.escAttr(s.close) + '"></button>' +
                    '</div>' +
                    '<div class="ffc-modal-body">' +
                        '<p>' + helpers.esc(reschedule ? s.rescheduleIntro : (waitlist ? s.leaveWaitlistIntro : s.cancelIntro)) + '</p>' +
                        (reschedule && apt.reschedule_note ? window.FFC.notice('warning', 'warning', apt.reschedule_note, '', { role: 'note' }).outerHTML : '') +
                        '<dl class="ffc-cancel-summary">' +
                            '<dt>' + helpers.esc(s.cancelService) + '</dt>' +
                            '<dd>' + helpers.esc(apt.calendar_title) + '</dd>' +
                            (when ? '<dt>' + helpers.esc(s.cancelWhen) + '</dt><dd>' + helpers.esc(when) + '</dd>' : '') +
                        '</dl>' +
                        reason +
                    '</div>' +
                    '<div class="ffc-modal-footer">' +
                        window.FFC.buttonHtml({ label: s.cancelKeepBtn, category: 'neutral', icon: 'calendar-check', className: 'ffc-cancel-keep' }) +
                        confirm +
                    '</div>' +
                '</div>' +
            '</div>';

        return $(html);
    }

    function closeModal() {
        $('#' + MODAL_ID).remove();
        $(document).off('keydown.ffcCancelModal');
    }

    /**
     * Open the dialog for an appointment id, reading its details out of the
     * panel's own state rather than re-fetching or duplicating them into data
     * attributes — the list the user is looking at is already in memory.
     *
     * @param {number|string} appointmentId
     * @param {boolean}       [reschedule] Open in reschedule mode.
     */
    function openCancelDialog(appointmentId, reschedule) {
        var rows = FFCDashboard.panels.appointments.state || [];
        var apt = null;
        for (var i = 0; i < rows.length; i++) {
            if (String(rows[i].id) === String(appointmentId)) {
                apt = rows[i];
                break;
            }
        }
        if (!apt) {
            return;
        }

        closeModal();
        var $modal = buildModal(apt, !!reschedule);
        $('body').append($modal);
        $modal.find(reschedule ? '.ffc-cancel-keep' : '#ffc-cancel-reason').trigger('focus');

        $(document).on('keydown.ffcCancelModal', function (e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    }

    /**
     * Send the cancellation, with whatever reason was typed.
     *
     * With `next`, the browser goes there once the booking is cancelled
     * instead of reloading the list: rescheduling is cancel-then-book.
     *
     * @param {number|string} appointmentId
     * @param {string}        reason
     * @param {string}        [next] URL to open after the cancellation.
     */
    function cancelAppointment(appointmentId, reason, next) {
        var payload = { appointment_id: appointmentId };
        if (reason) payload.reason = reason;
        if (ffcDashboard.viewAsUserId) payload.viewAsUserId = ffcDashboard.viewAsUserId;

        FFC.request('ffc_cancel_appointment', payload, { nonce: ffcDashboard.schedulingNonce })
            .then(function () {
                closeModal();
                if (next) {
                    window.location.assign(next);
                    return;
                }
                helpers.flash('success', ffcDashboard.strings.cancelSuccess);
                var panel = FFCDashboard.panels.appointments;
                panel.state = null;
                $('#ffc-tabpanel-appointments').html('<div class="ffc-loading" role="status">' + ffcDashboard.strings.loading + '</div>');
                panel.load();
            })
            .catch(function (err) {
                // Prefer the server-supplied message; fall back to the
                // caller-specific cancelError string when the server didn't
                // include one or when the request failed at the network
                // layer (err.fromServer === false in both cases).
                helpers.flash('danger', (err && err.fromServer) ? err.message : ffcDashboard.strings.cancelError);
            });
    }

    var SECTION_ORDER = ['upcoming', 'past', 'cancelled'];

    function sectionLabel(key) {
        var s = ffcDashboard.strings;
        if (key === 'upcoming') return s.upcoming || 'Upcoming';
        if (key === 'past') return s.past || 'Past';
        return s.cancelled || 'Cancelled';
    }

    /**
     * One appointment row.
     *
     * @param {Object} apt     Appointment row from the REST payload.
     * @param {string} section Section key the row is drawn in.
     * @returns {string}
     */
    function buildRow(apt, section) {
        var rowClass = '';
        if (apt.status === 'cancelled') rowClass = 'ffc-row-cancelled';
        else if (section === 'past') rowClass = 'ffc-row-past';

        var html = '<tr' + (rowClass ? ' class="' + rowClass + '"' : '') + '>';
        html += '<td>' + helpers.esc(apt.calendar_title) + '</td>';
        // Every value is text, and the status names a class only once it is
        // reduced to the characters a class may hold (#2246).
        var statusSlug = helpers.classSlug(apt.status);
        html += '<td>' + helpers.esc(apt.appointment_date) + '</td>';
        html += '<td>' + helpers.esc(apt.start_time) + '</td>';
        html += '<td><span class="ffc-pill ffc-dashboard-status-' + statusSlug + '">' + helpers.esc(apt.status_label) + '</span></td>';
        html += '<td>';

        if (apt.receipt_url) {
            html += window.FFC.buttonHtml({
                label: ffcDashboard.strings.viewReceipt || 'View Receipt',
                category: 'neutral',
                icon: 'file',
                href: apt.receipt_url,
                className: 'ffc-btn-receipt',
                attrs: { target: '_blank', rel: 'noopener noreferrer' }
            });
        }

        if (apt.can_cancel && apt.reschedule_url) {
            html += window.FFC.buttonHtml({
                label: ffcDashboard.strings.reschedule,
                category: 'caution',
                icon: 'calendar',
                className: 'ffc-reschedule-appointment',
                attrs: { 'data-id': apt.id }
            });
        }

        if (apt.can_cancel) {
            html += window.FFC.buttonHtml({
                label: apt.status === 'waitlist' ? ffcDashboard.strings.leaveWaitlist : ffcDashboard.strings.cancelAppointment,
                category: 'danger',
                icon: 'cross',
                className: 'ffc-cancel-appointment',
                attrs: { 'data-id': apt.id }
            });
        }

        if (!apt.can_cancel && apt.cancel_note) {
            html += '<span class="ffc-cancel-note">' + helpers.esc(apt.cancel_note) + '</span>';
        }

        if (section === 'upcoming' && apt.status === 'confirmed') {
            var aptEvent = {
                uid: 'ffc-apt-' + apt.id,
                summary: apt.calendar_title,
                // No e-mail (#2246): the row carries it masked, and a calendar
                // entry the person keeps does not need it.
                description: apt.calendar_title + (apt.name ? '\n' + (ffcDashboard.strings.name || 'Name:') + ' ' + apt.name : ''),
                location: ffcDashboard.mainAddress || '',
                date: apt.appointment_date_raw,
                startTime: apt.start_time_raw,
                // Use the raw wall-clock end (like start_time_raw), not the
                // display-formatted end_time — the calendar export needs a
                // parseable HH:MM, and raw avoids any locale/TZ formatting.
                endTime: apt.end_time_raw || apt.start_time_raw
            };
            html += ' ' + FFCDashboard.calExport.buildButton(aptEvent);
        }

        html += '</td></tr>';
        return html;
    }

    FFCDashboard.panels.appointments = {
        state: null,

        bindEvents: function () {
            $(document).on('click', '.ffc-cancel-appointment', function (e) {
                e.preventDefault();
                openCancelDialog($(this).data('id'));
            });

            $(document).on('click', '.ffc-reschedule-appointment', function (e) {
                e.preventDefault();
                openCancelDialog($(this).data('id'), true);
            });

            $(document).on('click', '#' + MODAL_ID + ' .ffc-reschedule-confirm', function (e) {
                e.preventDefault();
                var id = $(this).data('id');
                var rows = FFCDashboard.panels.appointments.state || [];
                var next = '';
                for (var i = 0; i < rows.length; i++) {
                    if (String(rows[i].id) === String(id)) {
                        next = rows[i].reschedule_url || '';
                        break;
                    }
                }
                cancelAppointment(id, ffcDashboard.strings.rescheduleReason, next);
            });

            // Delegated on `document` because the dialog is appended to
            // `<body>` on demand and removed on close.
            $(document).on('click', '#' + MODAL_ID + ' .ffc-cancel-confirm', function (e) {
                e.preventDefault();
                cancelAppointment($(this).data('id'), $('#ffc-cancel-reason').val() || '');
            });

            $(document).on('click', '#' + MODAL_ID + ' .ffc-cancel-keep, #' + MODAL_ID + ' .ffc-modal-close, #' + MODAL_ID + ' .ffc-modal-backdrop', function (e) {
                e.preventDefault();
                closeModal();
            });
        },

        load: function () {
            var $container = $('#ffc-tabpanel-appointments');
            if ($container.length === 0) return;

            if (typeof ffcDashboard.canViewAppointments !== 'undefined' && !ffcDashboard.canViewAppointments) {
                $container.empty().append(FFC.notice('danger', 'error', ffcDashboard.strings.noPermission, '', { role: 'alert' }));
                return;
            }

            if (this.state !== null) return;

            $container.html('<div class="ffc-loading" role="status">' + ffcDashboard.strings.loading + '</div>');

            var url = ffcDashboard.restUrl + 'user/appointments';
            if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

            var self = this;
            FFC.rest(url, { nonce: ffcDashboard.nonce })
                .then(function (response) {
                    self.state = response.appointments || [];
                    self.render(self.state, 1);
                })
                .catch(function () {
                    $container.empty().append(FFC.notice('danger', 'error', ffcDashboard.strings.error, '', { role: 'alert' }));
                });
        },

        // The page each section shows; `render()` resets them, a pager
        // click moves one (core's `handleSectionPage`).
        sectionPages: { upcoming: 1, past: 1, cancelled: 1 },

        // The filtered items of each section, from the last `render()`.
        sections: { upcoming: [], past: [], cancelled: [] },

        // A full render: on load, on Filter and on Clear. Every section
        // goes back to its first page (#1836). The core dispatch passes a
        // page as well; this tab pages per section and ignores it.
        render: function (appointments) {
            var $container = $('#ffc-tabpanel-appointments');

            var filterHtml = helpers.buildFilterBar('appointments');
            var $existing = $container.find('.ffc-filter-bar');
            var fromVal = $existing.find('.ffc-filter-from').val() || '';
            var toVal = $existing.find('.ffc-filter-to').val() || '';
            var searchVal = $existing.find('.ffc-filter-search').val() || '';

            this.sectionPages = { upcoming: 1, past: 1, cancelled: 1 };

            if (!appointments || appointments.length === 0) {
                this.sections = { upcoming: [], past: [], cancelled: [] };
                $container.html(filterHtml + '<div class="ffc-empty-state"><p>' + ffcDashboard.strings.noAppointments + '</p></div>');
                return;
            }

            var filtered = appointments;
            if (fromVal || toVal || searchVal) {
                filtered = appointments.filter(function (a) {
                    if (fromVal && a.appointment_date_raw < fromVal) return false;
                    if (toVal && a.appointment_date_raw > toVal) return false;
                    if (searchVal) {
                        var hay = (a.calendar_title + ' ' + a.status_label).toLowerCase();
                        if (hay.indexOf(searchVal.toLowerCase()) === -1) return false;
                    }
                    return true;
                });
            }

            // The server decides past and upcoming in the site's timezone;
            // the browser's UTC date put tonight's appointments in Past (#2246).
            var sections = { upcoming: [], past: [], cancelled: [] };
            filtered.forEach(function (apt) {
                if (apt.status === 'cancelled') sections.cancelled.push(apt);
                else if (apt.is_past) sections.past.push(apt);
                else sections.upcoming.push(apt);
            });
            // Upcoming reads soonest first; on one day by start time, then id.
            sections.upcoming.sort(function (a, b) {
                var ka = (a.appointment_date_raw || '') + ' ' + (a.start_time_raw || '');
                var kb = (b.appointment_date_raw || '') + ' ' + (b.start_time_raw || '');
                if (ka !== kb) return ka < kb ? -1 : 1;
                return (a.id || 0) - (b.id || 0);
            });
            this.sections = sections;

            var html = filterHtml;
            if (filtered.length === 0) html += helpers.buildNoResults('appointments');
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
                target: 'appointments',
                key: key,
                label: sectionLabel(key),
                items: items,
                page: this.sectionPages[key] || 1,
                tableClass: 'ffc-appointments-table ffc-dashboard-section-table' + (key === 'upcoming' ? '' : ' ffc-table-past'),
                headHtml:
                    '<th class="ffc-col-calendar">' + s.calendar + '</th>' +
                    '<th class="ffc-col-date">' + s.date + '</th>' +
                    '<th class="ffc-col-time">' + s.time + '</th>' +
                    '<th class="ffc-col-status">' + s.status + '</th>' +
                    '<th class="ffc-col-actions">' + s.actions + '</th>',
                rowHtml: function (apt) { return buildRow(apt, key); }
            });
        }
    };

})(jQuery);
