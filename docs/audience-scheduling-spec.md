# Sistema de Agendamentos de Públicos-Alvo

## Especificação Técnica

**Versão:** 1.0
**Data:** 2026-02-05
**Status:** Aprovado para implementação

---

## 1. Visão Geral

Dois sistemas de agendamento coexistindo no plugin:

| Sistema | Descrição | Shortcode |
|---------|-----------|-----------|
| **Auto-agendamento** | Usuário agenda para si mesmo | `[ffc_self_scheduling id="X"]` |
| **Públicos-alvo** | Usuário agenda grupos/pessoas | `[ffc_audience id="X"]` |

---

## 2. Entidades

### Diagrama de Relacionamento

```
Calendário (Schedule)
    └── Ambiente (Environment)
            └── Agendamento (Booking)
                    ├── Público-alvo (Group) ──► Membros (Users)
                    └── Usuários Individuais
```

### Definições

| Entidade | Descrição |
|----------|-----------|
| **Calendário (Schedule)** | Entidade pai que agrupa múltiplos ambientes |
| **Ambiente (Environment)** | Local físico (sala, auditório, etc.) |
| **Público-alvo (Group)** | Grupo de usuários com hierarquia (mãe/filhos) |
| **Agendamento (Booking)** | Reserva de ambiente + público-alvo/usuário + período |

---

## 3. Tabelas do Banco de Dados

### 3.1 Sistema Atual (Renomear)

| Atual | Novo |
|-------|------|
| `wp_ffc_calendars` | `wp_ffc_self_scheduling_calendars` |
| `wp_ffc_appointments` | `wp_ffc_self_scheduling_appointments` |
| `wp_ffc_blocked_dates` | `wp_ffc_self_scheduling_blocked_dates` |

### 3.2 Sistema Novo

#### wp_ffc_audience_schedules

```sql
CREATE TABLE wp_ffc_audience_schedules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    visibility ENUM('public', 'private') DEFAULT 'private',
    future_days_limit INT UNSIGNED DEFAULT NULL,
    notify_on_booking TINYINT(1) DEFAULT 1,
    notify_on_cancellation TINYINT(1) DEFAULT 1,
    email_template_booking TEXT,
    email_template_cancellation TEXT,
    include_ics TINYINT(1) DEFAULT 0,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_status (status),
    INDEX idx_created_by (created_by)
);
```

#### wp_ffc_audience_schedule_permissions

```sql
CREATE TABLE wp_ffc_audience_schedule_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    schedule_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    can_book TINYINT(1) DEFAULT 1,
    can_cancel_others TINYINT(1) DEFAULT 0,
    can_override_conflicts TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_schedule_user (schedule_id, user_id),
    INDEX idx_user (user_id),

    FOREIGN KEY (schedule_id) REFERENCES wp_ffc_audience_schedules(id) ON DELETE CASCADE
);
```

#### wp_ffc_audience_environments

```sql
CREATE TABLE wp_ffc_audience_environments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    schedule_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    working_hours JSON,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_schedule (schedule_id),
    INDEX idx_status (status),

    FOREIGN KEY (schedule_id) REFERENCES wp_ffc_audience_schedules(id) ON DELETE CASCADE
);
```

**Formato working_hours:**
```json
{
    "mon": {"start": "08:00", "end": "18:00", "closed": false},
    "tue": {"start": "08:00", "end": "18:00", "closed": false},
    "wed": {"start": "08:00", "end": "18:00", "closed": false},
    "thu": {"start": "08:00", "end": "18:00", "closed": false},
    "fri": {"start": "08:00", "end": "18:00", "closed": false},
    "sat": {"start": "08:00", "end": "12:00", "closed": false},
    "sun": {"closed": true}
}
```

#### wp_ffc_audience_holidays

```sql
CREATE TABLE wp_ffc_audience_holidays (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    schedule_id BIGINT UNSIGNED NOT NULL,
    holiday_date DATE NOT NULL,
    description VARCHAR(255),
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_schedule_date (schedule_id, holiday_date),
    INDEX idx_date (holiday_date),

    FOREIGN KEY (schedule_id) REFERENCES wp_ffc_audience_schedules(id) ON DELETE CASCADE
);
```

#### wp_ffc_audiences

```sql
CREATE TABLE wp_ffc_audiences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    color VARCHAR(7) DEFAULT '#3788d8',
    parent_id BIGINT UNSIGNED DEFAULT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_parent (parent_id),
    INDEX idx_status (status),

    FOREIGN KEY (parent_id) REFERENCES wp_ffc_audiences(id) ON DELETE SET NULL
);
```

#### wp_ffc_audience_members

```sql
CREATE TABLE wp_ffc_audience_members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audience_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_audience_user (audience_id, user_id),
    INDEX idx_user (user_id),

    FOREIGN KEY (audience_id) REFERENCES wp_ffc_audiences(id) ON DELETE CASCADE
);
```

#### wp_ffc_audience_bookings

