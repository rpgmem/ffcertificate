/**
 * FFC Dark Mode Toggle
 *
 * Applies the .ffc-dark-mode class to <html> based on the plugin setting.
 * Settings: 'off' (default), 'on' (always dark), 'auto' (follow OS).
 *
 * The plugin's own setting is the single source of truth — there is no
 * `prefers-color-scheme` anywhere in the CSS. The OS is consulted here, and
 * only when the setting is 'auto'.
 *
 * @since 4.6.16
 */
(function() {
    'use strict';

    var root = document.documentElement;
    var mq   = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var mode = (typeof ffcDarkMode !== 'undefined' && ffcDarkMode.mode) ? ffcDarkMode.mode : 'off';

    function paint(enable) {
        if (enable) {
            root.classList.add('ffc-dark-mode');
        } else {
            root.classList.remove('ffc-dark-mode');
        }
    }

    function onOsChange(e) {
        paint(e.matches);
    }

    /**
     * Apply a mode, and keep following the OS only while the mode is 'auto'.
     *
     * The listener is removed on every call before being re-added, so
     * switching 'auto' → 'off' actually stops following the OS instead of
     * leaving a listener that repaints the page on the next OS change.
     *
     * @param {string} next 'off' | 'on' | 'auto'
     */
    function apply(next) {
        if (mq && mq.removeEventListener) {
            mq.removeEventListener('change', onOsChange);
        }
        if (next === 'on') {
            paint(true);
            return;
        }
        if (next === 'auto' && mq) {
            paint(mq.matches);
            if (mq.addEventListener) {
                mq.addEventListener('change', onOsChange);
            }
            return;
        }
        paint(false);
    }

    apply(mode);

    // Live switch for the admin's own Dark Mode select, which auto-saves.
    // Without this the option was written and the page kept its old theme
    // until the next load — in BOTH directions, which is why it read as a
    // broken save rather than a missing repaint. The widget fires this event
    // for every autosaved key; only ours is acted on. On the frontend the
    // event never fires, so this listener costs nothing there.
    document.addEventListener('ffc:setting-saved', function(e) {
        if (e.detail && e.detail.key === 'dark_mode') {
            apply(String(e.detail.value));
        }
    });
})();
