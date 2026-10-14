<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminEnvironment;
use FreeFormCertificate\Audience\AudienceAdminImport;
use FreeFormCertificate\Tests\Support\ControlInventory;

/**
 * The Scheduling screens #1761 reframed offer the controls they offered before.
 *
 * The reframe changed boxes, not behaviour: Import & Export moved from its own
 * section boxes to the shared `.card`, the Environments filter moved into the
 * table navigation, and the appointment detail's postboxes became cards. Each
 * screen is rendered with WordPress's form helpers emitting real markup, and
 * its controls — every field name, link URL with its query arguments, button
 * and `data-*` attribute, in document order — must equal the inventory the
 * screen rendered before the reframe, frozen below. A control added, lost or
 * renamed fails; a class changing does not, because classes are the frame.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAdminImport
 * @covers \FreeFormCertificate\Audience\AudienceAdminEnvironment
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SchedulingFrameControlsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) { echo $text; } );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'settings_errors' )->justReturn( '' );
		Functions\when( 'wp_trim_words' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		// WordPress's helpers emit their real markup, so the fields and buttons
		// they add are part of the inventory rather than stubbed away.
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( $action = -1, $name = '_wpnonce' ) {
				echo '<input type="hidden" id="' . $name . '" name="' . $name . '" value="nonce-' . $action . '">';
			}
		);
		Functions\when( 'wp_nonce_url' )->alias( static fn( $url, $action = -1 ) => $url . '&_wpnonce=nonce-' . $action );
		Functions\when( 'submit_button' )->alias(
			static function ( $text = null, $type = 'primary', $name = 'submit', $wrap = true ) {
				$button = '<input type="submit" name="' . $name . '" id="' . $name . '" class="button button-' . $type . '" value="' . $text . '">';
				echo $wrap ? '<p class="submit">' . $button . '</p>' : $button;
			}
		);

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}
	}

	protected function tearDown(): void {
		unset( $_GET['appointment'], $_GET['schedule_id'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_import_and_export_offers_the_same_controls(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_hierarchical' )->andReturn(
				array(
					(object) array(
						'id'       => 1,
						'name'     => 'Teachers',
						'children' => array( (object) array( 'id' => 2, 'name' => 'Math' ) ),
					),
					(object) array( 'id' => 3, 'name' => 'Staff', 'children' => array() ),
				)
			);
		Mockery::mock( 'alias:FreeFormCertificate\Settings\SettingsReader' )
			->shouldReceive( 'get' )->andReturn( '1' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceCsvImporter' )
			->shouldReceive( 'get_sample_csv' )->andReturn( 'email;name' );

		ob_start();
		( new AudienceAdminImport( 'ffc-scheduling' ) )->render_content();
		$html = (string) ob_get_clean();

		$admin = 'https://example.com/wp-admin/';
		$this->assertSame(
			array(
				'form enctype=multipart/form-data method=post',
				'input id=ffc_import_members_nonce name=ffc_import_members_nonce type=hidden value=nonce-ffc_import_members',
				'input name=ffc_action type=hidden value=import_members',
				'input accept=.csv id=members_csv name=members_csv required= type=file',
				'select id=import_audience_id name=import_audience_id',
				'option value=',
				'option value=1',
				'option value=2',
				'option value=3',
				'input checked=checked data-ffc-autosave-key=audience_csv_create_users_default id=create_users name=create_users type=checkbox value=1',
				'input id=import_members name=import_members type=submit value=Import Members',
				'a href=' . $admin . 'admin.php?page=ffc-scheduling-settings&tab=import&download_sample=members&_wpnonce=nonce-download_sample',
				'form enctype=multipart/form-data method=post',
				'input id=ffc_import_audiences_nonce name=ffc_import_audiences_nonce type=hidden value=nonce-ffc_import_audiences',
				'input name=ffc_action type=hidden value=import_audiences',
				'input accept=.csv id=audiences_csv name=audiences_csv required= type=file',
				'input id=import_audiences name=import_audiences type=submit value=Import Audiences',
				'a href=' . $admin . 'admin.php?page=ffc-scheduling-settings&tab=import&download_sample=audiences&_wpnonce=nonce-download_sample',
				'form method=post',
				'input id=ffc_export_members_nonce name=ffc_export_members_nonce type=hidden value=nonce-ffc_export_members',
				'input name=ffc_action type=hidden value=export_members',
				'select id=export_audience_id name=export_audience_id',
				'option value=',
				'option value=1',
				'option value=2',
				'option value=3',
				'input id=export_members name=export_members type=submit value=Export Members',
				'form method=post',
				'input id=ffc_export_audiences_nonce name=ffc_export_audiences_nonce type=hidden value=nonce-ffc_export_audiences',
				'input name=ffc_action type=hidden value=export_audiences',
				'input id=export_audiences name=export_audiences type=submit value=Export Audiences',
			),
			ControlInventory::of( $html )
		);

		// The four sections are the shared box, two by two, each headed by its icon.
		$this->assertSame( 4, substr_count( $html, '<div class="card">' ) );
		$this->assertSame( 2, substr_count( $html, '<div class="ffc-import-export-cards">' ) );
		$this->assertStringNotContainsString( 'ffc-import-section', $html );
		$this->assertSame( 2, substr_count( $html, '<h2 class="ffc-icon-upload">' ) );
		$this->assertSame( 2, substr_count( $html, '<h2 class="ffc-icon-download">' ) );
	}

	public function test_environments_list_offers_the_same_controls(): void {
		$_GET['schedule_id'] = '1';
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' )
			->shouldReceive( 'get_all' )->andReturn(
				array(
					(object) array( 'id' => 4, 'name' => 'Room A', 'schedule_id' => 1, 'status' => 'active', 'color' => '#fff', 'description' => '' ),
					(object) array( 'id' => 5, 'name' => 'Room B', 'schedule_id' => 1, 'status' => 'inactive', 'color' => '#000', 'description' => '' ),
				)
			);
		$sched = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$sched->shouldReceive( 'get_all' )->andReturn(
			array(
				(object) array( 'id' => 1, 'name' => 'Cal One' ),
				(object) array( 'id' => 2, 'name' => 'Cal Two' ),
			)
		);
		$sched->shouldReceive( 'get_environment_label' )->andReturn( 'Environments' );

		ob_start();
		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->render_page();
		$html = (string) ob_get_clean();

		$page = 'https://example.com/wp-admin/admin.php?page=ffc-scheduling-environments';
		$this->assertSame(
			array(
				'a href=' . $page . '&action=new',
				'form method=get',
				'input name=page type=hidden value=ffc-scheduling-environments',
				'select name=schedule_id',
				'option value=',
				'option value=1',
				'option value=2',
				'input id=filter name=filter type=submit value=Filter',
				'a href=' . $page . '&action=edit&id=4',
				'a href=' . $page . '&action=edit&id=4',
				'a href=' . $page . '&action=deactivate&id=4&_wpnonce=nonce-deactivate_environment_4 onclick=return confirm(\' Are you sure you want to deactivate this environments? \');',
				'a href=' . $page . '&action=edit&id=5',
				'a href=' . $page . '&action=edit&id=5',
				'a href=' . $page . '&action=delete&id=5&_wpnonce=nonce-delete_environment_5 onclick=return confirm(\' Are you sure you want to permanently delete this environments? \');',
			),
			ControlInventory::of( $html )
		);

		// The filter sits in the table navigation, as on every other list.
		$this->assertMatchesRegularExpression( '/<div class="tablenav top">\s*<form method="get">/', $html );
		$this->assertStringNotContainsString( 'ffc-filter-form', $html );
	}

	public function test_appointment_detail_offers_the_same_controls(): void {
		$_GET['appointment'] = '5';
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/admin.php?page=ffc-appointments' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturn( '' )
			->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default );
		Mockery::getConfiguration()->setConstantsMap(
			array(
				'FreeFormCertificate\Core\PiiAccessPolicy'   => array(
					'TIER_UNMASKED' => 'unmasked',
					'TIER_REVEAL'   => 'reveal',
					'TIER_MASKED'   => 'masked',
				),
				'FreeFormCertificate\Core\DocumentFormatter' => array( 'PREFIX_APPOINTMENT' => 'A' ),
			)
		);
		Mockery::mock( 'alias:FreeFormCertificate\Core\PiiAccessPolicy' )
			->shouldReceive( 'resolve' )->andReturn( 'reveal' );
		Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' )
			->shouldReceive( 'mask_email' )->andReturn( 'a***@x.com' )
			->shouldReceive( 'mask_cpf' )->andReturn( '***.***.**1-01' )
			->shouldReceive( 'mask_rf' )->andReturn( '**.***.**8' )
			->shouldReceive( 'format_document' )->andReturn( 'PLAIN' )
			->shouldReceive( 'format_auth_code' )->andReturn( 'A-CODE' );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
			->shouldReceive( 'decrypt_appointment' )->andReturnUsing( static fn( $a ) => $a );
		Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' )
			->shouldReceive( 'findById' )->with( 5 )->andReturn(
				array(
					'id'               => 5,
					'status'           => 'confirmed',
					'calendar_id'      => 7,
					'appointment_date' => '2026-05-20',
					'start_time'       => '09:00:00',
					'end_time'         => '10:00:00',
					'name'             => 'Alice',
					'email'            => 'alice@x.com',
					'cpf'              => '51817842080',
					'rf'               => '1234568',
					'validation_code'  => 'CODE',
					'created_at'       => '2026-05-01',
					'custom_data'      => '{"field_a":"v","field_b":["x","y"]}',
				)
			);
		Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' )
			->shouldReceive( 'findById' )->with( 7 )->andReturn( array( 'id' => 7, 'title' => 'Clinic' ) );

		ob_start();
		include FFC_PLUGIN_DIR . '/includes/self-scheduling/views/appointments-list.php';
		$html = (string) ob_get_clean();

		$reveal = 'button data-field=%s data-nonce=nonce123 data-submission-id=5 data-type=appointment type=button';
		$this->assertSame(
			array(
				'a href=https://example.com/wp-admin/admin.php?page=ffc-appointments',
				sprintf( $reveal, 'email' ),
				sprintf( $reveal, 'cpf' ),
				sprintf( $reveal, 'rf' ),
			),
			ControlInventory::of( $html )
		);

		// The same rows, in the same order, now in two boxed sections.
		preg_match_all( '/<tr><th>([^<]+)<\/th>/', $html, $rows );
		$this->assertSame(
			array( 'Status', 'Calendar', 'Date', 'Time', 'Name', 'E-mail', 'Phone', 'CPF', 'RF', 'Validation Code', 'Created' ),
			$rows[1]
		);
		$this->assertStringNotContainsString( 'postbox', $html );
		$this->assertStringContainsString( '<div class="wrap ffc-admin-page ffc-page-appointments ffc-boxed">', $html );
		$this->assertStringContainsString( '<h2 class="ffc-icon-calendar">Appointment Details</h2>', $html );
		$this->assertStringContainsString( '<h2 class="ffc-icon-list">Custom Fields</h2>', $html );
		$this->assertSame( 2, substr_count( $html, '<div class="card">' ) );
	}
}
