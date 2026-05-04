<?php
/**
 * AdminUserColumns
 *
 * Adds custom columns to WordPress users list:
 * - Certificates count
 * - Appointments count
 * - Login as User action link
 *
 * @package FreeFormCertificate\Admin
 * @since 3.1.0
 * @version 4.2.0 - Added appointments column and separate user actions column
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin User Columns.
 */
class AdminUserColumns {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Cached flag for appointments table existence
	 *
	 * @since 4.6.13
	 * @var bool|null
	 */
	private static ?bool $appointments_table_exists = null;

	/**
	 * Cached flag for recruitment classification table existence
	 *
	 * @since 6.2.0
	 * @var bool|null
	 */
	private static ?bool $recruitment_table_exists = null;

	/**
	 * Batch-cached recruitment notice counts (user_id => count of distinct notices)
	 *
	 * @since 6.2.0
	 * @var array<int, int>|null
	 */
	private static ?array $recruitment_counts_cache = null;

	/**
	 * Cached dashboard URL for user actions column
	 *
	 * @since 4.6.13
	 * @var string|null
	 */
	private static ?string $dashboard_url_cache = null;

	/**
	 * Batch-cached certificate counts (user_id => count)
	 *
	 * @since 4.9.7
	 * @var array<int, int>|null
	 */
	private static ?array $certificate_counts_cache = null;

	/**
	 * Batch-cached appointment counts (user_id => count)
	 *
	 * @since 4.9.7
	 * @var array<int, int>|null
	 */
	private static ?array $appointment_counts_cache = null;

