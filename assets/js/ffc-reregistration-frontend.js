/**
 * Reregistration Frontend
 *
 * Handles the user-facing reregistration form:
 * - Load form via AJAX
 * - Real-time field validation on blur
 * - Input masks (CPF, phone, CEP)
 * - Divisão → Setor cascading dropdowns
 * - Acúmulo de Cargos conditional section
 * - Dependent select custom field type
 * - Save draft / Submit handlers
 *
 * @since 4.11.0
 * @package FreeFormCertificate
 */
(function ($) {
    'use strict';

    var S = (window.ffcReregistration && window.ffcReregistration.strings) || {};

    $(function () {
        initBannerButtons();
    });

    /* ─── Banner: Open Form ────────────────────────────── */

    function initBannerButtons() {
        $(document).on('click', '.ffc-rereg-open-form', function () {
            var id = $(this).data('reregistration-id');
            loadForm(id);
        });
    }

    function loadForm(reregistrationId) {
        var $panel = $('#ffc-rereg-form-panel');

        // Safety: create panel if it doesn't exist in the DOM
        if ($panel.length === 0) {
            $panel = $('<div id="ffc-rereg-form-panel" style="display:none;"></div>');
            var $dashboard = $('#ffc-user-dashboard');
            if ($dashboard.length) {
                $dashboard.prepend($panel);
            } else {
                $('body').append($panel);
            }
        }

        $panel.html('<div class="ffc-loading">' + (S.loading || 'Carregando formulário...') + '</div>').show();

        // Scroll to panel
        $('html, body').animate({ scrollTop: $panel.offset().top - 40 }, 300);

        FFC.request(
            'ffc_get_reregistration_form',
            { reregistration_id: reregistrationId },
            { nonce: ffcReregistration.nonce, ajaxUrl: ffcReregistration.ajaxUrl }
        )
            .then(function (data) {
                $panel.html(data.html);
                initForm($panel);
            })
            .catch(function (err) {
                var msg = (err && err.fromServer && err.message) || S.errorLoading || 'Erro ao carregar formulário.';
                $panel.html('').append($('<div class="ffc-error">').text(msg));
            });
    }

    /* ─── Form Initialization ──────────────────────────── */

    function initForm($container) {
        initMasks($container);
        initBlurValidation($container);
        initDivisaoSetor($container);
        initAcumuloCargos($container);
        initWorkingHours($container);
        initDependentSelects($container);
        initDraft($container);
        initSubmit($container);
        initCancel($container);
    }

    /* ─── Input Masks ──────────────────────────────────── */

    function initMasks($container) {
        $container.find('[data-mask="cpf"]').on('input', function () {
            var v = this.value.replace(/\D/g, '').substring(0, 11);
            if (v.length > 9) {
                v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
            } else if (v.length > 6) {
                v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
            } else if (v.length > 3) {
                v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
            }
            this.value = v;
        });

        $container.find('[data-mask="phone"]').on('input', function () {
            var v = this.value.replace(/\D/g, '').substring(0, 11);
            if (v.length > 6) {
                v = v.replace(/(\d{2})(\d{4,5})(\d{4})/, '($1) $2-$3');
            } else if (v.length > 2) {
                v = v.replace(/(\d{2})(\d{1,5})/, '($1) $2');
            }
            this.value = v;
        });

        $container.find('[data-mask="cep"]').on('input', function () {
            var v = this.value.replace(/\D/g, '').substring(0, 8);
            if (v.length > 5) {
                v = v.replace(/(\d{5})(\d{1,3})/, '$1-$2');
            }
            this.value = v;
        });

        // RF mask: XXX.XXX-X (7 digits)
        $container.find('[data-mask="rf"]').on('input', function () {
            var v = this.value.replace(/\D/g, '').substring(0, 7);
            if (v.length > 6) {
                v = v.replace(/(\d{3})(\d{3})(\d{1})/, '$1.$2-$3');
            } else if (v.length > 3) {
                v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
            }
            this.value = v;
        });

        // Number-only mask
        $container.find('[data-mask="number"]').on('input', function () {
            this.value = this.value.replace(/\D/g, '');
        });

        // CIN mask: XX.XXX.XXX-X
        $container.find('[data-mask="cin"]').on('input', function () {
            var v = this.value.replace(/\D/g, '').substring(0, 9);
            if (v.length > 8) {
                v = v.replace(/(\d{2})(\d{3})(\d{3})(\d{1})/, '$1.$2.$3-$4');
            } else if (v.length > 5) {
                v = v.replace(/(\d{2})(\d{3})(\d{1,3})/, '$1.$2.$3');
            } else if (v.length > 2) {
                v = v.replace(/(\d{2})(\d{1,3})/, '$1.$2');
            }
            this.value = v;
        });
    }

    /* ─── Divisão → Setor Cascading ───────────────────── */

    function initDivisaoSetor($container) {
        var $mapEl = $container.find('#ffc-divisao-setor-map');
        if (!$mapEl.length) return;

        var map;
        try {
            map = JSON.parse($mapEl.text());
        } catch (e) {
            return;
        }

        var $divisao = $container.find('#ffc_rereg_divisao');
        var $setor = $container.find('#ffc_rereg_setor');

        $divisao.on('change', function () {
            var div = $(this).val();
            var currentSetor = $setor.val();
            $setor.empty();

            if (!div || !map[div]) {
                $setor.append('<option value="">' + (S.selectDivisao || 'Selecione Divisão / Local') + '</option>');
                return;
            }

            $setor.append('<option value="">' + (S.selectSetor || 'Selecione') + '</option>');
            $.each(map[div], function (_, setor) {
                var $opt = $('<option>').val(setor).text(setor);
                if (setor === currentSetor) $opt.prop('selected', true);
                $setor.append($opt);
            });
        });
    }

    /* ─── Acúmulo de Cargos Toggle ────────────────────── */

    function initAcumuloCargos($container) {
        var $select = $container.find('#ffc_rereg_acumulo');
        var $fields = $container.find('.ffc-rereg-acumulo-fields');

        $select.on('change', function () {
            if ($(this).val() === (S.acumuloShowValue || 'I hold')) {
                $fields.slideDown(200);
            } else {
                $fields.slideUp(200);
            }
        });
    }

    /* ─── Working Hours (standard fields) ────────────── */

    function initWorkingHours($container) {
        $container.find('.ffc-working-hours').each(function () {
            var $wrap = $(this);
            var targetId = $wrap.data('target');
            var $hidden = $container.find('#' + targetId);

            function syncHidden() {
                var rows = [];
                $wrap.find('tbody tr').each(function () {
                    rows.push({
                        day: parseInt($(this).find('.ffc-wh-day').val(), 10) || 0,
                        entry1: $(this).find('.ffc-wh-entry1').val() || '',
                        exit1: $(this).find('.ffc-wh-exit1').val() || '',
                        entry2: $(this).find('.ffc-wh-entry2').val() || '',
                        exit2: $(this).find('.ffc-wh-exit2').val() || ''
                    });
                });
                $hidden.val(JSON.stringify(rows));
            }

            // Sync on any input change
            $wrap.on('change input', 'select, input', syncHidden);

            // Remove row
            $wrap.on('click', '.ffc-wh-remove', function () {
                $(this).closest('tr').remove();
                syncHidden();
            });

            // Add row
            $wrap.on('click', '.ffc-wh-add', function () {
                var $tbody = $wrap.find('tbody');
                var $row = $('<tr>' +
                    '<td><select class="ffc-wh-day">' +
                    '<option value="0">' + (S.sunday || 'Domingo') + '</option>' +
                    '<option value="1">' + (S.monday || 'Segunda') + '</option>' +
                    '<option value="2">' + (S.tuesday || 'Terça') + '</option>' +
                    '<option value="3">' + (S.wednesday || 'Quarta') + '</option>' +
                    '<option value="4">' + (S.thursday || 'Quinta') + '</option>' +
                    '<option value="5">' + (S.friday || 'Sexta') + '</option>' +
                    '<option value="6">' + (S.saturday || 'Sábado') + '</option>' +
                    '</select></td>' +
                    '<td><input type="time" class="ffc-wh-entry1"></td>' +
                    '<td><input type="time" class="ffc-wh-exit1"></td>' +
                    '<td><input type="time" class="ffc-wh-entry2"></td>' +
                    '<td><input type="time" class="ffc-wh-exit2"></td>' +
                    '<td><button type="button" class="ffc-wh-remove">&times;</button></td>' +
                    '</tr>');
                $tbody.append($row);
                syncHidden();
            });
        });
    }

    /* ─── Dependent Select Custom Fields ──────────────── */

    function initDependentSelects($container) {
        $container.find('.ffc-dependent-select').each(function () {
            var $wrap = $(this);
            var targetId = $wrap.data('target');
            var $hidden = $container.find('#' + targetId);
            var $parent = $wrap.find('.ffc-dep-parent');
            var $child = $wrap.find('.ffc-dep-child');
            var $groupsEl = $wrap.find('.ffc-dep-groups');

            var groups;
            try {
                groups = JSON.parse($groupsEl.text());
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
                $child.empty().append('<option value="">' + (S.select || 'Selecione') + '</option>');

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

    /* ─── Blur Validation ──────────────────────────────── */

    function initBlurValidation($container) {
        $container.on('blur', 'input, textarea, select', function () {
            validateField($(this));
        });
    }

    function validateField($field) {
        var $wrap = $field.closest('.ffc-rereg-field');
        var $error = $wrap.find('.ffc-field-error');
        // `$.trim` was removed in jQuery 4; native String.prototype.trim
        // covers the same case and works across all supported runtimes.
        var val = ($field.val() || '').trim();
        var msg = '';

        // Required check
        if ($field.prop('required') && !val) {
            msg = S.required || 'Este campo é obrigatório.';
        }

        // Format validation
        if (!msg && val) {
            var format = $wrap.data('format') || $field.data('format');
            if (format === 'cpf') {
                if (!validateCpf(val)) {
                    msg = S.invalidCpf || 'CPF inválido.';
                }
            } else if (format === 'email') {
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
                    msg = S.invalidEmail || 'E-mail inválido.';
                }
            } else if (format === 'phone') {
                if (!/^\(?\d{2}\)?\s?\d{4,5}-?\d{4}$/.test(val.replace(/\s+/g, ''))) {
                    msg = S.invalidPhone || 'Telefone inválido.';
                }
            } else if (format === 'custom_regex') {
                var regex = $wrap.data('regex');
                if (regex) {
                    try {
                        if (!new RegExp(regex).test(val)) {
                            msg = $wrap.data('regex-msg') || S.invalidFormat || 'Formato inválido.';
                        }
                    } catch (e) { /* skip invalid regex */ }
                }
            }
        }

        $wrap.toggleClass('has-error', !!msg);
        $error.text(msg);
        return !msg;
    }

    /* ─── CPF Validation ───────────────────────────────── */

    function validateCpf(cpf) {
        cpf = cpf.replace(/\D/g, '');
        if (cpf.length !== 11) return false;
        if (/^(\d)\1{10}$/.test(cpf)) return false;

        for (var t = 9; t < 11; t++) {
            var d = 0;
            for (var c = 0; c < t; c++) {
                d += parseInt(cpf.charAt(c), 10) * ((t + 1) - c);
            }
            d = ((10 * d) % 11) % 10;
            if (parseInt(cpf.charAt(t), 10) !== d) return false;
        }
        return true;
    }

    /* ─── Save Draft ───────────────────────────────────── */

    function initDraft($container) {
        $container.on('click', '.ffc-rereg-draft-btn', function () {
            var $btn = $(this);
            var $status = $container.find('.ffc-rereg-status');
            var id = $container.find('[name="reregistration_id"]').val();

            $btn.prop('disabled', true).text(S.saving || 'Salvando...');
            $status.text('');

            FFC.request(
                'ffc_save_reregistration_draft',
                { reregistration_id: id, fields: getFields($container) },
                { nonce: ffcReregistration.nonce, ajaxUrl: ffcReregistration.ajaxUrl }
            )
                .then(function () {
                    $btn.prop('disabled', false).text(S.saveDraft || 'Salvar Rascunho');
                    $status.text(S.draftSaved || 'Rascunho salvo.').addClass('ffc-status-ok');
                    setTimeout(function () { $status.text('').removeClass('ffc-status-ok'); }, 3000);
                })
                .catch(function (err) {
                    $btn.prop('disabled', false).text(S.saveDraft || 'Salvar Rascunho');
                    var msg = (err && err.fromServer && err.message) || S.errorSaving || 'Erro ao salvar rascunho.';
                    $status.text(msg).addClass('ffc-status-err');
                });
        });
    }

    /* ─── Submit ───────────────────────────────────────── */

    function initSubmit($container) {
        $container.on('submit', '#ffc-rereg-form', function (e) {
            e.preventDefault();

            // Validate all visible required fields
            var valid = true;
            $container.find('input[required]:visible, textarea[required]:visible, select[required]:visible').each(function () {
                if (!validateField($(this))) valid = false;
            });
            // Also validate format fields even if not required
            $container.find('[data-format]:visible').each(function () {
                var $f = $(this).find('input, textarea, select');
                if (!$f.length) $f = $(this);
                if ($f.val()) {
                    if (!validateField($f)) valid = false;
                }
            });

            if (!valid) {
                $container.find('.ffc-rereg-status').text(S.fixErrors || 'Por favor, corrija os erros abaixo.').addClass('ffc-status-err');
                // Scroll to first error
                var $first = $container.find('.has-error:first');
                if ($first.length) {
                    $('html, body').animate({ scrollTop: $first.offset().top - 60 }, 300);
                }
                return;
            }

            var $btn = $container.find('.ffc-rereg-submit-btn');
            var $status = $container.find('.ffc-rereg-status');
            var id = $container.find('[name="reregistration_id"]').val();

            $btn.prop('disabled', true).text(S.submitting || 'Enviando...');
            $status.text('').removeClass('ffc-status-err ffc-status-ok');

            FFC.request(
                'ffc_submit_reregistration',
                { reregistration_id: id, fields: getFields($container) },
                { nonce: ffcReregistration.nonce, ajaxUrl: ffcReregistration.ajaxUrl }
            )
                .then(function (data) {
                    var $notice = $('<div class="ffc-dashboard-notice ffc-notice-info">').append(
                        $('<p>').text((data && data.message) || S.submitted || 'Recadastramento enviado com sucesso!')
                    );
                    $container.find('#ffc-rereg-form').replaceWith($notice);
                    $('.ffc-rereg-banner[data-reregistration-id="' + id + '"]').slideUp();
                })
                .catch(function (err) {
                    $btn.prop('disabled', false).text(S.submit || 'Enviar');
                    if (err && err.fromServer) {
                        if (err.data && err.data.errors) {
                            showServerErrors($container, err.data.errors);
                        }
                        $status.text(err.message || S.errorSubmitting || 'Erro ao enviar.').addClass('ffc-status-err');
                    } else {
                        $status.text(S.errorSubmitting || 'Erro ao enviar.').addClass('ffc-status-err');
                    }
                });
        });
    }

    /* ─── Cancel ───────────────────────────────────────── */

    function initCancel($container) {
        $container.on('click', '.ffc-rereg-cancel-btn', function () {
            $('#ffc-rereg-form-panel').slideUp(200, function () {
                $(this).empty();
            });
        });
    }

    /* ─── Helpers ──────────────────────────────────────── */

    function getFields($container) {
        var data = {};
        // jQuery 4's selector parser rejects an unescaped `[` inside an
        // attribute-value literal, so prefix-match on the bare "fields"
        // token — every reregistration field is named `fields[…]`.
        $container.find('[name^="fields"]').each(function () {
            var match = this.name.match(/fields\[([^\]]+)\]/);
            if (!match) return;
            var key = match[1];
            if (this.type === 'checkbox') {
                data[key] = this.checked ? '1' : '';
            } else if (this.type === 'radio') {
                if (this.checked) {
                    data[key] = $(this).val();
                }
            } else {
                data[key] = $(this).val();
            }
        });
        return data;
    }

    function showServerErrors($container, errors) {
        // Clear previous errors
        $container.find('.has-error').removeClass('has-error');
        $container.find('.ffc-field-error').text('');

        $.each(errors, function (name, msg) {
            var $input = $container.find('[name="' + name + '"]');
            var $wrap = $input.closest('.ffc-rereg-field');
            $wrap.addClass('has-error');
            $wrap.find('.ffc-field-error').text(msg);
        });

        // Scroll to first error
        var $first = $container.find('.has-error:first');
        if ($first.length) {
            $('html, body').animate({ scrollTop: $first.offset().top - 60 }, 300);
        }
    }

})(jQuery);
