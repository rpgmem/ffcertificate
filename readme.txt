=== WP-FFCertificate ===
Contributors: (seu-usuario)
Tags: certificate, form builder, pdf generation, html2canvas, verification, validation
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 2.0.0
License: GPLv2 or later

Documentação completa e registro de alterações do plugin WP-FFCertificate.

== Description ==

WP-FFCertificate é uma solução robusta para WordPress voltada à criação de formulários dinâmicos e emissão automatizada de certificados. O plugin permite que administradores criem campos via Drag & Drop, validem submissões em tempo real e ofereçam aos usuários um certificado em PDF gerado diretamente no navegador, garantindo alta performance sem sobrecarregar o servidor.

== Features ==

* **Drag & Drop Form Builder:** Interface intuitiva para criar campos personalizados (Text, Select, Radio, Date, CPF).
* **Client-Side PDF Generation:** Utiliza html2canvas e jsPDF para gerar certificados A4 (Landscape) com suporte a imagens de fundo personalizadas.
* **Sistema de Verificação:** Shortcode de validação `[ffc_verification]` para autenticidade de certificados via código único.
* **Restrição por Identificador (CPF/ID):** Controle de emissão única ou modo "2ª Via" baseado em documento.
* **Sistema de Tickets:** Importação de lista de códigos exclusivos para acesso ao formulário.
* **Segurança Avançada:** Proteção contra bots com Captcha Matemático integrado e Honeypot.
* **Exportação de Dados:** Ferramenta de exportação CSV com filtros por formulário e data.
* **Notificações Assíncronas:** Envio de e-mails para o administrador via WP-Cron para não travar o fluxo do usuário.
* **Limpeza Automática:** Rotina diária para exclusão de registros antigos conforme configuração.

== Installation ==

1. Envie a pasta `wp-ffcertificate` para o diretório `/wp-content/plugins/`.
2. Ative o plugin através do menu 'Plugins' no WordPress.
3. Acesse o menu 'FFCertificates' para criar seu primeiro formulário.
4. Utilize o shortcode `[ffc_form id="ID_DO_FORM"]` em qualquer página ou post.

== Changelog ==

= 2.0.0 (Versão Atual) =
* **Internacionalização (i18n):** Implementação completa de suporte a tradução. Todas as strings do PHP foram envolvidas em funções `__()` e `_e()` e as strings de JavaScript foram localizadas via `wp_localize_script`.
* **Refatoração de PDF:** Migração do sistema de geração de imagem simples para PDF de alta fidelidade (A4 Landscape) usando jsPDF.
* **Otimização Mobile:** Adição de delays estratégicos e overlay de progresso (FFC Progress Overlay) para garantir a renderização correta em dispositivos móveis.
* **Segurança:** Implementação de Captcha Matemático dinâmico com validação via hash no backend para evitar spam.
* **Lógica de "2ª Via":** Nova lógica de detecção de duplicidade que permite recuperar certificados já emitidos ao digitar o mesmo CPF.
* **Arquitetura OOP:** Reestruturação modular do plugin em classes separadas (`Admin`, `Frontend`, `CPT`, `Submission_Handler`) para melhor manutenção.
* **Melhoria no Admin:** Inclusão de botões de download de PDF diretamente na lista de submissões do painel administrativo.
* **Correção de CORS:** Adição do atributo `crossorigin="anonymous"` na renderização de imagens para evitar erros de "Tainted Canvas".

= 1.5.0 =
* Implementação do sistema de Tickets (códigos de uso único).
* Adição de funcionalidade de clonagem de formulários.
* Criação da aba de configurações globais (Settings) com limpeza de logs automática.

= 1.0.0 =
* Lançamento inicial com Form Builder básico e exportação CSV.

== Layout & Placeholders ==

No editor de layout do certificado, você pode utilizar as seguintes tags dinâmicas:

