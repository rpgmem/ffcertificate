# ✅ Checklist de Migração - Plugin v3.1.1

## 📋 Pré-Requisitos (ANTES de Atualizar)

### ☑️ 1. Backup Completo
```bash
# Backup do Banco de Dados
mysqldump -u usuario -p nome_banco > backup_antes_migracao_$(date +%Y%m%d_%H%M%S).sql

# Backup dos Arquivos do Plugin
tar -czf backup_plugin_$(date +%Y%m%d_%H%M%S).tar.gz wp-content/plugins/wp-ffcertificate/
```

**✅ Verificação:** Confirme que os backups foram criados e têm tamanho > 0

---

### ☑️ 2. Executar Diagnóstico
1. Execute o SQL: `diagnostico-banco-legado.sql`
2. Me envie os resultados completos
3. **AGUARDE** minha análise antes de continuar

**❌ NÃO prossiga sem minha confirmação após análise do diagnóstico**

---

### ☑️ 3. Verificar Chaves de Encriptação

Verifique se as chaves de encriptação estão configuradas em `wp-config.php`:

```php
// Em wp-config.php, procure por:
define('FFC_ENCRYPTION_KEY', '...'); // Deve existir
define('FFC_ENCRYPTION_SALT', '...'); // Deve existir
```

**Se NÃO existirem:**
```php
// Adicione ANTES de "That's all, stop editing!"
define('FFC_ENCRYPTION_KEY', 'sua-chave-super-secreta-aqui-32-caracteres-min');
define('FFC_ENCRYPTION_SALT', 'seu-salt-super-secreto-aqui-32-caracteres-min');
```

**⚠️ IMPORTANTE:**
- Use chaves **únicas** e **aleatórias**
- **NUNCA** compartilhe ou commite essas chaves
- **GUARDE** em local seguro (sem elas, não consegue descriptografar)

---

## 🚀 Processo de Atualização

### ☑️ 4. Modo de Manutenção

```php
// Em wp-config.php, adicione temporariamente:
define('WP_MAINTENANCE', true);
```

Ou use plugin de manutenção para mostrar mensagem aos usuários.

---

### ☑️ 5. Desativar Plugin Atual

```bash
# Via WP-CLI (se disponível):
wp plugin deactivate wp-ffcertificate

# Ou via Admin:
# Plugins > Desativar "WP FF Certificate"
```

**✅ Verificação:** Plugin aparece como "Inativo" na lista

---

### ☑️ 6. Atualizar Arquivos do Plugin

```bash
# Opção 1: Substituir pasta completa (Recomendado)
rm -rf wp-content/plugins/wp-ffcertificate/
# Depois, faça upload da nova versão

# Opção 2: Git (se estiver usando)
cd wp-content/plugins/wp-ffcertificate/
git pull origin main
```

**✅ Verificação:** Verifique que novos arquivos existem:
- `includes/admin/class-ffc-settings-save-handler.php` ✅
- `includes/admin/class-ffc-admin-activity-log-page.php` ✅
- `includes/migrations/strategies/class-ffc-user-link-migration-strategy.php` ✅

---

### ☑️ 7. Ativar Plugin Atualizado

```bash
# Via WP-CLI:
wp plugin activate wp-ffcertificate

# Ou via Admin:
# Plugins > Ativar "WP FF Certificate"
```

**⚠️ CUIDADO:** Ao ativar, o plugin pode:
- Criar novas colunas automaticamente
- Criar tabela de Activity Log
- **NÃO VAI** executar migrações automaticamente (você controla)

---

### ☑️ 8. Verificar Logs de Erro

```bash
# Verificar error_log do PHP
tail -f /var/log/php_errors.log

# Ou verificar debug.log do WordPress
tail -f wp-content/debug.log
```

**✅ Esperado:** Nenhum erro fatal ao ativar

---

## 🔄 Executar Migrações (PASSO CRÍTICO)

### ☑️ 9. Acessar Página de Migrações

