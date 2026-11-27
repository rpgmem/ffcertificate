<?php
/**
 * Certificate readings page
 *
 * What the event terminals read for one certificate form (#2892): who got a
 * certificate, who waits on profile data and who was refused, and why.
 * Reached from the form's Terminal tab, so it has no menu row.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.53.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Audience\AudienceAdminTerminals;
use FreeFormCertificate\Audience\AudienceTerminalReader;
use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\PiiAccessPolicy;
use FreeFormCertificate\Core\PiiReveal;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Core\TerminalTargetKey;
use FreeFormCertificate\Frontend\CertificateFormTarget;
use FreeFormCertificate\Frontend\CertificateTerminalReadingRepository as Readings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The readings screen of a certificate form.
 *
 * @phpstan-import-type TerminalRow from \FreeFormCertificate\Audience\AudienceTerminalReader
 */
final class CertificateReadingsPage {

	/** The page slug. */
	public const MENU_SLUG = 'ffc-certificate-readings';

	/** Who sees the screen. */
	public const CAP = 'ffc_checkin_certificates';

	/** Who reveals a reader's RF. */
	public const PII_CAP = 'ffc_view_certificates_pii';

	/** The reveal type of a reader's RF ({@see AdminAjax}). */
	public const REVEAL_TYPE = 'certificate_reading';

	/** Rows per page. */
	private const PER_PAGE = 50;

	/** The most accounts a search narrows the list to. */
	private const SEARCH_LIMIT = 200;

	/**
	 * The result filter on screen, which the export carries.
	 *
	 * @var string
	 */
	private string $result = '';

	/**
	 * The search on screen, which the export carries.
	 *
	 * @var string
	 */
	private string $search = '';

