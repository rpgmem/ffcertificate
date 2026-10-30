<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationAdminRenderer;

/**
 * Tests for ReregistrationAdminRenderer: the list, form and submissions
 * views, including the filters each reads from the request and the
 * not-found refusal on the views that take an id.
 *
 * Every collaborator is the real class — ReregistrationRepository,
 * ReregistrationSubmissionReader, AudienceReader, AudienceTransferList,
 * AdminUI, DateFormatter, DocumentFormatter, PiiAccessPolicy — over a
 * `$wpdb` double that answers each query by its SQL text and the values
 * bound to it, from the arrays a test sets, and records what ran. A query
 * the double does not know fails the test.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationAdminRenderer
 */
class ReregistrationAdminRendererTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<array<int, mixed>> Every prepare() call, as its flattened argument list. */
	private array $prepared = array();

	/** @var list<array{0: string, 1: array<int, mixed>}> Every read, as [ method, bound values ]. */
	private array $ran = array();

	/** @var list<object> Rows the campaign list query answers. */
	private array $campaign_list = array();

	/** @var array<int, object> Campaigns by id, answered by get_by_id(). */
	private array $campaigns = array();

	/** @var array<int, list<object>> Status counts by campaign id. */
	private array $status_counts = array();

	/** @var array<int, list<object>> Audience badges by campaign id. */
	private array $campaign_audiences = array();

	/** @var array<int, list<string>> Linked audience ids by campaign id. */
	private array $audience_ids = array();

	/** @var list<object> Rows the audience list query answers (the tree source). */
	private array $audience_rows = array();

	/** @var array<int, object> Audiences by id, answered by AudienceReader::get_by_id(). */
	private array $audiences = array();

	/** @var array<int, list<int>> Member ids by audience id. */
	private array $members = array();

	/** @var list<object> Rows the submissions query answers. */
	private array $submissions = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> The `$_GET` an earlier test left, restored afterwards. */
	private array $get_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->get_backup = $_GET ?? array();
		$_GET             = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
			return 'https://example.com/wp-admin/' . $path;
		} );
		Functions\when( 'wp_nonce_url' )->alias( function ( $url, $action ) {
			return $url . '&_wpnonce=' . $action;
		} );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->alias( function ( $action ) {
			echo '<input type="hidden" data-nonce="' . $action . '">';
		} );
		Functions\when( 'wp_die' )->alias( function ( $message ) {
			throw new \RuntimeException( 'wp_die: ' . $message );
		} );
		Functions\when( 'wp_parse_args' )->alias( static fn ( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $data ) => json_encode( $data ) );
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n ) => number_format( (float) $n ) );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// DateFormatter reads the plugin settings, so the formats are chosen
		// here; emails are left enabled, so no disabled-email notice prints.
		Functions\when( 'get_option' )->alias(
			static fn ( $key, $default = false ) => 'ffc_settings' === $key
				? array( 'date_format' => 'Y-m-d', 'time_format' => 'H:i' )
				: $default
		);
		Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn ( $format, $ts, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )
				->setTimezone( $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( 'UTC' ) )
				->format( $format )
		);
		// PII policy (#1815): a logged-in viewer with no PII cap and no role,
		// i.e. the masked tier, unless a test says otherwise.
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'is_email' )->alias( static fn ( $e ) => false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'wp_create_nonce' )->alias( static fn ( $action ) => 'nonce:' . $action );
		// No capability by default: a view-only user, whom the Record PDF
		// endpoint refuses and the import panel does not admit.
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->prepared           = array();
		$this->ran                = array();
		$this->campaign_list      = array();
		$this->campaigns          = array();
		$this->status_counts      = array();
		$this->campaign_audiences = array();
		$this->audience_ids       = array();
		$this->audience_rows      = array();
		$this->audiences          = array();
		$this->members            = array();
		$this->submissions        = array();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->wpdbDouble();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$_GET = $this->get_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` double answering the queries these views run.
	 *
	 * @return Mockery\MockInterface
	 */
	private function wpdbDouble() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->users  = 'wp_users';

		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn ( string $text ): string => addcslashes( $text, '_%\\' ) );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( ...$args ) {
				$flat             = 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args;
				$this->prepared[] = $flat;
				return 'Q' . ( count( $this->prepared ) - 1 ) . ':' . $flat[0];
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				$bound       = $this->boundTo( (string) $sql );
				$this->ran[] = array( 'get_row', $bound );
				if ( false !== strpos( (string) $sql, 'SELECT * FROM %i WHERE id = %d' ) ) {
					if ( 'wp_ffc_reregistrations' === $bound[1] ) {
						return $this->campaigns[ (int) $bound[2] ] ?? null;
					}
					if ( 'wp_ffc_audiences' === $bound[1] ) {
						return $this->audiences[ (int) $bound[2] ] ?? null;
					}
				}
				$this->fail( 'Unexpected get_row(): ' . $sql . ' with ' . var_export( $bound, true ) );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) {
				$bound       = $this->boundTo( (string) $sql );
				$this->ran[] = array( 'get_col', $bound );
				if ( false !== strpos( (string) $sql, 'SELECT audience_id FROM %i WHERE reregistration_id = %d' ) ) {
					return $this->audience_ids[ (int) $bound[2] ] ?? array();
				}
				if ( false !== strpos( (string) $sql, 'SELECT DISTINCT user_id FROM %i WHERE audience_id IN' ) ) {
					$users = array();
					foreach ( array_slice( $bound, 2 ) as $audience_id ) {
						$users = array_merge( $users, $this->members[ (int) $audience_id ] ?? array() );
					}
					return array_map( 'strval', $users );
				}
				$this->fail( 'Unexpected get_col(): ' . $sql . ' with ' . var_export( $bound, true ) );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$bound       = $this->boundTo( (string) $sql );
				$this->ran[] = array( 'get_results', $bound );
				$text        = (string) $sql;
				if ( false !== strpos( $text, 'SELECT DISTINCT r.*' ) ) {
					return $this->campaign_list;
				}
				if ( false !== strpos( $text, 'SELECT status, COUNT(*)' ) ) {
					return $this->status_counts[ (int) $bound[2] ] ?? array();
				}
				if ( false !== strpos( $text, 'SELECT a.id, a.name, a.color' ) ) {
					return $this->campaign_audiences[ (int) $bound[3] ] ?? array();
				}
				if ( false !== strpos( $text, 'SELECT audience_id, user_id FROM %i' ) ) {
					// AudienceReader::get_member_ids_by_audience(): nobody, so no get_users().
					return array();
				}
				if ( false !== strpos( $text, 'LEFT JOIN %i u ON s.user_id = u.ID' ) ) {
					return $this->submissions;
				}
				if ( 'wp_ffc_audiences' === ( $bound[1] ?? null ) && false !== strpos( $text, 'SELECT * FROM %i' ) ) {
					if ( false !== strpos( $text, 'parent_id = %d' ) ) {
						// AudienceReader::get_children(): no audience here has children.
						return array();
					}
					return $this->audience_rows;
				}
				$this->fail( 'Unexpected get_results(): ' . $sql . ' with ' . var_export( $bound, true ) );
			}
		);

		return $wpdb;
	}

	/**
	 * The prepare() argument list behind a prepared query string.
	 *
	 * @return array<int, mixed>
	 */
	private function boundTo( string $sql ): array {
		if ( preg_match( '/^Q(\d+):/', $sql, $match ) ) {
			return $this->prepared[ (int) $match[1] ] ?? array();
		}
		return array( $sql );
	}

	/**
	 * Every prepare() call whose SQL contains $needle.
	 *
	 * @return list<array<int, mixed>>
	 */
	private function preparedWith( string $needle ): array {
		return array_values(
			array_filter( $this->prepared, static fn ( array $call ): bool => false !== strpos( (string) $call[0], $needle ) )
		);
	}

	private function campaign( int $id = 7 ): object {
		return (object) array(
			'id'                         => $id,
			'title'                      => 'Census 2026',
			'status'                     => 'active',
			'start_date'                 => '2026-01-01 08:00:00',
			'end_date'                   => '2026-02-01 18:00:00',
			'auto_approve'               => 1,
			'email_invitation_enabled'   => 1,
			'email_reminder_enabled'     => 0,
			'email_confirmation_enabled' => 0,
			'reminder_days'              => 5,
		);
	}

	private function countRow( string $status, int $count ): object {
		return (object) array(
			'status' => $status,
			'count'  => (string) $count,
		);
	}

	private function capture( callable $render ): string {
		ob_start();
		try {
			$render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * Whether a toggle's checkbox is printed with the given name, checked or not.
	 */
	private function assertToggle( string $html, string $name, bool $checked ): void {
		$this->assertMatchesRegularExpression(
			'#<input type="checkbox" id="' . preg_quote( $name, '#' ) . '" name="' . preg_quote( $name, '#' ) . '" value="1"' . ( $checked ? ' checked' : '(?! checked)' ) . '[^>]*>#',
			$html
		);
	}

	// ==================================================================
	// render_list()
	// ==================================================================

	public function test_render_list_passes_status_and_audience_filters_to_the_repository(): void {
		$_GET['status']      = 'active';
		$_GET['audience_id'] = '4';

		$this->campaign_list    = array( $this->campaign() );
		$this->status_counts[7] = array( $this->countRow( 'approved', 1 ), $this->countRow( 'submitted', 2 ) );
		$this->audience_rows    = array(
			(object) array( 'id' => '4', 'name' => 'Teachers', 'parent_id' => null ),
			(object) array( 'id' => '5', 'name' => 'Substitutes', 'parent_id' => '4' ),
		);

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_list( 'ffc-reregistration', true ) );

		$list = $this->preparedWith( 'SELECT DISTINCT r.*' );
		$this->assertCount( 1, $list, 'The campaigns are read once.' );
		$this->assertStringContainsString( 'ra_filter.audience_id = %d', $list[0][0] );
		$this->assertStringContainsString( 'r.status = %s', $list[0][0] );
		$this->assertSame( array( 'wp_ffc_reregistrations', 'wp_ffc_reregistration_audiences', 4, 'active' ), array_slice( $list[0], 1 ) );

		$this->assertStringContainsString( 'admin.php?page=ffc-reregistration&view=new', $html );
		$this->assertStringContainsString( 'Census 2026', $html );
		$this->assertStringContainsString( '1 / 3', $html );
		$this->assertStringContainsString( '<option value="4" >Teachers</option>', $html );
		$this->assertStringContainsString( '<option value="5" >&mdash; Substitutes</option>', $html );
	}

	public function test_render_list_without_filters_asks_for_everything(): void {
		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_list( 'ffc-reregistration', false ) );

		$list = $this->preparedWith( 'SELECT DISTINCT r.*' );
		$this->assertCount( 1, $list );
		$this->assertStringNotContainsString( 'ra_filter', $list[0][0] );
		$this->assertStringNotContainsString( 'WHERE', $list[0][0] );
		$this->assertSame( array( 'wp_ffc_reregistrations' ), array_slice( $list[0], 1 ), 'Nothing but the table is bound.' );
		$this->assertMatchesRegularExpression( '#<option value="draft"\s*>\s*Draft\s*</option>#', $html, 'The status filter lists every status.' );
		$this->assertMatchesRegularExpression( '#<option value="closed"\s*>\s*Closed\s*</option>#', $html );
		$this->assertStringContainsString( 'No reregistrations found.', $html );
	}

	// ==================================================================
	// render_list_row()
	// ==================================================================

	public function test_render_list_row_for_a_viewer_links_the_title_to_submissions(): void {
		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_list_row( 'ffc-reregistration', $this->campaign(), false ) );

		$this->assertSame( 7, $this->preparedWith( 'SELECT status, COUNT(*)' )[0][2], 'The statistics are read for this campaign.' );
		$this->assertSame( 7, $this->preparedWith( 'SELECT a.id, a.name, a.color' )[0][3], 'The audiences are read for this campaign.' );
		$this->assertStringContainsString( 'view=submissions&id=7', $html );
		$this->assertStringNotContainsString( 'view=edit&id=7', $html, 'A read-only viewer is never sent to the editor.' );
		$this->assertStringContainsString( '2026-01-01', $html );
		$this->assertStringContainsString( '2026-02-01', $html );
	}

	public function test_render_list_row_survives_an_unparseable_date(): void {
		$item             = $this->campaign();
		$item->start_date = 'garbage';

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_list_row( 'ffc-reregistration', $item, true ) );

		$this->assertStringContainsString( '<td class="column-period"> — 2026-02-01</td>', $html, 'A date that does not parse prints blank.' );
		$this->assertStringContainsString( 'delete_reregistration_7', $html, 'The delete link carries its nonce action.' );
	}

	// ==================================================================
	// render_form()
	// ==================================================================

	public function test_render_form_for_a_new_campaign_queries_no_audiences(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_form( 'ffc-reregistration', 0 ) );

		$this->assertSame( array(), $this->preparedWith( 'WHERE id = %d' ), 'Neither a campaign nor an audience is read by id.' );
		$this->assertSame( array(), $this->preparedWith( 'SELECT audience_id FROM %i WHERE reregistration_id' ), 'No linked audiences are read.' );
		$this->assertStringContainsString( '<h1>New Reregistration</h1>', $html );
		$this->assertStringContainsString( 'Create Reregistration', $html );
		$this->assertStringContainsString( 'data-selected="[]"', $html );
		$this->assertStringNotContainsString( 'ffc-rereg-send-invitations', $html, 'An unsaved campaign has nobody to invite.' );
	}

	public function test_render_form_for_an_existing_campaign_names_only_live_audiences(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->campaigns[7]    = $this->campaign();
		$this->audience_ids[7] = array( '3', '9' );
		$this->audiences[3]    = (object) array( 'id' => '3', 'name' => 'Teachers' );
		$this->members         = array(
			3 => array( 1, 2 ),
			9 => array( 2, 3 ),
		);

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_form( 'ffc-reregistration', 7 ) );

		$this->assertStringContainsString( '<h1>Edit Reregistration</h1>', $html );
		$this->assertStringContainsString( 'data-selected="[3,9]"', $html );
		$this->assertStringContainsString( 'value="Census 2026"', $html );
		$this->assertToggle( $html, 'rereg_auto_approve', true );
		$this->assertToggle( $html, 'rereg_email_reminder', false );
		$this->assertStringContainsString( '<strong>Affected users:</strong> 3', $html );
		$this->assertStringContainsString( 'ffc-rereg-send-invitations', $html );
		// The import picker offers the live audience and drops the deleted one.
		$this->assertStringContainsString( '<option value="3">Teachers</option>', $html );
		$this->assertStringNotContainsString( 'value="9"', $html );
	}

	public function test_render_form_hides_the_import_panel_without_the_import_capability(): void {
		$this->campaigns[7]    = $this->campaign();
		$this->audience_ids[7] = array( '3' );
		$this->audiences[3]    = (object) array( 'id' => '3', 'name' => 'Teachers' );

		$without_cap = $this->capture( fn () => ReregistrationAdminRenderer::render_form( 'ffc-reregistration', 7 ) );

		$this->assertStringNotContainsString( 'Teachers', $without_cap, 'Without the capability no import picker is drawn.' );
		$this->assertStringNotContainsString( 'ffc-rereg-import-box', $without_cap );
		$this->assertStringContainsString( 'ffc-rereg-send-invitations', $without_cap );
	}

	public function test_render_form_dies_for_an_unknown_campaign(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die: Reregistration not found.' );

		try {
			$this->capture( fn () => ReregistrationAdminRenderer::render_form( 'ffc-reregistration', 404 ) );
		} finally {
			$this->assertSame( 404, $this->preparedWith( 'WHERE id = %d' )[0][2] ?? null, 'The refusal follows a read of that id.' );
		}
	}

	// ==================================================================
	// render_submissions() / render_submission_row()
	// ==================================================================

	public function test_render_submissions_dies_for_an_unknown_campaign(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die: Reregistration not found.' );

		try {
			$this->capture( fn () => ReregistrationAdminRenderer::render_submissions( 'ffc-reregistration', 404, true ) );
		} finally {
			$this->assertSame( array(), $this->preparedWith( 'LEFT JOIN %i u' ), 'No submission is read for a campaign that does not exist.' );
		}
	}

	public function test_render_submissions_filters_and_draws_one_card_per_status(): void {
		$_GET['sub_status'] = 'submitted';
		$_GET['s']          = 'maria';

		$this->campaigns[7]     = $this->campaign();
		$this->status_counts[7] = array( $this->countRow( 'pending', 2 ), $this->countRow( 'odd', 1 ) );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submissions( 'ffc-reregistration', 7, true ) );

		$query = $this->preparedWith( 'LEFT JOIN %i u' );
		$this->assertCount( 1, $query, 'The submissions are read once.' );
		$this->assertSame( array( 'wp_ffc_reregistration_submissions', 'wp_users', 7, 'submitted', '%maria%', '%maria%' ), array_slice( $query[0], 1 ) );

		$this->assertSame( 'Submissions: Census 2026', trim( strip_tags( explode( '</h1>', $html )[0] ) ) );
		$this->assertStringContainsString( 'No submissions found.', $html );
		$this->assertStringContainsString( 'name="bulk_action"', $html );
		$this->assertStringContainsString( 'value="maria"', $html );

		preg_match_all(
			'#<div class="ffc-stat-card"><span class="ffc-stat-card__icon ([^"]*)" aria-hidden="true"></span><span class="ffc-stat-card__value">([^<]*)</span><span class="ffc-stat-card__label">([^<]*)</span></div>#',
			$html,
			$cards,
			PREG_SET_ORDER
		);
		$this->assertSame(
			array(
				array( 'ffc-icon-badge ffc-icon-clock', '2', 'Pending' ),
				array( 'ffc-icon-badge ffc-icon-badge-info ffc-icon-edit', '0', 'In Progress' ),
				array( 'ffc-icon-badge ffc-icon-badge-warning ffc-icon-inbox', '0', 'Submitted — Pending Review' ),
				array( 'ffc-icon-badge ffc-icon-badge-success ffc-icon-checkmark', '0', 'Approved' ),
				array( 'ffc-icon-badge ffc-icon-badge-danger ffc-icon-cross', '0', 'Rejected' ),
				array( 'ffc-icon-badge ffc-icon-history', '0', 'Expired' ),
				array( 'ffc-icon-badge ffc-icon-info', '1', 'odd' ),
				array( 'ffc-icon-badge ffc-icon-badge-primary ffc-icon-users', '3', 'Total' ),
			),
			array_map( static fn ( array $card ): array => array_slice( $card, 1 ), $cards ),
			'One card per status, an unknown one drawn neutral with the info icon, then the total.'
		);
	}

	public function test_render_submissions_for_a_viewer_hides_bulk_actions_and_lists_rows(): void {
		$this->campaigns[7]     = $this->campaign();
		$this->status_counts[7] = array( $this->countRow( 'approved', 1 ) );
		$this->submissions      = array(
			(object) array(
				'id'           => 31,
				'status'       => 'approved',
				'user_name'    => 'Maria',
				'user_email'   => 'maria@example.org',
				'submitted_at' => 1767225600,
				'reviewed_at'  => null,
				'notes'        => '',
			),
		);

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submissions( 'ffc-reregistration', 7, false ) );

		$query = $this->preparedWith( 'LEFT JOIN %i u' );
		$this->assertCount( 1, $query );
		$this->assertSame( array( 'wp_ffc_reregistration_submissions', 'wp_users', 7 ), array_slice( $query[0], 1 ), 'No filter reaches the query.' );
		$this->assertStringNotContainsString( 'name="bulk_action"', $html );
		$this->assertStringNotContainsString( 'maria@example.org', $html, 'The list never prints the email in clear (#1815).' );
		$this->assertStringContainsString( 'm***@example.org', $html );
		$this->assertStringNotContainsString( 'return_to_draft', $html, 'A viewer cannot send it back.' );
		$this->assertStringNotContainsString( 'ffc-record-btn', $html, 'A viewer the Record endpoint refuses is not offered the button (#1815).' );
	}

	// ==================================================================
	// render_submission_row() — PII tiers (#1815)
	// ==================================================================

	private function piiRow(): object {
		return (object) array(
			'id'           => 31,
			'user_id'      => 42,
			'status'       => 'approved',
			'user_name'    => 'Maria',
			'user_email'   => 'maria@example.org',
			'submitted_at' => 1767225600,
			'reviewed_at'  => null,
			'notes'        => '',
		);
	}

	public function test_masked_tier_sees_the_masked_email_without_reveal_or_details(): void {
		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $this->piiRow(), 7, false ) );

		$this->assertStringNotContainsString( 'maria@example.org', $html );
		$this->assertStringContainsString( '<span class="ffc-pii-value" data-field="email">m***@example.org</span>', $html );
		$this->assertStringNotContainsString( 'ffc-reveal-pii', $html );
		$this->assertStringNotContainsString( 'ffc-view-details-btn', $html, 'A viewer who cannot open the details is not offered them.' );
	}

	public function test_reveal_tier_gets_the_masked_email_a_reveal_button_and_the_details(): void {
		Functions\when( 'user_can' )->alias( static fn ( $uid, $cap ) => 'ffc_view_reregistration_pii' === $cap );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $this->piiRow(), 7, false ) );

		$this->assertStringNotContainsString( 'maria@example.org', $html );
		$this->assertStringContainsString( 'm***@example.org', $html );
		$this->assertStringContainsString(
			'<button type="button" class="ffc-btn ffc-btn-neutral ffc-icon-eye ffc-reveal-pii" data-type="reregistration" data-field="email" data-submission-id="31" data-nonce="nonce:ffc_reveal_pii_nonce">Reveal</button>',
			$html
		);
		$this->assertStringContainsString( 'ffc-view-details-btn', $html );
	}

	public function test_a_site_administrator_gets_a_masked_email_with_reveal(): void {
		Functions\when( 'user_can' )->alias( static fn ( $uid, $cap ) => 'manage_options' === $cap );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $this->piiRow(), 7, true ) );

		$this->assertStringNotContainsString( 'maria@example.org', $html, 'An administrator fetches the clear value through the audited endpoint (#1863).' );
		$this->assertStringContainsString( 'm***@example.org', $html );
		$this->assertStringContainsString( 'data-type="reregistration"', $html );
		$this->assertStringContainsString( 'ffc-view-details-btn', $html );
	}

	public function test_ffc_administrator_is_the_reveal_tier_as_in_the_other_domains(): void {
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'roles' => array( 'ffc_administrator' ) ) );
		Functions\when( 'user_can' )->alias( static fn ( $uid, $cap ) => 'manage_options' !== $cap );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $this->piiRow(), 7, true ) );

		$this->assertStringNotContainsString( 'maria@example.org', $html );
		$this->assertStringContainsString( 'ffc-reveal-pii', $html );
		$this->assertStringContainsString( 'ffc-view-details-btn', $html );
	}

	public function test_the_submitter_viewing_their_own_row_is_the_reveal_tier(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 42 );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $this->piiRow(), 7, false ) );

		$this->assertStringContainsString( 'ffc-reveal-pii', $html );
		$this->assertStringNotContainsString( 'maria@example.org', $html );
	}

	public function test_a_value_the_masker_does_not_recognise_is_hidden_whole(): void {
		$row             = $this->piiRow();
		$row->user_email = 'not-an-address';

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $row, 7, false ) );

		$this->assertStringNotContainsString( 'not-an-address', $html );
		$this->assertStringContainsString( '<span class="ffc-pii-value" data-field="email">n***</span>', $html );
	}

	public function test_a_row_without_an_email_shows_a_dash_and_no_reveal(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$row             = $this->piiRow();
		$row->user_email = null;

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $row, 7, false ) );

		$this->assertStringNotContainsString( 'ffc-pii-value', $html );
		$this->assertStringNotContainsString( 'ffc-reveal-pii', $html );
	}

	public function test_the_record_button_is_drawn_for_a_holder_of_the_personal_data_capability(): void {
		// The Record prints the whole submission unmasked, so the button
		// follows the personal-data policy (#2242).
		Functions\when( 'current_user_can' )->alias( static fn ( $cap ) => 'ffc_view_reregistration' === $cap );
		Functions\when( 'user_can' )->alias( static fn ( $uid, $cap ) => 'ffc_view_reregistration_pii' === $cap );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $this->piiRow(), 7, true ) );

		$this->assertStringContainsString( 'ffc-record-btn', $html );
	}

	public function test_the_record_button_is_not_drawn_for_a_manager_without_the_personal_data_capability(): void {
		Functions\when( 'current_user_can' )->alias( static fn ( $cap ) => in_array( $cap, array( 'ffc_view_reregistration', 'ffc_manage_reregistration' ), true ) );
		Functions\when( 'user_can' )->justReturn( false );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $this->piiRow(), 7, true ) );

		$this->assertStringNotContainsString( 'ffc-record-btn', $html );
	}

	public function test_the_record_button_is_not_drawn_for_a_view_only_user(): void {
		Functions\when( 'current_user_can' )->alias( static fn ( $cap ) => 'ffc_view_reregistration' === $cap );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $this->piiRow(), 7, false ) );

		$this->assertStringNotContainsString( 'ffc-record-btn', $html );
	}

	public function test_render_submission_row_for_a_submitted_entry_offers_review_actions(): void {
		$sub = (object) array(
			'id'           => 12,
			'status'       => 'submitted',
			'submitted_at' => 1767225600,
			'reviewed_at'  => 1767312000,
		);

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $sub, 7, true ) );

		$this->assertStringContainsString( 'action=approve&sub_id=12&id=7&_wpnonce=approve_submission_12', $html );
		$this->assertStringContainsString( 'action=reject&sub_id=12&id=7&_wpnonce=reject_submission_12', $html );
		$this->assertStringContainsString( 'action=return_to_draft&sub_id=12&id=7&_wpnonce=return_to_draft_submission_12', $html );
		$this->assertStringContainsString( '2026-01-01 00:00', $html );
		$this->assertStringContainsString( '2026-01-02 00:00', $html );
	}

	public function test_render_submission_row_uses_a_dash_for_missing_or_unformattable_dates(): void {
		// wp_date() answers false for a timestamp it cannot render, which
		// DateFormatter turns into an empty string.
		Functions\when( 'wp_date' )->justReturn( false );
		$sub = (object) array(
			'id'           => 13,
			'status'       => 'pending',
			'user_name'    => 'Ana',
			'user_email'   => 'ana@example.org',
			'submitted_at' => 1,
			'reviewed_at'  => 1,
		);

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $sub, 7, true ) );

		$this->assertSame( 2, substr_count( $html, '<td>—</td>' ), 'A date the formatter cannot render reads as a dash.' );
		$this->assertStringContainsString( 'Awaiting user', $html );
		$this->assertStringNotContainsString( 'return_to_draft', $html, 'A pending entry has nothing to send back.' );
	}
}
