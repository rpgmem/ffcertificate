/**
 * FFC User Dashboard — Appointments panel
 *
 * Self-scheduling appointments list with upcoming/past/cancelled sections,
 * filter bar, pagination, calendar export buttons, and cancel action.
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
                        '<button type="button" class="ffc-modal-close" aria-label="' + helpers.escAttr(s.close) + '">&times;</button>' +
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
                        '<button type="button" class="ffc-btn ffc-btn-secondary ffc-cancel-keep">' + helpers.esc(s.cancelKeepBtn) + '</button>' +
                        '<button type="button" class="ffc-btn ffc-btn-danger ffc-cancel-confirm" data-id="' + helpers.escAttr(String(apt.id)) + '">' + helpers.esc(s.cancelConfirmBtn) + '</button>' +
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
                $('#tab-appointments').html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');
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
            var $container = $('#tab-appointments');
            if ($container.length === 0) return;

            if (typeof ffcDashboard.canViewAppointments !== 'undefined' && !ffcDashboard.canViewAppointments) {
                $container.html('<div class="ffc-error">' + ffcDashboard.strings.noPermission + '</div>');
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
                    $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>');
                });
        },

        render: function (appointments, page) {
            var $container = $('#tab-appointments');
            page = page || 1;
            var pageSize = helpers.getPageSize();

            var filterHtml = helpers.buildFilterBar('appointments');
            var $existing = $container.find('.ffc-filter-bar');
            var fromVal = $existing.find('.ffc-filter-from').val() || '';
            var toVal = $existing.find('.ffc-filter-to').val() || '';
            var searchVal = $existing.find('.ffc-filter-search').val() || '';

            if (!appointments || appointments.length === 0) {
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
            var upcoming = [], past = [], cancelled = [];
            filtered.forEach(function (apt) {
                if (apt.status === 'cancelled') cancelled.push(apt);
                else if (apt.appointment_date_raw < today || apt.status === 'completed' || apt.status === 'no_show') past.push(apt);
                else upcoming.push(apt);
            });

            var allOrdered = [];
            upcoming.forEach(function (b) { b._section = 'upcoming'; allOrdered.push(b); });
            past.forEach(function (b) { b._section = 'past'; allOrdered.push(b); });
            cancelled.forEach(function (b) { b._section = 'cancelled'; allOrdered.push(b); });

            var start = (page - 1) * pageSize;
            var pageItems = allOrdered.slice(start, start + pageSize);

            var html = filterHtml;
            var currentSection = '';

            pageItems.forEach(function (apt) {
                var section = apt._section;

                if (section !== currentSection) {
                    if (currentSection !== '') html += '</tbody></table>';
                    currentSection = section;

                    var sectionLabel = '', isPastSection = false;
                    if (section === 'upcoming') { sectionLabel = ffcDashboard.strings.upcoming || 'Upcoming'; }
                    else if (section === 'past') { sectionLabel = ffcDashboard.strings.past || 'Past'; isPastSection = true; }
                    else { sectionLabel = ffcDashboard.strings.cancelled || 'Cancelled'; isPastSection = true; }

                    html += '<h3' + (section !== 'upcoming' ? ' style="margin-top: 30px;"' : '') + '>' + sectionLabel + '</h3>';
                    html += '<table class="ffc-appointments-table' + (isPastSection ? ' past-appointments' : '') + '">';
                    html += '<thead><tr>';
                    html += '<th>' + ffcDashboard.strings.calendar + '</th>';
                    html += '<th>' + ffcDashboard.strings.date + '</th>';
                    html += '<th>' + ffcDashboard.strings.time + '</th>';
                    html += '<th>' + ffcDashboard.strings.status + '</th>';
                    html += '<th>' + ffcDashboard.strings.actions + '</th>';
                    html += '</tr></thead><tbody>';
                }

                var rowClass = '';
                if (apt.status === 'cancelled') rowClass = 'cancelled-row';
                else if (section === 'past') rowClass = 'past-row';

                html += '<tr' + (rowClass ? ' class="' + rowClass + '"' : '') + '>';
                html += '<td>' + helpers.esc(apt.calendar_title) + '</td>';
                html += '<td>' + apt.appointment_date + '</td>';
                html += '<td>' + apt.start_time + '</td>';
                html += '<td><span class="appointment-status status-' + apt.status + '">' + apt.status_label + '</span></td>';
                html += '<td>';

                if (apt.receipt_url) {
                    html += '<a href="' + helpers.escAttr(apt.receipt_url) + '" class="button ffc-btn-receipt" target="_blank" rel="noopener noreferrer">' + (ffcDashboard.strings.viewReceipt || 'View Receipt') + '</a>';
                }

                if (apt.can_cancel) {
                    html += '<button class="button ffc-cancel-appointment" data-id="' + apt.id + '">' + ffcDashboard.strings.cancelAppointment + '</button>';
                }

                if (section === 'upcoming' && apt.status === 'confirmed') {
                    var aptEvent = {
                        uid: 'ffc-apt-' + apt.id,
                        summary: apt.calendar_title,
                        description: apt.calendar_title + (apt.name ? '\n' + (ffcDashboard.strings.name || 'Nome:') + ' ' + apt.name : '') + (apt.email ? '\n' + (ffcDashboard.strings.email || 'Email:') + ' ' + apt.email : ''),
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
            });

            if (currentSection !== '') html += '</tbody></table>';
            html += helpers.buildPagination(allOrdered.length, page, 'appointments');

            $container.html(html);

            $container.find('.ffc-filter-from').val(fromVal);
            $container.find('.ffc-filter-to').val(toVal);
            $container.find('.ffc-filter-search').val(searchVal);
        }
    };

})(jQuery);
