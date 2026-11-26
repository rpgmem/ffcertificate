<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\TerminalVerdict;
use FreeFormCertificate\Frontend\CertificateCompletionLink;
use FreeFormCertificate\Frontend\CertificateFormTarget;
use FreeFormCertificate\Frontend\CertificateTerminalReadingRepository;
use FreeFormCertificate\Tests\Support\FakeCredentials;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * A certificate form read at a terminal (#2891): its window, what is issued
 * from the profile, what waits on data, and which of the form's own checks
 * apply there.
 *
 * The submission, the restrictions, the limits and the existing-certificate
 * lookup are the collaborators the site's own form uses and have tests of
 * their own, so each case passes doubles that record what they were asked.
 *
 * @covers \FreeFormCertificate\Frontend\CertificateFormTarget
 * @covers \FreeFormCertificate\Frontend\CertificateTerminalReadingRepository
 * @covers \FreeFormCertificate\Frontend\CertificateCompletionLink
 */
class CertificateFormTargetTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** 2027-01-15 10:00:00 UTC. */
	private const NOW = 1800007200;

	private const FORM = 40;

	private const USER = 7;

	private const CPF = \FreeFormCertificate\Tests\Support\Identifiers::CPF_A;

	/** @var array<int, array<string, mixed>> */
	private array $meta = array();

	/** @var array<int, array<string, mixed>> */
	private array $user_meta = array();

	/** @var list<array<string, mixed>> */
	private array $saved = array();

	/** @var list<array<string, mixed>> */
	private array $checked = array();

	/** @var list<array<string, mixed>> */
	private array $notified = array();

	/** @var object */
	private $db;

	/** @var mixed */
	private $wpdb_backup;

	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( FakeCredentials::WP_SALT );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( string $format, ?int $ts = null ) => gmdate( $format, $ts ?? time() ) );
		Functions\when( 'date_i18n' )->alias( static fn( string $format, $ts = null ) => gmdate( $format, (int) ( $ts ?? time() ) ) );
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = false ) => 'ffc_settings' === $key ? array( 'time_format' => 'H:i' ) : $default
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'get_the_title' )->justReturn( 'Inclusive Education Seminar' );
		Functions\when( 'get_post' )->alias(
			function ( $id ) {
				if ( self::FORM !== (int) $id ) {
					return null;
				}
				$post              = new \WP_Post();
				$post->ID          = self::FORM;
				$post->post_type   = 'ffc_form';
				$post->post_status = 'publish';
				return $post;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			fn( $id, $key = '', $single = false ) => $this->meta[ (int) $id ][ $key ] ?? ''
		);
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) {
				$user               = new \WP_User( (int) $id );
				$user->display_name = 'maria da silva';
				$user->user_email   = 'Maria@Example.test';
				return $user;
			}
		);
		Functions\when( 'get_user_meta' )->alias(
			fn( $id, $key = '', $single = false ) => $this->user_meta[ (int) $id ][ $key ] ?? ''
		);

		$this->db       = $this->fake_db();
		$this->had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		if ( $this->had_wpdb ) {
			$this->wpdb_backup = $GLOBALS['wpdb'];
		}
		$GLOBALS['wpdb'] = $this->db;

		$this->meta[ self::FORM ] = array(
			'_ffc_form_config'     => array(
				CertificateFormTarget::CONFIG_KEY => array( 'enabled' => '1' ),
				'restrictions'                    => array(),
			),
			'_ffc_form_fields'     => array(
				array( 'name' => 'nome_completo', 'type' => 'text', 'required' => '1' ),
				array( 'name' => 'email', 'type' => 'email', 'required' => '1' ),
				array( 'name' => 'cpf_rf', 'type' => 'text', 'required' => '1' ),
				array( 'name' => 'turma', 'type' => 'hidden', 'default_value' => 'A' ),
				array( 'name' => 'about', 'type' => 'info' ),
			),
			'_ffc_geofence_config' => array(
				'datetime_enabled' => '1',
				'date_start'       => '2027-01-15',
				'date_end'         => '2027-01-15',
				'time_start'       => '09:00',
				'time_end'         => '12:00',
			),
		);
		$this->user_meta[ self::USER ] = array( 'ffc_user_cpf' => (string) \FreeFormCertificate\Core\Encryption::encrypt( self::CPF ) );
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

	// ---- The form as a target ------------------------------------------

	public function test_the_window_is_the_forms_own_opening_and_closing_on_one_day(): void {
		$this->assertSame(
			array(
				'date'   => '2027-01-15',
				'opens'  => gmmktime( 9, 0, 0, 1, 15, 2027 ),
				'closes' => gmmktime( 12, 0, 0, 1, 15, 2027 ),
			),
			CertificateFormTarget::window( self::FORM )
		);
		$this->assertCount( 1, $this->target()->terminal_windows( self::FORM ) );
		$this->assertSame( gmmktime( 12, 0, 0, 1, 15, 2027 ), $this->target()->terminal_ends_at( self::FORM ) );
		$this->assertSame( 'Inclusive Education Seminar', $this->target()->terminal_title( self::FORM ) );

		$this->meta[ self::FORM ]['_ffc_geofence_config']['time_end'] = '';
		$this->assertSame( gmmktime( 0, 0, 0, 1, 16, 2027 ), CertificateFormTarget::window( self::FORM )['closes'], 'no closing time runs to the end of the day' );
	}

	public function test_a_form_that_cannot_take_the_terminal_says_why(): void {
		$this->assertNull( CertificateFormTarget::unavailable( self::FORM ) );

		$this->meta[ self::FORM ]['_ffc_geofence_config']['date_end'] = '2027-01-16';
		$this->assertSame( 'ffc_terminal_form_window', CertificateFormTarget::unavailable( self::FORM )->get_error_code(), 'a window over two days' );
		$this->assertSame( array(), $this->target()->terminal_windows( self::FORM ) );
		$this->assertNull( $this->target()->terminal_ends_at( self::FORM ) );

		$this->meta[ self::FORM ]['_ffc_geofence_config']['date_end'] = '2027-01-15';
		$this->meta[ self::FORM ]['_ffc_geofence_config']['datetime_enabled'] = '';
		$this->assertSame( 'ffc_terminal_form_window', CertificateFormTarget::unavailable( self::FORM )->get_error_code(), 'no dated window' );

		$this->meta[ self::FORM ]['_ffc_geofence_config']['datetime_enabled'] = '1';
		$this->meta[ self::FORM ]['_ffc_form_config']['quiz_enabled']         = '1';
		$this->assertSame( 'ffc_terminal_form_quiz', $this->target()->terminal_queue_error( self::FORM )->get_error_code() );

		unset( $this->meta[ self::FORM ]['_ffc_form_config']['quiz_enabled'] );
		$this->meta[ self::FORM ]['_ffc_form_config'][ CertificateFormTarget::CONFIG_KEY ]['enabled'] = '';
		$this->assertSame( 'ffc_terminal_form_off', CertificateFormTarget::unavailable( self::FORM )->get_error_code(), 'the switch is off' );
		$this->assertSame( 'ffc_terminal_form_off', CertificateFormTarget::unavailable( 99 )->get_error_code(), 'no such form' );
	}

	public function test_the_screen_entry_names_the_hours_and_whether_it_is_open(): void {
		$window = CertificateFormTarget::window( self::FORM );
		$entry  = $this->target()->terminal_entry( self::FORM, $window, self::NOW );
		$this->assertTrue( $entry['open'] );
		$this->assertSame( '09:00–12:00', $entry['hours'] );
		$this->assertFalse( $this->target()->terminal_entry( self::FORM, $window, gmmktime( 8, 0, 0, 1, 15, 2027 ) )['open'] );
	}

	public function test_attendance_by_hand_writes_nothing(): void {
		$this->assertSame( 'failed', $this->target()->record( self::FORM, self::USER, self::NOW, 'manual', 5 ) );
	}

	// ---- Issuing -----------------------------------------------------

	public function test_a_reading_issues_the_certificate_from_the_profile(): void {
		$result = $this->target()->terminal_read( self::FORM, $this->reading() );

		$this->assertSame( TerminalVerdict::RECORDED, $result['status'], $result['message'] );
		$this->assertSame( 'ffc_terminal_issued', $result['code'] );
		$this->assertSame( 'Maria S.', $result['name'] );
		$this->assertCount( 1, $this->saved );
		$saved = $this->saved[0];
		$this->assertSame( self::FORM, $saved['form_id'] );
		$this->assertSame( 'maria@example.test', $saved['email'], 'the account e-mail, normalised' );
		$this->assertSame( 'Maria da Silva', $saved['data']['nome_completo'], 'the display name, as the site normalises a name' );
		$this->assertSame( self::CPF, $saved['data']['cpf_rf'] );
		$this->assertSame( 'A', $saved['data']['turma'], 'a hidden field carries the form\'s value' );
		$this->assertSame( '1', $saved['data']['ffc_lgpd_consent'], 'showing the QR accepts the consent' );
		$this->assertArrayNotHasKey( 'about', $saved['data'] );
		$this->assertSame( self::NOW, $saved['options']['submission_date'], 'issued at the moment of the reading' );
		$this->assertFalse( $saved['options']['record_ip'], 'a terminal has no visitor address to record' );
		$this->assertSame( array( array( 501, self::USER ) ), $this->db->links, 'the certificate belongs to the account that showed the code' );

		$row = CertificateTerminalReadingRepository::for_person( self::FORM, self::USER );
		$this->assertSame( CertificateTerminalReadingRepository::ISSUED, $row['result'] );
		$this->assertSame( 501, $row['submission_id'] );
		$this->assertSame( 3, $row['terminal_id'] );

		$again = $this->target()->terminal_read( self::FORM, $this->reading() );
		$this->assertSame( TerminalVerdict::ALREADY, $again['status'] );
		$this->assertCount( 1, $this->saved, 'a second reading issues nothing new' );
	}

	public function test_someone_who_already_submitted_on_the_site_gets_that_certificate(): void {
		$target = $this->target(
			array(
				'existing' => static fn( int $form, string $cpf ) => array(
					'is_reprint' => self::CPF === $cpf,
					'id'         => 77,
				),
			)
		);
		$result = $target->terminal_read( self::FORM, $this->reading() );

		$this->assertSame( TerminalVerdict::ALREADY, $result['status'] );
		$this->assertSame( array(), $this->saved );
		$this->assertSame( 77, CertificateTerminalReadingRepository::for_person( self::FORM, self::USER )['submission_id'] );
	}

	public function test_the_cpf_comes_from_an_earlier_submission_when_the_profile_holds_only_its_hash(): void {
		$this->user_meta[ self::USER ] = array();
		$this->db->latest_cpf          = (string) \FreeFormCertificate\Core\Encryption::encrypt( self::CPF );

		$this->assertSame( self::CPF, CertificateFormTarget::person( self::USER )['cpf_rf'] );
	}

	public function test_a_required_field_the_profile_cannot_fill_waits_on_data(): void {
		$this->meta[ self::FORM ]['_ffc_form_fields'][] = array( 'name' => 'school', 'type' => 'text', 'required' => '1' );
		$this->meta[ self::FORM ]['_ffc_form_fields'][] = array( 'name' => 'notes', 'type' => 'text' );

		$result = $this->target()->terminal_read( self::FORM, $this->reading() );

		$this->assertSame( TerminalVerdict::PENDING, $result['status'] );
		$this->assertSame( 'ffc_terminal_awaiting_data', $result['code'] );
		$this->assertSame( array(), $this->saved );
		$row = CertificateTerminalReadingRepository::for_person( self::FORM, self::USER );
		$this->assertSame( CertificateTerminalReadingRepository::AWAITING_DATA, $row['result'] );
		$this->assertSame( array( 'school' ), $row['missing'], 'only the required field, not the optional one' );
		$this->assertCount( 1, $this->notified, 'the person is sent the link to complete it' );
		$this->assertSame( array( 'school' ), $this->notified[0]['missing'] );
	}

	public function test_a_second_reading_that_still_waits_sends_no_second_email(): void {
		$this->meta[ self::FORM ]['_ffc_form_fields'][] = array(
			'name'     => 'school',
			'label'    => 'School',
			'type'     => 'text',
			'required' => '1',
		);

		$this->target()->terminal_read( self::FORM, $this->reading() );
		$this->target()->terminal_read( self::FORM, array( 'at' => self::NOW + 60 ) + $this->reading() );

		$this->assertCount( 1, $this->notified );
		$this->assertSame( array( 'School' ), $this->notified[0]['missing'], 'the e-mail names the field by its label' );
		$this->assertSame( self::NOW + 60, CertificateTerminalReadingRepository::for_person( self::FORM, self::USER )['read_at'], 'the later reading reopens the link' );
	}

	public function test_no_cpf_anywhere_waits_on_the_cpf(): void {
		$this->user_meta[ self::USER ] = array();

		$result = $this->target()->terminal_read( self::FORM, $this->reading() );

		$this->assertSame( TerminalVerdict::PENDING, $result['status'] );
		$this->assertSame( array( 'cpf_rf' ), CertificateTerminalReadingRepository::for_person( self::FORM, self::USER )['missing'] );
	}

	// ---- The form's own checks ---------------------------------------

	public function test_the_password_is_not_asked_and_the_allow_list_is_forced_by_the_terminal_setting(): void {
		$this->meta[ self::FORM ]['_ffc_form_config']['restrictions'] = array( 'password' => '1' );
		$this->meta[ self::FORM ]['_ffc_form_config'][ CertificateFormTarget::CONFIG_KEY ]['who'] = CertificateFormTarget::WHO_ALLOWLIST;

		$this->target()->terminal_read( self::FORM, $this->reading() );

		$this->assertCount( 1, $this->checked );
		$this->assertArrayNotHasKey( 'password', $this->checked[0]['config']['restrictions'], 'the terminal stands at the entrance' );
		$this->assertSame( '1', $this->checked[0]['config']['restrictions']['allowlist'] );
		$this->assertSame( self::CPF, $this->checked[0]['cpf'] );
	}

	public function test_a_restriction_refuses_and_keeps_the_reason(): void {
		$target = $this->target(
			array(
				'restrict' => static fn() => array(
					'allowed'   => false,
					'message'   => 'Not on the list.',
					'is_ticket' => false,
				),
			)
		);
		$result = $target->terminal_read( self::FORM, $this->reading() );

		$this->assertSame( TerminalVerdict::REFUSED, $result['status'] );
		$this->assertSame( 'Not on the list.', $result['message'] );
		$this->assertSame( '', $result['name'], 'a refusal names nobody' );
		$this->assertSame( 'ffc_terminal_restricted', CertificateTerminalReadingRepository::for_person( self::FORM, self::USER )['reason'] );
		$this->assertSame( array(), $this->saved );
	}

	public function test_a_person_over_the_limit_is_refused(): void {
		$result = $this->target( array( 'limit' => static fn() => 'Too many.' ) )->terminal_read( self::FORM, $this->reading() );

		$this->assertSame( 'ffc_terminal_limit', $result['code'] );
		$this->assertSame( array(), $this->saved );
	}

	public function test_a_ticket_form_asks_for_the_code_and_spends_it_once(): void {
		$this->meta[ self::FORM ]['_ffc_form_config']['restrictions']         = array( 'ticket' => '1' );
		$this->meta[ self::FORM ]['_ffc_form_config']['generated_codes_list'] = "TK-47\nTK-48";
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ (int) $id ][ $key ] = $value;
				return true;
			}
		);

		$asked = $this->target()->terminal_read( self::FORM, $this->reading() );
		$this->assertSame( 'ffc_terminal_ticket_required', $asked['code'] );
		$this->assertNull( CertificateTerminalReadingRepository::for_person( self::FORM, self::USER ), 'asking for the code records nothing' );

		$target = $this->target(
			array(
				'restrict' => function ( $config, $cpf, $ticket ) {
					$this->checked[] = array( 'ticket' => $ticket );
					return array(
						'allowed'   => true,
						'message'   => '',
						'is_ticket' => true,
					);
				},
			)
		);
		$result = $target->terminal_read( self::FORM, array( 'ticket_code' => 'tk-48' ) + $this->reading() );

		$this->assertSame( TerminalVerdict::RECORDED, $result['status'] );
		$this->assertSame( 'TK-48', $this->saved[0]['data']['ticket'], 'the row keeps the ticket, as on the site' );
		$this->assertSame( 'TK-47', $this->meta[ self::FORM ]['_ffc_form_config']['generated_codes_list'], 'the spent ticket leaves the list' );
	}

	public function test_an_offline_reading_of_a_ticket_form_is_refused(): void {
		$this->meta[ self::FORM ]['_ffc_form_config']['restrictions'] = array( 'ticket' => '1' );

		$result = $this->target()->terminal_read( self::FORM, array( 'queued' => true, 'ticket_code' => 'TK-48' ) + $this->reading() );

		$this->assertSame( 'ffc_terminal_ticket_offline', $result['code'] );
		$this->assertSame( array(), $this->saved );
	}

	public function test_a_reading_outside_the_window_is_refused_with_the_time(): void {
		$early = $this->target()->terminal_read( self::FORM, array( 'at' => gmmktime( 8, 0, 0, 1, 15, 2027 ) ) + $this->reading() );
		$this->assertSame( 'ffc_terminal_window_not_open', $early['code'] );
		$this->assertSame( 'This form opens at 09:00.', $early['message'] );

		$late = $this->target()->terminal_read( self::FORM, array( 'at' => gmmktime( 12, 0, 0, 1, 15, 2027 ) ) + $this->reading() );
		$this->assertSame( 'ffc_terminal_window_closed', $late['code'] );
		$this->assertSame( 'This form closed at 12:00.', $late['message'] );
		$this->assertSame( array(), $this->saved );
	}

	public function test_a_failed_save_is_refused_and_a_refusal_never_overwrites_an_issued_row(): void {
		$failing = $this->target( array( 'save' => static fn() => new \WP_Error( 'db', 'no' ) ) );
		$this->assertSame( 'ffc_terminal_failed', $failing->terminal_read( self::FORM, $this->reading() )['code'] );

		$this->target()->terminal_read( self::FORM, $this->reading() );
		$this->target( array( 'limit' => static fn() => 'Too many.' ) )->terminal_read( self::FORM, array( 'at' => self::NOW + 60 ) + $this->reading() );
		$this->assertSame( CertificateTerminalReadingRepository::ISSUED, CertificateTerminalReadingRepository::for_person( self::FORM, self::USER )['result'] );
	}

	// ---- The completion link -----------------------------------------

	public function test_a_completion_code_names_its_person_for_its_form_only(): void {
		$code = CertificateCompletionLink::code( self::FORM, self::USER );

		$this->assertStringStartsWith( CertificateCompletionLink::PREFIX, $code );
		$this->assertSame( self::USER, CertificateCompletionLink::user_of( $code, self::FORM ) );
		$this->assertNull( CertificateCompletionLink::user_of( $code, self::FORM + 1 ), 'another form' );
		$this->assertNull( CertificateCompletionLink::user_of( 'c.8.' . substr( $code, -22 ), self::FORM ), 'another person' );
		$this->assertNull( CertificateCompletionLink::user_of( 'c.7.short', self::FORM ) );
	}

	public function test_a_completion_link_opens_while_the_reading_waits_and_closes_after_seven_days(): void {
		Functions\when( 'is_email' )->justReturn( true );
		$code = CertificateCompletionLink::code( self::FORM, self::USER );

		$this->assertInstanceOf( \WP_Error::class, CertificateCompletionLink::resolve( self::FORM, $code, self::NOW ), 'no reading, no link' );

		$this->meta[ self::FORM ]['_ffc_form_fields'][] = array( 'name' => 'school', 'type' => 'text', 'required' => '1' );
		$this->target()->terminal_read( self::FORM, $this->reading() );

		$invite = CertificateCompletionLink::resolve( self::FORM, $code, self::NOW + 3600 );
		$this->assertIsArray( $invite );
		$this->assertTrue( $invite['completion'] );
		$this->assertSame( self::USER, $invite['user_id'] );
		$this->assertSame( 'Maria@Example.test', $invite['email'] );

		$expired = CertificateCompletionLink::resolve( self::FORM, $code, self::NOW + CertificateCompletionLink::TTL + 1 );
		$this->assertInstanceOf( \WP_Error::class, $expired );
		$this->assertSame( 'expired', $expired->get_error_code() );
	}

	public function test_the_certificate_it_issues_spends_the_link(): void {
		Functions\when( 'is_email' )->justReturn( true );
		$this->meta[ self::FORM ]['_ffc_form_fields'][] = array( 'name' => 'school', 'type' => 'text', 'required' => '1' );
		$this->target()->terminal_read( self::FORM, $this->reading() );

		CertificateCompletionLink::complete( self::FORM, self::USER, 901 );

		$row = CertificateTerminalReadingRepository::for_person( self::FORM, self::USER );
		$this->assertSame( CertificateTerminalReadingRepository::ISSUED, $row['result'] );
		$this->assertSame( 901, $row['submission_id'] );
		$used = CertificateCompletionLink::resolve( self::FORM, CertificateCompletionLink::code( self::FORM, self::USER ), self::NOW );
		$this->assertInstanceOf( \WP_Error::class, $used );
		$this->assertSame( 'used', $used->get_error_code() );
	}

	public function test_the_filter_answers_only_its_own_codes(): void {
		$this->assertNull( CertificateCompletionLink::filter( null, self::FORM, '5.7.abcdefghijklmnopqrstuv' ), 'an audience invitation is left to its resolver' );
		$this->assertSame( array( 'user_id' => 3 ), CertificateCompletionLink::filter( array( 'user_id' => 3 ), self::FORM, 'c.7.x' ), 'an earlier answer stands' );
		$this->assertInstanceOf( \WP_Error::class, CertificateCompletionLink::filter( null, self::FORM, 'c.7.x' ) );
	}

	// ---- Helpers -----------------------------------------------------

	/**
	 * The target, with doubles for its collaborators.
	 *
	 * @param array<string, callable> $with Collaborators to replace.
	 * @return CertificateFormTarget
	 */
	private function target( array $with = array() ): CertificateFormTarget {
		return new CertificateFormTarget(
			$with['save'] ?? function ( int $form_id, string $title, array $data, string $email, array $fields, array $config, array $options ) {
				$this->saved[] = compact( 'form_id', 'title', 'data', 'email', 'options' );
				return 500 + count( $this->saved );
			},
			function ( int $id, int $user ): bool {
				$this->db->links[] = array( $id, $user );
				return true;
			},
			$with['restrict'] ?? function ( $config, $cpf, $ticket ) {
				$this->checked[] = compact( 'config', 'cpf', 'ticket' );
				return array(
					'allowed'   => true,
					'message'   => '',
					'is_ticket' => false,
				);
			},
			$with['limit'] ?? static fn() => null,
			$with['existing'] ?? static fn() => array(
				'is_reprint' => false,
				'id'         => 0,
			),
			function ( int $form_id, int $user_id, array $missing, int $read_at ): bool {
				$this->notified[] = compact( 'form_id', 'user_id', 'missing', 'read_at' );
				return true;
			}
		);
	}

	/**
	 * A reading, as the terminal path hands it over.
	 *
	 * @return array{user_id: int, name: string, ticket: bool, at: int, terminal_id: int, queued: bool, ticket_code: string}
	 */
	private function reading(): array {
		return array(
			'user_id'     => self::USER,
			'name'        => 'Maria S.',
			'ticket'      => false,
			'at'          => self::NOW,
			'terminal_id' => 3,
			'queued'      => false,
			'ticket_code' => '',
		);
	}

	/**
	 * A `$wpdb` holding the readings table, the newest-identifier query and
	 * the named lock.
	 *
	 * @return object
	 */
	private function fake_db(): object {
		return new class() {
			/** @var string */
			public $prefix = 'wp_';
			/** @var list<array<string, mixed>> */
			public array $rows = array();
			/** @var list<array{0: int, 1: int}> */
			public array $links = array();
			public string $latest_cpf = '';

			public function prepare( string $sql, ...$args ): string {
				$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
				return vsprintf( str_replace( array( '%i', '%d', '%s' ), array( '%s', '%d', "'%s'" ), $sql ), $args );
			}

			public function get_var( string $sql ) {
				return false !== strpos( $sql, 'GET_LOCK' ) ? '1' : null;
			}

			public function query( string $sql ) {
				return 1;
			}

			public function get_row( string $sql, $output = null ) {
				if ( false !== strpos( $sql, 'ffc_certificate_terminal_readings' ) && 1 === preg_match( '/form_id = (\d+) AND user_id = (\d+)/', $sql, $m ) ) {
					foreach ( $this->rows as $row ) {
						if ( (int) $row['form_id'] === (int) $m[1] && (int) $row['user_id'] === (int) $m[2] ) {
							return $row;
						}
					}
					return null;
				}
				if ( false !== strpos( $sql, 'cpf_encrypted, rf_encrypted' ) ) {
					return '' === $this->latest_cpf ? null : array(
						'cpf_encrypted' => $this->latest_cpf,
						'rf_encrypted'  => '',
					);
				}
				return null;
			}

			public function insert( string $table, array $data, $format = null ) {
				$this->rows[] = array( 'id' => count( $this->rows ) + 1 ) + $data;
				return 1;
			}

			public function update( string $table, array $data, array $where, $format = null, $where_format = null ) {
				foreach ( $this->rows as $i => $row ) {
					if ( (int) $row['id'] === (int) $where['id'] ) {
						$this->rows[ $i ] = array_merge( $row, $data );
					}
				}
				return 1;
			}

			public function get_charset_collate(): string {
				return '';
			}
		};
	}
}
