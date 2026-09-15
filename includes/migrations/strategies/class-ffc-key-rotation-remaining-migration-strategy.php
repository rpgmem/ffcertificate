<?php
/**
 * Re-encrypt the PII areas that `KeyRotationMigrationStrategy` never reached.
 *
 * WHY A SECOND STRATEGY AND NOT AN EXTENSION OF THE FIRST (#1236)
 *
 * `KeyRotationMigrationStrategy` walks exactly two tables -- `ffc_submissions`
 * and `ffc_self_scheduling_appointments` -- and latches a `completed` boolean
 * in its own state. Its `calculate_status()` short-circuits to `pending = 0`
 * BEFORE looking at any table, so on an install that already finished the
 * rotation, adding targets to it would keep reporting "complete" while never
 * touching them: a false green over exactly the data at risk. Re-arming that
 * flag instead would force a pointless re-walk of the two tables already done.
 *
 * A separate strategy gets its own cursor, its own completion flag and its own
 * card in Settings -> Migrations, which is the evidence shape this project
 * prefers: a counter an operator can read in production.
 *
 * WHAT IS AT STAKE, AND IT IS NOT PERFORMANCE
 *
 * A value still encrypted under the WordPress-derived key depends on
 * `SECURE_AUTH_KEY` / `LOGGED_IN_KEY` / `NONCE_KEY`. Rotating those -- routine
 * security hygiene, offered as a one-click action by several hosts -- makes
 * the data PERMANENTLY unreadable. Decoupling exists to sever that dependency;
 * until this migration runs it is severed for two areas only.
 *
 * SCOPE OF THIS FILE
 *
 * Reregistration submission bodies (the `data` JSON) only, so far. The other
 * two areas #1236 names join as additional targets in {@see self::targets()},
 * which is why the cursor is keyed per target from the start rather than being
 * a single scalar -- each is a different storage SHAPE, not merely another
 * table: `ffc_recruitment_candidate` is columns with paired search hashes to
 * rebuild, and the user profile is usermeta keyed by user rather than rows.
 *
 * The recruitment target is NOT hypothetical: a production install measured
 * 7.695 candidate rows, every one of them written inside a single hour on one
 * day -- a bulk import. That shape matters twice over. It means the whole set
 * shares one salt era, so a handful of rows answers for all of them; and it
 * explains why no duplicate candidate was ever observed there, since a second
 * import never ran to re-find those people. The absence of duplicates is
 * therefore evidence about the IMPORT HISTORY, never about the hashes.
 *
 * @package FreeFormCertificate
 * @since   6.25.0
 */

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\ArrayValue;
use FreeFormCertificate\Core\Encryption;
use WP_Error;

/*
 * SEM `phpcs:disable` de arquivo, de proposito (#1236).
 *
 * O #1035 colapsou anotacoes por linha em disables de arquivo onde a
 * justificativa era propriedade da CLASSE: toda tabela tocada ali e `ffc_*`, para
 * as quais o WordPress nao expoe API. Aqui isso deixou de valer -- o alvo de
 * perfil le `wp_usermeta`, uma tabela do core --, e
 * `PhpcsSuppressionTest::test_file_level_direct_query_disables_only_cover_plugin_tables()`
 * cobra exatamente essa honestidade. Das duas saidas que o proprio guarda
 * nomeia, esta e a segunda: largar o disable e anotar por linha.
 */

/**
 * Finishes the key rotation over the areas the original strategy never walked.
 */
class KeyRotationRemainingMigrationStrategy implements MigrationStrategyInterface {

	/**
	 * Option holding cursor + fingerprint + completion for this strategy.
	 */
	private const STATE_OPTION = 'ffc_key_rotation_remaining_state';

	/**
	 * Target key for the reregistration submission bodies.
	 */
	private const TARGET_REREGISTRATION = 'reregistration_data';

	/**
	 * Rows examined per batch.
	 *
	 * Smaller than the original strategy's 100 because each row here carries a
	 * whole JSON body with an unbounded number of encrypted values, where a row
	 * there carries a fixed handful of columns.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Target key for the recruitment candidate columns.
	 */
	private const TARGET_RECRUITMENT = 'recruitment_candidate';

	/**
	 * Target key for the sensitive user-profile usermeta.
	 */
	private const TARGET_USER_PROFILE = 'user_profile_meta';

