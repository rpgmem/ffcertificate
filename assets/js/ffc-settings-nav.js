/**
 * Keyboard movement in the Settings section list (#2338).
 *
 * The list is links, one per tab, each loading its own page, so Tab still
 * walks it as usual. On top of that, the arrow keys move between the tab
 * links, and Home and End go to the first and the last one, as in a tab
 * strip. The group labels are not links, so the keys never land on them, and
 * the "Go to" links leave the screen for another one, so the keys skip them
 * too: they stay reachable with Tab.
 */
(function () {
    'use strict';

    var TAB = '.ffc-settings-tabs__tab:not(.ffc-settings-tabs__tab--module)';

    function onKey(e) {
        var current = e.target;
        if (!current || !current.matches || !current.matches(TAB)) {
            return;
        }
        var list = current.closest('.ffc-settings-tabs__list');
        if (!list) {
            return;
        }
        var tabs = Array.prototype.slice.call(list.querySelectorAll(TAB));
        var at = tabs.indexOf(current);
        var next = null;

        switch (e.key) {
            case 'ArrowDown':
            case 'ArrowRight':
                next = tabs[(at + 1) % tabs.length];
                break;
            case 'ArrowUp':
            case 'ArrowLeft':
                next = tabs[(at - 1 + tabs.length) % tabs.length];
                break;
            case 'Home':
                next = tabs[0];
                break;
            case 'End':
                next = tabs[tabs.length - 1];
                break;
            default:
                return;
        }
        if (next && next !== current) {
            e.preventDefault();
            next.focus();
        }
    }

    function init() {
        var lists = document.querySelectorAll('.ffc-settings-tabs__list');
        Array.prototype.forEach.call(lists, function (list) {
            if (list.getAttribute('data-ffc-keys') === 'bound') {
                return;
            }
            list.setAttribute('data-ffc-keys', 'bound');
            list.addEventListener('keydown', onKey);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.FFCSettingsNav = { init: init };
})();
