/**
 * FFC User Dashboard JavaScript
 * v3.1.0: Standardized to use event delegation pattern
 * @since 3.1.0
 */

(function($) {
    'use strict';

    const FFCDashboard = {

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
        },

        /**
         * Load initial tab content
         */
        loadInitialTab: function() {
            const activeTab = $('.ffc-tab.active').data('tab');
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
            const $button = $(e.currentTarget);
            const tab = $button.data('tab');

            // Update active tab button
            $('.ffc-tab').removeClass('active');
            $button.addClass('active');

            // Update active tab content
            $('.ffc-tab-content').removeClass('active');
            $('#tab-' + tab).addClass('active');

            // Update URL without reload
            if (history.pushState) {
                const url = new URL(window.location);
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
         * Load certificates via API
         */
        loadCertificates: function() {
            const $container = $('#tab-certificates');

            // Check if container exists (permission check)
            if ($container.length === 0) {
                return; // User doesn't have permission
            }

            // Check if user has permission
            if (typeof ffcDashboard.canViewCertificates !== 'undefined' && !ffcDashboard.canViewCertificates) {
                $container.html('<div class="ffc-error">' + ffcDashboard.strings.noPermission + '</div>');
                return;
            }

            // Check if already loaded
            if ($container.find('.ffc-certificates-table').length > 0) {
                return; // Already loaded
            }

            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            // Build URL with viewAsUserId if in admin mode
            let url = ffcDashboard.restUrl + 'user/certificates';
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
                    FFCDashboard.renderCertificates(response.certificates);
                },
                error: function(xhr) {
                    $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>');
                }
            });
        },

        /**
         * Render certificates table
         */
        renderCertificates: function(certificates) {
            const $container = $('#tab-certificates');

            if (!certificates || certificates.length === 0) {
                $container.html(
                    '<div class="ffc-empty-state">' +
                    '<p>📜</p>' +
                    '<p>' + ffcDashboard.strings.noCertificates + '</p>' +
                    '</div>'
                );
                return;
            }

            let html = '<table class="ffc-certificates-table">';
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

            certificates.forEach(function(cert) {
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

            $container.html(html);
        },

        /**
         * Load appointments via API
         */
        loadAppointments: function() {
            const $container = $('#tab-appointments');

            // Check if container exists (permission check)
            if ($container.length === 0) {
                return; // User doesn't have permission
            }

            // Check if user has permission
            if (typeof ffcDashboard.canViewAppointments !== 'undefined' && !ffcDashboard.canViewAppointments) {
                $container.html('<div class="ffc-error">' + ffcDashboard.strings.noPermission + '</div>');
                return;
            }

            // Check if already loaded
            if ($container.find('.ffc-appointments-table').length > 0) {
                return; // Already loaded
            }

            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            // Build URL with viewAsUserId if in admin mode
            let url = ffcDashboard.restUrl + 'user/appointments';
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
                    FFCDashboard.renderAppointments(response.appointments);
                },
                error: function(xhr) {
                    $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>');
                }
            });
        },

        /**
         * Render appointments table
         */
        renderAppointments: function(appointments) {
            const $container = $('#tab-appointments');

            if (!appointments || appointments.length === 0) {
                $container.html(
                    '<div class="ffc-empty-state">' +
                    '<p>📅</p>' +
                    '<p>' + ffcDashboard.strings.noAppointments + '</p>' +
                    '</div>'
                );
                return;
            }

            let html = '<table class="ffc-appointments-table">';
            html += '<thead>';
            html += '<tr>';
            html += '<th>' + ffcDashboard.strings.calendar + '</th>';
            html += '<th>' + ffcDashboard.strings.date + '</th>';
            html += '<th>' + ffcDashboard.strings.time + '</th>';
            html += '<th>' + ffcDashboard.strings.status + '</th>';
            html += '<th>' + ffcDashboard.strings.actions + '</th>';
            html += '</tr>';
            html += '</thead>';
            html += '<tbody>';

            appointments.forEach(function(apt) {
                html += '<tr>';
                html += '<td>' + apt.calendar_title + '</td>';
                html += '<td>' + apt.appointment_date + '</td>';
                html += '<td>' + apt.start_time + '</td>';
                html += '<td><span class="appointment-status status-' + apt.status + '">' + apt.status_label + '</span></td>';
                html += '<td>';

                // Show receipt/print button
                if (apt.receipt_url) {
                    html += '<a href="' + apt.receipt_url + '" class="button" target="_blank" style="margin-right: 5px;">';
                    html += '📄 ' + (ffcDashboard.strings.viewReceipt || 'View Receipt');
                    html += '</a>';
                }

                // Show cancel button only if allowed
                if (apt.can_cancel) {
                    html += '<button class="button ffc-cancel-appointment" data-id="' + apt.id + '">';
                    html += '❌ ' + ffcDashboard.strings.cancelAppointment;
                    html += '</button>';
                }

                html += '</td>';
                html += '</tr>';
            });

            html += '</tbody>';
            html += '</table>';

            $container.html(html);

            // Bind cancel event
            $container.on('click', '.ffc-cancel-appointment', function(e) {
                e.preventDefault();
                const appointmentId = $(this).data('id');
                FFCDashboard.cancelAppointment(appointmentId);
            });
        },

        /**
         * Load audience bookings via API
         */
        loadAudienceBookings: function() {
            const $container = $('#tab-audience');

            // Check if container exists (permission check)
            if ($container.length === 0) {
                return; // User doesn't have permission
            }

            // Check if user has permission
            if (typeof ffcDashboard.canViewAudienceBookings !== 'undefined' && !ffcDashboard.canViewAudienceBookings) {
                $container.html('<div class="ffc-error">' + ffcDashboard.strings.noPermission + '</div>');
                return;
            }

            // Check if already loaded
            if ($container.find('.ffc-audience-bookings-table').length > 0) {
                return; // Already loaded
            }

            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            // Build URL with viewAsUserId if in admin mode
            let url = ffcDashboard.restUrl + 'user/audience-bookings';
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
                    FFCDashboard.renderAudienceBookings(response.bookings);
                },
                error: function(xhr) {
                    $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>');
                }
            });
        },

        /**
         * Render audience bookings table
         */
        renderAudienceBookings: function(bookings) {
            const $container = $('#tab-audience');

            if (!bookings || bookings.length === 0) {
                $container.html(
                    '<div class="ffc-empty-state">' +
                    '<p>👥</p>' +
                    '<p>' + ffcDashboard.strings.noAudienceBookings + '</p>' +
                    '</div>'
                );
                return;
            }

            // Separate upcoming, past, and cancelled bookings
            const upcoming = bookings.filter(b => !b.is_past && b.status !== 'cancelled');
            const past = bookings.filter(b => b.is_past && b.status !== 'cancelled');
            const cancelled = bookings.filter(b => b.status === 'cancelled');

            let html = '';

            // Upcoming bookings
            if (upcoming.length > 0) {
                html += '<h3>' + (ffcDashboard.strings.upcoming || 'Upcoming') + '</h3>';
                html += FFCDashboard.buildAudienceBookingsTable(upcoming);
            }

            // Past bookings
            if (past.length > 0) {
                html += '<h3 style="margin-top: 30px;">' + (ffcDashboard.strings.past || 'Past') + '</h3>';
                html += FFCDashboard.buildAudienceBookingsTable(past, true);
            }

            // Cancelled bookings
            if (cancelled.length > 0) {
                html += '<h3 style="margin-top: 30px;">' + (ffcDashboard.strings.cancelled || 'Cancelled') + '</h3>';
                html += FFCDashboard.buildAudienceBookingsTable(cancelled, true);
            }

            $container.html(html);
        },

        /**
         * Build audience bookings table HTML
         */
        buildAudienceBookingsTable: function(bookings, isPast) {
            let html = '<table class="ffc-audience-bookings-table' + (isPast ? ' past-bookings' : '') + '">';
            html += '<thead>';
            html += '<tr>';
            html += '<th>' + (ffcDashboard.strings.environment || 'Environment') + '</th>';
            html += '<th>' + (ffcDashboard.strings.date || 'Date') + '</th>';
            html += '<th>' + (ffcDashboard.strings.time || 'Time') + '</th>';
            html += '<th>' + (ffcDashboard.strings.description || 'Description') + '</th>';
            html += '<th>' + (ffcDashboard.strings.audiences || 'Audiences') + '</th>';
            html += '</tr>';
            html += '</thead>';
            html += '<tbody>';

            bookings.forEach(function(booking) {
                var rowClass = isPast ? 'past-row' : '';
                if (booking.status === 'cancelled') rowClass = 'cancelled-row';
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
                        html += '<span class="ffc-audience-tag" style="background-color: ' + audience.color + '; color: #fff; padding: 2px 8px; border-radius: 3px; margin-right: 5px; font-size: 12px;">';
                        html += audience.name;
                        html += '</span>';
                    });
                }
                html += '</td>';
                html += '</tr>';
            });

            html += '</tbody>';
            html += '</table>';

            return html;
        },

        /**
         * Cancel appointment
         */
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
                        // Reload appointments
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

        /**
         * Load profile via API
         */
        loadProfile: function() {
            const $container = $('#tab-profile');

            // Check if already loaded
            if ($container.find('.ffc-profile-info').length > 0) {
                return; // Already loaded
            }

            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            // Build URL with viewAsUserId if in admin mode
            let url = ffcDashboard.restUrl + 'user/profile';
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
                error: function(xhr) {
                    $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>');
                }
            });
        },

        /**
         * Render profile information
         */
        renderProfile: function(profile) {
            const $container = $('#tab-profile');

            let html = '<div class="ffc-profile-info">';

            // Name(s) - show all distinct names used in submissions
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

            // CPF/RF (masked) - show all distinct values
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
