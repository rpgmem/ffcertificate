<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudiencePostEventPending;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The post-event form a participant can still fill in, as the dashboard
 * lists it (#2028).
 *
 * @covers \FreeFormCertificate\Audience\AudiencePostEventPending
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudiencePostEventPendingTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const OPENS = 1800000000;

	private const CLOSES = 1800000000 + 7 * 86400;

	/** @var \Mockery\MockInterface */
	private $form;

	/** @var \Mockery\MockInterface */
	private $reader;

	/** @var \Mockery\MockInterface */
	private $mailer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_the_title' )->justReturn( 'Evaluation' );
		Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' )
			->shouldReceive( 'format_datetime' )->andReturnUsing( static fn( int $t ) => gmdate( 'Y-m-d H:i', $t ) );

		$this->form = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudiencePostEventForm' );
		$this->form->shouldReceive( 'settings' )->andReturn(
			array(
				'form_id'        => 40,
				'window_days'    => 7,
				'attendees_only' => false,
			)
		)->byDefault();
		$this->form->shouldReceive( 'is_form' )->andReturn( true )->byDefault();
		$this->form->shouldReceive( 'opens_at' )->andReturn( self::OPENS )->byDefault();
		$this->form->shouldReceive( 'closes_at' )->andReturn( self::CLOSES )->byDefault();
		$this->form->shouldReceive( 'eligibility' )->andReturn( true )->byDefault();

		$this->reader = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceFormInviteReader' );
		$this->reader->shouldReceive( 'answers' )->andReturn( array() )->byDefault();

		$this->mailer = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceFormInviteMailer' );
		$this->mailer->shouldReceive( 'link' )->with( 5, 9, 40 )->andReturn( 'https://example.org/form/?ffc_invite=abc' )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function booking( string $status = 'active' ): object {
		return (object) array(
			'id'     => 5,
			'status' => $status,
		);
	}

	public function test_an_open_form_the_person_may_fill_in_is_waiting_with_their_link(): void {
		$this->assertSame(
			array(
				'form_title'   => 'Evaluation',
				'url'          => 'https://example.org/form/?ffc_invite=abc',
				'closes_at'    => self::CLOSES,
				'closes_label' => gmdate( 'Y-m-d H:i', self::CLOSES ),
			),
			AudiencePostEventPending::for_user( $this->booking(), 9, self::OPENS + 60 )
		);
	}

	public function test_someone_viewing_as_the_person_gets_no_link(): void {
		$waiting = AudiencePostEventPending::for_user( $this->booking(), 9, self::OPENS + 60, false );

		$this->assertNotNull( $waiting );
		$this->assertSame( '', $waiting['url'] );
		$this->assertSame( 'Evaluation', $waiting['form_title'] );
	}

	public function test_a_cancelled_booking_has_nothing_waiting_and_reads_nothing(): void {
		$this->form->shouldNotReceive( 'settings' );

		$this->assertNull( AudiencePostEventPending::for_user( $this->booking( 'cancelled' ), 9, self::OPENS + 60 ) );
	}

	public function test_a_booking_without_a_form_has_nothing_waiting(): void {
		$this->form->shouldReceive( 'is_form' )->andReturn( false );
		$this->form->shouldNotReceive( 'eligibility' );

		$this->assertNull( AudiencePostEventPending::for_user( $this->booking(), 9, self::OPENS + 60 ) );
	}

	public function test_outside_the_window_nothing_waits_and_no_participant_is_read(): void {
		$this->form->shouldNotReceive( 'eligibility' );
		$this->reader->shouldNotReceive( 'answers' );

		$this->assertNull( AudiencePostEventPending::for_user( $this->booking(), 9, self::OPENS - 1 ), 'Before the event ends.' );
		$this->assertNull( AudiencePostEventPending::for_user( $this->booking(), 9, self::CLOSES ), 'Once the window closed.' );
	}

	public function test_a_person_the_form_would_refuse_has_nothing_waiting(): void {
		$this->form->shouldReceive( 'eligibility' )->andReturn( new \WP_Error( 'absent', 'No.' ) );
		$this->reader->shouldNotReceive( 'answers' );

		$this->assertNull( AudiencePostEventPending::for_user( $this->booking(), 9, self::OPENS + 60 ) );
	}

	public function test_a_person_who_answered_has_nothing_waiting(): void {
		$this->reader->shouldReceive( 'answers' )->with( 5 )->andReturn( array( 9 => 'publish' ) );
		$this->mailer->shouldNotReceive( 'link' );

		$this->assertNull( AudiencePostEventPending::for_user( $this->booking(), 9, self::OPENS + 60 ) );
	}

	public function test_a_form_no_page_embeds_has_nowhere_to_send_the_person(): void {
		$this->mailer->shouldReceive( 'link' )->andReturn( '' );

		$this->assertNull( AudiencePostEventPending::for_user( $this->booking(), 9, self::OPENS + 60 ) );
	}
}
