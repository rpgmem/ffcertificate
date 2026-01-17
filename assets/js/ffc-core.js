/**
 * FFC Core Module
 * v3.0.0 - Modular Architecture
 * 
 * Global namespace initialization and shared constants
 * This file should be loaded FIRST before all other FFC modules
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
        version: '3.0.0',
        
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
         * Enable debug mode
         */
        enableDebug: function() {
            this.config.debug = true;
            console.log('[FFC] Debug mode enabled');
        },
        
        /**
         * Disable debug mode
         */
        disableDebug: function() {
            this.config.debug = false;
            console.log('[FFC] Debug mode disabled');
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
        console.log('[FFC] Module registered:', name, 'v' + version);
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
    if (typeof jQuery !== 'undefined') {
        jQuery(document).ready(function() {
            console.log('[FFC Core] Initialized v' + window.FFC.version);
            
            // Log loaded modules after a short delay (to let other modules load)
            setTimeout(function() {
                var modules = window.FFC.getModules();
                if (modules.length > 0) {
                    console.log('[FFC] Loaded modules:', modules.map(function(m) { 
                        return m.name + ' v' + m.version; 
                    }).join(', '));
                }
            }, 500);
        });
    } else {
        console.warn('[FFC Core] jQuery not found. Some features may not work.');
    }
    
})(window);