# Auditoria: dados de usuário e criptografia

Levantamento do estado atual de como o plugin armazena, lê e protege dados
associados a usuários. Serve como base para as correções e decisões de refactor
subsequentes.

## 1. Camadas de armazenamento

O plugin distribui dados de usuário em cinco camadas, cada uma com papel
distinto:

| Camada | Tabela / local | Finalidade |
| --- | --- | --- |
| Core WP | `wp_users`, `wp_usermeta` | Autenticação, role, capabilities, integração nativa |
| Perfil FFC | `wp_ffc_user_profiles` | Colunas indexáveis para relatórios e listagens (`display_name`, `phone`, `department`, `organization`, `notes`, `preferences` JSON) |
| Meta estendida | `wp_usermeta` com prefixo `ffc_user_*` | Campos dinâmicos e sensíveis criptografados (CPF, RF, RG), com hash de lookup `ffc_user_<key>_hash` |
| Submissões | `wp_ffc_submissions` | Histórico imutável por envio: `email_encrypted/hash`, `cpf_encrypted/hash`, `rf_encrypted/hash`, `data`, consentimento LGPD |
| Rerregistro | `wp_ffc_custom_fields` + `wp_ffc_reregistration_submissions` + `wp_usermeta['ffc_custom_fields_data']` JSON | Schema dinâmico por audiência e valores submetidos |

A tabela `wp_ffc_submissions` não é duplicação do perfil: é log imutável. Não
deve ser unificada ao perfil no futuro.

## 2. Campos padrão de rerregistro (~30)

Definidos pelo seeder em
`includes/reregistration/class-ffc-reregistration-standard-fields-seeder.php:105-498`
e agrupados por seção:

- **Dados pessoais:** `display_name`, `sexo`, `estado_civil`, `rf`, `vinculo`,
  `data_nascimento`, `cpf`, `rg`, `unidade_lotacao`, `unidade_exercicio`,
  `divisao_setor`.
- **Contato:** `endereco`, `endereco_numero`, `endereco_complemento`, `bairro`,
  `cidade`, `uf`, `cep`, `phone`, `celular`, `contato_emergencia`,
  `tel_emergencia`, `email_institucional`, `email_particular`.
- **Jornada:** `jornada`, `horario_trabalho` (tipo `working_hours`).
- **Sindicato:** `sindicato`.
- **Acúmulo de cargos:** `acumulo_cargos`, `jornada_acumulo`,
  `cargo_funcao_acumulo`, `horario_trabalho_acumulo`.

Tipos suportados: `text`, `number`, `date`, `select`, `dependent_select`,
`checkbox`, `textarea`, `working_hours`.

## 3. Ciclo de vida dos campos

### Definição e seeding
- Seeder por audiência: `class-ffc-reregistration-standard-fields-seeder.php:509-567`.
- CRUD de definição: `class-ffc-custom-field-repository.php:85-275`.
- Schema: `class-ffc-activator.php:372-431`.

### Criação de usuário
- `UserCreator::get_or_create_user` em
  `includes/user-dashboard/class-ffc-user-creator.php:51`.
- Criação do WP User com role `ffc_user`: linhas 115-153.
- Sync de metadados (`display_name`, `first_name`, `ffc_registration_date`):
  linhas 305-328.
- Inserção em `wp_ffc_user_profiles`: linhas 340-375.

### Atualização
- `UserManager::update_extended_profile` em
  `includes/user-dashboard/class-ffc-user-manager.php:412-482` divide o payload
  entre colunas da tabela e `wp_usermeta`, aplicando criptografia para campos
  sensíveis e gravando hash de lookup.
- Rerregistro: `class-ffc-reregistration-data-processor.php:106-241` sanitiza,
  valida e (quando `is_sensitive=1`) encripta.

### Leitura
- `UserManager::get_profile` (`:273-309`) e `get_extended_profile` (`:498-528`).
- Identificadores mascarados: `get_user_cpfs_masked` (`:536-610`),
  `get_user_identifiers_masked` (`:619-681`), `get_user_emails` (`:689-738`).
