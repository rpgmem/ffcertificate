<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\IdentityPreflightAjaxEndpoint;
use FreeFormCertificate\Maintenance\IdentityRelink;
use FreeFormCertificate\Maintenance\IdentityRepair;
use RuntimeException;
use WP_User;

/**
 * The preflight says what the write would say (#1397 sprint 4).
 *
 * Same shape as `IdentitySearchAjaxEndpointTest`, for the same reason: a test
 * that checked the preflight reports "refused" would pass against a SECOND
 * copy of the repair's checks, which is the defect the `plan()` split exists
 * to make impossible. So each case drives the REAL `IdentityRepair::repair()`
 * and the endpoint against ONE fixture and asserts the two agree, word for
 * word.
 *
 * @covers \FreeFormCertificate\Admin\IdentityPreflightAjaxEndpoint
 */
class IdentityPreflightAjaxEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * An RF whose check digit matches, so the value itself never refuses.
	 *
	 * @var string
	 */
	private const GOOD_RF = '1234561';

	/**
	 * Rows a table answers with, keyed `table => hash => rows`.
	 *
	 * @var array<string, array<string, array<int, array<string, mixed>>>>
	 */
	private array $rows = array();

	/**
	 * What the endpoint sent.
	 *
	 * @var array<string, mixed>
	 */
	private array $sent = array();

	/**
	 * Set up the WordPress boundary and the `$wpdb` double.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Admin\IdentityPreflightAjaxEndpoint' );
		class_exists( '\FreeFormCertificate\Maintenance\IdentityRepair' );
		class_exists( '\FreeFormCertificate\Maintenance\IdentityRelink' );

		$this->rows = array();
		$this->sent = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return is_array( $value ) || is_object( $value ) ? '' : trim( (string) $value );
			}
		);

		Functions\when( 'get_userdata' )->alias(
			static function ( $user_id ) {
				$user               = new WP_User( (int) $user_id );
				$user->display_name = 'Account ' . (int) $user_id;
				$user->user_email   = 'a' . (int) $user_id . '@example.org';

				return $user;
			}
		);

		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data ) {
				$this->sent = (array) $data;

				throw new RuntimeException( 'ffc_test_sent' );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data ) {
				$this->sent = (array) $data;

				throw new RuntimeException( 'ffc_test_sent' );
			}
		);

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				return array(
					'sql'  => $sql,
					'args' => $args,
				);
			}
		);

		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( $prepared ) {
				$sql = (string) ( $prepared['sql'] ?? '' );

				// The consolidation's ciphertext read; nothing here drives one.
				if ( 0 === strpos( $sql, 'SELECT' ) ) {
					return null;
				}

				return $prepared['args'][0] ?? null;
			}
		);

		// Two readers reach this with different statements: the repair's
		// `SELECT id, user_id … WHERE <column> = <hash>` and the relink's
		// `SELECT id, user_id, cpf_hash, rf_hash …`, plus the relink's own
		// `WHERE user_id = %d`. All three are answered from one fixture, which
		// is what lets a case drive both paths from it.
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $prepared ) {
				$sql   = (string) ( $prepared['sql'] ?? '' );
				$table = (string) ( $prepared['args'][0] ?? '' );

				if ( false !== strpos( $sql, 'user_id = %d' ) && false === strpos( $sql, '%i = %s' ) ) {
					$owner = (int) ( $prepared['args'][1] ?? 0 );
					$out   = array();

					foreach ( $this->rows[ $table ] ?? array() as $rows ) {
						foreach ( $rows as $row ) {
							if ( (int) ( $row['user_id'] ?? 0 ) === $owner ) {
								$out[] = $row;
							}
						}
					}

					return $out;
				}

				$hash = (string) ( $prepared['args'][2] ?? '' );

				return $this->rows[ $table ][ $hash ] ?? array();
			}
		);

		$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		$wpdb->shouldReceive( 'update' )->andReturn( 1 );
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );

		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * Tear down Brain\Monkey and the request.
	 */
	protected function tearDown(): void {
		$_POST = array();

		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Teach a table which rows carry a hash.
	 *
	 * @param string                           $table Unprefixed table.
	 * @param string                           $hash  The hash.
	 * @param array<int, array<string, mixed>> $rows  Rows to answer with.
	 * @return void
	 */
	private function given( string $table, string $hash, array $rows ): void {
		$this->rows[ 'wp_' . $table ][ $hash ] = $rows;
	}

	/**
	 * One record row.
	 *
	 * @param int    $id    Row id.
	 * @param int    $owner Account.
	 * @param string $cpf   Its cpf_hash.
	 * @param string $rf    Its rf_hash.
	 * @return array<string, mixed>
	 */
	private static function row( int $id, int $owner, string $cpf, string $rf ): array {
		return array(
			'id'       => $id,
			'user_id'  => $owner,
			'cpf_hash' => $cpf,
			'rf_hash'  => $rf,
		);
	}

	/**
	 * The hash the registry gives a value, which is what the fixture must use
	 * for a collision to be a collision.
	 *
	 * @param string $value The plain value.
	 * @return string
	 */
	private static function hash_of( string $value ): string {
		return (string) \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'rf', $value );
	}

	/**
	 * Run one preflight.
	 *
	 * @param string $subject The finding's hash.
	 * @param string $value   The value typed.
	 * @return array<string, mixed>
	 */
	private function preflight( string $subject, string $value ): array {
		$_POST = array(
			'subject' => $subject,
			'field'   => 'rf',
			'value'   => $value,
		);

		try {
			IdentityPreflightAjaxEndpoint::handle();
		} catch ( RuntimeException $e ) {
			if ( 'ffc_test_sent' !== $e->getMessage() ) {
				throw $e;
			}
		}

		return $this->sent;
	}

	/**
	 * The real repair, with only the index write stubbed.
	 *
	 * @return IdentityRepair
	 */
	private function writer(): IdentityRepair {
		return new class() extends IdentityRepair {

			/**
			 * @param int    $user_id  Account.
			 * @param string $new_hash Corrected hash.
			 * @param string $column   Index column.
			 * @return bool
			 */
			protected function reindex( int $user_id, string $new_hash, string $column = 'rf_hash' ): bool {
				return true;
			}
		};
	}

	/**
	 * THE WHOLE POINT: the preflight's answer and the write's answer are one
	 * answer, driven from a single fixture per case.
	 */
	public function test_every_refusal_the_preflight_shows_is_the_one_the_write_reaches(): void {
		$cases = array(
			// Nothing carries the finding's hash any more.
			'already repaired'             => array(
				'rows'    => array(),
				'subject' => 'gone',
			),
			// The same wrong number typed by two people.
			'two accounts, one wrong value' => array(
				'rows'    => array(
					self::row( 1, 398, 'cpfA', 'wrong' ),
					self::row( 2, 771, 'cpfB', 'wrong' ),
				),
				'subject' => 'wrong',
			),
		);

		foreach ( $cases as $name => $case ) {
			$this->rows = array();

			if ( array() !== $case['rows'] ) {
				$this->given( 'ffc_submissions', $case['subject'], $case['rows'] );
			}

			$written = $this->writer()->repair( $case['subject'], self::GOOD_RF );

			$shown = $this->preflight( $case['subject'], self::GOOD_RF );

			$this->assertInstanceOf( \WP_Error::class, $written, $name . ': the write must have refused.' );
			$this->assertFalse( (bool) $shown['allowed'], $name . ': the preflight must refuse too.' );
			$this->assertSame(
				$written->get_error_code(),
				$shown['code'],
				$name . ': the preflight must name the refusal the write names.'
			);
			$this->assertSame(
				$written->get_error_message(),
				$shown['message'],
				$name . ': the preflight must give the reason the write gives, word for word.'
			);
		}
	}

	/**
	 * A correction that would go through says how much it rewrites, because
	 * "it will work" and "it will rewrite four records" are different answers.
	 */
	public function test_an_allowed_correction_reports_what_it_would_rewrite(): void {
		$this->given(
			'ffc_submissions',
			'wrong',
			array(
				self::row( 1, 398, 'cpfA', 'wrong' ),
				self::row( 2, 398, 'cpfA', 'wrong' ),
			)
		);

		$shown = $this->preflight( 'wrong', self::GOOD_RF );

		$this->assertTrue( (bool) $shown['allowed'] );
		$this->assertSame( 2, $shown['rows'] );
		$this->assertSame( 398, $shown['account'] );
		$this->assertFalse( (bool) $shown['consolidates'] );
	}

	/**
	 * THE REFUSAL THAT IS NOT A FAILURE.
	 *
	 * The confirmed value belongs to another account. The preflight names
	 * them, which the refusal's sentence cannot — an operator reading only
	 * that sentence has to go and find out whom, and the method already knows.
	 */
	public function test_a_collision_names_the_account_that_holds_the_value(): void {
		$this->given(
			'ffc_submissions',
			'wrong',
			array( self::row( 1, 398, 'cpfA', 'wrong' ) )
		);
		$this->given(
			'ffc_submissions',
			self::hash_of( self::GOOD_RF ),
			array( self::row( 2, 513, 'cpfA', self::hash_of( self::GOOD_RF ) ) )
		);

		$shown = $this->preflight( 'wrong', self::GOOD_RF );

		$this->assertFalse( (bool) $shown['allowed'] );
		$this->assertSame( 'ffc_identity_repair_collision', $shown['code'] );
		$this->assertSame( 513, $shown['holder']['id'] );
		$this->assertSame( 'Account 513', $shown['holder']['name'] );
	}

	/**
	 * WHY NO MOVE IS OFFERED, ASSERTED RATHER THAN ASSUMED.
	 *
	 * "Move the records to whoever holds the number" is the obvious next step
	 * and the agreement rule can never accept it: the holder carries the
	 * CONFIRMED value in the field these records carry WRONGLY, which is two
	 * different non-empty values, which is a conflict. The fixture below is
	 * the friendliest possible one — the two agree on the CPF — and the relink
	 * still refuses. A later change that made the move offerable would fail
	 * here, which is the point of asserting it.
	 */
	public function test_the_move_that_looks_obvious_is_refused_by_the_rule(): void {
		$this->given(
			'ffc_submissions',
			'wrong',
			array( self::row( 1, 398, 'cpfA', 'wrong' ) )
		);
		$this->given(
			'ffc_submissions',
			self::hash_of( self::GOOD_RF ),
			array( self::row( 2, 513, 'cpfA', self::hash_of( self::GOOD_RF ) ) )
		);

		$relink  = new IdentityRelink();
		$moving  = $relink->moving( 'wrong', 'rf' );
		$verdict = $relink->verdict( $moving, 513 );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'ffc_identity_relink_conflict', $verdict->get_error_code() );

		// And the preflight does not pretend otherwise.
		$this->assertArrayNotHasKey( 'move', $this->preflight( 'wrong', self::GOOD_RF ) );
	}

	/**
	 * Nothing stored comes back: a prefix, an account, a count and a sentence.
	 */
	public function test_the_response_never_carries_the_hash_of_what_was_typed(): void {
		$this->given(
			'ffc_submissions',
			'aVeryLongSubjectHashIndeed',
			array( self::row( 1, 398, 'cpfA', 'aVeryLongSubjectHashIndeed' ) )
		);

		$shown = $this->preflight( 'aVeryLongSubjectHashIndeed', self::GOOD_RF );

		$this->assertSame( 'aVeryLongSub', $shown['prefix'] );
		$this->assertNotContains( self::hash_of( self::GOOD_RF ), $shown, 'The hash of the typed value must never travel back.' );
		$this->assertNotContains( self::GOOD_RF, $shown, 'The value itself must never travel back.' );
	}
}
