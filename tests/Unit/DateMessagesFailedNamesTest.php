<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\DateMessagesAdminPage;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Who a run failed to reach, for the History tab (#2692): masked names, read
 * from the run's counted failures, and only for a viewer allowed to see names.
 *
 * @covers \FreeFormCertificate\DateMessages\DateMessagesAdminPage
 */
class DateMessagesFailedNamesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, string> Capabilities the viewer holds. */
	private array $caps = array();

	/** @var list<array<int, mixed>> Statements prepared. */
	private array $prepared = array();

	/** @var mixed */
	private $wpdb_backup;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );

		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$wpdb              = Mockery::mock( 'wpdb' );
		$wpdb->prefix      = 'wp_';
		$wpdb->users       = 'wp_users';
		$this->prepared    = array();
		$prepared          = &$this->prepared;
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( ...$args ) use ( &$prepared ) {
				$prepared[] = $args;
				return 'SQL';
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturn( array( 'Ana Souza', 'Bia Lima' ) );
		$GLOBALS['wpdb'] = $wpdb;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, array<string, mixed>> $history Runs.
	 * @return array<int, list<string>>
	 */
	private function names( array $history ): array {
		$method = new \ReflectionMethod( DateMessagesAdminPage::class, 'failed_names' );
		$method->setAccessible( true );
		return $method->invoke( null, $history );
	}

	public function test_a_viewer_allowed_to_see_names_gets_them_masked_for_runs_that_failed(): void {
		$this->caps = array( DateMessagesAdminPage::PII_CAP );

		$names = $this->names(
			array(
				array( 'id' => 7, 'failed' => 2 ),
				array( 'id' => 8, 'failed' => 0 ),
			)
		);

		$this->assertSame( array( 7 => array( 'A** S***', 'B** L***' ) ), $names );
		$this->assertCount( 1, $this->prepared, 'A run with no failure is not read.' );
		$this->assertSame( 'xf7', $this->prepared[0][4], 'The names come from the run\'s counted failures.' );
	}

	public function test_a_viewer_without_the_capability_gets_nothing_and_nothing_is_read(): void {
		$this->caps = array( DateMessagesAdminPage::VIEW_CAP );

		$this->assertSame( array(), $this->names( array( array( 'id' => 7, 'failed' => 2 ) ) ) );
		$this->assertSame( array(), $this->prepared );
	}
}
