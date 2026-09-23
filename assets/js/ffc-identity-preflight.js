/**
 * "What would this number do?" for the identity queue (#1397 sprint 4).
 *
 * The correction field takes a number HR confirmed, and until now the only
 * way to learn what it would do was to do it. The most useful answer is the
 * one that is not a failure: the number already belongs to another account,
 * which means these records are probably theirs and moving them is a verb
 * this screen already has.
 *
 * Every verdict here comes from the server, and on the server from the
 * repair's own checks minus the write. This file decides nothing; it renders.
 *
 * The typed value travels browser -> server, which is what a correction is.
 * Nothing stored comes back: the response carries an account, a name, a row
 * count and the refusal's own sentence.
 *
 * @since 6.28.4
 */
(function ($) {
    'use strict';

    var cfg = window.ffcIdentityPreflight || {};

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
     * Show one line in a finding's verdict region.
     *
     * @param {jQuery} $region
     * @param {string} text
     * @param {string} tone `ok`, `warn` or `bad`.
     * @returns {jQuery} The region, emptied and repainted.
     */
    function say($region, text, tone) {
        return $region.empty().append(
            $('<p/>', { 'class': 'ffc-identity-verdict-line ffc-identity-verdict-' + tone }).text(text)
        );
    }

    /**
     * Paint one answer.
     *
     * A collision is the one refusal that is not a failure: the number belongs
     * to somebody, and knowing to whom is the answer. It is NOT offered a move
     * — on a collision the holder carries the confirmed value in the very
     * field these records carry wrongly, which the agreement rule calls a
     * conflict, so a move would be refused every time. It is a merge decision,
     * which is what the server's own sentence says.
     *
     * @param {jQuery} $region
     * @param {Object} data
     */
    function paint($region, data) {
        if (data.allowed) {
            say(
                $region,
                format(
                    $region.data(data.consolidates ? 'consolidates' : 'allowed') || '',
                    [data.rows]
                ),
                'ok'
            );
            return;
        }

        if (!data.holder) {
            say($region, data.message, 'bad');
            return;
        }

        say(
            $region,
            format($region.data('holder') || '', [data.holder.name || ('#' + data.holder.id), data.holder.id]),
            'warn'
        );

        $region.append(
            $('<p/>', { 'class': 'ffc-identity-verdict-line' }).append(
                $('<a/>', {
                    href: String($region.data('profile') || '') + encodeURIComponent(data.holder.id),
                    text: $region.data('open') || ''
                })
            )
        );
    }

    /**
     * Ask what the typed number would do.
     *
     * @param {jQuery} $button
     */
    function check($button) {
        var $region = $('#' + $button.data('ffcVerdict'));
        var $value = $('#' + $button.data('ffcValue'));
        var typed = String($value.val() || '').trim();

        if (!$region.length) {
            return;
        }

        if (!typed) {
            say($region, $region.data('empty') || '', 'bad');
            $value.trigger('focus');
            return;
        }

        $button.prop('disabled', true);

        $.post(cfg.ajaxUrl, {
            action: cfg.action,
            nonce: cfg.nonce,
            subject: $button.data('ffcSubject'),
            field: $button.data('ffcField'),
            value: typed
        }).done(function (response) {
            if (!response || !response.success || !response.data) {
                say($region, $region.data('failed') || '', 'bad');
                return;
            }

            paint($region, response.data);
        }).fail(function () {
            say($region, $region.data('failed') || '', 'bad');
        }).always(function () {
            $button.prop('disabled', false);
        });
    }

    /**
     * Bind to whatever is in the document now. Idempotent, like its sibling:
     * the namespace is what makes a second call replace rather than add.
     *
     * @returns {boolean} Whether there was anything to bind.
     */
    function boot() {
        if (!cfg.ajaxUrl || !$('.ffc-identity-check').length) {
            return false;
        }

        $(document).off('.ffcIdentityPreflight')
            .on('click.ffcIdentityPreflight', '.ffc-identity-check', function (event) {
                event.preventDefault();
                check($(this));
            });

        return true;
    }

    window.FFC = window.FFC || {};
    window.FFC.IdentityPreflight = { boot: boot };

    $(boot);
}(jQuery));
