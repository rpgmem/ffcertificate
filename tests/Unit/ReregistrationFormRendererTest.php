<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Reregistration\ReregistrationFieldOptions;
use FreeFormCertificate\Reregistration\ReregistrationFormRenderer;
use FreeFormCertificate\Tests\Support\CallLog;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for ReregistrationFormRenderer.
 *
 * The repositories, the custom-field reader, the field options, the group
 * labels, the record generator, Encryption (the bootstrap's keys) and the
 * user manager are the real classes, over a strict `$wpdb` double that
 * answers the campaign's audience link, audience 1 and its fields, the
 * previous-approval lookup and the profile table — any other query fails the
 * test.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationFormRenderer
 */
class ReregistrationFormRendererTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<object> Field rows audience 1 carries. */
	private array $fields = array();

	/** @var list<string> What the campaign's audience link returns. */
	private array $audience_ids = array();

	/**
	 * The row the previous-approval lookup returns. `null` -- no earlier
	 * approved reregistration -- is the common case (#1213).
	 *
	 * @var object|null
	 */
	private ?object $import_source = null;

	/**
	 * The ffc_user_profiles row of user 10, or null when the table does not
	 * exist.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $profile_row = null;

	/** @var list<string> Every query the double answered, in order. */
	private array $queries = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/**
	 * A CPF in the `xxx.xxx.xxx-xx` mask a visitor types.
	 *
	 * @param string $cpf Eleven digits.
	 */
	private static function masked( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9 );
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'wp_date' )->alias( function ( $format, $ts = null, $tz = null ) { return date( $format, $ts ?? time() ); } );
		Functions\when( 'wp_timezone' )->alias( function () { return new \DateTimeZone( 'UTC' ); } );
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( $key === 'ffc_settings' ) return array( 'date_format' => 'Y-m-d', 'time_format' => 'H:i' );
			return 'Y-m-d';
		} );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$user_mock = Mockery::mock( 'WP_User' );
		$user_mock->user_email      = 'test@example.com';
		$user_mock->display_name    = 'Test User';
		$user_mock->user_registered = '2025-01-01 00:00:00';
		Functions\when( 'get_userdata' )->justReturn( $user_mock );

		$this->fields        = array();
		$this->audience_ids  = array();
		$this->import_source = null;
		$this->profile_row   = null;
		$this->queries       = array();
		$GLOBALS['wpdb']     = $this->database();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The strict `$wpdb` double, answering from the properties above.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		// Interpolates, so every read can dispatch on the query's full text.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				foreach ( $values as $v ) {
					$sql = (string) preg_replace( '/%[ids]/', (string) $v, (string) $sql, 1 );
				}
				return $sql;
			}
		);

		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				if ( preg_match( '/^SELECT audience_id FROM wp_ffc_reregistration_audiences WHERE reregistration_id = \d+$/', (string) $sql ) ) {
					return $this->audience_ids;
				}
				$this->fail( 'Unexpected get_col: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				if ( str_starts_with( (string) $sql, 'SELECT * FROM wp_ffc_custom_fields WHERE audience_id = 1 AND is_active = 1' ) ) {
					return $this->fields;
				}
				$this->fail( 'Unexpected get_results: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				if ( 'SELECT * FROM wp_ffc_audiences WHERE id = 1' === $sql ) {
					return (object) array( 'id' => 1, 'name' => 'Test Audience', 'parent_id' => 0 );
				}
				if ( str_contains( (string) $sql, 'INNER JOIN wp_ffc_reregistrations r' ) && str_contains( (string) $sql, "AND s.status = approved" ) ) {
					return $this->import_source;
				}
				if ( 'SELECT * FROM wp_ffc_user_profiles WHERE user_id = 10' === $sql && null !== $this->profile_row ) {
					return $this->profile_row;
				}
				$this->fail( 'Unexpected get_row: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				if ( 'SHOW TABLES LIKE wp_ffc_user_profiles' === $sql ) {
					return null !== $this->profile_row ? 'wp_ffc_user_profiles' : null;
				}
				$this->fail( 'Unexpected get_var: ' . $sql );
			}
		);

		return $wpdb;
	}

	/**
	 * Build a minimal field stdClass with renderer-safe defaults.
	 *
	 * @param array<string, mixed> $overrides
	 */
	private function makeField( array $overrides ): object {
		return (object) array_merge( array(
			'id'                 => 0,
			'field_key'          => '',
			'field_label'        => '',
			'field_type'         => 'text',
			'field_group'        => 'personal',
			'field_source'       => 'standard',
			'field_profile_key'  => null,
			'field_mask'         => null,
			'is_sensitive'       => 0,
			'field_options'      => null,
			'validation_rules'   => null,
			'sort_order'         => 0,
			'is_required'        => 0,
			'is_active'          => 1,
		), $overrides );
	}

	/**
	 * Store the fields the campaign's audience carries: with fields, the
	 * campaign links audience 1; without, it links none.
	 *
	 * @param array<object> $fields Field rows of audience 1.
	 */
	private function useFields( array $fields = array() ): void {
		$this->fields       = $fields;
		$this->audience_ids = empty( $fields ) ? array() : array( '1' );
	}

	/**
	 * The shipped acknowledgment notice, the fallback when no audience field
	 * carries one.
	 */
	private static function defaultNotice(): string {
		return ReregistrationFieldOptions::get_default_acknowledgment_html();
	}

	// ==================================================================
	// render() — basic rendering
	// ==================================================================

	public function test_render_returns_form_html(): void {
		$this->useFields();

		$rereg = (object) array(
			'id'       => 1,
			'title'    => 'Recadastramento 2025',
			'end_date' => '2025-12-31 23:59:59',
		);
		$submission = (object) array( 'data' => null );

		$html = ReregistrationFormRenderer::render( $rereg, $submission, 10 );

		$this->assertStringContainsString( 'ffc-rereg-form-container', $html );
		$this->assertStringContainsString( 'Recadastramento 2025', $html );
		$this->assertStringContainsString( 'ffc-rereg-form', $html );
		// No acknowledgment field present → the default notice fallback fires.
		$this->assertStringContainsString( self::defaultNotice(), $html );
	}

	public function test_render_offers_the_import_when_a_previous_approval_exists(): void {
		$this->import_source = (object) array( 'reregistration_title' => 'Recadastramento 2024' );
		$this->useFields();

		$rereg      = (object) array( 'id' => 2, 'title' => 'Recadastramento 2025', 'end_date' => '2025-12-31 23:59:59' );
		$submission = (object) array( 'data' => null );

		$html = ReregistrationFormRenderer::render( $rereg, $submission, 10 );

		$this->assertStringContainsString( 'class="ffc-notice ffc-notice-info"', $html );
		$this->assertStringContainsString( 'data-ffc-notice="rereg-import"', $html );
		$this->assertStringContainsString( 'ffc-rereg-import-btn', $html );
		$this->assertStringContainsString( 'Recadastramento 2024', $html );
	}

	public function test_render_omits_the_import_offer_without_a_previous_approval(): void {
		$this->useFields();

		$rereg      = (object) array( 'id' => 2, 'title' => 'Recadastramento 2025', 'end_date' => '2025-12-31 23:59:59' );
		$submission = (object) array( 'data' => null );

		$html = ReregistrationFormRenderer::render( $rereg, $submission, 10 );

		$this->assertStringNotContainsString( 'data-ffc-notice="rereg-import"', $html );
	}

	public function test_render_acknowledgment_field_outputs_its_html(): void {
		$this->useFields( array(
			$this->makeField( array(
				'id'            => 1,
				'field_key'     => 'acknowledgment',
				'field_label'   => 'Acknowledgment',
				'field_type'    => 'acknowledgment',
				'field_group'   => 'acknowledgment',
				'field_options' => json_encode( array( 'html' => '<p>Per-audience notice text</p>' ) ),
			) ),
		) );

		$rereg = (object) array(
			'id'       => 1,
			'title'    => 'Recadastramento 2025',
			'end_date' => '2025-12-31 23:59:59',
		);

		$html = ReregistrationFormRenderer::render( $rereg, (object) array( 'data' => null ), 10 );

		// The per-audience HTML renders inside the termo wrapper…
		$this->assertStringContainsString( 'ffc-rereg-termo-text', $html );
		$this->assertStringContainsString( 'Per-audience notice text', $html );
		// …and the default-notice fallback does NOT also fire.
		$this->assertStringNotContainsString( self::defaultNotice(), $html );
	}

	// ==================================================================
	// render() — with saved draft data
	// ==================================================================

	public function test_render_populates_from_saved_draft(): void {
		$this->useFields( array(
			$this->makeField( array(
				'id'          => 1,
				'field_key'   => 'display_name',
				'field_label' => 'Name',
				'field_type'  => 'text',
				'field_group' => 'personal',
				'is_required' => 1,
			) ),
			$this->makeField( array(
				'id'          => 2,
				'field_key'   => 'phone',
				'field_label' => 'Home Phone',
				'field_type'  => 'text',
				'field_group' => 'contact',
			) ),
		) );

		$rereg = (object) array(
			'id'       => 2,
			'title'    => 'Test Draft',
			'end_date' => '2025-06-30 23:59:59',
		);
		$submission = (object) array(
			'data' => json_encode( array(
				'fields' => array(
					'display_name' => 'Maria Silva',
					'phone'        => '11999999999',
				),
			) ),
		);

		$html = ReregistrationFormRenderer::render( $rereg, $submission, 10 );

		$this->assertStringContainsString( 'Maria Silva', $html );
		$this->assertStringContainsString( '11999999999', $html );
	}

	/**
	 * A returned draft has to come back READABLE, not as ciphertext.
	 *
	 * The sensitive value is written by `Encryption::encrypt` (see
	 * `ReregistrationDataProcessor`), so what sits in the submission's JSON is
	 * ciphertext. The profile path already decrypts; the draft path did not, and
	 * the user saw the blob in place of their own CPF.
	 *
	 * The test charges the VALUE: the plaintext appears and the ciphertext does
	 * not. An assertion of "rendered without error" would pass with the defect
	 * in place.
	 *
	 * @return void
	 */
	public function test_render_decrypts_a_sensitive_draft_value(): void {
		// What `ReregistrationDataProcessor` writes: the real envelope, under
		// the bootstrap's key.
		$ciphertext = Encryption::encrypt( self::masked( Identifiers::CPF_A ) );
		$this->assertIsString( $ciphertext );

		$this->useFields( array(
			$this->makeField( array(
				'id'           => 1,
				'field_key'    => 'cpf',
				'field_label'  => 'CPF/CIN',
				'field_type'   => 'text',
				'field_group'  => 'personal',
				'is_sensitive' => 1,
			) ),
			$this->makeField( array(
				'id'          => 2,
				'field_key'   => 'phone',
				'field_label' => 'Home Phone',
				'field_type'  => 'text',
				'field_group' => 'contact',
			) ),
		) );

		$rereg      = (object) array(
			'id'       => 3,
			'title'    => 'Devolvida para rascunho',
			'end_date' => '2025-06-30 23:59:59',
		);
		$submission = (object) array(
			'data' => json_encode(
				array(
					'fields' => array(
						'cpf'   => $ciphertext,
						'phone' => '11999999999',
					),
				)
			),
		);

		$html = ReregistrationFormRenderer::render( $rereg, $submission, 10 );

		$this->assertStringContainsString( self::masked( Identifiers::CPF_A ), $html, 'The sensitive value must come back in clear text.' );
		$this->assertStringNotContainsString( $ciphertext, $html, 'The ciphertext must not reach the form.' );
		$this->assertStringContainsString( '11999999999', $html, 'The non-sensitive field is left untouched.' );
	}

	// ==================================================================
	// render() — deadline is displayed
	// ==================================================================

	public function test_render_shows_deadline(): void {
		$this->useFields();

		$rereg = (object) array(
			'id'       => 3,
			'title'    => 'Deadline Test',
			'end_date' => '2025-09-15 18:00:00',
		);
		$submission = (object) array( 'data' => null );

		$html = ReregistrationFormRenderer::render( $rereg, $submission, 5 );

		$this->assertStringContainsString( 'Deadline', $html );
		$this->assertStringContainsString( '2025-09-15', $html );
	}

	// ==================================================================
	// render_input() — one branch per field type
	// ==================================================================

	/**
	 * Makes selected()/checked() behave like WordPress so the chosen option
	 * is observable in the markup.
	 */
	private function stubRealSelectedAndChecked(): void {
		$helper = static function ( $current, $value, $echo, string $attr ): string {
			$out = (string) $current === (string) $value ? " {$attr}='{$attr}'" : '';
			if ( $echo ) {
				echo $out;
			}
			return $out;
		};
		Functions\when( 'selected' )->alias(
			static fn ( $current, $value = true, $echo = true ) => $helper( $current, $value, $echo, 'selected' )
		);
		Functions\when( 'checked' )->alias(
			static fn ( $current, $value = true, $echo = true ) => $helper( $current, $value, $echo, 'checked' )
		);
		Functions\when( 'esc_textarea' )->alias( static fn ( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/**
	 * @param array<string, mixed> $rules
	 */
	private function renderInput( object $field, $value, bool $required = false, array $rules = array() ): string {
		ob_start();
		ReregistrationFormRenderer::render_input( $field, 'fid', 'fields[' . $field->field_key . ']', $value, $required, $rules );
		return (string) ob_get_clean();
	}

	public function test_render_input_textarea_escapes_its_value_and_marks_required(): void {
		$this->stubRealSelectedAndChecked();
		$html = $this->renderInput( $this->makeField( array( 'field_key' => 'notes', 'field_type' => 'textarea' ) ), '<b>x</b>', true );

		$this->assertSame( '<textarea id="fid" name="fields[notes]" rows="3" required>&lt;b&gt;x&lt;/b&gt;</textarea>', $html );
	}

	public function test_render_input_textarea_drops_a_non_scalar_value(): void {
		$this->stubRealSelectedAndChecked();
		$html = $this->renderInput( $this->makeField( array( 'field_key' => 'notes', 'field_type' => 'textarea' ) ), array( 'x' ) );

		$this->assertSame( '<textarea id="fid" name="fields[notes]" rows="3"></textarea>', $html );
	}

	public function test_render_input_select_lists_choices_and_selects_the_value(): void {
		$this->stubRealSelectedAndChecked();
		$field = $this->makeField( array(
			'field_key'     => 'shift',
			'field_type'    => 'select',
			'field_options' => json_encode( array( 'choices' => array( 'Morning', 'Evening' ) ) ),
		) );

		$html = $this->renderInput( $field, 'Evening', true );

		$this->assertStringContainsString( '<select id="fid" name="fields[shift]" required>', $html );
		$this->assertStringContainsString( '<option value="">Select</option>', $html );
		$this->assertStringContainsString( '<option value="Morning" >Morning</option>', $html );
		$this->assertStringContainsString( "<option value=\"Evening\"  selected='selected'>Evening</option>", $html );
		$this->assertStringEndsWith( '</select>', $html );
	}

	public function test_render_input_checkbox_is_checked_for_one_and_flags_required(): void {
		$this->stubRealSelectedAndChecked();
		$field = $this->makeField( array( 'field_key' => 'agree', 'field_type' => 'checkbox', 'field_label' => 'I agree' ) );

		$checked   = $this->renderInput( $field, '1', true );
		$unchecked = $this->renderInput( $field, '0', false );

		$this->assertStringContainsString( "checked='checked'", $checked );
		$this->assertStringContainsString( 'I agree <span class="ffc-required" aria-hidden="true">*</span>', $checked );
		$this->assertStringNotContainsString( 'checked=', $unchecked );
		$this->assertStringNotContainsString( 'ffc-required', $unchecked );
		// The control itself carries the constraint, not only the marker (#1850).
		$this->assertMatchesRegularExpression( '/<input type="checkbox"[^>]* required[ >]/', $checked );
		$this->assertStringNotContainsString( ' required', $unchecked );
	}

	public function test_render_input_number_carries_the_field_mask(): void {
		$this->stubRealSelectedAndChecked();
		$field = $this->makeField( array( 'field_key' => 'age', 'field_type' => 'number', 'field_mask' => '000' ) );

		$this->assertSame(
			'<input type="number" id="fid" name="fields[age]" value="42" data-mask="000">',
			$this->renderInput( $field, 42 )
		);
	}

	public function test_render_input_date_ignores_masks(): void {
		$this->stubRealSelectedAndChecked();
		$field = $this->makeField( array( 'field_key' => 'born', 'field_type' => 'date', 'field_mask' => '00/00' ) );

		$this->assertSame(
			'<input type="date" id="fid" name="fields[born]" value="2000-01-02" required>',
			$this->renderInput( $field, '2000-01-02', true )
		);
	}

	public function test_render_input_text_takes_the_mask_from_the_format_rule(): void {
		$this->stubRealSelectedAndChecked();
		$field = $this->makeField( array( 'field_key' => 'cpf', 'field_type' => 'text' ) );

		$this->assertSame(
			'<input type="text" id="fid" name="fields[cpf]" value="" data-mask="cpf">',
			$this->renderInput( $field, null, false, array( 'format' => 'cpf' ) )
		);
	}

	public function test_render_input_unknown_type_falls_back_to_text(): void {
		$this->stubRealSelectedAndChecked();
		$field = $this->makeField( array( 'field_key' => 'misc', 'field_type' => 'color' ) );

		$this->assertSame( '<input type="text" id="fid" name="fields[misc]" value="v">', $this->renderInput( $field, 'v' ) );
	}

	public function test_render_input_dependent_select_restores_parent_and_child(): void {
		$this->stubRealSelectedAndChecked();
		$field = $this->makeField( array(
			'field_key'     => 'unit',
			'field_type'    => 'dependent_select',
			'field_options' => json_encode( array(
				'parent_label' => 'Region',
				'child_label'  => 'School',
				'groups'       => array( 'North' => array( 'N1', 'N2' ), 'South' => array( 'S1' ) ),
			) ),
		) );

		$html = $this->renderInput( $field, json_encode( array( 'parent' => 'North', 'child' => 'N2' ) ) );

		$this->assertStringContainsString( '<label>Region</label>', $html );
		$this->assertStringContainsString( '<label>School</label>', $html );
		$this->assertStringContainsString( "<option value=\"North\"  selected='selected'>North</option>", $html );
		$this->assertStringContainsString( "<option value=\"N2\"  selected='selected'>N2</option>", $html );
		$this->assertStringNotContainsString( 'value="S1"', $html, 'Only the chosen parent\'s children are listed.' );
	}

	public function test_render_input_dependent_select_without_value_uses_default_labels(): void {
		$this->stubRealSelectedAndChecked();
		$field = $this->makeField( array(
			'field_key'     => 'unit',
			'field_type'    => 'dependent_select',
			'field_options' => json_encode( array( 'groups' => array( 'North' => array( 'N1' ) ) ) ),
		) );

		$html = $this->renderInput( $field, '' );

		$this->assertStringContainsString( '<label>Category</label>', $html );
		$this->assertStringContainsString( '<label>Subcategory</label>', $html );
		$this->assertStringNotContainsString( 'value="N1"', $html, 'No parent chosen, so no child is listed.' );
	}

	public function test_render_input_working_hours_renders_stored_rows(): void {
		$this->stubRealSelectedAndChecked();
		$field = $this->makeField( array( 'field_key' => 'hours', 'field_type' => 'working_hours' ) );
		$rows  = array( array( 'day' => 3, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00' ) );

		$html = $this->renderInput( $field, json_encode( $rows ) );

		// The stored schedule, not the default one, fills the table and the
		// hidden input.
		$this->assertGreaterThan( 1, count( ReregistrationFieldOptions::get_default_working_hours() ), 'The default schedule must be distinguishable from one stored row.' );
		$this->assertSame( 1, substr_count( $html, '<tr>' ) - 1, 'One stored row plus the header row.' );
		$this->assertStringContainsString( 'value="' . json_encode( $rows ) . '"', $html );
		$this->assertStringContainsString( "<option value=\"3\"  selected='selected'>Wednesday</option>", $html );
		$this->assertStringContainsString( 'value="13:00"', $html );
	}

	public function test_render_input_working_hours_falls_back_to_the_default_schedule(): void {
		$this->stubRealSelectedAndChecked();
		$field   = $this->makeField( array( 'field_key' => 'hours', 'field_type' => 'working_hours' ) );
		$default = ReregistrationFieldOptions::get_default_working_hours();

		$html = $this->renderInput( $field, '[]' );

		// One row per day of the shipped schedule, and the hidden input
		// carries that schedule.
		$this->assertSame( count( $default ), substr_count( $html, '<tr>' ) - 1, 'One row per default day plus the header row.' );
		$this->assertStringContainsString( 'value="' . json_encode( $default ) . '"', $html );
		$this->assertStringContainsString( "<option value=\"1\"  selected='selected'>Monday</option>", $html );
	}

	// ==================================================================
	// render_field() / render_group_fieldset() / acknowledgment fallback
	// ==================================================================

	public function test_render_field_acknowledgment_without_html_uses_the_default_notice(): void {
		$field = $this->makeField( array(
			'field_key'     => 'ack',
			'field_type'    => 'acknowledgment',
			'field_options' => json_encode( array( 'html' => '' ) ),
		) );

		ob_start();
		ReregistrationFormRenderer::render_field( $field, '' );
		$html = (string) ob_get_clean();

		$this->assertSame( '<div class="ffc-rereg-termo-text">' . self::defaultNotice() . '</div>', $html );
	}

	public function test_render_field_checkbox_uses_the_inline_wrapper(): void {
		$this->stubRealSelectedAndChecked();
		$field = $this->makeField( array(
			'id'               => 9,
			'field_key'        => 'agree',
			'field_type'       => 'checkbox',
			'field_label'      => 'I agree',
			'validation_rules' => json_encode( array( 'format' => 'ignored' ) ),
		) );

		ob_start();
		ReregistrationFormRenderer::render_field( $field, '1' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-field-key="agree"', $html );
		$this->assertStringContainsString( 'id="ffc_rereg_field_9"', $html );
		$this->assertStringContainsString( "checked='checked'", $html );
		$this->assertStringNotContainsString( '<label for=', $html, 'A checkbox carries its label inline, not above.' );
	}

	public function test_render_field_prints_no_message_slot_without_an_error(): void {
		$field = $this->makeField( array( 'id' => 3, 'field_key' => 'phone', 'field_type' => 'text', 'field_label' => 'Phone', 'is_required' => 1 ) );

		ob_start();
		ReregistrationFormRenderer::render_field( $field, '' );
		$html = (string) ob_get_clean();

		// The script inserts the message where it is needed (#1844); the old
		// empty `.ffc-rereg-field-error` slot is gone.
		$this->assertStringNotContainsString( 'ffc-rereg-field-error', $html );
		$this->assertStringNotContainsString( 'ffc-field-message', $html );
		$this->assertStringNotContainsString( 'aria-invalid', $html );
		$this->assertStringContainsString( '<span class="ffc-required" aria-hidden="true">*</span>', $html );
	}

	public function test_render_field_prints_a_server_error_in_the_field_message_standard(): void {
		$field = $this->makeField( array( 'id' => 3, 'field_key' => 'phone', 'field_type' => 'text', 'field_label' => 'Phone' ) );

		ob_start();
		ReregistrationFormRenderer::render_field( $field, '', 'Invalid phone.' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString(
			'<span id="ffc-msg-ffc_rereg_field_3" class="ffc-field-message is-error ffc-icon-error">Invalid phone.</span>',
			$html
		);
		$this->assertStringContainsString( ' class="is-invalid" aria-invalid="true" data-ffc-message-id="ffc-msg-ffc_rereg_field_3" aria-describedby="ffc-msg-ffc_rereg_field_3"', $html );
		// Label, control, message: the message follows the control.
		$this->assertLessThan( strpos( $html, 'ffc-field-message' ), strpos( $html, 'id="ffc_rereg_field_3"' ) );
	}

	public function test_render_field_ties_a_checkbox_to_its_server_error(): void {
		$this->stubRealSelectedAndChecked();
		$field = $this->makeField( array( 'id' => 9, 'field_key' => 'agree', 'field_type' => 'checkbox', 'field_label' => 'I agree' ) );

		ob_start();
		ReregistrationFormRenderer::render_field( $field, '0', 'Tick to agree.' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aria-invalid="true"', $html );
		$this->assertStringContainsString( 'id="ffc-msg-ffc_rereg_field_9"', $html );
		$this->assertLessThan( strpos( $html, 'ffc-field-message' ), strpos( $html, '</label>' ) );
	}

	public function test_render_group_fieldset_prints_nothing_for_an_empty_group(): void {
		ob_start();
		ReregistrationFormRenderer::render_group_fieldset( 1, 'Personal', array(), array() );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_render_acknowledgment_fieldset_prints_the_default_notice(): void {
		ob_start();
		ReregistrationFormRenderer::render_acknowledgment_fieldset( 4 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<legend>4. Acknowledgment</legend>', $html );
		$this->assertStringContainsString( self::defaultNotice(), $html );
	}

	// ==================================================================
	// build_field_values() — resolution order
	// ==================================================================

	/**
	 * The profile is read through the real UserManager: user 10 has a profile
	 * row (the `phone` column) and an encrypted `cpf` in the extended-profile
	 * meta; `empty_key` is stored empty, so its field falls to the default.
	 */
	public function test_build_field_values_follows_draft_profile_email_default_order(): void {
		$this->profile_row = array(
			'id'           => '1',
			'user_id'      => '10',
			'display_name' => 'Teacher',
			'phone'        => '1188887777',
			'department'   => null,
			'organization' => null,
			'notes'        => null,
			'preferences'  => null,
			'created_at'   => '2025-01-01 00:00:00',
			'updated_at'   => '2025-01-01 00:00:00',
		);
		$stored_cpf        = Encryption::encrypt( self::masked( Identifiers::CPF_A ) );
		$meta_reads        = array();
		Functions\when( 'get_user_meta' )->alias(
			static function ( $user_id, $key ) use ( &$meta_reads, $stored_cpf ) {
				$meta_reads[] = array( (int) $user_id, (string) $key );
				return 'ffc_user_cpf' === $key ? $stored_cpf : '';
			}
		);
		Functions\when( 'sanitize_key' )->alias( static fn ( $key ) => strtolower( (string) $key ) );

		$fields = array(
			$this->makeField( array( 'id' => 1, 'field_key' => 'name' ) ),
			$this->makeField( array( 'id' => 2, 'field_key' => 'phone', 'field_profile_key' => 'phone' ) ),
			$this->makeField( array( 'id' => 3, 'field_key' => 'cpf', 'field_profile_key' => 'cpf', 'is_sensitive' => 1 ) ),
			$this->makeField( array( 'id' => 4, 'field_key' => 'email_institucional' ) ),
			$this->makeField( array( 'id' => 5, 'field_key' => 'blank', 'field_profile_key' => 'empty_key', 'field_options' => array( 'default' => 'fallback' ) ) ),
			$this->makeField( array( 'id' => 6, 'field_key' => 'nothing' ) ),
		);
		$user   = (object) array( 'user_email' => 'teacher@example.org' );

		$method = new \ReflectionMethod( ReregistrationFormRenderer::class, 'build_field_values' );
		$method->setAccessible( true );
		$values = $method->invoke( null, $fields, array( 'name' => 'From draft' ), 10, $user );

		$this->assertSame(
			array(
				'name'                => 'From draft',
				'phone'               => '1188887777',
				'cpf'                 => self::masked( Identifiers::CPF_A ),
				'email_institucional' => 'teacher@example.org',
				'blank'               => 'fallback',
				'nothing'             => '',
			),
			$values
		);
		// The profile was read once, for user 10, over exactly the mapped keys:
		// `phone` from the profile row, the other two from the extended meta.
		$this->assertSame(
			array(
				'SHOW TABLES LIKE wp_ffc_user_profiles',
				'SELECT * FROM wp_ffc_user_profiles WHERE user_id = 10',
			),
			$this->queries
		);
		$this->assertSame( array( array( 10, 'ffc_user_cpf' ), array( 10, 'ffc_user_empty_key' ) ), $meta_reads );
	}

	public function test_build_field_values_skips_the_profile_for_a_guest(): void {
		$meta = CallLog::record( 'get_user_meta', '' );

		$fields = array(
			$this->makeField( array( 'id' => 1, 'field_key' => 'email_institucional' ) ),
		);

		$method = new \ReflectionMethod( ReregistrationFormRenderer::class, 'build_field_values' );
		$method->setAccessible( true );
		$values = $method->invoke( null, $fields, array(), 0, false );

		$this->assertSame( array( 'email_institucional' => '' ), $values, 'No user, so no e-mail fallback either.' );
		$this->assertSame( array(), $this->queries, 'A guest has no profile to read.' );
		$this->assertSame( 0, $meta->count(), 'A guest has no profile meta to read.' );
	}
}
