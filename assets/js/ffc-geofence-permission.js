/**
 * FFC Geofence Frontend — the GPS-permission banners.
 *
 * Extends window.FFCGeofence (ffc-geofence-frontend.js). The GPS step asks
 * the Permissions API before it asks for the position, and draws one of
 * these when it already knows the answer: "Location blocked" with the
 * platform's how-to, or "We need your location" so the browser's prompt
 * fires inside a fresh tap. The banner, its telemetry and the cookie step
 * are FFC.Preflight's (ffc-preflight.js, #2030).
 *
 * @package FFC
 * @since 6.6.4 (split out of ffc-geofence-frontend.js)
 */

(function() {
    'use strict';

    var FFCGeofence = window.FFCGeofence;

    Object.assign(FFCGeofence, {

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
                    //
                    // And only while the banner is still waiting. Once the
                    // visitor has clicked through it, a check is already
                    // running, and the grant it prompts for is the very
                    // change that fires this: re-entering would ask for the
                    // position a second time and, outside the area, block
                    // the wrapper twice (#1668).
                    const banner = formWrapper[0].querySelector('[data-ffc-notice="preflight-gps"]');
                    if (status.state === 'granted' && banner) {
                        banner.remove();
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
            window.FFC.Preflight.log(formWrapper, 'gps_denied');

            const platform = window.FFC.Platform.os();
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

            window.FFC.Preflight.banner(formWrapper, 'preflight-gps', title, body, instructions, tryAnyway, function () {
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
            window.FFC.Preflight.log(formWrapper, 'gps_prompt');

            const title = this.getString('gpsPromptTitle', 'We need your location');
            const body = this.getString(
                'gpsPromptBody',
                'This site needs to confirm you are at the venue. After you tap "Continue", your browser will ask for permission.'
            );
            const cta = this.getString('gpsPromptContinue', 'Continue');

            window.FFC.Preflight.banner(formWrapper, 'preflight-gps', title, body, '', cta, function () {
                self.validateGeolocation(formWrapper, config, true);
            });
        }

    });

})();
