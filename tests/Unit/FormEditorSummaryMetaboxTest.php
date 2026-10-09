<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormEditorSummaryMetabox;

/**
 * @covers \FreeFormCertificate\Admin\FormEditorSummaryMetabox
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class FormEditorSummaryMetaboxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Post meta by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}
		foreach ( array( '__', 'esc_html__', 'esc_html', 'esc_attr', 'esc_url' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => '/wp-admin/' . $p );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $key ] ?? '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function post( string $status = 'publish' ): \WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = 20;
		$post->post_status = $status;
		return $post;
	}

	/**
	 * The rows keyed by label.
	 *
	 * @return array<string, array{label: string, html: string, on: bool}>
	 */
	private function rows( int $count, string $status = 'publish' ): array {
		$repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\SubmissionRepository' );
		$repo->shouldReceive( 'countForExport' )->with( array( 20 ), 'publish' )->andReturn( $count );
		return array_column( ( new FormEditorSummaryMetabox() )->rows( $this->post( $status ) ), null, 'label' );
	}

	public function test_a_new_form_reads_as_empty_and_open(): void {
		$rows = $this->rows( 0 );

		$this->assertSame( '0', $rows['Fields']['html'] );
		$this->assertSame( '0', $rows['Submissions']['html'], 'no link when there is nothing to list' );
		$this->assertSame( 'Open to everyone', $rows['Restrictions']['html'] );
		$this->assertSame( 'Off', $rows['Emails']['html'] );
		foreach ( array( 'Date/time window', 'Geolocation', 'Quiz', 'Operator access' ) as $label ) {
			$this->assertSame( 'Off', $rows[ $label ]['html'], $label );
			$this->assertFalse( $rows[ $label ]['on'], $label );
		}
	}

	public function test_names_what_the_saved_form_has_on(): void {
		$this->meta = array(
			'_ffc_form_fields'          => array( array( 'name' => 'a' ), array( 'name' => 'b' ) ),
			'_ffc_form_config'          => array(
				'restrictions'     => array( 'password' => '1', 'ticket' => '1', 'allowlist' => '0' ),
				'send_user_email'  => '1',
				'send_admin_email' => '1',
				'quiz_enabled'     => '1',
			),
			'_ffc_device_limit_enabled' => '1',
			'_ffc_geofence_config'      => array( 'datetime_enabled' => '1', 'geo_enabled' => '0' ),
			'_ffc_csv_public_enabled'   => '1',
		);

		$rows = $this->rows( 7 );

		$this->assertSame( '2', $rows['Fields']['html'] );
		$this->assertSame( '<a href="/wp-admin/edit.php?post_type=ffc_form&page=ffc-submissions&filter_form_id=20">7</a>', $rows['Submissions']['html'] );
		$this->assertSame( 'Password, Ticket, Device limit', $rows['Restrictions']['html'] );
		$this->assertSame( 'Participant, Administrator', $rows['Emails']['html'] );
		$this->assertSame( 'On', $rows['Date/time window']['html'] );
		$this->assertSame( 'Off', $rows['Geolocation']['html'] );
		$this->assertTrue( $rows['Quiz']['on'] );
		$this->assertTrue( $rows['Operator access']['on'] );
	}

	public function test_an_unsaved_form_does_not_query_submissions(): void {
		$repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\SubmissionRepository' );
		$repo->shouldNotReceive( 'countForExport' );

		$rows = array_column( ( new FormEditorSummaryMetabox() )->rows( $this->post( 'auto-draft' ) ), null, 'label' );

		$this->assertSame( '0', $rows['Submissions']['html'] );
	}

	public function test_render_prints_a_definition_list_and_the_saved_state_note(): void {
		$repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\SubmissionRepository' );
		$repo->shouldReceive( 'countForExport' )->andReturn( 0 );

		ob_start();
		( new FormEditorSummaryMetabox() )->render( $this->post() );
		$html = (string) ob_get_clean();

		$this->assertStringStartsWith( '<dl class="ffc-facts ffc-form-summary">', $html );
		$this->assertSame( 8, substr_count( $html, '<dt class="ffc-facts__label">' ) );
		$this->assertStringContainsString( 'As last saved.', $html );
	}
}
