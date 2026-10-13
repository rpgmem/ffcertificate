=== Free Form Certificate ===

Crie formulários dinâmicos, gere certificados em PDF e valide a autenticidade com acesso por link mágico.

== Description ==

O Free Form Certificate é um plugin de WordPress para emitir certificados em PDF a partir de formulários dinâmicos e verificar sua autenticidade. Ele também cobre agendamento de atendimentos, agendamentos de grupos, campanhas de recadastramento, filas de candidatos de concursos públicos, URLs curtas, QR codes e e-mails por data. Cada módulo, inclusive o de certificados, pode ser ligado ou desligado em Configurações → Módulos.

A referência completa (cada token, shortcode, capacidade e configuração) vem dentro do plugin, em Configurações → Documentação.

= Certificados =

* **Construtor de formulários** - Campos de arrastar e soltar: Texto, E-mail, Número, Data, Área de texto, Seleção, Rádio, Caixa de seleção, Bloco de informação, Incorporação (mídia) e Oculto.
* **PDF gerado no navegador** - Certificados desenhados no navegador com html2canvas e jsPDF, a partir de Modelos de documento reutilizáveis com imagens de fundo.
* **Links mágicos** - Acesso ao certificado com um clique, por um link único com um token aleatório de 32 caracteres, enviado por e-mail.
* **Verificação** - Conferência de autenticidade pelo código de autenticação ou pelo link mágico, na página `/valid` criada na ativação.
* **Modo questionário** - Formulários com nota, cada campo pontuado por si (opções, pontos, faixas), com os tokens `{{score}}`, `{{max_score}}`, `{{score_percent}}`, `{{correct_count}}` e `{{question_count}}`. Respostas abertas podem ser corrigidas por uma pessoa, e o questionário pode recomeçar quando o participante sai da janela.

= QR Codes =

* **QR codes nos certificados** - O token `{{qr_code}}` liga cada certificado à sua página de verificação.
* **Design do QR code** - Configurações → QR Code define o formato dos pontos e dos olhos, as cores ou um degradê, um logotipo no centro, molduras com chamada para ação e fundo transparente. O design vale para certificados, links mágicos e URLs curtas. Cada formato oferecido é testado para garantir a leitura.
* **Gerador de QR Code** - URLs Curtas → Gerador de QR Code cria códigos avulsos para um endereço, texto, Wi-Fi, e-mail, telefone, SMS, WhatsApp, cartão de contato (vCard), perfil em rede social ou evento de calendário. Os códigos são baixados em SVG ou PNG, ou impressos direto, e o último design de cada usuário fica lembrado. As URLs curtas manuais são criadas aqui: para um site ou um perfil em rede social, uma chave coloca no código uma URL curta com contagem de cliques, criada ao baixar ou imprimir.

= Encurtador de URLs =

* **Links curtos** - Um domínio próprio de links curtos para os endereços gerados pelo plugin, com um QR code por link e limpeza dos links que nunca foram clicados.
* **Integração com o WordPress** - Disponível como o shortlink do WordPress e como um campo REST `ffc_shortlink` nos tipos de post habilitados.

= Autoagendamento (Calendários Pessoais) =

* **Gestão de calendários** - Vários calendários com horários, durações, expediente e datas bloqueadas configuráveis.
* **Agendamento** - Widget de agendamento no site, com a disponibilidade de horários em tempo real.
* **Notificações por e-mail** - E-mails de confirmação, aprovação, cancelamento e lembrete.
* **Comprovantes em PDF** - Comprovantes de agendamento gerados no navegador para download.
* **Telas do administrador** - Gerencie, aprove e exporte agendamentos.

= Agendamento de Público-alvo (agendamentos de grupos) =

* **Gestão de públicos** - Públicos (grupos) hierárquicos com cores.
* **Gestão de ambientes** - Espaços físicos com calendários, expediente, feriados e capacidade.
* **Agendamentos de grupos** - Agende atividades para públicos inteiros ou para usuários individuais.
* **Importação e exportação em CSV** - Importe e exporte públicos e membros, com criação de usuários.
* **Detecção de conflitos** - Os conflitos são verificados antes de um agendamento ser confirmado.
* **Notificações por e-mail** - Notificações automáticas de novos agendamentos e de cancelamentos.