* `{{auth_code}}`: Código de autenticação de 12 dígitos.
* `{{cpf_rf}}`: Documento informado (CPF ou ID).
* `{{form_title}}`: Título do formulário atual.
* `{{submission_date}}`: Data da emissão (DD/MM/AAAA).
* `{{submission_id}}`: ID numérico da submissão no banco.
* `{{validation_url}}`: URL da página de verificação (se configurada).
* `{{nome_da_variavel}}`: Qualquer nome de campo definido no Form Builder.

== Shortcodes ==

* `[ffc_form id="123"]`: Exibe o formulário de emissão.
* `[ffc_verification]`: Exibe a interface de busca para validar códigos de certificados.


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

O arquivo principal do plugin é o `wp-ffcertificate.php`.

✔ classes/ (dentro de wp-ffcertificate.php)
   - `Free_Form_Certificate`: Classe principal, hooks, CPT e admin menus.
   - `FFC_Submission_Handler`: Lógica de DB, CRON, exportação CSV e template.
   - `FFC_Submission_List`: Implementação de `WP_List_Table` para submissões.

✔ assets/
   - js/admin.js: Lógica do form builder e geração manual/preview no admin.
   - js/frontend.js: Lógica AJAX de submissão e geração de PNG no frontend.
   - js/html2canvas.min.js: Biblioteca que transforma o HTML gerado pelo formulário em uma imagem
   - jspdf.umd.min.js: Biblioteca que embute a imgem gerada em um PDF
   - css/admin.css: Estilos para o CPT e telas de admin.
   - css/ffc-pdf-core.css:
   - css/frontend.css:

✔ includes/
   - class-ffc-activator.php
   - class-ffc-admin.php
   - class-ffc-cpt.php
   - class-ffc-deactivator.php
   - class-ffc-form-editor.php
   - class-ffc-frontend.php
   - class-ffc-loader.php
   - class-ffc-settings.php
   - class-ffc-submission-handler.php
   - class-ffc-submissions-list-table.php
   - class-ffc-utils.php

✔ languages/
   - ffc.pot

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
- `{{fill_date}}`: Data de emissão.
- `{{fill_time}}`: Hora de emissão.
- `{{form_title}}`: Título do formulário
- `{{submission_date}}`: Data atual formatada
- `{{submission_id}}`: ID do registro no banco de dados
- `{{ticket}}`: Campo do ticket
- `{{name}}` ou `{{nome}}`:
- `{{email}}`:
- `{{current_date}}`:
- `{{nome_variavel}}`: Campos definidos pelo usuário: (deve ser o "Name (Variable)" definido no builder)
- `{{validation_url}}`:
- `{{index}}`:

==========================
 5. Shortcodes disponiveis
==========================

- [ffc_form id="123"]
- [ffc_verification]

==========================
 6. Notificação ao Administrador
==========================
Notificação é 100% assíncrona via WP-Cron (`ffc_send_admin_notification_hook`).

→ Garante que a submissão do formulário seja rápida.
→ Seguro contra timeouts do servidor.

==========================
 7. Exportação CSV
==========================
Disponível na tela "Submissions" com filtro opcional por formulário.
Gera um arquivo CSV completo com todos os metadados de submissão (ID, IP, Data) e todos os campos de dados únicos encontrados.

==========================
 8. Limpeza Automática
==========================
O evento `ffc_daily_cleanup_hook` roda diariamente via WP-Cron para deletar submissões mais antigas que o número de dias configurado em "Settings" > "General Settings".

==========================
 9. Segurança
==========================
Lista de permissões: Restrinja a emissão a uma lista específica de CPFs/IDs.
Modo de ticket: Exija um código de ticket exclusivo para emitir o certificado. Os tickets são "descartados" (excluídos) após o uso.
Lista de bloqueios: Bloqueie IDs ou tickets específicos de gerar certificados.

Captcha matemático: Proteção integrada contra bots em todos os formulários.

==========================
 10. Autenticação e Verificação
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