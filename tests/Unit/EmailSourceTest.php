<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\EmailSource;

/**
 * @covers \FreeFormCertificate\Core\EmailSource
 */
class EmailSourceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\class_exists( '\FreeFormCertificate\Core\EmailSource' );
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_keys_follow_the_queue_contract(): void {
		$this->assertSame( 'plugin:ffcertificate_certificate', EmailSource::CERTIFICATE );
		$this->assertSame( 'plugin:ffcertificate_scheduling', EmailSource::SCHEDULING );
		$this->assertSame( 'plugin:ffcertificate_audience', EmailSource::AUDIENCE );
		$this->assertSame( 'plugin:ffcertificate_reregistration', EmailSource::REREGISTRATION );
		$this->assertSame( 'plugin:ffcertificate_recruitment', EmailSource::RECRUITMENT );
		$this->assertSame( 'plugin:ffcertificate_account', EmailSource::ACCOUNT );
		$this->assertSame( 'plugin:ffcertificate_admin', EmailSource::ADMIN );
	}

	public function test_label_returns_a_distinct_ffcertificate_label_per_key(): void {
		$keys = array(
			EmailSource::CERTIFICATE,
			EmailSource::SCHEDULING,
			EmailSource::AUDIENCE,
			EmailSource::REREGISTRATION,
			EmailSource::RECRUITMENT,
			EmailSource::ACCOUNT,
			EmailSource::ADMIN,
		);

		$labels = array();
		foreach ( $keys as $key ) {
			$label = EmailSource::label( $key );
			$this->assertStringContainsString( 'FFCertificate', $label );
			$labels[] = $label;
		}

		// Every function gets its own label (per-function labelling).
		$this->assertCount( count( $labels ), array_unique( $labels ) );
	}

	public function test_label_falls_back_to_product_name_for_an_unknown_key(): void {
		$this->assertSame( 'FFCertificate', EmailSource::label( 'plugin:ffcertificate_unknown' ) );
	}
}
