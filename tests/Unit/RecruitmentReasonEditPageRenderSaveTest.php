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
 * Terminal wp_safe_redirect()/exit and wp_die() become marker exceptions so
 * each branch is observable, so no test reaches the `exit`. Capabilities,
 * AdminUI, RecruitmentReasonReader and RecruitmentReasonWriter are the real
 * ones: the capability check runs over a stubbed current_user_can(), and the
 * repository halves over a `$wpdb` double that answers the row by id and
 * records every update.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentReasonEditPage
 */
class RecruitmentReasonEditPageRenderSaveTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var object|null The reason row the database holds. */
	private ?object $row = null;

	/** @var array<int, mixed> Arguments of the last prepare(). */
	private array $bound = array();

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
		class_exists( '\\FreeFormCertificate\\Recruitment\\RecruitmentReasonEditPage' );

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

		$this->row     = null;
		$this->bound   = array();
		$this->updates = array();

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->bound = $args;
				return (string) $sql;
			}
		);
		// RecruitmentReasonReader::get_by_id() binds the table, then the id.
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			fn() => ( null !== $this->row && (int) $this->row->id === (int) $this->bound[1] ) ? $this->row : null
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array( $table, $data, $where );
				return 1;
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
		Functions\when( 'current_user_can' )->justReturn( false );

		$msg = $this->capture( static fn () => RecruitmentReasonEditPage::render() );
		$this->assertStringStartsWith( 'WP_DIE:', $msg );
	}

	public function test_render_existing_reason_outputs_form(): void {
		$this->row = $this->reason_row();
		$_GET['reason_id'] = '7';

		ob_start();
		RecruitmentReasonEditPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Edit reason', $html );
		$this->assertStringContainsString( 'name="label"', $html );
		$this->assertStringContainsString( 'ffc_recruitment_save_reason', $html );
		$this->assertStringContainsString( 'medical', $html );
		// The stored set ticks exactly its two toggles.
		$this->assertMatchesRegularExpression( '/id="ffc-reason-edit-applies-denied"[^>]* checked/', $html );
		$this->assertMatchesRegularExpression( '/id="ffc-reason-edit-applies-granted"[^>]* checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/id="ffc-reason-edit-applies-appeal_denied"[^>]* checked/', $html );
	}

	public function test_render_applies_all_when_stored_empty(): void {
		$row             = $this->reason_row();
		$row->applies_to = '';
		$this->row       = $row;
		$_GET['reason_id'] = '7';

		ob_start();
		RecruitmentReasonEditPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Applies to', $html );
		// Stored empty means "applies to all", drawn as every box unchecked.
		$this->assertStringContainsString( 'id="ffc-reason-edit-applies-appeal_granted"', $html );
		$this->assertStringNotContainsString( ' checked', $html );
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
		Functions\when( 'current_user_can' )->justReturn( false );

		$msg = $this->capture( static fn () => RecruitmentReasonEditPage::handle_save() );
		$this->assertStringStartsWith( 'WP_DIE:', $msg );
		$this->assertSame( array(), $this->updates );
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
		$this->assertSame( array(), $this->updates );
	}

	public function test_save_invalid_id_redirects_back(): void {
		$_POST['reason_id'] = '0';

		$msg = $this->capture( static fn () => RecruitmentReasonEditPage::handle_save() );
		$this->assertStringStartsWith( 'REDIRECT:', $msg );
		$this->assertStringContainsString( 'tab=reasons', $msg );
		$this->assertSame( array(), $this->updates );
	}

	public function test_save_happy_path_updates_and_redirects(): void {
		$_POST['reason_id']  = '7';
		$_POST['label']      = 'New Label';
		$_POST['color']      = '#00ff00';
		$_POST['applies_to'] = array( 'denied', 'granted', 123 );

		$msg = $this->capture( static fn () => RecruitmentReasonEditPage::handle_save() );
		$this->assertStringContainsString( 'REDIRECT:', $msg );
		$this->assertStringContainsString( 'ffc_msg=saved', $msg );
		$this->assertStringContainsString( 'reason_id=7', $msg );

		$this->assertCount( 1, $this->updates, 'The writer must update the row once.' );
		[ $table, $data, $where ] = $this->updates[0];
		$this->assertSame( 'wp_ffc_recruitment_reason', $table );
		$this->assertSame( array( 'id' => 7 ), $where );
		$this->assertSame( 'New Label', $data['label'] );
		$this->assertSame( '#00ff00', $data['color'] );
		// The non-string entry is dropped; the rest is stored as CSV.
		$this->assertSame( 'denied,granted', $data['applies_to'] );
	}

	public function test_save_missing_fields_default_to_empty(): void {
		$_POST['reason_id'] = '7';

		$msg = $this->capture( static fn () => RecruitmentReasonEditPage::handle_save() );
		$this->assertStringContainsString( 'REDIRECT:', $msg );

		$this->assertCount( 1, $this->updates, 'The writer must update the row once.' );
		[ , $data ] = $this->updates[0];
		$this->assertSame( '', $data['label'] );
		// An empty color is stored as the reader's default badge color.
		$this->assertSame( \FreeFormCertificate\Recruitment\RecruitmentReasonReader::DEFAULT_COLOR, $data['color'] );
		// An empty selection is stored empty (= applies to every status).
		$this->assertSame( '', $data['applies_to'] );
	}
}
