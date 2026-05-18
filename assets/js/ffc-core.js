/**
 * FFC Core Module
 * v3.1.0 - Added centralized helpers
 *
 * Global namespace initialization and shared constants
 * This file should be loaded FIRST before all other FFC modules
 *
 * Changelog:
 * v3.1.0: Added FFC.ajax() and FFC.toggleFields() centralized helpers
 * v3.0.0: Modular Architecture
 *
 * @since 3.0.0
 */

(function(window) {
    'use strict';
    
    /**
     * Initialize global FFC namespace
     */
    window.FFC = window.FFC || {
        
        /**
         * Plugin version
         */
        version: (window.ffcCoreConfig && window.ffcCoreConfig.version) || '0.0.0',
        
        /**
         * Shared configuration
         */
        config: {
            debug: false,
            ajaxUrl: window.ffc_ajax?.ajax_url || '/wp-admin/admin-ajax.php',
            nonce: window.ffc_ajax?.nonce || '',
            strings: window.ffc_ajax?.strings || {}
        },
        
        /**
         * Check if a module is loaded
         * 
         * @param {string} moduleName - Module name (e.g., 'Utils', 'Frontend', 'Admin')
         * @return {boolean} True if module is loaded
         */
        isModuleLoaded: function(moduleName) {
            return typeof this[moduleName] !== 'undefined' && this[moduleName] !== null;
        },
        
        /**
         * Debug logger (only logs if debug mode is enabled)
         * 
         * @param {string} message - Message to log
         * @param {*} data - Optional data to log
         */
        log: function(message, data) {
            if (this.config.debug) {
                if (typeof data !== 'undefined') {
                    console.log('[FFC Debug]', message, data);
                } else {
                    console.log('[FFC Debug]', message);
                }
            }
        },
        
        /**
         * Error logger (always logs)
         * 
         * @param {string} message - Error message
         * @param {*} error - Optional error object
         */
        error: function(message, error) {
            if (typeof error !== 'undefined') {
                console.error('[FFC Error]', message, error);
            } else {
                console.error('[FFC Error]', message);
            }
        },
        
        /**
         * Warning logger (always logs)
         * 
         * @param {string} message - Warning message
         */
        warn: function(message) {
            console.warn('[FFC Warning]', message);
        },
        
        /**
         * Get AJAX URL
         * 
         * @return {string} AJAX URL
         */
        getAjaxUrl: function() {
            return this.config.ajaxUrl;
        },
        
        /**
         * Get nonce
         * 
         * @return {string} Nonce
         */
        getNonce: function() {
            return this.config.nonce;
        },
        
        /**
         * Get translated string
         *
         * @param {string} key - String key
         * @param {string} defaultValue - Default value if key not found
         * @return {string} Translated string
         */
        getString: function(key, defaultValue) {
            return this.config.strings[key] || defaultValue || key;
        },

        /**
         * Centralized AJAX helper
         *
         * @param {Object} options - AJAX configuration
         * @param {string} options.action - WordPress AJAX action name
         * @param {Object} options.data - Additional data to send
         * @param {Function} options.success - Success callback
         * @param {Function} options.error - Error callback
         * @param {string} options.method - HTTP method (default: 'POST')
         * @param {boolean} options.includeNonce - Include nonce automatically (default: true)
         * @return {jqXHR} jQuery AJAX object
         */
        ajax: function(options) {
            if (!options.action) {
                this.error('FFC.ajax: action parameter is required');
                return;
            }

            var ajaxData = options.data || {};
            ajaxData.action = options.action;

            // Include nonce by default
            if (options.includeNonce !== false && this.config.nonce) {
                ajaxData.nonce = this.config.nonce;
            }

            var ajaxOptions = {
                url: this.config.ajaxUrl,
                type: options.method || 'POST',
                data: ajaxData,
                success: options.success || function() {},
                error: options.error || function(xhr, status, error) {
                    FFC.error('AJAX request failed', {
                        action: options.action,
                        status: status,
                        error: error
                    });
                }
            };

            this.log('AJAX request', { action: options.action, data: ajaxData });

            return jQuery.ajax(ajaxOptions);
        },

        /**
         * Promise-based AJAX helper (admin-ajax convention).
         *
         * Wraps `jQuery.post` so the entire admin/frontend codebase has a
         * single chokepoint for cross-cutting concerns (nonce injection,
         * response unwrapping, error normalisation). Returns a native
         * Promise resolving with `response.data` on success or rejecting
         * with an `Error` whose message comes from `response.data.message`.
         *
         * Why this lives alongside the legacy `FFC.ajax`:
         *   - `FFC.ajax` is callback-based with options object — fine but
         *     verbose and inconsistent with modern code.
         *   - New code should use `FFC.request` for cleaner `.then/.catch`
         *     ergonomics. Old call-sites keep using `FFC.ajax`; migrations
         *     happen opportunistically when the file is touched.
         *
         * @param {string} action          WordPress AJAX action.
         * @param {Object} [data]          Payload (action + nonce injected).
         * @param {Object} [options]
         * @param {string} [options.nonce] Override the default nonce.
         * @param {string} [options.ajaxUrl] Override admin-ajax.php URL.
         * @returns {Promise<*>} Resolves with `response.data` on success.
         */
        request: function(action, data, options) {
            options = options || {};
            // Nonce resolution order: explicit options.nonce wins (used
            // by the locations CRUD style callers); a nonce baked into
            // `data` wins next (used by callers localized via their own
            // wp_localize_script that don't go through ffc_ajax — eg.
            // ffc-form-list-features.js, ffc-admin-autosave.js); the
            // global ffc_ajax nonce is the last-resort default.
            var resolvedNonce = options.nonce
                || (data && typeof data === 'object' && data.nonce)
                || this.config.nonce
                || '';
            // Callers may pass `data` as either an object (the usual
            // case) or a pre-serialised URL-encoded string — typically
            // the result of `$form.serialize()`. Both forms get action
            // + nonce appended.
            var payload;
            if (typeof data === 'string') {
                payload = data
                    + '&action=' + encodeURIComponent(action)
                    + '&nonce=' + encodeURIComponent(resolvedNonce);
            } else {
                payload = jQuery.extend({}, data, {
                    action: action,
                    nonce: resolvedNonce,
                });
            }
            var url = options.ajaxUrl || this.config.ajaxUrl || '/wp-admin/admin-ajax.php';
            // jQuery.post doesn't accept a timeout. When callers want one,
            // fall through to jQuery.ajax({ url, type:'POST', data, timeout })
            // which returns the same jqXHR-with-.done/.fail interface.
            return new Promise(function(resolve, reject) {
                var jqXHR = options.timeout
                    ? jQuery.ajax({ url: url, type: 'POST', data: payload, timeout: options.timeout })
                    : jQuery.post(url, payload);
                jqXHR
                    .done(function(res) {
                        if (!res || !res.success) {
                            // Server may send `data` as either an object
                            // ({message: '...'}) or a bare string — both
                            // are valid wp_send_json_error shapes.
                            var serverMsg = (res && typeof res.data === 'string')
                                ? res.data
                                : (res && res.data && res.data.message);
                            var msg = serverMsg
                                || (FFC.config.strings && FFC.config.strings.error)
                                || 'Request failed';
                            var err = new Error(msg);
                            // Lets callers distinguish "server told us
                            // what's wrong" from "library fell back to a
                            // generic string" — important for sites that
                            // want a caller-specific error label when no
                            // server message was supplied.
                            err.fromServer = !!serverMsg;
                            // Preserve the full server payload so callers
                            // can read auxiliary fields the server sent
                            // alongside the error (eg. refresh_captcha,
                            // new_hash, rate_limit_retry_after).
                            err.data = res && res.data;
                            reject(err);
                            return;
                        }
                        resolve(res.data);
                    })
                    .fail(function(xhr) {
                        var msg = (FFC.config.strings && FFC.config.strings.connectionError)
                            || 'Connection error';
                        var err = new Error(msg);
                        err.fromServer = false;
                        // Expose the jqXHR so callers can inspect
                        // responseJSON / status / etc. when the failure
                        // is an HTTP error (eg. 429 with a rate_limit
                        // payload in the body).
                        err.xhr = xhr;
                        if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                            err.data = xhr.responseJSON.data;
                        }
                        reject(err);
                    });
            });
        },

        /**
         * Promise-based WP REST API helper.
         *
         * Sibling of `FFC.request` for endpoints that live on the WP REST
         * surface instead of `admin-ajax.php`. Centralises the verbose
         * jQuery.ajax pattern that user-dashboard / audience callers
         * spell out by hand:
         *   - `X-WP-Nonce` header injection (REST convention, not body param);
         *   - JSON body encoding for write methods (POST/PUT/PATCH/DELETE);
         *   - error normalisation that surfaces `response.message` from
         *     a WP_REST_Response error body when available.
         *
         * @param {string} url URL completa. Callers passam
         *                     `ffcDashboard.restUrl + 'user/profile'` etc.
         *                     O helper não monta a URL para não acoplar
         *                     FFC.config a vars localizadas dashboard/audience.
         * @param {Object} [options]
         * @param {string} [options.method='GET'] HTTP verb.
         * @param {Object} [options.data] Payload. GET/HEAD → query string;
         *                                outros → JSON body.
         * @param {string} [options.nonce] X-WP-Nonce override. Default vem
         *                                 de `FFC.config.restNonce` se setado.
         * @returns {Promise<*>} Resolve com response body parseado;
         *                       rejeita com `Error` (campo `.xhr` preserva
         *                       o jqXHR para introspection).
         */
        rest: function(url, options) {
            options = options || {};
            var method = (options.method || 'GET').toUpperCase();
            var nonce = options.nonce || (this.config && this.config.restNonce) || '';

            var ajaxOpts = {
                url: url,
                method: method,
                beforeSend: function(xhr) {
                    if (nonce) {
                        xhr.setRequestHeader('X-WP-Nonce', nonce);
                    }
                }
            };

            if (typeof options.timeout === 'number') {
                ajaxOpts.timeout = options.timeout;
            }

            if (typeof options.data !== 'undefined' && options.data !== null) {
                if (method === 'GET' || method === 'HEAD') {
                    ajaxOpts.data = options.data;
                } else {
                    ajaxOpts.contentType = 'application/json';
                    ajaxOpts.data = JSON.stringify(options.data);
                }
            }

            return new Promise(function(resolve, reject) {
                // Pass success/error inside ajaxOpts (instead of chaining
                // .done/.fail on the return value) so test doubles that
                // intercept $.ajax can drive resolution by invoking
                // opts.success / opts.error directly.
                ajaxOpts.success = function(response) { resolve(response); };
                ajaxOpts.error = function(xhr) {
                    var msg = (xhr && xhr.responseJSON && xhr.responseJSON.message)
                        || (FFC.config.strings && FFC.config.strings.connectionError)
                        || 'Request failed';
                    var err = new Error(msg);
                    err.xhr = xhr;
                    reject(err);
                };
                jQuery.ajax(ajaxOpts);
            });
        },

        /**
         * Centralized field toggle helper
         *
         * @param {jQuery|string} $trigger - Trigger element (checkbox, radio, select)
         * @param {jQuery|string} $target - Target element(s) to show/hide
         * @param {*} showValue - Value that should show the target (default: true for checkboxes, first option for others)
         * @param {Object} options - Additional options
         * @param {boolean} options.useSlide - Use slideDown/slideUp animation (default: false)
         * @param {number} options.duration - Animation duration in ms (default: 200)
         * @param {boolean} options.invertLogic - Invert the show/hide logic (default: false)
         */
        toggleFields: function($trigger, $target, showValue, options) {
            $trigger = jQuery($trigger);
            $target = jQuery($target);
            options = options || {};

            if ($trigger.length === 0 || $target.length === 0) {
                this.warn('toggleFields: trigger or target not found');
                return;
            }

            var self = this;
            var useSlide = options.useSlide || false;
            var duration = options.duration || 200;
            var invertLogic = options.invertLogic || false;

            // Determine the show value if not provided
            if (typeof showValue === 'undefined') {
                if ($trigger.is(':checkbox')) {
                    showValue = true; // Checked = show
                } else if ($trigger.is('select')) {
                    showValue = $trigger.find('option:first').val();
                }
            }

            // Function to check if target should be visible
            var shouldShow = function() {
                var currentValue;

                if ($trigger.is(':checkbox')) {
                    currentValue = $trigger.is(':checked');
                } else if ($trigger.is(':radio')) {
                    currentValue = $trigger.filter(':checked').val();
                } else {
                    currentValue = $trigger.val();
                }

                var matches = (currentValue === showValue);
                return invertLogic ? !matches : matches;
            };

            // Function to update visibility
            var updateVisibility = function() {
                var show = shouldShow();

                if (useSlide) {
                    if (show) {
                        $target.slideDown(duration);
                    } else {
                        $target.slideUp(duration);
                    }
                } else {
                    $target.toggle(show);
                }

                self.log('toggleFields: visibility updated', { show: show });
            };

            // Bind change event
            $trigger.on('change', updateVisibility);

            // Initial update
            updateVisibility();

            this.log('toggleFields: initialized', {
                trigger: $trigger.length + ' element(s)',
                target: $target.length + ' element(s)',
                showValue: showValue
            });
        },

        /**
         * Enable debug mode
         */
        enableDebug: function() {
            this.config.debug = true;
        },

        /**
         * Disable debug mode
         */
        disableDebug: function() {
            this.config.debug = false;
        }
    };
    
    /**
     * Module registry for tracking loaded modules
     */
    window.FFC._modules = [];
    
    /**
     * Register a module
     * 
     * @param {string} name - Module name
     * @param {string} version - Module version
     */
    window.FFC.registerModule = function(name, version) {
        this._modules.push({
            name: name,
            version: version,
            loadedAt: new Date()
        });
    };
    
    /**
     * Get all registered modules
     * 
     * @return {Array} Array of registered modules
     */
    window.FFC.getModules = function() {
        return this._modules;
    };
    
    /**
     * Initialize on DOM ready
     */
    if (typeof jQuery === 'undefined') {
        console.warn('[FFC Core] jQuery not found. Some features may not work.');
    } else {
        jQuery(function($) {
            // Delegated confirm dialog for destructive actions
            $(document).on('click', '[data-confirm]', function(e) {
                if (!confirm($(this).attr('data-confirm'))) {
                    e.preventDefault();
                }
            });
        });
    }

})(window);