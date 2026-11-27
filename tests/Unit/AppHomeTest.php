<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Shortcodes\AppHome;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * What the app's Home shows from FFC (site-pwa#23): the confirmation banner,
 * the next commitment and the pending items, and how they join what other
 * plugins handed the app.
 *
 * @covers \FreeFormCertificate\Shortcodes\AppHome
 */
class AppHomeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = false ) => 'ffc_settings' === $key ? array( 'date_format' => 'd/m/Y' ) : $default
		);
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( string $format, int $ts, $tz = null ) => ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_confirmations_become_banners_and_forms_pending_items(): void {
		$items = AppHome::build(
			array(
				array( 'type' => 'confirmed', 'key' => 'confirmed:42:2026-11-18', 'title' => 'Encontro de Coordenadores', 'text' => 'x', 'detail' => 'y' ),
				array( 'type' => 'form', 'key' => 'form:42', 'title' => 'Encontro de Coordenadores', 'text' => 'x', 'detail' => 'y', 'url' => 'https://example.org/f/42/', 'closes' => '28/11' ),
				array( 'type' => 'form', 'key' => 'form:43', 'title' => 'No link', 'text' => 'x', 'detail' => 'y' ),
			),
			null,
			array(),
			null
		);

		$this->assertSame(
			array(
				array(
					'id'       => 'ffc:confirmed:42:2026-11-18',
					'text'     => 'Attendance confirmed at %s',
					'emphasis' => 'Encontro de Coordenadores',
					'tone'     => 'success',
				),
			),
			$items['banners']
		);
		$this->assertCount( 1, $items['pending'], 'a form without a link is not a pending item' );
		$this->assertSame( 'Post-event form', $items['pending'][0]['label'] );
		$this->assertSame( 'Encontro de Coordenadores · until 28/11', $items['pending'][0]['meta'] );
		$this->assertSame( 'https://example.org/f/42/', $items['pending'][0]['url'] );
		$this->assertNull( $items['next'] );
	}

	public function test_the_next_event_becomes_the_card_with_the_presence_and_agenda_screens(): void {
		$items = AppHome::build(
			array(),
			array( 'title' => 'Reunião pedagógica', 'place' => 'Auditório', 'date' => 'ter, 25/11', 'time' => '' ),
			array(),
			null
		);

		$this->assertSame( 'Reunião pedagógica', $items['next']['title'] );
		$this->assertSame( 'Auditório · ter, 25/11', $items['next']['meta'], 'an empty part leaves no separator' );
		$this->assertSame( array( 'presence', 'calendar' ), array_column( $items['next']['actions'], 'screen' ) );
		$this->assertTrue( $items['next']['actions'][0]['primary'] );
		$this->assertSame( 'qr', $items['next']['actions'][0]['icon'] );
	}

	public function test_an_open_campaign_and_a_recent_certificate_are_pending(): void {
		$items = AppHome::build(
			array(),
			null,
			array(
				array( 'title' => 'Recadastramento 2026', 'end_date' => '2026-11-30', 'can_submit' => true ),
				array( 'title' => 'Already sent', 'end_date' => '2026-11-30', 'can_submit' => false ),
			),
			array( 'id' => 7, 'form_title' => 'Formação em Educação Inclusiva', 'submission_date' => 1800000000 )
		);

		$this->assertSame( array( 'Recadastramento 2026', 'Certificate available' ), array_column( $items['pending'], 'label' ) );
		$this->assertSame( 'Deadline 30/11/2026', $items['pending'][0]['meta'] );
		$this->assertSame( array( 'reregistration', 'certificates' ), array_column( $items['pending'], 'screen' ) );
		$this->assertSame( array( 'warning', 'success' ), array_column( $items['pending'], 'tone' ) );
		$this->assertSame( 'Formação em Educação Inclusiva', $items['pending'][1]['meta'] );
	}

	public function test_a_certificate_waiting_on_data_is_pending_with_its_link(): void {
		$items = AppHome::build( array(), null, array(), null, array( array( 'title' => 'Seminar', 'url' => 'https://example.test/form/?ffc_invite=c.x', 'until' => '22/01/2027' ) ) );
		$this->assertSame(
			array(
				array(
					'label' => 'Complete your certificate',
					'meta'  => 'Seminar · until 22/01/2027',
					'url'   => 'https://example.test/form/?ffc_invite=c.x',
					'icon'  => 'edit',
					'tone'  => 'warning',
				),
			),
			$items['pending']
		);
	}

	public function test_ffcs_items_join_the_others_and_never_replace_a_next_card_already_set(): void {
		$theirs = array(
			'banners' => array( array( 'id' => 'other' ) ),
			'next'    => array( 'title' => 'Theirs' ),
			'pending' => array( array( 'label' => 'Other' ) ),
		);
		$ours   = array(
			'banners' => array( array( 'id' => 'ffc' ) ),
			'next'    => array( 'title' => 'Ours' ),
			'pending' => array( array( 'label' => 'FFC' ) ),
		);

		$merged = AppHome::merge( $theirs, $ours );
		$this->assertSame( array( 'other', 'ffc' ), array_column( $merged['banners'], 'id' ) );
		$this->assertSame( array( 'Other', 'FFC' ), array_column( $merged['pending'], 'label' ) );
		$this->assertSame( 'Theirs', $merged['next']['title'] );

		$theirs['next'] = null;
		$this->assertSame( 'Ours', AppHome::merge( $theirs, $ours )['next']['title'] );
		$this->assertSame( array( 'ffc' ), array_column( AppHome::merge( array(), $ours )['banners'], 'id' ), 'missing lists start empty' );
	}

	public function test_a_contract_it_does_not_speak_or_no_user_leaves_home_untouched(): void {
		$home = array( 'banners' => array(), 'next' => null, 'pending' => array() );
		$this->assertSame( $home, AppHome::feed( $home, new \WP_User( 5 ), 2 ) );
		$this->assertSame( $home, AppHome::feed( $home, null, 1 ) );
		$this->assertSame( $home, AppHome::feed( $home, new \WP_User( 0 ), 1 ) );
		$this->assertSame( 'nonsense', AppHome::feed( 'nonsense', new \WP_User( 5 ), 1 ) );
	}

	public function test_it_hooks_the_apps_home_filter(): void {
		Functions\expect( 'add_filter' )->once()->with( 'site_pwa_home', array( AppHome::class, 'feed' ), 10, 3 );
		AppHome::init();
	}
}
