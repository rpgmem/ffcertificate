<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\UrlShortenerService;
use FreeFormCertificate\UrlShortener\UrlShortenerRepository;

/**
 * Tests for UrlShortenerService: short URL creation, code generation,
 * settings accessors, CRUD operations, and statistics.
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerService
 */
class UrlShortenerServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private UrlShortenerService $service;

	/** @var UrlShortenerRepository|Mockery\MockInterface */
	private $repo;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub common WP functions
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_title' )->alias( function ( $title ) {
			return strtolower( preg_replace( '/[^a-zA-Z0-9\-]/', '', $title ) );
		} );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'home_url' )->alias( function ( $path = '' ) {
			return 'https://example.com' . $path;
		} );
		Functions\when( 'current_time' )->justReturn( '2026-02-22 12:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
			return array_merge( $defaults, $args );
		} );

		// Namespaced WP function stubs (resolved inside FreeFormCertificate\UrlShortener)
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_title' )->alias( function ( $title ) {
			return strtolower( preg_replace( '/[^a-zA-Z0-9\-]/', '', $title ) );
		} );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'home_url' )->alias( function ( $path = '' ) {
			return 'https://example.com' . $path;
		} );
		Functions\when( 'current_time' )->justReturn( '2026-02-22 12:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( '__' )->returnArg();

		$this->repo = Mockery::mock( UrlShortenerRepository::class );
		// No code is retired unless a test says so (#2337).
		$this->repo->shouldReceive( 'codeRetired' )->andReturn( false )->byDefault();
		$this->service = new UrlShortenerService( $this->repo );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// create_short_url()
	// ==================================================================

	public function test_create_short_url_success(): void {
		$this->repo->shouldReceive( 'codeExists' )->andReturn( false );
		$this->repo->shouldReceive( 'insert' )->once()->andReturn( 42 );
		$this->repo->shouldReceive( 'findById' )->with( 42 )->once()->andReturn( [
			'id'         => 42,
			'short_code' => 'abc123',
			'target_url' => 'https://example.com/page',
			'status'     => 'active',
		] );

		$result = $this->service->create_short_url( 'https://example.com/page', 'Test Link' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 42, $result['data']['id'] );
	}

	public function test_create_short_url_empty_url_returns_error(): void {
		Functions\when( 'esc_url_raw' )->justReturn( '' );

		$result = $this->service->create_short_url( '' );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_create_short_url_returns_existing_for_post_id(): void {
		$existing = [
			'id'         => 10,
			'short_code' => 'exists',
			'post_id'    => 5,
			'status'     => 'active',
		];
		$this->repo->shouldReceive( 'findByPostId' )->with( 5 )->once()->andReturn( $existing );

		$result = $this->service->create_short_url( 'https://example.com', '', 5 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 10, $result['data']['id'] );
	}

	public function test_create_short_url_insert_failure_returns_error(): void {
		$this->repo->shouldReceive( 'codeExists' )->andReturn( false );
		$this->repo->shouldReceive( 'insert' )->once()->andReturn( false );

		$result = $this->service->create_short_url( 'https://example.com/page' );

		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_create_short_url_draws_again_when_the_key_refuses_the_code(): void {
		// Another request took the drawn code between the check and the insert.
		$in_table = array();
		$this->repo->shouldReceive( 'codeExists' )->andReturnUsing(
			static function ( $code ) use ( &$in_table ) {
				return in_array( $code, $in_table, true );
			}
		);
		$tried = array();
		$this->repo->shouldReceive( 'insert' )->twice()->andReturnUsing(
			static function ( $data ) use ( &$tried, &$in_table ) {
				$tried[] = $data['short_code'];
				if ( 1 === count( $tried ) ) {
					$in_table[] = $data['short_code']; // The key refused it: it is taken now.
					return false;
				}
				return 7;
			}
		);
		$this->repo->shouldReceive( 'findById' )->with( 7 )->once()->andReturn( array( 'id' => 7 ) );

		$result = $this->service->create_short_url( 'https://example.com/page' );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 2, $tried, 'A refused insert under a taken code draws a second code.' );
		$this->assertNotSame( $tried[0], $tried[1] );
	}

	public function test_create_short_url_does_not_draw_again_for_another_failure(): void {
		$this->repo->shouldReceive( 'codeExists' )->andReturn( false );
		$this->repo->shouldReceive( 'insert' )->once()->andReturn( false );

		$result = $this->service->create_short_url( 'https://example.com/page' );

		$this->assertFalse( $result['success'], 'A refusal that is not about the code fails at once.' );
	}

	// ==================================================================
	// generate_unique_code()
	// ==================================================================

	public function test_generate_unique_code_returns_correct_length(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_code_length' => 8 ] );
		$this->repo->shouldReceive( 'codeExists' )->andReturn( false );

		$code = $this->service->generate_unique_code();

		$this->assertSame( 8, strlen( $code ) );
	}

	public function test_generate_unique_code_draws_from_both_cases_and_digits(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		$this->repo->shouldReceive( 'codeExists' )->andReturn( false );

		// The column is binary, so case counts and both are drawn (#2337).
		$drawn = '';
		for ( $i = 0; $i < 50; $i++ ) {
			$drawn .= $this->service->generate_unique_code( 10 );
		}

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{500}$/', $drawn );
		$this->assertMatchesRegularExpression( '/[A-Z]/', $drawn );
		$this->assertMatchesRegularExpression( '/[a-z]/', $drawn );
	}

	public function test_generate_unique_code_skips_a_retired_code(): void {
		$seen = array();
		$this->repo->shouldReceive( 'codeExists' )->andReturn( false );
		$this->repo->shouldReceive( 'codeRetired' )->andReturnUsing(
			static function ( $code ) use ( &$seen ) {
				$seen[] = $code;
				return 1 === count( $seen ); // The first draw was retired.
			}
		);

		$code = $this->service->generate_unique_code( 6 );

		$this->assertCount( 2, $seen );
		$this->assertSame( $seen[1], $code, 'The code returned is the one that was not retired.' );
	}

	public function test_generate_unique_code_never_exceeds_the_column(): void {
		$lengths = array();
		$this->repo->shouldReceive( 'codeExists' )->andReturnUsing(
			static function ( $code ) use ( &$lengths ) {
				$lengths[ strlen( $code ) ] = true;
				return true; // Every code is taken.
			}
		);

		$this->assertSame( '', $this->service->generate_unique_code( 9 ), 'No code found up to the column is an empty answer.' );
		$this->assertSame( array( 9, 10 ), array_keys( $lengths ), 'It grows to the column width and stops there.' );
	}

	public function test_generate_unique_code_retries_on_collision(): void {
		$call_count = 0;
		$this->repo->shouldReceive( 'codeExists' )->andReturnUsing( function () use ( &$call_count ) {
			$call_count++;
			// First call collides, second succeeds
			return $call_count <= 1;
		} );

		$code = $this->service->generate_unique_code( 6 );

		$this->assertSame( 6, strlen( $code ) );
		$this->assertSame( 2, $call_count );
	}

	public function test_generate_unique_code_increases_length_after_max_attempts(): void {
		$attempt = 0;
		$this->repo->shouldReceive( 'codeExists' )->andReturnUsing( function () use ( &$attempt ) {
			$attempt++;
			// First 10 attempts collide (max_attempts), then succeed with length+1
			return $attempt <= 10;
		} );

		$code = $this->service->generate_unique_code( 4 );

		// Should be 5 chars (4 + 1 fallback)
		$this->assertSame( 5, strlen( $code ) );
	}

	public function test_generate_unique_code_custom_length(): void {
		$this->repo->shouldReceive( 'codeExists' )->andReturn( false );

		$code = $this->service->generate_unique_code( 10 );

		$this->assertSame( 10, strlen( $code ) );
	}

	// ==================================================================
	// get_short_url()
	// ==================================================================

	public function test_get_short_url_builds_correct_url(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_prefix' => 'go' ] );

		$url = $this->service->get_short_url( 'abc123' );

		$this->assertSame( 'https://example.com/go/abc123', $url );
	}

	public function test_get_short_url_custom_prefix(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_prefix' => 'link' ] );

		$url = $this->service->get_short_url( 'xyz789' );

		$this->assertSame( 'https://example.com/link/xyz789', $url );
	}

	// ==================================================================
	// get_prefix()
	// ==================================================================

	public function test_get_prefix_default_is_go(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$this->assertSame( 'go', $this->service->get_prefix() );
	}

	public function test_get_prefix_from_settings(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_prefix' => 'link' ] );

		$this->assertSame( 'link', $this->service->get_prefix() );
	}

	public function test_get_prefix_sanitizes_value(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_prefix' => 'My Link!' ] );

		$prefix = $this->service->get_prefix();

		// sanitize_title strips spaces and special chars
		$this->assertMatchesRegularExpression( '/^[a-z0-9\-]+$/', $prefix );
	}

	// ==================================================================
	// get_code_length()
	// ==================================================================

	public function test_get_code_length_default_is_6(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$this->assertSame( 6, $this->service->get_code_length() );
	}

	public function test_get_code_length_clamped_to_min_4(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_code_length' => 2 ] );

		$this->assertSame( 4, $this->service->get_code_length() );
	}

	public function test_get_code_length_clamped_to_max_10(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_code_length' => 99 ] );

		$this->assertSame( 10, $this->service->get_code_length() );
	}

	public function test_get_code_length_from_settings(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_code_length' => 8 ] );

		$this->assertSame( 8, $this->service->get_code_length() );
	}

	// ==================================================================
	// get_redirect_type()
	// ==================================================================

	public function test_get_redirect_type_default_is_302(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$this->assertSame( 302, $this->service->get_redirect_type() );
	}

	public function test_get_redirect_type_accepts_301(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_redirect_type' => 301 ] );

		$this->assertSame( 301, $this->service->get_redirect_type() );
	}

	public function test_get_redirect_type_accepts_307(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_redirect_type' => 307 ] );

		$this->assertSame( 307, $this->service->get_redirect_type() );
	}

	public function test_get_redirect_type_invalid_falls_back_to_302(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_redirect_type' => 404 ] );

		$this->assertSame( 302, $this->service->get_redirect_type() );
	}

	// ==================================================================
	// is_enabled()
	// ==================================================================

	public function test_is_enabled_true_by_default(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$this->assertTrue( $this->service->is_enabled() );
	}

	public function test_is_enabled_returns_true_when_setting_is_1(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_enabled' => 1 ] );

		$this->assertTrue( $this->service->is_enabled() );
	}

	public function test_is_enabled_returns_true_when_setting_is_boolean_true(): void {
		// Regression: the Modules-tab / autosave path persists this slot as a
		// real bool. A strict `1 === true` check wrongly disabled the module.
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_enabled' => true ] );

		$this->assertTrue( $this->service->is_enabled() );
	}

	public function test_is_enabled_returns_false_when_disabled(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_enabled' => 0 ] );

		$this->assertFalse( $this->service->is_enabled() );
	}

	public function test_is_enabled_returns_false_when_boolean_false(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_enabled' => false ] );

		$this->assertFalse( $this->service->is_enabled() );
	}

	// ==================================================================
	// is_auto_create_enabled()
	// ==================================================================

	public function test_is_auto_create_enabled_true_by_default(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$this->assertTrue( $this->service->is_auto_create_enabled() );
	}

	public function test_is_auto_create_enabled_returns_false_when_disabled(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_auto_create' => 0 ] );

		$this->assertFalse( $this->service->is_auto_create_enabled() );
	}

	// ==================================================================
	// get_enabled_post_types()
	// ==================================================================

	public function test_get_enabled_post_types_default(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$this->assertSame( [ 'post', 'page' ], $this->service->get_enabled_post_types() );
	}

	public function test_get_enabled_post_types_from_array(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_post_types' => [ 'post', 'product' ] ] );

		$this->assertSame( [ 'post', 'product' ], $this->service->get_enabled_post_types() );
	}

	public function test_get_enabled_post_types_from_csv_string(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_post_types' => 'post, page, product' ] );

		$result = $this->service->get_enabled_post_types();

		$this->assertContains( 'post', $result );
		$this->assertContains( 'page', $result );
		$this->assertContains( 'product', $result );
	}

	public function test_get_enabled_post_types_empty_falls_back_to_default(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_post_types' => [] ] );

		$this->assertSame( [ 'post', 'page' ], $this->service->get_enabled_post_types() );
	}

	// ==================================================================
	// get_exposed_post_types()
	// ==================================================================

	public function test_get_exposed_post_types_defaults_to_empty(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		// No post/page default — expose is opt-in.
		$this->assertSame( [], $this->service->get_exposed_post_types() );
	}

	public function test_get_exposed_post_types_from_array(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_expose_post_types' => [ 'post', 'page' ] ] );

		$this->assertSame( [ 'post', 'page' ], $this->service->get_exposed_post_types() );
	}

	public function test_get_exposed_post_types_from_csv_string(): void {
		Functions\when( 'get_option' )->justReturn( [ 'url_shortener_expose_post_types' => 'post, page' ] );

		$result = $this->service->get_exposed_post_types();

		$this->assertContains( 'post', $result );
		$this->assertContains( 'page', $result );
	}

	// ==================================================================
	// resolve_target()
	// ==================================================================

	public function test_resolve_target_manual_uses_stored_target(): void {
		// No post_id → stored target_url, no get_permalink call.
		$this->assertSame(
			'https://example.com/page',
			UrlShortenerService::resolve_target( array( 'target_url' => 'https://example.com/page' ) )
		);
	}

	public function test_resolve_target_post_linked_uses_current_permalink(): void {
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/new-slug' );

		$this->assertSame(
			'https://example.com/new-slug',
			UrlShortenerService::resolve_target(
				array( 'post_id' => 5, 'target_url' => 'https://example.com/old-slug' )
			)
		);
	}

	public function test_resolve_target_post_linked_falls_back_when_post_gone(): void {
		Functions\when( 'get_permalink' )->justReturn( false );

		$this->assertSame(
			'https://example.com/old',
			UrlShortenerService::resolve_target(
				array( 'post_id' => 9, 'target_url' => 'https://example.com/old' )
			)
		);
	}

	// ==================================================================
	// update_short_url()
	// ==================================================================

	public function test_update_short_url_persists_target_and_title(): void {
		$this->repo->shouldReceive( 'update' )->once()->with(
			7,
			Mockery::on(
				function ( $data ) {
					return 'https://x/y' === $data['target_url']
						&& 'New title' === $data['title']
						&& isset( $data['updated_at'] );
				}
			)
		)->andReturn( true );

		$this->assertTrue( $this->service->update_short_url( 7, 'https://x/y', 'New title' ) );
	}

	// ==================================================================
	// delete_short_url()
	// ==================================================================

	public function test_delete_short_url_retires_the_code(): void {
		$row = array( 'id' => 1, 'short_code' => 'abc123' );
		$this->repo->shouldReceive( 'findById' )->with( 1 )->once()->andReturn( $row );
		$this->repo->shouldReceive( 'retireAndDelete' )->with( $row, 'deleted' )->once()->andReturn( true );

		$this->assertTrue( $this->service->delete_short_url( 1 ) );
	}

	public function test_delete_short_url_failure(): void {
		$row = array( 'id' => 2, 'short_code' => 'abc124' );
		$this->repo->shouldReceive( 'findById' )->with( 2 )->once()->andReturn( $row );
		$this->repo->shouldReceive( 'retireAndDelete' )->once()->andReturn( false );

		$this->assertFalse( $this->service->delete_short_url( 2 ) );
	}

	public function test_delete_short_url_of_a_missing_link_is_false(): void {
		$this->repo->shouldReceive( 'findById' )->with( 99 )->once()->andReturn( null );
		$this->repo->shouldNotReceive( 'retireAndDelete' );

		$this->assertFalse( $this->service->delete_short_url( 99 ) );
	}

	// ==================================================================
	// empty_trash() (#2337)
	// ==================================================================

	public function test_empty_trash_works_through_every_batch_and_counts_what_went(): void {
		$full = array_map( static fn( $i ) => array( 'id' => $i, 'short_code' => 'c' . $i ), range( 1, 200 ) );
		$tail = array( array( 'id' => 201, 'short_code' => 'c201' ) );
		$this->repo->shouldReceive( 'findTrashedBatch' )->with( 200 )->twice()->andReturn( $full, $tail );
		$this->repo->shouldReceive( 'retireAndDelete' )->times( 201 )->andReturn( true );

		$this->assertSame( 201, $this->service->empty_trash() );
	}

	public function test_empty_trash_stops_when_a_batch_deletes_nothing(): void {
		$full = array_map( static fn( $i ) => array( 'id' => $i, 'short_code' => 'c' . $i ), range( 1, 200 ) );
		// The same undeletable rows come back each time; one pass, then stop.
		$this->repo->shouldReceive( 'findTrashedBatch' )->once()->andReturn( $full );
		$this->repo->shouldReceive( 'retireAndDelete' )->times( 200 )->andReturn( false );

		$this->assertSame( 0, $this->service->empty_trash() );
	}

	// ==================================================================
	// trash_short_url()
	// ==================================================================

	public function test_trash_short_url_success(): void {
		$this->repo->shouldReceive( 'update' )
			->with( 1, Mockery::on( function ( $data ) {
				return $data['status'] === 'trashed' && isset( $data['updated_at'] );
			} ) )
			->once()
			->andReturn( 1 );

		$this->assertTrue( $this->service->trash_short_url( 1 ) );
	}

	// ==================================================================
	// restore_short_url()
	// ==================================================================

	public function test_restore_short_url_sets_disabled(): void {
		$this->repo->shouldReceive( 'update' )
			->with( 1, Mockery::on( function ( $data ) {
				return $data['status'] === 'disabled' && isset( $data['updated_at'] );
			} ) )
			->once()
			->andReturn( 1 );

		$this->assertTrue( $this->service->restore_short_url( 1 ) );
	}

	// ==================================================================
	// set_active()
	// ==================================================================

	public function test_set_active_switches_a_live_link_off(): void {
		$this->repo->shouldReceive( 'findById' )->with( 1 )->once()->andReturn( [
			'id' => 1, 'status' => 'active',
		] );
		$this->repo->shouldReceive( 'update' )
			->with( 1, Mockery::on( function ( $data ) {
				return $data['status'] === 'disabled';
			} ) )
			->once()
			->andReturn( 1 );

		$this->assertTrue( $this->service->set_active( 1, false ) );
	}

	public function test_set_active_switches_a_live_link_on(): void {
		$this->repo->shouldReceive( 'findById' )->with( 2 )->once()->andReturn( [
			'id' => 2, 'status' => 'disabled',
		] );
		$this->repo->shouldReceive( 'update' )
			->with( 2, Mockery::on( function ( $data ) {
				return $data['status'] === 'active';
			} ) )
			->once()
			->andReturn( 1 );

		$this->assertTrue( $this->service->set_active( 2, true ) );
	}

	/**
	 * @dataProvider provide_download_paths
	 */
	public function test_download_extension_reads_the_path_only( string $url, string $expected ): void {
		Functions\when( 'wp_parse_url' )->alias( static fn( $u, $c = -1 ) => parse_url( $u, $c ) );

		$this->assertSame( $expected, UrlShortenerService::download_extension( $url ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_download_paths(): array {
		return array(
			'pdf'                 => array( 'https://example.org/edital.pdf', 'pdf' ),
			'upper case, query'   => array( 'https://example.org/a/Plan.XLSX?dl=1#top', 'xlsx' ),
			'a page'              => array( 'https://example.org/inscricoes/', '' ),
			'html'                => array( 'https://example.org/page.html', '' ),
			'extension in query'  => array( 'https://example.org/get?file=a.pdf', '' ),
			'bare host'           => array( 'https://example.org', '' ),
		);
	}

	public function test_the_shared_destination_list_and_its_warning(): void {
		Functions\when( 'wp_parse_url' )->alias( static fn( $u, $c = -1 ) => parse_url( $u, $c ) );
		Functions\when( 'esc_url_raw' )->alias( static fn( $u, $p = null ) => is_array( $p ) && ! in_array( strtolower( (string) parse_url( $u, PHP_URL_SCHEME ) ), $p, true ) ? '' : $u );

		foreach ( array( 'https://a.test/', 'ftp://a.test/f', 'sftp://a.test/', 'ssh://u@a.test', 'mailto:a@a.test', 'tel:+5511999999999', 'sms:+5511999999999' ) as $url ) {
			$this->assertTrue( UrlShortenerService::is_allowed_destination( $url ), $url );
		}
		foreach ( array( 'javascript:alert(1)', 'data:text/html,x', 'file:///etc/passwd', 'irc://a.test/', 'a.test/no-scheme' ) as $url ) {
			$this->assertFalse( UrlShortenerService::is_allowed_destination( $url ), $url );
		}

		$this->assertSame( '', UrlShortenerService::scheme_warning( 'https://a.test/' ) );
		$this->assertSame( 'tel', UrlShortenerService::scheme_warning( 'TEL:+5511' ) );
		$this->assertSame( '', UrlShortenerService::scheme_warning( 'javascript:x' ), 'A refused scheme is not a warning.' );
	}

	// ==================================================================
	// reactivate_short_url() / regenerate_code() (#2337)
	// ==================================================================

	public function test_reactivate_sets_the_link_active(): void {
		$this->repo->shouldReceive( 'update' )
			->with( 3, Mockery::on( static fn( $data ) => 'active' === $data['status'] ) )
			->once()
			->andReturn( 1 );

		$this->assertTrue( $this->service->reactivate_short_url( 3 ) );
	}

	public function test_regenerate_swaps_the_code_on_the_same_row(): void {
		$link = array( 'id' => 9, 'short_code' => 'old123' );
		$this->repo->shouldReceive( 'codeExists' )->andReturn( false );
		$this->repo->shouldReceive( 'swapCode' )->once()->with( $link, Mockery::type( 'string' ) )->andReturn( true );
		$this->repo->shouldReceive( 'findById' )->with( 9 )->once()->andReturn( array( 'id' => 9, 'short_code' => 'new123' ) );

		$result = $this->service->regenerate_code( $link );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 9, $result['data']['id'] );
	}

	public function test_regenerate_draws_again_when_the_new_code_was_taken(): void {
		$in_table = array();
		$this->repo->shouldReceive( 'codeExists' )->andReturnUsing(
			static function ( $c ) use ( &$in_table ) {
				return in_array( $c, $in_table, true );
			}
		);
		$tried = array();
		$this->repo->shouldReceive( 'swapCode' )->twice()->andReturnUsing(
			static function ( $row, $code ) use ( &$tried, &$in_table ) {
				$tried[] = $code;
				if ( 1 === count( $tried ) ) {
					$in_table[] = $code;
					return false;
				}
				return true;
			}
		);
		$this->repo->shouldReceive( 'findById' )->andReturn( array( 'id' => 9 ) );

		$this->assertTrue( $this->service->regenerate_code( array( 'id' => 9, 'short_code' => 'old123' ) )['success'] );
		$this->assertCount( 2, $tried );
	}

	public function test_regenerate_failure_leaves_the_link_unchanged(): void {
		$this->repo->shouldReceive( 'codeExists' )->andReturn( false );
		$this->repo->shouldReceive( 'swapCode' )->once()->andReturn( false );
		$this->repo->shouldNotReceive( 'findById' );

		$result = $this->service->regenerate_code( array( 'id' => 9, 'short_code' => 'old123' ) );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'unchanged', $result['error'] );
	}

	public function test_set_active_to_the_state_it_already_has_is_success(): void {
		$this->repo->shouldReceive( 'findById' )->with( 4 )->once()->andReturn( [
			'id' => 4, 'status' => 'active',
		] );
		// $wpdb->update() answers 0 when no row changed (#2337).
		$this->repo->shouldReceive( 'update' )->once()->andReturn( 0 );

		$this->assertTrue( $this->service->set_active( 4, true ) );
	}

	public function test_set_active_reports_a_database_error(): void {
		$this->repo->shouldReceive( 'findById' )->with( 5 )->once()->andReturn( [
			'id' => 5, 'status' => 'active',
		] );
		$this->repo->shouldReceive( 'update' )->once()->andReturn( false );

		$this->assertFalse( $this->service->set_active( 5, false ) );
	}

	public function test_set_active_leaves_a_trashed_link_in_the_trash(): void {
		$this->repo->shouldReceive( 'findById' )->with( 3 )->once()->andReturn( [
			'id' => 3, 'status' => 'trashed',
		] );
		$this->repo->shouldNotReceive( 'update' );

		$this->assertFalse( $this->service->set_active( 3, true ) );
	}

	public function test_set_active_not_found_returns_false(): void {
		$this->repo->shouldReceive( 'findById' )->with( 999 )->once()->andReturn( null );

		$this->assertFalse( $this->service->set_active( 999, true ) );
	}

	// ==================================================================
	// get_stats()
	// ==================================================================

	public function test_get_stats_delegates_to_repository(): void {
		$stats = [
			'total_links'   => 10,
			'active_links'  => 7,
			'total_clicks'  => 500,
			'trashed_links' => 2,
		];
		$this->repo->shouldReceive( 'getStats' )->once()->andReturn( $stats );

		$this->assertSame( $stats, $this->service->get_stats() );
	}

	// ==================================================================
	// get_repository()
	// ==================================================================

	public function test_get_repository_returns_injected_instance(): void {
		$this->assertSame( $this->repo, $this->service->get_repository() );
	}

	// ==================================================================
	// get_example_short_url() / code_from_short_url() (#1586)
	// ==================================================================

	public function test_the_example_short_url_is_fixed_and_has_the_configured_length(): void {
		Functions\when( 'get_option' )->justReturn( array( 'url_shortener_prefix' => 'go', 'url_shortener_code_length' => 7 ) );

		$this->assertSame( 'https://example.com/go/Ab3dEf9', $this->service->get_example_short_url() );
		$this->assertSame( $this->service->get_example_short_url(), $this->service->get_example_short_url(), 'Always the same.' );
	}

	public function test_an_own_short_url_yields_its_code(): void {
		Functions\when( 'get_option' )->justReturn( array( 'url_shortener_prefix' => 'go' ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url ) => parse_url( $url ) );
		$this->repo->shouldReceive( 'codeExists' )->with( 'abc123' )->andReturn( true );

		$this->assertSame( 'abc123', $this->service->code_from_short_url( 'https://example.com/go/abc123' ) );
		$this->assertSame( 'abc123', $this->service->code_from_short_url( 'http://WWW.Example.com/go/abc123/?utm=x' ), 'Scheme, case, www and a query do not hide it.' );
	}

	public function test_other_addresses_are_not_short_urls(): void {
		Functions\when( 'get_option' )->justReturn( array( 'url_shortener_prefix' => 'go' ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url ) => parse_url( $url ) );
		$this->repo->shouldReceive( 'codeExists' )->with( 'gone99' )->andReturn( false );

		$this->assertSame( '', $this->service->code_from_short_url( 'https://example.com/contact' ), 'Another page of the site can be shortened.' );
		$this->assertSame( '', $this->service->code_from_short_url( 'https://other.test/go/abc123' ), 'Another host is not ours.' );
		$this->assertSame( '', $this->service->code_from_short_url( 'https://example.com/go/a/b' ), 'A deeper path is not a code.' );
		$this->assertSame( '', $this->service->code_from_short_url( 'https://example.com/go/' ) );
		$this->assertSame( '', $this->service->code_from_short_url( 'https://example.com/go/gone99' ), 'A code that does not exist is not a short URL.' );
		$this->assertSame( '', $this->service->code_from_short_url( 'not a url' ) );
	}
}