```sql
CREATE TABLE wp_ffc_audience_bookings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    environment_id BIGINT UNSIGNED NOT NULL,
    booking_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    booking_type ENUM('audience', 'individual') NOT NULL,
    description VARCHAR(300) NOT NULL,
    status ENUM('active', 'cancelled') DEFAULT 'active',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    cancelled_by BIGINT UNSIGNED DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    cancellation_reason VARCHAR(500) DEFAULT NULL,

    INDEX idx_environment (environment_id),
    INDEX idx_date (booking_date),
    INDEX idx_status (status),
    INDEX idx_created_by (created_by),
    INDEX idx_env_date_status (environment_id, booking_date, status),

    FOREIGN KEY (environment_id) REFERENCES wp_ffc_audience_environments(id) ON DELETE CASCADE
);
```

#### wp_ffc_audience_booking_audiences

```sql
CREATE TABLE wp_ffc_audience_booking_audiences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    audience_id BIGINT UNSIGNED NOT NULL,

    UNIQUE KEY unique_booking_audience (booking_id, audience_id),
    INDEX idx_audience (audience_id),

    FOREIGN KEY (booking_id) REFERENCES wp_ffc_audience_bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (audience_id) REFERENCES wp_ffc_audiences(id) ON DELETE CASCADE
);
```

#### wp_ffc_audience_booking_users

```sql
CREATE TABLE wp_ffc_audience_booking_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,

    UNIQUE KEY unique_booking_user (booking_id, user_id),
    INDEX idx_user (user_id),

    FOREIGN KEY (booking_id) REFERENCES wp_ffc_audience_bookings(id) ON DELETE CASCADE
);
```

---

## 4. Estrutura de Pastas

```
includes/
├── self-scheduling/          # Renomeado de /calendars/
│   ├── class-ffc-self-scheduling-activator.php
│   ├── class-ffc-self-scheduling-handler.php
│   ├── class-ffc-self-scheduling-shortcode.php
│   └── ...
│
└── audience/                 # Novo sistema
    ├── class-ffc-audience-activator.php
    ├── class-ffc-audience-loader.php
    │
    ├── admin/
    │   ├── class-ffc-audience-schedules-admin.php
    │   ├── class-ffc-audience-environments-admin.php
    │   ├── class-ffc-audience-groups-admin.php
    │   ├── class-ffc-audience-bookings-admin.php
    │   └── class-ffc-audience-settings.php
    │
    ├── repositories/
    │   ├── class-ffc-audience-schedule-repository.php
    │   ├── class-ffc-audience-environment-repository.php
    │   ├── class-ffc-audience-group-repository.php
    │   ├── class-ffc-audience-member-repository.php
    │   └── class-ffc-audience-booking-repository.php
    │
    ├── frontend/
    │   ├── class-ffc-audience-shortcode.php
    │   └── class-ffc-audience-booking-handler.php
    │
    ├── services/
    │   ├── class-ffc-audience-conflict-checker.php
    │   ├── class-ffc-audience-email-handler.php
    │   └── class-ffc-audience-ics-generator.php
    │
    └── import/
        └── class-ffc-audience-csv-importer.php

assets/
├── css/
│   ├── ffc-self-scheduling-admin.css
│   ├── ffc-self-scheduling-frontend.css
│   ├── ffc-audience-admin.css
│   └── ffc-audience-frontend.css
└── js/
    ├── ffc-self-scheduling-admin.js
    ├── ffc-self-scheduling-frontend.js
    ├── ffc-audience-admin.js
    └── ffc-audience-frontend.js
```

---

## 5. Menu Admin

```
FFC Agendamentos
├── Auto-Agendamento
│   ├── Calendários
│   └── Agendamentos
├── Públicos-Alvo
│   ├── Calendários
│   ├── Ambientes
│   ├── Grupos
│   ├── Agendamentos
│   └── Importar CSV
└── Configurações
```

---

## 6. Capabilities

| Capability | Descrição |
|------------|-----------|
| `ffc_view_self_scheduling` | Ver próprios auto-agendamentos |
| `ffc_book_audience` | Agendar públicos-alvo (requer permissão no calendário) |
| `ffc_view_audience_booking` | Ver agendamentos onde está incluído |
| `ffc_manage_audiences` | Gerenciar grupos (admin) |
| `ffc_override_conflicts` | Sobrescrever conflitos |

---

## 7. Regras de Negócio

### 7.1 Hierarquia de Público-alvo

- **2 níveis:** Mãe → Filhos
- Agendar **mãe** = filhos automaticamente incluídos
- Agendar **mãe** = filhos entram em **conflito** se agendados separadamente
- Agendar **filho** = outros filhos **NÃO** conflitam

### 7.2 Regras de Conflito

| Situação | Comportamento |
|----------|---------------|
| Mesmo público-alvo, horário sobreposto | **Bloqueia** agendamento |
| Mesmo ambiente, horário sobreposto | **Alerta** + checkbox confirmação |
| Usuário em múltiplos grupos agendados | **Alerta** + checkbox confirmação |
| Usuário individual já agendado | **Alerta** + checkbox confirmação |

