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
 * @since 6.7.x  Extracted from AudienceAdminAudience (#591 phase-3, Sprint E5c)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

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
	 * @param string $menu_slug The menu slug prefix.
	 * @return void
	 */
	public static function render_list( string $menu_slug ): void {
		$audiences = AudienceReader::get_hierarchical();
		$add_url   = admin_url( 'admin.php?page=' . $menu_slug . '-audiences&action=new' );

		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Audiences', 'ffcertificate' ); ?></h1>
		<?php if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' ) ) : ?>
		<a href="<?php echo esc_url( $add_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'ffcertificate' ); ?></a>
		<?php endif; ?>
		<hr class="wp-header-end">

		<?php settings_errors( 'ffc_audience' ); ?>

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

		if ( $is_active ) {
			$deactivate_url = wp_nonce_url(
				admin_url( 'admin.php?page=' . $menu_slug . '-audiences&action=deactivate&id=' . $audience->id ),
				'deactivate_audience_' . $audience->id
			);
		} else {
			$delete_url = wp_nonce_url(
				admin_url( 'admin.php?page=' . $menu_slug . '-audiences&action=delete&id=' . $audience->id ),
				'delete_audience_' . $audience->id
			);
		}

		$indent_class = $level > 0 ? 'ffc-hierarchy-child ffc-hierarchy-level-' . $level : '';

		?>
		<tr class="<?php echo 0 === $level ? 'ffc-hierarchy-parent' : ''; ?>">
			<td class="column-name <?php echo esc_attr( $indent_class ); ?>">
				<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $audience->name ); ?></a></strong>
			</td>
			<td class="column-color">
				<span class="ffc-color-swatch" style="background-color: <?php echo esc_attr( $audience->color ); ?>;"></span>
			</td>
			<td class="column-members">
				<a href="<?php echo esc_url( $members_url ); ?>">
					<?php echo esc_html( (string) $direct_count ); ?>
					<?php if ( $has_children && $total_count > $direct_count ) : ?>
						<span class="ffc-member-total" title="<?php esc_attr_e( 'Including children', 'ffcertificate' ); ?>">(<?php echo esc_html( (string) $total_count ); ?>)</span>
					<?php endif; ?>
				</a>
			</td>
			<td class="column-status">
				<span class="ffc-status-badge ffc-status-<?php echo esc_attr( $audience->status ); ?>">
					<?php echo $is_active ? esc_html__( 'Active', 'ffcertificate' ) : esc_html__( 'Inactive', 'ffcertificate' ); ?>
				</span>
			</td>
			<td class="column-actions">
				<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'ffcertificate' ); ?></a> |
				<a href="<?php echo esc_url( $members_url ); ?>"><?php esc_html_e( 'Members', 'ffcertificate' ); ?></a> |
				<?php if ( $is_active ) : ?>
					<a href="<?php echo esc_url( $deactivate_url ); ?>" class="delete-link" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to deactivate this audience?', 'ffcertificate' ); ?>');">
						<?php esc_html_e( 'Deactivate', 'ffcertificate' ); ?>
					</a>
				<?php else : ?>
					<a href="<?php echo esc_url( $delete_url ); ?>" class="delete-link" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to permanently delete this audience?', 'ffcertificate' ); ?>');">
						<?php esc_html_e( 'Delete', 'ffcertificate' ); ?>
					</a>
				<?php endif; ?>
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
	 * @param string $menu_slug The menu slug prefix.
	 * @param int    $id        Audience ID (0 for new).
	 * @return void
	 */
	public static function render_form( string $menu_slug, int $id ): void {
		$audience   = null;
		$page_title = __( 'Add New Audience', 'ffcertificate' );

		if ( $id > 0 ) {
			$audience = AudienceReader::get_by_id( $id );
			if ( ! $audience ) {
				wp_die( esc_html__( 'Audience not found.', 'ffcertificate' ) );
			}
			$page_title = __( 'Edit Audience', 'ffcertificate' );
		}

		$possible_parents = AudienceReader::get_possible_parents( $id );
		$back_url         = admin_url( 'admin.php?page=' . $menu_slug . '-audiences' );

		?>
		<h1><?php echo esc_html( $page_title ); ?></h1>
		<a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Audiences', 'ffcertificate' ); ?></a>

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
					<span class="ffc-color-swatch" style="background-color: <?php echo esc_attr( $ancestor->color ); ?>; width:12px; height:12px; display:inline-block; border-radius:50%; vertical-align:middle;"></span>
					<a href="<?php echo esc_url( $ancestor_edit_url ); ?>"><?php echo esc_html( $ancestor->name ); ?></a>
					<span class="ffc-breadcrumb-sep">&rsaquo;</span>
				<?php endforeach; ?>
				<span class="ffc-color-swatch" style="background-color: <?php echo esc_attr( $audience->color ); ?>; width:12px; height:12px; display:inline-block; border-radius:50%; vertical-align:middle;"></span>
				<strong><?php echo esc_html( $audience->name ); ?></strong>
			</div>
			<?php endif; ?>
		<?php endif; ?>

		<?php settings_errors( 'ffc_audience' ); ?>

		<form method="post" action="" class="ffc-form">
			<?php wp_nonce_field( 'save_audience', 'ffc_audience_nonce' ); ?>
			<input type="hidden" name="audience_id" value="<?php echo esc_attr( (string) $id ); ?>">
			<input type="hidden" name="ffc_action" value="save_audience">

			<table class="form-table" role="presentation"><tbody>
				<tr>
					<th scope="row">
						<label for="audience_name"><?php esc_html_e( 'Name', 'ffcertificate' ); ?> <span class="required">*</span></label>
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
							)
						);
						?>
					</td>
				</tr>
				<?php
				$is_child     = ! empty( $audience->parent_id );
				$is_self_join = ! empty( $audience->allow_self_join );
				?>
				<tr>
					<th scope="row">
						<label for="audience_self_join"><?php esc_html_e( 'Allow Self-Join', 'ffcertificate' ); ?></label>
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
						<?php else : ?>
							<?php
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'    => 'audience_self_join',
									'id'      => 'audience_self_join',
									'checked' => $is_self_join,
									'label'   => __( 'Users can join/leave child groups from their dashboard', 'ffcertificate' ),
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'When enabled, all descendant audiences inherit this setting. Users can join up to 2 child groups.', 'ffcertificate' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</tbody></table>

			<?php submit_button( $id > 0 ? __( 'Update Audience', 'ffcertificate' ) : __( 'Create Audience', 'ffcertificate' ) ); ?>
		</form>

		<?php if ( $id > 0 ) : ?>
			<?php self::render_custom_fields_section( $id ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render custom fields management section
	 *
	 * @param int $audience_id Audience ID.
	 * @return void
	 */
	public static function render_custom_fields_section( int $audience_id ): void {
		// 3-state model: hidden without view; read-only with view-only; editable
		// with manage. The save/delete/replicate AJAX is gated server-side by
		// ffc_manage_custom_fields regardless of what the UI renders.
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_custom_fields' )
			&& ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_custom_fields' ) ) {
			return;
		}
		$can_edit_fields = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_custom_fields' );

		// Ensure standard fields are seeded for this audience before rendering.
		if ( class_exists( '\FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' ) ) {
			\FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder::seed_for_audience( $audience_id );
		}

		$fields       = \FreeFormCertificate\Reregistration\CustomFieldReader::get_by_audience( $audience_id, false );
		$field_types  = \FreeFormCertificate\Reregistration\CustomFieldReader::FIELD_TYPES;
		$group_labels = class_exists( '\FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' )
			? \FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder::get_group_labels()
			: array();

		// "Replicate to children" is only meaningful when this audience has
		// descendants to push its option lists down to.
		$has_children = ! empty( AudienceReader::get_children( $audience_id ) );

		?>
		<hr>
		<h2><?php esc_html_e( 'Reregistration Fields', 'ffcertificate' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Define all fields shown during reregistration. Standard fields can be reordered, relabelled, regrouped and deactivated, but not deleted. Use "+ Add Field" to create custom fields.', 'ffcertificate' ); ?></p>

		<div id="ffc-custom-fields-container" data-audience-id="<?php echo esc_attr( (string) $audience_id ); ?>">
			<div id="ffc-custom-fields-list" class="ffc-custom-fields-sortable">
				<?php if ( ! empty( $fields ) ) : ?>
					<?php foreach ( $fields as $field ) : ?>
						<?php self::render_custom_field_row( $field, $field_types, $group_labels ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<?php if ( $can_edit_fields ) : ?>
			<p>
				<button type="button" id="ffc-add-custom-field" class="button">
					<?php esc_html_e( '+ Add Field', 'ffcertificate' ); ?>
				</button>
				<button type="button" id="ffc-save-custom-fields" class="button button-primary">
					<?php esc_html_e( 'Save Fields', 'ffcertificate' ); ?>
				</button>
				<?php if ( $has_children ) : ?>
					<button type="button" id="ffc-replicate-field-options" class="button"
							title="<?php esc_attr_e( 'Copy this audience\'s option lists (departments, unions, work schedules…) to all child and grandchild audiences. Overwrites their current lists.', 'ffcertificate' ); ?>">
						<?php esc_html_e( 'Replicate lists to children', 'ffcertificate' ); ?>
					</button>
				<?php endif; ?>
				<span id="ffc-custom-fields-status" class="ffc-save-status"></span>
			</p>
			<?php else : ?>
			<p class="description"><em><?php esc_html_e( 'Read-only — you do not have permission to edit custom field definitions.', 'ffcertificate' ); ?></em></p>
			<?php endif; ?>
		</div>

		<!-- Template for new field row (used by JS) -->
		<script type="text/html" id="tmpl-ffc-custom-field-row">
			<div class="ffc-custom-field-row" data-field-id="new_{{data.index}}" data-field-source="custom">
				<div class="ffc-field-handle"><span class="dashicons dashicons-menu"></span></div>
				<div class="ffc-field-content">
					<div class="ffc-field-main-row">
						<span class="ffc-field-source-badge ffc-field-source-custom"><?php esc_html_e( 'Custom', 'ffcertificate' ); ?></span>
						<input type="text" class="ffc-field-label regular-text" placeholder="<?php esc_attr_e( 'Field Label', 'ffcertificate' ); ?>" value="">
						<select class="ffc-field-type">
							<?php foreach ( $field_types as $type ) : ?>
								<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( ucfirst( $type ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<select class="ffc-field-group">
							<option value=""><?php esc_html_e( '(No group)', 'ffcertificate' ); ?></option>
							<?php foreach ( $group_labels as $gkey => $glabel ) : ?>
								<option value="<?php echo esc_attr( $gkey ); ?>"><?php echo esc_html( $glabel ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php
						// Toggle switches (.ffc-toggle). The .ffc-field-{required,active,sensitive}
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
					<button type="button" class="button button-small ffc-field-toggle-details" title="<?php esc_attr_e( 'Toggle details', 'ffcertificate' ); ?>">
						<span class="dashicons dashicons-admin-generic"></span>
					</button>
					<button type="button" class="button button-small button-link-delete ffc-field-delete" title="<?php esc_attr_e( 'Remove', 'ffcertificate' ); ?>">
						<span class="dashicons dashicons-trash"></span>
					</button>
				</div>
			</div>
		</script>
		<?php
	}

	/**
	 * Render a single custom field row in the editor.
	 *
	 * @param object                $field        Field object from database.
	 * @param array<int, string>    $field_types  Available field types.
	 * @param array<string, string> $group_labels Map of group_key => translated label.
	 * @phpstan-param CustomFieldRow $field
	 * @return void
	 */
	public static function render_custom_field_row( object $field, array $field_types, array $group_labels = array() ): void {
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

		$source       = isset( $field->field_source ) && 'standard' === $field->field_source ? 'standard' : 'custom';
		$is_standard  = ( 'standard' === $source );
		$locked_attr  = $is_standard ? ' disabled' : '';
		$field_group  = (string) ( $field->field_group ?? '' );
		$profile_key  = (string) ( $field->field_profile_key ?? '' );
		$mask         = (string) ( $field->field_mask ?? '' );
		$is_sensitive = ! empty( $field->is_sensitive );

		$badge_label = $is_standard
			? __( 'Standard', 'ffcertificate' )
			: __( 'Custom', 'ffcertificate' );
		$badge_class = $is_standard ? 'ffc-field-source-standard' : 'ffc-field-source-custom';

		// Ensure the field's current group appears in the select even if it.
		// is not one of the canonical seeder groups (custom field groups).
		if ( '' !== $field_group && ! isset( $group_labels[ $field_group ] ) ) {
			$group_labels[ $field_group ] = $field_group;
		}

		?>
		<div class="ffc-custom-field-row <?php echo empty( $field->is_active ) ? 'ffc-field-inactive' : ''; ?> ffc-field-source-<?php echo esc_attr( $source ); ?>" data-field-id="<?php echo esc_attr( (string) $field->id ); ?>" data-field-source="<?php echo esc_attr( $source ); ?>">
			<div class="ffc-field-handle"><span class="dashicons dashicons-menu"></span></div>
			<div class="ffc-field-content">
				<div class="ffc-field-main-row">
					<span class="ffc-field-source-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></span>
					<input type="text" class="ffc-field-label regular-text" placeholder="<?php esc_attr_e( 'Field Label', 'ffcertificate' ); ?>" value="<?php echo esc_attr( $field->field_label ); ?>">
					<select class="ffc-field-type"<?php echo esc_attr( $locked_attr ); ?>>
						<?php foreach ( $field_types as $type ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $field->field_type, $type ); ?>><?php echo esc_html( ucfirst( $type ) ); ?></option>
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
							'disabled'    => $is_standard,
							'title'       => __( 'Encrypt this value at rest (AES-256).', 'ffcertificate' ),
						)
					);
					?>
				</div>
				<?php if ( 'acknowledgment' === $field->field_type ) : ?>
					<div class="ffc-field-html-container">
						<p class="description"><?php esc_html_e( 'Notice shown at the end of the reregistration form and printed on the ficha PDF. Use "Replicate lists to children" to push it to descendant audiences.', 'ffcertificate' ); ?></p>
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
					<input type="text" class="ffc-field-key" placeholder="<?php esc_attr_e( 'field_key', 'ffcertificate' ); ?>" value="<?php echo esc_attr( $field->field_key ); ?>"<?php echo esc_attr( $locked_attr ); ?>>
					<input type="text" class="ffc-field-profile-key" placeholder="<?php esc_attr_e( 'profile_key (optional)', 'ffcertificate' ); ?>" value="<?php echo esc_attr( $profile_key ); ?>"<?php echo esc_attr( $locked_attr ); ?>>
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
											<button type="button" class="button button-link-delete ffc-ds-division-remove"><?php esc_html_e( 'Remove division', 'ffcertificate' ); ?></button>
										</div>
										<div class="ffc-ds-sectors">
											<?php foreach ( (array) $ds_sectors as $ds_sector ) : ?>
												<div class="ffc-ds-sector">
													<input type="text" class="ffc-ds-sector-name regular-text" value="<?php echo esc_attr( (string) $ds_sector ); ?>" placeholder="<?php esc_attr_e( 'Department name', 'ffcertificate' ); ?>">
													<button type="button" class="button-link ffc-ds-sector-remove" aria-label="<?php esc_attr_e( 'Remove department', 'ffcertificate' ); ?>">&times;</button>
												</div>
											<?php endforeach; ?>
										</div>
										<button type="button" class="button button-small ffc-ds-sector-add"><?php esc_html_e( '+ Add Department', 'ffcertificate' ); ?></button>
									</div>
								<?php endforeach; ?>
							</div>
							<p>
								<button type="button" class="button ffc-ds-division-add"><?php esc_html_e( '+ Add Division', 'ffcertificate' ); ?></button>
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
				<button type="button" class="button button-small ffc-field-toggle-details" title="<?php esc_attr_e( 'Toggle details', 'ffcertificate' ); ?>">
					<span class="dashicons dashicons-admin-generic"></span>
				</button>
				<?php if ( ! $is_standard ) : ?>
					<button type="button" class="button button-small button-link-delete ffc-field-delete" title="<?php esc_attr_e( 'Remove', 'ffcertificate' ); ?>">
						<span class="dashicons dashicons-trash"></span>
					</button>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render audience members page
	 *
	 * @param string $menu_slug The menu slug prefix.
	 * @param int    $id        Audience ID.
	 * @return void
	 */
	public static function render_members( string $menu_slug, int $id ): void {
		$audience = AudienceReader::get_by_id( $id );
		if ( ! $audience ) {
			wp_die( esc_html__( 'Audience not found.', 'ffcertificate' ) );
		}

		$members  = AudienceReader::get_members( (int) $audience->id );
		$back_url = admin_url( 'admin.php?page=' . $menu_slug . '-audiences' );

		?>
		<h1><?php /* translators: %s: audience name */ echo esc_html( sprintf( __( 'Members of %s', 'ffcertificate' ), $audience->name ) ); ?></h1>
		<a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Audiences', 'ffcertificate' ); ?></a>

		<?php settings_errors( 'ffc_audience' ); ?>

		<div class="ffc-members-section">
			<h2><?php esc_html_e( 'Add Members', 'ffcertificate' ); ?></h2>
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
				<?php submit_button( __( 'Add Selected Members', 'ffcertificate' ), 'primary', 'add_members', false ); ?>
			</form>
		</div>

		<div class="ffc-members-section">
			<h2><?php esc_html_e( 'Current Members', 'ffcertificate' ); ?> (<?php echo count( $members ); ?>)</h2>

			<?php if ( empty( $members ) ) : ?>
				<p><?php esc_html_e( 'No members yet.', 'ffcertificate' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'ffcertificate' ); ?></th>
							<th><?php esc_html_e( 'Email', 'ffcertificate' ); ?></th>
							<th class="column-actions"><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
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
									<td><?php echo esc_html( $user->user_email ); ?></td>
									<td class="column-actions">
										<a href="<?php echo esc_url( $remove_url ); ?>" class="delete-link" onclick="return confirm('<?php esc_attr_e( 'Remove this member?', 'ffcertificate' ); ?>');">
											<?php esc_html_e( 'Remove', 'ffcertificate' ); ?>
										</a>
									</td>
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
