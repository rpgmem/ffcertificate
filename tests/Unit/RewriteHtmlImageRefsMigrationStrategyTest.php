<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\Strategies\RewriteHtmlImageRefsMigrationStrategy;
use FreeFormCertificate\Admin\CertTemplateCpt;

/**
 * A testable subclass that overrides the WordPress media plumbing so the tests
 * exercise the scan / dedup / rewrite logic without touching the filesystem
 * uploads dir or admin includes.
 */
class TestableRewriteHtmlImageRefsMigrationStrategy extends RewriteHtmlImageRefsMigrationStrategy {

	/**
	 * Basenames side-loaded, in order (one entry per real sideload call).
	 *
	 * @var array<int, string>
	 */
	public array $sideloaded = array();

	protected function sideload_resolved_file( string $src_path, string $basename ): string {
		$this->sideloaded[] = $basename;
		return 'https://media.example/uploads/' . $basename;
	}
}

/**
 * Tests for the #865 migration that rewrites legacy `html/` image references
 * into Media Library attachments.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\RewriteHtmlImageRefsMigrationStrategy
 */
class RewriteHtmlImageRefsMigrationStrategyTest extends TestCase {

	/**
	 * Temp directory standing in for the plugin's legacy `html/` folder.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * In-memory post-meta store keyed "id|key".
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = array();

	/**
	 * In-memory options store keyed by option name.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Captured update_post_meta calls: each [id, key, value].
	 *
	 * @var array<int, array{0:int,1:string,2:mixed}>
	 */
	private array $written = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Migrations\\Strategies\\RewriteHtmlImageRefsMigrationStrategy' );
		class_exists( '\\FreeFormCertificate\\Admin\\CertTemplateCpt' );
		Functions\when( '__' )->returnArg();

		$this->dir = sys_get_temp_dir() . '/ffc-htmlrefs-' . uniqid( '', true ) . '/';
		mkdir( $this->dir, 0777, true );

		$this->meta    = array();
		$this->options = array();
		$this->written = array();
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
	 * Create an image file in the temp legacy folder.
	 *
	 * @param string $name Basename.
	 */
	private function put_file( string $name ): void {
		file_put_contents( $this->dir . $name, 'PNGDATA' );
	}

	/**
	 * A legacy `/html/` URL for a given file basename.
	 *
	 * @param string $name Basename.
	 * @return string
	 */
	private function html_url( string $name ): string {
		return '/wp-content/plugins/ffcertificate/html/' . $name;
	}