1. Vá para: **Admin > Forms > Settings > Migrations**
2. Você verá lista de migrações disponíveis

---

### ☑️ 10. Ordem CORRETA de Execução

**IMPORTANTE:** Execute as migrações **NESTA ORDEM EXATA:**

#### **Migração 1: Encrypt Sensitive Data** (PRIMEIRA - OBRIGATÓRIA)
```
Nome: "Encrypt Sensitive Data (LGPD)"
Status: Pendentes: 1500 | Migrados: 0
```

**O que faz:**
- Criptografa `email` → `email_encrypted`
- Criptografa `cpf_rf` → Hash SHA-256 em `cpf_rf_hash`
- Criptografa `data` (JSON) → `data_encrypted`
- Criptografa `user_ip` → `user_ip_encrypted`

**Ação:**
1. Clique em **"Run Migration"**
2. **AGUARDE** (pode levar minutos se tiver muitas submissions)
3. Verifique que status mudou para: `Pendentes: 0 | Migrados: 1500`

**✅ Verificação:**
```sql
SELECT COUNT(*) FROM wp_ffc_submissions WHERE email_encrypted IS NOT NULL;
-- Deve retornar o mesmo número total de submissions
```

---

#### **Migração 2: User Link** (SEGUNDA - RECOMENDADA)
```
Nome: "User Link (Link submissions to WordPress users)"
Status: Pendentes: 1500 | Migrados: 0
```

**O que faz:**
- Cria coluna `user_id` se não existir
- Para cada submission:
  1. Verifica se CPF/RF já tem user_id → Reutiliza
  2. Verifica se email existe no WordPress → Linka
  3. Se não: Cria novo usuário com role `ffc_user`
- Atualiza `display_name` com nome da submission

**⚠️ Pré-requisito:** Migração #1 (Encrypt) **deve estar completa**

