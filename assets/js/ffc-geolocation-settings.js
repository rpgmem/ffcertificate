/**
 * FFC Geolocation Settings — preset toggle + per-case radio snap.
 *
 * The "When GPS fails" combobox has four options: Tolerant, Hybrid,
 * Strict, Custom. The first three are presets that snap the per-case
 * radio table to a fixed allow/block matrix; Custom unhides the table
 * so the admin can edit each case individually. The matrix comes from
 * PHP via `ffcGeolocationSettings.presetCases` so JS and PHP stay in
 * sync without duplicating the map.
 *
 * @since 6.5.4
 */
(function ($) {
    'use strict';

    $(function () {
        var $preset = $('#ffc_gps_fallback_preset');
        if (0 === $preset.length) {
            return;
        }
        var $table = $('.ffc-gps-fallback-cases');
        if (0 === $table.length) {
            return;
        }

        var presetCases = (window.ffcGeolocationSettings && window.ffcGeolocationSettings.presetCases) || {};

        function applyPreset(preset) {
            if ('custom' === preset) {
                $table.removeAttr('hidden');
                return;
            }
            $table.attr('hidden', 'hidden');
            var cases = presetCases[preset];
            if (!cases) {
                return;
            }
            // Snap each per-case radio to the preset's default value.
            Object.keys(cases).forEach(function (caseKey) {
                var value = cases[caseKey];
                var $radio = $table.find('input[name="gps_fallback_cases[' + caseKey + ']"][value="' + value + '"]');
                $radio.prop('checked', true);
            });
        }

        $preset.on('change', function () {
            applyPreset($(this).val());
        });
    });
}(jQuery));
