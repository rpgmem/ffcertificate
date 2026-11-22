<?php
/**
 * FormCache
 *
 * Page-cache purges for the pages that embed a form or a calendar. The
 * form-settings object cache this class was written for left in 6.45.0
 * (#2241, #2552): it duplicated WordPress's own post-meta cache, so the
 * class keeps a name that no longer describes it (CLAUDE.md "Form settings
 * come from post meta").
 *
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace
 * @since   2.9.1
 * @package FreeFormCertificate\Submissions
 */

declare(strict_types=1);

namespace FreeFormCertificate\Submissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page-cache purges for forms.
 *
 * @since 2.9.1
 */
class FormCache {

	/**
	 * Daily warming event, retired in 6.43.0 (#2241). Kept so the event an
	 * install already scheduled is cleared, on every request, on
	 * deactivation and in both uninstall paths.
	 */
	public const WARM_HOOK = 'ffcertificate_warm_cache_hook';

	/**
	 * Aggressive page-cache purge across the whole site — for one-shot
	 * admin-triggered events (early-open, geofence change) where the
	 * per-post `flush_post()` calls in {@see purge_external_caches()}
	 * don't help because the `ffc_form` CPT is registered with
	 * `'public' => false`. The visible surface is the WP page that
	 * embeds `[ffc_form id=N]` via shortcode — a different post the
	 * cache plugins can't associate with the form id automatically.
	 *
	 * Hits each integration's broad "purge all" API instead of the
	 * per-post one. Each call is best-effort (try/catch) so a
	 * misbehaving cache plugin can never break the host action.
	 *
	 * Pair with {@see purge_external_caches()} — that one fires the
	 * `ffc_form_cache_purged` action hook with the form id, while this
	 * one is unconditional. The action hook below also fires here so
	 * Cloudflare APO / Redis page cache integrations get the same
	 * signal for both code paths.
	 *
	 * @param int    $form_id The form post id (passed to the action hook
	 *                        for consumers that want it; the cache plugin
	 *                        purges themselves are site-wide).
	 * @param string $reason  Free-form tag — currently 'early_open' or
	 *                        'geofence_changed'.
	 */
	public static function purge_all_pages( int $form_id, string $reason = '' ): void {
		// W3 Total Cache — site-wide page cache flush.
		if ( class_exists( '\W3TC\Dispatcher' ) ) {
			try {
				$component = \W3TC\Dispatcher::component( 'CacheFlush' );
				if ( is_object( $component ) ) {
					if ( method_exists( $component, 'flush_pgcache' ) ) {
						$component->flush_pgcache();
					} elseif ( method_exists( $component, 'flush_all' ) ) {
						$component->flush_all();
					}
				}
			} catch ( \Throwable $e ) {
				// Swallow — host action must not fail on a third-party glitch; leave a gated breadcrumb.
				\FreeFormCertificate\Core\Debug::log_form(
					'External cache purge failed',
					array(
						'integration' => 'w3tc',
						'error'       => $e->getMessage(),
					)
				);
			}
		}

		// LiteSpeed Cache.
		if ( class_exists( '\LiteSpeed\Purge' ) ) {
			try {
				\LiteSpeed\Purge::purge_all( 'FFC ' . $reason );
			} catch ( \Throwable $e ) {
				// Swallow — host action must not fail on a third-party glitch; leave a gated breadcrumb.
				\FreeFormCertificate\Core\Debug::log_form(
					'External cache purge failed',
					array(
						'integration' => 'litespeed',
						'error'       => $e->getMessage(),
					)
				);
			}
		}

		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			try {
				wp_cache_clear_cache();
			} catch ( \Throwable $e ) {
				// Swallow — host action must not fail on a third-party glitch; leave a gated breadcrumb.
				\FreeFormCertificate\Core\Debug::log_form(
					'External cache purge failed',
					array(
						'integration' => 'wpsupercache',
						'error'       => $e->getMessage(),
					)
				);
			}
		}

