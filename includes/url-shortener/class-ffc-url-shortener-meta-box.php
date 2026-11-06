<?php
/**
 * URL Shortener Meta Box
 *
 * Adds a meta box to post/page editors showing the short URL,
 * QR Code preview, and download options (PNG/SVG).
 *
 * @package FreeFormCertificate\UrlShortener
 * @since 5.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Core\AjaxTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metabox UI for url shortener.
 */
class UrlShortenerMetaBox {

	use AjaxTrait;

	/**
	 * Description.
	 *
	 * @var UrlShortenerService
	 */
	private UrlShortenerService $service;

	/**
	 * Constructor.
	 *
	 * @param UrlShortenerService $service Service.
	 */
	public function __construct( UrlShortenerService $service ) {
		$this->service = $service;
	}

	/**
	 * Register the auto-create-on-publish hook.
	 *
	 * Split out of {@see init()} and called from **outside** the loader's
	 * `is_admin()` gate: the block editor saves over the REST API, where
	 * `is_admin()` is false, so a `save_post` hook registered admin-only never
	 * runs for a Gutenberg publish (#1013).
	 *
	 * Running outside admin is safe because {@see on_save_post()} defends
	 * itself — autosave, revision and non-`publish` guards, the auto-create
	 * toggle, the enabled-post-type allowlist, and a `findByPostId()` check
	 * that makes it idempotent. It needs no capability check: it is not a
	 * request handler but a `save_post` hook, which only fires once WordPress
	 * has already authorised the save.
	 *
	 * @since 6.21.0
	 */
	public function init_auto_create(): void {
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
	}

	/**
	 * Register the admin-only UI hooks.
	 *
	 * The auto-create hook lives in {@see init_auto_create()} instead, because
	 * it must also run in the REST context. `wp_ajax_*` stays here: `is_admin()`
	 * is true on `admin-ajax.php`.
	 */
	public function init(): void {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX: regenerate short code.
		add_action( 'wp_ajax_ffc_regenerate_short_url', array( $this, 'ajax_regenerate' ) );
	}

