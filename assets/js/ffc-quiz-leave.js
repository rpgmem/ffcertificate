/**
 * FFC quiz — clear the answers when the participant leaves the window
 * (#1731, #1745).
 *
 * A form carrying `data-ffc-quiz-leave` (a JSON list of the scored question
 * names) watches the participant once they have started. Two levels, read
 * from `data-ffc-quiz-leave-level`:
 *
 * - Moderate (`1`): `visibilitychange` to hidden — another tab, a minimised
 *   browser. Not `blur`: a system notification, a screen reader or a click
 *   inside an embedded video blurs the window without the person leaving it.
 * - Strict (`2`): also the window's `blur`, so two windows side by side are
 *   caught. A blur counts only when the focus stays away longer than
 *   `data-ffc-quiz-leave-grace` milliseconds, and never when the focus moved
 *   into an iframe of the page itself (a video, the ALTCHA widget).
 *
 * Each departure counts once in the hidden `ffc_quiz_focus_losses` input,
 * kept in sessionStorage so a reload does not reset it. On return the scored
 * answers are cleared and a dialog says why; its "Start again" reloads the
 * page, so options marked to shuffle are shuffled again.
 *
 * Only the scored answers are cleared; the name, the CPF/RF and the other
 * fields are the participant's. It is a deterrent, not a lock: another device
 * or a browser without JavaScript is not stopped, and the server only records
 * the count it is sent.
 *
 * @since 6.39.0
 */
(function(window, document) {
    'use strict';

    var STORAGE_PREFIX = 'ffc_quiz_leaves_';
    var STRICT = '2';

    var api = {};

    function storageKey(form) {
        var id = form.querySelector('input[name="form_id"]');
        return STORAGE_PREFIX + (id ? id.value : form.id);
    }

    function readCount(form) {
        try {
            var n = parseInt(window.sessionStorage.getItem(storageKey(form)), 10);
            return isNaN(n) || n < 0 ? 0 : n;
        } catch (e) {
            return 0;
        }
    }

    function writeCount(form, n) {
        var input = form.querySelector('.ffc-quiz-focus-losses');
        if (input) {
            input.value = String(n);
        }
        try {
            window.sessionStorage.setItem(storageKey(form), String(n));
        } catch (e) {
            // Private mode or blocked storage: the hidden input still carries it.
        }
    }

    function questions(form) {
        try {
            var list = JSON.parse(form.getAttribute('data-ffc-quiz-leave') || '[]');
            return Array.isArray(list) ? list.map(String) : [];
        } catch (e) {
            return [];
        }
    }

    function grace(form) {
        var n = parseInt(form.getAttribute('data-ffc-quiz-leave-grace'), 10);
        return isNaN(n) || n < 0 ? 0 : n;
    }

    /**
     * Clear one question's answer, whatever its control.
     */
    function clearQuestion(form, name) {
        var safe = window.CSS && window.CSS.escape ? window.CSS.escape(name) : name;
        var controls = form.querySelectorAll('[name="' + safe + '"], [name="' + safe + '\\[\\]"]');
        Array.prototype.forEach.call(controls, function(el) {
            if (el.type === 'radio' || el.type === 'checkbox') {
                el.checked = false;
                el.disabled = false;
            } else if (el.tagName === 'SELECT') {
                el.selectedIndex = el.options.length && el.options[0].value === '' ? 0 : -1;
            } else {
                el.value = '';
            }
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    function openDialog(form) {
        var overlay = form.querySelector('.ffc-quiz-leave-overlay');
        if (!overlay) {
            return;
        }
        overlay.hidden = false;
        var button = overlay.querySelector('.ffc-quiz-leave-restart');
        if (button) {
            button.focus();
        }
    }

    /**
     * Whether the focus went into a frame of this page rather than away
     * from it: the window blurs in both cases.
     */
    function focusIsInPageFrame() {
        var active = document.activeElement;
        return !!active && active.tagName === 'IFRAME';
    }

    /**
     * Reload the page. A property of the API so a test can observe it.
     */
    api.reload = function() {
        window.location.reload();
    };

    /**
     * Watch one form. Returns the visibility handler, for tests.
     */
    function watch(form) {
        if (form.getAttribute('data-ffc-quiz-leave-bound') === '1') {
            return null;
        }
        form.setAttribute('data-ffc-quiz-leave-bound', '1');

        var strict = form.getAttribute('data-ffc-quiz-leave-level') === STRICT;
        var graceMs = grace(form);
        var started = false;
        var left = false;
        var reloading = false;
        var blurTimer = null;
        writeCount(form, readCount(form));

        form.addEventListener('input', function() {
            started = true;
        });
        form.addEventListener('change', function() {
            started = true;
        });

        var restart = form.querySelector('.ffc-quiz-leave-restart');
        if (restart) {
            restart.addEventListener('click', function() {
                // Unloading hides the page: that is not a departure.
                reloading = true;
                api.reload();
            });
        }

        function cancelBlur() {
            if (blurTimer !== null) {
                window.clearTimeout(blurTimer);
                blurTimer = null;
                return true;
            }
            return false;
        }

        function depart() {
            cancelBlur();
            if (!started || left || reloading) {
                return;
            }
            left = true;
            writeCount(form, readCount(form) + 1);
        }

        function comeBack() {
            if (!left) {
                return;
            }
            left = false;
            questions(form).forEach(function(name) {
                clearQuestion(form, name);
            });
            openDialog(form);
        }

        function detached() {
            if (document.documentElement.contains(form)) {
                return false;
            }
            stop();
            return true;
        }

        function onBlur() {
            if (detached()) {
                return;
            }
            if (!started || left || reloading || focusIsInPageFrame()) {
                return;
            }
            cancelBlur();
            if (graceMs === 0) {
                depart();
                return;
            }
            blurTimer = window.setTimeout(function() {
                blurTimer = null;
                depart();
            }, graceMs);
        }

        function onFocus() {
            if (detached()) {
                return;
            }
            // Back within the grace: the loss does not count.
            if (cancelBlur()) {
                return;
            }
            comeBack();
        }

        function stop() {
            cancelBlur();
            document.removeEventListener('visibilitychange', onVisibility);
            window.removeEventListener('blur', onBlur);
            window.removeEventListener('focus', onFocus);
            try {
                window.sessionStorage.removeItem(storageKey(form));
            } catch (e) {
                // Nothing to clean up without storage.
            }
        }

        function onVisibility() {
            // A submitted form is replaced by the result; stop watching it.
            if (detached()) {
                return;
            }
            if (document.visibilityState === 'hidden') {
                depart();
                return;
            }
            comeBack();
        }

        document.addEventListener('visibilitychange', onVisibility);
        if (strict) {
            window.addEventListener('blur', onBlur);
            window.addEventListener('focus', onFocus);
        }
        return onVisibility;
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('form[data-ffc-quiz-leave]'), watch);
    }

    // Not on window.FFC: this file may load before ffc-core.js (#1739).
    api.init = init;
    api.watch = watch;
    window.ffcQuizLeave = api;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
