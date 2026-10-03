/**
 * Field behaviours shared by every screen that renders custom fields.
 *
 * The reregistration form on the dashboard and the "FFC" section of the
 * wp-admin user screen render the same fields from the same definitions, so
 * they share one implementation of what those fields do in the browser:
 * - input masks (`data-mask`: cpf, phone, cep, rf, number, cin)
 * - dependent selects (`.ffc-dependent-select` → a hidden JSON input)
 * - the dual-post toggle (the accumulation fields follow `acumulo_cargos`)
 * - format checks (`cpf`, `email`, `phone`, `custom_regex`)
 *
 * Every function takes the container it works in, so a screen wires only the
 * part of the page that holds fields, and binds directly on the elements --
 * calling one twice on the same container binds twice, so call each once.
 *
 * @since 6.33.0
 * @package FreeFormCertificate
 */
(function ($) {
    'use strict';

    window.FFC = window.FFC || {};

    /**
     * Maps a mask name to its formatter. Each strips to digits, caps the
     * length, then inserts the separators for however many digits are there.
     */
    var MASKS = {
        cpf: function (value) {
            var v = value.replace(/\D/g, '').substring(0, 11);
            if (v.length > 9) {
                return v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
            }
            if (v.length > 6) {
                return v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
            }
            if (v.length > 3) {
                return v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
            }
            return v;
        },
        // (DD) 98765-4321: area code plus up to nine digits.
        phone: function (value) {
            var v = value.replace(/\D/g, '').substring(0, 11);
            if (v.length > 6) {
                return v.replace(/(\d{2})(\d{4,5})(\d{4})/, '($1) $2-$3');
            }
            if (v.length > 2) {
                return v.replace(/(\d{2})(\d{1,5})/, '($1) $2');
            }
            return v;
        },
        cep: function (value) {
            var v = value.replace(/\D/g, '').substring(0, 8);
            return v.length > 5 ? v.replace(/(\d{5})(\d{1,3})/, '$1-$2') : v;
        },
        // RF: XXX.XXX-X (7 digits).
        rf: function (value) {
            var v = value.replace(/\D/g, '').substring(0, 7);
            if (v.length > 6) {
                return v.replace(/(\d{3})(\d{3})(\d{1})/, '$1.$2-$3');
            }
            if (v.length > 3) {
                return v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
            }
            return v;
        },
        number: function (value) {
            return value.replace(/\D/g, '');
        },
        // CIN: XX.XXX.XXX-X.
        cin: function (value) {
            var v = value.replace(/\D/g, '').substring(0, 9);
            if (v.length > 8) {
                return v.replace(/(\d{2})(\d{3})(\d{3})(\d{1})/, '$1.$2.$3-$4');
            }
            if (v.length > 5) {
                return v.replace(/(\d{2})(\d{3})(\d{1,3})/, '$1.$2.$3');
            }
            if (v.length > 2) {
                return v.replace(/(\d{2})(\d{1,3})/, '$1.$2');
            }
            return v;
        }
    };

    /**
     * Masks every `[data-mask]` input inside the container. An unknown mask
     * name is left alone rather than guessed at.
     */
    function initMasks($container) {
        $container.find('[data-mask]').each(function () {
            var format = MASKS[$(this).attr('data-mask')];
            if (!format) {
                return;
            }
            $(this).on('input', function () {
                this.value = format(this.value);
            });
        });
    }

    /**
     * Wires each `.ffc-dependent-select`: the child list follows the parent,
     * and the pair is written to the hidden input `data-target` names as
     * `{"parent": …, "child": …}`, which is what the server stores.
     */
    function initDependentSelects($container, strings) {
        var S = strings || {};

        $container.find('.ffc-dependent-select').each(function () {
            var $wrap = $(this);
            var $hidden = $container.find('#' + $wrap.data('target'));
            var $parent = $wrap.find('.ffc-dep-parent');
            var $child = $wrap.find('.ffc-dep-child');
            var groups;

            try {
                groups = JSON.parse($wrap.find('.ffc-dep-groups').text());
            } catch (e) {
                return;
            }

            function updateHidden() {
                $hidden.val(JSON.stringify({
                    parent: $parent.val() || '',
                    child: $child.val() || ''
                }));
            }

            $parent.on('change', function () {
                var parentVal = $(this).val();
                $child.empty().append($('<option value="">').text(S.select || 'Select'));

                if (parentVal && groups[parentVal]) {
                    $.each(groups[parentVal], function (_, item) {
                        $child.append($('<option>').val(item).text(item));
                    });
                }
                updateHidden();
            });

            $child.on('change', updateHidden);
        });
    }

    /**
     * The accumulation fields only count when the person declares they hold a
     * second post; the server discards them otherwise, so they are hidden --
     * and their `required` lifted, because constraint validation ignores
     * visibility and a hidden required field blocks the submit.
     *
     * Fields are found by `data-field-key`, which both renderers emit on the
     * field's wrapper.
     *
     * @param {jQuery} $container
     * @param {string} showValue  The option that reveals the fields ("I hold", translated).
     */
    function initDualPost($container, showValue) {
        var $select = $container.find('[data-field-key="acumulo_cargos"] select');
        var $fields = $container.find(
            '[data-field-key="jornada_acumulo"],' +
            '[data-field-key="cargo_funcao_acumulo"],' +
            '[data-field-key="horario_trabalho_acumulo"]'
        );

        if (!$select.length || !$fields.length) {
            return;
        }

        function apply(animate) {
            var show = $select.val() === (showValue || 'I hold');

            if (animate) {
                show ? $fields.slideDown(200) : $fields.slideUp(200);
            } else {
                show ? $fields.show() : $fields.hide();
            }

            $fields.each(function () {
                window.FFC.setRequiredWithin($(this), show);
            });
        }

        $select.on('change', function () {
            apply(true);
        });

        // Applied once on load, so the form opens matching the stored value.
        apply(false);
    }

    /**
     * Brazilian CPF check digits.
     */
    function validateCpf(cpf) {
        cpf = String(cpf).replace(/\D/g, '');
        if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) {
            return false;
        }

        for (var t = 9; t < 11; t++) {
            var d = 0;
            for (var c = 0; c < t; c++) {
                d += parseInt(cpf.charAt(c), 10) * ((t + 1) - c);
            }
            d = ((10 * d) % 11) % 10;
            if (parseInt(cpf.charAt(t), 10) !== d) {
                return false;
            }
        }
        return true;
    }

    /**
     * The message for a value that breaks its format, or '' when it is fine.
     * An empty value is fine here; requiredness is the caller's question.
     *
     * @param {string} value
     * @param {string} format   cpf | email | phone | custom_regex
     * @param {Object} strings  invalidCpf, invalidEmail, invalidPhone, invalidFormat
     * @param {Object} [regex]  {pattern, message} for custom_regex
     * @return {string}
     */
    function formatError(value, format, strings, regex) {
        var S = strings || {};
        var val = String(value || '').trim();

        if (!val) {
            return '';
        }
        if (format === 'cpf') {
            return validateCpf(val) ? '' : (S.invalidCpf || 'Invalid CPF.');
        }
        if (format === 'email') {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val) ? '' : (S.invalidEmail || 'Invalid email.');
        }
        if (format === 'phone') {
            return /^\(?\d{2}\)?\s?\d{4,5}-?\d{4}$/.test(val.replace(/\s+/g, '')) ? '' : (S.invalidPhone || 'Invalid phone number.');
        }
        if (format === 'custom_regex' && regex && regex.pattern) {
            try {
                if (!new RegExp(regex.pattern).test(val)) {
                    return regex.message || S.invalidFormat || 'Invalid format.';
                }
            } catch (e) { /* an invalid pattern checks nothing */ }
        }
        return '';
    }

    window.FFC.Fields = {
        masks: MASKS,
        initMasks: initMasks,
        initDependentSelects: initDependentSelects,
        initDualPost: initDualPost,
        validateCpf: validateCpf,
        formatError: formatError
    };

})(jQuery);
