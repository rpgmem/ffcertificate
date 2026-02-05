# Análise de Consolidação dos Sistemas de Agendamento

**Data:** 2026-02-05
**Status:** Análise pré-implementação (nenhum código alterado)

---

## 1. Situação Atual

O plugin possui **dois sistemas de agendamento independentes**, cada um com sua própria arquitetura, tabelas, menus admin e APIs:

### 1.1 Self-Scheduling (Agendamento Pessoal)
- **Namespace:** `FreeFormCertificate\SelfScheduling`
- **Diretório:** `includes/self-scheduling/`
- **Versão introduzida:** 4.1.0 (renomeado em 4.5.0)
- **Propósito:** O próprio usuário agenda para si mesmo (ex: marcar uma consulta, prova, atendimento)
- **Menu admin:** "FFC Calendars" (posição 26) — via CPT `ffc_self_scheduling`
- **Submenus:** All Calendars, Add New, Appointments
- **Frontend:** Shortcode `[ffc_self_scheduling id="X"]`
- **Tabelas (3):**
  - `ffc_self_scheduling_calendars`
  - `ffc_self_scheduling_appointments`
  - `ffc_self_scheduling_blocked_dates`
- **Arquivos PHP:** ~10 classes

### 1.2 Audience Scheduling (Agendamento de Públicos)
- **Namespace:** `FreeFormCertificate\Audience`
- **Diretório:** `includes/audience/`
- **Versão introduzida:** 4.5.0
- **Propósito:** Um gestor agenda ambientes/salas para grupos (públicos/turmas) de pessoas
- **Menu admin:** "Scheduling" (posição 30) — via `add_menu_page()`
- **Submenus:** Dashboard, Calendars, Environments, Audiences, Bookings, Import, Settings
- **Frontend:** Shortcode `[ffc_audience]`
- **Tabelas (9):**
  - `ffc_audience_schedules`
  - `ffc_audience_schedule_permissions`
  - `ffc_audience_environments`
  - `ffc_audience_holidays`
  - `ffc_audiences`
  - `ffc_audience_members`
  - `ffc_audience_bookings`
  - `ffc_audience_booking_audiences`
  - `ffc_audience_booking_users`
- **Arquivos PHP:** ~8 classes

---

## 2. Comparação Detalhada

### 2.1 Quem Agenda

| Aspecto | Self-Scheduling | Audience |
|---------|----------------|----------|
| **Quem agenda** | O próprio usuário (ou visitante) | Um gestor com permissão |
| **Para quem** | Para si mesmo | Para grupos/turmas ou indivíduos |
| **Login obrigatório** | Configurável (pode ser guest) | Sim, sempre |
| **Permissões** | Por roles do WP (allowed_roles) | Por tabela própria (schedule_permissions) |

### 2.2 O que é Agendado

| Aspecto | Self-Scheduling | Audience |
|---------|----------------|----------|
| **Unidade** | Slot de tempo fixo (ex: 30min) | Faixa de horário livre (start_time → end_time) |
| **Local** | Implícito (o calendário é o recurso) | Explícito via "Ambientes" (salas, laboratórios) |
| **Capacidade** | max_appointments_per_slot (por slot) | Sem limite de capacidade (mas detecta conflitos) |
| **Tipos de booking** | Apenas individual | `audience` ou `individual` |

### 2.3 Conceitos Exclusivos do Self-Scheduling

| Conceito | Descrição |
|----------|-----------|
| **Slots fixos** | Duração fixa (slot_duration), intervalo entre slots (slot_interval) |
| **Janela de agendamento** | advance_booking_min/max — antecedência mínima/máxima |
| **Intervalo mínimo entre agendamentos** | minimum_interval_between_bookings por usuário |
| **Workflow de aprovação** | requires_approval → pending → confirmed |
| **Dados pessoais com criptografia** | email, CPF/RF, telefone, IP (AES-256 + SHA-256 hash) |
| **Consent LGPD** | consent_given, consent_date, consent_ip, consent_text |
| **Token de confirmação** | Para acesso de guests (confirmation_token) |
| **Código de validação** | Código legível tipo certificado (validation_code) |
| **Blocked dates** | Bloqueios com padrão recorrente (full_day, time_range, recurring) |
| **PDF receipts** | Geração de comprovante em PDF |
| **Reminders** | reminder_sent_at — envio de lembrete 24h antes |
| **Status workflow** | pending → confirmed → completed / cancelled / no_show |

### 2.4 Conceitos Exclusivos do Audience Scheduling

| Conceito | Descrição |
|----------|-----------|
| **Ambientes** | Salas físicas com horário de funcionamento próprio (working_hours JSON por ambiente) |
| **Públicos (Audiences)** | Grupos hierárquicos (parent/child) com cor para identificação visual |
| **Membros** | Relação N:1 entre usuários e públicos |
| **Booking N:N** | Um booking pode ter múltiplos públicos E múltiplos usuários individuais |
| **Permissões granulares** | can_book, can_cancel_others, can_override_conflicts (por schedule + user) |
| **Detecção de conflitos** | Verifica sobreposição de horários para membros dos públicos |
| **Visibilidade** | public/private por schedule |
| **Holidays** | Feriados simples (date + description), sem recorrência |
| **ICS** | Suporte a arquivo ICS (calendário) nas notificações |
| **Import CSV** | Importação em massa de dados |

