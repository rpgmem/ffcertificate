<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Reregistration\ProfileCompletion;
use FreeFormCertificate\Tests\Support\Identifiers;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The details the app asks for before anything else (#2962).
 *
 * @covers \FreeFormCertificate\Reregistration\ProfileCompletion
 */
class ProfileCompletionTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var mixed The $wpdb the test replaced. */
	private $wpdb_backup = null;

	/** @var array<string, list<int>> User ids each identifier hash belongs to. */
	private array $owners = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $value ) => trim( strip_tags( (string) $value ) ) );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof \WP_Error );

		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( string $sql, ...$args ): array => $args );
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			fn( array $args ): array => 'wp_ffc_user_profiles' === $args[0] ? ( $this->owners[ $args[1] . '|' . $args[2] ] ?? array() ) : array()
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A field definition as the reader returns it.
	 */
	private function field( int $id, string $key, string $profile_key = '', string $type = 'text' ): object {
		return (object) array(
			'id'                => $id,
			'field_key'         => $key,
			'field_label'       => ucfirst( str_replace( '_', ' ', $key ) ),
			'field_type'        => $type,
			'field_profile_key' => $profile_key,
			'is_required'       => 1,
			'is_sensitive'      => in_array( $key, array( 'cpf', 'rf' ), true ) ? 1 : 0,
			'validation_rules'  => null,
			'field_options'     => null,
		);
	}

	/**
	 * The four standard definitions, the unit stored as an answer.
	 *
	 * @return array<string, object>
	 */
	private function fields(): array {
		return array(
			'display_name'    => $this->field( 1, 'display_name', 'display_name' ),
			'cpf'             => $this->field( 2, 'cpf', 'cpf' ),
			'rf'              => $this->field( 3, 'rf', 'rf' ),
			'unidade_lotacao' => $this->field( 4, 'unidade_lotacao' ),
		);
	}

	private function own( string $key, string $digits, array $ids ): void {
		$this->owners[ $key . '_hash|' . SensitiveFieldRegistry::hash_identifier( $key, $digits ) ] = $ids;
	}

	public function test_everything_is_missing_on_an_imported_account(): void {
		$this->assertSame(
			array( ProfileCompletion::MISSING_NAME, ProfileCompletion::MISSING_IDENTITY, ProfileCompletion::MISSING_UNIT ),
			ProfileCompletion::evaluate( $this->fields(), array( 1 => 'maria.silva' ), 'maria.silva' ),
			'a name equal to the login is no name'
		);
	}

	public function test_a_cpf_or_an_rf_is_enough_and_nothing_is_asked_twice(): void {
		$values = array(
			1 => 'Maria da Silva',
			3 => Identifiers::RF_A,
			4 => 'EMEF Exemplo',
		);
		$this->assertSame( array(), ProfileCompletion::evaluate( $this->fields(), $values, 'maria.silva' ) );

		$values[3] = '';
		$values[2] = Identifiers::CPF_A;
		$this->assertSame( array(), ProfileCompletion::evaluate( $this->fields(), $values, 'maria.silva' ) );
	}

	public function test_a_field_the_install_does_not_define_is_not_asked_for(): void {
		$this->assertSame( array(), ProfileCompletion::evaluate( array(), array(), 'maria' ) );

		$fields = $this->fields();
		unset( $fields['unidade_lotacao'], $fields['cpf'], $fields['rf'] );
		$this->assertSame( array( ProfileCompletion::MISSING_NAME ), ProfileCompletion::evaluate( $fields, array(), 'maria' ) );
	}

	public function test_a_complete_save_writes_the_profile_and_the_answer(): void {
		$missing = array( ProfileCompletion::MISSING_NAME, ProfileCompletion::MISSING_IDENTITY, ProfileCompletion::MISSING_UNIT );
		$plan    = ProfileCompletion::plan(
			7,
			$missing,
			$this->fields(),
			array(
				'display_name'    => ' Maria da Silva ',
				'cpf'             => '518.178.420-80',
				'rf'              => '',
				'unidade_lotacao' => 'EMEF Exemplo',
			)
		);

		$this->assertSame( array(), $plan['errors'] );
		$this->assertSame(
			array(
				'display_name' => 'Maria da Silva',
				'cpf'          => Identifiers::CPF_A,
			),
			$plan['profile']
		);
		$this->assertSame( array( 'field_4' => 'EMEF Exemplo' ), $plan['answers'] );
	}

	public function test_only_what_is_missing_is_written(): void {
		$plan = ProfileCompletion::plan(
			7,
			array( ProfileCompletion::MISSING_UNIT ),
			$this->fields(),
			array(
				'display_name'    => 'Someone Else',
				'cpf'             => Identifiers::CPF_B,
				'unidade_lotacao' => 'EMEF Exemplo',
			)
		);

		$this->assertSame( array(), $plan['profile'], 'a name or CPF already stored is never overwritten from here' );
		$this->assertSame( array( 'field_4' => 'EMEF Exemplo' ), $plan['answers'] );
	}

	public function test_each_missing_part_says_what_to_fix(): void {
		$plan = ProfileCompletion::plan(
			7,
			array( ProfileCompletion::MISSING_NAME, ProfileCompletion::MISSING_IDENTITY, ProfileCompletion::MISSING_UNIT ),
			$this->fields(),
			array( 'display_name' => 'Maria' )
		);

		$this->assertSame(
			array( 'Type your full name, first and last.', 'Type your CPF or your RF.', 'Fill in Unidade lotacao.' ),
			$plan['errors']
		);
		$this->assertSame( array(), $plan['profile'] );
		$this->assertSame( array(), $plan['answers'] );
	}

	public function test_an_invalid_cpf_or_rf_is_refused(): void {
		$plan = ProfileCompletion::plan(
			7,
			array( ProfileCompletion::MISSING_IDENTITY ),
			$this->fields(),
			array(
				'cpf' => Identifiers::CPF_BAD_CHECK_DIGIT,
				'rf'  => '12345',
			)
		);

		$this->assertSame( array( 'This CPF is not valid.', 'This RF is not valid.' ), $plan['errors'] );
	}

	public function test_a_cpf_on_another_account_is_refused_and_ones_own_is_not(): void {
		$this->own( 'cpf', Identifiers::CPF_A, array( 9 ) );

		$taken = ProfileCompletion::plan( 7, array( ProfileCompletion::MISSING_IDENTITY ), $this->fields(), array( 'cpf' => Identifiers::CPF_A ) );
		$this->assertSame( array( 'This CPF is already on another account. Ask an administrator.' ), $taken['errors'] );

		$own = ProfileCompletion::plan( 9, array( ProfileCompletion::MISSING_IDENTITY ), $this->fields(), array( 'cpf' => Identifiers::CPF_A ) );
		$this->assertSame( array(), $own['errors'], 'already this account\'s own' );
		$this->assertSame( array( 'cpf' => Identifiers::CPF_A ), $own['profile'] );
	}

	public function test_an_ffc_administrator_is_never_asked(): void {
		$user        = new \WP_User( 3 );
		$user->roles = array( ProfileCompletion::EXEMPT_ROLE );
		$this->assertFalse( ProfileCompletion::required( $user ) );
		$this->assertFalse( ProfileCompletion::required( new \WP_User( 0 ) ), 'nobody signed in' );
		$this->assertSame( array(), ProfileCompletion::save( $user, array( 'display_name' => 'X Y' ) ) );
	}

	public function test_the_screen_is_registered_as_required_with_the_app_only(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$screens = new class() {
			/** @var array<string, array<string, mixed>> */
			public array $added = array();

			public function add( string $id, array $args ): bool {
				$this->added[ $id ] = $args;
				return true;
			}
		};

		ProfileCompletion::register( $screens, 2 );
		$this->assertSame( array(), $screens->added, 'another contract version registers nothing' );

		ProfileCompletion::register( $screens, ProfileCompletion::API_VERSION );
		$this->assertSame( array( ProfileCompletion::SCREEN ), array_keys( $screens->added ) );
		$this->assertSame( array( ProfileCompletion::class, 'required' ), $screens->added[ ProfileCompletion::SCREEN ]['required'] );
		$this->assertSame( array( ProfileCompletion::class, 'render' ), $screens->added[ ProfileCompletion::SCREEN ]['render'] );
	}
}
