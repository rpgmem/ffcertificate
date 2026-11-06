<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UrlShortener\UrlShortenerPostLifecycle;
use FreeFormCertificate\UrlShortener\UrlShortenerRepository;
use FreeFormCertificate\UrlShortener\UrlShortenerService;

/**
 * A post's short URL follows the post (#2337).
 *
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerPostLifecycle
 */
class UrlShortenerPostLifecycleTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var UrlShortenerService|Mockery\MockInterface */
	private $service;

	/** @var UrlShortenerRepository|Mockery\MockInterface */
	private $repo;

	private UrlShortenerPostLifecycle $lifecycle;

	/** @var array<int, array<string, mixed>> Post meta written, by post. */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->repo    = Mockery::mock( UrlShortenerRepository::class );
		$this->service = Mockery::mock( UrlShortenerService::class );
		$this->service->shouldReceive( 'get_repository' )->andReturn( $this->repo );
		$this->lifecycle = new UrlShortenerPostLifecycle( $this->service );

		$meta = &$this->meta;
		Functions\when( 'update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$meta ) {
				$meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) use ( &$meta ) {
				return $meta[ $id ][ $key ] ?? '';
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			static function ( $id, $key ) use ( &$meta ) {
				unset( $meta[ $id ][ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_init_registers_the_three_post_hooks(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$hooks ) {
				$hooks[ $hook ] = $args;
			}
		);

		$this->lifecycle->init();

		$this->assertSame(
			array(
				'trashed_post'           => 1,
				'transition_post_status' => 3,
				'deleted_post'           => 1,
			),
			$hooks
		);
	}

	public function test_trashing_the_post_disables_its_live_link_and_remembers_it(): void {
		$this->repo->shouldReceive( 'findByPostId' )->with( 5 )->andReturn( array( 'id' => 50, 'status' => 'active' ) );
		$this->service->shouldReceive( 'set_active' )->with( 50, false )->once()->andReturn( true );

		$this->lifecycle->on_trashed( 5 );

		$this->assertSame( 1, $this->meta[5][ UrlShortenerPostLifecycle::PAUSED_META ] ?? null );
	}

	public function test_trashing_leaves_a_link_switched_off_by_hand_alone(): void {
		$this->repo->shouldReceive( 'findByPostId' )->with( 5 )->andReturn( array( 'id' => 50, 'status' => 'disabled' ) );
		$this->service->shouldNotReceive( 'set_active' );

		$this->lifecycle->on_trashed( 5 );

		$this->assertArrayNotHasKey( 5, $this->meta, 'Nothing to bring back later.' );
	}

	public function test_publishing_again_brings_back_the_link_its_trashing_paused(): void {
		$this->meta[5][ UrlShortenerPostLifecycle::PAUSED_META ] = 1;
		$this->repo->shouldReceive( 'findByPostId' )->with( 5 )->andReturn( array( 'id' => 50, 'status' => 'disabled' ) );
		$this->service->shouldReceive( 'set_active' )->with( 50, true )->once()->andReturn( true );

		$this->lifecycle->on_transition( 'publish', 'draft', $this->post( 5 ) );

		$this->assertArrayNotHasKey( UrlShortenerPostLifecycle::PAUSED_META, $this->meta[5] );
	}

	public function test_a_restore_to_draft_keeps_the_link_off(): void {
		$this->meta[5][ UrlShortenerPostLifecycle::PAUSED_META ] = 1;
		$this->service->shouldNotReceive( 'set_active' );

		$this->lifecycle->on_transition( 'draft', 'trash', $this->post( 5 ) );

		$this->assertSame( 1, $this->meta[5][ UrlShortenerPostLifecycle::PAUSED_META ], 'Still waiting for the publish.' );
	}

	public function test_publishing_a_post_whose_link_was_disabled_by_hand_does_not_switch_it_on(): void {
		$this->repo->shouldNotReceive( 'findByPostId' );
		$this->service->shouldNotReceive( 'set_active' );

		$this->lifecycle->on_transition( 'publish', 'draft', $this->post( 5 ) );
	}

	public function test_deleting_the_post_deletes_its_link(): void {
		$this->repo->shouldReceive( 'findByPostId' )->with( 5 )->andReturn( array( 'id' => 50, 'status' => 'trashed' ) );
		$this->service->shouldReceive( 'delete_short_url' )->with( 50 )->once()->andReturn( true );

		$this->lifecycle->on_deleted( 5 );
	}

	public function test_deleting_a_post_without_a_link_does_nothing(): void {
		$this->repo->shouldReceive( 'findByPostId' )->with( 6 )->andReturn( null );
		$this->service->shouldNotReceive( 'delete_short_url' );

		$this->lifecycle->on_deleted( 6 );
	}

	/**
	 * A post double.
	 *
	 * @param int $id Post ID.
	 * @return \WP_Post|Mockery\MockInterface
	 */
	private function post( int $id ) {
		$post     = Mockery::mock( \WP_Post::class );
		$post->ID = $id;
		return $post;
	}
}
