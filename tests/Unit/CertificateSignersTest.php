<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\CertificateSigners;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\UserDashboard\UserProfileFieldMap;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;

/**
 * The signer list and the `{{signer:…}}` tokens (#2582).
 *
 * @covers \FreeFormCertificate\Core\CertificateSigners
 */
class CertificateSignersTest extends TestCase {

	/** @var mixed The signer option. */
	private $option = array();

	/** @var array<int, array<string, mixed>> User meta. */
	private array $user_meta = array();

	/** @var array<int, object> Accounts. */
	private array $users = array();

	/** @var array<int, array<string, mixed>> Logged actions. */
	private array $logged = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => CertificateSigners::OPTION === $name ? $this->option : array() );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				if ( CertificateSigners::OPTION === $name ) {
					$this->option = $value;
				}
				return true;
			}
		);
		Functions\when( 'get_user_meta' )->alias( fn( $id, $key ) => $this->user_meta[ $id ][ $key ] ?? '' );
		Functions\when( 'get_userdata' )->alias( fn( $id ) => $this->users[ $id ] ?? false );
		Functions\when( 'wp_get_attachment_url' )->alias( static fn( $id ) => 'https://example.test/sig-' . $id . '.png' );
		Functions\when( 'add_action' )->justReturn( true );
		$this->users[7]     = (object) array( 'ID' => 7, 'display_name' => 'Maria Souza', 'user_email' => 'Maria@Example.test', 'user_login' => 'maria' );
		$this->users[8]     = (object) array( 'ID' => 8, 'display_name' => 'No RF', 'user_email' => 'norf@example.test', 'user_login' => 'norf' );
		$this->user_meta[7] = array( CertificateSigners::RF_META_KEY => Encryption::encrypt( '5181780' ) );
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_rf_meta_key_matches_the_profile_field_map(): void {
		$this->assertSame( UserProfileFieldMap::get( 'rf' )['meta_key'], CertificateSigners::RF_META_KEY );
	}

	public function test_adding_a_signer_needs_an_rf_is_recorded_once_and_stores_no_rf(): void {
		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => CertificateSigners::OPTION === $name ? $this->option : ( 'ffc_settings' === $name ? ActivityLogBuffer::ENABLED_SETTINGS : array() )
		);

		$this->assertInstanceOf( \WP_Error::class, CertificateSigners::add( 8, 'x', 0 ), 'a user without an RF cannot sign' );
		$this->assertInstanceOf( \WP_Error::class, CertificateSigners::add( 99, 'x', 0 ), 'no such user' );
		$this->assertSame( 1, CertificateSigners::add( 7, ' Divisão Pedagógica ', 12 ) );
		$this->assertInstanceOf( \WP_Error::class, CertificateSigners::add( 7, 'again', 0 ), 'a user is listed once' );

		$signer = CertificateSigners::get( 1 );
		$this->assertSame( 'Divisão Pedagógica', $signer['role'] );
		$this->assertTrue( $signer['active'] );
		$this->assertStringNotContainsString( '5181780', (string) json_encode( $this->option ), 'no copy of the RF is stored' );
		$this->assertSame(
			array(
				array(
					'action'  => 'certificate_signer_added',
					'level'   => 'info',
					'context' => array( 'signer_id' => 1, 'signer_user_id' => 7 ),
				),
			),
			ActivityLogBuffer::contexts(),
			'adding the signer, and only that, is recorded; the RF is not in it'
		);
	}

	private function seed( array $row ): void {
		$this->option = array(
			array_merge(
				array( 'id' => 1, 'user_id' => 7, 'role' => 'Divisão Pedagógica', 'signature_id' => 12, 'active' => true ),
				$row
			),
		);
	}

	public function test_tokens_read_name_and_rf_from_the_profile_now(): void {
		$this->seed( array() );
		$values = CertificateSigners::token_values( CertificateSigners::get( 1 ) );
		$this->assertSame(
			array(
				'signer:name'      => 'Maria Souza',
				'signer:rf'        => '518.178-0',
				'signer:role'      => 'Divisão Pedagógica',
				'signer:signature' => 'https://example.test/sig-12.png',
			),
			$values
		);

		$this->users[7]->display_name = 'Maria S. Souza';
		$this->assertSame( 'Maria S. Souza', CertificateSigners::token_values( CertificateSigners::get( 1 ) )['signer:name'], 'a profile correction reaches the next certificate' );
	}

	public function test_a_preview_masks_the_rf_and_no_signer_prints_nothing(): void {
		$this->seed( array() );
		$this->assertNotSame( '518.178-0', CertificateSigners::token_values( CertificateSigners::get( 1 ), true )['signer:rf'] );
		$this->assertStringContainsString( '*', CertificateSigners::token_values( CertificateSigners::get( 1 ), true )['signer:rf'] );
		$this->assertSame( array( 'signer:name' => '', 'signer:rf' => '', 'signer:role' => '', 'signer:signature' => '' ), CertificateSigners::token_values( null ) );
	}

	public function test_a_deleted_account_leaves_a_copy_and_an_inactive_signer(): void {
		$this->seed( array() );
		CertificateSigners::keep_copy_of_deleted_user( 7 );
		unset( $this->users[7], $this->user_meta[7] );

		$signer = CertificateSigners::get( 1 );
		$this->assertSame( 0, $signer['user_id'], 'the link to the account is dropped' );
		$this->assertFalse( $signer['active'] );
		$this->assertNotSame( '5181780', $signer['kept_rf'], 'the copy is encrypted' );
		$values = CertificateSigners::token_values( $signer );
		$this->assertSame( 'Maria Souza', $values['signer:name'], 'issued certificates do not change' );
		$this->assertSame( '518.178-0', $values['signer:rf'] );

		$this->assertFalse( CertificateSigners::update( 2, 'x', 0, true ) );
		CertificateSigners::update( 1, 'Other', 0, true );
		$this->assertFalse( CertificateSigners::get( 1 )['active'], 'a signer with no account cannot be reactivated' );
	}

	public function test_the_privacy_eraser_removes_the_copy_and_the_rf_prints_masked(): void {
		$this->seed( array() );
		CertificateSigners::keep_copy_of_deleted_user( 7 );
		unset( $this->users[7], $this->user_meta[7] );

		$this->assertFalse( CertificateSigners::erase( 'someone@else.test' )['items_removed'] );
		$this->assertTrue( CertificateSigners::erase( ' maria@example.TEST ' )['items_removed'], 'matched by the canonical e-mail' );
		$signer = CertificateSigners::get( 1 );
		$this->assertSame( '', $signer['kept_name'] );
		$this->assertSame( '', $signer['kept_rf'] );
		$values = CertificateSigners::token_values( $signer );
		$this->assertSame( '', $values['signer:name'] );
		$this->assertStringContainsString( '*', $values['signer:rf'] );
		$this->assertFalse( CertificateSigners::erase( 'maria@example.test' )['items_removed'], 'a second request has nothing left' );
	}

	public function test_another_accounts_deletion_changes_nothing(): void {
		$this->seed( array() );
		$before = $this->option;
		CertificateSigners::keep_copy_of_deleted_user( 8 );
		$this->assertSame( $before, $this->option );
	}

	public function test_a_form_names_its_signer_and_bad_rows_are_dropped(): void {
		$this->option = array( array( 'id' => 'x' ), 'nope', array( 'id' => 3, 'user_id' => 7, 'active' => '' ) );
		Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => 40 === $id ? '3' : '' );
		$this->assertSame( array( 3 ), array_keys( CertificateSigners::all() ) );
		$this->assertSame( 3, CertificateSigners::for_form( 40 )['id'] );
		$this->assertFalse( CertificateSigners::for_form( 40 )['active'], 'a form keeps a deactivated signer' );
		$this->assertNull( CertificateSigners::for_form( 41 ) );
	}

	public function test_form_counts_tally_the_signer_meta(): void {
		Functions\when( 'get_posts' )->justReturn( array( 10, 11, 12 ) );
		Functions\when( 'get_post_meta' )->alias( static fn( $id ) => array( 10 => '1', 11 => '1', 12 => '2' )[ $id ] );
		$this->assertSame( array( 1 => 2, 2 => 1 ), CertificateSigners::form_counts() );
	}
}

