<?php
/**
 * Schema-agreement guard (#1087).
 *
 * A table declared in two places drifts silently. This file checks the two
 * shapes that duplication takes in this repository.
 *
 * **Within one file — `CREATE TABLE` vs `add_columns_if_missing()`.**
 *
 *   1. The `CREATE TABLE` is what a **fresh install** gets in one statement.
 *   2. The `add_columns_if_missing()` call is what an **existing install** gets
 *      when `FFC_VERSION` changes.
 *
 * Both must name the same columns, or a fresh install and an upgraded install
 * end up with different tables and nothing says so. That is not hypothetical:
 * `ffc_submissions` was born with 7 of its 25 columns until #1091, and
 * `ffc_reregistration_submissions` without `auth_code` / `magic_token` until
 * #1093.
 *
 * **Across files — the same table declared by more than one class.** Three
 * tables are: `ffc_custom_fields` (3 declarations), `ffc_reregistration_submissions`
 * (3) and `ffc_reregistrations` (2), because the activators were written after
 * the migrations that first created those tables and neither side was retired.
 * Nothing compared them, and the third occurrence of the #1091 class was hiding
 * exactly there: `UserDashboardActivator` declared **12 of the 17 columns** of
 * `ffc_custom_fields`, missing the five that `CustomFieldWriter::create()`
 * writes on every insert. A fresh install came out correct only because
 * `MigrationDynamicReregFields` runs later in the same activation and its
 * `dbDelta` added them — and that migration is one-shot, flagged by an option,
 * so any recreation of the table afterwards would have produced a permanently
 * 12-column table.
 *
 * The keys diverged the same way, and that one was already costing something:
 * both paths indexed `auth_code` and `magic_token` under **different names**, so
 * an install that took both ended up carrying two indexes on each column
 * (visible in the `SHOW CREATE TABLE` the idempotence gate dumped). Aligning
 * the declarations stops new installs from inheriting the pair; dropping the
 * duplicates on existing installs is a separate, destructive change.
 *
 * **Why both declarations still exist.** Every one of these activators guards
 * its `dbDelta()` on `table_exists()`, so `dbDelta` never runs on an install
 * that already has the table — which is exactly why the incremental path was
 * written. Dropping the guard so `dbDelta` handles upgrades is the "adds a
 * column the normal WordPress way" scenario CLAUDE.md warns fires the latent
 * malformed-`ALTER` defects, and it would change behaviour for every install
 * on every version bump. Consolidating the columns does not require it.
 *
 * **This is a scan, not a framework.** The population is two calls in the whole
 * repository. A generalised schema-declaration engine over two sites would be
 * indirection that narrows nothing — the trap CLAUDE.md names, and the same
 * call made in #1079 against a `Core\OptionValue::int()` for three sites. What
 * this file does instead is find whatever `add_columns_if_missing()` calls
 * exist and check each against the `CREATE TABLE` in the same file. A third
 * one is covered the day it is written, with no new abstraction.
 *
 * It compares **column names, not types**: the two sources spell types
 * differently by construction (`VARCHAR(20) DEFAULT NULL` in the PHP array,
 * `varchar(20) DEFAULT NULL` in the SQL), and normalising that would be
 * re-implementing dbDelta's comparison inside a test. Names are what drift
 * when someone adds a column to one place only.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SchemaAgreementTest extends TestCase {

	/**
	 * Ficheiros que declaram uma tabela E a completam incrementalmente.
	 *
	 * DOIS IDIOMAS, E O SINGULAR E A MAIORIA (#1241)
	 *
	 * `DatabaseHelperTrait` expoe `add_column_if_missing()` e
	 * `add_columns_if_missing()`, e nada obriga um activator a escolher um.
	 * Esta varredura procurava so o PLURAL, que tem 4 ocorrencias, contra 40
	 * do singular -- entao media 2 dos 6 activators.
	 *
	 * A autoverificacao nao pegou porque ela cobrava que a lista nao fosse
	 * vazia, e o idioma plural existe em dois arquivos. Uma minoria medida
	 * sustentava a aparencia de varredura viva. E a forma que o CLAUDE.md
	 * descreve como "nunca conte como limpo o que nao foi olhado", com o
	 * agravante de parecer olhado.
	 *
	 * @return list<string> Absolute paths.
	 */
	private function activator_files(): array {
		$root  = dirname( __DIR__, 2 ) . '/includes';
		$found = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();

			if ( substr( $path, -4 ) !== '.php' ) {
				continue;
			}

			$source = (string) file_get_contents( $path );

			// `add_columns_if_missing(` contem `add_column` como prefixo? Nao:
			// `column` e `columns` divergem antes do parentese, entao as duas
			// buscas sao independentes.
			$has_incremental = false !== strpos( $source, 'add_column_if_missing(' )
				|| false !== strpos( $source, 'add_columns_if_missing(' );

			if ( $has_incremental && false !== strpos( $source, 'CREATE TABLE' ) ) {
				$found[] = $path;
			}
		}

		sort( $found );

		return $found;
	}

	/**
	 * Colunas de todo `CREATE TABLE` de um ficheiro, somadas.
	 *
	 * Somadas de proposito: um activator pode declarar varias tabelas, e casar
	 * cada chamada incremental com o seu proprio statement exigiria resolver a
	 * variavel de tabela. Uma coluna presente em QUALQUER create do mesmo
	 * ficheiro basta para dizer que ela nao falta numa instalacao nova -- que e
	 * a falha que isto guarda.
	 *
	 * LE O PARSER COMPARTILHADO, E NAO UM REGEX PROPRIO (#1241)
	 *
	 * Este metodo tinha o seu proprio regex, exigindo literalmente
	 * `CREATE TABLE {$table_name} (...) {$charset_collate}`. Duas formas reais
	 * escapavam dele, e o docblock de {@see self::declarations_by_table()} ja
	 * dizia por que isso aconteceria: *"uma segunda varredura privada aqui e
	 * como os tres acabariam medindo conjuntos diferentes"*.
	 *
	 *   - `RateLimitActivator` nomeia as tabelas `$table_limits`, `$table_logs`
	 *     e `$table_signals` -- 3 statements invisiveis.
	 *   - `RecruitmentActivator` fecha com `) ENGINE=InnoDB {$charset_collate};`
	 *     -- 9 statements invisiveis, e esse e o caso que produz FALSO
	 *     POSITIVO: sem enxergar o create, toda coluna incremental do ficheiro
	 *     parece ausente dele.
	 *
	 * @param string $path Caminho do ficheiro.
	 * @return list<string>
	 */
	private function create_table_columns( string $path ): array {
		$columns = array();

		foreach ( $this->create_statements() as $statement ) {
			if ( $statement['file'] !== $path ) {
				continue;
			}

			$columns = array_merge( $columns, $this->columns_of( $statement['sql'] ) );
		}

		return array_values( array_unique( $columns ) );
	}

	/**
	 * Colunas que os dois idiomas incrementais entregam, num ficheiro.
	 *
	 * AS DUAS FORMAS DE ASPAS SAO O DETALHE QUE CUSTA (#1241)
	 *
	 * O tipo aparece entre aspas SIMPLES quando e simples (`'LONGTEXT NULL'`) e
	 * entre aspas DUPLAS quando carrega um `COMMENT '...'` dentro. Um extrator
	 * que aceite so o primeiro caso derruba justamente as declaracoes mais
	 * ricas -- as 13 do `AudienceActivator` --, e o numero cai de forma que
	 * parece boa noticia. E o extrator tendo parado de olhar.
	 *
	 * O TIPO E O DISCRIMINADOR, E NAO O NOME
	 *
	 * Ancorar so no nome da coluna faria um esquema de argumento do REST
	 * (`'code' => array( 'type' => 'string' )`) contar como coluna. O que
	 * separa os dois e a chamada: o idioma singular exige literalmente
	 * `add_column_if_missing(`, e o plural exige a chave `'type'` na linha
	 * seguinte a `array(`.
	 *
	 * @param string $source Conteudo do ficheiro.
	 * @return list<string>
	 */
	private function incremental_columns( string $source ): array {
		$names = array();

		// Singular: `add_column_if_missing( $tabela, 'coluna', 'tipo' ...`.
		preg_match_all(
			'/add_column_if_missing\s*\(\s*[^,]{1,120}?,\s*[\'"]([a-z_][a-z0-9_]*)[\'"]\s*,/s',
			$source,
			$singular
		);

		// Plural: `'coluna' => array( 'type' => ...`, que e o que distingue uma
		// chave de coluna de qualquer outra string citada por perto.
		preg_match_all(
			'/[\'"]([a-z_][a-z0-9_]*)[\'"]\s*=>\s*array\(\s*\n\s*[\'"]type[\'"]\s*=>/m',
			$source,
			$plural
		);

		foreach ( array_merge( $singular[1], $plural[1] ) as $name ) {
			$names[] = $name;
		}

		$names = array_values( array_unique( $names ) );

		return array_values( array_diff( $names, $this->staging_columns( $source ) ) );
	}

	/**
	 * Colunas de ESTAGIO, que nao podem estar no `CREATE TABLE` (#1241).
	 *
	 * POR QUE ELAS SAO O CONTRARIO DE DIVIDA
	 *
	 * A migracao DATETIME -> BIGINT do #249 adiciona uma coluna temporaria,
	 * preenche-a, e no fim a RENOMEIA para o nome definitivo
	 * (`ALTER TABLE ... CHANGE submission_date_ts submission_date ...`). O
	 * helper compartilhado monta o nome como `$column . '_ts'`.
	 *
	 * Ela e adicionada por `add_column_if_missing()`, entao a varredura a
	 * encontra -- mas declara-la numa instalacao nova criaria uma coluna
	 * permanente sobre a qual o `CHANGE` tentaria renomear outra. O passo 3 da
	 * #1241, seguido ao pe da letra, introduziria esse defeito em tres das
	 * quinze colunas que ela lista.
	 *
	 * O SINAL ESTA NO PROPRIO FICHEIRO, E NAO NUM SUFIXO
	 *
	 * Excluir tudo que termina em `_ts` seria uma regra sobre o NOME, e uma
	 * coluna legitima com esse sufixo passaria a ser ignorada em silencio. O
	 * que se procura aqui e a evidencia de que o ficheiro a renomeia embora: o
	 * nome aparecendo como ORIGEM de um `CHANGE`. Uma coluna que fica nao tem
	 * essa linha.
	 *
	 * @param string $source Conteudo do ficheiro.
	 * @return list<string>
	 */
	private function staging_columns( string $source ): array {
		preg_match_all(
			'/CHANGE\s+%i\s+%i[^;]*?,\s*[\'"]([a-z_][a-z0-9_]*)[\'"]\s*,\s*[\'"][a-z_][a-z0-9_]*[\'"]/s',
			$source,
			$renamed
		);

		return array_values( array_unique( $renamed[1] ) );
	}

	/**
	 * Todo `CREATE TABLE` de `includes/`, pelo parser compartilhado.
	 *
	 * @return list<array{table: string|null, sql: string, file: string}>
	 */
	private function create_statements(): array {
		require_once dirname( __DIR__, 2 ) . '/.github/scripts/ffc-create-statements.php';

		/** @var list<array{table: string|null, sql: string, file: string}> $statements */
		$statements = ffc_create_statements( dirname( __DIR__, 2 ) . '/includes' );

		return $statements;
	}

	/**
	 * Nomes de coluna do corpo de um `CREATE TABLE`.
	 *
	 * O `ENGINE=...` opcional antes do `{$charset_collate}` e o que faltava
	 * (#1241): sem ele, os nove statements do `RecruitmentActivator` nao tinham
	 * corpo extraido -- nas DUAS direcoes desta guarda, porque
	 * {@see self::declarations_by_table()} usa o mesmo regex.
	 *
	 * @param string $sql Statement completo.
	 * @return list<string>
	 */
	private function columns_of( string $sql ): array {
		if ( ! preg_match( '/CREATE TABLE [^(]*\((.*)\)\s*(?:ENGINE=\w+\s*)?\{?\$charset_collate/s', $sql, $body ) ) {
			return array();
		}

		$columns = array();

		foreach ( explode( "\n", $body[1] ) as $line ) {
			$line = rtrim( trim( $line ), ',' );

			if ( '' === $line || preg_match( '/^(?:PRIMARY KEY|UNIQUE KEY|KEY)\b/i', $line ) ) {
				continue;
			}

			if ( preg_match( '/^`?([a-z_][a-z0-9_]*)`?\s/i', $line, $column ) ) {
				$columns[] = $column[1];
			}
		}

		return $columns;
	}

	/**
	 * A varredura tem de MEDIR todo ficheiro que declara e completa uma tabela.
	 *
	 * A autoverificacao anterior cobrava so que a lista nao fosse vazia -- e
	 * ela nunca era, porque o idioma plural existe em dois ficheiros. Dois
	 * medidos de seis pareciam varredura viva (#1241).
	 *
	 * Esta cobra a mesma coisa que o portao do `dbDelta` cobra: que a contagem
	 * vista bata com a de uma REDE MAIS LARGA, montada aqui de forma
	 * deliberadamente ingenua. Se as duas divergirem, alguem estreitou a
	 * varredura sem perceber.
	 */
	public function test_the_scan_measures_every_file_that_declares_and_completes_a_table(): void {
		$measured = $this->activator_files();

		$this->assertNotEmpty(
			$measured,
			'No activator declares both a CREATE TABLE and an incremental column call — the guard premise is gone.'
		);

		$wide     = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = $file->getPathname();

			if ( substr( $path, -4 ) !== '.php' ) {
				continue;
			}

			$source = (string) file_get_contents( $path );

			if ( preg_match( '/add_columns?_if_missing\s*\(/', $source )
				&& false !== strpos( $source, 'CREATE TABLE' ) ) {
				$wide[] = $path;
			}
		}

		sort( $wide );

		$this->assertSame(
			$wide,
			$measured,
			"A rede larga acha ficheiros que a varredura nao mede — ela foi estreitada."
		);
	}

	/**
	 * Todo `CREATE TABLE` que o parser compartilhado acha tem de ter corpo lido.
	 *
	 * E a regra que o portao do `dbDelta` enuncia -- *"nunca conte como limpo o
	 * que nao foi olhado"* -- aplicada ao regex de corpo, que e onde as duas
	 * direcoes desta guarda se encontram.
	 *
	 * Ela teria pego a cegueira do `ENGINE=InnoDB` no dia em que ela entrou: o
	 * parser achava 34 statements e o regex extraia 25, e os 9 que faltavam
	 * eram todos do `RecruitmentActivator` -- em AMBAS as direcoes.
	 */
	public function test_every_create_statement_the_parser_finds_has_a_readable_body(): void {
		$statements = $this->create_statements();

		$this->assertNotEmpty( $statements, 'O parser compartilhado nao achou statement nenhum.' );

		$unreadable = array();

		foreach ( $statements as $statement ) {
			if ( array() === $this->columns_of( $statement['sql'] ) ) {
				$unreadable[] = ( $statement['table'] ?? '?' ) . ' (' . basename( $statement['file'] ) . ')';
			}
		}

		$this->assertSame(
			array(),
			$unreadable,
			"Statements que o parser acha e este ficheiro nao consegue ler — a varredura os conta como limpos sem olhar:\n  "
			. implode( "\n  ", $unreadable )
		);
	}

	public function test_every_incremental_column_is_also_in_a_create_table(): void {
		$failures = array();

		foreach ( $this->activator_files() as $path ) {
			$source      = (string) file_get_contents( $path );
			$create      = $this->create_table_columns( $path );
			$incremental = $this->incremental_columns( $source );

			// A vacuidade e medida ANTES da exclusao de estagio. "O extrator nao
			// achou nada" e defeito; "tudo que achou era coluna de estagio" e
			// estado legitimo -- e o caso do `RecruitmentActivator`, cuja unica
			// chamada incremental e a da migracao #249. Cobrar a lista ja
			// filtrada confundiria os dois (#1241).
			$this->assertNotEmpty(
				array_merge( $incremental, $this->staging_columns( $source ) ),
				basename( $path ) . ': the incremental-column scan found nothing, so its check is vacuous.'
			);

			foreach ( array_diff( $incremental, $create ) as $missing ) {
				$failures[] = basename( $path ) . ' :: ' . $missing;
			}
		}

		$this->assertSame(
			array(),
			$failures,
			"A column that add_columns_if_missing() gives an existing install is absent from every"
			. " CREATE TABLE in the same file, so a fresh install would not have it:\n  "
			. implode( "\n  ", $failures )
		);
	}

	/**
	 * Every `CREATE TABLE` in `includes/`, grouped by the table it writes to.
	 *
	 * Uses the same extraction as `ActivatorSqlTest` and the dbDelta idempotence
	 * gate — one parser, three consumers, for the reason `uninstall.php` is one
	 * manifest. A private second scan here is how the three would end up
	 * measuring different sets.
	 *
	 * @return array<string, array<string, array{columns: list<string>, keys: list<string>}>>
	 *         Table => file basename => its declared columns and key names.
	 */
	private function declarations_by_table(): array {
		$by_table = array();

		foreach ( $this->create_statements() as $statement ) {
			if ( null === $statement['table'] ) {
				continue;
			}

			// O `ENGINE=` opcional e o que faltava (#1241): sem ele os nove
			// statements do `RecruitmentActivator` saiam desta direcao tambem.
			if ( ! preg_match( '/CREATE TABLE [^(]*\((.*)\)\s*(?:ENGINE=\w+\s*)?\{?\$charset_collate/s', $statement['sql'], $body ) ) {
				continue;
			}

			$columns = array();
			$keys    = array();

			foreach ( explode( "\n", $body[1] ) as $line ) {
				$line = rtrim( trim( $line ), ',' );

				if ( '' === $line ) {
					continue;
				}

				// `PRIMARY KEY` is unnamed, so it is covered by the column set
				// implicitly and comparing it would compare nothing.
				if ( preg_match( '/^(?:UNIQUE )?KEY\s+`?([a-z_][a-z0-9_]*)`?/i', $line, $key ) ) {
					$keys[] = $key[1];
					continue;
				}

				if ( preg_match( '/^(?:PRIMARY KEY)\b/i', $line ) ) {
					continue;
				}

				if ( preg_match( '/^`?([a-z_][a-z0-9_]*)`?\s/i', $line, $column ) ) {
					$columns[] = $column[1];
				}
			}

			$by_table[ $statement['table'] ][ basename( $statement['file'] ) ] = array(
				'columns' => $columns,
				'keys'    => $keys,
			);
		}

		return array_filter(
			$by_table,
			static function ( array $declarations ): bool {
				return count( $declarations ) > 1;
			}
		);
	}

	public function test_the_cross_file_scan_finds_tables_declared_more_than_once(): void {
		// The two checks below compare declarations against each other, so an
		// empty result would make both pass having looked at nothing — the
		// silent-pass shape #1087 passo 6 found in four guards.
		$this->assertNotEmpty(
			$this->declarations_by_table(),
			'No table is declared by more than one file any more. If the duplication was genuinely '
			. 'retired, delete these two checks; if the scan broke, fix it — do not leave it passing on nothing.'
		);
	}

	public function test_every_declaration_of_a_table_names_the_same_columns(): void {
		$failures = array();

		foreach ( $this->declarations_by_table() as $table => $declarations ) {
			$union = array();

			foreach ( $declarations as $declaration ) {
				$union = array_unique( array_merge( $union, $declaration['columns'] ) );
			}

			foreach ( $declarations as $file => $declaration ) {
				foreach ( array_diff( $union, $declaration['columns'] ) as $missing ) {
					$failures[] = $table . ' :: ' . $file . ' is missing ' . $missing;
				}
			}
		}

		$this->assertSame(
			array(),
			$failures,
			"One declaration of a table names a column the others do not, so which columns an\n"
			. "install ends up with depends on which path created the table — the #1091 class,\n"
			. "one level up:\n  " . implode( "\n  ", $failures )
		);
	}

	public function test_every_declaration_of_a_table_names_the_same_keys(): void {
		$failures = array();

		foreach ( $this->declarations_by_table() as $table => $declarations ) {
			$union = array();

			foreach ( $declarations as $declaration ) {
				$union = array_unique( array_merge( $union, $declaration['keys'] ) );
			}

			foreach ( $declarations as $file => $declaration ) {
				foreach ( array_diff( $union, $declaration['keys'] ) as $missing ) {
					$failures[] = $table . ' :: ' . $file . ' is missing ' . $missing;
				}
			}
		}

		$this->assertSame(
			array(),
			$failures,
			"One declaration of a table names an index the others do not. When both paths run,\n"
			. "the table carries both — which is how ffc_reregistration_submissions ended up with\n"
			. "two indexes on auth_code and two on magic_token, under different names:\n  "
			. implode( "\n  ", $failures )
		);
	}

	/**
	 * The two composite indexes `Activator::add_columns()` creates through
	 * `add_index_if_missing()` — the ones a fresh install lacked until #1091.
	 *
	 * Kept as a named case rather than folded into the scan above: index calls
	 * take a raw SQL fragment, not a declarative array, so there is nothing to
	 * compare structurally without parsing SQL.
	 */
	public function test_the_submissions_create_table_carries_its_composite_indexes(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-ffc-activator.php' );

		$this->assertStringContainsString( 'idx_form_cpf_new', $source );
		$this->assertStringContainsString( 'idx_form_rf', $source );
	}
}
