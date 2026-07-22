<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CptCapPolicy;

/**
 * Tests for the #739 §3.2 read-only viewer write-gate.
 *
 * @covers \FreeFormCertificate\Admin\CptCapPolicy
 */
class CptCapPolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Admin\\CptCapPolicy' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_init_registers_map_meta_cap_filter(): void {
		$hooks = array();
		Functions\when( 'add_filter' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
				return true;
			}
		);

		CptCapPolicy::init();

		$this->assertContains( 'map_meta_cap', $hooks );
	}

	/**
	 * Build a WP_Post stub with the given post_type.
	 *
	 * @param string $type Post type.
	 * @return \WP_Post
	 */
	private function fake_post( string $type ): \WP_Post {
		$post            = new \WP_Post();
		$post->post_type = $type;
		return $post;
	}

	public function test_edit_post_on_form_is_forced_to_manage_forms(): void {
		Functions\when( 'get_post' )->justReturn( $this->fake_post( 'ffc_form' ) );

		$out = CptCapPolicy::gate_cpt_writes( array( 'ffc_view_forms' ), 'edit_post', 7, array( 42 ) );

		$this->assertSame( array( 'ffc_manage_forms' ), $out );
	}

	public function test_delete_post_on_calendar_is_forced_to_manage_calendars(): void {
		Functions\when( 'get_post' )->justReturn( $this->fake_post( 'ffc_self_scheduling' ) );

		$out = CptCapPolicy::gate_cpt_writes( array( 'ffc_view_calendars' ), 'delete_post', 7, array( 42 ) );

		$this->assertSame( array( 'ffc_manage_calendars' ), $out );
	}

	public function test_non_write_meta_cap_passes_through(): void {
		// read_post must NOT be re-gated — viewers are allowed to read.
		$out = CptCapPolicy::gate_cpt_writes( array( 'ffc_view_forms' ), 'read_post', 7, array( 42 ) );

		$this->assertSame( array( 'ffc_view_forms' ), $out );
	}

	public function test_write_on_unrelated_post_type_passes_through(): void {
		Functions\when( 'get_post' )->justReturn( $this->fake_post( 'page' ) );

		$in  = array( 'edit_others_posts' );
		$out = CptCapPolicy::gate_cpt_writes( $in, 'edit_post', 7, array( 42 ) );

		$this->assertSame( $in, $out );
	}

	public function test_missing_post_id_passes_through(): void {
		$in  = array( 'edit_others_posts' );
		$out = CptCapPolicy::gate_cpt_writes( $in, 'edit_post', 7, array() );

		$this->assertSame( $in, $out );
	}

	public function test_unknown_post_passes_through(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$in  = array( 'edit_others_posts' );
		$out = CptCapPolicy::gate_cpt_writes( $in, 'edit_post', 7, array( 999 ) );

		$this->assertSame( $in, $out );
	}
}
