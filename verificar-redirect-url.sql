-- Verificar configuração de User Access Settings no banco
SELECT option_name, option_value
FROM wrrel_options
WHERE option_name = 'ffc_user_access_settings';
