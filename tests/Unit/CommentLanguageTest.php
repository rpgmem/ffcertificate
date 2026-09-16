<?php
/**
 * Every comment and every i18n source string is written in English.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Comment-language guard (#1260).
 *
 * `CLAUDE.md` opens by stating that every versioned artifact is in English while
 * the conversation with the maintainer is in Brazilian Portuguese, and records
 * WHY the drift happened: nothing said so, so three releases' worth of
 * CHANGELOG, 24 test files and 75 assertion messages came out in Portuguese
 * without anyone deciding to. This guard is the half a written rule cannot do.
 *
 * IT EXISTS BECAUSE THE CLEANUP ITSELF SHIPPED INCOMPLETE THREE TIMES
 *
 * #1260 translated the tree in slices, and each slice used an ad-hoc Portuguese
 * word list. Each list failed differently:
 *
 * 1. The first contained tokens that collide with English and with code, and
 *    reported 959 false-positive lines. It was pruned, and the pruning is what
 *    lost the recall.
 * 2. The pruned list was ACCENTED-ONLY, so it never saw Portuguese written
 *    without accents. That is how much of the test-suite prose was written.
 * 3. Neither list contained the 3-state tier names, which read as domain
 *    vocabulary: one slice left them and the next translated them, so the two
 *    disagreed with each other.
 *
 * Net result: 35 files merged as "translated" while still carrying a Portuguese
 * clause in the middle of an otherwise English paragraph. The proofs those
 * slices carried -- the token skeleton and the string inventory -- are real and
 * prove the right thing, which is that NO BEHAVIOUR MOVED. Neither can see
 * prose at all, and that is the gap this fills.
 *
 * THE RULE: TWO DISTINCT FUNCTION WORDS ON ONE LINE
 *
 * A line is flagged when it carries two DISTINCT words from {@see self::WORDS},
 * after quoted spans are removed. Requiring two is the whole design, and it is
 * what those three failures cost to find:
 *
 * - One word anywhere is what reported `example.com`, `DoS` and a Brazilian
 *   surname, because the fix for such a false positive was always to delete the
 *   word from the list -- which is precisely how the recall went.
 * - Two words essentially never co-occur in an English technical sentence,
 *   which lets the list stay WIDE, unaccented forms and all.
 *
 * So do not "fix" a false positive by removing a word. Either the line is
 * Portuguese-as-data, in which case the quote rule below already covers it or
 * it belongs in {@see self::ALLOWED} with a reason, or the rule needs a third
 * word -- never a narrower list.
 *
 * QUOTED IS MENTIONED, NOT USED
 *
 * A span inside backticks or quotes is removed before matching. That is the
 * use/mention distinction, and it is principled rather than a patch: a comment
 * that OPERATES on Portuguese quotes it. All four exemptions this guard was
 * first written with turned out to be that shape -- the connective list
 * `DataSanitizer` keeps lowercase, its worked example, and the two rendered
 * pt-BR samples in `DateFormatter` and `VerificationResponseRenderer` -- so the
 * rule replaced the allowlist entirely instead of growing it.
 *
 * MEASURED, NOT ASSERTED
 *
 * The word list was not written from intuition; that is what failed three
 * times. It was tuned against ground truth: the 160 Portuguese comment lines
 * #1278 removed, grouped into the 60 contiguous BLOCKS they form. The metric is
 * per block, not per line, because half of those lines are fragments of a
 * sentence (`* aquilo.`, `// expectativa.`) that no line-level rule can catch
 * and none needs to -- flagging one line of a block is what makes the block
 * findable, and a human then fixes the paragraph.
 *
 * Final measurement: **59 of 60 blocks (98.3%), with zero false positives over
 * the whole tree**. Two things that produced are worth keeping:
 *
 * - A greedy tuner that maximised recall alone proposed `int` and `blank` --
 *   ENGLISH words, earning their place only by sitting on a Portuguese line in
 *   this particular corpus. That is the "the dictionary describes itself" trap
 *   in a new costume. Every word here is in because it is Portuguese, never
 *   because it fixed a specific block.
 * - The wider list flagged six lines on a tree that three passes had called
 *   clean. They were six real Portuguese comments, fixed in the same commit --
 *   the guard paid for itself before it existed.
 *
 * THE ONE BLOCK IT MISSES, AND WHY THAT IS THE DESIGN
 *
 * `// Linha 1 carries both CPF + RF; linha 2 is RF-only (CPF blank).` It is an
 * English sentence with ONE Portuguese word used twice, so a two-DISTINCT-word
 * rule cannot see it by construction. Lowering the threshold to catch it costs
 * every false positive the rule exists to avoid; it is a knowingly accepted
 * miss, not an oversight.
 *
 * WHAT IT DOES NOT SEE
 *
 * A single Portuguese word. `_x( 'ficha', 'pdf filename prefix' )` shipped as a
 * source string translating to itself, and no two-word rule can catch it -- a
 * lone domain term is a product question (is it a domain term like `CPF`, or
 * does it rename to `Record`?), which is #1264's, not a language defect. Nor
 * does it read `languages/`, `CHANGELOG.md`, commit messages or PR bodies:
 * `CLAUDE.md` records that the PR-body half is disciplined at the moment of
 * writing, because nothing else catches it.
 */
