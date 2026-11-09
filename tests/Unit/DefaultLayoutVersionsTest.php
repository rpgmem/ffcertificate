<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\DefaultLayoutVersions;

/**
 * A form whose layout is an earlier body of a default model is offered the
 * current one, and only then (#2577).
 *
 * @covers \FreeFormCertificate\Admin\DefaultLayoutVersions
 */
class DefaultLayoutVersionsTest extends TestCase {

	/**
	 * SHA-1 of each default certificate body as it ships now. A changed body
	 * fails here until the body it replaces is archived under
	 * `templates/certificate-defaults/previous/` as `<slug>-<version>.html`
	 * (the version it was released in) and this hash is updated, so a form on
	 * the replaced body is still offered the new one.
	 */
	private const CURRENT = array(
		'default_certificate_1' => 'd1631dd91a505a188814c12b37b1f3afcb6b931b',
		'default_certificate_2' => 'd3e3336c741cb988093b8d61ee1d0ed5d5c9ea51',
		'default_certificate_3' => '88c39ef290e5f41d464c765f065c04f1acd7f2d7',
		'default_certificate_4' => '32404bb51d1765ceb459924fc6873093a59ce015',
		'default_certificate_5' => '9c1096f7a009ade70b4452b11a2e8c66fbab6214',
		'default_certificate_6' => 'b4c8b687ce745f0b5f8ad81fa1baaa54aac2d1b4',
	);

	/** @var int Pool id the default lookup answers; 0 means the pool lacks it. */
	private int $pool_id = 42;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'get_the_title' )->justReturn( 'Certificate model 2' );
		Functions\when( 'get_posts' )->alias( fn() => $this->pool_id > 0 ? array( $this->pool_id ) : array() );
		DefaultLayoutVersions::reset();
	}

	protected function tearDown(): void {
		DefaultLayoutVersions::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function archived_body( string $name ): string {
		return (string) file_get_contents( FFC_PLUGIN_DIR . DefaultLayoutVersions::PREVIOUS_DIR . $name );
	}

	private function current_body( string $slug ): string {
		return (string) file_get_contents( FFC_PLUGIN_DIR . 'templates/certificate-defaults/' . $slug . '.html' );
	}

	private function notice( string $layout ): string {
		ob_start();
		DefaultLayoutVersions::render_notice( $layout );
		return (string) ob_get_clean();
	}

	public function test_an_archived_body_is_recognised_even_reindented(): void {
		$body = $this->archived_body( 'default_certificate_2-6.44.1.html' );

		$this->assertSame( 'default_certificate_2', DefaultLayoutVersions::earlier_default_of( $body ) );
		$this->assertSame(
			'default_certificate_2',
			DefaultLayoutVersions::earlier_default_of( "\n\n" . str_replace( "\n        ", "\n\t", $body ) ),
			'the code editor may change indentation; whitespace does not make a copy different'
		);
	}

	public function test_the_current_body_an_edited_copy_and_an_empty_layout_are_not_earlier_bodies(): void {
		$this->assertSame( '', DefaultLayoutVersions::earlier_default_of( $this->current_body( 'default_certificate_2' ) ) );
		$edited = str_replace( '#803063', '#123456', $this->archived_body( 'default_certificate_2-6.44.1.html' ) );
		$this->assertSame( '', DefaultLayoutVersions::earlier_default_of( $edited ), 'a customised copy is never offered an overwrite' );
		$this->assertSame( '', DefaultLayoutVersions::earlier_default_of( "  \n" ) );
	}

	public function test_the_notice_carries_the_pool_default_on_its_button(): void {
		$html = $this->notice( $this->archived_body( 'default_certificate_2-6.42.0.html' ) );

		$this->assertStringContainsString( 'ffc-layout-update-notice', $html );
		$this->assertStringContainsString( 'earlier version of Certificate model 2', $html );
		$this->assertStringContainsString( 'id="ffc_update_default_layout_btn"', $html );
		$this->assertStringContainsString( 'data-template-id="42"', $html );
		$this->assertStringContainsString( 'data-template-name="Certificate model 2"', $html );
	}

	public function test_no_notice_for_a_current_copy_or_when_the_pool_lost_the_default(): void {
		$this->assertSame( '', $this->notice( $this->current_body( 'default_certificate_2' ) ) );

		$this->pool_id = 0;
		$this->assertSame( '', $this->notice( $this->archived_body( 'default_certificate_2-6.42.0.html' ) ) );
	}

	public function test_a_changed_default_body_is_archived_before_it_ships(): void {
		foreach ( self::CURRENT as $slug => $hash ) {
			$this->assertSame(
				$hash,
				sha1( $this->current_body( $slug ) ),
				"{$slug}.html changed: archive the body it replaces under " . DefaultLayoutVersions::PREVIOUS_DIR . " as {$slug}-<version it shipped in>.html, then update this hash."
			);
		}
	}

	public function test_every_archived_body_belongs_to_a_default_and_differs_from_it(): void {
		$files = glob( FFC_PLUGIN_DIR . DefaultLayoutVersions::PREVIOUS_DIR . '*' );
		$this->assertIsArray( $files );
		$archived = DefaultLayoutVersions::archived();
		$this->assertCount( count( $files ), $archived, 'every file in the archive is named <slug>-<version>.html' );

		$seen = array();
		foreach ( $archived as $file => $slug ) {
			$this->assertArrayHasKey( $slug, self::CURRENT, basename( $file ) . ' names no shipped default' );
			$this->assertNotSame(
				trim( (string) preg_replace( '/\s+/', ' ', (string) file_get_contents( $file ) ) ),
				trim( (string) preg_replace( '/\s+/', ' ', $this->current_body( $slug ) ) ),
				basename( $file ) . ' is the current body, so a current copy would be offered itself'
			);
			$seen[ $slug ] = true;
		}
		$this->assertSame( array_keys( self::CURRENT ), array_keys( array_intersect_key( self::CURRENT, $seen ) ), 'each model keeps its earlier bodies' );
	}
}
