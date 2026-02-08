/**
 * Free Form Certificate - Frontend JavaScript
 * Handles form submission, verification, and delegates masks to FFC.Frontend
 *
 * PDF Generation: Uses ffc-pdf-generator.js (shared module)
 * Utilities: Uses ffc-frontend-helpers.js (modular)
 *
 * @version 3.1.0 - Cleaned up defensive code
 *
 * Changelog:
 * v3.1.0: Removed defensive backward compatibility code - uses FFC.Frontend namespace exclusively
 * v3.0.0: REFACTORED - Updated to use FFC.Frontend namespace (backward compatible)
 * v2.9.12: REFACTORED - Moved masks and messages to ffc-frontend-utils.js
 * v2.9.11: Fixed 4 frontend bugs (layout, captcha, error display, CPF/RF mask)
 */

(function($) {
    'use strict';
    window.ffcUtils = window.FFC.Frontend.Masks;

    /**
     * Show an accessible inline alert message instead of window.alert()
     *
     * @param {string} message - Message text
     * @param {jQuery|null} $context - Element near which to show the message (falls back to body)
     */
    function showAccessibleAlert(message, $context) {
        // Remove any previous transient alerts
        $('.ffc-accessible-alert').remove();

        var $alert = $('<div class="ffc-accessible-alert ffc-message ffc-message-error" role="alert">' + message + '</div>');

        if ($context && $context.length) {
            $context.before($alert);
        } else {
            $('body').prepend($alert);
        }

        // Auto-remove after 8 seconds
        setTimeout(function() {
            $alert.fadeOut(300, function() { $(this).remove(); });
        }, 8000);

        // Focus the alert for screen readers
        $alert.attr('tabindex', '-1').focus();
    }

    /**
     * Handle magic link verification (automatic on page load)
     * 
     * v2.8.0: Supports both query string (?token=) and hash (#token=)
     * v2.9.0: Hash format preferred to avoid WordPress redirects
     * Format: /valid/#token=xxx
     */
    function handleMagicLinkVerification() {
        var $container = $('.ffc-magic-link-container, .ffc-verification-auto-check');
        
        if ($container.length === 0) {
            return; // No verification container on this page
        }

        var token = null;
        
        // Priority 1: Get token from data attribute (pre-loaded from query string)
        token = $container.data('token');
        
        // Priority 2: Get token from URL hash (#token=xxx)
        if (!token && window.location.hash) {
            var hash = window.location.hash;
            
            // Remove leading # if present
            if (hash.startsWith('#')) {
                hash = hash.slice(1);
            }
            
            var hashParams = new URLSearchParams(hash);
            token = hashParams.get('token');

            // console.log('[FFC] Hash detected:', window.location.hash);
            // console.log('[FFC] Token extracted from hash:', token ? token.substring(0, 8) + '...' : 'null');
        }
        
        // Priority 3: Get token from query string (?token=xxx) - fallback
        if (!token) {
            var urlParams = new URLSearchParams(window.location.search);
            token = urlParams.get('token');
        }
        
        if (!token) {
            // No token found - show manual form (already visible by default)
            return;
        }

        // Auto-verify with token - hide manual form, show loading
        $container.find('.ffc-verification-manual').hide();
        $container.find('.ffc-verify-loading').show();

        $.ajax({
            url: ffc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ffc_verify_magic_token',
                token: token
            },
            success: function(response) {
                if (response.success) {
                    displayVerificationResult(response.data, $container);
                } else {
                    showVerificationError(response.data ? response.data.message : 'Invalid token', $container);
                }
            },
            error: function() {
                showVerificationError('Connection error. Please try again.', $container);
            }
        });
    }
    
    /**
     * Display verification result
     * 
     * ✅ v2.9.8: Use HTML from backend directly (beautiful layout)
     * ✅ v2.9.10: Add pdf_data to download button
     */
    function displayVerificationResult(data, $container) {
        // ✅ Priority: Use HTML from backend (v2.9.7+ beautiful layout)
        if (data.html) {
            $container.html(data.html);
            
            // ✅ v2.9.10: Add pdf_data to download button
            if (data.pdf_data) {
                var $downloadBtn = $container.find('.ffc-download-btn, .ffc-download-pdf-btn');
                if ($downloadBtn.length) {
                    $downloadBtn.attr('data-pdf-data', JSON.stringify(data.pdf_data));
                    // console.log('[FFC] PDF data added to button');
                }
            }
            
            return;
        }
        
        // Fallback: Legacy format
        var html = '<div class="ffc-verification-success">';
        html += '<h3>' + (ffc_ajax.strings.certificateValid || 'Document Valid!') + '</h3>';
        
        if (data.html_preview) {
            html += '<div class="ffc-certificate-preview">' + data.html_preview + '</div>';
        }
        
        if (data.form_title) {
            html += '<p><strong>' + (ffc_ajax.strings.formTitle || 'Form') + ':</strong> ' + data.form_title + '</p>';
        }
        
        if (data.auth_code) {
            html += '<p><strong>' + (ffc_ajax.strings.authCode || 'Auth Code') + ':</strong> ' + data.auth_code + '</p>';
        }
        
        if (data.submission_date) {
            html += '<p><strong>' + (ffc_ajax.strings.issueDate || 'Issue Date') + ':</strong> ' + data.submission_date + '</p>';
        }
        
        if (data.template || data.pdf_data) {
            var pdfDataToUse = data.pdf_data || {
                template: data.template,
                form_title: data.form_title,
                submission: data.submission,
                bg_image: data.bg_image
            };
            
            html += '<button class="ffc-download-pdf-btn" data-pdf-data=\'' + JSON.stringify(pdfDataToUse) + '\'>' + 
                    (ffc_ajax.strings.downloadPDF || 'Download PDF') + '</button>';
        }
        
        html += '</div>';
        
        $container.html(html);
    }
    
    /**
     * Show verification error
     */
    function showVerificationError(message, $container) {
        var html = '<div class="ffc-verification-error">';
        html += '<h3>' + (ffc_ajax.strings.certificateInvalid || 'Document Invalid') + '</h3>';
        html += '<p>' + message + '</p>';
        html += '<div class="ffc-manual-verification-form">';
        html += '<p>' + (ffc_ajax.strings.tryManually || 'Or try manual verification') + ':</p>';
        html += '<input type="text" class="ffc-manual-auth-code" placeholder="' + (ffc_ajax.strings.enterAuthCode || 'Enter auth code') + '">';
        html += '<button class="ffc-manual-verify-btn">' + (ffc_ajax.strings.verify || 'Verify') + '</button>';
        html += '</div>';
        html += '</div>';
        
        $container.html(html);
    }

    /**
     * Handle manual verification form submit
     */
    $(document).on('submit', '.ffc-verification-form', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var authCode = $form.find('input[name="ffc_auth_code"]').val().trim();
        var captchaAns = $form.find('input[name="ffc_captcha_ans"]').val();
        var captchaHash = $form.find('input[name="ffc_captcha_hash"]').val();
        var honeypot = $form.find('input[name="ffc_honeypot_trap"]').val();
        
        if (!authCode) {
            showAccessibleAlert(ffc_ajax.strings.enterCode || 'Please enter the code', $form);
            return;
        }
        
        $.ajax({
            url: ffc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ffc_verify_certificate',
                nonce: ffc_ajax.nonce,
                ffc_auth_code: authCode,
                ffc_captcha_ans: captchaAns,
                ffc_captcha_hash: captchaHash,
                ffc_honeypot_trap: honeypot
            },
            success: function(response) {
                if (response.success) {
                    displayVerificationResult(response.data, $form.closest('.ffc-verification-container'));
                } else {
                    // Refresh captcha if needed
                    if (response.data && response.data.refresh_captcha) {
                        FFC.Frontend.UI.refreshCaptcha($form, response.data.new_label, response.data.new_hash);
                    }

                    // Show error inline without destroying the form
                    var errorMsg = response.data ? response.data.message : (ffc_ajax.strings.error || 'Error');
                    var $errorDiv = $form.find('.ffc-verify-error');
                    if (!$errorDiv.length) {
                        $form.find('.ffc-form-field').first().before('<div class="ffc-verify-error"></div>');
                        $errorDiv = $form.find('.ffc-verify-error');
                    }
                    $errorDiv.html('<p class="ffc-message ffc-message-error">' + errorMsg + '</p>');
                }
            },
            error: function() {
                showAccessibleAlert(ffc_ajax.strings.connectionError || 'Connection error', $form);
            }
        });
    });

    /**
     * Handle manual verify button (in error state - magic link failures)
     * Re-renders the full verification form so user can enter code with captcha
     */
    $(document).on('click', '.ffc-manual-verify-btn', function() {
        // Reload the page to get a fresh form with captcha
        window.location.href = window.location.pathname;
    });

    /**
     * ✅ PDF Download (uses shared ffc-pdf-generator.js)
     */
    $(document).on('click', '.ffc-download-pdf-btn, .ffc-download-btn', function() {
        try {
            var pdfData = JSON.parse($(this).attr('data-pdf-data') || '{}');
            var filename = pdfData.filename || 'certificate.pdf';
            
            // ✅ Uses shared PDF generator module
            if (typeof window.ffcGeneratePDF === 'function') {
                window.ffcGeneratePDF(pdfData, filename);
            } else {
                console.error('[FFC] PDF generator not loaded');
                showAccessibleAlert(ffc_ajax.strings.pdfLibrariesFailed || 'PDF generation not available', $(this).parent());
            }
        } catch (e) {
            console.error('[FFC] Error parsing PDF data:', e);
            showAccessibleAlert(ffc_ajax.strings.error || 'Error occurred', $(this).parent());
        }
    });

    /**
     * Handle form submission
     * 
     * ✅ v3.0.0: Updated to use FFC.Frontend namespace (backward compatible)
     */
    function handleFormSubmission() {
        $(document).on('submit', '.ffc-submission-form, .ffc-form', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $submitBtn = $form.find('button[type="submit"]');
            var originalBtnText = $submitBtn.text();
            
            // Basic validation
            var isValid = true;
            $form.find('[required]').each(function() {
                if (!$(this).val()) {
                    isValid = false;
                    $(this).addClass('ffc-field-error').attr('aria-invalid', 'true');
                } else {
                    $(this).removeClass('ffc-field-error').removeAttr('aria-invalid');
                }
            });
            
            if (!isValid) {
                showAccessibleAlert(ffc_ajax.strings.fillRequired || 'Please fill all required fields', $form);
                // Focus the first invalid field
                $form.find('.ffc-field-error').first().focus();
                return;
            }
            
            // Disable submit button
            $submitBtn.prop('disabled', true).text(ffc_ajax.strings.processing || 'Processing...');
            
            // Prepare form data
            var formData = $form.serialize();
            formData += '&action=ffc_submit_form';
            formData += '&nonce=' + encodeURIComponent(ffc_ajax.nonce);
            
            $.ajax({
                url: ffc_ajax.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        // ✅ Use response.data.html for beautiful layout
                        if (response.data.html) {
                            $form.html(response.data.html);
                            
                            // Add PDF data to download button if available
                            if (response.data.pdf_data) {
                                var $downloadBtn = $form.find('.ffc-download-btn, .ffc-download-pdf-btn');
                                if ($downloadBtn.length) {
                                    $downloadBtn.attr('data-pdf-data', JSON.stringify(response.data.pdf_data));
                                }
                            }
                        } else {
                            // Fallback to simple success message
                            FFC.Frontend.UI.showFormSuccess($form, '');
                        }
                        
                        // ✅ Auto-download PDF if available
                        if (response.data.pdf_data && typeof window.ffcGeneratePDF === 'function') {
                            setTimeout(function() {
                                var filename = response.data.pdf_data.filename || 'certificate.pdf';
                                window.ffcGeneratePDF(response.data.pdf_data, filename);
                            }, 500);
                        }
                    } else {
                        // Show error in form
                        var errorMsg = response.data ? response.data.message : (ffc_ajax.strings.error || 'Error occurred');
                        FFC.Frontend.UI.showFormError($form, errorMsg);

                        // Refresh captcha if needed
                        if (response.data && response.data.refresh_captcha) {
                            FFC.Frontend.UI.refreshCaptcha($form, response.data.new_label, response.data.new_hash);
                        }

                        $submitBtn.prop('disabled', false).text(originalBtnText);
                    }
                },
                error: function(xhr, status, error) {
                    try {
                        var response = xhr.responseJSON;
                        if (response && response.data && response.data.rate_limit) {
                            FFC.Frontend.RateLimit.show(response.data.message, response.data.wait_seconds);
                            $submitBtn.prop('disabled', false).text(originalBtnText);
                            return;
                        }
                    } catch(e) {}

                    showAccessibleAlert(ffc_ajax.strings.connectionError || 'Connection error', $form);
                    $submitBtn.prop('disabled', false).text(originalBtnText);
                }
            });
        });
    }

    /**
     * Setup MutationObserver to re-apply masks when DOM changes
     */
    function setupDynamicMaskObserver() {
        if (!FFC.Frontend.Masks) {
            console.warn('[FFC] Frontend helpers not loaded - dynamic masks disabled');
            return;
        }
        
        // Create observer instance
        var observer = new MutationObserver(function(mutations) {
            var needsAuthMask = false;
            var needsCpfMask = false;
            var needsTicketMask = false;
            
            mutations.forEach(function(mutation) {
                // Only process added nodes
                if (mutation.addedNodes.length === 0) {
                    return;
                }
                
                mutation.addedNodes.forEach(function(node) {
                    // Skip text nodes
                    if (node.nodeType !== 1) {
                        return;
                    }
                    
                    var $node = $(node);
                    
                    // Check for auth code inputs
                    if ($node.hasClass('ffc-manual-auth-code') || 
                        $node.find('.ffc-manual-auth-code').length ||
                        $node.find('.ffc-verify-input').length) {
                        needsAuthMask = true;
                    }
                    
                    // Check for CPF/RF inputs
                    if ($node.attr('name') === 'cpf_rf' || 
                        $node.attr('name') === 'cpf' ||
                        $node.find('input[name="cpf_rf"], input[name="cpf"]').length) {
                        needsCpfMask = true;
                    }
                    
                    // Check for ticket fields
                    if ($node.attr('name') === 'ffc_ticket' ||
                        $node.hasClass('ffc-ticket-input') ||
                        $node.find('input[name="ffc_ticket"], .ffc-ticket-input').length) {
                        needsTicketMask = true;
                    }
                });
            });
            
            // Apply masks if needed (debounced)
            if (needsAuthMask || needsCpfMask || needsTicketMask) {
                clearTimeout(observer.maskTimeout);
                observer.maskTimeout = setTimeout(function() {
                    if (needsAuthMask) {
                        FFC.Frontend.Masks.applyAuthCode();
                        // console.log('[FFC] Auth code mask re-applied');
                    }

                    if (needsCpfMask) {
                        FFC.Frontend.Masks.applyCpfRf();
                        // console.log('[FFC] CPF/RF mask re-applied');
                    }

                    if (needsTicketMask) {
                        FFC.Frontend.Masks.applyTicket();
                        // console.log('[FFC] Ticket mask re-applied');
                    }
                }, 50);
            }
        });
        
        // Configuration
        var config = {
            childList: true,  // Observe direct children
            subtree: true     // Observe all descendants
        };
        
        // Start observing document body
        observer.observe(document.body, config);

        // console.log('[FFC] MutationObserver initialized for dynamic masks');

        // Return observer for cleanup if needed
        return observer;
    }

    // Initialize on document ready
    $(document).ready(function() {
        // Delay magic link to ensure hash is available
        setTimeout(function() {
            handleMagicLinkVerification();
        }, 100);
        
        handleFormSubmission();

        // Apply masks using FFC.Frontend.Masks
        if (FFC.Frontend.Masks) {
            FFC.Frontend.Masks.applyAuthCode();
            // console.log('[FFC] Auth code mask applied');

            FFC.Frontend.Masks.applyCpfRf();
            // console.log('[FFC] CPF/RF mask applied');

            FFC.Frontend.Masks.applyTicket();
            // console.log('[FFC] Ticket mask applied');

            // Setup dynamic mask observer
            setupDynamicMaskObserver();
        } else {
            console.warn('[FFC] Frontend helpers not loaded - masks disabled');
        }
    });

    // console.log('[FFC Frontend] Script loaded v3.1.0 (cleaned up defensive code)');

})(jQuery);