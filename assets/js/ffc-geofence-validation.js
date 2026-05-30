/**
 * FFC Geofence Validation
 *
 * Pure date/time order validator extracted from ffc-geofence-admin.js per
 * S2 of #163. Mirrors `Geofence::analyze_datetime_order()` on the server
 * so the live red-border feedback in the form-editor metabox matches the
 * save-time validation byte for byte.
 *
 * Public API:
 *
 *   window.FFCGeofenceValidation.analyzeDateTimeOrder( values ): { fieldName → message }
 *
 * where `values` is an object with optional `date_start`, `date_end`,
 * `time_start`, `time_end`, `time_mode` ('daily' | 'span', default
 * 'daily'). The return is empty when the configuration passes order
 * validation or when the relevant inputs are not yet filled in.
 *
 * No jQuery dependency — pure JS over plain objects. Loaded as a script
 * dependency of `ffc-geofence-admin` in the form-editor enqueue.
 *
 * @package FFC
 * @since 6.5.4
 */

(function () {
    'use strict';

    // Default English copy — used when the PHP-side localization
    // (`window.ffcGeofenceMessages`, set by FormEditor::enqueue_scripts())
    // didn't load, e.g. unit tests, or when admin pages outside the form
    // editor load this script bare. Loco translates the PHP `__()` strings
    // that produce the localized values.
    var DEFAULT_MESSAGES = {
        date_order: 'End date is earlier than the start date.',
        span_order: 'In span mode, the end datetime must be after the start datetime.',
        daily_order: 'End time must be later than start time. For an overnight single event, switch the Time Mode to "Span" and set the end date to the next day.'
    };

    function msg(key) {
        var src = (typeof window !== 'undefined' && window.ffcGeofenceMessages) || {};
        return src[key] || DEFAULT_MESSAGES[key];
    }

    function analyzeDateTimeOrder(v) {
        var errors = {};
        // Match PHP default — `time_mode` omitted means 'daily'. The
        // metabox form always supplies a value, but tests and any future
        // direct caller benefit from the same default the server uses.
        var timeMode = v.time_mode || 'daily';

        if (v.date_start && v.date_end && v.date_end < v.date_start) {
            var dateMsg = msg('date_order');
            errors.date_start = dateMsg;
            errors.date_end   = dateMsg;
            // Early return: subsequent span/daily checks would just stack
            // a redundant error on the same pair of inputs.
            return errors;
        }

        if (timeMode === 'span' && v.date_start && v.date_end && v.time_start && v.time_end) {
            var startComposed = v.date_start + ' ' + v.time_start;
            var endComposed   = v.date_end   + ' ' + v.time_end;
            if (endComposed <= startComposed) {
                var spanMsg = msg('span_order');
                errors.time_start = spanMsg;
                errors.time_end   = spanMsg;
            }
            return errors;
        }

        if (timeMode === 'daily' && v.time_start && v.time_end && v.time_end <= v.time_start) {
            var dailyMsg = msg('daily_order');
            errors.time_start = dailyMsg;
            errors.time_end   = dailyMsg;
        }

        return errors;
    }

    if (typeof window !== 'undefined') {
        window.FFCGeofenceValidation = window.FFCGeofenceValidation || {};
        window.FFCGeofenceValidation.analyzeDateTimeOrder = analyzeDateTimeOrder;
    }
})();
