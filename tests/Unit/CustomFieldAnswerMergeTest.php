<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\CustomFieldAnswerMerge;
use FreeFormCertificate\Reregistration\CustomFieldReader;

/**
 * Tests for CustomFieldAnswerMerge: answers stored under an audience's former
 * copy of a field move onto the field every audience inherits (#2502).
 *
 * The map folds copy 11 (audience 3, depth 0), copy 21 (audience 7, depth 1)
 * and copy 31 (audience 9, depth 0) into field 1, key `nome`.
 *
 * @covers \FreeFormCertificate\Reregistration\CustomFieldAnswerMerge
 */
class CustomFieldAnswerMergeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> Fields of the user's latest submission. */
	private array $latest = array();

	/** @var list<int> Audience ids the user belongs to. */
	private array $memberships = array();

	/** @var array<string, mixed> Options the double holds. */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		CustomFieldAnswerMerge::reset();

		$this->latest      = array();
		$this->memberships = array();
		$this->options     = array(
			CustomFieldAnswerMerge::MAP_OPTION => array(
				11 => array( 1, 3, 0, 'nome' ),
				21 => array( 1, 7, 1, 'nome' ),
				31 => array( 1, 9, 0, 'nome' ),
			),
		);

		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) {
				unset( $this->options[ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql ) => (string) $sql );
		// ReregistrationSubmissionReader::get_latest_answers_for_user().
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			fn() => array() === $this->latest ? array() : array( json_encode( array( 'fields' => $this->latest ) ) )
		);
		// AudienceReader::get_user_audiences().
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			fn() => array_map( static fn( $id ) => (object) array( 'id' => $id ), $this->memberships )
		);
	}

	protected function tearDown(): void {
		CustomFieldAnswerMerge::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_without_a_map_the_answers_are_left_alone(): void {
		$this->options = array();
		$data          = array( 'field_11' => 'Ana' );

		$this->assertSame( array( 'data' => $data, 'changed' => false ), CustomFieldAnswerMerge::converge( 5, $data ) );
	}

	public function test_a_single_copy_moves_onto_the_field_and_its_key_goes(): void {
		$result = CustomFieldAnswerMerge::converge( 5, array( 'field_21' => 'Ana', 'field_99' => 'kept' ) );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( array( 'field_99' => 'kept', 'field_1' => 'Ana' ), $result['data'] );
	}

	public function test_an_answer_given_on_the_field_since_the_fold_wins(): void {
		$result = CustomFieldAnswerMerge::converge( 5, array( 'field_1' => 'New', 'field_11' => 'Old' ) );

		$this->assertSame( array( 'field_1' => 'New' ), $result['data'] );
	}

	public function test_the_value_of_the_latest_submission_wins_first(): void {
		$this->latest      = array( 'nome' => 'From the form' );
		$this->memberships = array( 7 );

		$result = CustomFieldAnswerMerge::converge( 5, array( 'field_11' => 'From the form', 'field_21' => 'Deeper' ) );

		$this->assertSame( 'From the form', $result['data']['field_1'] );
	}

	public function test_then_the_copy_of_the_deepest_audience_the_person_belongs_to(): void {
		$this->latest      = array( 'nome' => 'Matches neither' );
		$this->memberships = array( 3, 7 );

		$result = CustomFieldAnswerMerge::converge( 5, array( 'field_11' => 'Parent', 'field_21' => 'Child', 'field_31' => 'Other' ) );

		$this->assertSame( 'Child', $result['data']['field_1'] );
	}

	public function test_then_the_copy_with_the_lowest_id(): void {
		$result = CustomFieldAnswerMerge::converge( 5, array( 'field_31' => 'Third', 'field_11' => 'First' ) );

		$this->assertSame( array( 'field_1' => 'First' ), $result['data'] );
	}

	public function test_old_ids_of_lists_every_copy_folded_into_a_field(): void {
		$this->assertSame( array( 11, 21, 31 ), CustomFieldAnswerMerge::old_ids_of( 1 ) );
		$this->assertSame( array(), CustomFieldAnswerMerge::old_ids_of( 2 ) );
	}

	public function test_record_adds_to_the_map_and_clear_forgets_it(): void {
		CustomFieldAnswerMerge::record( array( 41 => array( 2, 4, 0, 'rf' ) ) );
		CustomFieldAnswerMerge::reset();
		$this->assertSame( array( 2, 4, 0, 'rf' ), CustomFieldAnswerMerge::map()[41] );
		$this->assertCount( 4, CustomFieldAnswerMerge::map() );

		CustomFieldAnswerMerge::clear();
		$this->assertSame( array(), CustomFieldAnswerMerge::map() );
		$this->assertArrayNotHasKey( CustomFieldAnswerMerge::MAP_OPTION, $this->options );
	}

	/**
	 * A user's answers read through the fold before the migration writes it,
	 * so no answer is out of sight in between.
	 */
	public function test_reading_a_users_answers_applies_the_fold(): void {
		Functions\when( 'get_user_meta' )->justReturn( array( 'field_21' => 'Ana' ) );

		$this->assertSame( array( 'field_1' => 'Ana' ), CustomFieldReader::get_user_data( 5 ) );
	}
}
