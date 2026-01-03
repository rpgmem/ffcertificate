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
     */
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
        if ( $migration_key === 'magic_tokens' || $migration_key === 'data_cleanup' ) {
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
            
            // Count migrated (column not empty)
            $migrated = $wpdb->get_var( 
                $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table_name} WHERE %i IS NOT NULL AND %i != ''", $column, $column )
            );
            
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
}