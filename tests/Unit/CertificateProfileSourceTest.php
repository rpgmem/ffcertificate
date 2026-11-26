<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Frontend\CertificateFormTarget;
use FreeFormCertificate\Frontend\CertificateProfileSource;
use PHPUnit\Framework\TestCase;

/**
 * Where a certificate form's field takes its value when a terminal issues
 * from the profile (#2891): which sources a field type keeps, what each
 * fills, and that a choice takes a profile value only when it is one of its
 * options.
 *
 * @covers \FreeFormCertificate\Frontend\CertificateProfileSource
 * @covers \FreeFormCertificate\Frontend\CertificateFormTarget::submission_data
 */
class CertificateProfileSourceTest extends TestCase {

	/** @var mixed */
	private $wpdb_backup;

	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
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

	public function test_a_profile_field_source_is_kept_for_a_fillable_type(): void {
		$this->assertSame( 'field:divisao_setor', CertificateProfileSource::sanitize( 'field:divisao_setor', 'text' ) );
		$this->assertSame( 'field:jornada', CertificateProfileSource::sanitize( 'field:Jor<na>da', 'select' ) );
	}

	public function test_a_source_is_dropped_for_a_field_that_cannot_be_filled(): void {
		$this->assertSame( '', CertificateProfileSource::sanitize( 'field:rg', 'hidden' ) );
		$this->assertSame( '', CertificateProfileSource::sanitize( 'field:rg', 'info' ) );
		$this->assertSame( '', CertificateProfileSource::sanitize( 'field:', 'text' ) );
		$this->assertSame( '', CertificateProfileSource::sanitize( 'meta:rg', 'text' ) );
	}

	public function test_consent_is_kept_for_a_checkbox_only(): void {
		$this->assertSame( CertificateProfileSource::CONSENT, CertificateProfileSource::sanitize( 'consent', 'checkbox' ) );
		$this->assertSame( '', CertificateProfileSource::sanitize( 'consent', 'radio' ) );
		$this->assertSame( '', CertificateProfileSource::sanitize( 'consent', 'text' ) );
	}

	public function test_consent_ticks_every_option_of_the_checkbox(): void {
		$field = array(
			'name'           => 'termo',
			'type'           => 'checkbox',
			'choices'        => array( 'I agree' ),
			'profile_source' => 'consent',
		);
		$this->assertSame( array( 'I agree' ), CertificateProfileSource::value_for( $field, array() ) );
	}

	public function test_a_choice_takes_the_profile_value_only_when_it_is_an_option(): void {
		$field = array(
			'name'           => 'jornada',
			'type'           => 'radio',
			'choices'        => array( 'J30', 'J40' ),
			'profile_source' => 'field:jornada',
		);
		$this->assertSame( 'J40', CertificateProfileSource::value_for( $field, array( 'field:jornada' => 'J40' ) ) );
		$this->assertNull( CertificateProfileSource::value_for( $field, array( 'field:jornada' => 'J20' ) ) );

		$field['type'] = 'checkbox';
		$this->assertSame( array( 'J30' ), CertificateProfileSource::value_for( $field, array( 'field:jornada' => 'J30' ) ) );
	}

	public function test_a_field_without_a_source_or_a_value_takes_nothing(): void {
		$this->assertNull( CertificateProfileSource::value_for( array( 'type' => 'text' ), array( 'field:x' => 'y' ) ) );
		$this->assertNull(
			CertificateProfileSource::value_for(
				array(
					'type'           => 'text',
					'profile_source' => 'field:setor',
				),
				array()
			)
		);
	}

	public function test_a_form_naming_no_profile_field_reads_no_profile(): void {
		$wpdb = \Mockery::mock( 'wpdb' );
		$wpdb->shouldNotReceive( 'get_results' );
		$GLOBALS['wpdb'] = $wpdb;

		$fields = array(
			array(
				'name' => 'curso',
				'type' => 'text',
			),
			array(
				'name'           => 'termo',
				'type'           => 'checkbox',
				'choices'        => array( 'Yes' ),
				'profile_source' => 'consent',
			),
		);
		$this->assertSame( array(), CertificateProfileSource::values( 7, $fields ) );
	}

	public function test_the_editor_offers_the_global_profile_fields_once_each(): void {
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql ) => (string) $sql );
		$wpdb->shouldReceive( 'get_results' )->andReturn(
			array(
				(object) array(
					'id'                 => '1',
					'audience_id'        => '0',
					'overrides_field_id' => null,
					'field_key'          => 'divisao_setor',
					'field_label'        => 'Division',
					'sort_order'         => '1',
					'is_active'          => '1',
				),
				(object) array(
					'id'                 => '2',
					'audience_id'        => '0',
					'overrides_field_id' => null,
					'field_key'          => 'jornada',
					'field_label'        => 'Working hours',
					'sort_order'         => '2',
					'is_active'          => '1',
				),
			)
		);
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		$options = CertificateProfileSource::options();

		$this->assertSame( 'Division', $options['field:divisao_setor'] ?? null );
		$this->assertSame( 'Working hours', $options['field:jornada'] ?? null );
		$this->assertCount( 2, $options );
	}

	public function test_the_terminal_submission_takes_profile_values_and_consent(): void {
		Functions\when( 'wp_parse_args' )->alias( static fn( $a, $b ) => array_merge( $b, $a ) );
		$fields = array(
			array(
				'name'     => 'nome_completo',
				'type'     => 'text',
				'required' => '1',
			),
			array(
				'name'           => 'setor',
				'type'           => 'text',
				'required'       => '1',
				'profile_source' => 'field:divisao_setor',
			),
			array(
				'name'           => 'termo',
				'type'           => 'checkbox',
				'required'       => '1',
				'choices'        => array( 'I agree' ),
				'profile_source' => 'consent',
			),
			array(
				'name'           => 'cargo',
				'type'           => 'text',
				'required'       => '1',
				'profile_source' => 'field:cargo',
			),
		);
		$person = array(
			'name'   => 'Ana Lima',
			'email'  => 'ana@example.org',
			'cpf_rf' => '',
		);

		$built = CertificateFormTarget::submission_data( $fields, $person, array( 'field:divisao_setor' => 'DRE Butantã' ) );

		$this->assertSame( 'DRE Butantã', $built['data']['setor'] );
		$this->assertSame( array( 'I agree' ), $built['data']['termo'] );
		$this->assertSame( array( 'cargo' ), $built['missing'], 'A profile field the person has not filled stays missing.' );
	}
}
