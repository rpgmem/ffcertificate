<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationFormRenderer;

/**
 * @covers \FreeFormCertificate\Reregistration\ReregistrationFormRenderer
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReregistrationFormRendererTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

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

		$user_mock = Mockery::mock( 'WP_User' );
		$user_mock->user_email      = 'test@example.com';
		$user_mock->display_name    = 'Test User';
		$user_mock->user_registered = '2025-01-01 00:00:00';
		Functions\when( 'get_userdata' )->justReturn( $user_mock );

		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}

		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return func_get_arg(0); } )->byDefault();
		$wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @var array<object>|null Field definitions the CustomFieldRepository mock
	 *                         returns. Reset before each `mockRepositories` call.
	 */
	private static $mockFields = null;

	/**
	 * @var object|null The source submission the `ReregistrationSubmissionReader`
	 *                  alias returns. `null` -- no approved reregistration
	 *                  anterior -- e o caso comum (#1213).
	 */
	private static $mockImportSource = null;

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
	 * Register alias mocks for the renderer's static repository dependencies.
	 *
	 * @param array<object> $fields Fields to expose through
	 *                              CustomFieldReader::get_by_audience_with_parents.
	 */
	private function mockRepositories( array $fields = array() ): void {
		self::$mockFields = $fields;

		$reregRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' );
		$reregRepoMock->shouldReceive( 'get_audience_ids' )->andReturn( empty( $fields ) ? array() : array( 1 ) );

		// Without this alias the renderer reaches #1213's real query and the test
		// dies in `ReregistrationRepository::get_table_name()`. `null` is the
		// common case: no earlier approved reregistration, so there is no import
		// offer.
		$submissionReaderMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' );
		$submissionReaderMock->shouldReceive( 'get_latest_approved_for_user' )->andReturn( self::$mockImportSource );

		$customFieldRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldReader' );
		$customFieldRepoMock->shouldReceive( 'get_by_audience_with_parents' )->andReturn( $fields );
		$customFieldRepoMock->shouldReceive( 'get_user_data' )->andReturn( array() );

		$fieldOptionsMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationFieldOptions' );
		$fieldOptionsMock->shouldReceive( 'get_default_acknowledgment_html' )->andReturn( '<p>Default termo de ciência</p>' );
		$fieldOptionsMock->shouldIgnoreMissing( array() );

		$seederMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' );
		$seederMock->shouldReceive( 'get_group_labels' )->andReturn( array(
			'personal' => 'Personal Data',
			'contact'  => 'Contact Information',
		) );
	}

	// ==================================================================
	// render() — basic rendering
	// ==================================================================

	public function test_render_returns_form_html(): void {
		$this->mockRepositories();

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
		$this->assertStringContainsString( 'Default termo de ciência', $html );
	}

	public function test_render_offers_the_import_when_a_previous_approval_exists(): void {
		self::$mockImportSource = (object) array( 'reregistration_title' => 'Recadastramento 2024' );
		$this->mockRepositories();

		$rereg      = (object) array( 'id' => 2, 'title' => 'Recadastramento 2025', 'end_date' => '2025-12-31 23:59:59' );
		$submission = (object) array( 'data' => null );

		$html = ReregistrationFormRenderer::render( $rereg, $submission, 10 );

		$this->assertStringContainsString( 'ffc-rereg-import-notice', $html );
		$this->assertStringContainsString( 'Recadastramento 2024', $html );
	}

	public function test_render_omits_the_import_offer_without_a_previous_approval(): void {
		$this->mockRepositories();

		$rereg      = (object) array( 'id' => 2, 'title' => 'Recadastramento 2025', 'end_date' => '2025-12-31 23:59:59' );
		$submission = (object) array( 'data' => null );

		$html = ReregistrationFormRenderer::render( $rereg, $submission, 10 );

		$this->assertStringNotContainsString( 'ffc-rereg-import-notice', $html );
	}

	public function test_render_acknowledgment_field_outputs_its_html(): void {
		$this->mockRepositories( array(
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
		$this->assertStringNotContainsString( 'Default termo de ciência', $html );
	}

	// ==================================================================
	// render() — with saved draft data
	// ==================================================================

	public function test_render_populates_from_saved_draft(): void {
		$this->mockRepositories( array(
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
		$encryptionMock = Mockery::mock( 'alias:FreeFormCertificate\\Core\\Encryption' );
		// The shared reader asks the VALUE whether it is an envelope before
		// attempting (#1509). `byDefault()` on the negative keeps every other
		// value in this render from being handed to `decrypt()` at all, which is
		// the behaviour that change buys.
		$encryptionMock->shouldReceive( 'looks_like_envelope' )
			->with( 'CIPHERTEXT-DO-CPF' )
			->andReturn( true );
		$encryptionMock->shouldReceive( 'looks_like_envelope' )->andReturn( false )->byDefault();
		$encryptionMock->shouldReceive( 'decrypt' )
			->with( 'CIPHERTEXT-DO-CPF' )
			->andReturn( '529.982.247-25' );
		$encryptionMock->shouldReceive( 'decrypt' )->andReturn( null )->byDefault();

		$this->mockRepositories( array(
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
						'cpf'   => 'CIPHERTEXT-DO-CPF',
						'phone' => '11999999999',
					),
				)
			),
		);

		$html = ReregistrationFormRenderer::render( $rereg, $submission, 10 );

		$this->assertStringContainsString( '529.982.247-25', $html, 'O valor sensível tem de voltar em texto claro.' );
		$this->assertStringNotContainsString( 'CIPHERTEXT-DO-CPF', $html, 'The ciphertext must not reach the form.' );
		$this->assertStringContainsString( '11999999999', $html, 'The non-sensitive field is left untouched.' );
	}

	// ==================================================================
	// render() — deadline is displayed
	// ==================================================================

	public function test_render_shows_deadline(): void {
		$this->mockRepositories();

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
		$field = $this->makeField( array( 'field_key' => 'shift', 'field_type' => 'select' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldReader' )
			->shouldReceive( 'get_field_choices' )->with( $field )->andReturn( array( 'Morning', 'Evening' ) );

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
		$this->assertStringContainsString( 'I agree <span class="ffc-required">*</span>', $checked );
		$this->assertStringNotContainsString( 'checked=', $unchecked );
		$this->assertStringNotContainsString( 'ffc-required', $unchecked );
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
			'field_options' => json_encode( array( 'parent_label' => 'Region', 'child_label' => 'School' ) ),
		) );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldReader' )
			->shouldReceive( 'get_dependent_choices' )->with( $field )
			->andReturn( array( 'North' => array( 'N1', 'N2' ), 'South' => array( 'S1' ) ) );

		$html = $this->renderInput( $field, json_encode( array( 'parent' => 'North', 'child' => 'N2' ) ) );

		$this->assertStringContainsString( '<label>Region</label>', $html );
		$this->assertStringContainsString( '<label>School</label>', $html );
		$this->assertStringContainsString( "<option value=\"North\"  selected='selected'>North</option>", $html );
		$this->assertStringContainsString( "<option value=\"N2\"  selected='selected'>N2</option>", $html );
		$this->assertStringNotContainsString( 'value="S1"', $html, 'Only the chosen parent\'s children are listed.' );
	}

	public function test_render_input_dependent_select_without_value_uses_default_labels(): void {
		$this->stubRealSelectedAndChecked();
		$field = $this->makeField( array( 'field_key' => 'unit', 'field_type' => 'dependent_select' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldReader' )
			->shouldReceive( 'get_dependent_choices' )->andReturn( array( 'North' => array( 'N1' ) ) );

		$html = $this->renderInput( $field, '' );

		$this->assertStringContainsString( '<label>Category</label>', $html );
		$this->assertStringContainsString( '<label>Subcategory</label>', $html );
		$this->assertStringNotContainsString( 'value="N1"', $html, 'No parent chosen, so no child is listed.' );
	}

	public function test_render_input_working_hours_renders_stored_rows(): void {
		$this->stubRealSelectedAndChecked();
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationFieldOptions' )
			->shouldNotReceive( 'get_default_working_hours' );
		$field = $this->makeField( array( 'field_key' => 'hours', 'field_type' => 'working_hours' ) );
		$rows  = array( array( 'day' => 3, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00' ) );

		$html = $this->renderInput( $field, json_encode( $rows ) );

		$this->assertSame( 1, substr_count( $html, '<tr>' ) - 1, 'One stored row plus the header row.' );
		$this->assertStringContainsString( "<option value=\"3\"  selected='selected'>Wednesday</option>", $html );
		$this->assertStringContainsString( 'value="13:00"', $html );
	}

	public function test_render_input_working_hours_falls_back_to_the_default_schedule(): void {
		$this->stubRealSelectedAndChecked();
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationFieldOptions' )
			->shouldReceive( 'get_default_working_hours' )->once()
			->andReturn( array( array( 'day' => 1, 'entry1' => '09:00', 'exit1' => '', 'entry2' => '', 'exit2' => '18:00' ) ) );
		$field = $this->makeField( array( 'field_key' => 'hours', 'field_type' => 'working_hours' ) );

		$html = $this->renderInput( $field, '[]' );

		$this->assertStringContainsString( 'value="09:00"', $html );
		$this->assertStringContainsString( "<option value=\"1\"  selected='selected'>Monday</option>", $html );
	}

	// ==================================================================
	// render_field() / render_group_fieldset() / acknowledgment fallback
	// ==================================================================

	public function test_render_field_acknowledgment_without_html_uses_the_default_notice(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationFieldOptions' )
			->shouldReceive( 'get_default_acknowledgment_html' )->once()->andReturn( '<p>Shipped notice</p>' );
		$field = $this->makeField( array(
			'field_key'     => 'ack',
			'field_type'    => 'acknowledgment',
			'field_options' => json_encode( array( 'html' => '' ) ),
		) );

		ob_start();
		ReregistrationFormRenderer::render_field( $field, '' );
		$html = (string) ob_get_clean();

		$this->assertSame( '<div class="ffc-rereg-termo-text"><p>Shipped notice</p></div>', $html );
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

	public function test_render_group_fieldset_prints_nothing_for_an_empty_group(): void {
		ob_start();
		ReregistrationFormRenderer::render_group_fieldset( 1, 'Personal', array(), array() );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_render_acknowledgment_fieldset_prints_the_default_notice(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationFieldOptions' )
			->shouldReceive( 'get_default_acknowledgment_html' )->andReturn( '<p>Shipped notice</p>' );

		ob_start();
		ReregistrationFormRenderer::render_acknowledgment_fieldset( 4 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<legend>4. Acknowledgment</legend>', $html );
		$this->assertStringContainsString( '<p>Shipped notice</p>', $html );
	}

	// ==================================================================
	// build_field_values() — resolution order
	// ==================================================================

	public function test_build_field_values_follows_draft_profile_email_default_order(): void {
		$user_manager = Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\UserManager' );
		$user_manager->shouldReceive( 'get_extended_profile' )
			->once()
			->with( 10, array( 'phone', 'cpf', 'empty_key' ), array( 'cpf' ) )
			->andReturn( array( 'phone' => '1188887777', 'cpf' => '529.982.247-25', 'empty_key' => '' ) );

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
				'cpf'                 => '529.982.247-25',
				'email_institucional' => 'teacher@example.org',
				'blank'               => 'fallback',
				'nothing'             => '',
			),
			$values
		);
	}

	public function test_build_field_values_skips_the_profile_for_a_guest(): void {
		Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\UserManager' )
			->shouldNotReceive( 'get_extended_profile' );

		$fields = array(
			$this->makeField( array( 'id' => 1, 'field_key' => 'email_institucional' ) ),
		);

		$method = new \ReflectionMethod( ReregistrationFormRenderer::class, 'build_field_values' );
		$method->setAccessible( true );
		$values = $method->invoke( null, $fields, array(), 0, false );

		$this->assertSame( array( 'email_institucional' => '' ), $values, 'No user, so no e-mail fallback either.' );
	}
}
