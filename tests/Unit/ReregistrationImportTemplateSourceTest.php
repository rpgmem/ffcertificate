<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Reregistration\ReregistrationImportTemplateSource;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The blank answers file of one audience of a campaign (#2506). The header
 * itself is ReregistrationImportStagingService::template_header(), tested
 * against the import's own map.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationImportTemplateSource
 */
class ReregistrationImportTemplateSourceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<int> Audiences campaign 3 reaches. */
	private array $campaign_audiences = array( 7, 8 );

	/** @var bool Whether the caller holds the import capability. */
	private bool $can = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->alias( fn() => $this->can );
		Functions\when( 'nocache_headers' )->justReturn( null );
		Functions\when( 'wp_die' )->alias(
			static function ( $message ) {
				throw new \RuntimeException( (string) $message );
			}
		);

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( ...$args ) => $args );
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing( fn() => array_map( 'strval', $this->campaign_audiences ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_an_audience_of_the_campaign_is_served_without_cache(): void {
		$nonces = array();
		Functions\when( 'check_admin_referer' )->alias(
			static function ( $action ) use ( &$nonces ) {
				$nonces[] = $action;
				return 1;
			}
		);
		$nocache = 0;
		Functions\when( 'nocache_headers' )->alias(
			static function () use ( &$nocache ) {
				++$nocache;
			}
		);

		( new ReregistrationImportTemplateSource( 3, 8 ) )->authorize();

		$this->assertSame( array( 'ffc_rereg_import' ), $nonces, 'The import\'s own nonce.' );
		$this->assertSame( 1, $nocache );
	}

	public function test_an_audience_the_campaign_does_not_reach_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'This audience is not part of the campaign.' );

		( new ReregistrationImportTemplateSource( 3, 9 ) )->authorize();
	}

	public function test_without_the_import_capability_it_is_refused(): void {
		$this->can = false;
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Permission denied.' );

		( new ReregistrationImportTemplateSource( 3, 7 ) )->authorize();
	}

	public function test_the_file_names_the_campaign_and_the_audience_and_has_no_rows(): void {
		$source = new ReregistrationImportTemplateSource( 3, 7 );

		$this->assertSame( 'ffc-reregistration-3-audience-7.csv', $source->filename() );
		$this->assertSame( array(), iterator_to_array( $source->rows() ) );
	}
}