	/**
	 * Hook the menu and the assets.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		// Here, not on `admin_menu`, which never fires on admin-ajax, where the
		// export runs (CLAUDE.md "CSV export architecture").
		\FreeFormCertificate\Core\SourceRegistry::register(
			CertificateReadingsExportSource::TYPE,
			static fn(): CertificateReadingsExportSource => new CertificateReadingsExportSource()
		);
	}

	/**
	 * Register the screen with no menu row (no parent): the Terminal tab of
	 * the form links to it.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'',
			__( 'Terminal readings', 'ffcertificate' ),
			__( 'Terminal readings', 'ffcertificate' ),
			self::CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * The reveal script, on this screen only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}
		PiiReveal::enqueue_assets();
		// The Export CSV button drives the shared batched export (#2892).
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-batched-export' );
		\FreeFormCertificate\Core\AssetRegistry::enqueue_styles( 'ffc-progress-overlay' );
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			'ffc-certificate-readings',
			FFC_PLUGIN_URL . "assets/js/ffc-certificate-readings{$s}.js",
			array( 'ffc-batched-export' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-certificate-readings',
			'ffcCertificateReadings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( CertificateReadingsExportSource::NONCE ),
				'strings' => array(
					'preparing' => __( 'Preparing…', 'ffcertificate' ),
					/* translators: %1$d processed, %2$d total */
					'exporting' => __( 'Exporting %1$d/%2$d…', 'ffcertificate' ),
					'done'      => __( 'Done!', 'ffcertificate' ),
					'error'     => __( 'The export failed. Try again.', 'ffcertificate' ),
				),
			)
		);
	}

	/**
	 * The screen's address for a form.
	 *
	 * @param int $form_id Form id.
	 * @return string
	 */
	public static function url( int $form_id ): string {
		return add_query_arg(
			array(
				'page'    => self::MENU_SLUG,
				'form_id' => $form_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Whether the current user may see the screen.
	 *
	 * @return bool
	 */
	public static function can_view(): bool {
		return Capabilities::current_user_can_admin_or( self::CAP );
	}

	/**
	 * Render the screen.
	 */
	public function render_page(): void {
		if ( ! self::can_view() ) {
			wp_die( esc_html__( 'You do not have permission to see the terminal readings.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}
		$form_id = RequestInput::get_get_int( 'form_id' );
		$form    = $form_id > 0 ? get_post( $form_id ) : null;
		if ( ! $form instanceof \WP_Post || 'ffc_form' !== $form->post_type ) {
			wp_die( esc_html__( 'This form does not exist.', 'ffcertificate' ), '', array( 'response' => 404 ) );
		}

		$now       = time();
		$terminals = AudienceTerminalReader::active_for_target( TerminalTargetKey::of( CertificateFormTarget::TYPE, $form_id ), $now );
		$result    = RequestInput::get_get_key( 'result' );
		$result    = in_array( $result, array( Readings::ISSUED, Readings::AWAITING_DATA, Readings::REFUSED ), true ) ? $result : '';
		$search    = trim( RequestInput::get_get_string( 's' ) );

		$this->result = $result;
		$this->search = $search;
		$people       = '' === $search ? null : self::matching_accounts( $search );
		$paged        = max( 1, RequestInput::get_get_int( 'paged' ) );
		$total        = Readings::count_matching( $form_id, $result, $people );
		$rows         = Readings::page( $form_id, $result, $people, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$reveal       = PiiAccessPolicy::TIER_MASKED !== PiiAccessPolicy::resolve( self::PII_CAP, null );
		$nonce        = $reveal ? wp_create_nonce( 'ffc_reveal_pii_nonce' ) : '';

		echo '<div class="wrap ffc-admin-page ffc-page-certificate-readings ffc-boxed">';
		$this->render_head( $form, count( $terminals ) );
		$this->render_stats( Readings::counts( $form_id ) );
		$this->render_offline( $terminals, $now );
		$this->render_filters( $form_id, $search, $result );
		$this->render_table( $rows, $nonce );
		$this->render_pagination( $form_id, $search, $result, $total, $paged );
		echo '<p class="description ffc-readings-note">' . esc_html__( 'Personal data is masked; Reveal is recorded in the activity log. A person read twice shows once, with the result of the first valid reading.', 'ffcertificate' ) . '</p>';
		echo '</div>';
	}

	/**
	 * The title, the event's day and hours, and Back to the form.
	 *
	 * @param \WP_Post $form      The form.
	 * @param int      $terminals Terminals holding it.
	 */
	private function render_head( \WP_Post $form, int $terminals ): void {
		$window = CertificateFormTarget::window( (int) $form->ID );
		echo '<div class="ffc-readings-head"><div>';
		/* translators: %s: form title */
		echo '<h1>' . esc_html( sprintf( __( 'Terminal readings · %s', 'ffcertificate' ), get_the_title( $form ) ) ) . '</h1>';
		$parts = array();
		if ( null !== $window ) {
			$parts[] = DateFormatter::format_wallclock_date( $window['date'] );
			/* translators: 1: opening time, 2: closing time */
			$parts[] = sprintf( __( 'form open from %1$s to %2$s', 'ffcertificate' ), DateFormatter::format_time( $window['opens'] ), DateFormatter::format_time( $window['closes'] ) );
		}
		/* translators: %s: number of terminals */
		$parts[] = sprintf( _n( '%s terminal', '%s terminals', $terminals, 'ffcertificate' ), number_format_i18n( $terminals ) );
		echo '<p class="ffc-readings-subtitle">' . esc_html( implode( ' · ', $parts ) ) . '</p>';
		echo '</div><div class="ffc-readings-actions">';
		AdminUI::render_button(
			array(
				'label' => __( 'Back to the form', 'ffcertificate' ),
				'icon'  => 'chevron-left',
				'href'  => (string) get_edit_post_link( (int) $form->ID, 'raw' ) . '#ffc-tab-terminal',
			)
		);
		// The export carries the filters on screen (#2892).
		AdminUI::render_button(
			array(
				'label' => __( 'Export CSV', 'ffcertificate' ),
				'icon'  => 'download',
				'id'    => 'ffc-readings-export',
				'attrs' => array(
					'data-form-id' => (string) $form->ID,
					'data-result'  => $this->result,
					'data-search'  => $this->search,
				),
			)
		);
		echo '</div></div>';
		// WordPress moves admin notices below this line, so they never split the head.
		echo '<hr class="wp-header-end">';
	}

	/**
	 * The four counts.
	 *
	 * @param array{issued: int, awaiting_data: int, refused: int} $counts Readings by result.
	 */
	private function render_stats( array $counts ): void {
		$cards = array(
			array(
				'label' => __( 'Readings', 'ffcertificate' ),
				'value' => array_sum( $counts ),
				'icon'  => 'user-check',
			),
			array(
				'label' => __( 'Certificates issued', 'ffcertificate' ),
				'value' => $counts[ Readings::ISSUED ],
				'icon'  => 'award',
				'tone'  => 'success',
			),
			array(
				'label' => __( 'Awaiting profile data', 'ffcertificate' ),
				'value' => $counts[ Readings::AWAITING_DATA ],
				'icon'  => 'user-clock',
				'tone'  => 'warning',
			),
			array(
				'label' => __( 'Refused', 'ffcertificate' ),
				'value' => $counts[ Readings::REFUSED ],
				'icon'  => 'cross',
				'tone'  => 'danger',
			),
		);
		echo '<div class="ffc-stats">';
		foreach ( $cards as $card ) {
			echo AdminUI::get_stat_card( $card ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_stat_card() escapes every value.
		}
		echo '</div>';
	}

	/**
	 * A warning for each offline terminal that holds readings not sent yet.
	 *
	 * @param array $terminals Terminals holding the form.
	 * @param int   $now       Unix time.
	 * @phpstan-param list<TerminalRow> $terminals
	 */
	private function render_offline( array $terminals, int $now ): void {
		foreach ( $terminals as $terminal ) {
			if ( AudienceAdminTerminals::STATE_OFFLINE !== AudienceAdminTerminals::state( $terminal, $now ) || $terminal['queued'] <= 0 ) {
				continue;
			}
			$message = sprintf(
				/* translators: 1: terminal name, 2: number of readings */
				_n(
					'The terminal %1$s is offline with %2$s reading in its queue. The certificates are issued when it syncs, with the time of each reading.',
					'The terminal %1$s is offline with %2$s readings in its queue. The certificates are issued when it syncs, with the time of each reading.',
					$terminal['queued'],
					'ffcertificate'
				),
				'<strong>' . esc_html( $terminal['label'] ) . '</strong>',
				esc_html( number_format_i18n( $terminal['queued'] ) )
			);
			\FreeFormCertificate\Core\AdminNotice::callout( 'warning', $message, array( 'classes' => array( 'inline' ) ) );
		}
	}

	/**
	 * Search by person and a filter by result.
	 *
	 * @param int    $form_id Form id.
	 * @param string $search  Search term.
	 * @param string $result  Result filter.
	 */
	private function render_filters( int $form_id, string $search, string $result ): void {
		$options = array(
			''                      => __( 'All results', 'ffcertificate' ),
			Readings::ISSUED        => __( 'Issued', 'ffcertificate' ),
			Readings::AWAITING_DATA => __( 'Awaiting data', 'ffcertificate' ),
			Readings::REFUSED       => __( 'Refused', 'ffcertificate' ),
		);
		echo '<form method="get" class="ffc-list">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '">';
		echo '<input type="hidden" name="form_id" value="' . esc_attr( (string) $form_id ) . '">';
		echo '<div class="ffc-list-filters">';
		echo '<div class="ffc-list-filters__field">';
		echo '<label class="ffc-list-filters__label" for="ffc-readings-search">' . esc_html__( 'Search person', 'ffcertificate' ) . '</label>';
		echo '<input type="search" id="ffc-readings-search" name="s" class="regular-text" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Name, e-mail or RF', 'ffcertificate' ) . '">';
		echo '</div><div class="ffc-list-filters__field">';
		echo '<label class="ffc-list-filters__label" for="ffc-readings-result">' . esc_html__( 'Result', 'ffcertificate' ) . '</label>';
		echo '<select id="ffc-readings-result" name="result">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $result, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></div><div class="ffc-list-filters__actions">';
		AdminUI::render_button(
			array(
				'label' => __( 'Filter', 'ffcertificate' ),
				'icon'  => 'filter',
				'type'  => 'submit',
			)
		);
		if ( '' !== $search || '' !== $result ) {
			AdminUI::render_button(
				array(
					'label' => __( 'Clear', 'ffcertificate' ),
					'icon'  => 'eraser',
					'href'  => self::url( $form_id ),
				)
			);
		}
		echo '</div></div></form>';
	}

	/**
	 * The readings table.
	 *
	 * @param list<array<string, mixed>> $rows  Rows of {@see Readings::page()}.
	 * @param string                     $nonce Reveal nonce, or '' when the user may not reveal.
	 */
	private function render_table( array $rows, string $nonce ): void {
		echo '<div class="card">';
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No reading matches.', 'ffcertificate' ) . '</p></div>';
			return;
		}
		echo '<div class="ffc-readings-scroll"><table class="widefat striped ffc-readings-table"><thead><tr>';
		foreach ( array( __( 'Person', 'ffcertificate' ), __( 'Reading', 'ffcertificate' ), __( 'Terminal', 'ffcertificate' ), __( 'Result', 'ffcertificate' ), __( 'Certificate', 'ffcertificate' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$labels = array();
		foreach ( $rows as $row ) {
			$terminal_id = is_int( $row['terminal_id'] ?? null ) ? $row['terminal_id'] : 0;
			if ( ! isset( $labels[ $terminal_id ] ) ) {
				$terminal               = $terminal_id > 0 ? AudienceTerminalReader::terminal( $terminal_id ) : null;
				$labels[ $terminal_id ] = null !== $terminal ? $terminal['label'] : '—';
			}
			$this->render_row( $row, $labels[ $terminal_id ], $nonce );
		}
		echo '</tbody></table></div></div>';
	}

	/**
	 * One reading.
	 *
	 * @param array<string, mixed> $row      Row of {@see Readings::page()}.
	 * @param string               $terminal The terminal's name.
	 * @param string               $nonce    Reveal nonce, or ''.
	 */
	private function render_row( array $row, string $terminal, string $nonce ): void {
		$user_id = is_int( $row['user_id'] ?? null ) ? $row['user_id'] : 0;
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		$rf      = $user_id > 0 ? \FreeFormCertificate\Core\CertificateSigners::user_rf( $user_id ) : '';
		$result  = is_string( $row['result'] ?? null ) ? $row['result'] : '';
		$code    = is_string( $row['auth_code'] ?? null ) ? $row['auth_code'] : '';
		$read_at = is_int( $row['read_at'] ?? null ) ? $row['read_at'] : 0;

		echo '<tr><td><strong>' . esc_html( $user ? (string) $user->display_name : __( 'Account deleted', 'ffcertificate' ) ) . '</strong>';
		if ( '' !== $rf ) {
			echo '<br><span class="description">' . esc_html__( 'RF', 'ffcertificate' ) . ' <code class="ffc-pii-value" data-field="rf">' . esc_html( DocumentFormatter::mask_rf( $rf ) ) . '</code>';
			if ( '' !== $nonce ) {
				$reveal_button = PiiReveal::field_button(
					array(
						'field'         => 'rf',
						'type'          => self::REVEAL_TYPE,
						'submission-id' => is_int( $row['id'] ?? null ) ? $row['id'] : 0,
						'nonce'         => $nonce,
					),
					true
				);
				echo ' ' . $reveal_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_button() escapes every attribute.
			}
			echo '</span>';
		}
		echo '</td>';
		echo '<td>' . esc_html( $read_at > 0 ? DateFormatter::format_time( $read_at ) : '—' ) . '</td>';
		echo '<td>' . esc_html( $terminal ) . '</td>';
		echo '<td><span class="ffc-reading-result ffc-reading-result-' . esc_attr( str_replace( '_', '-', $result ) ) . '">' . esc_html( self::result_label( $result ) ) . '</span>';
		$why = self::why( $row );
		if ( '' !== $why ) {
			echo '<br><span class="description">' . esc_html( $why ) . '</span>';
		}
		echo '</td>';
		echo '<td>' . ( '' !== $code ? '<code>' . esc_html( DocumentFormatter::format_auth_code( $code, DocumentFormatter::PREFIX_CERTIFICATE ) ) . '</code>' : '—' ) . '</td></tr>';
	}

	/**
	 * A result in words.
	 *
	 * @param string $result One of the result constants.
	 * @return string
	 */
	public static function result_label( string $result ): string {
		$labels = array(
			Readings::ISSUED        => __( 'Issued', 'ffcertificate' ),
			Readings::AWAITING_DATA => __( 'Awaiting data', 'ffcertificate' ),
			Readings::REFUSED       => __( 'Refused', 'ffcertificate' ),
		);
		return $labels[ $result ] ?? $result;
	}

	/**
	 * What a result waits on, or why it was refused.
	 *
	 * @param array<string, mixed> $row Reading row.
	 * @return string
	 */
	public static function why( array $row ): string {
		if ( Readings::AWAITING_DATA === ( $row['result'] ?? '' ) ) {
			$missing = is_array( $row['missing'] ?? null ) ? array_filter( $row['missing'], 'is_string' ) : array();
			/* translators: %s: comma-separated field names */
			return array() === $missing ? __( 'Waiting on profile data', 'ffcertificate' ) : sprintf( __( 'Missing: %s', 'ffcertificate' ), implode( ', ', $missing ) );
		}
		if ( Readings::REFUSED !== ( $row['result'] ?? '' ) ) {
			return '';
		}
		$reasons = array(
			'ffc_terminal_window_closed'  => __( 'The form was closed', 'ffcertificate' ),
			'ffc_terminal_ticket_offline' => __( 'A ticket needs a connection', 'ffcertificate' ),
			'ffc_terminal_ticket_refused' => __( 'The ticket was refused', 'ffcertificate' ),
			'ffc_terminal_restricted'     => __( 'Not allowed on this form', 'ffcertificate' ),
			'ffc_terminal_limit'          => __( 'Submission limit reached', 'ffcertificate' ),
		);
		$reason  = is_string( $row['reason'] ?? null ) ? $row['reason'] : '';
		return $reasons[ $reason ] ?? '';
	}

	/**
	 * The page links.
	 *
	 * @param int    $form_id Form id.
	 * @param string $search  Search term.
	 * @param string $result  Result filter.
	 * @param int    $total   Matching readings.
	 * @param int    $paged   Current page.
	 */
	private function render_pagination( int $form_id, string $search, string $result, int $total, int $paged ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages <= 1 ) {
			return;
		}
		$base = add_query_arg(
			array_filter(
				array(
					's'      => $search,
					'result' => $result,
				)
			),
			self::url( $form_id )
		);
		echo '<div class="tablenav bottom"><div class="tablenav-pages">';
		echo wp_kses_post(
			(string) paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%', $base ),
					'format'    => '',
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'total'     => $pages,
					'current'   => $paged,
				)
			)
		);
		echo '</div></div>';
	}

	/**
	 * The accounts a search names: by name, login or e-mail, or by the full
	 * RF through its hash, never by decrypting.
	 *
	 * @param string $term Search term.
	 * @return list<int>
	 */
	public static function matching_accounts( string $term ): array {
		$digits = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $term );
		if ( 1 === preg_match( '/^[\d.\-\s]+$/', $term ) && 'rf' === \FreeFormCertificate\Core\DataSanitizer::classify_cpf_rf( $digits ) ) {
			$hash = \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'rf', $digits );
			if ( null === $hash ) {
				return array();
			}
			return array_values( array_map( 'intval', ( new \FreeFormCertificate\Repositories\UserProfileRepository() )->findUserIdsByHash( 'rf_hash', $hash ) ) );
		}
		$ids = get_users(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'fields'         => 'ID',
				'number'         => self::SEARCH_LIMIT,
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}
}
