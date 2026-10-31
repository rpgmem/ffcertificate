/**
 * FFC Frontend Helpers Module
 * v3.0.0 - Modular Architecture
 * 
 * Consolidated frontend utilities:
 * - Input masks (CPF/RF, Auth Code, Ticket); the CPF/RF shape and the CPF
 *   and RF validators come from ffc-field-behaviours.js (#1920)
 * - Field validation (CPF, RF, Email)
 * - UI feedback (Error, Success messages)
 * - Captcha refresh
 * - Rate limiting with countdown
 * 
 * @since 3.0.0
 */

(function($, window) {
    'use strict';

    /**
     * Warn that a public function is deprecated and leaves in 6.45.0 (#2089).
     *
     * @param {string} name - Qualified function name.
     */
    function warnDeprecated(name) {
        if (window.console && typeof window.console.warn === 'function') {
            window.console.warn('FFC: ' + name + '() is deprecated since 6.43.0 and will be removed in 6.45.0.');
        }
    }
    
    // Initialize FFC namespace
    window.FFC = window.FFC || {};
    
    /**
     * ==========================================================================
     * VALIDATION MODULE
     * ==========================================================================
     */
    var Validation = {
        
        // The validators live in ffc-field-behaviours.js, shared with the
        // dashboard and the admin (#1920); these names stay for callers.
        validateCPF: function(cpf) {
            return window.FFC.Fields.validateCpf(cpf);
        },

        validateRF: function(rf) {
            return window.FFC.Fields.validateRf(rf);
        },

        /**
         * Validate email format
         * 
         * @param {string} email - Email address
         * @return {boolean} - True if valid format
         *
         * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
         * @removal 6.45.0
         */
        validateEmail: function(email) {
            warnDeprecated('FFC.Frontend.Validation.validateEmail');
            var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }
    };
    
    /**
     * ==========================================================================
     * MASKS MODULE
     * ==========================================================================
     */
    var Masks = {
        
        /**
         * Apply CPF/RF mask to input fields WITH VALIDATION
         * 
         * Formats based on length:
         * - 7 digits: XXX.XXX-X (RF)
         * - 11 digits: XXX.XXX.XXX-XX (CPF)
         * 
         * Validates on blur and shows visual feedback
         * 
         * @param {jQuery} $inputs - Input elements to apply mask (optional)
         */
        applyCpfRf: function($inputs) {
            // If no inputs provided, find all CPF/RF inputs
            if (!$inputs || $inputs.length === 0) {
                $inputs = $('input[name="cpf_rf"], input[name="cpf"], input[id*="cpf"]');
            }
            
            if ($inputs.length === 0) {
                return;
            }

            // console.log('[FFC Masks] Applying CPF/RF mask with validation to', $inputs.length, 'field(s)');

$inputs.each(function() {
                var $input = $(this);
                
                // Remove existing handlers to avoid duplicates
                $input.off('input.cpfrf blur.cpfrf paste.cpfrf');
                
                // Apply mask on input
                $input.on('input.cpfrf', function() {
                    // The shared CPF/RF shape (#1920): seven digits or fewer
                    // read as an RF, more as a CPF.
                    var masked = window.FFC.Fields.masks.cpf_rf($(this).val());
                    
                    $(this).val(masked);
                    
                    // A new value withdraws the verdict on the old one.
                    window.FFC.clearFieldMessage(this);
                    if (this.setCustomValidity) {
                        this.setCustomValidity('');
                    }
                });
                
                // Validate on blur (when user leaves field)
                $input.on('blur.cpfrf', function() {
                    var value = $(this).val().replace(/\D/g, '');
                    
                    // Skip if empty (not required check)
                    if (value.length === 0) {
                        window.FFC.clearFieldMessage(this);
                        if (this.setCustomValidity) {
                            this.setCustomValidity('');
                        }
                        return;
                    }
                    
                    var isValid = false;
                    var errorMsg = '';

                    // Get localized strings. Each shortcode-specific page
                    // localizes its own strings object — read from whichever
                    // is present. Order: shortcode-specific objects first
                    // (richer context), `ffc_ajax` as a sane fallback for
                    // pages that load `ffc-frontend-js` (the main form
                    // submission flow), then hardcoded English at the end
                    // (#243 Sprint 4 — pre-tech-debt cleanup; the proper
                    // fix is a shared `ffc_common_strings` object localized
                    // on every page that loads this file).
                    var strings = {};
                    if (typeof ffc_csv_download !== 'undefined' && ffc_csv_download.strings) {
                        strings = ffc_csv_download.strings;
                    } else if (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) {
                        strings = ffc_ajax.strings;
                    }

                    if (value.length === 7) {
                        // RF validation
                        isValid = Validation.validateRF(value);
                        errorMsg = strings.rfInvalid || 'Invalid RF';
                    } else if (value.length === 11) {
                        // CPF validation
                        isValid = Validation.validateCPF(value);
                        errorMsg = strings.cpfInvalid || 'Invalid CPF';
                    } else {
                        errorMsg = strings.enterValidCpfRf || 'Enter a valid CPF (11 digits) or RF (7 digits)';
                    }
                    
                    // The browser's own validation carries the verdict, so an
                    // invalid CPF/RF blocks the submit instead of reaching the
                    // server first (#1734).
                    if (this.setCustomValidity) {
                        this.setCustomValidity(isValid ? '' : errorMsg);
                    }

                    // The message and the state are the field-message
                    // standard (#1844): its id is appended to the hint the
                    // field already names, never put in its place. It stays
                    // until the value changes, as long as the submit is blocked.
                    if (isValid) {
                        window.FFC.fieldMessage(this, 'valid');
                    } else {
                        window.FFC.fieldMessage(this, 'error', errorMsg);
                    }
                });

                // Apply on paste
                $input.on('paste.cpfrf', function() {
                    var $this = $(this);
                    setTimeout(function() {
                        $this.trigger('input');
                    }, 10);
                });
                
                // Apply to initial value if exists
                if ($input.val()) {
                    $input.trigger('input');
                }
            });
            
            // The look of the states and of the message is the
            // field-message standard in ffc-common.css (#1844).
        },
        
        /**
         * Apply auth code mask to input fields
         * Supports two formats:
         *   - With prefix:    P-XXXX-XXXX-XXXX  (P = C, R, or A)
         *   - Without prefix: XXXX-XXXX-XXXX    (backwards compatible)
         *
         * @param {jQuery} $inputs - Input elements to apply mask (optional)
         */
        applyAuthCode: function($inputs) {
            // If no inputs provided, find all auth code inputs
            if (!$inputs || $inputs.length === 0) {
                $inputs = $('input[name="ffc_auth_code"], .ffc-verify-input, .ffc-manual-auth-code');
            }

            if ($inputs.length === 0) {
                return;
            }

            var validPrefixes = ['C', 'R', 'A'];

            $inputs.each(function() {
                var $input = $(this);

                // Update maxlength to accommodate prefix format (P-XXXX-XXXX-XXXX = 16 chars)
                if (!$input.attr('maxlength') || parseInt($input.attr('maxlength'), 10) < 16) {
                    $input.attr('maxlength', 16);
                }

                // Remove existing handlers to avoid duplicates
                $input.off('input.authcode paste.authcode');

                // Apply mask on input
                $input.on('input.authcode', function() {
                    // Remove all except A-Z and 0-9, convert to uppercase
                    var value = $(this).val().toUpperCase().replace(/[^A-Z0-9]/g, '');

                    // Detect prefix: first char is C/R/A and total length > 12
                    var hasPrefix = false;
                    var prefix = '';
                    if (value.length > 0 && validPrefixes.indexOf(value[0]) !== -1 && value.length > 12) {
                        hasPrefix = true;
                        prefix = value[0];
                        value = value.substring(1);
                    }

                    // Limit code portion to 12 characters
                    if (value.length > 12) {
                        value = value.substring(0, 12);
                    }

                    // Apply mask: XXXX-XXXX-XXXX
                    var masked = '';
                    if (value.length <= 4) {
                        masked = value;
                    } else if (value.length <= 8) {
                        masked = value.substring(0, 4) + '-' + value.substring(4);
                    } else {
                        masked = value.substring(0, 4) + '-' + value.substring(4, 8) + '-' + value.substring(8);
                    }

                    // Prepend prefix if detected
                    if (hasPrefix) {
                        masked = prefix + '-' + masked;
                    }

                    $(this).val(masked);
                });

                // Apply on paste
                $input.on('paste.authcode', function() {
                    var $this = $(this);
                    setTimeout(function() {
                        $this.trigger('input');
                    }, 10);
                });

                // Apply to initial value if exists
                if ($input.val()) {
                    $input.trigger('input');
                }
            });
        },
        
        /**
         * Apply ticket code mask to input fields
         * Format: XXXX-XXXX (8 alphanumeric characters)
         * 
         * @param {jQuery} $inputs - Input elements to apply mask (optional)
         */
        applyTicket: function($inputs) {
            // If no inputs provided, find all ticket inputs
            if (!$inputs || $inputs.length === 0) {
                $inputs = $('input[name="ffc_ticket"], .ffc-ticket-input, #ffc_ticket');
            }
            
            if ($inputs.length === 0) {
                return;
            }

            // console.log('[FFC Masks] Applying ticket mask to', $inputs.length, 'field(s)');

$inputs.each(function() {
                var $input = $(this);
                
                // Remove existing handlers to avoid duplicates
                $input.off('input.ticket paste.ticket');
                
                // Apply mask on input
                $input.on('input.ticket', function() {
                    // Remove all except A-Z and 0-9, convert to uppercase
                    var value = $(this).val().toUpperCase().replace(/[^A-Z0-9]/g, '');
                    
                    // Limit to 8 characters
                    if (value.length > 8) {
                        value = value.substring(0, 8);
                    }
                    
                    // Apply mask: XXXX-XXXX
                    var masked = '';
                    if (value.length <= 4) {
                        masked = value;
                    } else {
                        masked = value.substring(0, 4) + '-' + value.substring(4);
                    }
                    
                    $(this).val(masked);
                });
                
                // Apply on paste
                $input.on('paste.ticket', function() {
                    var $this = $(this);
                    setTimeout(function() {
                        $this.trigger('input');
                    }, 10);
                });
                
                // Apply to initial value if exists
                if ($input.val()) {
                    $input.trigger('input');
                }
            });
        }
    };
    
    /**
     * ==========================================================================
     * UI MODULE
     * ==========================================================================
     */
    var UI = {

        /**
         * Show error message in form
         *
         * @param {jQuery} $form - Form element
         * @param {string} message - Error message to display
         */
        showFormError: function($form, message) {
            // Remove existing error messages
            $form.find('[data-ffc-notice="form-error"]').remove();

            var error = window.FFC.notice('danger', 'error', message, '', { role: 'alert', hook: 'form-error' });

            // Insert at top of form
            $form.prepend(error);

            // Scroll to error (smooth)
            $('html, body').animate({
                scrollTop: $(error).offset().top - 100
            }, 300);

            // Auto-remove after 10 seconds
            setTimeout(function() {
                $(error).fadeOut(300, function() {
                    $(this).remove();
                });
            }, 10000);
        },

        /**
         * Show success message in form
         * 
         * @param {jQuery} $form - Form element
         * @param {string} html - Success HTML content
         */
        showFormSuccess: function($form, html) {
            // Remove existing messages
            $form.find('[data-ffc-notice="form-error"], [data-ffc-notice="form-success"]').remove();

            // If html provided, use it directly
            if (html && html.trim().length > 0) {
                $form.html(html);
                return;
            }

            // Otherwise show generic success
            var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};
            $form.html(window.FFC.notice(
                'success',
                'success',
                strings.submissionSuccessful || 'Your submission was successful.',
                strings.success || 'Success!',
                { role: 'status', hook: 'form-success' }
            ));
        },

        /**
         * Hand the visitor a fresh challenge after the server refused one.
         *
         * `verify()` SPENDS the challenge, so every error path answers through
         * `SecurityService::with_fresh_challenge()` and sets `refresh_captcha`
         * — including "document not found", which is not a security failure at
         * all. Whatever this does not do, the visitor is left holding a token
         * the server has already burned.
         *
         * **It dispatches on `provider`, and that is the whole fix (#1305).**
         * This used to take `(newLabel, newHash)` — the math shape — so under
         * ALTCHA it found none of its selectors, received `undefined` for both
         * arguments, and did nothing while the widget stayed green with a spent
         * token. The next submission was then refused with "This verification
         * was already used", and ticking the box again did nothing because
         * `auto` defaults to `off` and the widget considered itself solved.
         *
         * The rule matches `applyChallenge()` in `ffc-dynamic-fragments.js`,
         * deliberately: an unrecognised provider is left alone rather than
         * half-applied. That client is the page-load half and correctly SKIPS
         * ALTCHA — there the widget is fresh and unsolved, and its challenge
         * lives behind a URL rather than in the cached HTML. Only this error
         * path has something to undo.
         *
         * `CompositeCaptcha` delegates its payload to the ALTCHA half, so the
         * `both` mode arrives here as `altcha`. There is no third branch to
         * add.
         *
         * **Everything is scoped to `$form` and matched by NAME, and that is
         * load-bearing (#1056).** `ffc-calendar-frontend.js` carried its own
         * copy of this until #1305 folded it back here, and that copy existed
         * because an earlier page-global version rewrote the question in every
         * captcha row on the page while updating only the *first* hidden token
         * — so with two forms on one page, which this plugin supports on
         * purpose, the second showed a new question beside the old token and
         * answering what was on screen failed with "the math answer is
         * incorrect": true, and useless. A rewrite that widens these selectors
         * past `$form` brings that back.
         *
         * @param {jQuery} $form   Form element.
         * @param {Object} payload Challenge payload from the server; names
         *                         itself in `provider`.
         */
        refreshCaptcha: function($form, payload) {
            if (!$form || !$form.length || !payload || !payload.provider) {
                return;
            }

            if (payload.provider === 'altcha') {
                UI.resetAltchaWidget($form);
                return;
            }

            if (payload.provider !== 'math') {
                return;
            }

            // Find captcha elements
            var $captchaLabelText = $form.find('.ffc-captcha-label-text').first();
            var $captchaInput = $form.find('input[name="ffc_captcha_ans"]');
            var $captchaHash = $form.find('input[name="ffc_captcha_hash"]');

            // Update label text only (required span stays intact)
            if ($captchaLabelText.length && payload.new_label) {
                $captchaLabelText.text(payload.new_label);
            }

            // Update hash
            if ($captchaHash.length && payload.new_hash) {
                $captchaHash.val(payload.new_hash);
            }

            // Clear and focus input
            if ($captchaInput.length) {
                // The cleared, focused field is the whole cue. A yellow
                // flash used to be painted inline here and never showed:
                // `.ffc-captcha-row .ffc-input` sets its ground with
                // `!important`, which beats an inline declaration (#1840).
                $captchaInput.val('').focus();
            }
        },

        /**
         * Put an `<altcha-widget>` back to its unsolved state.
         *
         * `reset()` is the widget's own public API: the 3.3.0 component returns
         * `{configure, getConfiguration, getState, hide, log, reset, setState,
         * show, updateUI, verify}` and the custom element carries it. That was
         * read off the vendored bundle rather than taken from documentation,
         * and it is not executed here — the tests cover OUR dispatch reaching
         * `reset()`, not the vendor's implementation of it.
         *
         * Hence the `typeof` guard: on a bundle where the method is absent the
         * widget is left exactly as it is today, so the worst case is the
         * current behaviour rather than a thrown error that would also swallow
         * the error message the visitor needs to read.
         *
         * `form.reset()` would also work — the widget listens for the form's
         * native `reset` event — and is NOT used: it would clear the code the
         * visitor just typed, on the one screen where retyping it is the whole
         * task.
         *
         * @param {jQuery} $form Form element.
         */
        resetAltchaWidget: function($form) {
            $form.find('altcha-widget').each(function() {
                if (typeof this.reset === 'function') {
                    this.reset();
                }
            });
        }
    };
    
    /**
     * ==========================================================================
     * RATE LIMIT MODULE
     * ==========================================================================
     */
    var RateLimit = {
        blocked: false,

        /**
         * The countdowns running now, one per refused form, so two forms on
         * one page never share a notice, a timer or a button (#1846).
         *
         * @type {Array<{form: Element, stop: Function}>}
         */
        active: [],

        /**
         * Show the rate-limit notice with a countdown on the form that was
         * refused, and hold its submit button until the wait is over.
         *
         * The form comes from the caller, the one whose submit got the
         * refusal. It used to be `$('.ffc-form')`, a class no public form
         * carries, so the certificate form never showed the notice (#1846).
         *
         * @param {string} message - Rate limit message
         * @param {number} waitSeconds - Seconds to wait
         * @param {jQuery|Element} form - The form that was refused
         * @param {string} [label] - The button's label to restore at the end;
         *                           defaults to its text now
         */
        show: function(message, waitSeconds, form, label) {
            var $form = $(form).first();
            if (!$form.length) {
                return;
            }
            this.enable($form);
            this.blocked = true;

            var self = this;
            var $btn = $form.find('button[type="submit"]').first();
            var restoreLabel = typeof label === 'string' ? label : $btn.text();
            var remaining = Math.max(0, parseInt(waitSeconds, 10) || 0);

            // role="status" + aria-live="polite" so the countdown
            // changes are announced by screen readers without
            // interrupting their current speech (assertive would be
            // wrong for ticking values).
            var text = document.createElement('p');
            var countdown = document.createElement('strong');
            text.className = 'ffc-notice-text';
            countdown.textContent = this._formatRemaining(remaining);
            text.appendChild(document.createTextNode(message + ' '));
            text.appendChild(countdown);
            var notice = window.FFC.notice('warning', 'clock', '', '', {
                role: 'status',
                live: 'polite',
                hook: 'rate-limit',
                children: [text]
            });

            // A response to the submit, so it sits directly above the form,
            // where the accessible alert goes too; the notices that frame the
            // form (bypass, quiz, schedule) stay above the title (#1842).
            $form.before(notice);

            var waitText = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings.wait || 'Wait...' : 'Wait...';
            var buttonCount = document.createElement('span');
            buttonCount.textContent = this._formatRemaining(remaining);
            $btn.prop('disabled', true).empty()
                .append(document.createTextNode(waitText + ' ('), buttonCount, document.createTextNode(')'));

            var timer = null;
            var state = {
                form: $form[0],
                stop: function() {
                    clearTimeout(timer);
                    if (notice.parentNode) {
                        notice.parentNode.removeChild(notice);
                    }
                    $btn.prop('disabled', false).text(restoreLabel);
                }
            };
            this.active.push(state);

            // show() painted the initial mm:ss synchronously; the first tick
            // is one second later.
            var tick = function() {
                remaining--;
                if (remaining <= 0) {
                    self.enable($form);
                    return;
                }
                countdown.textContent = self._formatRemaining(remaining);
                buttonCount.textContent = countdown.textContent;
                timer = setTimeout(tick, 1000);
            };
            timer = setTimeout(tick, 1000);
        },

        /**
         * 6.6.4 Sprint 6 — format seconds into mm:ss for the
         * countdown display, shared by the first paint and every tick.
         *
         * @param {number} remaining Seconds remaining.
         * @returns {string} mm:ss formatted.
         */
        _formatRemaining: function(remaining) {
            if (remaining <= 0) return '0:00';
            var mins = Math.floor(remaining / 60);
            var secs = remaining % 60;
            return mins + ':' + (secs < 10 ? '0' : '') + secs;
        },

        /**
         * End the wait on one form: remove its notice and give its submit
         * button back its label. Without a form, end every wait.
         *
         * @param {jQuery|Element} [form]
         */
        enable: function(form) {
            var target = form ? $(form)[0] : null;
            this.active = this.active.filter(function(state) {
                if (target && state.form !== target) {
                    return true;
                }
                state.stop();
                return false;
            });
            this.blocked = this.active.length > 0;
        }
    };
    
    /**
     * ==========================================================================
     * PUBLIC API
     * ==========================================================================
     */
    window.FFC.Frontend = {
        Validation: Validation,
        Masks: Masks,
        UI: UI,
        RateLimit: RateLimit
    };
    
    /**
     * ==========================================================================
     * BACKWARD COMPATIBILITY (Legacy Support)
     * ==========================================================================
     */
    window.ffcUtils = {
        // Masks
        applyCpfRfMask: Masks.applyCpfRf,
        applyAuthCodeMask: Masks.applyAuthCode,
        applyTicketMask: Masks.applyTicket,
        
        // Validation
        validateCPF: Validation.validateCPF,
        validateRF: Validation.validateRF,
        
        // UI
        showFormError: UI.showFormError,
        showFormSuccess: UI.showFormSuccess,
        refreshCaptcha: UI.refreshCaptcha
    };
    
    window.FFCRateLimit = RateLimit;

    // console.log('[FFC Frontend Helpers] Module loaded v3.0.0');

})(jQuery, window);