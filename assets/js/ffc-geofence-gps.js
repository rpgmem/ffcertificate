/**
 * FFC Geofence Frontend — GPS acquisition, distance check, cache, fallback.
 *
 * Extends window.FFCGeofence (ffc-geofence-frontend.js). Methods stay on the
 * shared object so `this.*` (and the FFCGeofence.MIN_LOADING_MS reference)
 * resolve exactly as in the pre-split single file.
 *
 * @package FFC
 * @since 3.0.0 (split out of ffc-geofence-frontend.js)
 */

(function() {
    'use strict';

    var FFCGeofence = window.FFCGeofence;

    Object.assign(FFCGeofence, {

        /**
         * Validate geolocation
         *
         * @param {jQuery} formWrapper Form wrapper element
         * @param {object} config Geo configuration
         */
        validateGeolocation: function(formWrapper, config, preflightDone) {
            const self = this;

            // The page is secure by now: the `secure` pre-flight step runs
            // before this one (#2030).

            // Check browser support. Honour the admin's allow/block choice
            // for the `noApi` case: 'allow' lets the user through despite
            // the missing API (the geofence becomes effectively trust-
            // the-browser); 'block' keeps the form locked.
            if (!navigator.geolocation) {
                if (this.shouldAllow(config, 'noApi')) {
                    this.debug('Geolocation API unavailable but fallback case noApi=allow, showing form');
                    this.rememberFailure(formWrapper, 'noApi');
                    this.showForm(formWrapper);
                    return;
                }
                this.handleBlocked(
                    formWrapper,
                    config.hideMode,
                    this.getString('browserNoSupport', 'Your browser does not support location services required by this form. Please use a modern browser to access it.'),
                    false
                );
                return;
            }

            // 6.6.4 Sprint 3 — Permissions API pre-check.
            //
            // Avoids the native getCurrentPosition prompt entirely when
            // we can already tell the state. Three outcomes:
            //   - 'granted' → continue to the existing flow below
            //   - 'denied' → render the "denied" banner (instructions
            //     + Try anyway). Don't call getCurrentPosition.
            //   - 'prompt' → render the explainer banner with explicit
            //     CTA so the prompt fires only after a fresh user
            //     gesture (better mobile UX than browser-decided timing).
            //
            // Compatibility:
            //   - iOS Safari <16 throws TypeError on the query; the
            //     catch falls through to the existing flow (preserves
            //     today's behaviour: native prompt with our existing
            //     progressive loading messages).
            //   - PermissionStatus.onchange lets the user grant in
            //     Settings without reloading and we re-enter the flow
            //     automatically.
            //
            // `preflightDone` parameter — set on recursive re-entry from
            // any "granted" or fallback path so we don't loop.
            if (!preflightDone
                && navigator.permissions
                && typeof navigator.permissions.query === 'function'
                && window.FFC && window.FFC.Preflight
                && typeof window.FFC.Preflight.geoPermission === 'function') {
                // The pre-flight run this query belongs to; a recheck that
                // starts a newer one while it waits makes its answer stale.
                const run = formWrapper.data('ffcPreflightRun');
                const stale = function () {
                    return formWrapper.data('ffcPreflightRun') !== run;
                };
                // The queue module confirms a "denied" before it is
                // believed (#2048): Opera for Android reports it while it
                // gives the position.
                window.FFC.Preflight.geoPermission().then(function (answer) {
                    if (stale()) {
                        return;
                    }
                    if (!answer.status) {
                        self.debug('Permissions API unavailable — falling through to native flow');
                        self.validateGeolocation(formWrapper, config, true);
                        return;
                    }
                    self.handleGpsPermissionStatus(formWrapper, config, answer.status, answer.state);
                });
                return;
            }

            // Check cache first. The cache holds a non-sensitive "pass"
            // token (set after a successful in-area validation), not the
            // raw coordinates — so a recent pass short-circuits straight to
            // showForm without re-running GPS. Trade-off: an area-config
            // change within the TTL window isn't re-evaluated until the
            // token expires.
            const cached = this.getLocationCache(formWrapper.attr('id'));
            if (cached) {
                this.debug('Using cached geofence pass');
                // Show the same loading state real fetches see, then resolve
                // after a short minimum hold so the user gets a visual
                // confirmation the form just validated location instead of
                // an instant "form appeared from nowhere" transition.
                formWrapper.find('.ffc-submission-form').hide();
                formWrapper.addClass('ffc-geofence-loading');
                this.showLoadingMessage(
                    formWrapper,
                    this.getString('detectingLocation', 'Verifying your location…')
                );
                setTimeout(function() {
                    self.hideLoadingMessage(formWrapper);
                    formWrapper.removeClass('ffc-geofence-loading');
                    self.showForm(formWrapper);
                }, FFCGeofence.MIN_LOADING_MS);
                return;
            }

            var isSafariBrowser = window.FFC.Platform.webkit();

            // IMPORTANT: Hide form BEFORE requesting location
            formWrapper.find('.ffc-submission-form').hide();
            formWrapper.addClass('ffc-geofence-loading');

            // Progressive loading messages so the user knows the page is
            // alive and gets increasingly specific guidance. Safari/iOS
            // takes notoriously longer to show the permission prompt so
            // each stage runs later than on the other platforms.
            var progressTimers = [];
            var phase2Ms = isSafariBrowser ? 8000 : 3000;
            var phase3Ms = isSafariBrowser ? 20000 : 10000;

            this.showLoadingMessage(
                formWrapper,
                this.getString(
                    isSafariBrowser ? 'safariPhase1' : 'detectingLocation',
                    isSafariBrowser
                        ? 'Requesting your location… If prompted, tap "Allow".'
                        : 'Verifying your location…'
                )
            );
            progressTimers.push(setTimeout(function() {
                self.updateLoadingMessage(
                    formWrapper,
                    self.getString(
                        isSafariBrowser ? 'safariPhase2' : 'awaitingPermission',
                        isSafariBrowser
                            ? 'Waiting for location permission… Check if a browser prompt appeared.'
                            : 'Waiting for location permission. Confirm the browser prompt if it appeared.'
                    )
                );
            }, phase2Ms));
            progressTimers.push(setTimeout(function() {
                self.updateLoadingMessage(
                    formWrapper,
                    self.getString(
                        isSafariBrowser ? 'safariPhase3' : 'stillTrying',
                        isSafariBrowser
                            ? 'Still trying to get your location… If it is not working, check that Location Services is enabled in Settings > Privacy & Security > Location Services.'
                            : 'Still trying to get your location… Check that location is enabled in your device settings.'
                    )
                );
            }, phase3Ms));

            var retried = false;
            // Safari/iOS: allow a SHORT cached position on the first attempt
            // so the browser can respond instantly instead of forcing a fresh
            // GPS fix that may time out. The earlier 30000 ms window was too
            // permissive — iOS would happily return a fix from before the
            // user walked out of the allowed area, so the form rendered as
            // valid despite the user being elsewhere. 5 s still avoids the
            // GPS-prompt latency on warm-cache reloads while keeping the
            // position fresh enough to reflect "the user is here right now".
            var firstMaxAge = isSafariBrowser ? 5000 : 0;
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
                self.applyGpsFallback(formWrapper, self.getFreshGeoConfig(formWrapper, config));
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

                // Caching happens inside checkLocation once the position is
                // confirmed within an allowed area — we persist a pass token,
                // never the coordinates themselves.
                var activeConfig = self.getFreshGeoConfig(formWrapper, config);
                self.checkLocation(formWrapper, loc, activeConfig);
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

                var activeConfig = self.getFreshGeoConfig(formWrapper, config);

                // Map the error code to a fallback case key (must match the
                // keys the server emits via `gpsFallback`). For unknown error
                // codes (iOS sometimes reports code=0) treat as position
                // unavailable since that's the strictest assumption that
                // still produces actionable guidance.
                var caseKey;
                var errorMessage;
                switch (error.code) {
                    case error.PERMISSION_DENIED:
                        caseKey      = 'permissionDenied';
                        errorMessage = isSafariBrowser
                            ? self.getString('safariPermissionDenied', 'Location access was denied. On Safari/iOS, go to Settings > Privacy & Security > Location Services and ensure it is enabled for your browser.')
                            : self.getString('permissionDenied', 'Location access is required to use this form. Allow location access in your browser settings and reload the page.');
                        break;
                    case error.POSITION_UNAVAILABLE:
                        caseKey      = 'positionUnavailable';
                        errorMessage = isSafariBrowser
                            ? self.getString('safariPositionUnavailable', 'Unable to determine your location. On Safari/iOS, ensure Location Services is enabled in Settings > Privacy & Security > Location Services.')
                            : self.getString('positionUnavailable', "We couldn't determine your location. Make sure GPS / location services are enabled on your device and reload the page.");
                        break;
                    case error.TIMEOUT:
                        caseKey      = 'timeout';
                        errorMessage = isSafariBrowser
                            ? self.getString('safariTimeout', 'Location request timed out. On Safari/iOS, ensure Location Services is enabled in Settings > Privacy & Security > Location Services.')
                            : self.getString('timeout', 'Location request took too long. Check your connection and reload the page.');
                        break;
                    default:
                        caseKey      = 'positionUnavailable';
                        errorMessage = isSafariBrowser
                            ? self.getString('safariPositionUnavailable', 'Unable to determine your location. On Safari/iOS, ensure Location Services is enabled in Settings > Privacy & Security > Location Services.')
                            : (activeConfig.messageError || self.getString('locationError', 'Unable to determine your location.'));
                }

                if (self.shouldAllow(activeConfig, caseKey)) {
                    self.debug('GPS failed but case ' + caseKey + '=allow, showing form');
                    self.rememberFailure(formWrapper, caseKey);
                    self.showForm(formWrapper);
                    return;
                }

                // Surface a "Reload page" affordance for the three transient
                // failure cases — the user can usually recover from these
                // by reloading after enabling location / fixing connectivity.
                self.handleBlocked(formWrapper, activeConfig.hideMode, errorMessage, true);
            }

            // Request geolocation
            navigator.geolocation.getCurrentPosition(onSuccess, onError, {
                enableHighAccuracy: true,
                timeout: geoTimeout,
                maximumAge: firstMaxAge
            });
        },

        /**
         * Resolve whether a given GPS-failure case should allow access.
         *
         * The server sends a per-case allow/block map via `config.gpsFallback`
         * (object). For pre-fallback-presets servers that may still emit
         * the legacy 'allow' | 'block' string, fall back to the old binary
         * semantic (allow ↔ true, block ↔ false).
         *
         * @param {object} config  Geo configuration emitted by the server.
         * @param {string} caseKey One of permissionDenied / noApi /
         *                         positionUnavailable / timeout / safetyTimer.
         * @returns {boolean}      true → showForm, false → handleBlocked.
         */
        shouldAllow: function(config, caseKey) {
            var fb = (config && config.gpsFallback);
            if (typeof fb === 'string') {
                return 'allow' === fb;
            }
            return !!(fb && fb[caseKey]);
        },

        /**
         * Apply the safety-timer fallback when geolocation never responded.
         *
         * @param {jQuery} formWrapper Form wrapper element
         * @param {object} config      Geo configuration
         */
        applyGpsFallback: function(formWrapper, config) {
            if (this.shouldAllow(config, 'safetyTimer')) {
                this.debug('safetyTimer=allow, showing form despite GPS never responding');
                this.rememberFailure(formWrapper, 'safetyTimer');
                this.showForm(formWrapper);
                return;
            }
            var msg = window.FFC.Platform.webkit()
                ? this.getString('safariSafetyTimeout', "Your device didn't respond to the location request. On Safari/iOS, ensure Location Services is enabled in Settings > Privacy & Security > Location Services, then reload the page.")
                : this.getString('safetyTimeout', "Your device didn't respond to the location request. Make sure location is enabled and reload the page.");
            this.handleBlocked(formWrapper, config.hideMode, msg, true);
        },

        /**
         * Keep the last reading in memory for the submission (#2040).
         *
         * @param {jQuery} formWrapper Form wrapper element
         * @param {object} location    {latitude, longitude, accuracy}
         */
        rememberReading: function(formWrapper, location) {
            formWrapper.data('ffcGeoReading', {
                lat: location.latitude,
                lng: location.longitude,
                accuracy: location.accuracy,
                at: Date.now()
            });
            formWrapper.removeData('ffcGeoStatus');
        },

        /**
         * Keep the failure the policy let through, so the server applies the
         * same case (#2040).
         *
         * @param {jQuery} formWrapper Form wrapper element
         * @param {string} caseKey     A `gpsFallback` key
         */
        rememberFailure: function(formWrapper, caseKey) {
            formWrapper.removeData('ffcGeoReading');
            formWrapper.data('ffcGeoStatus', caseKey);
        },

        /**
         * The GPS fields a submission carries, for the server to decide the
         * area by (#2040): the reading, or the failure the policy let
         * through. A reading older than the cache lifetime, or none at all
         * (the form showed from a cached pass), is read again; the browser
         * answers from its own cache, and the permission is already given.
         *
         * @param {jQuery} $form The submission form
         * @returns {Promise<string>} `&ffc_geo_…` pairs, or '' when the form has no GPS check
         */
        submissionFields: function($form) {
            var self = this;
            var formId = String($form.find('[name="form_id"]').val() || '');
            var all = window.ffcGeofenceConfig || {};
            var config = all[formId] && all[formId].geo;
            if (!config || !config.enabled || !config.gpsEnabled) {
                return Promise.resolve('');
            }

            var $wrapper = $form.closest('.ffc-form-wrapper');
            var ttl = (config.cacheTtl || 600) * 1000;
            var status = $wrapper.data('ffcGeoStatus');
            var reading = $wrapper.data('ffcGeoReading');
            var snake = function (key) { return key.replace(/[A-Z]/g, function (c) { return '_' + c.toLowerCase(); }); };
            var fromReading = function (r) {
                return '&ffc_geo_lat=' + encodeURIComponent(r.lat)
                    + '&ffc_geo_lng=' + encodeURIComponent(r.lng)
                    + '&ffc_geo_accuracy=' + encodeURIComponent(r.accuracy)
                    + '&ffc_geo_age=' + Math.max(0, Date.now() - r.at);
            };

            if (status) {
                return Promise.resolve('&ffc_geo_status=' + snake(status));
            }
            if (reading && Date.now() - reading.at <= ttl) {
                return Promise.resolve(fromReading(reading));
            }
            if (!navigator.geolocation) {
                return Promise.resolve('&ffc_geo_status=no_api');
            }

            return new Promise(function (resolve) {
                navigator.geolocation.getCurrentPosition(function (position) {
                    self.rememberReading($wrapper, position.coords);
                    resolve(fromReading($wrapper.data('ffcGeoReading')));
                }, function (error) {
                    var cases = { 1: 'permission_denied', 2: 'position_unavailable', 3: 'timeout' };
                    resolve('&ffc_geo_status=' + (cases[error && error.code] || 'position_unavailable'));
                }, { enableHighAccuracy: true, timeout: 10000, maximumAge: ttl });
            });
        },

        getFreshGeoConfig: function(formWrapper, fallback) {
            var formId = formWrapper.attr('id').replace('ffc-form-', '');
            if (window.ffcGeofenceConfig && window.ffcGeofenceConfig[formId] && window.ffcGeofenceConfig[formId].geo) {
                return window.ffcGeofenceConfig[formId].geo;
            }
            return fallback;
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

            // Kept in memory for the submission, which the server decides
            // by (#2040); never stored.
            this.rememberReading(formWrapper, location);

            if (areas.length === 0) {
                this.debug('No areas defined, allowing access');
                this.cacheGeofencePass(formWrapper, config);
                this.showForm(formWrapper);
                return; // No restrictions
            }

            // Each area is judged with its own radius, as the server judges
            // the submission (#2070): inside when the point is and the error
            // is at most `tolerance` times the radius, unsure when the error
            // circle still reaches the area, outside otherwise.
            const verdict = this.areaVerdict(location, areas, Number(config.tolerance) || 1.5);

            if (verdict === 'unsure') {
                this.debug('GPS reading cannot tell inside from outside', { accuracy: location.accuracy });
                if (this.shouldAllow(config, 'positionUnavailable')) {
                    this.showForm(formWrapper);
                    this.showImpreciseNotice(formWrapper);
                    return;
                }
                this.handleBlocked(
                    formWrapper,
                    config.hideMode,
                    this.getString('inaccurate', 'Your location is not precise enough to confirm you are in the allowed area. Move to an open area or turn on precise location, then reload the page. On a computer, open this page on your phone with location turned on.'),
                    true
                );
                return;
            }

            if (verdict === 'outside') {
                this.handleBlocked(
                    formWrapper,
                    config.hideMode,
                    config.messageBlocked || this.getString('outsideArea', 'You are outside the allowed area for this form.')
                );
            } else {
                this.debug('User within allowed area, showing form');
                this.cacheGeofencePass(formWrapper, config);
                this.showForm(formWrapper);
            }
        },

        /**
         * The reading against every area (#2070).
         *
         * @param {object} location  {latitude, longitude, accuracy}
         * @param {Array}  areas     [{lat, lng, radius}]
         * @param {number} tolerance How many times an area's radius the error may reach.
         * @returns {string} 'inside' | 'unsure' | 'outside'
         */
        areaVerdict: function(location, areas, tolerance) {
            const accuracy = typeof location.accuracy === 'number' ? location.accuracy : 0;
            let verdict = 'outside';
            for (let i = 0; i < areas.length; i++) {
                const radius = Number(areas[i].radius);
                const distance = this.calculateDistance(location.latitude, location.longitude, areas[i].lat, areas[i].lng);
                if (distance <= radius && accuracy <= radius * tolerance) {
                    return 'inside';
                }
                if (distance - accuracy <= radius) {
                    verdict = 'unsure';
                }
            }
            return verdict;
        },

        /**
         * Say that the form opened without the location being confirmed:
         * the policy let an imprecise reading through (#2070).
         *
         * @param {jQuery} formWrapper Form wrapper element
         */
        showImpreciseNotice: function(formWrapper) {
            formWrapper.find('[data-ffc-notice="geofence-imprecise"]').remove();
            formWrapper.prepend(window.FFC.notice(
                'warning',
                'map-pin',
                this.getString('inaccurateAllowed', 'Your location is not precise enough to confirm you are in the allowed area, but this form allows access anyway.'),
                '',
                { role: 'note', hook: 'geofence-imprecise' }
            ));
        },

        /**
         * Persist a non-sensitive "pass" token after a successful in-area
         * validation, when the form opted into caching. We deliberately
         * store only {validated:true, expires} — never the coordinates —
         * so no location data sits at rest in localStorage.
         *
         * @param {jQuery} formWrapper Form wrapper element
         * @param {object} config      Geo configuration (cacheEnabled/cacheTtl)
         */
        cacheGeofencePass: function(formWrapper, config) {
            if (config && config.cacheEnabled) {
                this.setLocationCache(formWrapper.attr('id'), config.cacheTtl || 600);
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
         * Read the cached geofence pass token.
         *
         * Returns `true` when a non-expired pass token exists for this form,
         * otherwise `null`. Entries written by pre-6.x builds (which stored a
         * `location` object) are treated as a miss so the visitor re-validates
         * via GPS — no stale coordinates are ever read back.
         *
         * @param {string} formId Form wrapper id.
         * @returns {boolean|null} true on a valid pass, null on miss/expired/legacy.
         */
        getLocationCache: function(formId) {
            try {
                if (!localStorage) return null;

                const cacheKey = 'ffc_geo_' + formId;
                const cached = localStorage.getItem(cacheKey);

                if (!cached) return null;

                const data = JSON.parse(cached);
                const now = Math.floor(Date.now() / 1000);

                if (data.expires && now > data.expires) {
                    localStorage.removeItem(cacheKey);
                    return null;
                }

                return data.validated === true ? true : null;
            } catch (e) {
                // Safari private mode or quota exceeded
                return null;
            }
        },

        /**
         * Write the geofence pass token.
         *
         * Stores only {validated:true, expires} — never the coordinates — so
         * the localStorage entry carries no sensitive location data at rest
         * (CodeQL js/clear-text-storage-of-sensitive-data). The token means
         * "this visitor passed the geofence within the TTL window".
         *
         * @param {string} formId Form wrapper id.
         * @param {number} ttl    Time-to-live in seconds.
         */
        setLocationCache: function(formId, ttl) {
            try {
                if (!localStorage) return;

                const cacheKey = 'ffc_geo_' + formId;
                const now = Math.floor(Date.now() / 1000);

                const data = {
                    validated: true,
                    expires: now + ttl
                };

                localStorage.setItem(cacheKey, JSON.stringify(data));
            } catch (e) {
                // Safari private mode or quota exceeded — silently skip caching
                this.debug('localStorage unavailable, skipping cache', e.message);
            }
        }

    });

})();
