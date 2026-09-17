/**
 * Reregistration CSV import — the operator's half of the four-phase job (#1214).
 *
 * Two clicks, deliberately, and that is the one place this client departs from
 * the recruitment importer it otherwise mirrors. There, validate flows straight
 * into promote; here it stops and shows the report. The validate phase exists
 * to be READ — it tells the operator how many rows are ready, who is skipped
 * because they already submitted, and which lines fail — and a client that
 * promotes on its own makes that report decorative. This import writes to
 * people's records and cannot be undone, so the second click is the consent.
 *
 * @since 6.26.0
 * @package FreeFormCertificate
 */
(function ($) {
    'use strict';

    /**
     * The staged job waiting for its second click, or null.
     *
     * It is dropped whenever the file or the audience changes: the staged rows
     * belong to the file that was checked, so promoting after a swap would
     * import the previous one while the operator watched the new name in the
     * field. The abandoned job is reaped by the service's TTL sweep.
     */
    var job = null;

    function cfg() {
        return window.ffcReregImport || {};
    }

    function str(key, fallback) {
        var s = cfg().strings || {};
        return s[key] || fallback;
    }

    function $box() {
        return $('.ffc-rereg-import-box');
    }

    function setStatus(text) {
        $box().find('.ffc-rereg-import-status').text(text || '');
    }

    function setBusy(busy) {
        $box().find('.ffc-rereg-import-check, .ffc-rereg-import-file, .ffc-rereg-import-audience').prop('disabled', busy);
        // The Import button has its own rule: busy disables it, and idle only
        // re-enables it when there is a clean job to apply.
        $box().find('.ffc-rereg-import-apply').prop('disabled', busy || !job);
    }

    function clearReport() {
        var $report = $box().find('.ffc-rereg-import-report');
        $report.attr('hidden', true);
        $report.find('.ffc-rereg-import-counts').empty();
        $report.find('.ffc-rereg-import-problems').empty();
    }

    function dropJob() {
        job = null;
        clearReport();
        setStatus('');
        $box().find('.ffc-rereg-import-apply').prop('disabled', true);
    }

    /**
     * Render one count line.
     *
     * @param {string} label Text.
     * @param {number} value Count.
     * @param {string} kind  Modifier suffix: ready | skipped | failed | total.
     */
    function countItem(label, value, kind) {
        return $('<li>')
            .addClass('ffc-rereg-import-count ffc-rereg-import-count-' + kind)
            .text(label + ' ' + value);
    }

    function renderReport(report) {
        var $report = $box().find('.ffc-rereg-import-report');
        var $counts = $report.find('.ffc-rereg-import-counts').empty();
        var $problems = $report.find('.ffc-rereg-import-problems').empty();

        $counts.append(countItem(str('countTotal', 'Rows in the file:'), report.total, 'total'));
        $counts.append(countItem(str('countReady', 'Will be imported:'), report.ready, 'ready'));
        $counts.append(countItem(str('countSkipped', 'Already submitted, kept as is:'), report.skipped, 'skipped'));
        $counts.append(countItem(str('countFailed', 'Failing:'), report.failed, 'failed'));

        if (report.failures && report.failures.length) {
            $problems.append($('<p>').addClass('ffc-rereg-import-blocked')
                .text(str('blocked', 'Nothing was imported. Fix these lines and check the file again:')));
            var $list = $('<ul>').addClass('ffc-rereg-import-failures');
            report.failures.forEach(function (line) {
                $list.append($('<li>').text(line));
            });
            $problems.append($list);
        }

        $report.removeAttr('hidden');
    }

    /**
     * Phase 1. `FFC.request` cannot carry a file — it builds either an object
     * or a serialised string — so the upload goes through jQuery directly with
     * `processData` and `contentType` off, which is what lets the browser set
     * its own multipart boundary.
     *
     * @param {File}   file       Chosen CSV.
     * @param {string} audienceId Audience the columns belong to.
     * @param {string} reregId    Campaign.
     * @return {Promise<Object>} Resolves with the started job.
     */
    function start(file, audienceId, reregId) {
        var fd = new FormData();
        fd.append('action', 'ffc_rereg_import_start');
        fd.append('nonce', cfg().nonce || '');
        fd.append('rereg_id', reregId);
        fd.append('audience_id', audienceId);
        fd.append('csv_file', file);

        return new Promise(function (resolve, reject) {
            $.ajax({
                url: cfg().ajaxUrl,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false
            }).done(function (res) {
                if (res && res.success) {
                    resolve(res.data);
                    return;
                }
                reject(new Error((res && res.data && res.data.message) || str('error', 'An error occurred.')));
            }).fail(function () {
                reject(new Error(str('network', 'The server could not be reached.')));
            });
        });
    }

    function call(action, data) {
        return FFC.request(action, data, { nonce: cfg().nonce, ajaxUrl: cfg().ajaxUrl });
    }

    /**
     * "Check file" — stage and validate, then stop.
     */
    function check() {
        var $btn = $('#ffc-rereg-import-check');
        var file = ($('#ffc-rereg-import-file')[0] || {}).files;
        var audienceId = $('#ffc-rereg-import-audience').val();
        var reregId = $btn.data('rereg-id');

        if (!file || !file.length) {
            setStatus(str('chooseFile', 'Choose a CSV file.'));
            return;
        }

        dropJob();
        setBusy(true);
        setStatus(str('staging', 'Reading the file…'));

        start(file[0], audienceId, reregId).then(function (started) {
            setStatus(str('validating', 'Checking every row…'));
            return call('ffc_rereg_import_validate', { job_id: started.jobId }).then(function (report) {
                renderReport(report);
                if (report.ok) {
                    job = started.jobId;
                    setStatus(str('ready', 'Ready. Press Import to write these rows.'));
                } else {
                    // A blocked job stays staged until the TTL sweep: there is
                    // nothing to apply, and re-uploading is the fix.
                    job = null;
                    setStatus('');
                }
                setBusy(false);
            });
        }).catch(function (err) {
            setBusy(false);
            setStatus((err && err.message) || str('error', 'An error occurred.'));
        });
    }

    /**
     * "Import" — promote until done, then commit.
     */
    function apply() {
        if (!job) { return; }

        var jobId = job;
        setBusy(true);

        function nextBatch() {
            return call('ffc_rereg_import_promote', { job_id: jobId }).then(function (batch) {
                setStatus(
                    (str('importing', 'Importing %1$d/%2$d…'))
                        .replace('%1$d', batch.processed)
                        .replace('%2$d', batch.total)
                );
                if (!batch.done) {
                    return nextBatch();
                }
                return batch;
            });
        }

        setStatus(str('importing', 'Importing %1$d/%2$d…').replace('%1$d', 0).replace('%2$d', '?'));

        nextBatch().then(function () {
            setStatus(str('finishing', 'Finishing…'));
            return call('ffc_rereg_import_commit', { job_id: jobId }).then(function (done) {
                job = null;
                setStatus(
                    str('done', 'Imported %1$d. Skipped %2$d.')
                        .replace('%1$d', done.promoted)
                        .replace('%2$d', done.skipped)
                );
                setBusy(false);
                $('#ffc-rereg-import-apply').prop('disabled', true);
            });
        }).catch(function (err) {
            setBusy(false);
            // A half-promoted job is NOT retried automatically. Promotion is
            // idempotent per row (a promoted row is `processed = 1` and the
            // next batch skips it), so pressing Import again resumes rather
            // than duplicating — but that is the operator's call to make after
            // reading why it stopped.
            setStatus((err && err.message) || str('error', 'An error occurred.'));
            $('#ffc-rereg-import-apply').prop('disabled', false);
        });
    }

    // Delegated from `document` at load, never from inside an init that could
    // early-return on this screen -- the #783 failure mode, which dropped the
    // audience-bookings export handler in exactly that way.
    $(document).on('click', '#ffc-rereg-import-check', check);
    $(document).on('click', '#ffc-rereg-import-apply', apply);
    $(document).on('change', '#ffc-rereg-import-file, #ffc-rereg-import-audience', dropJob);
})(jQuery);
