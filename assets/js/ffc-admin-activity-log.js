/**
 * FFC Admin Activity Log — AJAX filter / search / pagination.
 *
 * Intercepts the filter form, search form, pagination links and the
 * Clear Filters link; POSTs to `ffc_activity_log_fetch` and swaps the
 * table body + pagination block from the JSON response. `history.pushState`
 * keeps the URL in sync so results are bookmarkable and the back button
 * restores the previous filter.
 *
 * The Export CSV button drives the shared window.FFCBatchedExport engine
 * (#772) through the unified `ffc_export_*` dispatcher. It reads the filters
 * on screen when it is clicked, so a filter applied in place reaches the
 * CSV (#2340).
 *
 * @since 6.5.8
 */
(function ($) {
    'use strict';

    if (!window.FFC || !window.FFC.request) {
        return;
    }

    function cfg()     { return window.ffcActivityLog || {}; }
    function nonce()   { return cfg().nonce || ''; }
    function strings() { return cfg().strings || {}; }

    // Lazy lookups — the handlers fire even when the activity log
    // markup isn't present, so we re-find these on every event rather
    // than caching at module load.
    function $table()      { return $('#ffc-activity-log-table'); }
    function $pagination() { return $('#ffc-activity-log-pagination'); }

    var EMPTY_FILTERS = { level: '', log_action: '', log_user: '', date_from: '', date_to: '', search: '' };

    function readFilters() {
        return {
            level:      ($('#filter-by-level').val()       || ''),
            log_action: ($('#filter-by-action').val()      || ''),
            log_user:   ($('#filter-by-user').val()        || ''),
            date_from:  ($('#filter-date-from').val()      || ''),
            date_to:    ($('#filter-date-to').val()        || ''),
            search:     ($('#ffc-log-search').val()        || ''),
        };
    }

    function writeFilters(filters) {
        $('#filter-by-level').val(filters.level || '');
        $('#filter-by-action').val(filters.log_action || '');
        $('#filter-by-user').val(filters.log_user || '');
        $('#filter-date-from').val(filters.date_from || '');
        $('#filter-date-to').val(filters.date_to || '');
        $('#ffc-log-search').val(filters.search || '');
    }

    function hasFilters(filters) {
        return Object.keys(EMPTY_FILTERS).some(function (key) { return '' !== (filters[key] || ''); });
    }

    function buildQuery(filters, paged) {
        var qs = $.param({
            page:      'ffc-settings',
            tab:       'activity_log',
            level:     filters.level,
            log_action: filters.log_action,
            log_user:  filters.log_user,
            date_from: filters.date_from,
            date_to:   filters.date_to,
            s:         filters.search,
            paged:     paged || 1,
        });
        // Drop empty params for a cleaner URL.
        return qs.replace(/(?:^|&)[a-z_]+=(?=&|$)/g, '').replace(/^&/, '');
    }

    function pushUrl(filters, paged) {
        if (!window.history || !window.history.pushState) { return; }
        var qs = buildQuery(filters, paged);
        var url = window.location.pathname + '?' + qs;
        window.history.pushState({ filters: filters, paged: paged }, '', url);
    }

    function showLoading() {
        $table().addClass('ffc-loading');
    }
    function hideLoading() {
        $table().removeClass('ffc-loading');
    }

    function applyResponse(data, filters, paged, opts) {
        opts = opts || {};
        var s    = strings();
        var $tbl = $table();
        if (!$tbl.length) { return; }

        // The Clear Filters button shows while a filter is set (#2340).
        $('.tablenav.top .alignleft .ffc-log-clear').prop('hidden', !hasFilters(filters));
        // The count line and the page reveal belong to a table with rows.
        var $meta = $('.ffc-log-meta');
        $meta.prop('hidden', !!data.is_empty);
        $meta.find('.ffc-log-count').text(data.count_text || '');

        if (data.is_empty) {
            // The server's empty state tells "no matches" from "nothing
            // recorded yet" (#2340); an in-content callout, not a core
            // `.notice`, which common.js lifts out of the container (#1843).
            if (data.empty_html) {
                $tbl.html(data.empty_html);
            } else {
                $tbl.empty().append(window.FFC.notice('info', 'info', s.noLogs || 'No activity logs found.', '', { role: 'status' }));
            }
        } else {
            var $tbody = $tbl.find('tbody');
            if (!$tbody.length) {
                $tbl.html(buildTableShell() + '<div id="ffc-activity-log-pagination"></div>');
                $tbody = $tbl.find('tbody');
            }
            $tbody.html(data.table_html);
            $pagination().html(data.pagination_html || '');
        }

        if (!opts.skipPushState) {
            pushUrl(filters, paged);
        }
    }

    function buildTableShell() {
        var s = strings();
        return ''
            + '<table class="wp-list-table widefat fixed striped">'
            +   '<thead><tr>'
            +     '<th width="12%">' + (s.colDate    || 'Date/Time')  + '</th>'
            +     '<th width="10%">' + (s.colLevel   || 'Level')      + '</th>'
            +     '<th width="18%">' + (s.colAction  || 'Action')     + '</th>'
            +     '<th width="15%">' + (s.colUser    || 'User')       + '</th>'
            +     '<th width="12%">' + (s.colIp      || 'IP Address') + '</th>'
            +     '<th width="33%">' + (s.colContext || 'Context')    + '</th>'
            +   '</tr></thead>'
            +   '<tbody></tbody>'
            + '</table>';
    }

    function fetch(filters, paged, opts) {
        showLoading();
        return window.FFC.request('ffc_activity_log_fetch', {
            level:      filters.level,
            log_action: filters.log_action,
            log_user:   filters.log_user,
            date_from:  filters.date_from,
            date_to:    filters.date_to,
            search:     filters.search,
            paged:      paged || 1,
            nonce:      nonce(),
        })
            .then(function (data) {
                hideLoading();
                applyResponse(data, filters, paged || 1, opts || {});
            })
            .catch(function (err) {
                hideLoading();
                var s = strings();
                var msg = (err && err.message) ? err.message : (s.error || 'Failed to fetch logs.');
                if (window.FFC.Admin && typeof window.FFC.Admin.notify === 'function') {
                    window.FFC.Admin.notify(msg, 'error');
                } else {
                    window.alert(msg);
                }
            });
    }

    // Delegated handlers — register at IIFE eval time, no document.ready
    // wrapper. The handlers themselves do lazy lookups so they're a
    // no-op when the activity log markup isn't on the page.

    // Filter form
    $(document).on('submit', '.tablenav.top form', function (e) {
        if (!$table().length) { return; }
        e.preventDefault();
        fetch(readFilters(), 1);
    });

    // Pagination links (anchor inside the pagination container).
    $(document).on('click', '#ffc-activity-log-pagination a', function (e) {
        var href = $(this).attr('href') || '';
        var m = href.match(/[?&]paged=(\d+)/);
        if (!m) { return; }
        e.preventDefault();
        fetch(readFilters(), parseInt(m[1], 10));
    });

    // Clear Filters — the one beside the filters and the one in the
    // "no matches" state. A regular anchor with the base URL; trapped and
    // reset in place instead of reloading.
    $(document).on('click', 'a.ffc-log-clear', function (e) {
        if (!$table().length) { return; }
        // The link carries the bare base URL; a filter in it would mean
        // something else wears the hook, so leave that one alone.
        var href = $(this).attr('href') || '';
        if (/[?&](level|log_action|log_user|date_from|date_to|s)=/.test(href)) { return; }
        e.preventDefault();
        writeFilters(EMPTY_FILTERS);
        fetch($.extend({}, EMPTY_FILTERS), 1);
    });

    // Export CSV — batched engine (#772). The button drives the unified
    // ffc_export_* dispatcher via the shared window.FFCBatchedExport driver,
    // with the filters on screen at the moment of the click (#2340). Export
    // order is id-DESC (a stable keyset), not the on-screen sort.
    $(document).on('click', '#ffc-activitylog-export-btn', function () {
        if (!window.FFCBatchedExport) { return; }
        var c           = cfg();
        var s           = strings();
        var $btn        = $(this);
        var exportNonce = c.exportNonce || '';
        if (!exportNonce) { return; }

        // Progress is shown through the shared FFCProgressOverlay modal, driven
        // by the driver itself (overlay: true) — same UI as the public
        // download (#786).
        window.FFCBatchedExport.run({
            type: 'activity_log',
            ajaxUrl: c.ajaxUrl,
            nonce: exportNonce,
            button: $btn,
            overlay: true,
            strings: {
                preparing: s.exportPreparing,
                exporting: s.exportProgress,
                downloading: s.exportDone,
                error: s.error
            },
            startData: (function () {
                var f = readFilters();
                return {
                    level:      f.level,
                    log_action: f.log_action,
                    log_user:   f.log_user,
                    date_from:  f.date_from,
                    date_to:    f.date_to,
                    s:          f.search
                };
            }())
        });
    });

    // Back/forward — restore from history state.
    $(window).on('popstate', function (e) {
        var state = e.originalEvent && e.originalEvent.state;
        if (state && state.filters) {
            writeFilters(state.filters);
            fetch(state.filters, state.paged || 1, { skipPushState: true });
        }
    });
}(jQuery));
