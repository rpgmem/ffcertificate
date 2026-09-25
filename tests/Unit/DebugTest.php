<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Debug;

/**
 * Tests for Debug: area enable/disable, conditional logging,
 * data formatting, and convenience method delegation.
 */
class DebugTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array Captured error_log calls */
	private $logged = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$logged = &$this->logged;
		Functions\when( 'error_log' )->alias( function ( $msg ) use ( &$logged ) {
			$logged[] = $msg;
			return true;
		} );
	}

	protected function tearDown(): void {
		$this->logged = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper: mock get_option to enable a specific debug area.
	 */
	private function enable_area( string $area ): void {
		Functions\when( 'get_option' )->justReturn( array( $area => 1 ) );
	}

	/**
	 * Helper: mock get_option to disable all debug areas.
	 */
	private function disable_all(): void {
		Functions\when( 'get_option' )->justReturn( array() );
	}

	// ==================================================================
	// is_enabled()
	// ==================================================================

	public function test_enabled_when_setting_is_1(): void {
		$this->enable_area( Debug::AREA_PDF_GENERATOR );
		$this->assertTrue( Debug::is_enabled( Debug::AREA_PDF_GENERATOR ) );
	}

	public function test_disabled_when_setting_missing(): void {
		$this->disable_all();
		$this->assertFalse( Debug::is_enabled( Debug::AREA_PDF_GENERATOR ) );
	}

	public function test_disabled_when_setting_is_0(): void {
		Functions\when( 'get_option' )->justReturn( array( Debug::AREA_PDF_GENERATOR => 0 ) );
		$this->assertFalse( Debug::is_enabled( Debug::AREA_PDF_GENERATOR ) );
	}

	public function test_different_areas_independent(): void {
		Functions\when( 'get_option' )->justReturn( array(
			Debug::AREA_EMAIL_HANDLER => 1,
			Debug::AREA_REST_API      => 0,
		) );
		$this->assertTrue( Debug::is_enabled( Debug::AREA_EMAIL_HANDLER ) );
		$this->assertFalse( Debug::is_enabled( Debug::AREA_REST_API ) );
	}

	// ==================================================================
	// log() — conditional logging
	// ==================================================================

	public function test_log_writes_when_enabled(): void {
		$this->enable_area( Debug::AREA_PDF_GENERATOR );
		Debug::log( Debug::AREA_PDF_GENERATOR, 'Test message' );
		$this->assertCount( 1, $this->logged );
		$this->assertStringContainsString( '[FFC Debug] Test message', $this->logged[0] );
	}

	public function test_log_skips_when_disabled(): void {
		$this->disable_all();
		Debug::log( Debug::AREA_PDF_GENERATOR, 'Should not appear' );
		$this->assertCount( 0, $this->logged );
	}

	// ==================================================================
	// log() — data formatting
	// ==================================================================

	public function test_log_with_null_data_no_data_suffix(): void {
		$this->enable_area( Debug::AREA_ENCRYPTION );
		Debug::log( Debug::AREA_ENCRYPTION, 'No data' );
		$this->assertStringNotContainsString( '| Data:', $this->logged[0] );
	}

	public function test_log_with_string_data(): void {
		$this->enable_area( Debug::AREA_ENCRYPTION );
		Debug::log( Debug::AREA_ENCRYPTION, 'Msg', 'extra info' );
		$this->assertStringContainsString( '| Data: extra info', $this->logged[0] );
	}

	public function test_log_with_array_data(): void {
		$this->enable_area( Debug::AREA_ENCRYPTION );
		Debug::log( Debug::AREA_ENCRYPTION, 'Msg', array( 'key' => 'val' ) );
		$this->assertStringContainsString( '| Data:', $this->logged[0] );
		$this->assertStringContainsString( 'key', $this->logged[0] );
		$this->assertStringContainsString( 'val', $this->logged[0] );
	}

	public function test_log_with_integer_data(): void {
		$this->enable_area( Debug::AREA_GEOFENCE );
		Debug::log( Debug::AREA_GEOFENCE, 'Count', 42 );
		$this->assertStringContainsString( '| Data: 42', $this->logged[0] );
	}

	// ==================================================================
	// Convenience methods — delegation
	// ==================================================================

	public function test_log_pdf_delegates(): void {
		$this->enable_area( Debug::AREA_PDF_GENERATOR );
		Debug::log_pdf( 'PDF test' );
		$this->assertCount( 1, $this->logged );
		$this->assertStringContainsString( 'PDF test', $this->logged[0] );
	}

	public function test_log_email_delegates(): void {
		$this->enable_area( Debug::AREA_EMAIL_HANDLER );
		Debug::log_email( 'Email test' );
		$this->assertCount( 1, $this->logged );
	}

	public function test_log_form_delegates(): void {
		$this->enable_area( Debug::AREA_FORM_PROCESSOR );
		Debug::log_form( 'Form test' );
		$this->assertCount( 1, $this->logged );
	}

	public function test_log_rest_api_delegates(): void {
		$this->enable_area( Debug::AREA_REST_API );
		Debug::log_rest_api( 'API test' );
		$this->assertCount( 1, $this->logged );
	}

	public function test_log_migrations_delegates(): void {
		$this->enable_area( Debug::AREA_MIGRATIONS );
		Debug::log_migrations( 'Migration test' );
		$this->assertCount( 1, $this->logged );
	}

	public function test_log_activity_log_delegates(): void {
		$this->enable_area( Debug::AREA_ACTIVITY_LOG );
		Debug::log_activity_log( 'Activity test' );
		$this->assertCount( 1, $this->logged );
	}

	// ==================================================================
	// Constants — verify all areas defined
	// ==================================================================

	public function test_all_area_constants_defined(): void {
		$ref = new \ReflectionClass( Debug::class );
		$constants = $ref->getConstants();
		$areas = array_filter( $constants, function ( $k ) {
			return str_starts_with( $k, 'AREA_' );
		}, ARRAY_FILTER_USE_KEY );
		// 6.6.4 follow-up (#361 Sprint 1) — added AREA_BROWSER_ENV
		// as the 15th area. If this assertion fires, double-check
		// whether a new area was added intentionally (update the
		// count + the Settings → Debug tab grouping in
		// includes/settings/views/ffc-tab-advanced.php) or
		// accidentally (remove the const).
		$this->assertCount( 15, $areas );
	}

	// ==================================================================
	// PII / credential redaction in array payloads
	// ==================================================================

	public function test_log_masks_email_value(): void {
		$this->enable_area( Debug::AREA_EMAIL_HANDLER );
		Debug::log( Debug::AREA_EMAIL_HANDLER, 'Sent', array( 'email' => 'someone@example.com' ) );
		$this->assertStringNotContainsString( 'someone@example.com', $this->logged[0] );
		// Length hint preserved so the field shape is still debuggable.
		$this->assertStringContainsString( 'len:19', $this->logged[0] );
	}

	public function test_log_masks_cpf_and_cpf_rf(): void {
		$this->enable_area( Debug::AREA_FORM_PROCESSOR );
		Debug::log( Debug::AREA_FORM_PROCESSOR, 'Doc', array(
			'cpf'    => '12345678901',
			'cpf_rf' => '7654321',
		) );
		$this->assertStringNotContainsString( '12345678901', $this->logged[0] );
		$this->assertStringNotContainsString( '7654321', $this->logged[0] );
	}

	public function test_log_masks_auth_code_and_magic_token(): void {
		$this->enable_area( Debug::AREA_PDF_GENERATOR );
		Debug::log( Debug::AREA_PDF_GENERATOR, 'Generated', array(
			'auth_code'   => 'ABCDEF123456',
			'magic_token' => 'tok_supersecret_value_xyz',
		) );
		$this->assertStringNotContainsString( 'ABCDEF123456', $this->logged[0] );
		$this->assertStringNotContainsString( 'tok_supersecret_value_xyz', $this->logged[0] );
	}

	public function test_log_strips_magic_token_from_url(): void {
		$this->enable_area( Debug::AREA_PDF_GENERATOR );
		Debug::log( Debug::AREA_PDF_GENERATOR, 'Built URL', array(
			'target_url' => 'https://example.com/valid?magic_token=abc123xyz&foo=bar',
		) );
		$this->assertStringNotContainsString( 'abc123xyz', $this->logged[0] );
		$this->assertStringContainsString( '[redacted]', $this->logged[0] );
		$this->assertStringContainsString( 'foo=bar', $this->logged[0] );
	}

	public function test_log_preserves_non_sensitive_fields(): void {
		$this->enable_area( Debug::AREA_ADMIN );
		Debug::log( Debug::AREA_ADMIN, 'Op', array(
			'submission_id' => 42,
			'form_id'       => 7,
			'context'       => 'submission',
		) );
		$this->assertStringContainsString( 'submission_id', $this->logged[0] );
		$this->assertStringContainsString( '42', $this->logged[0] );
		$this->assertStringContainsString( 'form_id', $this->logged[0] );
		$this->assertStringContainsString( 'submission', $this->logged[0] );
	}

	public function test_log_redacts_recursively(): void {
		$this->enable_area( Debug::AREA_REST_API );
		Debug::log( Debug::AREA_REST_API, 'Nested', array(
			'request' => array(
				'meta'  => array( 'email' => 'leak@example.com' ),
				'count' => 3,
			),
		) );
		$this->assertStringNotContainsString( 'leak@example.com', $this->logged[0] );
		$this->assertStringContainsString( 'count', $this->logged[0] );
		$this->assertStringContainsString( '3', $this->logged[0] );
	}

	public function test_log_short_value_fully_masked(): void {
		$this->enable_area( Debug::AREA_FORM_PROCESSOR );
		Debug::log( Debug::AREA_FORM_PROCESSOR, 'Short', array( 'token' => 'abc' ) );
		$this->assertStringNotContainsString( '=> abc', $this->logged[0] );
	}

	public function test_log_keys_are_case_insensitive(): void {
		$this->enable_area( Debug::AREA_FORM_PROCESSOR );
		Debug::log( Debug::AREA_FORM_PROCESSOR, 'Mixed', array( 'Email' => 'mixed@example.com' ) );
		$this->assertStringNotContainsString( 'mixed@example.com', $this->logged[0] );
	}

	public function test_log_empty_string_preserved_not_masked(): void {
		$this->enable_area( Debug::AREA_FORM_PROCESSOR );
		Debug::log( Debug::AREA_FORM_PROCESSOR, 'Empty', array( 'email' => '' ) );
		// Empty string should remain empty, not get a length hint.
		$this->assertStringNotContainsString( 'len:0', $this->logged[0] );
	}

	// ==================================================================
	// No client IP reaches the log (#1441)
	// ==================================================================

	/**
	 * The address is hashed at the SINK, so no call site can leak one.
	 *
	 * Seven `Debug::log_*` payloads passed a raw address under the key `ip`, and
	 * the reason was not seven mistakes: `ip` was simply missing from the
	 * redaction list while email, cpf, rf, phone, tokens and passwords were all
	 * on it. Fixing the call sites would have left the eighth to be written.
	 *
	 * @dataProvider provider_ip_keys
	 * @param string $key A key the redactor must treat as an address.
	 */
	public function test_an_ip_is_hashed_and_never_logged( string $key ): void {
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		$this->enable_area( 'debug_frontend' );

		Debug::log_frontend( 'probe', array( $key => '152.249.52.217' ) );

		$this->assertCount( 1, $this->logged );
		$out = $this->logged[0];

		$this->assertStringNotContainsString( '152.249.52.217', $out, 'The address itself reached the log.' );
		$this->assertStringNotContainsString( '152.249.52', $out, 'Three octets is still the visitor.' );
		$this->assertStringContainsString( $key . '_hash', $out, 'The hash must be labelled as one, matching the activity log context.' );
		$this->assertStringContainsString(
			substr( hash( 'sha256', '152.249.52.217' . 'test-salt' ), 0, 16 ),
			$out,
			'The logged value must be the salted hash of the address.'
		);
	}

	/**
	 * @return array<int, array<int, string>>
	 */
	public static function provider_ip_keys(): array {
		return array(
			array( 'ip' ),
			array( 'user_ip' ),
			array( 'client_ip' ),
			array( 'remote_addr' ),
		);
	}

	/**
	 * The hash is SALTED, which is the difference between a hash and a lookup.
	 *
	 * An IPv4 has 2^32 possibilities, so a bare `sha256` truncation is reversible
	 * by exhaustion in seconds. A test that only checked "the address is absent"
	 * passes against an unsalted hash too.
	 */
	public function test_the_ip_hash_is_salted(): void {
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		$this->enable_area( 'debug_frontend' );

		Debug::log_frontend( 'probe', array( 'ip' => '152.249.52.217' ) );

		$this->assertStringNotContainsString(
			substr( hash( 'sha256', '152.249.52.217' ), 0, 16 ),
			$this->logged[0],
			'An unsalted hash of an IPv4 is a lookup table, not anonymisation.'
		);
	}

	/**
	 * An empty address logs an empty hash rather than the hash of ''.
	 */
	public function test_an_absent_ip_hashes_to_nothing(): void {
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		$this->enable_area( 'debug_frontend' );

		Debug::log_frontend( 'probe', array( 'ip' => '' ) );

		$this->assertStringNotContainsString(
			substr( hash( 'sha256', 'test-salt' ), 0, 16 ),
			$this->logged[0],
			'Hashing an empty address produces a constant that reads like a real visitor.'
		);
	}

	/**
	 * Nested payloads are covered too: the redactor recurses, and an address one
	 * level down is the same leak.
	 */
	public function test_a_nested_ip_is_hashed(): void {
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		$this->enable_area( 'debug_frontend' );

		Debug::log_frontend( 'probe', array( 'request' => array( 'ip' => '152.249.52.217' ) ) );

		$this->assertStringNotContainsString( '152.249.52.217', $this->logged[0] );
	}

	/**
	 * Every key the codebase actually uses for an address is on the sink's list.
	 *
	 * The behavioural tests above prove the four names the redactor knows. This
	 * one proves the redactor knows the names the CALL SITES use, so an eighth
	 * payload inventing `visitor_addr` fails here instead of leaking quietly. The
	 * scan is paren-matched rather than line-based, because these payloads span
	 * several lines.
	 */
	public function test_no_debug_payload_names_an_address_the_sink_does_not_know(): void {
		$known   = array( 'ip', 'user_ip', 'client_ip', 'remote_addr' );
		$root    = dirname( __DIR__, 2 ) . '/includes';
		$scanned = 0;
		$unknown = array();

		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			$src = (string) file_get_contents( $file->getPathname() );

			foreach ( $this->debug_log_payloads( $src ) as $args ) {
				++$scanned;

				if ( ! preg_match( '/get_user_ip\s*\(|REMOTE_ADDR|HTTP_X_FORWARDED_FOR|HTTP_CF_CONNECTING_IP/', $args ) ) {
					continue;
				}

				preg_match_all( '/[\'"]([a-z_]+)[\'"]\s*=>\s*[^,]*(?:get_user_ip\s*\(|REMOTE_ADDR|HTTP_X_FORWARDED_FOR|HTTP_CF_CONNECTING_IP)/', $args, $m );
				foreach ( $m[1] as $key ) {
					if ( ! in_array( $key, $known, true ) ) {
						$unknown[] = str_replace( dirname( __DIR__, 2 ) . '/', '', $file->getPathname() ) . ": '{$key}'";
					}
				}
			}
		}

		$this->assertGreaterThan(
			50,
			$scanned,
			'The Debug::log_* scan collapsed -- there are around a hundred of these calls, so a handful means the paren match broke.'
		);

		$this->assertSame(
			array(),
			array_values( array_unique( $unknown ) ),
			"A Debug payload passes an address under a key the redactor does not know, so it reaches the log in full."
				. " Add the key to `Debug::IP_KEYS` (and to this test's list):\n  " . implode( "\n  ", array_unique( $unknown ) )
		);
	}

	/**
	 * Every `Debug::log_*( … )` argument list in one file, paren-matched.
	 *
	 * @param string $src PHP source.
	 * @return array<int, string>
	 */
	private function debug_log_payloads( string $src ): array {
		$out = array();

		if ( ! preg_match_all( '/Debug::log_[a-z_]+\s*\(/', $src, $m, PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}

		foreach ( $m[0] as $hit ) {
			$i     = strpos( $src, '(', $hit[1] );
			$depth = 0;
			$j     = $i;
			$len   = strlen( $src );

			while ( $j < $len ) {
				$c = $src[ $j ];
				if ( "'" === $c || '"' === $c ) {
					$q = $c;
					++$j;
					while ( $j < $len ) {
						if ( '\\' === $src[ $j ] ) {
							$j += 2;
							continue;
						}
						if ( $src[ $j ] === $q ) {
							break;
						}
						++$j;
					}
				} elseif ( '(' === $c ) {
					++$depth;
				} elseif ( ')' === $c ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				}
				++$j;
			}

			$out[] = substr( $src, $i, $j - $i + 1 );
		}

		return $out;
	}
}
