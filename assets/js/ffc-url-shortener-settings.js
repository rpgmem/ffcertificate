/**
 * URL Shortener Settings tab JavaScript.
 *
 * Two concerns:
 *  1. Couple the "Expose" checkbox to its row's "Shorten" checkbox — Expose is
 *     only meaningful when the type is shortened (mirrors the server-side
 *     Expose ⊆ Shorten intersection). Disabled + unchecked when Shorten is off.
 *  2. Drive the "Generate missing short URLs" backfill: a keyset AJAX loop that
 *     pages `cursor → next_cursor` until the server reports `done`.
 *
 * @since 6.18.0
 */
(function ($) {
    'use strict';

    var settings = window.ffcUrlShortenerSettings || {};

    function t(key, fallback) {
        return (settings.i18n && settings.i18n[key]) || fallback;
    }

    /**
     * Enable/disable a row's Expose checkbox to follow its Shorten checkbox.
     *
     * @param {HTMLElement} shortenEl The `.ffc-shorten-type` checkbox.
     */
    function syncExposeRow(shortenEl) {
        var $shorten = $(shortenEl);
        var $expose = $shorten.closest('tr').find('.ffc-expose-type');
        if (!$expose.length) {
            return;
        }
        if ($shorten.prop('checked')) {
            $expose.prop('disabled', false);
        } else {
            $expose.prop('checked', false).prop('disabled', true);
        }
    }

    function bindCoupling() {
        $(document).on('change', '.ffc-shorten-type', function () {
            syncExposeRow(this);
        });
        // Normalize initial state (defensive — server already renders disabled).
        $('.ffc-shorten-type').each(function () {
            syncExposeRow(this);
        });
    }

    /**
     * Run one backfill batch, then recurse until done.
     *
     * @param {jQuery} $button  The trigger button.
     * @param {jQuery} $status  The status element.
     * @param {number} cursor   Keyset cursor (0 on the first request).
     * @param {number} created  Running count of created short URLs.
     * @param {number} total    Backlog total (0 until the first response fills it).
     */
    function runBatch($button, $status, cursor, created, total) {
        window.FFC.request('ffc_url_shortener_backfill', { cursor: cursor }, {
            nonce: $('#ffc_url_shortener_backfill_nonce').val(),
            ajaxUrl: settings.ajaxUrl || window.ajaxurl
        }).then(function (data) {
            // FFC.request rejects on !success; a resolved-but-empty payload
            // (success with null data) still counts as an error here.
            if (!data) {
                $status.text(t('error', 'An error occurred.'));
                $button.prop('disabled', false);
                return;
            }

            created += (data.created || 0);
            if (typeof data.total !== 'undefined') {
                total = data.total;
            }

            if (data.done) {
                $status.text(
                    t('done', 'Done.') + ' ' +
                    created + ' ' + t('createdSuffix', 'short URLs created.')
                );
                $button.prop('disabled', false);
                return;
            }

            var progress = total > 0
                ? created + ' / ' + total
                : String(created);
            $status.text(t('working', 'Generating…') + ' ' + progress);

            runBatch($button, $status, data.next_cursor || 0, created, total);
        }).catch(function () {
            $status.text(t('error', 'An error occurred.'));
            $button.prop('disabled', false);
        });
    }

    function bindBackfill() {
        $(document).on('click', '#ffc-url-shortener-backfill', function (e) {
            e.preventDefault();
            var $button = $(this);
            var $status = $('#ffc-url-shortener-backfill-status');
            $button.prop('disabled', true);
            $status.text(t('working', 'Generating…'));
            runBatch($button, $status, 0, 0, 0);
        });
    }

    $(function () {
        bindCoupling();
        bindBackfill();
    });

    // Exposed for unit testing.
    window.ffcUrlShortenerSettingsInternal = {
        syncExposeRow: syncExposeRow,
        runBatch: runBatch
    };
})(jQuery);
