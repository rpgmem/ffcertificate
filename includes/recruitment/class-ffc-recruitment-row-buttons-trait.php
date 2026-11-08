<?php
/**
 * Recruitment row buttons
 *
 * The actions of a recruitment list row as family buttons in an Actions
 * column, always visible (#2486), shared by the tables that extend
 * {@see AbstractRecruitmentListTable} and the candidates table, which
 * extends WP_List_Table directly.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the Edit and Delete buttons of a list row.
 */
trait RecruitmentRowButtonsTrait {

	/**
	 * The Delete button of a row: a danger button behind the confirm modal
	 * of `ffc-recruitment-admin.js`, which follows its link on confirm.
	 *
	 * @param string $delete_url        Nonced delete URL.
	 * @param string $title             Modal heading.
	 * @param string $body              Modal intro.
	 * @param string $consequences_json JSON list of consequence bullets.
	 * @return string
	 */
	protected function delete_row_action_link( string $delete_url, string $title, string $body, string $consequences_json ): string {
		return \FreeFormCertificate\Core\Button::html(
			array(
				'label'    => __( 'Delete', 'ffcertificate' ),
				'category' => 'danger',
				'icon'     => 'delete',
				'href'     => $delete_url,
				'attrs'    => array(
					'data-ffc-confirm'              => true,
					'data-ffc-confirm-title'        => $title,
					'data-ffc-confirm-body'         => $body,
					'data-ffc-confirm-consequences' => $consequences_json,
					'data-ffc-confirm-cta'          => __( 'Delete', 'ffcertificate' ),
					'data-ffc-confirm-style'        => 'destructive',
				),
			)
		);
	}

	/**
	 * The Edit button of a row.
	 *
	 * @param string $edit_url Edit screen URL.
	 * @return string
	 */
	protected function edit_button( string $edit_url ): string {
		return \FreeFormCertificate\Core\Button::html(
			array(
				'label' => __( 'Edit', 'ffcertificate' ),
				'icon'  => 'edit',
				'href'  => $edit_url,
			)
		);
	}

	/**
	 * A row's actions as buttons, always visible (#2486), where WordPress's
	 * row actions were links shown on hover.
	 *
	 * @param array<array-key, string> $buttons Button markup, already escaped.
	 * @return string
	 */
	protected function row_buttons( array $buttons ): string {
		return '<div class="ffc-row-buttons">' . implode( '', $buttons ) . '</div>';
	}
}
