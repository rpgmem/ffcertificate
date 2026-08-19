<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CertTemplateFichaResolver;
use FreeFormCertificate\Admin\CertTemplateCpt;

/**
 * Tests for the global reregistration-ficha template resolver (#951 phase 2).
 *
 * @covers \FreeFormCertificate\Admin\CertTemplateFichaResolver
 */
class CertTemplateFichaResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Admin\\CertTemplateFichaResolver' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub the option + a pool template's kind/html.
	 *
	 * @param int    $selected The selected id stored in the option.
	 * @param string $kind     The template's kind meta.
	 * @param string $html     The template's HTML.
	 * @return void
	 */
	private function prime( int $selected, string $kind, string $html ): void {
		Functions\when( 'get_option' )->justReturn( $selected );
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

	public function test_resolve_returns_the_selected_ficha_html(): void {
		$this->prime( 42, CertTemplateCpt::KIND_FICHA, '<div>ficha</div>' );

		$this->assertSame( '<div>ficha</div>', ( new CertTemplateFichaResolver() )->resolve( '' ) );
	}

	public function test_resolve_falls_back_when_nothing_selected(): void {
		$this->prime( 0, CertTemplateCpt::KIND_FICHA, '<div>ficha</div>' );

		$this->assertSame( '', ( new CertTemplateFichaResolver() )->resolve( '' ) );
	}

	public function test_resolve_ignores_a_non_ficha_template(): void {
		// Stale id points at a certificate template → not honoured.
		$this->prime( 7, CertTemplateCpt::KIND_CERTIFICATE, '<div>cert</div>' );

		$this->assertSame( '', ( new CertTemplateFichaResolver() )->resolve( '' ) );
	}

	public function test_resolve_respects_html_another_listener_supplied(): void {
		$this->prime( 42, CertTemplateCpt::KIND_FICHA, '<div>ficha</div>' );

		$this->assertSame( '<div>other</div>', ( new CertTemplateFichaResolver() )->resolve( '<div>other</div>' ) );
	}

	public function test_selected_id_reads_the_option(): void {
		Functions\when( 'get_option' )->justReturn( 9 );
		$this->assertSame( 9, CertTemplateFichaResolver::selected_id() );

		Functions\when( 'get_option' )->justReturn( array( 'not', 'scalar' ) );
		$this->assertSame( 0, CertTemplateFichaResolver::selected_id() );
	}
}
