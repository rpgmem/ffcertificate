<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\FormEditorSaveValidator;
use PHPUnit\Framework\TestCase;

/**
 * The fields every certificate form must have (#1734, #1741): which they
 * are, and which a form is missing.
 *
 * @covers \FreeFormCertificate\Admin\FormEditorSaveValidator
 */
class RequiredCertificateFieldsTest extends TestCase {

	/**
	 * The stored `required_certificate_tags`.
	 *
	 * @var string
	 */
	private string $tags = '';

	/**
	 * The stored `required_form_fields`, or null when never saved.
	 *
	 * @var array<int, array<string, string>>|null
	 */
	private ?array $fields = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => 'ffc_settings' === $key
				? array_filter(
					array(
						'required_certificate_tags' => $this->tags,
						'required_form_fields'      => $this->fields,
					),
					static fn( $v ) => null !== $v
				)
				: $default
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_default_table_requires_name_cpf_rf_and_email(): void {
		$this->assertSame( array( 'name', 'cpf_rf', 'email' ), FormEditorSaveValidator::required_field_names() );
	}

	public function test_the_admin_can_drop_name_and_cpf_rf_but_not_email(): void {
		$this->fields = array( array( 'name' => 'course', 'label' => 'Course', 'type' => 'text' ) );
		$this->assertSame( array( 'course', 'email' ), FormEditorSaveValidator::required_field_names() );
		$this->assertFalse( FormEditorSaveValidator::is_required_field( 'name' ) );
	}

	public function test_required_field_returns_the_configured_row(): void {
		$this->fields = array( array( 'name' => 'birth', 'label' => 'Birth date', 'type' => 'date' ) );
		$this->assertSame( array( 'name' => 'birth', 'label' => 'Birth date', 'type' => 'date' ), FormEditorSaveValidator::required_field( 'birth' ) );
		$this->assertNull( FormEditorSaveValidator::required_field( 'other' ) );
	}

	public function test_nome_satisfies_name(): void {
		$this->assertTrue( FormEditorSaveValidator::is_required_field( 'nome' ) );
		$this->assertTrue( FormEditorSaveValidator::is_required_field( 'cpf_rf' ) );
		$this->assertFalse( FormEditorSaveValidator::is_required_field( 'curso' ) );
		$this->assertFalse( FormEditorSaveValidator::is_required_field( '' ) );
	}

	public function test_missing_fields_are_reported_in_required_order(): void {
		$validator = new FormEditorSaveValidator();
		$this->assertSame(
			array( 'name', 'cpf_rf' ),
			$validator->missing_required_fields( array( array( 'type' => 'email', 'name' => 'email' ) ) )
		);
		$this->assertSame(
			array(),
			$validator->missing_required_fields(
				array(
					array( 'type' => 'text', 'name' => 'nome' ),
					array( 'type' => 'text', 'name' => 'cpf_rf' ),
					array( 'type' => 'email', 'name' => 'email' ),
				)
			)
		);
	}

	public function test_an_info_block_named_like_a_tag_does_not_count(): void {
		$validator = new FormEditorSaveValidator();
		$this->assertSame(
			array( 'name' ),
			$validator->missing_required_fields(
				array(
					array( 'type' => 'info', 'name' => 'name' ),
					array( 'type' => 'text', 'name' => 'cpf_rf' ),
					array( 'type' => 'email', 'name' => 'email' ),
				)
			)
		);
	}

	/**
	 * The requirement is the configured list, not the form's Event Schedule:
	 * a form without a time is asked for {{schedule}} when the list names it
	 * (#1741, changed so the configuration alone decides).
	 */
	public function test_the_configured_list_decides_whether_schedule_is_asked(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $key ) => 'ffc_settings' === $key
				? array( 'required_certificate_tags' => "{{auth_code}}\n{{name}}\n{{cpf_rf}}\n{{schedule}}", 'required_form_fields' => array() )
				: array()
		);
		$this->assertSame(
			array( '{{auth_code}}', '{{name}}', '{{cpf_rf}}', '{{schedule}}' ),
			FormEditorSaveValidator::layout_required_tags(),
			'Named in Settings → Certificates: asked of every form, with or without a time.'
		);

		Functions\when( 'get_option' )->alias(
			static fn( $key ) => 'ffc_settings' === $key
				? array( 'required_certificate_tags' => "{{auth_code}}\n{{name}}\n{{cpf_rf}}", 'required_form_fields' => array() )
				: array()
		);
		$this->assertSame(
			array( '{{auth_code}}', '{{name}}', '{{cpf_rf}}' ),
			FormEditorSaveValidator::layout_required_tags(),
			'Not named: not asked.'
		);
	}
}
