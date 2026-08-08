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

    /**
     * Insert an HTML snippet at the cursor of the certificate-layout editor.
     *
     * Prefers the CodeMirror instance (replaceSelection at the caret so the
     * image lands where the user is typing); falls back to appending to the raw
     * textarea via setLayoutContent when CodeMirror hasn't mounted (JS-lite,
     * tests) so the value still updates.
     *
     * @param {jQuery} $textarea The #ffc_pdf_layout jQuery node.
     * @param {string} snippet   HTML to insert.
     */
    function insertAtCursor($textarea, snippet) {
        var $cm = $textarea.nextAll('.CodeMirror').first();
        if ($cm.length && $cm[0].CodeMirror && typeof $cm[0].CodeMirror.replaceSelection === 'function') {
            var cm = $cm[0].CodeMirror;
            cm.replaceSelection(snippet);
            cm.save();
            $textarea.trigger('change');
            cm.focus();
            return;
        }
        setLayoutContent($textarea, ($textarea.val() || '') + snippet);
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
        var defaultBadge = strings.templateDefaultBadge || 'Default template';
        var customBadge = strings.templateCustomBadge || 'My template';
        templates.forEach(function(template) {
            var $opt = $(
                '<div class="ffc-template-option" style="padding:15px;margin:10px 0;border:2px solid #ddd;border-radius:4px;cursor:pointer;transition:all 0.2s;">' +
                    '<strong style="font-size:16px;"></strong>' +
                    '<div style="color:#666;font-size:13px;margin-top:5px;"></div>' +
                '</div>'
            );
            // Templates are addressed by DB post id (#865). Legacy html/ glob
            // fallback entries carry id 0 + a `file` basename; both attributes
            // are stored so loadTemplateFile can pick the right POST param.
            $opt.attr('data-id', (template.id != null) ? template.id : 0);
            $opt.attr('data-file', template.file || '');
            $opt.find('strong').text(template.label);
            $opt.find('div').text(template.is_default ? defaultBadge : customBadge);
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
            var templateId = $(this).data('id');
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

            loadTemplateFile(templateId, templateFile, templateName);
        });
    });

    // Load a certificate template into the layout editor.
    //
    // Primary path (#865): POST ffc_load_template with the DB pool post id
    // (template_id) — the server resolves the HTML via CertTemplateReader.
    // When the id is 0 (a legacy html/ glob fallback entry) it posts the
    // `filename` param instead, which the handler serves from html/ as a
    // deprecated shim (#865 phase-4). Replaces the old direct fetch() of
    // /wp-content/plugins/.../html/<file>, so template resolution is now
    // gated by the nonce + capability check server-side.
    function loadTemplateFile(templateId, filename, displayName) {
        var showNotification = window.FFC.Admin.showNotification || function() {};
        var ajaxData = (typeof ffc_ajax !== 'undefined') ? ffc_ajax : {};
        var strings = ajaxData.strings || {};
        var ajaxUrl = ajaxData.ajax_url || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');

        // Show loading notification
        var loadingText = strings.loadingTemplate || 'Loading template...';
        showNotification(loadingText, 'info', 0);

        var postData = { action: 'ffc_load_template', nonce: ajaxData.nonce || '' };
        templateId = parseInt(templateId, 10) || 0;
        if (templateId > 0) {
            postData.template_id = templateId;
        } else {
            postData.filename = filename || '';
        }

        $.post(ajaxUrl, postData)
            .done(function(response) {
                if (response && response.success) {
                    var $htmlField = $('#ffc_pdf_layout');
                    if ($htmlField.length) {
                        // #865: the load response is a structured payload
                        // { html, bg_image }. Tolerate the legacy bare-string
                        // shape (older responses) by using response.data as-is.
                        var data = response.data;
                        var html = (data && typeof data === 'object') ? (data.html || '') : data;
                        setLayoutContent($htmlField, html);
                        // Carry the template's background image into the form's
                        // Background Image URL field so a loaded template brings
                        // its background with it (empty clears any prior value).
                        if (data && typeof data === 'object' && 'bg_image' in data) {
                            $('#ffc_bg_image_input').val(data.bg_image || '').trigger('change');
                        }
                        var successTemplate = strings.templateLoadedSuccess || 'Template "%s" loaded successfully!';
                        var successMsg = successTemplate.replace('%s', displayName || filename || '');
                        showNotification('✓ ' + successMsg, 'success', 3000);
                    } else {
                        var fieldMsg = strings.htmlFieldNotFound || 'HTML field not found.';
                        showNotification('✗ ' + fieldMsg, 'error');
                    }
                } else {
                    var notFoundMsg = strings.templateFileNotFound || 'Template file not found. Check if file exists in html/ folder.';
                    showNotification('✗ ' + notFoundMsg, 'error', 8000);
                }
            })
            .fail(function(jqXHR) {
                var errorMsg;
                if (jqXHR && jqXHR.status === 403) {
                    errorMsg = strings.accessDenied || 'Access denied. Check file permissions.';
                } else if (jqXHR && jqXHR.status === 404) {
                    errorMsg = strings.templateFileNotFound || 'Template file not found. Check if file exists in html/ folder.';
                } else {
                    errorMsg = strings.networkError || 'Network error. Check your connection.';
                }
                showNotification('✗ ' + errorMsg, 'error', 8000);
            });
    }

    // Save the current layout HTML as a new template in the DB pool (#865).
    $(document).on('click', '#ffc_save_as_model_btn', function(e) {
        e.preventDefault();
        var showNotification = window.FFC.Admin.showNotification || function() {};
        var ajaxData = (typeof ffc_ajax !== 'undefined') ? ffc_ajax : {};
        var strings = ajaxData.strings || {};

        // Flush CodeMirror into the textarea before reading (mirrors preview).
        var $layout = $('#ffc_pdf_layout');
        var $cm = $layout.nextAll('.CodeMirror').first();
        if ($cm.length && $cm[0].CodeMirror && typeof $cm[0].CodeMirror.save === 'function') {
            $cm[0].CodeMirror.save();
        }
        var html = $layout.val();
        if (!html || !html.trim()) {
            alert(strings.previewEmpty || 'The HTML editor is empty. Add a template first.');
            return;
        }

        var title = window.prompt(strings.saveModelPrompt || 'Name for this template:', '');
        if (title === null) {
            return; // cancelled
        }
        title = title.trim();
        if (!title) {
            return;
        }

        showNotification(strings.loading || 'Loading...', 'info', 0);
        $.post(ajaxData.ajax_url || (typeof ajaxurl !== 'undefined' ? ajaxurl : ''), {
            action: 'ffc_save_template',
            nonce: ajaxData.nonce || '',
            title: title,
            html: html,
            // Round-trip the current background so a saved model carries it back
            // when re-loaded (#865).
            bg_image: $('#ffc_bg_image_input').val() || ''
        })
            .done(function(response) {
                if (response && response.success) {
                    showNotification('✓ ' + (strings.templateSaved || 'Template saved!'), 'success', 3000);
                } else {
                    showNotification('✗ ' + (strings.error || 'Error: '), 'error', 8000);
                }
            })
            .fail(function() {
                showNotification('✗ ' + (strings.connectionError || 'Connection error.'), 'error', 8000);
            });
    });

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
    // MEDIA LIBRARY - Inline Image Insert (#865 Phase 4)
    // ==========================================================================
    //
    // Separate cached frame from the background picker above: this one's
    // `select` handler inserts an <img> into the layout editor rather than
    // writing the bg_image URL field, so the two must not share state.

    var imageInserter;

    $(document).on('click', '#ffc_btn_insert_image', function(e) {
        e.preventDefault();
        var showNotification = window.FFC.Admin.showNotification || function() {};
        var strings = (typeof ffc_ajax !== 'undefined' && ffc_ajax.strings) ? ffc_ajax.strings : {};

        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
            showNotification(strings.wpMediaNotAvailable || 'WordPress Media Library is not available. Please reload the page.', 'error');
            return;
        }

        if (imageInserter) {
            imageInserter.open();
            return;
        }

        imageInserter = wp.media({
            title: strings.insertImageTitle || 'Insert Image',
            button: { text: strings.insertImageButton || 'Insert into layout' },
            library: { type: 'image' },
            multiple: false
        });

        imageInserter.on('select', function() {
            var attachment = imageInserter.state().get('selection').first().toJSON();
            var url = attachment.url || '';
            if (!url) {
                return;
            }

            // Resolve the active certificate-HTML editor: the form editor uses
            // #ffc_pdf_layout, the cert-template edit screen uses
            // #ffc_template_html. Whichever is present on this screen wins.
            var $textarea = $('#ffc_pdf_layout, #ffc_template_html').first();
            if (!$textarea.length) {
                showNotification(strings.htmlTextareaNotFound || 'Error: HTML textarea not found', 'error');
                return;
            }

            // Media Library URLs are Media-Library-hosted (update-safe), so the
            // <img> the picker inserts never trips the save-time html/ linter.
            var alt = (attachment.alt || '').replace(/"/g, '&quot;');
            var tag = '<img src="' + url + '"' + (alt ? ' alt="' + alt + '"' : '') + ' />';
            insertAtCursor($textarea, tag);

            showNotification(strings.imageInserted || 'Image inserted into the layout.', 'success');
        });

        imageInserter.open();
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