	/**
	 * Initialize user columns
	 */
	public static function init(): void {
		// Add custom columns to users list.
		add_filter( 'manage_users_columns', array( __CLASS__, 'add_custom_columns' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_custom_column' ), 10, 3 );

		// Mark our value columns + the built-in display name as sortable.
		add_filter( 'manage_users_sortable_columns', array( __CLASS__, 'register_sortable_columns' ) );
		// SQL-level sort by count requires rewriting WP_User_Query mid-flight.
		add_action( 'pre_user_query', array( __CLASS__, 'apply_sort_to_user_query' ) );

		// Enqueue styles for the column.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
	}

	/**
	 * Add custom columns to users table
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string> Modified columns
	 */
	public static function add_custom_columns( array $columns ): array {
		// Add after "Posts" column.
		$new_columns = array();

		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;

			if ( 'posts' === $key ) {
				$new_columns['ffc_certificates'] = __( 'Certificates', 'ffcertificate' );
				$new_columns['ffc_appointments'] = __( 'Appointments', 'ffcertificate' );
				$new_columns['ffc_notices']      = __( 'Notices', 'ffcertificate' );
				$new_columns['ffc_user_actions'] = __( 'User Actions', 'ffcertificate' );
			}
		}

		return $new_columns;
	}

	/**
	 * Register the columns that should advertise themselves as sortable.
	 *
	 * `name` is the built-in display-name column WP already renders but
	 * doesn't mark sortable on its own. The three count columns are SQL-
	 * sorted via {@see self::apply_sort_to_user_query()}.
	 *
	 * @param array<string, string|array<int, mixed>> $sortable Existing sortable columns.
	 * @return array<string, string|array<int, mixed>>
	 */
	public static function register_sortable_columns( array $sortable ): array {
		$sortable['name']             = 'display_name';
		$sortable['ffc_certificates'] = 'ffc_certificates';
		$sortable['ffc_appointments'] = 'ffc_appointments';
		$sortable['ffc_notices']      = 'ffc_notices';
		return $sortable;
	}

	/**
	 * Render content for custom columns
	 *
	 * @param string $output Custom column output.
	 * @param string $column_name Column name.
	 * @param int    $user_id User ID.
	 * @return string Column HTML
	 */
	public static function render_custom_column( string $output, string $column_name, int $user_id ): string {
		switch ( $column_name ) {
			case 'ffc_certificates':
				return self::render_certificates_count( $user_id );

			case 'ffc_appointments':
				return self::render_appointments_count( $user_id );

			case 'ffc_notices':
				return self::render_notices_count( $user_id );

			case 'ffc_user_actions':
				return self::render_user_actions( $user_id );

			default:
				return $output;
		}
	}

	/**
	 * Render certificates count
	 *
	 * @param int $user_id User ID.
	 * @return string Column HTML
	 */
	private static function render_certificates_count( int $user_id ): string {
		$count = self::get_user_certificate_count( $user_id );

		if ( 0 === $count ) {
			return '<span class="ffc-empty-value">—</span>';
		}

		return sprintf(
			'<strong>%d</strong> %s',
			$count,
			_n( 'certificate', 'certificates', $count, 'ffcertificate' )
		);
	}

	/**
	 * Render appointments count
	 *
	 * @param int $user_id User ID.
	 * @return string Column HTML
	 */
	private static function render_appointments_count( int $user_id ): string {
		$count = self::get_user_appointment_count( $user_id );

		if ( 0 === $count ) {
			return '<span class="ffc-empty-value">—</span>';
		}

		return sprintf(
			'<strong>%d</strong> %s',
			$count,
			_n( 'appointment', 'appointments', $count, 'ffcertificate' )
		);
	}

	/**
	 * Render recruitment notices count.
	 *
	 * Counts the distinct notices in which the user appears as a candidate
	 * (i.e. has at least one classification row joined via candidate.user_id).
	 *
	 * @since 6.2.0
	 * @param int $user_id User ID.
	 * @return string Column HTML
	 */
	private static function render_notices_count( int $user_id ): string {
		$count = self::get_user_notice_count( $user_id );

		if ( 0 === $count ) {
			return '<span class="ffc-empty-value">—</span>';
		}

		return sprintf(
			'<strong>%d</strong> %s',
			$count,
			_n( 'notice', 'notices', $count, 'ffcertificate' )
		);
	}

	/**
	 * Render user actions (login as user link)
	 *
	 * @param int $user_id User ID.
	 * @return string Column HTML
	 */
	private static function render_user_actions( int $user_id ): string {
		// Get dashboard URL from User Access Settings (cached per request).
		if ( null === self::$dashboard_url_cache ) {
			$user_access_settings      = get_option( 'ffc_user_access_settings', array() );
			self::$dashboard_url_cache = isset( $user_access_settings['redirect_url'] ) && ! empty( $user_access_settings['redirect_url'] )
				? $user_access_settings['redirect_url']
				: home_url( '/dashboard' );
		}
		$dashboard_url = self::$dashboard_url_cache;

		// Create view-as link with nonce.
		$view_as_url = add_query_arg(
			array(
				'ffc_view_as_user' => $user_id,
				'ffc_view_nonce'   => wp_create_nonce( 'ffc_view_as_user_' . $user_id ),
			),
			$dashboard_url
		);

		return sprintf(
			'<a href="%s" class="ffc-view-as-user button button-small" target="_blank" title="%s">%s</a>',
			esc_url( $view_as_url ),
			esc_attr__( 'View dashboard as this user', 'ffcertificate' ),
			__( 'Login as User', 'ffcertificate' )
		);
	}

	/**
	 * Get certificate count for user (batch-loaded)
	 *
	 * First call loads counts for ALL users in a single query,
	 * subsequent calls return from cache. Eliminates N+1 queries.
	 *
	 * @since 4.9.7 - Batch query replaces per-user COUNT
	 * @param int $user_id User ID.
	 * @return int Certificate count
	 */
	private static function get_user_certificate_count( int $user_id ): int {
		if ( null === self::$certificate_counts_cache ) {
			self::load_certificate_counts();
		}

		return self::$certificate_counts_cache[ $user_id ] ?? 0;
	}

	/**
	 * Get appointment count for user (batch-loaded)
	 *
	 * @since 4.9.7 - Batch query replaces per-user COUNT
	 * @param int $user_id User ID.
	 * @return int Appointment count
	 */
	private static function get_user_appointment_count( int $user_id ): int {
		if ( null === self::$appointment_counts_cache ) {
			self::load_appointment_counts();
		}

		return self::$appointment_counts_cache[ $user_id ] ?? 0;
	}

	/**
	 * Get distinct-notice count for user (batch-loaded).
	 *
	 * @since 6.2.0
	 * @param int $user_id User ID.
	 * @return int
	 */
	private static function get_user_notice_count( int $user_id ): int {
		if ( null === self::$recruitment_counts_cache ) {
			self::load_recruitment_notice_counts();
		}

		return self::$recruitment_counts_cache[ $user_id ] ?? 0;
	}

	/**
	 * Load certificate counts for all users in a single batch query
	 *
	 * @since 4.9.7
	 * @return void
	 */
	private static function load_certificate_counts(): void {
		global $wpdb;
		$table = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, COUNT(*) AS cnt FROM %i WHERE user_id IS NOT NULL AND status != 'trash' GROUP BY user_id",
				$table
			),
			ARRAY_A
		);

