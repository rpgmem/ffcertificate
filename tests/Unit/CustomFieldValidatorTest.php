<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\CustomFieldValidator;

/**
 * Tests for CustomFieldValidator: required checks, type validation,
 * format validation (CPF, email, phone, regex), and complex types
 * (working_hours, dependent_select).
 */
class CustomFieldValidatorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
            return $thing instanceof \WP_Error;
        });
        Functions\when( 'FreeFormCertificate\Reregistration\is_wp_error' )->alias( function ( $thing ) {
            return $thing instanceof \WP_Error;
        });
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helper: build a field object
    // ------------------------------------------------------------------

    private function make_field( array $overrides = [] ): object {
        return (object) array_merge(
            array(
                'field_label'      => 'Test Field',
                'field_type'       => 'text',
                'is_required'      => 0,
                'field_options'    => null,
                'validation_rules' => null,
            ),
            $overrides
        );
    }

    // ==================================================================
    // is_empty_value()
    // ==================================================================

    public function test_is_empty_value_null(): void {
        $this->assertTrue( CustomFieldValidator::is_empty_value( null ) );
    }

    public function test_is_empty_value_empty_string(): void {
        $this->assertTrue( CustomFieldValidator::is_empty_value( '' ) );
    }

    public function test_is_empty_value_whitespace_string(): void {
        $this->assertTrue( CustomFieldValidator::is_empty_value( '   ' ) );
    }

    public function test_is_empty_value_empty_array(): void {
        $this->assertTrue( CustomFieldValidator::is_empty_value( array() ) );
    }

    public function test_is_empty_value_json_empty_array(): void {
        $this->assertTrue( CustomFieldValidator::is_empty_value( '[]' ) );
    }

    public function test_is_empty_value_non_empty_string(): void {
        $this->assertFalse( CustomFieldValidator::is_empty_value( 'hello' ) );
    }

    public function test_is_empty_value_zero_string(): void {
        $this->assertFalse( CustomFieldValidator::is_empty_value( '0' ) );
    }

    public function test_is_empty_value_non_empty_array(): void {
        $this->assertFalse( CustomFieldValidator::is_empty_value( array( 'a' ) ) );
    }

    // ==================================================================
    // is_valid_date()
    // ==================================================================

    public function test_is_valid_date_valid(): void {
        $this->assertTrue( CustomFieldValidator::is_valid_date( '2026-03-15' ) );
    }

    public function test_is_valid_date_invalid_format(): void {
        $this->assertFalse( CustomFieldValidator::is_valid_date( '15/03/2026' ) );
    }

    public function test_is_valid_date_invalid_day(): void {
        $this->assertFalse( CustomFieldValidator::is_valid_date( '2026-02-30' ) );
    }

    public function test_is_valid_date_not_a_date(): void {
        $this->assertFalse( CustomFieldValidator::is_valid_date( 'not-a-date' ) );
    }

    // ==================================================================
    // validate() — required field
    // ==================================================================

    public function test_validate_required_empty_returns_error(): void {
        $field = $this->make_field( array( 'is_required' => 1 ) );
        $result = CustomFieldValidator::validate( $field, '' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'field_required', $result->get_error_code() );
    }

    public function test_validate_required_with_value_returns_true(): void {
        $field = $this->make_field( array( 'is_required' => 1 ) );
        $result = CustomFieldValidator::validate( $field, 'hello' );

        $this->assertTrue( $result );
    }

    public function test_validate_optional_empty_returns_true(): void {
        $field = $this->make_field( array( 'is_required' => 0 ) );
        $result = CustomFieldValidator::validate( $field, '' );

        $this->assertTrue( $result );
    }

    // ==================================================================
    // validate() — number type
    // ==================================================================

    public function test_validate_number_valid(): void {
        $field = $this->make_field( array( 'field_type' => 'number' ) );
        $this->assertTrue( CustomFieldValidator::validate( $field, '42' ) );
    }

    public function test_validate_number_decimal(): void {
        $field = $this->make_field( array( 'field_type' => 'number' ) );
        $this->assertTrue( CustomFieldValidator::validate( $field, '3.14' ) );
    }

    public function test_validate_number_invalid(): void {
        $field = $this->make_field( array( 'field_type' => 'number' ) );
        $result = CustomFieldValidator::validate( $field, 'abc' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'field_invalid_number', $result->get_error_code() );
    }

    // ==================================================================
    // validate() — date type
    // ==================================================================

    public function test_validate_date_valid(): void {
        $field = $this->make_field( array( 'field_type' => 'date' ) );
        $this->assertTrue( CustomFieldValidator::validate( $field, '2026-01-15' ) );
    }

    public function test_validate_date_invalid(): void {
        $field = $this->make_field( array( 'field_type' => 'date' ) );
        $result = CustomFieldValidator::validate( $field, '15/01/2026' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'field_invalid_date', $result->get_error_code() );
    }

    // ==================================================================
    // validate() — select type
    // ==================================================================

    public function test_validate_select_valid_choice(): void {
        $field = $this->make_field( array(
            'field_type'    => 'select',
            'field_options' => array( 'choices' => array( 'opt1', 'opt2', 'opt3' ) ),
        ));

        $this->assertTrue( CustomFieldValidator::validate( $field, 'opt2' ) );
    }

    public function test_validate_select_invalid_choice(): void {
        $field = $this->make_field( array(
            'field_type'    => 'select',
            'field_options' => array( 'choices' => array( 'opt1', 'opt2' ) ),
        ));

        $result = CustomFieldValidator::validate( $field, 'opt999' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'field_invalid_option', $result->get_error_code() );
    }

    public function test_validate_select_empty_options_passes(): void {
        $field = $this->make_field( array(
            'field_type'    => 'select',
            'field_options' => array( 'choices' => array() ),
        ));

        $this->assertTrue( CustomFieldValidator::validate( $field, 'anything' ) );
    }

    // ==================================================================
    // validate() — dependent_select type
    // ==================================================================

    public function test_validate_dependent_select_valid(): void {
        $field = $this->make_field( array(
            'field_type'    => 'dependent_select',
            'field_options' => array(
                'groups' => array(
                    'Division A' => array( 'Dept 1', 'Dept 2' ),
                    'Division B' => array( 'Dept 3' ),
                ),
            ),
        ));

        $value = wp_json_encode( array( 'parent' => 'Division A', 'child' => 'Dept 2' ) );
        $this->assertTrue( CustomFieldValidator::validate( $field, $value ) );
    }

    public function test_validate_dependent_select_invalid_parent(): void {
        $field = $this->make_field( array(
            'field_type'    => 'dependent_select',
            'field_options' => array(
                'groups' => array(
                    'Division A' => array( 'Dept 1' ),
                ),
            ),
        ));

        $value = wp_json_encode( array( 'parent' => 'No Such Division', 'child' => 'Dept 1' ) );
        $result = CustomFieldValidator::validate( $field, $value );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'field_invalid_dependent_select', $result->get_error_code() );
    }

    public function test_validate_dependent_select_invalid_child(): void {
        $field = $this->make_field( array(
            'field_type'    => 'dependent_select',
            'field_options' => array(
                'groups' => array(
                    'Division A' => array( 'Dept 1' ),
                ),
            ),
        ));

        $value = wp_json_encode( array( 'parent' => 'Division A', 'child' => 'No Such Dept' ) );
        $result = CustomFieldValidator::validate( $field, $value );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'field_invalid_dependent_select', $result->get_error_code() );
    }

    public function test_validate_dependent_select_missing_keys(): void {
        $field = $this->make_field( array(
            'field_type'    => 'dependent_select',
            'field_options' => array( 'groups' => array() ),
        ));

        $result = CustomFieldValidator::validate( $field, '{"only_parent": "x"}' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'field_invalid_dependent_select', $result->get_error_code() );
    }

    // ==================================================================
    // validate() — working_hours type
    // ==================================================================

    public function test_validate_working_hours_valid(): void {
        $field = $this->make_field( array( 'field_type' => 'working_hours' ) );

        $value = wp_json_encode( array(
            array( 'day' => 1, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00' ),
            array( 'day' => 2, 'entry1' => '08:00', 'exit2' => '17:00' ),
        ));

        $this->assertTrue( CustomFieldValidator::validate( $field, $value ) );
    }

    public function test_validate_working_hours_invalid_day(): void {
        $field = $this->make_field( array( 'field_type' => 'working_hours' ) );

        $value = wp_json_encode( array(
            array( 'day' => 9, 'entry1' => '08:00', 'exit2' => '17:00' ),
        ));

        $result = CustomFieldValidator::validate( $field, $value );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_validate_working_hours_missing_entry1(): void {
        $field = $this->make_field( array( 'field_type' => 'working_hours' ) );

        $value = wp_json_encode( array(
            array( 'day' => 1, 'exit2' => '17:00' ),
        ));

        $result = CustomFieldValidator::validate( $field, $value );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_validate_working_hours_missing_exit2(): void {
        $field = $this->make_field( array( 'field_type' => 'working_hours' ) );

        $value = wp_json_encode( array(
            array( 'day' => 1, 'entry1' => '08:00' ),
        ));

        $result = CustomFieldValidator::validate( $field, $value );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_validate_working_hours_invalid_time_format(): void {
        $field = $this->make_field( array( 'field_type' => 'working_hours' ) );

        $value = wp_json_encode( array(
            array( 'day' => 1, 'entry1' => '8am', 'exit2' => '17:00' ),
        ));

        $result = CustomFieldValidator::validate( $field, $value );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_validate_working_hours_not_array(): void {
        $field = $this->make_field( array( 'field_type' => 'working_hours' ) );

        $result = CustomFieldValidator::validate( $field, 'not-json' );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_validate_working_hours_bad_exit1_format(): void {
        $field = $this->make_field( array( 'field_type' => 'working_hours' ) );

        $value = wp_json_encode( array(
            array( 'day' => 0, 'entry1' => '08:00', 'exit1' => 'noon', 'entry2' => '13:00', 'exit2' => '17:00' ),
        ));

        $result = CustomFieldValidator::validate( $field, $value );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_validate_working_hours_bad_entry2_format(): void {
        $field = $this->make_field( array( 'field_type' => 'working_hours' ) );

        $value = wp_json_encode( array(
            array( 'day' => 0, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => 'afternoon', 'exit2' => '17:00' ),
        ));

        $result = CustomFieldValidator::validate( $field, $value );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    // ==================================================================
    // validate_format — min/max length
    // ==================================================================

    public function test_validate_min_length_passes(): void {
        $field = $this->make_field( array(
            'validation_rules' => array( 'min_length' => 3 ),
        ));

        $this->assertTrue( CustomFieldValidator::validate( $field, 'abc' ) );
    }

    public function test_validate_min_length_fails(): void {
        $field = $this->make_field( array(
            'validation_rules' => array( 'min_length' => 5 ),
        ));

        $result = CustomFieldValidator::validate( $field, 'ab' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'field_too_short', $result->get_error_code() );
    }

    public function test_validate_max_length_passes(): void {
        $field = $this->make_field( array(
            'validation_rules' => array( 'max_length' => 10 ),
        ));

        $this->assertTrue( CustomFieldValidator::validate( $field, 'short' ) );
    }

    public function test_validate_max_length_fails(): void {
        $field = $this->make_field( array(
            'validation_rules' => array( 'max_length' => 3 ),
        ));

        $result = CustomFieldValidator::validate( $field, 'toolong' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'field_too_long', $result->get_error_code() );
    }

    // ==================================================================
    // validate_format — CPF format
    // ==================================================================

    public function test_validate_cpf_valid(): void {
        $field = $this->make_field( array(
            'validation_rules' => array( 'format' => 'cpf' ),
        ));

        $this->assertTrue( CustomFieldValidator::validate( $field, '529.982.247-25' ) );
    }

    public function test_validate_cpf_invalid(): void {
        $field = $this->make_field( array(
            'validation_rules' => array( 'format' => 'cpf' ),
        ));

        $result = CustomFieldValidator::validate( $field, '000.000.000-00' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'field_invalid_cpf', $result->get_error_code() );
    }

    // ==================================================================
    // validate_format — email format
    // ==================================================================

    public function test_validate_email_valid(): void {
        Functions\when( 'is_email' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Reregistration\is_email' )->justReturn( true );

        $field = $this->make_field( array(
            'validation_rules' => array( 'format' => 'email' ),
        ));

        $this->assertTrue( CustomFieldValidator::validate( $field, 'user@example.com' ) );
    }

    public function test_validate_email_invalid(): void {
        Functions\when( 'is_email' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Reregistration\is_email' )->justReturn( false );

        $field = $this->make_field( array(
            'validation_rules' => array( 'format' => 'email' ),
        ));

        $result = CustomFieldValidator::validate( $field, 'not-an-email' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'field_invalid_email', $result->get_error_code() );
    }

    // ==================================================================
    // validate_format — phone format
    // ==================================================================

    public function test_validate_phone_valid(): void {
        $field = $this->make_field( array(
            'validation_rules' => array( 'format' => 'phone' ),
        ));

        $this->assertTrue( CustomFieldValidator::validate( $field, '(11) 99999-8888' ) );
    }

    public function test_validate_phone_invalid(): void {
        $field = $this->make_field( array(
            'validation_rules' => array( 'format' => 'phone' ),
        ));

        $result = CustomFieldValidator::validate( $field, 'abc' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'field_invalid_phone', $result->get_error_code() );
    }

    // ==================================================================
    // validate_format — custom_regex
    // ==================================================================

    public function test_validate_custom_regex_passes(): void {
        $field = $this->make_field( array(
            'validation_rules' => array(
                'format'       => 'custom_regex',
                'custom_regex' => '/^[A-Z]{3}$/',
            ),
        ));

        $this->assertTrue( CustomFieldValidator::validate( $field, 'ABC' ) );
    }

    public function test_validate_custom_regex_fails(): void {
        $field = $this->make_field( array(
            'validation_rules' => array(
                'format'       => 'custom_regex',
                'custom_regex' => '/^[A-Z]{3}$/',
            ),
        ));

        $result = CustomFieldValidator::validate( $field, 'abc' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'field_invalid_format', $result->get_error_code() );
    }

    public function test_validate_custom_regex_auto_wraps_delimiters(): void {
        $field = $this->make_field( array(
            'validation_rules' => array(
                'format'       => 'custom_regex',
                'custom_regex' => '^\d{4}$',
            ),
        ));

        $this->assertTrue( CustomFieldValidator::validate( $field, '1234' ) );

        $result = CustomFieldValidator::validate( $field, 'abcd' );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_validate_custom_regex_uses_custom_message(): void {
        $field = $this->make_field( array(
            'validation_rules' => array(
                'format'               => 'custom_regex',
                'custom_regex'         => '/^X$/',
                'custom_regex_message' => 'Must be X!',
            ),
        ));

        $result = CustomFieldValidator::validate( $field, 'Y' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'Must be X!', $result->get_error_message() );
    }

    public function test_validate_custom_regex_invalid_pattern_skips(): void {
        $field = $this->make_field( array(
            'validation_rules' => array(
                'format'       => 'custom_regex',
                'custom_regex' => '/[invalid',
            ),
        ));

        $this->assertTrue( CustomFieldValidator::validate( $field, 'anything' ) );
    }

    // ==================================================================
    // validate() — text type with no rules (passthrough)
    // ==================================================================

    public function test_validate_text_no_rules_passes(): void {
        $field = $this->make_field( array( 'field_type' => 'text' ) );
        $this->assertTrue( CustomFieldValidator::validate( $field, 'any value' ) );
    }
}
