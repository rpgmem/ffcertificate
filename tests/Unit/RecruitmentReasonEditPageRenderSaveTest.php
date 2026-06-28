<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentReasonEditPage;

/**
 * Behavior tests for RecruitmentReasonEditPage::render() and ::handle_save().
 * The terminal wp_safe_redirect()/exit and wp_die() are replaced by marker
 * exceptions so each branch is observable; the reader (constant + helper
 * source) is defined as a guarded real stub via eval (alias mocks can't
 * expose constants or real static helpers), while the writer and AdminUI
 * are alias-mocked.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentReasonEditPage
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentReasonEditPageRenderSaveTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $caps;

	/** @var \Mockery\MockInterface */
	private $reader;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Recruitment\\RecruitmentReasonEditPage' );

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ) => (int) $v );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'admin_url' )->returnArg();
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

		$this->caps = Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' );
		$this->caps->shouldReceive( 'current_user_can_admin_or' )->andReturn( true )->byDefault();

		$adminui = Mockery::mock( 'alias:FreeFormCertificate\Admin\AdminUI' );
		$adminui->shouldReceive( 'render_toggle' )->andReturnNull()->byDefault();

		// RecruitmentReasonReader::get_by_id() returns the row under test;
		// decode_applies_to() mirrors the real CSV-split contract. The
		// alias replaces the autoloaded class wholesale; the page never
		// reads DEFAULT_COLOR because every row stub carries a color.
		$this->reader = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonReader' );
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( null )->byDefault();
		$this->reader->shouldReceive( 'decode_applies_to' )->andReturnUsing(
			static function ( $stored ) {
				$stored = trim( (string) $stored );
				return '' === $stored
					? array( 'denied', 'granted', 'appeal_denied', 'appeal_granted' )
					: array_values( array_filter( array_map( 'trim', explode( ',', $stored ) ) ) );
			}
		)->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $_GET['reason_id'], $_POST['reason_id'], $_POST['label'], $_POST['color'], $_POST['applies_to'] );
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

	private function reason_row(): object {
		return (object) array(
			'id'         => 7,
			'slug'       => 'medical',
			'label'      => 'Medical',
			'color'      => '#ff0000',
			'applies_to' => 'denied,granted',
		);
	}

	// ----- render() ---------------------------------------------------

	public function test_render_denied_when_no_cap(): void {
		$this->caps->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );

		$msg = $this->capture( static fn () => RecruitmentReasonEditPage::render() );
		$this->assertStringStartsWith( 'WP_DIE:', $msg );
	}

	public function test_render_existing_reason_outputs_form(): void {
		$this->reader->shouldReceive( 'get_by_id' )->with( 7 )->andReturn( $this->reason_row() );
		$_GET['reason_id'] = '7';

		ob_start();
		RecruitmentReasonEditPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Edit reason', $html );
		$this->assertStringContainsString( 'name="label"', $html );
		$this->assertStringContainsString( 'ffc_recruitment_save_reason', $html );
		$this->assertStringContainsString( 'medical', $html );
	}

	public function test_render_applies_all_when_stored_empty(): void {
		$row = $this->reason_row();
		$row->applies_to = '';
		$this->reader->shouldReceive( 'get_by_id' )->with( 7 )->andReturn( $row );
		$_GET['reason_id'] = '7';

		ob_start();
		RecruitmentReasonEditPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Applies to', $html );
	}

	public function test_render_not_found_shows_notice(): void {
		// No id -> reason null.
		ob_start();
		RecruitmentReasonEditPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Reason not found', $html );
		$this->assertStringContainsString( 'Back to Reasons', $html );
	}

	// ----- handle_save() ----------------------------------------------

	public function test_save_denied_when_no_cap(): void {
		$this->caps->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );

		$msg = $this->capture( static fn () => RecruitmentReasonEditPage::handle_save() );
		$this->assertStringStartsWith( 'WP_DIE:', $msg );
	}

	public function test_save_bad_nonce_dies(): void {
		Functions\when( 'check_admin_referer' )->alias(
			static function () {
				throw new \RuntimeException( 'NONCE_FAIL' );
			}
		);
		$_POST['reason_id'] = '7';

		$msg = $this->capture( static fn () => RecruitmentReasonEditPage::handle_save() );
		$this->assertSame( 'NONCE_FAIL', $msg );
	}

	public function test_save_invalid_id_redirects_back(): void {
		$_POST['reason_id'] = '0';

		$msg = $this->capture( static fn () => RecruitmentReasonEditPage::handle_save() );
		$this->assertStringStartsWith( 'REDIRECT:', $msg );
		$this->assertStringContainsString( 'tab=reasons', $msg );
	}

	public function test_save_happy_path_updates_and_redirects(): void {
		$writer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonWriter' );
		$writer->shouldReceive( 'update' )
			->once()
			->with(
				7,
				Mockery::on(
					static function ( $data ) {
						return 'New Label' === $data['label']
							&& '#00ff00' === $data['color']
							&& array( 'denied', 'granted' ) === $data['applies_to'];
					}
				)
			)
			->andReturn( true );

		$_POST['reason_id']  = '7';
		$_POST['label']      = 'New Label';
		$_POST['color']      = '#00ff00';
		$_POST['applies_to'] = array( 'denied', 'granted', 123 );

		$msg = $this->capture( static fn () => RecruitmentReasonEditPage::handle_save() );
		$this->assertStringContainsString( 'REDIRECT:', $msg );
		$this->assertStringContainsString( 'ffc_msg=saved', $msg );
		$this->assertStringContainsString( 'reason_id=7', $msg );
	}

	public function test_save_missing_fields_default_to_empty(): void {
		$writer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonWriter' );
		$writer->shouldReceive( 'update' )
			->once()
			->with(
				7,
				Mockery::on(
					static function ( $data ) {
						return '' === $data['label'] && '' === $data['color'] && array() === $data['applies_to'];
					}
				)
			)
			->andReturn( true );

		$_POST['reason_id'] = '7';

		$msg = $this->capture( static fn () => RecruitmentReasonEditPage::handle_save() );
		$this->assertStringContainsString( 'REDIRECT:', $msg );
	}
}