### 2.5 Conceitos em Comum

| Conceito | Self-Scheduling | Audience |
|----------|----------------|----------|
| **Calendar/Schedule** | `ffc_self_scheduling_calendars` | `ffc_audience_schedules` |
| **Working hours** | JSON no calendar | JSON no environment |
| **Blocked dates / Holidays** | `ffc_self_scheduling_blocked_dates` | `ffc_audience_holidays` |
| **Bookings** | `ffc_self_scheduling_appointments` | `ffc_audience_bookings` |
| **Status** | active/inactive (calendar) | active/inactive (schedule) |
| **Email notifications** | Sim (4 tipos) | Sim (booking + cancellation) |
| **Cancellation tracking** | cancelled_at, cancelled_by, reason | cancelled_at, cancelled_by, reason |
| **REST API** | `/ffc/v1/calendars/*` | `/ffc/v1/audience/*` |
| **Admin menu** | Menu independente | Menu independente |
| **Shortcode** | `[ffc_self_scheduling]` | `[ffc_audience]` |

---

## 3. Análise de Viabilidade da Consolidação

### 3.1 Pontos que FAVORECEM a consolidação

1. **Confusão do admin:** Dois menus de "calendários" no WP-admin é confuso para o usuário administrador
2. **Conceitos compartilhados:** Calendários, horários de funcionamento, feriados/bloqueios, bookings, emails
3. **Código duplicado:** Lógica de verificação de horários, envio de emails, validação de datas
4. **Manutenção futura:** Correções de bugs precisam ser feitas em dois lugares
5. **UX unificada:** Um único ponto de entrada no admin para "tudo de agendamento"

### 3.2 Pontos que DIFICULTAM a consolidação

1. **Modelos de dados fundamentalmente diferentes:**
   - Self-Scheduling = slots fixos pré-calculados, capacidade por slot
   - Audience = faixas de tempo livres, sem capacidade fixa

2. **Quem é o "bookee":**
   - Self-Scheduling = dados do próprio usuário (nome, email, CPF, LGPD consent)
   - Audience = grupos de pessoas (públicos com hierarquia parent/child)

3. **Criptografia e LGPD:**
   - Self-Scheduling tem criptografia pesada (AES-256 para email, CPF, IP)
   - Audience não precisa disso (trabalha com user_id do WordPress)

4. **Complexidade do Audience:**
   - 9 tabelas com relacionamentos N:N
   - Hierarquia de públicos (parent/child)
   - Ambientes (salas) com horários próprios
   - Permissões granulares por usuário+schedule

5. **CPT vs Custom Pages:**
   - Self-Scheduling usa Custom Post Type (integrado ao WP editor)
   - Audience usa páginas admin custom (formulários próprios)

6. **Maturidade diferente:**
   - Self-Scheduling existe desde v4.1.0, mais maduro, com PDF, receipts, reminders
   - Audience é novo (v4.5.0), ainda tem placeholders ("Not implemented yet" em AJAX handlers)

---

## 4. Opções de Consolidação

### Opção A: Consolidação Total (NÃO RECOMENDADA)

Fundir tudo em um único sistema com um "tipo" de agendamento (self vs audience).

**Prós:**
- Uma única base de código
- Um menu admin

**Contras:**
- Reescrita massiva (~20 classes, ~12 tabelas)
- Risco altíssimo de regressão
- Schema do banco completamente diferente
- A tabela unificada de bookings ficaria extremamente complexa
- As UIs são fundamentalmente diferentes (slots vs faixas livres)
- Migração de dados existentes seria complexa e arriscada

**Estimativa de impacto:** Muito alto. Essencialmente seria reescrever os dois sistemas do zero.

---

### Opção B: Consolidação de Menu + Shared Services (RECOMENDADA)

Manter as duas engines separadas internamente, mas unificar a experiência do admin.

#### B.1 Menu Unificado

Trocar os dois menus separados por um único menu "Agendamentos" com submenus claros:

```
📅 Agendamentos
├── Dashboard (visão geral de ambos os sistemas)
├── ── Self-Scheduling ──────
├── Calendários Pessoais (lista CPT ffc_self_scheduling)
├── Appointments (agendamentos pessoais)
├── ── Audience ─────────────
├── Calendários de Públicos
├── Ambientes
├── Públicos
├── Agendamentos de Públicos
├── ── Geral ────────────────
├── Importação
└── Configurações
```

**Implementação:**
- Remover o `show_in_menu` do CPT `ffc_self_scheduling` e registrar manualmente via `add_submenu_page`
- Criar um menu pai compartilhado (`ffc-scheduling`)
- Organizar submenus com separadores visuais (via CSS ou submenus desabilitados)

#### B.2 Dashboard Unificado

