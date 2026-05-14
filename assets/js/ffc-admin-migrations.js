/**
 * FFC Admin Migrations — batch runner with JSON progress polling.
 *
 * Intercepts the migration "Run" link, then loops a small POST to the
 * `ffc_migration_run_batch` AJAX endpoint until the response reports
 * `is_complete=true`. Each iteration repaints the progress bar +
 * counters from the JSON payload — no full-page-HTML parsing.
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

    function repaintCard($card, payload) {
        var strings = getStrings();
        // Progress bar
        var percent = parseFloat(payload.percent || 0).toFixed(1);
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

    $(document).on('click', '.ffc-migration-actions a.button-primary', function (e) {
        var $btn         = $(this);
        var $card        = $btn.closest('.ffc-migration-card');
        var $description = $btn.next('.description');
        var key          = getMigrationKey($btn);
        if (!key) { return; }

        var confirmMsg = $btn.data('confirm') || '';
        if (confirmMsg && !window.confirm(confirmMsg)) {
            e.preventDefault();
            return;
        }

        e.preventDefault();
        var initialStrings  = getStrings();
        var originalBtnHtml = $btn.html();
        var totalProcessed  = 0;
        $btn.prop('disabled', true).addClass('disabled')
            .html('<span class="dashicons dashicons-update dashicons-spin"></span> ' + (initialStrings.processing || 'Processing...'));

        function runBatch() {
            window.FFC.request('ffc_migration_run_batch', {
                migration_key: key,
                nonce:         getNonce(),
            })
                .then(function (data) {
                    if (!data) { data = {}; }
                    var strings = getStrings();
                    repaintCard($card, data);

                    // Accumulate the REAL processed count from each batch,
                    // not iterations × 100 — last batch is often shorter.
                    totalProcessed += parseInt(data.processed, 10) || 0;
                    $description.html(
                        (strings.processed || 'Processed ')
                        + '<strong>' + formatNumber(totalProcessed) + '</strong> '
                        + (strings.records || 'records...')
                    );

                    if (data.is_complete) {
                        $btn.html('<span class="dashicons dashicons-yes-alt"></span> ' + (strings.migrationComplete || 'Migration Complete'));
                        $description.html('✓ ' + (strings.allRecordsMigrated || 'All records have been successfully migrated.'));
                        setTimeout(function () { window.location.reload(); }, 1500);
                    } else {
                        setTimeout(runBatch, 300);
                    }
                })
                .catch(function (err) {
                    var strings = getStrings();
                    var msg = (err && err.message) ? err.message : (strings.errorOccurred || 'Error occurred. Please try again.');
                    $btn.prop('disabled', false).removeClass('disabled').html(originalBtnHtml);
                    $description.html('<span class="ffc-text-error">✗ ' + msg + '</span>');
                });
        }

        runBatch();
    });
}(jQuery));
