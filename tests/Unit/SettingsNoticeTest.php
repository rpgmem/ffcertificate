<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\SettingsNotice;

/**
 * A Settings action's result waits on the server; the URL carries only the
 * area (#2338).
 *
 * @covers \FreeFormCertificate\Admin\SettingsNotice
 */
class SettingsNoticeTest extends TestCase {

	/** @var array<string, mixed> */
	private array $store = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->store = array();
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->store[ $key ] ?? false );
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->store[ $key ] );
				return true;
			}
		);
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $url ) => $url . '&' . $key . '=' . $value );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ) );
	}

	protected function tearDown(): void {
		unset( $_GET['ffc_notice'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_url_names_the_area_and_the_text_stays_on_the_server(): void {
		$url = SettingsNotice::add( 'https://site.test/x?page=ffc-settings', 'url_cleanup', 'success', 'Deleted 4 short URL(s).' );

		$this->assertSame( 'https://site.test/x?page=ffc-settings&ffc_notice=url_cleanup', $url );
		$this->assertSame( array( 'type' => 'success', 'message' => 'Deleted 4 short URL(s).' ), $this->store['ffc_notice_3_url_cleanup'] );
	}

	public function test_a_notice_is_shown_once_and_only_for_its_area(): void {
		SettingsNotice::add( 'u', 'pubaccess', 'error', 'Table is locked' );

		$_GET['ffc_notice'] = 'url_cleanup';
		$this->assertNull( SettingsNotice::take( 'pubaccess' ), 'Another area named in the URL shows nothing.' );

		$_GET['ffc_notice'] = 'pubaccess';
		$notice             = SettingsNotice::take( 'pubaccess' );
		$this->assertSame( 'Table is locked', SettingsNotice::message_of( $notice, 'error' ) );
		$this->assertSame( '', SettingsNotice::message_of( $notice, 'success' ) );
		$this->assertNull( SettingsNotice::take( 'pubaccess' ), 'Shown once.' );
	}

	public function test_an_unknown_area_stores_nothing(): void {
		$this->assertSame( 'u', SettingsNotice::add( 'u', 'anything', 'success', 'x' ) );
		$this->assertSame( array(), $this->store );

		$_GET['ffc_notice'] = 'anything';
		$this->assertNull( SettingsNotice::take( 'anything' ) );
	}
}
