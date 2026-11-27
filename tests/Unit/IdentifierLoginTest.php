<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Tests\Support\FakeCredentials;
use FreeFormCertificate\Tests\Support\Identifiers;
use FreeFormCertificate\UserDashboard\IdentifierLogin;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Signing in and resetting the password with a CPF or an RF (#2960).
 *
 * @covers \FreeFormCertificate\UserDashboard\IdentifierLogin
 * @covers \FreeFormCertificate\UserDashboard\UserCreator::user_ids_by_hash
 */
class IdentifierLoginTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const CPF = '518.178.420-80';

	private const RF = '518.178-0';

	/** @var mixed The $wpdb the test replaced. */
	private $wpdb_backup = null;

	/** @var array<string, list<int>> User ids each hash belongs to, per table. */
	private array $owners = array();

	/** @var array<int, string> The password each account accepts. */
	private array $passwords = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( string $sql, ...$args ): array => $args
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			fn( array $args ): array => $this->owners[ $args[0] . '|' . $args[1] . '|' . $args[2] ] ?? array()
		);

		Functions\when( 'get_user_by' )->alias(
			static function ( $field, $id ) {
				$user             = new \WP_User( (int) $id );
				$user->user_login = 'user' . $id;
				return $user;
			}
		);
		Functions\when( 'wp_authenticate_username_password' )->alias(
			function ( $user, $login, $password ) {
				$id = (int) substr( (string) $login, 4 );
				if ( ( $this->passwords[ $id ] ?? null ) === $password ) {
					$found             = new \WP_User( $id );
					$found->user_login = (string) $login;
					return $found;
				}
				return new \WP_Error( 'incorrect_password', 'core' );
			}
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_backup;
		unset( $_POST['user_login'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Give an identifier to accounts, in the identity index.
	 *
	 * @param string    $key   `cpf` or `rf`.
	 * @param string    $value The identifier as typed.
	 * @param list<int> $ids   The accounts.
	 */
	private function own( string $key, string $value, array $ids, string $table = 'wp_ffc_user_profiles' ): void {
		$hash = (string) SensitiveFieldRegistry::hash_identifier( $key, preg_replace( '/\D/', '', $value ) );
		$this->owners[ $table . '|' . $key . '_hash|' . $hash ] = $ids;
	}

	public function test_only_a_cpf_or_an_rf_is_read_as_one(): void {
		$this->assertSame( 'cpf_hash', IdentifierLogin::identifier_hash( self::CPF )[0] ?? null );
		$this->assertSame( 'cpf_hash', IdentifierLogin::identifier_hash( Identifiers::CPF_A )[0] ?? null );
		$this->assertSame( 'rf_hash', IdentifierLogin::identifier_hash( self::RF )[0] ?? null );
		$this->assertSame(
			IdentifierLogin::identifier_hash( Identifiers::CPF_A ),
			IdentifierLogin::identifier_hash( self::CPF ),
			'the mask does not change the hash'
		);

		foreach ( array( '', 'maria', 'maria@example.org', '12345', '123456789012', 'cpf 51817842080', '518178420-8x' ) as $input ) {
			$this->assertNull( IdentifierLogin::identifier_hash( $input ), $input );
		}
	}

	public function test_a_cpf_signs_in_its_one_account(): void {
		$this->own( 'cpf', self::CPF, array( 7 ) );
		$this->passwords[7] = FakeCredentials::PASSWORD;

		$user = IdentifierLogin::authenticate( new \WP_Error( 'invalid_username', 'core' ), self::CPF, FakeCredentials::PASSWORD );

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( 7, $user->ID );
	}

	public function test_an_rf_known_only_from_a_submission_signs_in_too(): void {
		$this->own( 'rf', self::RF, array( 9 ), 'wp_ffc_submissions' );
		$this->passwords[9] = FakeCredentials::PASSWORD;

		$user = IdentifierLogin::authenticate( null, Identifiers::RF_A, FakeCredentials::PASSWORD );

		$this->assertSame( 9, $user->ID );
	}

	public function test_a_wrong_password_keeps_wordpress_s_own_answer(): void {
		$this->own( 'cpf', self::CPF, array( 7 ) );
		$this->passwords[7] = FakeCredentials::PASSWORD;
		$core               = new \WP_Error( 'invalid_username', 'core' );

		$this->assertSame( $core, IdentifierLogin::authenticate( $core, self::CPF, 'fake-wrong' ) );
		$this->assertSame( $core, IdentifierLogin::authenticate( $core, '204.569.420-84', FakeCredentials::PASSWORD ), 'an unknown CPF answers the same' );
	}

	public function test_the_password_settles_a_cpf_two_accounts_share(): void {
		$this->own( 'cpf', self::CPF, array( 7 ) );
		$this->own( 'cpf', self::CPF, array( 7, 8 ), 'wp_ffc_submissions' );
		$this->passwords[7] = 'fake-seven';
		$this->passwords[8] = 'fake-eight';

		$this->assertSame( 8, IdentifierLogin::authenticate( null, self::CPF, 'fake-eight' )->ID );
		$this->assertSame( 7, IdentifierLogin::authenticate( null, self::CPF, 'fake-seven' )->ID );
		$this->assertNull( IdentifierLogin::authenticate( null, self::CPF, 'fake-neither' ), 'nobody accepts it: nothing said' );
	}

	public function test_a_cpf_two_accounts_share_with_one_password_asks_for_an_administrator(): void {
		$this->own( 'cpf', self::CPF, array( 7, 8 ) );
		$this->passwords[7] = FakeCredentials::PASSWORD;
		$this->passwords[8] = FakeCredentials::PASSWORD;

		$result = IdentifierLogin::authenticate( null, self::CPF, FakeCredentials::PASSWORD );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( IdentifierLogin::AMBIGUOUS, $result->get_error_code() );
	}

	public function test_a_real_username_and_an_empty_password_are_left_alone(): void {
		$this->own( 'cpf', self::CPF, array( 7 ) );
		$this->passwords[7] = '';
		$signed             = new \WP_User( 3 );

		$this->assertSame( $signed, IdentifierLogin::authenticate( $signed, self::CPF, FakeCredentials::PASSWORD ), 'a username that is all digits already won' );
		$this->assertNull( IdentifierLogin::authenticate( null, self::CPF, '' ) );
	}

	public function test_lost_password_finds_the_one_account_or_asks_for_an_administrator(): void {
		$this->own( 'cpf', self::CPF, array( 7 ) );
		$this->own( 'rf', self::RF, array( 7, 8 ) );
		// The bootstrap's WP_Error has no add(); the form's does.
		$errors = new class() extends \WP_Error {
			/** @var list<string> */
			public array $added = array();

			public function add( $code, $message ): void {
				$this->added[] = $code;
			}
		};

		$_POST['user_login'] = self::CPF;
		$this->assertSame( 7, IdentifierLogin::lost_password_user( false, $errors )->ID );

		$found = new \WP_User( 4 );
		$this->assertSame( $found, IdentifierLogin::lost_password_user( $found, $errors ), 'WordPress\'s own find stands' );

		$_POST['user_login'] = self::RF;
		$this->assertFalse( IdentifierLogin::lost_password_user( false, $errors ) );
		$this->assertSame( array( IdentifierLogin::AMBIGUOUS ), $errors->added );

		$_POST['user_login'] = 'nobody';
		$this->assertFalse( IdentifierLogin::lost_password_user( false, new \WP_Error() ) );
	}

	public function test_the_login_label_names_the_identifiers_on_the_login_screen_only(): void {
		$this->assertSame( IdentifierLogin::label(), IdentifierLogin::login_label( 'Nome de usuário ou endereço de e-mail', 'Username or Email Address', 'default' ) );
		$this->assertSame( 'Senha', IdentifierLogin::login_label( 'Senha', 'Password', 'default' ) );
		$this->assertSame( 'x', IdentifierLogin::login_label( 'x', 'Username or Email Address', 'other-plugin' ) );

		$hooks = array();
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $callback, $priority = 10 ) use ( &$hooks ) {
				$hooks[ $hook ] = $priority;
			}
		);
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback, $priority = 10 ) use ( &$hooks ) {
				$hooks[ $hook ] = $priority;
			}
		);
		IdentifierLogin::init();
		$this->assertSame( 30, $hooks['authenticate'], 'after WordPress\'s own username and e-mail checks' );
		$this->assertArrayHasKey( 'lostpassword_user_data', $hooks );
		$this->assertArrayHasKey( 'login_init', $hooks );
		$this->assertArrayHasKey( 'site_pwa_login_username_label', $hooks );
		$this->assertArrayNotHasKey( 'gettext', $hooks, 'the label filter waits for the login screen' );

		IdentifierLogin::relabel();
		$this->assertArrayHasKey( 'gettext', $hooks );
	}
}
