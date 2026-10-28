<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAccountEmail;

/**
 * The masked account email of the audience screens (#1824), at every tier
 * of the shared PII policy, which runs for real here: the viewer's caps and
 * roles are what the stubs vary. AudienceReader and AudienceScheduleRepository
 * are the real classes too, over a `$wpdb` double that answers their count
 * queries by the table and the bound user id.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAccountEmail
 * @preserveGlobalState disabled
 */
class AudienceAccountEmailTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * The listed account whose email is drawn.
	 */
	private const MEMBER = 7;

	/**
	 * The viewer.
	 */
	private const VIEWER = 2;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $this->had_wpdb ? $GLOBALS['wpdb'] : null;

		// The request-wide nonce memo: a render in an earlier test would
		// otherwise have created it already.
		$nonce = new \ReflectionProperty( AudienceAccountEmail::class, 'nonce' );
		$nonce->setAccessible( true );
		$nonce->setValue( null, null );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== strpos( (string) $e, '@' ) );
		Functions\when( 'get_current_user_id' )->justReturn( self::VIEWER );
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The viewer holds these caps and roles.
	 *
	 * @param list<string> $caps  Capabilities.
	 * @param list<string> $roles Roles.
	 */
	private function viewer( array $caps, array $roles = array() ): void {
		Functions\when( 'user_can' )->alias( static fn( $uid, $cap ) => in_array( $cap, $caps, true ) );
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'roles' => $roles ) );
		Functions\when( 'wp_create_nonce' )->justReturn( 'reveal-nonce' );
	}

	/**
	 * @return array<string, array{0: list<string>, 1: list<string>, 2: int, 3: bool}>
	 */
	public static function tiers(): array {
		return array(
			'WordPress admin (reveal)'               => array( array( 'manage_options' ), array(), self::MEMBER, true ),
			// The audience module's own cap since #1863.
			'audiences PII cap (reveal)'             => array( array( 'ffc_view_audiences', 'ffc_view_audiences_pii' ), array( 'ffc_audiences_manager' ), self::MEMBER, true ),
			'the account owner (own data)'           => array( array(), array(), self::VIEWER, true ),
			'audiences operator (masked)'            => array( array( 'ffc_view_audiences', 'ffc_import_audiences' ), array( 'ffc_audiences_operator' ), self::MEMBER, false ),
			'appointments PII cap only (masked)'     => array( array( 'ffc_view_appointments_pii' ), array( 'ffc_appointments_admin' ), self::MEMBER, false ),
			'certificates PII cap only (masked)'     => array( array( 'ffc_view_certificates_pii' ), array( 'ffc_certificates_admin' ), self::MEMBER, false ),
		);
	}

	/**
	 * @dataProvider tiers
	 *
	 * @param list<string> $caps    Viewer caps.
	 * @param list<string> $roles   Viewer roles.
	 * @param int          $account The listed account.
	 * @param bool         $button  Whether a Reveal button is drawn.
	 */
	public function test_every_tier_gets_the_masked_address_and_only_revealers_a_button( array $caps, array $roles, int $account, bool $button ): void {
		$this->viewer( $caps, $roles );

		$html = AudienceAccountEmail::render( $account, 'member@example.org' );

		$this->assertStringNotContainsString( 'member@example.org', $html, 'no tier receives the clear address' );
		$this->assertStringContainsString( '<span class="ffc-pii-row"><span class="ffc-pii-value" data-field="email">m***@example.org</span>', $html );
		$this->assertSame( $button, str_contains( $html, 'ffc-reveal-pii' ) );
		if ( $button ) {
			$this->assertStringContainsString(
				'<button type="button" class="ffc-btn ffc-btn-neutral ffc-icon-eye ffc-reveal-pii" data-type="audience_account" data-field="email" data-submission-id="' . $account . '" data-nonce="reveal-nonce">Reveal</button>',
				$html
			);
		}
	}

	/**
	 * The nonce is memoised in a static for the request; setUp() clears it, so
	 * this render is the first of its request.
	 */
	public function test_the_nonce_is_created_once_for_every_row(): void {
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'roles' => array() ) );
		Functions\expect( 'wp_create_nonce' )->once()->with( 'ffc_reveal_pii_nonce' )->andReturn( 'n1' );

		$first  = AudienceAccountEmail::render( 7, 'a@x.org' );
		$second = AudienceAccountEmail::render( 8, 'b@x.org' );

		$this->assertStringContainsString( 'data-nonce="n1"', $first );
		$this->assertStringContainsString( 'data-nonce="n1"', $second );
	}

	public function test_an_address_the_mask_cannot_parse_is_not_printed(): void {
		$this->viewer( array() );

		$html = AudienceAccountEmail::render( self::MEMBER, 'not-an-address' );

		$this->assertStringNotContainsString( 'not-an-address', $html );
		$this->assertStringContainsString( 'data-field="email">n***</span>', $html );
	}

	public function test_no_email_draws_nothing(): void {
		$this->assertSame( '', AudienceAccountEmail::render( self::MEMBER, '' ) );
	}

	public function test_an_account_is_listed_as_a_member_or_a_calendar_user(): void {
		$queried = array();
		$wpdb    = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( string $sql, ...$args ): string => $sql . '|' . implode( '|', $args )
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( string $sql ) use ( &$queried ) {
				$queried[] = $sql;
				if ( 'SELECT COUNT(*) FROM %i WHERE user_id = %d|wp_ffc_audience_members|7' === $sql ) {
					return '1';
				}
				if ( 'SELECT COUNT(*) FROM %i WHERE user_id = %d|wp_ffc_audience_schedule_permissions|8' === $sql ) {
					return '2';
				}
				if ( 1 === preg_match( '/^SELECT COUNT\(\*\) FROM %i WHERE user_id = %d\|wp_ffc_audience_(members|schedule_permissions)\|\d+$/', $sql ) ) {
					return '0';
				}
				$this->fail( 'Unexpected get_var(): ' . $sql );
			}
		);
		$GLOBALS['wpdb'] = $wpdb;

		$this->assertTrue( AudienceAccountEmail::is_listed( 7 ), 'a member' );
		$this->assertTrue( AudienceAccountEmail::is_listed( 8 ), 'a calendar user' );
		$this->assertFalse( AudienceAccountEmail::is_listed( 9 ), 'an account no audience screen lists' );
		$this->assertFalse( AudienceAccountEmail::is_listed( 0 ), 'no account' );
		$this->assertSame(
			array(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d|wp_ffc_audience_members|7',
				'SELECT COUNT(*) FROM %i WHERE user_id = %d|wp_ffc_audience_members|8',
				'SELECT COUNT(*) FROM %i WHERE user_id = %d|wp_ffc_audience_schedule_permissions|8',
				'SELECT COUNT(*) FROM %i WHERE user_id = %d|wp_ffc_audience_members|9',
				'SELECT COUNT(*) FROM %i WHERE user_id = %d|wp_ffc_audience_schedule_permissions|9',
			),
			$queried,
			'a member short-circuits the permission lookup, and no account queries nothing'
		);
	}
}
