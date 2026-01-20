# 🔍 Diagnóstico do Banco de Dados Legado

## 📋 O que Este Diagnóstico Faz?

Este SQL **apenas LÊ** dados do banco de dados. **NÃO modifica nada**.

Ele vai me ajudar a entender:
1. ✅ Quais colunas existem na sua tabela
2. ✅ Quantas submissions precisam ser migradas
3. ✅ Se já existem colunas de encriptação
4. ✅ Se há conflitos potenciais (emails duplicados)
5. ✅ Estado atual das migrações

---

## 🚀 Como Executar?

### Opção 1: phpMyAdmin (Recomendado)
1. Acesse seu **phpMyAdmin**
2. Selecione o banco de dados do WordPress
3. Clique na aba **SQL**
4. Cole o conteúdo do arquivo `diagnostico-banco-legado.sql`
5. Clique em **Executar**
6. **Copie TODOS os resultados** e me envie

### Opção 2: Linha de Comando (MySQL CLI)
```bash
# Se você tiver acesso SSH
mysql -u seu_usuario -p nome_do_banco < diagnostico-banco-legado.sql
```

---

## 📊 O Que o Diagnóstico Vai Mostrar?

### PARTE 1: Estrutura da Tabela
```
Field                Type             Null    Key     Default
------------------------------------------------------------
id                   int(11)          NO      PRI     NULL
form_id              int(11)          NO              NULL
email                text             YES             NULL
email_encrypted      text             YES             NULL
cpf_rf               varchar(255)     YES             NULL
cpf_rf_hash          varchar(64)      YES             NULL
data                 longtext         YES             NULL
data_encrypted       longtext         YES             NULL
user_id              bigint(20)       YES             NULL
...
```
**O que significa:** Mostra todas as colunas e seus tipos.

---

### PARTE 2: Informações Gerais
```
Metrica                                          | Valor
-------------------------------------------------|-------
Total de Submissions                             | 1500
Submissions com email (não criptografado)        | 1500
Submissions com email_encrypted                  | 0
Submissions com data (não criptografado)         | 1500
Submissions com data_encrypted                   | 0
Submissions com cpf_rf (não hash)                | 1500
Submissions com cpf_rf_hash                      | 0
```

**Interpretação:**
- ✅ **1500 submissions** existentes
- ❌ **0 criptografadas** → Precisa migrar TODAS
- ⚠️ **Dados sensíveis não protegidos** → Migração URGENTE

---

### PARTE 3: Estado da Coluna user_id
```
status_coluna_user_id
-------------------------------------
NÃO - Coluna user_id não existe
```

**Interpretação:**
- ❌ Coluna `user_id` **não existe** → A migração vai criar
- ✅ Primeira vez rodando migração User Link

OU:

```
status_coluna_user_id
-------------------------------------
SIM - Coluna user_id existe

Submissions com user_id: 800
```

**Interpretação:**
- ✅ Coluna `user_id` **já existe**
- ⚠️ 800 submissions já linkadas, 700 pendentes

---

### PARTE 4: Amostra de Estrutura
```
id  | email_status | email_encrypted_status | cpf_rf_status | data_status
----|--------------|------------------------|---------------|-------------
1   | TEM_DADO     | NULL                   | TEM_DADO      | TEM_DADO
2   | TEM_DADO     | NULL                   | TEM_DADO      | TEM_DADO
3   | TEM_DADO     | NULL                   | TEM_DADO      | TEM_DADO
```

**Interpretação:**
- ✅ Submissions **TÊM** dados não criptografados
- ❌ **NULL** nas colunas criptografadas
- ⚠️ **Precisa migrar**

---

### PARTE 5: Colunas Existentes
```
COLUMN_NAME          | DATA_TYPE      | IS_NULLABLE
---------------------|----------------|-------------
email                | text           | YES
email_encrypted      | text           | YES
cpf_rf               | varchar(255)   | YES
cpf_rf_hash          | varchar(64)    | YES
data                 | longtext       | YES
data_encrypted       | longtext       | YES
user_id              | bigint(20)     | YES  ← (se existir)
```

**Interpretação:**
- ✅ Colunas de **encriptação JÁ EXISTEM** → Banco está preparado
- ⚠️ Só falta **popular com dados criptografados**

OU:

- ❌ Algumas colunas **NÃO EXISTEM** → As migrações vão criar

---

### PARTE 6: Estado das Migrações
```
Migracao                      | Pendentes | Migrados
------------------------------|-----------|----------
EMAIL → EMAIL_ENCRYPTED       | 1500      | 0
CPF_RF → CPF_RF_HASH          | 1500      | 0
DATA → DATA_ENCRYPTED         | 1500      | 0
```

**Interpretação:**
- ❌ **1500 pendentes** em CADA migração
- ✅ **0% concluído** → Primeira execução
- ⚠️ Todas as 3 migrações precisam rodar

---

### PARTE 7: Conflitos Potenciais
```
tipo_conflito                        | emails_unicos | total_registros | duplicatas
-------------------------------------|---------------|-----------------|------------
Emails duplicados (não criptografados) | 1450          | 1500            | 50
```

**Interpretação:**
- ⚠️ **50 duplicatas** de email
- ⚠️ 50 pessoas têm múltiplas submissions
- ✅ **NORMAL** em sistemas de certificados (pessoa faz curso várias vezes)
- ✅ Migração User Link vai **linkar todas ao mesmo user**

---

## 🎯 O Que Vou Analisar Com Esses Dados?

Com os resultados, vou responder suas 3 perguntas:

### 1️⃣ **Posso Desativar/Ativar o Plugin Após Atualização?**
✅ **SIM**, mas com cuidados:
- Se colunas de encriptação **já existem**: Seguro
- Se colunas **não existem**: O plugin vai criar automaticamente

### 2️⃣ **Todas as Submissions Serão Migradas Sem Erros?**
Depende do diagnóstico:
- ✅ Se dados estão **bem formatados** (email válido, CPF válido): SIM
- ⚠️ Se há **dados corrompidos** (email NULL, JSON inválido): Alguns vão falhar
- ✅ Migrações têm **tratamento de erro** → Registros problemáticos são logados

### 3️⃣ **Preciso Fazer Mais Adaptações no Código?**
Vou verificar:
- ✅ Se estrutura do banco é compatível
- ❌ Se há colunas extras/personalizadas que não conheço
- ⚠️ Se há customizações que quebram as migrações

---

## 📤 Me Envie Os Resultados

Depois de executar o SQL, copie **TODOS** os resultados (todas as 7 partes) e me envie.

Com isso, vou:
1. ✅ Confirmar compatibilidade
2. ✅ Identificar riscos
3. ✅ Criar plano de migração específico para seu caso
4. ✅ Criar SQL de correção (se necessário)
5. ✅ Criar checklist de migração passo-a-passo

---

## 🛡️ Garantias de Segurança

✅ **Este SQL NÃO:**
- ❌ Modifica dados
- ❌ Deleta registros
- ❌ Altera estrutura
- ❌ Mostra dados sensíveis (CPF, email, etc)

✅ **Este SQL APENAS:**
- ✅ Lê metadados
- ✅ Conta registros
- ✅ Mostra estrutura
- ✅ Verifica status

**É 100% SEGURO executar em produção.**

---

## ❓ Problemas?

Se tiver erro ao executar:
1. Verifique se o nome da tabela é `wp_ffc_submissions`
2. Se o prefixo for diferente (ex: `wpx_`), substitua no SQL
3. Me envie a mensagem de erro completa

---

**Aguardo os resultados! 🚀**
