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

                var matches = (currentValue == showValue);
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