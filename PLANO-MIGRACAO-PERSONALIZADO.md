# 🎯 PLANO DE MIGRAÇÃO PERSONALIZADO
## Para Banco Legado (sem colunas de encriptação)

**Seu Caso:** 267 submissions | Banco v1.x/v2.x | Sem colunas de segurança

---

## ✅ PRÉ-REQUISITOS (CRÍTICOS)

### 1. Backup Completo
```bash
# No phpMyAdmin ou SSH:
mysqldump -u usuario -p nome_banco > backup_pre_migracao_$(date +%Y%m%d_%H%M%S).sql

# Ou no phpMyAdmin:
# Banco > Exportar > SQL > Executar
```

**✅ Verificação:** Arquivo .sql criado com tamanho > 100 KB

---

### 2. Configurar Chaves de Encriptação

**ANTES de ativar o plugin v3.1.1**, adicione em `wp-config.php`:

```php
// Cole ANTES de "/* That's all, stop editing! Happy publishing. */"

// Chaves de Encriptação LGPD (v3.1.1)
// GERE chaves únicas usando: https://randomkeygen.com/ (256-bit keys)
define('FFC_ENCRYPTION_KEY', 'SUA-CHAVE-SUPER-SECRETA-AQUI-MIN-32-CARACTERES');
define('FFC_ENCRYPTION_SALT', 'SEU-SALT-SUPER-SECRETO-AQUI-MIN-32-CARACTERES');
```

**⚠️ IMPORTANTE:**
- Use chaves DIFERENTES para KEY e SALT
- Mínimo 32 caracteres cada
- Use caracteres especiais, números, maiúsculas
- **NUNCA compartilhe ou commite no Git**
- **GUARDE em local seguro** (sem elas, perde acesso aos dados!)

**Exemplo de chaves fortes:**
```php
define('FFC_ENCRYPTION_KEY', 'k8Qp2#mN7$xR9@vL3!dF6^hJ4%tY1&bW5*gS8(cE2)zA0+uI');
define('FFC_ENCRYPTION_SALT', 'n5Vr1!pX4#mK7@cL2$hN9^fJ6%tB3&wQ8*yD0(gS5)eA1+zI');
```

---

## 🚀 ETAPA 1: ATUALIZAÇÃO DO PLUGIN (15 min)

### 1.1. Modo Manutenção (Opcional mas Recomendado)
```php
// Em wp-config.php, adicione temporariamente:
define('WP_MAINTENANCE', true);
```

### 1.2. Desativar Plugin Atual
```
Admin > Plugins > WP FF Certificate > Desativar
```

### 1.3. Substituir Arquivos
```bash
# Opção 1: FTP/cPanel
1. Baixe backup da pasta atual: wp-content/plugins/wp-ffcertificate/
2. Delete a pasta atual
3. Faça upload da v3.1.1

# Opção 2: SSH/Git
cd wp-content/plugins/wp-ffcertificate/
git pull origin main  # Ou substitua manualmente
```

### 1.4. Ativar Plugin v3.1.1
```
Admin > Plugins > WP FF Certificate > Ativar
```

**✅ O QUE ACONTECE AO ATIVAR:**
```
🔧 Plugin detecta: "Colunas de segurança não existem"
🔧 Plugin CRIA automaticamente:
   ✅ email_encrypted (text)
   ✅ data_encrypted (longtext)
   ✅ cpf_rf_hash (varchar 64)
   ✅ user_ip_encrypted (text)
   ✅ user_id (bigint)
   ✅ magic_token (varchar 64)
   ✅ auth_code (varchar 10)

✅ Resultado: Banco PRONTO para migrações
⏱️ Tempo: ~10 segundos
```

### 1.5. Verificar Ativação
```
Acesse: Admin > Forms

✅ Esperado: Menu aparece normalmente
❌ Erro?: Me envie mensagem de erro completa
```

---

## 🔄 ETAPA 2: EXECUTAR MIGRAÇÕES (30-60 min)

Acesse: **Admin > Forms > Settings > Migrations**

### ⚡ MIGRAÇÃO #1: Encrypt Sensitive Data (PRIMEIRA - OBRIGATÓRIA)

