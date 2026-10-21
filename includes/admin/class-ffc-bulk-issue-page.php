<?php
/**
 * BulkIssuePage
 *
 * The "Bulk issue" tab of the form editor (#1945, #1967). The tab works on the
 * form being edited: download its CSV template, upload a participants file, map
 * its columns onto the form and preview what would be issued and what would be
 * skipped. Nothing is written here yet.
 *
 * The editor is one WordPress post form, so the upload cannot be a form of its
 * own: the tab posts the file over AJAX (`ffc_bulk_issue_preview`), and the
 * server answers with the HTML of the step. The file is parsed from the
 * request and never stored: staging it so the mapping step does not need the
 * file again is part of the job-engine sprint.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Core\SyncCsvExport;
use FreeFormCertificate\Submissions\BulkIssuePlanner;
use FreeFormCertificate\Submissions\BulkIssueRepository;
use FreeFormCertificate\Submissions\BulkIssueRunner;
use FreeFormCertificate\Submissions\SubmissionHandler;
use FreeFormCertificate\Repositories\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the tab and handles its template download and preview requests.
 */
final class BulkIssuePage {

	/** Capability that shows the tab and runs the preview (#1945). */
	public const CAP = 'ffc_import_certificates';

	/** Field name of the uploaded participants file. */
	private const FILE_FIELD = 'ffc_bulk_file';

	/** Largest participants file accepted, in bytes. */
	private const MAX_BYTES = 2097152;

	/** Nonce action of the preview request, localized for the tab's script. */
	public const PREVIEW_NONCE = 'ffc_bulk_issue_preview';

	/** Nonce action of the start and batch requests, localized for the tab's script (#1945). */
	public const RUN_NONCE = 'ffc_bulk_issue_run';

	/**
	 * Hooks the template download and the preview. Called from the Loader.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_ffc_bulk_issue_template', array( self::class, 'handle_template' ) );
		add_action( 'wp_ajax_ffc_bulk_issue_preview', array( self::class, 'handle_preview' ) );
		add_action( 'wp_ajax_ffc_bulk_issue_start', array( self::class, 'handle_start' ) );
		add_action( 'wp_ajax_ffc_bulk_issue_batch', array( self::class, 'handle_batch' ) );
	}

	/**
	 * Nonce action that binds a template download to one form.
	 *
	 * @param int $form_id Form post ID.
	 * @return string
	 */
	public static function template_nonce_action( int $form_id ): string {
		return 'ffc_bulk_issue_template_' . $form_id;
	}

	/**
	 * Streams the blank template of one form.
	 *
	 * @return void
	 */
	public static function handle_template(): void {
		$form_id = absint( RequestInput::get_get_string( 'form_id' ) );

		( new SyncCsvExport() )->handle( new BulkIssueTemplateSource( $form_id ) );
	}

