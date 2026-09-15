<?php
/**
 * Two sheets declaring the same class must agree on which one wins.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\CssSelectors;
use PHPUnit\Framework\TestCase;

/**
 * Component-ownership guard (#1162, a sub-issue of #1148).
 *
 * When two sheets declare `.ffc-x` **bare** — no ancestor, no second class — and
 * both load on the same screen, the winner is the order in which WordPress
 * prints the `<link>` tags. That order is deterministic **only** when one
 * declares the other as a dependency in `wp_enqueue_style`; without the edge it
 * is enqueue order, which in turn is the order the modules are wired in
 * `Loader`. Moving two bootstrap lines repaints a screen.
 *
 * The guard measures exactly that: a class declared bare in two sheets **with no
 * dependency edge between them**, in either direction, direct or transitive.
 * Pairs that cannot coexist on one screen (admin × frontend, distinct admin
 * screens) go in `ALLOWED` with the reason — that impossibility is a property of
 * the enqueue *gates*, which this scan does not read.
 *
 * **This was not prevention: the defect was live.** `.ffc-status-cancelled` was
 * declared by `ffc-calendar-admin.css` (red, `--ffc-danger-*`) and by
 * `ffc-audience-admin.css` (amber, `--ffc-warning-*`). On the Appointments
 * screen both load — Appointments is a submenu of `ffc-scheduling`, so the
 * audience sheet's gate (`strpos( $hook, 'ffc-scheduling' )`) matches there too
 * — and since `AudienceLoader` is wired after `SelfSchedulingLoader`, amber won.
 * The result: in the Status column, "Cancelled" rendered amber while its four
 * siblings rendered as the module's own sheet asked. Both families were given
 * their own names (`ffc-appointment-status-*` and `ffc-audience-status-*`), in
 * the #1151 / #1154 mould.
 *
 * Three things the measurement taught, none of them guessable:
 *
 * 1. **`ffc-admin-submissions.css` loads on EVERY `?page=ffc-*` screen**, not
 *    only the submissions one: its gate is `is_ffc_page()`, which matches any
 *    `ffc-` menu. The enqueuer's own docblock says "submissions page". That is
 *    why its `.ffc-status-badge` was the accidental base of the recruitment and
 *    reregistration badges -- both were given their own names in #1183 and the
 *    baseline went EMPTY. The sheet still loads on every `ffc-*` screen, so the
 *    gate remains the mechanism to watch.
 * 2. **The result is not "the lower one wins": it is a MERGE.** The audience
 *    badge was rendering `text-transform: uppercase` and `letter-spacing` that
 *    only the submissions sheet declares, and `white-space: nowrap` that only
 *    `ffc-common.css` declares. None of the three properties was written in the
 *    component's own sheet. Neither "last wins" nor "first applies" describes
 *    that.
 * 3. **One handle can be enqueued with different dependency lists at different
 *    sites** (`ffc-admin-settings` is one), and WordPress keeps whichever
 *    registered first. The scan unions the lists deliberately: what matters to
 *    this guard is whether the edge is *declared anywhere*, not which site won
 *    the race.
 *
 * What it does NOT see: whether the two sheets genuinely coexist on a screen
 * (that is the gates), inline CSS printed from PHP, and collisions through a
 * compound selector — only the bare declaration, which is the form that reaches
 * any component.
 */
class StylesheetOwnershipTest extends TestCase {