= Recadastramento =

* **Gestão de campanhas** - Campanhas de recadastramento ligadas a públicos, com períodos configuráveis.
* **Campos personalizados** - Campos personalizados por público (texto, área de texto, número, data, seleção, caixa de seleção) com validação, além de um conjunto padrão de campos de identificação, contato e vínculo funcional.
* **Notificações por e-mail** - E-mails de convite, lembrete e confirmação com modelos editáveis.
* **Fluxo de aprovação** - Aprovação manual ou automática, com uma tela de análise para o administrador.
* **Ficha em PDF** - Uma ficha em PDF de cada envio, a partir de um modelo personalizável.
* **Integração com o painel** - Os usuários veem os avisos de recadastramento, enviam o formulário e baixam a ficha pelo painel.

= Recrutamento (filas de candidatos de concursos públicos) =

* **Gestão de editais e candidatos** - Editais, candidatos e listas de classificação com posição e pontuação.
* **Importação atômica de CSV** - Apaga e reinsere em uma única transação, desfazendo tudo a qualquer erro de validação; detecta o separador ponto e vírgula (BR/EU).
* **Fluxo de convocação** - Convocações individuais e em lote com histórico de chamadas que só acrescenta, cancelamento e uma ação "Desfazer decisão" que devolve o candidato à fila.
* **Máquinas de estado** - Ciclos de vida do edital (rascunho → preliminar → ativo → encerrado) e da classificação, com uma regra de congelamento na reabertura que protege as linhas de contratados e ausentes.
* **Fila pública e painel do candidato** - `[ffc_recruitment_queue]` lista os candidatos convocados e não convocados de cada edital; `[ffc_recruitment_my_calls]` dá a cada candidato uma visão própria de suas classificações e convocações.
* **Envio de e-mails** - E-mails automáticos de convocação com marcadores de dados pessoais mascarados.

= Mensagens por Data =

* **E-mails de aniversário** - E-mails automáticos montados a partir da data de nascimento de cada usuário, enviados no dia ou um número definido de dias antes. Nada é enviado até que um administrador crie e ative uma regra.
* **Regras** - Várias regras independentes, cada uma com seu deslocamento, público opcional, assunto e corpo em HTML.
* **Prévia e Enviar agora** - Veja quem receberia uma regra, e por que alguém seria pulado, antes de qualquer envio. Rode um intervalo de datas à mão ou envie a si mesmo um teste com dados de exemplo.
* **Resumo para gestores** - Um e-mail opcional de resumo para usuários escolhidos, 24 horas depois de cada execução.
* **Próximas datas** - Um painel com os próximos aniversários (só dia e mês), protegido por uma capacidade própria.
* **Descadastro com um clique** - Cada mensagem traz um link de descadastro assinado que confirma por POST, e os usuários também podem se descadastrar pelo painel.

= Segurança e restrições =

* **Geofencing** - Restrinja o acesso ao formulário por coordenadas de GPS ou por áreas baseadas em IP.
* **Limite de taxa** - Limites configuráveis por IP, e-mail, CPF/RF e dispositivo, com bloqueio automático.
* **Restrição por documento** - Controle a emissão de certificados pela validação do CPF/RF.
* **Sistema de tickets** - Importe códigos de acesso de uso único para dar acesso exclusivo ao formulário.
* **Lista de permitidos / bloqueados** - Permita ou bloqueie documentos específicos.
* **Captcha e honeypot** - Um honeypot em todo formulário público, mais um desafio de matemática, um desafio de prova de trabalho ALTCHA, ou os dois (ALTCHA com o de matemática como reserva quando o JavaScript está desligado).
* **Criptografia de dados** - E-mail, CPF/RF, endereço IP e outros campos sensíveis são criptografados no banco (AES-256), com hashes com sal para as buscas.

= Administração =

