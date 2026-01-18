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
                datetimeEnabled: config.datetime ? config.datetime.enabled : false,
                geoEnabled: config.geo ? config.geo.enabled : false,
                config: config
            });

            // PRIORITY 1: Validate Date/Time (server timestamp is trusted)
            if (config.datetime && config.datetime.enabled) {
                const datetimeValid = this.validateDateTime(config.datetime);

                if (!datetimeValid.valid) {
                    this.handleBlocked(formWrapper, config.datetime.hideMode, datetimeValid.message, config.datetime.message);
                    return; // Stop here, don't check geo
                }
            }

            // PRIORITY 2: Validate Geolocation (if enabled)
            if (config.geo && config.geo.enabled && config.geo.gpsEnabled) {
                this.validateGeolocation(formWrapper, config.geo);
            }
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

            this.debug('DateTime validation', {
                currentDate,
                currentTime,
                dateStart: config.dateStart,
                dateEnd: config.dateEnd,
                timeStart: config.timeStart,
                timeEnd: config.timeEnd
            });

            // Check date range
            if (config.dateStart && currentDate < config.dateStart) {
                return {
                    valid: false,
                    message: config.message || 'This form is not yet available.'
                };
            }

            if (config.dateEnd && currentDate > config.dateEnd) {
                return {
                    valid: false,
                    message: config.message || 'This form is no longer available.'
                };
            }

            // Check time range
            if (config.timeStart && config.timeEnd) {
                if (currentTime < config.timeStart || currentTime > config.timeEnd) {
                    return {
                        valid: false,
                        message: config.message || 'This form is only available during specific hours.'
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
        validateGeolocation: function(formWrapper, config) {
            const self = this;

            // Check browser support
            if (!navigator.geolocation) {
                this.handleBlocked(
                    formWrapper,
                    config.hideMode,
                    'Your browser does not support geolocation.',
                    config.messageError
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

            // Show loading state
            formWrapper.addClass('ffc-geofence-loading');
            this.showLoadingMessage(formWrapper, 'Detecting your location...');

            // Request geolocation
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    // Success
                    self.hideLoadingMessage(formWrapper);
                    formWrapper.removeClass('ffc-geofence-loading');

                    const location = {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy: position.coords.accuracy
                    };

                    self.debug('GPS location obtained', location);

                    // Cache location
                    if (config.cacheEnabled) {
                        self.setLocationCache(formWrapper.attr('id'), location, config.cacheTtl || 600);
                    }

                    // Check if within areas
                    self.checkLocation(formWrapper, location, config);
                },
                function(error) {
                    // Error
                    self.hideLoadingMessage(formWrapper);
                    formWrapper.removeClass('ffc-geofence-loading');

                    self.debug('Geolocation error', error);

                    let errorMessage = config.messageError || 'Unable to determine your location.';

                    switch (error.code) {
                        case error.PERMISSION_DENIED:
                            errorMessage = 'Location permission denied. Please enable location services.';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            errorMessage = 'Location information is unavailable.';
                            break;
                        case error.TIMEOUT:
                            errorMessage = 'Location request timed out.';
                            break;
                    }

                    self.handleBlocked(formWrapper, config.hideMode, errorMessage, config.messageError);
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
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
                    'You are outside the allowed area for this form.',
                    config.messageBlocked
                );
            } else {
                this.debug('User within allowed area, showing form');
            }
        },

        /**
         * Calculate distance between two coordinates using Haversine formula
         *
         * @param {number} lat1 Latitude of point 1
         * @param {number} lon1 Longitude of point 1
         * @param {number} lat2 Latitude of point 2
         * @param {number} lon2 Longitude of point 2
         * @returns {number} Distance in kilometers
         */
        calculateDistance: function(lat1, lon1, lat2, lon2) {
            const R = 6371; // Earth radius in km
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
         * Handle blocked form
         *
         * @param {jQuery} formWrapper Form wrapper element
         * @param {string} hideMode Display mode ('hide', 'message', 'title_message')
         * @param {string} defaultMessage Default message
         * @param {string} customMessage Custom message (optional)
         */
        handleBlocked: function(formWrapper, hideMode, defaultMessage, customMessage) {
            const message = customMessage || defaultMessage;

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
         * Show loading message
         */
        showLoadingMessage: function(formWrapper, message) {
            const html = '<div class="ffc-geofence-loading-msg"><div class="ffc-spinner"></div><p>' + this.escapeHtml(message) + '</p></div>';
            formWrapper.prepend(html);
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
