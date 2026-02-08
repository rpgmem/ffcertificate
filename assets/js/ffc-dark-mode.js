/**
 * FFC Dark Mode Toggle
 *
 * Applies the .ffc-dark-mode class to <html> based on the plugin setting.
 * Settings: 'off' (default), 'on' (always dark), 'auto' (follow OS).
 *
 * @since 4.6.16
 */
(function() {
    'use strict';

    var setting = (typeof ffcDarkMode !== 'undefined' && ffcDarkMode.mode) ? ffcDarkMode.mode : 'off';
    var root = document.documentElement;

    function applyDarkMode(enable) {
        if (enable) {
            root.classList.add('ffc-dark-mode');
        } else {
            root.classList.remove('ffc-dark-mode');
        }
    }

    if (setting === 'on') {
        applyDarkMode(true);
    } else if (setting === 'auto') {
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        applyDarkMode(mq.matches);
        mq.addEventListener('change', function(e) {
            applyDarkMode(e.matches);
        });
    }
})();
