<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceEmailTokenMigration;

/**
 * Tests for the one-shot audience email token migration (#653).
 *
 * The schedule repository is the real one, over a `$wpdb` double that answers
 * the schedule list and records every update (#2127).
 *
 * @covers \FreeFormCertificate\Audience\AudienceEmailTokenMigration
 */
class AudienceEmailTokenMigrationTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/**
	 * Every `$wpdb->update()`, as `id => data`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $updates = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$this->updates     = array();
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A database holding these schedule rows.
	 *
	 * @param array<int, object> $rows Schedule rows.
	 */
	private function schedules( array $rows ): void {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( $rows );
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[ $where['id'] ] = $data;
				return 1;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	public function test_skips_when_already_migrated(): void {
		Functions\when( 'get_option' )->justReturn( 1 );
		// No expectations: reading or writing a schedule fails the test.
		$GLOBALS['wpdb'] = Mockery::mock( 'wpdb' );

		AudienceEmailTokenMigration::maybe_migrate();

		$this->assertSame( array(), $this->updates );
	}

	public function test_rewrites_single_brace_tokens_and_sets_flag(): void {
		Functions\when( 'get_option' )->justReturn( false );
		$flag = null;
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) use ( &$flag ) {
				if ( 'ffc_audience_email_tokens_migrated_v1' === $name ) {
					$flag = $value;
				}
				return true;
			}
		);
		$this->schedules(
			array(
				(object) array(
					'id'                          => 7,
					'email_template_booking'      => 'Hi {user_name}, at {environment_name}. Keep .btn{color:red}',
					'email_template_cancellation' => 'Bye {user_name} — {cancellation_reason}',
				),
			)
		);

		AudienceEmailTokenMigration::maybe_migrate();

		$this->assertArrayHasKey( 7, $this->updates );
		// Known tokens doubled...
		$this->assertSame( 'Hi {{user_name}}, at {{environment_name}}. Keep .btn{color:red}', $this->updates[7]['email_template_booking'] );
		$this->assertSame( 'Bye {{user_name}} — {{cancellation_reason}}', $this->updates[7]['email_template_cancellation'] );
		// ...and the literal CSS brace block is left untouched (only known tokens convert).
		$this->assertStringContainsString( '.btn{color:red}', $this->updates[7]['email_template_booking'] );
		// Flag set so it never runs twice.
		$this->assertSame( 1, $flag );
	}

	public function test_skips_schedules_with_no_templates(): void {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		$this->schedules(
			array( (object) array( 'id' => 3, 'email_template_booking' => null, 'email_template_cancellation' => null ) )
		);

		AudienceEmailTokenMigration::maybe_migrate();

		$this->assertSame( array(), $this->updates );
	}
}
