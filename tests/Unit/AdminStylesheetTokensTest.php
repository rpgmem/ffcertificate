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

	// ==================================================================
	// Direção D — a cor herdada (#1126, quinta rodada)
	// ==================================================================

	/**
	 * The base pair, and the reason it is a rule rather than a habit.
	 *
	 * Text that declares no colour of its own inherits one from OUTSIDE this
	 * repository: in wp-admin from core's `body { color: #3c434a }`, on a public
	 * page from the active theme. Both are near-black, so on a dark ground such
	 * text measures **1,28:1** — which is what the `[ffc_audience]` legend and
	 * the reregistration tab's `<label>Modelo:` were, while every *declared*
	 * pair in those same sheets measured fine. `DarkModeCssTest` cannot see it
	 * by construction: it compares pairs, and here one half is not declared.
	 *
	 * So the palette carries one rule that gives the inherited value a
	 * theme-aware default at each root we own, and this test pins two things
	 * about it: it exists and paints through a token, and every selector in it
	 * still names a wrapper the markup actually renders. The second half is the
	 * staleness check — a root that gets renamed leaves a rule that silently
	 * covers nothing, and the symptom is one screen of grey-on-grey.
	 */
	public function test_the_dark_mode_base_pair_exists_and_uses_a_token(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ffc-common.css' );
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		$this->assertMatchesRegularExpression(
			'/:root\.ffc-dark-mode\s+body\.wp-admin\s*,/',
			$css,
			'A regra de base sumiu do bloco escuro — todo texto sem cor própria volta a herdar o #3c434a do core.'
		);

		$this->assertStringNotContainsString(
			":root.ffc-dark-mode body {",
			$css,
			'`body` sem `.wp-admin` também repinta o texto do TEMA nas páginas públicas, que não é nosso.'
		);

		$this->assertSame(
			'var(--ffc-text)',
			self::base_pair_colour( $css ),
			'A regra de base tem de pintar por token — um literal aqui é o defeito que o bloco existe para corrigir.'
		);
	}

	public function test_the_dark_mode_base_pair_names_only_live_roots(): void {
		$css      = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ffc-common.css' );
		$selector = self::base_pair_selector( (string) preg_replace( '#/\*.*?\*/#s', '', $css ) );

		$roots = array();
		foreach ( explode( ',', $selector ) as $part ) {
			$part = trim( $part );
			if ( preg_match( '/\.(ffc-[\w-]+)$/', $part, $m ) ) {
				$roots[] = $m[1];
			}
		}

		$this->assertGreaterThanOrEqual( 3, count( $roots ), 'A varredura do seletor de base colapsou.' );

		$markup = '';
		foreach ( self::php_and_template_sources() as $path ) {
			$markup .= (string) file_get_contents( $path );
		}
		$this->assertGreaterThan( 500000, strlen( $markup ), 'A varredura da marcação colapsou.' );

		foreach ( $roots as $root ) {
			$this->assertStringContainsString(
				$root,
				$markup,
				"A regra de base nomeia .{$root}, que nenhuma marcação renderiza — ela cobre nada."
			);
		}
	}

	/**
	 * The declaration block of the base-pair rule.
	 *
	 * @param string $css Comment-stripped stylesheet.
	 * @return string The `color` value, or an empty string.
	 */
	private static function base_pair_colour( string $css ): string {
		if ( ! preg_match( '/:root\.ffc-dark-mode\s+body\.wp-admin\s*,[^{}]*\{([^{}]*)\}/s', $css, $m ) ) {
			return '';
		}
		if ( ! preg_match( '/(?<![-\w])color\s*:\s*([^;]+);/', $m[1], $c ) ) {
			return '';
		}

		return trim( $c[1] );
	}

	/**
	 * The selector list of the base-pair rule.
	 *
	 * @param string $css Comment-stripped stylesheet.
	 * @return string
	 */
	private static function base_pair_selector( string $css ): string {
		if ( ! preg_match( '/(:root\.ffc-dark-mode\s+body\.wp-admin\s*,[^{}]*)\{/s', $css, $m ) ) {
			return '';
		}

		return $m[1];
	}

	/**
	 * Every PHP source and template that can render markup.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private static function php_and_template_sources(): array {
		$root = dirname( __DIR__, 2 );
		$out  = array();

		foreach ( array( $root . '/includes', $root . '/templates' ) as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );
			foreach ( $it as $file ) {
				if ( $file->isFile() && 'php' === $file->getExtension() ) {
					$out[] = $file->getPathname();
				}
			}
		}

		return $out;
	}

	// ==================================================================
	// Direção E — o controle de formulário, que não herda (#1126)
	// ==================================================================

	/**
	 * Rules that paint a control's ground but leave its text to the browser.
	 *
	 * A `<button>`, `<input>`, `<select>` or `<textarea>` does NOT inherit
	 * `color`: the user agent gives it `buttontext` / `fieldtext`, which is
	 * near-black. So the base pair of Direction D cannot reach it — a control
	 * given one of our dark grounds renders near-black text on it no matter what
	 * any ancestor says. Measured on the audience calendar's month arrows and
	 * "Hoje" button, the two the smoke reported; the same scan found sixteen
	 * more, including every field of the public reregistration form and of the
	 * profile editor, unreported only because nobody had those screens open.
	 *
	 * This one IS statically decidable, which the inherited-colour class is not:
	 * the rule either declares `color` next to its `background` or it does not.
	 *
	 * @return array<string, list<string>> Stylesheet basename => offending selectors.
	 */
	private static function controls_without_a_text_colour(): array {
		$colour = '/(?<![-\w])color\s*:/';
		// A ground that is actually painted — `transparent` and `none` are not.
		$ground = '/(?<![-\w])background(-color)?\s*:\s*(?!none|transparent|0 0|url)/';
		// Text-bearing controls only. A toggle track, a day cell and a
		// `::before` glyph carry no text, so the rule does not apply to them.
		$control = '/(?:^|\s|>)(?:button|textarea|select|input(?:\[[^\]]*\])?|\.[\w-]*(?:btn|input)[\w-]*)$/i';
		// A state modifier inherits the colour of the base rule it overrides,
		// so re-declaring it there would be noise, not safety.
		$modifier = '/::|:disabled|:checked|:hover|:focus|:active/';

		$out = array();
		foreach ( self::stylesheets() as $name => $path ) {
			$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $path ) );
			if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', $css, $m, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $m as $rule ) {
				$selector = trim( $rule[1] );
				$body     = $rule[2];
				if ( str_starts_with( $selector, '@' ) || preg_match( $modifier, $selector ) ) {
					continue;
				}
				if ( ! preg_match( $ground, $body ) || preg_match( $colour, $body ) ) {
					continue;
				}
				foreach ( explode( ',', $selector ) as $part ) {
					$part = trim( $part );
					if ( '' !== $part && preg_match( $control, $part ) ) {
						$out[ $name ][] = $part;
					}
				}
			}
		}

		return $out;
	}

	public function test_a_control_given_a_ground_also_declares_its_text_colour(): void {
		$this->assertSame(
			array(),
			self::controls_without_a_text_colour(),
			'Um controle de formulário não herda `color`: quem pinta o fundo tem de pintar o texto, ou o navegador usa o quase-preto dele.'
		);
	}

	/**
	 * The scan has to be able to see the shape it forbids, or an empty result
	 * means nothing — the #1071 / #1094 lesson, applied to a new scanner.
	 *
	 * @return void
	 */
	public function test_the_control_scan_can_see_the_shape_it_forbids(): void {
		$offenders = self::detect_controls( ".x button { background: var(--ffc-bg-card); }" );
		$this->assertSame( array( 'x.css' => array( '.x button' ) ), $offenders );

		// …and does not report the two shapes that are correct by construction.
		$this->assertSame(
			array(),
			self::detect_controls( ".x button { color: var(--ffc-text); background: var(--ffc-bg-card); }" ),
			'Uma regra que declara as duas metades está certa.'
		);
		$this->assertSame(
			array(),
			self::detect_controls( ".x button:disabled { background: var(--ffc-bg-alt); }" ),
			'Um modificador de estado herda a cor da regra base que ele sobrescreve.'
		);
		$this->assertSame(
			array(),
			self::detect_controls( ".x .ffc-toggle-track { background: var(--ffc-bg-alt); }" ),
			'Uma pista de interruptor não carrega texto.'
		);
	}

	/**
	 * Run the control scan over one stylesheet's worth of CSS text.
	 *
	 * @param string $css Stylesheet source.
	 * @return array<string, list<string>>
	 */
	private static function detect_controls( string $css ): array {
		$dir  = sys_get_temp_dir() . '/ffc-control-scan-' . uniqid( '', true );
		mkdir( $dir );
		file_put_contents( $dir . '/x.css', $css );

		$colour   = '/(?<![-\w])color\s*:/';
		$ground   = '/(?<![-\w])background(-color)?\s*:\s*(?!none|transparent|0 0|url)/';
		$control  = '/(?:^|\s|>)(?:button|textarea|select|input(?:\[[^\]]*\])?|\.[\w-]*(?:btn|input)[\w-]*)$/i';
		$modifier = '/::|:disabled|:checked|:hover|:focus|:active/';

		$out = array();
		$src = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', $src, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $rule ) {
				$selector = trim( $rule[1] );
				if ( str_starts_with( $selector, '@' ) || preg_match( $modifier, $selector ) ) {
					continue;
				}
				if ( ! preg_match( $ground, $rule[2] ) || preg_match( $colour, $rule[2] ) ) {
					continue;
				}
				foreach ( explode( ',', $selector ) as $part ) {
					$part = trim( $part );
					if ( '' !== $part && preg_match( $control, $part ) ) {
						$out['x.css'][] = $part;
					}
				}
			}
		}

		unlink( $dir . '/x.css' );
		rmdir( $dir );

		return $out;
	}

	/**
	 * The notice's text nodes are named, and painted through a token.
	 *
	 * The rule above them paints the box and every child inherits it — which is
	 * how it reads in isolation, and how it measured at 13:1 in a Chromium
	 * loaded with WordPress's real admin CSS. On a live install with other
	 * plugins it did not hold: something declares a colour on those nodes
	 * directly, and ANY direct declaration beats an inherited value however
	 * specific its source. Naming the children at 0,3,1 is what fixed it, and
	 * the fix was confirmed on the real install.
	 *
	 * The rule is therefore load-bearing and invisible: delete these five
	 * selectors and nothing else fails, because the box rule still measures
	 * fine and the pair meter compares declared pairs. Same shape as the base
	 * pair of Direction D, and the same reason for pinning it.
	 *
	 * What it does not see — deliberately — is WHICH rule was losing. That was
	 * never identified: the scan ruled out all 28 of our stylesheets and the
	 * five core files that could plausibly carry it. A guard cannot assert
	 * against a rule nobody can name; it can assert that our answer to it is
	 * still here.
	 */
	public function test_the_notice_text_nodes_are_named_and_use_a_token(): void {
		$css = (string) preg_replace(
			'#/\*.*?\*/#s',
			'',
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ffc-common.css' )
		);

		if ( ! preg_match( '/(:root\.ffc-dark-mode\s+\.notice\s+p\s*,[^{}]*)\{([^{}]*)\}/s', $css, $m ) ) {
			$this->fail( 'A regra que nomeia os nós de texto da tarja sumiu — sem ela o texto volta a herdar, e nada mais falha.' );
		}

		foreach ( array( '.notice p', '.notice li', '.notice strong' ) as $needed ) {
			$this->assertStringContainsString(
				$needed,
				$m[1],
				"A regra deixou de cobrir `{$needed}`."
			);
		}

		$this->assertMatchesRegularExpression(
			'/(?<![-\w])color\s*:\s*var\(--ffc-[\w-]+\)/',
			$m[2],
			'A tarja tem de pintar por token — literal aqui é o defeito que o bloco existe para corrigir.'
		);

		// E nunca com `!important`: responder `!important` com `!important`
		// começa uma guerra que a próxima folha de terceiro ganha (#1141).
		$this->assertStringNotContainsString(
			'!important',
			$m[2],
			'Escalar para `!important` aqui é uma guerra que a próxima folha ganha.'
		);
	}

	/**
	 * The shared modal keeps its `.ffc-shortcode` scope, and it is load-bearing.
	 *
	 * There is a second `.ffc-modal` in `ffc-reregistration-admin.css`, written
	 * WITHOUT a scope prefix. It looks like a duplicate and is not: one centres
	 * by flex at 500px and scrolls the content, the other positions by
	 * `margin-top` at 820px and scrolls the body, with an `h2` title and a
	 * filled header. Unifying them would redesign a screen, not refactor one.
	 *
	 * `ffc-common` is a DECLARED dependency of `ffc-reregistration-admin`, so
	 * both sheets load on that screen. The prefix is the only thing keeping the
	 * shared rules off the admin modal — drop it "to tidy up" and the admin
	 * screen inherits a 500px flex-centred box it was never built for. Nothing
	 * else would fail: the literal ratchet, the pair meter and the dependency
	 * direction all stay green, because every rule involved is still tokenised
	 * and still declared.
	 *
	 * @return void
	 */
	public function test_the_shared_modal_keeps_its_scope_prefix(): void {
		$css = (string) preg_replace(
			'#/\*.*?\*/#s',
			'',
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/ffc-common.css' )
		);

		$unscoped = array();
		if ( preg_match_all( '/^([^{}\n]*\.ffc-modal[\w-]*[^{}\n]*)\{/m', $css, $m ) ) {
			foreach ( $m[1] as $selector ) {
				foreach ( explode( ',', $selector ) as $part ) {
					$part = trim( $part );
					if ( '' !== $part && ! str_contains( $part, '.ffc-shortcode' ) ) {
						$unscoped[] = $part;
					}
				}
			}
		}

		$this->assertNotSame( array(), $m[1] ?? array(), 'A varredura do modal colapsou.' );
		$this->assertSame(
			array(),
			$unscoped,
			'Regra de modal sem `.ffc-shortcode` na paleta: ela alcançaria o modal do admin de recadastramento, que é outro componente.'
		);
	}


	public function test_every_budget_entry_names_a_real_stylesheet(): void {
		$known = array_keys( self::stylesheets() );

		foreach ( array_keys( self::BUDGET ) as $name ) {
			$this->assertContains( $name, $known, "BUDGET names {$name}, which does not exist." );
		}
	}
}
