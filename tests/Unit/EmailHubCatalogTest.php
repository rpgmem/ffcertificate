<?php
/**
 * Guard: the e-mail hub's catalogue agrees with the templates it edits (#1143).
 *
 * The hub (Settings → SMTP → Email Model) lists, per e-mail, the `{{token}}`s an
 * administrator may use. That list is help text typed by hand next to a template
 * maintained somewhere else, so it drifts silently — and the drift is invisible
 * from both ends: a token missing from the list still renders, and a token
 * listed but never resolved renders as literal braces in a real e-mail.
 *
 * #1143 is what prompted this. Adding `{{cancel_button}}` to the approval
 * template did not add it to the hub's list, so the shipped default used a token
 * the editor never offered — and an administrator restoring the default would
 * have been looking at a body containing a token the help text denied existed.
 *
 * Three directions, each a different failure:
 *
 *  - **Every catalogued e-mail is allowlisted in `EmailTemplates`.** The hub
 *    saves through `save_global()`, which refuses a name outside the allowlist,
 *    so a catalogue-only entry is an editor that silently cannot save.
 *  - **Every allowlisted e-mail is catalogued.** Otherwise it is editable in
 *    principle and unreachable in practice.
 *  - **Every token a shipped default USES is offered by the hub.** This is the
 *    #1143 direction. The reverse is deliberately NOT checked: the list names
 *    what is *available*, and several e-mails offer tokens their default body
 *    does not happen to use (`{{user_email}}`, `{{site_name}}`, the masked
 *    recruitment fields) precisely so an administrator can add them.
 *
 * What it does not see: whether a token the hub offers actually resolves at
 * send time. That lives in the handlers, in half a dozen idioms — a literal
 * `'{{x}}' =>` map, a `'{{' . $key . '}}'` loop, and the `{{validation_url …}}`
 * link DSL — so a scanner over them reports false negatives, which is worse
 * than no scanner. Verified by hand at #1143: all of them resolve.
 *
 * The two admin notifications (`appointment-admin-notification`,
 * `submission-admin-notification`) are absent from both lists on purpose — they
 * are echo partials rendered through `ffc_render_email_partial()`, not editable
 * defaults, and each file says so in its own docblock.
 *
 * Dependency-free on purpose — no WordPress, no Brain\Monkey. Source text only.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class EmailHubCatalogTest extends TestCase {

	/**
	 * Templates rendered as echo partials rather than editable defaults, with
	 * the reason inline. They belong to neither list by construction.
	 *
	 * @var array<string, string>
	 */
	private const NOT_HUB_EDITABLE = array(
		'appointment-admin-notification' => 'partial de sistema, enviada por AppointmentEmailHandler via ffc_render_email_partial()',
		'submission-admin-notification'  => 'idem, por EmailHandler — notificação ao admin, não texto de usuário',
		'layout'                         => 'a moldura configurável, não um e-mail',
	);

	/**
	 * The hub catalogue: template name => offered token names (no braces).
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function catalogue(): array {
		$src = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/includes/settings/tabs/class-ffc-tab-email-texts.php'
		);
		$at = strpos( $src, 'private static function email_body_hub_catalog' );
		if ( false === $at ) {
			return array();
		}
		$src = substr( $src, $at );

		$out = array();
		if ( preg_match_all( "/'([a-z0-9-]+)'\s*=>\s*array\(\s*'label'.*?'tokens'\s*=>\s*array\(([^)]*)\)/s", $src, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $entry ) {
				preg_match_all( "/'([a-z0-9_]+)'/", $entry[2], $tokens );
				$out[ $entry[1] ] = $tokens[1];
			}
		}

		return $out;
	}

	/**
	 * The `EmailTemplates` allowlist — the names `save_global()` accepts.
	 *
	 * @return array<int, string>
	 */
	private static function allowlist(): array {
		$src = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/includes/core/class-ffc-email-templates.php'
		);
		if ( ! preg_match( '/private const TEMPLATES = array\((.*?)\);/s', $src, $m ) ) {
			return array();
		}
		preg_match_all( "/'([a-z0-9-]+)'/", $m[1], $names );

		return $names[1];
	}

	/**
	 * Tokens a shipped default body actually uses.
	 *
	 * Reads from `return array(` onwards so the file's own docblock — which
	 * cites its tokens in prose — is not mistaken for the body.
	 *
	 * @param string $name Template basename.
	 * @return array<int, string>
	 */
	private static function tokens_used( string $name ): array {
		$path = dirname( __DIR__, 2 ) . '/templates/emails/' . $name . '.php';
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$src = (string) file_get_contents( $path );
		$at  = strpos( $src, 'return array(' );
		if ( false !== $at ) {
			$src = substr( $src, $at );
		}
		preg_match_all( '/\{\{([a-z0-9_]+)\}\}/', $src, $m );

		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * Every shipped template file, minus the ones that are not e-mails.
	 *
	 * @return array<int, string>
	 */
	private static function template_files(): array {
		$out = array();
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/templates/emails/*.php' ) as $path ) {
			$out[] = basename( (string) $path, '.php' );
		}
		sort( $out );

		return $out;
	}

	public function test_the_catalogue_and_the_allowlist_name_the_same_emails(): void {
		$catalogued = array_keys( self::catalogue() );
		$allowlisted = self::allowlist();
		sort( $catalogued );
		sort( $allowlisted );

		$this->assertSame(
			$allowlisted,
			$catalogued,
			'O hub salva via EmailTemplates::save_global(), que recusa nome fora da allowlist: uma entrada só no catálogo é um editor que não salva, e uma só na allowlist é um e-mail editável que ninguém alcança.'
		);
	}

	public function test_every_token_a_shipped_default_uses_is_offered_by_the_hub(): void {
		$catalogue = self::catalogue();
		$missing   = array();

		foreach ( $catalogue as $name => $offered ) {
			foreach ( self::tokens_used( $name ) as $token ) {
				if ( ! in_array( $token, $offered, true ) ) {
					$missing[ $name ][] = $token;
				}
			}
		}

		$this->assertSame(
			array(),
			$missing,
			'O corpo padrão usa um token que o hub não oferece — foi exatamente o {{cancel_button}} do #1143.'
		);
	}

	public function test_every_template_file_is_catalogued_or_named_as_not_editable(): void {
		$catalogue = self::catalogue();

		foreach ( self::template_files() as $name ) {
			if ( isset( self::NOT_HUB_EDITABLE[ $name ] ) ) {
				continue;
			}
			$this->assertArrayHasKey(
				$name,
				$catalogue,
				"templates/emails/{$name}.php não está no hub nem listado como partial de sistema."
			);
		}
	}

	public function test_the_not_editable_list_names_only_files_that_exist(): void {
		$files = self::template_files();

		foreach ( array_keys( self::NOT_HUB_EDITABLE ) as $name ) {
			$this->assertContains(
				$name,
				$files,
				"NOT_HUB_EDITABLE lista {$name}, que não existe — allowlist que sobrevive ao próprio motivo."
			);
		}
	}

	public function test_neither_scan_can_collapse_in_silence(): void {
		$catalogue = self::catalogue();

		$this->assertGreaterThan( 10, count( $catalogue ), 'A varredura do catálogo colapsou.' );
		$this->assertGreaterThan( 10, count( self::allowlist() ), 'A varredura da allowlist colapsou.' );
		$this->assertGreaterThan( 10, count( self::template_files() ), 'A varredura dos arquivos colapsou.' );

		// O leitor de tokens tem de enxergar tokens onde eles vivem…
		$this->assertContains( 'cancel_button', self::tokens_used( 'selfscheduling-confirmation' ) );
		// …e não pode ler a prosa do docblock como corpo: este arquivo cita
		// {{receipt_button}} na documentação e o corpo NÃO o usa.
		$this->assertNotContains( 'receipt_button', self::tokens_used( 'appointment-cancellation' ) );
	}
}
