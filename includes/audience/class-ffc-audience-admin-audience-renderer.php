<?php
/**
 * Audience Admin Audience Renderer
 *
 * Pure view layer for the audience management sub-page. Holds the
 * HTML-rendering methods extracted from AudienceAdminAudience (the
 * controller). Receives the menu slug and whatever locals each view needs
 * as explicit parameters so it never reaches back through controller state.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.12.0  Extracted from AudienceAdminAudience (#591 phase-3, Sprint E5c)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\LabelSorter;
use FreeFormCertificate\Reregistration\CustomFieldReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audience Admin Audience Renderer.
 *
 * @phpstan-import-type AudienceRow from AudienceReader
 * @phpstan-import-type CustomFieldRow from \FreeFormCertificate\Reregistration\CustomFieldReader
 */
final class AudienceAdminAudienceRenderer {

	/**
	 * Render audiences list
	 *
	 * The list top of the admin layout (#2498): the action beside the title,
	 * the labelled filters in a card and the count above the table.
	 *
	 * @param string $menu_slug The menu slug prefix.
	 * @return void
	 */
	public static function render_list( string $menu_slug ): void {
		$status = \FreeFormCertificate\Core\RequestInput::get_get_key( 'status' );
		$status = in_array( $status, array( 'active', 'inactive' ), true ) ? $status : '';
		$search = \FreeFormCertificate\Core\RequestInput::get_get_string( 's' );

		$audiences = AudienceReader::get_hierarchical();
		$matches   = 0;
		if ( '' !== $status || '' !== $search ) {
			$audiences = self::filter_tree( $audiences, $status, $search, $matches );
		} else {
			$matches = self::count_tree( $audiences );
		}
		$list_url = admin_url( 'admin.php?page=' . $menu_slug . '-audiences' );
		$add_url  = $list_url . '&action=new';

		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Audiences', 'ffcertificate' ); ?></h1>
		<?php
		if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' ) ) {
			\FreeFormCertificate\Admin\AdminUI::render_button(
				array(
					'label'    => __( 'New audience', 'ffcertificate' ),
					'category' => 'create',
					'icon'     => 'plus',
					'href'     => $add_url,
				)
			);
		}
		?>
		<hr class="wp-header-end">

		<?php \FreeFormCertificate\Core\AdminNotice::settings_errors( 'ffc_audience' ); ?>

