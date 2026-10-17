<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormEditorBuilderMetabox;

/**
 * @covers \FreeFormCertificate\Admin\FormEditorBuilderMetabox
 */
class FormEditorBuilderMetaboxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private FormEditorBuilderMetabox $metabox;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// SettingsReader reads the required certificate tags (#1734).
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n, $d = 0 ) => number_format( (float) $n, (int) $d ) );

		// `LabelSorter::locale()` is guarded by `function_exists( 'get_locale' )`,
		// so this used to take the no-WordPress branch only because nothing in
		// the process had defined that function yet. That is an accident of
		// file ordering, not a property of these tests: the moment any other
		// suite stubs `get_locale`, the guard passes here too and the call
		// lands unmocked. Pin it to the same locale the fallback would have
		// produced, so the behaviour under test is chosen rather than inherited.
		Functions\when( 'get_locale' )->justReturn( 'en_US' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->alias( function ( $p = '' ) { return '/wp-admin/' . $p; } );
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'checked' )->alias( static fn( $c, $cur = true, $echo = true ) => (string) $c === (string) $cur ? ( $echo ? print( ' checked' ) : ' checked' ) : '' );

		$this->metabox = new FormEditorBuilderMetabox();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render( $fields_meta = '', string $status = 'publish' ): string {
		Functions\when( 'get_post_meta' )->justReturn( $fields_meta );
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = 22;
		$post->post_status = $status;
		ob_start();
		$this->metabox->render( $post );
		return (string) ob_get_clean();
	}

	public function test_render_with_no_saved_fields_outputs_add_field_button(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'ffc-add-field', $html );
		$this->assertStringContainsString( 'Add New Field', $html );
	}

	public function test_render_emits_field_template_for_dynamic_field_cloning(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'ffc-field-template', $html );
		$this->assertStringContainsString( 'ffc-hidden', $html );
	}

	public function test_render_mentions_the_minimal_required_field_tags(): void {
		$html = $this->render();

		// The description hints the operator at the required field keys.
		$this->assertStringContainsString( '<code>name</code>', $html );
		$this->assertStringContainsString( '<code>email</code>', $html );
		$this->assertStringContainsString( '<code>cpf_rf</code>', $html );
	}

	public function test_render_iterates_saved_fields_payload(): void {
		$saved = array(
			array(
				'name'  => 'name',
				'label' => 'Full Name',
				'type'  => 'text',
			),
		);
		$html = $this->render( $saved );

		// Saved fields surface their label in a builder row.
		$this->assertStringContainsString( 'Full Name', $html );
	}

	public function test_a_saved_hidden_field_shows_its_fixed_value(): void {
		$html = $this->render(
			array(
				array(
					'name'          => 'turma',
					'type'          => 'hidden',
					'default_value' => '2026-A',
				),
			)
		);

		// #1574: the value a hidden field submits is editable in the row.
		$this->assertMatchesRegularExpression( '/<div class="ffc-hidden-value-field">/', $html, 'Visible for a hidden field.' );
		$this->assertStringContainsString( 'name="ffc_fields[0][default_value]" value="2026-A"', $html );
	}

	/**
	 * #1730 — each option row carries its correct mark and points, aligned,
	 * and every field carries its quiz block.
	 */
	public function test_option_rows_carry_their_correct_mark_and_points(): void {
		$html = $this->render(
			array(
				array(
					'name'        => 'q',
					'type'        => 'radio',
					'choices'     => array( 'A', 'B' ),
					'points'      => '0,7',
					'correct'     => array( 'B' ),
					'quiz_scored' => '1',
					'quiz_mode'   => 'correct',
				),
			)
		);

		$this->assertSame( 2, preg_match_all( '/name="ffc_fields\[0\]\[choice_correct\]\[\]" value="([01])"/', $html, $marks ) );
		$this->assertSame( array( '0', '1' ), $marks[1], 'The mark follows its option.' );
		$this->assertSame( 2, preg_match_all( '/name="ffc_fields\[0\]\[choice_points\]\[\]" value="(\d*)"/', $html, $points ) );
		$this->assertSame( array( '0', '7' ), $points[1] );
		$this->assertStringContainsString( 'name="ffc_fields[0][quiz_block]" value="1"', $html );
		$this->assertMatchesRegularExpression( '/id="ffc_field_quiz_scored_0"[^>]*checked/', $html );
		$this->assertStringNotContainsString( 'ffc-quiz-points ffc-hidden', $html, 'The old comma-separated points input is gone.' );
	}

	/**
	 * #1734 — a field a required certificate tag calls for is marked and
	 * locked; any other field is not.
	 */
	public function test_a_required_tag_field_is_marked_and_locked(): void {
		$html = $this->render(
			array(
				array(
					'name'  => 'cpf_rf',
					'type'  => 'text',
					'label' => 'CPF / RF',
				),
				array(
					'name'  => 'curso',
					'type'  => 'text',
					'label' => 'Curso',
				),
			)
		);

		$this->assertSame( 1, substr_count( $html, 'ffc-field-row ffc-field-row-required-tag' ) );
		$this->assertStringContainsString( 'Required field', $html );
		$this->assertMatchesRegularExpression( '/name="ffc_fields\[0\]\[name\]" value="cpf_rf"[^>]*readonly/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="ffc_fields\[1\]\[name\]" value="curso"[^>]*readonly/', $html );
		$this->assertSame( 1, substr_count( $html, 'ffc-remove-field" disabled' ) );
	}

	/**
	 * #1741 — a required field takes the configured type and cannot change it.
	 */
	public function test_a_required_field_has_its_type_locked(): void {
		$html = $this->render(
			array(
				array(
					'name'  => 'email',
					'type'  => 'text',
					'label' => 'E-mail',
				),
			)
		);

		$this->assertStringContainsString( '<input type="hidden" class="ffc-field-type-selector" name="ffc_fields[0][type]" value="email">', $html );
		$this->assertStringContainsString( '<span class="ffc-field-title"><strong>Email</strong></span>', $html, 'The configured type titles the row.' );
		$this->assertStringContainsString( 'value="E-mail"', $html, 'The label stays the operator\'s.' );
	}

	/**
	 * #1756 — a row is titled by its type, and no row offers to change it.
	 */
	public function test_rows_are_titled_by_type_and_offer_no_type_select(): void {
		$html = $this->render(
			array(
				array( 'name' => 'course', 'type' => 'radio', 'label' => 'Course' ),
				array( 'type' => 'info', 'label' => '' ),
			)
		);

		$this->assertStringContainsString( '<strong>Radio Buttons</strong>', $html );
		$this->assertStringContainsString( '<strong>Info Block</strong>', $html );
		$this->assertStringNotContainsString( '<strong>Field</strong>', $html );
		$this->assertDoesNotMatchRegularExpression( '/<select[^>]*name="ffc_fields\[\d+\]\[type\]"/', $html );
		$this->assertStringContainsString( 'name="ffc_fields[0][type]" value="radio"', $html );
	}

	/**
	 * #1741 — a form lacking required fields offers to add them.
	 */
	public function test_a_form_missing_required_fields_offers_to_add_them(): void {
		$html = $this->render(
			array(
				array(
					'name'  => 'email',
					'type'  => 'email',
					'label' => 'Email',
				),
			)
		);

		$this->assertStringContainsString( 'ffc-required-fields-missing', $html );
		$this->assertStringContainsString( 'ffc-add-required-fields', $html );
		$this->assertMatchesRegularExpression( '/data-ffc-required-fields="\[\{&quot;name&quot;:&quot;name&quot;|data-ffc-required-fields="\[\{"name":"name"/', $html );
		$this->assertStringNotContainsString( '"name":"email"', $html, 'Only what is missing is offered.' );
	}

	public function test_a_complete_form_shows_no_missing_notice(): void {
		$html = $this->render(
			array(
				array( 'name' => 'name', 'type' => 'text', 'label' => 'Name' ),
				array( 'name' => 'cpf_rf', 'type' => 'text', 'label' => 'CPF' ),
				array( 'name' => 'email', 'type' => 'email', 'label' => 'Email' ),
			)
		);

		$this->assertStringNotContainsString( 'ffc-required-fields-missing', $html );
	}

	/**
	 * #1741 — a new form starts with the configured table, locked.
	 */
	public function test_a_new_form_is_seeded_from_the_required_field_table(): void {
		$html = $this->render( '', 'auto-draft' );

		$this->assertSame( 3, substr_count( $html, 'ffc-field-row ffc-field-row-required-tag' ) );
		$this->assertStringContainsString( 'value="CPF / RF"', $html );
		$this->assertStringNotContainsString( 'ffc-required-fields-missing', $html );
	}
}
