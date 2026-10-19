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
     * @param {Object} apt Appointment row from the panel's own state.
     * @returns {jQuery}
     */
    function buildModal(apt) {
        var s = ffcDashboard.strings;
        var when = apt.appointment_date + (apt.start_time ? ' · ' + apt.start_time : '');

        var html = '' +
            '<div class="ffc-shortcode ffc-modal" id="' + MODAL_ID + '" role="dialog" aria-modal="true" aria-labelledby="' + MODAL_ID + '-title">' +
                '<div class="ffc-modal-backdrop"></div>' +
                '<div class="ffc-modal-content">' +
                    '<div class="ffc-modal-header">' +
                        '<h3 class="ffc-modal-title" id="' + MODAL_ID + '-title">' + helpers.esc(s.cancelTitle) + '</h3>' +
                        '<button type="button" class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-cross ffc-modal-close" aria-label="' + helpers.escAttr(s.close) + '" title="' + helpers.escAttr(s.close) + '"></button>' +
                    '</div>' +
                    '<div class="ffc-modal-body">' +
                        '<p>' + helpers.esc(s.cancelIntro) + '</p>' +
                        '<dl class="ffc-cancel-summary">' +
                            '<dt>' + helpers.esc(s.cancelService) + '</dt>' +
                            '<dd>' + helpers.esc(apt.calendar_title) + '</dd>' +
                            (when ? '<dt>' + helpers.esc(s.cancelWhen) + '</dt><dd>' + helpers.esc(when) + '</dd>' : '') +
                        '</dl>' +
                        '<div class="ffc-form-group">' +
                            '<label for="ffc-cancel-reason">' + helpers.esc(s.cancelReasonLabel) + '</label>' +
                            '<textarea id="ffc-cancel-reason" rows="3"></textarea>' +
                        '</div>' +
                    '</div>' +
                    '<div class="ffc-modal-footer">' +
                        window.FFC.buttonHtml({ label: s.cancelKeepBtn, category: 'neutral', icon: 'calendar-check', className: 'ffc-cancel-keep' }) +
                        window.FFC.buttonHtml({ label: s.cancelConfirmBtn, category: 'danger', icon: 'cross', className: 'ffc-cancel-confirm', attrs: { 'data-id': String(apt.id) } }) +
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
     */
    function openCancelDialog(appointmentId) {
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
        var $modal = buildModal(apt);
        $('body').append($modal);
        $modal.find('#ffc-cancel-reason').trigger('focus');

        $(document).on('keydown.ffcCancelModal', function (e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    }

    /**
     * Send the cancellation, with whatever reason was typed.
     *
     * @param {number|string} appointmentId
     * @param {string}        reason
     */
    function cancelAppointment(appointmentId, reason) {
        var payload = { appointment_id: appointmentId };
        if (reason) payload.reason = reason;
        if (ffcDashboard.viewAsUserId) payload.viewAsUserId = ffcDashboard.viewAsUserId;

        FFC.request('ffc_cancel_appointment', payload, { nonce: ffcDashboard.schedulingNonce })
            .then(function () {
                closeModal();
                alert(ffcDashboard.strings.cancelSuccess);
                var panel = FFCDashboard.panels.appointments;
                panel.state = null;
                $('#ffc-tabpanel-appointments').html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');
                panel.load();
            })
            .catch(function (err) {
                // Prefer the server-supplied message; fall back to the
                // caller-specific cancelError string when the server didn't
                // include one or when the request failed at the network
                // layer (err.fromServer === false in both cases).
                var msg = (err && err.fromServer) ? err.message : ffcDashboard.strings.cancelError;
                alert(msg);
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
        html += '<td>' + apt.appointment_date + '</td>';
        html += '<td>' + apt.start_time + '</td>';
        html += '<td><span class="ffc-pill ffc-dashboard-status-' + apt.status + '">' + apt.status_label + '</span></td>';
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

        if (apt.can_cancel) {
            html += window.FFC.buttonHtml({
                label: ffcDashboard.strings.cancelAppointment,
                category: 'danger',
                icon: 'cross',
                className: 'ffc-cancel-appointment',
                attrs: { 'data-id': apt.id }
            });
        }

        if (section === 'upcoming' && apt.status === 'confirmed') {
            var aptEvent = {
                uid: 'ffc-apt-' + apt.id,
                summary: apt.calendar_title,
                description: apt.calendar_title + (apt.name ? '\n' + (ffcDashboard.strings.name || 'Name:') + ' ' + apt.name : '') + (apt.email ? '\n' + (ffcDashboard.strings.email || 'Email:') + ' ' + apt.email : ''),
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

            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

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

            var today = new Date().toISOString().slice(0, 10);
            var sections = { upcoming: [], past: [], cancelled: [] };
            filtered.forEach(function (apt) {
                if (apt.status === 'cancelled') sections.cancelled.push(apt);
                else if (apt.appointment_date_raw < today || apt.status === 'completed' || apt.status === 'no_show') sections.past.push(apt);
                else sections.upcoming.push(apt);
            });
            this.sections = sections;

            var html = filterHtml;
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