	/**
	 * Pairs that stay, because they cannot coexist on one screen.
	 *
	 * Key: `class|sheetA|sheetB` (sheets in alphabetical order).
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = array(
		// `ffc-admin.css` is admin (`users.php` and `?page=ffc-*` screens);
		// `ffc-frontend.css` only ships on the frontend. The certificate preview
		// component is the same on both sides, and is a candidate for a shared
		// sheet -- but that is an architecture decision, not an ordering one.
		'ffc-preview-backdrop|ffc-admin.css|ffc-frontend.css'  => 'admin × frontend: they never coexist on one screen',
		'ffc-preview-container|ffc-admin.css|ffc-frontend.css' => 'admin × frontend: they never coexist on one screen',
		'ffc-preview-stage|ffc-admin.css|ffc-frontend.css'     => 'admin × frontend: they never coexist on one screen',
		'ffc-checkbox-label|ffc-admin.css|ffc-reregistration-frontend.css' => 'admin × frontend: they never coexist on one screen',

		// `ffc-calendar-editor.css` only loads when editing the
		// `ffc_self_scheduling` CPT, where `is_ffc_page()` is false (the post
		// type is not `ffc_form`) -- so `ffc-admin.css` does not get in there,
		// and neither does the `ffc-admin` handle from `users.php`.
		'ffc-shortcode-display|ffc-admin.css|ffc-calendar-editor.css' => 'distinct screens: the editor sheet only ships on the ffc_self_scheduling CPT',

		// `column-actions` and `column-status` left here in #1184. They were the
		// exception whose reason -- "distinct screens" -- lived entirely in the
		// enqueue GATE, which this scan does not read. It now lives in the
		// selector: the audience sheet descends from
		// `[class*="ffc-page-scheduling-"]` and the reregistration one from
		// `.ffc-page-reregistration` / `.ffc-page-custom-fields`, so the
		// impossibility is structural and no longer has to be promised here.

		// `ffc-custom-fields-admin.css` ships on the user profile and the audience
		// screens; `ffc-reregistration-admin.css`, on the reregistration ones.
		'ffc-color-dot|ffc-custom-fields-admin.css|ffc-reregistration-admin.css' => 'distinct screens: profile/audience × reregistration',

		// `ffc-working-hours.css` ships on the user profile and on the frontend
		// dashboard; `ffc-audience-admin.css`, on the audience screens.
		'ffc-working-hours|ffc-audience-admin.css|ffc-reregistration-frontend.css' => 'admin × frontend: they never coexist on one screen',
		'ffc-working-hours|ffc-audience-admin.css|ffc-working-hours.css' => 'distinct screens: audience × user profile and dashboard',
		// The call's status badge exists on both recruitment surfaces, and they
		// cannot coexist: the admin sheet is gated on
		// `is_recruitment_screen( $hook_suffix )`, which is a wp-admin hook, and
		// the public one is enqueued while the shortcode renders.
		'ffc-recruitment-status-badge|ffc-recruitment-admin.css|ffc-recruitment-public.css' => 'admin × frontend: they never coexist on one screen',
	);

	/**
	 * Pairs that coexist and have no edge. A debt register; it only shrinks.
	 *
	 * @var array<string, string>
	 */
	private const BASELINE = array();

	/**
	 * Sheets that do not go through `wp_enqueue_style`, with the reason.
	 *
	 * @var array<string, string>
	 */
	private const NO_HANDLE = array(
		// Appointment cancellation builds a document of its own and prints the
		// `<link>` tags by hand, in explicit order (palette first) -- precisely
		// so that the order is a testable value rather than an `echo` behind an
		// `exit()`. There is no WordPress queue for an edge to live in.
		'ffc-appointment-cancellation.css' => 'the cancellation handler\'s own document; order printed by hand',
	);

