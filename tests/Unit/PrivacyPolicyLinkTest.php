<?php
/**
 * Tests for the "Privacy Policy" words of a consent sentence (#2537).
 *
 * `get_privacy_policy_url()` returns '' until a privacy page is chosen, and
 * the consent sentences printed it straight into `href`: an empty link that
 * reloads the page in a new tab, as if a policy existed.
 *
 * @covers \FreeFormCertificate\Core\PrivacyPolicyLink
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\PrivacyPolicyLink;
use PHPUnit\Framework\TestCase;

final class PrivacyPolicyLinkTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->alias( static fn( $url ) => str_replace( '"', '&quot;', (string) $url ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_links_the_privacy_page_in_a_new_tab(): void {
		Functions\when( 'get_privacy_policy_url' )->justReturn( 'https://example.com/privacy/' );

		$this->assertSame(
			'<a href="https://example.com/privacy/" target="_blank" rel="noopener">Privacy Policy</a>',
			PrivacyPolicyLink::html()
		);
	}

	public function test_names_the_policy_without_a_link_when_the_site_has_no_privacy_page(): void {
		Functions\when( 'get_privacy_policy_url' )->justReturn( '' );

		$html = PrivacyPolicyLink::html();

		$this->assertSame( 'Privacy Policy', $html );
		$this->assertStringNotContainsString( '<a', $html );
	}

	public function test_the_url_is_escaped(): void {
		Functions\when( 'get_privacy_policy_url' )->justReturn( 'https://example.com/"onmouseover="x' );

		$this->assertStringContainsString( 'href="https://example.com/&quot;onmouseover=&quot;x"', PrivacyPolicyLink::html() );
	}
}
