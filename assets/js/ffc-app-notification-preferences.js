/**
 * FFC — the Notifications section of the app's Preferences (site-pwa#66).
 *
 * The e-mail toggles the dashboard keeps on its Profile and Messages tabs,
 * saved through the same `user/profile` route the moment one flips. Only
 * the toggles drawn are posted; the server merges them into the rest.
 */

(function () {
    'use strict';

    var config = window.ffcAppNotificationPreferences || {};
    var strings = config.strings || {};
    var timer = null;

    function say(status, text, tone) {
        if (!status) return;
        status.textContent = text;
        status.className = 'ffc-app-notification-status ffc-status is-' + tone;
        status.hidden = false;
        if (timer) clearTimeout(timer);
        if (tone === 'success') {
            timer = setTimeout(function () { status.hidden = true; }, 2000);
        }
    }

    function save(box) {
        if (!window.FFC || typeof window.FFC.rest !== 'function') return;
        var prefs = {};
        box.querySelectorAll('.ffc-app-notification-toggle').forEach(function (input) {
            prefs[input.getAttribute('data-key')] = input.checked;
        });
        var status = box.querySelector('.ffc-app-notification-status');
        window.FFC.rest(config.rest, { method: 'PUT', data: { preferences: prefs }, nonce: config.nonce })
            .then(function () { say(status, strings.saved || 'Saved', 'success'); })
            .catch(function () { say(status, strings.failed || 'Error', 'error'); });
    }

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (!target || !target.classList || !target.classList.contains('ffc-app-notification-toggle')) return;
        var box = target.closest('.ffc-app-notification-preferences');
        if (box) save(box);
    });
})();
