<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\BulkIssuePage;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the refusals of BulkIssuePage::handle_preview(), the endpoint of
 * the Bulk issue tab (#1945, #1967). Each refusal happens before any file is
 * read, so the planner is not reached.
 *
 * @covers \FreeFormCertificate\Admin\BulkIssuePage
 */
class BulkIssuePageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = null ): void {
				throw new \RuntimeException( (string) $status );
			}
		);

		$_POST = array( 'form_id' => '12' );
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_refuses_a_user_without_the_import_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( '403' );

		BulkIssuePage::handle_preview();
	}

	public function test_refuses_an_id_that_is_not_a_form(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'post' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( '400' );

		BulkIssuePage::handle_preview();
	}

	public function test_refuses_a_form_the_user_may_not_edit(): void {
		Functions\when( 'current_user_can' )->alias(
			static fn( string $capability, ...$args ): bool => BulkIssuePage::CAP === $capability
		);
		Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( '400' );

		BulkIssuePage::handle_preview();
	}

	public function test_refuses_a_missing_form_id(): void {
		$_POST = array();
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( '400' );

		BulkIssuePage::handle_preview();
	}
	public function test_start_refuses_a_user_without_the_import_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( '403' );

		BulkIssuePage::handle_start();
	}

	public function test_batch_refuses_a_job_another_user_started(): void {
		global $wpdb;
		$wpdb = new class() {
			public string $prefix = 'wp_';

			public function prepare( $query, ...$args ) {
				return 'SQL';
			}

			public function get_row( $query = null, $output = null ) {
				return array(
					'id'      => 3,
					'form_id' => 7,
					'user_id' => 9,
					'status'  => 'staging',
					'total'   => 1,
					'issued'  => 0,
					'skipped' => 0,
				);
			}
		};

		$_POST = array( 'job_id' => '3' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( '403' );

		BulkIssuePage::handle_batch();
	}

	/**
	 * The preview step must carry the mapping and the choices on to the issue
	 * step, which plans the file again: without them the start request reached
	 * the server with the file alone and was refused as "nothing to issue".
	 */
	public function test_the_preview_carries_the_mapping_and_the_choices_to_the_issue_step(): void {
		Functions\when( 'esc_attr' )->alias( static fn( $text ): string => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		Functions\when( 'esc_html' )->alias( static fn( $text ): string => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		Functions\when( 'esc_html__' )->returnArg();
		$_POST = array(
			'ffc_issue_mode'         => 'column',
			'ffc_issue_date_column'  => 'Day',
			'ffc_issue_send_links'   => '1',
			'ffc_issue_score'        => 'zero',
		);

		$render = new \ReflectionMethod( BulkIssuePage::class, 'render_plan' );
		$render->setAccessible( true );

		ob_start();
		$render->invoke(
			null,
			array(
				'issue'      => array(),
				'skipped'    => array(),
				'zero_score' => 0,
			),
			array(
				'name'   => 'Nome',
				'cpf_rf' => 'CPF',
				'email'  => 'E-mail',
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<input type="hidden" name="ffc_map[name]" value="Nome">', $html );
		$this->assertStringContainsString( '<input type="hidden" name="ffc_map[cpf_rf]" value="CPF">', $html );
		$this->assertStringContainsString( '<input type="hidden" name="ffc_issue_mode" value="column">', $html );
		$this->assertStringContainsString( '<input type="hidden" name="ffc_issue_date_column" value="Day">', $html );
		$this->assertStringContainsString( '<input type="hidden" name="ffc_issue_send_links" value="1">', $html );
		$this->assertStringContainsString( '<input type="hidden" name="ffc_issue_score" value="zero">', $html );
	}

	/**
	 * The progress text is built from the job as the batch left it: reading it
	 * before the batch dropped the last batch from the final count.
	 */
	public function test_the_last_batch_reports_the_counts_after_it_ran(): void {
		global $wpdb;
		$wpdb = new class() {
			public string $prefix = 'wp_';

			/** @var array<int, string> */
			private array $states = array( 'staging', 'done', 'done' );

			public function prepare( $query, ...$args ) {
				return 'SQL';
			}

			public function get_row( $query = null, $output = null ) {
				$status = array_shift( $this->states ) ?? 'done';

				return array(
					'id'      => 3,
					'form_id' => 7,
					'user_id' => 5,
					'status'  => $status,
					'total'   => 30,
					'issued'  => 'staging' === $status ? 25 : 30,
					'skipped' => 0,
				);
			}
		};

		$_POST = array( 'job_id' => '3' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$sent = null;
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data ) use ( &$sent ): void {
				$sent = $data;
			}
		);

		BulkIssuePage::handle_batch();

		$this->assertTrue( $sent['done'] );
		$this->assertStringContainsString( '30', $sent['text'] );
		$this->assertStringNotContainsString( '25', $sent['text'] );
	}
}
