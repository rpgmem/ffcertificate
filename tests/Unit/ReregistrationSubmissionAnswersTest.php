<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationSubmissionAnswers;

/**
 * Tests for ReregistrationSubmissionAnswers: the read-only answer rows the
 * user dashboard draws for one submission.
 *
 * The readers are the real classes; the `$wpdb` double answers each table
 * with the rows a test sets.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationSubmissionAnswers
 */
class ReregistrationSubmissionAnswersTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<object> The campaign's field rows. */
	private array $fields = array();

	/** @var object|null The campaign row. */
	private $campaign;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'get_option' )->alias( static function ( $key, $default = false ) {
			return 'ffc_settings' === $key ? array( 'date_format' => 'd/m/Y' ) : $default;
		});
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts = null ) => gmdate( $format, $ts ?? time() ) );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );

		$this->campaign = (object) array(
			'id'     => 10,
			'title'  => 'Annual',
			'status' => 'active',
		);
		$this->fields   = array(
			$this->field( 'rf', 'text', 'rf', 1 ),
			$this->field( 'vinculo', 'text', 'number', 0 ),
			$this->field( 'data_nascimento', 'date', '', 0 ),
			$this->field( 'cep', 'text', 'cep', 0 ),
			$this->field( 'horario_trabalho', 'working_hours', '', 0 ),
			$this->field( 'acumulo_cargos', 'select', '', 0 ),
			$this->field( 'jornada_acumulo', 'select', '', 0 ),
			$this->field( 'acknowledgment', 'acknowledgment', '', 0 ),
		);

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static function ( $sql, ...$args ) {
			$flat = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
			return array(
				'sql'  => $sql,
				'args' => $flat,
			);
		});
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( function ( $q ) {
			$table = (string) $q['args'][0];
			if ( 'wp_ffc_reregistrations' === $table ) {
				return $this->campaign;
			}
			if ( 'wp_ffc_audiences' === $table ) {
				return (object) array(
					'id'        => 5,
					'name'      => 'Staff',
					'parent_id' => null,
				);
			}
			return null;
		});
		$wpdb->shouldReceive( 'get_col' )->andReturn( array( '5' ) );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( fn() => $this->fields );

		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	private function field( string $key, string $type, string $mask, int $sensitive ): object {
		return (object) array(
			'id'           => crc32( $key ),
			'audience_id'  => 5,
			'field_key'    => $key,
			'field_label'  => ucfirst( $key ),
			'field_type'   => $type,
			'field_mask'   => $mask,
			'is_sensitive' => $sensitive,
		);
	}

	private function submission( array $values ): object {
		return (object) array(
			'id'                => 3,
			'reregistration_id' => 10,
			'user_id'           => 7,
			'data'              => json_encode( array( 'fields' => $values ) ),
		);
	}

	private function values(): array {
		return array(
			'rf'               => '5181780',
			'vinculo'          => '2',
			'data_nascimento'  => '1985-10-12',
			'cep'              => '08615070',
			'horario_trabalho' => json_encode(
				array(
					array(
						'day'    => 1,
						'entry1' => '08:00',
						'exit1'  => '12:00',
						'entry2' => '13:00',
						'exit2'  => '17:00',
					),
				)
			),
			'acumulo_cargos'   => 'I do not hold',
			'jornada_acumulo'  => 'JB - 20h/a',
		);
	}

	private function by_key( array $answers ): array {
		return array_column( $answers['rows'], 'value', 'key' );
	}

	public function test_personal_data_is_masked_until_revealed(): void {
		$masked = ReregistrationSubmissionAnswers::build( $this->submission( $this->values() ), false );

		$this->assertTrue( $masked['has_masked'] );
		$rows = $this->by_key( $masked );
		$this->assertSame( '518.***-0', $rows['rf'] );
		$this->assertSame( '12/10/****', $rows['data_nascimento'], 'A birth date is personal by its key.' );
		$this->assertSame( '2', $rows['vinculo'], 'A value that is not personal is drawn as given.' );

		$clear = $this->by_key( ReregistrationSubmissionAnswers::build( $this->submission( $this->values() ), true ) );
		$this->assertSame( '518.178-0', $clear['rf'], 'Revealed, a document takes its usual shape.' );
		$this->assertSame( '12/10/1985', $clear['data_nascimento'] );
	}

	public function test_values_are_shaped_and_the_display_only_block_is_left_out(): void {
		$rows = $this->by_key( ReregistrationSubmissionAnswers::build( $this->submission( $this->values() ), false ) );

		$this->assertSame( '08615-070', $rows['cep'] );
		$this->assertSame( 'Mon 08:00–12:00, 13:00–17:00', $rows['horario_trabalho'] );
		$this->assertArrayNotHasKey( 'acknowledgment', $rows );
	}

	public function test_second_post_fields_show_only_when_a_second_post_is_held(): void {
		$rows = $this->by_key( ReregistrationSubmissionAnswers::build( $this->submission( $this->values() ), false ) );
		$this->assertArrayNotHasKey( 'jornada_acumulo', $rows );

		$values                   = $this->values();
		$values['acumulo_cargos'] = 'I hold';
		$rows                     = $this->by_key( ReregistrationSubmissionAnswers::build( $this->submission( $values ), false ) );
		$this->assertSame( 'JB - 20h/a', $rows['jornada_acumulo'] );
	}

	public function test_an_empty_answer_stays_empty_and_is_not_counted_as_masked(): void {
		$answers = ReregistrationSubmissionAnswers::build( $this->submission( array( 'vinculo' => '' ) ), false );

		$this->assertSame( '', $this->by_key( $answers )['vinculo'] );
		$this->assertFalse( $answers['has_masked'] );
	}

	public function test_a_missing_campaign_answers_null(): void {
		$this->campaign = null;

		$this->assertNull( ReregistrationSubmissionAnswers::build( $this->submission( $this->values() ), false ) );
	}
}
