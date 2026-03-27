/**
 * FFC Geofence Frontend
 *
 * Handles client-side geolocation and date/time validation for forms
 *
 * @package FFC
 * @since 3.0.0
 */

(function($) {
    'use strict';

    const FFCGeofence = {
        /**
         * Initialize geofence validation
         */
        init: function() {
            // Check if global config exists
            if (typeof window.ffcGeofenceConfig === 'undefined') {
                return;
            }

            this.debug('FFC Geofence initialized', window.ffcGeofenceConfig);

            // Process each form (skip non-numeric keys like '_global')
            Object.keys(window.ffcGeofenceConfig).forEach(formId => {
                // Only process numeric form IDs (skip keys starting with '_')
                if (!isNaN(formId) && !formId.startsWith('_')) {
                    this.processForm(formId, window.ffcGeofenceConfig[formId]);
                }
            });
        },

        /**
         * Process individual form
         *
         * @param {string} formId Form ID
         * @param {object} config Form geofence configuration
         */
        processForm: function(formId, config) {
            const formWrapper = $('#ffc-form-' + formId);

            if (formWrapper.length === 0) {
                this.debug('Form wrapper not found for ID: ' + formId);
                return;
            }

            this.debug('Processing form', {
                formId: formId,
                adminBypass: config.adminBypass || false,
                datetimeEnabled: config.datetime ? config.datetime.enabled : false,
                geoEnabled: config.geo ? config.geo.enabled : false,
                config: config
            });

            // Show admin bypass messages if active (partial or full)
            if (config.adminBypass === true && config.bypassInfo) {
                this.showAdminBypassMessages(formWrapper, config.bypassInfo);
                this.debug('Admin bypass active for some restrictions');
            }

            // PRIORITY 1: Validate Date/Time (server timestamp is trusted)
            // Only validate if datetime is enabled (not bypassed)
            if (config.datetime && config.datetime.enabled) {
                const datetimeValid = this.validateDateTime(config.datetime);

                if (!datetimeValid.valid) {
                    this.handleBlocked(formWrapper, config.datetime.hideMode, config.datetime.message || datetimeValid.message);
                    return; // Stop here, don't check geo
                }
                // DateTime validation passed, continue...
            }

            // PRIORITY 2: Validate Geolocation (if enabled)
            // Only validate if geo is enabled (not bypassed)
            if (config.geo && config.geo.enabled) {
                // Check if GPS validation is required
                if (config.geo.gpsEnabled) {
                    this.validateGeolocation(formWrapper, config.geo);
                } else if (config.geo.ipEnabled) {
                    // IP-only validation happens on backend, show form
                    // (Backend already validated before sending this config)
                    this.showForm(formWrapper);
                    this.debug('IP-only validation (backend), showing form');
                } else {
                    // Geo enabled but neither GPS nor IP enabled - show form
                    this.showForm(formWrapper);
                    this.debug('No GPS/IP method enabled, showing form');
                }
            } else {
                // No geolocation check needed, show form now
                this.showForm(formWrapper);
                this.debug('No geolocation validation, showing form');
            }
        },

        /**
         * Get translated string
         *
         * @param {string} key String key
         * @param {string} fallback Fallback if translation not found
         * @returns {string} Translated string or fallback
         */
        getString: function(key, fallback) {
            if (window.ffcGeofenceConfig && window.ffcGeofenceConfig._global && window.ffcGeofenceConfig._global.strings) {
                return window.ffcGeofenceConfig._global.strings[key] || fallback;
            }
            return fallback;
        },

        /**
         * Validate date/time restrictions
         *
         * @param {object} config DateTime configuration
         * @returns {object} {valid: boolean, message: string}
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
                        message: config.message || this.getString('formNotYetAvailable', 'This form is not yet available.')
                    };
                }

                if (now > endDateTime) {
                    return {
                        valid: false,
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
                    message: config.message || this.getString('formNotYetAvailable', 'This form is not yet available.')
                };
            }

            if (config.dateEnd && currentDate > config.dateEnd) {
                return {
                    valid: false,
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
                        message: config.message || this.getString('formOnlyDuringHours', 'This form is only available during specific hours.')
                    };
                }
            }

            return { valid: true, message: '' };
        },

        /**
         * Validate geolocation
         *
         * @param {jQuery} formWrapper Form wrapper element
         * @param {object} config Geo configuration
         */
        /**
         * Detect if running on Safari/iOS (including iPadOS 13+ which
         * reports a Mac desktop user-agent).
         */
        isSafari: function() {
            var ua = navigator.userAgent;
            // Classic iOS devices
            if (/iPad|iPhone|iPod/.test(ua)) {
                return true;
            }
            // iPadOS 13+ identifies as Macintosh but has touch support
            if (/Macintosh/.test(ua) && navigator.maxTouchPoints && navigator.maxTouchPoints > 1) {
                return true;
            }
            // Desktop Safari (not Chrome, Edge, or Android)
            return /^((?!chrome|android).)*safari/i.test(ua);
        },

        validateGeolocation: function(formWrapper, config) {
            const self = this;

            // Check HTTPS requirement (required by Safari and recommended by all browsers)
            if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
                this.debug('Geolocation blocked: page not served over HTTPS');
                this.handleBlocked(
                    formWrapper,
                    config.hideMode,
                    this.getString('httpsRequired', 'This form requires a secure connection (HTTPS) to access your location. Please contact the site administrator.')
                );
                return;
            }

            // Check browser support
            if (!navigator.geolocation) {
                this.handleBlocked(
                    formWrapper,
                    config.hideMode,
                    this.getString('browserNoSupport', 'Your browser does not support geolocation.')
                );
                return;
            }

            // Check cache first
            const cached = this.getLocationCache(formWrapper.attr('id'));
            if (cached) {
                this.debug('Using cached location', cached);
                this.checkLocation(formWrapper, cached, config);
                return;
            }

            var isSafariBrowser = this.isSafari();

            // IMPORTANT: Hide form BEFORE requesting location
            formWrapper.find('.ffc-submission-form').hide();
            formWrapper.addClass('ffc-geofence-loading');

            // Safari/iOS: progressive loading messages so the user knows
            // the page is alive and gets increasingly specific guidance.
            // Android/Chrome shows the permission prompt immediately so a
            // single static message is enough.
            var progressTimers = [];
            if (isSafariBrowser) {
                this.showLoadingMessage(
                    formWrapper,
                    this.getString('safariPhase1', 'Requesting your location\u2026 If prompted, tap "Allow".')
                );
                progressTimers.push(setTimeout(function() {
                    self.updateLoadingMessage(
                        formWrapper,
                        self.getString('safariPhase2', 'Waiting for location permission\u2026 Check if a browser prompt appeared.')
                    );
                }, 8000));
                progressTimers.push(setTimeout(function() {
                    self.updateLoadingMessage(
                        formWrapper,
                        self.getString('safariPhase3', 'Still trying to get your location\u2026 If it is not working, check that Location Services is enabled in Settings > Privacy & Security > Location Services.')
                    );
                }, 20000));
            } else {
                this.showLoadingMessage(formWrapper, this.getString('detectingLocation', 'Detecting your location...'));
            }

            var retried = false;
            // Safari/iOS: allow a recent cached position on the first attempt
            // so the browser can respond instantly instead of forcing a fresh
            // GPS fix that may time out.
            var firstMaxAge = isSafariBrowser ? 30000 : 0;
            var geoTimeout  = isSafariBrowser ? 15000 : 10000;

            // Safety timeout: if neither success nor error fires (Safari can
            // silently ignore the request), clean up and apply the gps_fallback
            // setting so the user is never stuck on an infinite spinner.
            var resolved = false;
            var safetyTimer = setTimeout(function() {
                if (resolved) {
                    return;
                }
                resolved = true;
                progressTimers.forEach(clearTimeout);
                self.debug('Safety timeout reached — geolocation never responded');
                self.hideLoadingMessage(formWrapper);
                formWrapper.removeClass('ffc-geofence-loading');
                self.applyGpsFallback(formWrapper, config);
            }, isSafariBrowser ? 40000 : 25000);

            function done() {
                resolved = true;
                clearTimeout(safetyTimer);
                progressTimers.forEach(clearTimeout);
            }

            function onSuccess(position) {
                if (resolved) { return; }
                done();

                self.hideLoadingMessage(formWrapper);
                formWrapper.removeClass('ffc-geofence-loading');

                var loc = {
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    accuracy: position.coords.accuracy
                };

                self.debug('GPS location obtained', loc);

                // Cache location
                if (config.cacheEnabled) {
                    self.setLocationCache(formWrapper.attr('id'), loc, config.cacheTtl || 600);
                }

                // Check if within areas (will show form if valid)
                self.checkLocation(formWrapper, loc, config);
            }

            function onError(error) {
                if (resolved) { return; }
                self.debug('Geolocation error', error);

                // On Safari, retry once with relaxed settings
                if (isSafariBrowser && !retried && (error.code === error.TIMEOUT || error.code === error.POSITION_UNAVAILABLE)) {
                    retried = true;
                    self.debug('Safari: retrying with enableHighAccuracy=false');
                    navigator.geolocation.getCurrentPosition(onSuccess, onFinalError, {
                        enableHighAccuracy: false,
                        timeout: 15000,
                        maximumAge: 60000
                    });
                    return;
                }

                onFinalError(error);
            }

            function onFinalError(error) {
                if (resolved) { return; }
                done();

                self.hideLoadingMessage(formWrapper);
                formWrapper.removeClass('ffc-geofence-loading');

                // Build a browser-specific error message. This ALWAYS takes
                // priority over the generic admin messageError so the user
                // receives actionable guidance (especially on Safari/iOS).
                var errorMessage;

                switch (error.code) {
                    case error.PERMISSION_DENIED:
                        errorMessage = isSafariBrowser
                            ? self.getString('safariPermissionDenied', 'Location access was denied. On Safari/iOS, go to Settings > Privacy & Security > Location Services and ensure it is enabled for your browser.')
                            : self.getString('permissionDenied', 'Location permission denied. Please enable location services.');
                        break;
                    case error.POSITION_UNAVAILABLE:
                        errorMessage = isSafariBrowser
                            ? self.getString('safariPositionUnavailable', 'Unable to determine your location. On Safari/iOS, ensure Location Services is enabled in Settings > Privacy & Security > Location Services.')
                            : self.getString('positionUnavailable', 'Location information is unavailable.');
                        break;
                    case error.TIMEOUT:
                        errorMessage = isSafariBrowser
                            ? self.getString('safariTimeout', 'Location request timed out. On Safari/iOS, ensure Location Services is enabled in Settings > Privacy & Security > Location Services.')
                            : self.getString('timeout', 'Location request timed out.');
                        break;
                    default:
                        errorMessage = config.messageError || self.getString('locationError', 'Unable to determine your location.');
                }

                // Honor gps_fallback = 'allow': show the form instead of blocking.
                if (config.gpsFallback === 'allow') {
                    self.debug('GPS failed but gps_fallback=allow, showing form');
                    self.showForm(formWrapper);
                    return;
                }

                self.handleBlocked(formWrapper, config.hideMode, errorMessage);
            }

            // Request geolocation
            navigator.geolocation.getCurrentPosition(onSuccess, onError, {
                enableHighAccuracy: true,
                timeout: geoTimeout,
                maximumAge: firstMaxAge
            });
        },

        /**
         * Apply the gps_fallback admin setting when GPS is unavailable.
         *
         * @param {jQuery} formWrapper Form wrapper element
         * @param {object} config      Geo configuration
         */
        applyGpsFallback: function(formWrapper, config) {
            if (config.gpsFallback === 'allow') {
                this.debug('gps_fallback=allow, showing form despite GPS failure');
                this.showForm(formWrapper);
            } else {
                var msg = this.isSafari()
                    ? this.getString('safariTimeout', 'Location request timed out. On Safari/iOS, ensure Location Services is enabled in Settings > Privacy & Security > Location Services.')
                    : this.getString('timeout', 'Location request timed out.');
                this.handleBlocked(formWrapper, config.hideMode, msg);
            }
        },

        /**
         * Check if location is within allowed areas
         *
         * @param {jQuery} formWrapper Form wrapper element
         * @param {object} location User location {latitude, longitude}
         * @param {object} config Geo configuration
         */
        checkLocation: function(formWrapper, location, config) {
            const areas = config.areas || [];

            if (areas.length === 0) {
                this.debug('No areas defined, allowing access');
                this.showForm(formWrapper);
                return; // No restrictions
            }

            let withinAnyArea = false;

            for (let i = 0; i < areas.length; i++) {
                const area = areas[i];
                const distance = this.calculateDistance(
                    location.latitude,
                    location.longitude,
                    area.lat,
                    area.lng
                );

                this.debug('Distance check', {
                    area: i + 1,
                    distance: distance.toFixed(2) + ' km',
                    radius: area.radius + ' km',
                    within: distance <= area.radius
                });

                if (distance <= area.radius) {
                    withinAnyArea = true;
                    break; // Found matching area
                }
            }

            if (!withinAnyArea) {
                this.handleBlocked(
                    formWrapper,
                    config.hideMode,
                    config.messageBlocked || this.getString('outsideArea', 'You are outside the allowed area for this form.')
                );
            } else {
                this.debug('User within allowed area, showing form');
                this.showForm(formWrapper);
            }
        },

        /**
         * Calculate distance between two coordinates using Haversine formula
         *
         * @param {number} lat1 Latitude of point 1
         * @param {number} lon1 Longitude of point 1
         * @param {number} lat2 Latitude of point 2
         * @param {number} lon2 Longitude of point 2
         * @returns {number} Distance in meters
         */
        calculateDistance: function(lat1, lon1, lat2, lon2) {
            const R = 6371000; // Earth radius in meters
            const dLat = this.deg2rad(lat2 - lat1);
            const dLon = this.deg2rad(lon2 - lon1);

            const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                      Math.cos(this.deg2rad(lat1)) * Math.cos(this.deg2rad(lat2)) *
                      Math.sin(dLon / 2) * Math.sin(dLon / 2);

            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            const distance = R * c;

            return distance;
        },

        /**
         * Convert degrees to radians
         */
        deg2rad: function(deg) {
            return deg * (Math.PI / 180);
        },

        /**
         * Show form after successful validation
         * Adds 'ffc-validated' class to override CSS hiding
         *
         * @param {jQuery} formWrapper Form wrapper element
         */
        showForm: function(formWrapper) {
            formWrapper.addClass('ffc-validated');
            this.debug('Form validation passed, showing form');
        },

        /**
         * Handle blocked form
         *
         * @param {jQuery} formWrapper Form wrapper element
         * @param {string} hideMode Display mode ('hide', 'message', 'title_message')
         * @param {string} message  The message to display (already resolved by caller)
         */
        handleBlocked: function(formWrapper, hideMode, message) {

            this.debug('Blocking form', { hideMode, message });

            switch (hideMode) {
                case 'hide':
                    // Hide entire form
                    formWrapper.hide();
                    break;

                case 'message':
                    // Hide form, show message only
                    formWrapper.find('.ffc-submission-form').hide();
                    formWrapper.find('.ffc-form-title').hide();
                    this.showBlockedMessage(formWrapper, message);
                    break;

                case 'title_message':
                    // Show title + description + message
                    formWrapper.find('.ffc-submission-form').hide();
                    this.showBlockedMessage(formWrapper, message);
                    break;

                default:
                    // Default to showing message
                    formWrapper.find('.ffc-submission-form').hide();
                    this.showBlockedMessage(formWrapper, message);
                    break;
            }
        },

        /**
         * Show blocked message
         */
        showBlockedMessage: function(formWrapper, message) {
            const html = '<div class="ffc-geofence-blocked"><p>' + this.escapeHtml(message) + '</p></div>';
            formWrapper.append(html);
        },

        /**
         * Show admin bypass messages (one for each active restriction)
         *
         * @param {jQuery} formWrapper Form wrapper element
         * @param {object} bypassInfo Info about which restrictions are bypassed
         */
        showAdminBypassMessages: function(formWrapper, bypassInfo) {
            if (!bypassInfo) {
                // Fallback: show generic message if no bypass info
                const message = '🔓 ' + this.getString('bypassGeneric', 'Admin Bypass Mode Active - Geofence restrictions are disabled for administrators');
                const html = '<div class="ffc-geofence-admin-bypass"><p>' + this.escapeHtml(message) + '</p></div>';
                formWrapper.prepend(html);
                return;
            }

            // Show specific messages for each bypassed restriction
            if (bypassInfo.hasDatetime) {
                const datetimeMsg = '🔓 ' + this.getString('bypassDatetime', 'Admin Bypass: Date/Time restrictions are disabled for administrators');
                const datetimeHtml = '<div class="ffc-geofence-admin-bypass"><p>' + this.escapeHtml(datetimeMsg) + '</p></div>';
                formWrapper.prepend(datetimeHtml);
            }

            if (bypassInfo.hasGeo) {
                const geoMsg = '🔓 ' + this.getString('bypassGeo', 'Admin Bypass: Geolocation restrictions are disabled for administrators');
                const geoHtml = '<div class="ffc-geofence-admin-bypass"><p>' + this.escapeHtml(geoMsg) + '</p></div>';
                formWrapper.prepend(geoHtml);
            }

            // If neither, show generic message
            if (!bypassInfo.hasDatetime && !bypassInfo.hasGeo) {
                const message = '🔓 ' + this.getString('bypassActive', 'Admin Bypass Mode Active');
                const html = '<div class="ffc-geofence-admin-bypass"><p>' + this.escapeHtml(message) + '</p></div>';
                formWrapper.prepend(html);
            }
        },

        /**
         * Show loading message
         */
        showLoadingMessage: function(formWrapper, message) {
            const html = '<div class="ffc-geofence-loading-msg"><div class="ffc-spinner"></div><p>' + this.escapeHtml(message) + '</p></div>';
            formWrapper.prepend(html);
        },

        /**
         * Update the text of an existing loading message without
         * removing/re-adding the element (preserves the spinner animation).
         *
         * @param {jQuery} formWrapper Form wrapper element.
         * @param {string} message     New message text.
         */
        updateLoadingMessage: function(formWrapper, message) {
            var el = formWrapper.find('.ffc-geofence-loading-msg p');
            if (el.length) {
                el.text(message);
            }
        },

        /**
         * Hide loading message
         */
        hideLoadingMessage: function(formWrapper) {
            formWrapper.find('.ffc-geofence-loading-msg').remove();
        },

        /**
         * Get cached location
         */
        getLocationCache: function(formId) {
            if (!localStorage) return null;

            const cacheKey = 'ffc_geo_' + formId;
            const cached = localStorage.getItem(cacheKey);

            if (!cached) return null;

            try {
                const data = JSON.parse(cached);
                const now = Math.floor(Date.now() / 1000);

                if (data.expires && now > data.expires) {
                    localStorage.removeItem(cacheKey);
                    return null;
                }

                return data.location;
            } catch (e) {
                return null;
            }
        },

        /**
         * Set location cache
         */
        setLocationCache: function(formId, location, ttl) {
            if (!localStorage) return;

            const cacheKey = 'ffc_geo_' + formId;
            const now = Math.floor(Date.now() / 1000);

            const data = {
                location: location,
                expires: now + ttl
            };

            localStorage.setItem(cacheKey, JSON.stringify(data));
        },

        /**
         * Format date to YYYY-MM-DD
         */
        formatDate: function(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        },

        /**
         * Format time to HH:MM
         */
        formatTime: function(date) {
            const hours = String(date.getHours()).padStart(2, '0');
            const minutes = String(date.getMinutes()).padStart(2, '0');
            return hours + ':' + minutes;
        },

        /**
         * Escape HTML
         */
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * Debug log (only when debug mode is enabled)
         */
        debug: function(message, data) {
            if (window.ffcGeofenceConfig && window.ffcGeofenceConfig._global && window.ffcGeofenceConfig._global.debug) {
                console.log('[FFC Geofence] ' + message, data || '');
            }
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        FFCGeofence.init();
    });

})(jQuery);
