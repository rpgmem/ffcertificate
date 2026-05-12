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
    // Tab switching - Using event delegation
    $(document).on('click', '.ffc-geo-tab-btn', function() {
        var tab = $(this).data('tab');
        $('.ffc-geo-tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.ffc-geo-tab-content').removeClass('active');
        $('#ffc-tab-' + tab).addClass('active');
    });

    // DateTime restrictions - Enable/Disable fields based on checkbox
    function toggleDateTimeFields() {
        var enabled = $('input[name="ffc_geofence[datetime_enabled]"]').is(':checked');
        $('#ffc-tab-datetime input[type="date"], #ffc-tab-datetime input[type="time"], #ffc-tab-datetime select, #ffc-tab-datetime textarea, #ffc-tab-datetime input[type="radio"]')
            .not('input[name="ffc_geofence[datetime_enabled]"]')
            .prop('disabled', !enabled)
            .closest('tr').css('opacity', enabled ? '1' : '0.5');

        // Also check if time mode row should be visible
        toggleTimeModeRow();
    }

    // Using event delegation for datetime enabled checkbox
    $(document).on('change', 'input[name="ffc_geofence[datetime_enabled]"]', toggleDateTimeFields);
    toggleDateTimeFields(); // Run on load

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

    // Geolocation restrictions - Enable/Disable fields based on checkbox
    function toggleGeoFields() {
        var enabled = $('input[name="ffc_geofence[geo_enabled]"]').is(':checked');
        $('#ffc-tab-geolocation input[type="checkbox"], #ffc-tab-geolocation textarea, #ffc-tab-geolocation select')
            .not('input[name="ffc_geofence[geo_enabled]"]')
            .prop('disabled', !enabled)
            .closest('tr').css('opacity', enabled ? '1' : '0.5');

        // If geolocation is enabled, ensure at least one method is selected
        if (enabled) {
            validateGeoMethods();
        }
    }

    // Using event delegation for geo enabled checkbox
    $(document).on('change', 'input[name="ffc_geofence[geo_enabled]"]', function() {
        toggleGeoFields();

        // When geolocation is enabled, validate methods
        if ($(this).is(':checked')) {
            validateGeoMethods();
        }
    });
    toggleGeoFields(); // Run on load

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
