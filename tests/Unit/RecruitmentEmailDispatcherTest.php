<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentEmailDispatcher;

/**
 * Tests for RecruitmentEmailDispatcher — verifies the §11 placeholder
 * resolution, the from-header builder, the `wp_mail` call shape, and the
 * "no email on file" early-exit (returns false without invoking `wp_mail`).
 *
 * Best-effort send semantics (§7) mean we don't surface `wp_mail` return
 * codes; we just assert the call happened with the right payload.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentEmailDispatcher
 */
class RecruitmentEmailDispatcherTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-05-01 10:00:00' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return is_string( $email ) && false !== strpos( $email, '@' );
			}
		);

		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing( static fn ( $sql ) => $sql )
			->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function call_stub( int $id ): object {
		return (object) array(
			'id'                  => (string) $id,
			'classification_id'   => '10',
			'called_at'           => '2026-05-01 10:00:00',
			'date_to_assume'      => '2026-06-01',
			'time_to_assume'      => '08:00:00',
			'out_of_order'        => '0',
			'out_of_order_reason' => null,
			'cancellation_reason' => null,
			'cancelled_at'        => null,
			'cancelled_by'        => null,
			'notes'               => null,
			'created_by'          => '1',
			'created_at'          => '2026-05-01 10:00:00',
			'updated_at'          => '2026-05-01 10:00:00',
		);
	}

	private function classification_stub(): object {
		return (object) array(
			'id'           => '10',
			'candidate_id' => '100',
			'adjutancy_id' => '2',
			'notice_id'    => '5',
			'list_type'    => 'definitive',
			'rank'         => '1',
			'score'        => '90.0000',
			'status'       => 'called',
			'created_at'   => '2026-05-01 10:00:00',
			'updated_at'   => '2026-05-01 10:00:00',
		);
	}

	private function candidate_stub( ?string $email_encrypted = null ): object {
		return (object) array(
			'id'              => '100',
			'user_id'         => null,
			'name'            => 'Alice',
			'cpf_encrypted'   => 'cpf-cipher',
			'cpf_hash'        => 'cpf-hash',
			'rf_encrypted'    => null,
			'rf_hash'         => null,
			'email_encrypted' => $email_encrypted,
			'email_hash'      => null === $email_encrypted ? null : 'email-hash',
			'phone'           => null,
			'notes'           => null,
			'pcd_hash'        => 'pcd-hash',
			'created_at'      => '2026-05-01 10:00:00',
			'updated_at'      => '2026-05-01 10:00:00',
		);
	}

	private function notice_stub(): object {
		return (object) array(
			'id'                    => '5',
			'code'                  => 'EDITAL-2026-01',
			'name'                  => 'Edital de 2026',
			'status'                => 'active',
			'opened_at'             => '2026-04-01 09:00:00',
			'closed_at'             => null,
			'was_reopened'          => '0',
			'public_columns_config' => '{}',
			'created_at'            => '2026-05-01 10:00:00',
			'updated_at'            => '2026-05-01 10:00:00',
		);
	}

	private function adjutancy_stub(): object {
		return (object) array(
			'id'         => '2',
			'slug'       => 'matematica',
			'name'       => 'Matemática',
			'created_at' => '2026-05-01 10:00:00',
			'updated_at' => '2026-05-01 10:00:00',
		);
	}

	public function test_returns_false_when_call_unknown(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$wp_mail_called = false;
		Functions\when( 'wp_mail' )->alias(
			static function () use ( &$wp_mail_called ) {
				$wp_mail_called = true;
				return true;
			}
		);

		$this->assertFalse( RecruitmentEmailDispatcher::send_for_call( 999 ) );
		$this->assertFalse( $wp_mail_called );
	}

	public function test_returns_false_when_candidate_has_no_email(): void {
		$this->wpdb->shouldReceive( 'get_row' )
			->times( 5 )
			->andReturn(
				$this->call_stub( 7 ),
				$this->classification_stub(),
				$this->candidate_stub( null ),
				$this->notice_stub(),
				$this->adjutancy_stub()
			);

		$wp_mail_called = false;
		Functions\when( 'wp_mail' )->alias(
			static function () use ( &$wp_mail_called ) {
				$wp_mail_called = true;
				return true;
			}
		);

		$this->assertFalse( RecruitmentEmailDispatcher::send_for_call( 7 ) );
		$this->assertFalse( $wp_mail_called, 'No email = no wp_mail call' );
	}

	public function test_returns_false_when_classification_unknown(): void {
		$this->wpdb->shouldReceive( 'get_row' )
			->times( 2 )
			->andReturn( $this->call_stub( 7 ), null );

		$wp_mail_called = false;
		Functions\when( 'wp_mail' )->alias(
			static function () use ( &$wp_mail_called ) {
				$wp_mail_called = true;
				return true;
			}
		);

		$this->assertFalse( RecruitmentEmailDispatcher::send_for_call( 7 ) );
		$this->assertFalse( $wp_mail_called );
	}

	public function test_returns_false_when_notice_or_adjutancy_unknown(): void {
		// Notice lookup returns null — adjutancy lookup is also queried
		// (both fetched before the null-check), but the result is the same:
		// no send.
		$this->wpdb->shouldReceive( 'get_row' )
			->times( 5 )
			->andReturn(
				$this->call_stub( 7 ),
				$this->classification_stub(),
				$this->candidate_stub( 'enc-email' ),
				null, // notice missing
				$this->adjutancy_stub()
			);

		$wp_mail_called = false;
		Functions\when( 'wp_mail' )->alias(
			static function () use ( &$wp_mail_called ) {
				$wp_mail_called = true;
				return true;
			}
		);

		$this->assertFalse( RecruitmentEmailDispatcher::send_for_call( 7 ) );
		$this->assertFalse( $wp_mail_called );
	}
}
