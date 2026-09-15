<?php
/**
 * Reregistration Email Handler
 *
 * Sends invitation, reminder, and confirmation emails for reregistration campaigns.
 * Uses SchedulingMailer for the shared chrome + transport.
 *
 * @package FreeFormCertificate\Reregistration
 * @since 4.11.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Scheduling\SchedulingMailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for reregistration email operations.
 *
 * @phpstan-import-type ReregistrationRow from ReregistrationRepository
 * @phpstan-import-type ReregistrationSubmissionRow from ReregistrationSubmissionReader
 */
class ReregistrationEmailHandler {

	use \FreeFormCertificate\Core\EmailHelperTrait;

	/**
	 * Quantas submissoes um lote de lembrete processa (#1232 passo 2).
	 *
	 * 50, e nao 100, porque o gargalo por participante nao e so o `wp_mail()`:
	 * `PasswordInvite::issue_for()` faz um hash phpass -- deliberadamente
	 * lento -- mais um `UPDATE wp_users`, e o envio escreve ainda uma linha de
	 * log. Sao ~3 escritas por pessoa, numa requisicao de visitante.
	 *
	 * Com o plugin irmao `total-mail-queue` ativo o `wp_mail()` vira um INSERT
	 * (ele curto-circuita em `pre_wp_mail`, sem handshake SMTP), mas as outras
	 * escritas continuam la -- por isso o lote nao foi dimensionado supondo a
	 * fila instalada.
	 *
	 * @var int
	 */
	public const REMINDER_BATCH_SIZE = 50;

	/**
	 * Evento unico que continua um lote de lembretes.
	 *
	 * Registrado no orquestrador (`Loader`), com os outros eventos agendados:
	 * registro de cron e ciclo de vida do orquestrador, nao bootstrap de
	 * modulo -- a distincao que o CLAUDE.md fixa para os `*Loader`.
	 *
	 * @var string
	 */
	public const REMINDER_BATCH_HOOK = 'ffc_reregistration_reminder_batch';

	/**
	 * Segundos entre um lote e o proximo.
	 *
	 * E um PISO, nao uma promessa: o WP-Cron e disparado por requisicao de
	 * visitante, entao num site parado o lote seguinte sai quando alguem
	 * aparecer. Quem precisa de cadencia real configura `DISABLE_WP_CRON` mais
	 * um cron de sistema.
	 *
	 * @var int
	 */
	public const REMINDER_BATCH_DELAY = 60;

	/**
	 * Send invitation emails to whoever is still awaiting one.
	 *
	 * **Idempotent by construction** (#1190): it asks
	 * {@see ReregistrationSubmissionReader::get_awaiting_invitation()} who is
	 * owed an email and stamps `invited_at` on the ones it reached, so running
	 * it twice in a row sends nothing the second time. It used to ask for
	 * `status = 'pending'` and mail all of them — a proxy that re-invited
	 * everybody who had ignored the first email, which is why it could only
	 * ever be called on a status transition.
	 *
	 * A deadline that moved forward re-opens the door for whoever has not
	 * finished; see that method and `UNFINISHED_STATUSES` for who that is.
	 *
	 * @param int $reregistration_id Reregistration ID.
	 * @return int Number of emails sent.
	 */
	public static function send_invitations( int $reregistration_id ): int {
		if ( self::emails_disabled() ) {
			return 0;
		}

		$rereg = ReregistrationRepository::get_by_id( $reregistration_id );
		if ( ! $rereg || empty( $rereg->email_invitation_enabled ) ) {
			return 0;
		}

		$extended_at = isset( $rereg->deadline_extended_at ) ? (int) $rereg->deadline_extended_at : 0;

		$submissions = ReregistrationSubmissionReader::get_awaiting_invitation(
			$reregistration_id,
			$extended_at > 0 ? $extended_at : null
		);

		$template = self::effective_template( 'reregistration-invitation' );
		if ( ! $template ) {
			return 0;
		}

		$count  = 0;
		$mailed = array();
		foreach ( $submissions as $sub ) {
			// O link de definição de senha é emitido POR USUÁRIO e por envio
			// (#1212). Emitir rotaciona a chave, então o último e-mail é
			// sempre o que vale -- que é o comportamento certo para um
			// convite reenviado.
			$extra = array( 'set_password_url' => \FreeFormCertificate\Core\PasswordInvite::issue_for( (int) $sub->user_id ) );
			if ( self::send_to_user( (int) $sub->user_id, $rereg, $template, $extra ) ) {
				++$count;
				$mailed[] = (int) $sub->id;
			}
		}

		// Only the ones that actually went out. A send that failed leaves the
		// row unstamped, so the next run tries it again instead of burying it.
		ReregistrationSubmissionWriter::mark_invited( $mailed );

		// Activity log.
		self::log(
			'reregistration_invitations_sent',
			0,
			array(
				'reregistration_id' => $reregistration_id,
				'count'             => $count,
			)
		);

		return $count;
	}

