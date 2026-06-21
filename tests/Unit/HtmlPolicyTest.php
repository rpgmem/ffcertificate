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
}
