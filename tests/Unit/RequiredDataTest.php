<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\SettingsAjaxEndpoint;
use FreeFormCertificate\Settings\RequiredData;
use FreeFormCertificate\Settings\SettingsReader;
use FreeFormCertificate\Settings\Tabs\TabCertificates;
use PHPUnit\Framework\TestCase;

/**
 * The "Required data" table on Settings → Certificates (#1759): the one-shot
 * migration from the two settings it replaces, the two readers deriving from
 * it, the sanitiser both save paths share, the autosave allowlist entry and
 * the tab's render.
 *
 * @covers \FreeFormCertificate\Settings\RequiredData
 * @covers \FreeFormCertificate\Settings\SettingsReader
 * @covers \FreeFormCertificate\Admin\SettingsAjaxEndpoint
 * @covers \FreeFormCertificate\Settings\Tabs\TabCertificates
 */
class RequiredDataTest extends TestCase {

	/**
	 * In-memory WordPress options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static fn( $key ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) )
		);
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ): bool {
				$this->options[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Store `ffc_settings`.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	private function settings( array $settings ): void {
		$this->options['ffc_settings'] = $settings;
	}

	/**
	 * What the two readers return right now.
	 *
	 * @return array{fields: array<int, array<string, string>>, tags: array<int, string>}
	 */
	private function readings(): array {
		return array(
			'fields' => SettingsReader::required_form_fields(),
			'tags'   => SettingsReader::required_certificate_tags(),
		);
	}

	/**
	 * A stored table row.
	 *
	 * @return array{name: string, label: string, type: string, form: bool, html: bool}
	 */
	private static function row( string $name, string $label, string $type, bool $form, bool $html ): array {
		return array(
			'name'  => $name,
			'label' => $label,
			'type'  => $type,
			'form'  => $form,
			'html'  => $html,
		);
	}

	// ── Migration ────────────────────────────────────────────────────

	public function test_migrates_from_the_field_table_alone(): void {
		$this->settings(
			array(
				'required_form_fields' => array(
					array( 'name' => 'course', 'label' => 'Course', 'type' => 'text' ),
					array( 'name' => 'birth', 'label' => 'Birth date', 'type' => 'date' ),
				),
			)
		);

		$this->assertSame( 7, RequiredData::migrate() );

		$this->assertSame(
			array(
				self::row( 'course', 'Course', 'text', true, false ),
				self::row( 'birth', 'Birth date', 'date', true, false ),
				self::row( 'email', 'Email', 'email', true, false ),
				// The tag list was never saved, so its defaults hold.
				self::row( 'auth_code', 'auth_code', 'text', false, true ),
				self::row( 'name', 'name', 'text', false, true ),
				self::row( 'cpf_rf', 'cpf_rf', 'text', false, true ),
				self::row( 'schedule', 'schedule', 'text', false, true ),
			),
			$this->options['ffc_settings']['required_data']
		);
	}

	public function test_migrates_from_the_tag_list_alone(): void {
		$this->settings( array( 'required_certificate_tags' => "{{auth_code}}\n{{name}}\n{{curso}}" ) );

		RequiredData::migrate();

		$this->assertSame(
			array(
				// The field list was never saved: its defaults, plus the
				// fields the old tag list required, as Text.
				self::row( 'name', 'Full Name', 'text', true, true ),
				self::row( 'cpf_rf', 'CPF / RF', 'text', true, false ),
				self::row( 'email', 'Email', 'email', true, false ),
				self::row( 'curso', 'curso', 'text', true, true ),
				self::row( 'auth_code', 'auth_code', 'text', false, true ),
				// A list saved before #1741 never named {{schedule}}, and the
				// reader kept asking for it; the table keeps that.
				self::row( 'schedule', 'schedule', 'text', false, true ),
			),
			$this->options['ffc_settings']['required_data']
		);
	}

	public function test_migrates_from_both_as_a_union_by_name(): void {
		$this->settings(
			array(
				'required_certificate_tags' => "{{auth_code}}\n{{name}}\n{{CURSO}}\n{{qr_code:size=150}}",
				'required_form_fields'      => array(
					array( 'name' => 'name', 'label' => 'Name', 'type' => 'text' ),
					array( 'name' => 'phone', 'label' => 'Phone', 'type' => 'number' ),
				),
			)
		);

		RequiredData::migrate();

		$this->assertSame(
			array(
				self::row( 'name', 'Name', 'text', true, true ),
				self::row( 'phone', 'Phone', 'number', true, false ),
				self::row( 'email', 'Email', 'email', true, false ),
				self::row( 'auth_code', 'auth_code', 'text', false, true ),
				self::row( 'curso', 'curso', 'text', false, true ),
			),
			$this->options['ffc_settings']['required_data'],
			'A tag that is not a plain {{name}} cannot be a row.'
		);
	}

