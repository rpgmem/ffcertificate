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
            if (window.ffc_admin_ajax && ffc_admin_ajax.strings.fileImported) {
                alert(ffc_admin_ajax.strings.fileImported);
            }
        };
        reader.onerror = function() { 
            alert(ffc_admin_ajax.strings.errorReadingFile || 'Erro ao ler arquivo'); 
        };
        reader.readAsText(file);
    });

    // =========================================================================
    // 2. LOAD SERVER TEMPLATE (AJAX)
    // =========================================================================
    $('#ffc_load_template_btn').on('click', function(e) {
        e.preventDefault();
        var filename = $('#ffc_template_select').val();
        var $btn = $(this);

        if (!filename) {
            alert(ffc_admin_ajax.strings.selectTemplate || 'Selecione um template');
            return;
        }

        if (!confirm(ffc_admin_ajax.strings.confirmReplaceContent || 'Isso substituirá o conteúdo atual. Continuar?')) {
            return;
        }

        $btn.prop('disabled', true).text(ffc_admin_ajax.strings.loading || 'Carregando...');

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
                    alert(ffc_admin_ajax.strings.templateLoaded || 'Template carregado!');
                } else {
                    alert((ffc_admin_ajax.strings.error || 'Erro: ') + response.data);
                }
            },
            error: function() {
                alert(ffc_admin_ajax.strings.connectionError || 'Erro de conexão');
            },
            complete: function() {
                $btn.prop('disabled', false).text(ffc_admin_ajax.strings.loadTemplate || 'Carregar Template');
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
        
        mediaUploader = wp.media({
            title: ffc_admin_ajax.strings.selectBackgroundImage || 'Selecionar Imagem de Fundo',
            button: { text: ffc_admin_ajax.strings.useImage || 'Usar esta imagem' },
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
        
        if(qty < 1) return;

        $btn.prop('disabled', true);
        $status.text(ffc_admin_ajax.strings.generating || 'Gerando...');
        
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
                    $status.text(qty + ' ' + (ffc_admin_ajax.strings.codesGenerated || 'códigos gerados'));
                } else {
                    $status.text(ffc_admin_ajax.strings.errorGeneratingCodes || 'Erro ao gerar códigos');
                }
            },
            complete: function() {
                $btn.prop('disabled', false);
            },
            error: function() {
                $status.text(ffc_admin_ajax.strings.connectionError || 'Erro de conexão');
                $btn.prop('disabled', false);
            }
        });
    });

    // =========================================================================
    // 5. FORM BUILDER (O CORAÇÃO DO PROBLEMA)
    // =========================================================================
    
    // Função para reindexar nomes dos campos
    function ffc_reindex_fields() {
        $('#ffc-fields-container').children('.ffc-field-row').each(function(index) {
            $(this).find('input, select, textarea').each(function() {
                const name = $(this).attr('name');
                if (name) {
                    // Regex robusto para trocar o índice: ffc_fields[X][label] -> ffc_fields[index][label]
                    const newName = name.replace(/ffc_fields\[[^\]]*\]/, 'ffc_fields[' + index + ']');
                    $(this).attr('name', newName);
                }
            });
        });
    }

    // Inicializa Sortable (Arrastar e Soltar)
    if ($.fn.sortable) {
        $('#ffc-fields-container').sortable({
            handle: '.ffc-sort-handle',
            placeholder: 'ui-state-highlight',
            update: function() { ffc_reindex_fields(); }
        });
    }

    // ADICIONAR NOVO CAMPO (Corrigido para usar o conteúdo do Template)
    $('.ffc-add-field').on('click', function(e) {
        e.preventDefault();
        
        // Pega o HTML de dentro da div de template
        var templateHtml = $('.ffc-field-template').html();
        var $container = $('#ffc-fields-container');
        
        // Cria o elemento jQuery
        var $newRow = $(templateHtml);
        
        // Remove classes de controle e garante que apareça
        $newRow.removeClass('ffc-field-template ffc-hidden').show();
        
        // Reseta campos internos
        $newRow.find('input, select, textarea').val('');
        $newRow.find('.ffc-field-type-selector').val('text');
        
        // Adiciona ao container
        $container.append($newRow);
        
        // Reindexa para o PHP salvar certo
        ffc_reindex_fields();
    });

    // REMOVER CAMPO (Usando delegação para funcionar em novos campos)
    $(document).on('click', '.ffc-remove-field', function(e) { 
        e.preventDefault();
        if (confirm(ffc_admin_ajax.strings.confirmDeleteField || 'Remover este campo?')) {
            $(this).closest('.ffc-field-row').remove();
            ffc_reindex_fields(); 
        }
    });
    
    // LÓGICA MOSTRAR/ESCONDER OPÇÕES (Delegação + Seletor Correto)
    $(document).on('change', '.ffc-field-type-selector', function() {
        const selectedType = $(this).val();
        const $row = $(this).closest('.ffc-field-row');
        const $optionsContainer = $row.find('.ffc-options-field'); 
        
        if (selectedType === 'select' || selectedType === 'radio') {
            $optionsContainer.stop(true, true).fadeIn(200).removeClass('ffc-hidden');
        } else {
            $optionsContainer.hide().addClass('ffc-hidden');
        }
    });

    // Inicialização: Aplica a visibilidade nos campos já carregados do banco
    $('.ffc-field-type-selector').each(function() {
        $(this).trigger('change');
    });

    ffc_reindex_fields(); 
});