	/**
	 * Send reminder emails to pending/in-progress members.
	 *
	 * @param int        $reregistration_id Reregistration ID.
	 * @param array<int> $user_ids          Specific user IDs (empty = all pending).
	 * @return int Number of emails sent.
	 */
	public static function send_reminders( int $reregistration_id, array $user_ids = array() ): int {
		return self::dispatch_reminders( $reregistration_id, $user_ids, 0, 0 )['sent'];
	}

	/**
	 * Um LOTE de lembretes, e o reagendamento do proximo quando sobra fila.
	 *
	 * POR QUE LOTEAR
	 *
	 * `run_automated_reminders()` roda no wp-cron, isto e, DENTRO DA
	 * REQUISICAO DE UM VISITANTE. Sem limite, uma campanha de milhares de
	 * participantes fazia esse visitante pagar milhares de `wp_mail()` --
	 * e, por participante, ainda um hash phpass e um `UPDATE wp_users` vindos
	 * de `PasswordInvite::issue_for()`. O carimbo por item entregue no passo 1
	 * ja tornava o envio retomavel entre execucoes diarias, mas o alcance
	 * ficava limitado a (quanto cabe numa execucao) x `reminder_days`: numa
	 * campanha grande, o prazo vence antes de todo mundo ser lembrado.
	 *
	 * O PAYLOAD CARREGA O CURSOR, E ISSO NAO E OPCIONAL
	 *
	 * Ver o docblock de {@see ReregistrationSubmissionReader::get_awaiting_reminder()}:
	 * uma submissao cujo usuario foi apagado nunca recebe carimbo, entao um
	 * loop guiado apenas por `reminder_sent_at IS NULL` a rebuscaria para
	 * sempre. Sao dois escalares -- id da campanha e cursor --, o que tambem
	 * e o que a opcao `cron` suporta sem custo: ela e autoloaded e
	 * desserializada em TODA requisicao do site, entao um payload grande sai
	 * caro em todo lugar, o tempo todo.
	 *
	 * A ULTIMA PAGINA E QUEM ENCERRA
	 *
	 * Uma pagina menor que o lote significa que a fila acabou; so uma pagina
	 * CHEIA reagenda. Uma campanha de 50 ou menos termina numa execucao so,
	 * exatamente como antes deste passo.
	 *
	 * @param int $reregistration_id ID da campanha.
	 * @param int $after_id          Cursor: so linhas com `id` maior que este.
	 * @return void
	 */
	public static function send_reminder_batch( int $reregistration_id, int $after_id = 0 ): void {
		$result = self::dispatch_reminders( $reregistration_id, array(), $after_id, self::REMINDER_BATCH_SIZE );

		if ( $result['seen'] < self::REMINDER_BATCH_SIZE ) {
			return;
		}

		$args = array( $reregistration_id, $result['last_id'] );

		// Sem esta guarda, duas execucoes do cron sobre a mesma campanha --
		// possivel quando dois visitantes disparam o wp-cron quase juntos --
		// enfileirariam dois lotes identicos.
		if ( wp_next_scheduled( self::REMINDER_BATCH_HOOK, $args ) ) {
			return;
		}

		wp_schedule_single_event( time() + self::REMINDER_BATCH_DELAY, self::REMINDER_BATCH_HOOK, $args );
	}

