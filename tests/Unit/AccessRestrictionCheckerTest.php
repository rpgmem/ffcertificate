<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\AccessRestrictionChecker;
use FreeFormCertificate\Tests\Support\Identifiers;

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
		// Reset the global $wpdb some ticket tests install so it never leaks
		// into a sibling test in the same process.
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Install a global $wpdb whose INSERT IGNORE reports `$rows_affected`
	 * rows, so the atomic ticket claim (try_claim_ticket) can be driven
	 * deterministically: 1 = this caller won, 0 = ticket already claimed.
	 *
	 * @param int $rows_affected Rows the mocked query reports as affected.
	 */
	private function mock_wpdb_claim( int $rows_affected, ?object $existing = null ): void {
		global $wpdb;
		$wpdb                = Mockery::mock( 'wpdb' );
		$wpdb->options       = 'wp_options';
		$wpdb->prefix        = 'wp_';
		$wpdb->rows_affected = 0;
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v );
		// ReprintDetector's lookup (#1574): the submission a spent ticket
		// issued, or none.
		$wpdb->shouldReceive( 'get_row' )->andReturn( $existing );
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function () use ( $wpdb, $rows_affected ) {
				$wpdb->rows_affected = $rows_affected;
				return $rows_affected;
			}
		);
	}

	/**
	 * A CPF in the `xxx.xxx.xxx-xx` mask an operator pastes into a list.
	 *
	 * @param string $cpf Eleven digits.
	 */
	private static function masked( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9 );
	}

	// ==================================================================
	// CPF mask normalization
	// ==================================================================

	public function test_cpf_with_dots_and_dash_normalizes_for_denylist(): void {
		$config = array(
			'restrictions'      => array( 'denylist' => '1' ),
			'denied_users_list' => self::masked( Identifiers::CPF_A ),
		);

		// Input already clean (no mask)
		$result = AccessRestrictionChecker::check( $config, Identifiers::CPF_A, '', 1 );
		$this->assertFalse( $result['allowed'] );
	}

	public function test_cpf_input_with_mask_normalizes_for_denylist(): void {
		$config = array(
			'restrictions'      => array( 'denylist' => '1' ),
			'denied_users_list' => Identifiers::CPF_A,  // No mask in list
		);

		// Input with mask — should still match after cleaning
		$result = AccessRestrictionChecker::check( $config, self::masked( Identifiers::CPF_A ), '', 1 );
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
			'allowed_users_list' => self::masked( Identifiers::CPF_B ),
		);

		// Input without mask matches list with mask
		$result = AccessRestrictionChecker::check( $config, Identifiers::CPF_B, '', 1 );
		$this->assertTrue( $result['allowed'] );
	}

	// ==================================================================
	// Whitespace and blank line handling
	// ==================================================================

	public function test_denylist_ignores_blank_lines(): void {
		$config = array(
			'restrictions'      => array( 'denylist' => '1' ),
			'denied_users_list' => "\n\n" . self::masked( Identifiers::CPF_A ) . "\n\n\n",
		);

		$result = AccessRestrictionChecker::check( $config, Identifiers::CPF_A, '', 1 );
		$this->assertFalse( $result['allowed'] );
	}

	public function test_denylist_trims_whitespace(): void {
		$config = array(
			'restrictions'      => array( 'denylist' => '1' ),
			'denied_users_list' => '  ' . Identifiers::CPF_A . "  \n  " . Identifiers::CPF_B . '  ',
		);

		$result = AccessRestrictionChecker::check( $config, Identifiers::CPF_A, '', 1 );
		$this->assertFalse( $result['allowed'] );
	}

	public function test_allowlist_ignores_blank_lines(): void {
		$config = array(
			'restrictions'       => array( 'allowlist' => '1' ),
			'allowed_users_list' => "\n\n" . Identifiers::CPF_A . "\n\n",
		);

		$result = AccessRestrictionChecker::check( $config, Identifiers::CPF_A, '', 1 );
		$this->assertTrue( $result['allowed'] );
	}

	public function test_empty_denylist_string_allows_all(): void {
		$config = array(
			'restrictions'      => array( 'denylist' => '1' ),
			'denied_users_list' => '',
		);

		$result = AccessRestrictionChecker::check( $config, Identifiers::CPF_A, '', 1 );
		$this->assertTrue( $result['allowed'] );
	}

	public function test_empty_allowlist_string_blocks_all(): void {
		$config = array(
			'restrictions'       => array( 'allowlist' => '1' ),
			'allowed_users_list' => '',
		);

		$result = AccessRestrictionChecker::check( $config, Identifiers::CPF_A, '', 1 );
		$this->assertFalse( $result['allowed'] );
	}

	// ==================================================================
	// Inactive restrictions (value '0' or missing)
	// ==================================================================

	public function test_denylist_disabled_with_zero_allows(): void {
		$config = array(
			'restrictions'      => array( 'denylist' => '0' ),
			'denied_users_list' => Identifiers::CPF_A,
		);

		$result = AccessRestrictionChecker::check( $config, Identifiers::CPF_A, '', 1 );
		$this->assertTrue( $result['allowed'] );
	}

	public function test_restrictions_key_present_but_empty_allows(): void {
		$config = array(
			'restrictions' => array(),
		);

		$result = AccessRestrictionChecker::check( $config, Identifiers::CPF_A, '', 1 );
		$this->assertTrue( $result['allowed'] );
	}

	public function test_missing_denied_users_list_key_allows(): void {
		$config = array(
			'restrictions' => array( 'denylist' => '1' ),
			// 'denied_users_list' key is missing
		);

		$result = AccessRestrictionChecker::check( $config, Identifiers::CPF_A, '', 1 );
		$this->assertTrue( $result['allowed'] );
	}

	public function test_missing_allowed_users_list_key_blocks(): void {
		$config = array(
			'restrictions' => array( 'allowlist' => '1' ),
			// 'allowed_users_list' key is missing
		);

		$result = AccessRestrictionChecker::check( $config, Identifiers::CPF_A, '', 1 );
		$this->assertFalse( $result['allowed'] );
	}

	// ==================================================================
	// Combined restrictions — multiple rules active
	// ==================================================================

	public function test_password_checked_before_denylist(): void {
		$config = array(
			'restrictions'      => array( 'password' => '1', 'denylist' => '1' ),
			'validation_code'   => 'secret',
			'denied_users_list' => Identifiers::CPF_A,
		);

		// Password is empty — fails before denylist check
		$_POST['ffc_password'] = '';

		$result = AccessRestrictionChecker::check( $config, Identifiers::CPF_A, '', 1 );

		$this->assertFalse( $result['allowed'] );
		$this->assertStringContainsString( 'Password is required', $result['message'] );
	}

	public function test_password_passes_then_denylist_blocks(): void {
		$config = array(
			'restrictions'      => array( 'password' => '1', 'denylist' => '1' ),
			'validation_code'   => 'secret',
			'denied_users_list' => Identifiers::CPF_A,
		);

		$_POST['ffc_password'] = 'secret';

		$result = AccessRestrictionChecker::check( $config, Identifiers::CPF_A, '', 1 );

		$this->assertFalse( $result['allowed'] );
		$this->assertStringContainsString( 'cannot use this form', $result['message'] );
		$this->assertStringContainsString( 'contact', $result['message'], 'It says whom to turn to (#2341).' );
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
			'allowed_users_list' => Identifiers::CPF_A . "\n" . Identifiers::CPF_B,
		);

		$_POST['ffc_password'] = 'mypass';

		$result = AccessRestrictionChecker::check( $config, Identifiers::CPF_A, '', 1 );
		$this->assertTrue( $result['allowed'] );
		$this->assertFalse( $result['is_ticket'] );
	}

	public function test_allowlist_pass_then_ticket_consumed(): void {
		$config = array(
			'restrictions'       => array(
				'allowlist' => '1',
				'ticket'    => '1',
			),
			'allowed_users_list' => Identifiers::CPF_A,
			'generated_codes_list' => "TKT-001\nTKT-002",
		);

		$this->mock_wpdb_claim( 1 ); // This caller wins the atomic claim.
		// #2341: the ticket leaves the list only once a submission is saved.
		Functions\expect( 'update_post_meta' )->never();

		$result = AccessRestrictionChecker::check( $config, Identifiers::CPF_A, 'TKT-001', 42 );

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

		$this->mock_wpdb_claim( 1 ); // This caller wins the atomic claim.
		Functions\when( 'update_post_meta' )->justReturn( true );

		$result = AccessRestrictionChecker::check( $config, '', 'abc-def-123', 1 );

		$this->assertTrue( $result['allowed'] );
		$this->assertTrue( $result['is_ticket'] );
	}

	public function test_ticket_valid_but_claim_lost_blocks_as_already_used(): void {
		// The ticket IS still in the list (passes the membership pre-filter),
		// but a concurrent request already claimed it — INSERT IGNORE reports
		// 0 rows — so this caller must be rejected, not issued a second cert.
		$config = array(
			'restrictions'         => array( 'ticket' => '1' ),
			'generated_codes_list' => "TKT-001\nTKT-002",
		);

		$this->mock_wpdb_claim( 0 ); // Lost the race: ticket already claimed.
		// update_post_meta must NOT run on the losing path.
		Functions\expect( 'update_post_meta' )->never();

		$result = AccessRestrictionChecker::check( $config, '', 'TKT-001', 42 );

		$this->assertFalse( $result['allowed'] );
		$this->assertFalse( $result['is_ticket'] );
		$this->assertStringContainsString( 'Invalid or already used', $result['message'] );
	}

	public function test_a_spent_ticket_that_issued_a_certificate_passes_as_a_reprint(): void {
		// #1574: the ticket left the list when it issued a certificate; using
		// it again must reach the reprint, not "already used".
		$config = array(
			'restrictions'         => array( 'ticket' => '1' ),
			'generated_codes_list' => 'TKT-002',
		);
		$this->mock_wpdb_claim( 0, (object) array( 'id' => '7', 'data' => '{}', 'submission_date' => '2026-01-01 10:00:00' ) );
		Functions\expect( 'update_post_meta' )->never();

		$result = AccessRestrictionChecker::check( $config, '', 'tkt-001', 42 );

		$this->assertTrue( $result['allowed'] );
		$this->assertFalse( $result['is_ticket'], 'A reprint claims and consumes nothing.' );
	}

	public function test_a_spent_ticket_on_a_quiz_form_is_still_rejected(): void {
		// On a quiz form a request that passes is a new attempt; a spent
		// ticket must not buy one.
		$config = array(
			'restrictions'         => array( 'ticket' => '1' ),
			'generated_codes_list' => 'TKT-002',
			'quiz_enabled'         => '1',
		);
		$this->mock_wpdb_claim( 0, (object) array( 'id' => '7', 'data' => '{}', 'submission_date' => '2026-01-01 10:00:00' ) );

		$result = AccessRestrictionChecker::check( $config, '', 'TKT-001', 42 );

		$this->assertFalse( $result['allowed'] );
		$this->assertStringContainsString( 'Invalid or already used', $result['message'] );
	}

	public function test_ticket_allowed_when_db_layer_unavailable(): void {
		// No usable $wpdb (e.g. very early bootstrap): the claim cannot be
		// made atomic, so fall back to the legacy list check by allowing the
		// valid ticket rather than hard-failing a legitimate submission.
		unset( $GLOBALS['wpdb'] );
		$config = array(
			'restrictions'         => array( 'ticket' => '1' ),
			'generated_codes_list' => "TKT-001\nTKT-002",
		);

		Functions\when( 'update_post_meta' )->justReturn( true );

		$result = AccessRestrictionChecker::check( $config, '', 'TKT-001', 42 );

		$this->assertTrue( $result['allowed'] );
		$this->assertTrue( $result['is_ticket'] );
	}

	public function test_ticket_empty_list_blocks(): void {
		$config = array(
			'restrictions'         => array( 'ticket' => '1' ),
			'generated_codes_list' => '',
		);

		$this->mock_wpdb_claim( 0 );

		$result = AccessRestrictionChecker::check( $config, '', 'ANY-TICKET', 1 );

		$this->assertFalse( $result['allowed'] );
		$this->assertStringContainsString( 'Invalid or already used', $result['message'] );
	}

	public function test_ticket_missing_list_key_blocks(): void {
		$config = array(
			'restrictions' => array( 'ticket' => '1' ),
			// 'generated_codes_list' key missing
		);

		$this->mock_wpdb_claim( 0 );

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

	/**
	 * `get_post_meta` returns `''` when the meta row does not exist, and in
	 * PHP 8 assigning an offset on a string is fatal (#1087). Nothing is
	 * listed, so nothing is written either (#2341).
	 */
	public function test_consume_ticket_survives_an_absent_config_meta(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\expect( 'update_post_meta' )->never();

		AccessRestrictionChecker::consume_ticket( 1, 'ANYTHING' );
	}

	public function test_consume_ticket_with_missing_config_key(): void {
		Functions\when( 'get_post_meta' )->justReturn( array() );
		Functions\expect( 'update_post_meta' )->never();

		AccessRestrictionChecker::consume_ticket( 1, 'ANYTHING' );
	}

	public function test_consume_ticket_matches_the_line_in_any_case_and_keeps_the_others(): void {
		// #2341: check() matched `abc-1` against `ABC-1`, and consume compared
		// the raw strings, so a ticket typed in lower case stayed listed.
		Functions\when( 'get_post_meta' )->justReturn( array( 'generated_codes_list' => "ABC-1\n  def-2 \nGHI-3" ) );
		$saved = null;
		Functions\expect( 'update_post_meta' )->once()->andReturnUsing(
			static function ( $id, $key, $config ) use ( &$saved ) {
				$saved = $config;
				return true;
			}
		);

		AccessRestrictionChecker::consume_ticket( 3, ' Def-2' );

		$this->assertSame( "ABC-1\nGHI-3", $saved['generated_codes_list'] );
	}

	public function test_consume_ticket_writes_the_config_as_it_is_now(): void {
		// #2341: the config is re-read at consume time, so a setting the
		// operator changed while the submission ran is not written back old.
		Functions\when( 'get_post_meta' )->justReturn(
			array(
				'generated_codes_list' => "T-1\nT-2\nNEW-9",
				'success_message'      => 'edited meanwhile',
			)
		);
		$saved = null;
		Functions\expect( 'update_post_meta' )->once()->andReturnUsing(
			static function ( $id, $key, $config ) use ( &$saved ) {
				$saved = $config;
				return true;
			}
		);

		AccessRestrictionChecker::consume_ticket( 3, 'T-1' );

		$this->assertSame( "T-2\nNEW-9", $saved['generated_codes_list'] );
		$this->assertSame( 'edited meanwhile', $saved['success_message'] );
	}

	public function test_consume_ticket_writes_nothing_when_the_ticket_is_not_listed(): void {
		Functions\when( 'get_post_meta' )->justReturn( array( 'generated_codes_list' => 'T-2' ) );
		Functions\expect( 'update_post_meta' )->never();

		AccessRestrictionChecker::consume_ticket( 3, 'T-1' );
	}

	// ==================================================================
	// release_ticket() / reap_stale_claims() (#2341)
	// ==================================================================

	public function test_release_ticket_deletes_the_claim_of_the_normalised_ticket(): void {
		$deleted = array();
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$deleted ) {
				$deleted[] = $name;
				return true;
			}
		);

		AccessRestrictionChecker::release_ticket( 42, ' tkt-001 ' );
		AccessRestrictionChecker::release_ticket( 42, '   ' );

		$this->assertSame( array( 'ffc_ticket_used_42_' . hash( 'sha256', 'TKT-001' ) ), $deleted );
	}

	public function test_a_released_claim_lets_the_ticket_be_claimed_again(): void {
		// The claim and its release name the same row: the name the INSERT
		// IGNORE wrote is the one release_ticket() deletes.
		global $wpdb;
		$rows                = array();
		$wpdb                = Mockery::mock( 'wpdb' );
		$wpdb->options       = 'wp_options';
		$wpdb->rows_affected = 0;
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql, ...$args ) => $args[0] );
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( $name ) use ( $wpdb, &$rows ) {
				$wpdb->rows_affected = isset( $rows[ $name ] ) ? 0 : 1;
				$rows[ $name ]       = true;
				return $wpdb->rows_affected;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$rows ) {
				unset( $rows[ $name ] );
				return true;
			}
		);
		$config = array(
			'restrictions'         => array( 'ticket' => '1' ),
			'generated_codes_list' => 'TKT-001',
		);

		$this->assertTrue( AccessRestrictionChecker::check( $config, '', 'TKT-001', 42 )['is_ticket'] );
		AccessRestrictionChecker::release_ticket( 42, 'tkt-001' );
		$this->assertTrue( AccessRestrictionChecker::check( $config, '', 'TKT-001', 42 )['is_ticket'] );
	}

	public function test_reap_deletes_old_claims_whose_ticket_left_the_list(): void {
		global $wpdb;
		$listed  = 'ffc_ticket_used_7_' . hash( 'sha256', 'STILL-HERE' );
		$spent   = 'ffc_ticket_used_7_' . hash( 'sha256', 'SPENT' );
		$gone    = 'ffc_ticket_used_8_' . hash( 'sha256', 'X' );
		$deleted = array();

		$wpdb          = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v );
		$wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing(
			function ( $sql, $like, $cutoff, $limit ) {
				$this->assertSame( 'ffc_ticket_used_%', $like );
				$this->assertLessThanOrEqual( time() - DAY_IN_SECONDS, $cutoff );
				$this->assertSame( 500, $limit );
				return 'SQL';
			}
		);
		$wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( $listed, $spent, $gone, 'ffc_ticket_used_junk' ) );
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			static function ( $table, $where ) use ( &$deleted ) {
				$deleted[] = $where['option_name'];
				return 1;
			}
		);
		$reads = array();
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id ) use ( &$reads ) {
				$reads[] = $id;
				return 7 === $id ? array( 'generated_codes_list' => "other\n still-here " ) : '';
			}
		);

		$this->assertSame( 2, AccessRestrictionChecker::reap_stale_claims() );
		$this->assertSame( array( $spent, $gone ), $deleted, 'A listed ticket keeps its claim; a deleted form keeps none.' );
		$this->assertSame( array( 7, 8 ), $reads, 'Each form is read once.' );
	}

	public function test_reap_reads_nothing_more_when_no_claim_is_old(): void {
		global $wpdb;
		$wpdb          = Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v );
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		$wpdb->shouldNotReceive( 'delete' );

		$this->assertSame( 0, AccessRestrictionChecker::reap_stale_claims() );
	}

	// ==================================================================
	// CPF edge cases
	// ==================================================================

	public function test_empty_cpf_not_in_denylist(): void {
		$config = array(
			'restrictions'      => array( 'denylist' => '1' ),
			'denied_users_list' => Identifiers::CPF_A . "\n" . Identifiers::CPF_B,
		);

		// Empty CPF should not match any entry
		$result = AccessRestrictionChecker::check( $config, '', '', 1 );
		$this->assertTrue( $result['allowed'] );
	}

	public function test_empty_cpf_not_in_allowlist_blocks(): void {
		$config = array(
			'restrictions'       => array( 'allowlist' => '1' ),
			'allowed_users_list' => Identifiers::CPF_A,
		);

		// Empty CPF is not in the allowlist
		$result = AccessRestrictionChecker::check( $config, '', '', 1 );
		$this->assertFalse( $result['allowed'] );
	}

	public function test_cpf_with_spaces_normalizes(): void {
		$config = array(
			'restrictions'      => array( 'denylist' => '1' ),
			'denied_users_list' => strtr( self::masked( Identifiers::CPF_A ), '.-', '  ' ),
		);

		// Spaces are non-digit, stripped by preg_replace('/\D/', '')
		$result = AccessRestrictionChecker::check( $config, Identifiers::CPF_A, '', 1 );
		$this->assertFalse( $result['allowed'] );
	}

	// ==================================================================
	// Password (#1861)
	// ==================================================================

	public function test_password_given_by_the_caller_is_checked_without_reading_post(): void {
		$_POST  = array();
		$config = array(
			'restrictions'    => array( 'password' => '1' ),
			'validation_code' => 'open-sesame',
		);

		$this->assertTrue( AccessRestrictionChecker::check( $config, '', '', 1, 'open-sesame' )['allowed'] );
		$wrong = AccessRestrictionChecker::check( $config, '', '', 1, 'open-sesamE' );
		$this->assertFalse( $wrong['allowed'] );
		$this->assertSame( 'Incorrect password.', $wrong['message'] );
	}

	public function test_an_empty_stored_password_admits_nobody(): void {
		$config = array(
			'restrictions'    => array( 'password' => '1' ),
			'validation_code' => '',
		);

		$this->assertFalse( AccessRestrictionChecker::check( $config, '', '', 1, 'anything' )['allowed'] );
	}

	public function test_password_is_compared_in_constant_time(): void {
		// A plain `!==` returns at the first differing byte, which times the
		// secret; the comparison must be hash_equals().
		$method = new \ReflectionMethod( AccessRestrictionChecker::class, 'check' );
		$lines  = file( (string) $method->getFileName() );
		$body   = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );

		$this->assertStringContainsString( 'hash_equals( $valid_password, $password )', $body );
		$this->assertStringNotContainsString( '$password !== $valid_password', $body );
	}
}
