===========================================
   README / Documentação Final
===========================================

==========================
 1. Objetivo do Plugin
==========================
WP-FFCertificate é um plugin completo para:

- Criar formulários dinâmicos (drag & drop no backend)
- Receber submissões com validação server-side
- Salvar dados no banco (tabela personalizada: wp_ffc_submissions)
- Enviar notificação assíncrona ao administrador (via WP-Cron)
- Gerar certificado em **PNG no front-end** via html2canvas (rápido e sem travar o servidor)
- Permitir exportação CSV (com seleção/filtragem)
- Clonar formulários (metacampos incluídos)
- Limpar submissões antigas automaticamente (configurável em "Settings")
- Oferecer UI amigável com abas no CPT para construção e configuração

O plugin foi reestruturado para escalabilidade, segurança e performance.

==========================
 2. Organização Interna
==========================

O plugin principal é o `wp-ffcertificate.php`.

✔ classes/ (dentro de wp-ffcertificate.php)
   - `Free_Form_Certificate`: Classe principal, hooks, CPT e admin menus.
   - `FFC_Submission_Handler`: Lógica de DB, CRON, exportação CSV e template.
   - `FFC_Submission_List`: Implementação de `WP_List_Table` para submissões.

✔ assets/
   - js/admin.js: Lógica do form builder e geração manual/preview no admin.
   - js/frontend.js: Lógica AJAX de submissão e geração de PNG no frontend.
   - css/admin.css: Estilos para o CPT e telas de admin.

==========================
 3. Fluxo de Submissão
==========================

(1) Frontend envia AJAX (action: `ffc_submit_form`)
→ nonce validado
→ honeypot validado
→ campos sanitizados e validados (incluindo campos obrigatórios)
→ dados enviados ao handler

(2) Handler
→ Salva submissão (`wp_ffc_submissions`)
→ Agenda notificação admin (wp_schedule_single_event)
→ Retorna ID da submissão + template HTML + dados sanitizados

(3) Frontend
→ Recebe JSON de sucesso
→ Substitui {{placeholders}} no template
→ Usa html2canvas para renderizar HTML/CSS e gerar PNG
→ Dispara download automático do PNG para o usuário

(4) Frontend envia AJAX
→ Validações de segurança (Nonce/Honeypot)
→ Verifica se "Restrição por Lista" está ativa (checa campo 'cpf_rf')
→ Verifica se já existe submissão para este CPF neste Formulário:
    A. SE EXISTIR: Recupera os dados originais do banco (Modo 2ª Via).
    B. SE NÃO EXISTIR: Salva novos dados, gera Código de Autenticação e agenda notificação.

(5) Retorno
→ O sistema devolve o JSON com os dados (novos ou recuperados).
→ O PDF é gerado no navegador com os dados corretos.

==========================
 4. Geração do Certificado (PDF)
==========================
O conteúdo HTML suporta CSS avançado e imagens de fundo (para admins).

Placeholders disponíveis:
- `{{auth_code}}`: Código de autenticação único (ex: A1B2-C3D4-E5F6).
- `{{cpf_rf}}`: Identificador único do usuário (obrigatório para restrição).
- `{{date}}`: Data de emissão.
- `{{form_title}}` Título do formulário
- `{{submission_date}}` Data atual formatada
- `{{submission_id}}` ID do registro no banco de dados
- `{{ticket}}` Campo do ticket
- Campos definidos pelo usuário: `{{nome_variavel}}` (deve ser o "Name (Variable)" definido no builder)

==========================
 5. Notificação ao Administrador
==========================
Notificação é 100% assíncrona via WP-Cron (`ffc_send_admin_notification_hook`).

→ Garante que a submissão do formulário seja rápida.
→ Seguro contra timeouts do servidor.

==========================
 6. Exportação CSV
==========================
Disponível na tela "Submissions" com filtro opcional por formulário.
Gera um arquivo CSV completo com todos os metadados de submissão (ID, IP, Data) e todos os campos de dados únicos encontrados.

==========================
 7. Limpeza Automática
==========================
O evento `ffc_daily_cleanup_hook` roda diariamente via WP-Cron para deletar submissões mais antigas que o número de dias configurado em "Settings" > "General Settings".

==========================
 8. Autenticação e Verificação
==========================
O plugin agora garante a autenticidade dos documentos gerados.

1. Código Único:
   Cada submissão gera um hash de 12 caracteres salvo no banco.
   Adicione `{{auth_code}}` no layout do seu PDF para exibi-lo.

2. Página de Validação:
   Crie uma página no WordPress e use o shortcode: `[ffc_verification]`.
   Isso exibirá um campo de busca onde terceiros podem validar o código.
   - Retorno: Status (Válido/Inválido), dados do evento e dados do aluno.
   - UX: O campo possui máscara automática para formatar XXXX-YYYY-ZZZZ.