		// WP Rocket — clean entire site cache.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			try {
				rocket_clean_domain();
			} catch ( \Throwable $e ) {
				// Swallow — host action must not fail on a third-party glitch; leave a gated breadcrumb.
				\FreeFormCertificate\Core\Debug::log_form(
					'External cache purge failed',
					array(
						'integration' => 'wprocket',
						'error'       => $e->getMessage(),
					)
				);
			}
		}

		/**
		 * Mirror the per-form hook so consumers (Cloudflare APO, Redis
		 * page cache, custom CDN purgers) receive the same signal on
		 * the aggressive-purge path. `$reason` is suffixed `:all` so
		 * subscribers that want to differentiate can.
		 *
		 * @param int    $form_id Form post id that triggered the purge.
		 * @param string $reason  Source tag + ':all' suffix.
		 */
		do_action( 'ffc_form_cache_purged', $form_id, $reason . ':all' );
	}

	/**
	 * Best-effort external page-cache purge across known third-party
	 * plugins, plus an action hook for anything else (Cloudflare APO,
	 * Redis page cache, custom setups).
	 *
	 * Call this IN ADDITION TO {@see self::clear_form_cache()} when
	 * the form's public-facing state has changed in a way visitors
	 * would notice on a cached page (form started early, datetime
	 * edited, public toggles flipped). Routine internal-only cache
	 * touches (every `save_post`, every submission) should only call
	 * `clear_form_cache()` to keep them cheap.
	 *
	 * Each integration is wrapped in a try/catch so a misbehaving
	 * third-party plugin can never break the host action.
	 *
	 * @param int    $form_id The form post id whose public pages
	 *                        should be invalidated.
	 * @param string $reason  Free-form tag passed to the action hook
	 *                        ('early_open', 'geofence_changed', etc.)
	 *                        — lets external integrations log /
	 *                        rate-limit / branch on the source.
	 */
	public static function purge_external_caches( int $form_id, string $reason = '' ): void {
		if ( $form_id <= 0 ) {
			return;
		}

		// W3 Total Cache.
		if ( class_exists( '\W3TC\Dispatcher' ) ) {
			try {
				\W3TC\Dispatcher::component( 'CacheFlush' )->flush_post( $form_id );
			} catch ( \Throwable $e ) {
				// Swallow — host action must not fail on a third-party glitch; leave a gated breadcrumb.
				\FreeFormCertificate\Core\Debug::log_form(
					'External cache purge failed',
					array(
						'integration' => 'w3tc',
						'form_id'     => $form_id,
						'error'       => $e->getMessage(),
					)
				);
			}
		}

		// LiteSpeed Cache.
		if ( class_exists( '\LiteSpeed\Purge' ) ) {
			try {
				\LiteSpeed\Purge::add( 'P_' . $form_id );
			} catch ( \Throwable $e ) {
				// Swallow — host action must not fail on a third-party glitch; leave a gated breadcrumb.
				\FreeFormCertificate\Core\Debug::log_form(
					'External cache purge failed',
					array(
						'integration' => 'litespeed',
						'form_id'     => $form_id,
						'error'       => $e->getMessage(),
					)
				);
			}
		}

		// WP Super Cache.
		if ( function_exists( 'wpsc_delete_post_cache' ) ) {
			try {
				wpsc_delete_post_cache( $form_id );
			} catch ( \Throwable $e ) {
				// Swallow — host action must not fail on a third-party glitch; leave a gated breadcrumb.
				\FreeFormCertificate\Core\Debug::log_form(
					'External cache purge failed',
					array(
						'integration' => 'wpsupercache',
						'form_id'     => $form_id,
						'error'       => $e->getMessage(),
					)
				);
			}
		}

		// WP Rocket.
		if ( function_exists( 'rocket_clean_post' ) ) {
			try {
				rocket_clean_post( $form_id );
			} catch ( \Throwable $e ) {
				// Swallow — host action must not fail on a third-party glitch; leave a gated breadcrumb.
				\FreeFormCertificate\Core\Debug::log_form(
					'External cache purge failed',
					array(
						'integration' => 'wprocket',
						'form_id'     => $form_id,
						'error'       => $e->getMessage(),
					)
				);
			}
		}

		/**
		 * Fires after the plugin has invalidated the form's public-
		 * page cache. Third-party integrations (Cloudflare APO, Redis
		 * page cache, custom CDN purger) can hook this to invalidate
		 * their own layer.
		 *
		 * @param int    $form_id The form post id whose pages just
		 *                        had their cache invalidated.
		 * @param string $reason  Source tag — currently one of
		 *                        'early_open', 'geofence_changed',
		 *                        'manual_clear_all', or empty when
		 *                        unspecified.
		 */
		do_action( 'ffc_form_cache_purged', $form_id, $reason );
	}

	/**
	 * Sweep external page-cache purges across every published form.
	 *
	 * Called by the "Clear all cache" admin button so that, after the
	 * plugin's own object cache has been wiped, third-party page caches
	 * (W3 Total Cache, LiteSpeed, Super Cache, WP Rocket, CDN APO via
	 * the action hook) also drop their cached versions of FFC form
	 * pages — otherwise an admin clicking "Clear all cache" still sees
	 * stale public pages.
	 *
	 * @param string $reason Forwarded to {@see self::purge_external_caches()}.
	 * @return int Number of forms iterated.
	 */
	public static function purge_external_caches_for_all_forms( string $reason = 'manual_clear_all' ): int {
		$ids = get_posts(
			array(
				'post_type'        => 'ffc_form',
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
		foreach ( $ids as $id ) {
			self::purge_external_caches( (int) $id, $reason );
		}
		return count( $ids );
	}

	/**
	 * Register hooks for automatic cache invalidation
	 */
	public static function register_hooks(): void {
		add_action( 'save_post_ffc_form', array( __CLASS__, 'on_form_saved' ), 10, 3 );
	}

	/**
	 * Drop the daily warming event an install scheduled before the warming
	 * was retired (#2241). Called on every request from
	 * `Loader::init_plugin()`; once the event is gone it costs one
	 * `wp_next_scheduled()` lookup in the already-loaded cron array.
	 *
	 * @return void
	 */
	public static function retire_warming_schedule(): void {
		if ( false !== wp_next_scheduled( self::WARM_HOOK ) ) {
			self::unschedule_cache_warming();
		}
	}

	/**
	 * Hook callback: Form saved. Purges the page caches that hold the pages
	 * embedding the form.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 */
	public static function on_form_saved( int $post_id, \WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		self::purge_page_cache( $post_id, 'ffc_form' );
	}

	/**
	 * Unschedule cache warming cron job
	 */
	public static function unschedule_cache_warming(): void {
		wp_clear_scheduled_hook( self::WARM_HOOK );
	}

	/**
	 * Purge page cache (LiteSpeed, WP Rocket, etc.) when a form or calendar
	 * is saved so that cached pages don't serve stale geofence/schedule data.
	 *
	 * @param int    $post_id   Post ID that was saved.
	 * @param string $post_type Post type slug.
	 */
	public static function purge_page_cache( int $post_id, string $post_type ): void {
		$shortcode_tag = 'ffc_form' === $post_type ? 'ffc_form' : 'ffc_self_scheduling';

		// Find pages that embed this form/calendar via shortcode.
		$pages = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				's'              => '[' . $shortcode_tag . ' id="' . $post_id . '"',
				'posts_per_page' => 20,
				'fields'         => 'ids',
			)
		);

		// LiteSpeed Cache — purge specific URLs.
		if ( defined( 'LSCWP_V' ) ) {
			foreach ( $pages as $page_id ) {
				$url = get_permalink( $page_id );
				if ( $url ) {
					do_action( 'litespeed_purge_url', $url );
				}
			}
			if ( empty( $pages ) ) {
				do_action( 'litespeed_purge_all' );
			}
		}

		// WP Rocket — clean specific URLs.
		if ( function_exists( 'rocket_clean_post' ) ) {
			foreach ( $pages as $page_id ) {
				rocket_clean_post( $page_id );
			}
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_posts' ) ) {
			w3tc_flush_posts();
		}

		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
	}
}
