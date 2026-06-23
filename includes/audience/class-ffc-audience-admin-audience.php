<?php
/**
 * Audience Admin - Audience management sub-page
 *
 * @package FreeFormCertificate\Audience
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\Utils;
use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;

use FreeFormCertificate\Core\ColorValidator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles audience CRUD rendering and actions.
 *
 * @phpstan-import-type AudienceRow from AudienceRepository
 * @phpstan-import-type CustomFieldRow from \FreeFormCertificate\Reregistration\CustomFieldRepository
 */
class AudienceAdminAudience {

	/**
	 * Menu slug prefix.
	 *
	 * @var string
	 */
	private string $menu_slug;

	/**
	 * Constructor.
	 *
	 * @param string $menu_slug The menu slug prefix.
	 */
	public function __construct( string $menu_slug ) {
		$this->menu_slug = $menu_slug;
	}

	/**
	 * Render audiences page
	 *
	 * @return void
	 */
	public function render_page(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = RequestInput::get_get_string( 'action', 'list' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		?>
		<div class="wrap">
			<?php
			switch ( $action ) {
				case 'new':
				case 'edit':
					AudienceAdminAudienceRenderer::render_form( $this->menu_slug, $id );
					break;
				case 'members':
					AudienceAdminAudienceRenderer::render_members( $this->menu_slug, $id );
					break;
				default:
					AudienceAdminAudienceRenderer::render_list( $this->menu_slug );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Handle audience actions (save, delete, members)
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' ) ) {
			return;
		}

		// Show feedback for redirect-based actions.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['message'] ) && isset( $_GET['page'] ) && $_GET['page'] === $this->menu_slug . '-audiences' ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$msg      = RequestInput::get_get_string( 'message' );
			$messages = array(
				'created'        => __( 'Audience created successfully.', 'ffcertificate' ),
				'deactivated'    => __( 'Audience deactivated successfully.', 'ffcertificate' ),
				'deleted'        => __( 'Audience deleted successfully.', 'ffcertificate' ),
				'member_removed' => __( 'Member removed successfully.', 'ffcertificate' ),
			);
			if ( isset( $messages[ $msg ] ) ) {
				add_settings_error( 'ffc_audience', 'ffc_message', $messages[ $msg ], 'success' );
			}
		}

		// Handle save.
		if ( isset( $_POST['ffc_action'] ) && 'save_audience' === $_POST['ffc_action'] ) {
			if ( ! wp_verify_nonce( RequestInput::get_post_string( 'ffc_audience_nonce' ), 'save_audience' ) ) {
				return;
			}

			$id   = isset( $_POST['audience_id'] ) ? absint( $_POST['audience_id'] ) : 0;
			$data = array(
				'name'            => RequestInput::get_post_string( 'audience_name' ),
				'color'           => ColorValidator::normalize( isset( $_POST['audience_color'] ) ? wp_unslash( $_POST['audience_color'] ) : '', '#3788d8' ),
				'parent_id'       => isset( $_POST['audience_parent'] ) && '' !== $_POST['audience_parent'] ? absint( $_POST['audience_parent'] ) : null,
				'status'          => RequestInput::get_post_string( 'audience_status', 'active' ),
				'allow_self_join' => ! empty( $_POST['audience_self_join'] ) ? 1 : 0,
			);

			if ( $id > 0 ) {
				AudienceRepository::update( $id, $data );

				// Cascade allow_self_join to children if this is a parent.
				if ( empty( $data['parent_id'] ) ) {
					AudienceRepository::cascade_self_join( $id, (int) $data['allow_self_join'] );
				}

				add_settings_error( 'ffc_audience', 'ffc_message', __( 'Audience updated successfully.', 'ffcertificate' ), 'success' );
			} else {
				$new_id = AudienceRepository::create( $data );
				if ( $new_id ) {
					// Cascade to children (if creating a parent from template/import).
					if ( empty( $data['parent_id'] ) ) {
						AudienceRepository::cascade_self_join( $new_id, (int) $data['allow_self_join'] );
					}
					wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-audiences&action=edit&id=' . $new_id . '&message=created' ) );
					exit;
				}
			}
		}

		// Handle add members.
		if ( isset( $_POST['ffc_action'] ) && 'add_members' === $_POST['ffc_action'] ) {
			if ( ! wp_verify_nonce( RequestInput::get_post_string( 'ffc_add_members_nonce' ), 'add_members' ) ) {
				return;
			}

			$audience_id     = isset( $_POST['audience_id'] ) ? absint( $_POST['audience_id'] ) : 0;
			$user_ids_string = RequestInput::get_post_string( 'user_ids' );

			if ( $audience_id > 0 && ! empty( $user_ids_string ) ) {
				$user_ids = array_map( 'absint', explode( ',', $user_ids_string ) );
				$added    = AudienceRepository::bulk_add_members( $audience_id, $user_ids );
				/* translators: %d: number of members added */
				add_settings_error( 'ffc_audience', 'ffc_message', sprintf( __( '%d member(s) added successfully.', 'ffcertificate' ), $added ), 'success' );
			}
		}

		// Handle remove member.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['remove_user'] ) && isset( $_GET['id'] ) ) {
			$user_id     = absint( $_GET['remove_user'] );
			$audience_id = absint( $_GET['id'] );
			if ( wp_verify_nonce( RequestInput::get_get_string( '_wpnonce' ), 'remove_member_' . $user_id ) ) {
				AudienceRepository::remove_member( $audience_id, $user_id );
				wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-audiences&action=members&id=' . $audience_id . '&message=member_removed' ) );
				exit;
			}
		}

		// Handle deactivate (active items get deactivated instead of deleted).
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['action'] ) && 'deactivate' === $_GET['action'] && isset( $_GET['id'] ) && isset( $_GET['page'] ) && $_GET['page'] === $this->menu_slug . '-audiences' ) {
			$id = absint( $_GET['id'] );
			if ( wp_verify_nonce( RequestInput::get_get_string( '_wpnonce' ), 'deactivate_audience_' . $id ) ) {
				AudienceRepository::update( $id, array( 'status' => 'inactive' ) );
				wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-audiences&message=deactivated' ) );
				exit;
			}
		}

		// Handle delete (only inactive items can be permanently deleted).
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['id'] ) && isset( $_GET['page'] ) && $_GET['page'] === $this->menu_slug . '-audiences' ) {
			if ( ! Capabilities::current_user_can_admin_or( 'ffc_delete_audiences' ) ) {
				wp_die( esc_html__( 'You do not have permission to delete audiences.', 'ffcertificate' ) );
			}
			$id = absint( $_GET['id'] );
			if ( wp_verify_nonce( RequestInput::get_get_string( '_wpnonce' ), 'delete_audience_' . $id ) ) {
				$aud = AudienceRepository::get_by_id( $id );
				if ( $aud && 'active' !== $aud->status ) {
					AudienceRepository::delete( $id );
					wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-audiences&message=deleted' ) );
					exit;
				}
			}
		}
	}
}
