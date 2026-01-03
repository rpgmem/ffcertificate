/**
 * FFC Frontend Utilities
 * Reusable functions for form handling, masks, and user feedback
 * 
 * @version 1.0.0
 * @since 2.9.11
 * 
 * Functions:
 * - showFormError()      - Display error message in form
 * - showFormSuccess()    - Display success message in form  
 * - refreshCaptcha()     - Update captcha question and hash
 * - applyCpfRfMask()     - Apply CPF/RF mask to inputs
 * - applyAuthCodeMask()  - Apply auth code mask (XXXX-XXXX-XXXX)
 */

(function($, window) {
    'use strict';

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
     * Apply CPF/RF mask to input fields
     * 
     * Formats based on length:
     * - 7 digits: XXX.XXX-X (RF)
     * - 11 digits: XXX.XXX.XXX-XX (CPF)
     * 
     * Based on FFC_Utils::format_cpf() and FFC_Utils::format_rf()
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
        
        console.log('[FFC Utils] Applying CPF/RF mask to', $inputs.length, 'field(s)');
        
        $inputs.each(function() {
            var $input = $(this);
            
            // Remove existing handlers to avoid duplicates
            $input.off('input.cpfrf paste.cpfrf keypress.cpfrf');
            
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
    }

    /**
     * Apply auth code mask to input fields
     * Format: XXXX-XXXX-XXXX
     * 
     * Based on FFC_Utils::format_auth_code()
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

    // Export functions to global namespace
    window.ffcUtils = {
        showFormError: showFormError,
        showFormSuccess: showFormSuccess,
        refreshCaptcha: refreshCaptcha,
        applyCpfRfMask: applyCpfRfMask,
        applyAuthCodeMask: applyAuthCodeMask
    };
    
    console.log('[FFC Utils] Frontend utilities module loaded (v1.0.0)');

})(jQuery, window);