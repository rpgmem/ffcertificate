<?php
/**
 * Reregistration Submission Writer
 *
 * Write-side of the reregistration-submission repository split (#563 backlog,
 * Sprint D2). Holds every INSERT / UPDATE and the workflow mutators (approve,
 * reject, return-to-draft, bulk operations, token provisioning). Reads live in
 * {@see ReregistrationSubmissionReader}. Callers depend on the reader (reads)
 * and this writer (writes) directly; the delegating façade was retired in
 * #563 B3-A.
 *
 * @since   6.12.0
 * @package FreeFormCertificate\Reregistration
 *
 * @phpstan-import-type ReregistrationSubmissionRow from ReregistrationSubmissionReader
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against one of the plugin's own ffc_* tables, which WordPress exposes no API for. Caching is decided per read at the repository layer, not per statement (#1042).
/**
 * Write operations for reregistration submission records.
 *
 * @since 6.12.0
 *
 * @phpstan-import-type ReregistrationSubmissionRow from ReregistrationSubmissionReader
 */
class ReregistrationSubmissionWriter {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for reregistration submission queries.
	 *
	 * Must match {@see ReregistrationSubmissionReader::cache_group()} so writes
	 * invalidate the entries reads populate.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_rereg_submissions';
	}

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_reregistration_submissions';
	}

	/**
	 * Ensure a submission has a magic_token, generating one if missing.
	 *
	 * @param object $submission Submission row object.
	 * @phpstan-param ReregistrationSubmissionRow $submission
	 * @return string The magic_token (existing or newly generated).
	 */
	public static function ensure_magic_token( object $submission ): string {
		if ( ! empty( $submission->magic_token ) ) {
			return $submission->magic_token;
		}

		$token = bin2hex( random_bytes( 32 ) );
		self::update( (int) $submission->id, array( 'magic_token' => $token ) );

		return $token;
	}

	/**
	 * Create a submission record.
	 *
	 * @param array<string, mixed> $data Submission data.
	 * @return int|false Submission ID or false.
	 */
	public static function create( array $data ) {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$defaults = array(
			'reregistration_id' => 0,
			'user_id'           => 0,
			'data'              => null,
			'status'            => 'pending',
			'submitted_at'      => null,
			'reviewed_at'       => null,
			'reviewed_by'       => null,
			'notes'             => null,
		);
		$data     = wp_parse_args( $data, $defaults );

		$insert_data   = array(
			'reregistration_id' => (int) $data['reregistration_id'],
			'user_id'           => (int) $data['user_id'],
			'status'            => $data['status'],
		);
		$insert_format = array( '%d', '%d', '%s' );

		if ( null !== $data['data'] ) {
			$insert_data['data'] = is_string( $data['data'] ) ? $data['data'] : wp_json_encode( $data['data'] );
			$insert_format[]     = '%s';
		}

		if ( null !== $data['submitted_at'] ) {
			$insert_data['submitted_at'] = $data['submitted_at'];
			$insert_format[]             = '%s';
		}

		if ( null !== $data['notes'] ) {
			$insert_data['notes'] = sanitize_textarea_field( $data['notes'] );
			$insert_format[]      = '%s';
		}

		$result = $wpdb->insert( $table, $insert_data, $insert_format );

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update a submission.
	 *
	 * @param int                  $id   Submission ID.
	 * @param array<string, mixed> $data Update data.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		unset( $data['id'], $data['reregistration_id'], $data['user_id'], $data['created_at'] );

		if ( empty( $data ) ) {
			return false;
		}

		$update_data = array();
		$format      = array();

		$field_formats = array(
			'data'         => '%s',
			'status'       => '%s',
			// `submitted_at`/`reviewed_at` are unix UTC int since 6.6.0 (#249 sub-escopos b/d).
			'submitted_at' => '%d',
			'reviewed_at'  => '%d',
			'reviewed_by'  => '%d',
			'notes'        => '%s',
			'auth_code'    => '%s',
			'magic_token'  => '%s',
		);

		foreach ( $data as $key => $value ) {
			if ( ! isset( $field_formats[ $key ] ) ) {
				continue;
			}

			if ( 'data' === $key && ! is_string( $value ) ) {
				$value = wp_json_encode( $value );
			}

			if ( 'notes' === $key && null !== $value ) {
				$value = sanitize_textarea_field( $value );
			}

			$update_data[ $key ] = $value;
			$format[]            = $field_formats[ $key ];
		}

		if ( empty( $update_data ) ) {
			return false;
		}

		$result = $wpdb->update(
			$table,
			$update_data,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		static::cache_delete( "id_{$id}" );

		return false !== $result;
	}

	/**
	 * Approve a submission.
	 *
	 * @param int $id          Submission ID.
	 * @param int $reviewer_id Reviewer user ID.
	 * @return bool
	 */
	public static function approve( int $id, int $reviewer_id ): bool {
		$result = self::update(
			$id,
			array(
				'status'      => 'approved',
				'reviewed_at' => time(),
				'reviewed_by' => $reviewer_id,
			)
		);

		static::cache_delete( "id_{$id}" );

		return $result;
	}

	/**
	 * Reject a submission.
	 *
	 * @param int    $id          Submission ID.
	 * @param int    $reviewer_id Reviewer user ID.
	 * @param string $notes       Rejection reason.
	 * @return bool
	 */
	public static function reject( int $id, int $reviewer_id, string $notes = '' ): bool {
		$result = self::update(
			$id,
			array(
				'status'      => 'rejected',
				'reviewed_at' => time(),
				'reviewed_by' => $reviewer_id,
				'notes'       => $notes,
			)
		);

		static::cache_delete( "id_{$id}" );

		return $result;
	}

	/**
	 * Return a submission to draft (in_progress) so the user can revise it.
	 *
	 * Clears the review metadata and resets submitted_at so the user
	 * sees it as an editable draft again.
	 *
	 * @param int $id Submission ID.
	 * @return bool
	 */
	public static function return_to_draft( int $id ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$result = $wpdb->update(
			$table,
			array(
				'status'       => 'in_progress',
				'submitted_at' => null,
				'reviewed_at'  => null,
				'reviewed_by'  => null,
				'notes'        => null,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		static::cache_delete( "id_{$id}" );

		return false !== $result;
	}

	/**
	 * Bulk return multiple submissions to draft.
	 *
	 * @param array<int> $ids Submission IDs.
	 * @return int Number of submissions returned to draft.
	 */
	public static function bulk_return_to_draft( array $ids ): int {
		$count = 0;
		foreach ( $ids as $id ) {
			if ( self::return_to_draft( (int) $id ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Bulk approve multiple submissions.
	 *
	 * @param array<int> $ids         Submission IDs.
	 * @param int        $reviewer_id Reviewer user ID.
	 * @return int Number of approved submissions.
	 */
	public static function bulk_approve( array $ids, int $reviewer_id ): int {
		$count = 0;
		foreach ( $ids as $id ) {
			if ( self::approve( (int) $id, $reviewer_id ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Quantas linhas a semeadura envia por INSERT (#1234).
	 *
	 * Quinhentas e o mesmo tamanho de pagina que o contrato de exportacao do
	 * #772 usa: grande o bastante para que o numero de idas ao banco deixe de
	 * importar, pequeno o bastante para nao esbarrar em `max_allowed_packet`
	 * numa linha de tres inteiros.
	 *
	 * @var int
	 */
	public const SEED_CHUNK_SIZE = 500;

	/**
	 * Create pending submissions for all affected users of a reregistration.
	 *
	 * Skips users who already have a submission for this reregistration.
	 *
	 * EM LOTE, E NAO LINHA A LINHA (#1234)
	 *
	 * A forma anterior era um SELECT (existe?) mais um INSERT por usuario: dez
	 * mil membros custavam vinte mil consultas DENTRO DE UMA REQUISICAO DE
	 * ADMIN -- a que salva a campanha. Agora sao `ceil( N / 500 )` INSERTs, e os
	 * mesmos dez mil membros custam vinte.
	 *
	 * POR QUE O `IGNORE` SUBSTITUI A CHECAGEM, E NAO APENAS A ESCONDE
	 *
	 * A tabela carrega `UNIQUE KEY idx_reregistration_user (reregistration_id,
	 * user_id)`, entao "pular quem ja tem submissao" ja era a regra do banco --
	 * o SELECT por linha apenas a repetia em PHP, e repetia com uma janela: entre
	 * ler e inserir, um segundo clique no botao Salvar podia inserir a mesma
	 * linha. A restricao fecha essa janela; a checagem nao fechava.
	 *
	 * `INSERT IGNORE` rebaixa TODO erro a aviso, o que normalmente e motivo para
	 * nao usa-lo. Aqui nao ha outro erro possivel: as tres colunas escritas sao
	 * dois inteiros que este metodo mesmo converte e o literal `pending`
	 * escrito aqui. Nao ha texto de usuario para truncar.
	 *
	 * A contagem devolvida continua sendo a de linhas CRIADAS: `affected_rows`
	 * de um INSERT multi-linha conta as que entraram, nao as ignoradas.
	 *
	 * @param int        $reregistration_id Reregistration ID.
	 * @param array<int> $audience_ids      Audience IDs.
	 * @return int Number of submissions created.
	 */
	public static function create_for_audience_members( int $reregistration_id, array $audience_ids ): int {
		$user_ids = ReregistrationRepository::get_user_ids_for_audiences( $audience_ids );

		// `get_user_ids_for_audiences()` concatena os membros de varias audiencias
		// e ja deduplica, mas devolve o que o leitor de audiencia entregou -- que
		// pode vir como string do driver. Normalizar aqui e o que permite confiar
		// nos `%d` abaixo e no tamanho dos lotes.
		$user_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $user_ids ),
					static function ( int $user_id ): bool {
						return $user_id > 0;
					}
				)
			)
		);

		if ( empty( $user_ids ) ) {
			return 0;
		}

		$wpdb    = self::db();
		$table   = self::get_table_name();
		$created = 0;

		foreach ( array_chunk( $user_ids, self::SEED_CHUNK_SIZE ) as $chunk ) {
			$rows = implode( ',', array_fill( 0, count( $chunk ), '(%d, %d, %s)' ) );
			$sql  = "INSERT IGNORE INTO %i (reregistration_id, user_id, status) VALUES {$rows}";

			$args = array( $table );
			foreach ( $chunk as $user_id ) {
				$args[] = $reregistration_id;
				$args[] = $user_id;
				// Mesmo estado inicial que o `create()` aplica por omissao.
				$args[] = 'pending';
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- A lista VALUES e montada so com marcadores %d/%s; os valores viajam como argumentos de prepare().
			$prepared = $wpdb->prepare( $sql, $args );
			if ( ! is_string( $prepared ) ) {
				continue;
			}

			// `query()` devolve `int|bool`; `false > 0` ja e falso, entao o
			// `is_int()` abaixo nao muda o comportamento -- ele existe para o
			// PHPStan, que no nivel 8 nao estreita o `bool` por uma comparacao.
			//
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- O argumento e a string que prepare() devolveu acima; o sniff nao acompanha uma string preparada atraves de uma atribuicao.
			$affected = $wpdb->query( $prepared );
			if ( is_int( $affected ) && $affected > 0 ) {
				$created += $affected;
			}
		}

		return $created;
	}

	/**
	 * Carimba o envio do LEMBRETE numa submissao.
	 *
	 * POR ITEM, e nao em lote como {@see self::mark_invited()} -- a diferenca e
	 * deliberada e vem do caminho de chamada. O convite e disparado por um
	 * clique do operador, que ve a tela e pode reagir; o lembrete roda no
	 * wp-cron, DENTRO DA REQUISICAO DE UM VISITANTE, sobre um conjunto que pode
	 * ter milhares de linhas e um `wp_mail()` sincrono por linha. Esse e
	 * exatamente o caminho que expira no meio -- e o defeito que o #1232
	 * descreve.
	 *
	 * Carimbar ao final significa que um timeout deixa NADA marcado, e todo
	 * mundo que ja recebeu e-mail recebe de novo na proxima execucao.
	 * Carimbando por item, uma interrupcao deixa marcado exatamente quem ja
	 * recebeu, e a execucao seguinte retoma de onde parou.
	 *
	 * Categoria A (unix UTC) conforme o CLAUDE.md -- `time()`, nunca
	 * `current_time()`.
	 *
	 * @param int $submission_id ID da submissao.
	 * @return bool
	 */
	public static function mark_reminded( int $submission_id ): bool {
		if ( $submission_id <= 0 ) {
			return false;
		}

		$wpdb = self::db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Escrita numa tabela `ffc_*` propria do plugin, para a qual o WordPress nao expoe API; a invalidacao do cache vem logo abaixo.
		$result = $wpdb->update(
			self::get_table_name(),
			array( 'reminder_sent_at' => time() ),
			array( 'id' => $submission_id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		static::cache_delete( "id_{$submission_id}" );

		return true;
	}

	/**
	 * Stamp the invitation timestamp on the submissions that were just emailed.
	 *
	 * Written in one statement rather than per row: the caller loops to send,
	 * and a failed send in the middle must not leave half the batch marked as
	 * invited while the other half gets a second email on the next click.
	 *
	 * Category A (unix UTC) per CLAUDE.md — `time()`, never `current_time()`.
	 *
	 * @param array<int, int> $submission_ids Submission IDs.
	 * @return int Rows updated.
	 */
	public static function mark_invited( array $submission_ids ): int {
		$ids = array_values( array_filter( array_map( 'intval', $submission_ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$wpdb         = self::db();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- O `{$placeholders}` é `%d` repetido por `array_fill()` acima, não dado de requisição; todo valor passa por `prepare()`.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- A consulta é a saída de `prepare()` guardada numa variável, que é como `ReregistrationRepository::expire_overdue()` faz pelo mesmo motivo: o retorno precisa ser testado antes de ir para `query()`.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- O sniff conta os marcadores do literal e não sabe que `prepare()` aceita um array único de argumentos, que é como a tabela e os ids chegam.
		$sql = $wpdb->prepare(
			"UPDATE %i SET invited_at = %d WHERE id IN ({$placeholders})",
			array_merge( array( self::get_table_name(), time() ), $ids )
		);

		// `prepare()` devolve `string|null`, e `query()` só aceita string -- o
		// mesmo guarda que `expire_overdue()` usa pela mesma razão.
		if ( ! is_string( $sql ) ) {
			return 0;
		}

		$result = $wpdb->query( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// Same invalidation the other mutators do: one key per row. There is no
		// group flush on the trait, and inventing one here would be a second
		// way to do what every sibling already does per id.
		foreach ( $ids as $id ) {
			static::cache_delete( "id_{$id}" );
		}

		return is_numeric( $result ) ? (int) $result : 0;
	}
}