	/**
	 * O despacho propriamente dito, compartilhado pelo caminho manual e pelo
	 * lote do cron.
	 *
	 * Devolve `seen` e `last_id` alem de `sent` porque quem decide reagendar
	 * precisa saber do TAMANHO DA PAGINA, nao de quantos e-mails sairam: uma
	 * pagina cheia em que tres envios falharam ainda tem fila adiante, e
	 * parar ali deixaria o resto da campanha para o dia seguinte.
	 *
	 * @param int        $reregistration_id ID da campanha.
	 * @param array<int> $user_ids          IDs explicitos (caminho manual).
	 * @param int        $after_id          Cursor keyset.
	 * @param int        $limit             Tamanho da pagina; `0` e sem limite.
	 * @return array{sent: int, seen: int, last_id: int}
	 */
	private static function dispatch_reminders( int $reregistration_id, array $user_ids, int $after_id, int $limit ): array {
		$empty = array(
			'sent'    => 0,
			'seen'    => 0,
			'last_id' => $after_id,
		);

		if ( self::emails_disabled() ) {
			return $empty;
		}

		$rereg = ReregistrationRepository::get_by_id( $reregistration_id );
		if ( ! $rereg || empty( $rereg->email_reminder_enabled ) ) {
			return $empty;
		}

		$template = self::effective_template( 'reregistration-reminder' );
		if ( ! $template ) {
			return $empty;
		}

		// Com ids explicitos o operador esta pedindo o envio para AQUELAS
		// pessoas, entao a marca nao filtra -- e um reenvio deliberado. Sem
		// eles, e o cron: ai quem ainda nao foi lembrado e o que decide.
		if ( ! empty( $user_ids ) ) {
			$submissions = array();
			foreach ( $user_ids as $uid ) {
				$sub = ReregistrationSubmissionReader::get_by_reregistration_and_user( $reregistration_id, (int) $uid );
				if ( $sub && in_array( $sub->status, ReregistrationSubmissionReader::REMINDABLE_STATUSES, true ) ) {
					$submissions[] = $sub;
				}
			}
		} else {
			// Um lembrete por campanha, mais um a cada extensao de prazo --
			// a mesma regra que o convite ja aplica desde o #1190, agora com
			// `reminder_sent_at` no lugar de `invited_at` (#1232).
			$extended_at = isset( $rereg->deadline_extended_at ) ? (int) $rereg->deadline_extended_at : 0;
			$submissions = ReregistrationSubmissionReader::get_awaiting_reminder(
				$reregistration_id,
				$extended_at > 0 ? $extended_at : null,
				$after_id,
				$limit
			);
		}

		$days_left = max( 0, (int) ( ( strtotime( $rereg->end_date ) - time() ) / 86400 ) );

		$count   = 0;
		$last_id = $after_id;
		foreach ( $submissions as $sub ) {
			// O cursor avanca mesmo quando o envio falha. E o que impede uma
			// submissao cujo usuario foi apagado de travar a fila: ela e
			// ultrapassada hoje e volta a ser tentada na varredura de amanha.
			$last_id = (int) $sub->id;

			// Também no lembrete: quem nunca definiu senha não consegue agir
			// no convite NEM no lembrete, e o botão do painel exige login.
			$extra = array(
				'days_left'        => (string) $days_left,
				'set_password_url' => \FreeFormCertificate\Core\PasswordInvite::issue_for( (int) $sub->user_id ),
			);
			if ( self::send_to_user( (int) $sub->user_id, $rereg, $template, $extra ) ) {
				// Carimbado IMEDIATAMENTE, nao ao final do laco: este metodo
				// roda no wp-cron, dentro da requisicao de um visitante, e um
				// timeout no meio deixaria todo mundo que ja recebeu sem marca
				// -- reenviando na proxima execucao, que e o defeito que este
				// trabalho conserta.
				ReregistrationSubmissionWriter::mark_reminded( (int) $sub->id );
				++$count;
			}
		}

		self::log(
			'reregistration_reminders_sent',
			0,
			array(
				'reregistration_id' => $reregistration_id,
				'count'             => $count,
			)
		);

		return array(
			'sent'    => $count,
			'seen'    => count( $submissions ),
			'last_id' => $last_id,
		);
	}

	/**
	 * Send confirmation email to a user after submission.
	 *
	 * @param int $submission_id Submission ID.
	 * @return bool
	 */
	public static function send_confirmation( int $submission_id ): bool {
		if ( self::emails_disabled() ) {
			return false;
		}

		$submission = ReregistrationSubmissionReader::get_by_id( $submission_id );
		if ( ! $submission ) {
			return false;
		}

		$rereg = ReregistrationRepository::get_by_id( (int) $submission->reregistration_id );
		if ( ! $rereg || empty( $rereg->email_confirmation_enabled ) ) {
			return false;
		}

		$template = self::effective_template( 'reregistration-confirmation' );
		if ( ! $template ) {
			return false;
		}

		$status_label = ReregistrationSubmissionReader::get_status_label( $submission->status );

		// Build magic link URL for direct verification.
		$magic_link_url = '';
		if ( ! empty( $submission->magic_token ) ) {
			$magic_link_url = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $submission->magic_token );
		}

