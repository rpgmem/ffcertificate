<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Reregistration\ReregistrationFrontend;
use FreeFormCertificate\Tests\Support\Identifiers;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the parts of ReregistrationFrontend's AJAX endpoints that run
 * past the guards: the form render, the import of the previous approved
 * cycle (decryption with the SOURCE campaign's fields, intersection with the
 * current one), the honeypot, validation errors and a successful submit.
 *
 * Everything runs for real — the campaign, submission, audience and custom
 * field stores, the submission writer, the form renderer and its template,
 * the data processor and its validator, the record generator and Encryption
 * (with the bootstrap's key constants). Storage is a `$wpdb` double that
 * answers each query by its SQL text from the rows a test sets, records
 * every query and write, and fails on a query it does not know; user meta is
 * an in-memory map. E-mail and the activity log are switched off through the
 * settings, so a submit stops at the stores. The JSON responders throw to
 * stand in for the `die()` WordPress performs.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationFrontend
 */
class ReregistrationFrontendFlowTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const USER = 5;

	/** @var array{type: string, data: mixed}|null */
	private ?array $response = null;

	/** @var array<int, object> Campaign rows by id. */
	private array $campaigns = array();

	/** @var array<int, list<int>> Audience ids linked to each campaign. */
	private array $campaign_audiences = array();

	/** @var array<int, object> Audience rows by id. */
	private array $audiences = array();

	/** @var array<int, list<object>> Active custom field rows of each audience. */
	private array $fields = array();

	/** @var list<int> The audiences the user belongs to. */
	private array $user_audiences = array();

	/** @var object|null The user's submission row in campaign 3; null for none. */
	private ?object $own_submission = null;

	/** @var object|null The user's latest approved submission elsewhere; null for none. */
	private ?object $latest_approved = null;

	/** @var list<string> Every SQL string the double ran. */
	private array $ran = array();

	/** @var list<int> The campaign ids whose audiences were looked up, in order. */
	private array $audience_lookups = array();

	/** @var list<array<int, mixed>> The bound arguments of every "latest approved" lookup. */
	private array $latest_lookups = array();

	/** @var list<array{0: string, 1: array<string, mixed>}> Every `$wpdb->insert()`, as `[ table, data ]`. */
	private array $inserts = array();

	/** @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}> Every `$wpdb->update()`, as `[ table, data, where ]`. */
	private array $updates = array();

	/** @var array<string, mixed> User meta of the current user, by key. */
	private array $meta = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<mixed> The `$_POST` an earlier test left, restored afterwards. */
	private array $post_backup = array();

	/**
	 * A CPF in the `xxx.xxx.xxx-xx` mask a visitor types.
	 *
	 * @param string $cpf Eleven digits.
	 */
	private static function masked( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9 );
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->post_backup = $_POST ?? array();
		$_POST             = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) { echo $text; } );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'absint' )->alias( static fn ( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn ( $k ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ) );
		Functions\when( 'wp_json_encode' )->alias( static fn ( $v ) => json_encode( $v ) );
		Functions\when( 'wp_parse_args' )->alias( static fn ( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'wp_rand' )->alias( static fn ( $min = 0, $max = 0 ) => random_int( (int) $min, (int) $max ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( $v ) => $v instanceof \WP_Error );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( self::USER );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-10-28 12:00:00' );
		Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn ( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) ( $ts ?? 0 ) ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( 'ffc_settings' === $key ) {
					// No e-mail and no activity log: a submit stops at the stores.
					return array(
						'disable_all_emails' => 1,
						'date_format'        => 'd/m/Y',
						'time_format'        => 'H:i',
					);
				}
				return $default;
			}
		);
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) {
				$user               = new \stdClass();
				$user->ID           = (int) $id;
				$user->user_email   = 'participant@example.org';
				$user->display_name    = 'Participant';
				$user->user_registered = '2025-01-01 00:00:00';
				return $user;
			}
		);
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key = '', $single = false ) {
				if ( self::USER !== (int) $user_id ) {
					return $single ? '' : array();
				}
				return $this->meta[ $key ] ?? ( $single ? '' : array() );
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( $user_id, $key, $value ) {
				$this->assertSame( self::USER, (int) $user_id, 'Only the current user\'s meta is written.' );
				$this->meta[ $key ] = $value;
				return true;
			}
		);

		$this->response = null;
		$response       = &$this->response;
		Functions\when( 'wp_send_json_error' )->alias( static function ( $data = null ) use ( &$response ) {
			$response = array( 'type' => 'error', 'data' => $data );
			throw new \RuntimeException( 'json' );
		} );
		Functions\when( 'wp_send_json_success' )->alias( static function ( $data = null ) use ( &$response ) {
			$response = array( 'type' => 'success', 'data' => $data );
			throw new \RuntimeException( 'json' );
		} );

		// Campaign 3 is the one being filled, linked to audience 11; campaign 2
		// is last cycle's, linked to audience 10; campaign 8 is another active
		// one, linked to audience 12.
		$this->campaigns          = array(
			3 => $this->campaign( 3, 'active', 'Census' ),
			2 => $this->campaign( 2, 'closed', 'Census 2025' ),
			8 => $this->campaign( 8, 'active', 'Other census' ),
		);
		$this->campaign_audiences = array(
			3 => array( 11 ),
			2 => array( 10 ),
			8 => array( 12 ),
		);
		$this->audiences          = array(
			10 => $this->audience( 10 ),
			11 => $this->audience( 11 ),
			12 => $this->audience( 12 ),
		);
		$this->fields             = array(
			10 => array( $this->field( 401, 'cpf', array( 'is_sensitive' => 1 ) ) ),
			11 => array(
				$this->field( 501, 'cpf', array( 'is_sensitive' => 1 ) ),
				$this->field( 502, 'phone' ),
				$this->field( 503, 'name', array( 'field_label' => 'Name', 'is_required' => 1 ) ),
			),
			12 => array(),
		);
		$this->user_audiences     = array( 11 );
		$this->own_submission     = $this->submission();
		$this->latest_approved    = null;
		$this->ran                = array();
		$this->audience_lookups   = array();
		$this->latest_lookups     = array();
		$this->inserts            = array();
		$this->updates            = array();
		$this->meta               = array();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->database();
	}

	protected function tearDown(): void {
		$_POST = $this->post_backup;
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The `$wpdb` double: answers from the rows the test set, records every
	 * query and write, and fails on anything it does not know.
	 *
	 * @return \Mockery\MockInterface
	 */
	private function database() {
		$wpdb            = Mockery::mock( 'wpdb' );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 0;
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$flat = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
				return array(
					'sql'  => $sql,
					'args' => $flat,
				);
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $q ) {
				$this->ran[] = $q['sql'];
				if ( 'SELECT * FROM %i WHERE id = %d' === $q['sql'] && 'wp_ffc_reregistrations' === $q['args'][0] ) {
					return isset( $this->campaigns[ $q['args'][1] ] ) ? clone $this->campaigns[ $q['args'][1] ] : null;
				}
				if ( 'SELECT * FROM %i WHERE id = %d' === $q['sql'] && 'wp_ffc_audiences' === $q['args'][0] ) {
					return isset( $this->audiences[ $q['args'][1] ] ) ? clone $this->audiences[ $q['args'][1] ] : null;
				}
				if ( false !== strpos( $q['sql'], 'WHERE reregistration_id = %d AND user_id = %d' ) ) {
					return 3 === $q['args'][1] && self::USER === $q['args'][2] && null !== $this->own_submission ? clone $this->own_submission : null;
				}
				if ( false !== strpos( $q['sql'], 'INNER JOIN %i r ON s.reregistration_id = r.id' ) ) {
					$this->latest_lookups[] = array_slice( $q['args'], 2, 2 );
					return null !== $this->latest_approved ? clone $this->latest_approved : null;
				}
				$this->fail( 'Unexpected get_row(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $q ) {
				$this->ran[] = $q['sql'];
				if ( 'SELECT audience_id FROM %i WHERE reregistration_id = %d' === $q['sql'] ) {
					$this->audience_lookups[] = $q['args'][1];
					return array_map( 'strval', $this->campaign_audiences[ $q['args'][1] ] ?? array() );
				}
				// The latest-answers prefill (#2225): no other campaign answered.
				if ( str_starts_with( $q['sql'], 'SELECT data FROM %i' ) ) {
					return array();
				}
				$this->fail( 'Unexpected get_col(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $q ) {
				$this->ran[] = $q['sql'];
				if ( false !== strpos( $q['sql'], 'INNER JOIN %i m ON a.id = m.audience_id' ) ) {
					return self::USER === $q['args'][2]
						? array_map( fn ( $id ) => clone $this->audiences[ $id ], $this->user_audiences )
						: array();
				}
				if ( false !== strpos( $q['sql'], 'JOIN %i ra ON r.id = ra.reregistration_id' ) ) {
					$audience_ids = array_slice( $q['args'], 2 );
					$rows         = array();
					foreach ( $this->campaign_audiences as $campaign_id => $linked ) {
						if ( array_intersect( $linked, $audience_ids ) && 'active' === $this->campaigns[ $campaign_id ]->status ) {
							$rows[] = clone $this->campaigns[ $campaign_id ];
						}
					}
					return $rows;
				}
				if ( false !== strpos( $q['sql'], 'WHERE audience_id = %d AND is_active = 1' ) ) {
					return array_map( static fn ( $f ) => clone $f, $this->fields[ $q['args'][1] ] ?? array() );
				}
				$this->fail( 'Unexpected get_results(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $q ) {
				$this->ran[] = $q['sql'];
				if ( false !== strpos( $q['sql'], 'auth_code = %s' ) || false !== strpos( $q['sql'], 'validation_code = %s' ) ) {
					// No other record already holds the drawn code.
					return null;
				}
				if ( 'SHOW TABLES LIKE %s' === $q['sql'] ) {
					// No profile table: the renderer falls back to the account.
					return null;
				}
				$this->fail( 'Unexpected get_var(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) {
				$this->inserts[] = array( $table, $data );
				return 1;
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array( $table, $data, $where );
				return 1;
			}
		);
		// The submission write is one UPDATE that tests the status in the
		// same statement (#2242); recorded in the shape `update()` records.
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $q ) {
				$this->ran[] = $q['sql'];
				if ( 1 !== preg_match( '/^UPDATE %i SET (.+) WHERE id = %d AND status IN \(/', $q['sql'], $m ) ) {
					$this->fail( 'Unexpected query(): ' . $q['sql'] );
				}
				$values = $q['args'];
				$table  = array_shift( $values );
				$data   = array();
				foreach ( explode( ', ', $m[1] ) as $assignment ) {
					$column          = array_shift( $values );
					$data[ $column ] = str_ends_with( $assignment, 'NULL' ) ? null : array_shift( $values );
				}
				$this->updates[] = array( $table, $data, array( 'id' => array_shift( $values ) ) );
				return 1;
			}
		);
		return $wpdb;
	}

	private function campaign( int $id, string $status, string $title ): object {
		return (object) array(
			'id'                         => $id,
			'status'                     => $status,
			'title'                      => $title,
			'start_date'                 => '2026-10-01 00:00:00',
			'end_date'                   => '2026-12-31 23:59:59',
			'auto_approve'               => 0,
			'email_confirmation_enabled' => 0,
		);
	}

	private function audience( int $id ): object {
		return (object) array(
			'id'        => $id,
			'name'      => 'Audience ' . $id,
			'parent_id' => null,
			'status'    => 'active',
		);
	}

	/**
	 * A custom field row with renderer-safe defaults.
	 *
	 * @param int                  $id        Field id.
	 * @param string               $key       Field key.
	 * @param array<string, mixed> $overrides Columns to change.
	 */
	private function field( int $id, string $key, array $overrides = array() ): object {
		return (object) array_merge(
			array(
				'id'                => $id,
				'field_key'         => $key,
				'field_label'       => ucfirst( $key ),
				'field_type'        => 'text',
				'field_group'       => 'personal',
				'field_source'      => 'custom',
				'field_profile_key' => null,
				'field_mask'        => null,
				'is_sensitive'      => 0,
				'field_options'     => null,
				'validation_rules'  => null,
				'sort_order'        => $id,
				'is_required'       => 0,
				'is_active'         => 1,
			),
			$overrides
		);
	}

	/**
	 * The user's submission row in campaign 3.
	 *
	 * @param string      $status Submission status.
	 * @param string|null $data   Stored JSON.
	 */
	private function submission( string $status = 'pending', ?string $data = null ): object {
		return (object) array(
			'id'                => 70,
			'reregistration_id' => 3,
			'user_id'           => self::USER,
			'status'            => $status,
			'data'              => $data,
		);
	}

	private function call( string $method ): void {
		try {
			ReregistrationFrontend::$method();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json', $e->getMessage() );
		}
		$this->assertNotNull( $this->response, "{$method}() must answer with JSON." );
	}

	// ==================================================================
	// ajax_get_form()
	// ==================================================================

	public function test_get_form_returns_the_rendered_form(): void {
		$_POST['reregistration_id'] = '3';
		$this->own_submission       = $this->submission( 'in_progress', (string) json_encode( array( 'fields' => array( 'name' => 'Ana' ) ) ) );

		$this->call( 'ajax_get_form' );

		$this->assertSame( 'success', $this->response['type'] );
		$this->assertSame( array( 'html' ), array_keys( $this->response['data'] ) );
		$html = $this->response['data']['html'];
		$this->assertStringContainsString( 'ffc-rereg-form', $html );
		$this->assertStringContainsString( 'Census', $html, 'The form is the requested campaign\'s.' );
		$this->assertMatchesRegularExpression( '/name="fields\[name\]"[^>]*value="Ana"|value="Ana"[^>]*name="fields\[name\]"/', $html, 'The form is filled from the user\'s own draft.' );
		$this->assertSame( array( array( self::USER, 3 ) ), $this->latest_lookups, 'The import offer is looked up for the current user, excluding this campaign.' );
		$this->assertSame( array(), $this->inserts, 'An existing submission is reused, not created again.' );
	}

	// ==================================================================
	// ajax_import_previous()
	// ==================================================================

	public function test_import_previous_refuses_an_inactive_campaign(): void {
		$_POST['reregistration_id'] = '3';
		$this->campaigns[3]->status = 'closed';

		$this->call( 'ajax_import_previous' );

		$this->assertSame( 'Reregistration not found or not active.', $this->response['data']['message'] );
		$this->assertSame( array(), $this->latest_lookups, 'An inactive campaign never looks up the previous approval.' );
	}

	public function test_import_previous_without_an_earlier_approval_says_so(): void {
		$_POST['reregistration_id'] = '3';

		$this->call( 'ajax_import_previous' );

		$this->assertSame( 'No previous approved reregistration to import.', $this->response['data']['message'] );
		$this->assertSame( array( array( self::USER, 3 ) ), $this->latest_lookups, 'The previous approval is looked up once, for the current user, excluding this campaign.' );
	}

	public function test_import_previous_decrypts_with_the_source_fields_and_keeps_only_current_keys(): void {
		$_POST['reregistration_id'] = '3';
		$this->latest_approved      = (object) array(
			'reregistration_id'    => '2',
			'reregistration_title' => 'Census 2025',
			'data'                 => json_encode(
				array(
					'fields' => array(
						'cpf'     => Encryption::encrypt( self::masked( Identifiers::CPF_A ) ),
						'phone'   => '',
						'retired' => 'x',
						'name'    => 'Ana',
					),
				)
			),
		);

		$this->call( 'ajax_import_previous' );

		$this->assertSame(
			array(
				'type' => 'success',
				'data' => array(
					'fields' => array( 'cpf' => self::masked( Identifiers::CPF_A ), 'name' => 'Ana' ),
					'source' => array( 'title' => 'Census 2025' ),
				),
			),
			$this->response,
			'Empty values and keys the current campaign lacks are dropped.'
		);
		$this->assertSame( array( 2, 3 ), $this->audience_lookups, 'The fields used to decrypt are the source campaign\'s, then the current campaign\'s are read for the intersection.' );
	}

	public function test_import_previous_skips_decryption_when_the_source_campaign_is_gone(): void {
		$_POST['reregistration_id'] = '3';
		unset( $this->campaigns[2] );
		$this->latest_approved = (object) array( 'reregistration_id' => '2', 'data' => null );

		$this->call( 'ajax_import_previous' );

		$this->assertSame( array( 'fields' => array(), 'source' => array( 'title' => '' ) ), $this->response['data'] );
		$this->assertSame( array( 3 ), $this->audience_lookups, 'Only the current campaign\'s fields are read: nothing is decrypted without a source campaign.' );
	}

	// ==================================================================
	// ajax_submit()
	// ==================================================================

	public function test_submit_trips_the_honeypot_before_reading_anything(): void {
		$_POST = array( 'ffc_honeypot_trap' => 'bot', 'reregistration_id' => '3' );

		$this->call( 'ajax_submit' );

		$this->assertSame( 'Invalid submission.', $this->response['data']['message'] );
		$this->assertSame( array(), $this->ran, 'The honeypot answers before any store is read.' );
	}

	public function test_submit_refuses_a_user_outside_the_campaign(): void {
		$_POST['reregistration_id'] = '3';
		// No row in campaign 3, and the user's only audience leads to campaign 8.
		$this->own_submission = null;
		$this->user_audiences = array( 12 );

		$this->call( 'ajax_submit' );

		$this->assertSame( 'You are not part of this reregistration.', $this->response['data']['message'] );
		$this->assertSame( array(), $this->inserts, 'No submission row is created for a user outside the campaign.' );
	}

	public function test_submit_returns_the_validation_errors_without_processing(): void {
		$_POST['reregistration_id'] = '3';
		$_POST['fields']            = array( 'phone' => '1' );

		$this->call( 'ajax_submit' );

		$this->assertSame(
			array( 'type' => 'error', 'data' => array( 'message' => 'Please fix the errors below.', 'errors' => array( 'fields[name]' => 'Name is required.' ) ) ),
			$this->response
		);
		$this->assertSame( array(), $this->updates, 'An invalid submission is not processed: nothing is written.' );
		$this->assertSame( array(), $this->meta, 'An invalid submission is not processed: no profile snapshot.' );
	}

	public function test_submit_processes_a_valid_submission(): void {
		$_POST['reregistration_id'] = '3';
		$_POST['fields']            = array(
			'cpf'  => self::masked( Identifiers::CPF_A ),
			'name' => 'Ana',
		);

		$this->call( 'ajax_submit' );

		$this->assertSame( array( 'type' => 'success', 'data' => array( 'message' => 'Reregistration submitted successfully!' ) ), $this->response );

		$this->assertCount( 1, $this->updates, 'The submission is processed exactly once.' );
		list( $table, $data, $where ) = $this->updates[0];
		$this->assertSame( 'wp_ffc_reregistration_submissions', $table );
		$this->assertSame( array( 'id' => 70 ), $where, 'The user\'s own submission is the one processed.' );
		$this->assertSame( 'submitted', $data['status'] );
		$stored = json_decode( $data['data'], true );
		$this->assertSame( array( 'cpf', 'phone', 'name' ), array_keys( $stored['fields'] ), 'Every field the campaign collects is persisted.' );
		$this->assertSame( 'Ana', $stored['fields']['name'] );
		$this->assertSame( '', $stored['fields']['phone'] );
		$this->assertSame( self::masked( Identifiers::CPF_A ), Encryption::decrypt( $stored['fields']['cpf'] ), 'The sensitive value is stored encrypted.' );
		$this->assertArrayNotHasKey( 'auth_code', $data, 'A plain submit issues no code: approval does (#2242).' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $data['magic_token'] );
		$this->assertSame( 'Ana', $this->meta['ffc_custom_fields_data']['field_503'], 'The values are kept for the current user\'s next form.' );
	}

	// ==================================================================
	// ajax_save_draft()
	// ==================================================================

	public function test_save_draft_trips_the_honeypot(): void {
		$_POST = array( 'ffc_honeypot_trap' => 'bot', 'reregistration_id' => '3' );

		$this->call( 'ajax_save_draft' );

		$this->assertSame( 'Invalid submission.', $this->response['data']['message'] );
		$this->assertSame( array(), $this->ran, 'The honeypot answers before any store is read.' );
	}

	// ==================================================================
	// One status gate and the campaign window (#2242)
	// ==================================================================

	/**
	 * The dashboard draws the button only for open statuses; a stale tab used
	 * to post anyway, and the handlers refused only `approved` and `expired`,
	 * so a submitted row went back to a draft and out of the review queue.
	 */
	public function test_save_draft_refuses_a_submitted_row(): void {
		$_POST['reregistration_id'] = '3';
		$_POST['fields']            = array( 'name' => 'Ana' );
		$this->own_submission       = $this->submission( 'submitted' );

		$this->call( 'ajax_save_draft' );

		$this->assertSame( 'This reregistration was already submitted and is awaiting review.', $this->response['data']['message'] );
		$this->assertSame( array(), $this->updates, 'The submitted row is not pulled back into a draft.' );
	}

	public function test_submit_refuses_a_submitted_row(): void {
		$_POST['reregistration_id'] = '3';
		$_POST['fields']            = array( 'cpf' => self::masked( Identifiers::CPF_A ), 'name' => 'Ana' );
		$this->own_submission       = $this->submission( 'submitted' );

		$this->call( 'ajax_submit' );

		$this->assertSame( 'error', $this->response['type'] );
		$this->assertSame( array(), $this->updates, 'A second submit issues no new code and sends nothing.' );
	}

	public function test_submit_refuses_after_the_end_without_waiting_for_the_daily_run(): void {
		// The campaign still reads `active` -- the daily run has not expired
		// it yet -- but the site clock is past its end.
		Functions\when( 'current_time' )->justReturn( '2027-01-01 00:00:01' );
		$_POST['reregistration_id'] = '3';
		$_POST['fields']            = array( 'cpf' => self::masked( Identifiers::CPF_A ), 'name' => 'Ana' );

		$this->call( 'ajax_submit' );

		$this->assertSame( 'This reregistration has closed.', $this->response['data']['message'] );
		$this->assertSame( array(), $this->updates );
	}

	public function test_save_draft_refuses_before_the_start(): void {
		Functions\when( 'current_time' )->justReturn( '2026-09-30 23:59:59' );
		$_POST['reregistration_id'] = '3';
		$_POST['fields']            = array( 'name' => 'Ana' );

		$this->call( 'ajax_save_draft' );

		$this->assertSame( 'This reregistration has not opened yet.', $this->response['data']['message'] );
		$this->assertSame( array(), $this->updates );
	}

	public function test_get_form_refuses_outside_the_window(): void {
		Functions\when( 'current_time' )->justReturn( '2027-01-01 00:00:01' );
		$_POST['reregistration_id'] = '3';

		$this->call( 'ajax_get_form' );

		$this->assertSame( 'This reregistration has closed.', $this->response['data']['message'] );
	}

	public function test_save_draft_stores_an_open_row_as_in_progress(): void {
		$_POST['reregistration_id'] = '3';
		$_POST['fields']            = array( 'name' => 'Ana' );

		$this->call( 'ajax_save_draft' );

		$this->assertSame( array( 'type' => 'success', 'data' => array( 'message' => 'Draft saved.' ) ), $this->response );
		$this->assertCount( 1, $this->updates );
		$this->assertSame( 'in_progress', $this->updates[0][1]['status'] );
	}
}
