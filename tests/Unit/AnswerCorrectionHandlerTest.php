<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\AnswerCorrectionHandler;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\Identifiers;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Correcting one answer of an issued certificate (#2241): a reason is
 * required, the change is logged with personal values masked, the identity
 * of the certificate stays out of reach, and nobody is e-mailed.
 *
 * @covers \FreeFormCertificate\Admin\AnswerCorrectionHandler
 */
class AnswerCorrectionHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $handler;

	/**
	 * What update_submission() received, keyed by submission id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $updates = array();

	/** @var array<int, string> */
	private array $cache_deleted = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias( static fn( $k, $d = false ) => 'ffc_settings' === $k ? ActivityLogBuffer::ENABLED_SETTINGS : $d );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( $v ) => (string) $v );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'is_email' )->alias( static fn( $e ) => filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false );
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key ) {
				$this->cache_deleted[] = $key;
				return true;
			}
		);

		$this->updates = array();
		$this->handler = Mockery::mock( 'FreeFormCertificate\\Submissions\\SubmissionHandler' );
		$this->handler->shouldReceive( 'get_submission' )->andReturn(
			array(
				'id'          => 5,
				'form_id'     => 3,
				'auth_code'   => 'ABCD1234EFGH',
				'magic_token' => str_repeat( 'ab', 16 ),
				'data'        => json_encode(
					array(
						'name'      => 'Ana Silv',
						'phone'     => '11987654321',
						'cpf_rf'    => Identifiers::CPF_A,
						'auth_code' => 'ABCD1234EFGH',
						'options'   => array( 'a', 'b' ),
					)
				),
			)
		);
		$this->handler->shouldReceive( 'update_submission' )->andReturnUsing(
			function ( $id, $email, $data ) {
				$this->updates[ $id ] = array(
					'email' => $email,
					'data'  => $data,
				);
				return true;
			}
		);
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function corrector(): AnswerCorrectionHandler {
		return new AnswerCorrectionHandler( $this->handler );
	}

	public function test_a_correction_saves_the_value_marks_it_and_logs_it(): void {
		$this->assertSame( 'answer_corrected', $this->corrector()->correct( 5, 'name', 'Ana Silva', 'Typo in the surname.' ) );

		$this->assertSame( '', $this->updates[5]['email'], 'The stored e-mail is left alone.' );
		$this->assertSame( 'Ana Silva', $this->updates[5]['data']['name'] );
		$this->assertSame( array( 'name' ), $this->updates[5]['data'][ AnswerCorrectionHandler::CORRECTED_KEY ] );
		$this->assertSame( Identifiers::CPF_A, $this->updates[5]['data']['cpf_rf'], 'Every other answer is kept.' );

		$logged = ActivityLogBuffer::contexts();
		$this->assertCount( 1, $logged );
		$this->assertSame( 'submission_answer_corrected', $logged[0]['action'] );
		$this->assertSame( 'name', $logged[0]['context']['field'] );
		$this->assertSame( 'Typo in the surname.', $logged[0]['context']['reason'] );
		$this->assertSame( 'Ana Silv', $logged[0]['context']['old_value'] );
		$this->assertSame( 'Ana Silva', $logged[0]['context']['new_value'] );

		// Verification reads the row from these keys, so they must not serve the old answer.
		$this->assertContains( 'auth_ABCD1234EFGH', $this->cache_deleted );
		$this->assertContains( 'token_' . str_repeat( 'ab', 16 ), $this->cache_deleted );
	}

	public function test_a_personal_value_is_logged_masked(): void {
		$this->corrector()->correct( 5, 'phone', '11912345678', 'Wrong number typed.' );

		$context = ActivityLogBuffer::contexts()[0]['context'];
		$this->assertStringNotContainsString( '11987654321', (string) json_encode( $context ) );
		$this->assertStringNotContainsString( '11912345678', (string) json_encode( $context ) );
	}

	public function test_a_correction_needs_a_reason(): void {
		$this->assertSame( 'answer_no_reason', $this->corrector()->correct( 5, 'name', 'Ana Silva', '  typo  ' ) );
		$this->assertSame( array(), $this->updates );
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	/**
	 * @dataProvider provider_locked_keys
	 */
	public function test_the_identity_of_the_certificate_is_refused( string $field ): void {
		$this->assertSame( 'answer_not_correctable', $this->corrector()->correct( 5, $field, 'x', 'A good reason here.' ) );
		$this->assertSame( array(), $this->updates );
	}

	/** @return array<string, array{string}> */
	public static function provider_locked_keys(): array {
		return array(
			'cpf/rf'          => array( 'cpf_rf' ),
			'e-mail'          => array( 'email' ),
			'auth code'       => array( 'auth_code' ),
			'internal key'    => array( '_quiz_score' ),
			'not an answer'   => array( 'nonexistent' ),
			'a list answer'   => array( 'options' ),
		);
	}

	public function test_an_unchanged_value_writes_nothing(): void {
		$this->assertSame( 'answer_unchanged', $this->corrector()->correct( 5, 'name', 'Ana Silv', 'A good reason here.' ) );
		$this->assertSame( array(), $this->updates );
	}

	public function test_only_managers_may_correct(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_edit_certificates' === $cap );
		$this->assertFalse( AnswerCorrectionHandler::current_user_can_correct(), 'An Operator edits but does not correct answers.' );

		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_manage_certificates' === $cap );
		$this->assertTrue( AnswerCorrectionHandler::current_user_can_correct() );
	}
}
