<?php
/**
 * SubmissionHandler v3.3.0
 * Complete refactored version with Repository Pattern
 *
 * @package FreeFormCertificate\Submissions
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace
 * @since 3.1.0 Optimized bulk operations (single query + suspended logging)
 * @since 3.0.0 Repository Pattern integration
 * @since 2.10.0 Encryption & LGPD support
 */

declare(strict_types=1);

namespace FreeFormCertificate\Submissions;

use FreeFormCertificate\Repositories\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for submission operations.
 */
class SubmissionHandler {

	/**
	 * Repository.
	 *
	 * @var SubmissionRepository
	 */
	private $repository;

	/**
	 * Lifecycle / maintenance collaborator.
	 *
	 * @var SubmissionLifecycleService
	 */
	private $lifecycle;

	/**
	 * Gancho INTERNO que o wp-cron dispara depois de uma submissao (#1248).
	 *
	 * Ele carrega um inteiro. O gancho PUBLICO
	 * `ffcertificate_process_submission_hook` continua recebendo os mesmos oito
	 * argumentos, disparado por {@see self::dispatch_async_pipeline()} depois de
	 * reidratar -- e por isso a troca e invisivel para quem escuta de fora.
	 *
	 * @var string
	 */
	public const ASYNC_PIPELINE_HOOK = 'ffc_process_submission_async';