	/**
	 * Handle => sheet, and handle => declared dependencies.
	 *
	 * @return array{files: array<string, string>, deps: array<string, array<int, string>>}
	 */
	private function graph(): array {
		$files = array();
		$deps  = array();

		foreach ( $this->php_sources() as $source ) {
			preg_match_all( "/const\s+([A-Z_][A-Z0-9_]*)\s*=\s*'([^']+)'/", $source, $cm, PREG_SET_ORDER );
			$consts = array();
			foreach ( $cm as $c ) {
				$consts[ $c[1] ] = $c[2];
			}

			$offset = 0;
			while ( preg_match( '/wp_(?:enqueue|register)_style\s*\(/', $source, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
				$open   = (int) $m[0][1] + strlen( $m[0][0] ) - 1;
				$args   = $this->arguments( $source, $open );
				$offset = (int) $m[0][1] + 1;

				if ( count( $args ) < 2 ) {
					continue;
				}
				if ( ! preg_match( '#assets/css/([a-z0-9-]+)(?:\{\$s(?:uffix)?\}|\$s)?(?:\.min)?\.css#', $args[1], $sm ) ) {
					continue;
				}

				$handle = null;
				if ( preg_match( "/'([^']+)'/", $args[0], $hm ) ) {
					$handle = $hm[1];
				} elseif ( preg_match( '/self::([A-Z_][A-Z0-9_]*)/', $args[0], $cn ) ) {
					$handle = $consts[ $cn[1] ] ?? null;
				}
				if ( null === $handle ) {
					continue;
				}

				$files[ $handle ] = $sm[1] . '.css';
				$deps[ $handle ]  = $deps[ $handle ] ?? array();
				if ( isset( $args[2] ) ) {
					preg_match_all( "/'([^']+)'/", $args[2], $dm );
					$deps[ $handle ] = array_values( array_unique( array_merge( $deps[ $handle ], $dm[1] ) ) );
				}
			}
		}

		return array(
			'files' => $files,
			'deps'  => $deps,
		);
	}

	/**
	 * A call's top-level arguments, given the index of its `(`.
	 *
	 * An `explode( ',', … )` will not do: the second argument is usually a
	 * concatenation with `plugins_url( …, dirname( __DIR__, 1 ) )`, whose inner
	 * comma would cut the list in the wrong place, dropping the sheet from the count.
	 *
	 * @param string $source File contents.
	 * @param int    $open   Index of the opening parenthesis.
	 * @return array<int, string>
	 */
	private function arguments( string $source, int $open ): array {
		$args  = array();
		$cur   = '';
		$depth = 0;
		$quote = '';
		$len   = strlen( $source );

		for ( $i = $open; $i < $len; $i++ ) {
			$ch = $source[ $i ];

			if ( '' !== $quote ) {
				$cur .= $ch;
				if ( '\\' === $ch && $i + 1 < $len ) {
					$cur .= $source[ ++$i ];
					continue;
				}
				if ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $ch || "'" === $ch ) {
				$quote = $ch;
				$cur  .= $ch;
				continue;
			}
			if ( '(' === $ch ) {
				++$depth;
				if ( 1 === $depth ) {
					continue;
				}
			} elseif ( ')' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					$args[] = $cur;
					return $args;
				}
			}
			if ( ',' === $ch && 1 === $depth ) {
				$args[] = $cur;
				$cur    = '';
				continue;
			}
			$cur .= $ch;
		}

		return $args;
	}

	/**
	 * Every `.php` file in `includes/`.
	 *
	 * @return array<int, string>
	 */
	private function php_sources(): array {
		$root  = dirname( __DIR__, 2 ) . '/includes';
		$files = array();

		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
		foreach ( $it as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = (string) file_get_contents( $file->getPathname() );
			}
		}

