/**
 * FFC quiz — clear the answers when the participant leaves the window (#1731).
 *
 * A form carrying `data-ffc-quiz-leave` (a JSON list of the scored question
 * names) watches `visibilitychange`, not `blur`: a system notification, a
 * screen reader or a click inside an embedded video blurs the window without
 * the person leaving it. Once the participant has started, leaving the page:
 *
 * - counts one exit in the hidden `ffc_quiz_focus_losses` input, kept in
 *   sessionStorage so a reload does not reset it;
 * - on return, clears the scored answers and opens the dialog that says why.
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

    function closeDialog(form) {
        var overlay = form.querySelector('.ffc-quiz-leave-overlay');
        if (overlay) {
            overlay.hidden = true;
        }
        var first = form.querySelector('input:not([type="hidden"]), select, textarea');
        if (first) {
            first.focus();
        }
    }

    /**
     * Watch one form. Returns the visibility handler, for tests.
     */
    function watch(form) {
        if (form.getAttribute('data-ffc-quiz-leave-bound') === '1') {
            return null;
        }
        form.setAttribute('data-ffc-quiz-leave-bound', '1');

        var started = false;
        var left = false;
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
                closeDialog(form);
            });
        }

        function onVisibility() {
            // A submitted form is replaced by the result; stop watching it.
            if (!document.documentElement.contains(form)) {
                document.removeEventListener('visibilitychange', onVisibility);
                try {
                    window.sessionStorage.removeItem(storageKey(form));
                } catch (e) {
                    // Nothing to clean up without storage.
                }
                return;
            }
            if (document.visibilityState === 'hidden') {
                if (started) {
                    left = true;
                    writeCount(form, readCount(form) + 1);
                }
                return;
            }
            if (left) {
                left = false;
                questions(form).forEach(function(name) {
                    clearQuestion(form, name);
                });
                openDialog(form);
            }
        }

        document.addEventListener('visibilitychange', onVisibility);
        return onVisibility;
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('form[data-ffc-quiz-leave]'), watch);
    }

    // Not on window.FFC: this file may load before ffc-core.js (#1739).
    window.ffcQuizLeave = { init: init, watch: watch };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
