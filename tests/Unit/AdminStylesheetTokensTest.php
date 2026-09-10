<?php
/**
 * Guard: an admin stylesheet paints through tokens, and the tokens are on the page (#1126 B).
 *
 * The dark mode is a token swap. A stylesheet that names its colours literally
 * opts out of it entirely, and the symptom is one-sided — the screen looks
 * right in the theme the literals were picked for and wrong in the other, so
 * review never catches it. #1116 fixed one occurrence (the autosave badge);
 * #1126 measured the population and found seven admin stylesheets with **zero**
 * `var(--ffc-*)` between them.
 *
 * Two directions, because tokenizing a sheet is only half the job:
 *
 *  - **A literal in a declaration.** A ratchet: the converted sheets block at
 *    zero, every other sheet carries its measured count as a baseline that can
 *    only shrink. A file that grows literals fails; a file that loses them also
 *    fails, so the win gets locked in.
 *  - **The tokens have to be declared on that screen.** `var(--ffc-bg-card)`
 *    resolves to nothing when `ffc-common.css` is not on the page, and CSS does
 *    **not** fall back to the previous literal — the whole declaration is
 *    invalid at compute time and the element renders with no background at all.
 *    So converting a sheet whose enqueue does not depend on `ffc-common` makes
 *    the screen worse, not better. Four such sites existed when this was
 *    written; the check is what found them.
 *
 * What it does not see: whether the token chosen is the *right* one. A
 * `var(--ffc-danger)` on a success badge passes here — presence, never
 * correctness, the same limit every other ratchet in this repository has. The
 * contrast half lives in `DarkModeCssTest`, which measures the pairs the CSS
 * actually paints.
 *
 * Dependency-free on purpose — no WordPress, no Brain\Monkey. It reads source
 * text only.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class AdminStylesheetTokensTest extends TestCase {

	/**
	 * Colour literals still allowed per stylesheet, by basename.
	 *
	 * A ratchet, not a target. `0` means the sheet is converted and blocks at
	 * zero. Every other number is what was measured when the guard landed, and
	 * it may only go down — lower it in the PR that lowers the real count.
	 *
	 * ⚠ The first version of this comment said the frontend sheets were
	 * "outside the dark mode's reach, since `.ffc-dark-mode` is only ever added
	 * on a plugin admin screen". **That was wrong.**
	 * `AssetHelper::enqueue_dark_mode()` is called from THREE places, and two
	 * of them are public: the `[ffc_form]` / verification / csv-download
	 * shortcodes, and the user-dashboard shortcode. The class lands on `<html>`
	 * there just as it does in wp-admin, so a frontend sheet full of literals
	 * is the same defect on a page a visitor sees — which is what the 6.24.0
	 * smoke reported before anyone re-read this line.
	 *
	 * @var array<string, int>
	 */
	private const BUDGET = array(
		// Every sheet the plugin paints with is converted. A `0` here is the
		// normal state and the guard blocks at it; the handful of non-zero
		// entries below are decisions, each with its reason INLINE in the CSS,
		// not silent debt.
		'ffc-admin-move-submissions.css' => 0,
		'ffc-admin-submission-edit.css' => 0,
		'ffc-admin-submissions.css'     => 0,
		'ffc-admin-utilities.css'       => 0,
		'ffc-appointment-cancellation.css' => 0,
		'ffc-calendar-admin.css'        => 0,
		'ffc-calendar-editor.css'       => 0,
		'ffc-calendar-frontend.css'     => 0,
		'ffc-certificates-dashboard.css' => 0,
		'ffc-custom-fields-admin.css'   => 0,
		'ffc-email-model.css'           => 0,
		'ffc-progress-overlay.css'      => 0,
		'ffc-recruitment-admin.css'     => 0,
		'ffc-recruitment-public.css'    => 0,
		'ffc-reregistration-admin.css'  => 0,
		'ffc-reregistration-frontend.css' => 0,
		'ffc-url-shortener-admin.css'   => 0,
		'ffc-working-hours.css'         => 0,

		// Deliberate literals, reason inline at each site:
		// - a white switch knob that would vanish into a dark track;
		// - translucent veils over whatever colour sits underneath;
		// - the white paper of the certificate preview;
		// - the twelve-hue categorical scale of the capability groups;
		// - `#adminmenu`, which follows the user's own wp-admin colour scheme;
		// - two vendor brand colours and one code-sample theme.
		'ffc-admin.css'                 => 3,
		'ffc-admin-settings.css'        => 5,
		'ffc-audience.css'              => 2,
		'ffc-audience-admin.css'        => 4,
		'ffc-frontend.css'              => 4,
		'ffc-user-dashboard.css'        => 2,
		'ffc-user-permissions.css'      => 12,

		// The palette itself, and the two sheets whose literals ARE the point:
		// a code-editor theme and a print stylesheet.
		'ffc-common.css'                => 117,
		'ffc-code-editor-dark.css'      => 35,
		'ffc-pdf-core.css'              => 12,
	);

	/**
	 * Source stylesheets, by basename.
	 *
	 * @return array<string, string> Basename => absolute path.
	 */
	private static function stylesheets(): array {
		$out = array();

		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/assets/css/*.css' ) as $path ) {
			$path = (string) $path;
			if ( str_ends_with( $path, '.min.css' ) ) {
				continue;
			}
			$out[ basename( $path ) ] = $path;
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Declarations in a stylesheet that name a colour literally.
	 *
	 * Reads **declarations**, never selectors: `#ffc-add-working-hour` is an id
	 * selector and matches a 3-digit hex pattern perfectly, so a scanner over
	 * raw text reports every `#ffc-…` element as a colour. Comments go first
	 * for the same reason — this file's own prose cites `#1126`.
	 *
	 * @param string $path Absolute path.
	 * @return array<int, string> The offending declarations, in file order.
	 */
	private static function literals( string $path ): array {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $path ) );

		if ( ! preg_match_all( '/\{([^{}]*)\}/s', $css, $matches ) ) {
			return array();
		}

		$out = array();
		foreach ( $matches[1] as $body ) {
			foreach ( explode( ';', (string) $body ) as $declaration ) {
				$declaration = trim( $declaration );
				if ( '' === $declaration ) {
					continue;
				}
				if ( preg_match( '/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(/', $declaration ) ) {
					$out[] = $declaration;
				}
			}
		}

		return $out;
	}

	// ==================================================================
	// Direção A — o literal de cor
	// ==================================================================

	public function test_no_stylesheet_exceeds_its_literal_budget(): void {
		$over = array();

		foreach ( self::stylesheets() as $name => $path ) {
			$budget = self::BUDGET[ $name ] ?? null;
			if ( null === $budget ) {
				$over[] = "{$name} is not in BUDGET — add it with its measured count (or 0).";
				continue;
			}

			$found = self::literals( $path );
			if ( count( $found ) > $budget ) {
				$excess = array_slice( $found, $budget );
				$over[] = sprintf(
					"%s: %d literais, orçamento %d. Sobrando:\n      %s",
					$name,
					count( $found ),
					$budget,
					implode( "\n      ", array_slice( $excess, 0, 8 ) )
				);
			}
		}

		$this->assertSame(
			array(),
			$over,
			"Uma folha ganhou cor literal — ela ignora o modo escuro:\n  "
			. implode( "\n  ", $over )
			. "\n\nUse var(--ffc-*). Se a paleta não tiver o papel, crie o token nos DOIS"
			. "\nblocos (claro e escuro), nunca só no escuro."
		);
	}

	public function test_the_literal_budget_is_not_slack(): void {
		$slack = array();

		foreach ( self::stylesheets() as $name => $path ) {
			$budget = self::BUDGET[ $name ] ?? null;
			if ( null === $budget ) {
				continue;
			}

			$found = count( self::literals( $path ) );
			if ( $found < $budget ) {
				$slack[] = "{$name}: {$found} literais, orçamento {$budget} — baixe o orçamento.";
			}
		}

		$this->assertSame(
			array(),
			$slack,
			"Uma folha perdeu literais e o orçamento não acompanhou. A catraca só encolhe:\n  "
			. implode( "\n  ", $slack )
		);
	}

	// ==================================================================
	// Direção B — o token precisa existir na tela
	// ==================================================================

	/**
	 * Every `wp_enqueue_style` / `wp_register_style` call in `includes/`.
	 *
	 * @return array<int, array{file: string, handle: string, args: string}>
	 */
	private static function style_calls(): array {
		$out = array();

		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/includes' ) );
		foreach ( $it as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			$php = (string) file_get_contents( $file->getPathname() );

			// The call's arguments, up to the closing paren of the call. The
			// bodies here never nest a paren-heavy expression past the deps
			// array, so a lazy match to `);` is enough and keeps the guard
			// readable — a full tokenizer would buy nothing.
			if ( ! preg_match_all( '/wp_(?:enqueue|register)_style\(\s*(.*?)\s*\);/s', $php, $matches, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $matches as $match ) {
				$args = (string) $match[1];
				if ( ! preg_match( "/'([a-z0-9_-]+)'/i", $args, $handle ) ) {
					continue;
				}
				$out[] = array(
					'file'   => str_replace( dirname( __DIR__, 2 ) . '/', '', $file->getPathname() ),
					'handle' => $handle[1],
					'args'   => $args,
				);
			}
		}

		return $out;
	}

	/**
	 * A stylesheet that reads a token must be enqueued depending on ffc-common.
	 *
	 * This is the half that is easy to forget and expensive to notice: an
	 * undeclared custom property does not degrade to the old literal, it voids
	 * the declaration. The screen loses the colour entirely, in **both** themes
	 * — strictly worse than the hardcoded hex the conversion replaced.
	 */
	public function test_a_stylesheet_that_reads_tokens_depends_on_ffc_common(): void {
		$reads_tokens = array();
		foreach ( self::stylesheets() as $name => $path ) {
			if ( 'ffc-common.css' === $name ) {
				continue;
			}
			if ( strpos( (string) file_get_contents( $path ), 'var(--ffc-' ) !== false ) {
				$reads_tokens[] = $name;
			}
		}

		$this->assertNotEmpty( $reads_tokens, 'No stylesheet reads a token — the scan collapsed.' );

		$offenders = array();
		foreach ( self::style_calls() as $call ) {
			foreach ( $reads_tokens as $name ) {
				$stem = substr( $name, 0, -4 );
				// The URL argument names the file, with or without the {$s}
				// minified suffix.
				if ( ! preg_match( '#assets/css/' . preg_quote( $stem, '#' ) . '(\{\$\w+\})?\.css#', $call['args'] ) ) {
					continue;
				}
				if ( strpos( $call['args'], "'ffc-common'" ) === false ) {
					$offenders[] = "{$call['file']} → {$call['handle']} ({$name})";
				}
			}
		}

		$this->assertSame(
			array(),
			array_values( array_unique( $offenders ) ),
			"Uma folha que lê var(--ffc-*) é enfileirada sem depender de ffc-common:\n  "
			. implode( "\n  ", array_unique( $offenders ) )
			. "\n\nSem ffc-common na página a propriedade não existe, e uma custom property"
			. "\ninexistente INVALIDA a declaração inteira — não cai para o literal anterior."
		);
	}

	// ==================================================================
	// Direção B2 — o interruptor precisa chegar junto com a paleta
	// ==================================================================

	/**
	 * Methods that put the palette on a screen without enqueueing the toggle,
	 * each because something else does or because the screen is meant to stay
	 * light. The reason is the point of the entry: without it this list becomes
	 * a place to silence the check.
	 *
	 * @var array<string, string>
	 */
	private const TOGGLE_NOT_NEEDED = array(
		// `AdminAssetsManager::enqueue_admin_assets()` enqueues the toggle on
		// every `is_ffc_page()` screen, and these all run on one.
		'includes/admin/class-ffc-admin-activity-log-page.php::enqueue_scripts()' => 'AdminAssetsManager já enfileira o interruptor nesta tela',
		'includes/admin/class-ffc-role-capability-editor.php::enqueue()' => 'idem',
		'includes/self-scheduling/class-ffc-self-scheduling-editor.php::enqueue_scripts()' => 'idem',
		'includes/self-scheduling/class-ffc-self-scheduling-admin.php::enqueue_admin_assets()' => 'idem',
		'includes/url-shortener/class-ffc-url-shortener-admin-page.php::enqueue_assets()' => 'idem',
		'includes/recruitment/class-ffc-recruitment-admin-assets-manager.php::maybe_enqueue()' => 'idem',

		// This IS the method that enqueues the toggle, right below the palette.
		'includes/admin/class-ffc-admin-assets-manager.php::enqueue_admin_base_styles()' => 'a própria origem do interruptor no admin',

		// The helper itself is not a screen: it is called BY the screens above.
		'includes/core/class-ffc-asset-helper.php::enqueue_common_style()' => 'helper, não é uma tela',

		// Deliberately light (#1126): these paint a fragment inside a WordPress
		// core screen that is not `is_ffc_page()` and stays light itself. A dark
		// island inside a light profile page reads as broken, not as a theme.
		'includes/admin/class-ffc-admin-user-custom-fields.php::enqueue_assets()' => 'perfil/usuário: tela do core, clara por decisão',
		'includes/admin/class-ffc-admin-user-capabilities.php::enqueue_scripts()' => 'idem',
		'includes/admin/class-ffc-admin-user-columns.php::enqueue_styles()' => 'users.php: tela do core, clara por decisão',
		'includes/url-shortener/class-ffc-url-shortener-meta-box.php::enqueue_assets()' => 'metabox no editor de post: tela do core, clara por decisão',
	);

	/**
	 * A method that enqueues a token-reading sheet must also enqueue the toggle.
	 *
	 * The palette alone is not the theme. `.ffc-dark-mode` is put on `<html>` by
	 * `AssetHelper::enqueue_dark_mode()`, and without it every `var(--ffc-*)` on
	 * the page resolves to the light block — no matter how thoroughly the sheet
	 * was tokenised. That is a silent failure with the worst possible shape: the
	 * CSS looks converted, the guard above passes, and the screen is simply
	 * always light.
	 *
	 * It was live on three public surfaces at once — the calendar, the audience
	 * and the recruitment shortcodes — and the 6.24.0 smoke reported all three
	 * as "still light" **after** their stylesheets had been fully converted.
	 *
	 * The check is per **method**, not per file: a class may enqueue on several
	 * hooks and only one of them is the screen that carries the palette.
	 *
	 * @return void
	 */
	public function test_a_method_that_enqueues_the_palette_also_enqueues_the_toggle(): void {
		$offenders = array();
		$seen      = 0;
		$allowed   = self::TOGGLE_NOT_NEEDED;

		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/includes' ) );
		foreach ( $it as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			$php  = (string) file_get_contents( $file->getPathname() );
			$rel  = str_replace( dirname( __DIR__, 2 ) . '/', '', $file->getPathname() );
			$body = preg_split( '/\n\t(?:public|protected|private)[^\n]*function\s+(\w+)/', $php, -1, PREG_SPLIT_DELIM_CAPTURE );

			if ( ! is_array( $body ) ) {
				continue;
			}

			for ( $i = 1; $i < count( $body ); $i += 2 ) {
				$method = (string) $body[ $i ];
				$code   = (string) ( $body[ $i + 1 ] ?? '' );

				// Only methods that put ffc-common itself on the page: that is
				// the one sheet whose presence means "this screen is painted by
				// the plugin's palette".
				if ( ! preg_match( "/wp_enqueue_style\(\s*'ffc-common'|enqueue_common_style\(/", $code ) ) {
					continue;
				}

				++$seen;

				$id = "{$rel}::{$method}()";

				if ( isset( $allowed[ $id ] ) ) {
					continue;
				}

				if ( strpos( $code, 'enqueue_dark_mode(' ) === false ) {
					$offenders[] = $id;
				}
			}
		}

		$this->assertGreaterThan( 4, $seen, 'The enqueue-method scan collapsed — no method enqueues the palette?' );

		// Uma entrada da allowlist que deixou de existir vira mentira herdada:
		// o próximo leitor confia nela sem conferir.
		$stale = array();
		foreach ( array_keys( $allowed ) as $entry ) {
			list( $rel_path, $fn ) = explode( '::', $entry, 2 );
			$abs = dirname( __DIR__, 2 ) . '/' . $rel_path;
			if ( ! file_exists( $abs ) || strpos( (string) file_get_contents( $abs ), 'function ' . rtrim( $fn, '()' ) ) === false ) {
				$stale[] = $entry;
			}
		}
		$this->assertSame( array(), $stale, "TOGGLE_NOT_NEEDED lista métodos que não existem mais:\n  " . implode( "\n  ", $stale ) );

		$this->assertSame(
			array(),
			$offenders,
			"Um método põe a paleta na página sem pôr o interruptor do modo escuro:\n  "
			. implode( "\n  ", $offenders )
			. "\n\nSem AssetHelper::enqueue_dark_mode() a classe .ffc-dark-mode nunca chega ao"
			. "\n<html>, e todo var(--ffc-*) resolve pelo bloco claro — a tela fica sempre"
			. "\nclara por mais tokenizada que a folha esteja."
		);
	}

	// ==================================================================
	// Direção C — o token precisa existir
	// ==================================================================

	/**
	 * Custom properties set per element by PHP, not declared in any stylesheet.
	 *
	 * These are data, not palette: the audience colour an operator picked, the
	 * capability-chip hue. They arrive through `style="--ffc-color: …"` on the
	 * element, so no `:root` declares them and none should.
	 *
	 * @var array<string, string>
	 */
	private const INLINE_PROPERTIES = array(
		'--ffc-color'          => 'a cor da audiência/motivo, escrita no style= do elemento',
		'--ffc-cap-chip-color' => 'a cor do chip de capacidade, idem',
	);

	/**
	 * `var(--ffc-x)` must name a property something actually declares.
	 *
	 * This is the half a reader never sees. `var(--ffc-border-medium, #c3c4c7)`
	 * looks like a token with a sensible fallback; the token was never declared,
	 * so the **fallback is what paints** — in both themes, which is precisely
	 * the dark-mode defect the token was supposed to prevent. Seven such names
	 * were live when this landed (`--ffc-bg-subtle`, `--ffc-card-bg`,
	 * `--ffc-warning-dark`, …), one of them inside the very badge #1116 fixed.
	 *
	 * Nothing reports it: the CSS is valid, the page renders, and the colour is
	 * plausible. Only a cross-file comparison sees it.
	 */
	public function test_every_token_read_is_declared_somewhere(): void {
		$declared = array();
		$used     = array();

		foreach ( self::stylesheets() as $name => $path ) {
			$css = (string) file_get_contents( $path );

			if ( preg_match_all( '/(--ffc-[a-z0-9-]+)\s*:/i', $css, $m ) ) {
				foreach ( $m[1] as $token ) {
					$declared[ strtolower( $token ) ] = true;
				}
			}
			if ( preg_match_all( '/var\(\s*(--ffc-[a-z0-9-]+)/i', $css, $m ) ) {
				foreach ( array_unique( $m[1] ) as $token ) {
					$used[ strtolower( $token ) ][] = $name;
				}
			}
		}

		$this->assertGreaterThan( 50, count( $declared ), 'The declaration scan collapsed.' );
		$this->assertGreaterThan( 50, count( $used ), 'The usage scan collapsed.' );

		$orphans = array();
		foreach ( $used as $token => $files ) {
			if ( isset( $declared[ $token ] ) || isset( self::INLINE_PROPERTIES[ $token ] ) ) {
				continue;
			}
			$orphans[] = $token . ' (' . implode( ', ', array_unique( $files ) ) . ')';
		}

		$this->assertSame(
			array(),
			$orphans,
			"var(--ffc-x) nomeia uma propriedade que nada declara — quem pinta é o fallback,"
			. "\nnos DOIS temas, que é exatamente o defeito que o token existe para evitar:\n  "
			. implode( "\n  ", $orphans )
			. "\n\nUse um token declarado, ou declare o novo nos dois blocos de ffc-common.css."
			. "\nSe a propriedade é escrita no style= do elemento, registre em INLINE_PROPERTIES."
		);
	}

	// ==================================================================
	// Autoverificação
	// ==================================================================

	/**
	 * The guard's own floor.
	 *
	 * `assertSame( array(), $offenders )` is satisfied by a scan that found
	 * nothing to look at — the shape #1094 found in four guards at once. Both
	 * scans are pinned here: the stylesheet list, and the enqueue-call list.
	 */
	public function test_neither_scan_can_collapse_in_silence(): void {
		$sheets = self::stylesheets();
		$this->assertGreaterThan( 20, count( $sheets ), 'The stylesheet scan collapsed.' );

		$calls = self::style_calls();
		$this->assertGreaterThan( 20, count( $calls ), 'The wp_enqueue_style scan collapsed.' );
		$this->assertContains( 'ffc-common', array_column( $calls, 'handle' ), 'The enqueue scan does not see ffc-common.' );

		// The literal scanner must actually find literals where they live.
		$this->assertGreaterThan(
			100,
			count( self::literals( $sheets['ffc-common.css'] ) ),
			'The literal scanner does not see the palette — check the declaration regex.'
		);

		// Every INLINE_PROPERTIES entry must still be read by some stylesheet —
		// an allowlist that outlives its usage is a lie the next reader inherits.
		$css_all = '';
		foreach ( $sheets as $path ) {
			$css_all .= (string) file_get_contents( $path );
		}
		foreach ( array_keys( self::INLINE_PROPERTIES ) as $token ) {
			$this->assertStringContainsString(
				'var(' . $token,
				str_replace( ' ', '', $css_all ),
				"INLINE_PROPERTIES lista {$token}, que nenhuma folha lê."
			);
		}

		// …and must not read an id selector as a colour.
		$this->assertSame(
			array(),
			self::literals( $sheets['ffc-calendar-editor.css'] ),
			'#ffc-add-working-hour is a selector, not a colour.'
		);
	}

	public function test_every_budget_entry_names_a_real_stylesheet(): void {
		$known = array_keys( self::stylesheets() );

		foreach ( array_keys( self::BUDGET ) as $name ) {
			$this->assertContains( $name, $known, "BUDGET names {$name}, which does not exist." );
		}
	}
}
