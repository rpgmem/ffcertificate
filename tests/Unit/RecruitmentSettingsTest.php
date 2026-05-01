<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentSettings;

/**
 * Tests for RecruitmentSettings — pins the OPTION_NAME contract (relied on
 * by `uninstall.php`), the defaults shape (sub-keys present on a fresh
 * install), the `all()` merge behavior, and the `sanitize()` int-clamping
 * for the public_* numeric sub-keys.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentSettings
 */
class RecruitmentSettingsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_option_name_matches_uninstall_constant(): void {
		// `uninstall.php` calls `delete_option('ffc_recruitment_settings')` —
		// the OPTION_NAME constant must match that exact string for the
		// teardown to actually remove the row.
		$this->assertSame( 'ffc_recruitment_settings', RecruitmentSettings::OPTION_NAME );
	}

	public function test_defaults_include_every_documented_subkey(): void {
		$defaults = RecruitmentSettings::defaults();

		$this->assertArrayHasKey( 'email_subject', $defaults );
		$this->assertArrayHasKey( 'email_from_address', $defaults );
		$this->assertArrayHasKey( 'email_from_name', $defaults );
		$this->assertArrayHasKey( 'email_body_html', $defaults );
		$this->assertArrayHasKey( 'public_cache_seconds', $defaults );
		$this->assertArrayHasKey( 'public_rate_limit_per_minute', $defaults );
		$this->assertArrayHasKey( 'public_default_page_size', $defaults );
	}

	public function test_defaults_match_plan_documented_values(): void {
		$defaults = RecruitmentSettings::defaults();

		// Per §15 of the plan.
		$this->assertSame( 60, $defaults['public_cache_seconds'] );
		$this->assertSame( 30, $defaults['public_rate_limit_per_minute'] );
		$this->assertSame( 50, $defaults['public_default_page_size'] );
		$this->assertSame( '', $defaults['email_from_address'] );
		$this->assertSame( '', $defaults['email_from_name'] );
	}

	public function test_all_returns_defaults_on_fresh_install(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$all = RecruitmentSettings::all();

		$this->assertSame( 60, $all['public_cache_seconds'] );
		$this->assertSame( 30, $all['public_rate_limit_per_minute'] );
		$this->assertSame( 50, $all['public_default_page_size'] );
	}

	public function test_all_merges_stored_values_over_defaults(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'public_cache_seconds' => 120,
				'email_from_name'      => 'Recruitment Team',
			)
		);

		$all = RecruitmentSettings::all();

		$this->assertSame( 120, $all['public_cache_seconds'] );
		$this->assertSame( 'Recruitment Team', $all['email_from_name'] );
		// Untouched sub-keys keep their default.
		$this->assertSame( 30, $all['public_rate_limit_per_minute'] );
	}

	public function test_get_returns_specific_subkey(): void {
		Functions\when( 'get_option' )->justReturn( array( 'public_default_page_size' => 25 ) );

		$this->assertSame( 25, RecruitmentSettings::get( 'public_default_page_size' ) );
	}

	public function test_sanitize_returns_defaults_when_input_is_not_array(): void {
		$out = RecruitmentSettings::sanitize( 'not-an-array' );

		$this->assertSame( RecruitmentSettings::defaults(), $out );
	}

	public function test_sanitize_clamps_negative_cache_seconds_to_default(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();

		$out = RecruitmentSettings::sanitize( array( 'public_cache_seconds' => -5 ) );

		$this->assertSame( 60, $out['public_cache_seconds'], 'Negative input falls back to default per the clamp.' );
	}

	public function test_sanitize_caps_oversized_cache_seconds(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();

		$out = RecruitmentSettings::sanitize( array( 'public_cache_seconds' => 999_999 ) );

		$this->assertSame( 86_400, $out['public_cache_seconds'], 'Above-max input is capped at the documented ceiling.' );
	}

	public function test_sanitize_keeps_admin_html_in_body_template(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();

		// Admins are trusted to write the body HTML; sanitize MUST NOT strip
		// tags, otherwise the email-body editor becomes unusable.
		$html = '<p>Hello <strong>{{name}}</strong>, click <a href="{{site_url}}">here</a>.</p>';
		$out  = RecruitmentSettings::sanitize( array( 'email_body_html' => $html ) );

		$this->assertSame( $html, $out['email_body_html'] );
	}

	public function test_sanitize_text_field_runs_on_subject_and_from(): void {
		$captured = array();
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $input ) use ( &$captured ): string {
				$captured[] = $input;
				return trim( (string) $input );
			}
		);

		RecruitmentSettings::sanitize(
			array(
				'email_subject'      => '  Subject  ',
				'email_from_address' => '  noreply@example.com  ',
				'email_from_name'    => '  Recruiter  ',
			)
		);

		$this->assertContains( '  Subject  ', $captured );
		$this->assertContains( '  noreply@example.com  ', $captured );
		$this->assertContains( '  Recruiter  ', $captured );
	}
}