**Status Inicial:**
```
Total: 267 registros
Pendentes: 267
Migrados: 0
```

**Ação:**
1. Clique em **"Run Migration"**
2. Aguarde (pode levar 1-2 minutos)
3. ✅ Verifique que mudou para: `Pendentes: 0 | Migrados: 267`

**O que acontece:**
```
Para CADA uma das 267 submissions:
✅ Lê email (texto puro)
✅ Criptografa com AES-256
✅ Salva em email_encrypted

✅ Lê data (JSON texto puro)
✅ Criptografa com AES-256
✅ Salva em data_encrypted

✅ Extrai CPF do JSON (se existir)
✅ Gera hash SHA-256
✅ Salva em cpf_rf_hash

✅ Lê user_ip (texto puro)
✅ Criptografa com AES-256
✅ Salva em user_ip_encrypted

⏱️ Tempo estimado: 267 × 0.3s = ~80 segundos
```

**✅ Verificação SQL:**
```sql
SELECT COUNT(*) FROM wrrel_ffc_submissions
WHERE email_encrypted IS NOT NULL;
-- Deve retornar: 267
```

---

### 👥 MIGRAÇÃO #2: User Link (SEGUNDA - RECOMENDADA)

**Status após #1:**
```
Total: 267 registros
Pendentes: 267
Migrados: 0
```

**Ação:**
1. Clique em **"Run Migration"**
2. Aguarde (pode levar 2-3 minutos)
3. ✅ Verifique resultado (deve ser ~265 sucesso, 2 conflitos)

**O que acontece:**
```
Para CADA submission:
1. Descriptografa email
2. Verifica se email existe no WordPress:
   - SIM: Linka ao usuário existente
   - NÃO: Cria novo usuário com role 'ffc_user'
3. Atualiza user_id na submission
4. Define display_name do usuário (extraído do JSON)

⏱️ Tempo estimado: 267 × 0.5s = ~135 segundos

Resultado esperado:
✅ ~265 usuários criados/linkados
⚠️ 2 possíveis conflitos (emails duplicados - NORMAL)
```

**Conflitos (2 duplicatas):**
```
Submission #50: email duplicado@example.com → User #100 ✅
Submission #100: email duplicado@example.com → User #100 ✅
(Ambas linkadas ao mesmo usuário - CORRETO)
```

**✅ Verificação SQL:**
```sql
SELECT COUNT(*) FROM wrrel_ffc_submissions
WHERE user_id IS NOT NULL;
-- Deve retornar: ~265-267
```

---

### 🧹 MIGRAÇÃO #3: Cleanup Unencrypted (TERCEIRA - OPCIONAL)

⚠️ **ATENÇÃO:** Esta migração é **IRREVERSÍVEL**!

**O que faz:**
```
Para CADA submission:
❌ DELETE dados de: email (texto puro)
❌ DELETE dados de: data (JSON texto puro)
❌ DELETE dados de: user_ip (texto puro)
❌ DELETE dados de: cpf_rf (texto puro)

✅ MANTÉM: Versões criptografadas
✅ MANTÉM: Hashes
✅ MANTÉM: IDs e metadados
```

**ANTES de rodar:**
1. ✅ Confirme que #1 rodou 100% OK (267 migrados)
2. ✅ Teste acessar algumas submissions no admin (dados aparecem?)
3. ✅ Teste alguns magic links (funcionam?)
4. ✅ Faça NOVO backup pós-migração #1 e #2

**Ação:**
1. Clique em **"Run Migration"**
2. Confirme (popup de aviso)
3. Aguarde (~30 segundos)

**✅ Verificação SQL:**
```sql
SELECT COUNT(*) FROM wrrel_ffc_submissions
WHERE email IS NULL AND email_encrypted IS NOT NULL;
-- Deve retornar: 267 (todos limpos)
```

---

## 🧪 ETAPA 3: TESTES PÓS-MIGRAÇÃO (15 min)

### Teste 1: Acessar Submission Antiga via Admin
```
1. Admin > Forms > Submissions
2. Clique em qualquer submission antiga
3. ✅ Verificar que dados aparecem descriptografados
4. ✅ Email, nome, dados aparecem normalmente
```