- REST: `GET /user/profile` em `class-ffc-user-profile-rest-controller.php:96-215`.

### Validação e sanitização
- Validador por tipo, formato e regra:
  `class-ffc-custom-field-validator.php:38-196` (CPF módulo 11, e-mail,
  telefone, regex custom).
- Sanitização no processador: `class-ffc-reregistration-data-processor.php:55-152`.

## 4. Criptografia: arquitetura central

Classe central: `FreeFormCertificate\Core\Encryption` em
`includes/core/class-ffc-encryption.php`.

- **Algoritmo:** AES-256-CBC com HMAC-SHA256 (encrypt-then-MAC), IV único de
  16 bytes por chamada (`random_bytes`).
- **Derivação de chave:** PBKDF2-SHA256, 10 mil iterações, a partir das chaves
  do WordPress ou de `FFC_ENCRYPTION_KEY`.
- **Formato:** `v2:base64(HMAC || IV || CIPHERTEXT)`. Legado v1 (sem HMAC)
  continua decifrável.
- **Hash de lookup:** `Encryption::hash($value)` em `:197-207` — SHA-256 sobre
  `$value . $salt`, com `$salt` derivado das chaves WP.
- **Helpers de alto nível:** `encrypt_submission` (`:217-240`),
  `decrypt_submission` (`:250-281`), `decrypt_field` (`:297-310`),
  `decrypt_appointment` (`:321-351`).

Não existe nenhuma chamada direta a `openssl_encrypt`, `sodium_*` ou
`mcrypt_*` fora desta classe. A primitiva está isolada.

## 5. Inconsistências identificadas

### 5.1 Hash de lookup divergente entre tabelas (CRÍTICA)

Submissões usam `Encryption::hash()` (com salt). Appointments usam raw
`hash('sha256', ...)` (sem salt). O mesmo e-mail produz hashes diferentes nas
duas tabelas — qualquer cruzamento entre entidades via hash falha
silenciosamente.

| Arquivo | Linha | Método atual | Correto |
| --- | --- | --- | --- |
| `includes/repositories/ffc-appointment-repository.php` | 108 | `hash('sha256', ...)` em `findByEmail` | `Encryption::hash` |
| `includes/repositories/ffc-appointment-repository.php` | 140 | `hash('sha256', ...)` em `findByCpfRf` | `Encryption::hash` |
| `includes/repositories/ffc-appointment-repository.php` | 475 | `hash('sha256', ...)` para `email_hash` em `createAppointment` | `Encryption::hash` |
| `includes/user-dashboard/class-ffc-user-cleanup.php` | 172 | `hash('sha256', ...)` ao reindexar `email_hash` da tabela de submissões após troca de e-mail | `Encryption::hash` |

**Auto-inconsistência interna da tabela de appointments:**
`createAppointment` escreve `cpf_hash`/`rf_hash` com `Encryption::hash` nas
linhas 487 e 491, mas `findByCpfRf` lê com raw sha256 na linha 140. O read não
bate com o próprio write — `findByCpfRf` nunca encontra os registros criados
pela própria aplicação.

### 5.2 Política de "campo sensível" espalhada (ALTA)

Não existe uma lista declarativa única. A decisão "este campo é sensível" vive
em seis lugares diferentes:

| Local | Mecanismo |
| --- | --- |
| `class-ffc-reregistration-standard-fields-seeder.php` | Flag `is_sensitive` por campo |
| `class-ffc-reregistration-data-processor.php:236, 327, 357` | Lê flag em runtime |
| `class-ffc-verification-handler.php:435` | Lê flag em runtime |
| `ffc-appointment-repository.php:469-516` | Lista fixa hard-coded |
| `ffc-submission-handler.php:190-217` | Lista fixa hard-coded |
| `ffc-activity-log.php:126-134` | Whitelist por ação, não por campo |

Se um campo novo for marcado como sensível no seeder, ele continua em texto
puro no agendamento e na submissão genérica até alguém editar as listas
hard-coded.

