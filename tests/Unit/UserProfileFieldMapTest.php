<?php
/**
 * Tests for UserProfileFieldMap — the declarative per-field descriptor
 * the UserProfileService consults to route reads and writes.
 *
 * Pure data class, no WP dependencies, no I/O.
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\UserProfileFieldMap;

/**
 * @coversNothing
 */
class UserProfileFieldMapTest extends TestCase {

    public function test_has_returns_true_for_registered_field(): void {
        $this->assertTrue( UserProfileFieldMap::has( 'display_name' ) );
        $this->assertTrue( UserProfileFieldMap::has( 'cpf' ) );
    }

    public function test_has_returns_false_for_unknown_field(): void {
        $this->assertFalse( UserProfileFieldMap::has( 'not_a_profile_field' ) );
        $this->assertFalse( UserProfileFieldMap::has( '' ) );
    }

    public function test_get_returns_spec_or_null(): void {
        $this->assertNull( UserProfileFieldMap::get( 'nope' ) );

        $spec = UserProfileFieldMap::get( 'cpf' );
        $this->assertIsArray( $spec );
        $this->assertSame( UserProfileFieldMap::STORAGE_USERMETA, $spec['storage'] );
        $this->assertSame( 'ffc_user_cpf', $spec['meta_key'] );
        $this->assertTrue( $spec['sensitive'] );
        $this->assertTrue( $spec['hashable'] );
    }

    public function test_is_sensitive_flags_expected_fields(): void {
        $this->assertTrue( UserProfileFieldMap::is_sensitive( 'cpf' ) );
        $this->assertTrue( UserProfileFieldMap::is_sensitive( 'rf' ) );
        $this->assertTrue( UserProfileFieldMap::is_sensitive( 'rg' ) );
        $this->assertFalse( UserProfileFieldMap::is_sensitive( 'display_name' ) );
        $this->assertFalse( UserProfileFieldMap::is_sensitive( 'unknown_field' ) );
    }

    public function test_sensitive_field_keys_matches_registry_flags(): void {
        $sensitive = UserProfileFieldMap::sensitive_field_keys();

        // At minimum CPF/RF/RG; no non-sensitive leaks.
        $this->assertContains( 'cpf', $sensitive );
        $this->assertContains( 'rf', $sensitive );
        $this->assertContains( 'rg', $sensitive );
        $this->assertNotContains( 'display_name', $sensitive );
        $this->assertNotContains( 'phone', $sensitive );
    }

    public function test_hash_meta_key_returns_suffix_for_hashable_fields(): void {
        $this->assertSame( 'ffc_user_cpf_hash', UserProfileFieldMap::hash_meta_key( 'cpf' ) );
        $this->assertSame( 'ffc_user_rf_hash', UserProfileFieldMap::hash_meta_key( 'rf' ) );
    }

    public function test_hash_meta_key_is_null_for_non_hashable_or_non_usermeta(): void {
        // RG is sensitive but not hashable.
        $this->assertNull( UserProfileFieldMap::hash_meta_key( 'rg' ) );
        // display_name lives in the profile table, not usermeta.
        $this->assertNull( UserProfileFieldMap::hash_meta_key( 'display_name' ) );
        $this->assertNull( UserProfileFieldMap::hash_meta_key( 'unknown_field' ) );
    }

    public function test_group_by_storage_splits_keys_across_layers(): void {
        $grouped = UserProfileFieldMap::group_by_storage(
            array( 'user_email', 'display_name', 'phone', 'cpf', 'rf', 'not_a_field' )
        );

        $this->assertArrayHasKey( UserProfileFieldMap::STORAGE_WP_USER, $grouped );
        $this->assertArrayHasKey( UserProfileFieldMap::STORAGE_PROFILE_TABLE, $grouped );
        $this->assertArrayHasKey( UserProfileFieldMap::STORAGE_USERMETA, $grouped );

        $this->assertSame( array( 'user_email' ), $grouped[ UserProfileFieldMap::STORAGE_WP_USER ] );
        $this->assertSame(
            array( 'display_name', 'phone' ),
            $grouped[ UserProfileFieldMap::STORAGE_PROFILE_TABLE ]
        );
        $this->assertSame(
            array( 'cpf', 'rf' ),
            $grouped[ UserProfileFieldMap::STORAGE_USERMETA ]
        );
    }

    public function test_group_by_storage_drops_unknown_keys_silently(): void {
        $grouped = UserProfileFieldMap::group_by_storage( array( 'nope', 'also_nope' ) );
        $this->assertSame( array(), $grouped );
    }

    public function test_display_name_declares_mirror_to_wp_users(): void {
        $spec = UserProfileFieldMap::get( 'display_name' );
        $this->assertIsArray( $spec );
        $this->assertArrayHasKey( 'mirrors', $spec );
        $this->assertIsArray( $spec['mirrors'] );
        $this->assertNotEmpty( $spec['mirrors'] );
        $this->assertSame( UserProfileFieldMap::STORAGE_WP_USER, $spec['mirrors'][0]['storage'] );
        $this->assertSame( 'display_name', $spec['mirrors'][0]['column'] );
    }

    public function test_field_keys_contains_every_expected_canonical_field(): void {
        $keys = UserProfileFieldMap::field_keys();

        $expected = array(
            'user_email',
            'display_name',
            'phone',
            'department',
            'organization',
            'notes',
            'preferences',
            'cpf',
            'rf',
            'rg',
            'jornada',
        );
        foreach ( $expected as $k ) {
            $this->assertContains( $k, $keys, "Missing canonical field: {$k}" );
        }
    }
}
