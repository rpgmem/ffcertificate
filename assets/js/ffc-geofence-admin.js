/**
 * FFC Geofence Admin
 *
 * JavaScript for geofence metabox in form editor
 *
 * v3.1.0: Standardized to use event delegation pattern
 *
 * @since 3.0.0
 */

jQuery(document).ready(function($) {
    // The former inner "Date & Time / Geolocation" button bar was removed
    // when those sections became two top-level form-editor tabs (Time /
    // Geolocation), so the .ffc-geo-tab-btn handler is gone.

    // DateTime restrictions — visibility now handled by the generic
    // `.ffc-collapsed-target` initializer in ffc-admin.js (#238 / Sprint 3).
    // The <tbody> wrapping the sub-rows carries
    // `data-ffc-master="ffc_geofence_datetime_enabled"`. We still need to
    // re-evaluate the "time mode" row visibility when the master toggle
    // changes (the row depends on different-dates being set, not on the
    // master itself).
    $(document).on('change', 'input[name="ffc_geofence[datetime_enabled]"]', function() {
        toggleTimeModeRow();
    });

    // Show/hide time mode row based on date range
    function toggleTimeModeRow() {
        var dateStart = $('input[name="ffc_geofence[date_start]"]').val();
        var dateEnd = $('input[name="ffc_geofence[date_end]"]').val();

        // Only show time mode option if different dates are set
        if (dateStart && dateEnd && dateStart !== dateEnd) {
            $('#ffc-time-mode-row').slideDown(200);
        } else {
            $('#ffc-time-mode-row').slideUp(200);
        }
    }

    // Using event delegation for date changes
    $(document).on('change', 'input[name="ffc_geofence[date_start]"], input[name="ffc_geofence[date_end]"]', toggleTimeModeRow);
    toggleTimeModeRow(); // Run on load

    // ====================================================================
    // #159 S5: live date/time order validation + "during" dropdown toggle.
    //
    // The same rules that run server-side in
    // `Geofence::analyze_datetime_order()` (S2) run here as the operator
    // edits the inputs, so the red border (and inline error text) reflect
    // the current draft without waiting for Update. The "Display during,
    // outside daily slot" dropdown also slides in/out based on the
    // Time Behavior radio so the operator does not stare at a control
    // that has no effect in span mode.
    // ====================================================================

    var DATETIME_FIELDS = [
        'ffc_geofence[date_start]',
        'ffc_geofence[date_end]',
        'ffc_geofence[time_start]',
        'ffc_geofence[time_end]'
    ];

    function getDateTimeValues() {
        return {
            date_start: $('input[name="ffc_geofence[date_start]"]').val() || '',
            date_end:   $('input[name="ffc_geofence[date_end]"]').val()   || '',
            time_start: $('input[name="ffc_geofence[time_start]"]').val() || '',
            time_end:   $('input[name="ffc_geofence[time_end]"]').val()   || '',
            time_mode:  $('input[name="ffc_geofence[time_mode]"]:checked').val() || 'daily'
        };
    }

    function refreshDateTimeValidity() {
        var values = getDateTimeValues();
        // analyzeDateTimeOrder lives in ffc-geofence-validation.js (loaded
        // as a script dependency of this file) so it's also unit-testable
        // without going through this jQuery-bound IIFE.
        var errors = window.FFCGeofenceValidation.analyzeDateTimeOrder(values);

        // Toggle the .ffc-input-invalid class on each input based on the map.
        DATETIME_FIELDS.forEach(function(name) {
            var $input = $('input[name="' + name + '"]');
            var key    = name.replace('ffc_geofence[', '').replace(']', '');
            $input.toggleClass('ffc-input-invalid', !!errors[key]);
        });

        // Update / show / hide the inline error paragraph under Date Range.
        var $msg = $('p.ffc-datetime-order-error');
        if ($msg.length) {
            // One message represents the current rule violation (the helper
            // repeats the same message across paired fields).
            var firstMsg = '';
            for (var k in errors) {
                if (Object.prototype.hasOwnProperty.call(errors, k)) {
                    firstMsg = errors[k];
                    break;
                }
            }
            if (firstMsg) {
                $msg.text(firstMsg).show();
            } else {
                $msg.hide();
            }
        }
    }

    // Toggle visibility of the "Display during, outside daily slot" row
    // based on Time Behavior. The row carries the metabox-rendered
    // `display:none` server-side when time_mode='span'; this JS keeps it
    // in sync with live edits.
    function toggleDuringHideModeRow() {
        var timeMode = $('input[name="ffc_geofence[time_mode]"]:checked').val() || 'daily';
        var $row     = $('#ffc-datetime-hide-mode-during-row');
        if (timeMode === 'daily') {
            $row.show();
        } else {
            $row.hide();
        }
    }

    $(document).on(
        'change input',
        'input[name="ffc_geofence[date_start]"], input[name="ffc_geofence[date_end]"], input[name="ffc_geofence[time_start]"], input[name="ffc_geofence[time_end]"], input[name="ffc_geofence[time_mode]"]',
        function() {
            refreshDateTimeValidity();
            toggleDuringHideModeRow();
        }
    );

    refreshDateTimeValidity(); // Sync on load (covers first-paint state).
    toggleDuringHideModeRow(); // Sync on load.

    // Geolocation restrictions — visibility now handled by the generic
    // `.ffc-collapsed-target` initializer in ffc-admin.js. We still
    // re-validate the GPS-or-IP "at least one method" rule whenever the
    // master toggle flips on.
    $(document).on('change', 'input[name="ffc_geofence[geo_enabled]"]', function() {
        if ($(this).is(':checked')) {
            validateGeoMethods();
        }
    });

    // Validate that at least GPS or IP is enabled when geolocation is active
    function validateGeoMethods() {
        var geoEnabled = $('input[name="ffc_geofence[geo_enabled]"]').is(':checked');
        var gpsEnabled = $('input[name="ffc_geofence[geo_gps_enabled]"]').is(':checked');
        var ipEnabled = $('input[name="ffc_geofence[geo_ip_enabled]"]').is(':checked');

        if (geoEnabled && !gpsEnabled && !ipEnabled) {
            // Auto-enable GPS as default
            $('input[name="ffc_geofence[geo_gps_enabled]"]').prop('checked', true);
        }
    }

    // Prevent unchecking both GPS and IP when geolocation is enabled - Using event delegation
    $(document).on('change', 'input[name="ffc_geofence[geo_gps_enabled]"], input[name="ffc_geofence[geo_ip_enabled]"]', function() {
        var geoEnabled = $('input[name="ffc_geofence[geo_enabled]"]').is(':checked');
        var gpsEnabled = $('input[name="ffc_geofence[geo_gps_enabled]"]').is(':checked');
        var ipEnabled = $('input[name="ffc_geofence[geo_ip_enabled]"]').is(':checked');

        if (geoEnabled && !gpsEnabled && !ipEnabled) {
            alert(ffc_geofence_admin.alert_message);
            $(this).prop('checked', true);
        }
    });
});
