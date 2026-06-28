<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentAdjutancyEditPage;

/**
 * Behavior tests for RecruitmentAdjutancyEditPage::render() and
 * ::handle_save(). Terminal wp_safe_redirect()/exit and wp_die() become
 * marker exceptions so each branch is observable. The reader is
 * alias-mocked for get_by_id(); the writer is alias-mocked to assert the
 * update call and exercise both the saved / save-failed redirect arms.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdjutancyEditPage
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentAdjutancyEditPageRenderSaveTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $reader;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Recruitment\\RecruitmentAdjutancyEditPage' );

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
		Functions\when( 'current_user_can' )->justReturn( true );
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

		$this->reader = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader' );
		$this->reader->shouldReceive( 'get_by_id' )->andReturn( null )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $_GET['adjutancy_id'], $_POST['adjutancy_id'], $_POST['name'], $_POST['color'] );
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

	private function adjutancy_row(): object {
		return (object) array(
			'id'    => 4,
			'slug'  => 'medical-board',
			'name'  => 'Medical Board',
			'color' => '#123456',
		);
	}

	// ----- render() ---------------------------------------------------

	public function test_render_denied_when_no_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$msg = $this->capture( static fn () => RecruitmentAdjutancyEditPage::render() );
		$this->assertStringStartsWith( 'WP_DIE:', $msg );
	}

	public function test_render_existing_adjutancy_outputs_form(): void {
		$this->reader->shouldReceive( 'get_by_id' )->with( 4 )->andReturn( $this->adjutancy_row() );
		$_GET['adjutancy_id'] = '4';

		ob_start();
		RecruitmentAdjutancyEditPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Edit adjutancy', $html );
		$this->assertStringContainsString( 'name="name"', $html );
		$this->assertStringContainsString( 'ffc_recruitment_save_adjutancy', $html );
		$this->assertStringContainsString( 'medical-board', $html );
		$this->assertStringContainsString( '#123456', $html );
	}

	public function test_render_not_found_shows_notice(): void {
		// No id -> adjutancy null.
		ob_start();
		RecruitmentAdjutancyEditPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Adjutancy not found', $html );
		$this->assertStringContainsString( 'Back to Adjutancies', $html );
	}

	// ----- handle_save() ----------------------------------------------

	public function test_save_denied_when_no_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$msg = $this->capture( static fn () => RecruitmentAdjutancyEditPage::handle_save() );
		$this->assertStringStartsWith( 'WP_DIE:', $msg );
	}

	public function test_save_bad_nonce_dies(): void {
		Functions\when( 'check_admin_referer' )->alias(
			static function () {
				throw new \RuntimeException( 'NONCE_FAIL' );
			}
		);
		$_POST['adjutancy_id'] = '4';

		$msg = $this->capture( static fn () => RecruitmentAdjutancyEditPage::handle_save() );
		$this->assertSame( 'NONCE_FAIL', $msg );
	}

	public function test_save_invalid_id_redirects_back(): void {
		$_POST['adjutancy_id'] = '0';

		$msg = $this->capture( static fn () => RecruitmentAdjutancyEditPage::handle_save() );
		$this->assertStringStartsWith( 'REDIRECT:', $msg );
		$this->assertStringContainsString( 'tab=adjutancies', $msg );
	}

	public function test_save_happy_path_updates_and_redirects_saved(): void {
		$writer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyWriter' );
		$writer->shouldReceive( 'update' )
			->once()
			->with(
				4,
				Mockery::on(
					static fn ( $data ) => 'New Name' === $data['name'] && '#abcdef' === $data['color']
				)
			)
			->andReturn( true );

		$_POST['adjutancy_id'] = '4';
		$_POST['name']         = 'New Name';
		$_POST['color']        = '#abcdef';

		$msg = $this->capture( static fn () => RecruitmentAdjutancyEditPage::handle_save() );
		$this->assertStringContainsString( 'REDIRECT:', $msg );
		$this->assertStringContainsString( 'ffc_msg=saved', $msg );
		$this->assertStringContainsString( 'adjutancy_id=4', $msg );
	}

	public function test_save_failure_redirects_save_failed(): void {
		$writer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyWriter' );
		$writer->shouldReceive( 'update' )->once()->andReturn( false );

		$_POST['adjutancy_id'] = '4';
		// Omit name/color to also cover the default-empty branch.

		$msg = $this->capture( static fn () => RecruitmentAdjutancyEditPage::handle_save() );
		$this->assertStringContainsString( 'REDIRECT:', $msg );
		$this->assertStringContainsString( 'ffc_msg=save-failed', $msg );
	}
}
