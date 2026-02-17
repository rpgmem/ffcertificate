<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\AccessRestrictionChecker;

/**
 * Tests for AccessRestrictionChecker: mask normalization, edge cases,
 * combined restrictions, whitespace handling, and consume_ticket.
 *
 * Note: Basic restriction tests are in FormProcessorRestrictionsTest.
 * This file covers additional edge cases and the consume_ticket method.
 *
 * @covers \FreeFormCertificate\Frontend\AccessRestrictionChecker
 */
class AccessRestrictionCheckerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
    }

    protected function tearDown(): void {
        unset( $_POST['ffc_password'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // CPF mask normalization
    // ==================================================================

    public function test_cpf_with_dots_and_dash_normalizes_for_denylist(): void {
        $config = array(
            'restrictions'      => array( 'denylist' => '1' ),
            'denied_users_list' => '123.456.789-01',
        );

        // Input already clean (no mask)
        $result = AccessRestrictionChecker::check( $config, '12345678901', '', 1 );
        $this->assertFalse( $result['allowed'] );
    }

    public function test_cpf_input_with_mask_normalizes_for_denylist(): void {
        $config = array(
            'restrictions'      => array( 'denylist' => '1' ),
            'denied_users_list' => '12345678901',  // No mask in list
        );

        // Input with mask — should still match after cleaning
        $result = AccessRestrictionChecker::check( $config, '123.456.789-01', '', 1 );
        $this->assertFalse( $result['allowed'] );
    }

    public function test_rf_number_with_slash_normalizes(): void {
        $config = array(
            'restrictions'      => array( 'denylist' => '1' ),
            'denied_users_list' => '12.345.678/0001-90',
        );

        // RF (CNPJ format) - should match after stripping non-digits
        $result = AccessRestrictionChecker::check( $config, '12345678000190', '', 1 );
        $this->assertFalse( $result['allowed'] );
    }

    public function test_allowlist_normalizes_both_sides(): void {
        $config = array(
            'restrictions'       => array( 'allowlist' => '1' ),
            'allowed_users_list' => '111.222.333-44',
        );

        // Input without mask matches list with mask
        $result = AccessRestrictionChecker::check( $config, '11122233344', '', 1 );
        $this->assertTrue( $result['allowed'] );
    }

    // ==================================================================
    // Whitespace and blank line handling
    // ==================================================================

    public function test_denylist_ignores_blank_lines(): void {
        $config = array(
            'restrictions'      => array( 'denylist' => '1' ),
            'denied_users_list' => "\n\n123.456.789-01\n\n\n",
        );

        $result = AccessRestrictionChecker::check( $config, '12345678901', '', 1 );
        $this->assertFalse( $result['allowed'] );
    }

    public function test_denylist_trims_whitespace(): void {
        $config = array(
            'restrictions'      => array( 'denylist' => '1' ),
            'denied_users_list' => "  12345678901  \n  98765432100  ",
        );

        $result = AccessRestrictionChecker::check( $config, '12345678901', '', 1 );
        $this->assertFalse( $result['allowed'] );
    }

    public function test_allowlist_ignores_blank_lines(): void {
        $config = array(
            'restrictions'       => array( 'allowlist' => '1' ),
            'allowed_users_list' => "\n\n12345678901\n\n",
        );

        $result = AccessRestrictionChecker::check( $config, '12345678901', '', 1 );
        $this->assertTrue( $result['allowed'] );
    }

    public function test_empty_denylist_string_allows_all(): void {
        $config = array(
            'restrictions'      => array( 'denylist' => '1' ),
            'denied_users_list' => '',
        );

        $result = AccessRestrictionChecker::check( $config, '12345678901', '', 1 );
        $this->assertTrue( $result['allowed'] );
    }

    public function test_empty_allowlist_string_blocks_all(): void {
        $config = array(
            'restrictions'       => array( 'allowlist' => '1' ),
            'allowed_users_list' => '',
        );

        $result = AccessRestrictionChecker::check( $config, '12345678901', '', 1 );
        $this->assertFalse( $result['allowed'] );
    }

    // ==================================================================
    // Inactive restrictions (value '0' or missing)
    // ==================================================================

    public function test_denylist_disabled_with_zero_allows(): void {
        $config = array(
            'restrictions'      => array( 'denylist' => '0' ),
            'denied_users_list' => '12345678901',
        );

        $result = AccessRestrictionChecker::check( $config, '12345678901', '', 1 );
        $this->assertTrue( $result['allowed'] );
    }

    public function test_restrictions_key_present_but_empty_allows(): void {
        $config = array(
            'restrictions' => array(),
        );

        $result = AccessRestrictionChecker::check( $config, '12345678901', '', 1 );
        $this->assertTrue( $result['allowed'] );
    }

    public function test_missing_denied_users_list_key_allows(): void {
        $config = array(
            'restrictions' => array( 'denylist' => '1' ),
            // 'denied_users_list' key is missing
        );

        $result = AccessRestrictionChecker::check( $config, '12345678901', '', 1 );
        $this->assertTrue( $result['allowed'] );
    }

    public function test_missing_allowed_users_list_key_blocks(): void {
        $config = array(
            'restrictions' => array( 'allowlist' => '1' ),
            // 'allowed_users_list' key is missing
        );

        $result = AccessRestrictionChecker::check( $config, '12345678901', '', 1 );
        $this->assertFalse( $result['allowed'] );
    }

    // ==================================================================
    // Combined restrictions — multiple rules active
    // ==================================================================

    public function test_password_checked_before_denylist(): void {
        $config = array(
            'restrictions'      => array( 'password' => '1', 'denylist' => '1' ),
            'validation_code'   => 'secret',
            'denied_users_list' => '12345678901',
        );

        // Password is empty — fails before denylist check
        $_POST['ffc_password'] = '';

        $result = AccessRestrictionChecker::check( $config, '12345678901', '', 1 );

        $this->assertFalse( $result['allowed'] );
        $this->assertStringContainsString( 'Password is required', $result['message'] );
    }

    public function test_password_passes_then_denylist_blocks(): void {
        $config = array(
            'restrictions'      => array( 'password' => '1', 'denylist' => '1' ),
            'validation_code'   => 'secret',
            'denied_users_list' => '12345678901',
        );

        $_POST['ffc_password'] = 'secret';

        $result = AccessRestrictionChecker::check( $config, '12345678901', '', 1 );

        $this->assertFalse( $result['allowed'] );
        $this->assertStringContainsString( 'blocked', $result['message'] );
    }

    public function test_all_restrictions_pass(): void {
        $config = array(
            'restrictions'       => array(
                'password'  => '1',
                'denylist'  => '1',
                'allowlist' => '1',
            ),
            'validation_code'    => 'mypass',
            'denied_users_list'  => "99999999999",
            'allowed_users_list' => "12345678901\n55566677788",
        );

        $_POST['ffc_password'] = 'mypass';

        $result = AccessRestrictionChecker::check( $config, '12345678901', '', 1 );
        $this->assertTrue( $result['allowed'] );
        $this->assertFalse( $result['is_ticket'] );
    }

    public function test_allowlist_pass_then_ticket_consumed(): void {
        $config = array(
            'restrictions'       => array(
                'allowlist' => '1',
                'ticket'    => '1',
            ),
            'allowed_users_list' => "12345678901",
            'generated_codes_list' => "TKT-001\nTKT-002",
        );

        Functions\when( 'update_post_meta' )->justReturn( true );

        $result = AccessRestrictionChecker::check( $config, '12345678901', 'TKT-001', 42 );

        $this->assertTrue( $result['allowed'] );
        $this->assertTrue( $result['is_ticket'] );
    }

    // ==================================================================
    // Ticket edge cases
    // ==================================================================

    public function test_ticket_with_surrounding_whitespace_matches(): void {
        $config = array(
            'restrictions'         => array( 'ticket' => '1' ),
            'generated_codes_list' => "  ABC-DEF-123  \n  GHI-JKL-456  ",
        );

        Functions\when( 'update_post_meta' )->justReturn( true );

        $result = AccessRestrictionChecker::check( $config, '', 'abc-def-123', 1 );

        $this->assertTrue( $result['allowed'] );
        $this->assertTrue( $result['is_ticket'] );
    }

    public function test_ticket_empty_list_blocks(): void {
        $config = array(
            'restrictions'         => array( 'ticket' => '1' ),
            'generated_codes_list' => '',
        );

        $result = AccessRestrictionChecker::check( $config, '', 'ANY-TICKET', 1 );

        $this->assertFalse( $result['allowed'] );
        $this->assertStringContainsString( 'Invalid or already used', $result['message'] );
    }

    public function test_ticket_missing_list_key_blocks(): void {
        $config = array(
            'restrictions' => array( 'ticket' => '1' ),
            // 'generated_codes_list' key missing
        );

        $result = AccessRestrictionChecker::check( $config, '', 'ABC-123', 1 );

        $this->assertFalse( $result['allowed'] );
    }

    // ==================================================================
    // consume_ticket() — standalone method
    // ==================================================================

    public function test_consume_ticket_removes_ticket_from_config(): void {
        $existing_config = array(
            'generated_codes_list' => "AAA-111\nBBB-222\nCCC-333",
        );

        Functions\when( 'get_post_meta' )->justReturn( $existing_config );

        $saved_config = null;
        Functions\expect( 'update_post_meta' )
            ->once()
            ->with( 10, '_ffc_form_config', Mockery::on( function( $config ) use ( &$saved_config ) {
                $saved_config = $config;
                return true;
            } ) )
            ->andReturn( true );

        AccessRestrictionChecker::consume_ticket( 10, 'BBB-222' );

        // Verify the ticket was removed
        $remaining = array_filter( array_map( 'trim', explode( "\n", $saved_config['generated_codes_list'] ) ) );
        $this->assertContains( 'AAA-111', $remaining );
        $this->assertContains( 'CCC-333', $remaining );
        $this->assertNotContains( 'BBB-222', $remaining );
    }

    public function test_consume_ticket_handles_last_ticket(): void {
        $existing_config = array(
            'generated_codes_list' => 'LAST-TICKET',
        );

        Functions\when( 'get_post_meta' )->justReturn( $existing_config );

        $saved_config = null;
        Functions\expect( 'update_post_meta' )
            ->once()
            ->with( 5, '_ffc_form_config', Mockery::on( function( $config ) use ( &$saved_config ) {
                $saved_config = $config;
                return true;
            } ) )
            ->andReturn( true );

        AccessRestrictionChecker::consume_ticket( 5, 'LAST-TICKET' );

        $remaining = array_filter( array_map( 'trim', explode( "\n", $saved_config['generated_codes_list'] ) ) );
        $this->assertEmpty( $remaining );
    }

    public function test_consume_ticket_with_missing_config_key(): void {
        $existing_config = array();  // No 'generated_codes_list'

        Functions\when( 'get_post_meta' )->justReturn( $existing_config );

        $saved_config = null;
        Functions\expect( 'update_post_meta' )
            ->once()
            ->with( 1, '_ffc_form_config', Mockery::on( function( $config ) use ( &$saved_config ) {
                $saved_config = $config;
                return true;
            } ) )
            ->andReturn( true );

        AccessRestrictionChecker::consume_ticket( 1, 'ANYTHING' );

        // Should not crash — empty list, nothing to remove
        $this->assertSame( '', $saved_config['generated_codes_list'] );
    }

    // ==================================================================
    // CPF edge cases
    // ==================================================================

    public function test_empty_cpf_not_in_denylist(): void {
        $config = array(
            'restrictions'      => array( 'denylist' => '1' ),
            'denied_users_list' => "12345678901\n98765432100",
        );

        // Empty CPF should not match any entry
        $result = AccessRestrictionChecker::check( $config, '', '', 1 );
        $this->assertTrue( $result['allowed'] );
    }

    public function test_empty_cpf_not_in_allowlist_blocks(): void {
        $config = array(
            'restrictions'       => array( 'allowlist' => '1' ),
            'allowed_users_list' => '12345678901',
        );

        // Empty CPF is not in the allowlist
        $result = AccessRestrictionChecker::check( $config, '', '', 1 );
        $this->assertFalse( $result['allowed'] );
    }

    public function test_cpf_with_spaces_normalizes(): void {
        $config = array(
            'restrictions'      => array( 'denylist' => '1' ),
            'denied_users_list' => '123 456 789 01',
        );

        // Spaces are non-digit, stripped by preg_replace('/\D/', '')
        $result = AccessRestrictionChecker::check( $config, '12345678901', '', 1 );
        $this->assertFalse( $result['allowed'] );
    }
}
