/**
 * FFC Frontend Utilities
 * Reusable functions for form handling, masks, validation, and user feedback
 * 
 * @version 1.1.0
 * @since 2.9.11
 * 
 * Functions:
 * - showFormError()      - Display error message in form
 * - showFormSuccess()    - Display success message in form  
 * - refreshCaptcha()     - Update captcha question and hash
 * - applyCpfRfMask()     - Apply CPF/RF mask to inputs (WITH VALIDATION)
 * - applyAuthCodeMask()  - Apply auth code mask (XXXX-XXXX-XXXX)
 * - applyTicketMask()    - Apply ticket mask (XXXX-XXXX)
 * - validateCPF()        - Validate CPF number (NEW)
 * - validateRF()         - Validate RF number (NEW)
 */

(function($, window) {
    'use strict';

    /**
     * Validate CPF (Cadastro de Pessoas Físicas)
     * 
     * @param {string} cpf - CPF to validate (can be formatted or not)
     * @return {boolean} - True if valid, false otherwise
     */
    function validateCPF(cpf) {
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
    }

    /**
     * Validate RF (Registro Funcional)
     * 
     * @param {string} rf - RF to validate (can be formatted or not)
     * @return {boolean} - True if valid (7 digits), false otherwise
     */
    function validateRF(rf) {
        // Remove formatting
        rf = rf.replace(/[^\d]+/g, '');
        
        // Check if has exactly 7 digits
        return rf.length === 7 && /^\d{7}$/.test(rf);
    }

    /**
     * Show error message in form
     * 
     * @param {jQuery} $form - Form element
     * @param {string} message - Error message to display
     */
    function showFormError($form, message) {
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
            .html('<strong>⚠️ ' + (message.indexOf('Error') === 0 ? '' : 'Error: ') + '</strong>' + message);
        
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
    }

    /**
     * Show success message in form
     * 
     * @param {jQuery} $form - Form element
     * @param {string} html - Success HTML content
     */
    function showFormSuccess($form, html) {
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
            .html('<h3 style="margin: 0 0 10px 0; font-size: 20px;">✅ Success!</h3><p style="margin: 0;">Your submission was successful.</p>');
        
        $form.html($success);
    }

    /**
     * Refresh captcha question and hash
     * 
     * @param {jQuery} $form - Form element
     * @param {string} newLabel - New captcha question HTML
     * @param {string} newHash - New captcha hash
     */
    function refreshCaptcha($form, newLabel, newHash) {
        // Find captcha elements
        var $captchaLabel = $form.find('label[for*="captcha"], .ffc-captcha-row label').first();
        var $captchaInput = $form.find('input[name="ffc_captcha_ans"]');
        var $captchaHash = $form.find('input[name="ffc_captcha_hash"]');
        
        // Update label
        if ($captchaLabel.length && newLabel) {
            $captchaLabel.html(newLabel);
            console.log('[FFC Utils] Captcha label updated');
        }
        
        // Update hash
        if ($captchaHash.length && newHash) {
            $captchaHash.val(newHash);
            console.log('[FFC Utils] Captcha hash updated');
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
    function applyCpfRfMask($inputs) {
        // If no inputs provided, find all CPF/RF inputs
        if (!$inputs || $inputs.length === 0) {
            $inputs = $('input[name="cpf_rf"], input[name="cpf"], input[id*="cpf"]');
        }
        
        if ($inputs.length === 0) {
            return;
        }
        
        console.log('[FFC Utils] Applying CPF/RF mask with validation to', $inputs.length, 'field(s)');
        
        $inputs.each(function() {
            var $input = $(this);
            
            // Remove existing handlers to avoid duplicates
            $input.off('input.cpfrf paste.cpfrf keypress.cpfrf blur.cpfrf');
            
            // Allow only numbers during keypress
            $input.on('keypress.cpfrf', function(e) {
                // Allow: backspace, delete, tab, escape, enter
                if ($.inArray(e.keyCode, [46, 8, 9, 27, 13]) !== -1 ||
                    // Allow: Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
                    (e.keyCode === 65 && e.ctrlKey === true) ||
                    (e.keyCode === 67 && e.ctrlKey === true) ||
                    (e.keyCode === 86 && e.ctrlKey === true) ||
                    (e.keyCode === 88 && e.ctrlKey === true)) {
                    return;
                }
                // Ensure that it is a number
                if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
                    e.preventDefault();
                }
            });
            
            // Apply mask on input
            $input.on('input.cpfrf', function() {
                // Remove all non-numeric characters
                var value = $(this).val().replace(/\D/g, '');
                
                // Limit to 11 characters
                if (value.length > 11) {
                    value = value.substring(0, 11);
                }
                
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
                    $(this).removeClass('ffc-invalid ffc-valid');
                    $(this).next('.ffc-field-error').remove();
                    return;
                }
                
                var isValid = false;
                var errorMsg = '';
                
                if (value.length === 7) {
                    // RF validation
                    isValid = validateRF(value);
                    errorMsg = 'RF inválido';
                } else if (value.length === 11) {
                    // CPF validation
                    isValid = validateCPF(value);
                    errorMsg = 'CPF inválido';
                } else {
                    errorMsg = 'Digite um CPF (11 dígitos) ou RF (7 dígitos) válido';
                }
                
                // Apply visual feedback
                if (isValid) {
                    $(this).removeClass('ffc-invalid').addClass('ffc-valid');
                    $(this).next('.ffc-field-error').fadeOut(function() { $(this).remove(); });
                } else {
                    $(this).removeClass('ffc-valid').addClass('ffc-invalid');
                    
                    // Show error message near field
                    var $errorSpan = $(this).next('.ffc-field-error');
                    if ($errorSpan.length === 0) {
                        $errorSpan = $('<span class="ffc-field-error"></span>').insertAfter($(this));
                    }
                    $errorSpan.text(errorMsg).fadeIn();
                    
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
    }

    /**
     * Apply auth code mask to input fields
     * Format: XXXX-XXXX-XXXX
     * 
     * @param {jQuery} $inputs - Input elements to apply mask (optional)
     */
    function applyAuthCodeMask($inputs) {
        // If no inputs provided, find all auth code inputs
        if (!$inputs || $inputs.length === 0) {
            $inputs = $('input[name="ffc_auth_code"], .ffc-verify-input, .ffc-manual-auth-code');
        }
        
        if ($inputs.length === 0) {
            return;
        }
        
        console.log('[FFC Utils] Applying auth code mask to', $inputs.length, 'field(s)');
        
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
    }

    /**
     * Apply ticket code mask to input fields
     * Format: XXXX-XXXX (8 alphanumeric characters)
     * 
     * @param {jQuery} $inputs - Input elements to apply mask (optional)
     */
    function applyTicketMask($inputs) {
        // If no inputs provided, find all ticket inputs
        if (!$inputs || $inputs.length === 0) {
            $inputs = $('input[name="ffc_ticket"], .ffc-ticket-input, #ffc_ticket');
        }
        
        if ($inputs.length === 0) {
            return;
        }
        
        console.log('[FFC Utils] Applying ticket mask to', $inputs.length, 'field(s)');
        
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

    // Export functions to global namespace
    window.ffcUtils = {
        showFormError: showFormError,
        showFormSuccess: showFormSuccess,
        refreshCaptcha: refreshCaptcha,
        applyCpfRfMask: applyCpfRfMask,
        applyAuthCodeMask: applyAuthCodeMask,
        applyTicketMask: applyTicketMask,
        validateCPF: validateCPF,
        validateRF: validateRF
    };
    
    console.log('[FFC Utils] Frontend utilities module loaded (v1.1.0 - with CPF/RF validation)');

})(jQuery, window);