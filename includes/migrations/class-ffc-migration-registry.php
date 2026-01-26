<?php
declare(strict_types=1);

/**
 * MigrationRegistry
 *
 * Centralized registry for all available migrations.
 * Separates configuration from execution logic.
 *
 * @since 3.1.0 (Extracted from FFC_Migration_Manager v3.1.0 refactor)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MigrationRegistry {

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
        $this->define_migratable_fields();
        $this->register_migrations();
    }

    /**
     * Define which fields can be migrated from JSON to dedicated columns
     *
     * @return void
     */
    private function define_migratable_fields(): void {
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
                'sanitize_callback' => array( '\FFC_Utils', 'clean_identifier' ),
                'icon'              => '🆔',
                'description'       => __( 'CPF or RF number', 'ffc' )
            ),
            'auth_code' => array(
                'json_keys'         => array( 'auth_code', 'codigo_autenticacao', 'verification_code' ),
                'column_name'       => 'auth_code',
                'sanitize_callback' => array( '\FFC_Utils', 'clean_auth_code' ),
                'icon'              => '🔐',
                'description'       => __( 'Authentication code', 'ffc' )
            )
        );

        // Allow plugins to add custom fields
        $this->field_definitions = apply_filters( 'ffc_migratable_fields', $this->field_definitions );
    }

    /**
     * Register all available migrations
     *
     * @return void
     */
    private function register_migrations(): void {
        $this->migrations = array();

        // Generate migrations automatically for each field
        $order = 1;
        foreach ( $this->field_definitions as $field_key => $field_config ) {
            $this->migrations[ $field_key ] = array(
                'name'            => sprintf( __( '%s Migration', 'ffc' ), $field_config['description'] ),
                'description'     => sprintf(
                    __( 'Migrate %s from JSON data to dedicated %s column', 'ffc' ),
                    strtolower( $field_config['description'] ),
                    $field_config['column_name']
                ),
                'icon'            => $field_config['icon'],
                'column'          => $field_config['column_name'],
                'batch_size'      => 100,
                'order'           => $order++,
                'requires_column' => true
            );
        }

        // Special migrations
        $this->migrations['magic_tokens'] = array(
            'name'            => __( 'Magic Tokens', 'ffc' ),
            'description'     => __( 'Generate unique magic tokens for secure certificate access', 'ffc' ),
            'icon'            => '🔮',
            'batch_size'      => 100,
            'order'           => $order++,
            'requires_column' => true
        );

        // v2.10.0: Encryption migrations
        $this->migrations['encrypt_sensitive_data'] = array(
            'name'            => __( 'Encrypt Sensitive Data', 'ffc' ),
            'description'     => __( 'Encrypt email, data, and user_ip for LGPD compliance', 'ffc' ),
            'icon'            => '🔒',
            'batch_size'      => 50,
            'order'           => $order++,
            'requires_column' => true
        );

        $this->migrations['cleanup_unencrypted'] = array(
            'name'            => __( 'Cleanup Unencrypted Data (15+ days)', 'ffc' ),
            'description'     => __( 'Remove unencrypted copies of sensitive data older than 15 days', 'ffc' ),
            'icon'            => '🧹',
            'batch_size'      => 100,
            'order'           => $order++,
            'requires_column' => false
        );

        // v3.1.0: User linking migration
        $this->migrations['user_link'] = array(
            'name'            => __( 'Link Submissions to Users', 'ffc' ),
            'description'     => __( 'Associate submissions with WordPress users based on CPF/RF', 'ffc' ),
            'icon'            => '👤',
            'batch_size'      => 100,
            'order'           => $order++,
            'requires_column' => true
        );

        // Data cleanup (option-based, not batch)
        $this->migrations['data_cleanup'] = array(
            'name'            => __( 'Data Cleanup', 'ffc' ),
            'description'     => __( 'Remove old migration data and cleanup database', 'ffc' ),
            'icon'            => '🗑️',
            'batch_size'      => 0,
            'order'           => 999, // Always last
            'requires_column' => false
        );

        // Allow plugins to add custom migrations
        $this->migrations = apply_filters( 'ffc_migrations_registry', $this->migrations );
    }

    /**
     * Get all registered migrations
     *
     * @return array
     */
    public function get_all_migrations(): array {
        return $this->migrations;
    }

    /**
     * Get a specific migration definition
     *
     * @param string $migration_key Migration identifier
     * @return array|null Migration definition or null if not found
     */
    public function get_migration( string $migration_key ) {
        return isset( $this->migrations[ $migration_key ] ) ? $this->migrations[ $migration_key ] : null;
    }

    /**
     * Get field definition for a specific field
     *
     * @param string $field_key Field identifier
     * @return array|null Field definition or null if not found
     */
    public function get_field_definition( string $field_key ) {
        return isset( $this->field_definitions[ $field_key ] ) ? $this->field_definitions[ $field_key ] : null;
    }

    /**
     * Get all field definitions
     *
     * @return array
     */
    public function get_all_field_definitions(): array {
        return $this->field_definitions;
    }

    /**
     * Check if a migration exists
     *
     * @param string $migration_key Migration identifier
     * @return bool
     */
    public function exists( string $migration_key ): bool {
        return isset( $this->migrations[ $migration_key ] );
    }

    /**
     * Check if a migration is available to run
     * Handles special cases like magic_tokens, data_cleanup, user_link
     *
     * @param string $migration_key Migration identifier
     * @return bool
     */
    public function is_available( string $migration_key ): bool {
        if ( ! $this->exists( $migration_key ) ) {
            return false;
        }

        // Special migrations that are always available
        $special_migrations = array( 'magic_tokens', 'data_cleanup', 'user_link', 'encrypt_sensitive_data', 'cleanup_unencrypted' );

        if ( in_array( $migration_key, $special_migrations ) ) {
            return true;
        }

        // Field migrations - check if field definition exists
        return isset( $this->field_definitions[ $migration_key ] );
    }
}
