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
     * 6.6.4 follow-up (#361 Sprint 1) — browser-environment diagnostic
     * log. Was inside ffc-geofence-frontend.js (ran always-on, polluted
     * console for every visitor). Moved here so it covers ALL form
     * pages (not just geofenced ones) and gated on the new
     * `debug_browser_env` toggle.
     *
     * Captures:
     *   - Service worker registrations — typically empty; populated on
     *     hosts that bundle a PWA shell SW that can intercept admin-
     *     ajax and break the nonce flow.
     *   - Clipboard write permission — relevant for the Copy buttons
     *     on the 6.6.2 success card. Chromium reports
     *     granted/prompt/denied; iOS Safari rejects the query, which
     *     itself is a signal worth logging.
     *
     * Default OFF. Toggle in Settings → Debug → Browser environment.
     */
    function ffcLogBrowserEnvDiagnostics() {
        if (typeof window.ffc_ajax === 'undefined'
            || !window.ffc_ajax.debug_browser_env) {
            return;
        }

        try {
            if (navigator.serviceWorker && typeof navigator.serviceWorker.getRegistrations === 'function') {
                navigator.serviceWorker.getRegistrations().then(function (regs) {
                    var scopes = regs.map(function (r) { return r.scope; });
                    console.info('[FFC Diagnostics] Service workers:', scopes.length, scopes);
                }).catch(function () {
                    // Some legacy browsers reject the promise outright.
                });
            } else {
                console.info('[FFC Diagnostics] Service workers: API not available');
            }
        } catch (e) {
            // Diagnostics must never break the form flow.
        }

        try {
            if (navigator.permissions && typeof navigator.permissions.query === 'function') {
                navigator.permissions.query({ name: 'clipboard-write' }).then(function (status) {
                    console.info('[FFC Diagnostics] Clipboard write permission:', status.state);
                }).catch(function () {
                    console.info('[FFC Diagnostics] Clipboard write permission: not queryable');
                });
            } else {
                console.info('[FFC Diagnostics] Permissions API: not available');
            }
        } catch (e) {
            // Swallow.
        }
    }

    // Fire diagnostic log once on script load, before any form
    // interaction. The gate is inside the function so the cost is
    // a single property read when the toggle is off.
    ffcLogBrowserEnvDiagnostics();

    /**
     * Show an accessible inline alert message instead of window.alert()
     *
     * @param {string} message - Message text
     * @param {jQuery|null} $context - Element near which to show the message (falls back to body)
     */
    function showAccessibleAlert(message, $context) {
        // Remove any previous transient alerts
        $('.ffc-accessible-alert').remove();

        var $alert = $('<div class="ffc-accessible-alert ffc-message ffc-message-error" role="alert">').text(message);

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

        FFC.request('ffc_verify_magic_token', { token: token })
            .then(function (data) {
                displayVerificationResult(data, $container);
            })
            .catch(function (err) {
                if (err && err.fromServer) {
                    showVerificationError(err.message || 'Invalid token', $container);
                } else {
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
     * Show verification error — uses the same .ffc-certificate-preview
     * card structure as the success path so the visual identity stays
     * consistent across success / error states (6.7.4). The red-gradient
     * header + the .ffc-status-badge.error pill live in CSS so the
     * markup here is just structural.
     */
    function showVerificationError(message, $container) {
        var s = (ffc_ajax && ffc_ajax.strings) || {};
        // `.ffc-verification-error` kept as secondary class so the
        // 4 pre-6.7.4 `frontend-extra.test.js` cases that query it
        // (and any 3rd-party CSS hooking it) keep working.
        var html = '<div class="ffc-certificate-preview ffc-error ffc-verification-error">';
        html += '<div class="ffc-preview-header">';
        html += '<span class="ffc-status-badge error ffc-icon-error">' + (s.certificateInvalid || 'Document Invalid') + '</span>';
        html += '</div>';
        html += '<div class="ffc-preview-body">';
        html += '<p class="ffc-error-message">' + message + '</p>';
        html += '<div class="ffc-manual-verification-form">';
        html += '<h4>' + (s.tryManually || 'Or try manual verification') + ':</h4>';
        html += '<input type="text" class="ffc-manual-auth-code" placeholder="' + (s.enterAuthCode || 'Enter validation code') + '">';
        html += '<button class="ffc-manual-verify-btn ffc-download-btn">' + (s.verify || 'Verify') + '</button>';
        html += '</div>';
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
        
        FFC.request('ffc_verify_certificate', {
            ffc_auth_code: authCode,
            ffc_captcha_ans: captchaAns,
            ffc_captcha_hash: captchaHash,
            ffc_honeypot_trap: honeypot
        })
            .then(function (data) {
                displayVerificationResult(data, $form.closest('.ffc-verification-container'));
            })
            .catch(function (err) {
                if (!(err && err.fromServer)) {
                    showAccessibleAlert(ffc_ajax.strings.connectionError || 'Connection error', $form);
                    return;
                }
                // Refresh captcha if the server signalled it.
                if (err.data && err.data.refresh_captcha) {
                    FFC.Frontend.UI.refreshCaptcha($form, err.data.new_label, err.data.new_hash);
                }
                // Show error inline without destroying the form.
                var errorMsg = err.message || (ffc_ajax.strings.error || 'Error');
                var $errorDiv = $form.find('.ffc-verify-error');
                if (!$errorDiv.length) {
                    $form.find('.ffc-form-field').first().before('<div class="ffc-verify-error"></div>');
                    $errorDiv = $form.find('.ffc-verify-error');
                }
                $errorDiv.html('').append($('<p class="ffc-message ffc-message-error">').text(errorMsg));
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
        // 6.6.2 (Sprint 4) — offline check. The PDF generation itself is
        // client-side and would technically work offline, but the certificate
        // background image is fetched from the server. Failing fast with a
        // clear message beats html2canvas rendering a half-broken canvas.
        if (typeof navigator !== 'undefined' && navigator.onLine === false) {
            showAccessibleAlert(ffc_ajax.strings.offlineMessage || 'You appear to be offline. Reconnect to the internet and try again.', $(this).parent());
            return;
        }
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
     * Detect the device family for the "where is my file" hint in the
     * success card. Mirrors the UA logic in ffc-pdf-generator.js (iPadOS
     * reports "Macintosh" but exposes maxTouchPoints > 1).
     *
     * @returns {'ios'|'android'|'desktop'}
     */
    function detectPlatform() {
        var ua = navigator.userAgent || '';
        if (/iPad|iPhone|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) {
            return 'ios';
        }
        if (/Android/i.test(ua)) {
            return 'android';
        }
        return 'desktop';
    }

    /**
     * Hide the platform-guidance lines that don't apply to the visitor's
     * device. The matching line stays visible.
     *
     * @param {jQuery} $form Form (or success card) container.
     */
    function filterPlatformGuidance($form) {
        var platform = detectPlatform();
        $form.find('.ffc-success-where-is-file li[data-platform]').each(function () {
            var $li = $(this);
            if ($li.attr('data-platform') !== platform) {
                $li.hide();
            }
        });
    }

    /**
     * Copy-to-clipboard handler for the success card (auth code + magic
     * link). Falls back to the legacy document.execCommand path on
     * browsers without async clipboard (older mobile Safari).
     */
    $(document).on('click', '.ffc-copy-btn', function () {
        var $btn = $(this);
        var text = $btn.attr('data-ffc-copy') || '';
        if (!text) {
            return;
        }
        var originalLabel = $btn.data('ffc-original-label');
        if (typeof originalLabel === 'undefined') {
            originalLabel = $btn.text();
            $btn.data('ffc-original-label', originalLabel);
        }
        var copiedLabel = (ffc_ajax.strings && ffc_ajax.strings.copied) || 'Copied!';
        var failedLabel = (ffc_ajax.strings && ffc_ajax.strings.copyFailed) || 'Could not copy';

        var showFeedback = function (ok) {
            $btn.text(ok ? copiedLabel : failedLabel);
            setTimeout(function () {
                $btn.text(originalLabel);
            }, 2000);
        };

        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(text).then(function () {
                showFeedback(true);
            }, function () {
                showFeedback(legacyClipboardCopy(text));
            });
            return;
        }
        showFeedback(legacyClipboardCopy(text));
    });

    function legacyClipboardCopy(text) {
        try {
            var $ta = $('<textarea>').css({ position: 'fixed', top: '-1000px', opacity: 0 }).val(text).appendTo('body');
            $ta[0].select();
            var ok = document.execCommand && document.execCommand('copy');
            $ta.remove();
            return !!ok;
        } catch (e) {
            return false;
        }
    }

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
            
            // 6.6.2 (Sprint 4) — offline detection. The AJAX would fail
            // with a generic connectionError anyway, but the user has no
            // way to act on that. Telling them "you appear to be offline"
            // points at the actual fix.
            if (typeof navigator !== 'undefined' && navigator.onLine === false) {
                showAccessibleAlert(ffc_ajax.strings.offlineMessage || 'You appear to be offline. Reconnect to the internet and try again.', $form);
                return;
            }

            if (!isValid) {
                showAccessibleAlert(ffc_ajax.strings.fillRequired || 'Please fill all required fields', $form);
                // Focus the first invalid field
                $form.find('.ffc-field-error').first().focus();
                return;
            }
            
            // Disable submit button
            $submitBtn.prop('disabled', true).text(ffc_ajax.strings.processing || 'Processing...');
            
            // Prepare form data (FFC.request appends action + nonce).
            var formData = $form.serialize();

            FFC.request('ffc_submit_form', formData)
                .then(function (data) {
                    if (!data) {
                        FFC.Frontend.UI.showFormError($form, ffc_ajax.strings.error || 'Error occurred');
                        $submitBtn.prop('disabled', false).text(originalBtnText);
                        return;
                    }
                    if (data.html) {
                        $form.html(data.html);
                        // Add PDF data to download button if available
                        if (data.pdf_data) {
                            var $downloadBtn = $form.find('.ffc-download-btn, .ffc-download-pdf-btn');
                            if ($downloadBtn.length) {
                                $downloadBtn.attr('data-pdf-data', JSON.stringify(data.pdf_data));
                            }
                        }
                        // Show only the platform-specific "where is my file"
                        // hint relevant to this device. The other lines stay
                        // in the DOM (so a forwarded link still renders them
                        // if JS is disabled), just hidden visually here.
                        filterPlatformGuidance($form);
                    } else {
                        FFC.Frontend.UI.showFormSuccess($form, '');
                    }
                    // Auto-download PDF if available
                    if (data.pdf_data && typeof window.ffcGeneratePDF === 'function') {
                        setTimeout(function () {
                            var filename = data.pdf_data.filename || 'certificate.pdf';
                            window.ffcGeneratePDF(data.pdf_data, filename);
                        }, 500);
                    }
                })
                .catch(function (err) {
                    // Rate-limit path (server returns 429 with rate_limit
                    // flag); the legacy code read it via xhr.responseJSON,
                    // which FFC.request doesn't surface for connection
                    // errors. Server-issued protocol errors carry err.data.
                    if (err && err.data && err.data.rate_limit) {
                        FFC.Frontend.RateLimit.show(err.data.message, err.data.wait_seconds);
                        $submitBtn.prop('disabled', false).text(originalBtnText);
                        return;
                    }
                    if (err && err.fromServer) {
                        FFC.Frontend.UI.showFormError($form, err.message || ffc_ajax.strings.error || 'Error occurred');
                        if (err.data && err.data.refresh_captcha) {
                            FFC.Frontend.UI.refreshCaptcha($form, err.data.new_label, err.data.new_hash);
                        }
                    } else {
                        showAccessibleAlert(ffc_ajax.strings.connectionError || 'Connection error', $form);
                    }
                    $submitBtn.prop('disabled', false).text(originalBtnText);
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

        // Auto-resize textareas on input
        $(document).on('input', '.ffc-textarea', function() {
            this.style.setProperty('height', 'auto', 'important');
            this.style.setProperty('height', this.scrollHeight + 'px', 'important');
        });
    });

    // console.log('[FFC Frontend] Script loaded v3.1.0 (cleaned up defensive code)');

})(jQuery);