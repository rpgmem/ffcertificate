<?php
/**
 * Documentation partial — Reference: Capabilities & Roles.
 *
 * GENERATED at render time from `CapabilityCatalog::groups()` (the canonical
 * human metadata, kept in sync with the registry by `CapabilityCatalogTest`),
 * so this page can never drift from the shipped capabilities. Part of the
 * documentation reorganization (rpgmem/ffcertificate#674).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_cap_catalog = '\FreeFormCertificate\UserDashboard\CapabilityCatalog';
?>
<!-- Capabilities & Roles Section -->
<div class="card">
	<h3 id="reference-capabilities"><span class="dashicons dashicons-admin-network" aria-hidden="true"></span> <?php esc_html_e( 'Capabilities & Roles', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'The plugin ships fine-grained capabilities you can assign to roles or individual users with any capability-management plugin. WordPress administrators (manage_options) always hold every FFC capability.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'The 3-state permission model', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Each admin domain exposes a view/manage pair, giving every surface three states:', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'No access', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'neither capability: the surface is hidden.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Read-only', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the view capability: inputs disabled, no save, row/bulk actions hidden.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Read-write', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the manage capability: full create/edit/delete/configure.', 'ffcertificate' ); ?></li>
		</ul>
		<p>
			<?php esc_html_e( 'Capabilities follow one grammar:', 'ffcertificate' ); ?>
			<code>ffc_&lt;action&gt;_[own_]&lt;domain&gt;[_&lt;qualifier&gt;]</code>.
			<?php esc_html_e( 'The own_ prefix marks a self-scoped end-user capability (the user\'s own data on the front end).', 'ffcertificate' ); ?>
		</p>
	</div>

	<?php if ( class_exists( $ffc_cap_catalog ) && method_exists( $ffc_cap_catalog, 'groups' ) ) : ?>
		<?php
		$ffc_last_level = '';
		foreach ( $ffc_cap_catalog::groups() as $ffc_group ) :
			$ffc_level = isset( $ffc_group['level'] ) ? (string) $ffc_group['level'] : '';
			if ( $ffc_level !== $ffc_last_level ) :
				$ffc_last_level = $ffc_level;
				?>
				<h4 class="ffc-mt-20">
					<?php
					echo esc_html(
						'admin' === $ffc_level
							? __( 'Administration capabilities (wp-admin)', 'ffcertificate' )
							: __( 'End-user capabilities (front end)', 'ffcertificate' )
					);
					?>
				</h4>
				<?php
			endif;
			?>
			<div class="ffc-doc-example">
				<h4><?php echo esc_html( isset( $ffc_group['label'] ) ? (string) $ffc_group['label'] : '' ); ?></h4>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Capability', 'ffcertificate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'What it does', 'ffcertificate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( (array) ( $ffc_group['caps'] ?? array() ) as $ffc_slug => $ffc_cap ) : ?>
							<tr>
								<td>
									<code><?php echo esc_html( (string) $ffc_slug ); ?></code><br>
									<em><?php echo esc_html( isset( $ffc_cap['label'] ) ? (string) $ffc_cap['label'] : '' ); ?></em>
								</td>
								<td><?php echo esc_html( isset( $ffc_cap['description'] ) ? (string) $ffc_cap['description'] : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
