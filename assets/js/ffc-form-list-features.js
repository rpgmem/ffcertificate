/**
 * FFC Forms-List Features — inline AJAX for the per-row toggles.
 *
 * Each `.ffc-features-toggle` carries data-ffc-form-id and
 * data-ffc-feature. On change we POST to ffc_update_form_feature; on
 * error the toggle rolls back to the previous state so the UI never
 * lies about the persisted value.
 *
 * @since 6.5.6
 */
(function ($) {
    'use strict';

    if (!window.FFC || !window.FFC.request) {
        return;
    }

    var cfg     = window.ffcFormListFeatures || {};
    var strings = cfg.strings || {};
    var nonce   = cfg.nonce || '';

    function setBadgeState($badge, state, text) {
        if (!$badge || !$badge.length) { return; }
        $badge
            .removeClass('ffc-features-badge--saving ffc-features-badge--saved ffc-features-badge--error')
            .addClass('ffc-features-badge--' + state)
            .text(text || '')
            .removeAttr('hidden');
    }

    function hideBadgeLater($badge) {
        if (!$badge || !$badge.length) { return; }
        setTimeout(function () {
            $badge.attr('hidden', 'hidden').text('');
        }, 1800);
    }

    $(document).on('change', '.ffc-features-toggle input[type="checkbox"]', function () {
        var $input  = $(this);
        var formId  = $input.data('ffc-form-id');
        var feature = $input.data('ffc-feature');
        if (!formId || !feature) { return; }

        var newValue = $input.is(':checked');
        var $row     = $input.closest('tr');
        var $badge   = $row.find('.ffc-features-badge').first();

        setBadgeState($badge, 'saving', strings.saving || 'Saving…');
        $input.prop('disabled', true);

        window.FFC.request('ffc_update_form_feature', {
            form_id: formId,
            feature: feature,
            value:   newValue ? '1' : '0',
            nonce:   nonce,
        })
            .then(function () {
                $input.prop('disabled', false);
                setBadgeState($badge, 'saved', strings.saved || 'Saved');
                hideBadgeLater($badge);
            })
            .catch(function (err) {
                // Roll the toggle back so the displayed state matches
                // the server. Re-enable the input so the user can retry.
                $input.prop('checked', !newValue).prop('disabled', false);
                var msg = (err && err.message) ? err.message : (strings.error || 'Save failed');
                setBadgeState($badge, 'error', msg);
            });
    });
}(jQuery));
