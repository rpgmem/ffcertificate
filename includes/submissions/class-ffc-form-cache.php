<?php
/**
 * FormCache
 * Caching layer for form configurations to improve performance
 *
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @since   2.9.1
 * @package FreeFormCertificate\Submissions
 */

declare(strict_types=1);

namespace FreeFormCertificate\Submissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Caching layer for form configurations to improve performance.
 *
 * @since 2.9.1
 */
class FormCache {

	const CACHE_GROUP = 'ffc_forms';

	/**
	 * Get cache expiration from settings
	 */
	public static function get_expiration(): int {
		$settings = get_option( 'ffc_settings', array() );
		return isset( $settings['cache_expiration'] ) ? intval( $settings['cache_expiration'] ) : 3600;
	}

	/**
	 * Get form configuration with caching
	 *
	 * @param int $form_id Form ID.
	 * @return array<string, mixed>|false Form config array or false if not found
	 */
	public static function get_form_config( int $form_id ) {
		$cache_key = 'config_' . $form_id;
		$config    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false === $config ) {
			$config = get_post_meta( $form_id, '_ffc_form_config', true );

			if ( $config && is_array( $config ) ) {
				wp_cache_set( $cache_key, $config, self::CACHE_GROUP, self::get_expiration() );

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					\FreeFormCertificate\Core\Debug::log_form( 'Form config cache MISS', array( 'form_id' => $form_id ) );
				}
			} else {
				return false;
			}
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				\FreeFormCertificate\Core\Debug::log_form( 'Form config cache HIT', array( 'form_id' => $form_id ) );
		}

		return $config;
	}

	/**
	 * Get form fields with caching
	 *
	 * @param int $form_id Form ID.
	 * @return array<int, array<string, mixed>>|false Form fields array or false if not found
	 */
	public static function get_form_fields( int $form_id ) {
		$cache_key = 'fields_' . $form_id;
		$fields    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false === $fields ) {
			$fields = get_post_meta( $form_id, '_ffc_form_fields', true );

			if ( $fields && is_array( $fields ) ) {
				wp_cache_set( $cache_key, $fields, self::CACHE_GROUP, self::get_expiration() );
			} else {
				return false;
			}
		}

		return $fields;
	}

	/**
	 * Get form background image with caching.
	 *
	 * @param int $form_id Form ID.
	 * @return string Background image URL or empty string.
	 */
	public static function get_form_background( int $form_id ): string {
		$cache_key = 'bg_' . $form_id;
		$bg        = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false === $bg ) {
			$bg = get_post_meta( $form_id, '_ffc_form_bg', true );

			if ( $bg ) {
				wp_cache_set( $cache_key, $bg, self::CACHE_GROUP, self::get_expiration() );
			}
		}

		return $bg ? (string) $bg : '';  // Return empty string instead of false.
	}

	/**
	 * Get complete form data
	 *
	 * @param int $form_id Form ID.
	 * @return array<string, mixed> Complete form data
	 */
	public static function get_form_complete( int $form_id ): array {
		$cache_key = 'complete_' . $form_id;
		$data      = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false === $data ) {
			$data = array(
				'config'     => self::get_form_config( $form_id ),
				'fields'     => self::get_form_fields( $form_id ),
				'background' => self::get_form_background( $form_id ),
			);

			wp_cache_set( $cache_key, $data, self::CACHE_GROUP, self::get_expiration() );
		}

		return $data;
	}

	/**
	 * Get form post object with caching
	 *
	 * @param int $form_id Form ID.
	 * @return \WP_Post|false Post object or false if not found
	 */
	public static function get_form_post( int $form_id ) {
		$cache_key = 'post_' . $form_id;
		$post      = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false === $post ) {
			$post = get_post( $form_id );

			if ( $post && 'ffc_form' === $post->post_type ) {
				wp_cache_set( $cache_key, $post, self::CACHE_GROUP, self::get_expiration() );
			} else {
				return false;
			}
		}

		return $post;
	}

	/**
	 * Clear cache for specific form.
	 *
	 * @param int $form_id Form ID.
	 * @return bool True if any cache keys were cleared.
	 */
	public static function clear_form_cache( int $form_id ): bool {
		$keys = array(
			'config_' . $form_id,
			'fields_' . $form_id,
			'bg_' . $form_id,
			'complete_' . $form_id,
			'post_' . $form_id,
		);

		$cleared = 0;
		foreach ( $keys as $key ) {
			if ( wp_cache_delete( $key, self::CACHE_GROUP ) ) {
				++$cleared;
			}
		}

		return $cleared > 0;
	}

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
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- best-effort.
				// Swallow.
			}
		}

		// LiteSpeed Cache.
		if ( class_exists( '\LiteSpeed\Purge' ) ) {
			try {
				\LiteSpeed\Purge::purge_all( 'FFC ' . $reason );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- best-effort.
				// Swallow.
			}
		}

		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			try {
				wp_cache_clear_cache();
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- best-effort.
				// Swallow.
			}
		}

		// WP Rocket — clean entire site cache.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			try {
				rocket_clean_domain();
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- best-effort.
				// Swallow.
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
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- best-effort.
				// Swallow — host action must not fail on a third-party glitch.
			}
		}

		// LiteSpeed Cache.
		if ( class_exists( '\LiteSpeed\Purge' ) ) {
			try {
				\LiteSpeed\Purge::add( 'P_' . $form_id );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- best-effort.
				// Swallow.
			}
		}

		// WP Super Cache.
		if ( function_exists( 'wpsc_delete_post_cache' ) ) {
			try {
				wpsc_delete_post_cache( $form_id );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- best-effort.
				// Swallow.
			}
		}

		// WP Rocket.
		if ( function_exists( 'rocket_clean_post' ) ) {
			try {
				rocket_clean_post( $form_id );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- best-effort.
				// Swallow.
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
	 * Clear all form caches
	 */
	public static function clear_all_cache(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return wp_cache_flush();
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
	 * Warm up cache for a form.
	 *
	 * @param int $form_id Form ID.
	 * @return bool Always true.
	 */
	public static function warm_cache( int $form_id ): bool {
		self::get_form_complete( $form_id );
		return true;
	}

	/**
	 * Warm up cache for all forms.
	 *
	 * @param int $limit Maximum number of forms to warm.
	 * @return int Number of forms warmed.
	 */
	public static function warm_all_forms( int $limit = 50 ): int {
		$args = array(
			'post_type'      => 'ffc_form',
			'posts_per_page' => $limit,
			'post_status'    => 'publish',
			'fields'         => 'ids',
		);

		$form_ids = get_posts( $args );
		$warmed   = 0;

		foreach ( $form_ids as $form_id ) {
			if ( self::warm_cache( $form_id ) ) {
				++$warmed;
			}
		}

		return $warmed;
	}

	/**
	 * Get cache statistics
	 *
	 * @return array<string, mixed> Cache statistics
	 */
	public static function get_stats(): array {
		return array(
			'enabled'    => wp_using_ext_object_cache(),
			'backend'    => wp_using_ext_object_cache() ? 'external' : 'database',
			'group'      => self::CACHE_GROUP,
			'expiration' => self::get_expiration() . ' seconds',
			'note'       => 'Detailed stats require Redis/Memcached with stats module',
		);
	}

	/**
	 * Check if form cache exists
	 *
	 * @param int $form_id Form ID.
	 * @return array<string, bool> Cache status per key
	 */
	public static function check_form_cache_status( int $form_id ): array {
		$keys = array(
			'config'     => 'config_' . $form_id,
			'fields'     => 'fields_' . $form_id,
			'background' => 'bg_' . $form_id,
			'complete'   => 'complete_' . $form_id,
			'post'       => 'post_' . $form_id,
		);

		$status = array();

		foreach ( $keys as $name => $cache_key ) {
			$cached          = wp_cache_get( $cache_key, self::CACHE_GROUP );
			$status[ $name ] = false !== $cached;
		}

		return $status;
	}

	/**
	 * Get cache key for debugging.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $type    Cache type (config, fields, bg, complete, post).
	 * @return string Cache key string.
	 */
	public static function get_cache_key( int $form_id, string $type = 'config' ): string {
		$keys = array(
			'config'     => 'config_' . $form_id,
			'fields'     => 'fields_' . $form_id,
			'bg'         => 'bg_' . $form_id,
			'background' => 'bg_' . $form_id,
			'complete'   => 'complete_' . $form_id,
			'post'       => 'post_' . $form_id,
		);

		return isset( $keys[ $type ] ) ? $keys[ $type ] : $keys['config'];
	}

	/**
	 * Register hooks for automatic cache invalidation
	 */
	public static function register_hooks(): void {
		add_action( 'save_post_ffc_form', array( __CLASS__, 'on_form_saved' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_form_deleted' ), 10, 2 );
		add_action(
			'ffcertificate_warm_cache_hook',
			static function (): void {
				self::warm_all_forms();
			}
		);
	}

	/**
	 * Hook callback: Form saved
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 */
	public static function on_form_saved( int $post_id, \WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		self::clear_form_cache( $post_id );

		if ( 'publish' === $post->post_status ) {
			self::warm_cache( $post_id );
		}

		self::purge_page_cache( $post_id, 'ffc_form' );
	}

	/**
	 * Hook callback: Form deleted
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public static function on_form_deleted( int $post_id, \WP_Post $post ): void {
		if ( 'ffc_form' === $post->post_type ) {
			self::clear_form_cache( $post_id );
		}
	}

	/**
	 * Schedule cache warming cron job
	 */
	public static function schedule_cache_warming(): void {
		if ( ! wp_next_scheduled( 'ffcertificate_warm_cache_hook' ) ) {
			wp_schedule_event( time(), 'daily', 'ffcertificate_warm_cache_hook' );
		}
	}

	/**
	 * Unschedule cache warming cron job
	 */
	public static function unschedule_cache_warming(): void {
		$timestamp = wp_next_scheduled( 'ffcertificate_warm_cache_hook' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'ffcertificate_warm_cache_hook' );
		}
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

// Register hooks on load.
add_action( 'init', array( __NAMESPACE__ . '\\FormCache', 'register_hooks' ), 5 );
