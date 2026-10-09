<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Scheduling\SchedulingMailer;

/**
 * Tests for SchedulingMailer: send() wraps the body in the shared configurable
 * chrome (ffc_email_document) and transports via EmailService.
 *
 * @covers \FreeFormCertificate\Scheduling\SchedulingMailer
 */
class SchedulingMailerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_date' )->justReturn( '2026' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		// ffc_settings / ffc_email_template resolve to arrays (defaults);
		// any other key (admin_email) returns an empty string.
		Functions\when( 'get_option' )->alias( static function ( $key, $default = false ) {
			return in_array( $key, array( 'ffc_settings', 'ffc_email_template' ), true ) ? array() : '';
		} );
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
			return $value;
		} );
		// #673: EmailService::send derives a text/plain alternative for HTML
		// messages — stub the WP glue that derivation touches.
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( $s ) {
				return trim( (string) strip_tags( (string) $s ) );
			}
		);
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'remove_action' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// send()
	// ==================================================================

	public function test_send_wraps_body_in_configurable_chrome_by_default(): void {
		$sent_body = null;
		Functions\when( 'wp_mail' )->alias( function ( $to, $subj, $body ) use ( &$sent_body ) {
			$sent_body = $body;
			return true;
		} );

		SchedulingMailer::send( 'test@example.com', 'Subject', '<p>Body</p>' );

		// Wrapped in the shared chrome (table-based document) with the email body inside.
		$this->assertStringContainsString( '<!DOCTYPE html>', $sent_body );
		$this->assertStringContainsString( '<table', $sent_body );
		$this->assertStringContainsString( '<p>Body</p>', $sent_body );
	}

	public function test_document_without_an_appearance_keeps_the_email_model_body(): void {
		$html = SchedulingMailer::document( '<p>Body</p>' );

		$this->assertStringNotContainsString( 'ffc-email-col-text', $html );
		$this->assertStringNotContainsString( 'background-image', $html );
		$this->assertMatchesRegularExpression( '/<td class="ffc-email-body" valign="top" style="background-color:#ffffff;color:#333333;/', $html );
	}

	public function test_document_draws_the_body_appearance_between_the_global_header_and_footer(): void {
		Functions\when( 'esc_url_raw' )->returnArg();
		$html = SchedulingMailer::document(
			'<p>Body</p>',
			array(
				'body_appearance' => array(
					'image_url'      => 'https://example.org/art.png',
					'min_height'     => 360,
					'fallback_color' => '#fff3e8',
					'text_color'     => '#2b2d42',
					'position'       => 'left',
					'text_width'     => 60,
				),
			)
		);

		// The body cell: image anchored on the free side, fallback colour, height and the legacy attributes.
		$this->assertStringContainsString( 'background="https://example.org/art.png" bgcolor="#fff3e8" height="360"', $html );
		$this->assertStringContainsString( "background-color:#fff3e8;background-image:url('https://example.org/art.png');background-position:right center;background-size:cover;", $html );
		// The text column first, the free side after it.
		$this->assertMatchesRegularExpression( '/ffc-email-col-text" width="60%".*<p>Body<\/p>.*ffc-email-col-space" width="40%"/s', $html );
		// Phones: the text takes the full width.
		$this->assertStringContainsString( '.ffc-email-col-text { width: 100% !important; display: block !important; }', $html );
		// Header and footer are still the Email Model's.
		$this->assertStringContainsString( 'Test Site', $html );
	}

	public function test_document_puts_the_free_side_first_when_the_text_is_on_the_right(): void {
		Functions\when( 'esc_url_raw' )->returnArg();
		$html = SchedulingMailer::document(
			'<p>Body</p>',
			array(
				'body_appearance' => array(
					'image_url'      => '',
					'fallback_color' => '#fff3e8',
					'text_color'     => '',
					'position'       => 'right',
					'text_width'     => 50,
				),
			)
		);

		$this->assertMatchesRegularExpression( '/ffc-email-col-space" width="50%".*ffc-email-col-text" width="50%"/s', $html );
		$this->assertStringNotContainsString( 'background-image', $html, 'no image, no background image' );
		$this->assertStringNotContainsString( 'height="', $html );
	}

	public function test_send_skip_wrap_when_false(): void {
		$sent_body = null;
		Functions\when( 'wp_mail' )->alias( function ( $to, $subj, $body ) use ( &$sent_body ) {
			$sent_body = $body;
			return true;
		} );

		SchedulingMailer::send( 'test@example.com', 'Subject', '<p>Raw</p>', array(), false );
		$this->assertSame( '<p>Raw</p>', $sent_body );
	}

	public function test_send_returns_wp_mail_result(): void {
		Functions\when( 'wp_mail' )->justReturn( false );
		$this->assertFalse( SchedulingMailer::send( 'test@example.com', 'Subj', 'Body' ) );
	}
}
