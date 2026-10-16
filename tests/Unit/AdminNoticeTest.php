<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\AdminNotice;

/**
 * The page-notice builder (#1843): the icon, the dismissibility rule and the
 * settings-API path.
 *
 * @covers \FreeFormCertificate\Core\AdminNotice
 */
class AdminNoticeTest extends TestCase {

	/**
	 * What reached wp_admin_notice(), one entry per call.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $calls = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->calls = array();
		$calls       = &$this->calls;
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message, $args = array() ) use ( &$calls ) {
				$calls[] = array( $message, $args );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_it_leads_with_the_type_icon_in_its_tone(): void {
		AdminNotice::render( 'success', 'Saved.' );

		$this->assertCount( 1, $this->calls );
		$this->assertSame(
			'<span class="ffc-admin-notice-icon ffc-icon-success ffc-icon-tone-success" aria-hidden="true"></span>Saved.',
			$this->calls[0][0]
		);
		$this->assertSame( 'success', $this->calls[0][1]['type'] );
	}

	public function test_an_error_reads_the_error_icon_in_the_danger_tone(): void {
		AdminNotice::render( 'error', 'No.' );

		$this->assertStringContainsString( 'ffc-icon-error ffc-icon-tone-danger', $this->calls[0][0] );
	}

	public function test_updated_is_read_as_success_and_an_unknown_type_as_info(): void {
		AdminNotice::render( 'updated', 'a' );
		AdminNotice::render( 'bogus', 'b' );

		$this->assertSame( 'success', $this->calls[0][1]['type'] );
		$this->assertSame( 'info', $this->calls[1][1]['type'] );
		$this->assertStringContainsString( 'ffc-icon-info', $this->calls[1][0] );
	}

	/**
	 * @dataProvider dismissibility_provider
	 */
	public function test_the_type_decides_whether_it_closes( string $type, bool $expected ): void {
		AdminNotice::render( $type, 'm', array( 'dismissible' => ! $expected ) );

		$this->assertSame( $expected, $this->calls[0][1]['dismissible'], 'a caller cannot override the rule' );
		$this->assertSame( $expected, AdminNotice::is_dismissible( $type ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function dismissibility_provider(): array {
		return array(
			'success'          => array( 'success', true ),
			'updated (legacy)' => array( 'updated', true ),
			'info'             => array( 'info', true ),
			'warning'          => array( 'warning', false ),
			'error'            => array( 'error', false ),
		);
	}

	public function test_an_acknowledgement_closes_whatever_its_type(): void {
		AdminNotice::render( 'error', '<p>Key missing.</p>', array( 'acknowledge' => true, 'paragraph_wrap' => false ) );

		$this->assertTrue( $this->calls[0][1]['dismissible'] );
		$this->assertArrayNotHasKey( 'acknowledge', $this->calls[0][1], 'not a wp_admin_notice() argument' );
	}

	public function test_without_paragraph_wrap_the_icon_goes_inside_the_first_paragraph(): void {
		AdminNotice::render( 'warning', '<p class="x"><strong>Heads up</strong> body</p><p>two</p>', array( 'paragraph_wrap' => false ) );

		$this->assertStringStartsWith(
			'<p class="x"><span class="ffc-admin-notice-icon ffc-icon-warning ffc-icon-tone-warning" aria-hidden="true"></span><strong>',
			$this->calls[0][0]
		);
		$this->assertSame( 1, substr_count( $this->calls[0][0], 'ffc-admin-notice-icon' ) );
	}

	public function test_without_a_leading_paragraph_the_icon_is_prepended(): void {
		AdminNotice::render( 'info', '<ul><li>a</li></ul>', array( 'paragraph_wrap' => false ) );

		$this->assertStringStartsWith( '<span class="ffc-admin-notice-icon', $this->calls[0][0] );
	}

	public function test_it_keeps_the_callers_classes_and_adds_its_own(): void {
		AdminNotice::render( 'info', 'm', array( 'additional_classes' => array( 'ffc-x' ), 'id' => 'n1' ) );

		$this->assertSame( array( 'ffc-x', 'ffc-admin-notice' ), $this->calls[0][1]['additional_classes'] );
		$this->assertSame( 'n1', $this->calls[0][1]['id'] );
	}

	public function test_the_message_is_passed_through_untouched_for_wp_admin_notice_to_escape(): void {
		// The contract is wp_admin_notice()'s: the caller escapes, core prints
		// through wp_kses_post(). Nothing here may unescape or re-encode.
		AdminNotice::render( 'error', '&lt;script&gt;' );

		$this->assertStringEndsWith( '&lt;script&gt;', $this->calls[0][0] );
	}

	public function test_settings_errors_prints_each_queued_message_through_render(): void {
		Functions\expect( 'get_settings_errors' )->once()->with( 'ffc_user_access_settings' )->andReturn(
			array(
				array(
					'setting' => 'ffc_user_access_settings',
					'code'    => 'ffc_user_access_updated',
					'message' => 'User Access settings saved successfully.',
					'type'    => 'updated',
				),
				array(
					'setting' => 'ffc_user_access_settings',
					'code'    => '',
					'message' => 'Bad URL.',
					'type'    => 'error',
				),
			)
		);

		AdminNotice::settings_errors( 'ffc_user_access_settings' );

		$this->assertCount( 2, $this->calls );
		$this->assertSame( 'success', $this->calls[0][1]['type'] );
		$this->assertTrue( $this->calls[0][1]['dismissible'] );
		$this->assertSame( 'setting-error-ffc_user_access_updated', $this->calls[0][1]['id'] );
		$this->assertStringContainsString( 'ffc-icon-success', $this->calls[0][0] );
		$this->assertSame( 'error', $this->calls[1][1]['type'] );
		$this->assertFalse( $this->calls[1][1]['dismissible'] );
		$this->assertArrayNotHasKey( 'id', $this->calls[1][1] );
	}

	public function test_settings_errors_prints_nothing_when_nothing_is_queued(): void {
		Functions\when( 'get_settings_errors' )->justReturn( array() );

		AdminNotice::settings_errors( 'ffc_settings' );

		$this->assertSame( array(), $this->calls );
	}
}