	public function test_migrates_from_neither_into_the_default_table(): void {
		$this->settings( array( 'quiz_focus_grace_ms' => 700 ) );

		RequiredData::migrate();

		$this->assertSame(
			array(
				self::row( 'name', 'Full Name', 'text', true, true ),
				self::row( 'cpf_rf', 'CPF / RF', 'text', true, true ),
				self::row( 'email', 'Email', 'email', true, false ),
				self::row( 'auth_code', 'auth_code', 'text', false, true ),
				self::row( 'schedule', 'schedule', 'text', false, true ),
			),
			$this->options['ffc_settings']['required_data']
		);
		$this->assertSame( 700, $this->options['ffc_settings']['quiz_focus_grace_ms'], 'The rest of the option survives.' );
		$this->assertSame( '1', $this->options[ RequiredData::MIGRATION_FLAG ] );
	}

	/**
	 * The four starting states the migration meets.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function legacy_states(): array {
		return array(
			'neither'    => array( array() ),
			'tags only'  => array( array( 'required_certificate_tags' => "{{auth_code}}\n{{name}}\n{{curso}}" ) ),
			'table only' => array(
				array( 'required_form_fields' => array( array( 'name' => 'course', 'label' => 'Course', 'type' => 'textarea' ) ) ),
			),
			'both'       => array(
				array(
					'required_certificate_tags' => '{{auth_code}}, {{cpf_rf}}',
					'required_form_fields'      => array(
						array( 'name' => 'cpf_rf', 'label' => 'ID', 'type' => 'text' ),
						array( 'name' => 'email', 'label' => 'Mail', 'type' => 'email' ),
					),
				),
			),
		);
	}

	/**
	 * @dataProvider legacy_states
	 *
	 * @param array<string, mixed> $settings Legacy settings.
	 */
	public function test_the_migration_changes_nothing_the_readers_return( array $settings ): void {
		$this->settings( $settings );
		$before = $this->readings();

		RequiredData::migrate();
		$this->assertNotNull( RequiredData::stored(), 'The table is built.' );
		$after = $this->readings();

		$this->assertSame( $before['fields'], $after['fields'] );
		$this->assertEqualsCanonicalizing( $before['tags'], $after['tags'] );
		$this->assertSame( '{{auth_code}}', $after['tags'][0] );
	}

	public function test_the_migration_runs_once(): void {
		$this->settings( array( 'required_certificate_tags' => '{{auth_code}}' ) );
		RequiredData::migrate();
		$table = $this->options['ffc_settings']['required_data'];

		$this->options['ffc_settings']['required_certificate_tags'] = '{{auth_code}} {{other}}';
		unset( $this->options['ffc_settings']['required_data'] );

		$this->assertSame( 0, RequiredData::migrate(), 'The flag stops a second run.' );
		$this->assertArrayNotHasKey( 'required_data', $this->options['ffc_settings'] );
		$this->assertNotEmpty( $table );
	}

	public function test_the_init_callback_runs_the_migration(): void {
		$this->settings( array() );

		RequiredData::maybe_migrate();

		$this->assertSame( RequiredData::from_legacy(), $this->options['ffc_settings']['required_data'] );
		$this->assertSame( '1', $this->options[ RequiredData::MIGRATION_FLAG ] );
	}

	public function test_the_migration_never_overwrites_a_stored_table(): void {
		$stored = array( self::row( 'name', 'Name', 'text', true, true ) );
		$this->settings(
			array(
				'required_data'             => $stored,
				'required_certificate_tags' => '{{auth_code}} {{other}}',
			)
		);

		$this->assertSame( 0, RequiredData::migrate() );
		$this->assertSame( $stored, $this->options['ffc_settings']['required_data'] );
		$this->assertSame( '1', $this->options[ RequiredData::MIGRATION_FLAG ] );
	}

	// ── Readers ─────────────────────────────────────────────────────

	public function test_the_readers_fall_back_to_the_legacy_keys_before_the_table_exists(): void {
		$this->settings(
			array(
				'required_certificate_tags' => "{{name}}\n{{curso}}",
				'required_form_fields'      => array( array( 'name' => 'curso', 'label' => 'Course', 'type' => 'text' ) ),
			)
		);

		$this->assertSame( array( '{{auth_code}}', '{{name}}', '{{curso}}' ), SettingsReader::required_certificate_tags() );
		$this->assertSame(
			array(
				array( 'name' => 'curso', 'label' => 'Course', 'type' => 'text' ),
				array( 'name' => 'email', 'label' => 'Email', 'type' => 'email' ),
			),
			SettingsReader::required_form_fields()
		);
	}