class CommentLanguageTest extends TestCase {

	/**
	 * Portuguese words, accented and unaccented.
	 *
	 * Deliberately wide. Several entries collide with English or with code on
	 * their own -- the two-distinct-words rule and the quote rule are what make
	 * that safe, and narrowing this list is how recall was lost before. See the
	 * class docblock before adding or removing one.
	 *
	 * @var array<int, string>
	 */
	private const WORDS = array(
		// Determiners, pronouns and prepositions -- the densest signal in any sentence.
		'aos', 'ao', 'às', 'à', 'cada', 'cuja', 'cujo', 'das', 'dos', 'ela', 'elas', 'ele', 'eles',
		'esta', 'este', 'essa', 'esse', 'isso', 'isto', 'lhe', 'nas', 'nem', 'nenhum', 'nenhuma', 'num',
		'numa', 'para', 'pela', 'pelas', 'pelo', 'pelos', 'qualquer', 'quais', 'que', 'quem', 'seu',
		'seus', 'sua', 'suas', 'todo', 'toda', 'todos', 'todas', 'uma', 'com', 'sem', 'sobre', 'entre',
		'dentro', 'fora',

		// Conjunctions and adverbs.
		'ainda', 'antes', 'apenas', 'aqui', 'abaixo', 'acima', 'assim', 'até', 'ate', 'depois',
		'enquanto', 'então', 'entao', 'mais', 'menos', 'mesmo', 'muito', 'nunca', 'onde', 'ou', 'pois',
		'porém', 'porem', 'porque', 'quando', 'sempre', 'só', 'somente', 'também', 'tambem', 'já', 'ja',

		// Verb forms that carry a technical sentence.
		'cai', 'chama', 'declara', 'deve', 'devem', 'devolve', 'edita', 'entra', 'estão', 'estao',
		'existe', 'existem', 'faz', 'fazem', 'fica', 'grava', 'guarda', 'mora', 'monta', 'nasce',
		'passou', 'passaram', 'pode', 'podem', 'precisa', 'puxa', 'responde', 'retorna', 'roda', 'sai',
		'segue', 'será', 'sera', 'têm', 'foi', 'são', 'sao', 'vem', 'vêm', 'vê', 'viraria', 'é',

		// Nouns and adjectives the codebase's own prose uses.
		'aba', 'antiga', 'antigo', 'arquivo', 'aviso', 'banco', 'bruto', 'campo', 'campos', 'chave',
		'coluna', 'consulta', 'contagem', 'convite', 'cor', 'correcao', 'defeito', 'degrau', 'envelope',
		'envio', 'escrita', 'esquema', 'falha', 'ficheiro', 'função', 'funcao', 'gravação', 'gravacao',
		'janela', 'laço', 'laco', 'leitura', 'linha', 'linhas', 'lote', 'metodo', 'modulo', 'motivo',
		'numero', 'obrigatorio', 'opcional', 'prazo', 'prefixo', 'primeiro', 'propria', 'proprio',
		'própria', 'razão', 'razao', 'repositorio', 'requisicao', 'asercao', 'segundo', 'senha',
		'tabela', 'tamanho', 'tela', 'ultima', 'último', 'usuário', 'usuario', 'valor', 'variavel',
		'variáveis', 'varredura', 'vazia', 'vazio', 'versão', 'versao', 'nao', 'não',

	);

