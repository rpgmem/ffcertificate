// =========================================================================
// FUNÇÃO GLOBAL DE GERAÇÃO DE PDF
// =========================================================================
window.generateCertificate = function(pdfData) {
    if (typeof html2canvas === 'undefined' || typeof window.jspdf === 'undefined') {
        alert('Erro: Bibliotecas de PDF não carregaram.'); return;
    }

    // Configuração A4 Paisagem (Landscape)
    const A4_WIDTH_PX = 1123; 
    const A4_HEIGHT_PX = 794;

    function replacePlaceholders(template, data) {
        let finalHtml = template;
        const submission = data.submission || {};
        
        for (const key in submission) {
            if (submission.hasOwnProperty(key)) {
                // Sanitização básica para evitar quebra de HTML
                let safeVal = String(submission[key])
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
                
                // Formatação específica para auth_code (XXXX-YYYY...)
                if (key === 'auth_code' && safeVal.length === 12 && safeVal.indexOf('-') === -1) {
                    safeVal = safeVal.replace(/(.{4})/g, '$1-').slice(0, -1);
                }
                
                // Substituição Case-Insensitive global
                const regex = new RegExp('{{' + key + '}}', 'gi');
                finalHtml = finalHtml.replace(regex, safeVal);
            }
        }
        
        // Tags Globais
        const dateNow = new Date().toLocaleDateString('pt-BR');
        finalHtml = finalHtml.replace(/{{form_title}}/gi, data.form_title || 'Certificado');
        finalHtml = finalHtml.replace(/{{current_date}}/gi, dateNow);
        
        return finalHtml;
    }

    // 1. Prepara o container temporário
    const $tempContainer = jQuery('<div>').css({
        position: 'absolute', top: '-9999px', left: '-9999px',
        width: A4_WIDTH_PX + 'px', height: A4_HEIGHT_PX + 'px',
        overflow: 'hidden', background: '#fff'
    }).appendTo('body');

    // 2. Insere o HTML processado
    const htmlContent = replacePlaceholders(pdfData.template, pdfData);
    $tempContainer.html(htmlContent);

    // 3. Gera o PDF
    const { jsPDF } = window.jspdf;
    
    // Melhora a qualidade com scale: 2
    html2canvas($tempContainer[0], { 
        scale: 2, 
        useCORS: true, 
        logging: false 
    }).then(canvas => {
        const imgData = canvas.toDataURL('image/jpeg', 0.95); // JPEG ligeiramente comprimido para leveza
        const pdf = new jsPDF({ orientation: 'l', unit: 'px', format: [A4_WIDTH_PX, A4_HEIGHT_PX] });
        
        pdf.addImage(imgData, 'JPEG', 0, 0, A4_WIDTH_PX, A4_HEIGHT_PX);
        
        // Nome do arquivo sanitizado
        const cleanTitle = (pdfData.form_title || 'certificado').replace(/[^a-z0-9]/gi, '_').toLowerCase();
        pdf.save(cleanTitle + '_' + (pdfData.submission_id || Date.now()) + '.pdf');
        
        $tempContainer.remove(); // Limpeza
    }).catch(err => {
        console.error('Erro PDF:', err);
        alert('Erro ao gerar PDF. Tente novamente.');
        $tempContainer.remove();
    });
};

// =========================================================================
// LÓGICA DO FORMULÁRIO (JQUERY)
// =========================================================================
jQuery(function($) {

    // 1. MÁSCARA DINÂMICA PARA O CAMPO CPF/RF
    // ---------------------------------------------------------------------
    $(document).on('input', 'input[name="cpf_rf"]', function(e) {
        let v = $(this).val().replace(/\D/g, ''); // Remove tudo que não for dígito
        
        // Limita a 11 números reais
        if (v.length > 11) {
            v = v.slice(0, 11);
        }

        // Aplica Máscara
        if (v.length <= 7) {
            // Máscara 1: 000.000-0
            v = v.replace(/^(\d{3})(\d)/, '$1.$2');
            v = v.replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2-$3');
        } else {
            // Máscara 2: 000.000.000-00
            v = v.replace(/^(\d{3})(\d)/, '$1.$2');
            v = v.replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3');
            v = v.replace(/^(\d{3})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3-$4');
        }

        $(this).val(v);
    });

    // 2. SUBMISSÃO DO FORMULÁRIO
    // ---------------------------------------------------------------------
    $('.ffc-submission-form').on('submit', function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const $btn  = $form.find('button[type="submit"]');
        const $msg  = $form.find('.ffc-message');
        const $cpfInput = $form.find('input[name="cpf_rf"]');

        // --- VALIDAÇÃO FRONTEND ---
        // Se existir campo CPF, valida tamanho exato (7 ou 11)
        if ($cpfInput.length > 0) {
            const rawVal = $cpfInput.val().replace(/\D/g, '');
            if (rawVal.length !== 7 && rawVal.length !== 11) {
                alert('O campo de identificação (CPF/RF) deve conter exatamente 7 ou 11 dígitos.');
                $cpfInput.trigger('focus');
                return false;
            }
        }

        // Prepara envio
        const originalBtnText = $btn.text();
        $btn.prop('disabled', true).text('Processando...');
        $msg.removeClass('ffc-success ffc-error').hide();

        // Serializa dados
        let formData = $form.serializeArray();

        // === [NOVO] LIMPEZA DE MÁSCARA DO AUTH_CODE ===
        // Procura pelo campo 'auth_code' e remove qualquer caractere que não seja letra ou número
        formData.forEach(function(field) {
            if (field.name === 'auth_code' || field.name === 'codigo') {
                // Remove traços, espaços e pontos, mantendo apenas A-Z e 0-9
                field.value = field.value.replace(/[^a-zA-Z0-9]/g, '');
                console.log('FFC: Auth Code limpo para envio:', field.value);
            }
        });
        // ==============================================

        formData.push({ name: 'action', value: 'ffc_submit_form' });
        formData.push({ name: 'nonce', value: (typeof ffc_ajax !== 'undefined' ? ffc_ajax.nonce : '') });

        $.ajax({
            url: (typeof ffc_ajax !== 'undefined') ? ffc_ajax.ajax_url : '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: formData, // Envia o array com o auth_code já limpo
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false).text(originalBtnText);

                if (response.success) {
                    $msg.addClass('ffc-success').html(response.data.message).show();
                    
                    // Se houver dados de PDF, dispara a geração
                    if (response.data.pdf_data) {
                        $msg.append('<div class="ffc-generating-msg" style="color:#666; margin-top:5px; font-size:0.9em;">⏳ Gerando PDF...</div>');
                        
                        // Pequeno delay para garantir que a UI atualizou
                        setTimeout(function(){ 
                            window.generateCertificate(response.data.pdf_data); 
                            $form.find('.ffc-generating-msg').html('✅ Download iniciado.');
                        }, 500);
                    }
                    
                    $form[0].reset(); // Limpa o formulário após sucesso
                } else {
                    $msg.addClass('ffc-error').html(response.data.message || 'Erro desconhecido.').show();
                }
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false).text(originalBtnText);
                console.error(error);
                $msg.addClass('ffc-error').html('Erro de conexão com o servidor. Tente novamente.').show();
            }
        });
    });

});