	/**
	 * Register meta box for enabled post types.
	 */
	public function register_meta_box(): void {
		$post_types = $this->service->get_enabled_post_types();

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'ffc_url_shortener',
				__( 'Short URL & QR Code', 'ffcertificate' ),
				array( $this, 'render' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the meta box content.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render( \WP_Post $post ): void {
		wp_nonce_field( 'ffc_short_url_meta_box', 'ffc_short_url_meta_nonce' );
		$this->render_box( $post );
	}

	/**
	 * The box's markup as a string, for the AJAX actions that redraw it in
	 * place instead of reloading the editor (#2337).
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public function box_html( \WP_Post $post ): string {
		ob_start();
		$this->render_box( $post );
		return (string) ob_get_clean();
	}

	/**
	 * Draw the box: the link, its state, its clicks and QR, and the one
	 * action button.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function render_box( \WP_Post $post ): void {
		$repository = $this->service->get_repository();
		$record     = $repository->findByPostId( $post->ID );
		$can_manage = self::can_manage( $post->ID );

		// Viewing the editor never creates a link (#2337): publishing does, and
		// so does a later save while the post has none.
		if ( ! $record ) {
			echo '<div class="ffc-shorturl-metabox">';
			if ( 'publish' !== $post->post_status ) {
				echo '<p class="description">' . esc_html__( 'Publish the post to generate a short URL.', 'ffcertificate' ) . '</p>';
			} elseif ( $this->service->is_auto_create_enabled() ) {
				echo '<p class="description">' . esc_html__( 'The short URL is created when the post is saved.', 'ffcertificate' ) . '</p>';
			} elseif ( $can_manage ) {
				echo '<p class="description">' . esc_html__( 'This post has no short URL yet.', 'ffcertificate' ) . '</p>';
				$this->render_action_button( $post->ID, 'generate' );
			} else {
				echo '<p class="description">' . esc_html__( 'This post has no short URL yet.', 'ffcertificate' ) . '</p>';
			}
			echo '</div>';
			return;
		}

		$status = (string) ( $record['status'] ?? '' );
		$labels = array(
			'active'   => __( 'Active', 'ffcertificate' ),
			'disabled' => __( 'Disabled', 'ffcertificate' ),
			'trashed'  => __( 'In the trash', 'ffcertificate' ),
		);

		$short_url = $this->service->get_short_url( $record['short_code'] );
		$is_active = 'active' === ( $record['status'] ?? '' );

		// Generate QR Code pointing to the short URL so clicks are tracked.
		// Pass short_code for database caching (avoids regeneration on every admin load).
		$qr_handler = new UrlShortenerQrHandler( $this->service );
		// The size comes from the constant rather than a literal, because it is
		// what decides whether this call reaches the cache: `generate_qr_base64()`
		// only reads and writes at `CACHE_SIZE` (#1233). With a `200` written
		// here, changing the constant would make the metabox -- the only repeated
		// caller, and the reason the cache exists -- stop caching in silence.
		// With the QR design applied it renders the styled SVG instead (#1563).
		$qr_src = $is_active ? $qr_handler->preview_src( $short_url, UrlShortenerQrHandler::CACHE_SIZE, $record['short_code'] ) : '';
		?>
		<div class="ffc-shorturl-metabox">
			<?php // The link's state, which the box acts on (#2337). ?>
			<p class="ffc-shorturl-state">
				<span class="ffc-shorturl-status ffc-shorturl-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $labels[ $status ] ?? $status ); ?></span>
			</p>
			<!-- Short URL -->
			<div class="ffc-mb-md">
				<label class="ffc-shorturl-label"><?php esc_html_e( 'Short URL', 'ffcertificate' ); ?></label>
				<div class="ffc-shorturl-input-row">
					<input type="text" value="<?php echo esc_attr( $short_url ); ?>" readonly
							id="ffc-shorturl-input" class="widefat ffc-shorturl-input-sm" />
					<?php
					\FreeFormCertificate\Core\Button::render(
						array(
							'label'     => __( 'Copy', 'ffcertificate' ),
							'icon'      => 'copy',
							'icon_only' => true,
							'class'     => 'ffc-copy-shorturl',
							'attrs'     => array(
								'data-url' => $short_url,
							),
						)
					);
					?>
				</div>
			</div>

			<!-- Click count -->
			<p class="ffc-shorturl-clicks">
				<?php
				$clicks = (int) $record['click_count'];
				printf(
					/* translators: %s: number of clicks, in bold */
					esc_html( _n( '%s click', '%s clicks', $clicks, 'ffcertificate' ) ),
					'<strong>' . esc_html( number_format_i18n( $clicks ) ) . '</strong>'
				);
				?>
			</p>

			<?php if ( ! $is_active ) : ?>
				<p class="description">
					<?php
					echo esc_html(
						'trashed' === ( $record['status'] ?? '' )
							? __( 'This short URL is in the trash and does not redirect.', 'ffcertificate' )
							: __( 'This short URL is disabled and does not redirect.', 'ffcertificate' )
					);
					?>
				</p>
			<?php endif; ?>

			<!-- QR Code Preview -->
			<?php if ( $is_active && '' !== $qr_src ) : ?>
				<div class="ffc-shorturl-qr-preview">
					<img src="<?php echo esc_attr( $qr_src ); ?>"
						alt="<?php esc_attr_e( 'QR Code', 'ffcertificate' ); ?>" />
				</div>

				<!-- Download Buttons -->
				<div class="ffc-shorturl-download-row">
					<?php
					\FreeFormCertificate\Core\Button::render(
						array(
							'label' => 'PNG',
							'icon'  => 'download',
							'class' => 'ffc-download-qr',
							'attrs' => array(
								'data-format'  => 'png',
								'data-post-id' => (string) $post->ID,
							),
						)
					);
					\FreeFormCertificate\Core\Button::render(
						array(
							'label' => 'SVG',
							'icon'  => 'download',
							'class' => 'ffc-download-qr',
							'attrs' => array(
								'data-format'  => 'svg',
								'data-post-id' => (string) $post->ID,
							),
						)
					);
					?>
				</div>
			<?php endif; ?>

			<?php
			// An off link comes back with its code; a live one swaps it (#2337).
			if ( $can_manage ) {
				$this->render_action_button( $post->ID, $is_active ? 'regenerate' : 'reactivate' );
			}
			?>
		</div>
		<?php
	}

	/**
	 * The box's one action button, named for what it will do (#2337).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $action  `generate`, `reactivate` or `regenerate`.
	 */
	private function render_action_button( int $post_id, string $action ): void {
		$buttons                                   = array(
			'generate'   => array( __( 'Generate', 'ffcertificate' ), 'create', 'plus', '' ),
			'reactivate' => array( __( 'Reactivate', 'ffcertificate' ), 'caution', 'restore', '' ),
			'regenerate' => array( __( 'Regenerate', 'ffcertificate' ), 'caution', 'sync', __( 'Give this link a new code? The current code stops working and is never issued again.', 'ffcertificate' ) ),
		);
		list( $label, $category, $icon, $confirm ) = $buttons[ $action ];

		echo '<div class="ffc-shorturl-regenerate">';
		\FreeFormCertificate\Core\Button::render(
			array(
				'label'    => $label,
				'category' => $category,
				'icon'     => $icon,
				'class'    => 'ffc-regenerate-shorturl',
				'attrs'    => array(
					'data-post-id'     => (string) $post_id,
					// Not `data-confirm`: ffc-core.js asks for every element carrying
					// that one, so the box would ask twice.
					'data-ffc-confirm' => $confirm,
				),
			)
		);
		echo '</div>';
	}

