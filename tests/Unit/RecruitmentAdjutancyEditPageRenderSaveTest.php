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
 * marker exceptions so each branch is observable, so no test reaches the
 * `exit`. RecruitmentAdjutancyReader and RecruitmentAdjutancyWriter are the
 * real ones, over a `$wpdb` double that answers the row by id and records
 * every update, so both the saved / save-failed redirect arms run (#2127).
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdjutancyEditPage
 */
class RecruitmentAdjutancyEditPageRenderSaveTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var object|null The adjutancy row the database holds. */
	private ?object $row = null;

	/** @var array<int, mixed> Arguments of the last prepare(). */
	private array $bound = array();

	/** @var int|false What `$wpdb->update()` answers. */
	private $update_result = 1;

	/**
	 * Every `$wpdb->update()`, as [table, data, where].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private array $updates = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
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
		class_exists( '\\FreeFormCertificate\\Recruitment\\RecruitmentAdjutancyEditPage' );

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

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

		$this->row           = null;
		$this->update_result = 1;
		$this->updates       = array();

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->bound = $args;
				return (string) $sql;
			}
		);
		// RecruitmentAdjutancyReader::get_by_id() binds the table, then the id.
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			fn() => ( null !== $this->row && (int) $this->row->id === (int) $this->bound[1] ) ? $this->row : null
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array( $table, $data, $where );
				return $this->update_result;
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
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
		$this->row = $this->adjutancy_row();
		$_GET['adjutancy_id'] = '4';

		ob_start();
		RecruitmentAdjutancyEditPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Edit adjutancy', $html );
		$this->assertStringContainsString( 'name="name"', $html );
		$this->assertStringContainsString( 'ffc_recruitment_save_adjutancy', $html );
		$this->assertStringContainsString( 'medical-board', $html );
		$this->assertStringContainsString( '#123456', $html );
		// A boxed edit screen (#1631): the record is the h1, and the section
		// is a card whose heading carries the adjutancy icon, not a postbox.
		$this->assertMatchesRegularExpression( '/<h1>Edit adjutancy — [^<]+<\/h1>/', $html );
		$this->assertStringContainsString( '<div class="card"><h2 class="ffc-icon-building">General</h2>', $html );
		$this->assertStringNotContainsString( 'postbox', $html );
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
		$_POST['adjutancy_id'] = '4';
		$_POST['name']         = 'New Name';
		$_POST['color']        = '#abcdef';

		$msg = $this->capture( static fn () => RecruitmentAdjutancyEditPage::handle_save() );
		$this->assertStringContainsString( 'REDIRECT:', $msg );
		$this->assertStringContainsString( 'ffc_msg=saved', $msg );
		$this->assertStringContainsString( 'adjutancy_id=4', $msg );

		$this->assertCount( 1, $this->updates, 'The writer must update the row once.' );
		[ $table, $data, $where ] = $this->updates[0];
		$this->assertSame( 'wp_ffc_recruitment_adjutancy', $table );
		$this->assertSame( array( 'id' => 4 ), $where );
		$this->assertSame( 'New Name', $data['name'] );
		$this->assertSame( '#abcdef', $data['color'] );
	}

	public function test_save_failure_redirects_save_failed(): void {
		$this->update_result = false;

		$_POST['adjutancy_id'] = '4';
		// Omit name/color to also cover the default-empty branch.

		$msg = $this->capture( static fn () => RecruitmentAdjutancyEditPage::handle_save() );
		$this->assertStringContainsString( 'REDIRECT:', $msg );
		$this->assertStringContainsString( 'ffc_msg=save-failed', $msg );
		$this->assertCount( 1, $this->updates, 'The writer must attempt the update once.' );
	}
}