	public function test_an_empty_stored_table_reads_as_not_built(): void {
		$this->settings(
			array(
				'required_data'             => array(),
				'required_certificate_tags' => '{{auth_code}} {{curso}}',
			)
		);

		$this->assertNull( RequiredData::stored() );
		$this->assertSame( array( '{{auth_code}}', '{{curso}}', '{{schedule}}' ), SettingsReader::required_certificate_tags() );
	}

	public function test_the_readers_derive_from_the_table_once_it_exists(): void {
		$this->settings(
			array(
				'required_data'             => array(
					self::row( 'name', 'Name', 'text', true, true ),
					self::row( 'phone', 'Phone', 'number', true, false ),
					self::row( 'course', 'Course', 'text', false, true ),
					// A generated tag never becomes a field, whatever is stored.
					self::row( 'schedule', 'schedule', 'text', true, true ),
					self::row( 'email', 'Mail', 'text', false, false ),
					self::row( 'auth_code', 'auth_code', 'text', false, false ),
					array( 'name' => 'Not a name!', 'form' => true ),
					'not a row',
				),
				// Ignored once the table exists.
				'required_certificate_tags' => '{{legacy}}',
				'required_form_fields'      => array( array( 'name' => 'legacy', 'label' => 'Legacy', 'type' => 'text' ) ),
			)
		);

		$this->assertSame(
			array(
				array( 'name' => 'name', 'label' => 'Name', 'type' => 'text' ),
				array( 'name' => 'phone', 'label' => 'Phone', 'type' => 'number' ),
				array( 'name' => 'email', 'label' => 'Mail', 'type' => 'email' ),
			),
			SettingsReader::required_form_fields(),
			'Email stays required, as an Email field, with its stored label.'
		);
		$this->assertSame(
			array( '{{auth_code}}', '{{name}}', '{{course}}', '{{schedule}}' ),
			SettingsReader::required_certificate_tags(),
			'{{auth_code}} stays required and comes first.'
		);
	}

	public function test_rows_show_the_stored_table_or_the_one_the_migration_would_build(): void {
		$this->settings( array() );
		$this->assertSame( RequiredData::from_legacy(), RequiredData::rows() );

		$stored = array( self::row( 'name', 'Name', 'text', true, true ) );
		$this->settings( array( 'required_data' => $stored ) );
		$this->assertSame( $stored, RequiredData::rows() );
	}

	// ── Sanitiser ───────────────────────────────────────────────────

	public function test_sanitize_json_refuses_anything_but_a_list_of_rows(): void {
		$this->assertNull( RequiredData::sanitize_json( 'not json' ) );
		$this->assertNull( RequiredData::sanitize_json( '{"name":"x"}' ) );
		$this->assertNull( RequiredData::sanitize_json( '["not a row"]' ) );
		$this->assertNull( RequiredData::sanitize_json( array( array( 'name' => 'x' ) ) ) );
	}

	public function test_sanitize_json_stores_names_as_field_keys_and_applies_the_locks(): void {
		$json = (string) json_encode(
			array(
				array( 'name' => 'Birth-Date', 'label' => '<b>Birth</b>', 'type' => 'date', 'form' => true, 'html' => false ),
				array( 'name' => 'score', 'label' => 'Score', 'type' => 'number', 'form' => true, 'html' => true ),
				array( 'name' => 'email', 'label' => 'Mail', 'type' => 'text', 'form' => false, 'html' => true ),
				array( 'name' => 'auth_code', 'label' => '', 'type' => 'text', 'form' => false, 'html' => false ),
				array( 'name' => '', 'label' => 'Being typed', 'type' => 'text', 'form' => true, 'html' => false ),
				array( 'name' => 'birth_date', 'label' => 'Duplicate', 'type' => 'text', 'form' => false, 'html' => true ),
			)
		);

		$this->assertSame(
			array(
				self::row( 'birth_date', '<b>Birth</b>', 'date', true, false ),
				self::row( 'score', 'Score', 'number', false, true ),
				self::row( 'email', 'Mail', 'email', true, true ),
				self::row( 'auth_code', 'auth_code', 'text', false, true ),
			),
			RequiredData::sanitize_json( $json )
		);
	}

