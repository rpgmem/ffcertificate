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
        // Minimum time the loading spinner stays up before the form is
        // released. Used by the cache-hit fast path so the user gets a
        // visual "verifying location" tick instead of an instant
        // transition from loading state to ready form. Exposed on the
        // object so unit tests can pin the value without monkey-patching
        // setTimeout.
        MIN_LOADING_MS: 600,

        /**
         * Initialize geofence validation
         */
        init: function() {
            // Check if global config exists
            if (typeof window.ffcGeofenceConfig === 'undefined') {
                return;
            }

            this.debug('FFC Geofence initialized', window.ffcGeofenceConfig);

            // 6.6.4 follow-up (#361) — diagnostic log moved out of
            // this module. It now lives in ffc-frontend.js, gated by
            // the dedicated `debug_browser_env` toggle, so the
            // service-worker / clipboard / etc. signals are captured
            // on every form page (not just forms with geofence) and
            // only fire when the admin explicitly enables the
            // toggle in Settings → Debug.

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
                    // Per-phase hide mode (#159 S4). validateDateTime returns
                    // phase ∈ {'before','during','after'} on a fail; pick the
                    // matching config.datetime.hideMode<Phase>. Fall back to
                    // hideModeBefore when phase is unset (defensive — should
                    // not happen, but keeps the previous "always block"
                    // semantic intact).
                    const phaseMode = this.pickHideMode(config.datetime, datetimeValid.phase);
                    this.handleBlocked(formWrapper, phaseMode, config.datetime.message || datetimeValid.message);
                    return; // Stop here, don't check geo
                }
                // DateTime validation passed, continue...
            }

            // PRIORITY 2: Cookie sanity check (6.6.4 Sprint 2).
            // Probes `document.cookie` write+read roundtrip to surface
            // visitors with cookies fully blocked at the browser level.
            // Cookies are required by:
            //   - the WP session token that wp_verify_nonce reads on
            //     submit (otherwise the auto-recover in #356 will also
            //     fail since the cookie is what binds the fresh nonce),
            //   - the per-visitor identity that gates ITP-affected
            //     downloads in 6.6.2.
            // Skipped on adminBypass (operators don't get the banner).
            // Does NOT catch ITP partitioning that strips cookies later
            // mid-session — that case is covered by the server-side
            // refresh_nonce auto-recover (6.6.3 #356).
            if (config.adminBypass !== true && ! this.checkCookieSupport()) {
                this.handleCookieBlocked(formWrapper);
                return; // Stop here, don't check geo
            }

            // PRIORITY 3: Validate Geolocation (if enabled)
            // Only validate if geo is enabled (not bypassed). The
            // implementation is extracted into processGeoOrShow so the
            // cookie-blocked "try anyway" path (Sprint 2) and the
            // permission-denied "try anyway" path (Sprint 3) can re-enter
            // it without duplicating the branch table.
            this.processGeoOrShow(formWrapper, config);
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
         * Pick the correct per-phase hide mode (#159 S4).
         *
         * @param {object} datetimeConfig config.datetime block (carries
         *                                hideModeBefore/During/After).
         * @param {string} phase          'before' | 'during' | 'after' | undefined.
         * @returns {string} 'hide' | 'message' | 'title_message'.
         */
        pickHideMode: function(datetimeConfig, phase) {
            if (phase === 'after')  return datetimeConfig.hideModeAfter  || 'message';
            if (phase === 'during') return datetimeConfig.hideModeDuring || 'message';
            return datetimeConfig.hideModeBefore || 'message';
        },

        /**
         * 6.6.4 Sprint 2 — probe document.cookie to confirm the browser
         * accepts first-party cookies on this origin. Returns true if
         * the visitor can hold cookies, false if the probe didn't round-
         * trip.
         *
         * Caveats:
         *   - `navigator.cookieEnabled` is checked but doesn't catch
         *     site-specific blocking; the actual write+read is what
         *     proves it works for THIS request flow.
         *   - Does NOT detect Safari ITP partitioning of cookies on
         *     cross-context navigations (that case is handled
         *     server-side via the 6.6.3 refresh_nonce auto-recover).
         *   - Probe cookie is short-lived (10s max-age) so even if the
         *     cleanup line below somehow fails, the cookie expires
         *     itself.
         *
         * @returns {boolean} true if cookies appear to work
         */
        checkCookieSupport: function() {
            try {
                if (typeof navigator !== 'undefined' && navigator.cookieEnabled === false) {
                    return false;
                }
                if (typeof document === 'undefined') {
                    return false;
                }
                const probeName = 'ffc_cookie_probe';
                const probeValue = '1';
                document.cookie = probeName + '=' + probeValue
                    + '; path=/; SameSite=Lax; max-age=10';
                const present = document.cookie.split('; ').some(function (c) {
                    return c.indexOf(probeName + '=' + probeValue) === 0;
                });
                // Clean up immediately on success path — don't litter the
                // visitor's cookie jar with our probe.
                document.cookie = probeName + '=; path=/; SameSite=Lax; max-age=0';
                return present;
            } catch (e) {
                // Some sandboxed contexts throw on `document.cookie`
                // assignment; treat as blocked.
                return false;
            }
        },

        /**
         * 6.6.4 Sprint 2 — render the "cookies blocked" yellow warning
         * banner with platform-aware instructions and a permissive
         * "try anyway" CTA. Form stays in the DOM but is hidden
         * behind the banner until the visitor either fixes the
         * underlying setting (and reloads) or chooses to proceed
         * anyway.
         *
         * @param {jQuery} formWrapper Form wrapper element.
         */
        handleCookieBlocked: function(formWrapper) {
            this.debug('Cookies appear to be blocked');

            // 6.6.4 follow-up (#361 Sprint 2) — telemetry ping so the
            // admin can see the volume of cookie-walls in Activity Log
            // + per-form metabox badges.
            this.logPreflightBail(formWrapper, 'cookies');

            const platform = this.detectPlatformFamily();
            const title = this.getString('cookieBlockedTitle', 'Cookies blocked');
            const body = this.getString(
                'cookieBlockedBody',
                'Cookies are blocked in this browser. Without cookies, the form submission may fail. Enable cookies for this site, or try a different browser.'
            );
            const instructions = this.getString(
                platform === 'ios' ? 'cookieBlockedHowIos'
                    : platform === 'android' ? 'cookieBlockedHowAndroid'
                    : 'cookieBlockedHowDesktop',
                ''
            );
            const tryAnyway = this.getString('cookieTryAnyway', 'Try anyway');

            const $banner = $(
                '<div class="ffc-preflight-banner ffc-preflight-cookies" role="alert" aria-live="assertive">'
                + '<strong class="ffc-preflight-banner-title"></strong>'
                + '<p class="ffc-preflight-banner-body"></p>'
                + (instructions ? '<p class="ffc-preflight-banner-how"></p>' : '')
                + '<button type="button" class="ffc-preflight-try-anyway"></button>'
                + '</div>'
            );
            $banner.find('.ffc-preflight-banner-title').text(title);
            $banner.find('.ffc-preflight-banner-body').text(body);
            if (instructions) {
                $banner.find('.ffc-preflight-banner-how').text(instructions);
            }
            $banner.find('.ffc-preflight-try-anyway').text(tryAnyway);

            $banner.find('.ffc-preflight-try-anyway').on('click', () => {
                $banner.remove();
                // Continue pipeline as if the gate passed — equivalent
                // to skipping to STEP 3 (geo). We re-enter processForm
                // with a flag so we don't re-run the cookie probe.
                const formId = formWrapper.attr('id').replace('ffc-form-', '');
                const config = window.ffcGeofenceConfig && window.ffcGeofenceConfig[formId];
                if (! config) {
                    this.showForm(formWrapper);
                    return;
                }
                this.processGeoOrShow(formWrapper, config);
            });

            formWrapper.find('.ffc-submission-form, .ffc-form').hide();
            formWrapper.prepend($banner);
        },

        /**
         * 6.6.4 Sprint 2 — extracted from processForm so the
         * "try anyway" path on the cookie banner can re-enter just
         * the geo step without re-running datetime + cookies.
         *
         * @param {jQuery} formWrapper Form wrapper element.
         * @param {object} config Form geofence configuration.
         */
        processGeoOrShow: function(formWrapper, config) {
            if (config.geo && config.geo.enabled) {
                if (config.geo.gpsEnabled) {
                    this.validateGeolocation(formWrapper, config.geo);
                } else if (config.geo.ipEnabled) {
                    this.showForm(formWrapper);
                    this.debug('IP-only validation (backend), showing form');
                } else {
                    this.showForm(formWrapper);
                    this.debug('No GPS/IP method enabled, showing form');
                }
            } else {
                this.showForm(formWrapper);
                this.debug('No geolocation validation, showing form');
            }
        },

        /**
         * 6.6.4 Sprint 2 — detect device family for platform-specific
         * instructions in the cookies / GPS banners. Returns
         * 'ios' | 'android' | 'desktop'. iPadOS reports "Macintosh" in
         * the UA but exposes maxTouchPoints > 1; the same heuristic
         * the PDF generator uses.
         *
         * @returns {'ios'|'android'|'desktop'}
         */
        detectPlatformFamily: function() {
            const ua = (typeof navigator !== 'undefined' && navigator.userAgent) || '';
            if (/iPad|iPhone|iPod/.test(ua)
                || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) {
                return 'ios';
            }
            if (/Android/i.test(ua)) {
                return 'android';
            }
            return 'desktop';
        },

        /**
         * 6.6.4 follow-up (#361 Sprint 2) — fire-and-forget telemetry
         * ping to the `ffc_log_preflight_bail` endpoint. Records an
         * ActivityLog row server-side so admins see the volume of
         * cookie / GPS walls in Activity Log + per-form metabox
         * badges (Sprint 3).
         *
         * Best-effort: any failure (network, nonce stale, endpoint
         * disabled) is swallowed. The user-facing banner is the
         * primary feedback; telemetry is a secondary signal for
         * admin visibility.
         *
         * Uses FFC.request (so a stale nonce auto-recovers via the
         * #356 path); falls back to fetch() if FFC.request isn't
         * loaded for some reason on the page.
         *
         * @param {jQuery} formWrapper Form wrapper element.
         * @param {string} reason `cookies` | `gps_denied` | `gps_prompt`
         */
        logPreflightBail: function(formWrapper, reason) {
            try {
                const formIdAttr = formWrapper.attr('id') || '';
                const formId = parseInt(formIdAttr.replace('ffc-form-', ''), 10);
                if (! formId || isNaN(formId)) {
                    return;
                }
                if (typeof window.FFC === 'object'
                    && typeof window.FFC.request === 'function') {
                    window.FFC.request('ffc_log_preflight_bail', {
                        form_id: formId,
                        reason: reason,
                    }).catch(function () {
                        // swallow
                    });
                }
            } catch (e) {
                // swallow — telemetry must never break the form flow
            }
        },

        /**
         * 6.6.4 Sprint 3 — dispatch on the PermissionStatus returned by
         * navigator.permissions.query({name: 'geolocation'}). Wires an
         * onchange listener so the user can grant in Settings without
         * reloading the page and we pick up the transition.
         *
         * @param {jQuery}           formWrapper Form wrapper element.
         * @param {object}           config Geofence config.
         * @param {PermissionStatus} status Result of permissions.query.
         */
        handleGpsPermissionStatus: function(formWrapper, config, status) {
            const self = this;
            try {
                status.onchange = function () {
                    // Only act on a positive transition. denied↔prompt
                    // transitions are noise here (the banner already
                    // describes the situation).
                    if (status.state === 'granted') {
                        const banner = formWrapper[0].querySelector('.ffc-preflight-gps');
                        if (banner) banner.remove();
                        self.validateGeolocation(formWrapper, config, true);
                    }
                };
            } catch (e) {
                // Some legacy PermissionStatus impls don't allow onchange
                // assignment. Swallow.
            }

            this.debug('GPS permission status', status.state);

            if (status.state === 'granted') {
                this.validateGeolocation(formWrapper, config, true);
                return;
            }
            if (status.state === 'denied') {
                this.handleGpsDeniedBanner(formWrapper, config);
                return;
            }
            // 'prompt' or anything unexpected.
            this.handleGpsPromptBanner(formWrapper, config);
        },

        /**
         * 6.6.4 Sprint 3 — render the "GPS denied" yellow banner with
         * platform-specific instructions on how to revoke the block.
         * "Try anyway" lets the user fall through to the existing
         * native flow (the browser may still re-prompt some devices).
         *
         * @param {jQuery} formWrapper Form wrapper element.
         * @param {object} config Geofence config.
         */
        handleGpsDeniedBanner: function(formWrapper, config) {
            const self = this;

            // 6.6.4 follow-up (#361 Sprint 2) — telemetry ping.
            this.logPreflightBail(formWrapper, 'gps_denied');

            const platform = this.detectPlatformFamily();
            const title = this.getString('gpsDeniedTitle', 'Location blocked');
            const body = this.getString(
                'gpsDeniedBody',
                'This site needs your location, but the browser has it set to "block" for this site.'
            );
            const instructions = this.getString(
                platform === 'ios' ? 'gpsDeniedHowIos'
                    : platform === 'android' ? 'gpsDeniedHowAndroid'
                    : 'gpsDeniedHowDesktop',
                ''
            );
            const tryAnyway = this.getString('gpsTryAnyway', 'Try anyway');

            this.renderPreflightBanner(formWrapper, 'ffc-preflight-gps', title, body, instructions, tryAnyway, function () {
                // Fall through to the existing native flow. Skip the
                // pre-check so we don't loop back into this banner.
                self.validateGeolocation(formWrapper, config, true);
            });
        },

        /**
         * 6.6.4 Sprint 3 — render the "we'll ask for location" explainer
         * banner BEFORE the native prompt fires. State === 'prompt'
         * means the browser would ask anyway — pre-explaining the
         * reason and gating the prompt on a user click gives the user
         * context AND ensures the prompt fires inside a fresh user-
         * gesture window (matters on iOS Safari, where async-deferred
         * prompts sometimes get refused silently).
         *
         * @param {jQuery} formWrapper Form wrapper element.
         * @param {object} config Geofence config.
         */
        handleGpsPromptBanner: function(formWrapper, config) {
            const self = this;

            // 6.6.4 follow-up (#361 Sprint 2) — telemetry ping. We log
            // this even though it isn't a "block" per se; admins want
            // to know how many users see the prompt explainer (signal
            // of friction even if they proceed).
            this.logPreflightBail(formWrapper, 'gps_prompt');

            const title = this.getString('gpsPromptTitle', 'We need your location');
            const body = this.getString(
                'gpsPromptBody',
                'This site needs to confirm you are at the venue. After you tap "Continue", your browser will ask for permission.'
            );
            const cta = this.getString('gpsPromptContinue', 'Continue');

            this.renderPreflightBanner(formWrapper, 'ffc-preflight-gps', title, body, '', cta, function () {
                self.validateGeolocation(formWrapper, config, true);
            });
        },

        /**
         * 6.6.4 Sprint 3 — shared renderer for pre-flight banners
         * (denied + prompt). DOM shape mirrors the cookie banner
         * (Sprint 2) so the CSS class .ffc-preflight-banner styles
         * both, but the modifier class lets callers identify which
         * one is showing (for the onchange listener cleanup path).
         *
         * @param {jQuery}   formWrapper
         * @param {string}   modifierClass eg. 'ffc-preflight-gps'
         * @param {string}   title
         * @param {string}   body
         * @param {string}   instructions (may be empty)
         * @param {string}   ctaLabel
         * @param {Function} onCta Callback fired on CTA click after banner removal.
         */
        renderPreflightBanner: function(formWrapper, modifierClass, title, body, instructions, ctaLabel, onCta) {
            const $banner = $(
                '<div class="ffc-preflight-banner ' + modifierClass + '" role="alert" aria-live="assertive">'
                + '<strong class="ffc-preflight-banner-title"></strong>'
                + '<p class="ffc-preflight-banner-body"></p>'
                + (instructions ? '<p class="ffc-preflight-banner-how"></p>' : '')
                + '<button type="button" class="ffc-preflight-try-anyway"></button>'
                + '</div>'
            );
            $banner.find('.ffc-preflight-banner-title').text(title);
            $banner.find('.ffc-preflight-banner-body').text(body);
            if (instructions) {
                $banner.find('.ffc-preflight-banner-how').text(instructions);
            }
            $banner.find('.ffc-preflight-try-anyway').text(ctaLabel).on('click', function () {
                $banner.remove();
                onCta();
            });
            formWrapper.find('.ffc-submission-form, .ffc-form').hide();
            formWrapper.prepend($banner);
        },

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

        validateGeolocation: function(formWrapper, config, preflightDone) {
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

            // Check browser support. Honour the admin's allow/block choice
            // for the `noApi` case: 'allow' lets the user through despite
            // the missing API (the geofence becomes effectively trust-
            // the-browser); 'block' keeps the form locked.
            if (!navigator.geolocation) {
                if (this.shouldAllow(config, 'noApi')) {
                    this.debug('Geolocation API unavailable but fallback case noApi=allow, showing form');
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
                && typeof navigator.permissions.query === 'function') {
                try {
                    navigator.permissions.query({ name: 'geolocation' })
                        .then(function (status) {
                            self.handleGpsPermissionStatus(formWrapper, config, status);
                        })
                        .catch(function () {
                            self.debug('Permissions.query rejected — falling through to native flow');
                            self.validateGeolocation(formWrapper, config, true);
                        });
                } catch (e) {
                    self.validateGeolocation(formWrapper, config, true);
                }
                return;
            }

            // Check cache first
            const cached = this.getLocationCache(formWrapper.attr('id'));
            if (cached) {
                this.debug('Using cached location', cached);
                // Show the same loading state real fetches see, then resolve
                // after a short minimum hold so the user gets a visual
                // confirmation the form just validated location instead of
                // an instant "form appeared from nowhere" transition.
                formWrapper.find('.ffc-submission-form').hide();
                formWrapper.addClass('ffc-geofence-loading');
                this.showLoadingMessage(
                    formWrapper,
                    this.getString('detectingLocation', 'Verifying your location\u2026')
                );
                setTimeout(function() {
                    self.hideLoadingMessage(formWrapper);
                    formWrapper.removeClass('ffc-geofence-loading');
                    self.checkLocation(formWrapper, cached, config);
                }, FFCGeofence.MIN_LOADING_MS);
                return;
            }

            var isSafariBrowser = this.isSafari();

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
                        ? 'Requesting your location\u2026 If prompted, tap "Allow".'
                        : 'Verifying your location\u2026'
                )
            );
            progressTimers.push(setTimeout(function() {
                self.updateLoadingMessage(
                    formWrapper,
                    self.getString(
                        isSafariBrowser ? 'safariPhase2' : 'awaitingPermission',
                        isSafariBrowser
                            ? 'Waiting for location permission\u2026 Check if a browser prompt appeared.'
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
                            ? 'Still trying to get your location\u2026 If it is not working, check that Location Services is enabled in Settings > Privacy & Security > Location Services.'
                            : 'Still trying to get your location\u2026 Check that location is enabled in your device settings.'
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

                // Cache location
                if (config.cacheEnabled) {
                    self.setLocationCache(formWrapper.attr('id'), loc, config.cacheTtl || 600);
                }

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
                this.showForm(formWrapper);
                return;
            }
            var msg = this.isSafari()
                ? this.getString('safariSafetyTimeout', "Your device didn't respond to the location request. On Safari/iOS, ensure Location Services is enabled in Settings > Privacy & Security > Location Services, then reload the page.")
                : this.getString('safetyTimeout', "Your device didn't respond to the location request. Make sure location is enabled and reload the page.");
            this.handleBlocked(formWrapper, config.hideMode, msg, true);
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
                    distance: distance.toFixed(2) + ' m',
                    radius: area.radius + ' m',
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
            // validateGeolocation calls `.hide()` on the form body before
            // requesting GPS, which sets an inline `display: none`. The
            // matching CSS show rule is `!important`, which the spec says
            // beats inline non-important — but at least one real browser
            // path (reported post-#191) ignored it and left the form
            // hidden. Clearing the inline style explicitly here is cheap
            // and removes any dependency on cascade resolution order: the
            // form becomes visible the moment we add `.ffc-validated`.
            formWrapper.find('.ffc-submission-form').show();
            this.debug('Form validation passed, showing form');
        },

        resetForm: function(formWrapper) {
            formWrapper.removeClass('ffc-validated');
            formWrapper.find('.ffc-geofence-blocked').remove();
            formWrapper.find('.ffc-geofence-admin-bypass').remove();
            formWrapper.find('.ffc-submission-form').show();
            formWrapper.find('.ffc-form-title').show();
            formWrapper.show();
        },

        recheck: function() {
            if (typeof window.ffcGeofenceConfig === 'undefined') {
                return;
            }

            var self = this;
            Object.keys(window.ffcGeofenceConfig).forEach(function(formId) {
                if (isNaN(formId) || formId.indexOf('_') === 0) {
                    return;
                }

                var formWrapper = jQuery('#ffc-form-' + formId);
                if (formWrapper.length === 0) {
                    return;
                }

                if (formWrapper.hasClass('ffc-geofence-loading')) {
                    self.debug('Skipping recheck for form ' + formId + ' (GPS loading)');
                    return;
                }

                self.debug('Rechecking form ' + formId + ' with fresh config');
                self.resetForm(formWrapper);
                self.processForm(formId, window.ffcGeofenceConfig[formId]);
            });
        },

        /**
         * Handle blocked form
         *
         * @param {jQuery}  formWrapper Form wrapper element
         * @param {string}  hideMode    Display mode ('hide', 'message', 'title_message')
         * @param {string}  message     The message to display (already resolved by caller)
         * @param {boolean} [showReload] If true, append a "Reload page" button under
         *                  the message. Used for transient GPS failures the user
         *                  can usually recover from (denied → enable in settings,
         *                  unavailable → toggle GPS, timeout → check connection).
         */
        handleBlocked: function(formWrapper, hideMode, message, showReload) {

            this.debug('Blocking form', { hideMode: hideMode, message: message, showReload: !!showReload });

            switch (hideMode) {
                case 'hide':
                    // Hide entire form
                    formWrapper.hide();
                    break;

                case 'message':
                    // Hide form, show message only
                    formWrapper.find('.ffc-submission-form').hide();
                    formWrapper.find('.ffc-form-title').hide();
                    this.showBlockedMessage(formWrapper, message, showReload);
                    break;

                case 'title_message':
                    // Show title + description + message
                    formWrapper.find('.ffc-submission-form').hide();
                    this.showBlockedMessage(formWrapper, message, showReload);
                    break;

                default:
                    // Default to showing message
                    formWrapper.find('.ffc-submission-form').hide();
                    this.showBlockedMessage(formWrapper, message, showReload);
                    break;
            }
        },

        /**
         * Show blocked message
         */
        showBlockedMessage: function(formWrapper, message, showReload) {
            var html = '<div class="ffc-geofence-blocked"><p>' + this.escapeHtml(message) + '</p>';
            if (showReload) {
                var label = this.getString('reloadPageBtn', 'Reload page');
                html += '<button type="button" class="ffc-btn ffc-geofence-reload-btn">'
                    + this.escapeHtml(label) + '</button>';
            }
            html += '</div>';
            formWrapper.append(html);

            if (showReload) {
                formWrapper.find('.ffc-geofence-reload-btn').on('click', function() {
                    window.location.reload();
                });
            }
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

                return data.location;
            } catch (e) {
                // Safari private mode or quota exceeded
                return null;
            }
        },

        /**
         * Set location cache
         */
        setLocationCache: function(formId, location, ttl) {
            try {
                if (!localStorage) return;

                const cacheKey = 'ffc_geo_' + formId;
                const now = Math.floor(Date.now() / 1000);

                const data = {
                    location: location,
                    expires: now + ttl
                };

                localStorage.setItem(cacheKey, JSON.stringify(data));
            } catch (e) {
                // Safari private mode or quota exceeded — silently skip caching
                this.debug('localStorage unavailable, skipping cache', e.message);
            }
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

    window.FFCGeofence = FFCGeofence;

    $(document).ready(function() {
        FFCGeofence.init();
    });

})(jQuery);
