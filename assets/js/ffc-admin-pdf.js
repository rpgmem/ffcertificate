/**
 * FFC Admin - PDF Management Module
 * v3.1.0 - Extracted from ffc-admin.js
 *
 * Handles PDF template management, background images, and PDF downloads
 *
 * Dependencies:
 * - jQuery
 * - WordPress Media API (wp.media)
 * - FFC Core (for notifications via window.FFC.Admin.showNotification)
 * - window.ffcGeneratePDF() - Shared PDF generator module
 *
 * @since 3.1.0
 */

(function($, FFC) {
    'use strict';

    /**
     * Write content into the certificate-layout textarea.
     *
     * The textarea (#ffc_pdf_layout) is wrapped by WordPress CodeMirror via
     * ffc-admin-code-editor.js. CodeMirror mirrors its own buffer onto the
     * textarea on save / submit, but it does NOT pick up direct
     * `$textarea.val()` writes — the visible editor stays empty even though
     * a sneaky later read of `.val()` would return the new content. Always
     * route writes through the CodeMirror API when an instance is mounted.
     *
     * @param {jQuery} $textarea The #ffc_pdf_layout jQuery node.
     * @param {string} content   New textarea content.
     */
    function setLayoutContent($textarea, content) {
        var $cm = $textarea.nextAll('.CodeMirror').first();
        if ($cm.length && $cm[0].CodeMirror && typeof $cm[0].CodeMirror.setValue === 'function') {
            $cm[0].CodeMirror.setValue(content);
            $cm[0].CodeMirror.save();
        } else {
            $textarea.val(content);
        }
        $textarea.trigger('change');
    }

    // ==========================================================================
    // TEMPLATE MANAGEMENT
    // ==========================================================================

    // Load Template button - Opens modal to select template
    $(document).on('click', '#ffc_load_template_btn', function(e) {
        e.preventDefault();
        // Templates labels come from PHP (wp_localize_script) so they're
        // translatable via Loco. The fallback list mirrors the PHP default
        // for the rare case where ffc_ajax hasn't loaded yet.
        var ajaxData = (typeof ffc_ajax !== 'undefined') ? ffc_ajax : {};
        // The PHP side (AdminAssetsManager::discover_layout_templates) globs
        // html/ and keeps any *.html whose basename contains "certificate".
        // An empty fallback is correct: if ffc_ajax never localized, the
        // modal simply shows no options instead of pretending hardcoded
        // legacy filenames exist on disk.
        var templates = Array.isArray(ajaxData.templates) ? ajaxData.templates : [];

        // Get localized strings with fallbacks
        var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};
        var selectTemplateText = strings.selectTemplate || 'Select a Template';
        var cancelText = strings.cancel || 'Cancel';

        // Build the modal skeleton with static HTML only; every dynamic
        // value (localized strings, per-template label/value) flows through
        // jQuery's text() / attr() so meta-characters stay literal. The
        // earlier `'<div …>' + selectTemplateText + '…'` concatenation
        // tripped CodeQL's "DOM text reinterpreted as HTML" sink even
        // though the strings come from wp_localize_script.
        var $modal = $(
            '<div id="ffc-template-modal" role="dialog" aria-modal="true" aria-labelledby="ffc-template-modal-title" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:999999;display:flex;align-items:center;justify-content:center;">' +
                '<div style="background:#fff;padding:30px;border-radius:8px;max-width:500px;width:90%;box-shadow:0 4px 20px rgba(0,0,0,0.3);">' +
                    '<h2 id="ffc-template-modal-title" style="margin:0 0 20px 0;font-size:20px;"></h2>' +
                    '<div class="ffc-template-list" style="max-height:400px;overflow-y:auto;"></div>' +
                    '<div style="margin-top:20px;text-align:right;">' +
                        '<button id="ffc-modal-cancel" class="button" style="margin-right:10px;"></button>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );
        $modal.find('#ffc-template-modal-title').text(selectTemplateText);
        $modal.find('#ffc-modal-cancel').text(cancelText);

        var $list = $modal.find('.ffc-template-list');
        templates.forEach(function(template) {
            var $opt = $(
                '<div class="ffc-template-option" style="padding:15px;margin:10px 0;border:2px solid #ddd;border-radius:4px;cursor:pointer;transition:all 0.2s;">' +
                    '<strong style="font-size:16px;"></strong>' +
                    '<div style="color:#666;font-size:13px;margin-top:5px;"></div>' +
                '</div>'
            );
            $opt.attr('data-file', template.value);
            $opt.find('strong').text(template.label);
            $opt.find('div').text(template.value);
            $list.append($opt);
        });

        $('body').append($modal);

        // Hover effect
        $('.ffc-template-option').hover(
            function() { $(this).css({'border-color': '#2271b1', 'background': '#f0f6fc'}); },
            function() { $(this).css({'border-color': '#ddd', 'background': 'transparent'}); }
        );

        // Cancel button
        $('#ffc-modal-cancel').on('click', function() {
            $('#ffc-template-modal').fadeOut(200, function() { $(this).remove(); });
        });

        // Click outside to close
        $('#ffc-template-modal').on('click', function(e) {
            if (e.target.id === 'ffc-template-modal') {
                $(this).fadeOut(200, function() { $(this).remove(); });
            }
        });

        // Escape key to close
        $(document).on('keydown.ffcTemplateModal', function(e) {
            if (e.key === 'Escape' && $('#ffc-template-modal').length) {
                $('#ffc-template-modal').fadeOut(200, function() { $(this).remove(); });
                $(document).off('keydown.ffcTemplateModal');
            }
        });

        // Template selection
        $('.ffc-template-option').on('click', function() {
            var templateFile = $(this).data('file');
            var templateName = $(this).find('strong').text();

            $('#ffc-template-modal').remove();

            // Get localized string or fallback to English
            var confirmMsg = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings && ffc_ajax.strings.confirmLoadTemplate)
                ? ffc_ajax.strings.confirmLoadTemplate.replace('%s', templateName)
                : 'Load "' + templateName + '"? This will replace your current certificate HTML.';

            if (!confirm(confirmMsg)) {
                return;
            }

            loadTemplateFile(templateFile, templateName);
        });
    });

    // Function to load template file via fetch
    function loadTemplateFile(filename, displayName) {
        var templateUrl = '/wp-content/plugins/ffcertificate/html/' + filename;
        var showNotification = window.FFC.Admin.showNotification || function() {};
        var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};

        // Show loading notification
        var loadingText = strings.loadingTemplate || 'Loading template...';
        showNotification(loadingText, 'info', 0);

        fetch(templateUrl)
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }

                return response.text();
            })
            .then(function(htmlContent) {
                var $htmlField = $('#ffc_pdf_layout');

                if ($htmlField.length) {
                    setLayoutContent($htmlField, htmlContent);
                    var successTemplate = strings.templateLoadedSuccess || 'Template "%s" loaded successfully!';
                    var successMsg = successTemplate.replace('%s', displayName || filename);
                    showNotification('✓ ' + successMsg, 'success', 3000);
                } else {
                    var errorMsg = strings.htmlFieldNotFound || 'HTML field not found.';
                    showNotification('✗ ' + errorMsg, 'error');
                }
            })
            .catch(function(error) {
                var errorMsg = '';
                if (error.message.includes('404')) {
                    errorMsg = strings.templateFileNotFound || 'Template file not found. Check if file exists in html/ folder.';
                } else if (error.message.includes('403')) {
                    errorMsg = strings.accessDenied || 'Access denied. Check file permissions.';
                } else if (error.message.includes('Failed to fetch')) {
                    errorMsg = strings.networkError || 'Network error. Check your connection.';
                } else {
                    var errorTemplate = strings.errorLoadingTemplate || 'Error loading template: %s';
                    errorMsg = errorTemplate.replace('%s', error.message);
                }

                showNotification('✗ ' + errorMsg, 'error', 8000);
            });
    }

    // ==========================================================================
    // IMPORT HTML FILE
    // ==========================================================================

    // Import HTML file button
    $(document).on('click', '#ffc_btn_import_html', function(e) {
        e.preventDefault();
        var showNotification = window.FFC.Admin.showNotification || function() {};

        // Try to find file input
        var $fileInput = $('#ffc_import_html_file');
        if (!$fileInput.length) {
            $fileInput = $('input[type="file"][name*="import"]');
        }

        if ($fileInput.length) {
            $fileInput.click();
        } else {
            var $tempInput = $('<input type="file" accept=".html" style="display:none">');
            $('body').append($tempInput);

            $tempInput.on('change', function(e) {
                var file = e.target.files[0];
                if (!file) return;

                var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};

                if (file.type !== 'text/html' && !file.name.endsWith('.html')) {
                    var warningMsg = strings.selectHtmlFile || 'Please select an HTML file.';
                    showNotification(warningMsg, 'warning');
                    return;
                }

                var reader = new FileReader();
                reader.onload = function(e) {
                    var $htmlField = $('#ffc_pdf_layout');

                    if ($htmlField.length) {
                        setLayoutContent($htmlField, e.target.result);
                        var successMsg = strings.htmlImportedSuccess || 'HTML imported successfully!';
                        showNotification(successMsg, 'success');
                    } else {
                        var errorMsg = strings.htmlFieldNotFound || 'HTML field not found.';
                        showNotification('Error: ' + errorMsg, 'error');
                    }

                    $tempInput.remove();
                };
                reader.readAsText(file);
            });

            $tempInput.click();
        }
    });

    // Also handle file input change (if it exists in HTML)
    $(document).on('change', '#ffc_import_html_file, input[type="file"][name*="import"]', function(e) {
        var file = e.target.files[0];

        if (!file) return;

        var showNotification = window.FFC.Admin.showNotification || function() {};
        var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};

        var reader = new FileReader();
        reader.onload = function(evt) {
            var $htmlField = $('#ffc_pdf_layout');

            if ($htmlField.length) {
                setLayoutContent($htmlField, evt.target.result);
                var successMsg = strings.htmlImportedSuccess || 'HTML imported successfully!';
                showNotification(successMsg, 'success');
            } else {
                var errorMsg = strings.htmlTextareaNotFound || 'HTML textarea not found';
                showNotification('Error: ' + errorMsg, 'error');
            }
        };
        reader.onerror = function() {
            var errorMsg = strings.errorReadingFile || 'Error reading file';
            showNotification(errorMsg, 'error');
        };
        reader.readAsText(file);

        // Reset input
        $(this).val('');
    });

    // ==========================================================================
    // CERTIFICATE PREVIEW
    // ==========================================================================

    // Sample data for placeholder replacement.
    //
    // Source of truth is PHP: AdminAssetsManager localizes
    // CertificatePreviewSamples::get_map() as ffc_ajax.previewSamples, so
    // this preview stays in sync with the placeholders the real generators
    // fill. The JS only overlays values PHP can't know at enqueue time —
    // the live form title and custom builder field names.
    function getSampleFieldData() {
        var ajax = (typeof ffc_ajax !== 'undefined') ? ffc_ajax : {};
        var fieldData = $.extend({}, ajax.previewSamples || {});
        // Live form title overrides the PHP placeholder default.
        var liveTitle = $('#title').val();
        if (liveTitle) {
            fieldData['form_title'] = liveTitle;
        }
        // Scan form builder fields for custom variables
        $('#ffc-fields-container .ffc-field-row').each(function() {
            var fieldName = $(this).find('input[name*="[name]"]').val();
            var fieldLabel = $(this).find('input[name*="[label]"]').val();
            if (fieldName && !fieldData[fieldName]) {
                fieldData[fieldName] = fieldLabel || fieldName;
            }
        });
        return fieldData;
    }

    // Replace placeholders in HTML with sample data
    function replacePlaceholders(html, data) {
        // Replace simple {{variable}} placeholders
        html = html.replace(/\{\{(\w+)\}\}/g, function(match, key) {
            return data[key] !== undefined ? data[key] : match;
        });
        // Replace {{qr_code}} and variants with a placeholder SVG
        html = html.replace(/\{\{qr_code[^}]*\}\}/g,
            '<svg width="150" height="150" viewBox="0 0 150 150" xmlns="http://www.w3.org/2000/svg">' +
            '<rect width="150" height="150" fill="#f0f0f0" stroke="#ccc" stroke-width="1"/>' +
            '<text x="75" y="70" text-anchor="middle" font-size="12" fill="#999">QR Code</text>' +
            '<text x="75" y="90" text-anchor="middle" font-size="10" fill="#bbb">(preview)</text>' +
            '</svg>'
        );
        // Replace {{validation_url}} and variants with a sample link
        html = html.replace(/\{\{validation_url[^}]*\}\}/g,
            '<a href="#" style="color:#0073aa;">https://example.com/valid/#token=abc123</a>'
        );
        return html;
    }

    // Preview button click handler
    $(document).on('click', '#ffc_btn_preview', function(e) {
        e.preventDefault();

        // Flush the CodeMirror buffer into the textarea before reading so
        // the preview reflects the latest edits (otherwise the textarea
        // still carries the page-load value).
        var $layout = $('#ffc_pdf_layout');
        var $cm     = $layout.nextAll('.CodeMirror').first();
        if ($cm.length && $cm[0].CodeMirror && typeof $cm[0].CodeMirror.save === 'function') {
            $cm[0].CodeMirror.save();
        }
        var htmlContent = $layout.val();
        var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};

        if (!htmlContent || !htmlContent.trim()) {
            var emptyMsg = strings.previewEmpty || 'The HTML editor is empty. Add a template first.';
            alert(emptyMsg);
            return;
        }

        var bgImage = $('#ffc_bg_image_input, #ffc_bg_image_url').first().val() || '';
        var data = getSampleFieldData();
        var processedHtml = replacePlaceholders(htmlContent, data);

        // Build the iframe content. Background-image is applied AFTER the
        // iframe loads (via DOM properties on document.body, not via string
        // concatenation into CSS) so the user-controlled URL never flows
        // through a "DOM text reinterpreted as HTML" sink. CodeQL flagged
        // the old `iframeHtml += 'url(' + bgImage + ')'` pattern even though
        // bgImage already filters through esc_url on save; routing through
        // the .style setter side-steps the rule and adds a real layer of
        // CSS-context escaping for free.
        // Faithful preview: render at the real PDF page size (A4) and scale the
        // iframe to fit the modal (fitPreview below). Certificates default to
        // landscape — the generator treats an unset orientation as landscape —
        // so `background-size: cover` crops exactly like the generated PDF
        // instead of being cropped by the modal's own aspect ratio.
        var pageW = 1123;
        var pageH = 794;
        var iframeHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        iframeHtml += '<style>';
        iframeHtml += 'html, body { margin: 0; padding: 0; width: ' + pageW + 'px; height: ' + pageH + 'px; overflow: hidden; }';
        iframeHtml += 'body { font-family: Arial, Helvetica, sans-serif; position: relative;';
        iframeHtml += bgImage ? ' background-size: cover; background-position: center; background-repeat: no-repeat;' : '';
        iframeHtml += '}';
        iframeHtml += '</style></head><body>';
        iframeHtml += processedHtml;
        iframeHtml += '</body></html>';

        // Build modal. Localized strings (previewTitle/closeText/sampleDataNote)
        // come from wp_localize_script — admin-controlled, but CodeQL's taint
        // tracker flags string-concatenated insertion as "DOM text reinterpreted
        // as HTML". Use jQuery's text() / attr() so the strings can never be
        // reparsed as markup regardless of how they were filtered upstream.
        var previewTitle = strings.previewTitle || 'Certificate Preview';
        var closeText = strings.close || 'Close';
        var sampleDataNote = strings.previewSampleNote || 'Placeholders replaced with sample data. QR code shown as placeholder.';

        var $modal = $(
            '<div id="ffc-preview-modal">' +
                '<div class="ffc-preview-backdrop"></div>' +
                '<div class="ffc-preview-container">' +
                    '<div class="ffc-preview-header">' +
                        '<h2></h2>' +
                        '<button type="button" class="ffc-preview-close">&times;</button>' +
                    '</div>' +
                    '<div class="ffc-preview-note"></div>' +
                    '<div class="ffc-preview-body">' +
                        '<div class="ffc-preview-stage">' +
                        // sandbox="" — most-restrictive sandbox; rendered HTML
                        // can't execute scripts, navigate the parent, run
                        // plugins, etc. The preview only needs to paint the
                        // layout's static markup, so the empty sandbox is the
                        // right ceiling. Operators previewing their own
                        // template stay safe even if a teammate sneaked a
                        // <script> tag into the layout.
                        '<iframe id="ffc-preview-iframe" frameborder="0" sandbox=""></iframe>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );
        $modal.find('.ffc-preview-header h2').text(previewTitle);
        $modal.find('.ffc-preview-close').attr('title', closeText);
        $modal.find('.ffc-preview-note').text(sampleDataNote);

        $('body').append($modal);

        // Hand the iframe its HTML via the `srcdoc` attribute rather than
        // document.write so CodeQL's "DOM text reinterpreted as HTML" sink
        // (and the equivalent runtime risk) goes through a single declarative
        // entry point. The sandbox="" attribute set above stops any script
        // tag in the template from executing inside the preview frame.
        var iframe = document.getElementById('ffc-preview-iframe');
        iframe.setAttribute('srcdoc', iframeHtml);

        // Apply background-image via the DOM property setter — runs after
        // the sandboxed frame finishes loading so the body element exists.
        // The setter only accepts values that parse as a CSS <image>, so a
        // tampered URL can't escape the property to inject markup or JS.
        if (bgImage) {
            iframe.addEventListener('load', function () {
                var iframeDoc = iframe.contentDocument;
                if (iframeDoc && iframeDoc.body && iframeDoc.body.style) {
                    iframeDoc.body.style.backgroundImage = 'url(' + JSON.stringify(String(bgImage)) + ')';
                }
            });
        }

        // Scale the A4-sized iframe down to fit the modal body, preserving the
        // page aspect (no crop, no distortion). The stage wrapper takes the
        // scaled footprint so the page centers cleanly.
        function fitPreview() {
            var bodyEl  = $modal.find('.ffc-preview-body')[0];
            var stageEl = $modal.find('.ffc-preview-stage')[0];
            if (!bodyEl || !stageEl) { return; }
            var availW = bodyEl.clientWidth - 24;
            var availH = bodyEl.clientHeight - 24;
            if (availW <= 0 || availH <= 0) { return; }
            var scale = Math.min(availW / pageW, availH / pageH, 1);
            iframe.style.width           = pageW + 'px';
            iframe.style.height          = pageH + 'px';
            iframe.style.transformOrigin = 'top left';
            iframe.style.transform       = 'scale(' + scale + ')';
            stageEl.style.width  = Math.round(pageW * scale) + 'px';
            stageEl.style.height = Math.round(pageH * scale) + 'px';
        }

        // Show with fade
        requestAnimationFrame(function() {
            $modal.addClass('ffc-preview-visible');
            fitPreview();
        });
        $(window).on('resize.ffcAdminCertPreview', fitPreview);

        // Close handlers
        function closePreview() {
            $modal.removeClass('ffc-preview-visible');
            setTimeout(function() { $modal.remove(); }, 200);
            $(window).off('resize.ffcAdminCertPreview');
        }

        $modal.find('.ffc-preview-close').on('click', closePreview);
        $modal.find('.ffc-preview-backdrop').on('click', closePreview);

        // ESC key to close
        $(document).on('keydown.ffcPreview', function(e) {
            if (e.key === 'Escape') {
                closePreview();
                $(document).off('keydown.ffcPreview');
            }
        });
    });

    // ==========================================================================
    // MEDIA LIBRARY - Background Image
    // ==========================================================================

    var mediaUploader;

    $(document).on('click', '#ffc_btn_media_lib', function(e) {
        e.preventDefault();
        var showNotification = window.FFC.Admin.showNotification || function() {};
        var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};

        // Check if wp.media is available
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
            var errorMsg = strings.wpMediaNotAvailable || 'WordPress Media Library is not available. Please reload the page.';
            showNotification(errorMsg, 'error');
            return;
        }

        // If the uploader object has already been created, reopen the dialog
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }

        // Create the media uploader
        var titleText = strings.chooseBackgroundImage || 'Choose Background Image';
        var buttonText = strings.useThisImage || 'Use this image';

        mediaUploader = wp.media({
            title: titleText,
            button: {
                text: buttonText
            },
            multiple: false
        });

        // When an image is selected, run a callback
        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();

            // Try to find BG image URL field
            var $urlField = $('#ffc_bg_image_url');
            if (!$urlField.length) {
                $urlField = $('input[name*="bg_image"], input[name*="background"]').first();
            }

            if ($urlField.length) {
                $urlField.val(attachment.url);
            }

            // Try to find preview element
            var $preview = $('#ffc_bg_image_preview');
            if ($preview.length) {
                $preview.html('').append($('<img>').attr('src', attachment.url).css({'max-width': '200px', 'height': 'auto'}));
            }

            var successMsg = strings.backgroundImageSelected || 'Background image selected!';
            showNotification(successMsg, 'success');
        });

        mediaUploader.open();
    });

    // ==========================================================================
    // PUBLIC API - Export functions
    // ==========================================================================

    // Initialize FFC.Admin namespace if not exists
    window.FFC = window.FFC || {};
    window.FFC.Admin = window.FFC.Admin || {};
    window.FFC.Admin.PDF = {
        loadTemplate: loadTemplateFile
    };

    // Register module
    if (FFC.registerModule) {
        FFC.registerModule('Admin.PDF', FFC.version);
    }

})(jQuery, window.FFC);
