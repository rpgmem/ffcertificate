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
 * @covers \FreeFormCertificate\Audience\AudienceEmailTokenMigration
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceEmailTokenMigrationTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_skips_when_already_migrated(): void {
		Functions\when( 'get_option' )->justReturn( 1 );
		$repo = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$repo->shouldNotReceive( 'get_all' );
		$repo->shouldNotReceive( 'update' );

		AudienceEmailTokenMigration::maybe_migrate();

		$this->assertTrue( true );
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

		$updates = array();
		$repo    = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$repo->shouldReceive( 'get_all' )->andReturn(
			array(
				(object) array(
					'id'                          => 7,
					'email_template_booking'      => 'Hi {user_name}, at {environment_name}. Keep .btn{color:red}',
					'email_template_cancellation' => 'Bye {user_name} — {cancellation_reason}',
				),
			)
		);
		$repo->shouldReceive( 'update' )->andReturnUsing(
			function ( $id, $data ) use ( &$updates ) {
				$updates[ $id ] = $data;
				return true;
			}
		);

		AudienceEmailTokenMigration::maybe_migrate();

		$this->assertArrayHasKey( 7, $updates );
		// Known tokens doubled...
		$this->assertSame( 'Hi {{user_name}}, at {{environment_name}}. Keep .btn{color:red}', $updates[7]['email_template_booking'] );
		$this->assertSame( 'Bye {{user_name}} — {{cancellation_reason}}', $updates[7]['email_template_cancellation'] );
		// ...and the literal CSS brace block is left untouched (only known tokens convert).
		$this->assertStringContainsString( '.btn{color:red}', $updates[7]['email_template_booking'] );
		// Flag set so it never runs twice.
		$this->assertSame( 1, $flag );
	}

	public function test_skips_schedules_with_no_templates(): void {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );

		$repo = Mockery::mock( 'alias:\FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$repo->shouldReceive( 'get_all' )->andReturn(
			array( (object) array( 'id' => 3, 'email_template_booking' => null, 'email_template_cancellation' => null ) )
		);
		$repo->shouldNotReceive( 'update' );

		AudienceEmailTokenMigration::maybe_migrate();

		$this->assertTrue( true );
	}
}
