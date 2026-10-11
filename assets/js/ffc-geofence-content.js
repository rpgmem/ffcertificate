/**
 * FFC Geofence — protected content ([ffc_geofence], #1668).
 *
 * Runs the same checks a geofenced form runs (date/time, GPS with its
 * pre-flight and fallbacks), through the shared window.FFCGeofence object,
 * on every `[data-ffc-geofence-handle]` placeholder. Where a form would be
 * revealed, the content is fetched instead: the server re-runs the
 * date/time and IP checks and returns it only if they pass. The page HTML
 * never carries the content itself.
 *
 * @package FFC
 */

(function($) {
    'use strict';

    const geofence = window.FFCGeofence;
    if (!geofence) {
        return;
    }

    const showForm = geofence.showForm;
    const handleBlocked = geofence.handleBlocked;

    function isContent(wrapper) {
        return !!(wrapper && wrapper.is && wrapper.is('[data-ffc-geofence-handle]'));
    }

    // Where the shared flows would reveal a form, a content placeholder
    // fetches its content instead.
    geofence.showForm = function(wrapper) {
        if (isContent(wrapper)) {
            return this.loadContent(wrapper);
        }
        return showForm.apply(this, arguments);
    };

    // The placeholder renders with its own loading message; a block must
    // replace it rather than sit beneath it.
    geofence.handleBlocked = function(wrapper) {
        if (isContent(wrapper)) {
            this.hideLoadingMessage(wrapper);
        }
        return handleBlocked.apply(this, arguments);
    };

    Object.assign(geofence, {
        /**
         * Check every protected-content placeholder on the page.
         */
        initContent: function() {
            const self = this;
            $('[data-ffc-geofence-handle]').each(function() {
                self.processContent($(this));
            });
        },

        /**
         * Same order as a form: date/time first, then location.
         *
         * @param {jQuery} wrapper Placeholder element.
         */
        processContent: function(wrapper) {
            let config;
            try {
                config = JSON.parse(wrapper.attr('data-ffc-geofence-config') || '{}');
            } catch (e) {
                config = {};
            }

            if (config.adminBypass === true) {
                this.showBypassBadge(wrapper, config.bypassInfo);
            }

            if (config.datetime && config.datetime.enabled) {
                const datetime = this.validateDateTime(config.datetime);
                if (!datetime.valid) {
                    this.handleBlocked(
                        wrapper,
                        this.pickHideMode(config.datetime, datetime.phase),
                        config.datetime.message || datetime.message
                    );
                    return;
                }
            }

            this.processGeoOrShow(wrapper, config);
        },

        /**
         * A small badge over the content telling an administrator that a
         * restriction was skipped for them. A form shows full notices; here
         * they would sit above content that is the page's own (#1668). The
         * badge survives the content replacing the placeholder.
         *
         * @param {jQuery} wrapper    Placeholder element.
         * @param {Object} bypassInfo Which restrictions were skipped.
         */
        showBypassBadge: function(wrapper, bypassInfo) {
            const settings = window.ffcGeofenceContent || {};
            const label = (settings.strings && settings.strings.bypassBadge) || 'Admin bypass';
            const details = [];
            if (bypassInfo && bypassInfo.hasDatetime) {
                details.push(this.getString('bypassDatetime', 'Admin Bypass: Date/Time restrictions are disabled for administrators'));
            }
            if (bypassInfo && bypassInfo.hasGeo) {
                details.push(this.getString('bypassGeo', 'Admin Bypass: Geolocation restrictions are disabled for administrators'));
            }
            const $badge = $('<span class="ffc-geofence-bypass-badge ffc-icon-unlock"></span>').text(label);
            if (details.length) {
                $badge.attr('title', details.join('\n'));
            }
            wrapper.data('ffcBypassBadge', $badge);
            wrapper.prepend($badge);
        },

        /**
         * Fetch the content and put it in place of the placeholder.
         *
         * @param {jQuery} wrapper Placeholder element.
         * @returns {Promise|undefined}
         */
        loadContent: function(wrapper) {
            const self = this;
            const settings = window.ffcGeofenceContent || {};
            if (wrapper.data('ffcGeofenceRequested')) {
                return undefined;
            }
            wrapper.data('ffcGeofenceRequested', true);

            return window.FFC.request(
                settings.action || 'ffc_geofence_content',
                { handle: wrapper.attr('data-ffc-geofence-handle') },
                { ajaxUrl: settings.ajaxUrl }
            ).then(function(data) {
                wrapper.addClass('ffc-validated').html(data && data.html ? data.html : '');
                if (wrapper.data('ffcBypassBadge')) {
                    wrapper.prepend(wrapper.data('ffcBypassBadge'));
                }
            }).catch(function(err) {
                const fallback = (settings.strings && settings.strings.unavailable) || 'This content is not available.';
                const message = (err && err.data && err.data.message)
                    || (err && err.fromServer ? err.message : '')
                    || fallback;
                self.hideLoadingMessage(wrapper);
                self.showBlockedMessage(wrapper, message);
            });
        }
    });

    $(function() {
        geofence.initContent();
    });

})(jQuery);