**Ação:**
1. Clique em **"Run Migration"**
2. **AGUARDE** (pode levar mais tempo que #1)
3. Verifique logs de erros (se houver)

**✅ Verificação:**
```sql
SELECT COUNT(*) FROM wp_ffc_submissions WHERE user_id IS NOT NULL;
-- Deve retornar número próximo ao total (alguns podem falhar por conflito)
```

---

#### **Migração 3: Cleanup Unencrypted** (TERCEIRA - OPCIONAL MAS RECOMENDADA)
```
Nome: "Cleanup Unencrypted Data (Remove plain text)"
Status: Pendentes: 1500 | Migrados: 0
```

**O que faz:**
- **REMOVE** dados sensíveis não criptografados
- Define `email = NULL`
- Define `cpf_rf = NULL`
- Define `data = NULL`
- Define `user_ip = NULL`
- **MANTÉM** apenas versões criptografadas

**⚠️ ATENÇÃO:** Esta migração é **IRREVERSÍVEL**!
- Depois de rodar, **NÃO há volta**
- Certifique-se que migrações #1 e #2 rodaram OK
- **FAÇA BACKUP ANTES**

**Ação:**
1. **CONFIRME** que Encrypt (#1) está 100% OK
2. **CONFIRME** que User Link (#2) rodou (mesmo com alguns erros)
3. Clique em **"Run Migration"**

**✅ Verificação:**
```sql
SELECT COUNT(*) FROM wp_ffc_submissions WHERE email IS NULL AND email_encrypted IS NOT NULL;
-- Deve retornar o total de submissions (dados plain text removidos)
```

---

## 🧪 Testes Pós-Migração

### ☑️ 11. Testar Criação de Nova Submission

1. Acesse formulário público
2. Preencha e envie
3. Verifique que foi salvo com dados criptografados

**✅ Verificação SQL:**
```sql
SELECT id, email_encrypted IS NOT NULL AS tem_email_encrypted,
       data_encrypted IS NOT NULL AS tem_data_encrypted
FROM wp_ffc_submissions
ORDER BY id DESC LIMIT 1;
-- Ambos devem ser 1 (TRUE)
```

---

### ☑️ 12. Testar Magic Link (Verificação)

1. Copie magic link de uma submission antiga
2. Acesse o link
3. Verifique que dados aparecem corretamente descriptografados

**✅ Esperado:** Dados descriptografados e exibidos corretamente

---

### ☑️ 13. Testar Admin Edit

1. Admin > Forms > Submissions
2. Clique para editar uma submission
3. Verifique que dados aparecem descriptografados
4. Faça uma alteração e salve
5. Verifique que salvou corretamente

---

### ☑️ 14. Verificar Activity Log (Se Habilitado)

1. Settings > General > Activity Log Settings
2. Marque "Enable Activity Log"
3. Salve
4. Faça algumas ações (criar submission, editar, etc)
5. Acesse: Forms > Activity Log
6. Verifique que logs aparecem

---

## 🔍 Verificações de Segurança

### ☑️ 15. Verificar Dados Sensíveis Removidos

```sql
-- Este SQL deve retornar 0 após Cleanup:
SELECT COUNT(*) FROM wp_ffc_submissions
WHERE email IS NOT NULL AND email != '';

SELECT COUNT(*) FROM wp_ffc_submissions
WHERE cpf_rf IS NOT NULL AND cpf_rf != '';

SELECT COUNT(*) FROM wp_ffc_submissions
WHERE data IS NOT NULL AND data != '';
```

**✅ Esperado:** 0 em todas as queries (dados plain text removidos)

---

### ☑️ 16. Verificar Criptografia Funcionando

```sql
-- Pega uma submission aleatória
SELECT email_encrypted, data_encrypted
FROM wp_ffc_submissions
WHERE email_encrypted IS NOT NULL
LIMIT 1;
```

**✅ Esperado:** Strings longas e ilegíveis (base64 encoded)
**❌ Problema:** Se aparecer email legível = NÃO está criptografado

---

## ✅ Finalização

### ☑️ 17. Desativar Modo Manutenção

```php
// Remova de wp-config.php:
// define('WP_MAINTENANCE', true);
```

---

### ☑️ 18. Monitorar Erros (Primeiras 24h)

```bash
# Monitore logs continuamente:
tail -f wp-content/debug.log
tail -f /var/log/php_errors.log
```

**Fique atento a:**
- Erros de decriptação
- Erros ao criar submissions
- Erros no magic link

---

### ☑️ 19. Backup Pós-Migração

```bash
# Backup do banco APÓS migração (para rollback rápido se der problema):
mysqldump -u usuario -p nome_banco > backup_apos_migracao_$(date +%Y%m%d_%H%M%S).sql
```

---

## 🆘 Troubleshooting

### Erro: "Class FFC_Activity_Log not found"
**Solução:** Já corrigido na v3.1.1 (commit b346e41)

### Erro: "Call to undefined method calculate_status()"
**Solução:** Já corrigido na v3.1.1 (commit 0bb6093)

### Erro: "Encryption key not configured"
**Solução:** Adicione chaves em wp-config.php (ver passo #3)

### Migração falha com "timeout"
**Solução:**
1. Aumente `max_execution_time` no php.ini
2. Ou rode migração em batches menores (modificar código)
3. Ou rode via WP-CLI (sem timeout de browser)

### Alguns usuários não foram linkados
**Solução:**
1. Verifique logs em: Options > `ffc_migration_user_link_errors`
2. Emails duplicados com CPFs diferentes = conflito normal
3. Esses registros ficam com `user_id = NULL` (esperar resolução manual)

---

## 📞 Suporte

Se encontrar problemas:
1. ✅ Anote mensagem de erro completa
2. ✅ Copie logs relevantes
3. ✅ Me envie diagnóstico + erro
4. ✅ **NÃO** rode Cleanup (#3) se tiver dúvidas

---

**Boa sorte com a migração! 🚀**
