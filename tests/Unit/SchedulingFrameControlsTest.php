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
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Tests\Support\ControlInventory;
use FreeFormCertificate\Tests\Support\Identifiers;

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
 * The submits are the family's `<button type="submit">` since #1904, with the
 * name and value they always posted; the `id` WordPress's `submit_button()`
 * copied from the name was read by nothing and did not come along.
 *
 * The repositories, readers, Encryption, DocumentFormatter and the PII
 * policy are the real classes: a `$wpdb` double answers each query from the
 * rows a test stores, by table and bound id, and fails on any other query.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAdminImport
 * @covers \FreeFormCertificate\Audience\AudienceAdminEnvironment
 */
class SchedulingFrameControlsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, list<array<string, mixed>>> Stored rows by table name. */
	private array $tables = array();

	/** @var list<array<int, mixed>> Every prepare() call, as its argument list. */
	private array $prepared = array();

	/** @var array<string, mixed> The `ffc_settings` option. */
	private array $settings = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> `$_GET` as it was before this test. */
	private array $get_backup = array();

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
		Functions\when( 'get_settings_errors' )->justReturn( array() );
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

		// Storage: the readers' query helpers, the object cache (always a
		// miss, so every read reaches the double) and the settings option,
		// which also keeps Debug::is_enabled() off whatever ran before.
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, (array) $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		$this->settings = array();
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => 'ffc_settings' === $key ? $this->settings : $default
		);

		$this->tables   = array();
		$this->prepared = array();

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( ...$args ) {
				$flat             = 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args;
				$this->prepared[] = $flat;
				return 'Q' . ( count( $this->prepared ) - 1 ) . ':' . $flat[0];
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql, $output = 'OBJECT' ) {
				$bound = $this->boundTo( (string) $sql );
				if ( 0 === strpos( (string) $bound[0], 'SELECT * FROM %i WHERE id = %d' ) ) {
					foreach ( $this->tables[ $bound[1] ] ?? array() as $row ) {
						if ( (int) $row['id'] === (int) $bound[2] ) {
							return ARRAY_A === $output ? $row : (object) $row;
						}
					}
					return null;
				}
				$this->fail( 'Unexpected get_row(): ' . var_export( $bound, true ) );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->boundTo( (string) $sql );
				if ( 1 === preg_match( '/^SELECT \* FROM %i (WHERE schedule_id = %d )?\s*ORDER BY name ASC\s*$/', (string) $bound[0], $m ) ) {
					$rows = $this->tables[ $bound[1] ] ?? array();
					if ( ! empty( $m[1] ) ) {
						$rows = array_filter( $rows, static fn( array $row ): bool => (int) $row['schedule_id'] === (int) $bound[2] );
					}
					usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) $a['name'], (string) $b['name'] ) );
					return array_map( static fn( array $row ): object => (object) $row, $rows );
				}
				$this->fail( 'Unexpected get_results(): ' . var_export( $bound, true ) );
			}
		);
		// The environments list asks whether an inactive row has bookings
		// before it offers Delete (#2229); none here.
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->boundTo( (string) $sql );
				if ( 'SELECT COUNT(*) FROM %i WHERE environment_id = %d' === $bound[0] ) {
					return '0';
				}
				$this->fail( 'Unexpected get_var(): ' . var_export( $bound, true ) );
			}
		);
		foreach ( array( 'get_col', 'insert', 'update', 'delete', 'replace', 'query' ) as $method ) {
			$wpdb->shouldReceive( $method )->andReturnUsing(
				function ( ...$args ) use ( $method ) {
					$this->fail( 'Unexpected ' . $method . '(): ' . var_export( $args, true ) );
				}
			);
		}

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
		$this->get_backup  = $_GET ?? array();
	}

	protected function tearDown(): void {
		$_GET = $this->get_backup;
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The prepare() argument list behind a prepared query string.
	 *
	 * @return array<int, mixed>
	 */
	private function boundTo( string $sql ): array {
		if ( preg_match( '/^Q(\d+):/', $sql, $match ) ) {
			return $this->prepared[ (int) $match[1] ] ?? array( $sql );
		}
		return array( $sql );
	}

	/**
	 * How many prepared queries read the given table.
	 */
	private function queriesOn( string $table ): int {
		return count( array_filter( $this->prepared, static fn( array $call ): bool => ( $call[1] ?? null ) === $table ) );
	}

	public function test_import_and_export_offers_the_same_controls(): void {
		// Stored by name, as the reader orders them: a root with one child,
		// then a root without children.
		$this->tables['wp_ffc_audiences'] = array(
			array( 'id' => '1', 'name' => 'Admins', 'parent_id' => null, 'status' => 'active' ),
			array( 'id' => '2', 'name' => 'Math', 'parent_id' => '1', 'status' => 'active' ),
			array( 'id' => '3', 'name' => 'Staff', 'parent_id' => null, 'status' => 'active' ),
		);
		$this->settings = array( 'audience_csv_create_users_default' => '1' );

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
				'button name=import_members type=submit value=Import Members',
				'a href=' . $admin . 'admin.php?page=ffc-scheduling-settings&tab=import&download_sample=members&_wpnonce=nonce-download_sample',
				'form enctype=multipart/form-data method=post',
				'input id=ffc_import_audiences_nonce name=ffc_import_audiences_nonce type=hidden value=nonce-ffc_import_audiences',
				'input name=ffc_action type=hidden value=import_audiences',
				'input accept=.csv id=audiences_csv name=audiences_csv required= type=file',
				'button name=import_audiences type=submit value=Import Audiences',
				'a href=' . $admin . 'admin.php?page=ffc-scheduling-settings&tab=import&download_sample=audiences&_wpnonce=nonce-download_sample',
				'form method=post',
				'input id=ffc_export_members_nonce name=ffc_export_members_nonce type=hidden value=nonce-ffc_export_members',
				'input name=ffc_action type=hidden value=export_members',
				'select id=export_audience_id name=export_audience_id',
				'option value=',
				'option value=1',
				'option value=2',
				'option value=3',
				'button name=export_members type=submit value=Export Members',
				'form method=post',
				'input id=ffc_export_audiences_nonce name=ffc_export_audiences_nonce type=hidden value=nonce-ffc_export_audiences',
				'input name=ffc_action type=hidden value=export_audiences',
				'button name=export_audiences type=submit value=Export Audiences',
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
		// Calendar 1 names its environments itself, so the list and each
		// row's confirm read that label.
		$this->tables['wp_ffc_audience_environments'] = array(
			array( 'id' => '4', 'name' => 'Room A', 'schedule_id' => '1', 'status' => 'active', 'color' => '#fff', 'description' => '' ),
			array( 'id' => '5', 'name' => 'Room B', 'schedule_id' => '1', 'status' => 'inactive', 'color' => '#000', 'description' => '' ),
			array( 'id' => '6', 'name' => 'Hall', 'schedule_id' => '2', 'status' => 'active', 'color' => '#ccc', 'description' => '' ),
		);
		$this->tables['wp_ffc_audience_schedules']    = array(
			array( 'id' => '1', 'name' => 'Cal One', 'environment_label' => 'Environments' ),
			array( 'id' => '2', 'name' => 'Cal Two', 'environment_label' => '' ),
		);

		ob_start();
		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->render_view();
		$html = (string) ob_get_clean();

		$page = 'https://example.com/wp-admin/admin.php?page=ffc-scheduling-calendars&view=environments';
		$this->assertSame(
			array(
				'a href=' . $page . '&action=new&schedule_id=1',
				'a href=https://example.com/wp-admin/admin.php?page=ffc-scheduling-calendars',
				'a href=' . $page,
				'form method=get',
				'input name=page type=hidden value=ffc-scheduling-calendars',
				'input name=view type=hidden value=environments',
				'select name=schedule_id',
				'option value=',
				'option value=1',
				'option value=2',
				'button name=filter type=submit value=Filter',
				'a href=' . $page . '&action=edit&id=4',
				'button data-shortcode=[ffc_audience schedule_id= type=button',
				'a href=' . $page . '&action=edit&id=4',
				'a href=' . $page . '&action=deactivate&id=4&_wpnonce=nonce-deactivate_environment_4 onclick=return confirm(\' Are you sure you want to deactivate this environments? \');',
				'a href=' . $page . '&action=edit&id=5',
				'button data-shortcode=[ffc_audience schedule_id= type=button',
				'a href=' . $page . '&action=edit&id=5',
				'a href=' . $page . '&action=delete&id=5&_wpnonce=nonce-delete_environment_5 onclick=return confirm(\' Are you sure you want to permanently delete this environments? \');',
			),
			ControlInventory::of( $html )
		);

		// The filter sits in the table navigation, as on every other list.
		$this->assertMatchesRegularExpression( '/<div class="tablenav top">\s*<form method="get">/', $html );
		$this->assertStringNotContainsString( 'ffc-filter-form', $html );
		$this->assertStringNotContainsString( 'action=edit&id=6', $html, 'the list is filtered to the calendar' );
	}

	public function test_appointment_detail_offers_the_same_controls(): void {
		$_GET['appointment'] = '5';
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/admin.php?page=ffc-appointments' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== strpos( (string) $e, '@' ) );
		// The viewer may reveal personal data without owning the record.
		Functions\when( 'user_can' )->alias( static fn( $uid, $cap ) => 'ffc_view_appointments_pii' === $cap );

		// The e-mail and the CPF are stored encrypted, as the write path
		// stores them; the RF sits in the legacy plaintext column, because
		// decrypt_appointment() reads only one of the two encrypted
		// identifiers and this screen shows both rows. Phone is empty, so it
		// draws a dash and no Reveal.
		$this->tables['wp_ffc_self_scheduling_appointments'] = array(
			array(
				'id'               => '5',
				'status'           => 'confirmed',
				'calendar_id'      => '7',
				'appointment_date' => '2026-05-20',
				'start_time'       => '09:00:00',
				'end_time'         => '10:00:00',
				'name'             => 'Alice',
				'email_encrypted'  => (string) Encryption::encrypt( 'alice@x.com' ),
				'cpf_encrypted'    => (string) Encryption::encrypt( Identifiers::CPF_A ),
				'rf'               => Identifiers::RF_A,
				'validation_code'  => 'CODE',
				'created_at'       => '2026-05-01',
				'custom_data'      => '{"field_a":"v","field_b":["x","y"]}',
			),
		);
		$this->tables['wp_ffc_self_scheduling_calendars']    = array(
			array( 'id' => '7', 'title' => 'Clinic' ),
		);

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
		$this->assertStringNotContainsString( Identifiers::CPF_A, $html, 'the decrypted CPF is drawn masked' );
		$this->assertStringContainsString( '<span class="ffc-pii-value" data-field="cpf">', $html );
		$this->assertSame( 1, $this->queriesOn( 'wp_ffc_self_scheduling_appointments' ), 'the appointment is read once' );
		$this->assertSame( 1, $this->queriesOn( 'wp_ffc_self_scheduling_calendars' ), 'its calendar is read once' );
	}
}
