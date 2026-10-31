/**
 * Field behaviours shared by every screen that renders custom fields.
 *
 * The reregistration form on the dashboard and the "FFC" section of the
 * wp-admin user screen render the same fields from the same definitions, so
 * they share one implementation of what those fields do in the browser:
 * - input masks (`data-mask`: cpf, cpf_rf, phone, cep, rf, number, cin); the
 *   public forms' CPF/RF mask formats through these too, so every screen
 *   types a document the same way (#1920)
 * - dependent selects (`.ffc-dependent-select` → a hidden JSON input)
 * - the dual-post toggle (the accumulation fields follow `acumulo_cargos`)
 * - format checks (`cpf`, `email`, `phone`, `custom_regex`) and the CPF and RF
 *   validators every screen shares
 * - the address lookup by CEP (#2234), when Settings → General turns it on
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
        // A field that takes either: up to seven digits read as an RF,
        // more as a CPF -- the length is what tells them apart.
        cpf_rf: function (value) {
            var v = value.replace(/\D/g, '').substring(0, 11);
            return v.length <= 7 ? MASKS.rf(v) : MASKS.cpf(v);
        },
        number: function (value) {
            return value.replace(/\D/g, '');
        },
        // CIN: XX.XXX.XXX-X.
        cin: function (value) {
            // The check digit of an RG may be an X; only the ninth place takes it.
            var t = value.toUpperCase().replace(/[^0-9X]/g, '');
            var v = t.replace(/X/g, '').substring(0, 9);
            if (v.length === 8 && /^\d{8}X/.test(t)) {
                v += 'X';
            }
            if (v.length > 8) {
                return v.replace(/(\d{2})(\d{3})(\d{3})([\dX])/, '$1.$2.$3-$4');
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

    /** The masks whose shape a stored value can always take. */
    var SHAPE_ON_LOAD = ['cpf', 'rf', 'cpf_rf', 'cin', 'cep'];

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
            // A document that arrived from the server or an import is stored
            // bare as often as masked, so it is shaped on load too, not only
            // as the participant types (#2227). A phone is left as stored:
            // an international number would lose its country code.
            if (this.value && SHAPE_ON_LOAD.indexOf($(this).attr('data-mask')) !== -1) {
                this.value = format(this.value);
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
     * RF: seven digits and, when the site enforces it, a matching check
     * digit -- the server's own rule (`DocumentFormatter::validate_rf()`),
     * whose switch arrives as `ffcCoreConfig.rfCheckDigit`.
     */
    function validateRf(rf) {
        rf = String(rf).replace(/\D/g, '');
        if (!/^\d{7}$/.test(rf)) {
            return false;
        }
        if (!(window.ffcCoreConfig && window.ffcCoreConfig.rfCheckDigit)) {
            return true;
        }

        var weights = [7, 6, 5, 4, 3, 2];
        var sum = 0;
        for (var i = 0; i < 6; i++) {
            sum += parseInt(rf.charAt(i), 10) * weights[i];
        }
        return (11 - (sum % 11)) % 10 === parseInt(rf.charAt(6), 10);
    }

    /**
     * The UF whose range holds a CEP, or '' when none does -- the server's
     * `CepRule::state_of()`, over the table it sends as
     * `ffcCoreConfig.cepRanges`.
     */
    function cepState(digits) {
        var ranges = (window.ffcCoreConfig && window.ffcCoreConfig.cepRanges) || {};
        var prefix = parseInt(digits.substring(0, 5), 10);
        var found = '';
        Object.keys(ranges).forEach(function (uf) {
            ranges[uf].forEach(function (range) {
                if (!found && prefix >= range[0] && prefix <= range[1]) {
                    found = uf;
                }
            });
        });
        return found;
    }

    /**
     * Why a CEP is refused -- 'format', 'repeated', 'range' or 'uf' -- or ''
     * when it passes: the server's `CepRule::check()` (#2233).
     *
     * @param {string} cep
     * @param {string} [uf] The UF chosen in the same form; '' skips that check.
     * @return {string}
     */
    function cepProblem(cep, uf) {
        var val = String(cep || '').trim();
        if (!/^\d{5}-?\d{3}$/.test(val)) {
            return 'format';
        }
        var digits = val.replace('-', '');
        if (/^(\d)\1{7}$/.test(digits)) {
            return 'repeated';
        }
        var state = cepState(digits);
        if (!state) {
            return 'range';
        }
        var ranges = (window.ffcCoreConfig && window.ffcCoreConfig.cepRanges) || {};
        uf = String(uf || '').trim().toUpperCase();
        if (uf && ranges[uf] && uf !== state) {
            return 'uf';
        }
        return '';
    }

    /**
     * The message for a value that breaks its format, or '' when it is fine.
     * An empty value is fine here; requiredness is the caller's question.
     *
     * @param {string} value
     * @param {string} format   cpf | rf | cep | email | phone | custom_regex
     * @param {Object} strings  invalidCpf, invalidRf, invalidCep, cepRepeated, cepWrongUf,
     *                          invalidEmail, invalidPhone, invalidFormat
     * @param {Object} [regex]  {pattern, message} for custom_regex
     * @param {Object} [context] {uf} -- the form's UF, for the CEP check
     * @return {string}
     */
    function formatError(value, format, strings, regex, context) {
        var S = strings || {};
        var val = String(value || '').trim();

        if (!val) {
            return '';
        }
        if (format === 'cpf') {
            return validateCpf(val) ? '' : (S.invalidCpf || 'Invalid CPF.');
        }
        if (format === 'rf') {
            return validateRf(val) ? '' : (S.invalidRf || 'Invalid RF.');
        }
        if (format === 'cep') {
            var uf = (context && context.uf) || '';
            var problem = cepProblem(val, uf);
            if (problem === 'repeated') {
                return S.cepRepeated || 'Invalid CEP: all digits are the same.';
            }
            if (problem === 'uf') {
                return (S.cepWrongUf || 'This CEP does not belong to %s. Check the CEP or the UF.').replace('%s', String(uf).trim().toUpperCase());
            }
            return problem ? (S.invalidCep || 'Invalid CEP.') : '';
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

    /**
     * Address fields the lookup fills, by the key of its answer. The number
     * and the complement are never touched: the CEP does not know them.
     */
    var CEP_TARGETS = {
        street: 'endereco',
        neighborhood: 'bairro',
        city: 'cidade',
        uf: 'uf'
    };

    /** The shortest a lookup shows its progress: friction on purpose (#2234). */
    var CEP_MIN_MS = 3000;

    /** The longest the browser waits for the server's answer. */
    var CEP_ABORT_MS = 6000;

    /** How long a filled field stays highlighted. */
    var CEP_HIGHLIGHT_MS = 1500;

    function fieldControl($container, key) {
        return $container.find('[data-field-key="' + key + '"]')
            .find('input, select, textarea').not('[type="hidden"]').first();
    }

    /** Address fields read-only while the lookup runs; a select cannot be. */
    function lockAddress($controls, locked) {
        $controls.each(function () {
            var $c = $(this);
            if (locked) {
                if (this.tagName === 'SELECT') {
                    $c.attr('data-ffc-cep-tabindex', $c.attr('tabindex') || '')
                        .attr({ tabindex: '-1', 'aria-disabled': 'true' });
                } else if (!this.readOnly) {
                    $c.prop('readOnly', true).attr('data-ffc-cep-locked', '1');
                }
                $c.addClass('ffc-cep-locked');
                return;
            }
            if (this.tagName === 'SELECT' && $c.is('[data-ffc-cep-tabindex]')) {
                var previous = $c.attr('data-ffc-cep-tabindex');
                if (previous) {
                    $c.attr('tabindex', previous);
                } else {
                    $c.removeAttr('tabindex');
                }
                $c.removeAttr('data-ffc-cep-tabindex aria-disabled');
            } else if ($c.attr('data-ffc-cep-locked')) {
                $c.prop('readOnly', false).removeAttr('data-ffc-cep-locked');
            }
            $c.removeClass('ffc-cep-locked');
        });
    }

    /**
     * Writes the non-empty parts of an address into the form. A value the
     * participant typed is replaced only by a value the lookup has; a UF is
     * set only when it is one of the select's options.
     *
     * @return {{filled: Array, street: boolean}}
     */
    function applyAddress($container, address) {
        var filled = [];
        Object.keys(CEP_TARGETS).forEach(function (part) {
            var value = String((address && address[part]) || '').trim();
            var $control = fieldControl($container, CEP_TARGETS[part]);
            if (!value || !$control.length) {
                return;
            }
            if ($control.is('select')) {
                var has = $control.find('option').filter(function () {
                    return this.value === value;
                }).length;
                if (!has) {
                    return;
                }
            }
            $control.val(value).trigger('change');
            filled.push($control[0]);
        });
        return {
            filled: filled,
            street: !!String((address && address.street) || '').trim()
        };
    }

    /**
     * Fills the address from the CEP (#2234): on eight digits that pass the
     * offline rule, the server asks the configured provider, the address
     * fields wait read-only for at least three seconds, and what came back
     * fills the form. A CEP the provider does not know releases at once.
     * Nothing here blocks the submit.
     *
     * Off when `ffcCoreConfig.cepLookup` is null (the setting is off, or no
     * one is logged in) or the form has no CEP field.
     *
     * @param {jQuery} $container
     */
    function initCepLookup($container) {
        var config = window.ffcCoreConfig && window.ffcCoreConfig.cepLookup;
        var $cep = fieldControl($container, 'cep');
        if (!config || !config.url || !$cep.length) {
            return;
        }
        var S = config.strings || {};
        var $address = $();
        Object.keys(CEP_TARGETS).forEach(function (part) {
            $address = $address.add(fieldControl($container, CEP_TARGETS[part]));
        });

        var $progress = $('<span class="ffc-cep-progress" aria-hidden="true" hidden><span class="ffc-cep-progress-bar"></span></span>');
        var $status = $('<span class="ffc-cep-status" role="status" aria-live="polite"></span>');
        $cep.after($progress, $status);

        var token = 0;
        var looked = String($cep.val() || '').replace('-', '');

        function finish(ticket, text) {
            if (ticket !== token) {
                return;
            }
            lockAddress($address, false);
            $progress.attr('hidden', true).removeClass('is-running is-done');
            $cep.removeAttr('aria-busy');
            $status.text(text || '');
        }

        function found(ticket, address) {
            if (ticket !== token) {
                return;
            }
            $progress.addClass('is-done');
            var result = applyAddress($container, address);
            finish(ticket, result.street
                ? (S.filled || 'Address filled from the CEP. Check the number.')
                : (S.cityOnly || 'City found. Fill in the street and the neighbourhood.'));
            $(result.filled).addClass('ffc-cep-filled');
            setTimeout(function () {
                $(result.filled).removeClass('ffc-cep-filled');
            }, CEP_HIGHLIGHT_MS);
            var $number = fieldControl($container, 'endereco_numero');
            if ($number.length) {
                $number.trigger('focus');
            }
        }

        function search(digits) {
            var ticket = ++token;
            var started = Date.now();
            var notFound = S.notFound || 'CEP not found. Correct the CEP or fill in the address manually.';

            lockAddress($address, true);
            $cep.attr('aria-busy', 'true');
            $status.text(S.searching || 'Looking up the address…');
            $progress.removeAttr('hidden').removeClass('is-done');
            // Restart the fill from zero for a second lookup.
            $progress.removeClass('is-running');
            void $progress[0].offsetWidth;
            $progress.addClass('is-running');

            function later(fn) {
                setTimeout(fn, Math.max(0, CEP_MIN_MS - (Date.now() - started)));
            }

            $.ajax({
                url: config.url,
                method: 'POST',
                dataType: 'json',
                timeout: CEP_ABORT_MS,
                data: { action: config.action, nonce: config.nonce, cep: digits }
            }).done(function (response) {
                var answer = response && response.success ? response.data : null;
                if (answer && answer.status === 'found') {
                    later(function () { found(ticket, answer.address); });
                } else if (answer && answer.status === 'not_found') {
                    // The one early release: there is nothing to wait for.
                    finish(ticket, notFound);
                } else {
                    later(function () { finish(ticket, notFound); });
                }
            }).fail(function () {
                later(function () { finish(ticket, notFound); });
            });
        }

        $cep.on('input change', function () {
            var value = String(this.value || '');
            var digits = value.replace('-', '');
            if (digits === looked) {
                return;
            }
            looked = digits;
            if (digits.length !== 8 || cepProblem(value)) {
                // A changed CEP abandons the lookup in flight.
                if (token) {
                    finish(token, '');
                    token++;
                }
                return;
            }
            search(digits);
        });
    }

    window.FFC.Fields = {
        masks: MASKS,
        initMasks: initMasks,
        initCepLookup: initCepLookup,
        initDependentSelects: initDependentSelects,
        initDualPost: initDualPost,
        validateCpf: validateCpf,
        validateRf: validateRf,
        cepProblem: cepProblem,
        formatError: formatError
    };

})(jQuery);