		$auth_code_formatted = ! empty( $submission->auth_code )
			? \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $submission->auth_code, \FreeFormCertificate\Core\DocumentFormatter::PREFIX_REREGISTRATION )
			: '';

		return self::send_to_user(
			(int) $submission->user_id,
			$rereg,
			$template,
			array(
				'submission_status' => $status_label,
				'magic_link_url'    => $magic_link_url,
				'auth_code'         => $auth_code_formatted,
			)
		);
	}

	/**
	 * Run automated reminders for all active campaigns.
	 *
	 * Called by the daily cron job. Sends reminders when:
	 * - Campaign is active
	 * - email_reminder_enabled = 1
	 * - Days until end_date <= reminder_days
	 *
	 * @return void
	 */
	public static function run_automated_reminders(): void {
		if ( self::emails_disabled() ) {
			return;
		}

		global $wpdb;
		$table = ReregistrationRepository::get_table_name();

		// Get active campaigns where reminder is due.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron sweep for campaigns whose reminder is due, over the plugin's own ffc_* table; a cached list is exactly what a due-date sweep must not read.
		$campaigns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i
                 WHERE status = 'active'
                   AND email_reminder_enabled = 1
                   AND DATEDIFF(end_date, CURDATE()) <= reminder_days
                   AND DATEDIFF(end_date, CURDATE()) >= 0",
				$table
			)
		);

		if ( empty( $campaigns ) ) {
			return;
		}

		foreach ( $campaigns as $campaign ) {
			// Primeiro lote SINCRONO, o resto reagendado. Campanha de
			// `REMINDER_BATCH_SIZE` ou menos termina aqui mesmo, identica ao
			// comportamento anterior; so as grandes viram uma fila.
			self::send_reminder_batch( (int) $campaign->id, 0 );
		}
	}

	/**
	 * The effective subject + body for a reregistration email — the admin's SMTP
	 * email-body-hub override when set, else the shipped file default (#662, hub
	 * #964). Returns null (⇒ callers skip the send) when the body is unavailable,
	 * matching the previous `EmailTemplates::load()` null-guard.
	 *
	 * @param string $name Allowlisted template basename.
	 * @return array{subject:string, body:string}|null
	 */
	private static function effective_template( string $name ): ?array {
		$body = \FreeFormCertificate\Core\EmailTemplates::effective_body( $name, 'body' );
		if ( '' === $body ) {
			return null;
		}
		return array(
			'subject' => \FreeFormCertificate\Core\EmailTemplates::effective_body( $name, 'subject' ),
			'body'    => $body,
		);
	}

	/**
	 * Send an email to a specific user.
	 *
	 * @param int                   $user_id     User ID.
	 * @param object                $rereg       Reregistration object.
	 * @param array<string, string> $template    Template with 'subject' and 'body' keys.
	 * @param array<string, string> $extra_vars  Additional template variables.
	 * @phpstan-param ReregistrationRow $rereg
	 * @return bool
	 */
	private static function send_to_user( int $user_id, object $rereg, array $template, array $extra_vars = array() ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		// `get_option()` is mixed and this one is written by the
		// dashboard activator as a post id; anything that is not a
		// number falls back rather than being cast (#1060).
		$dashboard_page_id = get_option( 'ffc_dashboard_page_id' );
		$dashboard_url     = is_numeric( $dashboard_page_id ) && (int) $dashboard_page_id > 0
			? get_permalink( (int) $dashboard_page_id )
			: home_url( '/dashboard' );

		$variables = array_merge(
			array(
				'user_name'            => $user->display_name,
				'reregistration_title' => $rereg->title,
				'audience_name'        => $rereg->audience_name ?? '',
				'start_date'           => DateFormatter::format_date( $rereg->start_date ),
				'end_date'             => DateFormatter::format_date( $rereg->end_date ),
				'dashboard_url'        => $dashboard_url,
				'site_name'            => get_bloginfo( 'name' ),
			),
			$extra_vars
		);

		// Um `href` vazio é pior que um link comum: `issue_for()` só devolve
		// '' quando a chave não pôde ser emitida, e nesse caso o e-mail ainda
		// sai. Degradar para o painel mantém o botão útil para quem já tem
		// senha e nunca produz um link morto (#1212).
		if ( isset( $variables['set_password_url'] ) && '' === $variables['set_password_url'] ) {
			$variables['set_password_url'] = $dashboard_url;
		}

		$tokens = array();
		foreach ( $variables as $key => $value ) {
			$tokens[ '{{' . $key . '}}' ] = (string) $value;
		}
		$subject = \FreeFormCertificate\Core\TokenResolver::resolve( $template['subject'], $tokens );
		$body    = \FreeFormCertificate\Core\TokenResolver::resolve( $template['body'], $tokens );

		return SchedulingMailer::send( $user->user_email, $subject, $body, array(), true, \FreeFormCertificate\Core\EmailSource::REREGISTRATION );
	}

	/**
	 * Check if all emails are globally disabled.
	 * Delegates to EmailHelperTrait::ffc_emails_disabled().
	 */
	private static function emails_disabled(): bool {
		return self::ffc_emails_disabled();
	}

	/**
	 * Log an email event.
	 *
	 * @param string               $type    Event type.
	 * @param int                  $user_id User ID (0 for system events).
	 * @param array<string, mixed> $data    Extra data.
	 * @return void
	 */
	private static function log( string $type, int $user_id, array $data ): void {
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				$type,
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				$data,
				$user_id
			);
		}
	}
}