	/**
	 * How many distinct words make a line Portuguese.
	 *
	 * Two. See the class docblock -- this number, not the width of the word
	 * list, is what separates prose from `example.com`.
	 *
	 * @var int
	 */
	private const MIN_DISTINCT = 2;

	/**
	 * Directory names that are never scanned, at any depth.
	 *
	 * `languages/` is excluded because a `.po`/`.l10n.php` is SUPPOSED to be
	 * Portuguese -- it is the translation, not the source.
	 *
	 * @var array<int, string>
	 */
	private const SKIP_DIRS = array( '.git', 'node_modules', 'vendor', 'libs', 'coverage-js', 'languages' );

	/**
	 * Lines that are Portuguese on purpose, with the reason.
	 *
	 * EMPTY, and not because there is nothing of the kind: the four lines this
	 * guard was first written to exempt are all Portuguese-as-data, and the
	 * quote rule covers every one of them without an entry. It stays as the
	 * escape hatch for a line where the Portuguese is genuinely unquoted data.
	 *
	 * An entry that stops matching a real line fails, so the list cannot rot --
	 * the same both-ways ratchet the CSS guards use.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const ALLOWED = array();

	/**
	 * Quoted spans: backticks, single quotes, double quotes.
	 *
	 * @var string
	 */
	private const QUOTED = '/`[^`]*`|\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"/u';

	/**
	 * The word pattern, built once per process.
	 *
	 * @var string|null
	 */
	private static ?string $pattern = null;

	/**
	 * Distinct Portuguese words in one piece of text, quoted spans removed.
	 *
	 * @param string $text The line, or a source string.
	 * @return array<int, string> Lower-cased, sorted, unique.
	 */
	private function portuguese_words( string $text ): array {
		if ( null === self::$pattern ) {
			self::$pattern = '/\b(?:' . implode( '|', self::WORDS ) . ')\b/iu';
		}

		$unquoted = (string) preg_replace( self::QUOTED, ' ', $text );

		if ( 0 === preg_match_all( self::$pattern, $unquoted, $matches ) ) {
			return array();
		}

		$words = array_values( array_unique( array_map( 'mb_strtolower', $matches[0] ) ) );
		sort( $words );

		return $words;
	}

	/**
	 * Is this line a comment?
	 *
	 * Deliberately textual rather than a PHP tokenizer pass: the same scan has
	 * to read `.js` too, and a Portuguese sentence inside a string literal is
	 * the source-string direction's job, not this one's.
	 *
	 * @param string $line Raw line.
	 * @return bool
	 */
	private function is_comment_line( string $line ): bool {
		$trimmed = ltrim( $line );

		return str_starts_with( $trimmed, '*' )
			|| str_starts_with( $trimmed, '/*' )
			|| str_starts_with( $trimmed, '//' );
	}

	/**
	 * Every PHP and JS file in the tree, repo-relative.
	 *
	 * Walks the filesystem rather than shelling out to git, so the scan works in
	 * a checkout without a `.git` at all.
	 *
	 * @return array<int, string>
	 */
	private function files(): array {
		$root  = dirname( __DIR__, 2 );
		$found = array();

		$filter = new \RecursiveCallbackFilterIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			static function ( \SplFileInfo $file ): bool {
				if ( $file->isDir() ) {
					return ! in_array( $file->getFilename(), self::SKIP_DIRS, true );
				}

				$name = $file->getFilename();

				return ( str_ends_with( $name, '.php' ) || str_ends_with( $name, '.js' ) )
					&& ! str_ends_with( $name, '.min.js' );
			}
		);

		/** @var \SplFileInfo $file */
		foreach ( new \RecursiveIteratorIterator( $filter ) as $file ) {
			$found[] = substr( $file->getPathname(), strlen( $root ) + 1 );
		}