	public function test_sanitize_adds_the_two_locked_rows_when_absent(): void {
		$this->assertSame(
			array(
				self::row( 'email', 'Email', 'email', true, false ),
				self::row( 'auth_code', 'auth_code', 'text', false, true ),
			),
			RequiredData::sanitize( array() )
		);
	}

	// ── Autosave allowlist ──────────────────────────────────────────

	public function test_the_table_is_allowlisted_as_one_structured_field(): void {
		$allowlist = SettingsAjaxEndpoint::allowlist();

		$this->assertArrayHasKey( 'required_data', $allowlist );
		$this->assertSame( 'ffc_settings', $allowlist['required_data']['option'] );
		$this->assertSame( 'table', $allowlist['required_data']['type'] );
		$this->assertSame( 'ffc_manage_settings', $allowlist['required_data']['cap'] );
		$this->assertArrayNotHasKey( 'required_certificate_tags', $allowlist, 'The textarea it replaced is gone.' );
	}

	public function test_the_table_type_runs_the_entry_sanitiser(): void {
		$entry = SettingsAjaxEndpoint::allowlist()['required_data'];

		$this->assertNull( SettingsAjaxEndpoint::sanitize_value( '[1]', 'table', $entry ), 'A refusal, answered with a 400.' );
		$this->assertNull( SettingsAjaxEndpoint::sanitize_value( '[]', 'table', array() ), 'No sanitiser, no write.' );
		$this->assertSame(
			array(
				self::row( 'name', 'Name', 'text', true, true ),
				self::row( 'email', 'Email', 'email', true, false ),
				self::row( 'auth_code', 'auth_code', 'text', false, true ),
			),
			SettingsAjaxEndpoint::sanitize_value( '[{"name":"name","label":"Name","type":"text","form":true,"html":true}]', 'table', $entry )
		);
	}

	// ── Render ──────────────────────────────────────────────────────

	/**
	 * Render the tab with the current options.
	 */
	private function render(): string {
		// Before the tab class loads: SettingsTab requires WordPress's
		// formatting.php when `wp_kses_post` is not defined.
		foreach ( array( 'wp_kses_post', 'esc_html', 'esc_attr', 'esc_html__', 'esc_attr__' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'esc_html_e' )->echoArg();
		Functions\when( 'esc_attr_e' )->echoArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'selected' )->alias(
			static function ( $a, $b ): void {
				echo (string) $a === (string) $b ? 'selected' : '';
			}
		);
		Functions\when( 'disabled' )->alias(
			static function ( $on ): void {
				echo $on ? 'disabled' : '';
			}
		);
		ob_start();
		( new TabCertificates() )->render();
		return (string) ob_get_clean();
	}

	public function test_the_tab_renders_one_table_inside_the_form(): void {
		$this->settings( array() );
		$html = $this->render();

		$this->assertStringContainsString( 'data-ffc-autosave-key="required_data"', $html );
		$this->assertStringNotContainsString( 'required_certificate_tags', $html, 'The textarea is gone.' );
		$this->assertStringNotContainsString( 'ffc_settings[required_form_fields]', $html, 'So is the separate builder.' );
		// No-clobber: every switch is a named field of the tab's form.
		$form = substr( $html, (int) strpos( $html, '<form' ), (int) strpos( $html, '</form>' ) - (int) strpos( $html, '<form' ) );
		$this->assertStringContainsString( 'name="ffc_settings[required_data][0][form]"', $form );
		$this->assertStringContainsString( 'name="ffc_settings[required_data][0][html]"', $form );
		$this->assertStringContainsString( 'name="ffc_settings[required_data][__index__][name]"', $html, 'The template row.' );
	}

	public function test_a_generated_tag_shows_the_html_switch_only_and_the_locks_render_disabled(): void {
		$this->settings( array() );
		$html = $this->render();

		// Row 3 is auth_code, row 4 schedule in the default table.
		$this->assertStringNotContainsString( 'ffc_settings[required_data][3][form]', $html );
		$this->assertStringNotContainsString( 'ffc_settings[required_data][4][form]', $html );
		$this->assertStringContainsString( 'name="ffc_settings[required_data][4][html]"', $html );
		$this->assertMatchesRegularExpression( '/name="ffc_settings\[required_data\]\[3\]\[html\]" value="1" checked disabled/', $html );
		$this->assertMatchesRegularExpression( '/name="ffc_settings\[required_data\]\[2\]\[form\]" value="1" checked disabled/', $html );
		$this->assertSame( 2, substr_count( $html, 'ffc-required-data-generated' ) );
	}
}
