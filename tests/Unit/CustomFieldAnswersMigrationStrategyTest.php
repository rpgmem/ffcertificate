<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\Strategies\CustomFieldAnswersMigrationStrategy;
use FreeFormCertificate\Reregistration\CustomFieldAnswerMerge;

/**
 * Tests for the card that writes the answers folded copies held onto the
 * inherited field (#2502), over an in-memory `wp_usermeta`.
 *
 * @covers \FreeFormCertificate\Migrations\Strategies\CustomFieldAnswersMigrationStrategy
 */
class CustomFieldAnswersMigrationStrategyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array{user_id: int, value: array<string, mixed>}> Meta rows by umeta_id. */
	private array $meta = array();

	/** @var array<string, mixed> Options. */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		CustomFieldAnswerMerge::reset();

		$this->options = array( CustomFieldAnswerMerge::MAP_OPTION => array( 11 => array( 1, 3, 0, 'nome' ) ) );
		$this->meta    = array(
			5 => array(
				'user_id' => 50,
				'value'   => array( 'field_11' => 'Ana' ),
			),
			6 => array(
				'user_id' => 60,
				'value'   => array( 'field_2' => 'untouched' ),
			),
			9 => array(
				'user_id' => 90,
				'value'   => array( 'field_11' => 'Bia' ),
			),
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'maybe_unserialize' )->alias( static fn( $v ) => is_string( $v ) ? unserialize( $v ) : $v );
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
		Functions\when( 'update_user_meta' )->alias(
			function ( $user_id, $key, $value ) {
				foreach ( $this->meta as $id => $row ) {
					if ( $row['user_id'] === $user_id ) {
						$this->meta[ $id ]['value'] = $value;
					}
				}
				return true;
			}
		);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		global $wpdb;
		$wpdb           = Mockery::mock( 'wpdb' );
		$wpdb->prefix   = 'wp_';
		$wpdb->usermeta = 'wp_usermeta';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( ...$args ) => $args );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			fn( $q ) => (string) count( array_filter( array_keys( $this->meta ), static fn( $id ) => $id > $q[3] ) )
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $q ) {
				if ( 'wp_usermeta' !== ( $q[1] ?? '' ) ) {
					// AudienceReader::get_user_audiences(): nobody belongs anywhere.
					return array();
				}
				$rows = array();
				foreach ( $this->meta as $id => $row ) {
					if ( $id > $q[3] && count( $rows ) < $q[4] ) {
						$rows[] = array(
							'umeta_id'   => (string) $id,
							'user_id'    => (string) $row['user_id'],
							'meta_value' => serialize( $row['value'] ),
						);
					}
				}
				return $rows;
			}
		);
		// No submission to compare with.
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() );
	}

	protected function tearDown(): void {
		CustomFieldAnswerMerge::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_card_counts_the_users_with_answers_while_a_map_exists(): void {
		$status = ( new CustomFieldAnswersMigrationStrategy() )->calculate_status( 'custom_field_answers', array() );

		$this->assertSame( 3, $status['total'] );
		$this->assertSame( 3, $status['pending'] );
		$this->assertFalse( $status['is_complete'] );
	}

	public function test_batches_move_the_answers_count_what_changed_and_forget_the_map_at_the_end(): void {
		$strategy = new CustomFieldAnswersMigrationStrategy();

		$first = $strategy->execute( 'custom_field_answers', array( 'batch_size' => 2 ) );
		$this->assertSame( 1, $first['processed'], 'Two rows examined, one changed.' );
		$this->assertTrue( $first['has_more'] );
		$this->assertSame( array( 'field_1' => 'Ana' ), $this->meta[5]['value'] );
		$this->assertSame( array( 'field_2' => 'untouched' ), $this->meta[6]['value'] );
		$this->assertSame( 6, $this->options[ CustomFieldAnswersMigrationStrategy::CURSOR_OPTION ] );

		$second = $strategy->execute( 'custom_field_answers', array( 'batch_size' => 2 ) );
		$this->assertSame( 1, $second['processed'] );
		$this->assertFalse( $second['has_more'] );
		$this->assertSame( array( 'field_1' => 'Bia' ), $this->meta[9]['value'] );

		$this->assertArrayNotHasKey( CustomFieldAnswerMerge::MAP_OPTION, $this->options, 'Every user was passed: the map goes.' );
		$this->assertArrayNotHasKey( CustomFieldAnswersMigrationStrategy::CURSOR_OPTION, $this->options );
		$this->assertTrue( $strategy->calculate_status( 'custom_field_answers', array() )['is_complete'] );
	}

	public function test_without_a_map_there_is_nothing_to_do(): void {
		$this->options = array();
		$strategy      = new CustomFieldAnswersMigrationStrategy();

		$this->assertTrue( $strategy->calculate_status( 'custom_field_answers', array() )['is_complete'] );
		$result = $strategy->execute( 'custom_field_answers', array() );
		$this->assertSame( 0, $result['processed'] );
		$this->assertFalse( $result['has_more'] );
		$this->assertSame( array( 'field_11' => 'Ana' ), $this->meta[5]['value'] );
	}
}