		sort( $found );

		return $found;
	}

	/**
	 * Scans every comment line in the tree.
	 *
	 * @return array{hits: array<string, array<string, array{line: int, words: array<int, string>}>>, files: int, lines: int}
	 */
	private function scan(): array {
		$root  = dirname( __DIR__, 2 );
		$hits  = array();
		$files = 0;
		$lines = 0;

		foreach ( $this->files() as $relative ) {
			++$files;

			foreach ( explode( "\n", (string) file_get_contents( $root . '/' . $relative ) ) as $index => $line ) {
				if ( ! $this->is_comment_line( $line ) ) {
					continue;
				}
				++$lines;

				$words = $this->portuguese_words( $line );
				if ( count( $words ) < self::MIN_DISTINCT ) {
					continue;
				}

				$hits[ $relative ][ trim( $line ) ] = array(
					'line'  => $index + 1,
					'words' => $words,
				);
			}
		}

		return array(
			'hits'  => $hits,
			'files' => $files,
			'lines' => $lines,
		);
	}

	/**
	 * No comment carries a Portuguese sentence.
	 *
	 * @return void
	 */
	public function test_no_comment_is_written_in_portuguese(): void {
		$found = array();

		foreach ( $this->scan()['hits'] as $relative => $comments ) {
			foreach ( $comments as $text => $hit ) {
				if ( isset( self::ALLOWED[ $relative ][ $text ] ) ) {
					continue;
				}
				$found[] = "{$relative}:{$hit['line']} [" . implode( ', ', $hit['words'] ) . "] {$text}";
			}
		}

		$this->assertSame(
			array(),
			$found,
			"Comment written in Portuguese. `CLAUDE.md` requires English in every versioned artifact.\n"
				. "If the Portuguese is DATA the sentence operates on, quote it -- a backticked or quoted\n"
				. "span is not read as prose. Never fix this by deleting a word from WORDS: that is how\n"
				. "the #1260 slices lost their recall three times over.\n" . implode( "\n", $found )
		);
	}

	/**
	 * No i18n source string carries a Portuguese sentence.
	 *
	 * Stricter than the comment half, with no allowlist at all. A source string
	 * is the one place the language is not a preference: the key-rotation
	 * migration reported a failure in Portuguese, so a non-Portuguese install
	 * had no way to read it AND no translation could fix it -- every `.po`
	 * translates FROM the source (#1260).
	 *
	 * @return void
	 */
	public function test_no_i18n_source_string_is_written_in_portuguese(): void {
		$root  = dirname( __DIR__, 2 );
		$found = array();
		$seen  = 0;

		$call = '/\b(?:__|_e|_x|_n|_nx|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x|esc_attr_x)'
			. '\s*\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/u';

		foreach ( $this->files() as $relative ) {
			if ( ! str_ends_with( $relative, '.php' ) ) {
				continue;
			}

			$source = (string) file_get_contents( $root . '/' . $relative );
			if ( 0 === preg_match_all( $call, $source, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[2] as $match ) {
				++$seen;

				// The literal IS the text here, so the quote rule must not run:
				// it would blank every string and the direction would pass by
				// measuring nothing.
				$words = $this->portuguese_words( ' ' . str_replace( array( '`', '"', "'" ), ' ', (string) $match[0] ) . ' ' );
				if ( count( $words ) < self::MIN_DISTINCT ) {
					continue;
				}

				$line    = substr_count( substr( $source, 0, (int) $match[1] ), "\n" ) + 1;
				$found[] = "{$relative}:{$line} [" . implode( ', ', $words ) . '] ' . $match[0];
			}
		}

		$this->assertGreaterThan(
			5000,
			$seen,
			'The source-string scan found almost nothing -- it is broken, not clean.'
		);

		$this->assertSame(
			array(),
			$found,
			"i18n source string written in Portuguese. The source is what every `.po` translates FROM,\n"
				. "so a Portuguese source cannot be fixed by a translation and leaves a non-Portuguese\n"
				. "install with no readable text at all:\n" . implode( "\n", $found )
		);
	}

	/**
	 * An allowed line that stopped matching leaves the list.
	 *
	 * Without this the allowlist rots: a line translated later, or reworded,
	 * would keep its exemption and quietly cover a NEW Portuguese line of the
	 * same text.
	 *
	 * @return void
	 */
	public function test_an_allowed_line_that_no_longer_matches_is_removed(): void {
		$hits  = $this->scan()['hits'];
		$stale = array();

		foreach ( self::ALLOWED as $relative => $comments ) {
			foreach ( $comments as $text => $reason ) {
				if ( ! isset( $hits[ $relative ][ $text ] ) ) {
					$stale[] = "{$relative}: `{$text}` ({$reason})";
				}
			}
		}

		$this->assertSame(
			array(),
			$stale,
			"ALLOWED entry that matches no line. The line was translated, moved or reworded --\n"
				. "drop the entry so the exemption cannot cover a future line of the same text:\n"
				. implode( "\n", $stale )
		);
	}

	/**
	 * The scan reaches the tree.
	 *
	 * An empty scan must never read as clean -- the lesson `CLAUDE.md` records
	 * from #1071 / #1094, and the one this guard needs most, because its whole
	 * value is a zero.
	 *
	 * @return void
	 */
	public function test_the_scan_reaches_the_tree(): void {
		$scan = $this->scan();

		$this->assertGreaterThan( 800, $scan['files'], 'Far fewer files than the tree holds -- the walk is broken.' );
		$this->assertGreaterThan( 60000, $scan['lines'], 'Far fewer comment lines than the tree holds -- the line filter is broken.' );

		$files = $this->files();
		$this->assertContains( 'includes/class-ffc-loader.php', $files, 'The walk misses `includes/`.' );
		$this->assertContains( 'tests/Unit/CommentLanguageTest.php', $files, 'The walk misses `tests/` -- where the #1260 drift actually lived.' );
		$this->assertContains( 'assets/js/ffc-core.js', $files, 'The walk misses `assets/js/`.' );
		$this->assertContains( 'templates/emails/reregistration-invitation.php', $files, 'The walk misses `templates/`.' );
		$this->assertContains( 'uninstall.php', $files, 'The walk misses the repository root.' );
	}

	/**
	 * The detector fires on prose and stays quiet on English.
	 *
	 * This pins the DESIGN, and every English line here is one that a one-word
	 * rule actually reported during #1260, at the cost of a pruning round.
	 *
	 * @return void
	 */
	public function test_two_distinct_words_are_required(): void {
		$english = array(
			'// Source: https://www.cloudflare.com/ips-v4',
			'// catches the DoS case before geofence -- geofence is an authorization',
			'// the size cap is the real DoS guard. Returns a 400/413 `WP_Error`',
			'* - "maria dos santos e oliveira" -> "Maria dos Santos e Oliveira"',
			'* connectives (`de`, `da`, `do`, `das`, `dos`, `e`) lowercase.',
			"* `'8h às 19h30'` / `'8h to 7:30 PM'` shape the issue spec calls",
		);
		foreach ( $english as $line ) {
			$this->assertLessThan(
				self::MIN_DISTINCT,
				count( $this->portuguese_words( $line ) ),
				"English line reported as Portuguese: {$line}"
			);
		}

		$portuguese = array(
			// Accented -- the only shape the first list caught.
			'// O usuario vem do TOKEN, nunca de um id da requisicao.',
			// Unaccented -- the shape it missed, and why 35 files shipped.
			'// E a asercao que sustenta a correcao.',
			'// Sem LIMIT, o dia em que um administrador liga a retencao numa',
			// The tier names no list contained.
			'// the *vê e edita* tier. Read-only viewers reach the page but',
			// The six lines a tree three passes had called clean still carried.
			'// Guarda por versao (#1231) -- ver a nota logo acima da assinatura.',
			'* Versao do envelope gravado na coluna qr_cache.',
		);
		foreach ( $portuguese as $line ) {
			$this->assertGreaterThanOrEqual(
				self::MIN_DISTINCT,
				count( $this->portuguese_words( $line ) ),
				"Portuguese line not reported: {$line}"
			);
		}
	}
}
