<?php
/**
 * BulkIssuePage
 *
 * The "Issue in bulk" screen (#1945), under Certificates → Submissions. Sprint 1
 * covers the first half of the flow: choose a form, download its CSV template,
 * upload a participants file, map its columns onto the form and preview what
 * would be issued and what would be skipped. Nothing is written here yet.
 *
 * The file is parsed from the request and never stored: staging it so the
 * mapping step does not need the file again is part of the job-engine sprint.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Core\SyncCsvExport;
use FreeFormCertificate\Submissions\BulkIssuePlanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and handles the bulk issue screen.
 */
final class BulkIssuePage {

	public const SLUG = 'ffc-bulk-issue';

	/** Capability that opens the screen and downloads the template (#1945). */
	public const CAP = 'ffc_import_certificates';

	/** Field name of the uploaded participants file. */
	private const FILE_FIELD = 'ffc_bulk_file';

	/** Largest participants file accepted, in bytes. */
	private const MAX_BYTES = 2097152;

	/** Nonce action of the upload form. */
	private const PREVIEW_NONCE = 'ffc_bulk_issue_preview';

	/**
	 * Hooks the screen and its template download. Called from the Loader.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 20 );
		add_action( 'admin_post_ffc_bulk_issue_template', array( self::class, 'handle_template' ) );
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
	 * Adds the screen under the forms menu, beside Submissions.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=ffc_form',
			__( 'Issue in bulk', 'ffcertificate' ),
			__( 'Issue in bulk', 'ffcertificate' ),
			self::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
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
	 * Renders the screen and handles the upload and the mapping.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ) );
		}

		$form_id = absint( RequestInput::get_post_string( 'form_id' ) );
		$forms   = self::forms();
		$error   = '';
		$plan    = null;
		$parsed  = null;
		$mapping = array();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared to a constant, never echoed.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			check_admin_referer( self::PREVIEW_NONCE );

			$upload = self::read_upload( $error );

			if ( null !== $upload && $form_id > 0 ) {
				$parsed  = BulkIssuePlanner::parse( $upload );
				$fields  = get_post_meta( $form_id, '_ffc_form_fields', true );
				$keys    = BulkIssuePlanner::template_columns( is_array( $fields ) ? $fields : array() );
				$keys    = array_values( array_unique( array_merge( BulkIssuePlanner::REQUIRED_KEYS, $keys ) ) );
				$mapping = self::submitted_mapping( $keys );

				if ( array() !== $mapping ) {
					$problems = BulkIssuePlanner::mapping_problems( $mapping, $parsed['header'] );

					if ( array() === $problems ) {
						$plan = BulkIssuePlanner::plan( $parsed['rows'], $mapping, $parsed['header'] );
					} else {
						$error = __( 'Map a column from the file to each of name, CPF/RF and e-mail.', 'ffcertificate' );
					}
				}
			} elseif ( '' === $error && $form_id <= 0 ) {
				$error = __( 'Choose the form the certificates are issued for.', 'ffcertificate' );
			}
		}

		echo '<div class="wrap ffc-admin-page ffc-page-bulk-issue">';
		echo '<h1>' . esc_html__( 'Issue in bulk', 'ffcertificate' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Issue certificates for a list of participants from a CSV file, without each of them submitting the form. Choose the form, download its template, upload the file, map its columns and check the preview.', 'ffcertificate' ) . '</p>';

		if ( '' !== $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}

		self::render_form( $forms, $form_id, $parsed, $mapping );

		if ( null !== $plan ) {
			self::render_plan( $plan );
		}

		echo '</div>';
	}

	/**
	 * Published forms, for the selector.
	 *
	 * @return array<int, \WP_Post>
	 */
	private static function forms(): array {
		return get_posts(
			array(
				'post_type'      => 'ffc_form',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Reads the uploaded file. Sets `$error` and returns null when it cannot be
	 * used. Nothing is moved to disk: the contents stay in memory.
	 *
	 * @param string $error Set to a message when the upload is rejected.
	 * @return string|null File contents, or null.
	 */
	private static function read_upload( string &$error ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by check_admin_referer() in render(); the file is checked by is_uploaded_file() and read, never echoed.
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by check_admin_referer() in render(); each value is sanitised below.
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
	 * Renders the form selector and the upload, mapping and preview fields.
	 *
	 * @param array<int, \WP_Post>      $forms   Published forms.
	 * @param int                       $form_id Chosen form, 0 for none.
	 * @param array<string, mixed>|null $parsed  Parsed file, once uploaded.
	 * @param array<string, string>     $mapping Mapping submitted so far.
	 * @return void
	 */
	private static function render_form( array $forms, int $form_id, ?array $parsed, array $mapping ): void {
		$action = admin_url( 'edit.php?post_type=ffc_form&page=' . self::SLUG );

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( $action ) . '">';
		wp_nonce_field( self::PREVIEW_NONCE );

		echo '<p><label for="ffc_bulk_form"><strong>' . esc_html__( 'Form', 'ffcertificate' ) . '</strong></label><br>';
		echo '<select name="form_id" id="ffc_bulk_form">';
		echo '<option value="0">' . esc_html__( 'Choose a form…', 'ffcertificate' ) . '</option>';

		foreach ( $forms as $form ) {
			echo '<option value="' . esc_attr( (string) $form->ID ) . '"' . selected( $form_id, $form->ID, false ) . '>' . esc_html( $form->post_title ) . '</option>';
		}

		echo '</select></p>';

		if ( $form_id > 0 ) {
			$template = wp_nonce_url(
				admin_url( 'admin-post.php?action=ffc_bulk_issue_template&form_id=' . $form_id ),
				self::template_nonce_action( $form_id )
			);
			echo '<p><a href="' . esc_url( $template ) . '">' . esc_html__( 'Download the CSV template for this form', 'ffcertificate' ) . '</a></p>';
		}

		echo '<p><label for="ffc_bulk_file"><strong>' . esc_html__( 'Participants file (CSV)', 'ffcertificate' ) . '</strong></label><br>';
		echo '<input type="file" name="' . esc_attr( self::FILE_FIELD ) . '" id="ffc_bulk_file" accept=".csv,text/csv"></p>';

		if ( null !== $parsed ) {
			self::render_mapping( $form_id, $parsed, $mapping );
		}

		AdminUI::render_button(
			array(
				'label'    => null === $parsed ? __( 'Check the file', 'ffcertificate' ) : __( 'Preview', 'ffcertificate' ),
				'category' => 'primary',
				'icon'     => 'eye',
				'type'     => 'submit',
				'name'     => 'submit',
			)
		);

		echo '</form>';
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
			default:
				return $reason;
		}
	}
}