Uma página de dashboard que mostra:
- Próximos agendamentos pessoais (self-scheduling)
- Próximos agendamentos de públicos (audience)
- Estatísticas combinadas
- Quick links para ações comuns

#### B.3 Shared Services (extrair código duplicado)

Criar serviços compartilhados para:

| Serviço | Usado por |
|---------|-----------|
| `WorkingHoursService` | Ambos (validação de horários de funcionamento) |
| `DateBlockingService` | Ambos (feriados + bloqueios) |
| `NotificationService` | Ambos (emails de confirmação/cancelamento) |
| `ConflictDetectionService` | Ambos (verificação de sobreposição) |

**Implementação:**
- Criar namespace `FreeFormCertificate\Scheduling\Shared`
- Extrair lógica comum gradualmente via interfaces/traits
- Cada sistema mantém suas especificidades

#### B.4 Configurações Unificadas

Uma única página de configurações com abas:
- **Geral:** Configurações que afetam ambos (fuso horário, formato de data)
- **Self-Scheduling:** Configurações específicas
- **Audience:** Configurações específicas

---

### Opção C: Consolidação Parcial + Refactoring Futuro (ALTERNATIVA PRAGMÁTICA)

Fazer apenas a consolidação de menus agora (parte mais simples e de maior impacto visual) e deixar o refactoring de serviços compartilhados para um segundo momento.

**Escopo imediato:**
1. Unificar menus admin sob "Agendamentos"
2. Criar dashboard overview

**Escopo futuro:**
3. Extrair shared services
4. Unificar configurações

---

## 5. Recomendação Final

### Recomendação: **Opção B** (Menu + Shared Services), implementada em fases como a **Opção C**

**Fase 1 — Consolidação de Menus (impacto imediato, baixo risco):**
- Criar menu pai único "Agendamentos" (`ffc-scheduling`)
- Migrar Self-Scheduling CPT para submenu (via `show_in_menu => 'ffc-scheduling'`)
- Organizar submenus com separação visual clara
- Criar dashboard unificado com overview dos dois sistemas
- **Arquivos afetados:**
  - `includes/self-scheduling/class-ffc-self-scheduling-cpt.php` (mudar `show_in_menu`)
  - `includes/self-scheduling/class-ffc-self-scheduling-admin.php` (mudar parent do submenu)
  - `includes/audience/class-ffc-audience-admin-page.php` (ajustar menu)
  - Novo: `includes/scheduling/class-ffc-scheduling-dashboard.php`

**Fase 2 — Shared Services (médio risco, alto valor de manutenção):**
- Extrair `WorkingHoursService`
- Extrair `DateBlockingService`
- Extrair `NotificationService`
- **Nenhuma mudança de schema de banco**

**Fase 3 — Configurações Unificadas (baixo risco):**
- Página de settings com abas
- Migrar settings de ambos os sistemas

### O que NÃO fazer:
- **NÃO** fundir as tabelas de banco de dados — os modelos são fundamentalmente diferentes
- **NÃO** criar uma abstração "universal booking" — over-engineering sem ganho real
- **NÃO** tentar unificar os shortcodes — servem propósitos diferentes
- **NÃO** remover o CPT do Self-Scheduling — é prático para o editor WP

---

## 6. Resumo de Impacto

| Aspecto | Consolidação Total | Menu + Services (Recom.) |
|---------|-------------------|--------------------------|
| Risco | 🔴 Muito alto | 🟢 Baixo (Fase 1) / 🟡 Médio (Fase 2) |
| Impacto visual | 🟢 Total | 🟢 Total (menu unificado) |
| Reuso de código | 🟢 Máximo | 🟡 Parcial (services) |
| Migração de dados | 🔴 Necessária | 🟢 Desnecessária |
| Breaking changes | 🔴 Muitas | 🟢 Nenhuma (Fase 1) |
| Shortcodes | 🔴 Quebrariam | 🟢 Mantidos |
| Tabelas de banco | 🔴 Redesign total | 🟢 Sem alteração |

---

## 7. Estrutura Proposta de Menus (Fase 1)

```
📅 Agendamentos (ffc-scheduling, dashicons-calendar-alt, posição 26)
│
├── 📊 Dashboard              → Visão geral unificada
│
├── ── Pessoal ───────────── (separador visual)
├── 📋 Calendários            → Lista CPT ffc_self_scheduling
├── ➕ Novo Calendário        → Add new CPT
├── 📅 Agendamentos Pessoais → Lista de appointments
│
├── ── Públicos ──────────── (separador visual)
├── 📋 Calendários de Públicos → Audience schedules
├── 🏢 Ambientes              → Environments
├── 👥 Públicos               → Audiences
├── 📅 Agendamentos de Públicos → Audience bookings
│
├── ── Ferramentas ────────── (separador visual)
├── 📥 Importação             → CSV import
└── ⚙️ Configurações          → Settings unificadas
```

Esta estrutura:
- Elimina confusão de dois menus separados
- Mantém clareza sobre o que é "pessoal" vs "público"
- Preserva toda a funcionalidade existente
- Não requer migração de dados
- Pode ser implementada de forma incremental
