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
 * (#772) through the unified `ffc_export_*` dispatcher, carrying the current
 * level/action/search filters — the timeout-safe start → batch → download
 * job replaces the former synchronous stream.
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

    function readFilters() {
        return {
            level:      ($('#filter-by-level').val()       || ''),
            log_action: ($('#filter-by-action').val()      || ''),
            search:     ($('#ffc-log-search').val()        || ''),
        };
    }

    function buildQuery(filters, paged) {
        var qs = $.param({
            post_type: 'ffc_form',
            page:      'ffc-activity-log',
            level:     filters.level,
            log_action: filters.log_action,
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

        if (data.is_empty) {
            $tbl.html(
                '<div class="notice notice-info"><p>'
                + (s.noLogs || 'No activity logs found.')
                + '</p></div>'
            );
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
                if (window.FFC.Admin && typeof window.FFC.Admin.showNotification === 'function') {
                    window.FFC.Admin.showNotification(msg, 'error');
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

    // Clear filters link — currently a regular anchor with the
    // base URL. Trap the click and reset locally instead of reloading.
    $(document).on('click', '.tablenav.top .alignleft a.button[href*="ffc-activity-log"]', function (e) {
        // Only intercept when no `?level=` / `log_action` / `s` in href —
        // that's the Clear Filters button by construction.
        var href = $(this).attr('href') || '';
        if (/[?&](level|log_action|s)=/.test(href)) { return; }
        e.preventDefault();
        $('#filter-by-level').val('');
        $('#filter-by-action').val('');
        $('#ffc-log-search').val('');
        fetch({ level: '', log_action: '', search: '' }, 1);
    });

    // Export CSV — batched engine (#772). The button drives the unified
    // ffc_export_* dispatcher via the shared window.FFCBatchedExport driver,
    // carrying the current level/action/search filters. Export order is
    // id-DESC (a stable keyset), not the on-screen sort.
    $(document).on('click', '#ffc-activitylog-export-btn', function () {
        if (!window.FFCBatchedExport) { return; }
        var c         = cfg();
        var s         = strings();
        var $btn      = $(this);
        var $progress = $('#ffc-activitylog-export-progress');
        var exportNonce = c.exportNonce || '';
        if (!exportNonce) { return; }

        var originalText = $btn.text();
        $btn.prop('disabled', true).text(s.exportPreparing || 'Preparing…');
        $progress.show().text('');

        var exportingTpl = s.exportProgress || 'Exporting %1$d/%2$d…';
        var total = 0;

        window.FFCBatchedExport.run({
            type: 'activity_log',
            ajaxUrl: c.ajaxUrl,
            nonce: exportNonce,
            startData: {
                level:      $btn.data('level') || '',
                log_action: $btn.data('log_action') || '',
                s:          $btn.data('s') || ''
            },
            callbacks: {
                onStart: function (t) { total = t; },
                onProgress: function (processed) {
                    $progress.text(exportingTpl.replace('%1$d', processed).replace('%2$d', total));
                },
                onComplete: function (downloadUrl, ctx) {
                    var $iframe = $('<iframe>', { src: downloadUrl }).css({ display: 'none' }).appendTo('body');
                    setTimeout(function () {
                        $btn.prop('disabled', false).text(originalText);
                        $progress.text('✓ ' + ctx.processed + '/' + total + ' — ' + (s.exportDone || 'Done!'));
                        setTimeout(function () { $progress.fadeOut(); }, 5000);
                        $iframe.remove();
                    }, 2000);
                },
                onError: function (err) {
                    $btn.prop('disabled', false).text(originalText);
                    $progress.text((err && err.fromServer && err.message) || (s.error || 'Failed to fetch logs.'));
                }
            }
        });
    });

    // Back/forward — restore from history state.
    $(window).on('popstate', function (e) {
        var state = e.originalEvent && e.originalEvent.state;
        if (state && state.filters) {
            $('#filter-by-level').val(state.filters.level || '');
            $('#filter-by-action').val(state.filters.log_action || '');
            $('#ffc-log-search').val(state.filters.search || '');
            fetch(state.filters, state.paged || 1, { skipPushState: true });
        }
    });
}(jQuery));
