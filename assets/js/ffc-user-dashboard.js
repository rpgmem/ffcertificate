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
            } else if (tab === 'profile') {
                this.loadProfile();
            }
        },

        /**
         * Load certificates via API
         */
        loadCertificates: function() {
            const $container = $('#tab-certificates');

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

            // Display Name
            html += '<div class="ffc-profile-field">';
            html += '<label>' + ffcDashboard.strings.name + '</label>';
            html += '<div class="value">' + (profile.display_name || '-') + '</div>';
            html += '</div>';

            // Email(s)
            html += '<div class="ffc-profile-field">';
            html += '<label>' + ffcDashboard.strings.linkedEmails + '</label>';
            if (profile.emails && profile.emails.length > 0) {
                html += '<ul class="email-list">';
                profile.emails.forEach(function(email) {
                    html += '<li>' + email + '</li>';
                });
                html += '</ul>';
            } else {
                html += '<div class="value">' + (profile.email || '-') + '</div>';
            }
            html += '</div>';

            // CPF/RF (masked)
            html += '<div class="ffc-profile-field">';
            html += '<label>' + ffcDashboard.strings.cpfRf + '</label>';
            html += '<div class="value">' + (profile.cpf_masked || '-') + '</div>';
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
