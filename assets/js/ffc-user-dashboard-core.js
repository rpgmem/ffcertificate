/**
 * FFC User Dashboard — Core
 *
 * Defines the `window.FFCDashboard` shell: panel registry, generic event
 * bindings (tabs, pagination, filter bar, page-size selector), the always-
 * visible summary header, and the table-driven tab dispatch.
 *
 * Panels live in sibling files (ffc-user-dashboard-{certificates,appointments,
 * audience,reregistrations,profile,audience-join}.js) and self-register at
 * parse time via `FFCDashboard.panels[name] = { state, load, render, bindEvents }`.
 * Adding a new panel means: (1) create one file, (2) `wp_enqueue_script` it
 * with `'ffc-dashboard'` as a dep — no edits to this file.
 *
 * @since 6.5.2
 */

(function ($) {
    'use strict';

    var DEFAULT_PAGE_SIZE = 25;

    var helpers = {
        esc: function (str) { return $('<div>').text(str || '').html(); },

        // Attribute-safe escape: esc() encodes < > & but not the double quote
        // that would break out of an HTML attribute value, so harden href/src
        // interpolations with the extra quote replacement.
        escAttr: function (str) { return helpers.esc(str).replace(/"/g, '&quot;'); },

        pad2: function (n) { return n < 10 ? '0' + n : '' + n; },

        getPageSize: function () {
            var stored = parseInt(localStorage.getItem('ffc_page_size'), 10);
            return (stored && [10, 25, 50].indexOf(stored) !== -1) ? stored : DEFAULT_PAGE_SIZE;
        },

        buildFilterBar: function (tabName) {
            var s = ffcDashboard.strings;
            var html = '<div class="ffc-filter-bar" data-tab="' + tabName + '">';
            html += '<label>' + (s.filterFrom || 'From:') + ' <input type="date" class="ffc-filter-from" /></label>';
            html += '<label>' + (s.filterTo || 'To:') + ' <input type="date" class="ffc-filter-to" /></label>';
            html += '<input type="text" class="ffc-filter-search" placeholder="' + (s.filterSearch || 'Search...') + '" />';
            html += '<button type="button" class="button ffc-filter-apply">' + (s.filterApply || 'Filter') + '</button>';
            html += '<button type="button" class="button ffc-filter-clear">' + (s.filterClear || 'Clear') + '</button>';
            html += '</div>';
            return html;
        },

        buildPageSizeSelector: function () {
            var current = helpers.getPageSize();
            var s = ffcDashboard.strings;
            var html = '<span class="ffc-page-size-select">' + (s.perPage || 'Per page:') + ' ';
            [10, 25, 50].forEach(function (n) {
                if (n === current) {
                    html += '<strong>' + n + '</strong> ';
                } else {
                    html += '<a href="#" class="ffc-page-size-btn" data-size="' + n + '">' + n + '</a> ';
                }
            });
            html += '</span>';
            return html;
        },

        buildPagination: function (total, page, dataAttr) {
            var pageSize = helpers.getPageSize();
            if (total <= pageSize) {
                return '<div class="ffc-pagination">' + helpers.buildPageSizeSelector() + '</div>';
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

            html += ' ' + helpers.buildPageSizeSelector();
            html += '</div>';
            return html;
        }
    };

    window.FFCDashboard = {
        panels: {},
        helpers: helpers,

        init: function () {
            this.bindEvents();
            this.bindPanelEvents();
            this.loadSummary();
            this.loadInitialTab();
        },

        bindEvents: function () {
            $(document).on('click', '.ffc-tab', this.switchTab.bind(this));
            $(document).on('keydown', '.ffc-tab', this.handleTabKeydown.bind(this));
            $(document).on('click', '.ffc-pagination-btn', this.handlePagination.bind(this));

            $(document).on('click', '.ffc-filter-apply', function (e) {
                e.preventDefault();
                FFCDashboard.applyTabFilter($(this).closest('.ffc-filter-bar').data('tab'));
            });
            $(document).on('click', '.ffc-filter-clear', function (e) {
                e.preventDefault();
                var $bar = $(this).closest('.ffc-filter-bar');
                $bar.find('input').val('');
                FFCDashboard.applyTabFilter($bar.data('tab'));
            });
            $(document).on('keyup', '.ffc-filter-search', function (e) {
                if (e.key === 'Enter') {
                    FFCDashboard.applyTabFilter($(this).closest('.ffc-filter-bar').data('tab'));
                }
            });

            $(document).on('click', '.ffc-page-size-btn', function (e) {
                e.preventDefault();
                localStorage.setItem('ffc_page_size', parseInt($(this).data('size'), 10));
                FFCDashboard.applyTabFilter($('.ffc-tab.active').data('tab'));
            });
        },

        bindPanelEvents: function () {
            var self = this;
            Object.keys(this.panels).forEach(function (name) {
                var panel = self.panels[name];
                if (panel && typeof panel.bindEvents === 'function') {
                    panel.bindEvents(self);
                }
            });
        },

        // ---- Summary header (always visible, not a tab) ----

        loadSummary: function () {
            var $summary = $('#ffc-dashboard-summary');
            if ($summary.length === 0) return;

            var url = ffcDashboard.restUrl + 'user/summary';
            if (ffcDashboard.viewAsUserId) url += '?viewAsUserId=' + ffcDashboard.viewAsUserId;

            FFC.rest(url, { nonce: ffcDashboard.nonce })
                .then(function (data) { FFCDashboard.renderSummary(data); })
                .catch(function () {
                    $summary.html('<div class="ffc-error">' + ffcDashboard.strings.error + '</div>');
                });
        },

        renderSummary: function (data) {
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
                    html += '<div class="ffc-summary-detail">' + data.next_appointment.time + ' &mdash; ' + helpers.esc(data.next_appointment.title) + '</div>';
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

        // ---- Tab dispatch (table-driven via this.panels) ----

        loadInitialTab: function () {
            var panel = this.panels[$('.ffc-tab.active').data('tab')];
            if (panel && typeof panel.load === 'function') panel.load();
        },

        switchTab: function (e) {
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

            var panel = this.panels[tab];
            if (panel && typeof panel.load === 'function') panel.load();
        },

        handleTabKeydown: function (e) {
            var $tabs = $('.ffc-tab');
            var index = $tabs.index($(e.currentTarget));
            var newIndex = -1;

            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); newIndex = (index + 1) % $tabs.length; }
            else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { e.preventDefault(); newIndex = (index - 1 + $tabs.length) % $tabs.length; }
            else if (e.key === 'Home') { e.preventDefault(); newIndex = 0; }
            else if (e.key === 'End') { e.preventDefault(); newIndex = $tabs.length - 1; }

            if (newIndex >= 0) $tabs.eq(newIndex).focus().trigger('click');
        },

        applyTabFilter: function (tab) {
            var panel = this.panels[tab];
            if (panel && panel.state != null && typeof panel.render === 'function') {
                panel.render(panel.state, 1);
            }
        },

        handlePagination: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var page = parseInt($btn.data('page'), 10);
            var target = $btn.data('target');
            var panel = this.panels[target];
            if (panel && typeof panel.render === 'function') panel.render(panel.state, page);
        }
    };

    $(document).ready(function () {
        if ($('#ffc-user-dashboard').length > 0) FFCDashboard.init();
    });

})(jQuery);
