/**
 * FFC Utils Module
 * v3.0.0 - Modular Architecture (Refactored)
 * 
 * General helper functions shared across the plugin
 * 
 * NOTE: Formatting functions (formatCPF, formatRF, formatAuthCode) moved to ffc-frontend-helpers.js
 * to consolidate all frontend-specific logic in one place.
 */
(function($, window) {
    'use strict';
    
    // Initialize FFC namespace
    window.FFC = window.FFC || {};
    
    /**
     * Utils module
     */
    window.FFC.Utils = {
        
        /**
         * Debounce function
         * Delays execution until after wait time has elapsed since last call
         * 
         * @param {function} func - Function to debounce
         * @param {number} wait - Wait time in ms
         * @return {function} Debounced function
         */
        debounce: function(func, wait) {
            var timeout;
            return function() {
                var context = this, args = arguments;
                clearTimeout(timeout);
                timeout = setTimeout(function() {
                    func.apply(context, args);
                }, wait);
            };
        },
        
        /**
         * Scroll to element smoothly
         * 
         * @param {jQuery} $element - Element to scroll to
         * @param {number} offset - Offset from top (default: 100)
         */
        scrollTo: function($element, offset) {
            offset = offset || 100;
            if ($element && $element.length) {
                $('html, body').animate({
                    scrollTop: $element.offset().top - offset
                }, 300);
            }
        },
        
        /**
         * Check if email is valid
         * 
         * @param {string} email - Email address
         * @return {boolean} True if valid format
         */
        isValidEmail: function(email) {
            var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        },
        
        /**
         * Generate random string
         * 
         * @param {number} length - String length
         * @return {string} Random alphanumeric string
         */
        randomString: function(length) {
            var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            var result = '';
            for (var i = 0; i < length; i++) {
                result += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            return result;
        },
        
        /**
         * Parse query string
         * 
         * @param {string} queryString - Query string (with or without ?)
         * @return {object} Parsed parameters
         */
        parseQueryString: function(queryString) {
            if (queryString.startsWith('?')) {
                queryString = queryString.slice(1);
            }
            var params = {};
            var pairs = queryString.split('&');
            for (var i = 0; i < pairs.length; i++) {
                var pair = pairs[i].split('=');
                if (pair[0]) {
                    params[decodeURIComponent(pair[0])] = decodeURIComponent(pair[1] || '');
                }
            }
            return params;
        },
        
        /**
         * Format date
         * 
         * @param {Date|string} date - Date object or string
         * @param {string} format - Format string (default: 'DD/MM/YYYY')
         * @return {string} Formatted date
         */
        formatDate: function(date, format) {
            if (typeof date === 'string') {
                date = new Date(date);
            }
            
            format = format || 'DD/MM/YYYY';
            
            var day = ('0' + date.getDate()).slice(-2);
            var month = ('0' + (date.getMonth() + 1)).slice(-2);
            var year = date.getFullYear();
            
            return format
                .replace('DD', day)
                .replace('MM', month)
                .replace('YYYY', year);
        },
        
        /**
         * Clean string (remove all non-alphanumeric characters)
         * 
         * @param {string} value - String to clean
         * @param {boolean} allowSpaces - Allow spaces (default: false)
         * @return {string} Clean string
         */
        cleanString: function(value, allowSpaces) {
            if (allowSpaces) {
                return value.replace(/[^a-zA-Z0-9\s]/g, '');
            }
            return value.replace(/[^a-zA-Z0-9]/g, '');
        },
        
        /**
         * Clean digits only (remove all non-numeric characters)
         * 
         * @param {string} value - String to clean
         * @return {string} Digits only
         */
        cleanDigits: function(value) {
            return value.replace(/\D/g, '');
        },
        
        /**
         * Truncate string to max length with ellipsis
         * 
         * @param {string} str - String to truncate
         * @param {number} maxLength - Maximum length
         * @return {string} Truncated string
         */
        truncate: function(str, maxLength) {
            if (str.length <= maxLength) {
                return str;
            }
            return str.substring(0, maxLength - 3) + '...';
        },
        
        /**
         * Escape HTML to prevent XSS
         * 
         * @param {string} html - HTML string to escape
         * @return {string} Escaped HTML
         */
        escapeHtml: function(html) {
            var div = document.createElement('div');
            div.textContent = html;
            return div.innerHTML;
        }
    };
    
    console.log('[FFC Utils] Module loaded v3.0.0 (refactored)');
    
})(jQuery, window);