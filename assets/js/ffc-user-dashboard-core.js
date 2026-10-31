/**
 * FFC User Dashboard — Core
 *
 * Defines the `window.FFCDashboard` shell: panel registry, generic event
 * bindings (tabs, pagination, per-section pagers, filter bar, page-size
 * selector), the always-
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

    // Fixed page sizes of the sectioned tabs (Personal Calendar, Group
    // Calendar), one per section (#1836). They are not a viewer setting:
    // the upcoming items lead, so they get the most room, and the
    // cancelled ones the least.
    var SECTION_PAGE_SIZES = { upcoming: 5, past: 3, cancelled: 2 };

    // A pager past this many pages shows the first, the last and the
    // current page with its neighbours, and an ellipsis for the rest.
    var SECTION_PAGER_MAX_BUTTONS = 7;

    var helpers = {
        esc: function (str) { return $('<div>').text(str || '').html(); },

        // Attribute-safe escape: esc() encodes < > & but not the double quote
        // that would break out of an HTML attribute value, so harden href/src
        // interpolations with the extra quote replacement.
        escAttr: function (str) { return helpers.esc(str).replace(/"/g, '&quot;'); },

        pad2: function (n) { return n < 10 ? '0' + n : '' + n; },

        // A tag over a colour an operator picked (an audience group). The
        // ground and its text come from the REST payload -- `color` and
        // `text_color`, the second computed server-side by
        // `ContrastColor::on()` (#1826) -- and travel as the two custom
        // properties `.ffc-pill-row-color` reads, so the rule stays in the
        // sheet. Anything that is not a hex colour is dropped, and the tag
        // falls back to the neutral pair that class declares.
        rowColorTag: function (cls, item) {
            var hex = /^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/;
            var bg = item && hex.test(item.color || '') ? item.color : '';
            var fg = item && hex.test(item.text_color || '') ? item.text_color : '';
            var style = (bg && fg) ? ' style="--ffc-badge-row-bg:' + bg + ';--ffc-badge-row-text:' + fg + ';"' : '';
            return '<span class="' + cls + ' ffc-pill-row-color"' + style + '>' + helpers.esc(item ? item.name : '') + '</span>';
        },

        // A status line's text and tone: the shared `.ffc-status` the admin
        // uses too, drawn by FFC.status() (#1843). The tone is a class on the
        // palette (#1826) -- never a colour literal, which ignores the dark theme.
        setStatus: function ($el, text, tone) {
            window.FFC.status($el, tone || '', text);
            return $el;
        },

        // A class name built from a server value keeps only the characters a
        // class may hold, so a value never breaks out of the attribute (#2246).
        classSlug: function (value) {
            return String(value || '').toLowerCase().replace(/[^a-z0-9_-]/g, '');
        },

        // Browser storage is a convenience: a private window, blocked site
        // data or a full quota throws, and the dashboard works without it
        // (#2246).
        storage: {
            get: function (key) {
                try { return window.localStorage.getItem(key); } catch (e) { return null; }
            },
            set: function (key, value) {
                try { window.localStorage.setItem(key, String(value)); } catch (e) { /* not stored */ }
            }
        },

        // A message about an action, shown in the dashboard instead of a
        // browser alert (#2246): `tone` is a notice tone, and an error is
        // announced at once (`role="alert"`), anything else politely.
        // The dashboard's root, or the page when the dashboard is not on it.
        root: function () {
            var $root = $('#ffc-user-dashboard');
            return $root.length ? $root : $('body');
        },

        flash: function (tone, text) {
            var $region = $('#ffc-dashboard-flash');
            if ($region.length === 0) {
                $region = $('<div class="ffc-dashboard-flash" id="ffc-dashboard-flash"></div>');
                var $summary = $('#ffc-dashboard-summary');
                if ($summary.length) $summary.before($region);
                else helpers.root().prepend($region);
            }
            var notice = window.FFC.notice(tone || 'info', '', String(text || ''), '', { role: tone === 'danger' ? 'alert' : 'status' });
            $region.empty().append(notice);
            if (notice && typeof notice.scrollIntoView === 'function') notice.scrollIntoView({ block: 'nearest' });
            return $region;
        },

        // The message an error carries from the server, or the fallback.
        errorMessage: function (err, fallback) {
            var json = err && err.xhr && err.xhr.responseJSON;
            if (json && json.message) return json.message;
            if (err && err.fromServer && err.message) return err.message;
            return fallback || ffcDashboard.strings.error || 'Error';
        },

        // "No results" under an active filter, with the way out beside it
        // (#2246). Without a filter the panel shows its own empty state.
        buildNoResults: function (tabName) {
            var s = ffcDashboard.strings;
            return '<div class="ffc-empty-state ffc-empty-filtered" role="status"><p>' +
                helpers.esc(s.noResults || 'No results for these filters.') + '</p>' +
                window.FFC.buttonHtml({ label: s.clearFilters || 'Clear filters', category: 'neutral', icon: 'eraser', className: 'ffc-filter-clear', attrs: { 'data-tab': tabName } }) +
                '</div>';
        },

        getPageSize: function () {
            var stored = parseInt(helpers.storage.get('ffc_page_size'), 10);
            return (stored && [10, 25, 50].indexOf(stored) !== -1) ? stored : DEFAULT_PAGE_SIZE;
        },

        buildFilterBar: function (tabName) {
            var s = ffcDashboard.strings;
            var html = '<div class="ffc-filter-bar" data-tab="' + tabName + '">';
            html += '<label>' + (s.filterFrom || 'From:') + ' <input type="date" class="ffc-filter-from" /></label>';
            html += '<label>' + (s.filterTo || 'To:') + ' <input type="date" class="ffc-filter-to" /></label>';
            html += '<input type="text" class="ffc-filter-search" placeholder="' + helpers.escAttr(s.filterSearch || 'Search...') + '" aria-label="' + helpers.escAttr(s.filterSearchLabel || 'Search this list') + '" />';
            html += window.FFC.buttonHtml({ label: s.filterApply || 'Filter', category: 'neutral', icon: 'filter', className: 'ffc-filter-apply' });
            html += window.FFC.buttonHtml({ label: s.filterClear || 'Clear', category: 'neutral', icon: 'eraser', className: 'ffc-filter-clear' });
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

        SECTION_PAGE_SIZES: SECTION_PAGE_SIZES,

        sectionPageSize: function (section) {
            return SECTION_PAGE_SIZES[section] || SECTION_PAGE_SIZES.upcoming;
        },

        // Clamp a page number into the range a section of `total` items has.
        clampSectionPage: function (page, total, size) {
            var totalPages = Math.max(1, Math.ceil(total / size));
            page = parseInt(page, 10) || 1;
            return Math.min(Math.max(page, 1), totalPages);
        },

        // Which page numbers a pager draws: all of them up to the button
        // budget, otherwise the first, the last and the current page with
        // its neighbours. A gap is `null`.
        sectionPageNumbers: function (page, totalPages) {
            var out = [];
            var i;
            if (totalPages <= SECTION_PAGER_MAX_BUTTONS) {
                for (i = 1; i <= totalPages; i++) out.push(i);
                return out;
            }
            var from = Math.max(2, page - 1);
            var to = Math.min(totalPages - 1, page + 1);
            out.push(1);
            if (from > 2) out.push(null);
            for (i = from; i <= to; i++) out.push(i);
            if (to < totalPages - 1) out.push(null);
            out.push(totalPages);
            return out;
        },

        // A section heading with the section's total beside it, e.g.
        // "Upcoming (12)". The count is the filtered total, not the page's.
        buildSectionHeading: function (label, count) {
            return '<h3 class="ffc-dashboard-section-title">' + helpers.esc(label) +
                ' <small class="ffc-dashboard-section-count">(' + count + ')</small></h3>';
        },

        // The pager under one section's table (#1836): the range on the
        // left, the page buttons on the right. Empty when the section fits
        // on one page. `target` is the panel name and `section` the
        // section key; the click handler in this file reads both.
        buildSectionPager: function (total, page, size, target, section, label) {
            if (total <= size) return '';

            var s = ffcDashboard.strings;
            var totalPages = Math.ceil(total / size);
            page = helpers.clampSectionPage(page, total, size);
            var first = (page - 1) * size + 1;
            var last = Math.min(page * size, total);

            var range = (s.sectionRange || '{start}\u2013{end} of {total}')
                .replace('{start}', first).replace('{end}', last).replace('{total}', total);
            var navLabel = (s.sectionPagination || 'Pagination: {section}').replace('{section}', label || section);

            var attrs = ' data-target="' + helpers.escAttr(target) + '" data-section="' + helpers.escAttr(section) + '"';
            var html = '<nav class="ffc-section-pager" aria-label="' + helpers.escAttr(navLabel) + '">';
            html += '<span class="ffc-section-pager-range">' + helpers.esc(range) + '</span>';
            html += '<span class="ffc-section-pager-pages">';

            // The keys are the button family's neutral buttons written by
            // hand (#1874): a page number or an arrow glyph IS the label, so
            // they carry no icon, and the current page is marked by
            // `aria-current`, which the sheet paints.
            html += '<button type="button" class="ffc-btn ffc-btn-neutral ffc-section-page-btn"' + attrs +
                ' data-page="' + (page - 1) + '" aria-label="' + helpers.escAttr(s.previousPage || 'Previous page') + '"' +
                (page <= 1 ? ' disabled' : '') + '>&lsaquo;</button>';

            helpers.sectionPageNumbers(page, totalPages).forEach(function (n) {
                if (n === null) {
                    html += '<span class="ffc-section-pager-gap" aria-hidden="true">&hellip;</span>';
                    return;
                }
                var current = n === page;
                html += '<button type="button" class="ffc-btn ffc-btn-neutral ffc-section-page-btn"' + attrs +
                    ' data-page="' + n + '" aria-label="' + helpers.escAttr((s.pageNumber || 'Page {page}').replace('{page}', n)) + '"' +
                    (current ? ' aria-current="page"' : '') + '>' + n + '</button>';
            });

            html += '<button type="button" class="ffc-btn ffc-btn-neutral ffc-section-page-btn"' + attrs +
                ' data-page="' + (page + 1) + '" aria-label="' + helpers.escAttr(s.nextPage || 'Next page') + '"' +
                (page >= totalPages ? ' disabled' : '') + '>&rsaquo;</button>';

            html += '</span></nav>';
            return html;
        },

        // One section of a sectioned tab: the heading with its count, the
        // table with the current page's rows, and the pager. `opts`:
        //   target, key, label, items, page, tableClass, headHtml,
        //   rowHtml(item) -- the panel owns its columns and rows.
        buildSection: function (opts) {
            var size = helpers.sectionPageSize(opts.key);
            var page = helpers.clampSectionPage(opts.page, opts.items.length, size);
            var start = (page - 1) * size;
            var html = '<section class="ffc-dashboard-section" data-section="' + helpers.escAttr(opts.key) + '">';
            html += helpers.buildSectionHeading(opts.label, opts.items.length);
            // The wrapper is what scrolls sideways on a phone; on a wide
            // screen it is a plain block.
            html += '<div class="ffc-dashboard-section-scroll"><table class="' + opts.tableClass + '">';
            html += '<thead><tr>' + opts.headHtml + '</tr></thead><tbody>';
            opts.items.slice(start, start + size).forEach(function (item) {
                html += opts.rowHtml(item);
            });
            html += '</tbody></table></div>';
            html += helpers.buildSectionPager(opts.items.length, page, size, opts.target, opts.key, opts.label);
            html += '</section>';
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
                html += window.FFC.buttonHtml({
                    label: ffcDashboard.strings.previous || 'Previous',
                    category: 'neutral',
                    icon: 'chevron-left',
                    className: 'ffc-pagination-btn',
                    attrs: { 'data-page': page - 1, 'data-target': dataAttr }
                }) + ' ';
            }

            html += '<span class="ffc-pagination-info">';
            html += (ffcDashboard.strings.pageOf || 'Page {current} of {total}').replace('{current}', page).replace('{total}', totalPages);
            html += '</span>';

            if (page < totalPages) {
                html += ' ' + window.FFC.buttonHtml({
                    label: ffcDashboard.strings.next || 'Next',
                    category: 'neutral',
                    icon: 'chevron-right',
                    className: 'ffc-pagination-btn',
                    attrs: { 'data-page': page + 1, 'data-target': dataAttr }
                });
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
            this.bindHistory();
            this.bindSession();
            this.bindPanelEvents();
            this.loadSummary();
            this.loadInitialTab();
        },

        bindEvents: function () {
            $(document).on('click', '.ffc-tab', this.switchTab.bind(this));
            $(document).on('keydown', '.ffc-tab', this.handleTabKeydown.bind(this));
            $(document).on('click', '.ffc-pagination-btn', this.handlePagination.bind(this));
            $(document).on('click', '.ffc-section-page-btn', this.handleSectionPage.bind(this));

            $(document).on('click', '.ffc-filter-apply', function (e) {
                e.preventDefault();
                FFCDashboard.applyTabFilter($(this).closest('.ffc-filter-bar').data('tab'));
            });
            $(document).on('click', '.ffc-filter-clear', function (e) {
                e.preventDefault();
                // The bar's own button, or the one beside "No results".
                var tab = $(this).data('tab') || $(this).closest('.ffc-filter-bar').data('tab');
                $('.ffc-filter-bar[data-tab="' + tab + '"]').find('input').val('');
                FFCDashboard.applyTabFilter(tab);
            });
            $(document).on('keyup', '.ffc-filter-search', function (e) {
                if (e.key === 'Enter') {
                    FFCDashboard.applyTabFilter($(this).closest('.ffc-filter-bar').data('tab'));
                }
            });

            $(document).on('click', '.ffc-page-size-btn', function (e) {
                e.preventDefault();
                helpers.storage.set('ffc_page_size', parseInt($(this).data('size'), 10));
                FFCDashboard.applyTabFilter($('.ffc-tab.is-active').data('tab'));
            });
        },

        // Back and Forward walk the tabs the URL recorded (#2246).
        bindHistory: function () {
            var self = this;
            $(window).on('popstate', function () {
                var tab = new URL(window.location).searchParams.get('tab');
                var $button = tab ? $('.ffc-tab[data-tab="' + tab + '"]') : $('.ffc-tab').first();
                if ($button.length) self.activateTab($button, false);
            });
        },

        // An expired login or a stale nonce answers 401, or 403 with core's
        // `rest_cookie_invalid_nonce`. Every request of the dashboard then
        // fails the same way, so the page says it once, with the way out,
        // instead of a generic error in each panel (#2246).
        isSessionError: function (xhr) {
            if (!xhr) return false;
            var code = xhr.responseJSON && xhr.responseJSON.code;
            return xhr.status === 401 || code === 'rest_cookie_invalid_nonce';
        },

        showSessionExpired: function () {
            if ($('#ffc-dashboard-session').length) return;
            var s = ffcDashboard.strings;
            var notice = window.FFC.notice('warning', 'clock', s.sessionExpired || 'Your session has expired. Reload the page to continue.', '', { role: 'alert' });
            var $wrap = $('<div class="ffc-dashboard-session" id="ffc-dashboard-session"></div>').append(notice);
            $wrap.find('.ffc-notice-body').append(window.FFC.buttonHtml({ label: s.reloadPage || 'Reload page', category: 'primary', icon: 'sync', className: 'ffc-session-reload' }));
            helpers.root().prepend($wrap);
        },

        bindSession: function () {
            var self = this;
            $(document).ajaxError(function (event, xhr, settings) {
                var url = (settings && settings.url) || '';
                if (url.indexOf(ffcDashboard.restUrl) === 0 && self.isSessionError(xhr)) self.showSessionExpired();
            });
            $(document).on('click', '.ffc-session-reload', function (e) {
                e.preventDefault();
                window.location.reload();
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
                    $summary.empty().append(FFC.notice('danger', 'error', ffcDashboard.strings.error, '', { role: 'alert' }));
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
            var panel = this.panels[$('.ffc-tab.is-active').data('tab')];
            if (panel && typeof panel.load === 'function') panel.load();
        },

        switchTab: function (e) {
            e.preventDefault();
            this.activateTab($(e.currentTarget), true);
        },

        // Show one tab; `record` adds it to the history, which Back then
        // walks (a tab restored from the history is not recorded again).
        activateTab: function ($button, record) {
            var tab = $button.data('tab');

            $('.ffc-tab').removeClass('is-active').attr('aria-selected', 'false').attr('tabindex', '-1');
            $button.addClass('is-active').attr('aria-selected', 'true').attr('tabindex', '0');

            $('.ffc-tab-content').removeClass('is-active');
            $('#ffc-tabpanel-' + tab).addClass('is-active');

            if (record && history.pushState) {
                var url = new URL(window.location);
                url.searchParams.set('tab', tab);
                history.pushState({ tab: tab }, '', url);
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

        // A page of one section (#1836): only that section is rebuilt, so
        // the other sections keep their pages and the filter bar keeps
        // its values. The panel supplies the markup through
        // `buildSectionHtml(section)` and keeps the page in
        // `sectionPages`; focus returns to the current page's button.
        handleSectionPage: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            if ($btn.prop('disabled')) return;
            var panel = this.panels[$btn.data('target')];
            var section = String($btn.data('section'));
            if (!panel || typeof panel.buildSectionHtml !== 'function') return;

            panel.sectionPages = panel.sectionPages || {};
            panel.sectionPages[section] = parseInt($btn.data('page'), 10) || 1;

            var $section = $btn.closest('.ffc-dashboard-section');
            var $next = $(panel.buildSectionHtml(section));
            $section.replaceWith($next);
            $next.find('.ffc-section-page-btn[aria-current="page"]').trigger('focus');
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
