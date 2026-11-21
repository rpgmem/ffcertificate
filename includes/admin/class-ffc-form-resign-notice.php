<?php
/**
 * The form screen's notice and action for signing certificates again.
 *
 * @package FreeFormCertificate\Admin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\AdminNotice;
use FreeFormCertificate\Core\Button;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Submissions\CertificateSignatureRepository;
use FreeFormCertificate\Submissions\FormResign;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Re-sign with current data" on the form edit screen (#2696).
 */
final class FormResignNotice {

	/**
	 * The admin-post action.
	 */
	public const ACTION = 'ffc_resign_form_certificates';

	/**
	 * Register the notice and the handler.
	 *
	 * `admin_notices` because the notice belongs above the editor, where the
	 * save that caused it lands; `admin_post_` with a nonce link, the shape
	 * of WordPress's own trash links: the notice is printed through
	 * `wp_kses_post()`, which keeps a link and drops a form's inputs.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * The nonce action for one form.
	 *
	 * @param int $form_id Form id.
	 */
	public static function nonce_action( int $form_id ): string {
		return self::ACTION . '_' . $form_id;
	}

	/**
	 * Print the notice on a form's edit screen, when its signed certificates
	 * carry form fields it no longer has, or a walk is still under way.
	 *
	 * @return void
	 */
	public static function render(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'post' !== $screen->base || 'ffc_form' !== $screen->post_type ) {
			return;
		}
		$form_id = absint( RequestInput::get_get_string( 'post' ) );
		if ( $form_id <= 0 || ! current_user_can( 'edit_post', $form_id ) ) {
			return;
		}

		$result = RequestInput::get_get_string( 'ffc_resigned' );
		if ( '' !== $result ) {
			AdminNotice::render(
				'success',
				esc_html(
					sprintf(
						/* translators: %d: number of certificates signed again */
						_n( '%d certificate was signed again with the current data.', '%d certificates were signed again with the current data.', absint( $result ), 'ffcertificate' ),
						absint( $result )
					)
				)
			);
		}

		$in_progress = FormResign::in_progress( $form_id );
		if ( ! $in_progress && ! FormResign::is_stale( $form_id ) ) {
			return;
		}

		$count  = CertificateSignatureRepository::count_for_form( $form_id );
		$button = Button::html(
			array(
				'label'    => $in_progress ? __( 'Continue signing again', 'ffcertificate' ) : __( 'Re-sign with current data', 'ffcertificate' ),
				'category' => 'caution',
				'icon'     => 'sync',
				'href'     => self::action_url( $form_id ),
			)
		);

		$message = $in_progress
			? __( 'Signing this form\'s certificates again stopped before the end. Continue to sign the rest.', 'ffcertificate' )
			: sprintf(
				/* translators: %d: number of signed certificates of the form */
				_n(
					'The title, workload or event dates of this form changed after its %d signed certificate was signed, so its verification page says it changed after signing. Signing it again attests the data as it is now, and is recorded in the activity log.',
					'The title, workload or event dates of this form changed after its %d signed certificates were signed, so their verification pages say they changed after signing. Signing them again attests the data as it is now, and is recorded in the activity log.',
					$count,
					'ffcertificate'
				),
				$count
			);

		AdminNotice::render(
			'warning',
			'<p>' . esc_html( $message ) . '</p><p>' . $button . '</p>',
			array( 'paragraph_wrap' => false )
		);
	}

	/**
	 * The action's address for one form, nonce included.
	 *
	 * @param int $form_id Form id.
	 */
	public static function action_url( int $form_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::ACTION,
					'form_id' => $form_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( $form_id )
		);
	}

	/**
	 * Sign the next batch again and return to the form.
	 *
	 * Each request signs a bounded batch, then reloads the form screen; the
	 * notice offers to continue until the walk ends.
	 *
	 * @return void
	 */
	public static function handle(): void {
		$form_id = absint( RequestInput::get_get_string( 'form_id' ) );
		check_admin_referer( self::nonce_action( $form_id ) );
		if ( $form_id <= 0 || 'ffc_form' !== get_post_type( $form_id ) || ! current_user_can( 'edit_post', $form_id ) ) {
			wp_die( esc_html__( 'Access denied.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		$totals = FormResign::run_batch( $form_id );
		$args   = array(
			'post'   => $form_id,
			'action' => 'edit',
		);
		if ( $totals['done'] ) {
			$args['ffc_resigned'] = $totals['signed'];
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'post.php' ) ) );
		exit;
	}

	/**
	 * The line the QR-code row of the form editor adds while signed
	 * certificates exist: said before the save, so the operator knows what
	 * editing these fields does (#2696).
	 *
	 * @param int $form_id Form id.
	 * @return string Plain text, or '' when the form has no signed certificate.
	 */
	public static function editing_warning( int $form_id ): string {
		$count = $form_id > 0 ? CertificateSignatureRepository::count_for_form( $form_id ) : 0;
		if ( $count <= 0 ) {
			return '';
		}
		return sprintf(
			/* translators: %d: number of signed certificates of the form */
			_n(
				'%d certificate of this form is signed. Changing the title, workload or event dates makes it read as changed after signing, until it is signed again from the notice the save shows.',
				'%d certificates of this form are signed. Changing the title, workload or event dates makes them read as changed after signing, until they are signed again from the notice the save shows.',
				$count,
				'ffcertificate'
			),
			$count
		);
	}
}