	/**
	 * Chave de meta cifrada => meta de hash pareada (null quando o campo nao e
	 * pesquisavel por hash).
	 *
	 * AS CHAVES SAO LITERAIS AQUI DE PROPOSITO. A fonte de verdade e
	 * `UserProfileFieldMap`, no modulo UserDashboard -- e importa-la criaria a
	 * aresta `Migrations > UserDashboard`, que nao existe na baseline do
	 * `ModuleBoundaryTest`. A estrategia ja fixa nomes de coluna dos outros dois
	 * alvos pela mesma razao; quem cobra a concordancia e
	 * `KeyRotationUserProfileTargetTest`, que vive fora do grafo de modulos e
	 * reprova se o mapa ganhar um campo sensivel que esta lista nao conheca.
	 *
	 * `ffc_user_profiles` NAO entra: suas seis colunas sao texto puro
	 * (`sensitive => false` no mapa), entao nao ha o que recifrar la. A #1236
	 * juntava as duas coisas numa linha so; medido, o ciphertext mora apenas na
	 * usermeta.
	 *
	 * @return array<string, string|null>
	 */
	private function profile_meta_map(): array {
		return array(
			'ffc_user_cpf' => 'ffc_user_cpf_hash',
			'ffc_user_rf'  => 'ffc_user_rf_hash',
			'ffc_user_rg'  => null,
		);
	}

	/**
	 * Targets this strategy walks, in order.
	 *
	 * Adding the profile usermeta means adding an entry here plus a `migrate_*`
	 * method; the cursor, the status maths and the completion latch already work
	 * per target.
	 *
	 * @return array<int, string>
	 */
	private function targets(): array {
		return array( self::TARGET_RECRUITMENT, self::TARGET_REREGISTRATION, self::TARGET_USER_PROFILE );
	}

	/**
	 * Encrypted column => paired searchable hash column, for the recruitment
	 * candidate table.
	 *
	 * Rebuilding these hashes is the half that fixes a LIVE defect rather than
	 * merely preventing a future one: `Encryption::hash()` reads
	 * `FFC_HASH_SALT` as soon as it is defined, so every hash written before
	 * the decoupling is unreachable by every lookup made after it --
	 * `RecruitmentCandidateReader::get_by_cpf_hash()` compares for equality.
	 * Four consumers break, and the fourth is not a search: the importer's
	 * dedup (`CandidatePersister`), which on the next import would create a
	 * second row for someone it cannot find.
	 *
	 * @return array<string, string>
	 */
	private function recruitment_columns(): array {
		return array(
			'cpf_encrypted'   => 'cpf_hash',
			'rf_encrypted'    => 'rf_hash',
			'email_encrypted' => 'email_hash',
		);
	}

