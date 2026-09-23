/**
 * "What would this merge move?" for the identity queue (#1397 sprint 5).
 *
 * A merge is the one verb on this screen no other undoes, so the confirmation
 * has to be specific rather than solemn: who stays, who empties, and how many
 * records move, per store. Every number here comes from the server, and on the
 * server from `IdentityMerge::plan()` — the write's own resolution, asked for
 * before the write rather than reconstructed beside it.
 *
 * It renders; it decides nothing. In particular it does not choose a survivor:
 * it reports how much each login holds, which is evidence, and the operator
 * chooses.
 *
 * @since 6.28.4
 */
(function ($) {
    'use strict';

    var cfg = window.ffcIdentityMergePreview || {};

    /**
     * Fill a %s / %1$s template the way `sprintf` would for our few cases.
     *
     * @param {string} template
     * @param {Array}  args
     * @returns {string}
     */
    function format(template, args) {
        var out = String(template || '');

        args.forEach(function (value, index) {
            out = out
                .split('%' + (index + 1) + '$s').join(value)
                .replace('%s', value);
        });

        return out;
    }

    /**
     * Append one line to a preview region.
     *
     * @param {jQuery} $region
     * @param {string} text
     * @param {string} tone
     * @returns {jQuery}
     */
    function line($region, text, tone) {
        return $region.append(
            $('<p/>', { 'class': 'ffc-identity-preview-line' + (tone ? ' ffc-identity-preview-' + tone : '') })
                .text(text)
        );
    }

    /**
     * Render one answer.
     *
     * @param {jQuery} $region
     * @param {Object} data
     */
    function paint($region, data) {
        $region.empty();

        if (!data.allowed) {
            line($region, data.message, 'bad');
            return;
        }

        line($region, format($region.data('heading') || '', [
            data.survivor.name || ('#' + data.survivor.id),
            data.absorbed.name || ('#' + data.absorbed.id)
        ]), 'head');

        line($region, format($region.data('total') || '', [data.total]), 'total');

        // A store holding nothing is printed as zero rather than dropped: an
        // absent row and a row reading zero are the same fact, but only the
        // second says it was looked at.
        var $stores = $('<ul/>', { 'class': 'ffc-identity-preview-stores' });

        (data.stores || []).forEach(function (store) {
            $('<li/>').text(format($region.data('store') || '', [store.records, store.store])).appendTo($stores);
        });

        $region.append($stores);

        if ((data.gains || []).length) {
            line($region, format($region.data('gains') || '', [data.gains.join(', ')]), null);
        }

        line($region, format($region.data('holds') || '', [
            data.survivor.name || ('#' + data.survivor.id),
            data.survivor.records
        ]), null);
        line($region, format($region.data('holds') || '', [
            data.absorbed.name || ('#' + data.absorbed.id),
            data.absorbed.records
        ]), null);
    }

    /**
     * Ask what this pair would move.
     *
     * @param {jQuery} $button
     */
    function preview($button) {
        var $region = $('#' + $button.data('ffcRegion'));
        var $form = $button.closest('form');
        var keep = $form.find('.ffc-identity-keep:checked').val();

        if (!$region.length) {
            return;
        }

        // The survivor decides the direction, so there is nothing to preview
        // until one is chosen — and guessing one would be the screen making
        // the decision this verb exists to put to a person.
        if (!keep) {
            $region.empty();
            line($region, $region.data('choose') || '', 'bad');
            return;
        }

        var a = String($button.data('ffcA'));
        var b = String($button.data('ffcB'));

        $button.prop('disabled', true);

        $.post(cfg.ajaxUrl, {
            action: cfg.action,
            nonce: cfg.nonce,
            survivor: keep,
            absorbed: String(keep) === a ? b : a
        }).done(function (response) {
            if (!response || !response.success || !response.data) {
                $region.empty();
                line($region, $region.data('failed') || '', 'bad');
                return;
            }

            paint($region, response.data);
        }).fail(function () {
            $region.empty();
            line($region, $region.data('failed') || '', 'bad');
        }).always(function () {
            $button.prop('disabled', false);
        });
    }

    /**
     * Bind to whatever is in the document now. Idempotent, like its siblings.
     *
     * @returns {boolean} Whether there was anything to bind.
     */
    function boot() {
        if (!cfg.ajaxUrl || !$('.ffc-identity-preview').length) {
            return false;
        }

        $(document).off('.ffcIdentityMergePreview')
            .on('click.ffcIdentityMergePreview', '.ffc-identity-preview', function (event) {
                event.preventDefault();
                preview($(this));
            })
            // A preview taken against one survivor says nothing about the
            // other, so changing the choice clears it rather than leaving a
            // count the operator would read as current.
            .on('change.ffcIdentityMergePreview', '.ffc-identity-keep', function () {
                var $button = $(this).closest('form').find('.ffc-identity-preview');

                $('#' + $button.data('ffcRegion')).empty();
            });

        return true;
    }

    window.FFC = window.FFC || {};
    window.FFC.IdentityMergePreview = { boot: boot };

    $(boot);
}(jQuery));
