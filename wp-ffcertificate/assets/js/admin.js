jQuery(document).ready(function($) {

    // =========================================================================
    // 1. UPLOAD LOCAL FILE (FileReader)
    // =========================================================================
    $('#ffc_btn_import_html').on('click', function(e) {
        e.preventDefault();
        $('#ffc_import_html_file').trigger('click');
    });

    $('#ffc_import_html_file').on('change', function(e) {
        var file = e.target.files[0];
        if (!file) return;

        var reader = new FileReader();
        reader.onload = function(e) {
            $('#ffc_pdf_layout').val(e.target.result);
            $('#ffc_import_html_file').val('');
            alert(ffc_admin_ajax.strings.fileImported);
        };
        reader.onerror = function() { alert(ffc_admin_ajax.strings.errorReadingFile); };
        reader.readAsText(file);
    });

    // =========================================================================
    // 2. LOAD SERVER TEMPLATE (AJAX) - NEW
    // =========================================================================
    $('#ffc_load_template_btn').on('click', function(e) {
        e.preventDefault();
        
        var filename = $('#ffc_template_select').val();
        var $btn = $(this);

        if (!filename) {
            alert(ffc_admin_ajax.strings.selectTemplate);
            return;
        }

        if (!confirm(ffc_admin_ajax.strings.confirmReplaceContent)) {
            return;
        }

        if (typeof ffc_admin_ajax === 'undefined') {
            alert(ffc_admin_ajax.strings.errorJsVarsNotLoaded);
            return;
        }

        $btn.prop('disabled', true).text(ffc_admin_ajax.strings.loading);

        $.ajax({
            url: ffc_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ffc_load_template',
                filename: filename,
                nonce: ffc_admin_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#ffc_pdf_layout').val(response.data);
                    alert(ffc_admin_ajax.strings.templateLoaded);
                } else {
                    alert(ffc_admin_ajax.strings.error + response.data);
                }
            },
            error: function() {
                alert(ffc_admin_ajax.strings.connectionError);
            },
            complete: function() {
                $btn.prop('disabled', false).text(ffc_admin_ajax.strings.loadTemplate);
            }
        });
    });

    // =========================================================================
    // 3. MEDIA LIBRARY (BACKGROUND IMAGE)
    // =========================================================================
    var mediaUploader;
    $('#ffc_btn_media_lib').on('click', function(e) {
        e.preventDefault();
        if (mediaUploader) { mediaUploader.open(); return; }
        
        mediaUploader = wp.media.frames.file_frame = wp.media({
            title: ffc_admin_ajax.strings.selectBackgroundImage,
            button: { text: ffc_admin_ajax.strings.useImage },
            multiple: false
        });
        
        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#ffc_bg_image_input').val(attachment.url);
        });
        
        mediaUploader.open();
    });

    // =========================================================================
    // 4. GENERATE RANDOM CODES (TICKETS)
    // =========================================================================
    $('#ffc_btn_generate_codes').on('click', function(e) {
        e.preventDefault();
        
        var qty = $('#ffc_qty_codes').val();
        var $btn = $(this);
        var $textarea = $('#ffc_generated_list');
        var $status = $('#ffc_gen_status');
        
        if (typeof ffc_admin_ajax === 'undefined') { return; }
        if(qty < 1) return;

        $btn.prop('disabled', true);
        $status.text(ffc_admin_ajax.strings.generating);
        
        $.ajax({
            url: ffc_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ffc_generate_codes',
                qty: qty,
                nonce: ffc_admin_ajax.nonce
            },
            success: function(response) {
                if(response.success) {
                    var currentVal = $textarea.val();
                    var sep = (currentVal.length > 0 && !currentVal.endsWith('\n')) ? "\n" : "";
                    $textarea.val(currentVal + sep + response.data.codes);
                    $status.text(qty + ' ' + ffc_admin_ajax.strings.codesGenerated);
                } else {
                    $status.text(ffc_admin_ajax.strings.errorGeneratingCodes);
                }
            },
            complete: function() {
                $btn.prop('disabled', false);
            },
            error: function() {
                $status.text(ffc_admin_ajax.strings.connectionError);
                $btn.prop('disabled', false);
            }
        });
    });

    // =========================================================================
    // 5. ADMIN PDF DOWNLOAD (LIST TABLE)
    // =========================================================================
    $(document).on('click', '.ffc-admin-pdf-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var id = $btn.data('id');
        var originalText = $btn.text();
        
        if(typeof ffc_admin_ajax === 'undefined') return;

        $btn.text('⏳').prop('disabled', true);

        $.ajax({
            url: ffc_admin_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ffc_admin_get_pdf_data',
                submission_id: id,
                nonce: ffc_admin_ajax.nonce
            },
            success: function(response) {
                if(response.success) {
                    if (typeof window.generateCertificate === 'function') {
                        setTimeout(function() {
                            window.generateCertificate(response.data);
                        }, 100);
                    } else {
                        alert(ffc_admin_ajax.strings.errorPdfLibraryNotLoaded);
                    }
                } else {
                    alert(ffc_admin_ajax.strings.errorFetchingData + (response.data ? response.data.message : ffc_admin_ajax.strings.unknown));
                }
            },
            error: function() { alert(ffc_admin_ajax.strings.connectionError); },
            complete: function() { $btn.prop('disabled', false).text(originalText); }
        });
    });

    // =========================================================================
    // 6. FORM BUILDER (Drag & Drop, Add/Remove)
    // =========================================================================
    function ffc_reindex_fields() {
        $('#ffc-fields-container').children('.ffc-field-row:not(.ffc-field-template)').each(function(index) {
            $(this).find('input, select, textarea').each(function() {
                const name = $(this).attr('name');
                if (name) {
                    const newName = name.replace(/ffc_fields\[.*?\]/, 'ffc_fields[' + index + ']');
                    $(this).attr('name', newName);
                }
            });
        });
    }

    if ($.fn.sortable) {
        $('#ffc-fields-container').sortable({
            handle: '.ffc-sort-handle',
            update: function(event, ui) { ffc_reindex_fields(); }
        });
    }

    $('.ffc-add-field').on('click', function(e) {
        e.preventDefault();
        const $template = $('.ffc-field-template');
        const $newRow = $template.clone();
        $newRow.removeClass('ffc-field-template').show();
        $newRow.find('input, select').val(''); 
        $newRow.find('.ffc-field-type-select').val('text');
        $newRow.find('input[type="checkbox"]').prop('checked', false);
        $newRow.find('.ffc-options-field').hide();
        $('#ffc-fields-container').append($newRow);
        ffc_reindex_fields(); 
    });

    $('#ffc-fields-container').on('click', '.ffc-remove-field', function(e) { 
        e.preventDefault();
        if (confirm(ffc_admin_ajax.strings.confirmDeleteField)) {
            $(this).closest('.ffc-field-row').remove();
            ffc_reindex_fields(); 
        }
    });
    
    $('#ffc-fields-container').on('change', '.ffc-field-type-select', function() {
        const selectedType = $(this).val();
        const $optionsContainer = $(this).closest('.ffc-field-row').find('.ffc-options-field'); 
        if (selectedType === 'select' || selectedType === 'radio') {
            $optionsContainer.show();
        } else {
            $optionsContainer.hide();
        }
    });

    ffc_reindex_fields(); 
    $('.ffc-field-type-select').trigger('change');
});