/**
 * FFC Geofence Frontend — date/time window validation.
 *
 * Extends window.FFCGeofence (ffc-geofence-frontend.js) with validateDateTime.
 * Methods stay on the shared object so `this.*` resolves as before.
 *
 * @package FFC
 * @since 3.0.0 (split out of ffc-geofence-frontend.js)
 */

(function() {
    'use strict';

    var FFCGeofence = window.FFCGeofence;

    Object.assign(FFCGeofence, {

        /**
         * Validate date/time restrictions
         *
         * @param {object} config DateTime configuration
         * @returns {object} {valid: boolean, message: string, phase?: 'before'|'during'|'after'}
         */
        validateDateTime: function(config) {
            const now = new Date();
            const currentDate = this.formatDate(now);
            const currentTime = this.formatTime(now);
            const timeMode = config.timeMode || 'daily';

            this.debug('DateTime validation', {
                currentDate,
                currentTime,
                dateStart: config.dateStart,
                dateEnd: config.dateEnd,
                timeStart: config.timeStart,
                timeEnd: config.timeEnd,
                timeMode: timeMode
            });

            // Determine if we have time and date ranges
            const hasTimeRange = config.timeStart && config.timeEnd;
            const hasDateRange = config.dateStart && config.dateEnd;
            const differentDates = hasDateRange && config.dateStart !== config.dateEnd;

            // MODE 1: Time spans across dates (start datetime → end datetime)
            if (timeMode === 'span' && hasDateRange && hasTimeRange && differentDates) {
                const startDateTime = new Date(config.dateStart + ' ' + config.timeStart);
                const endDateTime = new Date(config.dateEnd + ' ' + config.timeEnd);

                if (now < startDateTime) {
                    return {
                        valid: false,
                        phase: 'before',
                        message: config.message || this.getString('formNotYetAvailable', 'This form is not yet available.')
                    };
                }

                if (now > endDateTime) {
                    return {
                        valid: false,
                        phase: 'after',
                        message: config.message || this.getString('formNoLongerAvailable', 'This form is no longer available.')
                    };
                }

                // Within datetime span - allow access
                return { valid: true, message: '' };
            }

            // MODE 2: Daily time range (default behavior)
            // Check date range first
            if (config.dateStart && currentDate < config.dateStart) {
                return {
                    valid: false,
                    phase: 'before',
                    message: config.message || this.getString('formNotYetAvailable', 'This form is not yet available.')
                };
            }

            if (config.dateEnd && currentDate > config.dateEnd) {
                return {
                    valid: false,
                    phase: 'after',
                    message: config.message || this.getString('formNoLongerAvailable', 'This form is no longer available.')
                };
            }

            // Then check daily time range (if within date range)
            if (hasTimeRange) {
                const timeStart = config.timeStart || '00:00';
                const timeEnd = config.timeEnd || '23:59';

                if (currentTime < timeStart || currentTime > timeEnd) {
                    return {
                        valid: false,
                        phase: 'during',
                        message: config.message || this.getString('formOnlyDuringHours', 'This form is only available during specific hours.')
                    };
                }
            }

            return { valid: true, message: '' };
        }

    });

})();