	/**
	 * Answers one step of the tab: the mapping after the file is read, or the
	 * preview once the columns are mapped. Replies with the step's HTML and the
	 * label the button takes for the next step.
	 *
	 * @return void
	 */
	public static function handle_preview(): void {
		check_ajax_referer( self::PREVIEW_NONCE, 'nonce' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'ffcertificate' ) ), 403 );
		}

		$form_id = absint( RequestInput::get_post_string( 'form_id' ) );

		if ( $form_id <= 0 || 'ffc_form' !== get_post_type( $form_id ) || ! current_user_can( 'edit_post', $form_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose the form the certificates are issued for.', 'ffcertificate' ) ), 400 );
		}

		$error   = '';
		$parsed  = null;
		$mapping = array();
		$plan    = self::plan_from_request( $form_id, $parsed, $mapping, $error );

		ob_start();
		self::render_result( $form_id, $parsed, $mapping, $plan, $error );
		$html = (string) ob_get_clean();

		wp_send_json_success(
			array(
				'html'  => $html,
				'label' => null === $parsed ? __( 'Check the file', 'ffcertificate' ) : __( 'Preview', 'ffcertificate' ),
			)
		);
	}

	/**
	 * Starts the job for the file the operator checked. The file and the mapping
	 * are posted again, as on the preview, and the server plans them once more
	 * before staging: nothing the client sends is trusted as the plan itself.
	 *
	 * @return void
	 */
	public static function handle_start(): void {
		check_ajax_referer( self::RUN_NONCE, 'nonce' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'ffcertificate' ) ), 403 );
		}

		$form_id = absint( RequestInput::get_post_string( 'form_id' ) );

		if ( $form_id <= 0 || 'ffc_form' !== get_post_type( $form_id ) || ! current_user_can( 'edit_post', $form_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose the form the certificates are issued for.', 'ffcertificate' ) ), 400 );
		}

		$error   = '';
		$parsed  = null;
		$mapping = array();
		$plan    = self::plan_from_request( $form_id, $parsed, $mapping, $error );

		if ( null === $plan || array() === $plan['issue'] ) {
			wp_send_json_error(
				array( 'message' => '' !== $error ? $error : __( 'There is nothing to issue in this file.', 'ffcertificate' ) ),
				400
			);
		}

		$job_id = BulkIssueRunner::start( $form_id, get_current_user_id(), $plan );

		if ( is_wp_error( $job_id ) ) {
			wp_send_json_error( array( 'message' => $job_id->get_error_message() ), 500 );
		}

		wp_send_json_success( array( 'job_id' => $job_id ) );
	}

	/**
	 * Issues the next batch of a job the current user started, and answers with
	 * the counters. The client calls it until `done` is true.
	 *
	 * @return void
	 */
	public static function handle_batch(): void {
		check_ajax_referer( self::RUN_NONCE, 'nonce' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'ffcertificate' ) ), 403 );
		}

		$job_id = absint( RequestInput::get_post_string( 'job_id' ) );
		$job    = $job_id > 0 ? BulkIssueRepository::find_job( $job_id ) : null;

		if ( null === $job || get_current_user_id() !== $job['user_id'] || ! current_user_can( 'edit_post', $job['form_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'ffcertificate' ) ), 403 );
		}

		$runner = new BulkIssueRunner( new SubmissionHandler(), new SubmissionRepository() );
		$runner->process_batch( $job_id );

		$after = self::read_job( $job_id );

		if ( null === $after ) {
			wp_send_json_error( array( 'message' => __( 'The import could not be found.', 'ffcertificate' ) ), 404 );
		}

		$done    = BulkIssueRepository::STATUS_DONE === $after['status'];
		$handled = $after['issued'] + $after['skipped'];

		wp_send_json_success(
			array(
				'done' => $done,
				'text' => $done
					? sprintf(
						/* translators: 1: certificates issued, 2: lines skipped. */
						__( 'Done: %1$d certificate(s) issued, %2$d line(s) skipped.', 'ffcertificate' ),
						$job['issued'],
						$job['skipped']
					)
					: sprintf(
						/* translators: 1: lines handled, 2: lines in the file, 3: certificates issued, 4: lines skipped. */
						__( '%1$d of %2$d line(s) handled: %3$d issued, %4$d skipped.', 'ffcertificate' ),
						$handled,
						$job['total'],
						$job['issued'],
						$job['skipped']
					),
			)
		);
	}

	/**
	 * Reads a job's current state, after a batch has changed it.
	 *
	 * @param int $job_id Job ID.
	 * @return array{id: int, form_id: int, user_id: int, status: string, total: int, issued: int, skipped: int}|null
	 */
	private static function read_job( int $job_id ): ?array {
		return BulkIssueRepository::find_job( $job_id );
	}

	/**
	 * Reads the file and maps its columns, as the preview does. Sets `$parsed`
	 * and `$mapping` for the step that shows them, and returns the plan once the
	 * mapping is complete.
	 *
	 * @param int                       $form_id Form the certificates are issued for.
	 * @param array<string, mixed>|null $parsed  Set to the parsed file when one was uploaded.
	 * @param array<string, string>     $mapping Set to the column chosen for each key.
	 * @param string                    $error   Set to a message when the file cannot be used.
	 * @return array{issue: array<int, array<string, mixed>>, skipped: array<int, array{line: int, reason: string}>, no_email: array<int, int>}|null
	 */
	private static function plan_from_request( int $form_id, ?array &$parsed, array &$mapping, string &$error ): ?array {
		$plan   = null;
		$upload = self::read_upload( $error );

		if ( null !== $upload ) {
			$parsed  = BulkIssuePlanner::parse( $upload );
			$fields  = get_post_meta( $form_id, '_ffc_form_fields', true );
			$keys    = BulkIssuePlanner::template_columns( is_array( $fields ) ? $fields : array() );
			$keys    = array_values( array_unique( array_merge( BulkIssuePlanner::REQUIRED_KEYS, $keys ) ) );
			$mapping = self::submitted_mapping( $keys );

			if ( array() !== $mapping ) {
				$problems      = BulkIssuePlanner::mapping_problems( $mapping, $parsed['header'] );
				$dates         = self::date_choice();
				$date_problems = BulkIssuePlanner::date_problems( $dates, $parsed['header'] );

				if ( array() !== $problems ) {
					$error = __( 'Map a column from the file to each of name, CPF/RF and e-mail.', 'ffcertificate' );
				} elseif ( array() !== $date_problems ) {
					$error = 'event_date' === $date_problems[0]
						? __( 'Choose the day the certificates are dated.', 'ffcertificate' )
						: __( 'Choose the file column that holds the issue date.', 'ffcertificate' );
				} else {
					$plan = BulkIssuePlanner::plan( $parsed['rows'], $mapping, $parsed['header'], $dates, self::sends_links() );
				}
			}
		}

		return $plan;
	}

	/**
	 * The issue date the operator chose on the mapping step. An unknown mode
	 * falls back to dating each certificate when it is issued.
	 *
	 * @return array{mode: string, date: string, column: string}
	 */
	private static function date_choice(): array {
		$mode = RequestInput::get_post_string( 'ffc_issue_mode', BulkIssuePlanner::DATE_NOW );

		if ( ! in_array( $mode, array( BulkIssuePlanner::DATE_NOW, BulkIssuePlanner::DATE_EVENT, BulkIssuePlanner::DATE_COLUMN ), true ) ) {
			$mode = BulkIssuePlanner::DATE_NOW;
		}

		return array(
			'mode'   => $mode,
			'date'   => RequestInput::get_post_string( 'ffc_issue_date' ),
			'column' => RequestInput::get_post_string( 'ffc_issue_date_column' ),
		);
	}

	/**
	 * Renders the tab's panel body for the form being edited: the template link,
	 * the file field and the button, and an empty box the steps are written into.
	 *
	 * @param \WP_Post $post Form being edited.
	 * @return void
	 */
	public static function render_panel( \WP_Post $post ): void {
		$template = wp_nonce_url(
			admin_url( 'admin-post.php?action=ffc_bulk_issue_template&form_id=' . $post->ID ),
			self::template_nonce_action( $post->ID )
		);

		echo '<div class="ffc-bulk-issue" id="ffc-bulk-issue" data-form-id="' . esc_attr( (string) $post->ID ) . '">';
		echo '<p class="description">' . esc_html__( 'Issue certificates for a list of participants from a CSV file, without each of them submitting the form. Download the template, upload the file, map its columns and check the preview. Nothing is issued yet.', 'ffcertificate' ) . '</p>';
		echo '<p><a href="' . esc_url( $template ) . '">' . esc_html__( 'Download the CSV template for this form', 'ffcertificate' ) . '</a></p>';
		echo '<p><label for="ffc_bulk_file"><strong>' . esc_html__( 'Participants file (CSV)', 'ffcertificate' ) . '</strong></label><br>';
		echo '<input type="file" name="' . esc_attr( self::FILE_FIELD ) . '" id="ffc_bulk_file" accept=".csv,text/csv"></p>';

		AdminUI::render_button(
			array(
				'label'    => __( 'Check the file', 'ffcertificate' ),
				'category' => 'primary',
				'icon'     => 'eye',
			)
		);

		echo '<div class="ffc-bulk-issue-result" aria-live="polite"></div>';
		echo '</div>';
	}

	/**
	 * Writes one step's HTML: an error, the mapping, or the preview.
	 *
	 * @param int                       $form_id Chosen form.
	 * @param array<string, mixed>|null $parsed  Parsed file, once uploaded.
	 * @param array<string, string>     $mapping Mapping submitted so far.
	 * @param array<string, mixed>|null $plan    Plan, once the columns are mapped.
	 * @param string                    $error   Message to show, or ''.
	 * @return void
	 */
	private static function render_result( int $form_id, ?array $parsed, array $mapping, ?array $plan, string $error ): void {
		if ( '' !== $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}

		if ( null !== $parsed && null === $plan ) {
			self::render_mapping( $form_id, $parsed, $mapping );
		}

		if ( null !== $plan ) {
			self::render_plan( $plan );
		}
	}

	/**
	 * Reads the uploaded file. Sets `$error` and returns null when it cannot be
	 * used. Nothing is moved to disk: the contents stay in memory.
	 *
	 * @param string $error Set to a message when the upload is rejected.
	 * @return string|null File contents, or null.
	 */
	private static function read_upload( string &$error ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by check_ajax_referer() in handle_preview(); the file is checked by is_uploaded_file() and read, never echoed.
		$file = $_FILES[ self::FILE_FIELD ] ?? null;

		if ( ! is_array( $file ) || UPLOAD_ERR_NO_FILE === ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$error = __( 'Choose a CSV file to upload.', 'ffcertificate' );
			return null;
		}

		$tmp = is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '';

		if ( UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			$error = __( 'The file could not be uploaded.', 'ffcertificate' );
			return null;
		}

		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_BYTES ) {
			$error = __( 'The file is larger than 2 MB.', 'ffcertificate' );
			return null;
		}

		$content = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file of the request.

		if ( false === $content ) {
			$error = __( 'The file could not be read.', 'ffcertificate' );
			return null;
		}

		return $content;
	}

	/**
	 * The column mapping posted with the form. Each key maps to a column name
	 * sanitised as text; an unset key maps to ''. Empty when no mapping was
	 * posted, which is the first step.
	 *
	 * @param array<int, string> $keys Field names the mapping covers.
	 * @return array<string, string>
	 */
	private static function submitted_mapping( array $keys ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by check_ajax_referer() in handle_preview(); each value is sanitised below.
		$posted = isset( $_POST['ffc_map'] ) && is_array( $_POST['ffc_map'] ) ? wp_unslash( $_POST['ffc_map'] ) : null;

		if ( null === $posted ) {
			return array();
		}

		$mapping = array();

		foreach ( $keys as $key ) {
			$mapping[ $key ] = is_scalar( $posted[ $key ] ?? null ) ? sanitize_text_field( (string) $posted[ $key ] ) : '';
		}

		return $mapping;
	}

	/**
	 * The column selectors. Each identifier and form field picks the file
	 * column it comes from; the header's names guess the likely match.
	 *
	 * @param int                   $form_id Chosen form.
	 * @param array<string, mixed>  $parsed  Parsed file.
	 * @param array<string, string> $mapping Mapping submitted so far.
	 * @return void
	 */
	private static function render_mapping( int $form_id, array $parsed, array $mapping ): void {
		$header = $parsed['header'];
		$fields = get_post_meta( $form_id, '_ffc_form_fields', true );
		$keys   = array_values( array_unique( array_merge( BulkIssuePlanner::REQUIRED_KEYS, BulkIssuePlanner::template_columns( is_array( $fields ) ? $fields : array() ) ) ) );

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Form field', 'ffcertificate' ) . '</th><th>' . esc_html__( 'Column in the file', 'ffcertificate' ) . '</th></tr></thead><tbody>';

		foreach ( $keys as $key ) {
			$selected = $mapping[ $key ] ?? self::guess_column( $key, $header );
			$required = in_array( $key, BulkIssuePlanner::REQUIRED_KEYS, true );

			echo '<tr><td><code>' . esc_html( $key ) . '</code>' . ( $required ? ' <strong>*</strong>' : '' ) . '</td><td>';
			echo '<select name="ffc_map[' . esc_attr( $key ) . ']">';
			echo '<option value="">' . esc_html__( 'Not in this file', 'ffcertificate' ) . '</option>';

			foreach ( $header as $column ) {
				echo '<option value="' . esc_attr( $column ) . '"' . selected( $selected, $column, false ) . '>' . esc_html( $column ) . '</option>';
			}

			echo '</select></td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Fields marked * are required. The file has to be uploaded again on this step.', 'ffcertificate' ) . '</p>';

		self::render_issue_date( $header, self::date_choice() );
	}

	/**
	 * The issue-date selector: when each certificate is dated, one day for all,
	 * or a date column of the file.
	 *
	 * @param array<int, string>                                $header Header row.
	 * @param array{mode: string, date: string, column: string} $dates  Choice made so far.
	 * @return void
	 */
	private static function render_issue_date( array $header, array $dates ): void {
		$modes = array(
			BulkIssuePlanner::DATE_NOW    => __( 'When each certificate is issued', 'ffcertificate' ),
			BulkIssuePlanner::DATE_EVENT  => __( 'One date for every certificate', 'ffcertificate' ),
			BulkIssuePlanner::DATE_COLUMN => __( 'From a column of the file', 'ffcertificate' ),
		);

		echo '<fieldset class="ffc-bulk-issue-date"><legend><strong>' . esc_html__( 'Issue date', 'ffcertificate' ) . '</strong></legend>';
		echo '<p><label for="ffc_issue_mode">' . esc_html__( 'Date of each certificate', 'ffcertificate' ) . '</label><br>';
		echo '<select name="ffc_issue_mode" id="ffc_issue_mode">';

		foreach ( $modes as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $dates['mode'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select></p>';
		echo '<p><label for="ffc_issue_date">' . esc_html__( 'Day for every certificate', 'ffcertificate' ) . '</label><br>';
		echo '<input type="date" name="ffc_issue_date" id="ffc_issue_date" value="' . esc_attr( $dates['date'] ) . '"></p>';
		echo '<p><label for="ffc_issue_date_column">' . esc_html__( 'Column with the day of each certificate', 'ffcertificate' ) . '</label><br>';
		echo '<select name="ffc_issue_date_column" id="ffc_issue_date_column">';
		echo '<option value="">' . esc_html__( 'Not in this file', 'ffcertificate' ) . '</option>';

		foreach ( $header as $column ) {
			echo '<option value="' . esc_attr( $column ) . '"' . selected( $dates['column'], $column, false ) . '>' . esc_html( $column ) . '</option>';
		}

		echo '</select></p>';
		echo '<p><label for="ffc_issue_send_links">' . esc_html__( 'E-mail of each certificate', 'ffcertificate' ) . '</label><br>';
		echo '<select name="ffc_issue_send_links" id="ffc_issue_send_links">';
		echo '<option value="">' . esc_html__( 'Do not send', 'ffcertificate' ) . '</option>';
		echo '<option value="1"' . selected( self::sends_links(), true, false ) . '>' . esc_html__( 'Send the link as each certificate is issued', 'ffcertificate' ) . '</option>';
		echo '</select></p>';
		echo '<p class="description">' . esc_html__( 'To send later, use Send link by e-mail in the submissions list.', 'ffcertificate' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Whether each certificate's link e-mail goes out as it is issued.
	 *
	 * @return bool
	 */
	private static function sends_links(): bool {
		return '1' === RequestInput::get_post_string( 'ffc_issue_send_links' );
	}

	/**
	 * Column of the file likely to hold a field, by its name or the usual label.
	 *
	 * @param string             $key    Field name.
	 * @param array<int, string> $header Header row.
	 * @return string Matching column, or ''.
	 */
	private static function guess_column( string $key, array $header ): string {
		$aliases = array(
			'name'   => array( 'name', 'nome' ),
			'cpf_rf' => array( 'cpf_rf', 'cpf', 'rf', 'cpf/rf' ),
			'email'  => array( 'email', 'e-mail' ),
		);

		$wanted = $aliases[ $key ] ?? array( $key );

		foreach ( $header as $column ) {
			foreach ( $wanted as $alias ) {
				if ( 0 === strcasecmp( trim( $column ), $alias ) ) {
					return $column;
				}
			}
		}

		return '';
	}

	/**
	 * Renders the counts and the skipped rows of a plan.
	 *
	 * @param array<string, mixed> $plan Output of BulkIssuePlanner::plan().
	 * @return void
	 */
	private static function render_plan( array $plan ): void {
		$issue   = count( $plan['issue'] );
		$skipped = count( $plan['skipped'] );

		echo '<h3>' . esc_html__( 'Preview', 'ffcertificate' ) . '</h3>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: rows that would be issued, 2: rows that would be skipped. */
				__( '%1$d certificate(s) would be issued and %2$d row(s) skipped. Nothing has been issued yet.', 'ffcertificate' ),
				$issue,
				$skipped
			)
		) . '</p>';

		if ( $issue > 0 ) {
			echo '<p>';
			Button::render(
				array(
					'label'    => sprintf(
						/* translators: %d: certificates that will be issued. */
						__( 'Issue %d certificate(s)', 'ffcertificate' ),
						$issue
					),
					'category' => 'primary',
					'icon'     => 'upload',
					'class'    => 'ffc-bulk-issue-start',
				)
			);
			echo '</p>';
			echo '<p class="ffc-bulk-issue-progress" aria-live="polite"></p>';
		}

		if ( array() !== $plan['no_email'] ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: comma-separated line numbers. */
					__( 'Without an e-mail, so issued with no account: lines %s.', 'ffcertificate' ),
					implode( ', ', $plan['no_email'] )
				)
			) . '</p>';
		}

		if ( array() !== $plan['skipped'] ) {
			$by_reason = array_count_values( array_map( 'strval', array_column( $plan['skipped'], 'reason' ) ) );
			$parts     = array();

			foreach ( $by_reason as $reason => $count ) {
				$parts[] = sprintf(
					/* translators: 1: number of skipped rows, 2: reason the rows were skipped. */
					__( '%1$d: %2$s', 'ffcertificate' ),
					$count,
					self::reason_label( (string) $reason )
				);
			}

			echo '<p>' . esc_html__( 'Skipped, by reason:', 'ffcertificate' ) . ' ' . esc_html( implode( '; ', $parts ) ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Line', 'ffcertificate' ) . '</th><th>' . esc_html__( 'Reason', 'ffcertificate' ) . '</th></tr></thead><tbody>';

			foreach ( $plan['skipped'] as $row ) {
				echo '<tr><td>' . esc_html( (string) $row['line'] ) . '</td><td>' . esc_html( self::reason_label( $row['reason'] ) ) . '</td></tr>';
			}

			echo '</tbody></table>';
		}
	}

	/**
	 * Translated label of a skip reason.
	 *
	 * @param string $reason One of the BulkIssuePlanner SKIP_* constants.
	 * @return string Translated reason.
	 */
	private static function reason_label( string $reason ): string {
		switch ( $reason ) {
			case BulkIssuePlanner::SKIP_EMPTY_ROW:
				return __( 'Empty row', 'ffcertificate' );
			case BulkIssuePlanner::SKIP_MISSING_NAME:
				return __( 'No name', 'ffcertificate' );
			case BulkIssuePlanner::SKIP_MISSING_CPF_RF:
				return __( 'No CPF or RF', 'ffcertificate' );
			case BulkIssuePlanner::SKIP_INVALID_CPF_RF:
				return __( 'CPF or RF with invalid check digits', 'ffcertificate' );
			case BulkIssuePlanner::SKIP_INVALID_EMAIL:
				return __( 'Invalid e-mail', 'ffcertificate' );
			case BulkIssuePlanner::SKIP_DUPLICATE_FILE:
				return __( 'Same CPF or RF as an earlier line of the file', 'ffcertificate' );
			case BulkIssuePlanner::SKIP_INVALID_DATE:
				return __( 'Date that cannot be read', 'ffcertificate' );
			default:
				return $reason;
		}
	}
}
