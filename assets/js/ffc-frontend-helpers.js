/**
 * FFC Frontend Helpers Module
 * v3.0.0 - Modular Architecture
 * 
 * Consolidated frontend utilities:
 * - Input masks (CPF/RF, Auth Code, Ticket)
 * - Field validation (CPF, RF, Email)
 * - UI feedback (Error, Success messages)
 * - Captcha refresh
 * - Rate limiting with countdown
 * 
 * @since 3.0.0
 */

(function($, window) {
    'use strict';
    
    // Initialize FFC namespace
    window.FFC = window.FFC || {};
    
    /**
     * ==========================================================================
     * VALIDATION MODULE
     * ==========================================================================
     */
    var Validation = {
        
        /**
         * Validate CPF (Cadastro de Pessoas Físicas)
         * 
         * @param {string} cpf - CPF to validate (can be formatted or not)
         * @return {boolean} - True if valid, false otherwise
         */
        validateCPF: function(cpf) {
            // Remove formatting
            cpf = cpf.replace(/[^\d]+/g, '');
            
            // Check if has 11 digits
            if (cpf.length !== 11) {
                return false;
            }
            
            // Check for known invalid CPFs (all same digits)
            if (/^(\d)\1{10}$/.test(cpf)) {
                return false;
            }
            
            // Validate first check digit
            var sum = 0;
            for (var i = 0; i < 9; i++) {
                sum += parseInt(cpf.charAt(i)) * (10 - i);
            }
            var digit1 = 11 - (sum % 11);
            if (digit1 >= 10) digit1 = 0;
            
            if (digit1 !== parseInt(cpf.charAt(9))) {
                return false;
            }
            
            // Validate second check digit
            sum = 0;
            for (var j = 0; j < 10; j++) {
                sum += parseInt(cpf.charAt(j)) * (11 - j);
            }
            var digit2 = 11 - (sum % 11);
            if (digit2 >= 10) digit2 = 0;
            
            if (digit2 !== parseInt(cpf.charAt(10))) {
                return false;
            }
            
            return true;
        },
        
        /**
         * Validate RF (Registro Funcional)
         * 
         * @param {string} rf - RF to validate (can be formatted or not)
         * @return {boolean} - True if valid (7 digits), false otherwise
         */
        validateRF: function(rf) {
            // Remove formatting
            rf = rf.replace(/[^\d]+/g, '');
            
            // Check if has exactly 7 digits
            return rf.length === 7 && /^\d{7}$/.test(rf);
        },
        
        /**
         * Validate email format
         * 
         * @param {string} email - Email address
         * @return {boolean} - True if valid format
         */
        validateEmail: function(email) {
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
                    // Remove all except digits
                    var value = $(this).val().replace(/\D/g, '');
                    
                    // Limit to 11 digits (CPF)
                    if (value.length > 11) {
                        value = value.substring(0, 11);
                    }
                    
                    // Apply mask based on length
                    var masked = '';
                    
                    if (value.length <= 7) {
                        // RF format: XXX.XXX-X
                        if (value.length <= 3) {
                            masked = value;
                        } else if (value.length <= 6) {
                            masked = value.substring(0, 3) + '.' + value.substring(3);
                        } else {
                            masked = value.substring(0, 3) + '.' + value.substring(3, 6) + '-' + value.substring(6);
                        }
                    } else {
                        // CPF format: XXX.XXX.XXX-XX
                        if (value.length <= 3) {
                            masked = value;
                        } else if (value.length <= 6) {
                            masked = value.substring(0, 3) + '.' + value.substring(3);
                        } else if (value.length <= 9) {
                            masked = value.substring(0, 3) + '.' + value.substring(3, 6) + '.' + value.substring(6);
                        } else {
                            masked = value.substring(0, 3) + '.' + value.substring(3, 6) + '.' + value.substring(6, 9) + '-' + value.substring(9);
                        }
                    }
                    
                    $(this).val(masked);
                    
                    // Remove validation styling while typing
                    $(this).removeClass('ffc-invalid ffc-valid');
                });
                
                // Validate on blur (when user leaves field)
                $input.on('blur.cpfrf', function() {
                    var value = $(this).val().replace(/\D/g, '');
                    
                    // Skip if empty (not required check)
                    if (value.length === 0) {
                        $(this).removeClass('ffc-invalid ffc-valid').removeAttr('aria-invalid aria-describedby');
                        $(this).next('.ffc-field-error').remove();
                        return;
                    }
                    
                    var isValid = false;
                    var errorMsg = '';

                    // Get localized strings
                    var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};

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
                    
                    // Apply visual feedback with ARIA
                    var inputId = $(this).attr('id') || $(this).attr('name') || 'cpfrf';
                    var errorId = 'ffc-error-' + inputId;

                    if (isValid) {
                        $(this).removeClass('ffc-invalid').addClass('ffc-valid')
                               .removeAttr('aria-invalid aria-describedby');
                        $(this).next('.ffc-field-error').fadeOut(function() { $(this).remove(); });
                    } else {
                        $(this).removeClass('ffc-valid').addClass('ffc-invalid')
                               .attr('aria-invalid', 'true')
                               .attr('aria-describedby', errorId);

                        // Show error message near field
                        var $errorSpan = $(this).next('.ffc-field-error');
                        if ($errorSpan.length === 0) {
                            $errorSpan = $('<span class="ffc-field-error" role="alert"></span>').insertAfter($(this));
                        }
                        $errorSpan.attr('id', errorId).text(errorMsg).fadeIn();

                        // Hide error after 5 seconds
                        setTimeout(function() {
                            $errorSpan.fadeOut();
                        }, 5000);
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
            
            // Add CSS for validation states if not exists
            if (!$('#ffc-validation-styles').length) {
                $('<style id="ffc-validation-styles">' +
                    'input.ffc-valid { border-color: #00a32a !important; background: #f0f9ff; }' +
                    'input.ffc-invalid { border-color: #d63638 !important; background: #fff3f3; }' +
                    '.ffc-field-error { display: block; color: #d63638; font-size: 12px; margin-top: 5px; animation: ffcSlideDown 0.3s ease; }' +
                '</style>').appendTo('head');
            }
        },
        
        /**
         * Apply auth code mask to input fields
         * Format: XXXX-XXXX-XXXX
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

            // console.log('[FFC Masks] Applying auth code mask to', $inputs.length, 'field(s)');

$inputs.each(function() {
                var $input = $(this);
                
                // Remove existing handlers to avoid duplicates
                $input.off('input.authcode paste.authcode');
                
                // Apply mask on input
                $input.on('input.authcode', function() {
                    // Remove all except A-Z and 0-9, convert to uppercase
                    var value = $(this).val().toUpperCase().replace(/[^A-Z0-9]/g, '');
                    
                    // Limit to 12 characters
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
    /**
     * Escape HTML entities to prevent XSS
     * @param {string} str - String to escape
     * @returns {string} Escaped string
     */
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;')
                          .replace(/</g, '&lt;')
                          .replace(/>/g, '&gt;')
                          .replace(/"/g, '&quot;')
                          .replace(/'/g, '&#39;');
    }

    var UI = {

        /**
         * Show error message in form
         *
         * @param {jQuery} $form - Form element
         * @param {string} message - Error message to display
         */
        showFormError: function($form, message) {
            // Remove existing error messages
            $form.find('.ffc-form-error').remove();
            
            // Create error HTML with styling
            var $error = $('<div class="ffc-form-error"></div>')
                .css({
                    'background': '#f8d7da',
                    'color': '#721c24',
                    'padding': '15px 20px',
                    'margin': '0 0 20px 0',
                    'border-radius': '5px',
                    'border': '1px solid #f5c6cb',
                    'font-size': '14px',
                    'line-height': '1.5',
                    'animation': 'ffcSlideDown 0.3s ease'
                })
                .html('<strong>' + (escapeHtml(message).indexOf('Error') === 0 ? '' : 'Error: ') + '</strong>' + escapeHtml(message));
            
            // Add slide down animation if not exists
            if (!$('#ffc-slide-animation').length) {
                $('<style id="ffc-slide-animation">@keyframes ffcSlideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }</style>')
                    .appendTo('head');
            }
            
            // Insert at top of form
            $form.prepend($error);
            
            // Scroll to error (smooth)
            $('html, body').animate({
                scrollTop: $error.offset().top - 100
            }, 300);
            
            // Auto-remove after 10 seconds
            setTimeout(function() {
                $error.fadeOut(300, function() {
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
            $form.find('.ffc-form-error, .ffc-form-success').remove();
            
            // If html provided, use it directly
            if (html && html.trim().length > 0) {
                $form.html(html);
                return;
            }
            
            // Otherwise show generic success
            var $success = $('<div class="ffc-form-success"></div>')
                .css({
                    'background': '#d4edda',
                    'color': '#155724',
                    'padding': '20px',
                    'margin': '0 0 20px 0',
                    'border-radius': '5px',
                    'border': '1px solid #c3e6cb',
                    'text-align': 'center',
                    'animation': 'ffcSlideDown 0.3s ease'
                })
                .html('<h3 style="margin: 0 0 10px 0; font-size: 20px;">✅ ' + (ffc_ajax.strings.success || 'Success!') + '</h3><p style="margin: 0;">' + (ffc_ajax.strings.submissionSuccessful || 'Your submission was successful.') + '</p>');
            
            $form.html($success);
        },
        
        /**
         * Refresh captcha question and hash
         * 
         * @param {jQuery} $form - Form element
         * @param {string} newLabel - New captcha question HTML
         * @param {string} newHash - New captcha hash
         */
        refreshCaptcha: function($form, newLabel, newHash) {
            // Find captcha elements
            var $captchaLabel = $form.find('label[for*="captcha"], .ffc-captcha-row label').first();
            var $captchaInput = $form.find('input[name="ffc_captcha_ans"]');
            var $captchaHash = $form.find('input[name="ffc_captcha_hash"]');
            
            // Update label
            if ($captchaLabel.length && newLabel) {
                $captchaLabel.html(newLabel);
                // console.log('[FFC UI] Captcha label updated');
            }

            // Update hash
            if ($captchaHash.length && newHash) {
                $captchaHash.val(newHash);
                // console.log('[FFC UI] Captcha hash updated');
            }
            
            // Clear and focus input
            if ($captchaInput.length) {
                $captchaInput.val('').focus();
                
                // Add visual feedback (flash animation)
                $captchaInput.css('background-color', '#fffbcc');
                setTimeout(function() {
                    $captchaInput.css({
                        'background-color': '#fff',
                        'transition': 'background-color 0.5s'
                    });
                }, 100);
            }
        }
    };
    
    /**
     * ==========================================================================
     * RATE LIMIT MODULE
     * ==========================================================================
     */
    var RateLimit = {
        blocked: false,
        waitSeconds: 0,
        countdownInterval: null,
        
        /**
         * Show rate limit notice with countdown
         * 
         * @param {string} message - Rate limit message
         * @param {number} waitSeconds - Seconds to wait
         */
        show: function(message, waitSeconds) {
            this.blocked = true;
            this.waitSeconds = waitSeconds;
            
            var $form = $('.ffc-form');
            var $btn = $form.find('button[type="submit"]');
            
            // Add notice to form
            $form.prepend(
                '<div class="ffc-rate-limit-notice">' +
                    '<div class="ffc-rate-limit-icon">⏱️</div>' +
                    '<div class="ffc-rate-limit-message">' + message + ' <strong id="ffc-countdown">0:00</strong></div>' +
                '</div>'
            );
            
            // Disable button with countdown
            var waitText = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings.wait || 'Wait...' : 'Wait...';
            $btn.prop('disabled', true).html(waitText + ' (<span id="ffc-countdown-btn">0:00</span>)');
            
            // Start countdown
            this.startCountdown();
        },
        
        /**
         * Start countdown timer
         */
        startCountdown: function() {
            var self = this;
            var remaining = this.waitSeconds;
            
            var updateDisplay = function() {
                if (remaining <= 0) {
                    self.enable();
                    return;
                }
                
                var mins = Math.floor(remaining / 60);
                var secs = remaining % 60;
                var display = mins + ':' + (secs < 10 ? '0' : '') + secs;
                
                $('#ffc-countdown').text(display);
                $('#ffc-countdown-btn').text(display);
                
                remaining--;
                setTimeout(updateDisplay, 1000);
            };
            
            updateDisplay();
        },
        
        /**
         * Enable form after rate limit expires
         */
        enable: function() {
            this.blocked = false;
            $('.ffc-rate-limit-notice').remove();
            var sendText = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings.send || 'Send' : 'Send';
            $('.ffc-form button[type="submit"]').prop('disabled', false).text(sendText);
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