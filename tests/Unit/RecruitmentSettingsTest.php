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

		// Subject + body moved to the global email-body hub (#964) — no longer
		// keys of this option; From/send-mode stay.
		$this->assertArrayNotHasKey( 'email_subject', $defaults );
		$this->assertArrayNotHasKey( 'email_body_html', $defaults );
		$this->assertArrayHasKey( 'email_from_address', $defaults );
		$this->assertArrayHasKey( 'email_from_name', $defaults );
		$this->assertArrayHasKey( 'email_mode', $defaults );
		$this->assertArrayHasKey( 'public_cache_seconds', $defaults );
		$this->assertArrayHasKey( 'public_rate_limit_per_minute', $defaults );
		$this->assertArrayHasKey( 'public_default_page_size', $defaults );
	}

	public function test_defaults_match_plan_documented_values(): void {
		$defaults = RecruitmentSettings::defaults();

		// 12 hours per the v6.1 polish PR (cache invalidation now hooks
		// every admin write, so the long TTL is safe).
		$this->assertSame( 12 * HOUR_IN_SECONDS, $defaults['public_cache_seconds'] );
		$this->assertSame( 30, $defaults['public_rate_limit_per_minute'] );
		$this->assertSame( 50, $defaults['public_default_page_size'] );
		$this->assertSame( '', $defaults['email_from_address'] );
		$this->assertSame( '', $defaults['email_from_name'] );
	}

	public function test_all_returns_defaults_on_fresh_install(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$all = RecruitmentSettings::all();

		$this->assertSame( 12 * HOUR_IN_SECONDS, $all['public_cache_seconds'] );
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

		$this->assertSame( 12 * HOUR_IN_SECONDS, $out['public_cache_seconds'], 'Negative input falls back to default per the clamp.' );
	}

	public function test_email_mode_defaults_to_always(): void {
		$this->assertSame( 'always', RecruitmentSettings::defaults()['email_mode'] );
	}

	public function test_sanitize_accepts_a_valid_email_mode(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();

		$out = RecruitmentSettings::sanitize( array( 'email_mode' => 'ask' ) );

		$this->assertSame( 'ask', $out['email_mode'] );
	}

	public function test_sanitize_rejects_an_unknown_email_mode(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();

		$out = RecruitmentSettings::sanitize( array( 'email_mode' => 'bogus' ) );

		$this->assertSame( 'always', $out['email_mode'], 'An out-of-allowlist mode collapses to the default.' );
	}

	public function test_sanitize_caps_oversized_cache_seconds(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();

		$out = RecruitmentSettings::sanitize( array( 'public_cache_seconds' => 999_999 ) );

		$this->assertSame( 86_400, $out['public_cache_seconds'], 'Above-max input is capped at the documented ceiling.' );
	}

	public function test_sanitize_drops_the_migrated_email_body_and_subject(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();

		// The subject + body are no longer this option's concern (#964) — sanitize
		// keeps only the surviving email keys (From / send mode).
		$out = RecruitmentSettings::sanitize(
			array(
				'email_body_html' => '<p>stale custom body</p>',
				'email_subject'   => 'stale subject',
			)
		);

		$this->assertArrayNotHasKey( 'email_body_html', $out );
		$this->assertArrayNotHasKey( 'email_subject', $out );
	}

	public function test_sanitize_text_field_runs_on_from_fields(): void {
		$captured = array();
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $input ) use ( &$captured ): string {
				$captured[] = $input;
				return trim( (string) $input );
			}
		);

		RecruitmentSettings::sanitize(
			array(
				'email_from_address' => '  noreply@example.com  ',
				'email_from_name'    => '  Recruiter  ',
			)
		);

		$this->assertContains( '  noreply@example.com  ', $captured );
		$this->assertContains( '  Recruiter  ', $captured );
	}

	// ==================================================================
	// migrate_email_to_hub() — one-shot move into ffc_email_bodies (#964)
	// ==================================================================

	public function test_migrate_email_to_hub_moves_a_custom_body_and_subject(): void {
		$recruitment = array(
			'email_body_html' => '<p>custom convocation body</p>',
			'email_subject'   => 'Custom subject',
		);
		$hub = array();
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( $recruitment, &$hub ) {
				if ( 'ffc_recruitment_settings' === $key ) {
					return $recruitment;
				}
				if ( 'ffc_email_bodies' === $key ) {
					return $hub;
				}
				return $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$hub ) {
				if ( 'ffc_email_bodies' === $key ) {
					$hub = $value;
				}
				return true;
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => trim( (string) strip_tags( (string) $s ) ) );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		RecruitmentSettings::migrate_email_to_hub();

		$this->assertArrayHasKey( 'recruitment-convocation', $hub );
		$this->assertSame( '<p>custom convocation body</p>', $hub['recruitment-convocation']['body'] );
		$this->assertSame( 'Custom subject', $hub['recruitment-convocation']['subject'] );
	}

	public function test_migrate_email_to_hub_skips_unchanged_defaults(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => trim( (string) strip_tags( (string) $s ) ) );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		// A recruitment option carrying exactly the shipped default migrates
		// nothing — the hub stays empty and effective_body() keeps resolving to
		// the same file default.
		$recruitment = array(
			'email_body_html' => \FreeFormCertificate\Core\EmailTemplates::body( 'recruitment-convocation', 'body' ),
			'email_subject'   => \FreeFormCertificate\Core\EmailTemplates::body( 'recruitment-convocation', 'subject' ),
		);
		$saved = false;
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( $recruitment ) {
				if ( 'ffc_recruitment_settings' === $key ) {
					return $recruitment;
				}
				if ( 'ffc_email_bodies' === $key ) {
					return array();
				}
				return $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$saved ) {
				if ( 'ffc_email_bodies' === $key ) {
					$saved = true;
				}
				return true;
			}
		);

		RecruitmentSettings::migrate_email_to_hub();

		$this->assertFalse( $saved, 'a default recruitment email migrates nothing into the hub' );
	}
}
