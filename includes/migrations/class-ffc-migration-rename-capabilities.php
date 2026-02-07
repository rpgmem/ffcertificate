<?php
declare(strict_types=1);

/**
 * Migration: Rename capabilities from old to new naming convention
 *
 * Renames:
 * - ffc_view_own_appointments -> ffc_view_self_scheduling
 *
 * @since 4.5.0
 * @package FreeFormCertificate\Migrations
 */

namespace FreeFormCertificate\Migrations;

if (!defined('ABSPATH')) {
    exit;
}

class MigrationRenameCapabilities {

    /**
     * Option key to track migration status
     */
    private const MIGRATION_OPTION = 'ffc_migration_rename_capabilities_completed';

    /**
     * Capability mappings (old => new)
     *
     * @var array<string, string>
     */
    private static array $capability_mappings = [
        'ffc_view_own_appointments' => 'ffc_view_self_scheduling',
    ];

    /**
     * Check if migration has been completed
     *
     * @return bool
     */
    public static function is_completed(): bool {
        return (bool) get_option(self::MIGRATION_OPTION, false);
    }

    /**
     * Run the migration
     *
     * @return array{success: bool, message: string, updated_users: int}
     */
    public static function run(): array {
        // Check if already completed
        if (self::is_completed()) {
            return [
                'success' => true,
                'message' => __('Capability migration already completed.', 'ffcertificate'),
                'updated_users' => 0,
            ];
        }

        $updated_users = 0;

        // Get all users
        $users = get_users(['fields' => 'ID']);

        foreach ($users as $user_id) {
            $user = new \WP_User($user_id);
            $user_updated = false;

            foreach (self::$capability_mappings as $old_cap => $new_cap) {
                // Check if user has old capability
                if ($user->has_cap($old_cap)) {
                    // Add new capability
                    $user->add_cap($new_cap);
                    // Remove old capability
                    $user->remove_cap($old_cap);
                    $user_updated = true;
                }
            }

            if ($user_updated) {
                $updated_users++;
            }
        }

        // Also update the ffc_user role if it exists
        $role = get_role('ffc_user');
        if ($role) {
            foreach (self::$capability_mappings as $old_cap => $new_cap) {
                if ($role->has_cap($old_cap)) {
                    $role->add_cap($new_cap);
                    $role->remove_cap($old_cap);
                }
            }
        }

        // Mark as completed
        update_option(self::MIGRATION_OPTION, true);

        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %d: number of users updated */
                __('Capability migration completed. Updated %d users.', 'ffcertificate'),
                $updated_users
            ),
            'updated_users' => $updated_users,
        ];
    }

    /**
     * Get migration status
     *
     * @return array{completed: bool, mappings: array}
     */
    public static function get_status(): array {
        return [
            'completed' => self::is_completed(),
            'mappings' => self::$capability_mappings,
        ];
    }
}