* **Registro de Atividades** - Trilha de auditoria das ações de administradores e usuários.
* **Painel do usuário** - Painel pessoal no site, com certificados, agendamentos, agendamentos de grupo, recadastramento e perfil.
* **Exportação em CSV** - Exportações em lotes, à prova de tempo limite, de envios, agendamentos e outros dados.
* **Migrações de dados** - Configurações → Migrações roda migrações em lotes com acompanhamento do progresso.
* **Ferramentas de manutenção** - Limpe URLs curtas obsoletas, desligue o Acesso Público de Operador nos formulários que já terminaram e audite como os envios estão ligados aos usuários do WordPress. A auditoria só gera relatório.
* **Tarefas Agendadas** - Uma tela que lista cada tarefa em segundo plano com sua próxima e última execução, define os horários diários e gera a linha de cron do servidor a instalar.
* **Resolução de identidade** - Uma auditoria de registros de identidade conflitantes (CPF/RF e e-mails compartilhados ou inválidos) entre os armazenamentos do plugin, com ações de aceitar e resolver.
* **E-mail** - Configurações de SMTP próprias, um layout de e-mail configurável compartilhado por todas as mensagens e uma chave geral para desligar todos os e-mails.
* **API REST** - Endpoints REST para integrações externas.
* **Capacidades e funções** - Permissões granulares e delegáveis, com três estados por área (oculto / só visualiza / visualiza e edita), funções dedicadas e editores por usuário e por função, para delegar o plugin sem conceder o papel de administrador do WordPress.
* **Modo Escuro** - Um tema escuro para o painel (desligado, ligado ou seguindo o sistema operacional).
* **Design Público** - Configurações → Design Público dá a todas as telas públicas as cores de destaque, de campo, de dica e de erro do site, o arredondamento dos cantos, a largura do formulário e a fonte. Vem desligado, vale só para o tema claro e avisa quando um par de cores fica abaixo do contraste mínimo da WCAG.

= Integrações =

* **Total Mail Queue** - Quando o plugin Total Mail Queue está ativo, cada e-mail entra na fila dele, identificado com o recurso do plugin que o enviou.
* **Cloudflare** - No modo opcional "seguro" de IP do cliente (Configurações → Diagnóstico de IP), o Cloudflare é detectado e suas faixas de IP são atualizadas todo dia, para que os limites e o geofencing usem o IP real do visitante.
* **Geolocalização por IP** - Geofencing por IP através do ip-api.com ou do ipinfo.io (o ipinfo.io exige uma chave de API).
* **Atualizações pelo GitHub** - As atualizações do plugin vêm das Releases do projeto no GitHub, pela tela de atualizações normal do WordPress, e o SHA-256 de cada pacote é conferido antes da instalação.

== Installation ==

1. Envie a pasta `ffcertificate` para `/wp-content/plugins/`.
2. Ative o plugin no menu "Plugins" do WordPress.
3. Vá em Certificado → Adicionar Novo Formulário para criar seu primeiro formulário.
4. Coloque o shortcode `[ffc_form id="FORM_ID"]` em qualquer página ou post.
5. Opcional: ligue ou desligue módulos em Configurações → Módulos, e instale a linha de cron do servidor mostrada em Configurações → Tarefas Agendadas para que as tarefas em segundo plano rodem na hora certa.

== Frequently Asked Questions ==

= Como crio um formulário? =

1. Vá em Certificado → Adicionar Novo Formulário.
2. Dê um título e adicione campos com o Construtor de formulários.
3. Escolha e ajuste o layout do certificado.
4. Salve e copie o shortcode gerado.

= O que são links mágicos? =

Links mágicos são endereços únicos, enviados por e-mail, que deixam o destinatário abrir e baixar o certificado com um clique. Cada link traz um token aleatório de 32 caracteres. Apagar o envio invalida o link.

= Como configuro a página de verificação? =

O plugin cria uma página `/valid` na ativação. Você também pode colocar `[ffc_verification]` em qualquer página.

= Como crio um calendário? =

1. Vá em Agendamento → Calendários Pessoais → Novo Calendário Pessoal.
2. Configure o expediente, a duração dos horários e a capacidade.
3. Coloque o shortcode `[ffc_self_scheduling id="CALENDAR_ID"]` em qualquer página.

