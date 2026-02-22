/**
 * FFC User Dashboard JavaScript
 * v4.0.0: Summary cards, filters, password change, LGPD, notes, notifications, configurable pagination
 * v3.2.0: Added client-side pagination and audience groups in profile
 * @since 3.1.0
 */

(function($) {
    'use strict';

    var DEFAULT_PAGE_SIZE = 25;

    // ---- Calendar Export Helpers ----

    var calIcons = {
        google: '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="1" y="3" width="14" height="12" rx="2" fill="#fff" stroke="#4285F4" stroke-width="1.5"/><path d="M1 5h14" stroke="#4285F4" stroke-width="1.5"/><circle cx="5" cy="9" r="1.2" fill="#EA4335"/><circle cx="8" cy="9" r="1.2" fill="#FBBC04"/><circle cx="11" cy="9" r="1.2" fill="#34A853"/><path d="M5 1v3M11 1v3" stroke="#4285F4" stroke-width="1.5" stroke-linecap="round"/></svg>',
        outlook: '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="1" y="3" width="14" height="12" rx="2" fill="#fff" stroke="#0078D4" stroke-width="1.5"/><path d="M1 5h14" stroke="#0078D4" stroke-width="1.5"/><rect x="4" y="7" width="3" height="2.5" rx="0.5" fill="#0078D4"/><rect x="9" y="7" width="3" height="2.5" rx="0.5" fill="#0078D4" opacity="0.5"/><path d="M5 1v3M11 1v3" stroke="#0078D4" stroke-width="1.5" stroke-linecap="round"/></svg>',
        ics: '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 10V3M8 10l-2.5-2.5M8 10l2.5-2.5" stroke="#666" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 12h10" stroke="#666" stroke-width="1.5" stroke-linecap="round"/></svg>',
        calendar: '<svg width="14" height="14" viewBox="0 0 16 16" fill="none"><rect x="1" y="3" width="14" height="12" rx="2" fill="none" stroke="#fff" stroke-width="1.5"/><path d="M1 7h14" stroke="#fff" stroke-width="1.2"/><path d="M5 1v3M11 1v3" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/></svg>'
    };

    function pad2(n) { return n < 10 ? '0' + n : '' + n; }

    function parseTime(timeStr) {
        if (!timeStr) return { h: 0, m: 0 };
        var parts = timeStr.split(':');
        return { h: parseInt(parts[0], 10) || 0, m: parseInt(parts[1], 10) || 0 };
    }

    function toIcsDateTime(dateRaw, timeStr) {
        var d = dateRaw.replace(/-/g, '');
        var t = parseTime(timeStr);
        return d + 'T' + pad2(t.h) + pad2(t.m) + '00';
    }

    function toGoogleDateTime(dateRaw, timeStr) { return toIcsDateTime(dateRaw, timeStr); }

    function toOutlookDateTime(dateRaw, timeStr) {
        var t = parseTime(timeStr);
        return dateRaw + 'T' + pad2(t.h) + ':' + pad2(t.m) + ':00';
    }

    function escapeIcsText(text) {
        if (!text) return '';
        return text.replace(/\\/g, '\\\\').replace(/\r/g, '').replace(/\n/g, '\\n').replace(/,/g, '\\,').replace(/;/g, '\\;');
    }

    function generateICS(event) {
        var domain = window.location.hostname;
        var uid = (event.uid || 'ffc-' + Date.now()) + '@' + domain;
        var dtstamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');

        var ics = 'BEGIN:VCALENDAR\r\n';
        ics += 'VERSION:2.0\r\n';
        ics += 'PRODID:-//' + (ffcDashboard.siteName || domain) + '//FFC//PT\r\n';
        ics += 'CALSCALE:GREGORIAN\r\n';
        ics += 'METHOD:PUBLISH\r\n';
        ics += 'BEGIN:VEVENT\r\n';
        ics += 'UID:' + uid + '\r\n';
        ics += 'DTSTAMP:' + dtstamp + '\r\n';
        ics += 'DTSTART:' + toIcsDateTime(event.date, event.startTime) + '\r\n';
        ics += 'DTEND:' + toIcsDateTime(event.date, event.endTime) + '\r\n';
        ics += 'SUMMARY:' + escapeIcsText(event.summary) + '\r\n';
        if (event.description) { ics += 'DESCRIPTION:' + escapeIcsText(event.description) + '\r\n'; }
        if (event.location) { ics += 'LOCATION:' + escapeIcsText(event.location) + '\r\n'; }
        ics += 'STATUS:CONFIRMED\r\n';
        ics += 'END:VEVENT\r\n';
        ics += 'END:VCALENDAR\r\n';
        return ics;
    }

    function buildGoogleCalendarUrl(event) {
        var dates = toGoogleDateTime(event.date, event.startTime) + '/' + toGoogleDateTime(event.date, event.endTime);
        var params = { action: 'TEMPLATE', text: event.summary, dates: dates, details: event.description || '' };
        if (event.location) { params.location = event.location; }
        if (ffcDashboard.wpTimezone) { params.ctz = ffcDashboard.wpTimezone; }
        var queryParts = [];
        for (var key in params) {
            if (params.hasOwnProperty(key)) { queryParts.push(encodeURIComponent(key) + '=' + encodeURIComponent(params[key])); }
        }
        return 'https://calendar.google.com/calendar/render?' + queryParts.join('&');
    }

    function buildOutlookUrl(event) {
        var params = { rru: 'addevent', subject: event.summary, startdt: toOutlookDateTime(event.date, event.startTime), enddt: toOutlookDateTime(event.date, event.endTime), body: event.description || '', path: '/calendar/action/compose' };
        if (event.location) { params.location = event.location; }
        var queryParts = [];
        for (var key in params) {
            if (params.hasOwnProperty(key)) { queryParts.push(encodeURIComponent(key) + '=' + encodeURIComponent(params[key])); }
        }
        return 'https://outlook.live.com/calendar/0/deeplink/compose?' + queryParts.join('&');
    }

    function downloadIcsFile(event) {
        var icsContent = generateICS(event);
        var blob = new Blob([icsContent], { type: 'text/calendar;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = (event.summary || 'event').replace(/[^a-zA-Z0-9\u00C0-\u024F]/g, '_').substring(0, 50) + '.ics';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function buildCalExportButton(event) {
        var googleUrl = buildGoogleCalendarUrl(event);
        var outlookUrl = buildOutlookUrl(event);
        var eventJson = JSON.stringify(event).replace(/'/g, '&#39;').replace(/"/g, '&quot;');

        var html = '<div class="ffc-cal-export-wrap">';
        html += '<button type="button" class="ffc-cal-export-btn">';
        html += calIcons.calendar + ' ';
        html += (ffcDashboard.strings.exportToCalendar || 'Exportar para Calendario');
        html += ' &#9662;</button>';
        html += '<div class="ffc-cal-export-dropdown">';
        html += '<a href="' + googleUrl + '" target="_blank" rel="noopener noreferrer">' + calIcons.google + ' Google Calendar</a>';
        html += '<a href="' + outlookUrl + '" target="_blank" rel="noopener noreferrer">' + calIcons.outlook + ' Outlook</a>';
        html += '<a href="#" class="ffc-cal-export-ics" data-event="' + eventJson + '">' + calIcons.ics + ' ' + (ffcDashboard.strings.otherIcs || 'Outros (.ics)') + '</a>';
        html += '</div></div>';
        return html;
    }

    // ---- Helpers ----

    function esc(str) { return $('<div>').text(str || '').html(); }

    function getPageSize() {
        var stored = parseInt(localStorage.getItem('ffc_page_size'), 10);
        return (stored && [10, 25, 50].indexOf(stored) !== -1) ? stored : DEFAULT_PAGE_SIZE;
    }

    // ---- Filter bar builder ----

    function buildFilterBar(tabName) {
        var s = ffcDashboard.strings;
        var html = '<div class="ffc-filter-bar" data-tab="' + tabName + '">';
        html += '<label>' + (s.filterFrom || 'From:') + ' <input type="date" class="ffc-filter-from" /></label>';
        html += '<label>' + (s.filterTo || 'To:') + ' <input type="date" class="ffc-filter-to" /></label>';
        html += '<input type="text" class="ffc-filter-search" placeholder="' + (s.filterSearch || 'Search...') + '" />';
        html += '<button type="button" class="button ffc-filter-apply">' + (s.filterApply || 'Filter') + '</button>';
        html += '<button type="button" class="button ffc-filter-clear">' + (s.filterClear || 'Clear') + '</button>';
        html += '</div>';
        return html;
    }

    // ---- Page size selector ----

    function buildPageSizeSelector() {
        var current = getPageSize();
        var s = ffcDashboard.strings;
        var html = '<span class="ffc-page-size-select">' + (s.perPage || 'Per page:') + ' ';
        [10, 25, 50].forEach(function(n) {
            if (n === current) {
                html += '<strong>' + n + '</strong> ';
            } else {
                html += '<a href="#" class="ffc-page-size-btn" data-size="' + n + '">' + n + '</a> ';
            }
        });
        html += '</span>';
        return html;
    }


    var FFCDashboard = {

        init: function() {
            this.bindEvents();
            this.loadSummary();
            this.loadInitialTab();
        },

        bindEvents: function() {
            $(document).on('click', '.ffc-tab', this.switchTab.bind(this));
            $(document).on('keydown', '.ffc-tab', this.handleTabKeydown.bind(this));
            $(document).on('click', '.ffc-pagination-btn', this.handlePagination.bind(this));

            // Calendar export: toggle dropdown
            $(document).on('click', '.ffc-cal-export-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var $dropdown = $(this).siblings('.ffc-cal-export-dropdown');
                $('.ffc-cal-export-dropdown.open').not($dropdown).removeClass('open');
                $dropdown.toggleClass('open');
            });

            // Calendar export: ICS download
            $(document).on('click', '.ffc-cal-export-ics', function(e) {
                e.preventDefault();
                var eventData = $(this).data('event');
                if (eventData) { downloadIcsFile(eventData); }
                $(this).closest('.ffc-cal-export-dropdown').removeClass('open');
            });

            // Close dropdown when clicking outside
            $(document).on('click', function() { $('.ffc-cal-export-dropdown.open').removeClass('open'); });
            $(document).on('click', '.ffc-cal-export-dropdown', function(e) { e.stopPropagation(); });

            // Profile edit/save/cancel
            $(document).on('click', '.ffc-profile-edit-btn', function(e) { e.preventDefault(); FFCDashboard.showProfileEditForm(); });
            $(document).on('click', '.ffc-profile-save-btn', function(e) { e.preventDefault(); FFCDashboard.saveProfile(); });
            $(document).on('click', '.ffc-profile-cancel-btn', function(e) {
                e.preventDefault();
                if (FFCDashboard._profileData) { FFCDashboard.renderProfile(FFCDashboard._profileData); }
            });

            // Password change
            $(document).on('click', '.ffc-password-save-btn', function(e) { e.preventDefault(); FFCDashboard.changePassword(); });
            $(document).on('click', '.ffc-password-toggle-btn', function(e) {
                e.preventDefault();
                $('#tab-profile .ffc-password-form').slideToggle(200);
            });

            // LGPD buttons
            $(document).on('click', '.ffc-lgpd-export-btn', function(e) { e.preventDefault(); FFCDashboard.privacyRequest('export_personal_data'); });
            $(document).on('click', '.ffc-lgpd-delete-btn', function(e) {
                e.preventDefault();
                if (confirm(ffcDashboard.strings.confirmDeletion || 'Are you sure?')) {
                    FFCDashboard.privacyRequest('remove_personal_data');
                }
            });

            // Cancel appointment
            $(document).on('click', '.ffc-cancel-appointment', function(e) {
                e.preventDefault();
                FFCDashboard.cancelAppointment($(this).data('id'));
            });

            // Audience self-join
            $(document).on('click', '.ffc-audience-join-btn', function(e) {
                e.preventDefault();
                var id = $(this).data('id');
                FFCDashboard.joinAudienceGroup(id);
            });
            $(document).on('click', '.ffc-audience-leave-btn', function(e) {
                e.preventDefault();
                var id = $(this).data('id');
                FFCDashboard.leaveAudienceGroup(id);
            });

            // Notification toggles
            $(document).on('change', '.ffc-notif-toggle', function() { FFCDashboard.saveNotificationPreferences(); });

            // Filter apply/clear
            $(document).on('click', '.ffc-filter-apply', function(e) {
                e.preventDefault();
                var tab = $(this).closest('.ffc-filter-bar').data('tab');
                FFCDashboard.applyTabFilter(tab);
            });
            $(document).on('click', '.ffc-filter-clear', function(e) {
                e.preventDefault();
                var $bar = $(this).closest('.ffc-filter-bar');
                $bar.find('input').val('');
                var tab = $bar.data('tab');
                FFCDashboard.applyTabFilter(tab);
            });
            $(document).on('keyup', '.ffc-filter-search', function(e) {
                if (e.key === 'Enter') {
                    var tab = $(this).closest('.ffc-filter-bar').data('tab');
                    FFCDashboard.applyTabFilter(tab);
                }
            });

            // Page size change
            $(document).on('click', '.ffc-page-size-btn', function(e) {
                e.preventDefault();
                var size = parseInt($(this).data('size'), 10);
                localStorage.setItem('ffc_page_size', size);
                // Re-render current visible tab
                var activeTab = $('.ffc-tab.active').data('tab');
                FFCDashboard.applyTabFilter(activeTab);
            });
        },

        // ---- Summary ----

        loadSummary: function() {
            var $summary = $('#ffc-dashboard-summary');
            if ($summary.length === 0) return;

            var url = ffcDashboard.restUrl + 'user/summary';
            if (ffcDashboard.viewAsUserId) { url += '?viewAsUserId=' + ffcDashboard.viewAsUserId; }

            $.ajax({
                url: url,
                method: 'GET',
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function(data) {
                    FFCDashboard.renderSummary(data);
                },
                error: function() {
                    $summary.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>');
                }
            });
        },

        renderSummary: function(data) {
            var $summary = $('#ffc-dashboard-summary');
            var s = ffcDashboard.strings;
            var html = '';

            if (ffcDashboard.canViewCertificates) {
                html += '<div class="ffc-summary-card">';
                html += '<div class="ffc-summary-number">' + (data.total_certificates || 0) + '</div>';
                html += '<div class="ffc-summary-label">' + (s.totalCertificates || 'Certificates') + '</div>';
                html += '</div>';
            }

            if (ffcDashboard.canViewAppointments) {
                html += '<div class="ffc-summary-card">';
                if (data.next_appointment) {
                    html += '<div class="ffc-summary-number ffc-summary-date">' + data.next_appointment.date + '</div>';
                    html += '<div class="ffc-summary-detail">' + data.next_appointment.time + ' &mdash; ' + esc(data.next_appointment.title) + '</div>';
                } else {
                    html += '<div class="ffc-summary-number">&mdash;</div>';
                }
                html += '<div class="ffc-summary-label">' + (s.nextAppointment || 'Next Appointment') + '</div>';
                html += '</div>';
            }

            if (ffcDashboard.canViewAudienceBookings) {
                html += '<div class="ffc-summary-card">';
                html += '<div class="ffc-summary-number">' + (data.upcoming_group_events || 0) + '</div>';
                html += '<div class="ffc-summary-label">' + (s.upcomingGroupEvents || 'Group Events') + '</div>';
                html += '</div>';
            }

            $summary.html(html);
        },

        // ---- Tab navigation ----

        loadInitialTab: function() {
            var activeTab = $('.ffc-tab.active').data('tab');
            if (activeTab === 'certificates') { this.loadCertificates(); }
            else if (activeTab === 'appointments') { this.loadAppointments(); }
            else if (activeTab === 'audience') { this.loadAudienceBookings(); }
            else if (activeTab === 'reregistrations') { this.loadReregistrations(); }
            else if (activeTab === 'profile') { this.loadProfile(); }
        },

        switchTab: function(e) {
            e.preventDefault();
            var $button = $(e.currentTarget);
            var tab = $button.data('tab');

            $('.ffc-tab').removeClass('active').attr('aria-selected', 'false').attr('tabindex', '-1');
            $button.addClass('active').attr('aria-selected', 'true').attr('tabindex', '0');

            $('.ffc-tab-content').removeClass('active');
            $('#tab-' + tab).addClass('active');

            if (history.pushState) {
                var url = new URL(window.location);
                url.searchParams.set('tab', tab);
                history.pushState({}, '', url);
            }

            if (tab === 'certificates') { this.loadCertificates(); }
            else if (tab === 'appointments') { this.loadAppointments(); }
            else if (tab === 'audience') { this.loadAudienceBookings(); }
            else if (tab === 'reregistrations') { this.loadReregistrations(); }
            else if (tab === 'profile') { this.loadProfile(); }
        },

        handleTabKeydown: function(e) {
            var $tabs = $('.ffc-tab');
            var $current = $(e.currentTarget);
            var index = $tabs.index($current);
            var newIndex = -1;

            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); newIndex = (index + 1) % $tabs.length; }
            else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { e.preventDefault(); newIndex = (index - 1 + $tabs.length) % $tabs.length; }
            else if (e.key === 'Home') { e.preventDefault(); newIndex = 0; }
            else if (e.key === 'End') { e.preventDefault(); newIndex = $tabs.length - 1; }

            if (newIndex >= 0) { $tabs.eq(newIndex).focus().trigger('click'); }
        },

        // ---- Filter application ----

        applyTabFilter: function(tab) {
            if (tab === 'certificates' && this._certificatesData) {
                this.renderCertificates(this._certificatesData, 1);
            } else if (tab === 'appointments' && this._appointmentsData) {
                this.renderAppointments(this._appointmentsData, 1);
            } else if (tab === 'audience' && this._audienceData) {
                this.renderAudienceBookings(this._audienceData, 1);
            } else if (tab === 'reregistrations' && this._reregistrationsData) {
                this.renderReregistrations(this._reregistrationsData, 1);
            }
        },

        // ---- Pagination ----

        buildPagination: function(total, page, dataAttr) {
            var pageSize = getPageSize();
            if (total <= pageSize) {
                return '<div class="ffc-pagination">' + buildPageSizeSelector() + '</div>';
            }

            var totalPages = Math.ceil(total / pageSize);
            var html = '<div class="ffc-pagination">';

            if (page > 1) {
                html += '<button class="button ffc-pagination-btn" data-page="' + (page - 1) + '" data-target="' + dataAttr + '">&laquo; ' + (ffcDashboard.strings.previous || 'Previous') + '</button> ';
            }

            html += '<span class="ffc-pagination-info">';
            html += (ffcDashboard.strings.pageOf || 'Page {current} of {total}').replace('{current}', page).replace('{total}', totalPages);
            html += '</span>';

            if (page < totalPages) {
                html += ' <button class="button ffc-pagination-btn" data-page="' + (page + 1) + '" data-target="' + dataAttr + '">' + (ffcDashboard.strings.next || 'Next') + ' &raquo;</button>';
            }

            html += ' ' + buildPageSizeSelector();
            html += '</div>';
            return html;
        },

        handlePagination: function(e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var page = parseInt($btn.data('page'), 10);
            var target = $btn.data('target');

            if (target === 'certificates') { this.renderCertificates(this._certificatesData, page); }
            else if (target === 'appointments') { this.renderAppointments(this._appointmentsData, page); }
            else if (target === 'audience') { this.renderAudienceBookings(this._audienceData, page); }
            else if (target === 'reregistrations') { this.renderReregistrations(this._reregistrationsData, page); }
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
            if (ffcDashboard.viewAsUserId) { url += '?viewAsUserId=' + ffcDashboard.viewAsUserId; }

            $.ajax({
                url: url,
                method: 'GET',
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function(response) {
                    FFCDashboard._certificatesData = response.certificates || [];
                    FFCDashboard.renderCertificates(FFCDashboard._certificatesData, 1);
                },
                error: function() { $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>'); }
            });
        },

        renderCertificates: function(certificates, page) {
            var $container = $('#tab-certificates');
            page = page || 1;
            var pageSize = getPageSize();

            // Filter bar (preserve values)
            var filterHtml = buildFilterBar('certificates');
            var $existing = $container.find('.ffc-filter-bar');
            var fromVal = $existing.find('.ffc-filter-from').val() || '';
            var toVal = $existing.find('.ffc-filter-to').val() || '';
            var searchVal = $existing.find('.ffc-filter-search').val() || '';

            if (!certificates || certificates.length === 0) {
                $container.html(filterHtml + '<div class="ffc-empty-state"><p>' + ffcDashboard.strings.noCertificates + '</p></div>');
                return;
            }

            // Apply filters
            var filtered = certificates;
            if (fromVal || toVal || searchVal) {
                filtered = certificates.filter(function(c) {
                    if (fromVal && c.submission_date_raw < fromVal) return false;
                    if (toVal && c.submission_date_raw > toVal) return false;
                    if (searchVal) {
                        var hay = (c.form_title + ' ' + c.email + ' ' + c.auth_code).toLowerCase();
                        if (hay.indexOf(searchVal.toLowerCase()) === -1) return false;
                    }
                    return true;
                });
            }

            var start = (page - 1) * pageSize;
            var pageItems = filtered.slice(start, start + pageSize);

            var html = filterHtml;
            html += '<table class="ffc-certificates-table">';
            html += '<thead><tr>';
            html += '<th>' + ffcDashboard.strings.eventName + '</th>';
            html += '<th>' + ffcDashboard.strings.date + '</th>';
            html += '<th>' + ffcDashboard.strings.consent + '</th>';
            html += '<th>' + ffcDashboard.strings.email + '</th>';
            html += '<th>' + ffcDashboard.strings.code + '</th>';
            html += '<th>' + ffcDashboard.strings.actions + '</th>';
            html += '</tr></thead><tbody>';

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
                if (cert.magic_link) {
                    html += '<a href="' + cert.magic_link + '" class="button ffc-btn-pdf" target="_blank">' + ffcDashboard.strings.downloadPdf + '</a>';
                }
                html += '</td>';
                html += '</tr>';
            });

            html += '</tbody></table>';
            html += this.buildPagination(filtered.length, page, 'certificates');

            $container.html(html);

            // Restore filter values
            $container.find('.ffc-filter-from').val(fromVal);
            $container.find('.ffc-filter-to').val(toVal);
            $container.find('.ffc-filter-search').val(searchVal);
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
            if (ffcDashboard.viewAsUserId) { url += '?viewAsUserId=' + ffcDashboard.viewAsUserId; }

            $.ajax({
                url: url,
                method: 'GET',
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function(response) {
                    FFCDashboard._appointmentsData = response.appointments || [];
                    FFCDashboard.renderAppointments(FFCDashboard._appointmentsData, 1);
                },
                error: function() { $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>'); }
            });
        },

        renderAppointments: function(appointments, page) {
            var $container = $('#tab-appointments');
            page = page || 1;
            var pageSize = getPageSize();

            var filterHtml = buildFilterBar('appointments');
            var $existing = $container.find('.ffc-filter-bar');
            var fromVal = $existing.find('.ffc-filter-from').val() || '';
            var toVal = $existing.find('.ffc-filter-to').val() || '';
            var searchVal = $existing.find('.ffc-filter-search').val() || '';

            if (!appointments || appointments.length === 0) {
                $container.html(filterHtml + '<div class="ffc-empty-state"><p>' + ffcDashboard.strings.noAppointments + '</p></div>');
                return;
            }

            // Filter
            var filtered = appointments;
            if (fromVal || toVal || searchVal) {
                filtered = appointments.filter(function(a) {
                    if (fromVal && a.appointment_date_raw < fromVal) return false;
                    if (toVal && a.appointment_date_raw > toVal) return false;
                    if (searchVal) {
                        var hay = (a.calendar_title + ' ' + a.status_label).toLowerCase();
                        if (hay.indexOf(searchVal.toLowerCase()) === -1) return false;
                    }
                    return true;
                });
            }

            // Separate into sections
            var today = new Date().toISOString().slice(0, 10);
            var upcoming = [], past = [], cancelled = [];
            filtered.forEach(function(apt) {
                if (apt.status === 'cancelled') { cancelled.push(apt); }
                else if (apt.appointment_date_raw < today || apt.status === 'completed' || apt.status === 'no_show') { past.push(apt); }
                else { upcoming.push(apt); }
            });

            var allOrdered = [];
            upcoming.forEach(function(b) { b._section = 'upcoming'; allOrdered.push(b); });
            past.forEach(function(b) { b._section = 'past'; allOrdered.push(b); });
            cancelled.forEach(function(b) { b._section = 'cancelled'; allOrdered.push(b); });

            var start = (page - 1) * pageSize;
            var pageItems = allOrdered.slice(start, start + pageSize);

            var html = filterHtml;
            var currentSection = '';

            pageItems.forEach(function(apt) {
                var section = apt._section;

                if (section !== currentSection) {
                    if (currentSection !== '') { html += '</tbody></table>'; }
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
                html += '<td>' + apt.calendar_title + '</td>';
                html += '<td>' + apt.appointment_date + '</td>';
                html += '<td>' + apt.start_time + '</td>';
                html += '<td><span class="appointment-status status-' + apt.status + '">' + apt.status_label + '</span></td>';
                html += '<td>';

                if (apt.receipt_url) {
                    html += '<a href="' + apt.receipt_url + '" class="button ffc-btn-receipt" target="_blank">' + (ffcDashboard.strings.viewReceipt || 'View Receipt') + '</a>';
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
                        endTime: apt.end_time || apt.start_time_raw
                    };
                    html += ' ' + buildCalExportButton(aptEvent);
                }

                html += '</td></tr>';
            });

            if (currentSection !== '') { html += '</tbody></table>'; }
            html += this.buildPagination(allOrdered.length, page, 'appointments');

            $container.html(html);

            // Restore filter values
            $container.find('.ffc-filter-from').val(fromVal);
            $container.find('.ffc-filter-to').val(toVal);
            $container.find('.ffc-filter-search').val(searchVal);

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
            if (ffcDashboard.viewAsUserId) { url += '?viewAsUserId=' + ffcDashboard.viewAsUserId; }

            $.ajax({
                url: url,
                method: 'GET',
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function(response) {
                    FFCDashboard._audienceData = response.bookings || [];
                    FFCDashboard.renderAudienceBookings(FFCDashboard._audienceData, 1);
                },
                error: function() { $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>'); }
            });
        },

        renderAudienceBookings: function(bookings, page) {
            var $container = $('#tab-audience');
            page = page || 1;
            var pageSize = getPageSize();

            var filterHtml = buildFilterBar('audience');
            var $existing = $container.find('.ffc-filter-bar');
            var fromVal = $existing.find('.ffc-filter-from').val() || '';
            var toVal = $existing.find('.ffc-filter-to').val() || '';
            var searchVal = $existing.find('.ffc-filter-search').val() || '';

            if (!bookings || bookings.length === 0) {
                $container.html(filterHtml + '<div class="ffc-empty-state"><p>' + ffcDashboard.strings.noAudienceBookings + '</p></div>');
                return;
            }

            // Filter
            var filtered = bookings;
            if (fromVal || toVal || searchVal) {
                filtered = bookings.filter(function(b) {
                    if (fromVal && b.booking_date_raw < fromVal) return false;
                    if (toVal && b.booking_date_raw > toVal) return false;
                    if (searchVal) {
                        var hay = (b.environment_name + ' ' + b.schedule_name + ' ' + b.description).toLowerCase();
                        if (hay.indexOf(searchVal.toLowerCase()) === -1) return false;
                    }
                    return true;
                });
            }

            var upcoming = filtered.filter(function(b) { return !b.is_past && b.status !== 'cancelled'; });
            var past = filtered.filter(function(b) { return b.is_past && b.status !== 'cancelled'; });
            var cancelled = filtered.filter(function(b) { return b.status === 'cancelled'; });

            var allOrdered = [].concat(upcoming, past, cancelled);
            var start = (page - 1) * pageSize;
            var pageItems = allOrdered.slice(start, start + pageSize);

            var html = filterHtml;
            var currentSection = '';

            pageItems.forEach(function(booking) {
                var section;
                if (booking.status === 'cancelled') { section = 'cancelled'; }
                else if (booking.is_past) { section = 'past'; }
                else { section = 'upcoming'; }

                if (section !== currentSection) {
                    if (currentSection !== '') { html += '</tbody></table>'; }
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
                if (booking.schedule_name) { html += '<br><small style="color: #666;">' + booking.schedule_name + '</small>'; }
                html += '</td>';
                html += '<td>' + booking.booking_date + '</td>';
                html += '<td>' + booking.start_time + ' - ' + booking.end_time + '</td>';
                html += '<td>' + (booking.description || '') + '</td>';
                html += '<td>';
                if (booking.audiences && booking.audiences.length > 0) {
                    booking.audiences.forEach(function(audience) {
                        html += '<span class="ffc-audience-tag" style="background-color: ' + esc(audience.color) + '; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 12px;">' + esc(audience.name) + '</span>';
                    });
                }
                html += '</td>';

                html += '<td>';
                if (section === 'upcoming' && booking.status !== 'cancelled') {
                    var audienceNames = booking.audiences ? booking.audiences.map(function(a) { return a.name; }).join(', ') : '';
                    var bookingEvent = {
                        uid: 'ffc-booking-' + booking.id,
                        summary: (booking.description || booking.environment_name),
                        description: (booking.environment_name ? (ffcDashboard.strings.environment || 'Ambiente') + ': ' + booking.environment_name : '') + (booking.schedule_name ? '\n' + booking.schedule_name : '') + (booking.description ? '\n' + booking.description : '') + (audienceNames ? '\n' + (ffcDashboard.strings.audiences || 'Audiencias') + ': ' + audienceNames : ''),
                        location: booking.environment_name || '',
                        date: booking.booking_date_raw,
                        startTime: booking.start_time,
                        endTime: booking.end_time || booking.start_time
                    };
                    html += buildCalExportButton(bookingEvent);
                }
                html += '</td></tr>';
            });

            if (currentSection !== '') { html += '</tbody></table>'; }
            html += this.buildPagination(allOrdered.length, page, 'audience');

            $container.html(html);

            $container.find('.ffc-filter-from').val(fromVal);
            $container.find('.ffc-filter-to').val(toVal);
            $container.find('.ffc-filter-search').val(searchVal);
        },

        // ---- Reregistrations ----

        _reregistrationsData: null,

        loadReregistrations: function() {
            var $container = $('#tab-reregistrations');
            if ($container.length === 0) return;

            if (typeof ffcDashboard.canViewReregistrations !== 'undefined' && !ffcDashboard.canViewReregistrations) {
                $container.html('<div class="ffc-error">' + ffcDashboard.strings.noPermission + '</div>');
                return;
            }

            if (this._reregistrationsData !== null) return;

            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            var url = ffcDashboard.restUrl + 'user/reregistrations';
            if (ffcDashboard.viewAsUserId) { url += '?viewAsUserId=' + ffcDashboard.viewAsUserId; }

            $.ajax({
                url: url,
                method: 'GET',
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function(response) {
                    FFCDashboard._reregistrationsData = response.reregistrations || [];
                    FFCDashboard.renderReregistrations(FFCDashboard._reregistrationsData, 1);
                },
                error: function() { $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>'); }
            });
        },

        renderReregistrations: function(items, page) {
            var $container = $('#tab-reregistrations');
            page = page || 1;
            var pageSize = getPageSize();
            var s = ffcDashboard.strings;

            var filterHtml = buildFilterBar('reregistrations');
            var $existing = $container.find('.ffc-filter-bar');
            var fromVal = $existing.find('.ffc-filter-from').val() || '';
            var toVal = $existing.find('.ffc-filter-to').val() || '';
            var searchVal = $existing.find('.ffc-filter-search').val() || '';

            if (!items || items.length === 0) {
                $container.html(filterHtml + '<div class="ffc-empty-state"><p>' + (s.noReregistrations || 'No reregistrations found.') + '</p></div>');
                return;
            }

            // Filter
            var filtered = items;
            if (fromVal || toVal || searchVal) {
                filtered = items.filter(function(r) {
                    if (fromVal && r.start_date < fromVal) return false;
                    if (toVal && r.end_date > toVal) return false;
                    if (searchVal) {
                        var hay = (r.title + ' ' + r.status_label + ' ' + (r.auth_code || '')).toLowerCase();
                        if (hay.indexOf(searchVal.toLowerCase()) === -1) return false;
                    }
                    return true;
                });
            }

            // Separate active vs completed
            var active = filtered.filter(function(r) { return r.is_active; });
            var completed = filtered.filter(function(r) { return !r.is_active; });
            var allOrdered = [];
            active.forEach(function(r) { r._section = 'active'; allOrdered.push(r); });
            completed.forEach(function(r) { r._section = 'completed'; allOrdered.push(r); });

            var start = (page - 1) * pageSize;
            var pageItems = allOrdered.slice(start, start + pageSize);

            var html = filterHtml;
            var currentSection = '';

            pageItems.forEach(function(item) {
                var section = item._section;

                if (section !== currentSection) {
                    if (currentSection !== '') { html += '</tbody></table>'; }
                    currentSection = section;

                    var sectionLabel = section === 'active'
                        ? (s.active || 'Active')
                        : (s.completed || 'Completed');

                    html += '<h3' + (section !== 'active' ? ' style="margin-top: 30px;"' : '') + '>' + sectionLabel + '</h3>';
                    html += '<table class="ffc-reregistrations-table' + (section !== 'active' ? ' past-reregistrations' : '') + '">';
                    html += '<thead><tr>';
                    html += '<th>' + (s.reregistrationTitle || 'Campaign') + '</th>';
                    html += '<th>' + (s.period || 'Period') + '</th>';
                    html += '<th>' + (s.status || 'Status') + '</th>';
                    html += '<th>' + (s.submittedAt || 'Submitted') + '</th>';
                    html += '<th>' + (s.validationCode || 'Validation Code') + '</th>';
                    html += '<th>' + (s.actions || 'Actions') + '</th>';
                    html += '</tr></thead><tbody>';
                }

                var rowClass = section !== 'active' ? ' class="past-row"' : '';
                html += '<tr' + rowClass + '>';
                html += '<td>' + esc(item.title) + '</td>';
                html += '<td>' + esc(item.start_date_formatted) + ' &mdash; ' + esc(item.end_date_formatted) + '</td>';
                html += '<td><span class="appointment-status status-' + item.status + '">' + esc(item.status_label) + '</span></td>';
                html += '<td>' + esc(item.submitted_at || '—') + '</td>';
                html += '<td>';
                if (item.auth_code) {
                    html += '<code class="ffc-auth-code">' + esc(item.auth_code) + '</code>';
                } else {
                    html += '—';
                }
                html += '</td>';
                html += '<td>';
                if (item.can_submit) {
                    html += '<button type="button" class="button ffc-btn-edit ffc-rereg-open-form" data-reregistration-id="' + item.reregistration_id + '">' + (s.editReregistration || 'Edit') + '</button> ';
                }
                if (item.can_download && item.magic_link) {
                    html += '<a href="' + esc(item.magic_link) + '" class="button ffc-btn-pdf" target="_blank" rel="noopener">' + (s.downloadFicha || 'Download Ficha') + '</a>';
                }
                html += '</td>';
                html += '</tr>';
            });

            if (currentSection !== '') { html += '</tbody></table>'; }
            html += this.buildPagination(allOrdered.length, page, 'reregistrations');

            $container.html(html);

            // Restore filter values
            $container.find('.ffc-filter-from').val(fromVal);
            $container.find('.ffc-filter-to').val(toVal);
            $container.find('.ffc-filter-search').val(searchVal);
        },

        // ---- Cancel Appointment ----

        cancelAppointment: function(appointmentId) {
            if (!confirm(ffcDashboard.strings.confirmCancel)) return;

            var data = { action: 'ffc_cancel_appointment', appointment_id: appointmentId, nonce: ffcDashboard.schedulingNonce };
            if (ffcDashboard.viewAsUserId) { data.viewAsUserId = ffcDashboard.viewAsUserId; }

            $.ajax({
                url: ffcDashboard.ajaxUrl,
                method: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        alert(ffcDashboard.strings.cancelSuccess);
                        FFCDashboard._appointmentsData = null;
                        $('#tab-appointments').html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');
                        FFCDashboard.loadAppointments();
                    } else {
                        alert(response.data.message || ffcDashboard.strings.cancelError);
                    }
                },
                error: function() { alert(ffcDashboard.strings.cancelError); }
            });
        },

        // ---- Profile ----

        loadProfile: function() {
            var $container = $('#tab-profile');
            if ($container.find('.ffc-profile-info').length > 0) return;

            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            var url = ffcDashboard.restUrl + 'user/profile';
            if (ffcDashboard.viewAsUserId) { url += '?viewAsUserId=' + ffcDashboard.viewAsUserId; }

            $.ajax({
                url: url,
                method: 'GET',
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function(response) { FFCDashboard.renderProfile(response); },
                error: function() { $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>'); }
            });
        },

        _profileData: null,

        renderProfile: function(profile) {
            var $container = $('#tab-profile');
            this._profileData = profile;
            var s = ffcDashboard.strings;

            var html = '<div class="ffc-profile-info">';

            // Name(s)
            html += '<div class="ffc-profile-field">';
            html += '<label>' + s.name + '</label>';
            if (profile.names && profile.names.length > 1) {
                html += '<ul class="name-list">';
                profile.names.forEach(function(name) { html += '<li>' + esc(name) + '</li>'; });
                html += '</ul>';
            } else if (profile.names && profile.names.length === 1) {
                html += '<div class="value">' + esc(profile.names[0]) + '</div>';
            } else {
                html += '<div class="value">' + esc(profile.display_name || '-') + '</div>';
            }
            html += '</div>';

            // Email(s)
            html += '<div class="ffc-profile-field">';
            html += '<label>' + s.linkedEmails + '</label>';
            if (profile.emails && profile.emails.length > 1) {
                html += '<ul class="email-list">';
                profile.emails.forEach(function(email) { html += '<li>' + esc(email) + '</li>'; });
                html += '</ul>';
            } else if (profile.emails && profile.emails.length === 1) {
                html += '<div class="value">' + esc(profile.emails[0]) + '</div>';
            } else {
                html += '<div class="value">' + esc(profile.email || '-') + '</div>';
            }
            html += '</div>';

            // CPF/RF
            html += '<div class="ffc-profile-field">';
            html += '<label>' + s.cpfRf + '</label>';
            if (profile.cpfs_masked && profile.cpfs_masked.length > 1) {
                html += '<ul class="cpf-list">';
                profile.cpfs_masked.forEach(function(cpf) { html += '<li>' + esc(cpf) + '</li>'; });
                html += '</ul>';
            } else if (profile.cpfs_masked && profile.cpfs_masked.length === 1) {
                html += '<div class="value">' + esc(profile.cpfs_masked[0]) + '</div>';
            } else {
                html += '<div class="value">' + esc(profile.cpf_masked || '-') + '</div>';
            }
            html += '</div>';

            // Phone
            html += '<div class="ffc-profile-field"><label>' + (s.phone || 'Phone:') + '</label>';
            html += '<div class="value">' + esc(profile.phone || '-') + '</div></div>';

            // Department
            html += '<div class="ffc-profile-field"><label>' + (s.department || 'Department:') + '</label>';
            html += '<div class="value">' + esc(profile.department || '-') + '</div></div>';

            // Organization
            html += '<div class="ffc-profile-field"><label>' + (s.organization || 'Organization:') + '</label>';
            html += '<div class="value">' + esc(profile.organization || '-') + '</div></div>';

            // Notes
            if (profile.notes) {
                html += '<div class="ffc-profile-field"><label>' + (s.notesLabel || 'Notes:') + '</label>';
                html += '<div class="value">' + esc(profile.notes) + '</div></div>';
            }

            // Audience Groups
            if (profile.audience_groups && profile.audience_groups.length > 0) {
                html += '<div class="ffc-profile-field">';
                html += '<label>' + (s.audienceGroups || 'Groups') + '</label>';
                html += '<div class="value" style="display: flex; flex-wrap: wrap; gap: 6px;">';
                profile.audience_groups.forEach(function(group) {
                    html += '<span style="background-color: ' + esc(group.color || '#2271b1') + '; color: #fff; padding: 4px 12px; border-radius: 3px; font-size: 13px;">' + esc(group.name) + '</span>';
                });
                html += '</div></div>';
            }

            // Member Since
            html += '<div class="ffc-profile-field"><label>' + s.memberSince + '</label>';
            html += '<div class="value">' + esc(profile.member_since || '-') + '</div></div>';

            html += '</div>'; // .ffc-profile-info

            // Action buttons bar
            html += '<div class="ffc-profile-actions">';
            html += '<button type="button" class="button button-primary ffc-profile-edit-btn">' + (s.editProfile || 'Edit Profile') + '</button>';
            html += '<button type="button" class="button ffc-password-toggle-btn">' + (s.changePassword || 'Change Password') + '</button>';
            html += '</div>';

            // ---- Password form (hidden until toggle) ----
            html += '<div class="ffc-password-form" style="display: none;">';
            html += '<div class="ffc-profile-field"><label for="ffc-current-password">' + (s.currentPassword || 'Current Password') + '</label>';
            html += '<input type="password" id="ffc-current-password" autocomplete="current-password" /></div>';
            html += '<div class="ffc-profile-field"><label for="ffc-new-password">' + (s.newPassword || 'New Password') + '</label>';
            html += '<input type="password" id="ffc-new-password" autocomplete="new-password" minlength="8" /></div>';
            html += '<div class="ffc-profile-field"><label for="ffc-confirm-password">' + (s.confirmPassword || 'Confirm New Password') + '</label>';
            html += '<input type="password" id="ffc-confirm-password" autocomplete="new-password" /></div>';
            html += '<button type="button" class="button button-primary ffc-password-save-btn">' + (s.save || 'Save') + '</button>';
            html += '<span class="ffc-password-status" style="margin-left: 10px;"></span>';
            html += '</div>';

            // ---- Audience self-join (loaded async) ----
            html += '<div class="ffc-audience-join-section" id="ffc-audience-join-section"></div>';
            setTimeout(function() { FFCDashboard.loadJoinableGroups(); }, 0);

            // ---- Notification preferences ----
            var prefs = profile.preferences || {};
            html += '<div class="ffc-profile-section">';
            html += '<h3>' + (s.notificationSection || 'Notification Preferences') + '</h3>';
            html += '<div class="ffc-notif-list">';
            html += this._buildToggle('notify_appointment_confirm', s.notifAppointmentConfirm || 'Appointment confirmation', prefs);
            html += this._buildToggle('notify_appointment_reminder', s.notifAppointmentReminder || 'Appointment reminder', prefs);
            html += this._buildToggle('notify_new_certificate', s.notifNewCertificate || 'New certificate issued', prefs);
            html += '</div>';
            html += '<span class="ffc-notif-status" style="margin-left: 10px; color: #28a745; display: none;"></span>';
            html += '</div>';

            // ---- LGPD section ----
            html += '<div class="ffc-profile-section ffc-lgpd-section">';
            html += '<h3>' + (s.privacySection || 'Privacy & Data (LGPD)') + '</h3>';
            html += '<p class="ffc-lgpd-desc">' + (s.exportDataDesc || 'Request a copy of all your personal data stored in the system.') + '</p>';
            html += '<button type="button" class="button ffc-lgpd-export-btn">' + (s.exportData || 'Export My Data') + '</button>';
            html += '<p class="ffc-lgpd-desc" style="margin-top: 20px;">' + (s.deletionDataDesc || 'Request deletion of your personal data. An administrator will review your request.') + '</p>';
            html += '<button type="button" class="button ffc-lgpd-delete-btn">' + (s.requestDeletion || 'Request Data Deletion') + '</button>';
            html += '<span class="ffc-lgpd-status" style="margin-left: 10px; display: none;"></span>';
            html += '</div>';

            $container.html(html);
        },

        _buildToggle: function(key, label, prefs) {
            var checked = prefs[key] ? ' checked' : '';
            return '<label class="ffc-toggle-label">' +
                '<input type="checkbox" class="ffc-notif-toggle" data-key="' + key + '"' + checked + ' />' +
                '<span class="ffc-toggle-switch"></span>' +
                '<span>' + label + '</span></label>';
        },

        showProfileEditForm: function() {
            var $container = $('#tab-profile');
            var profile = this._profileData;
            if (!profile) return;

            var html = '<div class="ffc-profile-edit-form">';
            html += '<h3>' + (ffcDashboard.strings.editProfile || 'Edit Profile') + '</h3>';

            html += '<div class="ffc-profile-field"><label for="ffc-edit-display-name">' + ffcDashboard.strings.name + '</label>';
            html += '<input type="text" id="ffc-edit-display-name" value="' + esc(profile.display_name) + '" maxlength="250" /></div>';

            html += '<div class="ffc-profile-field"><label for="ffc-edit-phone">' + (ffcDashboard.strings.phone || 'Phone:') + '</label>';
            html += '<input type="tel" id="ffc-edit-phone" value="' + esc(profile.phone) + '" maxlength="50" /></div>';

            html += '<div class="ffc-profile-field"><label for="ffc-edit-department">' + (ffcDashboard.strings.department || 'Department:') + '</label>';
            html += '<input type="text" id="ffc-edit-department" value="' + esc(profile.department) + '" maxlength="250" /></div>';

            html += '<div class="ffc-profile-field"><label for="ffc-edit-organization">' + (ffcDashboard.strings.organization || 'Organization:') + '</label>';
            html += '<input type="text" id="ffc-edit-organization" value="' + esc(profile.organization) + '" maxlength="250" /></div>';

            html += '<div class="ffc-profile-field"><label for="ffc-edit-notes">' + (ffcDashboard.strings.notesLabel || 'Notes:') + '</label>';
            html += '<textarea id="ffc-edit-notes" rows="3" maxlength="1000" placeholder="' + (ffcDashboard.strings.notesPlaceholder || 'Personal notes...') + '">' + esc(profile.notes) + '</textarea></div>';

            html += '<div class="ffc-profile-edit-actions" style="margin-top: 15px;">';
            html += '<button type="button" class="button button-primary ffc-profile-save-btn">' + (ffcDashboard.strings.save || 'Save') + '</button> ';
            html += '<button type="button" class="button ffc-profile-cancel-btn">' + (ffcDashboard.strings.cancel || 'Cancel') + '</button>';
            html += '<span class="ffc-profile-save-status" style="margin-left: 10px; display: none;"></span>';
            html += '</div></div>';

            $container.html(html);
        },

        saveProfile: function() {
            var data = {
                display_name: $('#ffc-edit-display-name').val(),
                phone: $('#ffc-edit-phone').val(),
                department: $('#ffc-edit-department').val(),
                organization: $('#ffc-edit-organization').val(),
                notes: $('#ffc-edit-notes').val()
            };

            var $saveBtn = $('.ffc-profile-save-btn');
            var $status = $('.ffc-profile-save-status');
            $saveBtn.prop('disabled', true);
            $status.text(ffcDashboard.strings.saving || 'Saving...').show().css('color', '#666');

            var url = ffcDashboard.restUrl + 'user/profile';
            if (ffcDashboard.viewAsUserId) { url += '?viewAsUserId=' + ffcDashboard.viewAsUserId; }

            $.ajax({
                url: url,
                method: 'PUT',
                contentType: 'application/json',
                data: JSON.stringify(data),
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function(response) {
                    FFCDashboard._profileData = null;
                    FFCDashboard.renderProfile(response);
                },
                error: function(xhr) {
                    var msg = (ffcDashboard.strings.saveError || 'Error saving profile');
                    if (xhr.responseJSON && xhr.responseJSON.message) { msg = xhr.responseJSON.message; }
                    $status.text(msg).css('color', '#d63638');
                    $saveBtn.prop('disabled', false);
                }
            });
        },

        // ---- Audience self-join ----

        loadJoinableGroups: function() {
            var $section = $('#ffc-audience-join-section');
            if ($section.length === 0) return;

            var url = ffcDashboard.restUrl + 'user/joinable-groups';
            if (ffcDashboard.viewAsUserId) { url += '?viewAsUserId=' + ffcDashboard.viewAsUserId; }

            $.ajax({
                url: url,
                method: 'GET',
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function(data) {
                    FFCDashboard.renderJoinableGroups(data);
                },
                error: function() {
                    $section.empty();
                }
            });
        },

        renderJoinableGroups: function(data) {
            var $section = $('#ffc-audience-join-section');
            var parents = data.parents || [];

            if (parents.length === 0) {
                $section.empty();
                return;
            }

            var s = ffcDashboard.strings;
            var html = '<h3>' + (s.joinGroups || 'Join Groups') + '</h3>';
            html += '<p class="ffc-audience-limit-msg">' +
                (s.joinGroupsDesc || 'Select up to {max} groups to participate in collective calendars.')
                    .replace('{max}', data.max_groups) +
                '</p>';

            parents.forEach(function(parent) {
                html += '<div class="ffc-audience-parent-group">';
                html += '<div class="ffc-audience-parent-header">';
                html += '<span class="ffc-audience-dot" style="background-color: ' + (parent.color || '#2271b1') + ';"></span>';
                html += '<span>' + esc(parent.name) + '</span>';
                html += '</div>';
                html += '<div class="ffc-audience-children-list">';

                (parent.children || []).forEach(function(child) {
                    html += '<div class="ffc-audience-join-item' + (child.is_member ? ' is-member' : '') + '" data-group-id="' + child.id + '">';
                    html += '<span class="ffc-audience-name">';
                    html += '<span class="ffc-audience-dot" style="background-color: ' + (child.color || parent.color || '#2271b1') + ';"></span>';
                    html += esc(child.name);
                    html += '</span>';

                    if (child.is_member) {
                        html += '<button type="button" class="button ffc-audience-leave-btn" data-id="' + child.id + '">' + (s.leaveGroup || 'Leave') + '</button>';
                    } else {
                        var disabled = (data.joined_count >= data.max_groups) ? ' disabled' : '';
                        html += '<button type="button" class="button button-primary ffc-audience-join-btn" data-id="' + child.id + '"' + disabled + '>' + (s.joinGroup || 'Join') + '</button>';
                    }
                    html += '</div>';
                });

                html += '</div></div>';
            });

            $section.html(html);
        },

        joinAudienceGroup: function(groupId) {
            var $btn = $('.ffc-audience-join-btn[data-id="' + groupId + '"]');
            $btn.prop('disabled', true).text(ffcDashboard.strings.saving || 'Saving...');

            var url = ffcDashboard.restUrl + 'user/audience-group/join';
            if (ffcDashboard.viewAsUserId) { url += '?viewAsUserId=' + ffcDashboard.viewAsUserId; }

            $.ajax({
                url: url,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ group_id: groupId }),
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function() {
                    FFCDashboard.reloadProfile();
                },
                error: function(xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) || (ffcDashboard.strings.error || 'Error');
                    alert(msg);
                    $btn.prop('disabled', false).text(ffcDashboard.strings.joinGroup || 'Join');
                }
            });
        },

        leaveAudienceGroup: function(groupId) {
            if (!confirm(ffcDashboard.strings.confirmLeaveGroup || 'Leave this group?')) return;

            var $btn = $('.ffc-audience-leave-btn[data-id="' + groupId + '"]');
            $btn.prop('disabled', true).text(ffcDashboard.strings.saving || 'Saving...');

            var url = ffcDashboard.restUrl + 'user/audience-group/leave';
            if (ffcDashboard.viewAsUserId) { url += '?viewAsUserId=' + ffcDashboard.viewAsUserId; }

            $.ajax({
                url: url,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ group_id: groupId }),
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function() {
                    FFCDashboard.reloadProfile();
                },
                error: function(xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) || (ffcDashboard.strings.error || 'Error');
                    alert(msg);
                    $btn.prop('disabled', false).text(ffcDashboard.strings.leaveGroup || 'Leave');
                }
            });
        },

        /**
         * Force-reload profile (bypasses the cache guard in loadProfile)
         */
        reloadProfile: function() {
            this._profileData = null;
            var $container = $('#tab-profile');
            $container.html('<div class="ffc-loading">' + ffcDashboard.strings.loading + '</div>');

            var url = ffcDashboard.restUrl + 'user/profile';
            if (ffcDashboard.viewAsUserId) { url += '?viewAsUserId=' + ffcDashboard.viewAsUserId; }

            $.ajax({
                url: url,
                method: 'GET',
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function(response) { FFCDashboard.renderProfile(response); },
                error: function() { $container.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>'); }
            });
        },

        // ---- Password change ----

        changePassword: function() {
            var s = ffcDashboard.strings;
            var current = $('#ffc-current-password').val();
            var newPwd = $('#ffc-new-password').val();
            var confirmPwd = $('#ffc-confirm-password').val();
            var $status = $('.ffc-password-status');

            if (!current || !newPwd || !confirmPwd) {
                $status.text(s.passwordError || 'All fields required').css('color', '#d63638');
                return;
            }

            if (newPwd !== confirmPwd) {
                $status.text(s.passwordMismatch || 'Passwords do not match').css('color', '#d63638');
                return;
            }

            if (newPwd.length < 8) {
                $status.text(s.passwordTooShort || 'Min 8 characters').css('color', '#d63638');
                return;
            }

            var $btn = $('.ffc-password-save-btn');
            $btn.prop('disabled', true);
            $status.text(ffcDashboard.strings.saving || 'Saving...').css('color', '#666');

            var url = ffcDashboard.restUrl + 'user/change-password';
            if (ffcDashboard.viewAsUserId) { url += '?viewAsUserId=' + ffcDashboard.viewAsUserId; }

            $.ajax({
                url: url,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ current_password: current, new_password: newPwd }),
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function(response) {
                    $status.text(response.message || s.passwordChanged || 'Password changed!').css('color', '#28a745');
                    $('#ffc-current-password, #ffc-new-password, #ffc-confirm-password').val('');
                    $btn.prop('disabled', false);
                },
                error: function(xhr) {
                    var msg = s.passwordError || 'Error';
                    if (xhr.responseJSON && xhr.responseJSON.message) { msg = xhr.responseJSON.message; }
                    $status.text(msg).css('color', '#d63638');
                    $btn.prop('disabled', false);
                }
            });
        },

        // ---- LGPD ----

        privacyRequest: function(type) {
            var $status = $('.ffc-lgpd-status');
            $status.text(ffcDashboard.strings.loading || 'Loading...').css('color', '#666').show();

            var url = ffcDashboard.restUrl + 'user/privacy-request';
            if (ffcDashboard.viewAsUserId) { url += '?viewAsUserId=' + ffcDashboard.viewAsUserId; }

            $.ajax({
                url: url,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ type: type }),
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function(response) {
                    $status.text(response.message || ffcDashboard.strings.privacyRequestSent || 'Request sent!').css('color', '#28a745');
                },
                error: function(xhr) {
                    var msg = ffcDashboard.strings.privacyRequestError || 'Error';
                    if (xhr.responseJSON && xhr.responseJSON.message) { msg = xhr.responseJSON.message; }
                    $status.text(msg).css('color', '#d63638');
                }
            });
        },

        // ---- Notification preferences ----

        saveNotificationPreferences: function() {
            var prefs = {};
            $('.ffc-notif-toggle').each(function() {
                prefs[$(this).data('key')] = $(this).is(':checked');
            });

            var $status = $('.ffc-notif-status');

            var url = ffcDashboard.restUrl + 'user/profile';
            if (ffcDashboard.viewAsUserId) { url += '?viewAsUserId=' + ffcDashboard.viewAsUserId; }

            $.ajax({
                url: url,
                method: 'PUT',
                contentType: 'application/json',
                data: JSON.stringify({ preferences: prefs }),
                beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', ffcDashboard.nonce); },
                success: function(response) {
                    if (response.preferences) {
                        FFCDashboard._profileData.preferences = response.preferences;
                    }
                    $status.text(ffcDashboard.strings.notifSaved || 'Saved').show();
                    setTimeout(function() { $status.fadeOut(); }, 2000);
                },
                error: function() {
                    $status.text(ffcDashboard.strings.saveError || 'Error').css('color', '#d63638').show();
                }
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        if ($('#ffc-user-dashboard').length > 0) {
            FFCDashboard.init();
        }
    });

})(jQuery);
