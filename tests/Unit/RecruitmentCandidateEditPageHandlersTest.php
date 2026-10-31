<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Recruitment\RecruitmentCandidateEditPage;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;

/**
 * Behavior tests for the RecruitmentCandidateEditPage admin-post handlers:
 * handle_save (general fields + email re-encrypt + diff log), handle_delete
 * (success / blocked), handle_link_user / handle_unlink_user (user-pointer
 * mutation), and the resolve_user() lookup strategy. The terminal
 * wp_safe_redirect()/exit and wp_die() are replaced by marker exceptions so
 * each branch is observable, and no test reaches the `exit`.
 *
 * The collaborators are the real ones: Capabilities, RecruitmentPiiAccessPolicy
 * (the tier follows the stored row's owner and `user_can()`), the candidate
 * Reader and Writer, Encryption with the bootstrap's keys,
 * RecruitmentDeleteService with the classification repository, and
 * RecruitmentActivityLogger down to ActivityLog's write buffer. Storage is a
 * `$wpdb` double that holds one candidate row and records every write.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidateEditPage
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidateEditor
 */
class RecruitmentCandidateEditPageHandlersTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const CANDIDATES      = 'wp_ffc_recruitment_candidate';
	private const CLASSIFICATIONS = 'wp_ffc_recruitment_classification';

	/** The operator running the handlers. */
	private const OPERATOR_ID = 1;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var object|null The candidate row the database holds. */
	private ?object $candidate = null;

	/** @var int Classifications the database holds for the candidate. */
	private int $classification_count = 0;

	/** @var array<string, bool> Capabilities the current user holds, by name. */
	private array $caps = array();

	/** @var bool What `user_can()` answers for the operator. */
	private bool $operator_can = false;

	/** @var array<int, mixed> Arguments of the last prepare(). */
	private array $bound = array();

	/**
	 * Every `$wpdb->update()`, as [table, data, where].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private array $updates = array();

	/**
	 * Every `$wpdb->delete()`, as [table, where].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $deletes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message, $args = array() ) {
				$ffc_type = isset( $args['type'] ) ? $args['type'] : 'info';
				$ffc_cls  = 'notice notice-' . $ffc_type;
				if ( ! empty( $args['dismissible'] ) ) { $ffc_cls .= ' is-dismissible'; }
				if ( ! empty( $args['additional_classes'] ) ) { $ffc_cls .= ' ' . implode( ' ', $args['additional_classes'] ); }
				$ffc_wrap = ! array_key_exists( 'paragraph_wrap', $args ) || $args['paragraph_wrap'];
				echo '<div class="' . $ffc_cls . '">' . ( $ffc_wrap ? '<p>' . $message . '</p>' : $message ) . '</div>';
			}
		);

		$this->candidate            = $this->candidate_before();
		$this->classification_count = 0;
		$this->caps                 = array();
		$this->operator_can         = false;
		$this->updates              = array();
		$this->deletes              = array();
		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ) => (int) $v );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg();
		// Every capability is held unless a test takes one away.
		Functions\when( 'current_user_can' )->alias( fn ( $cap ) => $this->caps[ $cap ] ?? true );
		Functions\when( 'user_can' )->alias( fn () => $this->operator_can );
		Functions\when( 'get_current_user_id' )->justReturn( self::OPERATOR_ID );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// Debug areas off, activity log on.
		Functions\when( 'get_option' )->alias(
			static fn ( $name, $default_value = false ) => 'ffc_settings' === $name ? ActivityLogBuffer::ENABLED_SETTINGS : $default_value
		);
		Functions\when( 'add_query_arg' )->alias(
			static fn ( $args ) => 'admin.php?' . http_build_query( (array) $args )
		);
		Functions\when( 'wp_die' )->alias(
			static function ( $msg = '' ) {
				throw new \RuntimeException( 'WP_DIE:' . $msg );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) {
				throw new \RuntimeException( 'REDIRECT:' . $url );
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->make_wpdb();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		unset(
			$_POST['candidate_id'],
			$_POST['name'],
			$_POST['email'],
			$_POST['phone'],
			$_POST['notes'],
			$_POST['user_lookup']
		);
		parent::tearDown();
	}

	/**
	 * A `$wpdb` double holding one candidate row; an unexpected query fails the test.
	 */
	private function make_wpdb(): object {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->bound = $args;
				return (string) $sql;
			}
		);
		// RecruitmentCandidateReader::get_by_id() binds the table, then the id.
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				if ( self::CANDIDATES === ( $this->bound[0] ?? null ) && false !== strpos( $sql, 'WHERE id = %d' ) ) {
					return ( null !== $this->candidate && (int) $this->candidate->id === (int) $this->bound[1] ) ? $this->candidate : null;
				}
				$this->fail( 'Unexpected get_row(): ' . $sql );
			}
		);
		// RecruitmentClassificationRepository::count_for_candidate().
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				if ( self::CLASSIFICATIONS === ( $this->bound[0] ?? null ) && false !== strpos( $sql, 'WHERE candidate_id = %d' ) ) {
					return (string) $this->classification_count;
				}
				$this->fail( 'Unexpected get_var(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				if ( self::CANDIDATES !== $table ) {
					$this->fail( 'Unexpected update() on ' . $table );
				}
				$this->updates[] = array( $table, $data, $where );
				return 1;
			}
		);
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				if ( self::CANDIDATES !== $table ) {
					$this->fail( 'Unexpected delete() on ' . $table );
				}
				$this->deletes[] = array( $table, $where );
				return 1;
			}
		);
		return $wpdb;
	}

	private function capture( callable $fn ): string {
		try {
			$fn();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		return '';
	}

	/**
	 * The stored candidate. It belongs to the operator, so the email tier is
	 * `unmasked` unless a test hands the row to someone else.
	 */
	private function candidate_before(): object {
		return (object) array(
			'id'         => '5',
			'user_id'    => (string) self::OPERATOR_ID,
			'name'       => 'Old Name',
			'phone'      => '111',
			'notes'      => null,
			'email_hash' => 'old-hash',
		);
	}

	/**
	 * Hand the stored candidate to another user, so the operator's tier
	 * comes from `user_can()`: `reveal` when it answers true, else `masked`.
	 *
	 * @param bool $operator_can What `user_can()` answers.
	 */
	private function owned_by_someone_else( bool $operator_can ): void {
		$this->candidate->user_id = '99';
		$this->operator_can       = $operator_can;
	}

	/**
	 * The data of the single candidate update the handler wrote.
	 *
	 * @return array<string, mixed>
	 */
	private function the_update(): array {
		$this->assertCount( 1, $this->updates, 'Exactly one candidate update.' );
		$this->assertSame( array( 'id' => 5 ), $this->updates[0][2] );
		return $this->updates[0][1];
	}

	// ==================================================================
	// handle_save()
	// ==================================================================

	public function test_handle_save_dies_without_cap(): void {
		$this->caps['ffc_manage_recruitment'] = false;

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_save' ) );

		$this->assertStringStartsWith( 'WP_DIE:', $msg );
		$this->assertSame( array(), $this->updates );
	}

	public function test_handle_save_redirects_to_list_when_id_zero(): void {
		$_POST['candidate_id'] = '0';

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_save' ) );

		$this->assertStringContainsString( 'tab=candidates', $msg );
		$this->assertStringNotContainsString( 'action=edit-candidate', $msg );
		$this->assertSame( array(), $this->updates );
	}

	public function test_handle_save_updates_fields_clears_email_and_logs_diff(): void {
		$_POST['candidate_id'] = '5';
		$_POST['name']         = 'New Name';
		$_POST['email']        = '';
		$_POST['phone']        = '';
		$_POST['notes']        = 'A note';

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_save' ) );

		$captured = $this->the_update();
		$this->assertSame( 'New Name', $captured['name'] );
		$this->assertNull( $captured['phone'], 'empty phone clears to null' );
		$this->assertSame( 'A note', $captured['notes'] );
		$this->assertArrayHasKey( 'email_encrypted', $captured );
		$this->assertNull( $captured['email_encrypted'], 'empty email clears encrypted column' );
		$this->assertArrayHasKey( 'email_hash', $captured );
		$this->assertNull( $captured['email_hash'] );

		// Diff: name changed, phone changed (111 → ''), notes changed (null → A note),
		// email_hash changed (old-hash → '').
		$logged = ActivityLogBuffer::contexts();
		$this->assertCount( 1, $logged );
		$this->assertSame( 'recruitment_candidate_fields_edited', $logged[0]['action'] );
		$this->assertSame( 5, $logged[0]['context']['candidate_id'] );
		$logged_diff = $logged[0]['context']['changes'];
		$this->assertArrayHasKey( 'name', $logged_diff );
		$this->assertArrayHasKey( 'phone', $logged_diff );
		$this->assertArrayHasKey( 'notes', $logged_diff );
		$this->assertArrayHasKey( 'email_hash', $logged_diff );
		$this->assertStringContainsString( 'ffc_msg=saved', $msg );
	}

	public function test_handle_save_encrypts_new_email(): void {
		$_POST['candidate_id'] = '5';
		$_POST['name']         = 'Old Name';
		$_POST['email']        = 'new@example.test';
		$_POST['phone']        = '111';
		$_POST['notes']        = '';

		$this->capture( array( RecruitmentCandidateEditPage::class, 'handle_save' ) );

		$captured = $this->the_update();
		$this->assertSame( 'new@example.test', Encryption::decrypt( (string) $captured['email_encrypted'] ) );
		$this->assertSame( Encryption::hash( 'new@example.test' ), $captured['email_hash'] );
	}

	public function test_handle_save_preserves_email_for_masked_tier(): void {
		// #739 §4.1 — a masked-tier operator cannot overwrite/clear the email
		// even with a forged field: the write is gated on the loaded row's tier.
		$_POST['candidate_id'] = '5';
		$_POST['name']         = 'Old Name';
		$_POST['email']        = 'attacker@evil.test';
		$_POST['phone']        = '111';
		$_POST['notes']        = '';

		$this->owned_by_someone_else( false );

		$this->capture( array( RecruitmentCandidateEditPage::class, 'handle_save' ) );

		// Email columns are never written, so the stored address is preserved.
		$captured = $this->the_update();
		$this->assertArrayNotHasKey( 'email_encrypted', $captured );
		$this->assertArrayNotHasKey( 'email_hash', $captured );
		$this->assertSame( 'Old Name', $captured['name'] );
	}

	public function test_handle_save_writes_the_email_a_reveal_tier_operator_revealed(): void {
		// #1863 — the reveal tier edits the email once a reveal enabled the
		// field, which is what puts it in the POST.
		$_POST['candidate_id'] = '5';
		$_POST['name']         = 'Old Name';
		$_POST['email']        = 'new@example.test';
		$_POST['phone']        = '111';
		$_POST['notes']        = '';

		$this->owned_by_someone_else( true );

		$this->capture( array( RecruitmentCandidateEditPage::class, 'handle_save' ) );

		$this->assertSame( 'new@example.test', Encryption::decrypt( (string) $this->the_update()['email_encrypted'] ) );
	}

	public function test_handle_save_keeps_the_email_a_reveal_tier_operator_never_revealed(): void {
		// The field stayed disabled, so no `email` was posted: keep the stored one.
		$_POST['candidate_id'] = '5';
		$_POST['name']         = 'Old Name';
		$_POST['phone']        = '111';
		$_POST['notes']        = '';

		$this->owned_by_someone_else( true );

		$this->capture( array( RecruitmentCandidateEditPage::class, 'handle_save' ) );

		$captured = $this->the_update();
		$this->assertArrayNotHasKey( 'email_encrypted', $captured );
		$this->assertArrayNotHasKey( 'email_hash', $captured );
	}

	// ==================================================================
	// handle_delete()
	// ==================================================================

	public function test_handle_delete_dies_without_delete_cap(): void {
		$this->caps['manage_options']         = false;
		$this->caps['ffc_delete_recruitment'] = false;

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_delete' ) );

		$this->assertStringStartsWith( 'WP_DIE:', $msg );
		$this->assertSame( array(), $this->deletes );
	}

	public function test_handle_delete_redirects_to_candidates_tab_on_success(): void {
		$_POST['candidate_id'] = '5';

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_delete' ) );

		$this->assertSame( array( array( self::CANDIDATES, array( 'id' => 5 ) ) ), $this->deletes );
		$this->assertSame( 'recruitment_candidate_deleted', ActivityLogBuffer::contexts()[0]['action'] );
		$this->assertStringContainsString( 'ffc_msg=deleted', $msg );
		$this->assertStringContainsString( 'tab=candidates', $msg );
		$this->assertStringNotContainsString( 'action=edit-candidate', $msg );
	}

	public function test_handle_delete_flashes_blocked_on_failure(): void {
		$_POST['candidate_id']      = '5';
		$this->classification_count = 2;

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_delete' ) );

		$this->assertSame( array(), $this->deletes, 'A candidate with classifications is not deleted.' );
		$this->assertStringContainsString( 'ffc_msg=delete-blocked', $msg );
		$this->assertStringContainsString( 'action=edit-candidate', $msg );
	}

	// ==================================================================
	// handle_link_user() / handle_unlink_user()
	// ==================================================================

	public function test_handle_link_user_flashes_not_found_for_unknown_lookup(): void {
		$_POST['candidate_id'] = '5';
		$_POST['user_lookup']  = 'ghost';

		Functions\when( 'get_user_by' )->justReturn( false );

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_link_user' ) );

		$this->assertStringContainsString( 'ffc_msg=link-user-not-found', $msg );
		$this->assertSame( array(), $this->updates );
	}

	public function test_handle_link_user_sets_pointer_and_flashes_ok(): void {
		$_POST['candidate_id'] = '5';
		$_POST['user_lookup']  = '42';

		$user     = Mockery::mock( \WP_User::class );
		$user->ID = 42;
		Functions\when( 'get_user_by' )->justReturn( $user );

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_link_user' ) );

		// the_update() also pins the row: WHERE id = 5.
		$this->assertSame( 42, $this->the_update()['user_id'] );
		$this->assertStringContainsString( 'ffc_msg=link-user-ok', $msg );
	}

	public function test_handle_unlink_user_clears_pointer(): void {
		$_POST['candidate_id'] = '5';

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_unlink_user' ) );

		$data = $this->the_update();
		$this->assertArrayHasKey( 'user_id', $data );
		$this->assertNull( $data['user_id'] );
		$this->assertStringContainsString( 'ffc_msg=unlink-user-ok', $msg );
		$this->assertSame(
			array(
				array(
					'candidate_id'  => 5,
					'previous_user' => self::OPERATOR_ID,
				),
			),
			$this->logged( 'recruitment_candidate_account_unlinked' )
		);
	}

	public function test_handle_link_user_logs_the_previous_and_the_new_account(): void {
		$_POST['candidate_id'] = '5';
		$_POST['user_lookup']  = '42';
		$this->candidate->user_id = '99';

		$this->lookup_returns( 42 );

		$this->capture( array( RecruitmentCandidateEditPage::class, 'handle_link_user' ) );

		$this->assertSame(
			array(
				array(
					'candidate_id'  => 5,
					'previous_user' => 99,
					'linked_user'   => 42,
				),
			),
			$this->logged( 'recruitment_candidate_account_linked' )
		);
	}

	public function test_handle_link_user_refuses_the_operators_own_account(): void {
		$_POST['candidate_id'] = '5';
		$_POST['user_lookup']  = (string) self::OPERATOR_ID;

		$this->lookup_returns( self::OPERATOR_ID );

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_link_user' ) );

		$this->assertStringContainsString( 'ffc_msg=link-user-self', $msg );
		$this->assertSame( array(), $this->updates, 'Becoming the owner would unmask the data without the PII capability.' );
		$this->assertSame( array(), $this->logged( 'recruitment_candidate_account_linked' ) );
	}

	public function test_handle_link_user_without_the_pii_cap_needs_the_candidates_own_identifiers(): void {
		$_POST['candidate_id'] = '5';
		$_POST['user_lookup']  = '42';
		$this->caps['manage_options']           = false;
		$this->caps['ffc_view_recruitment_pii'] = false;
		$this->candidate->email_encrypted       = Encryption::encrypt( 'jane@example.com' );
		$this->candidate->cpf_hash              = null;
		$this->candidate->rf_hash               = null;

		// The candidate's e-mail belongs to account 7, not 42.
		$this->lookup_returns( 42, 7 );

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_link_user' ) );

		$this->assertStringContainsString( 'ffc_msg=link-user-mismatch', $msg );
		$this->assertSame( array(), $this->updates );
	}

	public function test_handle_link_user_without_the_pii_cap_links_the_account_carrying_the_email(): void {
		$_POST['candidate_id'] = '5';
		$_POST['user_lookup']  = '7';
		$this->caps['manage_options']           = false;
		$this->caps['ffc_view_recruitment_pii'] = false;
		$this->candidate->email_encrypted       = Encryption::encrypt( 'jane@example.com' );
		$this->candidate->cpf_hash              = null;
		$this->candidate->rf_hash               = null;

		$this->lookup_returns( 7, 7 );

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_link_user' ) );

		$this->assertSame( 7, $this->the_update()['user_id'] );
		$this->assertStringContainsString( 'ffc_msg=link-user-ok', $msg );
	}

	/**
	 * Answer `get_user_by()`: the typed lookup finds `$typed`, and the
	 * candidate's e-mail finds `$by_email` (or nobody).
	 *
	 * @param int      $typed    Account the operator typed.
	 * @param int|null $by_email Account carrying the candidate's e-mail.
	 */
	private function lookup_returns( int $typed, ?int $by_email = null ): void {
		Functions\when( 'get_user_by' )->alias(
			static function ( $by, $value ) use ( $typed, $by_email ) {
				$id = 'email' === $by ? $by_email : $typed;
				if ( null === $id ) {
					return false;
				}
				$user     = Mockery::mock( \WP_User::class );
				$user->ID = $id;
				return $user;
			}
		);
	}

	/**
	 * The buffered activity-log contexts of one action.
	 *
	 * @param string $action Action.
	 * @return list<mixed>
	 */
	private function logged( string $action ): array {
		$out = array();
		foreach ( ActivityLogBuffer::contexts() as $entry ) {
			if ( $action === $entry['action'] ) {
				$out[] = $entry['context'];
			}
		}
		return $out;
	}

	// ==================================================================
	// resolve_user() — lookup strategy (via reflection)
	// ==================================================================

	private function resolve_user( string $lookup ) {
		$ref = new \ReflectionClass( RecruitmentCandidateEditPage::class );
		$m   = $ref->getMethod( 'resolve_user' );
		$m->setAccessible( true );
		return $m->invoke( null, $lookup );
	}

	public function test_resolve_user_returns_null_for_empty(): void {
		$this->assertNull( $this->resolve_user( '' ) );
	}

	public function test_resolve_user_uses_id_strategy_for_numeric(): void {
		$user = Mockery::mock( \WP_User::class );
		$seen = null;
		Functions\when( 'get_user_by' )->alias(
			static function ( $by, $value ) use ( $user, &$seen ) {
				$seen = array( $by, $value );
				return $user;
			}
		);

		$out = $this->resolve_user( '42' );

		$this->assertSame( $user, $out );
		$this->assertSame( 'id', $seen[0] );
	}

	public function test_resolve_user_uses_email_strategy_when_at_present(): void {
		$user = Mockery::mock( \WP_User::class );
		$seen = null;
		Functions\when( 'get_user_by' )->alias(
			static function ( $by, $value ) use ( $user, &$seen ) {
				$seen = $by;
				return $user;
			}
		);

		$this->resolve_user( 'a@b.test' );

		$this->assertSame( 'email', $seen );
	}

	public function test_resolve_user_uses_login_strategy_otherwise(): void {
		$user = Mockery::mock( \WP_User::class );
		$seen = null;
		Functions\when( 'get_user_by' )->alias(
			static function ( $by, $value ) use ( $user, &$seen ) {
				$seen = $by;
				return $user;
			}
		);

		$this->resolve_user( 'alice' );

		$this->assertSame( 'login', $seen );
	}

	public function test_resolve_user_returns_null_when_not_wp_user(): void {
		Functions\when( 'get_user_by' )->justReturn( false );

		$this->assertNull( $this->resolve_user( 'alice' ) );
	}
}
