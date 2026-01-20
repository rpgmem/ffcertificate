-- ============================================
-- DIAGNÓSTICO: Banco de Dados Legado
-- Plugin: WP FF Certificate
-- Objetivo: Verificar compatibilidade com migrações v3.1.1
-- ============================================

-- IMPORTANTE: Execute este SQL e me envie os resultados
-- Não se preocupe: este SQL NÃO modifica nada, apenas lê dados

-- ============================================
-- PARTE 1: Estrutura da Tabela
-- ============================================
SELECT '=== ESTRUTURA DA TABELA wp_ffc_submissions ===' AS '';

DESCRIBE wp_ffc_submissions;

-- ============================================
-- PARTE 2: Informações da Tabela
-- ============================================
SELECT '=== INFORMAÇÕES GERAIS ===' AS '';

SELECT
    'Total de Submissions' AS metrica,
    COUNT(*) AS valor
FROM wp_ffc_submissions

UNION ALL

SELECT
    'Submissions com email (não criptografado)' AS metrica,
    COUNT(*) AS valor
FROM wp_ffc_submissions
WHERE email IS NOT NULL AND email != ''

UNION ALL

SELECT
    'Submissions com email_encrypted' AS metrica,
    COUNT(*) AS valor
FROM wp_ffc_submissions
WHERE email_encrypted IS NOT NULL AND email_encrypted != ''

UNION ALL

SELECT
    'Submissions com data (não criptografado)' AS metrica,
    COUNT(*) AS valor
FROM wp_ffc_submissions
WHERE data IS NOT NULL AND data != ''

UNION ALL

SELECT
    'Submissions com data_encrypted' AS metrica,
    COUNT(*) AS valor
FROM wp_ffc_submissions
WHERE data_encrypted IS NOT NULL AND data_encrypted != ''

UNION ALL

SELECT
    'Submissions com cpf_rf (não hash)' AS metrica,
    COUNT(*) AS valor
FROM wp_ffc_submissions
WHERE cpf_rf IS NOT NULL AND cpf_rf != ''

UNION ALL

SELECT
    'Submissions com cpf_rf_hash' AS metrica,
    COUNT(*) AS valor
FROM wp_ffc_submissions
WHERE cpf_rf_hash IS NOT NULL AND cpf_rf_hash != '';

-- ============================================
-- PARTE 3: Estado da Coluna user_id
-- ============================================
SELECT '=== ESTADO DA COLUNA user_id ===' AS '';

SELECT
    CASE
        WHEN COUNT(*) > 0 THEN 'SIM - Coluna user_id existe'
        ELSE 'NÃO - Coluna user_id não existe'
    END AS status_coluna_user_id
FROM information_schema.COLUMNS
WHERE
    TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'wp_ffc_submissions'
    AND COLUMN_NAME = 'user_id';

-- Se a coluna existir, mostrar quantas estão linkadas
SELECT
    'Submissions com user_id' AS metrica,
    COUNT(*) AS valor
FROM wp_ffc_submissions
WHERE user_id IS NOT NULL;

-- ============================================
-- PARTE 4: Amostra de 3 Registros (SEM DADOS SENSÍVEIS)
-- ============================================
SELECT '=== AMOSTRA DE ESTRUTURA (primeiros 3 registros) ===' AS '';

SELECT
    id,
    form_id,
    -- NÃO mostrar email/cpf_rf (dados sensíveis)
    CASE WHEN email IS NOT NULL AND email != '' THEN 'TEM_DADO' ELSE 'NULL' END AS email_status,
    CASE WHEN email_encrypted IS NOT NULL AND email_encrypted != '' THEN 'TEM_DADO' ELSE 'NULL' END AS email_encrypted_status,
    CASE WHEN cpf_rf IS NOT NULL AND cpf_rf != '' THEN 'TEM_DADO' ELSE 'NULL' END AS cpf_rf_status,
    CASE WHEN cpf_rf_hash IS NOT NULL AND cpf_rf_hash != '' THEN 'TEM_DADO' ELSE 'NULL' END AS cpf_rf_hash_status,
    CASE WHEN data IS NOT NULL AND data != '' THEN 'TEM_DADO' ELSE 'NULL' END AS data_status,
    CASE WHEN data_encrypted IS NOT NULL AND data_encrypted != '' THEN 'TEM_DADO' ELSE 'NULL' END AS data_encrypted_status,
    CASE WHEN user_id IS NOT NULL THEN user_id ELSE 'NULL' END AS user_id_status,
    submission_date,
    status
