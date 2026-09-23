<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\IdentitySearchAjaxEndpoint;
use RuntimeException;
use WP_User;

/**
 * The dialog says what the write would say (#1397 sprint 3).
 *
 * THE ASSERTION IS THE EQUALITY, NOT THE STRING.
 *
 * A test that checked the dialog reports "refused" for a conflicting account
 * would pass against a SECOND copy of the agreement rule -- which is precisely
 * the defect this sprint exists to make impossible. So every case below drives
 * the REAL `IdentityRelink::relink()` and the endpoint against ONE fixture and
 * asserts the two agree: same allowed/refused, and for a refusal the same
 * `WP_Error` code and the same message, word for word.
 *
 * Delete `verdict()`'s call to `IdentityAgreement` and replace it with
 * anything of its own, and these fail -- which is how they were checked.
 *
 * @covers \FreeFormCertificate\Admin\IdentitySearchAjaxEndpoint
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class IdentitySearchAjaxEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Rows a table answers with, keyed `table => rows`.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = array();

	/**
	 * The identity index row per account, keyed by user id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $index = array();

	/**
	 * What the endpoint sent, success or error.
	 *
	 * @var array<string, mixed>
	 */
	private array $sent = array();

	/**
	 * Whether the last send was `wp_send_json_success`.
	 *
	 * @var bool
	 */
	private bool $ok = false;

	/**
	 * Stand up the WordPress boundary and the `$wpdb` double.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Admin\IdentitySearchAjaxEndpoint' );
		class_exists( '\FreeFormCertificate\Maintenance\IdentityRelink' );

		$this->rows  = array();
		$this->index = array();
		$this->sent  = array();
		$this->ok    = false;

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

		// `wp_send_json_*` die, so the send is where the handler ends:
		// throwing is what lets the response be read at all.
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data ) {
				$this->sent = (array) $data;
				$this->ok   = true;

				throw new RuntimeException( 'ffc_test_sent' );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data ) {
				$this->sent = (array) $data;
				$this->ok   = false;

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

		// Every `ffc_*` table the rule reads exists.
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( $prepared ) {
				return $prepared['args'][0] ?? null;
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $prepared ) {
				$sql   = (string) ( $prepared['sql'] ?? '' );
				$table = (string) ( $prepared['args'][0] ?? '' );
				$rows  = $this->rows[ $table ] ?? array();

				if ( false !== strpos( $sql, 'user_id = %d' ) ) {
					$owner = (int) ( $prepared['args'][1] ?? 0 );

					return array_values(
						array_filter(
							$rows,
							static function ( $row ) use ( $owner ) {
								return (int) ( $row['user_id'] ?? 0 ) === $owner;
							}
						)
					);
				}

				$column = (string) ( $prepared['args'][1] ?? '' );
				$hash   = (string) ( $prepared['args'][2] ?? '' );

				return array_values(
					array_filter(
						$rows,
						static function ( $row ) use ( $column, $hash ) {
							return ( $row[ $column ] ?? '' ) === $hash;
						}
					)
				);
			}
		);

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $prepared ) {
				$owner = (int) ( $prepared['args'][1] ?? 0 );

				return $this->index[ $owner ] ?? null;
			}
		);

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
	 * Teach a store its rows.
	 *
	 * @param string                           $table Unprefixed table.
	 * @param array<int, array<string, mixed>> $rows  The rows.
	 * @return void
	 */
	private function given( string $table, array $rows ): void {
		$this->rows[ 'wp_' . $table ] = $rows;
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
	 * Run one search, with the candidate list supplied rather than queried.
	 *
	 * `WP_User_Query` is the one part of the endpoint that is a plain
	 * WordPress call rather than a decision, so it is stood in for; every
	 * verdict below is still computed by the real rule.
	 *
	 * @param string          $hash       The identifier whose records move.
	 * @param array<int, int> $candidates Accounts the search returns.
	 * @return array<string, mixed>
	 */
	private function search( string $hash, array $candidates ): array {
		$_POST = array(
			'subject' => $hash,
			'field'   => 'rf',
			'q'       => 'anything',
		);

		$endpoint = new class( $candidates ) extends IdentitySearchAjaxEndpoint {

			/** @var array<int, int> */
			public static array $candidates = array();

			/**
			 * @param array<int, int> $candidates Accounts to answer with.
			 */
			public function __construct( array $candidates ) {
				self::$candidates = $candidates;
			}

			/**
			 * @param string $term  Ignored.
			 * @param int    $limit Ignored.
			 * @return array<int, WP_User>
			 */
			protected static function matching( string $term, int $limit ): array {
				return array_map( 'get_userdata', self::$candidates );
			}
		};

		try {
			$endpoint::handle();
		} catch ( RuntimeException $e ) {
			if ( 'ffc_test_sent' !== $e->getMessage() ) {
				throw $e;
			}
		}

		return $this->sent;
	}

	/**
	 * The real write path over the same `$wpdb` double.
	 *
	 * @return \FreeFormCertificate\Maintenance\IdentityRelink
	 */
	private function writer(): \FreeFormCertificate\Maintenance\IdentityRelink {
		return new class() extends \FreeFormCertificate\Maintenance\IdentityRelink {

			/**
			 * The index write, without a repository.
			 *
			 * @param int                   $user_id Account.
			 * @param array<string, string> $data    Columns.
			 * @return bool
			 */
			protected function reindex( int $user_id, array $data ): bool {
				return true;
			}
		};
	}

	/**
	 * Find one account in a response.
	 *
	 * @param array<string, mixed> $sent The response.
	 * @param int                  $id   The account.
	 * @return array<string, mixed>
	 */
	private static function account( array $sent, int $id ): array {
		foreach ( (array) ( $sent['accounts'] ?? array() ) as $account ) {
			if ( (int) ( $account['id'] ?? 0 ) === $id ) {
				return (array) $account;
			}
		}

		return array();
	}

	/**
	 * THE WHOLE POINT: the dialog's verdict and the write's verdict are one
	 * verdict, driven here from a single fixture per case.
	 *
	 * Each row is a scenario the fixture creates, the account it asks about,
	 * and whether the move is allowed. The refusals are checked against the
	 * write's own error code, so a dialog that invented its own vocabulary
	 * fails even while saying something true.
	 */
	public function test_every_verdict_the_dialog_shows_is_the_one_the_write_reaches(): void {
		$cases = array(
			// A shared CPF ties them; the target gains the RF. Allowed.
			'a gap the other identifier vouches for' => array(
				'rows'    => array(
					self::row( 1, 398, 'cpfA', 'rfMoving' ),
					self::row( 2, 513, 'cpfA', '' ),
				),
				'target'  => 513,
				'allowed' => true,
			),
			// The target holds a DIFFERENT CPF. Refused, and this is the one
			// that puts a record under the wrong login if it is ever wrong.
			'a second identifier that disagrees'     => array(
				'rows'    => array(
					self::row( 1, 398, 'cpfA', 'rfMoving' ),
					self::row( 2, 513, 'cpfB', '' ),
				),
				'target'  => 513,
				'allowed' => false,
			),
			// Nothing ties them at all.
			'no identifier in common'                => array(
				'rows'    => array(
					self::row( 1, 398, '', 'rfMoving' ),
					self::row( 2, 513, 'cpfB', '' ),
				),
				'target'  => 513,
				'allowed' => false,
			),
			// The records are already there.
			'the account they already belong to'     => array(
				'rows'    => array(
					self::row( 1, 398, 'cpfA', 'rfMoving' ),
				),
				'target'  => 398,
				'allowed' => false,
			),
		);

		foreach ( $cases as $name => $case ) {
			$this->rows = array();
			$this->given( 'ffc_submissions', $case['rows'] );

			$written = $this->writer()->relink( 'rfMoving', $case['target'] );

			$this->rows = array();
			$this->given( 'ffc_submissions', $case['rows'] );

			$shown = self::account( $this->search( 'rfMoving', array( $case['target'] ) ), $case['target'] );

			$this->assertNotSame( array(), $shown, $name . ': the dialog must report on the account asked about.' );
			$this->assertSame(
				$case['allowed'],
				(bool) $shown['allowed'],
				$name . ': the dialog and the write must agree on whether the move is allowed.'
			);

			if ( $case['allowed'] ) {
				$this->assertIsArray( $written, $name . ': the write must have gone through.' );
				continue;
			}

			$this->assertInstanceOf( \WP_Error::class, $written, $name . ': the write must have refused.' );
			$this->assertSame(
				$written->get_error_code(),
				$shown['code'],
				$name . ': the dialog must name the refusal the write names.'
			);
			$this->assertSame(
				$written->get_error_message(),
				$shown['reason'],
				$name . ': the dialog must give the reason the write gives, word for word.'
			);
		}
	}

	/**
	 * A refusal that does not depend on the destination replaces the list
	 * rather than labelling every candidate, because there is no candidate
	 * that could serve.
	 */
	public function test_a_refusal_that_names_no_target_replaces_the_list(): void {
		$this->given(
			'ffc_submissions',
			array(
				self::row( 1, 398, 'cpfA', 'rfMoving' ),
				self::row( 2, 771, 'cpfA', 'rfMoving' ),
			)
		);

		$sent = $this->search( 'rfMoving', array( 513 ) );

		$this->assertFalse( $this->ok, 'A move nothing can complete must not answer with a list of accounts.' );
		$this->assertSame( 'ffc_identity_relink_ambiguous', $sent['code'] ?? '' );
		$this->assertArrayNotHasKey( 'accounts', $sent );
	}

	/**
	 * An allowed candidate says what the account would GAIN, because that is
	 * the half of the rule an operator cannot see from the screen.
	 */
	public function test_an_allowed_candidate_names_what_the_account_gains(): void {
		$this->given(
			'ffc_submissions',
			array(
				self::row( 1, 398, 'cpfA', 'rfMoving' ),
				self::row( 2, 513, 'cpfA', '' ),
			)
		);

		$shown = self::account( $this->search( 'rfMoving', array( 513 ) ), 513 );

		$this->assertTrue( (bool) $shown['allowed'] );
		$this->assertStringContainsString( 'CPF', (string) $shown['label'] );
		$this->assertStringContainsString( 'RF', (string) $shown['label'] );
	}

	/**
	 * Nothing stored reaches the browser: the response carries a hash PREFIX
	 * and no identifier, encrypted or otherwise.
	 */
	public function test_the_response_carries_a_prefix_and_never_a_value(): void {
		$this->given(
			'ffc_submissions',
			array(
				self::row( 1, 398, 'cpfA', 'rfMovingHashThatIsLong' ),
				self::row( 2, 513, 'cpfA', '' ),
			)
		);

		$sent = $this->search( 'rfMovingHashThatIsLong', array( 513 ) );

		$this->assertSame( 'rfMovingHas', substr( (string) $sent['prefix'], 0, 11 ) );
		$this->assertNotSame( 'rfMovingHashThatIsLong', $sent['prefix'] );
		$this->assertSame( 1, $sent['records'] );
	}
}
