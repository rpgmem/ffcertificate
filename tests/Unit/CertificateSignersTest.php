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

	public function test_form_counts_tally_every_position_and_a_form_once_per_signer(): void {
		Functions\when( 'get_posts' )->justReturn( array( 10, 11, 12 ) );
		$meta = array(
			10 => array( '_ffc_signer_id' => '1', '_ffc_signer_id_2' => '2' ),
			11 => array( '_ffc_signer_id' => '1', '_ffc_signer_id_3' => '1' ),
			12 => array( '_ffc_signer_id_2' => '2' ),
		);
		Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => $meta[ $id ][ $key ] ?? '' );
		$this->assertSame( array( 1 => 2, 2 => 2 ), CertificateSigners::form_counts(), 'form 11 names signer 1 twice and counts once; form 12 counts signer 2 from position 2' );
	}

	public function test_positions_read_their_own_meta_keys(): void {
		$this->seed( array() );
		$this->option[] = array( 'id' => 2, 'user_id' => 7, 'role' => 'Direção', 'signature_id' => 0, 'active' => true );
		Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => array( '_ffc_signer_id' => '2', '_ffc_signer_id_3' => '1' )[ $key ] ?? '' );

		$this->assertSame( '_ffc_signer_id', CertificateSigners::form_meta_key( 1 ), 'position 1 keeps the key it had, so no form moves' );
		$this->assertSame( '_ffc_signer_id_2', CertificateSigners::form_meta_key( 2 ) );
		$positions = CertificateSigners::positions_for_form( 40 );
		$this->assertSame( array( 1, 2, 3 ), array_keys( $positions ) );
		$this->assertSame( 2, $positions[1]['id'] );
		$this->assertNull( $positions[2] );
		$this->assertSame( 1, $positions[3]['id'] );
		$this->assertNull( CertificateSigners::for_form( 40, 4 ), 'a position past the limit names nobody' );
		$this->assertNull( CertificateSigners::for_form( 40, 0 ) );
	}

	public function test_form_tokens_alias_position_one_and_print_an_empty_position_blank(): void {
		$this->seed( array() );
		Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => '_ffc_signer_id_2' === $key ? '1' : '' );
		$values = CertificateSigners::form_token_values( 40 );

		$this->assertSame( CertificateSigners::all_token_keys(), array_keys( $values ), 'every key of every position is present, so no token prints raw' );
		$this->assertCount( 16, $values );
		$this->assertSame( 'Maria Souza', $values['signer2:name'] );
		$this->assertSame( '518.178-0', $values['signer2:rf'] );
		$this->assertSame( 'https://example.test/sig-12.png', $values['signer2:signature'] );
		$this->assertSame( '', $values['signer1:name'], 'position 1 names nobody here' );
		$this->assertSame( '', $values['signer:name'], 'the alias follows position 1' );
		$this->assertSame( '', $values['signer3:role'] );

		Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => '_ffc_signer_id' === $key ? '1' : '' );
		$values = CertificateSigners::form_token_values( 40, true );
		$this->assertSame( $values['signer1:name'], $values['signer:name'] );
		$this->assertSame( $values['signer1:rf'], $values['signer:rf'] );
		$this->assertStringContainsString( '*', $values['signer:rf'], 'a preview masks every position' );
	}

	public function test_preview_values_keep_chosen_signers_and_sample_the_rest(): void {
		$this->seed( array() );
		$samples = CertificateSigners::sample_values();
		$this->assertSame( 'Maria Souza', $samples['signer:name'] );
		$this->assertSame( $samples['signer1:name'], $samples['signer:name'] );
		$this->assertCount( 3, array_unique( array( $samples['signer1:name'], $samples['signer2:name'], $samples['signer3:name'] ) ), 'each column of a template preview shows a different person' );
		$this->assertSame( FFC_PLUGIN_URL . 'assets/img/certificate-defaults/default_assinagture_1.png', $samples['signer2:signature'], 'a preview draws a sample signature in each column (#2624)' );
		$this->assertSame( FFC_PLUGIN_URL . 'assets/img/certificate-defaults/default_assinagture_2.png', $samples['signer1:signature'] );
		$this->assertSame( $samples['signer1:signature'], $samples['signer:signature'] );
		foreach ( array( 'signer1:signature', 'signer2:signature', 'signer3:signature' ) as $key ) {
			$this->assertFileExists( FFC_PLUGIN_DIR . substr( $samples[ $key ], strlen( FFC_PLUGIN_URL ) ), 'the sample image ships' );
		}

		$values = CertificateSigners::preview_values( array( 2 => CertificateSigners::get( 1 ) ) );
		$this->assertSame( 'Maria Souza', $values['signer2:name'] );
		$this->assertStringContainsString( '*', $values['signer2:rf'], 'a chosen signer previews masked' );
		$this->assertSame( 'Divisão Pedagógica', $values['signer2:role'] );
		$this->assertSame( $samples['signer1:name'], $values['signer1:name'], 'an empty position shows its sample' );
		$this->assertSame( $samples['signer3:role'], $values['signer3:role'] );
	}

	public function test_signature_tokens_are_the_alias_and_the_three_positions(): void {
		foreach ( array( '{{signer:signature}}', '{{signer1:signature}}', '{{signer2:signature}}', '{{signer3:signature}}' ) as $token ) {
			$this->assertTrue( CertificateSigners::is_signature_token( $token ), $token );
		}
		foreach ( array( '{{signer4:signature}}', '{{signer:name}}', ' {{signer:signature}}', '{{signer0:signature}}', '' ) as $token ) {
			$this->assertFalse( CertificateSigners::is_signature_token( $token ), $token );
		}
	}
}
