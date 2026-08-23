<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\EmailTemplates;

/**
 * @covers \FreeFormCertificate\Core\EmailTemplates
 */
class EmailTemplatesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\class_exists( '\FreeFormCertificate\Core\EmailTemplates' );
		Functions\when( '__' )->returnArg();
		// No global overrides by default → every reader falls back to the file.
		Functions\when( 'get_option' )->justReturn( array() );
	}

	/**
	 * Back the global-override option with an in-memory store so save/clear and
	 * their effect on body() can be exercised.
	 *
	 * @param array<string, array<string, string>> $seed Initial option value.
	 * @return array{0: callable} A holder whose [0]() returns the live store.
	 */
	private function fake_option_store( array $seed = array() ): array {
		$store = $seed;
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$store ) {
				return 'ffc_email_bodies' === $key ? $store : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				if ( 'ffc_email_bodies' === $key ) {
					$store = $value;
				}
				return true;
			}
		);
		return array( static function () use ( &$store ) { return $store; } );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_load_returns_subject_and_body_for_reregistration(): void {
		$t = EmailTemplates::load( 'reregistration-invitation' );

		$this->assertIsArray( $t );
		$this->assertArrayHasKey( 'subject', $t );
		$this->assertArrayHasKey( 'body', $t );
		$this->assertStringContainsString( '{{reregistration_title}}', $t['body'] );
	}

	public function test_load_returns_null_for_unknown_template(): void {
		$this->assertNull( EmailTemplates::load( 'does-not-exist' ) );
	}

	public function test_body_returns_audience_default_body(): void {
		$body = EmailTemplates::body( 'audience-booking' );

		$this->assertStringContainsString( '{{schedule_name}}', $body );
		$this->assertStringContainsString( '{{creator_name}}', $body );
	}

	public function test_body_returns_empty_string_for_unknown(): void {
		$this->assertSame( '', EmailTemplates::body( 'nope' ) );
	}

	/**
	 * @dataProvider editable_default_templates
	 */
	public function test_editable_default_bodies_load_from_files( string $name, string $token ): void {
		$body = EmailTemplates::body( $name );

		$this->assertNotSame( '', $body );
		$this->assertStringContainsString( $token, $body );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public function editable_default_templates(): array {
		return array(
			'certificate'  => array( 'certificate-user', '{{auth_code}}' ),
			'recruitment'  => array( 'recruitment-convocation', '{{notice_code}}' ),
			'confirmation' => array( 'selfscheduling-confirmation', '{{calendar_title}}' ),
		);
	}

	// ==================================================================
	// Global override layer (#964 phase 1)
	// ==================================================================

	public function test_body_ignores_the_global_override(): void {
		$this->fake_option_store( array( 'audience-booking' => array( 'body' => 'GLOBAL' ) ) );

		// body() is the shipped file default regardless of any stored override
		// (unchanged behaviour — existing callers stay byte-for-byte the same).
		$this->assertStringContainsString( '{{schedule_name}}', EmailTemplates::body( 'audience-booking' ) );
	}

	public function test_global_body_reads_the_stored_override(): void {
		$this->fake_option_store( array( 'audience-booking' => array( 'body' => 'GLOBAL BODY', 'subject' => 'GLOBAL SUBJECT' ) ) );

		$this->assertSame( 'GLOBAL BODY', EmailTemplates::global_body( 'audience-booking' ) );
		$this->assertSame( 'GLOBAL SUBJECT', EmailTemplates::global_body( 'audience-booking', 'subject' ) );
	}

	public function test_global_body_is_empty_when_unset_or_not_allowlisted(): void {
		$this->fake_option_store();

		$this->assertSame( '', EmailTemplates::global_body( 'audience-booking' ) );
		$this->assertSame( '', EmailTemplates::global_body( 'not-a-template' ) );
	}

	public function test_effective_body_prefers_global_over_file_then_falls_back(): void {
		// With an override, effective_body() returns it…
		$this->fake_option_store( array( 'audience-booking' => array( 'body' => 'GLOBAL BODY' ) ) );
		$this->assertSame( 'GLOBAL BODY', EmailTemplates::effective_body( 'audience-booking' ) );

		// …and with none, it falls back to the shipped file default.
		$this->fake_option_store();
		$this->assertStringContainsString( '{{schedule_name}}', EmailTemplates::effective_body( 'audience-booking' ) );
	}

	public function test_save_global_stores_body_and_subject(): void {
		$holder = $this->fake_option_store();

		$this->assertTrue( EmailTemplates::save_global( 'audience-booking', array( 'body' => 'B', 'subject' => 'S' ) ) );

		$store = $holder[0]();
		$this->assertSame( 'B', $store['audience-booking']['body'] );
		$this->assertSame( 'S', $store['audience-booking']['subject'] );
	}

	public function test_save_global_drops_empty_values_and_removes_empty_entry(): void {
		$holder = $this->fake_option_store( array( 'audience-booking' => array( 'body' => 'old' ) ) );

		// Saving only empty values removes the whole entry → back to file default.
		EmailTemplates::save_global( 'audience-booking', array( 'body' => '', 'subject' => '' ) );

		$this->assertArrayNotHasKey( 'audience-booking', $holder[0]() );
	}

	public function test_save_global_rejects_a_non_allowlisted_name(): void {
		$this->fake_option_store();

		$this->assertFalse( EmailTemplates::save_global( 'not-a-template', array( 'body' => 'x' ) ) );
	}

	public function test_clear_global_removes_the_override(): void {
		$holder = $this->fake_option_store( array( 'audience-booking' => array( 'body' => 'GLOBAL' ) ) );

		$this->assertTrue( EmailTemplates::clear_global( 'audience-booking' ) );
		$this->assertArrayNotHasKey( 'audience-booking', $holder[0]() );

		// effective_body() now resolves back to the file default.
		$this->assertStringContainsString( '{{schedule_name}}', EmailTemplates::effective_body( 'audience-booking' ) );
	}

	// ==================================================================
	// overrides_global() — the Global/Custom heuristic (#964)
	// ==================================================================

	public function test_overrides_global_is_false_for_empty_or_blank(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => (string) preg_replace( '/<[^>]*>/', '', (string) $s ) );

		$this->assertFalse( EmailTemplates::overrides_global( 'certificate-user', '', 'body' ) );
		$this->assertFalse( EmailTemplates::overrides_global( 'certificate-user', '<p></p>', 'body' ) );
	}

	public function test_overrides_global_is_false_when_equal_to_file_default(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => (string) preg_replace( '/<[^>]*>/', '', (string) $s ) );

		$file = EmailTemplates::body( 'certificate-user', 'body' );
		$this->assertNotSame( '', $file );
		// Byte-identical → Global.
		$this->assertFalse( EmailTemplates::overrides_global( 'certificate-user', $file, 'body' ) );
		// Same visible text, extra wrapping markup → still Global (normalised compare).
		$this->assertFalse( EmailTemplates::overrides_global( 'certificate-user', '<div>' . $file . '</div>', 'body' ) );
	}

	public function test_overrides_global_is_true_for_a_real_custom_body(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => (string) preg_replace( '/<[^>]*>/', '', (string) $s ) );

		$this->assertTrue(
			EmailTemplates::overrides_global( 'certificate-user', '<p>A completely different message for this one form.</p>', 'body' )
		);
	}

	public function test_overrides_global_baseline_is_the_file_default_not_the_override(): void {
		// A global override is set, but the heuristic still compares against the
		// shipped FILE default — so a form carrying the file default keeps
		// resolving as Global and will follow the newly-set override.
		$this->fake_option_store( array( 'certificate-user' => array( 'body' => '<p>New global copy</p>' ) ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => (string) preg_replace( '/<[^>]*>/', '', (string) $s ) );

		$file = EmailTemplates::body( 'certificate-user', 'body' );
		$this->assertFalse( EmailTemplates::overrides_global( 'certificate-user', $file, 'body' ) );
		// A body equal to the override (but not the file) is a real customisation.
		$this->assertTrue( EmailTemplates::overrides_global( 'certificate-user', 'New global copy', 'body' ) );
	}

	public function test_overrides_global_compares_subject_key(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => (string) preg_replace( '/<[^>]*>/', '', (string) $s ) );

		$file_subject = EmailTemplates::body( 'certificate-user', 'subject' );
		$this->assertNotSame( '', $file_subject );
		$this->assertFalse( EmailTemplates::overrides_global( 'certificate-user', $file_subject, 'subject' ) );
		$this->assertTrue( EmailTemplates::overrides_global( 'certificate-user', 'A custom subject line', 'subject' ) );
	}
}
