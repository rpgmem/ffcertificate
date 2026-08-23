<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CertTemplateWriter;
use FreeFormCertificate\Admin\CertTemplateCpt;

/**
 * Tests for the certificate-template pool write side (#865).
 *
 * @covers \FreeFormCertificate\Admin\CertTemplateWriter
 */
class CertTemplateWriterTest extends TestCase {

	/** @var array<int, array{id:int, key:string, value:mixed}> */
	private array $meta_writes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Admin\\CertTemplateWriter' );

		$this->meta_writes = array();
		$writes            = &$this->meta_writes;
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$writes ) {
				$writes[] = array(
					'id'    => $id,
					'key'   => $key,
					'value' => $value,
				);
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function pool_post( int $id ): \WP_Post {
		$post            = new \WP_Post();
		$post->ID        = $id;
		$post->post_type = CertTemplateCpt::POST_TYPE;
		return $post;
	}

	/**
	 * Collect the meta value written for a given key (last write wins).
	 */
	private function written( string $key ): mixed {
		$value = null;
		foreach ( $this->meta_writes as $w ) {
			if ( $key === $w['key'] ) {
				$value = $w['value'];
			}
		}
		return $value;
	}

	public function test_create_inserts_post_and_seeds_meta(): void {
		Functions\when( 'wp_insert_post' )->justReturn( 77 );

		$id = CertTemplateWriter::create( 'My model', '<div>Body</div>' );

		$this->assertSame( 77, $id );
		$this->assertSame( '<div>Body</div>', $this->written( CertTemplateCpt::META_HTML ) );
		$this->assertSame( '1', $this->written( CertTemplateCpt::META_VISIBLE ) );
		// A user template is never a shipped default.
		$this->assertSame( '0', $this->written( CertTemplateCpt::META_IS_DEFAULT ) );
		// Defaults to the certificate kind when not specified (#945).
		$this->assertSame( CertTemplateCpt::KIND_CERTIFICATE, $this->written( CertTemplateCpt::META_KIND ) );
	}

	public function test_create_stores_the_given_kind(): void {
		Functions\when( 'wp_insert_post' )->justReturn( 79 );

		CertTemplateWriter::create( 'Receipt', '<div>r</div>', true, '', CertTemplateCpt::KIND_APPOINTMENT_RECEIPT );

		$this->assertSame( CertTemplateCpt::KIND_APPOINTMENT_RECEIPT, $this->written( CertTemplateCpt::META_KIND ) );
	}

	public function test_create_honours_visible_false(): void {
		Functions\when( 'wp_insert_post' )->justReturn( 78 );

		CertTemplateWriter::create( 'Hidden', '<p>x</p>', false );

		$this->assertSame( '0', $this->written( CertTemplateCpt::META_VISIBLE ) );
	}

	public function test_create_returns_zero_on_wp_error(): void {
		// create() only checks is_int(); wp_insert_post( …, true ) returns a
		// WP_Error on failure — any non-int stands in for that here.
		Functions\when( 'wp_insert_post' )->justReturn( new \stdClass() );

		$id = CertTemplateWriter::create( 'Broken', '<p>x</p>' );

		$this->assertSame( 0, $id );
		$this->assertSame( array(), $this->meta_writes, 'no meta written when the insert fails' );
	}

	public function test_set_visibility_updates_pool_post(): void {
		Functions\when( 'get_post' )->justReturn( $this->pool_post( 5 ) );

		$this->assertTrue( CertTemplateWriter::set_visibility( 5, false ) );
		$this->assertSame( '0', $this->written( CertTemplateCpt::META_VISIBLE ) );
	}

	public function test_set_visibility_rejects_non_pool_post(): void {
		$other            = new \WP_Post();
		$other->ID        = 9;
		$other->post_type = 'page';
		Functions\when( 'get_post' )->justReturn( $other );

		$this->assertFalse( CertTemplateWriter::set_visibility( 9, true ) );
		$this->assertSame( array(), $this->meta_writes );
	}

	public function test_set_visibility_rejects_nonpositive_id(): void {
		$this->assertFalse( CertTemplateWriter::set_visibility( 0, true ) );
		$this->assertSame( array(), $this->meta_writes );
	}

	public function test_set_kind_updates_pool_post(): void {
		Functions\when( 'get_post' )->justReturn( $this->pool_post( 5 ) );

		$this->assertTrue( CertTemplateWriter::set_kind( 5, CertTemplateCpt::KIND_APPOINTMENT_RECEIPT ) );
		$this->assertSame( CertTemplateCpt::KIND_APPOINTMENT_RECEIPT, $this->written( CertTemplateCpt::META_KIND ) );
	}

	public function test_set_kind_rejects_unknown_kind(): void {
		Functions\when( 'get_post' )->justReturn( $this->pool_post( 5 ) );

		$this->assertFalse( CertTemplateWriter::set_kind( 5, 'bogus' ) );
		$this->assertSame( array(), $this->meta_writes );
	}

	public function test_set_kind_rejects_non_pool_post(): void {
		$other            = new \WP_Post();
		$other->ID        = 9;
		$other->post_type = 'page';
		Functions\when( 'get_post' )->justReturn( $other );

		$this->assertFalse( CertTemplateWriter::set_kind( 9, CertTemplateCpt::KIND_CERTIFICATE ) );
		$this->assertSame( array(), $this->meta_writes );
	}

	public function test_update_html_updates_pool_post(): void {
		Functions\when( 'get_post' )->justReturn( $this->pool_post( 12 ) );

		$this->assertTrue( CertTemplateWriter::update_html( 12, '<h1>New</h1>' ) );
		$this->assertSame( '<h1>New</h1>', $this->written( CertTemplateCpt::META_HTML ) );
	}

	public function test_update_html_rejects_missing_post(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$this->assertFalse( CertTemplateWriter::update_html( 404, '<h1>x</h1>' ) );
		$this->assertSame( array(), $this->meta_writes );
	}

	public function test_create_stores_background_image(): void {
		Functions\when( 'wp_insert_post' )->justReturn( 79 );

		CertTemplateWriter::create( 'With bg', '<p>x</p>', true, 'https://example.com/bg.png' );

		$this->assertSame( 'https://example.com/bg.png', $this->written( CertTemplateCpt::META_BG_IMAGE ) );
	}

	public function test_create_defaults_background_image_to_empty(): void {
		Functions\when( 'wp_insert_post' )->justReturn( 80 );

		CertTemplateWriter::create( 'No bg', '<p>x</p>' );

		$this->assertSame( '', $this->written( CertTemplateCpt::META_BG_IMAGE ) );
	}

	public function test_set_bg_image_updates_pool_post(): void {
		Functions\when( 'get_post' )->justReturn( $this->pool_post( 15 ) );

		$this->assertTrue( CertTemplateWriter::set_bg_image( 15, 'https://example.com/x.png' ) );
		$this->assertSame( 'https://example.com/x.png', $this->written( CertTemplateCpt::META_BG_IMAGE ) );
	}

	public function test_set_bg_image_rejects_non_pool_post(): void {
		$other            = new \WP_Post();
		$other->ID        = 9;
		$other->post_type = 'page';
		Functions\when( 'get_post' )->justReturn( $other );

		$this->assertFalse( CertTemplateWriter::set_bg_image( 9, 'https://x/y.png' ) );
		$this->assertSame( array(), $this->meta_writes );
	}
}