FROM wp_ffc_submissions
ORDER BY id ASC
LIMIT 3;

-- ============================================
-- PARTE 5: Verificação de Colunas Críticas
-- ============================================
SELECT '=== COLUNAS EXISTENTES (críticas para migração) ===' AS '';

SELECT
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE
    TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'wp_ffc_submissions'
    AND COLUMN_NAME IN (
        'email',
        'email_encrypted',
        'cpf_rf',
        'cpf_rf_hash',
        'data',
        'data_encrypted',
        'user_ip',
        'user_ip_encrypted',
        'user_id',
        'magic_token',
        'auth_code'
    )
ORDER BY
    FIELD(COLUMN_NAME, 'email', 'email_encrypted', 'cpf_rf', 'cpf_rf_hash', 'data', 'data_encrypted', 'user_ip', 'user_ip_encrypted', 'user_id', 'magic_token', 'auth_code');

-- ============================================
-- PARTE 6: Estado das Migrações
-- ============================================
SELECT '=== ESTADO DAS MIGRAÇÕES (necessárias) ===' AS '';

SELECT
    'EMAIL → EMAIL_ENCRYPTED' AS migracao,
    SUM(CASE WHEN email IS NOT NULL AND email != '' AND (email_encrypted IS NULL OR email_encrypted = '') THEN 1 ELSE 0 END) AS pendentes,
    SUM(CASE WHEN email_encrypted IS NOT NULL AND email_encrypted != '' THEN 1 ELSE 0 END) AS migrados
FROM wp_ffc_submissions

UNION ALL

SELECT
    'CPF_RF → CPF_RF_HASH' AS migracao,
    SUM(CASE WHEN cpf_rf IS NOT NULL AND cpf_rf != '' AND (cpf_rf_hash IS NULL OR cpf_rf_hash = '') THEN 1 ELSE 0 END) AS pendentes,
    SUM(CASE WHEN cpf_rf_hash IS NOT NULL AND cpf_rf_hash != '' THEN 1 ELSE 0 END) AS migrados
FROM wp_ffc_submissions

UNION ALL

SELECT
    'DATA → DATA_ENCRYPTED' AS migracao,
    SUM(CASE WHEN data IS NOT NULL AND data != '' AND (data_encrypted IS NULL OR data_encrypted = '') THEN 1 ELSE 0 END) AS pendentes,
    SUM(CASE WHEN data_encrypted IS NOT NULL AND data_encrypted != '' THEN 1 ELSE 0 END) AS migrados
FROM wp_ffc_submissions;

-- ============================================
-- PARTE 7: Verificação de Conflitos (User Link)
-- ============================================
SELECT '=== VERIFICAÇÃO DE CONFLITOS POTENCIAIS ===' AS '';

-- Verifica se existem emails duplicados (possível conflito)
SELECT
    'Emails duplicados (não criptografados)' AS tipo_conflito,
    COUNT(DISTINCT email) AS emails_unicos,
    COUNT(*) AS total_registros,
    COUNT(*) - COUNT(DISTINCT email) AS duplicatas
FROM wp_ffc_submissions
WHERE email IS NOT NULL AND email != '';

-- ============================================
-- FIM DO DIAGNÓSTICO
-- ============================================
SELECT '=== DIAGNÓSTICO CONCLUÍDO ===' AS '';
SELECT 'Execute este SQL e me envie os resultados completos' AS instrucao;
SELECT 'NÃO se preocupe: este SQL apenas LÊ dados, não modifica nada' AS garantia;
