/**
 * Danger Zone delete: a chosen target, a typed word and a countdown (#2338).
 *
 * The button stays disabled until a target is chosen. Submitting opens the
 * plugin's confirmation dialog (`FFC.overrideConfirm`), which names the target
 * and how many submissions it removes, asks for the confirmation word and
 * holds the delete button through the shared countdown. Only after that does
 * the form post, carrying the word, which the server checks again.
 *
 * Strings come from `ffcDangerZone` (TabAdvanced::enqueue_scripts()).
 */
(function () {
    'use strict';

    /**
     * Fill `%s` / `%1$s` / `%2$s` placeholders in order.
     *
     * @param {string} text   Translated pattern.
     * @param {Array}  values Values, in placeholder order.
     * @return {string}
     */
    function fill(text, values) {
        var i = 0;
        return String(text || '').replace(/%(\d+)\$s|%s/g, function (whole, position) {
            var index = position ? parseInt(position, 10) - 1 : i++;
            return values[index] !== undefined ? String(values[index]) : whole;
        });
    }

    function init() {
        var form = document.getElementById('ffc-danger-zone-form');
        if (!form || form.getAttribute('data-ffc-danger-zone') === 'bound') {
            return;
        }
        var select = document.getElementById('ffc_delete_target');
        var button = document.getElementById('ffc-danger-zone-delete');
        var word = document.getElementById('ffc_delete_confirm');
        if (!select || !button || !word) {
            return;
        }
        form.setAttribute('data-ffc-danger-zone', 'bound');

        var s = window.ffcDangerZone || {};
        var reset = document.getElementById('ffc_reset_counter');
        var locked = form.querySelector('fieldset');
        var confirmed = false;

        function sync() {
            button.disabled = (locked && locked.disabled) || select.value === '';
        }
        select.addEventListener('change', sync);
        sync();

        form.addEventListener('submit', function (e) {
            if (confirmed) {
                return;
            }
            e.preventDefault();
            if (select.value === '' || !window.FFC || typeof window.FFC.overrideConfirm !== 'function') {
                return;
            }

            var option = select.options[select.selectedIndex];
            var count = option.getAttribute('data-count-label') || '0';
            var reasons = [
                select.value === 'all'
                    ? fill(s.targetAll, [count])
                    : fill(s.targetForm, [option.getAttribute('data-label') || '', count])
            ];
            if (reset && reset.checked) {
                reasons.push(s.reset);
            }
            reasons.push(s.final);

            window.FFC.overrideConfirm({
                strings: s.dialog || {},
                reasons: reasons,
                agreeCategory: 'danger',
                agreeIcon: 'delete',
                matchField: { label: fill(s.matchLabel, [s.word]), word: s.word }
            }).then(function (agreed) {
                if (!agreed) {
                    return;
                }
                word.value = String(s.word || '');
                confirmed = true;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.FFCDangerZone = { init: init, fill: fill };
})();
