/**
 * Reregistration Frontend
 *
 * Handles the user-facing reregistration form:
 * - Load form via AJAX
 * - Real-time field validation on blur
 * - Input masks (CPF, phone)
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
        initFichaButtons();
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
        $panel.html('<div class="ffc-loading">' + (S.loading || 'Loading form...') + '</div>').show();

        // Scroll to panel
        $('html, body').animate({ scrollTop: $panel.offset().top - 40 }, 300);

        $.post(ffcReregistration.ajaxUrl, {
            action: 'ffc_get_reregistration_form',
            nonce: ffcReregistration.nonce,
            reregistration_id: reregistrationId
        }, function (res) {
            if (res.success) {
                $panel.html(res.data.html);
                initForm($panel);
            } else {
                $panel.html('<div class="ffc-error">' + (res.data && res.data.message ? res.data.message : S.errorLoading || 'Error loading form.') + '</div>');
            }
        }).fail(function () {
            $panel.html('<div class="ffc-error">' + (S.errorLoading || 'Error loading form.') + '</div>');
        });
    }

    /* ─── Form Initialization ──────────────────────────── */

    function initForm($container) {
        initMasks($container);
        initBlurValidation($container);
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
        var val = $.trim($field.val());
        var msg = '';

        // Required check
        if ($field.prop('required') && !val) {
            msg = S.required || 'This field is required.';
        }

        // Format validation
        if (!msg && val) {
            var format = $wrap.data('format');
            if (format === 'cpf') {
                if (!validateCpf(val)) {
                    msg = S.invalidCpf || 'Invalid CPF.';
                }
            } else if (format === 'email') {
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
                    msg = S.invalidEmail || 'Invalid email.';
                }
            } else if (format === 'phone') {
                if (!/^\(?\d{2}\)?\s?\d{4,5}-?\d{4}$/.test(val.replace(/\s+/g, ''))) {
                    msg = S.invalidPhone || 'Invalid phone number.';
                }
            } else if (format === 'custom_regex') {
                var regex = $wrap.data('regex');
                if (regex) {
                    try {
                        if (!new RegExp(regex).test(val)) {
                            msg = $wrap.data('regex-msg') || S.invalidFormat || 'Invalid format.';
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

            $btn.prop('disabled', true).text(S.saving || 'Saving...');
            $status.text('');

            $.post(ffcReregistration.ajaxUrl, {
                action: 'ffc_save_reregistration_draft',
                nonce: ffcReregistration.nonce,
                reregistration_id: id,
                standard_fields: getStandardFields($container),
                custom_fields: getCustomFields($container)
            }, function (res) {
                $btn.prop('disabled', false).text(S.saveDraft || 'Save Draft');
                if (res.success) {
                    $status.text(S.draftSaved || 'Draft saved.').addClass('ffc-status-ok');
                    setTimeout(function () { $status.text('').removeClass('ffc-status-ok'); }, 3000);
                } else {
                    $status.text(res.data && res.data.message ? res.data.message : S.errorSaving || 'Error saving draft.').addClass('ffc-status-err');
                }
            }).fail(function () {
                $btn.prop('disabled', false).text(S.saveDraft || 'Save Draft');
                $status.text(S.errorSaving || 'Error saving draft.').addClass('ffc-status-err');
            });
        });
    }

    /* ─── Submit ───────────────────────────────────────── */

    function initSubmit($container) {
        $container.on('submit', '#ffc-rereg-form', function (e) {
            e.preventDefault();

            // Validate all fields
            var valid = true;
            $container.find('input[required], textarea[required], select[required]').each(function () {
                if (!validateField($(this))) valid = false;
            });
            // Also validate format fields even if not required
            $container.find('[data-format]').each(function () {
                var $f = $(this).find('input, textarea, select');
                if ($f.val()) {
                    if (!validateField($f)) valid = false;
                }
            });

            if (!valid) {
                $container.find('.ffc-rereg-status').text(S.fixErrors || 'Please fix the errors below.').addClass('ffc-status-err');
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

            $btn.prop('disabled', true).text(S.submitting || 'Submitting...');
            $status.text('').removeClass('ffc-status-err ffc-status-ok');

            $.post(ffcReregistration.ajaxUrl, {
                action: 'ffc_submit_reregistration',
                nonce: ffcReregistration.nonce,
                reregistration_id: id,
                standard_fields: getStandardFields($container),
                custom_fields: getCustomFields($container)
            }, function (res) {
                if (res.success) {
                    $container.find('#ffc-rereg-form').replaceWith(
                        '<div class="ffc-dashboard-notice ffc-notice-info"><p>' +
                        (res.data.message || S.submitted || 'Reregistration submitted successfully!') +
                        '</p></div>'
                    );
                    // Hide the banner
                    $('.ffc-rereg-banner[data-reregistration-id="' + id + '"]').slideUp();
                } else {
                    $btn.prop('disabled', false).text(S.submit || 'Submit');
                    if (res.data && res.data.errors) {
                        showServerErrors($container, res.data.errors);
                    }
                    $status.text(res.data && res.data.message ? res.data.message : S.errorSubmitting || 'Error submitting.').addClass('ffc-status-err');
                }
            }).fail(function () {
                $btn.prop('disabled', false).text(S.submit || 'Submit');
                $status.text(S.errorSubmitting || 'Error submitting.').addClass('ffc-status-err');
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

    /* ─── Ficha Download ──────────────────────────────── */

    function initFichaButtons() {
        $(document).on('click', '.ffc-rereg-ficha-btn', function () {
            var $btn = $(this);
            var subId = $btn.data('submission-id');
            var originalText = $btn.text();

            if (!subId) return;

            $btn.prop('disabled', true).text(S.generatingPdf || 'Generating PDF...');

            $.post(ffcReregistration.ajaxUrl, {
                action: 'ffc_download_ficha',
                nonce: ffcReregistration.nonce,
                submission_id: subId
            }, function (res) {
                $btn.prop('disabled', false).text(S.downloadFicha || originalText);

                if (res.success && res.data.pdf_data) {
                    if (typeof window.ffcGeneratePDF === 'function') {
                        window.ffcGeneratePDF(res.data.pdf_data, res.data.pdf_data.filename || 'ficha.pdf');
                    } else {
                        alert(S.errorFicha || 'PDF generator not available.');
                    }
                } else {
                    alert(res.data && res.data.message ? res.data.message : S.errorFicha || 'Error generating ficha.');
                }
            }).fail(function () {
                $btn.prop('disabled', false).text(S.downloadFicha || originalText);
                alert(S.errorFicha || 'Error generating ficha.');
            });
        });
    }

    /* ─── Helpers ──────────────────────────────────────── */

    function getStandardFields($container) {
        var data = {};
        $container.find('[name^="standard_fields["]').each(function () {
            var key = this.name.match(/\[(\w+)\]/)[1];
            data[key] = $(this).val();
        });
        return data;
    }

    function getCustomFields($container) {
        var data = {};
        $container.find('[name^="custom_fields["]').each(function () {
            var key = this.name.match(/\[(field_\d+)\]/)[1];
            if (this.type === 'checkbox') {
                data[key] = this.checked ? '1' : '';
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