= Posso restringir quem gera certificados? =

Sim. Nas configurações de restrição de cada formulário você pode ligar o modo de lista de permitidos, usar o sistema de tickets, bloquear documentos com uma lista de bloqueados ou restringir por área com geofencing.

= As tarefas em segundo plano precisam de um cron no servidor? =

Elas rodam no WP-Cron, que só dispara quando o site recebe visitas. Para horários confiáveis, principalmente nos e-mails agendados, abra Configurações → Tarefas Agendadas. A tela mostra a linha exata de crontab para o seu servidor (WP-CLI, wget ou curl), o estado do `DISABLE_WP_CRON` e qualquer tarefa atrasada.

= O plugin funciona com plugins de cache de página (WP Rocket, LiteSpeed Cache, W3 Total Cache)? =

Sim. O plugin tem compatibilidade com cache embutida:

* **Formulários (captcha e nonces):** Um sistema de "Fragmentos dinâmicos" renova os desafios do captcha e os nonces de segurança por AJAX depois que a página carrega, então os formulários funcionam mesmo quando o HTML vem de um cache de página inteira.
* **Páginas do painel:** O shortcode `[user_dashboard_personal]` define a constante `DONOTCACHEPAGE`, envia os cabeçalhos padrão de não armazenar em cache e aciona os ganchos de exclusão do LiteSpeed, para que dados específicos do usuário nunca fiquem em cache.
* **Endpoints AJAX:** Os envios de formulário e as buscas de dados usam o `admin-ajax.php`, que os principais plugins de cache já excluem por padrão.
* **Diagnóstico:** Configurações → Cache mostra um cartão "Compatibilidade do Cache de Página" com o estado de cada recurso ligado ao cache e o plugin de cache detectado.

Não é preciso criar regras manuais de exclusão do cache.

= Preciso de alguma configuração no servidor com nginx? =

Um passo opcional de reforço. As exportações de CSV em lotes guardam um arquivo temporário (que pode conter dados pessoais decifrados) em `wp-content/uploads/ffc-tmp/`. No Apache, o plugin o protege com um `.htaccess` próprio; **o nginx ignora o `.htaccess`**, então acrescente uma regra de bloqueio ao bloco do servidor:

`location ^~ /wp-content/uploads/ffc-tmp/ { deny all; return 404; }`

O arquivo temporário já tem vida curta (nome aleatório, apagado logo depois do download, cron de limpeza diário), então isso é uma defesa adicional. Veja `docs/DEPLOYMENT.md` para os detalhes.

= Como traduzo o plugin? =

O plugin está pronto para tradução, com o text domain `ffcertificate`. Use o Loco Translate ou o Poedit com o modelo `languages/ffcertificate.pot`. Uma tradução para português do Brasil acompanha o plugin.

== Screenshots ==

As imagens ficam na pasta `docs/screenshots/` do repositório e aparecem no README.

1. Painel de certificados: formulários por data, com os envios de hoje e da semana
2. Construtor de formulários
3. Prévia do layout do certificado com dados de exemplo
4. Lista de envios com download do PDF
5. Formulário público de certificado
6. Página de verificação de certificados
7. Agendamento em um calendário pessoal no site
8. Lista de agendamentos
9. Calendário de sala para agendamentos de grupos
10. Mensagens por Data: um cartão de aniversário com imagem de fundo, em prévia antes do envio
11. Gerador de QR Code
12. URLs curtas com contagem de cliques
13. Modo escuro
14. O painel do próprio usuário

== Shortcodes ==

= [ffc_form] =
Mostra um formulário de emissão de certificado.

* `id` (obrigatório) - ID do formulário.

Exemplo: `[ffc_form id="123"]`

= [ffc_verification] =
Mostra a interface de verificação de certificados. Reconhece links mágicos pelo parâmetro `?token=`.

Exemplo: `[ffc_verification]`

