/**
 * FFC — the Messages section of the app's Preferences (site-pwa#66).
 *
 * The two e-mail toggles the dashboard's Messages tab carries on the site,
 * saved through the same `user/profile` route the moment one flips.
 */

(function () {
    'use strict';

    var config = window.ffcAppMessagePreferences || {};
    var strings = config.strings || {};
    var timer = null;

    function root() {
        return document.querySelector('.ffc-app-message-preferences');
    }

    function say(status, text, tone) {
        if (!status) return;
        status.textContent = text;
        status.className = 'ffc-message-pref-status ffc-status is-' + tone;
        status.hidden = false;
        if (timer) clearTimeout(timer);
        if (tone === 'success') {
            timer = setTimeout(function () { status.hidden = true; }, 2000);
        }
    }

    function save() {
        var box = root();
        if (!box || !window.FFC || typeof window.FFC.rest !== 'function') return;
        var prefs = {};
        box.querySelectorAll('.ffc-message-pref-toggle').forEach(function (input) {
            prefs[input.getAttribute('data-key')] = input.checked;
        });
        var status = box.querySelector('.ffc-message-pref-status');
        window.FFC.rest(config.rest, { method: 'PUT', data: { preferences: prefs }, nonce: config.nonce })
            .then(function () { say(status, strings.saved || 'Saved', 'success'); })
            .catch(function () { say(status, strings.failed || 'Error', 'error'); });
    }

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (target && target.classList && target.classList.contains('ffc-message-pref-toggle') && target.closest('.ffc-app-message-preferences')) {
            save();
        }
    });
})();
