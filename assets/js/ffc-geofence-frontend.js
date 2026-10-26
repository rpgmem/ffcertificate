/**
 * FFC Geofence Frontend — shared core (orchestration + UI + helpers).
 *
 * Owns the `window.FFCGeofence` object and the boot. The validation flows
 * live in sibling files that extend the same object via Object.assign, so
 * every `this.method()` call still resolves against the single instance:
 *  - ffc-geofence-datetime.js  → date/time window validation
 *  - ffc-geofence-gps.js       → GPS acquisition, distance check, cache, fallback
 *  - ffc-geofence-permission.js → the GPS-permission banners
 *
 * The checks before a form is shown run as FFC.Preflight's queue
 * (ffc-preflight.js, #2030). This file registers the steps that are
 * geofence's — `datetime` and `geo` — and runs the queue on
 * every certificate form: one with no restriction arrives with a minimal
 * configuration (`Frontend\BrowserGate::form_config()`).
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

        // An accepted GPS reading whose error is above this share of the
        // accepting area's radius is called weak to the visitor (#2072);
        // the default when the server sends no `weakShare`.
        WEAK_READING_SHARE: 0.5,

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

            window.FFC.Preflight.run(['datetime', 'secure', 'browser', 'cookies', 'geo'], this.preflightContext(formWrapper, config));
        },

        /**
         * What the pre-flight steps receive.
         *
         * @param {jQuery} formWrapper Form or content wrapper.
         * @param {object} config      Its geofence configuration.
         * @returns {object}
         */
        preflightContext: function(formWrapper, config) {
            const self = this;
            const global = (window.ffcGeofenceConfig && window.ffcGeofenceConfig._global) || {};
            return {
                wrapper: formWrapper,
                config: config,
                content: false,
                // On unless the setting says otherwise.
                browserCheck: global.browserCheck !== false,
                altchaOnly: global.altchaOnly === true,
                // Browsers give the location only to a secure page, and the
                // ALTCHA widget refuses to run outside one. The composite
                // captcha mode is not stopped: its math half works on any
                // page. The queue's `secure` step decides; the message is
                // the geofence's.
                needsSecure: function(ctx) {
                    return self.needsGps(ctx.config) || (!ctx.content && ctx.altchaOnly === true);
                },
                onInsecure: function(ctx) {
                    self.debug('Blocked: page is not a secure context');
                    const gps = self.needsGps(ctx.config);
                    self.handleBlocked(
                        ctx.wrapper,
                        gps ? ctx.config.geo.hideMode : 'message',
                        gps
                            ? ctx.text('httpsRequired', 'This form requires a secure connection (HTTPS) to access your location. Please contact the site administrator.')
                            : ctx.text('secureRequired', 'This form requires a secure connection (HTTPS). Please contact the site administrator.')
                    );
                },
                text: function(key, fallback) {
                    return self.getString(key, fallback);
                }
            };
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
         * Whether a configuration asks the browser for the location.
         *
         * @param {object} config Form or content geofence configuration.
         * @returns {boolean}
         */
        needsGps: function(config) {
            return !!(config && config.geo && config.geo.enabled && config.geo.gpsEnabled);
        },

        /**
         * The `geo` step: GPS when it is on, otherwise the form (an IP-only
         * check runs on the server).
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
            // The queue passed: what waits for it (the "already submitted"
            // notice) may draw now.
            formWrapper.trigger('ffc:preflight-passed');
        },

        resetForm: function(formWrapper) {
            formWrapper.removeClass('ffc-validated');
            formWrapper.find('[data-ffc-notice="geofence-blocked"]').remove();
            formWrapper.find('[data-ffc-notice="geofence-bypass"]').remove();
            formWrapper.find('[data-ffc-notice="geofence-imprecise"]').remove();
            // processForm draws the pre-flight banner again when it is
            // still needed; leaving the old one stacks a second (#1668).
            formWrapper.find('[data-ffc-notice^="preflight"]').remove();
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
            var children = [];
            if (showReload) {
                var button = window.FFC.button({
                    label: this.getString('reloadPageBtn', 'Reload page'),
                    category: 'neutral',
                    icon: 'sync',
                    className: 'ffc-geofence-reload-btn'
                });
                button.addEventListener('click', function() {
                    window.location.reload();
                });
                children.push(button);
            }
            // One block at a time: a second verdict replaces the first (#1668).
            formWrapper.find('[data-ffc-notice="geofence-blocked"]').remove();
            formWrapper.append(window.FFC.notice('warning', 'map-pin', message, '', {
                role: 'status',
                hook: 'geofence-blocked',
                children: children
            }));
        },

        /**
         * Show admin bypass messages (one for each active restriction)
         *
         * @param {jQuery} formWrapper Form wrapper element
         * @param {object} bypassInfo Info about which restrictions are bypassed
         */
        showAdminBypassMessages: function(formWrapper, bypassInfo) {
            var messages = [];
            if (!bypassInfo) {
                // Fallback: show generic message if no bypass info
                messages.push(this.getString('bypassGeneric', 'Admin Bypass Mode Active - Geofence restrictions are disabled for administrators'));
            } else {
                // One notice for each bypassed restriction.
                if (bypassInfo.hasDatetime) {
                    messages.push(this.getString('bypassDatetime', 'Admin Bypass: Date/Time restrictions are disabled for administrators'));
                }
                if (bypassInfo.hasGeo) {
                    messages.push(this.getString('bypassGeo', 'Admin Bypass: Geolocation restrictions are disabled for administrators'));
                }
                if (!bypassInfo.hasDatetime && !bypassInfo.hasGeo) {
                    messages.push(this.getString('bypassActive', 'Admin Bypass Mode Active'));
                }
            }

            // Prepended in reverse so they read in the order above, ahead of
            // the form's own notices (#1842).
            messages.reverse().forEach(function(message) {
                formWrapper.prepend(window.FFC.notice('info', 'unlock', message, '', { role: 'note', hook: 'geofence-bypass' }));
            });
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

    /*
     * The pre-flight steps that are geofence's (#2030). They run in the
     * order the caller gives FFC.Preflight.run().
     */
    window.FFC.Preflight.register('datetime', {
        // The server's timestamp is trusted; a bypassed window arrives
        // disabled.
        applies: function(ctx) {
            return !!(ctx.config.datetime && ctx.config.datetime.enabled);
        },
        run: function(ctx, next) {
            const result = FFCGeofence.validateDateTime(ctx.config.datetime);
            if (result.valid) {
                next();
                return;
            }
            // Per-phase hide mode (#159 S4): before, during or after.
            FFCGeofence.handleBlocked(
                ctx.wrapper,
                FFCGeofence.pickHideMode(ctx.config.datetime, result.phase),
                ctx.config.datetime.message || result.message
            );
        }
    });

    window.FFC.Preflight.register('geo', {
        run: function(ctx) {
            FFCGeofence.processGeoOrShow(ctx.wrapper, ctx.config);
        }
    });

    $(document).ready(function() {
        FFCGeofence.init();
    });

})(jQuery);
