<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CertTemplateReader;
use FreeFormCertificate\Admin\CertTemplateCpt;

/**
 * Tests for the certificate-template pool read side (#865).
 *
 * @covers \FreeFormCertificate\Admin\CertTemplateReader
 */
class CertTemplateReaderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Admin\\CertTemplateReader' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function fake_post( int $id, string $title, string $type = CertTemplateCpt::POST_TYPE ): \WP_Post {
		$post             = new \WP_Post();
		$post->ID         = $id;
		$post->post_title = $title;
		$post->post_type  = $type;
		return $post;
	}

	public function test_list_for_editor_groups_defaults_first(): void {
		// Query returns them title-sorted; the reader must still float defaults up.
		$posts = array(
			$this->fake_post( 1, 'Alpha (mine)' ),
			$this->fake_post( 2, 'Zeta default' ),
		);
		Functions\when( 'get_posts' )->justReturn( $posts );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) {
				if ( CertTemplateCpt::META_IS_DEFAULT === $key ) {
					return 2 === $id ? '1' : '';
				}
				return '';
			}
		);

		$list = CertTemplateReader::list_for_editor();

		$this->assertCount( 2, $list );
		$this->assertTrue( $list[0]['is_default'] );
		$this->assertSame( 2, $list[0]['id'] );
		$this->assertSame( 'Zeta default', $list[0]['label'] );
		$this->assertFalse( $list[1]['is_default'] );
		$this->assertSame( 1, $list[1]['id'] );
	}

	public function test_list_for_editor_certificate_kind_allows_absent_meta(): void {
		$captured = null;
		Functions\when( 'get_posts' )->alias(
			static function ( $args ) use ( &$captured ) {
				$captured = $args;
				return array();
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );

		CertTemplateReader::list_for_editor(); // default kind = certificate

		// The kind clause must match `certificate` OR a missing meta (#945 retrocompat).
		$kind_clause = $captured['meta_query'][1];
		$this->assertSame( 'OR', $kind_clause['relation'] );
		$this->assertSame( CertTemplateCpt::KIND_CERTIFICATE, $kind_clause[0]['value'] );
		$this->assertSame( 'NOT EXISTS', $kind_clause[1]['compare'] );
	}

	public function test_list_for_editor_receipt_kind_is_exact_match(): void {
		$captured = null;
		Functions\when( 'get_posts' )->alias(
			static function ( $args ) use ( &$captured ) {
				$captured = $args;
				return array();
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );

		CertTemplateReader::list_for_editor( CertTemplateCpt::KIND_APPOINTMENT_RECEIPT );

		$kind_clause = $captured['meta_query'][1];
		$this->assertSame( CertTemplateCpt::META_KIND, $kind_clause['key'] );
		$this->assertSame( CertTemplateCpt::KIND_APPOINTMENT_RECEIPT, $kind_clause['value'] );
	}

	public function test_get_kind_absent_meta_defaults_to_certificate(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->assertSame( CertTemplateCpt::KIND_CERTIFICATE, CertTemplateReader::get_kind( 5 ) );
	}

	public function test_get_kind_returns_stored_meta(): void {
		Functions\when( 'get_post_meta' )->justReturn( CertTemplateCpt::KIND_APPOINTMENT_RECEIPT );
		$this->assertSame( CertTemplateCpt::KIND_APPOINTMENT_RECEIPT, CertTemplateReader::get_kind( 5 ) );
	}

	public function test_get_kind_nonpositive_id_defaults_to_certificate(): void {
		$this->assertSame( CertTemplateCpt::KIND_CERTIFICATE, CertTemplateReader::get_kind( 0 ) );
	}

	public function test_get_html_returns_meta_for_pool_post(): void {
		Functions\when( 'get_post' )->justReturn( $this->fake_post( 5, 'X' ) );
		Functions\when( 'get_post_meta' )->justReturn( '<div>hi</div>' );

		$this->assertSame( '<div>hi</div>', CertTemplateReader::get_html( 5 ) );
	}

	public function test_get_html_returns_empty_for_wrong_post_type(): void {
		Functions\when( 'get_post' )->justReturn( $this->fake_post( 5, 'X', 'page' ) );

		$this->assertSame( '', CertTemplateReader::get_html( 5 ) );
	}

	public function test_get_html_returns_empty_for_nonpositive_id(): void {
		$this->assertSame( '', CertTemplateReader::get_html( 0 ) );
	}

	public function test_is_default_true_when_meta_set(): void {
		Functions\when( 'get_post_meta' )->justReturn( '1' );
		$this->assertTrue( CertTemplateReader::is_default( 9 ) );
	}

	public function test_is_default_false_when_meta_absent(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->assertFalse( CertTemplateReader::is_default( 9 ) );
	}

	public function test_is_default_false_for_nonpositive_id(): void {
		$this->assertFalse( CertTemplateReader::is_default( 0 ) );
	}

	public function test_get_bg_image_returns_meta_for_pool_post(): void {
		Functions\when( 'get_post' )->justReturn( $this->fake_post( 5, 'X' ) );
		Functions\when( 'get_post_meta' )->justReturn( 'https://example.com/bg.png' );

		$this->assertSame( 'https://example.com/bg.png', CertTemplateReader::get_bg_image( 5 ) );
	}

	public function test_get_bg_image_returns_empty_for_wrong_post_type(): void {
		Functions\when( 'get_post' )->justReturn( $this->fake_post( 5, 'X', 'page' ) );

		$this->assertSame( '', CertTemplateReader::get_bg_image( 5 ) );
	}

	public function test_get_bg_image_returns_empty_for_nonpositive_id(): void {
		$this->assertSame( '', CertTemplateReader::get_bg_image( 0 ) );
	}
}
