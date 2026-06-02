/**
 * FFC Geofence Frontend — shared core (orchestration + UI + helpers).
 *
 * Owns the `window.FFCGeofence` object and the boot. The validation flows
 * live in sibling files that extend the same object via Object.assign, so
 * every `this.method()` call still resolves against the single instance:
 *  - ffc-geofence-datetime.js  → date/time window validation
 *  - ffc-geofence-gps.js       → GPS acquisition, distance check, cache, fallback
 *  - ffc-geofence-preflight.js → cookie + GPS-permission pre-flight banners
 *
 * Handles client-side geolocation and date/time validation for forms.
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
         * Show loading message.
         *
         * 6.7.6 — Made idempotent. Pre-6.7.6 this `prepend()` ran
         * unconditionally; if the function fired twice (e.g. a cached
         * pre-flight followed by the real GPS check before the cached
         * path's hideLoadingMessage timeout completed, OR a re-init
         * race on page load), the page ended up with 2+ stacked
         * `.ffc-geofence-loading-msg` elements — each with its own
         * spinner and message. Now: if one already exists, re-use it
         * via updateLoadingMessage (preserves the running spinner
         * animation); otherwise create a fresh one.
         */
        showLoadingMessage: function(formWrapper, message) {
            if (formWrapper.find('.ffc-geofence-loading-msg').length) {
                this.updateLoadingMessage(formWrapper, message);
                return;
            }
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
