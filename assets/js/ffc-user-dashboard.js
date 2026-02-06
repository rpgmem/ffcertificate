/**
 * FFC User Dashboard JavaScript
 * v3.2.0: Added client-side pagination and audience groups in profile
 * @since 3.1.0
 */

(function($) {
    'use strict';

    var PAGE_SIZE = 25;

    var FFCDashboard = {

        /**
         * Initialize dashboard
         */
        init: function() {
            this.bindEvents();
            this.loadInitialTab();
        },

        /**
         * Bind event listeners - Using event delegation
         */
        bindEvents: function() {
            $(document).on('click', '.ffc-tab', this.switchTab.bind(this));
            $(document).on('click', '.ffc-pagination-btn', this.handlePagination.bind(this));
        },

        /**
         * Load initial tab content
         */
        loadInitialTab: function() {
            var activeTab = $('.ffc-tab.active').data('tab');
            if (activeTab === 'certificates') {
                this.loadCertificates();
            } else if (activeTab === 'appointments') {
                this.loadAppointments();
            } else if (activeTab === 'audience') {
                this.loadAudienceBookings();
            } else if (activeTab === 'profile') {
                this.loadProfile();
            }
        },

        /**
         * Switch between tabs
         */
        switchTab: function(e) {
            e.preventDefault();
            var $button = $(e.currentTarget);
            var tab = $button.data('tab');

            // Update active tab button
            $('.ffc-tab').removeClass('active');
            $button.addClass('active');

            // Update active tab content
            $('.ffc-tab-content').removeClass('active');
            $('#tab-' + tab).addClass('active');

            // Update URL without reload
            if (history.pushState) {
                var url = new URL(window.location);
                url.searchParams.set('tab', tab);
                history.pushState({}, '', url);
            }

            // Load tab data
            if (tab === 'certificates') {
                this.loadCertificates();
            } else if (tab === 'appointments') {
                this.loadAppointments();
            } else if (tab === 'audience') {
                this.loadAudienceBookings();
            } else if (tab === 'profile') {
                this.loadProfile();
            }
        },

        /**
         * Build pagination controls
         */
        buildPagination: function(total, page, dataAttr) {
            if (total <= PAGE_SIZE) return '';

            var totalPages = Math.ceil(total / PAGE_SIZE);
            var html = '<div class="ffc-pagination" style="margin-top: 15px; text-align: center;">';

            if (page > 1) {
                html += '<button class="button ffc-pagination-btn" data-page="' + (page - 1) + '" data-target="' + dataAttr + '">&laquo; ' + (ffcDashboard.strings.previous || 'Previous') + '</button> ';
            }

            html += '<span style="margin: 0 10px; color: #666; font-size: 13px;">';
            html += (ffcDashboard.strings.pageOf || 'Page {current} of {total}').replace('{current}', page).replace('{total}', totalPages);
            html += '</span>';

            if (page < totalPages) {
                html += ' <button class="button ffc-pagination-btn" data-page="' + (page + 1) + '" data-target="' + dataAttr + '">' + (ffcDashboard.strings.next || 'Next') + ' &raquo;</button>';
            }

            html += '</div>';
            return html;
        },

        /**
         * Handle pagination click
         */
        handlePagination: function(e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var page = parseInt($btn.data('page'), 10);
            var target = $btn.data('target');

            if (target === 'certificates') {
                this.renderCertificates(this._certificatesData, page);
            } else if (target === 'appointments') {
                this.renderAppointments(this._appointmentsData, page);
            } else if (target === 'audience') {
                this.renderAudienceBookings(this._audienceData, page);
            }
        },

        // ---- Certificates ----

        _certificatesData: null,

        loadCertificates: function() {
            var $container = $('#tab-certificates');
            if ($container.length === 0) return;

            if (typeof ffcDashboard.canViewCertificates !== 'undefined' && !ffcDashboard.canViewCertificates) {
                $container.html('<div class="ffc-error">' + ffcDashboard.strings.noPermission + '</div>');
                return;
            }

            if (this._certificatesData !== null) return;

            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            var url = ffcDashboard.restUrl + 'user/certificates';
            if (ffcDashboard.viewAsUserId) {
                url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;
            }

            $.ajax({
                url: url,
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce);
                },
                success: function(response) {
                    FFCDashboard._certificatesData = response.certificates || [];
                    FFCDashboard.renderCertificates(FFCDashboard._certificatesData, 1);
                },
                error: function() {
                    $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>');
                }
            });
        },

        renderCertificates: function(certificates, page) {
            var $container = $('#tab-certificates');
            page = page || 1;

            if (!certificates || certificates.length === 0) {
                $container.html(
                    '<div class="ffc-empty-state">' +
                    '<p>📜</p>' +
                    '<p>' + ffcDashboard.strings.noCertificates + '</p>' +
                    '</div>'
                );
                return;
            }

            var start = (page - 1) * PAGE_SIZE;
            var pageItems = certificates.slice(start, start + PAGE_SIZE);

            var html = '<table class="ffc-certificates-table">';
            html += '<thead>';
            html += '<tr>';
            html += '<th>' + ffcDashboard.strings.eventName + '</th>';
            html += '<th>' + ffcDashboard.strings.date + '</th>';
            html += '<th>' + ffcDashboard.strings.consent + '</th>';
            html += '<th>' + ffcDashboard.strings.email + '</th>';
            html += '<th>' + ffcDashboard.strings.code + '</th>';
            html += '<th>' + ffcDashboard.strings.actions + '</th>';
            html += '</tr>';
            html += '</thead>';
            html += '<tbody>';

            pageItems.forEach(function(cert) {
                html += '<tr>';
                html += '<td>' + cert.form_title + '</td>';
                html += '<td>' + cert.submission_date + '</td>';
                html += '<td><span class="' + (cert.consent_given ? 'consent-yes' : 'consent-no') + '">';
                html += (cert.consent_given ? ffcDashboard.strings.yes : ffcDashboard.strings.no);
                html += '</span></td>';
                html += '<td>' + cert.email + '</td>';
                html += '<td>' + cert.auth_code + '</td>';
                html += '<td>';
                html += '<a href="' + cert.magic_link + '" class="button" target="_blank">';
                html += '📄 ' + ffcDashboard.strings.downloadPdf;
                html += '</a>';
                html += '</td>';
                html += '</tr>';
            });

            html += '</tbody>';
            html += '</table>';
            html += this.buildPagination(certificates.length, page, 'certificates');

            $container.html(html);
        },

        // ---- Appointments ----

        _appointmentsData: null,

        loadAppointments: function() {
            var $container = $('#tab-appointments');
            if ($container.length === 0) return;

            if (typeof ffcDashboard.canViewAppointments !== 'undefined' && !ffcDashboard.canViewAppointments) {
                $container.html('<div class="ffc-error">' + ffcDashboard.strings.noPermission + '</div>');
                return;
            }

            if (this._appointmentsData !== null) return;

            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            var url = ffcDashboard.restUrl + 'user/appointments';
            if (ffcDashboard.viewAsUserId) {
                url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;
            }

            $.ajax({
                url: url,
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce);
                },
                success: function(response) {
                    FFCDashboard._appointmentsData = response.appointments || [];
                    FFCDashboard.renderAppointments(FFCDashboard._appointmentsData, 1);
                },
                error: function() {
                    $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>');
                }
            });
        },

        renderAppointments: function(appointments, page) {
            var $container = $('#tab-appointments');
            page = page || 1;

            if (!appointments || appointments.length === 0) {
                $container.html(
                    '<div class="ffc-empty-state">' +
                    '<p>📅</p>' +
                    '<p>' + ffcDashboard.strings.noAppointments + '</p>' +
                    '</div>'
                );
                return;
            }

            // Separate into upcoming, past, cancelled
            var today = new Date().toISOString().slice(0, 10);
            var upcoming = [], past = [], cancelled = [];
            appointments.forEach(function(apt) {
                if (apt.status === 'cancelled') {
                    cancelled.push(apt);
                } else if (apt.appointment_date_raw < today || apt.status === 'completed' || apt.status === 'no_show') {
                    past.push(apt);
                } else {
                    upcoming.push(apt);
                }
            });

            // Build ordered list: upcoming first, then past, then cancelled
            var allOrdered = [];
            upcoming.forEach(function(b) { b._section = 'upcoming'; allOrdered.push(b); });
            past.forEach(function(b) { b._section = 'past'; allOrdered.push(b); });
            cancelled.forEach(function(b) { b._section = 'cancelled'; allOrdered.push(b); });

            var start = (page - 1) * PAGE_SIZE;
            var pageItems = allOrdered.slice(start, start + PAGE_SIZE);

            var html = '';
            var currentSection = '';

            pageItems.forEach(function(apt) {
                var section = apt._section;

                // New section header + new table
                if (section !== currentSection) {
                    if (currentSection !== '') {
                        html += '</tbody></table>';
                    }
                    currentSection = section;

                    var sectionLabel = '';
                    var isPastSection = false;
                    if (section === 'upcoming') {
                        sectionLabel = ffcDashboard.strings.upcoming || 'Upcoming';
                    } else if (section === 'past') {
                        sectionLabel = ffcDashboard.strings.past || 'Past';
                        isPastSection = true;
                    } else {
                        sectionLabel = ffcDashboard.strings.cancelled || 'Cancelled';
                        isPastSection = true;
                    }

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
                html += '<td>' + apt.calendar_title + '</td>';
                html += '<td>' + apt.appointment_date + '</td>';
                html += '<td>' + apt.start_time + '</td>';
                html += '<td><span class="appointment-status status-' + apt.status + '">' + apt.status_label + '</span></td>';
                html += '<td>';

                if (apt.receipt_url) {
                    html += '<a href="' + apt.receipt_url + '" class="button" target="_blank" style="margin-right: 5px;">';
                    html += '📄 ' + (ffcDashboard.strings.viewReceipt || 'View Receipt');
                    html += '</a>';
                }

                if (apt.can_cancel) {
                    html += '<button class="button ffc-cancel-appointment" data-id="' + apt.id + '">';
                    html += ffcDashboard.strings.cancelAppointment;
                    html += '</button>';
                }

                html += '</td>';
                html += '</tr>';
            });

            if (currentSection !== '') {
                html += '</tbody></table>';
            }

            html += this.buildPagination(allOrdered.length, page, 'appointments');

            $container.html(html);

            // Bind cancel event
            $container.on('click', '.ffc-cancel-appointment', function(e) {
                e.preventDefault();
                var appointmentId = $(this).data('id');
                FFCDashboard.cancelAppointment(appointmentId);
            });
        },

        // ---- Audience Bookings ----

        _audienceData: null,

        loadAudienceBookings: function() {
            var $container = $('#tab-audience');
            if ($container.length === 0) return;

            if (typeof ffcDashboard.canViewAudienceBookings !== 'undefined' && !ffcDashboard.canViewAudienceBookings) {
                $container.html('<div class="ffc-error">' + ffcDashboard.strings.noPermission + '</div>');
                return;
            }

            if (this._audienceData !== null) return;

            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            var url = ffcDashboard.restUrl + 'user/audience-bookings';
            if (ffcDashboard.viewAsUserId) {
                url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;
            }

            $.ajax({
                url: url,
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce);
                },
                success: function(response) {
                    FFCDashboard._audienceData = response.bookings || [];
                    FFCDashboard.renderAudienceBookings(FFCDashboard._audienceData, 1);
                },
                error: function() {
                    $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>');
                }
            });
        },

        renderAudienceBookings: function(bookings, page) {
            var $container = $('#tab-audience');
            page = page || 1;

            if (!bookings || bookings.length === 0) {
                $container.html(
                    '<div class="ffc-empty-state">' +
                    '<p>👥</p>' +
                    '<p>' + ffcDashboard.strings.noAudienceBookings + '</p>' +
                    '</div>'
                );
                return;
            }

            // Separate by category
            var upcoming = bookings.filter(function(b) { return !b.is_past && b.status !== 'cancelled'; });
            var past = bookings.filter(function(b) { return b.is_past && b.status !== 'cancelled'; });
            var cancelled = bookings.filter(function(b) { return b.status === 'cancelled'; });

            // Flatten all into single list for pagination
            var allOrdered = [].concat(upcoming, past, cancelled);
            var start = (page - 1) * PAGE_SIZE;
            var pageItems = allOrdered.slice(start, start + PAGE_SIZE);

            // Build section headers based on which items are on this page
            var html = '';
            var currentSection = '';

            pageItems.forEach(function(booking) {
                var section;
                if (booking.status === 'cancelled') {
                    section = 'cancelled';
                } else if (booking.is_past) {
                    section = 'past';
                } else {
                    section = 'upcoming';
                }

                if (section !== currentSection) {
                    if (currentSection !== '') {
                        html += '</tbody></table>';
                    }
                    currentSection = section;

                    var sectionLabel;
                    var isPastSection = false;
                    if (section === 'upcoming') {
                        sectionLabel = ffcDashboard.strings.upcoming || 'Upcoming';
                    } else if (section === 'past') {
                        sectionLabel = ffcDashboard.strings.past || 'Past';
                        isPastSection = true;
                    } else {
                        sectionLabel = ffcDashboard.strings.cancelled || 'Cancelled';
                        isPastSection = true;
                    }

                    html += '<h3' + (currentSection !== 'upcoming' ? ' style="margin-top: 30px;"' : '') + '>' + sectionLabel + '</h3>';
                    html += '<table class="ffc-audience-bookings-table' + (isPastSection ? ' past-bookings' : '') + '">';
                    html += '<thead><tr>';
                    html += '<th>' + (ffcDashboard.strings.environment || 'Environment') + '</th>';
                    html += '<th>' + (ffcDashboard.strings.date || 'Date') + '</th>';
                    html += '<th>' + (ffcDashboard.strings.time || 'Time') + '</th>';
                    html += '<th>' + (ffcDashboard.strings.description || 'Description') + '</th>';
                    html += '<th>' + (ffcDashboard.strings.audiences || 'Audiences') + '</th>';
                    html += '</tr></thead><tbody>';
                }

                var rowClass = '';
                if (booking.status === 'cancelled') rowClass = 'cancelled-row';
                else if (booking.is_past) rowClass = 'past-row';

                html += '<tr' + (rowClass ? ' class="' + rowClass + '"' : '') + '>';
                html += '<td>' + booking.environment_name;
                if (booking.schedule_name) {
                    html += '<br><small style="color: #666;">' + booking.schedule_name + '</small>';
                }
                html += '</td>';
                html += '<td>' + booking.booking_date + '</td>';
                html += '<td>' + booking.start_time + ' - ' + booking.end_time + '</td>';
                html += '<td>' + (booking.description || '') + '</td>';
                html += '<td>';
                if (booking.audiences && booking.audiences.length > 0) {
                    booking.audiences.forEach(function(audience) {
                        html += '<span class="ffc-audience-tag" style="background-color: ' + audience.color + '; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 12px;">';
                        html += audience.name;
                        html += '</span>';
                    });
                }
                html += '</td>';
                html += '</tr>';
            });

            if (currentSection !== '') {
                html += '</tbody></table>';
            }

            html += this.buildPagination(allOrdered.length, page, 'audience');

            $container.html(html);
        },

        // ---- Cancel Appointment ----

        cancelAppointment: function(appointmentId) {
            if (!confirm(ffcDashboard.strings.confirmCancel)) {
                return;
            }

            $.ajax({
                url: ffcDashboard.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'ffc_cancel_appointment',
                    appointment_id: appointmentId,
                    nonce: ffcDashboard.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(ffcDashboard.strings.cancelSuccess);
                        // Force reload
                        FFCDashboard._appointmentsData = null;
                        $('#tab-appointments').html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');
                        FFCDashboard.loadAppointments();
                    } else {
                        alert(response.data.message || ffcDashboard.strings.cancelError);
                    }
                },
                error: function() {
                    alert(ffcDashboard.strings.cancelError);
                }
            });
        },

        // ---- Profile ----

        loadProfile: function() {
            var $container = $('#tab-profile');

            if ($container.find('.ffc-profile-info').length > 0) {
                return;
            }

            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            var url = ffcDashboard.restUrl + 'user/profile';
            if (ffcDashboard.viewAsUserId) {
                url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;
            }

            $.ajax({
                url: url,
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce);
                },
                success: function(response) {
                    FFCDashboard.renderProfile(response);
                },
                error: function() {
                    $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>');
                }
            });
        },

        renderProfile: function(profile) {
            var $container = $('#tab-profile');

            var html = '<div class="ffc-profile-info">';

            // Name(s)
            html += '<div class="ffc-profile-field">';
            html += '<label>' + ffcDashboard.strings.name + '</label>';
            if (profile.names && profile.names.length > 1) {
                html += '<ul class="name-list">';
                profile.names.forEach(function(name) {
                    html += '<li>' + name + '</li>';
                });
                html += '</ul>';
            } else if (profile.names && profile.names.length === 1) {
                html += '<div class="value">' + profile.names[0] + '</div>';
            } else {
                html += '<div class="value">' + (profile.display_name || '-') + '</div>';
            }
            html += '</div>';

            // Email(s)
            html += '<div class="ffc-profile-field">';
            html += '<label>' + ffcDashboard.strings.linkedEmails + '</label>';
            if (profile.emails && profile.emails.length > 1) {
                html += '<ul class="email-list">';
                profile.emails.forEach(function(email) {
                    html += '<li>' + email + '</li>';
                });
                html += '</ul>';
            } else if (profile.emails && profile.emails.length === 1) {
                html += '<div class="value">' + profile.emails[0] + '</div>';
            } else {
                html += '<div class="value">' + (profile.email || '-') + '</div>';
            }
            html += '</div>';

            // CPF/RF (masked)
            html += '<div class="ffc-profile-field">';
            html += '<label>' + ffcDashboard.strings.cpfRf + '</label>';
            if (profile.cpfs_masked && profile.cpfs_masked.length > 1) {
                html += '<ul class="cpf-list">';
                profile.cpfs_masked.forEach(function(cpf) {
                    html += '<li>' + cpf + '</li>';
                });
                html += '</ul>';
            } else if (profile.cpfs_masked && profile.cpfs_masked.length === 1) {
                html += '<div class="value">' + profile.cpfs_masked[0] + '</div>';
            } else {
                html += '<div class="value">' + (profile.cpf_masked || '-') + '</div>';
            }
            html += '</div>';

            // Audience Groups
            if (profile.audience_groups && profile.audience_groups.length > 0) {
                html += '<div class="ffc-profile-field">';
                html += '<label>' + (ffcDashboard.strings.audienceGroups || 'Groups') + '</label>';
                html += '<div class="value" style="display: flex; flex-wrap: wrap; gap: 6px;">';
                profile.audience_groups.forEach(function(group) {
                    html += '<span style="background-color: ' + (group.color || '#2271b1') + '; color: #fff; padding: 4px 12px; border-radius: 3px; font-size: 13px;">';
                    html += group.name;
                    html += '</span>';
                });
                html += '</div>';
                html += '</div>';
            }

            // Member Since
            html += '<div class="ffc-profile-field">';
            html += '<label>' + ffcDashboard.strings.memberSince + '</label>';
            html += '<div class="value">' + (profile.member_since || '-') + '</div>';
            html += '</div>';

            html += '</div>';

            $container.html(html);
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        if ($('#ffc-user-dashboard').length > 0) {
            FFCDashboard.init();
        }
    });

})(jQuery);
