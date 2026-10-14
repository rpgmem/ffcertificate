/**
 * FFC Geolocation Settings — "When GPS fails" steps + per-case grid.
 *
 * The three presets (Tolerant, Hybrid, Strict) are a steps control; each
 * snaps the per-case radio grid to a fixed allow/block matrix. The grid
 * itself is the `custom` value, behind the "Customize per case" toggle
 * (#1758): while it is on, the steps do not apply and are disabled; turning
 * it off snaps the grid back to the chosen step. Showing and hiding the grid
 * is the generic `.ffc-collapsed-target` pattern in ffc-admin.js.
 *
 * The matrix comes from PHP via `ffcGeolocationSettings.presetCases` so JS
 * and PHP stay in sync without duplicating the map.
 *
 * @since 6.5.4
 */
(function ($) {
    'use strict';

    $(function () {
        // Auto-save wiring for `data-ffc-autosave-key` inputs lives in
        // ffc-admin-autosave.js (generic page-init). This file only
        // handles the preset → per-case radio snap.

        var $steps = $('#ffc_gps_fallback_preset');
        if (0 === $steps.length) {
            return;
        }
        var $table = $('.ffc-gps-fallback-cases');
        if (0 === $table.length) {
            return;
        }
        var $custom = $('#ffc_gps_fallback_custom');

        var presetCases = (window.ffcGeolocationSettings && window.ffcGeolocationSettings.presetCases) || {};

        function chosenPreset() {
            return $steps.find('input[name="gps_fallback_preset"]:checked').val();
        }

        function applyPreset(preset) {
            var cases = presetCases[preset];
            if (!cases) {
                return;
            }
            // Snap each per-case radio to the preset's value.
            Object.keys(cases).forEach(function (caseKey) {
                var value = cases[caseKey];
                $table.find('input[name="gps_fallback_cases[' + caseKey + ']"][value="' + value + '"]').prop('checked', true);
            });
        }

        $steps.on('change', 'input[name="gps_fallback_preset"]', function () {
            applyPreset($(this).val());
        });

        $custom.on('change', function () {
            var isCustom = $(this).is(':checked');
            $steps.prop('disabled', isCustom);
            if (!isCustom) {
                applyPreset(chosenPreset());
            }
        });
    });
}(jQuery));
