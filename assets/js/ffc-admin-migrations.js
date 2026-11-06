/**
 * FFC Admin Migrations — batch runner with JSON progress polling.
 *
 * Intercepts the migration "Run" link, then loops a small POST to the
 * `ffc_migration_run_batch` AJAX endpoint until the response reports
 * `is_complete=true`, or until a batch changes nothing short of it, which
 * stops the card as stuck with the errors it collected. Each iteration
 * repaints the progress bar + counters from the JSON payload — no
 * full-page-HTML parsing. One run at a time: the other cards wait.
 *
 * The legacy link href is preserved so users without JS still get the
 * old one-batch-per-click behaviour.
 *
 * @since 4.6.0
 * @updated 6.5.7 — JSON endpoint; was HTML-parsing.
 * @package FreeFormCertificate\Settings
 */
(function ($) {
    'use strict';

    if (!window.FFC || !window.FFC.request) {
        return;
    }

    // Read the localised payload lazily — the script is enqueued early
    // and `wp_localize_script` may run after script load in some
    // environments (also: tests reset the global per fixture).
    function getStrings() { return (window.ffcMigrations && window.ffcMigrations.strings) || {}; }
    function getNonce()   { return (window.ffcMigrations && window.ffcMigrations.nonce)   || ''; }

    // Pull the migration_key out of the legacy href so we don't have
    // to duplicate it as a data-attribute — the URL already carries it.
    function getMigrationKey($a) {
        var href = $a.attr('href') || '';
        var m = href.match(/[?&]ffc_run_migration=([a-z0-9_\-]+)/i);
        return m ? m[1] : '';
    }

    // The percent the card shows: never 100 while a record is pending,
    // since one decimal rounds 99.96 up (#2338).
    function displayPercent(payload) {
        var value   = parseFloat(payload.percent || 0);
        var pending = parseInt(payload.pending, 10) || 0;
        if (pending > 0 && value >= 99.95) {
            value = 99.9;
        }
        return value.toFixed(1);
    }

    function repaintCard($card, payload) {
        var strings = getStrings();
        // Progress bar
        var percent = displayPercent(payload);
        $card.find('.ffc-progress-bar-fill').css('width', percent + '%');
        $card.find('.ffc-progress-bar-container').attr('aria-valuenow', percent);
        $card.find('.ffc-progress-bar-label').text(percent + '% ' + (strings.complete || 'Complete'));

        // Counters
        var $stats = $card.find('.ffc-migration-stats .ffc-migration-stat-value');
        // Order: Total, Migrated, Pending, Progress%.
        if ($stats.length >= 4) {
            $stats.eq(0).text(formatNumber(payload.total));
            $stats.eq(1).text(formatNumber(payload.migrated));
            $stats.eq(2).text(formatNumber(payload.pending));
            $stats.eq(3).text(percent + '%');
        }
    }

    function formatNumber(n) {
        var v = Number(n) || 0;
        return v.toLocaleString();
    }

    // The errors a run collected, as a list under the card's status line,
    // written as text.
    function showErrors($description, errors) {
        $description.nextAll('.ffc-migration-errors').remove();
        if (!errors.length) { return; }
        var $list = $('<ul class="ffc-migration-errors"></ul>');
        errors.forEach(function (msg) {
            $('<li></li>').text(String(msg)).appendTo($list);
        });
        $description.after($list);
    }

    function fill(template, value) {
        return String(template || '').replace('%s', value);
    }

    // One run at a time (#2338): a second click, or a click on another
    // card, cannot start a parallel loop. The busy button IS the flag, so
    // the state lives in the page and a reloaded page starts clean.
    function isRunning() {
        return $('.ffc-migration-actions a.ffc-migration-run.ffc-btn-loading').length > 0;
    }

    function setOthersWaiting($btn, waiting) {
        $('.ffc-migration-actions a.ffc-migration-run').not($btn).each(function () {
            var $other = $(this);
            if (waiting) {
                $other.addClass('disabled ffc-migration-waiting').attr('aria-disabled', 'true');
            } else if ($other.hasClass('ffc-migration-waiting')) {
                $other.removeClass('disabled ffc-migration-waiting').removeAttr('aria-disabled');
            }
        });
    }

    $(document).on('click', '.ffc-migration-actions a.ffc-migration-run', function (e) {
        var $btn         = $(this);
        var $card        = $btn.closest('.ffc-migration-card');
        var $description = $btn.next('.description');
        var key          = getMigrationKey($btn);
        if (!key) { return; }

        if (isRunning()) {
            e.preventDefault();
            window.FFC.status($description, 'warning', getStrings().otherRunning || 'Another migration is running. Wait for it to finish.');
            return;
        }

        var confirmMsg = $btn.data('confirm') || '';
        if (confirmMsg && !window.confirm(confirmMsg)) {
            e.preventDefault();
            return;
        }

        e.preventDefault();
        setOthersWaiting($btn, true);

        var initialStrings  = getStrings();
        var originalLabel   = $btn.text();
        var totalProcessed  = 0;
        var errors          = [];
        // Busy is the family's state (#1881): the label says what is running
        // and the ring after it turns; the icon class stays on the link.
        $btn.addClass('disabled ffc-btn-loading').attr('aria-disabled', 'true')
            .text(initialStrings.processing || 'Processing...');

        function release() {
            setOthersWaiting($btn, false);
            $btn.removeClass('disabled ffc-btn-loading').removeAttr('aria-disabled').text(originalLabel);
        }

        function collect(list) {
            (Array.isArray(list) ? list : []).forEach(function (msg) {
                if (errors.length < 50 && errors.indexOf(msg) === -1) {
                    errors.push(msg);
                }
            });
        }

        function runBatch() {
            window.FFC.request('ffc_migration_run_batch', {
                migration_key: key,
                nonce:         getNonce(),
            })
                .then(function (data) {
                    if (!data) { data = {}; }
                    var strings   = getStrings();
                    var processed = parseInt(data.processed, 10) || 0;
                    repaintCard($card, data);
                    collect(data.errors);

                    // Accumulate the REAL processed count from each batch,
                    // not iterations × 100 — last batch is often shorter.
                    totalProcessed += processed;
                    // The card's line is the shared status beside a control
                    // (#1843), written as text.
                    window.FFC.status($description, 'busy',
                        (strings.processed || 'Processed ')
                        + formatNumber(totalProcessed) + ' '
                        + (strings.records || 'records...')
                    );

                    if (data.is_complete) {
                        setOthersWaiting($btn, false);
                        $btn.removeClass('ffc-btn-loading ffc-icon-sync').addClass('ffc-icon-success')
                            .text(strings.migrationComplete || 'Migration Complete');
                        if (errors.length) {
                            // The success line says what went wrong on the way.
                            window.FFC.status($description, 'warning', fill(strings.completeWithErrors || 'Finished, with %s problems reported along the way:', formatNumber(errors.length)));
                            showErrors($description, errors);
                            return;
                        }
                        window.FFC.status($description, 'success', strings.allRecordsMigrated || 'All records have been successfully migrated.');
                        setTimeout(function () { window.location.reload(); }, 1500);
                        return;
                    }

                    // A batch that changed nothing short of completion will
                    // not change anything the next time either: stop and say
                    // so, rather than polling forever (#2338).
                    if (0 === processed || false === data.has_more) {
                        release();
                        window.FFC.status($description, 'error', fill(strings.stuck || 'Stopped: the last batch changed nothing and %s records are still pending.', formatNumber(data.pending)));
                        showErrors($description, errors);
                        return;
                    }

                    setTimeout(runBatch, 300);
                })
                .catch(function (err) {
                    var strings = getStrings();
                    var msg = (err && err.message) ? err.message : (strings.errorOccurred || 'Error occurred. Please try again.');
                    release();
                    window.FFC.status($description, 'error', msg);
                    showErrors($description, errors);
                });
        }

        runBatch();
    });
}(jQuery));
