<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/.github/scripts/ffc-asset-freshness.php';

/**
 * The post-deploy smoke's asset check, driven without a host (#2915).
 *
 * The check runs on the testes host after a deploy, so nothing in CI can run
 * it end to end; a wrong branch would surface as an alarm that names the wrong
 * cache, or stays quiet. What stays unreachable is that the host answers and
 * that 127.0.0.1 with the site's `Host` header reaches the same virtual host.
 */
final class AssetFreshnessCheckTest extends TestCase {

	private const NEW = 'aaaa';
	private const OLD = 'bbbb';

	public function test_a_fresh_public_body_passes(): void {
		$verdict = ffc_asset_freshness_verdict( self::NEW, self::NEW, null );

		$this->assertTrue( $verdict['ok'] );
		$this->assertFalse( $verdict['fatal'] );
	}

	public function test_a_stale_public_body_with_a_fresh_origin_blames_the_cdn(): void {
		$verdict = ffc_asset_freshness_verdict( self::NEW, self::OLD, self::NEW );

		$this->assertFalse( $verdict['ok'] );
		$this->assertTrue( $verdict['fatal'] );
		$this->assertStringContainsString( 'the CDN serves an older file', $verdict['detail'] );
	}

	public function test_a_stale_origin_blames_the_server_cache(): void {
		$verdict = ffc_asset_freshness_verdict( self::NEW, self::OLD, self::OLD );

		$this->assertTrue( $verdict['fatal'] );
		$this->assertStringContainsString( 'the server\'s own cache', $verdict['detail'] );
	}

	public function test_a_stale_body_with_no_origin_answer_still_fails_without_naming_a_layer(): void {
		$verdict = ffc_asset_freshness_verdict( self::NEW, self::OLD, null );

		$this->assertTrue( $verdict['fatal'] );
		$this->assertStringContainsString( 'the layer is unknown', $verdict['detail'] );
	}

	public function test_a_failed_public_fetch_warns_and_never_passes(): void {
		$verdict = ffc_asset_freshness_verdict( self::NEW, null, self::NEW );

		$this->assertFalse( $verdict['ok'], 'A fetch that measured nothing must not read as fresh.' );
		$this->assertFalse( $verdict['fatal'], 'A network that did not answer is not a stale cache.' );
	}

	public function test_the_origin_request_keeps_path_query_and_port_and_moves_the_host_to_a_header(): void {
		$request = ffc_asset_origin_request( 'https://site.example:8443/wp-content/plugins/ffcertificate/assets/js/ffc-core.js?ver=6.30.0.1700000000' );

		$this->assertSame(
			array(
				'url'  => 'https://127.0.0.1:8443/wp-content/plugins/ffcertificate/assets/js/ffc-core.js?ver=6.30.0.1700000000',
				'host' => 'site.example:8443',
			),
			$request
		);
	}

	public function test_the_origin_request_without_port_or_query(): void {
		$this->assertSame(
			array(
				'url'  => 'http://127.0.0.1/a.css',
				'host' => 'site.example',
			),
			ffc_asset_origin_request( 'http://site.example/a.css' )
		);
	}

	public function test_a_url_with_no_host_has_no_origin_request(): void {
		$this->assertNull( ffc_asset_origin_request( '/wp-content/plugins/ffcertificate/a.js' ) );
	}
}