	/**
	 * Whether the current user may generate, reactivate or regenerate the
	 * post's link: the manage capability and editing that post (#2337).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function can_manage( int $post_id ): bool {
		return \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_url_shortener' )
			&& current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Auto-create short URL on post publish.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function on_save_post( int $post_id, \WP_Post $post ): void {
		// Skip auto-save, revisions, and non-publish.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! $this->service->is_auto_create_enabled() ) {
			return;
		}

		$enabled_types = $this->service->get_enabled_post_types();
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return;
		}

		// Check if short URL already exists.
		$existing = $this->service->get_repository()->findByPostId( $post_id );
		if ( $existing ) {
			return;
		}

		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			$this->service->create_short_url( $permalink, $post->post_title, $post_id );
		}
	}

	/**
	 * Enqueue assets on relevant admin screens.
	 *
	 * @param string $hook_suffix Admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$post_type = get_post_type();
		if ( ! $post_type || ! in_array( $post_type, $this->service->get_enabled_post_types(), true ) ) {
			return;
		}

		// ffc-url-shortener-admin.css paints through `var(--ffc-*)` since #1126
		// (defeito B), and ffc-common.css is where those tokens are declared.
		\FreeFormCertificate\Core\AssetHelper::enqueue_common_style();

		\FreeFormCertificate\Core\AssetRegistry::enqueue_styles( 'ffc-url-shortener-admin-css' );
		// Saving and rasterising QR images, shared with the manual generator (#1563).
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-qr-raster' );
		// `ffc-admin-js` carries FFC.Admin.notify(), the one toast the admin
		// has (#1843): copy and regenerate report through it.
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-core', 'ffc-admin-js', 'ffc-url-shortener-admin' );
		wp_localize_script(
			'ffc-url-shortener-admin',
			'ffcUrlShortener',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ffc_short_url_nonce' ),
				'i18n'    => array(
					'copied'        => __( 'Copied!', 'ffcertificate' ),
					'copyFailed'    => __( 'Copy failed', 'ffcertificate' ),
					'error'         => __( 'An error occurred.', 'ffcertificate' ),
					'qrLoadFailed'  => __( 'Failed to load QR Code', 'ffcertificate' ),
					'copy'          => __( 'Copy', 'ffcertificate' ),
					'requestFailed' => __( 'Request failed', 'ffcertificate' ),
				),
			)
		);
	}

	/**
	 * AJAX: the box's action on the post's link, decided by its state (#2337).
	 *
	 * - no link: create it (the post must be published);
	 * - disabled or trashed: reactivate it, same code;
	 * - active: swap its code in place and retire the old one.
	 *
	 * Needs the manage capability and editing that post.
	 */
	public function ajax_regenerate(): void {
		$this->verify_ajax_nonce( 'ffc_short_url_nonce' );
		$this->check_ajax_permission( 'ffc_manage_url_shortener' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce verified in $this->verify_ajax_nonce() above. Cast to int, which sanitises; unslashing a value that becomes an int is a no-op.
		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'ffcertificate' ) ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot edit this post.', 'ffcertificate' ) ) );
		}

		$existing = $this->service->get_repository()->findByPostId( $post_id );

		if ( ! $existing ) {
			if ( 'publish' !== $post->post_status ) {
				wp_send_json_error( array( 'message' => __( 'Publish the post to generate a short URL.', 'ffcertificate' ) ) );
			}
			$permalink = get_permalink( $post_id );
			$result    = $this->service->create_short_url( $permalink ? $permalink : '', $post->post_title, $post_id );
			$message   = __( 'Short URL created.', 'ffcertificate' );
		} elseif ( 'active' !== ( $existing['status'] ?? '' ) ) {
			$result  = $this->service->reactivate_short_url( (int) $existing['id'] )
				? array(
					'success' => true,
					'data'    => array_merge( $existing, array( 'status' => 'active' ) ),
				)
				: array(
					'success' => false,
					'error'   => __( 'The short URL could not be reactivated.', 'ffcertificate' ),
				);
			$message = __( 'Short URL reactivated. Its code and QR code work again.', 'ffcertificate' );
		} else {
			$result  = $this->service->regenerate_code( $existing );
			$message = __( 'Short URL regenerated. The old code is retired.', 'ffcertificate' );
		}

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ?? '' ) );
		}

		$data              = $result['data'] ?? array();
		$data['short_url'] = $this->service->get_short_url( (string) ( $data['short_code'] ?? '' ) );
		$data['message']   = $message;
		// The box redrawn as it now is, so the editor does not reload (#2337).
		$data['html'] = $this->box_html( $post );
		wp_send_json_success( $data );
	}
}