	/**
	 * Wire Brain Monkey stubs for the given form ids, pool ids and meta store.
	 *
	 * @param array<int,int>       $forms Form post ids.
	 * @param array<int,int>       $pool  Pool post ids.
	 * @param array<string, mixed> $meta  Meta store keyed "id|key".
	 */
	private function wire( array $forms, array $pool, array $meta ): void {
		$this->meta = $meta;

		Functions\when( 'get_posts' )->alias(
			function ( $args ) use ( $forms, $pool ) {
				$type = $args['post_type'] ?? '';
				if ( 'ffc_form' === $type ) {
					return $forms;
				}
				if ( CertTemplateCpt::POST_TYPE === $type ) {
					return $pool;
				}
				return array();
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key, $single = false ) {
				return $this->meta[ "{$id}|{$key}" ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->written[]              = array( (int) $id, (string) $key, $value );
				$this->meta[ "{$id}|{$key}" ] = $value;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return $this->options[ $name ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	/**
	 * The last captured value written for a given post id + meta key.
	 *
	 * @param int    $id  Post id.
	 * @param string $key Meta key.
	 * @return mixed
	 */
	private function last_written( int $id, string $key ) {
		$found = null;
		foreach ( $this->written as $row ) {
			if ( $row[0] === $id && $row[1] === $key ) {
				$found = $row[2];
			}
		}
		return $found;
	}

	public function test_calculate_status_counts_posts_with_marker(): void {
		$this->put_file( 'default_background_certificate_1.png' );
		$this->wire(
			array( 10 ),
			array( 20 ),
			array(
				'10|_ffc_form_config'  => array(
					'pdf_layout' => '<img src="' . $this->html_url( 'default_background_certificate_1.png' ) . '">',
					'bg_image'   => '',
				),
				'10|_ffc_form_bg'      => '',
				'20|_ffc_template_html' => '<div>no marker here</div>',
			)
		);

		$strategy = new TestableRewriteHtmlImageRefsMigrationStrategy( $this->dir );
		$status   = $strategy->calculate_status( 'rewrite_html_image_refs', array() );

		$this->assertSame( 2, $status['total'] );
		$this->assertSame( 1, $status['pending'] );
		$this->assertSame( 1, $status['migrated'] );
		$this->assertFalse( $status['is_complete'] );
	}

	public function test_execute_rewrites_all_surfaces_and_dedups_sideload(): void {
		$this->put_file( 'default_background_certificate_1.png' );
		$this->put_file( 'default_assinagture_1.png' );

		$bg  = $this->html_url( 'default_background_certificate_1.png' );
		$sig = $this->html_url( 'default_assinagture_1.png' );

		$this->wire(
			array( 10 ),
			array( 20 ),
			array(
				// pdf_layout references bg twice (dedup) + bg_image references the same bg (dedup across fields).
				'10|_ffc_form_config'  => array(
					'pdf_layout' => '<img src="' . $bg . '"><img src="' . $bg . '">',
					'bg_image'   => $bg,
				),
				// top-level bg meta references the signature (same file the pool references → dedup across posts).
				'10|_ffc_form_bg'      => $sig,
				'20|_ffc_template_html' => '<img src="https://site.example' . $sig . '">',
			)
		);

		$strategy = new TestableRewriteHtmlImageRefsMigrationStrategy( $this->dir );
		$result   = $strategy->execute( 'rewrite_html_image_refs', array( 'batch_size' => 10 ) );

		$this->assertSame( 2, $result['processed'] );
		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertFalse( $result['has_more'] );

		// Each distinct file side-loaded exactly once despite four references.
		sort( $strategy->sideloaded );
		$this->assertSame(
			array( 'default_assinagture_1.png', 'default_background_certificate_1.png' ),
			$strategy->sideloaded
		);

		// No rewritten surface still carries the legacy marker.
		$config = $this->last_written( 10, '_ffc_form_config' );
		$this->assertIsArray( $config );
		$this->assertStringNotContainsString( 'ffcertificate/html/', (string) $config['pdf_layout'] );
		$this->assertStringNotContainsString( 'ffcertificate/html/', (string) $config['bg_image'] );
		$this->assertStringContainsString( 'https://media.example/uploads/default_background_certificate_1.png', (string) $config['pdf_layout'] );

		$this->assertSame( 'https://media.example/uploads/default_assinagture_1.png', $this->last_written( 10, '_ffc_form_bg' ) );
		$this->assertStringNotContainsString( 'ffcertificate/html/', (string) $this->last_written( 20, CertTemplateCpt::META_HTML ) );

		// Media map + processed sets persisted.
		$this->assertArrayHasKey( 'ffc_html_migrated_media', $this->options );
		$this->assertContains( 'form:10', $this->options['ffc_html_rewrite_processed'] );
		$this->assertContains( 'pool:20', $this->options['ffc_html_rewrite_processed'] );
	}

	public function test_missing_file_reported_as_error_and_terminates(): void {
		// No file created in the temp dir → the reference is unresolvable.
		$this->wire(
			array( 10 ),
			array(),
			array(
				'10|_ffc_form_config' => array(
					'pdf_layout' => '<img src="' . $this->html_url( 'gone.png' ) . '">',
					'bg_image'   => '',
				),
				'10|_ffc_form_bg'     => '',
			)
		);

		$strategy = new TestableRewriteHtmlImageRefsMigrationStrategy( $this->dir );
		$result   = $strategy->execute( 'rewrite_html_image_refs', array() );

		$this->assertSame( 0, $result['processed'] );
		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertEmpty( $strategy->sideloaded );
		// Nothing was rewritten.
		$this->assertNull( $this->last_written( 10, '_ffc_form_config' ) );

		// Visited once → no longer pending → terminates.
		$status = $strategy->calculate_status( 'rewrite_html_image_refs', array() );
		$this->assertTrue( $status['is_complete'] );
	}

	public function test_never_touches_assets_references(): void {
		$this->put_file( 'default_background_certificate_1.png' );
		$assets = '/wp-content/plugins/ffcertificate/assets/img/certificate-defaults/default_background_certificate_1.png';
		$this->wire(
			array( 10 ),
			array(),
			array(
				'10|_ffc_form_config' => array(
					'pdf_layout' => '<img src="' . $assets . '">',
					'bg_image'   => '',
				),
				'10|_ffc_form_bg'     => '',
			)
		);

		$strategy = new TestableRewriteHtmlImageRefsMigrationStrategy( $this->dir );
		$status   = $strategy->calculate_status( 'rewrite_html_image_refs', array() );
		$this->assertSame( 0, $status['pending'] );

		$result = $strategy->execute( 'rewrite_html_image_refs', array() );
		$this->assertSame( 0, $result['processed'] );
		$this->assertEmpty( $strategy->sideloaded );
		$this->assertNull( $this->last_written( 10, '_ffc_form_config' ) );
	}

	public function test_default_pool_templates_are_never_sideloaded(): void {
		// A shipped default carrying a stale legacy /html/ ref must be skipped
		// (#865): defaults are repaired by re-seeding (SEED_VERSION bump), never
		// sideloaded into the Media Library. Guards the seed/migration bug where
		// a v1-seeded default's html/ ref got copied into wp-content/uploads.
		$this->put_file( 'default_background_certificate_1.png' );
		$bg = $this->html_url( 'default_background_certificate_1.png' );
		$this->wire(
			array(),
			array( 20 ),
			array(
				'20|' . CertTemplateCpt::META_HTML       => '<img src="' . $bg . '">',
				'20|' . CertTemplateCpt::META_IS_DEFAULT => '1',
			)
		);

		$strategy = new TestableRewriteHtmlImageRefsMigrationStrategy( $this->dir );

		// The default is not a target at all → nothing to migrate.
		$status = $strategy->calculate_status( 'rewrite_html_image_refs', array() );
		$this->assertSame( 0, $status['total'] );
		$this->assertSame( 0, $status['pending'] );

		// And execute never sideloads or rewrites it.
		$result = $strategy->execute( 'rewrite_html_image_refs', array() );
		$this->assertSame( 0, $result['processed'] );
		$this->assertEmpty( $strategy->sideloaded );
		$this->assertNull( $this->last_written( 20, CertTemplateCpt::META_HTML ) );
	}

	public function test_idempotent_when_post_already_processed(): void {
		$this->put_file( 'default_background_certificate_1.png' );
		$this->wire(
			array( 10 ),
			array(),
			array(
				'10|_ffc_form_config' => array(
					'pdf_layout' => '<img src="' . $this->html_url( 'default_background_certificate_1.png' ) . '">',
					'bg_image'   => '',
				),
				'10|_ffc_form_bg'     => '',
			)
		);
		$this->options['ffc_html_rewrite_processed'] = array( 'form:10' );

		$strategy = new TestableRewriteHtmlImageRefsMigrationStrategy( $this->dir );
		$result   = $strategy->execute( 'rewrite_html_image_refs', array() );

		$this->assertSame( 0, $result['processed'] );
		$this->assertEmpty( $strategy->sideloaded );
		$this->assertNull( $this->last_written( 10, '_ffc_form_config' ) );
	}

	public function test_can_run_true_and_name_nonempty(): void {
		$strategy = new TestableRewriteHtmlImageRefsMigrationStrategy( $this->dir );
		$this->assertTrue( $strategy->can_run( 'rewrite_html_image_refs', array() ) );
		$this->assertNotSame( '', $strategy->get_name() );
	}

	public function test_a_reference_that_escapes_the_folder_is_refused_not_resolved(): void {
		$this->put_file( 'ok.png' );
		$escape = '/wp-content/plugins/ffcertificate/html/../wp-config.php';
		$this->wire(
			array(),
			array( 20 ),
			array( '20|' . CertTemplateCpt::META_HTML => '<img src="' . $escape . '">' )
		);

		$strategy = new TestableRewriteHtmlImageRefsMigrationStrategy( $this->dir );
		$result   = $strategy->execute( 'rewrite_html_image_refs', array() );

		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( array(), $strategy->sideloaded, 'A traversal must never reach the filesystem.' );
		$this->assertNull( $this->last_written( 20, CertTemplateCpt::META_HTML ) );
		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'Unrecognized html/ reference', $result['errors'][0] );
		$this->assertStringContainsString( 'template#20', $result['errors'][0] );
	}

	public function test_a_failed_import_is_reported_and_leaves_the_reference(): void {
		$this->put_file( 'bg.png' );
		$this->wire(
			array( 10 ),
			array(),
			array(
				'10|_ffc_form_config' => array( 'pdf_layout' => '', 'bg_image' => '' ),
				'10|_ffc_form_bg'     => $this->html_url( 'bg.png' ),
			)
		);

		$strategy = new class( $this->dir ) extends RewriteHtmlImageRefsMigrationStrategy {
			protected function sideload_resolved_file( string $src_path, string $basename ): string {
				return '';
			}
		};
		$result = $strategy->execute( 'rewrite_html_image_refs', array() );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Failed to import html/ file "bg.png"', $result['errors'][0] );
		$this->assertNull( $this->last_written( 10, '_ffc_form_bg' ) );
	}

	public function test_a_batch_stops_at_its_size_and_reports_more(): void {
		$this->put_file( 'bg.png' );
		$ref = '<img src="' . $this->html_url( 'bg.png' ) . '">';
		$this->wire(
			array( 10, 11 ),
			array(),
			array(
				'10|_ffc_form_config' => array( 'pdf_layout' => $ref, 'bg_image' => '' ),
				'11|_ffc_form_config' => array( 'pdf_layout' => $ref, 'bg_image' => '' ),
			)
		);

		$strategy = new TestableRewriteHtmlImageRefsMigrationStrategy( $this->dir );
		$result   = $strategy->execute( 'rewrite_html_image_refs', array( 'batch_size' => 1 ) );

		$this->assertSame( 1, $result['processed'] );
		$this->assertTrue( $result['has_more'] );
		$this->assertSame( array( 'form:10' ), $this->options['ffc_html_rewrite_processed'] );
	}

	public function test_a_file_already_in_the_media_map_is_reused_and_malformed_state_is_ignored(): void {
		$this->put_file( 'bg.png' );
		$this->wire(
			array( 10 ),
			array(),
			array(
				'10|_ffc_form_config' => array( 'pdf_layout' => '', 'bg_image' => '' ),
				'10|_ffc_form_bg'     => $this->html_url( 'bg.png' ),
			)
		);
		$this->options['ffc_html_rewrite_processed'] = 'not-a-list';
		$this->options['ffc_html_migrated_media']    = array(
			'bg.png' => 'https://media.example/uploads/already.png',
			7        => 'https://media.example/uploads/numeric-key.png',
			'x.png'  => array( 'not a url' ),
		);

		$strategy = new TestableRewriteHtmlImageRefsMigrationStrategy( $this->dir );
		$result   = $strategy->execute( 'rewrite_html_image_refs', array() );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( array(), $strategy->sideloaded, 'A file the map already holds is never imported twice.' );
		$this->assertSame( 'https://media.example/uploads/already.png', $this->last_written( 10, '_ffc_form_bg' ) );
		$this->assertSame(
			array( 'bg.png' => 'https://media.example/uploads/already.png' ),
			$this->options['ffc_html_migrated_media'],
			'Malformed map entries are dropped when the map is read back.'
		);
	}

	public function test_a_non_array_media_map_reads_as_empty(): void {
		$this->options['ffc_html_migrated_media'] = 'corrupt';
		$strategy                                 = new TestableRewriteHtmlImageRefsMigrationStrategy( $this->dir );

		$read = new \ReflectionMethod( RewriteHtmlImageRefsMigrationStrategy::class, 'media_map' );
		$read->setAccessible( true );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return $this->options[ $name ] ?? $default;
			}
		);

		$this->assertSame( array(), $read->invoke( $strategy ) );
	}

	// ------------------------------------------------------------------
	// sideload_resolved_file() — the real Media Library hand-off
	// ------------------------------------------------------------------

	/**
	 * Call the production sideload with WordPress's media API stubbed.
	 *
	 * @param string $src  Source file.
	 * @param mixed  $tmp  What wp_tempnam returns.
	 * @param mixed  $id   What media_handle_sideload returns.
	 * @param array  $deleted Collected wp_delete_file paths.
	 * @return string
	 */
	private function sideload( string $src, $tmp, $id, array &$deleted ): string {
		Functions\when( 'media_handle_sideload' )->justReturn( $id );
		Functions\when( 'wp_tempnam' )->justReturn( $tmp );
		Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );
		Functions\when( 'wp_get_attachment_url' )->alias( static fn( $aid ) => 'https://media.example/?attachment_id=' . $aid );
		Functions\when( 'wp_delete_file' )->alias(
			static function ( $path ) use ( &$deleted ) {
				$deleted[] = $path;
				if ( is_file( $path ) ) {
					unlink( $path );
				}
			}
		);

		$strategy = new RewriteHtmlImageRefsMigrationStrategy( $this->dir );
		$method   = new \ReflectionMethod( $strategy, 'sideload_resolved_file' );
		$method->setAccessible( true );

		return (string) $method->invoke( $strategy, $src, basename( $src ) );
	}

	public function test_sideload_returns_the_attachment_url(): void {
		$this->put_file( 'logo.png' );
		$deleted = array();

		$url = $this->sideload( $this->dir . 'logo.png', $this->dir . 'tmp-logo', 42, $deleted );

		$this->assertSame( 'https://media.example/?attachment_id=42', $url );
		$this->assertSame( array(), $deleted, 'A successful sideload owns the temp file; nothing is deleted here.' );
		$this->assertSame( 'PNGDATA', file_get_contents( $this->dir . 'tmp-logo' ) );
	}

	public function test_sideload_gives_up_without_a_temp_file(): void {
		$this->put_file( 'logo.png' );
		$deleted = array();

		$this->assertSame( '', $this->sideload( $this->dir . 'logo.png', false, 42, $deleted ) );
	}

	public function test_sideload_cleans_up_when_the_copy_fails(): void {
		$deleted = array();

		$this->assertSame( '', $this->sideload( $this->dir . 'absent.png', $this->dir . 'tmp-absent', 42, $deleted ) );
		$this->assertSame( array( $this->dir . 'tmp-absent' ), $deleted );
	}

	public function test_sideload_cleans_up_when_the_media_library_refuses(): void {
		$this->put_file( 'logo.png' );
		$deleted = array();

		$this->assertSame(
			'',
			$this->sideload( $this->dir . 'logo.png', $this->dir . 'tmp-refused', new \WP_Error( 'upload_error', 'No.' ), $deleted )
		);
		$this->assertSame( array( $this->dir . 'tmp-refused' ), $deleted );
	}
}