= [ffc_csv_download] =
Mostra a página pública do operador, onde operadores de confiança podem baixar o CSV de envios de um formulário, abrir um formulário antes da hora ou adiar seu encerramento. O acesso exige o ID do formulário e o hash de acesso gerado por "Acesso Público de Operador" no editor do formulário.

* `title` (opcional) - Título da página.

Exemplo: `[ffc_csv_download title="Baixar participantes"]`

= [ffc_self_scheduling] =
Mostra um calendário pessoal com seu widget de agendamento.

* `id` (obrigatório) - ID do calendário.

Exemplo: `[ffc_self_scheduling id="456"]`

= [ffc_audience] =
Mostra o calendário de agendamento de público-alvo para agendamentos de grupos.

Exemplo: `[ffc_audience]`

= [user_dashboard_personal] =
Mostra o painel pessoal do usuário, com certificados, agendamentos, agendamentos de grupo, recadastramento e perfil.

Exemplo: `[user_dashboard_personal]`

= [ffc_recruitment_queue] =
Mostra a fila pública de candidatos de um edital, dividida em listas de convocados e não convocados.

* `notice` (obrigatório) - Código do edital.
* `adjutancy` (opcional) - Restringe a lista a uma única função/cargo.

Exemplo: `[ffc_recruitment_queue notice="EDITAL-2026-01"]`

= [ffc_recruitment_my_calls] =
Mostra as classificações e o histórico de convocações do próprio candidato conectado.

Exemplo: `[ffc_recruitment_my_calls]`

== Layout e marcadores ==

Em um modelo de certificado, use estas marcações dinâmicas. A lista completa, inclusive as chaves dos campos padrão de identificação, contato e vínculo funcional, está em Configurações → Documentação → Variáveis/Tokens de Modelo.

= Marcações do sistema =
* `{{auth_code}}` - Código de autenticação alfanumérico de 12 caracteres (no formato XXXX-XXXX-XXXX)
* `{{form_title}}` - Título do formulário
* `{{submission_date}}` - Data de emissão, no formato de data configurado no plugin (`{{date}}` e `{{fill_date}}` são sinônimos)
* `{{print_date}}` - Data em que o PDF é gerado
* `{{submission_id}}` - ID numérico do envio
* `{{validation_url}}` - Endereço da página de verificação
* `{{qr_code}}` - QR code que leva à página de verificação
* `{{site_name}}`, `{{main_address}}`, `{{logo_gov}}`, `{{logo_org}}` - Identidade visual definida em Configurações → Geral

= Marcações dos campos do formulário =
* `{{field_name}}` - Qualquer nome de campo definido no Construtor de formulários
* Exemplos comuns: `{{name}}`, `{{email}}`, `{{cpf_rf}}`, `{{ticket}}`

== Privacidade e tratamento de dados ==

= Dados coletados =
* Envios de formulário (nome, e-mail, CPF/RF e campos personalizados)
* Campos do perfil do usuário (contato, endereço, vínculo funcional, data de nascimento) quando o painel ou o recadastramento os coletam
* Endereços IP (para limite de taxa, geofencing e trilha de auditoria)
* Agendamentos e agendamentos de grupo (data, horário, dados de contato)
* Datas e horários dos envios e das ações

= Armazenamento =
Todos os dados ficam nas tabelas próprias do plugin, `wp_ffc_*`, e em user meta e opções com o prefixo `ffc_`. As principais tabelas:

* Envios: `wp_ffc_submissions`, com os campos sensíveis criptografados
* Agendamentos: `wp_ffc_self_scheduling_appointments`
* Limite de taxa: `wp_ffc_rate_limits` e `wp_ffc_rate_limit_logs`
* Registro de atividades: `wp_ffc_activity_log`
* Perfis: `wp_ffc_user_profiles`

Excluir o plugin remove suas tabelas, opções e user meta só quando a opção de apagar todos os dados do plugin na desinstalação está ligada em Configurações → Avançado.

= Retenção =
* Limpeza automática configurável dos envios antigos
* Exclusão manual no painel do administrador
* Os exportadores e apagadores de dados pessoais do WordPress são registrados
* Apagar um envio invalida seu link mágico e seu QR code