		self::$certificate_counts_cache = array();
		if ( $results ) {
			foreach ( $results as $row ) {
				self::$certificate_counts_cache[ (int) $row['user_id'] ] = (int) $row['cnt'];
			}
		}
	}

	/**
	 * Load appointment counts for all users in a single batch query
	 *
	 * @since 4.9.7
	 * @return void
	 */
	private static function load_appointment_counts(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'ffc_self_scheduling_appointments';

		// Check if table exists (cached per request).
		if ( null === self::$appointments_table_exists ) {
			self::$appointments_table_exists = self::table_exists( $table );
		}

		self::$appointment_counts_cache = array();
		if ( ! self::$appointments_table_exists ) {
			return;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, COUNT(*) AS cnt FROM %i WHERE user_id IS NOT NULL AND status != 'cancelled' GROUP BY user_id",
				$table
			),
			ARRAY_A
		);

		if ( $results ) {
			foreach ( $results as $row ) {
				self::$appointment_counts_cache[ (int) $row['user_id'] ] = (int) $row['cnt'];
			}
		}
	}

	/**
	 * Load recruitment-notice counts for all users in a single batch query.
	 *
	 * Counts distinct notices the user appears in as a candidate, joining
	 * `candidate.user_id` against the classifications table.
	 *
	 * @since 6.2.0
	 * @return void
	 */
	private static function load_recruitment_notice_counts(): void {
		global $wpdb;
		$candidates_table      = $wpdb->prefix . 'ffc_recruitment_candidate';
		$classifications_table = $wpdb->prefix . 'ffc_recruitment_classification';

		if ( null === self::$recruitment_table_exists ) {
			self::$recruitment_table_exists = self::table_exists( $classifications_table );
		}

		self::$recruitment_counts_cache = array();
		if ( ! self::$recruitment_table_exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.user_id AS user_id, COUNT(DISTINCT cl.notice_id) AS cnt
				FROM %i AS cl
				INNER JOIN %i AS c ON c.id = cl.candidate_id
				WHERE c.user_id IS NOT NULL
				GROUP BY c.user_id',
				$classifications_table,
				$candidates_table
			),
			ARRAY_A
		);

		if ( $results ) {
			foreach ( $results as $row ) {
				self::$recruitment_counts_cache[ (int) $row['user_id'] ] = (int) $row['cnt'];
			}
		}
	}

	/**
	 * Inject ORDER BY into WP_User_Query when one of our value columns is
	 * the active sort.
	 *
	 * For the count columns we can't use the default `meta_key` pattern (no
	 * usermeta involved), so we rewrite `query_orderby` to reference a
	 * left-joined derived table aggregated by user_id. The JOIN is added
	 * to `query_from` only when the relevant orderby is selected, keeping
	 * normal user-list queries unaffected.
	 *
	 * @since 6.2.0
	 * @param \WP_User_Query $query Query mid-build.
	 * @return void
	 */
	public static function apply_sort_to_user_query( \WP_User_Query $query ): void {
		$orderby = isset( $query->query_vars['orderby'] ) ? (string) $query->query_vars['orderby'] : '';
		if ( ! in_array( $orderby, array( 'ffc_certificates', 'ffc_appointments', 'ffc_notices' ), true ) ) {
			return;
		}

		global $wpdb;
		$order = isset( $query->query_vars['order'] ) && 'ASC' === strtoupper( (string) $query->query_vars['order'] )
			? 'ASC'
			: 'DESC';

		switch ( $orderby ) {
			case 'ffc_certificates':
				$table  = \FreeFormCertificate\Core\Utils::get_submissions_table();
				$alias  = 'ffc_cert_counts';
				$select = "(SELECT user_id, COUNT(*) AS cnt FROM {$table} WHERE user_id IS NOT NULL AND status != 'trash' GROUP BY user_id)";
				break;

			case 'ffc_appointments':
				$appts_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
				if ( ! self::table_exists( $appts_table ) ) {
					return;
				}
				$alias  = 'ffc_appt_counts';
				$select = "(SELECT user_id, COUNT(*) AS cnt FROM {$appts_table} WHERE user_id IS NOT NULL AND status != 'cancelled' GROUP BY user_id)";
				break;

			case 'ffc_notices':
			default:
				$candidates      = $wpdb->prefix . 'ffc_recruitment_candidate';
				$classifications = $wpdb->prefix . 'ffc_recruitment_classification';
				if ( ! self::table_exists( $classifications ) ) {
					return;
				}
				$alias  = 'ffc_notice_counts';
				$select = "(SELECT c.user_id AS user_id, COUNT(DISTINCT cl.notice_id) AS cnt FROM {$classifications} AS cl INNER JOIN {$candidates} AS c ON c.id = cl.candidate_id WHERE c.user_id IS NOT NULL GROUP BY c.user_id)";
				break;
		}

		$query->query_from .= " LEFT JOIN {$select} AS {$alias} ON {$alias}.user_id = {$wpdb->users}.ID";
		// COALESCE so users with zero rows sort as 0 rather than getting
		// dropped to the bottom by NULL ordering vagaries between MySQL
		// versions.
		$query->query_orderby = "ORDER BY COALESCE({$alias}.cnt, 0) {$order}, {$wpdb->users}.user_login ASC";
	}

	/**
	 * Enqueue CSS for certificates column
	 *
	 * @param string $hook Hook name.
	 */
	public static function enqueue_styles( string $hook ): void {
		// Only load on users.php page.
		if ( 'users.php' !== $hook ) {
			return;
		}

		$s = \FreeFormCertificate\Core\Utils::asset_suffix();
		wp_enqueue_style( 'ffc-admin', FFC_PLUGIN_URL . "assets/css/ffc-admin{$s}.css", array(), FFC_VERSION );
	}
}