		return $files;
	}

	/**
	 * Does `$from` reach `$to` through the dependency chain?
	 *
	 * @param string                          $from Source handle.
	 * @param string                          $to   Target handle.
	 * @param array<string, array<int,string>> $deps The graph.
	 * @param array<string, bool>             $seen Already visited.
	 * @return bool
	 */
	private function reaches( string $from, string $to, array $deps, array &$seen ): bool {
		if ( isset( $seen[ $from ] ) ) {
			return false;
		}
		$seen[ $from ] = true;

		foreach ( $deps[ $from ] ?? array() as $dep ) {
			if ( $dep === $to || $this->reaches( $dep, $to, $deps, $seen ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is there an edge between two sheets, in either direction?
	 *
	 * @param string                          $a     Sheet A.
	 * @param string                          $b     Sheet B.
	 * @param array<string, string>           $files Handle => sheet.
	 * @param array<string, array<int,string>> $deps  The graph.
	 * @return bool
	 */
	private function linked( string $a, string $b, array $files, array $deps ): bool {
		$ha = array_keys( $files, $a, true );
		$hb = array_keys( $files, $b, true );

		foreach ( $ha as $x ) {
			foreach ( $hb as $y ) {
				$seen = array();
				if ( $this->reaches( $x, $y, $deps, $seen ) ) {
					return true;
				}
				$seen = array();
				if ( $this->reaches( $y, $x, $deps, $seen ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * (class, sheetA, sheetB) pairs declared bare with no edge.
	 *
	 * @return array<int, string> Keys of the form `class|sheetA|sheetB`.
	 */
	private function unlinked_pairs(): array {
		$graph = $this->graph();
		$bare  = array();

		foreach ( CssSelectors::sheets() as $path ) {
			$name = basename( $path );
			foreach ( CssSelectors::of( (string) file_get_contents( $path ) ) as $selector ) {
				$class = CssSelectors::bare_class( $selector );
				if ( null !== $class ) {
					$bare[ $class ][ $name ] = true;
				}
			}
		}

		$pairs = array();
		foreach ( $bare as $class => $sheets ) {
			$names = array_keys( $sheets );
			sort( $names );
			$count = count( $names );
			for ( $i = 0; $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					if ( ! $this->linked( $names[ $i ], $names[ $j ], $graph['files'], $graph['deps'] ) ) {
						$pairs[] = "{$class}|{$names[ $i ]}|{$names[ $j ]}";
					}
				}
			}
		}

		sort( $pairs );

		return $pairs;
	}

	/**
	 * Nothing new without an edge.
	 *
	 * @return void
	 */
	public function test_no_new_class_is_declared_bare_in_two_unlinked_sheets(): void {
		$new = array();
		foreach ( $this->unlinked_pairs() as $pair ) {
			if ( ! isset( self::ALLOWED[ $pair ] ) && ! isset( self::BASELINE[ $pair ] ) ) {
				$new[] = $pair;
			}
		}

		$this->assertSame(
			array(),
			$new,
			"Class declared bare in two sheets with no dependency edge between them — the "
				. "winner is enqueue order. Declare the dependency in `wp_enqueue_style`, give "
				. "the component its own name, or record it in ALLOWED with the reason the two "
				. "never load together:\n" . implode( "\n", $new )
		);
	}

	/**
	 * What gained an edge (or its own name) leaves the lists.
	 *
	 * @return void
	 */
	public function test_the_lists_shrink_when_a_pair_is_resolved(): void {
		$live  = $this->unlinked_pairs();
		$stale = array();

		foreach ( array_keys( self::BASELINE ) as $pair ) {
			if ( ! in_array( $pair, $live, true ) ) {
				$stale[] = "BASELINE: {$pair}";
			}
		}
		foreach ( array_keys( self::ALLOWED ) as $pair ) {
			if ( ! in_array( $pair, $live, true ) ) {
				$stale[] = "ALLOWED: {$pair}";
			}
		}

		$this->assertSame(
			array(),
			$stale,
			"A pair was resolved and the list did not follow — drop it to lock the win in:\n"
				. implode( "\n", $stale )
		);
	}

	/**
	 * Every entry in the lists carries a reason.
	 *
	 * @return void
	 */
	public function test_every_listed_pair_carries_a_reason(): void {
		$thin = array();
		foreach ( array_merge( self::ALLOWED, self::BASELINE, self::NO_HANDLE ) as $key => $reason ) {
			if ( strlen( trim( $reason ) ) < 15 ) {
				$thin[] = $key;
			}
		}

		$this->assertSame( array(), $thin, 'Entry with no written reason: ' . implode( ', ', $thin ) );
	}

	/**
	 * Every sheet has a handle, or sits in NO_HANDLE with the reason.
	 *
	 * This is the half that stops the guard passing out of ignorance: a sheet the
	 * extractor could not find would have an empty graph, and every pair of its
	 * would count as "no edge" — or, worse, a change in the call's shape would
	 * drop a whole sheet from the count with nothing going red.
	 *
	 * @return void
	 */
	public function test_every_stylesheet_is_reachable_through_an_enqueue(): void {
		$graph   = $this->graph();
		$known   = array_values( $graph['files'] );
		$missing = array();

		foreach ( CssSelectors::sheets() as $path ) {
			$name = basename( $path );
			if ( ! in_array( $name, $known, true ) && ! isset( self::NO_HANDLE[ $name ] ) ) {
				$missing[] = $name;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"Sheet with no `wp_enqueue_style` the extractor recognises. If it genuinely does "
				. "not go through the WordPress queue, record it in NO_HANDLE with the reason:\n"
				. implode( "\n", $missing )
		);

		foreach ( array_keys( self::NO_HANDLE ) as $name ) {
			$this->assertNotContains( $name, $known, "{$name} gained a handle — drop it from NO_HANDLE." );
		}
	}

	/**
	 * Reading the graph did not collapse.
	 *
	 * @return void
	 */
	public function test_the_dependency_graph_is_still_being_read(): void {
		$graph = $this->graph();

		$this->assertGreaterThanOrEqual( 25, count( $graph['files'] ), 'The extractor lost handles.' );
		$this->assertSame( 'ffc-admin.css', $graph['files']['ffc-admin-css'] ?? null );
		$this->assertContains( 'ffc-admin-utilities', $graph['deps']['ffc-admin-css'] ?? array() );

		// `ffc-calendar-admin` is only reachable because the extractor reads
		// top-level arguments: the call uses `plugins_url( "…", dirname( __DIR__, 1 ) )`,
		// whose inner comma would break an `explode`.
		$this->assertSame( 'ffc-calendar-admin.css', $graph['files']['ffc-calendar-admin'] ?? null );

		// `ffc-recruitment-admin` is only reachable because the extractor
		// resolves `self::HANDLE_CSS` against the file's own constants.
		$this->assertSame( 'ffc-recruitment-admin.css', $graph['files']['ffc-recruitment-admin'] ?? null );
	}

	/**
	 * A transitive edge counts as an edge.
	 *
	 * `ffc-admin-settings` → `ffc-admin-css` → `ffc-admin-utilities`: the sheets
	 * at the ends have a deterministic order without naming each other.
	 *
	 * @return void
	 */
	public function test_a_transitive_edge_counts_as_linked(): void {
		$graph = $this->graph();

		$this->assertTrue(
			$this->linked( 'ffc-admin-settings.css', 'ffc-admin-utilities.css', $graph['files'], $graph['deps'] )
		);
		$this->assertFalse(
			$this->linked( 'ffc-admin-settings.css', 'ffc-frontend.css', $graph['files'], $graph['deps'] )
		);
	}

	/**
	 * Only the bare declaration counts.
	 *
	 * A compound or a descendant already names the owner; it is the lone
	 * declaration that reaches any component carrying the class.
	 *
	 * @return void
	 */
	public function test_only_a_bare_declaration_counts(): void {
		$this->assertSame( 'ffc-x', CssSelectors::bare_class( '.ffc-x' ) );
		$this->assertSame( 'ffc-x', CssSelectors::bare_class( '.ffc-x:hover' ) );
		$this->assertSame( 'ffc-x', CssSelectors::bare_class( '.ffc-x::after' ) );

		$this->assertNull( CssSelectors::bare_class( '.ffc-y .ffc-x' ) );
		$this->assertNull( CssSelectors::bare_class( '.ffc-x.is-open' ) );
		$this->assertNull( CssSelectors::bare_class( 'a.ffc-x' ) );
	}
}
