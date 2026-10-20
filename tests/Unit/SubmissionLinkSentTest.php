<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Repositories\SubmissionRepository;
use FreeFormCertificate\Submissions\SubmissionHandler;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the link_sent_at listener of SubmissionHandler (#1967).
 *
 * The e-mail handler fires `ffcertificate_link_sent` after a link e-mail is
 * handed to the mailer; the handler stores the instant through its repository.
 *
 * @covers \FreeFormCertificate\Submissions\SubmissionHandler
 */
class SubmissionLinkSentTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_record_link_sent_stores_the_instant_through_the_repository(): void {
		$repository = Mockery::mock( SubmissionRepository::class );
		$repository->shouldReceive( 'update' )
			->once()
			->with(
				42,
				Mockery::on(
					static fn( array $data ): bool => isset( $data['link_sent_at'] ) && is_int( $data['link_sent_at'] )
				)
			)
			->andReturn( 1 );

		$handler = new SubmissionHandler();
		$property = new \ReflectionProperty( SubmissionHandler::class, 'repository' );
		$property->setAccessible( true );
		$property->setValue( $handler, $repository );

		$handler->record_link_sent( 42 );

		$this->assertTrue( true, 'the repository mock asserts the update' );
	}

	public function test_the_listener_is_registered_on_the_link_sent_hook(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'ffcertificate_link_sent', Mockery::type( 'array' ), 10, 1 );

		( new SubmissionHandler() )->register_async_pipeline();

		$this->assertTrue( true, 'add_action asserts the hook registration' );
	}
}
