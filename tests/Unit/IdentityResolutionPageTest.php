<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\IdentityResolutionPage;
use FreeFormCertificate\Maintenance\IdentityConflictQuery;

/**
 * The identity-resolution worklist screen (#1368).
 *
 * What these assert is the WIRING and the GATE — that the submenu is
 * registered under the capability the issue split out, and that the queue
 * reads the check-digit scan. That the scan finds the right values is
 * `IdentityConflictQueryTest`'s job, through the seam this exercises.
 *
 * @covers \FreeFormCertificate\Admin\IdentityResolutionPage
 */
class IdentityResolutionPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Set up Brain\Monkey and preload the class under test.
	 *
	 * The preload is the pcov attribution fix CLAUDE.md records: a class first
	 * autoloaded DURING a test method reports 0% even when fully exercised.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Admin\IdentityResolutionPage' );

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
	}

	/**
	 * Tear down Brain\Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A page whose query seam is a double, so no table has to stand up.
	 *
	 * @param array<int, array<string, mixed>> $findings What the scan reports.
	 * @return IdentityResolutionPage
	 */
	private function page_reading( array $findings ): IdentityResolutionPage {
		$query = Mockery::mock( IdentityConflictQuery::class );
		$query->shouldReceive( 'rf_check_digit_failures' )
			->once()
			->with( IdentityResolutionPage::LIMIT )
			->andReturn( $findings );

		return new class( $query ) extends IdentityResolutionPage {

			/** @var IdentityConflictQuery */
			private $double;

			/**
			 * @param IdentityConflictQuery $double Stand-in for the real query.
			 */
			public function __construct( $double ) {
				$this->double = $double;
			}

			/**
			 * @return IdentityConflictQuery
			 */
			protected function conflicts(): IdentityConflictQuery {
				return $this->double;
			}
		};
	}

	/**
	 * The screen registers one submenu, under the capability #1368 split out.
	 *
	 * The capability is the point of the whole PR: `ffc_manage_settings_dangerzone`
	 * would also hand this operator delete-all and the cleanups.
	 */
	public function test_it_registers_the_submenu_under_its_own_capability(): void {
		$captured = array();

		Functions\when( 'add_submenu_page' )->alias(
			static function ( ...$args ) use ( &$captured ) {
				$captured[] = $args;
				return 'hook';
			}
		);

		( new IdentityResolutionPage() )->register_menu();

		$this->assertCount( 1, $captured, 'The screen must register exactly one submenu.' );
		$this->assertSame( IdentityResolutionPage::PARENT, $captured[0][0], 'The submenu must hang off the Certificate menu.' );
		$this->assertSame(
			'ffc_manage_identities',
			$captured[0][3],
			'The screen must be gated on ffc_manage_identities, never on the danger-zone cap it was split out of.'
		);
		$this->assertSame( IdentityResolutionPage::MENU_SLUG, $captured[0][4] );
	}

	/**
	 * The menu slug and the page-scope class agree.
	 *
	 * `AdminPageScopeTest` freezes the map; this pins the other half, so a
	 * slug renamed here without the view cannot pass by agreeing with itself.
	 */
	public function test_the_page_scope_class_follows_the_slug(): void {
		$this->assertStringStartsWith( 'ffc-', IdentityResolutionPage::MENU_SLUG );

		$expected = 'ffc-page-' . substr( IdentityResolutionPage::MENU_SLUG, strlen( 'ffc-' ) );
		$view     = (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		$this->assertStringContainsString(
			'wrap ffc-admin-page ' . $expected,
			$view,
			'The view\'s div.wrap must carry the generic anchor plus the page class derived from the slug.'
		);
	}

	/**
	 * `init()` hooks the registration onto `admin_menu`.
	 */
	public function test_init_hooks_the_registration(): void {
		$page = new IdentityResolutionPage();

		Functions\expect( 'add_action' )
			->once()
			->with( 'admin_menu', array( $page, 'register_menu' ) );

		$page->init();
	}

	/**
	 * The queue reads the check-digit scan, at this screen's own limit.
	 *
	 * The limit is deliberately above the audit card's 50: this is a worklist
	 * an operator works to zero, not a sample.
	 */
	public function test_the_queue_reads_the_check_digit_scan(): void {
		$findings = array(
			array(
				IdentityConflictQuery::COLUMN_RELATED   => '7',
				IdentityConflictQuery::COLUMN_ROW_IDS   => 'submissions:4,9',
				IdentityConflictQuery::ALIAS_ROW_COUNT  => 2,
			),
		);

		$this->assertSame( $findings, $this->page_reading( $findings )->queue() );
		$this->assertGreaterThan(
			50,
			IdentityResolutionPage::LIMIT,
			'A worklist capped at the audit card\'s sample size would hide its own tail.'
		);
	}

	/**
	 * The screen refuses a visitor without the capability.
	 */
	public function test_it_refuses_a_visitor_without_the_capability(): void {
		Functions\expect( 'current_user_can' )
			->once()
			->with( IdentityResolutionPage::CAPABILITY )
			->andReturn( false );

		Functions\expect( 'wp_die' )->once()->andThrow( new \RuntimeException( 'died' ) );

		$this->expectException( \RuntimeException::class );

		( new IdentityResolutionPage() )->render_page();
	}

	/**
	 * The screen never touches the database itself.
	 *
	 * It DOES write now — that is what the repair is — but every write goes
	 * through `IdentityRepair`, which owns the transaction, the refusals and
	 * the ordering. A `$wpdb` call appearing on this side would be a second
	 * write path with none of that, and it would look fine in review.
	 *
	 * This replaces the read-only assertion PR 2 carried: that claim stopped
	 * being true the moment the repair landed, and a test whose premise is
	 * false still passes.
	 */
	public function test_the_screen_never_writes_directly(): void {
		$source = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' )
			. (string) file_get_contents( __DIR__ . '/../../includes/admin/views/identity-resolution-page.php' );

		// The CALL shapes, never the bare token: this class's own docblock
		// explains why the query seam exists and says `$wpdb` doing it, and a
		// test that cannot tell prose from a call is the trap CLAUDE.md
		// records for suppression scanners.
		foreach ( array( '$wpdb->', 'global $wpdb', 'update_option(', 'update_user_meta(', 'wp_update_user(' ) as $direct ) {
			$this->assertStringNotContainsString(
				$direct,
				$source,
				sprintf( 'Every write belongs to IdentityRepair; `%s` on this side is a second path without its transaction.', $direct )
			);
		}
	}

	/**
	 * The write is gated on the capability AND a nonce keyed to the finding.
	 *
	 * Keyed per finding on purpose: a nonce valid for any row would let a
	 * correction confirmed for one person be replayed against another.
	 */
	public function test_the_write_is_gated_on_the_capability_and_a_keyed_nonce(): void {
		$page = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );

		$this->assertStringContainsString( 'Capabilities::current_user_can_admin_or( self::CAPABILITY )', $page );
		$this->assertStringContainsString( 'check_admin_referer( self::REPAIR_NONCE . $subject )', $page );
	}

	/**
	 * The handler hands the repair the HASH and the value, never the row ids.
	 *
	 * Re-evaluation at confirmation time is the whole point: between listing a
	 * finding and confirming it the operator went and asked a person, and rows
	 * may have arrived or left.
	 */
	public function test_the_handler_passes_no_row_ids(): void {
		$page = (string) file_get_contents( __DIR__ . '/../../includes/admin/class-ffc-identity-resolution-page.php' );

		$this->assertStringNotContainsString( 'ffc_row_ids', $page );
		$this->assertStringContainsString( "RequestInput::get_post_string( 'ffc_subject', '' )", $page );
	}
}