### 5.3 Criptografia seletiva no log de atividade (MÉDIA)

`includes/core/class-ffc-activity-log.php:126-143` só encripta o campo
`context` quando a ação pertence a uma whitelist fixa (`submission_created`,
`data_accessed`, `data_modified`, `admin_searched`,
`encryption_migration_batch`). Qualquer outra ação persiste o contexto em
texto puro — se amanhã alguém logar dados sensíveis numa ação fora da lista,
vaza sem alarme.

### 5.4 Falhas de descriptografia silenciadas (MÉDIA)

- `includes/frontend/class-ffc-reprint-detector.php:160`:
  `Encryption::decrypt(...) ?? ''` — HMAC inválido vira string vazia sem log.
- `includes/api/class-ffc-submission-rest-controller.php:408`: try/catch
  engole o erro e retorna o dado original.

`Encryption::decrypt` retorna `null` em falha de HMAC (tampering) ou
corrupção. Silenciar esse sinal elimina auditoria num cenário em que ela é
exatamente o que a LGPD exige.

## 6. Pontos de atenção de arquitetura

- **Múltiplos caminhos de escrita para o mesmo campo** (ex.: `phone` vai em
  `wp_ffc_user_profiles`, em `wp_usermeta['ffc_user_phone']` e pode aparecer
  no JSON `ffc_custom_fields_data`). Sincronização espalhada entre
  `UserManager::update_extended_profile`,
  `ReregistrationDataProcessor` e `UserCreator::sync_user_metadata`.
- **JSON "bag"** em `ffc_custom_fields_data` facilita gravação dinâmica mas
  impede queries relacionais por valor.
- **Descriptografia replicada** em `UserManager`, `ReregistrationDataProcessor`
  e `PrivacyHandler` — cada um com sua convenção.
- **Campos standard protegidos:** `CustomFieldRepository::delete` recusa
  apagar campos standard, só permite `deactivate`, preservando dados já
  coletados.

## 7. Recomendações

Em ordem de prioridade e esforço crescente.

1. **Corrigir o hash do appointment (seção 5.1).** Bug real, escopo pequeno,
   alto valor. Exige migração dos hashes de `email_hash` já persistidos em
   `wp_ffc_self_scheduling_appointments` e das linhas de `wp_ffc_submissions`
   reindexadas pelo cleanup. Hashes de `cpf_hash`/`rf_hash` já estão corretos
   na escrita — só a leitura precisa ser ajustada.
2. **Centralizar a política de sensibilidade** num `SensitiveFieldRegistry`
   declarativo, consumido por todos os repositórios. Remove a possibilidade de
   divergência por construção.
3. **Auditoria de descriptografia:** substituir os `?? ''` e try/catch
   silenciosos por logging estruturado via `ActivityLog`.
4. **Avaliar `UserProfileService`** como porta única de leitura/escrita de
   perfil, com método em lote para exportações (CSV, LGPD). Só faz sentido
   após 1 e 2. Não é ganho de performance para leituras individuais; o ganho
   real aparece em exportações grandes, onde a implementação precisa usar
   generator + chunking para não estourar `memory_limit` em hospedagem
   compartilhada.
5. **`wp_ffc_submissions` permanece separada** do perfil. É log imutável, não
   estado atual do usuário.

## 8. Correções aplicadas (item 1)

### Código
- `includes/repositories/ffc-appointment-repository.php`
  - `findByEmail` (l. 108) e `createAppointment` (l. 483): agora usam
    `Encryption::hash($email)` para gerar `email_hash`.
  - `findByCpfRf` (l. 141): agora usa `Encryption::hash($cpf_rf_clean)`,
    coerente com o que `createAppointment` já escrevia (linhas 494/498).
- `includes/user-dashboard/class-ffc-user-cleanup.php`
  - `handle_email_change` (l. 173): reindexação de `email_hash` em submissões
    agora usa `Encryption::hash($new_email)`, coerente com o handler.

