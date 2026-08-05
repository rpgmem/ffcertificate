<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\Strategies\ImportLegacyTemplatesMigrationStrategy;
use FreeFormCertificate\Admin\CertTemplateCpt;

/**
 * Tests for the #865 migration that imports legacy `html/` certificate layouts
 * into the database-backed template pool.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\ImportLegacyTemplatesMigrationStrategy
 */
class ImportLegacyTemplatesMigrationStrategyTest extends TestCase {

	/**
	 * Temp directory standing in for the plugin's legacy `html/` folder.
	 *
	 * @var string
	 */
	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Migrations\\Strategies\\ImportLegacyTemplatesMigrationStrategy' );
		class_exists( '\\FreeFormCertificate\\Admin\\CertTemplateWriter' );
		Functions\when( '__' )->returnArg();

		$this->dir = sys_get_temp_dir() . '/ffc-legacy-' . uniqid( '', true ) . '/';
		mkdir( $this->dir, 0777, true );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '*' ) as $file ) {
			if ( is_string( $file ) ) {
				unlink( $file );
			}
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Write a fixture file into the temp legacy folder.
	 *
	 * @param string $name    Basename.
	 * @param string $content File contents.
	 */
	private function put( string $name, string $content = '<div>x</div>' ): void {
		file_put_contents( $this->dir . $name, $content );
	}

	/**
	 * Wire a mutable "imported basenames" option store so get_option reflects
	 * what update_option last persisted (models the real idempotency guard).
	 *
	 * @param array<int,string> $store Reference to the backing store.
	 */
	private function wire_imported_store( array &$store ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = array() ) use ( &$store ) {
				return $store;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				$store = $value;
				return true;
			}
		);
	}

	/**
	 * Wire the real CertTemplateWriter::create() by stubbing the WP calls it makes,
	 * capturing the titles and HTML bodies it would persist.
	 *
	 * @param array<int,string> $titles Reference collecting each created title.
	 * @param array<int,string> $htmls  Reference collecting each created HTML body.
	 */
	private function wire_writer( array &$titles, array &$htmls ): void {
		$counter = 0;
		Functions\when( 'wp_insert_post' )->alias(
			static function ( $arr ) use ( &$titles, &$counter ) {
				$titles[] = $arr['post_title'];
				return 100 + ( ++$counter );
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$htmls ) {
				if ( CertTemplateCpt::META_HTML === $key ) {
					$htmls[] = $value;
				}
				return true;
			}
		);
	}

	public function test_calculate_status_counts_importable_excluding_defaults_and_non_certificate(): void {
		$this->put( 'my_custom_certificate.html' );
		$this->put( 'another_certificate.html' );
		$this->put( 'default_certificate_1.html' ); // shipped default — excluded.
		$this->put( 'ficha_template.html' );        // no "certificate" — excluded.

		Functions\when( 'get_option' )->justReturn( array() );

		$strategy = new ImportLegacyTemplatesMigrationStrategy( $this->dir );
		$status   = $strategy->calculate_status( 'import_legacy_templates', array() );

		$this->assertSame( 2, $status['total'] );
		$this->assertSame( 0, $status['migrated'] );
		$this->assertSame( 2, $status['pending'] );
		$this->assertFalse( $status['is_complete'] );
	}

	public function test_execute_imports_pending_and_marks_complete(): void {
		$this->put( 'my_custom_certificate.html', 'AAA' );
		$this->put( 'another_certificate.html', 'BBB' );
		$this->put( 'default_certificate_2.html', 'DEF' ); // excluded.
		$this->put( 'ficha_template.html', 'FICHA' );      // excluded.

		$store = array();
		$this->wire_imported_store( $store );
		$titles = array();
		$htmls  = array();
		$this->wire_writer( $titles, $htmls );

		$strategy = new ImportLegacyTemplatesMigrationStrategy( $this->dir );
		$result   = $strategy->execute( 'import_legacy_templates', array( 'batch_size' => 20 ) );

		$this->assertSame( 2, $result['processed'] );
		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertFalse( $result['has_more'] );

		// Titles derived from basenames, sorted by basename (another < my_custom).
		$this->assertSame( array( 'Another Certificate', 'My Custom Certificate' ), $titles );
		$this->assertContains( 'AAA', $htmls );
		$this->assertContains( 'BBB', $htmls );

		// Both basenames recorded; the excluded ones are not.
		$this->assertContains( 'my_custom_certificate.html', $store );
		$this->assertContains( 'another_certificate.html', $store );
		$this->assertNotContains( 'default_certificate_2.html', $store );
		$this->assertNotContains( 'ficha_template.html', $store );

		// Re-reading status now reports complete.
		$after = $strategy->calculate_status( 'import_legacy_templates', array() );
		$this->assertTrue( $after['is_complete'] );
		$this->assertSame( 2, $after['migrated'] );
		$this->assertSame( 0, $after['pending'] );
	}

	public function test_execute_is_idempotent_when_all_imported(): void {
		$this->put( 'my_custom_certificate.html' );
		$this->put( 'another_certificate.html' );

		Functions\when( 'get_option' )->justReturn(
			array( 'my_custom_certificate.html', 'another_certificate.html' )
		);
		Functions\expect( 'wp_insert_post' )->never();
		Functions\expect( 'update_option' )->never();

		$strategy = new ImportLegacyTemplatesMigrationStrategy( $this->dir );
		$result   = $strategy->execute( 'import_legacy_templates', array() );

		$this->assertSame( 0, $result['processed'] );
		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertFalse( $result['has_more'] );
	}

	public function test_execute_respects_batch_size(): void {
		$this->put( 'my_custom_certificate.html' );
		$this->put( 'another_certificate.html' );

		$store = array();
		$this->wire_imported_store( $store );
		$titles = array();
		$htmls  = array();
		$this->wire_writer( $titles, $htmls );

		$strategy = new ImportLegacyTemplatesMigrationStrategy( $this->dir );
		$result   = $strategy->execute( 'import_legacy_templates', array( 'batch_size' => 1 ) );

		$this->assertSame( 1, $result['processed'] );
		$this->assertCount( 1, $titles );
		$this->assertCount( 1, $store );
		$this->assertTrue( $result['has_more'], 'one file still pending after a size-1 batch' );
	}

	public function test_execute_records_failure_but_still_terminates(): void {
		$this->put( 'broken_certificate.html', 'CONTENT' );

		$store = array();
		$this->wire_imported_store( $store );
		// wp_insert_post returns 0 → CertTemplateWriter::create() returns 0 → import fails.
		Functions\when( 'wp_insert_post' )->justReturn( 0 );
		Functions\when( 'update_post_meta' )->justReturn( true );

		$strategy = new ImportLegacyTemplatesMigrationStrategy( $this->dir );
		$result   = $strategy->execute( 'import_legacy_templates', array() );

		$this->assertSame( 0, $result['processed'] );
		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['errors'] );

		// Failure is still recorded as handled, so the batch runner terminates.
		$this->assertContains( 'broken_certificate.html', $store );
		$after = $strategy->calculate_status( 'import_legacy_templates', array() );
		$this->assertTrue( $after['is_complete'] );
	}

	public function test_empty_dir_reports_complete(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$strategy = new ImportLegacyTemplatesMigrationStrategy( $this->dir );
		$status   = $strategy->calculate_status( 'import_legacy_templates', array() );

		$this->assertSame( 0, $status['total'] );
		$this->assertSame( 0, $status['pending'] );
		$this->assertTrue( $status['is_complete'] );
		$this->assertEqualsWithDelta( 100.0, $status['percent'], 0.001 );
	}

	public function test_can_run_true_when_writer_present(): void {
		$strategy = new ImportLegacyTemplatesMigrationStrategy( $this->dir );
		$this->assertTrue( $strategy->can_run( 'import_legacy_templates', array() ) );
	}

	public function test_get_name_returns_nonempty(): void {
		$strategy = new ImportLegacyTemplatesMigrationStrategy( $this->dir );
		$this->assertNotSame( '', $strategy->get_name() );
	}
}
