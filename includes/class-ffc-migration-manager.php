<?php
/**
 * FFC_Migration_Manager
 * 
 * Centralized migration system for database schema updates and data migrations.
 * 
 * ✅ v2.9.15: REFATORADO - Lógica genérica e reutilizável
 * 
 * @since 2.9.13
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Migration_Manager {
    
    /**
     * Database table name
     */
    private $table_name;
    
    /**
     * Field definitions for migrations
     * 
     * @var array
     */
    private $field_definitions = array();
    
    /**
     * Registry of all available migrations
     * 
     * @var array
     */
    private $migrations = array();
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = FFC_Utils::get_submissions_table();
        
        // Initialize field definitions and migrations
        $this->define_migratable_fields();
        $this->register_migrations();
    }
    
    /**
     * ✅ CONFIGURAÇÃO CENTRALIZADA DE CAMPOS
     * 
     * Define quais campos migrar do JSON para colunas dedicadas
     * 
     * Nota: Validação removida - dados já foram validados no input
     */
    private function define_migratable_fields() {
        $this->field_definitions = array(
            'email' => array(
                'json_keys'         => array( 'email', 'user_email', 'e-mail', 'ffc_email' ),
                'column_name'       => 'email',
                'sanitize_callback' => 'sanitize_email',
                'icon'              => '📧',
                'description'       => __( 'Email address', 'ffc' )
            ),
            'cpf_rf' => array(
                'json_keys'         => array( 'cpf_rf', 'cpf', 'rf', 'documento' ),
                'column_name'       => 'cpf_rf',
                'sanitize_callback' => array( 'FFC_Utils', 'clean_identifier' ),
                'icon'              => '🆔',
                'description'       => __( 'CPF or RF number', 'ffc' )
            ),
            'auth_code' => array(
                'json_keys'         => array( 'auth_code', 'codigo_autenticacao', 'verification_code' ),
                'column_name'       => 'auth_code',
                'sanitize_callback' => array( 'FFC_Utils', 'clean_auth_code' ),
                'icon'              => '🔐',
                'description'       => __( 'Authentication code', 'ffc' )
            )
        );
        
        // Allow plugins to add custom fields
        $this->field_definitions = apply_filters( 'ffc_migratable_fields', $this->field_definitions );
    }
    
    /**
     * Register all available migrations
     */
    private function register_migrations() {
        $this->migrations = array();
        
        // ✅ Gerar migrações automaticamente para cada campo
        $order = 1;
        foreach ( $this->field_definitions as $field_key => $field_config ) {
            $this->migrations[ $field_key ] = array(
                'name'            => sprintf( __( '%s Migration', 'ffc' ), $field_config['description'] ),
                'description'     => sprintf( 
                    __( 'Migrate %s from JSON data to dedicated %s column', 'ffc' ),
                    strtolower( $field_config['description'] ),
                    $field_config['column_name']
                ),
                'callback'        => 'migrate_field_to_column',
                'callback_args'   => array( $field_key ),
                'batch_size'      => 100,
                'icon'            => $field_config['icon'],
                'column'          => $field_config['column_name'],
                'required_column' => $field_config['column_name'],
                'order'           => $order++
            );
        }
        
        // ✅ Migração de Magic Tokens (caso especial - não vem do JSON)
        $this->migrations['magic_tokens'] = array(
            'name'            => __( 'Magic Tokens', 'ffc' ),
            'description'     => __( 'Generate magic tokens for old submissions that don\'t have them', 'ffc' ),
            'callback'        => 'migrate_magic_tokens',
            'batch_size'      => 100,
            'icon'            => '🔗',
            'required_column' => 'magic_token',
            'order'           => 90  // Antes do cleanup
        );
        
        // ✅ v2.10.0: Criptografia de dados sensíveis
        $this->migrations['encrypt_sensitive_data'] = array(
            'name'            => __( 'Encrypt Sensitive Data', 'ffc' ),
            'description'     => __( 'Encrypt email, CPF/RF, IP address and JSON data with AES-256 encryption for LGPD compliance', 'ffc' ),
            'callback'        => 'migrate_encryption',
            'batch_size'      => 50,  // Menor devido ao custo da criptografia
            'icon'            => '🔒',
            'required_column' => 'email_encrypted',
            'order'           => 95  // Depois de magic_tokens, antes de data_cleanup
        );
        
        // ✅ v2.10.0: Limpeza de dados não criptografados (após 15 dias)
        $this->migrations['cleanup_unencrypted'] = array(
            'name'            => __( 'Cleanup Unencrypted Data (15+ days)', 'ffc' ),
            'description'     => __( 'Remove unencrypted data from old columns for submissions older than 15 days (OPTIONAL - for LGPD compliance)', 'ffc' ),
            'callback'        => 'cleanup_unencrypted_data',
            'batch_size'      => 100,
            'icon'            => '🗑️',
            'required_column' => null,
            'order'           => 96  // Depois de encrypt_sensitive_data
        );

        // ✅ v3.1.0: Link submissions to WordPress users
        $this->migrations['user_link'] = array(
            'name'            => __( 'Link Users', 'ffc' ),
            'description'     => __( 'Link existing submissions to WordPress users based on CPF/RF and email', 'ffc' ),
            'callback'        => 'migrate_user_link',
            'batch_size'      => 100,
            'icon'            => '👥',
            'required_column' => 'user_id',
            'order'           => 97  // Antes de data_cleanup
        );

        // ✅ Limpeza do JSON (última migração)
        $this->migrations['data_cleanup'] = array(
            'name'            => __( 'JSON Data Cleanup', 'ffc' ),
            'description'     => __( 'Remove migrated fields from JSON data column (run this LAST)', 'ffc' ),
            'callback'        => 'cleanup_migrated_fields',
            'batch_size'      => 100,
            'icon'            => '🧹',
            'required_column' => null,
            'order'           => 99  // SEMPRE ÚLTIMA
        );
        
        // Allow plugins to register custom migrations
        $this->migrations = apply_filters( 'ffc_register_migrations', $this->migrations );
        
        // Sort by order
        uasort( $this->migrations, function( $a, $b ) {
            $order_a = isset( $a['order'] ) ? $a['order'] : 999;
            $order_b = isset( $b['order'] ) ? $b['order'] : 999;
            return $order_a - $order_b;
        });
    }
    
    /**
     * Get all registered migrations
     * 
     * @return array Migrations array
     */
    public function get_migrations() {
        // ✅ Safety check: Ensure migrations are initialized
        if ( ! is_array( $this->migrations ) ) {
            $this->migrations = array();
        }
        
        return $this->migrations;
    }
    
    /**
     * ✅ v2.9.16: Check if migration is available (column exists or special migration)
     * 
     * @param string $migration_key Migration identifier
     * @return bool True if migration can be shown/run
     */
    public function is_migration_available( $migration_key ) {
        if ( ! isset( $this->migrations[ $migration_key ] ) ) {
            return false;
        }
        
        // Special migrations always available
        if ( $migration_key === 'magic_tokens' || $migration_key === 'data_cleanup' || $migration_key === 'user_link' ) {
            return true;
        }
        
        // Field migrations: check if column exists
        $migration = $this->migrations[ $migration_key ];
        if ( isset( $migration['column'] ) ) {
            return $this->column_exists( $migration['column'] );
        }
        
        return true;
    }
    
    /**
     * ✅ v2.9.16: Get migration status (progress, pending count)
     * 
     * @param string $migration_key Migration identifier
     * @return array|WP_Error Status array or error
     */
    public function get_migration_status( $migration_key ) {
        if ( ! isset( $this->migrations[ $migration_key ] ) ) {
            return new WP_Error( 'invalid_migration', __( 'Migration not found', 'ffc' ) );
        }
        
        global $wpdb;
        $migration = $this->migrations[ $migration_key ];
        
        // For field migrations
        if ( isset( $migration['column'] ) && isset( $this->field_definitions[ $migration_key ] ) ) {
            $column = $migration['column'];
            $field_def = $this->field_definitions[ $migration_key ];
            
            // Count total records
            $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );
            
            if ( $total == 0 ) {
                return array(
                    'total' => 0,
                    'migrated' => 0,
                    'pending' => 0,
                    'percent' => 100,
                    'is_complete' => true
                );
            }
            
            // ✅ v2.10.0: Check for encrypted column equivalent
            $encrypted_column = $column . '_encrypted';
            $column_exists = $wpdb->get_var( 
                $wpdb->prepare( 
                    "SELECT COUNT(*) FROM information_schema.COLUMNS 
                    WHERE TABLE_SCHEMA = %s 
                    AND TABLE_NAME = %s 
                    AND COLUMN_NAME = %s",
                    DB_NAME,
                    $this->table_name,
                    $encrypted_column
                )
            );
            
            // Count migrated
            if ( $column_exists ) {
                // If encrypted column exists, count records that have EITHER:
                // 1. Data in encrypted column (migrated with encryption)
                // 2. NULL in both columns (already cleaned up)
                $migrated = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} 
                    WHERE (%i IS NOT NULL AND %i != '') 
                    OR (%i IS NULL AND %i IS NULL)",
                    $encrypted_column, $encrypted_column,
                    $column, $encrypted_column
                ) );
            } else {
                // No encrypted column, use old logic
                $migrated = $wpdb->get_var( 
                    $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table_name} WHERE %i IS NOT NULL AND %i != ''", $column, $column )
                );
            }
            
            $pending = $total - $migrated;
            $percent = ( $total > 0 ) ? ( $migrated / $total ) * 100 : 100;
            
            return array(
                'total' => $total,
                'migrated' => $migrated,
                'pending' => $pending,
                'percent' => $percent,
                'is_complete' => ( $pending == 0 )
            );
        }
        
        // For special migrations (magic_tokens, data_cleanup)
        if ( $migration_key === 'magic_tokens' ) {
            $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );
            $with_token = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name} WHERE magic_token IS NOT NULL AND magic_token != ''" );
            
            $pending = $total - $with_token;
            $percent = ( $total > 0 ) ? ( $with_token / $total ) * 100 : 100;
            
            return array(
                'total' => $total,
                'migrated' => $with_token,
                'pending' => $pending,
                'percent' => $percent,
                'is_complete' => ( $pending == 0 )
            );
        }

        // ✅ v2.10.0: Encrypt Sensitive Data migration
        if ( $migration_key === 'encrypt_sensitive_data' ) {
            $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );
            
            if ( $total == 0 ) {
                return array(
                    'total' => 0,
                    'migrated' => 0,
                    'pending' => 0,
                    'percent' => 100,
                    'is_complete' => true
                );
            }
            
            // ✅ v2.10.0: Count as migrated if:
            // 1. Has encrypted data (email_encrypted OR data_encrypted has data)
            // 2. OR all sensitive columns are NULL (already cleaned)
            $migrated = $wpdb->get_var( 
                "SELECT COUNT(*) FROM {$this->table_name} 
                WHERE (
                    (email_encrypted IS NOT NULL AND email_encrypted != '')
                    OR (data_encrypted IS NOT NULL AND data_encrypted != '')
                    OR (email IS NULL AND data IS NULL AND user_ip IS NULL)
                )"
            );
            
            $pending = $total - $migrated;
            $percent = ( $total > 0 ) ? ( $migrated / $total ) * 100 : 100;
            
            return array(
                'total' => $total,
                'migrated' => $migrated,
                'pending' => $pending,
                'percent' => round( $percent, 2 ),
                'is_complete' => ( $pending == 0 )
            );
        }
        
        // ✅ v2.10.0: Cleanup Unencrypted Data (15+ days)
        if ( $migration_key === 'cleanup_unencrypted' ) {
            // Count submissions older than 15 days with encrypted data
            $total_eligible = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table_name} 
                WHERE submission_date <= DATE_SUB(NOW(), INTERVAL 15 DAY)
                AND (email_encrypted IS NOT NULL OR data_encrypted IS NOT NULL)"
            );
            
            if ( $total_eligible == 0 ) {
                return array(
                    'total' => 0,
                    'migrated' => 0,
                    'pending' => 0,
                    'percent' => 100,
                    'is_complete' => true,
                    'message' => __( 'No submissions older than 15 days with encrypted data', 'ffc' )
                );
            }
            
            // Count how many already have NULL in old columns
            $cleaned = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table_name} 
                WHERE submission_date <= DATE_SUB(NOW(), INTERVAL 15 DAY)
                AND (email_encrypted IS NOT NULL OR data_encrypted IS NOT NULL)
                AND email IS NULL 
                AND data IS NULL 
                AND user_ip IS NULL"
            );
            
            $pending = $total_eligible - $cleaned;
            $percent = ( $total_eligible > 0 ) ? ( $cleaned / $total_eligible ) * 100 : 100;
            
            return array(
                'total' => $total_eligible,
                'migrated' => $cleaned,
                'pending' => $pending,
                'percent' => round( $percent, 2 ),
                'is_complete' => ( $pending == 0 ),
                'message' => sprintf( 
                    __( '%d submissions eligible for cleanup (15+ days old with encrypted data)', 'ffc' ),
                    $total_eligible
                )
            );
        }
        
        // ✅ v3.1.0: User Link migration
        if ( $migration_key === 'user_link' ) {
            // Count total submissions with CPF/RF hash
            $total = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table_name}
                WHERE cpf_rf_hash IS NOT NULL AND cpf_rf_hash != ''"
            );

            if ( $total == 0 ) {
                return array(
                    'total' => 0,
                    'migrated' => 0,
                    'pending' => 0,
                    'percent' => 100,
                    'is_complete' => true
                );
            }

            // Count submissions already linked to users
            $with_user = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table_name}
                WHERE cpf_rf_hash IS NOT NULL AND cpf_rf_hash != ''
                AND user_id IS NOT NULL"
            );

            $pending = $total - $with_user;
            $percent = ( $total > 0 ) ? ( $with_user / $total ) * 100 : 100;

            return array(
                'total' => $total,
                'migrated' => $with_user,
                'pending' => $pending,
                'percent' => round( $percent, 2 ),
                'is_complete' => ( $pending == 0 )
            );
        }

        if ( $migration_key === 'data_cleanup' ) {
            // Check option flag
            $completed = get_option( "ffc_migration_{$migration_key}_completed", false );

            return array(
                'total' => 0,
                'migrated' => $completed ? 1 : 0,
                'pending' => $completed ? 0 : 1,
                'percent' => $completed ? 100 : 0,
                'is_complete' => $completed
            );
        }

        return new WP_Error( 'unknown_migration_type', __( 'Unknown migration type', 'ffc' ) );
    }
    
    /**
     * Get a single migration definition
     * 
     * @param string $migration_key Migration identifier
     * @return array|null Migration array or null
     */
    public function get_migration( $migration_key ) {
        return isset( $this->migrations[ $migration_key ] ) ? $this->migrations[ $migration_key ] : null;
    }
    
    /**
     * Check if migration can run (column exists)
     */
    public function can_run_migration( $migration_key ) {
        if ( ! isset( $this->migrations[ $migration_key ] ) ) {
            return false;
        }
        
        $migration = $this->migrations[ $migration_key ];
        
        // If no required column, can always run
        if ( empty( $migration['required_column'] ) ) {
            return true;
        }
        
        // Check if column exists
        return $this->column_exists( $migration['required_column'] );
    }
    
    /**
     * Check if database column exists
     */
    private function column_exists( $column_name ) {
        global $wpdb;
        
        $column = $wpdb->get_results( $wpdb->prepare(
            "SHOW COLUMNS FROM {$this->table_name} LIKE %s",
            $column_name
        ) );
        
        return ! empty( $column );
    }
    
    /**
     * Run a specific migration
     */
    public function run_migration( $migration_key, $batch_number = 0 ) {
        if ( ! isset( $this->migrations[ $migration_key ] ) ) {
            return new WP_Error( 'invalid_migration', __( 'Migration not found.', 'ffc' ) );
        }
        
        $migration = $this->migrations[ $migration_key ];
        
        // Check if can run
        if ( ! $this->can_run_migration( $migration_key ) ) {
            return new WP_Error(
                'column_missing',
                sprintf( __( 'Required column %s does not exist.', 'ffc' ), $migration['required_column'] )
            );
        }
        
        $callback = $migration['callback'];
        $args = isset( $migration['callback_args'] ) ? $migration['callback_args'] : array();
        
        // Add batch number to args
        $args[] = $batch_number;
        
        if ( ! method_exists( $this, $callback ) ) {
            return new WP_Error( 'invalid_callback', __( 'Migration callback not found.', 'ffc' ) );
        }
        
        // Run migration
        return call_user_func_array( array( $this, $callback ), $args );
    }
    
    /**
     * ✅ MÉTODO GENÉRICO: Migrar campo do JSON para coluna
     * 
     * Sanitiza dados mas NÃO valida - dados já foram validados no input
     * 
     * @param string $field_key Key do campo em $field_definitions
     * @param int $batch_number Batch number
     * @return array Result
     */
    private function migrate_field_to_column( $field_key, $batch_number = 0 ) {
        global $wpdb;
        
        if ( ! isset( $this->field_definitions[ $field_key ] ) ) {
            return array(
                'migrated' => 0,
                'processed' => 0,
                'has_more' => false,
                'error' => 'Field definition not found'
            );
        }
        
        $field_config = $this->field_definitions[ $field_key ];
        $column_name = $field_config['column_name'];
        $batch_size = $this->migrations[ $field_key ]['batch_size'];
        $offset = $batch_number > 0 ? ( $batch_number - 1 ) * $batch_size : 0;
        
        // Query: Buscar submissions sem valor na coluna
        $query = $wpdb->prepare(
            "SELECT id, data FROM {$this->table_name} 
             WHERE ({$column_name} IS NULL OR {$column_name} = '')
             LIMIT %d OFFSET %d",
            $batch_size,
            $offset
        );
        
        $submissions = $wpdb->get_results( $query, ARRAY_A );
        $migrated = 0;
        
        foreach ( $submissions as $submission ) {
            // Decodificar JSON
            $data = json_decode( $submission['data'], true );
            
            if ( ! is_array( $data ) ) {
                $data = json_decode( stripslashes( $submission['data'] ), true );
            }
            
            if ( ! is_array( $data ) ) {
                continue;
            }
            
            // ✅ Buscar valor em qualquer uma das chaves possíveis
            $value = null;
            foreach ( $field_config['json_keys'] as $json_key ) {
                if ( isset( $data[ $json_key ] ) && ! empty( $data[ $json_key ] ) ) {
                    $value = $data[ $json_key ];
                    break;
                }
            }
            
            // Se não encontrou, pular
            if ( $value === null ) {
                continue;
            }
            
            // ✅ Sanitizar valor
            $sanitized_value = $this->sanitize_field_value( $value, $field_config );
            
            // ✅ Verificar se não ficou vazio após sanitização
            if ( empty( $sanitized_value ) ) {
                continue;
            }
            
            // ✅ Atualizar coluna (SEM validação - dados já foram validados no input)
            $updated = $wpdb->update(
                $this->table_name,
                array( $column_name => $sanitized_value ),
                array( 'id' => $submission['id'] ),
                array( '%s' ),
                array( '%d' )
            );
            
            if ( $updated ) {
                $migrated++;
            }
        }
        
        return array(
            'migrated'   => $migrated,
            'processed'  => count( $submissions ),
            'has_more'   => count( $submissions ) === $batch_size
        );
    }
    
    /**
     * ✅ MÉTODO GENÉRICO: Limpar campos migrados do JSON
     * 
     * Remove todos os campos que foram migrados para colunas
     * 
     * @param int $batch_number Batch number
     * @return array Result
     */
    private function cleanup_migrated_fields( $batch_number = 0 ) {
        global $wpdb;
        
        $batch_size = $this->migrations['data_cleanup']['batch_size'];
        $offset = $batch_number > 0 ? ( $batch_number - 1 ) * $batch_size : 0;
        
        // Construir lista de colunas para verificar
        $columns_to_check = array();
        foreach ( $this->field_definitions as $field_key => $field_config ) {
            $columns_to_check[] = $field_config['column_name'];
        }
        
        $columns_sql = implode( ', ', $columns_to_check );
        
        // Query
        $query = $wpdb->prepare(
            "SELECT id, data, {$columns_sql} FROM {$this->table_name}
             LIMIT %d OFFSET %d",
            $batch_size,
            $offset
        );
        
        $submissions = $wpdb->get_results( $query, ARRAY_A );
        $cleaned = 0;
        
        foreach ( $submissions as $submission ) {
            // Pular se JSON vazio
            if ( empty( $submission['data'] ) || $submission['data'] === 'null' ) {
                continue;
            }
            
            $data = json_decode( $submission['data'], true );
            
            if ( ! is_array( $data ) ) {
                $data = json_decode( stripslashes( $submission['data'] ), true );
            }
            
            if ( ! is_array( $data ) || empty( $data ) ) {
                continue;
            }
            
            $data_modified = false;
            
            // ✅ Para cada campo migrado, remover do JSON se estiver na coluna
            foreach ( $this->field_definitions as $field_key => $field_config ) {
                $column_name = $field_config['column_name'];
                
                // Se a coluna tem valor
                if ( ! empty( $submission[ $column_name ] ) ) {
                    // Remover todas as possíveis chaves do JSON
                    foreach ( $field_config['json_keys'] as $json_key ) {
                        if ( isset( $data[ $json_key ] ) ) {
                            unset( $data[ $json_key ] );
                            $data_modified = true;
                        }
                    }
                }
            }
            
            // Atualizar se modificado
            if ( $data_modified ) {
                $updated = $wpdb->update(
                    $this->table_name,
                    array( 'data' => wp_json_encode( $data ) ),
                    array( 'id' => $submission['id'] ),
                    array( '%s' ),
                    array( '%d' )
                );
                
                if ( $updated ) {
                    $cleaned++;
                }
            }
        }
        
        return array(
            'migrated'   => $cleaned,
            'processed'  => count( $submissions ),
            'has_more'   => count( $submissions ) === $batch_size
        );
    }
    
    /**
     * Generate magic tokens for old submissions
     * (Caso especial - não migra do JSON)
     */
    private function migrate_magic_tokens( $batch_number = 0 ) {
        global $wpdb;
        
        $batch_size = $this->migrations['magic_tokens']['batch_size'];
        $offset = $batch_number > 0 ? ( $batch_number - 1 ) * $batch_size : 0;
        
        $query = $wpdb->prepare(
            "SELECT id FROM {$this->table_name} 
             WHERE magic_token IS NULL OR magic_token = ''
             LIMIT %d OFFSET %d",
            $batch_size,
            $offset
        );
        
        $submissions = $wpdb->get_results( $query, ARRAY_A );
        $migrated = 0;
        
        foreach ( $submissions as $submission ) {
            $token = bin2hex( random_bytes( 16 ) );
            
            $updated = $wpdb->update(
                $this->table_name,
                array( 'magic_token' => $token ),
                array( 'id' => $submission['id'] ),
                array( '%s' ),
                array( '%d' )
            );
            
            if ( $updated ) {
                $migrated++;
            }
        }
        
        return array(
            'migrated'   => $migrated,
            'processed'  => count( $submissions ),
            'has_more'   => count( $submissions ) === $batch_size
        );
    }
    
    /**
     * ✅ HELPER DE SANITIZAÇÃO
     * 
     * Validação removida - dados já foram validados no input
     */
    private function sanitize_field_value( $value, $field_config ) {
        if ( ! isset( $field_config['sanitize_callback'] ) ) {
            return sanitize_text_field( $value );
        }
        
        $callback = $field_config['sanitize_callback'];
        
        if ( is_callable( $callback ) ) {
            return call_user_func( $callback, $value );
        }
        
        return sanitize_text_field( $value );
    }

    /**
     * ✅ v2.10.0: Migrate data to encrypted fields
     * 
     * Encrypts sensitive data (email, CPF/RF, IP, JSON) using AES-256
     * Creates searchable hashes for email and CPF/RF
     * 
     * @param int $offset Starting offset
     * @param int $limit Batch size
     * @return array|WP_Error Result with migrated count
     */
    public function migrate_encryption( $offset = 0, $limit = 50 ) {
        global $wpdb;
        
        // Check if FFC_Encryption class exists
        if ( ! class_exists( 'FFC_Encryption' ) ) {
            return new WP_Error(
                'encryption_class_missing',
                __( 'FFC_Encryption class not found. Please ensure class-ffc-encryption.php is loaded.', 'ffc' )
            );
        }
        
        // Check if encryption is configured
        if ( ! FFC_Encryption::is_configured() ) {
            return new WP_Error(
                'encryption_not_configured',
                __( 'Encryption keys not configured. WordPress SECURE_AUTH_KEY and LOGGED_IN_KEY are required.', 'ffc' )
            );
        }
        
        // Get batch of submissions that need encryption
        $submissions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} 
                 WHERE (email_encrypted IS NULL OR email_encrypted = '') 
                 AND email IS NOT NULL 
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );
        
        if ( empty( $submissions ) ) {
            return array(
                'migrated' => 0,
                'pending' => 0,
                'has_more' => false
            );
        }
        
        $migrated = 0;
        $errors = array();
        
        foreach ( $submissions as $submission ) {
            try {
                // Encrypt email
                $email_encrypted = null;
                $email_hash = null;
                if ( ! empty( $submission['email'] ) ) {
                    $email_encrypted = FFC_Encryption::encrypt( $submission['email'] );
                    $email_hash = FFC_Encryption::hash( $submission['email'] );
                }
                
                // Encrypt CPF/RF
                $cpf_encrypted = null;
                $cpf_hash = null;
                if ( ! empty( $submission['cpf_rf'] ) ) {
                    $cpf_encrypted = FFC_Encryption::encrypt( $submission['cpf_rf'] );
                    $cpf_hash = FFC_Encryption::hash( $submission['cpf_rf'] );
                }
                
                // Encrypt IP
                $ip_encrypted = null;
                if ( ! empty( $submission['user_ip'] ) ) {
                    $ip_encrypted = FFC_Encryption::encrypt( $submission['user_ip'] );
                }
                
                // Encrypt JSON data
                $data_encrypted = null;
                if ( ! empty( $submission['data'] ) ) {
                    $data_encrypted = FFC_Encryption::encrypt( $submission['data'] );
                }
                
                // Update database
                $updated = $wpdb->update(
                    $this->table_name,
                    array(
                        'email_encrypted' => $email_encrypted,
                        'email_hash' => $email_hash,
                        'cpf_rf_encrypted' => $cpf_encrypted,
                        'cpf_rf_hash' => $cpf_hash,
                        'user_ip_encrypted' => $ip_encrypted,
                        'data_encrypted' => $data_encrypted
                    ),
                    array( 'id' => $submission['id'] ),
                    array( '%s', '%s', '%s', '%s', '%s', '%s' ),
                    array( '%d' )
                );
                
                if ( $updated !== false ) {
                    $migrated++;
                } else {
                    $errors[] = sprintf(
                        'Failed to update submission ID %d: %s',
                        $submission['id'],
                        $wpdb->last_error
                    );
                }
                
            } catch ( Exception $e ) {
                $errors[] = sprintf(
                    'Encryption error for submission ID %d: %s',
                    $submission['id'],
                    $e->getMessage()
                );
            }
        }
        
        // Log migration batch
        if ( class_exists( 'FFC_Activity_Log' ) ) {
            FFC_Activity_Log::log(
                'encryption_migration_batch',
                FFC_Activity_Log::LEVEL_INFO,
                array(
                    'offset' => $offset,
                    'migrated' => $migrated,
                    'errors' => count( $errors )
                )
            );
        }
        
        // Calculate remaining
        $total_pending = $this->count_pending_encryption();
        $has_more = $total_pending > 0;
        
        // If migration complete, save completion date
        if ( ! $has_more ) {
            update_option( 'ffc_encryption_migration_completed_date', current_time( 'mysql' ) );
        }
        
        return array(
            'migrated' => $migrated,
            'pending' => $total_pending,
            'has_more' => $has_more,
            'errors' => $errors
        );
    }
    
    /**
     * ✅ v2.10.0: Count submissions pending encryption
     * 
     * @return int Number of submissions without encrypted data
     */
    private function count_pending_encryption() {
        global $wpdb;
        
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} 
             WHERE (email_encrypted IS NULL OR email_encrypted = '') 
             AND email IS NOT NULL"
        );
    }
    
    /**
     * ✅ v2.10.0: Cleanup unencrypted data (15+ days old) - BATCH METHOD
     * 
     * Called by migration system.
     * Only cleans submissions that:
     * 1. Are 15+ days old
     * 2. Have encrypted data
     * 3. Still have unencrypted data in old columns
     * 
     * @param int $offset Batch offset
     * @param int $limit Batch size
     * @return array Result with cleaned count and errors
     */
    public function cleanup_unencrypted_data( $offset = 0, $limit = 100 ) {
        global $wpdb;
        
        // Get submissions eligible for cleanup (15+ days old, encrypted, still have plain data)
        $submissions = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$this->table_name} 
            WHERE submission_date <= DATE_SUB(NOW(), INTERVAL 15 DAY)
            AND (email_encrypted IS NOT NULL OR data_encrypted IS NOT NULL)
            AND (email IS NOT NULL OR data IS NOT NULL OR user_ip IS NOT NULL)
            ORDER BY id ASC
            LIMIT %d OFFSET %d",
            $limit,
            $offset
        ) );
        
        $cleaned = 0;
        $errors = array();
        
        foreach ( $submissions as $submission ) {
            // Nullify unencrypted columns
            $result = $wpdb->update(
                $this->table_name,
                array(
                    'email'   => null,
                    'cpf_rf'  => null,
                    'user_ip' => null,
                    'data'    => null
                ),
                array( 'id' => $submission->id ),
                array( '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );
            
            if ( $result !== false ) {
                $cleaned++;
            } else {
                $errors[] = sprintf(
                    'Failed to cleanup submission ID %d: %s',
                    $submission->id,
                    $wpdb->last_error
                );
            }
        }
        
        // Count remaining
        $remaining = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} 
            WHERE submission_date <= DATE_SUB(NOW(), INTERVAL 15 DAY)
            AND (email_encrypted IS NOT NULL OR data_encrypted IS NOT NULL)
            AND (email IS NOT NULL OR data IS NOT NULL OR user_ip IS NOT NULL)"
        );
        
        $has_more = $remaining > 0;
        
        return array(
            'migrated' => $cleaned,
            'pending' => $remaining,
            'has_more' => $has_more,
            'errors' => $errors
        );
    }
    
    /**
     * ✅ v2.10.0: Cleanup old unencrypted data (NULLIFY)
     * 
     * Sets old columns to NULL after successful encryption migration.
     * This is REVERSIBLE - columns remain, just data is removed.
     * 
     * @return array|WP_Error Result with cleaned count
     */
    public function cleanup_old_data() {
        global $wpdb;
        
        // Check if migration is 100% complete
        $pending = $this->count_pending_encryption();
        
        if ( $pending > 0 ) {
            return new WP_Error(
                'migration_incomplete',
                sprintf(
                    __( 'Cannot cleanup: %d submissions still need encryption. Complete migration first.', 'ffc' ),
                    $pending
                )
            );
        }
        
        // Check if user confirmed
        if ( ! isset( $_POST['ffc_confirm_cleanup'] ) || $_POST['ffc_confirm_cleanup'] !== 'yes' ) {
            return new WP_Error(
                'not_confirmed',
                __( 'Cleanup must be explicitly confirmed by the user.', 'ffc' )
            );
        }
        
        // NULLIFY old data (reversible - columns remain)
        $result = $wpdb->query(
            "UPDATE {$this->table_name} 
             SET email = NULL, 
                 cpf_rf = NULL, 
                 user_ip = NULL, 
                 data = '{}' 
             WHERE email_encrypted IS NOT NULL"
        );
        
        if ( $result === false ) {
            return new WP_Error(
                'cleanup_failed',
                sprintf( __( 'Database error: %s', 'ffc' ), $wpdb->last_error )
            );
        }
        
        // Save cleanup date
        update_option( 'ffc_data_cleanup_executed_date', current_time( 'mysql' ) );
        
        // Calculate drop available date (15 days from now)
        $drop_available = date( 'Y-m-d H:i:s', strtotime( '+15 days' ) );
        update_option( 'ffc_drop_available_date', $drop_available );
        
        // Log
        if ( class_exists( 'FFC_Activity_Log' ) ) {
            FFC_Activity_Log::log(
                'data_cleanup_executed',
                FFC_Activity_Log::LEVEL_INFO,
                array( 'rows_affected' => $result )
            );
        }
        
        return array(
            'cleaned' => $result,
            'message' => sprintf(
                __( 'Successfully cleaned %d records. Old data set to NULL.', 'ffc' ),
                $result
            ),
            'drop_available_date' => $drop_available
        );
    }
    
    /**
     * ✅ v2.10.0: Drop old columns permanently (IRREVERSIBLE)
     * 
     * WARNING: This action is PERMANENT and cannot be undone!
     * Only available 15 days after cleanup.
     * 
     * @return array|WP_Error Result
     */
    public function drop_old_columns() {
        global $wpdb;
        
        // Check if cleanup was executed
        $cleanup_date = get_option( 'ffc_data_cleanup_executed_date' );
        
        if ( ! $cleanup_date ) {
            return new WP_Error(
                'cleanup_not_executed',
                __( 'You must execute data cleanup before dropping columns.', 'ffc' )
            );
        }
        
        // Check if 15 days have passed
        $cleanup_time = strtotime( $cleanup_date );
        $now = current_time( 'timestamp' );
        $days_passed = ( $now - $cleanup_time ) / DAY_IN_SECONDS;
        
        if ( $days_passed < 15 ) {
            $days_remaining = ceil( 15 - $days_passed );
            return new WP_Error(
                'waiting_period',
                sprintf(
                    __( 'Waiting period active. %d days remaining. Available on: %s', 'ffc' ),
                    $days_remaining,
                    get_option( 'ffc_drop_available_date' )
                )
            );
        }
        
        // Check double confirmation
        if ( ! isset( $_POST['ffc_confirm_drop'] ) || $_POST['ffc_confirm_drop'] !== 'CONFIRMAR EXCLUSÃO' ) {
            return new WP_Error(
                'not_confirmed',
                __( 'You must type "CONFIRMAR EXCLUSÃO" to proceed.', 'ffc' )
            );
        }
        
        // Check checkboxes
        $required_checks = array( 'backup_done', 'tested_15_days', 'understand_irreversible' );
        foreach ( $required_checks as $check ) {
            if ( empty( $_POST[ 'ffc_' . $check ] ) ) {
                return new WP_Error(
                    'confirmation_incomplete',
                    __( 'All confirmation checkboxes must be checked.', 'ffc' )
                );
            }
        }
        
        // DROP COLUMNS (IRREVERSIBLE!)
        $columns_to_drop = array( 'email', 'cpf_rf', 'user_ip', 'data' );
        $dropped = array();
        $errors = array();
        
        foreach ( $columns_to_drop as $column ) {
            $result = $wpdb->query(
                "ALTER TABLE {$this->table_name} DROP COLUMN {$column}"
            );
            
            if ( $result !== false ) {
                $dropped[] = $column;
            } else {
                $errors[] = sprintf(
                    'Failed to drop column %s: %s',
                    $column,
                    $wpdb->last_error
                );
            }
        }
        
        // Save drop date
        update_option( 'ffc_columns_dropped', true );
        update_option( 'ffc_columns_dropped_date', current_time( 'mysql' ) );
        
        // Log
        if ( class_exists( 'FFC_Activity_Log' ) ) {
            FFC_Activity_Log::log(
                'columns_dropped',
                FFC_Activity_Log::LEVEL_INFO,
                array(
                    'dropped' => $dropped,
                    'errors' => $errors
                )
            );
        }
        
        return array(
            'dropped' => $dropped,
            'errors' => $errors,
            'message' => sprintf(
                __( 'Successfully dropped %d columns: %s', 'ffc' ),
                count( $dropped ),
                implode( ', ', $dropped )
            )
        );
    }
    
    /**
     * ✅ v2.10.0: Check if drop is available (15 days passed)
     * 
     * @return bool True if drop can be executed
     */
    public function can_drop_columns() {
        $cleanup_date = get_option( 'ffc_data_cleanup_executed_date' );
        
        if ( ! $cleanup_date ) {
            return false;
        }
        
        $cleanup_time = strtotime( $cleanup_date );
        $now = current_time( 'timestamp' );
        $days_passed = ( $now - $cleanup_time ) / DAY_IN_SECONDS;
        
        return $days_passed >= 15;
    }
    
    /**
     * ✅ v2.10.0: Get days remaining until drop available
     *
     * @return int Days remaining (0 if available)
     */
    public function get_drop_days_remaining() {
        $cleanup_date = get_option( 'ffc_data_cleanup_executed_date' );

        if ( ! $cleanup_date ) {
            return 999; // Not started
        }

        $cleanup_time = strtotime( $cleanup_date );
        $now = current_time( 'timestamp' );
        $days_passed = ( $now - $cleanup_time ) / DAY_IN_SECONDS;

        if ( $days_passed >= 15 ) {
            return 0; // Available now
        }

        return ceil( 15 - $days_passed );
    }

    /**
     * ✅ v3.1.0: Link submissions to WordPress users
     *
     * Delegates to FFC_Migration_User_Link class
     *
     * @param int $batch_number Batch number (0-indexed)
     * @return array Result with migrated count
     */
    private function migrate_user_link( $batch_number = 0 ) {
        // Load migration class if not already loaded
        if ( ! class_exists( 'FFC_Migration_User_Link' ) ) {
            $migration_file = FFC_PLUGIN_DIR . 'includes/class-ffc-migration-user-link.php';
            if ( file_exists( $migration_file ) ) {
                require_once $migration_file;
            } else {
                return array(
                    'success' => false,
                    'processed' => 0,
                    'errors' => 1,
                    'message' => __( 'Migration class file not found', 'ffc' ),
                );
            }
        }

        // Run migration (this is a one-time migration, not batched)
        $result = FFC_Migration_User_Link::run();

        // Convert to expected format
        return array(
            'migrated'  => $result['processed'] ?? 0,
            'processed' => $result['processed'] ?? 0,
            'has_more'  => false, // One-time migration
        );
    }
}