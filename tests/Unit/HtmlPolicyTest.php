<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\HtmlPolicy;

/**
 * #563 Sprint 5 phase 2 (B1) — unit tests for the HtmlPolicy allow-list
 * extracted from Core\Utils.
 */
class HtmlPolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_allowed_html_tags_returns_expected_tag_set(): void {
		Functions\when( 'apply_filters' )->alias(
			static function () {
				$args = func_get_args();
				return $args[1]; // Return the value being filtered.
			}
		);
		$tags = HtmlPolicy::get_allowed_html_tags();
		$this->assertIsArray( $tags );
		$this->assertArrayHasKey( 'b', $tags );
		$this->assertArrayHasKey( 'table', $tags );
		$this->assertArrayHasKey( 'img', $tags );
		$this->assertArrayHasKey( 'h1', $tags );
		$this->assertArrayHasKey( 'ul', $tags );
	}

	public function test_template_helper_classes_survive_on_images_and_cells(): void {
		// #1574: `class` was stripped from img/td/th, so the helper classes
		// ffc-pdf-core.css publishes for templates could not be used there.
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value ) => $value );
		$tags = HtmlPolicy::get_allowed_html_tags();

		foreach ( array( 'img', 'td', 'th' ) as $tag ) {
			$this->assertArrayHasKey( 'class', $tags[ $tag ], "`class` must be allowed on <{$tag}>." );
		}
		$this->assertArrayNotHasKey( 'onerror', $tags['img'], 'Only the attribute the templates need was added.' );
	}

	public function test_allowed_html_tags_is_filterable(): void {
		Functions\when( 'apply_filters' )->alias(
			static function () {
				$args          = func_get_args();
				$value         = $args[1];
				$value['mark'] = array();
				return $value;
			}
		);
		$tags = HtmlPolicy::get_allowed_html_tags();
		$this->assertArrayHasKey( 'mark', $tags );
	}

	public function test_a_layout_keeps_the_signer_token_in_an_image_address(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$seen = array();
		// wp_kses() reads the text before a colon in an address as a protocol
		// and drops it: that is what it did to {{signer:signature}} (#2584).
		Functions\when( 'wp_kses' )->alias(
			static function ( $html ) use ( &$seen ) {
				$seen[] = $html;
				return preg_replace( '/src="[^":]*:/', 'src="', $html );
			}
		);

		$this->assertSame( '<img src="{{signer:signature}}"><p>{{signer:name}}</p>', HtmlPolicy::kses_layout( '<img src="{{signer:signature}}"><p>{{signer:name}}</p>' ) );
		$this->assertStringNotContainsString( '{{signer:', $seen[0], 'the allow-list never sees the colon' );
		$this->assertSame( '<img src="alert(1)">', HtmlPolicy::kses_layout( '<img src="javascript:alert(1)">' ), 'any other protocol is still the allow-list\'s' );
		foreach ( array( 1, 2, 3 ) as $n ) {
			$this->assertSame( '<img src="{{signer' . $n . ':signature}}">', HtmlPolicy::kses_layout( '<img src="{{signer' . $n . ':signature}}">' ), "signer {$n}'s token keeps its address (#2624)" );
		}
		$this->assertSame( '<img src="signature}}">', HtmlPolicy::kses_layout( '<img src="{{signer4:signature}}">' ), 'a position the form cannot name is no token' );
	}
}
