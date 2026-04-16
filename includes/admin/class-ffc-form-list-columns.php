<?php
declare(strict_types=1);

/**
 * FormListColumns
 *
 * Adds custom columns to the ffc_form post type listing screen:
 * - ID: Form post ID for quick reference
 * - Shortcode: Copy-ready shortcode snippet
 * - Submissions: Total submission count per form
 *
 * @since 5.1.0
 * @package FreeFormCertificate\Admin
 */

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FormListColumns {

	/**
	 * Batch-cached submission counts (form_id => count).
	 *
	 * @var array<int, int>|null
	 */
	private static ?array $submission_counts_cache = null;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'manage_ffc_form_posts_columns', array( __CLASS__, 'add_columns' ) );
		add_action( 'manage_ffc_form_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-ffc_form_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'admin_head-edit.php', array( __CLASS__, 'inline_styles' ) );
	}

	/**
	 * Add custom columns to the forms list table.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public static function add_columns( array $columns ): array {
		$new = array();

		// Insert ID and Shortcode right after the checkbox (cb) and before title.
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( $key === 'cb' ) {
				$new['ffc_form_id'] = __( 'ID', 'ffcertificate' );
			}

			if ( $key === 'title' ) {
				$new['ffc_shortcode']   = __( 'Shortcode', 'ffcertificate' );
				$new['ffc_submissions'] = __( 'Submissions', 'ffcertificate' );
			}
		}

		return $new;
	}

	/**
	 * Render content for custom columns.
	 *
	 * @param string $column_name Column key.
	 * @param int    $post_id     Current post ID.
	 */
	public static function render_column( string $column_name, int $post_id ): void {
		switch ( $column_name ) {
			case 'ffc_form_id':
				echo '<code>' . esc_html( (string) $post_id ) . '</code>';
				break;

			case 'ffc_shortcode':
				$shortcode = '[ffc_form id="' . $post_id . '"]';
				printf(
					'<span class="ffc-shortcode-cell">'
					. '<code class="ffc-shortcode-code">%s</code>'
					. '<button type="button" class="ffc-copy-shortcode" data-shortcode="%s" title="%s">'
					. '<span class="dashicons dashicons-clipboard"></span>'
					. '</button>'
					. '</span>',
					esc_html( $shortcode ),
					esc_attr( $shortcode ),
					esc_attr__( 'Copy shortcode', 'ffcertificate' )
				);
				break;

			case 'ffc_submissions':
				$count = self::get_submission_count( $post_id );

				if ( $count === 0 ) {
					echo '<span class="ffc-empty-value">&mdash;</span>';
				} else {
					$url = admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions&form_id=' . $post_id );
					printf(
						'<a href="%s"><strong>%s</strong></a>',
						esc_url( $url ),
						esc_html( number_format_i18n( $count ) )
					);
				}
				break;
		}
	}

	/**
	 * Make the ID column sortable.
	 *
	 * @param array<string, string> $columns Sortable columns.
	 * @return array<string, string>
	 */
	public static function sortable_columns( array $columns ): array {
		$columns['ffc_form_id'] = 'ID';
		return $columns;
	}

	/**
	 * Get submission count for a form (batch-loaded).
	 *
	 * First call loads counts for ALL forms in a single query;
	 * subsequent calls return from the static cache.
	 *
	 * @param int $form_id Form post ID.
	 * @return int
	 */
	private static function get_submission_count( int $form_id ): int {
		if ( self::$submission_counts_cache === null ) {
			self::load_submission_counts();
		}

		return self::$submission_counts_cache[ $form_id ] ?? 0;
	}

	/**
	 * Batch-load submission counts for all forms in a single query.
	 */
	private static function load_submission_counts(): void {
		global $wpdb;
		$table = \FreeFormCertificate\Core\Utils::get_submissions_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT form_id, COUNT(*) AS cnt FROM %i WHERE status != 'trash' GROUP BY form_id",
				$table
			),
			ARRAY_A
		);

		self::$submission_counts_cache = array();
		if ( $results ) {
			foreach ( $results as $row ) {
				self::$submission_counts_cache[ (int) $row['form_id'] ] = (int) $row['cnt'];
			}
		}
	}

	/**
	 * Print inline styles and copy-to-clipboard script for the forms list screen.
	 */
	public static function inline_styles(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== 'ffc_form' ) {
			return;
		}

		?>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			document.querySelectorAll('.ffc-copy-shortcode').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var shortcode = this.getAttribute('data-shortcode');
					if (navigator.clipboard) {
						navigator.clipboard.writeText(shortcode);
					} else {
						var t = document.createElement('textarea');
						t.value = shortcode;
						document.body.appendChild(t);
						t.select();
						document.execCommand('copy');
						document.body.removeChild(t);
					}
					this.classList.add('copied');
					var self = this;
					setTimeout(function() { self.classList.remove('copied'); }, 1500);
				});
			});
		});
		</script>
		<?php
	}
}