	/**
	 * Gancho PUBLICO do fim da linha, com a assinatura de oito argumentos.
	 *
	 * @var string
	 */
	public const PIPELINE_HOOK = 'ffcertificate_process_submission_hook';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repository = new SubmissionRepository();
		$this->lifecycle  = new SubmissionLifecycleService( $this );
	}

	/**
	 * Get the submission repository instance
	 *
	 * @return SubmissionRepository
	 */
	public function get_repository(): SubmissionRepository {
		return $this->repository;
	}

	/**
	 * Generate unique magic token
	 */
	private function generate_magic_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Generate unique auth code
	 */
	private function generate_unique_auth_code(): string {
		return \FreeFormCertificate\Core\AuthCodeService::generate_globally_unique_auth_code();
	}

	/**
	 * Get submission by ID
	 *
	 * @uses Repository::findById()
	 *
	 * @param int $id ID.
	 * @return array<string, mixed>|null
	 */
	public function get_submission( int $id ) {
		$submission = $this->repository->findById( $id );

		if ( ! $submission ) {
			return null;
		}

		return $this->decrypt_submission_data( $submission );
	}

	/**
	 * Get submission by magic token
	 *
	 * @uses Repository::findByToken()
	 *
	 * @param string $token Token.
	 * @return array<string, mixed>|null
	 */
	public function get_submission_by_token( string $token ) {
		$clean_token = preg_replace( '/[^a-f0-9]/i', '', $token ) ?? '';

		if ( strlen( $clean_token ) !== 32 ) {
			return null;
		}

		$submission = $this->repository->findByToken( $clean_token );

		if ( ! $submission ) {
			return null;
		}

		return $this->decrypt_submission_data( $submission );
	}

	/**
	 * Process submission (main method)
	 *
	 * @uses Repository::insert()
	 *
	 * @param int                              $form_id         Form ID.
	 * @param string                           $form_title      Form title.
	 * @param array<string, mixed>             $submission_data Submission data (passed by reference).
	 * @param string                           $user_email      User email.
	 * @param array<int, array<string, mixed>> $fields_config   Fields configuration (list of field definitions).
	 * @param array<string, mixed>             $form_config     Form configuration.
	 * @return int|\WP_Error
	 */
	public function process_submission( int $form_id, string $form_title, array &$submission_data, string $user_email, array $fields_config, array $form_config ) {
		/**
		 * Fires before a submission is saved to the database.
		 *
		 * @since 4.6.4
		 * @param int    $form_id         Form ID.
		 * @param array<string, mixed>  $submission_data Submission data (passed by reference via the method).
		 * @param string $user_email      User email.
		 * @param array<string, mixed>  $form_config     Form configuration.
		 */
		do_action( 'ffcertificate_before_submission_save', $form_id, $submission_data, $user_email, $form_config );

		// 1. Generate auth code if not present.
		if ( empty( $submission_data['auth_code'] ) ) {
			$submission_data['auth_code'] = $this->generate_unique_auth_code();
		}

		// 2. Clean mandatory fields.
		$clean_auth_code = \FreeFormCertificate\Core\DocumentFormatter::clean_auth_code( $submission_data['auth_code'] );

		$clean_cpf_rf = null;
		if ( isset( $submission_data['cpf_rf'] ) && ! empty( $submission_data['cpf_rf'] ) ) {
			$clean_cpf_rf = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( (string) $submission_data['cpf_rf'] );
		}

		// 2b. Classify identifier as CPF or RF by digit length.
		$clean_cpf = null;
		$clean_rf  = null;
		if ( ! empty( $clean_cpf_rf ) ) {
			$id_len = strlen( $clean_cpf_rf );
			if ( 11 === $id_len ) {
				$clean_cpf = $clean_cpf_rf;
			} elseif ( 7 === $id_len ) {
				$clean_rf = $clean_cpf_rf;
			} else {
				// Unknown length — default to CPF (most common).
				$clean_cpf = $clean_cpf_rf;
			}
		}

		// 3. Generate magic token.
		$magic_token = $this->generate_magic_token();

		// 4. Extract extra data.
		$mandatory_keys = array( 'email', 'cpf_rf', 'auth_code', 'ffc_lgpd_consent' );
		$extra_data     = array_diff_key( $submission_data, array_flip( $mandatory_keys ) );

		$data_json = wp_json_encode( $extra_data );
		if ( 'null' === $data_json || false === $data_json || empty( $data_json ) ) {
			$data_json = '{}';
		}

		// 5. Get user IP.
		$user_ip = \FreeFormCertificate\Core\RequestInput::get_user_ip();

		// 5b. Extract ticket value (for hash-based lookup)
		$ticket_value = isset( $extra_data['ticket'] ) ? strtoupper( trim( (string) $extra_data['ticket'] ) ) : null;

		// 6. Encryption via SensitiveFieldRegistry — single policy source for
		// every field this handler treats as sensitive.
		$encrypted = \FreeFormCertificate\Core\SensitiveFieldRegistry::encrypt_fields(
			\FreeFormCertificate\Core\SensitiveFieldRegistry::CONTEXT_SUBMISSION,
			array(
				'email'   => $user_email,
				'cpf'     => $clean_cpf,
				'rf'      => $clean_rf,
				'user_ip' => $user_ip,
				'ticket'  => $ticket_value,
				// The JSON "{}" means no extra data — skip encryption instead
				// of writing a ciphertext for an empty payload.
				'data'    => '{}' === $data_json ? null : $data_json,
			)
		);

		$email_encrypted   = $encrypted['email_encrypted'] ?? null;
		$email_hash        = $encrypted['email_hash'] ?? null;
		$cpf_encrypted_val = $encrypted['cpf_encrypted'] ?? null;
		$cpf_hash_val      = $encrypted['cpf_hash'] ?? null;
		$rf_encrypted_val  = $encrypted['rf_encrypted'] ?? null;
		$rf_hash_val       = $encrypted['rf_hash'] ?? null;
		$ticket_hash       = $encrypted['ticket_hash'] ?? null;
		$ip_encrypted      = $encrypted['user_ip_encrypted'] ?? null;
		$data_encrypted    = $encrypted['data_encrypted'] ?? null;

		// 7. LGPD Consent. `consent_date` is unix UTC int since 6.6.0 (#249 sub-escopo d).
		$consent_given = isset( $submission_data['ffc_lgpd_consent'] ) && '1' === $submission_data['ffc_lgpd_consent'] ? 1 : 0;
		$consent_date  = $consent_given ? time() : null;
		$consent_text  = $consent_given ? __( 'User agreed to Privacy Policy and data storage', 'ffcertificate' ) : null;

		// 8. Link to WordPress user (v3.1.0)
		$lookup_cpf_hash = $cpf_hash_val ?? $rf_hash_val;
		$identifier_type = ! empty( $cpf_hash_val ) ? 'cpf' : ( ! empty( $rf_hash_val ) ? 'rf' : 'auto' );
		$user_id         = null;
		if ( ! empty( $lookup_cpf_hash ) && ! empty( $user_email ) ) {
			// Load User Manager if not already loaded.
			if ( ! class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
				$user_manager_file = FFC_PLUGIN_DIR . 'includes/user-dashboard/class-ffc-user-manager.php';
				if ( file_exists( $user_manager_file ) ) {
					require_once $user_manager_file;
				}
			}

			if ( class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
				$user_result = \FreeFormCertificate\UserDashboard\UserManager::get_or_create_user(
					$lookup_cpf_hash,
					$user_email,
					$submission_data,
					\FreeFormCertificate\UserDashboard\CapabilityManager::CONTEXT_CERTIFICATE,
					$identifier_type
				);

				if ( ! is_wp_error( $user_result ) ) {
					$user_id = $user_result;
				}
			}
		}

		// 9. Prepare insert data.
		$insert_data = array(
			'form_id'           => $form_id,
			'user_id'           => $user_id,  // v3.1.0: Link to WordPress user.
			// `submission_date` is unix UTC int since 6.6.0 (#249 sub-escopo a).
			// `time()` is TZ-neutral by construction so we don't go through
			// `current_time()` here.
			'submission_date'   => time(),
			'auth_code'         => $clean_auth_code,
			'status'            => 'publish',
			'magic_token'       => $magic_token,
			'email_encrypted'   => $email_encrypted,
			'email_hash'        => $email_hash,
			'cpf_encrypted'     => $cpf_encrypted_val,
			'cpf_hash'          => $cpf_hash_val,
			'rf_encrypted'      => $rf_encrypted_val,
			'rf_hash'           => $rf_hash_val,
			'ticket_hash'       => $ticket_hash,
			'user_ip_encrypted' => $ip_encrypted,
			'data_encrypted'    => $data_encrypted,
			'consent_given'     => $consent_given,
			'consent_date'      => $consent_date,
			'consent_text'      => $consent_text,
		);

		// Plaintext data column — only if encryption NOT configured.
		if ( class_exists( '\FreeFormCertificate\Core\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured() ) {
			$insert_data['data'] = null;
		} else {
			$insert_data['data'] = $data_json;
		}

		// 9. Insert using repository.
		$submission_id = $this->repository->insert( $insert_data );

		if ( ! $submission_id ) {
			return new \WP_Error( 'db_error', __( 'Error saving submission to the database.', 'ffcertificate' ) );
		}

		/**
		 * Fires after a submission is saved to the database.
		 *
		 * @since 4.6.4
		 * @param int    $submission_id   Newly created submission ID.
		 * @param int    $form_id         Form ID.
		 * @param array<string, mixed>  $submission_data Original submission data.
		 * @param string $user_email      User email.
		 */
		do_action( 'ffcertificate_after_submission_save', $submission_id, $form_id, $submission_data, $user_email );

		// Dispatch the async email/notification pipeline. EmailHandler listens
		// on `ffcertificate_process_submission_hook` and decides whether the
		// user email is enabled (and no-ops when emails are globally
		// disabled). Restores the trigger that was orphaned — the hook was
		// registered but never scheduled (#649).
		//
		// O QUE VIAJA NO AGENDAMENTO E UM INTEIRO (#1248)
		//
		// Ate aqui iam oito argumentos, entre eles o `$submission_data`
		// inteiro e o `$magic_token`. O agendamento mora na option `cron`, que
		// e AUTOLOADED: enquanto o evento estivesse pendente, todo pedido ao
		// site carregaria e desserializaria aquilo. E pior que custo -- vinte
		// linhas acima este mesmo metodo CIFRA e-mail, CPF, RF e os dados
		// extras antes do INSERT, e o payload gravava os originais em claro na
		// `wp_options`, junto do `magic_token`, que e a autenticacao inteira do
		// acesso ao certificado.
		//
		// `dispatch_async_pipeline()` reidrata os oito valores da linha e do
		// formulario e dispara o gancho publico com eles, entao nenhum ouvinte
		// externo percebe a mudanca.
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event(
				time() + 1,
				self::ASYNC_PIPELINE_HOOK,
				array( (int) $submission_id )
			);
		}

		return $submission_id;
	}

	/**
	 * Reidrata o contexto de uma submissao e dispara o gancho publico (#1248).
	 *
	 * O wp-cron chama isto com um inteiro; daqui sai o `do_action` de oito
	 * argumentos que `EmailHandler::async_process_submission()` -- e qualquer
	 * integracao de terceiro -- sempre recebeu. Sao ganchos DIFERENTES, entao
	 * nao ha recursao, e o ouvinte antigo continua registrado: eventos
	 * agendados na forma velha, pendentes no momento da atualizacao, seguem
	 * processando normalmente.
	 *
	 * DUAS NORMALIZACOES QUE NAO APARECEM -- MEDIDO, NAO SUPOSTO
	 *
	 * O banco guarda `cpf_rf` so com digitos e `auth_code` so com
	 * alfanumericos, enquanto o visitante pode ter digitado
	 * `123.456.789-00`. Isso nao muda e-mail nenhum porque os dois unicos
	 * consumidores desses valores normalizam a ENTRADA antes de formatar:
	 * `DocumentFormatter::format_document()` aplica `preg_replace('/\D/','')`
	 * e `format_auth_code()` aplica `/[^A-Z0-9]/i`. Cru e normalizado saem
	 * identicos.
	 *
	 * O QUE MUDA, E E DELIBERADO: A ORDEM DAS LINHAS NO AVISO AO ADMIN
	 *
	 * `EmailHandler::send_admin_notification()` desenha uma linha por chave, na
	 * ordem do array. No original essa ordem era a do POST, com as quatro
	 * chaves "obrigatorias" intercaladas entre os campos do formulario. O banco
	 * as separou das demais, e a posicao original delas nao esta guardada em
	 * lugar nenhum -- entao ela nao e recuperavel.
	 *
	 * A reconstrucao entao agrupa: identificacao primeiro, campos do formulario
	 * no meio, codigo e consentimento no fim. Os campos do formulario mantem a
	 * ordem original entre si, porque `array_diff_key()` preserva ordem e foi
	 * assim que o JSON foi gravado.
	 *
	 * Uma consequencia menor da mesma origem: um `ffc_lgpd_consent` enviado
	 * como `'0'` some da tabela, porque o banco guarda um booleano e um
	 * consentimento negado e indistinguivel de um nao enviado.
	 *
	 * A CONFIGURACAO LIDA E A DE AGORA, NAO A DE UM SEGUNDO ATRAS
	 *
	 * `_ffc_form_fields` e `_ffc_form_config` sao lidos na hora do disparo. Se
	 * alguem editar o formulario dentro da janela de ~1s, o e-mail sai com a
	 * configuracao nova -- o que e mais correto que sair com a velha, e nao com
	 * a fotografia que o payload antigo carregava.
	 *
	 * @param int $submission_id ID da submissao recem-criada.
	 * @return void
	 */
	public function dispatch_async_pipeline( int $submission_id ): void {
		if ( $submission_id <= 0 ) {
			return;
		}

		$submission = $this->get_submission( $submission_id );
		if ( ! is_array( $submission ) ) {
			// A linha pode ter sido apagada entre o agendamento e o disparo.
			return;
		}

		$form_id     = isset( $submission['form_id'] ) ? (int) $submission['form_id'] : 0;
		$user_email  = isset( $submission['email'] ) ? (string) $submission['email'] : '';
		$magic_token = isset( $submission['magic_token'] ) ? (string) $submission['magic_token'] : '';

		$submission_data = $this->rebuild_submission_data( $submission );

		$fields_config = $form_id > 0 ? get_post_meta( $form_id, '_ffc_form_fields', true ) : array();
		$form_config   = $form_id > 0 ? get_post_meta( $form_id, '_ffc_form_config', true ) : array();

		/**
		 * Fires after a submission is saved, to run the async email pipeline.
		 *
		 * @since 4.6.4
		 * @param int                  $submission_id   Submission ID.
		 * @param int                  $form_id         Form ID.
		 * @param string               $form_title      Form title.
		 * @param array<string, mixed> $submission_data Submission data.
		 * @param string               $user_email      User email.
		 * @param array<string, mixed> $fields_config   Field configuration.
		 * @param array<string, mixed> $form_config     Form configuration.
		 * @param string               $magic_token     Magic token.
		 */
		do_action(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- A constante guarda o literal `ffcertificate_process_submission_hook`, que ja carrega o prefixo do plugin; o sniff nao resolve constantes.
			self::PIPELINE_HOOK,
			$submission_id,
			$form_id,
			$form_id > 0 ? (string) get_the_title( $form_id ) : '',
			$submission_data,
			$user_email,
			is_array( $fields_config ) ? $fields_config : array(),
			is_array( $form_config ) ? $form_config : array(),
			$magic_token
		);
	}

	/**
	 * Remonta o array que o visitante enviou, a partir da linha decifrada.
	 *
	 * A ordem das chaves e a contrapartida documentada em
	 * {@see self::dispatch_async_pipeline()} -- ela e o que o aviso ao admin
	 * desenha, entao esta fixada aqui e presa por teste.
	 *
	 * @param array<string, mixed> $submission Linha ja decifrada.
	 * @return array<string, mixed>
	 */
	private function rebuild_submission_data( array $submission ): array {
		$data = array();

		$email = isset( $submission['email'] ) ? (string) $submission['email'] : '';
		if ( '' !== $email ) {
			$data['email'] = $email;
		}

		$cpf_rf = isset( $submission['cpf_rf'] ) ? (string) $submission['cpf_rf'] : '';
		if ( '' !== $cpf_rf ) {
			$data['cpf_rf'] = $cpf_rf;
		}

		// Os campos do formulario vivem no JSON da coluna `data`, e chegam na
		// ordem em que foram gravados -- que e a ordem original entre si.
		$raw = isset( $submission['data'] ) ? $submission['data'] : '';
		if ( is_string( $raw ) && '' !== $raw ) {
			$extra = json_decode( $raw, true );
			if ( is_array( $extra ) ) {
				foreach ( $extra as $key => $value ) {
					$data[ (string) $key ] = $value;
				}
			}
		}

		$auth_code = isset( $submission['auth_code'] ) ? (string) $submission['auth_code'] : '';
		if ( '' !== $auth_code ) {
			$data['auth_code'] = $auth_code;
		}

		if ( ! empty( $submission['consent_given'] ) ) {
			$data['ffc_lgpd_consent'] = '1';
		}

		return $data;
	}

	/**
	 * Update submission - FIXED v3.0.1
	 *
	 * @uses Repository::updateWithEditTracking()
	 *
	 * @param int                  $id         Submission ID.
	 * @param string               $new_email  New email.
	 * @param array<string, mixed> $clean_data Clean data.
	 */
	public function update_submission( int $id, string $new_email, array $clean_data ): bool {
		/**
		 * Fires before a submission is updated.
		 *
		 * @since 4.6.4
		 * @param int    $id         Submission ID.
		 * @param string $new_email  New email value.
		 * @param array<string, mixed>  $clean_data Sanitized submission data.
		 */
		do_action( 'ffcertificate_before_submission_update', $id, $new_email, $clean_data );

		$update_data = array();
		$has_data    = ! empty( $clean_data );
		$data_json   = null;

		if ( $has_data ) {
			// Remove edit tracking from JSON data (should be in columns).
			unset( $clean_data['is_edited'], $clean_data['edited_at'] );
			$data_json = wp_json_encode( $clean_data, JSON_UNESCAPED_UNICODE );
		}

		// Route sensitive fields through the registry.
		$encrypted   = \FreeFormCertificate\Core\SensitiveFieldRegistry::encrypt_fields(
			\FreeFormCertificate\Core\SensitiveFieldRegistry::CONTEXT_SUBMISSION,
			array(
				'email' => '' !== $new_email ? $new_email : null,
				'data'  => $has_data ? ( $data_json ? $data_json : '{}' ) : null,
			)
		);
		$update_data = array_merge( $update_data, $encrypted );

		// When data is updated and encryption is active, the plaintext column
		// must be NULLed out; otherwise store the plaintext json.
		if ( $has_data ) {
			if ( isset( $encrypted['data_encrypted'] ) ) {
				$update_data['data'] = null;
			} else {
				$update_data['data'] = $data_json;
			}
		}

		$result = $this->repository->updateWithEditTracking( $id, $update_data );

		if ( false !== $result ) {
			/**
			 * Fires after a submission is updated.
			 *
			 * @since 4.6.4
			 * @param int   $id          Submission ID.
			 * @param array $update_data Data that was updated.
			 */
			do_action( 'ffcertificate_after_submission_update', $id, $update_data );
		}

		return (bool) $result;  // Convert int|false to bool.
	}

	/**
	 * Update user link for a submission
	 *
	 * @since 4.3.0
	 * @param int      $id Submission ID.
	 * @param int|null $user_id WordPress user ID or null to unlink.
	 * @return bool True on success
	 */
	public function update_user_link( int $id, ?int $user_id ): bool {
		// `edited_at` is unix UTC int since 6.6.0 (#249 sub-escopo d).
		$update_data = array(
			'user_id'   => $user_id,
			'edited_at' => time(),
			'edited_by' => get_current_user_id(),
		);

		$result = $this->repository->update( $id, $update_data );

		if ( false !== $result && class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			// The action goes in the first argument; the second is the level.
			// These were swapped, so every link/unlink logged as the generic
			// `submission` while the real action name landed in the level slot
			// and was discarded by the level validation (#1024).
			$ffc_action = $user_id ? 'user_linked' : 'user_unlinked';
			\FreeFormCertificate\Core\ActivityLog::log(
				$ffc_action,
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array(
					'submission_id' => $id,
					'user_id'       => $user_id,
					'admin_id'      => get_current_user_id(),
				)
			);
		}

		return (bool) $result;
	}

	/**
	 * Decrypt submission data.
	 * Uses Encryption::decrypt_field() for each sensitive field.
	 *
	 * @param mixed $submission Submission.
	 * @return array<string, mixed>
	 */
	public function decrypt_submission_data( $submission ): array {
		if ( ! $submission || ! class_exists( '\FreeFormCertificate\Core\Encryption' ) ) {
			return $submission;
		}

		$submission['email']   = \FreeFormCertificate\Core\Encryption::decrypt_field( $submission, 'email' );
		$submission['user_ip'] = \FreeFormCertificate\Core\Encryption::decrypt_field( $submission, 'user_ip' );
		$submission['data']    = \FreeFormCertificate\Core\Encryption::decrypt_field( $submission, 'data' );

		// Decrypt split cpf/rf columns.
		$cpf_val = \FreeFormCertificate\Core\Encryption::decrypt_field( $submission, 'cpf' );
		$rf_val  = \FreeFormCertificate\Core\Encryption::decrypt_field( $submission, 'rf' );

		if ( ! empty( $cpf_val ) ) {
			$submission['cpf_rf'] = $cpf_val;
			$submission['cpf']    = $cpf_val;
			$submission['rf']     = null;
		} elseif ( ! empty( $rf_val ) ) {
			$submission['cpf_rf'] = $rf_val;
			$submission['rf']     = $rf_val;
			$submission['cpf']    = null;
		} else {
			$submission['cpf_rf'] = '';
			$submission['cpf']    = null;
			$submission['rf']     = null;
		}

		return $submission;
	}

	/**
	 * Trash submission
	 *
	 * @uses Repository::updateStatus()
	 * @param int $id ID.
	 */
	public function trash_submission( int $id ): bool {
		return $this->lifecycle->trash_submission( $id );
	}

	/**
	 * Restore submission
	 *
	 * @uses Repository::updateStatus()
	 * @param int $id ID.
	 */
	public function restore_submission( int $id ): bool {
		return $this->lifecycle->restore_submission( $id );
	}

	/**
	 * Permanently delete submission
	 *
	 * @uses Repository::delete()
	 * @param int $id ID.
	 */
	public function delete_submission( int $id ): bool {
		return $this->lifecycle->delete_submission( $id );
	}

	/**
	 * Bulk trash submissions (optimized)
	 *
	 * @uses Repository::bulkUpdateStatus()
	 *
	 * @param array<int, int> $ids Array of submission IDs.
	 * @return int|false Number of rows affected or false on error
	 */
	public function bulk_trash_submissions( array $ids ) {
		return $this->lifecycle->bulk_trash_submissions( $ids );
	}

	/**
	 * Bulk restore submissions (optimized)
	 *
	 * @uses Repository::bulkUpdateStatus()
	 *
	 * @param array<int, int> $ids Array of submission IDs.
	 * @return int|false Number of rows affected or false on error
	 */
	public function bulk_restore_submissions( array $ids ) {
		return $this->lifecycle->bulk_restore_submissions( $ids );
	}

	/**
	 * Move submissions between forms, skipping conflicts.
	 *
	 * Wraps SubmissionRepository::moveBetweenForms with the same
	 * disable-logging-then-log-once pattern used by the other bulk methods,
	 * so a 50-row move produces a single `submission_moved` activity entry
	 * instead of 50 individual `data_modified` entries.
	 *
	 * @param int             $from_form_id Source form ID.
	 * @param int             $to_form_id   Target form ID.
	 * @param array<int, int> $ids          Submission IDs.
	 * @return array{moved: list<int>, conflicts: list<int>}
	 */
	public function move_submissions_between_forms( int $from_form_id, int $to_form_id, array $ids ): array {
		return $this->lifecycle->move_submissions_between_forms( $from_form_id, $to_form_id, $ids );
	}

	/**
	 * Bulk delete submissions permanently (optimized)
	 *
	 * @uses Repository::bulkDelete()
	 *
	 * @param array<int, int> $ids Array of submission IDs.
	 * @return int|false Number of rows deleted or false on error
	 */
	public function bulk_delete_submissions( array $ids ) {
		return $this->lifecycle->bulk_delete_submissions( $ids );
	}

	/**
	 * Delete all submissions for a form
	 *
	 * @uses Repository::deleteByFormId()
	 */
	/**
	 * Delete all submissions (optionally by form_id)
	 *
	 * @param int|null $form_id Form ID to delete from, or null for all forms.
	 * @param bool     $reset_auto_increment Reset ID counter to 1.
	 * @return int Number of rows deleted
	 */
	public function delete_all_submissions( ?int $form_id = null, bool $reset_auto_increment = false ): int {
		return $this->lifecycle->delete_all_submissions( $form_id, $reset_auto_increment );
	}

	/**
	 * Reset AUTO_INCREMENT counter
	 *
	 * @return bool Query result
	 */
	public function reset_submission_counter(): bool {
		return $this->lifecycle->reset_submission_counter();
	}

	/**
	 * Run data cleanup (old submissions)
	 *
	 * @return int Number of deleted submissions
	 */
	public function run_data_cleanup(): int {
		return $this->lifecycle->run_data_cleanup();
	}

	/**
	 * Ensure magic token exists
	 *
	 * @param int $submission_id Submission ID.
	 */
	public function ensure_magic_token( int $submission_id ): string {
		$submission = $this->repository->findById( $submission_id );

		// If submission not found, return empty string.
		if ( ! $submission ) {
			return '';
		}

		// If token already exists, return it.
		if ( ! empty( $submission['magic_token'] ) ) {
			return $submission['magic_token'];
		}

		// Generate new token.
		$magic_token = $this->generate_magic_token();

		// Save to database.
		$this->repository->update(
			$submission_id,
			array(
				'magic_token' => $magic_token,
			)
		);

		return $magic_token;
	}
}
