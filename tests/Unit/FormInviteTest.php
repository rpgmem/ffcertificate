<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use FreeFormCertificate\Frontend\FormInvite;
use FreeFormCertificate\Frontend\Submission\InviteGuard;
use FreeFormCertificate\Frontend\Submission\SubmissionContext;
use FreeFormCertificate\Frontend\Submission\SubmissionRejected;
use PHPUnit\Framework\TestCase;

/**
 * The certificate form's side of a personal invitation (#1948).
 *
 * @covers \FreeFormCertificate\Frontend\FormInvite
 * @covers \FreeFormCertificate\Frontend\Submission\InviteGuard
 */
class FormInviteTest extends TestCase {

	private const INVITE = array(
		'booking_id' => 5,
		'user_id'    => 7,
		'name'       => 'ana souza',
		'email'      => 'Ana@Example.org',
		'event'      => 'Workshop',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function fields(): array {
		return array(
			array(
				'name' => 'nome_completo',
				'type' => 'text',
			),
			array(
				'name' => 'email',
				'type' => 'email',
			),
			array(
				'name' => 'cpf_rf',
				'type' => 'text',
			),
			array(
				'name' => 'rating',
				'type' => 'select',
			),
		);
	}

	private function context( array $post, array $config = array() ): SubmissionContext {
		$ctx                  = new SubmissionContext();
		$ctx->input           = $post;
		$ctx->form_id         = 40;
		$ctx->form_config     = $config;
		$ctx->fields_config   = self::fields();
		$ctx->submission_data = array(
			'nome_completo' => 'Someone Else',
			'email'         => 'forged@example.org',
			'cpf_rf'        => '52998224725',
			'rating'        => 'Good',
		);
		$ctx->user_email      = 'forged@example.org';
		$ctx->val_cpf         = '52998224725';
		return $ctx;
	}

	public function test_invite_only_reads_its_restriction(): void {
		$this->assertTrue( FormInvite::invite_only( array( 'restrictions' => array( 'invite' => '1' ) ) ) );
		$this->assertFalse( FormInvite::invite_only( array( 'restrictions' => array( 'invite' => '0' ) ) ) );
		$this->assertFalse( FormInvite::invite_only( array() ) );
	}

	public function test_resolve_returns_the_answer_and_refuses_when_nobody_answers(): void {
		Filters\expectApplied( FormInvite::FILTER )->once()->with( null, 40, 'code', '123' )->andReturn( self::INVITE );
		$this->assertSame( self::INVITE, FormInvite::resolve( 40, 'code', '123' ) );

		$unanswered = FormInvite::resolve( 40, 'code' );
		$this->assertInstanceOf( \WP_Error::class, $unanswered, 'With the audience module off, a link is refused.' );
		$this->assertSame( 'invalid', $unanswered->get_error_code() );
	}

	public function test_a_refusal_passes_through(): void {
		Filters\expectApplied( FormInvite::FILTER )->andReturn( new \WP_Error( 'closed', 'Closed.' ) );

		$this->assertSame( 'closed', FormInvite::resolve( 40, 'code' )->get_error_code() );
	}

	public function test_name_and_email_are_filled_and_locked_and_nothing_else(): void {
		$filled = FormInvite::fill_fields( self::fields(), self::INVITE );

		$this->assertSame( 'ana souza', $filled[0]['default_value'] );
		$this->assertTrue( $filled[0]['locked'] );
		$this->assertSame( 'Ana@Example.org', $filled[1]['default_value'] );
		$this->assertTrue( $filled[1]['locked'] );
		$this->assertArrayNotHasKey( 'locked', $filled[2], 'The CPF/RF is typed, never filled in.' );
		$this->assertArrayNotHasKey( 'locked', $filled[3] );
	}

	public function test_the_guard_overwrites_the_posted_identity_with_the_invited_one(): void {
		Functions\when( 'FreeFormCertificate\Core\mb_convert_case' )->alias( 'mb_convert_case' );
		Filters\expectApplied( FormInvite::FILTER )->once()->with( null, 40, 'code', '52998224725' )->andReturn( self::INVITE );
		$ctx = $this->context( array( 'ffc_invite' => 'code' ) );

		( new InviteGuard() )->apply( $ctx );

		$this->assertSame( 'ana@example.org', $ctx->user_email );
		$this->assertSame( 'Ana@Example.org', $ctx->submission_data['email'] );
		$this->assertNotSame( 'Someone Else', $ctx->submission_data['nome_completo'] );
		$this->assertSame( 'Good', $ctx->submission_data['rating'], 'The answers are the person\'s own.' );
		$this->assertSame( 5, $ctx->invite['booking_id'] );
	}

	public function test_the_guard_refuses_a_refused_invitation(): void {
		Filters\expectApplied( FormInvite::FILTER )->andReturn( new \WP_Error( 'identity_taken', 'Not yours.' ) );

		$this->expectException( SubmissionRejected::class );
		( new InviteGuard() )->apply( $this->context( array( 'ffc_invite' => 'code' ) ) );
	}

	public function test_without_a_code_the_form_is_open_unless_it_admits_invitations_only(): void {
		$open = $this->context( array() );
		( new InviteGuard() )->apply( $open );
		$this->assertNull( $open->invite );
		$this->assertSame( 'forged@example.org', $open->user_email, 'An open form keeps what was typed.' );

		try {
			( new InviteGuard() )->apply( $this->context( array(), array( 'restrictions' => array( 'invite' => '1' ) ) ) );
			$this->fail( 'An invite-only form refuses a submission without a code.' );
		} catch ( SubmissionRejected $rejected ) {
			$this->assertStringContainsString( 'invitation only', $rejected->get_payload()['message'] );
		}
	}
}