### 7.3 Validações

| Validação | Regra |
|-----------|-------|
| Descrição | Mínimo 15, máximo 300 caracteres |
| Horário início | ≥ horário abertura do ambiente |
| Horário fim | ≤ horário fechamento do ambiente |
| Dias futuros | Não ultrapassa limite do calendário (não se aplica ao admin) |
| Exclusão de entidades | Bloqueada se há agendamentos futuros |

### 7.4 Toggle Exclusivo

- Agendamento é **OU** para público-alvo **OU** para usuários individuais
- Não é possível misturar ambos no mesmo agendamento

---

## 8. Fluxo de Agendamento (Frontend)

1. Usuário acessa página com `[ffc_audience id="X"]`
2. Seleciona **Ambiente** (combo-box dos ambientes com permissão)
3. Vê **grade mensal** do ambiente com navegação
4. Clica em **data** → detalhes dos agendamentos existentes
5. Seleciona **horário** (dentro do funcionamento do ambiente)
6. Toggle: **Público-alvo** OU **Usuários individuais**
   - Público-alvo: seleção múltipla de grupos
   - Usuários: autocomplete por nome/email/CPF_RF
7. Preenche **descrição** (15-300 caracteres, obrigatória)
8. Sistema valida conflitos e limite de dias futuros
9. Se conflito permitido → checkbox "Estou ciente do conflito"
10. **Confirma** agendamento

---

## 9. Visualização

### 9.1 Grade do Calendário (Frontend)

- Formato **mensal** com navegação entre meses
- Agendamentos mostram cor do público-alvo
- Múltiplos públicos = **gradiente/listras** das cores
- Clicar no dia = detalhamento (ambiente/horário/público)

### 9.2 Dashboard do Usuário

- Aba única **"Agendamentos"**
- Lista unificada com ícones:
  - 👤 **Pessoal** (auto-agendamento)
  - 👥 **Grupo: [Nome]** (público-alvo)
- Toggle: **Futuros** / **Anteriores** (365 dias cada direção)
- Agendamentos cancelados visíveis com motivo

---

## 10. Cancelamento

- Clicar no agendamento na grade → modal
- Motivo **obrigatório**
- Campos salvos: `cancelled_by`, `cancelled_at`, `cancellation_reason`
- Slot **desaparece** da grade (para quem agenda)
- Permanece **visível como cancelado** no dashboard do usuário agendado

---

## 11. Notificações por E-mail

| Evento | Configuração |
|--------|--------------|
| Novo agendamento | On/Off (configurável) |
| Cancelamento | On/Off (configurável) |

- Conteúdo do email **configurável** pelo admin
- Opção de incluir arquivo **.ics** (on/off)

---

## 12. Importação CSV

- **Campos:** nome, email, CPF_RF, público-alvo
- Se usuário **existe** (por email/CPF_RF) → associa ao grupo
- Se **não existe** → cria como "FFC User"
- Senha gerada pelo WordPress e enviada por email

---

## 13. Permissões

| Ação | Quem pode |
|------|-----------|
| Criar/Editar/Excluir Calendário, Ambiente, Grupo | Admin |
| Agendar | Admin + Usuários autorizados no calendário |
| Cancelar agendamento | Admin + Criador + Usuários com permissão específica |
| Sobrescrever conflitos | Admin + Usuários com `ffc_override_conflicts` |
| Visualizar calendário público | Todos (incluindo guest) |
| Visualizar calendário privado | Usuários logados |

---

## 14. Decisões Técnicas

- ❌ Sem backward compatibility (shortcodes/hooks antigos)
- ✅ Limpeza de tabelas não usadas na migration
- ✅ Configurações em página própria no menu admin
- ✅ Reutilizar: Repository pattern, Encryption, Utils, Email handler, User manager

---

## 15. Fases de Implementação

| Fase | Escopo |
|------|--------|
| **1** | Migration: renomear tabelas/pasta + limpeza |
| **2** | Criar estrutura base do novo sistema (tabelas, classes) |
| **3** | Admin: CRUD Calendários, Ambientes, Grupos |
| **4** | Admin: Importação CSV |
| **5** | Frontend: Shortcode + grade mensal |
| **6** | Frontend: Fluxo de agendamento + conflitos |
| **7** | Dashboard: visualização unificada |
| **8** | Notificações: email + .ics |

---

## 16. Hooks

### Sistema Auto-agendamento (Renomeados)

```php
do_action('ffc_self_scheduling_appointment_created', $appointment, $calendar);
do_action('ffc_self_scheduling_appointment_cancelled', $appointment, $reason);
do_action('ffc_self_scheduling_appointment_confirmed', $appointment, $calendar);
```

### Sistema Públicos-alvo (Novos)

```php
do_action('ffc_audience_booking_created', $booking, $schedule);
do_action('ffc_audience_booking_cancelled', $booking, $reason);
```

---

## Changelog

- **2026-02-05** - Versão 1.0 - Especificação inicial aprovada
