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
     * @var array<string, array<string, mixed>>
     */
    private $field_definitions = array();

    /**
     * Registry of all available migrations
     *
     * @var array<string, array<string, mixed>>
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
                'icon'              => 'ffc-icon-email',
                'description'       => __( 'Email address', 'ffcertificate' )
            ),
            'cpf_rf' => array(
                'json_keys'         => array( 'cpf_rf', 'cpf', 'rf', 'documento' ),
                'column_name'       => 'cpf_rf',
                'sanitize_callback' => array( '\FreeFormCertificate\Core\Utils', 'clean_identifier' ),
                'icon'              => 'ffc-icon-id',
                'description'       => __( 'CPF or RF number', 'ffcertificate' )
            ),
            'auth_code' => array(
                'json_keys'         => array( 'auth_code', 'codigo_autenticacao', 'verification_code' ),
                'column_name'       => 'auth_code',
                'sanitize_callback' => array( '\FreeFormCertificate\Core\Utils', 'clean_auth_code' ),
                'icon'              => 'ffc-icon-lock',
                'description'       => __( 'Authentication code', 'ffcertificate' )
            )
        );

        // Allow plugins to add custom fields
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ffcertificate is the plugin prefix
        $this->field_definitions = apply_filters( 'ffcertificate_migratable_fields', $this->field_definitions );
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
                /* translators: %s: field description */
                'name'            => sprintf( __( '%s Migration', 'ffcertificate' ), $field_config['description'] ),
                'description'     => sprintf(
                    /* translators: 1: field description, 2: column name */
                    __( 'Migrate %1$s from JSON data to dedicated %2$s column', 'ffcertificate' ),
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
            'name'            => __( 'Magic Tokens', 'ffcertificate' ),
            'description'     => __( 'Generate unique magic tokens for secure certificate access', 'ffcertificate' ),
            'icon'            => 'ffc-icon-magic',
            'batch_size'      => 100,
            'order'           => $order++,
            'requires_column' => true
        );

        // v2.10.0: Encryption migrations
        $this->migrations['encrypt_sensitive_data'] = array(
            'name'            => __( 'Encrypt Sensitive Data', 'ffcertificate' ),
            'description'     => __( 'Encrypt email, data, and user_ip for LGPD compliance', 'ffcertificate' ),
            'icon'            => 'ffc-icon-lock',
            'batch_size'      => 50,
            'order'           => $order++,
            'requires_column' => true
        );

        $this->migrations['cleanup_unencrypted'] = array(
            'name'            => __( 'Cleanup Unencrypted Data (15+ days)', 'ffcertificate' ),
            'description'     => __( 'Remove unencrypted copies of sensitive data older than 15 days', 'ffcertificate' ),
            'icon'            => 'ffc-icon-broom',
            'batch_size'      => 100,
            'order'           => $order++,
            'requires_column' => false
        );

        // v3.1.0: User linking migration
        $this->migrations['user_link'] = array(
            'name'            => __( 'Link Submissions to Users', 'ffcertificate' ),
            'description'     => __( 'Associate submissions with WordPress users based on CPF/RF', 'ffcertificate' ),
            'icon'            => 'ffc-icon-user',
            'batch_size'      => 100,
            'order'           => $order++,
            'requires_column' => true
        );

        // v4.3.0: Name and email normalization migration
        $this->migrations['name_normalization'] = array(
            'name'            => __( 'Normalize Names & Emails', 'ffcertificate' ),
            'description'     => __( 'Normalize names (Brazilian capitalization) and emails (lowercase)', 'ffcertificate' ),
            'icon'            => 'ffc-icon-edit',
            'batch_size'      => 100,
            'order'           => $order++,
            'requires_column' => false
        );

        // v4.4.0: User capabilities migration
        $this->migrations['user_capabilities'] = array(
            'name'            => __( 'User Capabilities', 'ffcertificate' ),
            'description'     => __( 'Set user capabilities based on submission/appointment history', 'ffcertificate' ),
            'icon'            => 'ffc-icon-key',
            'batch_size'      => 50,
            'order'           => $order++,
            'requires_column' => false
        );

        // Data cleanup (option-based, not batch)
        $this->migrations['data_cleanup'] = array(
            'name'            => __( 'Data Cleanup', 'ffcertificate' ),
            'description'     => __( 'Remove old migration data and cleanup database', 'ffcertificate' ),
            'icon'            => 'ffc-icon-delete',
            'batch_size'      => 0,
            'order'           => 999, // Always last
            'requires_column' => false
        );

        // Allow plugins to add custom migrations
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ffcertificate is the plugin prefix
        $this->migrations = apply_filters( 'ffcertificate_migrations_registry', $this->migrations );
    }

    /**
     * Get all registered migrations
     *
     * @return array<string, array<string, mixed>>
     */
    public function get_all_migrations(): array {
        return $this->migrations;
    }

    /**
     * Get a specific migration definition
     *
     * @param string $migration_key Migration identifier
     * @return array<string, mixed>|null Migration definition or null if not found
     */
    public function get_migration( string $migration_key ) {
        return isset( $this->migrations[ $migration_key ] ) ? $this->migrations[ $migration_key ] : null;
    }

    /**
     * Get field definition for a specific field
     *
     * @param string $field_key Field identifier
     * @return array<string, mixed>|null Field definition or null if not found
     */
    public function get_field_definition( string $field_key ) {
        return isset( $this->field_definitions[ $field_key ] ) ? $this->field_definitions[ $field_key ] : null;
    }

    /**
     * Get all field definitions
     *
     * @return array<string, array<string, mixed>>
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
        $special_migrations = array( 'magic_tokens', 'data_cleanup', 'user_link', 'encrypt_sensitive_data', 'cleanup_unencrypted', 'name_normalization', 'user_capabilities' );

        if ( in_array( $migration_key, $special_migrations ) ) {
            return true;
        }

        // Field migrations - check if field definition exists
        return isset( $this->field_definitions[ $migration_key ] );
    }
}