### Teste 2: Magic Link
```
1. Copie magic link de uma submission
2. Abra em navegador anônimo
3. ✅ Verificar que certificado aparece
4. ✅ Dados corretos exibidos
```

### Teste 3: Criar Nova Submission
```
1. Acesse formulário público
2. Preencha e envie
3. ✅ Verificar que salvou
4. ✅ Verificar que dados já salvos criptografados
```

**SQL de Verificação:**
```sql
-- Pegar última submission:
SELECT id,
       email_encrypted IS NOT NULL AS tem_email_cript,
       data_encrypted IS NOT NULL AS tem_data_cript,
       user_id IS NOT NULL AS tem_user
FROM wrrel_ffc_submissions
ORDER BY id DESC LIMIT 1;

-- Ambos devem ser 1 (TRUE)
```

---

## 🎉 ETAPA 4: FINALIZAÇÃO (5 min)

### 1. Desativar Modo Manutenção
```php
// Remova de wp-config.php:
// define('WP_MAINTENANCE', true);
```

### 2. Habilitar Activity Log (Opcional)
```
Admin > Forms > Settings > General
☑️ Enable Activity Log
Save Changes
```

### 3. Backup Pós-Migração
```bash
mysqldump -u usuario -p nome_banco > backup_pos_migracao_$(date +%Y%m%d_%H%M%S).sql
```

### 4. Monitorar (Primeiras 24h)
```bash
# Verificar logs de erro:
tail -f wp-content/debug.log

# Ou ativar debug temporariamente:
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

---

## ⏱️ RESUMO DE TEMPO

| Etapa | Tempo Estimado |
|-------|----------------|
| Pré-requisitos (backup, chaves) | 15 min |
| Atualização do plugin | 5 min |
| Migração #1 (Encrypt) | 2 min |
| Migração #2 (User Link) | 3 min |
| Migração #3 (Cleanup) | 1 min |
| Testes | 15 min |
| **TOTAL** | **~40 minutos** |

---

## ✅ CHECKLIST FINAL

Antes de começar:
- [ ] Backup completo feito
- [ ] Chaves de encriptação configuradas em wp-config.php
- [ ] Chaves salvas em local seguro (cofre de senhas)
- [ ] Leu este plano completamente

Durante:
- [ ] Plugin desativado
- [ ] Arquivos substituídos pela v3.1.1
- [ ] Plugin ativado (colunas criadas automaticamente)
- [ ] Migração #1 executada (100% sucesso)
- [ ] Migração #2 executada (~99% sucesso)
- [ ] Migração #3 executada (opcional)

Após:
- [ ] Teste admin: Ver submission antiga ✅
- [ ] Teste magic link ✅
- [ ] Teste criar nova submission ✅
- [ ] Backup pós-migração feito
- [ ] Modo manutenção desativado

---

## 🆘 EM CASO DE PROBLEMAS

**Erro ao ativar plugin:**
```
1. Verifique se chaves estão em wp-config.php
2. Verifique syntax das chaves (aspas corretas)
3. Ative WP_DEBUG e veja wp-content/debug.log
4. Me envie erro completo
```

**Migração falha:**
```
1. NÃO entre em pânico
2. Dados originais AINDA estão no banco
3. Restore backup se necessário
4. Me envie erro para análise
```

**Dados não aparecem após migração:**
```
1. Verifique se chaves de encriptação estão corretas
2. Teste descriptografar manualmente:
   Admin > Settings > Migrations > Testar Decrypt
3. Se chaves mudaram: PROBLEMA (restore backup)
```

---

## 📞 SUPORTE

Se tiver QUALQUER dúvida ou problema:
1. ✅ NÃO continue se não tiver certeza
2. ✅ Me envie:
   - Mensagem de erro completa
   - Em qual etapa parou
   - Logs relevantes (debug.log, php_errors.log)
3. ✅ Aguarde minha resposta antes de prosseguir

---

**Seu banco está em ESTADO IDEAL para migração.**
**Taxa de sucesso esperada: 99-100%**
**Tempo total: ~40 minutos**

**SUCESSO NA MIGRAÇÃO! 🚀**
