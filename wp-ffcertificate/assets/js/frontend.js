jQuery(document).ready(function($) {

    // =========================================================================
    // 1. FUNÇÃO GLOBAL DE GERAÇÃO DE PDF
    // =========================================================================
    // Definimos na window para poder ser chamada de outros lugares se necessário
    window.generateCertificate = function(pdfData) {
        
        // Verifica se as bibliotecas foram carregadas
        if (typeof html2canvas === 'undefined' || typeof window.jspdf === 'undefined') {
            alert(ffc_ajax.strings.pdfLibrariesFailed); 
            return;
        }

        const { jsPDF } = window.jspdf;

        // Configuração A4 Paisagem (Pixels aproximados para tela @ 96 DPI)
        // 1123px x 794px
        const A4_WIDTH_PX = 1123; 
        const A4_HEIGHT_PX = 794;

        // 1. Cria (ou seleciona) o container oculto para renderização
        let $container = $('#ffc-pdf-generator-container');
        if ($container.length === 0) {
            $container = $('<div id="ffc-pdf-generator-container"></div>').appendTo('body');
        }

        // Reseta estilos para garantir que o container está invisível para o usuário
        // mas visível para o html2canvas renderizar
        $container.css({
            position: 'fixed',
            left: '-10000px',
            top: '0',
            width: A4_WIDTH_PX + 'px',
            height: A4_HEIGHT_PX + 'px',
            background: '#fff',
            overflow: 'hidden',
            zIndex: '-1' // Fica atrás de tudo
        });

        // 2. Prepara o Conteúdo
        // O HTML vem PRONTO do PHP (formatado, com QR code, placeholders trocados)
        let contentHtml = pdfData.final_html;

        // Aplica imagem de fundo (se houver)
        if (pdfData.bg_image) {
            $container.css({
                'background-image': 'url(' + pdfData.bg_image + ')',
                'background-size': 'cover',
                'background-position': 'center',
                'background-repeat': 'no-repeat'
            });
        } else {
            $container.css('background-image', 'none');
        }

        // Injeta o HTML no container
        $container.html(contentHtml);

        // 3. Gera o PDF
        html2canvas($container[0], {
            scale: 2,       // Aumenta a qualidade (2x) para impressão nítida
            useCORS: true,  // Permite carregar imagens externas (se CORS permitir)
            width: A4_WIDTH_PX,
            height: A4_HEIGHT_PX,
            // CORREÇÃO: Removido windowWidth e windowHeight para garantir que
            // o html2canvas use as dimensões explícitas do container sem interferência
            // do viewport.
            logging: false // Desativa logs no console para produção
        }).then(canvas => {
            const imgData = canvas.toDataURL('image/jpeg', 0.95);
            
            // Cria PDF Paisagem (landscape)
            const pdf = new jsPDF({
                orientation: 'landscape',
                unit: 'px',
                format: [A4_WIDTH_PX, A4_HEIGHT_PX]
            });

            pdf.addImage(imgData, 'JPEG', 0, 0, A4_WIDTH_PX, A4_HEIGHT_PX);
            pdf.save('Certificate-' + (pdfData.form_title || 'Document') + '.pdf');

            // Limpeza: Esvazia o container para economizar memória
            $container.html('');
        }).catch(err => {
            console.error('FFC PDF Error:', err);
            alert('Error generating PDF. Please try again.');
        });
    };

    // =========================================================================
    // 2. SUBMISSÃO DO FORMULÁRIO (AJAX)
    // =========================================================================
    $('.ffc-submission-form').on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $btn  = $form.find('.ffc-submit-btn');
        var $msg  = $form.find('.ffc-message');

        // Estado de "Carregando"
        var originalBtnText = $btn.text();
        $btn.prop('disabled', true).text(ffc_ajax.strings.processing);
        $form.find('.ffc-spinner').show(); // Se houver spinner
        $msg.hide().removeClass('ffc-success ffc-error');

        var formData = new FormData(this);
        // A action e o nonce já devem estar nos inputs hidden, mas forçamos aqui por segurança
        if(!formData.has('action')) formData.append('action', 'ffc_handle_submission');
        if(!formData.has('nonce'))  formData.append('nonce', ffc_ajax.nonce);

        $.ajax({
            url: ffc_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false, // Necessário para FormData
            contentType: false, // Necessário para FormData
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false).text(originalBtnText);
                $form.find('.ffc-spinner').hide();

                if (response.success) {
                    $msg.addClass('ffc-success').html(response.data.message).slideDown();
                    
                    // Se o servidor mandou dados de PDF, chamamos a função global
                    if (response.data.pdf_data) {
                        $msg.append('<div style="color:#666; margin-top:5px; font-style:italic;">⏳ ' + ffc_ajax.strings.generatingCertificate + '</div>');
                        
                        // Pequeno delay para garantir que o DOM atualizou visualmente
                        setTimeout(function(){ 
                            window.generateCertificate(response.data.pdf_data); 
                        }, 500);
                    }
                    
                    // Limpa o formulário
                    $form[0].reset(); 
                } else {
                    $msg.addClass('ffc-error').html(response.data.message).slideDown();
                    
                    // Se for erro de Captcha, atualiza os campos automaticamente
                    if (response.data && response.data.refresh_captcha) {
                        $form.find('label[for="ffc_captcha_ans"]').text(response.data.new_label);
                        $form.find('input[name="ffc_captcha_hash"]').val(response.data.new_hash);
                        $form.find('#ffc_captcha_ans').val('');
                    }
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(originalBtnText);
                $form.find('.ffc-spinner').hide();
                $msg.addClass('ffc-error').html(ffc_ajax.strings.connectionError).slideDown();
            }
        });
    });

    // =========================================================================
    // 3. VERIFICAÇÃO DE CERTIFICADO (AJAX)
    // =========================================================================
    $('.ffc-verification-form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn  = $form.find('button[type="submit"]');
        var $container = $form.closest('.ffc-verification-container').find('.ffc-verify-result');

        var originalText = $btn.text();
        $btn.prop('disabled', true).text(ffc_ajax.strings.verifying);
        $container.hide().html('');

        var data = $form.serializeArray();
        // action e nonce já devem estar no HTML, mas garantimos aqui se necessário
        // Nota: serializeArray pega todos os inputs, incluindo hiddens.

        $.ajax({
            url: ffc_ajax.ajax_url,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false).text(originalText);
                
                if (response.success) {
                    // Exibe o HTML de sucesso que veio do PHP (detalhes do certificado)
                    $container.html(response.data.html).fadeIn();
                    $form[0].reset();
                } else {
                    // Exibe erro
                    $container.html('<div class="ffc-verify-error" style="border:1px solid #d63638; background:#fff5f5; padding:15px; margin-top:15px; border-radius:4px; color:#d63638;">' + response.data.message + '</div>').fadeIn();
                    
                    if (response.data && response.data.refresh_captcha) {
                        $form.find('label[for="ffc_captcha_ans"]').text(response.data.new_label);
                        $form.find('input[name="ffc_captcha_hash"]').val(response.data.new_hash);
                        $form.find('#ffc_captcha_ans').val('');
                    }
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(originalText);
                alert(ffc_ajax.strings.connectionError);
            }
        });
    });

});