### Convenção de normalização
Optou-se por **não normalizar** (sem `strtolower`/`trim`) o valor passado ao
hash, espelhando o comportamento canônico do `SubmissionHandler`. Cada linha
agora satisfaz o invariante `email_hash = Encryption::hash(decrypt(email_encrypted))`.

Efeito colateral: lookup de `findByEmail` em appointments deixa de ser
case-insensitive para registros criados após a correção. O mesmo já valia para
submissões; consolidar a convenção facilita o cruzamento entre tabelas.

### Migração de dados legados
- Estratégia: `Migrations\Strategies\EmailHashRehashMigrationStrategy` em
  `includes/migrations/strategies/class-ffc-email-hash-rehash-migration-strategy.php`.
- Registrada em `MigrationRegistry` sob a chave `email_hash_rehash` com
  `batch_size = 100`.
- Varre ambas as tabelas (`wp_ffc_submissions` e
  `wp_ffc_self_scheduling_appointments`) por id, descriptografa
  `email_encrypted`, recomputa o hash salted e escreve somente quando o valor
  atual difere — idempotente.
- Progresso armazenado em duas opções
  (`ffc_email_hash_rehash_cursor_<tabela>`), uma por tabela, permitindo
  retomar após interrupção.
- Preflight (`can_run`) exige `Encryption::is_configured()`.

### Fora de escopo (follow-ups)
- `class-ffc-self-scheduling-appointment-handler.php:488` e
  `ffc-submission-repository.php:792` ainda contêm fallback
  `class_exists ? Encryption::hash : hash('sha256')`. Em produção o fallback
  nunca dispara (encryption é pré-requisito), mas a presença do raw SHA-256
  deve ser removida em passo seguinte.
- Criptografia seletiva em `ActivityLog` e silêncio em `decrypt` permanecem
  abertos — endereçados pelos itens 2 e 3 da seção 7.

## 9. Correções aplicadas (item 2 — política de campo sensível)

### Fase 0 — testes de caracterização
`tests/Unit/SensitiveFieldPolicyTest.php` fixa em um só lugar o contrato que
cada write path mantém:

- `SubmissionHandler::process_submission` encripta `email`, `cpf|rf`,
  `user_ip`, `data`; faz hash de `email`, `cpf|rf`, `ticket`.
- `AppointmentRepository::createAppointment` encripta `email`, `cpf|rf`,
  `phone`, `custom_data`, `user_ip`; faz hash de `email`, `cpf|rf`; remove
  plaintext.
- Também tranca o invariante estabelecido pelo item 1:
  `email_hash == Encryption::hash(decrypt(email_encrypted))`.

### Fase 1 — `SensitiveFieldRegistry`
Classe `FreeFormCertificate\Core\SensitiveFieldRegistry` em
`includes/core/class-ffc-sensitive-field-registry.php`. Mapa declarativo
indexado por contexto (`CONTEXT_SUBMISSION`, `CONTEXT_APPOINTMENT`); cada
campo informa `encrypted_column` e `hash_column`. API:

- `encrypt_fields( $context, $values )` — retorna o mapa de colunas a
  serem escritas. No-op quando a criptografia não está configurada.
- `plaintext_keys( $context )` — chaves cujo texto claro precisa ser
  removido antes do insert.

Consumidores:

- `SubmissionHandler::process_submission` / `update_submission` trocam os
  blocos inline por uma chamada ao registry.
- `AppointmentRepository::createAppointment` normaliza `cpf_rf → cpf|rf`
  antes da chamada e faz um único `foreach` para remover o plaintext.

Activity log permanece fora do escopo: a política de encriptação dele é
por *ação*, não por campo.

### Fase 2 — auditoria de migração
Comparado o registry com o estado hoje escrito por cada write path: todos
os campos declarados já eram criptografados no mesmo par de colunas pelos
call sites atuais. **Nenhuma migração adicional foi necessária.** As
migrações legadas continuam cobrindo os dois casos históricos:

- `split_cpf_rf` para linhas com `cpf_rf_hash` combinado.
- `email_hash_rehash` (item 1) para hashes de e-mail sem salt.
