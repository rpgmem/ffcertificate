<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\DateMessagesAjaxEndpoint;
use FreeFormCertificate\DateMessages\OptOutCategory;
use FreeFormCertificate\DateMessages\RecipientPreview;
use FreeFormCertificate\DateMessages\Rule;
use FreeFormCertificate\DateMessages\Runner;
use FreeFormCertificate\Tests\Support\CallLog;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Recipient preview and test send (#1538).
 *
 * Runs the real collaborators — RuleReader, RecipientResolver over the real
 * BirthdaySource, OptOut and DeliveryLog, MessageBuilder, SchedulingMailer
 * with the e-mail layout and EmailService down to a stubbed `wp_mail()` —
 * over a `$wpdb` double that answers each query by its SQL text and bound
 * values from the rows a test sets (rules by id, people by birthday, opt-out
 * preferences), records every write, and fails the test on a query it was
 * not taught.
 *
 * @covers \FreeFormCertificate\DateMessages\DateMessagesAjaxEndpoint
 * @covers \FreeFormCertificate\DateMessages\RecipientPreview
 */
class DateMessagesAjaxEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const RULES = 'wp_ffc_date_message_rules';

	private const RUNS = 'wp_ffc_date_message_runs';

	private const LOG = 'wp_ffc_date_message_log';

	private const PROFILES = 'wp_ffc_user_profiles';

	/**
	 * Capabilities the current user holds.
	 *
	 * @var array<int, string>
	 */
	private array $caps = array();

	/** @var mixed The `$wpdb` global before the test, restored in tearDown(). */
	private $previous_wpdb;

	/** Whether `$wpdb` was set before the test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> `$_POST` before the test, restored in tearDown(). */
	private array $previous_post = array();

	/** @var list<array{0: string, 1: array<int, mixed>}> Every prepared statement: [ sql, args ]. */
	private array $prepared = array();

	/** @var array<int, array<string, mixed>> Stored rules by id. */
	private array $rules = array();

	/** @var array<string, list<array{user_id: int, email: string, name: string}>> People by birthday ('m-d'). */
	private array $born = array();

	/** @var array<int, string> Stored preferences JSON by user id. */
	private array $preferences = array();

	/** @var list<array{table: string, data: array<string, mixed>}> Every insert. */
	private array $inserts = array();

	/** @var list<array{table: string, data: array<string, mixed>, where: array<string, mixed>}> Every update. */
	private array $updates = array();

	/** @var list<array{0: string, 1: array<int, mixed>}> Every raw query() statement. */
	private array $queries = array();

	/** @var list<array{to: string, subject: string, body: string}> Every wp_mail() call. */
	private array $mailed = array();

	/** What wp_mail() answers. */
	private bool $mail_result = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->previous_post = $_POST ?? array();

		$_POST      = array();
		$this->caps = array( 'ffc_view_date_messages' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'sanitize_hex_color' )->alias(
			static fn( $v ) => is_string( $v ) && 1 === preg_match( '/^#[0-9a-fA-F]{6}$/', $v ) ? $v : null
		);
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => trim( strip_tags( (string) $s ) ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts ) => gmdate( (string) $format, (int) $ts ) );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'is_email' )->alias( static fn( $v ) => false !== filter_var( $v, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.org' . $path );
		// Every setting at its default: e-mails enabled, debug and activity log off.
		Functions\when( 'get_option' )->alias( static fn( $key, $fallback = false ) => $fallback );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'user_email' => 'op@example.org' ) );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body ) {
				$this->mailed[] = array(
					'to'      => (string) $to,
					'subject' => (string) $subject,
					'body'    => (string) $body,
				);
				return $this->mail_result;
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null ) {
				throw new JsonResponse( true, $data, 200 );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = 400 ) {
				throw new JsonResponse( false, $data, (int) $status );
			}
		);

		$GLOBALS['wpdb'] = $this->database();
	}

	protected function tearDown(): void {
		$_POST = $this->previous_post;
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The `$wpdb` double: answers from the test's rows, records every write.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$this->prepared[] = array( (string) $sql, array_values( $args ) );
				return 'Q' . ( count( $this->prepared ) - 1 );
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $query ) {
				[ $sql, $args ] = $this->statement( $query );
				if ( self::RULES === $args[0] && false !== strpos( $sql, 'WHERE id = %d' ) ) {
					return $this->rules[ $args[1] ] ?? null;
				}
				$this->fail( 'Unexpected get_row: ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) {
				[ $sql, $args ] = $this->statement( $query );
				if ( false !== strpos( $sql, 'u.ID AS user_id' ) ) {
					// [ meta_key, ...month_days, after, limit ].
					$limit = (int) array_pop( $args );
					$after = (int) array_pop( $args );
					$days  = array_slice( $args, 1 );
					$rows  = array();
					foreach ( $days as $day ) {
						foreach ( $this->born[ $day ] ?? array() as $person ) {
							if ( $person['user_id'] > $after ) {
								$rows[] = $person;
							}
						}
					}
					usort( $rows, static fn( array $a, array $b ): int => $a['user_id'] <=> $b['user_id'] );
					return array_slice( $rows, 0, $limit );
				}
				if ( self::PROFILES === $args[0] && false !== strpos( $sql, 'SELECT user_id, preferences FROM %i WHERE user_id IN' ) ) {
					$rows = array();
					foreach ( array_slice( $args, 1 ) as $id ) {
						if ( isset( $this->preferences[ $id ] ) ) {
							$rows[] = array(
								'user_id'     => (string) $id,
								'preferences' => $this->preferences[ $id ],
							);
						}
					}
					return $rows;
				}
				$this->fail( 'Unexpected get_results: ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $query ) {
				[ $sql, $args ] = $this->statement( $query );
				if ( self::LOG === $args[0] && false !== strpos( $sql, 'SELECT user_id FROM %i WHERE rule_id = %d' ) ) {
					return array();
				}
				$this->fail( 'Unexpected get_col: ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $query ) {
				[ $sql, $args ]  = $this->statement( $query );
				$this->queries[] = array( $sql, $args );
				return 1;
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$this->inserts[] = array(
					'table' => $table,
					'data'  => $data,
				);
				$wpdb->insert_id = 12;
				return 1;
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array(
					'table' => $table,
					'data'  => $data,
					'where' => $where,
				);
				return 1;
			}
		);

		return $wpdb;
	}

	/**
	 * The SQL and bound values a prepared token stands for.
	 *
	 * @param mixed $query Token returned by prepare().
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function statement( $query ): array {
		$index = (int) substr( (string) $query, 1 );
		$this->assertArrayHasKey( $index, $this->prepared, 'Every query goes through prepare().' );
		return $this->prepared[ $index ];
	}

	/**
	 * The bound `after` cursor of every birthday query, in order.
	 *
	 * @return list<int>
	 */
	private function dueCursors(): array {
		$out = array();
		foreach ( $this->prepared as [ $sql, $args ] ) {
			if ( false !== strpos( $sql, 'u.ID AS user_id' ) ) {
				$out[] = (int) $args[ count( $args ) - 2 ];
			}
		}
		return $out;
	}

	/**
	 * Every read or write that touched a table.
	 *
	 * @param string $table Table name.
	 * @return int
	 */
	private function touches( string $table ): int {
		$reads  = array_filter( $this->prepared, static fn( array $p ): bool => in_array( $table, $p[1], true ) );
		$writes = array_filter(
			array_merge( $this->inserts, $this->updates ),
			static fn( array $w ): bool => $table === $w['table']
		);
		return count( $reads ) + count( $writes );
	}

	private function respond( callable $handler ): JsonResponse {
		try {
			$handler();
		} catch ( JsonResponse $r ) {
			return $r;
		}
		$this->fail( 'The handler did not answer.' );
	}

	/** Store rule 3, the one every saved-rule test reads. */
	private function storeRule(): void {
		$this->rules[3] = array(
			'id'      => '3',
			'name'    => 'r',
			'subject' => 'Hi {{first_name}}',
			'body'    => '<p>Body of the rule</p>',
		);
	}

	private function rule(): Rule {
		$rule = Rule::from_array(
			array(
				'id'      => 3,
				'name'    => 'r',
				'subject' => 'Hi {{first_name}}',
				'body'    => '<p>Body of the rule</p>',
			)
		);
		$this->assertInstanceOf( Rule::class, $rule );
		return $rule;
	}

	/**
	 * One person born on a day.
	 *
	 * @param string $month_day 'm-d'.
	 * @param int    $id        User id.
	 * @param string $email     Address ('' for none).
	 */
	private function bornOn( string $month_day, int $id, ?string $email = null ): void {
		$this->born[ $month_day ][] = array(
			'user_id' => $id,
			'email'   => $email ?? "person{$id}@example.org",
			'name'    => "Person {$id}",
		);
	}

	/** Store an opt-out of personal messages for a user. */
	private function optOut( int $id ): void {
		$this->preferences[ $id ] = (string) json_encode( array( OptOutCategory::key( OptOutCategory::PERSONAL ) => false ) );
	}

	public function test_preview_refuses_an_operator_without_the_view_capability(): void {
		$this->caps = array();

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'preview' ) );

		$this->assertFalse( $r->success );
		$this->assertSame( 403, $r->status );
		$this->assertSame( array(), $this->dueCursors(), 'Nobody is resolved.' );
	}

	/**
	 * @dataProvider bad_ranges
	 */
	public function test_preview_refuses_a_bad_range( string $from, string $to ): void {
		$this->storeRule();
		$_POST = array(
			'rule_id' => '3',
			'from'    => $from,
			'to'      => $to,
		);

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'preview' ) );

		$this->assertFalse( $r->success );
		$this->assertSame( 400, $r->status );
		$this->assertSame( array(), $this->dueCursors(), 'Nobody is resolved.' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function bad_ranges(): array {
		return array(
			'missing'  => array( '', '2026-10-01' ),
			'reversed' => array( '2026-10-02', '2026-10-01' ),
			'too wide' => array( '2026-10-01', '2026-11-01' ),
		);
	}

	public function test_preview_without_pii_returns_totals_only(): void {
		$this->storeRule();
		// Each day: one person who will receive it, one who opted out.
		$this->bornOn( '10-01', 1 );
		$this->bornOn( '10-01', 2 );
		$this->bornOn( '10-02', 3 );
		$this->bornOn( '10-02', 4 );
		$this->optOut( 2 );
		$this->optOut( 4 );
		$_POST = array(
			'rule_id' => '3',
			'from'    => '2026-10-01',
			'to'      => '2026-10-02',
		);

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'preview' ) );

		$this->assertTrue( $r->success );
		$this->assertSame( array( 0, 0 ), $this->dueCursors(), 'One page per day, two days.' );
		$this->assertSame( 2, $r->data['totals']['will_send'], 'One per day, two days.' );
		$this->assertSame( 2, $r->data['totals']['opted_out'] );
		$this->assertSame( 0, $r->data['totals']['no_email'] );
		$this->assertSame( array(), $r->data['rows'], 'No names without the PII capability.' );
		$this->assertFalse( $r->data['pii'] );
	}

	public function test_preview_with_pii_lists_people_with_the_address_masked_and_follows_the_cursor(): void {
		$this->caps = array( 'ffc_view_date_messages', 'ffc_view_date_messages_pii' );
		$this->storeRule();
		// One more person than a page holds, so the resolver asks for a second page.
		for ( $id = 1; $id <= Runner::BATCH_SIZE + 1; $id++ ) {
			$this->bornOn( '10-01', $id );
		}
		$_POST = array(
			'rule_id' => '3',
			'from'    => '2026-10-01',
			'to'      => '2026-10-01',
		);

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'preview' ) );

		$this->assertTrue( $r->data['pii'] );
		$this->assertSame( array( 0, Runner::BATCH_SIZE ), $this->dueCursors(), 'The second page starts after the first.' );
		$this->assertCount( Runner::BATCH_SIZE + 1, $r->data['rows'] );
		// Names are masked too (#1863); the upcoming dates reveal them, audited.
		$this->assertSame( array( 'P*** 1*', 'P*** 2*' ), array_slice( array_column( $r->data['rows'], 'name' ), 0, 2 ) );
		$this->assertStringNotContainsString( 'person1@example.org', (string) json_encode( $r->data ), 'The address is masked.' );
		$this->assertSame( '2026-10-01', $r->data['rows'][0]['date'] );
		$this->assertSame( Runner::BATCH_SIZE + 1, $r->data['rows'][ Runner::BATCH_SIZE ]['user_id'], 'The second page is listed.' );
	}

	public function test_preview_of_an_unsaved_rule_validates_the_form_but_not_its_name(): void {
		$_POST = array(
			'rule_id' => '0',
			'rule'    => array(
				'name'        => '',
				'subject'     => 's',
				'body'        => 'b',
				'offset_days' => '-3',
			),
			'from'    => '2026-10-01',
			'to'      => '2026-10-01',
		);

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'preview' ) );

		$this->assertTrue( $r->success );
		$this->assertSame( array( 0 ), $this->dueCursors(), 'Resolved once.' );
		$this->assertSame( 0, $this->touches( self::RULES ), 'No stored rule is read.' );
	}

	public function test_preview_reports_an_invalid_unsaved_rule(): void {
		$_POST = array(
			'rule' => array(
				'subject'     => 's',
				'body'        => 'b',
				'offset_days' => '99',
			),
			'from' => '2026-10-01',
			'to'   => '2026-10-01',
		);

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'preview' ) );

		$this->assertSame( 400, $r->status );
		$this->assertSame( array(), $this->dueCursors(), 'Nobody is resolved.' );
	}

	public function test_collect_caps_the_listed_rows_but_counts_everyone(): void {
		for ( $id = 1; $id <= RecipientPreview::ROW_LIMIT + 5; $id++ ) {
			$this->bornOn( '10-01', $id );
		}
		$day = new \DateTimeImmutable( '2026-10-01' );

		$out = RecipientPreview::collect( $this->rule(), $day, $day, true );

		$this->assertCount( RecipientPreview::ROW_LIMIT, $out['rows'] );
		$this->assertTrue( $out['truncated'] );
		$this->assertSame( RecipientPreview::ROW_LIMIT + 5, $out['totals']['will_send'] );
	}

	public function test_test_send_needs_the_manage_or_the_test_capability(): void {
		// A viewer previews; only manage or test sends (#1860).
		$this->caps = array( 'ffc_view_date_messages' );

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'test_send' ) );

		$this->assertSame( 403, $r->status );
		$this->assertSame( array(), $this->mailed, 'Nothing is sent.' );
	}

	public function test_the_test_capability_alone_sends_the_test(): void {
		// The Date Messages Operator holds `ffc_test_date_messages` and
		// nothing that changes a rule (#1860).
		$this->caps = array( 'ffc_view_date_messages', 'ffc_test_date_messages' );
		$this->storeRule();
		$_POST = array( 'rule_id' => '3' );

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'test_send' ) );

		$this->assertTrue( $r->success );
		$this->assertCount( 1, $this->mailed );
		$this->assertCount( 1, $this->inserts, 'One test run recorded.' );
		$this->assertSame( self::RUNS, $this->inserts[0]['table'] );
	}

	public function test_test_send_goes_to_the_operator_and_is_recorded_only_as_a_test(): void {
		$this->caps = array( 'ffc_manage_date_messages' );
		$this->storeRule();
		$_POST = array( 'rule_id' => '3' );
		Filters\expectApplied( 'ffcertificate_emit_source_headers' )
			->once()
			->with( Mockery::any(), 'plugin:ffcertificate_date_messages' )
			->andReturn( false );

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'test_send' ) );

		$this->assertTrue( $r->success );
		$this->assertCount( 1, $this->mailed );
		$this->assertSame( 'op@example.org', $this->mailed[0]['to'] );
		$this->assertSame( '[TEST] Hi Maria', $this->mailed[0]['subject'] );
		// Wrapped in the e-mail chrome, with the rule's body inside.
		$this->assertStringContainsString( '<!DOCTYPE html>', $this->mailed[0]['body'] );
		$this->assertStringContainsString( '<p>Body of the rule</p>', $this->mailed[0]['body'] );

		// The history shows the test; the delivery log, which is the
		// deduplication, never sees it.
		$this->assertSame( 0, $this->touches( self::LOG ), 'The delivery log is never read or written.' );
		$this->assertCount( 1, $this->inserts );
		$this->assertSame( self::RUNS, $this->inserts[0]['table'] );
		$run = $this->inserts[0]['data'];
		$this->assertSame( 3, $run['rule_id'] );
		$this->assertSame( 'test', $run['trigger_kind'] );
		$this->assertSame( 5, $run['created_by'] );
		$this->assertSame( Runner::today()->format( 'Y-m-d' ), $run['target_from'] );
		$this->assertCount( 1, $this->queries );
		$this->assertSame( array( self::RUNS, 'sent', 'sent', 1, 12 ), $this->queries[0][1], 'Counted as sent.' );
	}

	public function test_a_refused_test_send_is_reported(): void {
		$this->caps        = array( 'ffc_manage_date_messages' );
		$this->mail_result = false;
		$this->storeRule();
		$_POST = array( 'rule_id' => '3' );

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'test_send' ) );

		$this->assertSame( 500, $r->status );
		// A refused test is in the history too, counted as failed.
		$this->assertCount( 1, $this->inserts );
		$this->assertSame( 'test', $this->inserts[0]['data']['trigger_kind'] );
		$this->assertSame( 5, $this->inserts[0]['data']['created_by'] );
		$this->assertCount( 1, $this->queries );
		$this->assertSame( array( self::RUNS, 'failed', 'failed', 1, 12 ), $this->queries[0][1], 'Counted as failed.' );
	}

	public function test_message_preview_needs_the_view_capability(): void {
		$this->caps = array();
		$rendered   = CallLog::record( 'get_bloginfo', 'Test Site' );

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'message_preview' ) );

		$this->assertSame( 403, $r->status );
		$this->assertSame( 0, $rendered->count(), 'Neither the sample nor the document is rendered.' );
	}

	public function test_message_preview_renders_what_sending_wraps_and_sends_nothing(): void {
		$this->storeRule();
		$_POST = array( 'rule_id' => '3' );

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'message_preview' ) );

		$this->assertTrue( $r->success );
		$this->assertSame( 'Hi Maria', $r->data['subject'] );
		$this->assertStringStartsWith( '<!DOCTYPE html>', ltrim( $r->data['html'] ) );
		$this->assertStringContainsString( '<p>Body of the rule</p>', $r->data['html'] );
		$this->assertIsFloat( $r->data['contrast'] );
		$this->assertSame( array(), $this->mailed, 'Nothing is sent.' );
		$this->assertSame( array(), $this->inserts, 'Nothing is recorded.' );
		$this->assertSame( array(), $this->queries );
		$this->assertSame( 0, $this->touches( self::LOG ) );
	}

	public function test_message_preview_reports_an_invalid_appearance(): void {
		Functions\when( 'sanitize_hex_color' )->justReturn( null );
		$rendered = CallLog::record( 'get_bloginfo', 'Test Site' );
		$_POST    = array(
			'rule' => array(
				'subject'    => 'S',
				'body'       => 'B',
				'appearance' => array( 'text_color' => 'red' ),
			),
		);

		$r = $this->respond( array( DateMessagesAjaxEndpoint::class, 'message_preview' ) );

		$this->assertSame( 400, $r->status );
		$this->assertSame( 0, $rendered->count(), 'The document is not rendered.' );
	}
}

/**
 * A `wp_send_json_*` call, caught.
 */
class JsonResponse extends \Exception {

	/**
	 * @param bool  $success Success flag.
	 * @param mixed $data    Payload.
	 * @param int   $status  HTTP status.
	 */
	public function __construct( public bool $success, public $data, public int $status ) {
		parent::__construct( 'json' );
	}
}
