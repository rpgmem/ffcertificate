/**
 * FFC Geofence Frontend — cookie + GPS-permission pre-flight banners.
 *
 * Extends window.FFCGeofence (ffc-geofence-frontend.js). Methods stay on the
 * shared object so `this.*` resolves as in the pre-split single file.
 *
 * @package FFC
 * @since 6.6.4 (split out of ffc-geofence-frontend.js)
 */

(function($) {
    'use strict';

    var FFCGeofence = window.FFCGeofence;

    Object.assign(FFCGeofence, {

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
        }

    });

})(jQuery);
