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
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticeEditPage
 * @covers \FreeFormCertificate\Recruitment\RecruitmentExampleCsvSource
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentNoticeEditPageHandlersTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
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
		unset( $_POST['notice_id'], $_POST['name'], $_POST['public_columns'], $_POST['target_status'], $_POST['reason'] );
		parent::tearDown();
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
			'score' => '1',
			'email' => '1',
		);

		// Renderer supplies the column label map keys.
		$renderer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeEditPageRenderer' );
		$renderer->shouldReceive( 'columns_label_map' )->andReturn(
			array(
				'rank'  => 'Rank',
				'name'  => 'Name',
				'score' => 'Score',
				'email' => 'Email',
				'phone' => 'Phone',
			)
		);

		$repo     = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeWriter' );
		$captured = null;
		$repo->shouldReceive( 'update' )->once()->andReturnUsing(
			function ( $id, $data ) use ( &$captured ) {
				$captured = array( $id, $data );
				return 1;
			}
		);

		$msg = $this->capture( array( RecruitmentNoticeEditPage::class, 'handle_save' ) );

		$this->assertSame( 5, $captured[0] );
		$this->assertSame( 'New Name', $captured[1]['name'] );
		$config = json_decode( $captured[1]['public_columns_config'], true );
		$this->assertTrue( $config['rank'], 'rank forced true' );
		$this->assertTrue( $config['name'], 'name forced true' );
		$this->assertTrue( $config['score'] );
		$this->assertTrue( $config['email'] );
		$this->assertFalse( $config['phone'], 'unchecked column → false' );
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

		$sm = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeStateMachine' );
		$sm->shouldReceive( 'transition_to' )->once()->andReturn(
			array(
				'success' => true,
				'errors'  => array(),
			)
		);

		$msg = $this->capture( array( RecruitmentNoticeEditPage::class, 'handle_transition' ) );

		$this->assertStringContainsString( 'ffc_msg=transitioned', $msg );
	}

	/**
	 * @dataProvider error_code_provider
	 */
	public function test_handle_transition_maps_state_machine_errors_to_flash_keys( string $error_code, string $expected_flash ): void {
		$_POST['notice_id']     = '5';
		$_POST['target_status'] = 'preliminary';

		$sm = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeStateMachine' );
		$sm->shouldReceive( 'transition_to' )->once()->andReturn(
			array(
				'success' => false,
				'errors'  => array( $error_code ),
			)
		);

		$msg = $this->capture( array( RecruitmentNoticeEditPage::class, 'handle_transition' ) );

		$this->assertStringContainsString( 'ffc_msg=' . $expected_flash, $msg );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function error_code_provider(): array {
		return array(
			'blocked by calls' => array( 'recruitment_definitive_to_preliminary_blocked_by_calls', 'transition-blocked-by-calls' ),
			'reason required'  => array( 'recruitment_transition_reason_required', 'transition-reason-required' ),
			'race lost'        => array( 'recruitment_transition_race_lost', 'transition-race-lost' ),
			'generic failure'  => array( 'recruitment_invalid_transition: draft->closed', 'transition-failed' ),
		);
	}

	public function test_handle_transition_passes_reason_through_to_state_machine(): void {
		$_POST['notice_id']     = '5';
		$_POST['target_status'] = 'closed';
		$_POST['reason']        = 'Concluded';

		$sm           = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeStateMachine' );
		$seen_reason  = 'unset';
		$sm->shouldReceive( 'transition_to' )->once()->andReturnUsing(
			function ( $id, $target, $reason ) use ( &$seen_reason ) {
				$seen_reason = $reason;
				return array(
					'success' => true,
					'errors'  => array(),
				);
			}
		);

		$this->capture( array( RecruitmentNoticeEditPage::class, 'handle_transition' ) );

		$this->assertSame( 'Concluded', $seen_reason );
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
