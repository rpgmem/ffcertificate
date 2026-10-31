<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentNoticeEditPage;
use FreeFormCertificate\Core\CsvStreamer;
use FreeFormCertificate\Core\CsvDownloadInterface;

/**
 * Behavior tests for RecruitmentNoticeEditPage::handle_save(),
 * handle_transition() and handle_download_csv_example() — the admin-post
 * handlers. The terminal wp_safe_redirect()/exit is replaced by a marker
 * exception so the flash key (and the repository / state-machine call) is
 * observable without killing the process; the CSV-example download injects a
 * buffered CsvStreamer so its output is captured instead of hitting exit.
 * wp_die is likewise short-circuited.
 *
 * The save and transition paths run the real renderer column map, notice
 * writer, notice reader, state machine and classification repository over a
 * `$wpdb` double that answers each query by its SQL text from the notice row a
 * test stores, records every write, and fails the test on a query it does not
 * know — so every test shares the PHPUnit process.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticeEditPage
 * @covers \FreeFormCertificate\Recruitment\RecruitmentExampleCsvSource
 */
class RecruitmentNoticeEditPageHandlersTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var object|null The notice row the reader's SELECT answers; null for none. */
	private ?object $notice = null;

	/** @var int Calls the classification repository counts for the notice. */
	private int $calls = 0;

	/** @var int Rows the conditional status UPDATE reports as affected. */
	private int $status_affected = 1;

	/** @var list<string> Every prepared statement the double ran through query(). */
	private array $queries = array();

	/** @var list<array<int, mixed>> The arguments of every $wpdb->update() call. */
	private array $updates = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> The `$_POST` an earlier test left, restored afterwards. */
	private array $post_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$this->post_backup = $_POST ?? array();
		$GLOBALS['wpdb']   = $this->wpdb_double();

		// Storage around the real repositories: no object cache, no hooks, and
		// an empty `ffc_settings`, so the activity log is off for every order.
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'current_time' )->justReturn( '2026-10-28 10:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message, $args = array() ) {
				$ffc_type = isset( $args['type'] ) ? $args['type'] : 'info';
				$ffc_cls  = 'notice notice-' . $ffc_type;
				if ( ! empty( $args['dismissible'] ) ) { $ffc_cls .= ' is-dismissible'; }
				if ( ! empty( $args['additional_classes'] ) ) { $ffc_cls .= ' ' . implode( ' ', $args['additional_classes'] ); }
				$ffc_wrap = ! array_key_exists( 'paragraph_wrap', $args ) || $args['paragraph_wrap'];
				echo '<div class="' . $ffc_cls . '">' . ( $ffc_wrap ? '<p>' . $message . '</p>' : $message ) . '</div>';
			}
		);
		// Preload so pcov attributes the coverage this test drives through the
		// source; pcov skips a class first autoloaded mid-test-method (#772).
		class_exists( '\\FreeFormCertificate\Recruitment\RecruitmentExampleCsvSource' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ) => (int) $v );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'add_query_arg' )->alias(
			static fn ( $args ) => 'admin.php?' . http_build_query( (array) $args )
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_die' )->alias(
			static function ( $msg = '' ) {
				throw new \RuntimeException( 'WP_DIE:' . $msg );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) {
				throw new \RuntimeException( 'REDIRECT:' . $url );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$_POST = $this->post_backup;
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		parent::tearDown();
	}

	/**
	 * A `$wpdb` double for the notice tables: prepare() substitutes the bound
	 * values into the SQL text, reads answer from the stored notice row and
	 * call count, writes are recorded, and anything else fails the test.
	 */
	private function wpdb_double(): object {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $query, ...$args ): string {
				return (string) preg_replace_callback(
					'/%[ids]/',
					static function () use ( &$args ): string {
						return (string) array_shift( $args );
					},
					$query
				);
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( string $sql ) {
				if ( 'SELECT * FROM wp_ffc_recruitment_notice WHERE id = 5' !== $sql ) {
					$this->fail( 'Unexpected get_row: ' . $sql );
				}
				return null === $this->notice ? null : clone $this->notice;
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( string $sql ) {
				if ( false === strpos( $sql, 'FROM wp_ffc_recruitment_call c' ) || false === strpos( $sql, 'cl.notice_id = 5' ) ) {
					$this->fail( 'Unexpected get_var: ' . $sql );
				}
				return (string) $this->calls;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( string $sql ) {
				$this->queries[] = $sql;
				if ( 0 === strpos( $sql, 'UPDATE wp_ffc_recruitment_notice SET status = ' ) ) {
					return $this->status_affected;
				}
				if ( 0 === strpos( $sql, 'UPDATE wp_ffc_recruitment_notice SET ' ) ) {
					return 1;
				}
				$this->fail( 'Unexpected query: ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( ...$args ) {
				$this->updates[] = $args;
				return 1;
			}
		);

		return $wpdb;
	}

	/**
	 * Store the notice row the reader answers for notice 5.
	 *
	 * @param string      $status    Stored status.
	 * @param string|null $opened_at Stored `opened_at`.
	 * @param string|null $closed_at Stored `closed_at`.
	 */
	private function store_notice( string $status, ?string $opened_at = null, ?string $closed_at = null ): void {
		$this->notice = (object) array(
			'id'           => '5',
			'code'         => 'N-1',
			'name'         => 'Notice',
			'status'       => $status,
			'was_reopened' => '0',
			'opened_at'    => $opened_at,
			'closed_at'    => $closed_at,
		);
	}

	/**
	 * The conditional status UPDATEs the writer ran.
	 *
	 * @return list<string>
	 */
	private function status_updates(): array {
		return array_values(
			array_filter(
				$this->queries,
				static fn ( string $sql ): bool => 0 === strpos( $sql, 'UPDATE wp_ffc_recruitment_notice SET status = ' )
			)
		);
	}

	private function capture( callable $fn ): string {
		try {
			$fn();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		return '';
	}

	// ==================================================================
	// handle_save()
	// ==================================================================

	public function test_handle_save_dies_without_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$msg = $this->capture( array( RecruitmentNoticeEditPage::class, 'handle_save' ) );

		$this->assertStringStartsWith( 'WP_DIE:', $msg );
	}

	public function test_handle_save_redirects_to_list_when_notice_id_zero(): void {
		$_POST['notice_id'] = '0';

		$msg = $this->capture( array( RecruitmentNoticeEditPage::class, 'handle_save' ) );

		$this->assertStringStartsWith( 'REDIRECT:', $msg );
		$this->assertStringContainsString( 'tab=notices', $msg );
		// No edit-notice action segment on the back-to-list URL.
		$this->assertStringNotContainsString( 'action=edit-notice', $msg );
	}

	public function test_handle_save_persists_name_and_columns_then_flashes_saved(): void {
		$_POST['notice_id']      = '5';
		$_POST['name']           = 'New Name';
		$_POST['public_columns'] = array(
			'score'        => '1',
			'email_masked' => '1',
			'not_a_column' => '1',
		);

		$msg = $this->capture( array( RecruitmentNoticeEditPage::class, 'handle_save' ) );

		$this->assertCount( 1, $this->updates, 'one notice UPDATE' );
		list( $table, $data, $where ) = $this->updates[0];
		$this->assertSame( 'wp_ffc_recruitment_notice', $table );
		$this->assertSame( array( 'id' => 5 ), $where );
		$this->assertSame( 'New Name', $data['name'] );
		$config = json_decode( $data['public_columns_config'], true );
		$this->assertTrue( $config['rank'], 'rank forced true' );
		$this->assertTrue( $config['name'], 'name forced true' );
		$this->assertTrue( $config['score'] );
		$this->assertTrue( $config['email_masked'] );
		$this->assertFalse( $config['cpf_masked'], 'unchecked column → false' );
		$this->assertArrayNotHasKey( 'not_a_column', $config, 'a key outside the column map is dropped' );
		$this->assertStringContainsString( 'ffc_msg=saved', $msg );
		$this->assertStringContainsString( 'action=edit-notice', $msg );
	}

	// ==================================================================
	// handle_transition()
	// ==================================================================

	public function test_handle_transition_dies_without_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$msg = $this->capture( array( RecruitmentNoticeEditPage::class, 'handle_transition' ) );

		$this->assertStringStartsWith( 'WP_DIE:', $msg );
	}

	public function test_handle_transition_flashes_invalid_target_for_unknown_status(): void {
		$_POST['notice_id']     = '5';
		$_POST['target_status'] = 'bogus';

		$msg = $this->capture( array( RecruitmentNoticeEditPage::class, 'handle_transition' ) );

		$this->assertStringContainsString( 'ffc_msg=transition-invalid-target', $msg );
	}

	public function test_handle_transition_flashes_transitioned_on_success(): void {
		$_POST['notice_id']     = '5';
		$_POST['target_status'] = 'preliminary';
		$this->store_notice( 'draft' );

		$msg = $this->capture( array( RecruitmentNoticeEditPage::class, 'handle_transition' ) );

		$this->assertStringContainsString( 'ffc_msg=transitioned', $msg );
		$this->assertCount( 1, $this->status_updates(), 'one conditional status UPDATE' );
		$this->assertStringContainsString( 'SET status = preliminary', $this->status_updates()[0] );
		$this->assertStringContainsString( 'WHERE id = 5 AND status = draft', $this->status_updates()[0] );
	}

	public function test_handle_transition_refuses_a_bare_promotion(): void {
		$_POST['notice_id']     = '5';
		$_POST['target_status'] = 'definitive';
		$this->store_notice( 'preliminary' );

		$msg = $this->capture( array( RecruitmentNoticeEditPage::class, 'handle_transition' ) );

		// #2245: a status flip would keep the definitive list of an earlier cycle.
		$this->assertStringContainsString( 'ffc_msg=transition-requires-promote', $msg );
		$this->assertSame( array(), $this->status_updates(), 'The status is not touched.' );
	}

	/**
	 * The stored notice drives the real state machine into each refusal, and
	 * the handler maps the refusal's code to its flash key.
	 *
	 * @dataProvider error_code_provider
	 */
	public function test_handle_transition_maps_state_machine_errors_to_flash_keys( string $stored_status, string $target, int $calls, int $affected, string $expected_flash ): void {
		$_POST['notice_id']     = '5';
		$_POST['target_status'] = $target;
		$this->store_notice( $stored_status, '2026-01-01 00:00:00', 'closed' === $stored_status ? '2026-02-01 00:00:00' : null );
		$this->calls           = $calls;
		$this->status_affected = $affected;

		$msg = $this->capture( array( RecruitmentNoticeEditPage::class, 'handle_transition' ) );

		$this->assertStringContainsString( 'ffc_msg=' . $expected_flash, $msg );
	}

	/**
	 * Stored status, target, calls on the notice, rows the status UPDATE
	 * affects, and the flash key the refusal maps to.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int, 3: int, 4: string}>
	 */
	public function error_code_provider(): array {
		return array(
			'blocked by calls' => array( 'definitive', 'preliminary', 2, 1, 'transition-blocked-by-calls' ),
			'reason required'  => array( 'closed', 'definitive', 0, 1, 'transition-reason-required' ),
			'race lost'        => array( 'draft', 'preliminary', 0, 0, 'transition-race-lost' ),
			'generic failure'  => array( 'draft', 'closed', 0, 1, 'transition-failed' ),
		);
	}

	/**
	 * Reopening a closed notice needs a reason: the posted one reaching the
	 * state machine is what lets the transition through ("reason required"
	 * above is the same transition without it).
	 */
	public function test_handle_transition_passes_reason_through_to_state_machine(): void {
		$_POST['notice_id']     = '5';
		$_POST['target_status'] = 'definitive';
		$_POST['reason']        = 'Concluded';
		$this->store_notice( 'closed', '2026-01-01 00:00:00', '2026-02-01 00:00:00' );

		$msg = $this->capture( array( RecruitmentNoticeEditPage::class, 'handle_transition' ) );

		$this->assertStringContainsString( 'ffc_msg=transitioned', $msg );
		$this->assertCount( 1, $this->status_updates() );
		$this->assertStringContainsString( 'WHERE id = 5 AND status = closed', $this->status_updates()[0] );
	}

	// ==================================================================
	// handle_download_csv_example()
	// ==================================================================

	public function test_handle_download_csv_example_dies_without_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$msg = $this->capture( array( RecruitmentNoticeEditPage::class, 'handle_download_csv_example' ) );

		$this->assertStringStartsWith( 'WP_DIE:', $msg );
	}

	/**
	 * The formerly-untestable template download: with an injected CsvStreamer we
	 * capture the bytes and assert the header line + both example rows are present
	 * (the ';' delimiter that survives the BR/EU spreadsheet round-trip included).
	 */
	public function test_handle_download_csv_example_streams_header_and_example_rows(): void {
		Functions\when( 'nocache_headers' )->justReturn( true );

		$download = $this->buffered_download();

		RecruitmentNoticeEditPage::handle_download_csv_example( new CsvStreamer( $download ) );

		$this->assertTrue( $download->finished, 'stream finished' );
		$this->assertStringContainsString( 'name;cpf;rf', $download->output, 'header line present' );
		$this->assertStringContainsString( 'Maria da Silva', $download->output, 'PCD example row present' );
		$this->assertStringContainsString( 'João Souza', $download->output, 'non-PCD example row present' );
	}

	/**
	 * A CsvDownloadInterface that captures the export bytes instead of writing
	 * to php://output / calling exit.
	 */
	private function buffered_download(): CsvDownloadInterface {
		return new class() implements CsvDownloadInterface {
			public bool $finished = false;
			public string $output = '';
			/** @var resource|null */
			private $stream = null;

			public function send_headers( string $filename ): void {
				unset( $filename );
			}

			public function open_stream() {
				if ( ! is_resource( $this->stream ) ) {
					$this->stream = fopen( 'php://memory', 'w+' );
				}
				return $this->stream;
			}

			public function finish(): void {
				$this->finished = true;
				if ( is_resource( $this->stream ) ) {
					rewind( $this->stream );
					$this->output = (string) stream_get_contents( $this->stream );
				}
			}
		};
	}
}