		<form method="get" class="ffc-list">
			<input type="hidden" name="page" value="<?php echo esc_attr( $menu_slug . '-audiences' ); ?>" />
			<div class="ffc-list-filters">
				<div class="ffc-list-filters__field">
					<label class="ffc-list-filters__label" for="ffc-audience-status-filter"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></label>
					<select name="status" id="ffc-audience-status-filter">
						<option value="" <?php selected( $status, '' ); ?>><?php esc_html_e( 'All statuses', 'ffcertificate' ); ?></option>
						<option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'ffcertificate' ); ?></option>
						<option value="inactive" <?php selected( $status, 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'ffcertificate' ); ?></option>
					</select>
				</div>
				<div class="ffc-list-filters__field">
					<label class="ffc-list-filters__label" for="ffc-audience-search"><?php esc_html_e( 'Search', 'ffcertificate' ); ?></label>
					<input type="search" id="ffc-audience-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name', 'ffcertificate' ); ?>" />
				</div>
				<div class="ffc-list-filters__actions">
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_button(
						array(
							'label' => __( 'Filter', 'ffcertificate' ),
							'icon'  => 'filter',
							'type'  => 'submit',
						)
					);
					if ( '' !== $status || '' !== $search ) {
						\FreeFormCertificate\Admin\AdminUI::render_button(
							array(
								'label' => __( 'Clear', 'ffcertificate' ),
								'icon'  => 'eraser',
								'href'  => $list_url,
							)
						);
					}
					?>
				</div>
			</div>
		</form>

		<div class="tablenav top">
			<div class="tablenav-pages one-page">
				<span class="displaying-num">
					<?php
					/* translators: %s: number of audiences */
					echo esc_html( sprintf( _n( '%s audience', '%s audiences', $matches, 'ffcertificate' ), number_format_i18n( $matches ) ) );
					?>
				</span>
			</div>
			<br class="clear">
		</div>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" class="column-name"><?php esc_html_e( 'Name', 'ffcertificate' ); ?></th>
					<th scope="col" class="column-color"><?php esc_html_e( 'Color', 'ffcertificate' ); ?></th>
					<th scope="col" class="column-members"><?php esc_html_e( 'Members', 'ffcertificate' ); ?></th>
					<th scope="col" class="column-status"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
					<th scope="col" class="column-actions"><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $audiences ) ) : ?>
					<tr>
						<td colspan="5"><?php esc_html_e( 'No audiences found.', 'ffcertificate' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $audiences as $audience ) : ?>
						<?php self::render_row_recursive( $menu_slug, $audience, 0 ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<!-- Styles in ffc-audience-admin.css -->
		<?php
	}

	/**
	 * The audiences of a tree that match the filters, with the groups above
	 * each match kept so it still reads under its parent (#2498).
	 *
	 * @param array<int, \stdClass> $nodes   Audiences, each with optional `children`.
	 * @param string                $status  `active`, `inactive` or '' for any.
	 * @param string                $search  Part of the name, or '' for any.
	 * @param int                   $matches Incremented once per matching audience.
	 * @phpstan-param list<\stdClass> $nodes
	 * @phpstan-return list<AudienceRow>
	 * @return array<int, object>
	 */
	public static function filter_tree( array $nodes, string $status, string $search, int &$matches ): array {
		$kept = array();
		foreach ( $nodes as $node ) {
			$children = ! empty( $node->children ) ? self::filter_tree( $node->children, $status, $search, $matches ) : array();
			$is_match = ( '' === $status || $status === $node->status )
				&& ( '' === $search || false !== mb_stripos( (string) $node->name, $search ) );
			if ( $is_match ) {
				++$matches;
			}
			if ( $is_match || ! empty( $children ) ) {
				/**
				 * A copy of a row the reader returned, so it keeps its shape.
				 *
				 * @var AudienceRow $copy
				 */
				$copy           = clone $node;
				$copy->children = $children;
				$kept[]         = $copy;
			}
		}
		return $kept;
	}

	/**
	 * How many audiences a tree holds.
	 *
	 * @param array<int, \stdClass> $nodes Audiences, each with optional `children`.
	 * @phpstan-param list<\stdClass> $nodes
	 * @return int
	 */
	private static function count_tree( array $nodes ): int {
		$count = 0;
		foreach ( $nodes as $node ) {
			$count += 1 + ( ! empty( $node->children ) ? self::count_tree( $node->children ) : 0 );
		}
		return $count;
	}

	/**
	 * Render an audience row and its children recursively.
	 *
	 * @param string $menu_slug The menu slug prefix.
	 * @param object $audience  Audience object with optional children property.
	 * @param int    $level     Hierarchy depth (0 = root, 1 = child, 2+ = grandchild).
	 * @phpstan-param AudienceRow $audience
	 * @return void
	 */
	public static function render_row_recursive( string $menu_slug, object $audience, int $level ): void {
		$direct_count = AudienceReader::get_member_count( (int) $audience->id );
		$has_children = ! empty( $audience->children );
		$total_count  = $has_children ? AudienceReader::get_member_count( (int) $audience->id, true ) : $direct_count;

		$edit_url    = admin_url( 'admin.php?page=' . $menu_slug . '-audiences&action=edit&id=' . $audience->id );
		$members_url = admin_url( 'admin.php?page=' . $menu_slug . '-audiences&action=members&id=' . $audience->id );
		$is_active   = ( 'active' === $audience->status );
		// Edit / Delete render only for the caps
		// AudienceAdminAudience::handle_actions() checks; Members stays, since
		// the members screen opens on the menu's own `ffc_view_audiences` (#1769).
		$can_manage = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' );
		$can_delete = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_delete_audiences' );

		$indent_class = $level > 0 ? 'ffc-hierarchy-child ffc-hierarchy-level-' . $level : '';

		?>
		<tr class="<?php echo 0 === $level ? 'ffc-hierarchy-parent' : ''; ?>">
			<td class="column-name <?php echo esc_attr( $indent_class ); ?>">
				<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $audience->name ); ?></a></strong>
			</td>
			<td class="column-color">
				<span class="ffc-color-swatch" style="--ffc-color: <?php echo esc_attr( $audience->color ); ?>;"></span>
			</td>
			<td class="column-members">
				<?php echo esc_html( number_format_i18n( $direct_count ) ); ?>
				<?php if ( $has_children && $total_count > $direct_count ) : ?>
					<?php // The second number says what it is (#2498): the members of the groups below are counted too. ?>
					<span class="description ffc-member-total">
						<?php
						/* translators: %s: number of members, the groups below included */
						echo esc_html( sprintf( __( '%s with the groups below', 'ffcertificate' ), number_format_i18n( $total_count ) ) );
						?>
					</span>
				<?php endif; ?>
			</td>
			<td class="column-status">
				<?php
				// On/off is a switch that saves the moment it flips (#2498).
				if ( $can_manage ) {
					AudienceAdminCalendar::render_status_toggle( 'audience', (int) $audience->id, $is_active, (string) $audience->name, 'ffc-audience-row-status-' . $audience->id );
				}
				?>
				<span id="ffc-audience-row-status-<?php echo esc_attr( (string) $audience->id ); ?>" class="ffc-audience-status ffc-audience-status-<?php echo esc_attr( $audience->status ); ?>" data-ffc-status-on-class="ffc-audience-status-active" data-ffc-status-off-class="ffc-audience-status-inactive">
					<?php echo $is_active ? esc_html__( 'Active', 'ffcertificate' ) : esc_html__( 'Inactive', 'ffcertificate' ); ?>
				</span>
			</td>
			<td class="column-actions">
				<div class="ffc-row-buttons">
					<?php
					if ( $can_manage ) {
						\FreeFormCertificate\Admin\AdminUI::render_button(
							array(
								'label' => __( 'Edit', 'ffcertificate' ),
								'icon'  => 'edit',
								'href'  => $edit_url,
							)
						);
					}
					\FreeFormCertificate\Admin\AdminUI::render_button(
						array(
							'label' => __( 'Members', 'ffcertificate' ),
							'icon'  => 'users',
							'href'  => $members_url,
						)
					);
					if ( ! $is_active && $can_delete ) {
						if ( AudienceBookingReader::count_for_audience_tree( (int) $audience->id ) > 0 ) {
							echo '<span class="description">' . esc_html__( 'Has bookings, kept', 'ffcertificate' ) . '</span>';
						} else {
							\FreeFormCertificate\Admin\AdminUI::render_button(
								array(
									'label'    => __( 'Delete', 'ffcertificate' ),
									'category' => 'danger',
									'icon'     => 'delete',
									'href'     => wp_nonce_url( admin_url( 'admin.php?page=' . $menu_slug . '-audiences&action=delete&id=' . $audience->id ), 'delete_audience_' . $audience->id ),
									'attrs'    => array( 'data-confirm' => __( 'Are you sure you want to permanently delete this audience?', 'ffcertificate' ) ),
								)
							);
						}
					}
					?>
				</div>
			</td>
		</tr>
		<?php

		// Recursively render children.
		if ( $has_children ) {
			foreach ( $audience->children as $child ) {
				self::render_row_recursive( $menu_slug, $child, $level + 1 );
			}
		}
	}

	/**
	 * Render audience form
	 *
	 * The editor is three vertical tabs (#1761): the audience itself, its
	 * members (what `action=members` rendered as its own screen) and its
	 * reregistration fields. Each tab keeps its own form or AJAX save.
	 *
	 * @param string $menu_slug The menu slug prefix.
	 * @param int    $id        Audience ID (0 for new).
	 * @param string $open_tab  Tab to open when `?tab=` names none: `members`
	 *                          for the `action=members` URL.
	 * @return void
	 */
	public static function render_form( string $menu_slug, int $id, string $open_tab = 'audience' ): void {
		$audience   = null;
		$page_title = __( 'Add New Audience', 'ffcertificate' );

		// The members URL names a saved audience, as it did as its own screen.
		if ( $id > 0 || 'members' === $open_tab ) {
			$audience = AudienceReader::get_by_id( $id );
			if ( ! $audience ) {
				wp_die( esc_html__( 'Audience not found.', 'ffcertificate' ) );
			}
			$page_title = __( 'Edit Audience', 'ffcertificate' );
		}

		$possible_parents = AudienceReader::get_possible_parents( $id );
		$back_url         = admin_url( 'admin.php?page=' . $menu_slug . '-audiences' );
		// 3-state (#1777): the screen opens on `ffc_view_audiences`, the save on
		// `ffc_manage_audiences`. Without it the form renders read-only; the
		// fields section below keeps its own `ffc_manage_custom_fields` tier.
		$can_manage = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' );

		$tabs = array(
			array(
				'key'   => 'audience',
				'label' => __( 'Audience', 'ffcertificate' ),
				'icon'  => 'ffc-icon-users',
			),
		);
		if ( $id > 0 ) {
			$tabs[] = array(
				'key'   => 'members',
				/* translators: %d: number of members, as the audiences list counts them */
				'label' => sprintf( __( 'Members (%d)', 'ffcertificate' ), AudienceReader::get_member_count( $id ) ),
				'icon'  => 'ffc-icon-user-check',
			);
			if ( self::can_view_custom_fields() ) {
				$tabs[] = array(
					'key'   => 'fields',
					'label' => __( 'Reregistration fields', 'ffcertificate' ),
					'icon'  => 'ffc-icon-clipboard',
				);
			}
		}
		// A member added or removed lands back on the Members tab.
		if ( 'add_members' === \FreeFormCertificate\Core\RequestInput::get_post_string( 'ffc_action' )
			|| 'member_removed' === \FreeFormCertificate\Core\RequestInput::get_get_string( 'message' ) ) {
			$open_tab = 'members';
		}
		$active = AudienceEditorTabs::active( array_column( $tabs, 'key' ), $open_tab );

		?>
		<h1 class="wp-heading-inline"><?php echo esc_html( $page_title ); ?></h1>
		<?php
		// Back is a button beside the title on every editor (#2486).
		\FreeFormCertificate\Admin\AdminUI::render_button(
			array(
				'label' => __( 'Back to Audiences', 'ffcertificate' ),
				'icon'  => 'chevron-left',
				'href'  => $back_url,
			)
		);
		?>
		<hr class="wp-header-end">

		<?php if ( $audience && ! empty( $audience->parent_id ) ) : ?>
			<?php
			$ancestors = AudienceReader::get_ancestors( (int) $audience->id );
			if ( ! empty( $ancestors ) ) :
				?>
			<div class="ffc-breadcrumb">
				<?php
				foreach ( $ancestors as $ancestor ) :
					$ancestor_edit_url = admin_url( 'admin.php?page=' . $menu_slug . '-audiences&action=edit&id=' . $ancestor->id );
					?>
					<span class="ffc-color-dot" style="--ffc-color: <?php echo esc_attr( $ancestor->color ); ?>;"></span>
					<a href="<?php echo esc_url( $ancestor_edit_url ); ?>"><?php echo esc_html( $ancestor->name ); ?></a>
					<span class="ffc-breadcrumb-sep">&rsaquo;</span>
				<?php endforeach; ?>
				<span class="ffc-color-dot" style="--ffc-color: <?php echo esc_attr( $audience->color ); ?>;"></span>
				<strong><?php echo esc_html( $audience->name ); ?></strong>
			</div>
			<?php endif; ?>
		<?php endif; ?>

		<?php \FreeFormCertificate\Core\AdminNotice::settings_errors( 'ffc_audience' ); ?>
		<?php if ( ! $can_manage ) : ?>
			<p class="description"><em><?php esc_html_e( 'Read-only — you can view this page but do not have permission to change it.', 'ffcertificate' ); ?></em></p>
		<?php endif; ?>

		<?php // The editor grid with no side card: the side column stays empty, so the form sits where it sits on every other editor (#2486). ?>
		<div class="ffc-editor">
		<div class="ffc-editor__main">
		<?php AudienceEditorTabs::open( $tabs, $active ); ?>
		<?php AudienceEditorTabs::panel_open( $tabs[0], $active ); ?>
		<form method="post" action="" class="ffc-form">
			<?php wp_nonce_field( 'save_audience', 'ffc_audience_nonce' ); ?>
			<input type="hidden" name="audience_id" value="<?php echo esc_attr( (string) $id ); ?>">
			<input type="hidden" name="ffc_action" value="save_audience">

			<?php if ( ! $can_manage ) : ?>
				<?php // A disabled fieldset disables every control inside it, the autosave toggles included. ?>
				<fieldset disabled class="ffc-readonly-lock">
			<?php endif; ?>
			<table class="form-table" role="presentation"><tbody>
				<tr>
					<th scope="row">
						<label for="audience_name"><?php esc_html_e( 'Name', 'ffcertificate' ); ?> <span class="ffc-required" aria-hidden="true">*</span></label>
					</th>
					<td>
						<input type="text" name="audience_name" id="audience_name" class="regular-text"
								value="<?php echo esc_attr( $audience->name ?? '' ); ?>" required>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="audience_color"><?php esc_html_e( 'Color', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<input type="color" name="audience_color" id="audience_color"
								value="<?php echo esc_attr( $audience->color ?? '#3788d8' ); ?>">
						<p class="description"><?php esc_html_e( 'Color used for visual identification in calendars.', 'ffcertificate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="audience_parent"><?php esc_html_e( 'Parent Audience', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<select name="audience_parent" id="audience_parent">
							<option value=""><?php esc_html_e( 'None (top-level audience)', 'ffcertificate' ); ?></option>
							<?php foreach ( $possible_parents as $pp ) : ?>
								<option value="<?php echo esc_attr( (string) $pp->id ); ?>" <?php selected( $audience->parent_id ?? '', $pp->id ); ?>>
									<?php echo esc_html( str_repeat( '— ', (int) $pp->depth ) . $pp->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Select a parent to create a sub-group (up to 3 hierarchy levels).', 'ffcertificate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="audience_status"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<?php
						// Hidden sibling carries the "inactive" value when the
						// toggle is unchecked — POST keeps the same shape as
						// the old <select> (one of 'active' / 'inactive').
						?>
						<input type="hidden" name="audience_status" value="inactive">
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'audience_status',
								'id'      => 'audience_status',
								'value'   => 'active',
								'checked' => 'active' === (string) ( $audience->status ?? 'active' ),
								'label'   => __( 'Active', 'ffcertificate' ),
								'data'    => \FreeFormCertificate\Core\RecordAutosave::attrs( 'audience', $id, 'status' ),
							)
						);
						?>
					</td>
				</tr>
				<?php
				$is_child     = ! empty( $audience->parent_id );
				$is_self_join = ! empty( $audience->allow_self_join );
				// Null for a new audience, a child, and a parent with no children.
				$descendants_state = ( $id > 0 && ! $is_child ) ? AudienceReader::descendants_self_join_state( $id ) : null;
				?>
				<tr>
					<th scope="row">
						<?php // The four-option choice has no single input: the label points at its first option. ?>
						<label for="<?php echo esc_attr( $is_child || null === $descendants_state ? 'audience_self_join' : 'audience_self_join_scope-1' ); ?>"><?php esc_html_e( 'Allow Self-Join', 'ffcertificate' ); ?></label>
					</th>
					<td>
						<?php if ( $is_child ) : ?>
							<p class="description">
								<?php if ( $is_self_join ) : ?>
									<span class="ffc-aud-check">&check;</span>
									<?php esc_html_e( 'Inherited from parent audience. Users can join this group from their dashboard.', 'ffcertificate' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'This setting is controlled by the parent audience.', 'ffcertificate' ); ?>
								<?php endif; ?>
							</p>
							<input type="hidden" name="audience_self_join" value="<?php echo esc_attr( $is_self_join ? '1' : '0' ); ?>">
						<?php elseif ( null === $descendants_state ) : ?>
							<?php if ( 0 === $id ) : ?>
								<?php // A new group under an audience inherits its choice (#1901): the script swaps the switch for this line while a parent is picked. ?>
								<p class="description ffc-self-join-inherits" hidden><?php esc_html_e( 'Inherits from the audience above.', 'ffcertificate' ); ?></p>
							<?php endif; ?>
							<div class="ffc-self-join-own">
							<?php
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'    => 'audience_self_join',
									'id'      => 'audience_self_join',
									'checked' => $is_self_join,
									'label'   => __( 'Users can join/leave this group from their dashboard', 'ffcertificate' ),
									'data'    => \FreeFormCertificate\Core\RecordAutosave::attrs( 'audience', $id, 'allow_self_join' ),
								)
							);
							?>
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: how many self-join groups one user may belong to (Scheduling Settings, Audience Calendars tab) */
										__( 'A group created under this audience starts with self-join off. Once the audience has groups below it, this becomes one choice that covers them too. Users can join up to %d groups through self-join.', 'ffcertificate' ),
										\FreeFormCertificate\Audience\AudienceSelfJoinLimit::get()
									)
								);
								?>
							</p>
							</div>
						<?php else : ?>
							<?php
							// One choice for the audience and its children and grandchildren
							// (#1901), read back from their rows: none is selected while
							// the descendants disagree, and the notice says how.
							$scope = AudienceSelfJoinScope::from_flags( $is_self_join, $descendants_state );
							?>
							<div class="ffc-self-join-scope">
								<?php // Posted when no option is checked, so the save knows the choice was offered and left alone. ?>
								<input type="hidden" name="audience_self_join_scope" value="">
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_segmented(
									array(
										'name'         => 'audience_self_join_scope',
										'id'           => 'audience_self_join_scope',
										'value'        => $scope,
										'allow_none'   => true,
										'options'      => array(
											AudienceSelfJoinScope::OFF         => __( 'Off', 'ffcertificate' ),
											AudienceSelfJoinScope::SELF        => __( 'This audience only', 'ffcertificate' ),
											AudienceSelfJoinScope::ALL         => __( 'This audience + children and grandchildren', 'ffcertificate' ),
											AudienceSelfJoinScope::DESCENDANTS => __( 'Children and grandchildren only', 'ffcertificate' ),
										),
										'descriptions' => array(
											AudienceSelfJoinScope::OFF         => __( 'No one joins or leaves this audience or any group below it from the dashboard.', 'ffcertificate' ),
											AudienceSelfJoinScope::SELF        => __( 'Users can join/leave this group only. Its child and grandchild groups stay closed.', 'ffcertificate' ),
											AudienceSelfJoinScope::ALL         => __( 'Users can join/leave this group and every child and grandchild group.', 'ffcertificate' ),
											AudienceSelfJoinScope::DESCENDANTS => __( 'This group shows on the dashboard only as a heading over its children and grandchildren, which users can join/leave.', 'ffcertificate' ),
										),
										'legend'       => __( 'Allow Self-Join', 'ffcertificate' ),
										'data'         => \FreeFormCertificate\Core\RecordAutosave::attrs( 'audience', $id, 'self_join_scope' ),
									)
								);
								?>
								<?php if ( null === $scope ) : ?>
									<?php $descendants_count = AudienceReader::descendants_self_join_count( $id ); ?>
									<div class="notice notice-warning inline ffc-self-join-mixed"><p>
										<?php
										echo esc_html(
											sprintf(
												$is_self_join
													/* translators: 1: children and grandchildren that allow self-join, 2: all children and grandchildren */
													? __( 'This audience allows self-join; %1$d of %2$d children and grandchildren allow it. No option is selected and nothing changes until you pick one, which then applies to all of them.', 'ffcertificate' )
													/* translators: 1: children and grandchildren that allow self-join, 2: all children and grandchildren */
													: __( 'This audience does not allow self-join; %1$d of %2$d children and grandchildren allow it. No option is selected and nothing changes until you pick one, which then applies to all of them.', 'ffcertificate' ),
												$descendants_count['allowed'],
												$descendants_count['total']
											)
										);
										?>
									</p></div>
								<?php endif; ?>
							</div>
							<p class="description"><?php esc_html_e( 'A group created later under this audience starts with self-join on when every child and grandchild allows it, and off otherwise.', 'ffcertificate' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<?php
				// Who sees the tree in the Join groups panel, and whether a
				// member may leave its last group (#1918): set on the
				// top-level audience, read by every child and grandchild.
				$tree_access = $is_child
					? AudienceTreeRules::access( AudienceTreeRules::root_id( $id ) )
					: array(
						'members_only'       => ! empty( $audience->members_only ),
						'require_membership' => ! empty( $audience->members_only ) && ! empty( $audience->require_membership ),
					);
				?>
				<tr>
					<th scope="row">
						<?php if ( $is_child ) : ?>
							<?php esc_html_e( 'Join groups panel', 'ffcertificate' ); ?>
						<?php else : ?>
							<label for="audience_members_only"><?php esc_html_e( 'Join groups panel', 'ffcertificate' ); ?></label>
						<?php endif; ?>
					</th>
					<td>
						<?php if ( $is_child ) : ?>
							<p class="description">
								<?php
								if ( $tree_access['require_membership'] ) {
									esc_html_e( 'Inherited from the top-level audience: visible to members only, and members keep at least one group.', 'ffcertificate' );
								} elseif ( $tree_access['members_only'] ) {
									esc_html_e( 'Inherited from the top-level audience: visible to members only.', 'ffcertificate' );
								} else {
									esc_html_e( 'Inherited from the top-level audience: visible to every user.', 'ffcertificate' );
								}
								?>
							</p>
						<?php else : ?>
							<?php if ( 0 === $id ) : ?>
								<p class="description ffc-self-join-inherits" hidden><?php esc_html_e( 'Inherits from the audience above.', 'ffcertificate' ); ?></p>
							<?php endif; ?>
							<?php // The stack is a flex column, so `hidden` goes on a wrapper the sheet does not display. ?>
							<div class="ffc-self-join-own">
								<?php // Tells the save that the pair was offered, so an unchecked toggle means off. ?>
								<input type="hidden" name="audience_tree_access" value="1">
								<div class="ffc-control-toggle-stack">
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_toggle(
									array(
										'name'        => 'audience_members_only',
										'id'          => 'audience_members_only',
										'checked'     => $tree_access['members_only'],
										'label'       => __( 'Visible to members only', 'ffcertificate' ),
										'description' => __( 'This audience and its children and grandchildren show in the dashboard\'s Join groups panel only to users who already belong to one of its groups, however they were added. Calendars and bookings are not affected.', 'ffcertificate' ),
										'data'        => \FreeFormCertificate\Core\RecordAutosave::attrs( 'audience', $id, 'members_only' ),
									)
								);
								\FreeFormCertificate\Admin\AdminUI::render_toggle(
									array(
										'name'        => 'audience_require_membership',
										'id'          => 'audience_require_membership',
										'checked'     => $tree_access['require_membership'],
										'disabled'    => ! $tree_access['members_only'],
										'label'       => __( 'Keep at least one group', 'ffcertificate' ),
										'description' => __( 'A member cannot leave the last group they hold in this audience from the dashboard; they switch to another group instead. Needs "Visible to members only". Removing members here is not affected.', 'ffcertificate' ),
										'data'        => \FreeFormCertificate\Core\RecordAutosave::attrs( 'audience', $id, 'require_membership' ),
									)
								);
								?>
								</div>
							</div>
						<?php endif; ?>
					</td>
				</tr>
			</tbody></table>
			<?php if ( ! $can_manage ) : ?>
				</fieldset>
			<?php endif; ?>

			<?php if ( $can_manage ) : ?>
				<?php
				$save_label = $id > 0 ? __( 'Update Audience', 'ffcertificate' ) : __( 'Create Audience', 'ffcertificate' );
				\FreeFormCertificate\Admin\AdminUI::render_save_button(
					$save_label,
					array(
						'icon'  => $id > 0 ? 'save' : 'plus',
						'value' => $save_label,
					)
				);
				?>
			<?php endif; ?>
		</form>
		<?php AudienceEditorTabs::panel_close(); ?>

		<?php if ( $id > 0 ) : ?>
			<?php AudienceEditorTabs::panel_open( $tabs[1], $active ); ?>
			<?php self::render_members_tab( $menu_slug, $id, $can_manage ); ?>
			<?php AudienceEditorTabs::panel_close(); ?>
			<?php if ( isset( $tabs[2] ) ) : ?>
				<?php AudienceEditorTabs::panel_open( $tabs[2], $active ); ?>
				<?php self::render_custom_fields_section( $id ); ?>
				<?php AudienceEditorTabs::panel_close(); ?>
			<?php endif; ?>
		<?php endif; ?>
		<?php AudienceEditorTabs::close(); ?>
		</div>
		</div>
		<?php
	}

	/**
	 * Whether the current user sees the reregistration fields: with the view
	 * cap read-only, with the manage cap editable.
	 *
	 * @return bool
	 */
	private static function can_view_custom_fields(): bool {
		return \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_custom_fields' )
			|| \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_custom_fields' );
	}

	/**
	 * Render custom fields management section: the body of the Reregistration
	 * fields tab, whose panel carries the heading, and of the global list on
	 * Reregistration -> Custom Fields (#2502).
	 *
	 * An audience's tab shows what the audience asks, level by level (see
	 * {@see CustomFieldReader::resolve()}): its own fields and its changes
	 * are edited here; a field it inherits is one line with Customize and
	 * Hide.
	 *
	 * @param int $audience_id Audience ID; 0 renders the global list.
	 * @return void
	 */
	public static function render_custom_fields_section( int $audience_id ): void {
		// 3-state model: hidden without view; read-only with view-only; editable
		// with manage. The save/delete/replicate AJAX is gated server-side by
		// ffc_manage_custom_fields regardless of what the UI renders.
		if ( ! self::can_view_custom_fields() ) {
			return;
		}
		$can_edit_fields = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_custom_fields' );
		$is_global       = 0 === $audience_id;

		// The standard fields live in the global list, which every audience
		// inherits (#2502): make sure it exists before drawing either level.
		if ( class_exists( '\FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' ) ) {
			\FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder::seed_global();
		}

		// Custom fields for this audience, plus the ordered slug => translated
		// label map: A→Z by translated label with the display-only
		// `acknowledgment` type pinned last.
		$fields       = $is_global
			? \FreeFormCertificate\Reregistration\CustomFieldReader::get_by_audience( 0, false )
			: \FreeFormCertificate\Reregistration\CustomFieldReader::resolve( $audience_id );
		$field_types  = LabelSorter::sort(
			CustomFieldReader::field_type_labels(),
			static function ( string $label ): string {
				return $label;
			},
			array(),
			array( 'acknowledgment' )
		);
		$group_labels = class_exists( '\FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' )
			? \FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder::get_group_labels()
			: array();

		// "Replicate to children" is only meaningful when this audience has
		// descendants to push its option lists down to.
		$has_children = ! $is_global && ! empty( AudienceReader::get_children( $audience_id ) );

		// An older copy of a field shadows the inherited one in this tab, so
		// a field is listed once (#2502).
		$own_keys = array();
		foreach ( $fields as $field ) {
			if ( ! $is_global && (int) ( $field->definition_audience_id ?? -1 ) === $audience_id && 0 === (int) ( $field->override_id ?? 0 ) ) {
				$own_keys[ (string) $field->field_key ] = true;
			}
		}

		?>
		<p class="description">
			<?php
			if ( $is_global ) {
				esc_html_e( 'Every audience asks these fields, unless it changes or hides one. A change here reaches every audience that has not changed that field. Standard fields can be reordered, relabelled, regrouped and deactivated, but not deleted.', 'ffcertificate' );
			} else {
				esc_html_e( 'The fields this audience asks: the global ones, those of its parent audiences and its own. Customize changes an inherited field for this audience and its sub-audiences only; Hide stops asking it here. Use "Add Field" for a field only this audience asks.', 'ffcertificate' );
			}
			?>
		</p>

		<?php
		if ( ! $is_global ) {
			self::render_fields_summary( $fields, $audience_id, $own_keys );
		}
		?>

		<div id="ffc-custom-fields-container" data-audience-id="<?php echo esc_attr( (string) $audience_id ); ?>">
			<div id="ffc-custom-fields-list" class="ffc-custom-fields-sortable">
				<?php foreach ( $fields as $field ) : ?>
					<?php
					if ( $is_global ) {
						self::render_custom_field_row( $field, $field_types, $group_labels );
						continue;
					}
					$override_id = (int) ( $field->override_id ?? 0 );
					$here        = (int) ( $field->source_audience_id ?? -1 ) === $audience_id;
					$own         = (int) ( $field->definition_audience_id ?? -1 ) === $audience_id && 0 === $override_id;
					if ( $own ) {
						$inherited_key = false;
						foreach ( $fields as $other ) {
							if ( (string) $other->field_key === (string) $field->field_key && $other !== $field ) {
								$inherited_key = true;
								break;
							}
						}
						self::render_custom_field_row(
							$field,
							$field_types,
							$group_labels,
							array( 'badge' => $inherited_key ? 'copy' : 'own' )
						);
						continue;
					}
					if ( isset( $own_keys[ (string) $field->field_key ] ) ) {
						continue;
					}
					if ( $here && $override_id > 0 && empty( $field->is_hidden ) ) {
						$change = \FreeFormCertificate\Reregistration\CustomFieldReader::get_by_id( $override_id );
						if ( null !== $change ) {
							self::render_custom_field_row(
								$change,
								$field_types,
								$group_labels,
								array(
									'badge'    => 'changed',
									'override' => true,
								)
							);
							continue;
						}
					}
					self::render_inherited_field_row( $field, $audience_id, $field_types, $can_edit_fields );
					?>
				<?php endforeach; ?>
			</div>

			<?php if ( $can_edit_fields ) : ?>
			<p>
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label'    => __( 'Add Field', 'ffcertificate' ),
						'category' => 'create',
						'icon'     => 'plus',
						'id'       => 'ffc-add-custom-field',
					)
				);
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label'    => __( 'Save Fields', 'ffcertificate' ),
						'category' => 'primary',
						'icon'     => 'save',
						'id'       => 'ffc-save-custom-fields',
					)
				);
				if ( $has_children ) {
					// Caution, not danger (#1904): it rewrites the children's option
					// lists from this audience's; no field, member or record is deleted.
					\FreeFormCertificate\Admin\AdminUI::render_button(
						array(
							'label'    => __( 'Replicate lists to children', 'ffcertificate' ),
							'category' => 'caution',
							'icon'     => 'replicate-down',
							'id'       => 'ffc-replicate-field-options',
							'attrs'    => array( 'title' => __( 'Copy this audience\'s option lists (departments, unions, work schedules…) to all child and grandchild audiences. Overwrites their current lists.', 'ffcertificate' ) ),
						)
					);
				}
				?>
				<span id="ffc-custom-fields-status" class="ffc-status" role="status" aria-live="polite" hidden></span>
			</p>
			<?php else : ?>
			<p class="description"><em><?php esc_html_e( 'Read-only — you do not have permission to edit custom field definitions.', 'ffcertificate' ); ?></em></p>
			<?php endif; ?>
		</div>

		<!-- Template for new field row (used by JS) -->
		<script type="text/html" id="tmpl-ffc-custom-field-row">
			<div class="ffc-custom-field-row" data-field-id="new_{{data.index}}" data-field-source="custom">
				<div class="ffc-field-handle"><span class="ffc-icon-grip" aria-hidden="true"></span></div>
				<div class="ffc-field-content">
					<div class="ffc-field-main-row">
						<span class="ffc-field-source-badge ffc-field-source-custom"><?php esc_html_e( 'Custom', 'ffcertificate' ); ?></span>
						<input type="text" class="ffc-field-label regular-text" placeholder="<?php esc_attr_e( 'Field Label', 'ffcertificate' ); ?>" value="">
						<select class="ffc-field-type">
							<?php foreach ( $field_types as $type => $label ) : ?>
								<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<select class="ffc-field-group">
							<option value=""><?php esc_html_e( '(No group)', 'ffcertificate' ); ?></option>
							<?php foreach ( $group_labels as $gkey => $glabel ) : ?>
								<option value="<?php echo esc_attr( $gkey ); ?>"><?php echo esc_html( $glabel ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php
						// Toggle switches (.ffc-control-toggle). The .ffc-field-{required,active,sensitive}
						// classes stay on the inner <input> (input_class) because
						// ffc-custom-fields-admin.js serialises each row via those selectors.
						// Unique ids via the {{data.index}} mustache so cloned rows don't collide.
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'        => 'ffc_aud_field_required_{{data.index}}',
								'input_class' => 'ffc-field-required',
								'class'       => 'ffc-field-required-label',
								'label'       => __( 'Required', 'ffcertificate' ),
							)
						);
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'        => 'ffc_aud_field_active_{{data.index}}',
								'input_class' => 'ffc-field-active',
								'class'       => 'ffc-field-active-label',
								'label'       => __( 'Active', 'ffcertificate' ),
								'checked'     => true,
							)
						);
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'        => 'ffc_aud_field_sensitive_{{data.index}}',
								'input_class' => 'ffc-field-sensitive',
								'class'       => 'ffc-field-sensitive-label',
								'label'       => __( 'Sensitive', 'ffcertificate' ),
								'title'       => __( 'Encrypt this value at rest (AES-256).', 'ffcertificate' ),
							)
						);
						?>
					</div>
					<div class="ffc-field-details-row">
						<input type="text" class="ffc-field-key" placeholder="<?php esc_attr_e( 'field_key (auto)', 'ffcertificate' ); ?>" value="">
						<input type="text" class="ffc-field-profile-key" placeholder="<?php esc_attr_e( 'profile_key (optional)', 'ffcertificate' ); ?>" value="">
						<input type="text" class="ffc-field-mask" placeholder="<?php esc_attr_e( 'Mask (cpf, phone, cep…)', 'ffcertificate' ); ?>" value="">
						<div class="ffc-field-options-container" style="display:none;">
							<textarea class="ffc-field-choices" placeholder="<?php esc_attr_e( 'Options (one per line)', 'ffcertificate' ); ?>" rows="3"></textarea>
						</div>
						<div class="ffc-field-html-container" style="display:none;">
							<textarea class="ffc-field-html" placeholder="<?php esc_attr_e( 'Notice HTML (supports basic formatting and links)', 'ffcertificate' ); ?>" rows="6"></textarea>
						</div>
						<div class="ffc-field-validation-container">
							<select class="ffc-field-format">
								<option value=""><?php esc_html_e( 'No format validation', 'ffcertificate' ); ?></option>
								<option value="cpf"><?php esc_html_e( 'CPF', 'ffcertificate' ); ?></option>
								<option value="email"><?php esc_html_e( 'Email', 'ffcertificate' ); ?></option>
								<option value="phone"><?php esc_html_e( 'Phone', 'ffcertificate' ); ?></option>
								<option value="custom_regex"><?php esc_html_e( 'Custom Regex', 'ffcertificate' ); ?></option>
							</select>
							<input type="text" class="ffc-field-regex" placeholder="<?php esc_attr_e( 'Regex pattern', 'ffcertificate' ); ?>" style="display:none;">
							<input type="text" class="ffc-field-regex-msg" placeholder="<?php esc_attr_e( 'Error message for regex', 'ffcertificate' ); ?>" style="display:none;">
						</div>
						<input type="text" class="ffc-field-help" placeholder="<?php esc_attr_e( 'Help text (optional)', 'ffcertificate' ); ?>">
					</div>
				</div>
				<div class="ffc-field-actions">
					<?php self::render_field_actions( true ); ?>
				</div>
			</div>
		</script>
		<?php
	}

	/**
	 * Render a single custom field row in the editor.
	 *
	 * @param object                $field        Field object from database.
	 * @param array<string, string> $field_types  Ordered slug => translated label map.
	 * @param array<string, string> $group_labels Map of group_key => translated label.
	 * @param array<string, mixed>  $context      How an audience's tab draws the row (#2502):
	 *                                            `badge` is `own`, `copy` or `changed`;
	 *                                            `override` marks a change to an inherited
	 *                                            field, which keeps the field's key, type,
	 *                                            sensitivity and binding, follows its order
	 *                                            and goes back to the inherited field instead
	 *                                            of being deleted.
	 * @phpstan-param CustomFieldRow $field
	 * @return void
	 */
	public static function render_custom_field_row( object $field, array $field_types, array $group_labels = array(), array $context = array() ): void {
		$options = $field->field_options;
		if ( is_string( $options ) ) {
			$options = json_decode( $options, true );
		}
		$rules = $field->validation_rules;
		if ( is_string( $rules ) ) {
			$rules = json_decode( $rules, true );
		}

		$choices_text = '';
		if ( ! empty( $options['choices'] ) ) {
			$choices_text = implode( "\n", $options['choices'] );
		}
		$format       = $rules['format'] ?? '';
		$regex        = $rules['custom_regex'] ?? '';
		$regex_msg    = $rules['custom_regex_message'] ?? '';
		$help_text    = $options['help_text'] ?? '';
		$is_select    = ( 'select' === $field->field_type );
		$is_dependent = ( 'dependent_select' === $field->field_type );
		$ds_groups    = ( is_array( $options ) && isset( $options['groups'] ) && is_array( $options['groups'] ) ) ? $options['groups'] : array();
		$ds_target    = 'ffc_ds_map_' . (string) $field->id;
		$is_regex     = ( 'custom_regex' === $format );

		$source      = isset( $field->field_source ) && 'standard' === $field->field_source ? 'standard' : 'custom';
		$is_standard = ( 'standard' === $source );
		$is_override = ! empty( $context['override'] );
		$locked_attr = $is_standard ? ' disabled' : '';
		// A custom field anybody answered keeps its key, type, sensitivity and
		// profile binding, and cannot be deleted (#2242). The server enforces
		// it; the row draws it. A change to an inherited field never moves
		// those: they follow the field it changes (#2502).
		$answered     = ! $is_standard && ! $is_override && \FreeFormCertificate\Reregistration\CustomFieldReader::has_answers( $field );
		$answer_lock  = ( $is_standard || $answered || $is_override ) ? ' disabled' : '';
		$field_group  = (string) ( $field->field_group ?? '' );
		$profile_key  = (string) ( $field->field_profile_key ?? '' );
		$mask         = (string) ( $field->field_mask ?? '' );
		$is_sensitive = ! empty( $field->is_sensitive );

		$badge_label = $is_standard
			? __( 'Standard', 'ffcertificate' )
			: __( 'Custom', 'ffcertificate' );
		$badge_class = $is_standard ? 'ffc-field-source-standard' : 'ffc-field-source-custom';
		$badge_title = '';
		switch ( $context['badge'] ?? '' ) {
			case 'own':
				$badge_label = __( 'Only here', 'ffcertificate' );
				$badge_class = 'ffc-field-source-own';
				break;
			case 'copy':
				$badge_label = __( 'Own copy', 'ffcertificate' );
				$badge_class = 'ffc-field-source-copy';
				$badge_title = __( 'This audience keeps its own copy of a field it also inherits, with answers of its own. It is asked in place of the inherited one.', 'ffcertificate' );
				break;
			case 'changed':
				$badge_label = __( 'Changed here', 'ffcertificate' );
				$badge_class = 'ffc-field-source-changed';
				break;
		}

		// Ensure the field's current group appears in the select even if it.
		// is not one of the canonical seeder groups (custom field groups).
		if ( '' !== $field_group && ! isset( $group_labels[ $field_group ] ) ) {
			$group_labels[ $field_group ] = $field_group;
		}

		?>
		<div class="ffc-custom-field-row <?php echo empty( $field->is_active ) ? 'ffc-field-inactive' : ''; ?> ffc-field-source-<?php echo esc_attr( $source ); ?>" data-field-id="<?php echo esc_attr( (string) $field->id ); ?>" data-field-source="<?php echo esc_attr( $source ); ?>"<?php echo $is_override ? ' data-override="1"' : ''; ?>>
			<?php if ( $is_override ) : ?>
				<div class="ffc-field-handle-placeholder" aria-hidden="true"></div>
			<?php else : ?>
				<div class="ffc-field-handle"><span class="ffc-icon-grip" aria-hidden="true"></span></div>
			<?php endif; ?>
			<div class="ffc-field-content">
				<div class="ffc-field-main-row">
					<span class="ffc-field-source-badge <?php echo esc_attr( $badge_class ); ?>"<?php echo '' !== $badge_title ? ' title="' . esc_attr( $badge_title ) . '"' : ''; ?>><?php echo esc_html( $badge_label ); ?></span>
					<input type="text" class="ffc-field-label regular-text" placeholder="<?php esc_attr_e( 'Field Label', 'ffcertificate' ); ?>" value="<?php echo esc_attr( $field->field_label ); ?>">
					<select class="ffc-field-type"<?php echo esc_attr( $answer_lock ); ?>>
						<?php foreach ( $field_types as $type => $label ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $field->field_type, $type ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<select class="ffc-field-group">
						<option value=""><?php esc_html_e( '(No group)', 'ffcertificate' ); ?></option>
						<?php foreach ( $group_labels as $gkey => $glabel ) : ?>
							<option value="<?php echo esc_attr( $gkey ); ?>" <?php selected( $field_group, $gkey ); ?>><?php echo esc_html( $glabel ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'        => 'ffc_aud_field_required_' . (string) $field->id,
							'input_class' => 'ffc-field-required',
							'class'       => 'ffc-field-required-label',
							'label'       => __( 'Required', 'ffcertificate' ),
							'checked'     => ! empty( $field->is_required ),
						)
					);
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'        => 'ffc_aud_field_active_' . (string) $field->id,
							'input_class' => 'ffc-field-active',
							'class'       => 'ffc-field-active-label',
							'label'       => __( 'Active', 'ffcertificate' ),
							'checked'     => ! empty( $field->is_active ),
						)
					);
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'        => 'ffc_aud_field_sensitive_' . (string) $field->id,
							'input_class' => 'ffc-field-sensitive',
							'class'       => 'ffc-field-sensitive-label',
							'label'       => __( 'Sensitive', 'ffcertificate' ),
							'checked'     => $is_sensitive,
							'disabled'    => $is_standard || $answered || $is_override,
							'title'       => __( 'Encrypt this value at rest (AES-256).', 'ffcertificate' ),
						)
					);
					?>
				</div>
				<?php if ( $answered ) : ?>
					<p class="description ffc-field-answer-lock ffc-icon-lock"><?php esc_html_e( 'Already answered: the key, type, sensitivity and profile binding are locked, and the field cannot be deleted. Label, help text and options stay editable; deactivate it to stop asking.', 'ffcertificate' ); ?></p>
				<?php endif; ?>
				<?php if ( 'acknowledgment' === $field->field_type ) : ?>
					<div class="ffc-field-html-container">
						<p class="description"><?php esc_html_e( 'Notice shown at the end of the reregistration form and printed on the record PDF. Use "Replicate lists to children" to push it to descendant audiences.', 'ffcertificate' ); ?></p>
						<?php
						wp_editor(
							isset( $options['html'] ) ? (string) $options['html'] : '',
							'ffc_termo_' . (int) $field->id,
							array(
								'textarea_name' => 'ffc_termo_' . (int) $field->id,
								'textarea_rows' => 12,
								'media_buttons' => false,
								'teeny'         => true,
								'tinymce'       => array(
									'toolbar1' => 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
									'toolbar2' => '',
								),
								'quicktags'     => array( 'buttons' => 'strong,em,link,ul,ol,li,close' ),
							)
						);
						?>
					</div>
				<?php endif; ?>
				<div class="ffc-field-details-row" style="display:none;">
					<input type="text" class="ffc-field-key" placeholder="<?php esc_attr_e( 'field_key', 'ffcertificate' ); ?>" value="<?php echo esc_attr( $field->field_key ); ?>"<?php echo esc_attr( $answer_lock ); ?>>
					<input type="text" class="ffc-field-profile-key" placeholder="<?php esc_attr_e( 'profile_key (optional)', 'ffcertificate' ); ?>" value="<?php echo esc_attr( $profile_key ); ?>"<?php echo esc_attr( $answer_lock ); ?>>
					<input type="text" class="ffc-field-mask" placeholder="<?php esc_attr_e( 'Mask (cpf, phone, cep…)', 'ffcertificate' ); ?>" value="<?php echo esc_attr( $mask ); ?>"<?php echo esc_attr( $locked_attr ); ?>>
					<div class="ffc-field-options-container" <?php echo $is_select ? '' : 'style="display:none;"'; ?>>
						<textarea class="ffc-field-choices" placeholder="<?php esc_attr_e( 'Options (one per line)', 'ffcertificate' ); ?>" rows="3"><?php echo esc_textarea( $choices_text ); ?></textarea>
					</div>
					<div class="ffc-field-groups-container" <?php echo $is_dependent ? '' : 'style="display:none;"'; ?>>
						<input type="hidden" class="ffc-ds-map-json" id="<?php echo esc_attr( $ds_target ); ?>" value="<?php echo esc_attr( wp_json_encode( $ds_groups ) ? wp_json_encode( $ds_groups ) : '{}' ); ?>">
						<div class="ffc-ds-editor" data-target="<?php echo esc_attr( $ds_target ); ?>">
							<div class="ffc-ds-divisions">
								<?php foreach ( $ds_groups as $ds_division => $ds_sectors ) : ?>
									<div class="ffc-ds-division">
										<div class="ffc-ds-division-head">
											<input type="text" class="ffc-ds-division-name regular-text" value="<?php echo esc_attr( (string) $ds_division ); ?>" placeholder="<?php esc_attr_e( 'Division name', 'ffcertificate' ); ?>">
											<?php
											\FreeFormCertificate\Admin\AdminUI::render_button(
												array(
													'label' => __( 'Remove division', 'ffcertificate' ),
													'category' => 'danger',
													'icon' => 'delete',
													'class' => 'ffc-ds-division-remove',
												)
											);
											?>
										</div>
										<div class="ffc-ds-sectors">
											<?php foreach ( (array) $ds_sectors as $ds_sector ) : ?>
												<div class="ffc-ds-sector">
													<input type="text" class="ffc-ds-sector-name regular-text" value="<?php echo esc_attr( (string) $ds_sector ); ?>" placeholder="<?php esc_attr_e( 'Department name', 'ffcertificate' ); ?>">
													<?php
													\FreeFormCertificate\Core\Button::render(
														array(
															'label'     => __( 'Remove department', 'ffcertificate' ),
															'category'  => 'danger',
															'icon'      => 'cross',
															'icon_only' => true,
															'class'     => 'ffc-ds-sector-remove',
														)
													);
													?>
												</div>
											<?php endforeach; ?>
										</div>
										<?php
										\FreeFormCertificate\Admin\AdminUI::render_button(
											array(
												'label'    => __( 'Add Department', 'ffcertificate' ),
												'category' => 'create',
												'icon'     => 'plus',
												'class'    => 'ffc-ds-sector-add',
											)
										);
										?>
									</div>
								<?php endforeach; ?>
							</div>
							<p>
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_button(
									array(
										'label'    => __( 'Add Division', 'ffcertificate' ),
										'category' => 'create',
										'icon'     => 'plus',
										'class'    => 'ffc-ds-division-add',
									)
								);
								?>
							</p>
						</div>
					</div>
					<div class="ffc-field-validation-container">
						<select class="ffc-field-format"<?php echo esc_attr( $locked_attr ); ?>>
							<option value=""><?php esc_html_e( 'No format validation', 'ffcertificate' ); ?></option>
							<option value="cpf" <?php selected( $format, 'cpf' ); ?>><?php esc_html_e( 'CPF', 'ffcertificate' ); ?></option>
							<option value="email" <?php selected( $format, 'email' ); ?>><?php esc_html_e( 'Email', 'ffcertificate' ); ?></option>
							<option value="phone" <?php selected( $format, 'phone' ); ?>><?php esc_html_e( 'Phone', 'ffcertificate' ); ?></option>
							<option value="custom_regex" <?php selected( $format, 'custom_regex' ); ?>><?php esc_html_e( 'Custom Regex', 'ffcertificate' ); ?></option>
						</select>
						<input type="text" class="ffc-field-regex" placeholder="<?php esc_attr_e( 'Regex pattern', 'ffcertificate' ); ?>" value="<?php echo esc_attr( $regex ); ?>" <?php echo $is_regex ? '' : 'style="display:none;"'; ?><?php echo esc_attr( $locked_attr ); ?>>
						<input type="text" class="ffc-field-regex-msg" placeholder="<?php esc_attr_e( 'Error message for regex', 'ffcertificate' ); ?>" value="<?php echo esc_attr( $regex_msg ); ?>" <?php echo $is_regex ? '' : 'style="display:none;"'; ?><?php echo esc_attr( $locked_attr ); ?>>
					</div>
					<input type="text" class="ffc-field-help" placeholder="<?php esc_attr_e( 'Help text (optional)', 'ffcertificate' ); ?>" value="<?php echo esc_attr( $help_text ); ?>">
				</div>
			</div>
			<div class="ffc-field-actions">
				<?php
				if ( $is_override ) {
					self::render_inheritance_button( 'revert', (int) $field->id, __( 'Back to inherited', 'ffcertificate' ), 'caution', 'restore' );
					self::render_field_actions( false );
				} else {
					self::render_field_actions( ! $is_standard && ! $answered );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * One line for a field the audience inherits (#2502): its label, type,
	 * group and where it comes from, with Customize and Hide -- or Show again
	 * when the audience hid it.
	 *
	 * @param object                $field       Field as resolved for the audience.
	 * @param int                   $audience_id Audience whose tab this is.
	 * @param array<string, string> $field_types Slug => translated type label.
	 * @param bool                  $can_edit    Whether the actions are drawn.
	 * @phpstan-param CustomFieldRow $field
	 * @return void
	 */
	private static function render_inherited_field_row( object $field, int $audience_id, array $field_types, bool $can_edit ): void {
		$source_id   = (int) ( $field->source_audience_id ?? 0 );
		$hidden      = ! empty( $field->is_hidden );
		$hidden_here = $hidden && $source_id === $audience_id;
		if ( $hidden_here ) {
			$badge = __( 'Hidden here', 'ffcertificate' );
			$class = 'ffc-field-source-hidden';
		} elseif ( $hidden ) {
			/* translators: %s: name of the parent audience that hid the field */
			$badge = sprintf( __( 'Hidden in %s', 'ffcertificate' ), (string) ( $field->source_audience_name ?? '' ) );
			$class = 'ffc-field-source-hidden';
		} elseif ( 0 === $source_id ) {
			$badge = __( 'Global', 'ffcertificate' );
			$class = 'ffc-field-source-global';
		} else {
			/* translators: %s: name of the parent audience the field comes from */
			$badge = sprintf( __( 'From %s', 'ffcertificate' ), (string) ( $field->source_audience_name ?? '' ) );
			$class = 'ffc-field-source-parent';
		}
		$type = $field_types[ (string) $field->field_type ] ?? (string) $field->field_type;
		?>
		<div class="ffc-inherited-field-row<?php echo $hidden || empty( $field->is_active ) ? ' ffc-field-inactive' : ''; ?>" data-field-id="<?php echo esc_attr( (string) $field->id ); ?>">
			<span class="ffc-field-source-badge <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $badge ); ?></span>
			<span class="ffc-inherited-field-label"><?php echo esc_html( (string) $field->field_label ); ?></span>
			<span class="ffc-inherited-field-meta"><?php echo esc_html( $type ); ?><?php echo ! empty( $field->is_required ) ? ' · ' . esc_html__( 'Required', 'ffcertificate' ) : ''; ?></span>
			<?php if ( $can_edit ) : ?>
				<span class="ffc-field-actions">
					<?php
					if ( $hidden_here ) {
						self::render_inheritance_button( 'show', (int) $field->override_id, __( 'Show again', 'ffcertificate' ), 'neutral', 'eye' );
					} else {
						self::render_inheritance_button( 'customize', (int) $field->id, __( 'Customize', 'ffcertificate' ), 'neutral', 'edit' );
						if ( ! $hidden ) {
							self::render_inheritance_button( 'hide', (int) $field->id, __( 'Hide', 'ffcertificate' ), 'neutral', 'eye-off' );
						}
					}
					?>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A button that changes how the audience inherits a field (#2502).
	 * `ffc-custom-fields-admin.js` sends `data-ffc-inheritance` and
	 * `data-field-id` to `ffc_custom_field_inheritance`.
	 *
	 * @param string $operation customize, hide, show or revert.
	 * @param int    $field_id  The inherited field's id (customize, hide) or the change row's (show, revert).
	 * @param string $label     Button label.
	 * @param string $category  Button category.
	 * @param string $icon      Registry icon.
	 * @return void
	 */
	private static function render_inheritance_button( string $operation, int $field_id, string $label, string $category, string $icon ): void {
		\FreeFormCertificate\Admin\AdminUI::render_button(
			array(
				'label'    => $label,
				'category' => $category,
				'icon'     => $icon,
				'class'    => 'ffc-field-inheritance',
				'attrs'    => array(
					'data-ffc-inheritance' => $operation,
					'data-field-id'        => (string) $field_id,
				),
			)
		);
	}

	/**
	 * How many of the fields an audience asks come from where (#2502).
	 *
	 * @param array<int, object>  $fields      The audience's resolved fields, hidden included.
	 * @param int                 $audience_id Audience ID.
	 * @param array<string, bool> $own_keys    Keys the audience defines itself.
	 * @phpstan-param list<CustomFieldRow> $fields
	 * @return void
	 */
	private static function render_fields_summary( array $fields, int $audience_id, array $own_keys ): void {
		$inherited = array();
		foreach ( $fields as $field ) {
			if ( (int) ( $field->definition_audience_id ?? -1 ) !== $audience_id ) {
				$inherited[ (string) $field->field_key ] = true;
			}
		}
		$counts = array(
			'asked'   => 0,
			'global'  => 0,
			'parents' => 0,
			'changed' => 0,
			'hidden'  => 0,
			'own'     => 0,
			'copies'  => 0,
		);
		foreach ( $fields as $field ) {
			$own = (int) ( $field->definition_audience_id ?? -1 ) === $audience_id && 0 === (int) ( $field->override_id ?? 0 );
			if ( ! $own && isset( $own_keys[ (string) $field->field_key ] ) ) {
				continue;
			}
			if ( ! empty( $field->is_hidden ) ) {
				++$counts['hidden'];
				continue;
			}
			++$counts['asked'];
			if ( $own && isset( $inherited[ (string) $field->field_key ] ) ) {
				++$counts['copies'];
			} elseif ( $own ) {
				++$counts['own'];
			} elseif ( (int) ( $field->source_audience_id ?? -1 ) === $audience_id ) {
				++$counts['changed'];
			} elseif ( 0 === (int) ( $field->source_audience_id ?? 0 ) ) {
				++$counts['global'];
			} else {
				++$counts['parents'];
			}
		}
		$rows = array(
			'asked'   => __( 'Asks', 'ffcertificate' ),
			'global'  => __( 'From the global list', 'ffcertificate' ),
			'parents' => __( 'From parent audiences', 'ffcertificate' ),
			'changed' => __( 'Changed here', 'ffcertificate' ),
			'hidden'  => __( 'Hidden here', 'ffcertificate' ),
			'own'     => __( 'Only here', 'ffcertificate' ),
			'copies'  => __( 'Own copies', 'ffcertificate' ),
		);
		if ( 0 === $counts['copies'] ) {
			unset( $rows['copies'] );
		}
		?>
		<dl class="ffc-facts ffc-fields-summary">
			<?php foreach ( $rows as $key => $label ) : ?>
				<dt class="ffc-facts__label"><?php echo esc_html( $label ); ?></dt>
				<dd class="ffc-facts__value"><?php echo esc_html( number_format_i18n( $counts[ $key ] ) ); ?></dd>
			<?php endforeach; ?>
		</dl>
		<?php
	}

	/**
	 * The two icon-only buttons of a field row (#1904): open the details,
	 * and remove the field when it may be removed (a standard field may not).
	 * `.ffc-field-toggle-details` and `.ffc-field-delete` are the hooks
	 * `ffc-custom-fields-admin.js` binds.
	 *
	 * @param bool $removable Whether to draw the remove button.
	 * @return void
	 */
	private static function render_field_actions( bool $removable ): void {
		\FreeFormCertificate\Admin\AdminUI::render_button(
			array(
				'label'     => __( 'Toggle details', 'ffcertificate' ),
				'icon'      => 'settings',
				'icon_only' => true,
				'class'     => 'ffc-field-toggle-details',
			)
		);
		if ( $removable ) {
			\FreeFormCertificate\Admin\AdminUI::render_button(
				array(
					'label'     => __( 'Remove', 'ffcertificate' ),
					'category'  => 'danger',
					'icon'      => 'cross',
					'icon_only' => true,
					'class'     => 'ffc-field-delete',
				)
			);
		}
	}

	/**
	 * Render the Members tab: what the `action=members` screen rendered before
	 * it became a tab of the editor (#1761).
	 *
	 * @param string $menu_slug  The menu slug prefix.
	 * @param int    $id         Audience ID.
	 * @param bool   $can_manage Whether members can be added and removed: the
	 *                           screen opens on `ffc_view_audiences`, the writes
	 *                           on `ffc_manage_audiences` (#1777).
	 * @return void
	 */
	private static function render_members_tab( string $menu_slug, int $id, bool $can_manage ): void {
		$members = AudienceReader::get_members( $id );
		// The same count as the tab label and the audiences list, which leaves
		// out a row whose user is gone because the table below skips it (#1795).
		$member_count = AudienceReader::get_member_count( $id );

		?>
		<?php if ( $can_manage ) : ?>
		<div class="card">
			<h2 class="ffc-icon-plus"><?php esc_html_e( 'Add Members', 'ffcertificate' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'add_members', 'ffc_add_members_nonce' ); ?>
				<input type="hidden" name="audience_id" value="<?php echo esc_attr( (string) $id ); ?>">
				<input type="hidden" name="ffc_action" value="add_members">

				<p>
					<label for="user_search"><?php esc_html_e( 'Search users:', 'ffcertificate' ); ?></label>
					<input type="text" id="user_search" class="regular-text" placeholder="<?php esc_attr_e( 'Type to search...', 'ffcertificate' ); ?>">
				</p>
				<div id="user_results" class="ffc-user-results"></div>
				<input type="hidden" name="user_ids" id="selected_user_ids" value="">
				<div id="selected_users" class="ffc-selected-users"></div>
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label'    => __( 'Add Selected Members', 'ffcertificate' ),
						'category' => 'create',
						'icon'     => 'user-plus',
						'type'     => 'submit',
						'name'     => 'add_members',
						'value'    => __( 'Add Selected Members', 'ffcertificate' ),
					)
				);
				?>
			</form>
		</div>
		<?php endif; ?>

		<div class="card">
			<h2 class="ffc-icon-users"><?php esc_html_e( 'Current Members', 'ffcertificate' ); ?> (<?php echo (int) $member_count; ?>)</h2>

			<?php if ( 0 === $member_count ) : ?>
				<p><?php esc_html_e( 'No members yet.', 'ffcertificate' ); ?></p>
			<?php else : ?>
				<?php echo \FreeFormCertificate\Core\PiiReveal::page_button( AudienceAccountEmail::PII_CAP ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- page_button() escapes every value. ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'ffcertificate' ); ?></th>
							<th><?php esc_html_e( 'Email', 'ffcertificate' ); ?></th>
							<?php if ( $can_manage ) : ?>
							<th class="column-actions"><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $members as $user_id ) : ?>
							<?php $user = get_user_by( 'id', $user_id ); ?>
							<?php if ( $user ) : ?>
								<?php
								$remove_url = wp_nonce_url(
									admin_url( 'admin.php?page=' . $menu_slug . '-audiences&action=members&id=' . $id . '&remove_user=' . $user_id ),
									'remove_member_' . $user_id
								);
								?>
								<tr>
									<td><?php echo esc_html( $user->display_name ); ?></td>
									<td><?php echo AudienceAccountEmail::render( (int) $user_id, (string) $user->user_email ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes every value. ?></td>
									<?php if ( $can_manage ) : ?>
									<td class="column-actions">
										<a href="<?php echo esc_url( $remove_url ); ?>" class="ffc-delete-link" onclick="return confirm('<?php esc_attr_e( 'Remove this member?', 'ffcertificate' ); ?>');">
											<?php esc_html_e( 'Remove', 'ffcertificate' ); ?>
										</a>
									</td>
									<?php endif; ?>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<!-- Styles in ffc-audience-admin.css -->
		<!-- Scripts in ffc-audience-admin.js -->
		<?php
	}
}
