<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\IdentityMergePreviewAjaxEndpoint;
use FreeFormCertificate\Maintenance\IdentityMerge;
use RuntimeException;
use WP_User;

/**
 * The preview counts what the merge then moves (#1397 sprint 5).
 *
 * THE ACKNOWLEDGEMENT IS OF A NUMBER, SO THE NUMBER HAS TO BE THE REAL ONE.
 *
 * A merge is the one verb on this screen no other undoes. Its confirmation
 * states how many records move — and a count taken anywhere but in the write's
 * own resolution is a second answer to "which rows move", which the operator
 * would be acknowledging instead of the one that runs.
 *
 * So the case below runs the preview and then the REAL merge against ONE
 * fixture and asserts the numbers are the same, per store. Point `plan()`'s
 * count at a different predicate and it fails.
 *
 * @covers \FreeFormCertificate\Admin\IdentityMergePreviewAjaxEndpoint
 * @covers \FreeFormCertificate\Maintenance\IdentityMerge
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class IdentityMergePreviewTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Rows each table holds.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = array();

	/**
	 * What the endpoint sent.
	 *
	 * @var array<string, mixed>
	 */
	private array $sent = array();

	/**
	 * Per-store counts the real merge reported moving.
	 *
	 * @var array<string, int>
	 */
	private array $moved = array();

	/**
	 * Set up the WordPress boundary and the `$wpdb` double.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Admin\IdentityMergePreviewAjaxEndpoint' );
		class_exists( '\FreeFormCertificate\Maintenance\IdentityMerge' );

		$this->rows  = array();
		$this->sent  = array();
		$this->moved = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return is_array( $value ) || is_object( $value ) ? '' : trim( (string) $value );
			}
		);
		Functions\when( 'get_userdata' )->alias(
			static function ( $user_id ) {
				$user               = new WP_User( (int) $user_id );
				$user->display_name = 'Account ' . (int) $user_id;

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

		// Three statements reach `get_var`: the `SHOW TABLES LIKE` probe, and
		// the preview's two `SELECT COUNT(*) … WHERE user_id = %d`.
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $prepared ) {
				$sql = (string) ( $prepared['sql'] ?? '' );

				if ( 0 !== strpos( $sql, 'SELECT COUNT' ) ) {
					return $prepared['args'][0] ?? null;
				}

				$table = (string) ( $prepared['args'][0] ?? '' );
				$owner = (int) ( $prepared['args'][1] ?? 0 );

				return count( $this->under( $table, $owner ) );
			}
		);

		// `held_by()` reads the identifiers each account carries.
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $prepared ) {
				$table = (string) ( $prepared['args'][0] ?? '' );
				$owner = (int) ( $prepared['args'][1] ?? 0 );

				return $this->under( $table, $owner );
			}
		);

		$wpdb->shouldReceive( 'get_row' )->andReturn( null );

		// FAITHFUL TO `wpdb::update()`, WHICH IS WHAT MAKES THE COMPARISON
		// MEAN ANYTHING: it returns the number of rows the WHERE matched, and
		// that is the number the merge reports as moved.
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$owner   = (int) ( $where['user_id'] ?? 0 );
				$matched = $this->under( (string) $table, $owner );

				foreach ( $this->rows[ (string) $table ] ?? array() as $i => $row ) {
					if ( (int) ( $row['user_id'] ?? 0 ) === $owner ) {
						$this->rows[ (string) $table ][ $i ]['user_id'] = (int) ( $data['user_id'] ?? 0 );
					}
				}

				return count( $matched );
			}
		);

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
	 * Rows of one table belonging to one account.
	 *
	 * @param string $table The prefixed table.
	 * @param int    $owner The account.
	 * @return array<int, array<string, mixed>>
	 */
	private function under( string $table, int $owner ): array {
		return array_values(
			array_filter(
				$this->rows[ $table ] ?? array(),
				static function ( $row ) use ( $owner ) {
					return (int) ( $row['user_id'] ?? 0 ) === $owner;
				}
			)
		);
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
	 * @param int    $owner Account.
	 * @param string $cpf   Its cpf_hash.
	 * @param string $rf    Its rf_hash.
	 * @return array<string, mixed>
	 */
	private static function row( int $owner, string $cpf = 'cpfA', string $rf = '' ): array {
		return array(
			'user_id'  => $owner,
			'cpf_hash' => $cpf,
			'rf_hash'  => $rf,
		);
	}

	/**
	 * A merge whose index writes are stubbed.
	 *
	 * @return IdentityMerge
	 */
	private function merger(): IdentityMerge {
		return new class() extends IdentityMerge {

			/**
			 * @param int                   $user_id The account.
			 * @param array<string, string> $data    Index columns.
			 * @return bool
			 */
			protected function reindex( int $user_id, array $data ): bool {
				return true;
			}

			/**
			 * @param int $user_id The emptied account.
			 * @return bool
			 */
			protected function clear_index( int $user_id ): bool {
				return true;
			}
		};
	}

	/**
	 * Run one preview.
	 *
	 * @param int $survivor The account to keep.
	 * @param int $absorbed The account to empty.
	 * @return array<string, mixed>
	 */
	private function preview( int $survivor, int $absorbed ): array {
		$_POST = array(
			'survivor' => (string) $survivor,
			'absorbed' => (string) $absorbed,
		);

		try {
			IdentityMergePreviewAjaxEndpoint::handle();
		} catch ( RuntimeException $e ) {
			if ( 'ffc_test_sent' !== $e->getMessage() ) {
				throw $e;
			}
		}

		return $this->sent;
	}

	/**
	 * A pair sharing a CPF, with records spread over two stores.
	 *
	 * @return void
	 */
	private function a_pair_with_records(): void {
		$this->given(
			'ffc_submissions',
			array(
				self::row( 398 ),
				self::row( 513 ),
				self::row( 513 ),
				self::row( 513 ),
			)
		);
		$this->given(
			'ffc_self_scheduling_appointments',
			array(
				self::row( 513 ),
				self::row( 513 ),
			)
		);
		$this->given( 'ffc_recruitment_candidate', array() );
	}

	/**
	 * THE DONE-CRITERION: the preview's counts equal what the merge moves.
	 */
	public function test_the_preview_counts_what_the_merge_then_moves(): void {
		$this->a_pair_with_records();

		$shown = $this->preview( 398, 513 );

		$this->assertTrue( (bool) $shown['allowed'], 'The fixture must be a mergeable pair, or this proves nothing.' );

		$previewed = array();

		foreach ( $shown['stores'] as $store ) {
			$previewed[ 'wp_' . $store['store'] ] = (int) $store['records'];
		}

		$result = $this->merger()->merge( 398, 513 );

		$this->assertIsArray( $result );
		$this->assertSame(
			$previewed,
			$result['moved'],
			'What the operator acknowledged must be what the merge then moved, per store.'
		);
		$this->assertSame(
			array_sum( $previewed ),
			(int) $shown['total'],
			'The total must be the sum of the per-store counts it was built from.'
		);
		$this->assertSame( 5, (int) $shown['total'] );
	}

	/**
	 * A store holding nothing is reported as zero rather than dropped: an
	 * absent row and a row reading zero are the same fact, but only the second
	 * says it was looked at.
	 */
	public function test_a_store_with_nothing_is_reported_as_zero(): void {
		$this->a_pair_with_records();

		$shown = $this->preview( 398, 513 );

		$stores = array();

		foreach ( $shown['stores'] as $store ) {
			$stores[ $store['store'] ] = (int) $store['records'];
		}

		$this->assertArrayHasKey( 'ffc_recruitment_candidate', $stores );
		$this->assertSame( 0, $stores['ffc_recruitment_candidate'] );
	}

	/**
	 * How much each side holds is evidence for the choice of survivor — the
	 * #1368 criterion — and it is reported, never acted on.
	 */
	public function test_it_says_how_much_each_login_holds(): void {
		$this->a_pair_with_records();

		$shown = $this->preview( 398, 513 );

		$this->assertSame( 398, $shown['survivor']['id'] );
		$this->assertSame( 1, $shown['survivor']['records'] );
		$this->assertSame( 513, $shown['absorbed']['id'] );
		$this->assertSame( 5, $shown['absorbed']['records'] );
		$this->assertSame( 'Account 513', $shown['absorbed']['name'] );
	}

	/**
	 * A refusal the merge would make is shown as a refusal, in the service's
	 * own words — so nothing is acknowledged that the write would reject.
	 */
	public function test_a_refusal_is_the_one_the_merge_would_give(): void {
		$this->given(
			'ffc_submissions',
			array(
				self::row( 398, 'cpfA' ),
				self::row( 513, 'cpfB' ),
			)
		);

		$shown  = $this->preview( 398, 513 );
		$result = $this->merger()->merge( 398, 513 );

		$this->assertFalse( (bool) $shown['allowed'] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $result->get_error_code(), $shown['code'] );
		$this->assertSame( $result->get_error_message(), $shown['message'] );
	}

	/**
	 * The survivor gains the identifiers it did not hold, and the preview says
	 * which — the half of the rule an operator cannot see from the screen.
	 */
	public function test_it_names_what_the_surviving_login_gains(): void {
		$this->given(
			'ffc_submissions',
			array(
				self::row( 398, 'cpfA', '' ),
				self::row( 513, 'cpfA', 'rfB' ),
			)
		);

		$shown = $this->preview( 398, 513 );

		$this->assertTrue( (bool) $shown['allowed'] );
		$this->assertSame( array( 'CPF' ), $shown['matched'] );
		$this->assertSame( array( 'RF' ), $shown['gains'] );
	}
}
