<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CertTemplateReceiptResolver;
use FreeFormCertificate\Admin\CertTemplateCpt;

/**
 * Tests for the per-mode appointment-receipt template resolver (#945).
 *
 * @covers \FreeFormCertificate\Admin\CertTemplateReceiptResolver
 */
class CertTemplateReceiptResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Admin\\CertTemplateReceiptResolver' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub the option + a pool template's kind/html.
	 *
	 * @param array<string, int> $option    The selection option value.
	 * @param string             $kind      The template's kind meta.
	 * @param string             $html      The template's HTML.
	 * @return void
	 */
	private function prime( array $option, string $kind, string $html ): void {
		Functions\when( 'get_option' )->justReturn( $option );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) use ( $kind, $html ) {
				if ( CertTemplateCpt::META_KIND === $key ) {
					return $kind;
				}
				if ( CertTemplateCpt::META_HTML === $key ) {
					return $html;
				}
				return '';
			}
		);
		$post            = new \WP_Post();
		$post->post_type = CertTemplateCpt::POST_TYPE;
		Functions\when( 'get_post' )->justReturn( $post );
	}

	public function test_resolve_returns_pool_html_for_custom_mode(): void {
		$this->prime(
			array( 'regular' => 0, 'custom' => 42 ),
			CertTemplateCpt::KIND_APPOINTMENT_RECEIPT,
			'<div>custom receipt</div>'
		);

		$resolver = new CertTemplateReceiptResolver();
		$this->assertSame( '<div>custom receipt</div>', $resolver->resolve( '', 'custom' ) );
	}

	public function test_resolve_returns_pool_html_for_regular_mode(): void {
		$this->prime(
			array( 'regular' => 7, 'custom' => 0 ),
			CertTemplateCpt::KIND_APPOINTMENT_RECEIPT,
			'<div>regular receipt</div>'
		);

		$resolver = new CertTemplateReceiptResolver();
		$this->assertSame( '<div>regular receipt</div>', $resolver->resolve( '', 'regular' ) );
	}

	public function test_resolve_falls_through_when_unconfigured(): void {
		Functions\when( 'get_option' )->justReturn( array( 'regular' => 0, 'custom' => 0 ) );

		$resolver = new CertTemplateReceiptResolver();
		$this->assertSame( '', $resolver->resolve( '', 'custom' ) );
	}

	public function test_resolve_ignores_id_of_wrong_kind(): void {
		// The selected id points at a certificate template, not a receipt one.
		$this->prime(
			array( 'regular' => 0, 'custom' => 9 ),
			CertTemplateCpt::KIND_CERTIFICATE,
			'<div>a certificate</div>'
		);

		$resolver = new CertTemplateReceiptResolver();
		$this->assertSame( '', $resolver->resolve( '', 'custom' ) );
	}

	public function test_resolve_respects_html_already_supplied(): void {
		// Another listener already chose a template — do not override it.
		Functions\expect( 'get_option' )->never();

		$resolver = new CertTemplateReceiptResolver();
		$this->assertSame( '<div>prior</div>', $resolver->resolve( '<div>prior</div>', 'custom' ) );
	}

	public function test_selected_id_reads_option_per_mode(): void {
		Functions\when( 'get_option' )->justReturn( array( 'regular' => 3, 'custom' => 8 ) );

		$this->assertSame( 3, CertTemplateReceiptResolver::selected_id( 'regular' ) );
		$this->assertSame( 8, CertTemplateReceiptResolver::selected_id( 'custom' ) );
	}

	public function test_selected_id_zero_when_option_missing(): void {
		Functions\when( 'get_option' )->justReturn( false );
		$this->assertSame( 0, CertTemplateReceiptResolver::selected_id( 'regular' ) );
	}
}
