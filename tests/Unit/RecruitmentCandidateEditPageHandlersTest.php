<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCandidateEditPage;

/**
 * Behavior tests for the RecruitmentCandidateEditPage admin-post handlers:
 * handle_save (general fields + email re-encrypt + diff log), handle_delete
 * (success / blocked), handle_link_user / handle_unlink_user (user-pointer
 * mutation), and the resolve_user() lookup strategy. The terminal
 * wp_safe_redirect()/exit and wp_die() are replaced by marker exceptions so
 * each branch is observable.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidateEditPage
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentCandidateEditPageHandlersTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var \Mockery\MockInterface */
	private $utils;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ) => (int) $v );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
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

		// Utils::current_user_can_admin_or — used by handle_delete's cap gate.
		$this->utils = Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' );
		$this->utils->shouldReceive( 'current_user_can_admin_or' )->andReturn( true )->byDefault();
	}

	protected function tearDown(): void {
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

	private function capture( callable $fn ): string {
		try {
			$fn();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		return '';
	}

	private function candidate_before(): object {
		return (object) array(
			'id'         => '5',
			'name'       => 'Old Name',
			'phone'      => '111',
			'notes'      => null,
			'email_hash' => 'old-hash',
		);
	}

	// ==================================================================
	// handle_save()
	// ==================================================================

	public function test_handle_save_dies_without_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_save' ) );

		$this->assertStringStartsWith( 'WP_DIE:', $msg );
	}

	public function test_handle_save_redirects_to_list_when_id_zero(): void {
		$_POST['candidate_id'] = '0';

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_save' ) );

		$this->assertStringContainsString( 'tab=candidates', $msg );
		$this->assertStringNotContainsString( 'action=edit-candidate', $msg );
	}

	public function test_handle_save_updates_fields_clears_email_and_logs_diff(): void {
		$_POST['candidate_id'] = '5';
		$_POST['name']         = 'New Name';
		$_POST['email']        = '';
		$_POST['phone']        = '';
		$_POST['notes']        = 'A note';

		$repo     = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateRepository' );
		$repo->shouldReceive( 'get_by_id' )->andReturn( $this->candidate_before() );
		$captured = null;
		$repo->shouldReceive( 'update' )->once()->andReturnUsing(
			function ( $id, $data ) use ( &$captured ) {
				$captured = array( $id, $data );
				return 1;
			}
		);

		$logger      = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentActivityLogger' );
		$logged_diff = null;
		$logger->shouldReceive( 'candidate_fields_edited' )->once()->andReturnUsing(
			function ( $id, $changes ) use ( &$logged_diff ) {
				$logged_diff = $changes;
				return null;
			}
		);

		$enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
		$enc->shouldReceive( 'hash' )->andReturnUsing( static fn ( $v ) => 'h:' . $v );
		$enc->shouldReceive( 'encrypt' )->andReturnUsing( static fn ( $v ) => 'e:' . $v );

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_save' ) );

		$this->assertSame( 5, $captured[0] );
		$this->assertSame( 'New Name', $captured[1]['name'] );
		$this->assertNull( $captured[1]['phone'], 'empty phone clears to null' );
		$this->assertSame( 'A note', $captured[1]['notes'] );
		$this->assertNull( $captured[1]['email_encrypted'], 'empty email clears encrypted column' );
		$this->assertNull( $captured[1]['email_hash'] );
		// Diff: name changed, phone changed (111 → ''), notes changed (null → A note),
		// email_hash changed (old-hash → '').
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

		$repo     = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateRepository' );
		$repo->shouldReceive( 'get_by_id' )->andReturn( $this->candidate_before() );
		$captured = null;
		$repo->shouldReceive( 'update' )->once()->andReturnUsing(
			function ( $id, $data ) use ( &$captured ) {
				$captured = $data;
				return 1;
			}
		);

		$logger = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentActivityLogger' );
		$logger->shouldReceive( 'candidate_fields_edited' )->andReturn( null );

		$enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
		$enc->shouldReceive( 'hash' )->andReturnUsing( static fn ( $v ) => 'h:' . $v );
		$enc->shouldReceive( 'encrypt' )->andReturnUsing( static fn ( $v ) => 'e:' . $v );

		$this->capture( array( RecruitmentCandidateEditPage::class, 'handle_save' ) );

		$this->assertSame( 'e:new@example.test', $captured['email_encrypted'] );
		$this->assertSame( 'h:new@example.test', $captured['email_hash'] );
	}

	// ==================================================================
	// handle_delete()
	// ==================================================================

	public function test_handle_delete_dies_without_delete_cap(): void {
		// Override the permissive byDefault() with a strict false expectation.
		$this->utils->shouldReceive( 'current_user_can_admin_or' )
			->with( 'ffc_delete_recruitment' )
			->andReturn( false );

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_delete' ) );

		$this->assertStringStartsWith( 'WP_DIE:', $msg );
	}

	public function test_handle_delete_redirects_to_candidates_tab_on_success(): void {
		$_POST['candidate_id'] = '5';

		$svc = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentDeleteService' );
		$svc->shouldReceive( 'delete_candidate' )->once()->with( 5 )->andReturn(
			array(
				'success' => true,
				'errors'  => array(),
			)
		);

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_delete' ) );

		$this->assertStringContainsString( 'ffc_msg=deleted', $msg );
		$this->assertStringContainsString( 'tab=candidates', $msg );
		$this->assertStringNotContainsString( 'action=edit-candidate', $msg );
	}

	public function test_handle_delete_flashes_blocked_on_failure(): void {
		$_POST['candidate_id'] = '5';

		$svc = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentDeleteService' );
		$svc->shouldReceive( 'delete_candidate' )->once()->andReturn(
			array(
				'success' => false,
				'errors'  => array( 'blocked' ),
			)
		);

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_delete' ) );

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
	}

	public function test_handle_link_user_sets_pointer_and_flashes_ok(): void {
		$_POST['candidate_id'] = '5';
		$_POST['user_lookup']  = '42';

		$user     = Mockery::mock( \WP_User::class );
		$user->ID = 42;
		Functions\when( 'get_user_by' )->justReturn( $user );

		$repo    = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateRepository' );
		$set_uid = null;
		$repo->shouldReceive( 'set_user_id' )->once()->andReturnUsing(
			function ( $id, $uid ) use ( &$set_uid ) {
				$set_uid = array( $id, $uid );
				return true;
			}
		);

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_link_user' ) );

		$this->assertSame( array( 5, 42 ), $set_uid );
		$this->assertStringContainsString( 'ffc_msg=link-user-ok', $msg );
	}

	public function test_handle_unlink_user_clears_pointer(): void {
		$_POST['candidate_id'] = '5';

		$repo    = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateRepository' );
		$cleared = false;
		$repo->shouldReceive( 'set_user_id' )->once()->andReturnUsing(
			function ( $id, $uid ) use ( &$cleared ) {
				$cleared = ( 5 === $id && null === $uid );
				return true;
			}
		);

		$msg = $this->capture( array( RecruitmentCandidateEditPage::class, 'handle_unlink_user' ) );

		$this->assertTrue( $cleared );
		$this->assertStringContainsString( 'ffc_msg=unlink-user-ok', $msg );
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
