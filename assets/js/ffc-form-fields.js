/**
 * FFC form fields — the participant side of a field's options and limits.
 *
 * - Shuffles the options of a field marked `data-ffc-shuffle`. It runs in
 *   the browser on purpose: a page cache would freeze an order the server
 *   chose, and every participant would see the same one.
 * - Counts characters under a text or textarea (the `.ffc-field-counter`
 *   whose `data-ffc-counter-for` names it).
 * - Caps a checkbox group at `data-ffc-max-select`, disabling the boxes left
 *   unticked once the cap is reached and saying so in a field message.
 *   The counter is `aria-hidden`; the message is what is announced (#1844).
 * - Makes a required checkbox group (`data-ffc-required`) refuse an empty
 *   answer through the browser's own validation: HTML `required` on a box
 *   would demand that box, not "at least one".
 * - Makes a name field (`data-ffc-full-name`) refuse a single word.
 *
 * The server enforces every one of these again; this file only spares the
 * participant a round trip (#1729).
 *
 * @since 6.39.0
 */
(function(window, document) {
    'use strict';

    function strings() {
        return (window.ffcFormFields && window.ffcFormFields.strings) || {};
    }

    function format(template, values) {
        var i = 0;
        return String(template).replace(/%(\d+\$)?s/g, function(match, position) {
            var index = position ? parseInt(position, 10) - 1 : i++;
            return values[index] === undefined ? '' : values[index];
        });
    }

    function number(n) {
        try {
            return Number(n).toLocaleString(document.documentElement.lang || undefined);
        } catch (e) {
            return String(n);
        }
    }

    /**
     * Shuffle a container's options in place (Fisher–Yates). A select keeps
     * its empty "Select..." option first.
     */
    function shuffle(container, random) {
        var rand = random || Math.random;
        var items = Array.prototype.slice.call(container.children).filter(function(el) {
            return !(el.tagName === 'OPTION' && el.value === '');
        });
        for (var i = items.length - 1; i > 0; i--) {
            var j = Math.floor(rand() * (i + 1));
            var tmp = items[i];
            items[i] = items[j];
            items[j] = tmp;
        }
        items.forEach(function(el) {
            container.appendChild(el);
        });
    }

    function lengthCounter(control) {
        if (!control.id || !control.matches || !control.matches('input, textarea')) {
            return null;
        }
        var root = control.form || document;
        return Array.prototype.filter.call(root.querySelectorAll('[data-ffc-counter-for]'), function(counter) {
            return counter.getAttribute('data-ffc-counter-for') === control.id;
        })[0] || null;
    }

    function updateLength(control) {
        var counter = lengthCounter(control);
        if (!counter) {
            return;
        }
        var max = parseInt(control.getAttribute('maxlength'), 10);
        var min = parseInt(control.getAttribute('minlength'), 10) || 0;
        var length = Array.from(control.value).length;
        counter.textContent = number(length) + ' / ' + number(max);
        counter.classList.toggle('is-below-min', length > 0 && length < min);
    }

    function boxes(group) {
        return Array.prototype.slice.call(group.querySelectorAll('input[type="checkbox"]'));
    }

    function updateGroup(group) {
        var all = boxes(group);
        var ticked = all.filter(function(box) { return box.checked; }).length;
        var max = parseInt(group.getAttribute('data-ffc-max-select'), 10) || 0;

        if (max > 0) {
            var full = ticked >= max;
            all.forEach(function(box) {
                box.disabled = full && !box.checked;
                box.closest('label').classList.toggle('is-disabled', box.disabled);
            });
            var counter = group.querySelector('.ffc-field-counter');
            if (counter) {
                counter.textContent = format(strings().selected || '%1$s of %2$s selected', [number(ticked), number(max)]);
            }
            // The limit is said in a warning under the boxes; it blocks
            // nothing, the boxes left unticked are simply off.
            var FFC = window.FFC;
            if (FFC && typeof FFC.fieldMessage === 'function') {
                if (full) {
                    FFC.fieldMessage(all, 'warning', strings().limitReached || 'Untick one to choose another.');
                    group.setAttribute('data-ffc-limit-shown', '1');
                } else if (group.hasAttribute('data-ffc-limit-shown')) {
                    FFC.clearFieldMessage(all);
                    group.removeAttribute('data-ffc-limit-shown');
                }
            }
        }

        if (group.getAttribute('data-ffc-required') === '1' && all.length) {
            all[0].setCustomValidity(ticked > 0 ? '' : (strings().chooseOne || 'Choose at least one option.'));
        }
    }

    // A name field needs two words, each with a letter (#1734); the server
    // refuses the same thing, this only says it before the round trip.
    function updateFullName(control) {
        var words = control.value.trim().split(/\s+/).filter(function(word) {
            return /\p{L}/u.test(word);
        });
        var ok = control.value.trim() === '' || words.length >= 2;
        control.setCustomValidity(ok ? '' : (strings().fullName || 'Enter the first and last name.'));
    }

    function init(root) {
        var scope = root || document;

        Array.prototype.forEach.call(scope.querySelectorAll('[data-ffc-shuffle]'), function(container) {
            if (!container.hasAttribute('data-ffc-shuffled')) {
                shuffle(container);
                container.setAttribute('data-ffc-shuffled', '1');
            }
        });

        Array.prototype.forEach.call(scope.querySelectorAll('[data-ffc-counter-for]'), function(counter) {
            var control = document.getElementById(counter.getAttribute('data-ffc-counter-for'));
            if (control && lengthCounter(control) === counter) {
                updateLength(control);
            }
        });

        Array.prototype.forEach.call(scope.querySelectorAll('.ffc-choice-checkbox'), updateGroup);
        Array.prototype.forEach.call(scope.querySelectorAll('[data-ffc-full-name]'), updateFullName);
    }

    document.addEventListener('input', function(e) {
        if (e.target && lengthCounter(e.target)) {
            updateLength(e.target);
        }
        if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-ffc-full-name')) {
            updateFullName(e.target);
        }
    });

    document.addEventListener('change', function(e) {
        var group = e.target && e.target.closest ? e.target.closest('.ffc-choice-checkbox') : null;
        if (group) {
            updateGroup(group);
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { init(document); });
    } else {
        init(document);
    }

    window.FFC = window.FFC || {};
    window.FFC.FormFields = {
        init: init,
        shuffle: shuffle,
        updateGroup: updateGroup,
        updateLength: updateLength,
        updateFullName: updateFullName
    };
})(window, document);