	/**
	 * Full table name for the reregistration submissions.
	 *
	 * @return string
	 */
	private function reregistration_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ffc_reregistration_submissions';
	}

	/**
	 * Full table name for the recruitment candidates.
	 *
	 * @return string
	 */
	private function recruitment_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ffc_recruitment_candidate';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Registry key.
	 * @param array<string, mixed> $migration_config Registry config.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		unset( $migration_key, $migration_config );

		$total = $this->count_total();

		// A changed active key invalidates everything already walked: rows
		// re-encrypted under the previous key are legacy again.
		if ( ! $this->fingerprint_matches() ) {
			return array(
				'total'       => $total,
				'migrated'    => 0,
				'pending'     => $total,
				'percent'     => ( $total > 0 ) ? 0.0 : 100.0,
				'is_complete' => ( 0 === $total ),
			);
		}

		if ( $this->is_completed() ) {
			return array(
				'total'       => $total,
				'migrated'    => $total,
				'pending'     => 0,
				'percent'     => 100.0,
				'is_complete' => true,
			);
		}

		$migrated = $this->count_migrated();
		$pending  = max( 0, $total - $migrated );

		return array(
			'total'       => $total,
			'migrated'    => $migrated,
			'pending'     => $pending,
			'percent'     => ( $total > 0 ) ? round( ( $migrated / $total ) * 100, 2 ) : 100.0,
			'is_complete' => ( 0 === $pending ),
		);
	}

	/**
	 * Rows carrying at least one ciphertext, summed across every target.
	 *
	 * @return int
	 */
	private function count_total(): int {
		$total = 0;
		foreach ( $this->targets() as $target ) {
			$total += $this->count_target( $target, false );
		}

		return $total;
	}

	/**
	 * Rows already behind the cursor, summed across every target.
	 *
	 * @return int
	 */
	private function count_migrated(): int {
		$migrated = 0;
		foreach ( $this->targets() as $target ) {
			$migrated += $this->count_target( $target, true );
		}

		return $migrated;
	}

	/**
	 * Count rows of one target, optionally only those behind its cursor.
	 *
	 * @param string $target        Target key.
	 * @param bool   $behind_cursor Restrict to rows already walked.
	 * @return int
	 */
	private function count_target( string $target, bool $behind_cursor ): int {
		global $wpdb;

		if ( self::TARGET_USER_PROFILE === $target ) {
			return $this->count_user_profile_pending( $behind_cursor );
		}

		$table = $this->table_for( $target );
		if ( '' === $table || ! $this->table_exists( $table ) ) {
			return 0;
		}

		$where  = $this->pending_predicate( $target );
		$values = array( $table );

		if ( self::TARGET_REREGISTRATION === $target ) {
			$values[] = '%' . $wpdb->esc_like( Encryption::V2_PREFIX ) . '%';
		}

		if ( $behind_cursor ) {
			$where   .= ' AND id <= %d';
			$values[] = $this->get_cursor( $target );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $where comes only from pending_predicate(), which returns one of two hard-coded literals and never touches request data; every value, the table included, is bound through prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", $values ) );
	}

	/**
	 * The WHERE fragment that identifies rows this target still has to consider.
	 *
	 * Literal fragments only -- the bound values are appended by the caller.
	 *
	 * @param string $target Target key.
	 * @return string
	 */
	private function pending_predicate( string $target ): string {
		if ( self::TARGET_REREGISTRATION === $target ) {
			return 'data LIKE %s';
		}

		return '( cpf_encrypted IS NOT NULL OR rf_encrypted IS NOT NULL OR email_encrypted IS NOT NULL )';
	}

	/**
	 * Table backing one target.
	 *
	 * @param string $target Target key.
	 * @return string Empty when the target has no table of its own.
	 */
	private function table_for( string $target ): string {
		if ( self::TARGET_REREGISTRATION === $target ) {
			return $this->reregistration_table();
		}

		if ( self::TARGET_RECRUITMENT === $target ) {
			return $this->recruitment_table();
		}

		return '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Registry key.
	 * @param array<string, mixed> $migration_config Registry config.
	 * @param int                  $batch_number     Batch counter.
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		unset( $migration_key, $migration_config, $batch_number );

		$can_run = $this->can_run( '', array() );
		if ( $can_run instanceof WP_Error ) {
			return array(
				'success' => false,
				'message' => $can_run->get_error_message(),
			);
		}

		// The fingerprint is stamped on the first batch of a run. A key changed
		// mid-migration re-arms from zero rather than leaving a half-rotated set
		// silently marked complete.
		$this->stamp_fingerprint();

		// Um alvo por lote: o primeiro que ainda tenha linhas adiante do seu
		// cursor. Misturar alvos num mesmo lote tornaria o cursor ambíguo e
		// impediria retomar de onde parou.
		$result = array(
			'processed' => 0,
			'errors'    => array(),
		);

		foreach ( $this->targets() as $target ) {
			if ( $this->count_target( $target, false ) <= $this->count_target( $target, true ) ) {
				continue;
			}

			if ( self::TARGET_RECRUITMENT === $target ) {
				$result = $this->migrate_recruitment_batch();
			} elseif ( self::TARGET_REREGISTRATION === $target ) {
				$result = $this->migrate_reregistration_batch();
			} else {
				$result = $this->migrate_user_profile_batch();
			}
			break;
		}

		$status = $this->calculate_status( '', array() );
		if ( 0 === $status['pending'] && empty( $result['errors'] ) ) {
			$this->mark_completed();
		}

		return array(
			'success'   => true,
			'processed' => $result['processed'],
			'pending'   => $status['pending'],
			'has_more'  => $status['pending'] > 0,
			'errors'    => $result['errors'],
		);
	}

	/**
	 * Re-encrypt one batch of reregistration bodies.
	 *
	 * @return array{processed: int, errors: array<int, string>}
	 */
	private function migrate_reregistration_batch(): array {
		global $wpdb;

		$table  = $this->reregistration_table();
		$cursor = $this->get_cursor( self::TARGET_REREGISTRATION );
		$errors = array();

		if ( ! $this->table_exists( $table ) ) {
			return array(
				'processed' => 0,
				'errors'    => $errors,
			);
		}

		/**
		 * Tipagem explicita das linhas, o idioma que as outras classes que leem
		 * linhas usam (ver `AbstractRepository`, `AppointmentReader`). Sem ela o
		 * retorno de `get_results()` e `mixed` e o gate "Row shapes (level 9)"
		 * reprova cada acesso de offset e cada cast -- tolerancia zero, por
		 * decisao registrada no proprio workflow.
		 *
		 * `string|null` e nao um shape literal: o MySQL devolve toda coluna como
		 * string, e `id` chega como numerico em texto.
		 *
		 * @var list<array<string, string|null>>|null $rows
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statement de dados contra tabela `ffc_*` do plugin numa migracao: o WordPress nao expoe API para ela, e uma leitura em cache e exatamente o que um cursor de migracao nao pode tomar.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, data FROM %i WHERE id > %d AND data LIKE %s ORDER BY id ASC LIMIT %d',
				$table,
				$cursor,
				'%' . $wpdb->esc_like( Encryption::V2_PREFIX ) . '%',
				self::BATCH_SIZE
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || array() === $rows ) {
			// Nothing left ahead of the cursor: park it at the end so the status
			// maths reports complete instead of stalling one row short.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statement de dados contra tabela `ffc_*` do plugin numa migracao: o WordPress nao expoe API para ela, e uma leitura em cache e exatamente o que um cursor de migracao nao pode tomar.
			$max_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $table ) );
			if ( $max_id > $cursor ) {
				$this->set_cursor( self::TARGET_REREGISTRATION, $max_id );
			}

			return array(
				'processed' => 0,
				'errors'    => $errors,
			);
		}

		$processed = 0;
		$last_id   = $cursor;

		foreach ( $rows as $row ) {
			$last_id = (int) $row['id'];

			$rewritten = $this->rewrite_body( (string) ( $row['data'] ?? '' ), $last_id, $errors );
			if ( null === $rewritten ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statement de dados contra tabela `ffc_*` do plugin numa migracao: o WordPress nao expoe API para ela, e uma leitura em cache e exatamente o que um cursor de migracao nao pode tomar.
			$updated = $wpdb->update(
				$table,
				array( 'data' => $rewritten ),
				array( 'id' => $last_id ),
				array( '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				$errors[] = sprintf(
					/* translators: %d: submission ID */
					__( 'Could not write the re-encrypted body for reregistration submission %d.', 'ffcertificate' ),
					$last_id
				);
				continue;
			}

			++$processed;
		}

		$this->set_cursor( self::TARGET_REREGISTRATION, $last_id );

		return array(
			'processed' => $processed,
			'errors'    => $errors,
		);
	}

	/**
	 * Quantos USUARIOS ainda tem meta sensivel a considerar neste alvo.
	 *
	 * Conta usuarios distintos, e nao linhas de meta, porque e assim que o lote
	 * pagina -- um usuario com tres metas e uma unidade de trabalho, nao tres.
	 * Contar linhas faria a comparacao `count(false) > count(true)` do
	 * `execute()` discordar do que o lote de fato consome.
	 *
	 * @param bool $behind_cursor Restringe ao que ja ficou para tras do cursor.
	 * @return int
	 */
	private function count_user_profile_pending( bool $behind_cursor ): int {
		global $wpdb;

		$meta_keys    = array_keys( $this->profile_meta_map() );
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		$values   = array( $wpdb->usermeta );
		$values   = array_merge( $values, $meta_keys );
		$values[] = '%' . $wpdb->esc_like( Encryption::V2_PREFIX ) . '%';

		$cursor_sql = '';
		if ( $behind_cursor ) {
			$cursor_sql = ' AND user_id <= %d';
			$values[]   = $this->get_cursor( self::TARGET_USER_PROFILE );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Os fragmentos interpolados sao marcadores gerados aqui a partir da contagem de chaves e um literal fixo de cursor; todo valor, a tabela inclusive, passa por prepare(). Leitura de migracao: uma resposta em cache e exatamente o que um cursor nao pode tomar.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM %i WHERE meta_key IN ({$placeholders}) AND meta_value LIKE %s{$cursor_sql}", $values ) );
	}

	/**
	 * Recifra a meta sensivel de um lote de usuarios e refaz os hashes.
	 *
	 * POR QUE A PAGINA E DE USUARIOS, E NAO DE LINHAS DE META
	 *
	 * Um usuario carrega ate tres metas cifradas. Paginando por linha de meta e
	 * avancando o cursor por `user_id`, um usuario partido entre dois lotes
	 * perderia as metas que ficaram para tras -- o cursor ja teria passado por
	 * ele. Paginar por usuario torna a unidade de trabalho indivisivel.
	 *
	 * POR QUE A ESCRITA VAI PELA API DO WordPress
	 *
	 * A unica consulta direta e o SELECT que resolve a pagina de `user_id`: um
	 * keyset (`user_id > %d`) que `WP_User_Query` nao sabe expressar, e trocar o
	 * keyset por `offset` arriscaria pular um usuario -- que aqui significa PII
	 * ilegivel para sempre depois da rotacao de salts. Lido e escrito, porem,
	 * vai por `get_user_meta()` / `update_user_meta()`, que passam pelo cache de
	 * objeto e mantem o caminho de escrita identico ao do
	 * `UserProfileService` -- inclusive o hash so gravado quando muda.
	 *
	 * @return array{processed: int, errors: array<int, string>}
	 */
	private function migrate_user_profile_batch(): array {
		global $wpdb;

		$cursor = $this->get_cursor( self::TARGET_USER_PROFILE );
		$errors = array();
		$map    = $this->profile_meta_map();

		$meta_keys    = array_keys( $map );
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		$values   = array( $wpdb->usermeta );
		$values   = array_merge( $values, $meta_keys );
		$values[] = '%' . $wpdb->esc_like( Encryption::V2_PREFIX ) . '%';
		$values[] = $cursor;
		$values[] = self::BATCH_SIZE;

		/**
		 * Uma coluna de ids, tipada explicitamente porque `get_col()` devolve
		 * `mixed` para o analisador.
		 *
		 * @var list<string>|null $ids
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Como em count_user_profile_pending(): fragmentos gerados aqui, valores todos por prepare(), e cache proibido num cursor de migracao.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM %i WHERE meta_key IN ({$placeholders}) AND meta_value LIKE %s AND user_id > %d ORDER BY user_id ASC LIMIT %d", $values ) );

		if ( ! is_array( $ids ) || array() === $ids ) {
			return array(
				'processed' => 0,
				'errors'    => $errors,
			);
		}

		$processed = 0;
		$last_id   = $cursor;

		foreach ( $ids as $raw_id ) {
			$user_id = (int) $raw_id;
			$last_id = $user_id;

			foreach ( $map as $meta_key => $hash_key ) {
				$stored = get_user_meta( $user_id, $meta_key, true );

				// O prefixo e um FILTRO DE CUSTO, nao de correcao: `decrypt()`
				// ja devolveria null para o que nao sabe decifrar, e o guarda
				// seguinte protegeria o valor de qualquer jeito (medido por
				// mutacao). O que ele evita e a CHAMADA: abrir o envelope,
				// derivar a comparacao HMAC e chamar `openssl_decrypt` para
				// cada meta em texto claro, num laco que percorre todos os
				// usuarios do site.
				//
				// Ate o #1234 havia um segundo motivo, maior: cada falha
				// gravava uma linha em `ffc_activity_log`, sem teto. O teto
				// agora existe (cinco por requisicao), entao o que sobra e o
				// custo da decifragem em si -- suficiente, mas nao mais o
				// argumento dramatico que este comentario carregava antes.
				if ( ! is_string( $stored ) || 0 !== strpos( $stored, Encryption::V2_PREFIX ) ) {
					continue;
				}

				$plain = Encryption::decrypt( $stored );
				if ( null === $plain || '' === $plain ) {
					$errors[] = sprintf(
						/* translators: 1: meta key, 2: user ID */
						__( 'Could not decrypt %1$s for user %2$d — left untouched (the key may be unrecoverable).', 'ffcertificate' ),
						$meta_key,
						$user_id
					);
					continue;
				}

				$reencrypted = Encryption::encrypt( $plain );
				if ( null === $reencrypted ) {
					continue;
				}
				update_user_meta( $user_id, $meta_key, $reencrypted );

				if ( null === $hash_key ) {
					continue;
				}

				$hash = Encryption::hash( $plain );
				if ( null === $hash ) {
					continue;
				}

				// So escreve quando muda, espelhando os outros dois alvos: uma
				// linha ja sob o salt atual nao custa escrita.
				$current = get_user_meta( $user_id, $hash_key, true );
				if ( ! is_string( $current ) || ! hash_equals( $hash, $current ) ) {
					update_user_meta( $user_id, $hash_key, $hash );
				}
			}

			++$processed;
		}

		$this->set_cursor( self::TARGET_USER_PROFILE, $last_id );

		return array(
			'processed' => $processed,
			'errors'    => $errors,
		);
	}

	/**
	 * Re-encrypt one batch of recruitment candidates and rebuild their hashes.
	 *
	 * RECONSTRUIR O HASH PODE COLIDIR -- E O CASO EM QUE ISSO ACONTECE E
	 * EXATAMENTE O QUE A #1236 DESCREVE.
	 *
	 * Este bloco ja afirmou o contrario, e a afirmacao estava errada. Ela dizia
	 * que duas linhas da mesma pessoa sao "o que as restricoes ja proibem".
	 * Nao sao: `cpf_hash` e `rf_hash` sao UNIQUE sobre o VALOR do hash, nao
	 * sobre a pessoa. Sob salts diferentes a mesma pessoa produz valores
	 * diferentes, e o par passa pela restricao sem esbarrar nela.
	 *
	 * E esse par existe justamente pelo defeito que esta migracao conserta. A
	 * parte 2 da #1236 levanta a hipotese: depois do desacoplamento,
	 * `RecruitmentCandidateReader::get_by_cpf_hash()` deixou de achar o
	 * candidato antigo, entao o dedup do importador (`CandidatePersister`)
	 * criou uma linha NOVA em vez de atualizar a existente.
	 *
	 * Onde isso aconteceu, reconstruir o hash da linha antiga produz o valor
	 * que a linha nova ja tem, e o `UPDATE` bate na UNIQUE. A consequencia nao
	 * e perda: o erro entra em `$errors`, o laco segue, e a linha simplesmente
	 * nao migra -- mas o card nunca chega a 0 pendentes ate que os duplicados
	 * sejam reconciliados a mao. Por isso a mensagem de erro carrega o
	 * `last_error` do banco: e ele que nomeia a chave e o valor duplicados.
	 *
	 * O que continua verdadeiro, e vale dizer para nao confundir os dois
	 * casos: uma tabela que nunca recebeu um par desses nao pode colidir aqui.
	 * Entradas distintas seguem distintas por SHA-256, e as duas eras produzem
	 * valores sem relacao entre si, entao uma tabela meio migrada tambem esta
	 * a salvo. A colisao e uma propriedade dos DADOS, nao do algoritmo.
	 *
	 * The hash is only written when it actually differs, mirroring the original
	 * strategy -- a row already under the current salt costs no write.
	 *
	 * @return array{processed: int, errors: array<int, string>}
	 */
	private function migrate_recruitment_batch(): array {
		global $wpdb;

		$table  = $this->recruitment_table();
		$cursor = $this->get_cursor( self::TARGET_RECRUITMENT );
		$errors = array();

		if ( ! $this->table_exists( $table ) ) {
			return array(
				'processed' => 0,
				'errors'    => $errors,
			);
		}

		/**
		 * Tipagem explicita das linhas deste alvo.
		 *
		 * @see self::migrate_reregistration_batch() para o motivo da anotacao.
		 *
		 * Aqui o mapa e generico de proposito -- `array<string, string|null>` e
		 * nao um shape literal -- porque as colunas sao lidas por chave VARIAVEL
		 * (`$row[ $enc_col ]`), e um shape literal recusa o acesso por variavel.
		 *
		 * @var list<array<string, string|null>>|null $rows
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statement de dados contra tabela `ffc_*` do plugin numa migracao: o WordPress nao expoe API para ela, e uma leitura em cache e exatamente o que um cursor de migracao nao pode tomar.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, cpf_encrypted, cpf_hash, rf_encrypted, rf_hash, email_encrypted, email_hash
				 FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
				$table,
				$cursor,
				self::BATCH_SIZE
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || array() === $rows ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statement de dados contra tabela `ffc_*` do plugin numa migracao: o WordPress nao expoe API para ela, e uma leitura em cache e exatamente o que um cursor de migracao nao pode tomar.
			$max_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $table ) );
			if ( $max_id > $cursor ) {
				$this->set_cursor( self::TARGET_RECRUITMENT, $max_id );
			}

			return array(
				'processed' => 0,
				'errors'    => $errors,
			);
		}

		$processed = 0;
		$last_id   = $cursor;

		foreach ( $rows as $row ) {
			$last_id = (int) $row['id'];
			$update  = array();
			$formats = array();

			foreach ( $this->recruitment_columns() as $enc_col => $hash_col ) {
				$ciphertext = (string) ( $row[ $enc_col ] ?? '' );
				if ( '' === $ciphertext ) {
					continue;
				}

				$plain = Encryption::decrypt( $ciphertext );
				if ( null === $plain || '' === $plain ) {
					$errors[] = sprintf(
						/* translators: 1: column name, 2: candidate ID */
						__( 'Could not decrypt %1$s for recruitment candidate %2$d — left unchanged (the key may be unrecoverable).', 'ffcertificate' ),
						$enc_col,
						$last_id
					);
					continue;
				}

				$fresh = Encryption::encrypt( $plain );
				if ( null === $fresh ) {
					continue;
				}

				$update[ $enc_col ] = $fresh;
				$formats[]          = '%s';

				$new_hash = Encryption::hash( $plain );
				if ( null === $new_hash ) {
					continue;
				}

				$current_hash = isset( $row[ $hash_col ] ) ? (string) $row[ $hash_col ] : '';
				if ( '' === $current_hash || ! hash_equals( $new_hash, $current_hash ) ) {
					$update[ $hash_col ] = $new_hash;
					$formats[]           = '%s';
				}
			}

			if ( array() === $update ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statement de dados contra tabela `ffc_*` do plugin numa migracao: o WordPress nao expoe API para ela, e uma leitura em cache e exatamente o que um cursor de migracao nao pode tomar.
			$written = $wpdb->update( $table, $update, array( 'id' => $last_id ), $formats, array( '%d' ) );

			if ( false === $written ) {
				// `last_error` nomeia a chave e o valor quando o motivo e a
				// UNIQUE de `cpf_hash`/`rf_hash` -- o caso descrito no
				// docblock, que precisa de reconciliacao manual e nao de nova
				// tentativa. Sem ele a mensagem nao distingue isso de uma
				// falha de escrita qualquer.
				$detail = isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error
					? (string) $wpdb->last_error
					: '';

				$errors[] = '' !== $detail
					? sprintf(
						/* translators: 1: candidate ID, 2: database error message */
						__( 'Could not write the re-encrypted values for recruitment candidate %1$d: %2$s', 'ffcertificate' ),
						$last_id,
						$detail
					)
					: sprintf(
						/* translators: %d: candidate ID */
						__( 'Could not write the re-encrypted values for recruitment candidate %d.', 'ffcertificate' ),
						$last_id
					);
				continue;
			}

			++$processed;
		}

		$this->set_cursor( self::TARGET_RECRUITMENT, $last_id );

		return array(
			'processed' => $processed,
			'errors'    => $errors,
		);
	}

	/**
	 * Re-encrypt every ciphertext inside one submission body.
	 *
	 * WHICH VALUES ARE CIPHERTEXT IS READ FROM THE VALUE, NOT FROM THE CONFIG.
	 *
	 * The writer encrypts a field only when its `is_sensitive` flag is on
	 * ({@see \FreeFormCertificate\Reregistration\ReregistrationDataProcessor}),
	 * and that flag is editable -- a field switched off after some submissions
	 * were stored leaves ciphertext behind that the config no longer claims.
	 * Dispatching on the stored value's own `v2:` prefix is therefore the only
	 * reading that cannot drift, and it also skips plaintext without needing to
	 * know which fields exist.
	 *
	 * @param string             $json    Raw `data` column.
	 * @param int                $row_id  Submission ID, for error messages.
	 * @param array<int, string> $errors  Collected errors, by reference.
	 * @return string|null Rewritten JSON, or null when there is nothing to write.
	 */
	private function rewrite_body( string $json, int $row_id, array &$errors ): ?string {
		if ( '' === $json ) {
			return null;
		}

		$body = json_decode( $json, true );
		if ( ! is_array( $body ) ) {
			$errors[] = sprintf(
				/* translators: %d: submission ID */
				__( 'The body of reregistration submission %d is not valid JSON — left unchanged.', 'ffcertificate' ),
				$row_id
			);
			return null;
		}

		$fields = ArrayValue::array( $body, 'fields' );
		if ( array() === $fields ) {
			return null;
		}

		$changed = false;

		foreach ( $fields as $key => $value ) {
			if ( ! is_string( $value ) || 0 !== strpos( $value, Encryption::V2_PREFIX ) ) {
				continue;
			}

			$plain = Encryption::decrypt( $value );
			if ( null === $plain || '' === $plain ) {
				$errors[] = sprintf(
					/* translators: 1: field key, 2: submission ID */
					__( 'Could not decrypt %1$s on reregistration submission %2$d — left unchanged (the key may be unrecoverable).', 'ffcertificate' ),
					(string) $key,
					$row_id
				);
				continue;
			}

			$fresh = Encryption::encrypt( $plain );
			if ( null === $fresh ) {
				continue;
			}

			$fields[ $key ] = $fresh;
			$changed        = true;
		}

		if ( ! $changed ) {
			return null;
		}

		$body['fields'] = $fields;
		$encoded        = wp_json_encode( $body );

		return is_string( $encoded ) ? $encoded : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Mirrors the original strategy's gate: BOTH constants must be defined
	 * before anything runs. Rotating with only the key set would rebuild search
	 * hashes under the still-shared WordPress salt, forcing a second rotation
	 * the moment the salt is decoupled later.
	 *
	 * @param string               $migration_key    Registry key.
	 * @param array<string, mixed> $migration_config Registry config.
	 * @return true|WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		unset( $migration_key, $migration_config );

		if ( ! $this->is_decoupled() ) {
			return new WP_Error(
				'encryption_not_decoupled',
				__( 'Define both FFC_ENCRYPTION_KEY and FFC_HASH_SALT (32+ chars each) in wp-config.php first. See Settings → Advanced → Encryption Key Health.', 'ffcertificate' )
			);
		}

		return true;
	}

	/**
	 * Whether BOTH decoupling constants are in place.
	 *
	 * A seam, and a deliberate one: `execute()` re-checks this even though
	 * `MigrationStatusCalculator::execute_migration()` already gates on
	 * `can_run()`. The redundancy is worth keeping because this path WRITES
	 * ciphertext -- a caller that skipped the gate would not merely fail, it
	 * would re-encrypt every row under the WordPress-derived key and persist
	 * it, making the situation worse than doing nothing. Reading the flag
	 * through an overridable method is what lets a test drive the guarded code
	 * without defining `FFC_ENCRYPTION_KEY`, which is process-wide and would
	 * change `Encryption` for every test that runs afterwards (the order
	 * dependence the CLAUDE.md records).
	 *
	 * @return bool
	 */
	protected function is_decoupled(): bool {
		$health = Encryption::key_health_report();

		return ! empty( $health['encryption_decoupled'] ) && ! empty( $health['salt_decoupled'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Encryption Key Rotation — Remaining Areas', 'ffcertificate' );
	}

	/**
	 * Whether a table exists.
	 *
	 * Local rather than inherited from the database trait: this class is a
	 * migration strategy, not an activator, and it needs exactly this one
	 * helper.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Sonda de schema numa migracao: a resposta precisa refletir o schema vivo, entao cachear seria justamente o errado, e o WordPress nao expoe API para ela.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Stored state.
	 *
	 * @return array<string, mixed>
	 */
	private function get_state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persist state.
	 *
	 * @param array<string, mixed> $state State.
	 * @return void
	 */
	private function put_state( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Cursor for one target.
	 *
	 * @param string $target Target key.
	 * @return int
	 */
	private function get_cursor( string $target ): int {
		$state   = $this->get_state();
		$cursors = ArrayValue::array( $state, 'cursors' );

		return isset( $cursors[ $target ] ) && is_numeric( $cursors[ $target ] ) ? (int) $cursors[ $target ] : 0;
	}

	/**
	 * Advance the cursor for one target.
	 *
	 * @param string $target Target key.
	 * @param int    $value  New cursor.
	 * @return void
	 */
	private function set_cursor( string $target, int $value ): void {
		$state              = $this->get_state();
		$cursors            = ArrayValue::array( $state, 'cursors' );
		$cursors[ $target ] = $value;
		$state['cursors']   = $cursors;

		$this->put_state( $state );
	}

	/**
	 * Whether the stored fingerprint still matches the active key.
	 *
	 * @return bool
	 */
	private function fingerprint_matches(): bool {
		$state = $this->get_state();

		return array_key_exists( 'fingerprint', $state )
			&& hash_equals( ArrayValue::string( $state, 'fingerprint' ), Encryption::key_fingerprint() );
	}

	/**
	 * Stamp the active fingerprint, resetting progress when the key changed.
	 *
	 * @return void
	 */
	private function stamp_fingerprint(): void {
		if ( $this->fingerprint_matches() ) {
			return;
		}

		$this->put_state(
			array(
				'fingerprint' => Encryption::key_fingerprint(),
				'cursors'     => array(),
			)
		);
	}

	/**
	 * Whether the run is latched complete.
	 *
	 * @return bool
	 */
	private function is_completed(): bool {
		$state = $this->get_state();
		return ! empty( $state['completed'] );
	}

	/**
	 * Latch completion.
	 *
	 * @return void
	 */
	private function mark_completed(): void {
		$state              = $this->get_state();
		$state['completed'] = true;

		$this->put_state( $state );
	}
}
