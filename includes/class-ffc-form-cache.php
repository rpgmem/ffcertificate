<?php
/**
 * FFC_Form_Cache
 * Caching layer for form configurations to improve performance
 * 
 * Features:
 * - Caches form config, fields, and background images
 * - Uses WordPress object cache (supports Redis, Memcached, etc.)
 * - Automatic cache invalidation on form save
 * - Grouped cache for better organization
 * - Statistics and debugging methods
 * 
 * @since 2.9.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Form_Cache {
    
    /**
     * Cache group name
     */
    const CACHE_GROUP = 'ffc_forms';
    
    /**
     * Cache expiration (1 hour)
     */
    const CACHE_EXPIRATION = 3600;
    
    /**
     * Get form configuration with caching
     * 
     * @param int $form_id Form post ID
     * @return array|false Form configuration or false if not found
     */
    public static function get_form_config( $form_id ) {
        $cache_key = 'config_' . $form_id;
        $config = wp_cache_get( $cache_key, self::CACHE_GROUP );
        
        if ( false === $config ) {
            // Cache miss - fetch from database
            $config = get_post_meta( $form_id, '_ffc_form_config', true );
            
            if ( $config && is_array( $config ) ) {
                // Store in cache
                wp_cache_set( $cache_key, $config, self::CACHE_GROUP, self::CACHE_EXPIRATION );
                
                // Log cache miss (if debug enabled)
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    FFC_Utils::debug_log( 'Form config cache MISS', array( 'form_id' => $form_id ) );
                }
            } else {
                // No config found
                return false;
            }
        } else {
            // Log cache hit (if debug enabled)
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                FFC_Utils::debug_log( 'Form config cache HIT', array( 'form_id' => $form_id ) );
            }
        }
        
        return $config;
    }
    
    /**
     * Get form fields with caching
     * 
     * @param int $form_id Form post ID
     * @return array|false Form fields or false if not found
     */
    public static function get_form_fields( $form_id ) {
        $cache_key = 'fields_' . $form_id;
        $fields = wp_cache_get( $cache_key, self::CACHE_GROUP );
        
        if ( false === $fields ) {
            // Cache miss - fetch from database
            $fields = get_post_meta( $form_id, '_ffc_form_fields', true );
            
            if ( $fields && is_array( $fields ) ) {
                // Store in cache
                wp_cache_set( $cache_key, $fields, self::CACHE_GROUP, self::CACHE_EXPIRATION );
            } else {
                // No fields found
                return false;
            }
        }
        
        return $fields;
    }
    
    /**
     * Get form background image with caching
     * 
     * @param int $form_id Form post ID
     * @return string|false Background image URL or false if not found
     */
    public static function get_form_background( $form_id ) {
        $cache_key = 'bg_' . $form_id;
        $bg = wp_cache_get( $cache_key, self::CACHE_GROUP );
        
        if ( false === $bg ) {
            // Cache miss - fetch from database
            $bg = get_post_meta( $form_id, '_ffc_form_bg', true );
            
            if ( $bg ) {
                // Store in cache (even empty string to avoid repeated queries)
                wp_cache_set( $cache_key, $bg, self::CACHE_GROUP, self::CACHE_EXPIRATION );
            }
        }
        
        return $bg ? $bg : false;
    }
    
    /**
     * Get complete form data (config + fields + background)
     * Single cache lookup for all form data
     * 
     * @param int $form_id Form post ID
     * @return array Complete form data with keys: config, fields, background
     */
    public static function get_form_complete( $form_id ) {
        $cache_key = 'complete_' . $form_id;
        $data = wp_cache_get( $cache_key, self::CACHE_GROUP );
        
        if ( false === $data ) {
            // Cache miss - fetch all data
            $data = array(
                'config' => self::get_form_config( $form_id ),
                'fields' => self::get_form_fields( $form_id ),
                'background' => self::get_form_background( $form_id )
            );
            
            // Store complete data in cache
            wp_cache_set( $cache_key, $data, self::CACHE_GROUP, self::CACHE_EXPIRATION );
        }
        
        return $data;
    }
    
    /**
     * Get form post object with caching
     * 
     * @param int $form_id Form post ID
     * @return WP_Post|false Post object or false
     */
    public static function get_form_post( $form_id ) {
        $cache_key = 'post_' . $form_id;
        $post = wp_cache_get( $cache_key, self::CACHE_GROUP );
        
        if ( false === $post ) {
            $post = get_post( $form_id );
            
            if ( $post && $post->post_type === 'ffc_form' ) {
                wp_cache_set( $cache_key, $post, self::CACHE_GROUP, self::CACHE_EXPIRATION );
            } else {
                return false;
            }
        }
        
        return $post;
    }
    
    /**
     * Clear cache for specific form
     * Called automatically when form is saved
     * 
     * @param int $form_id Form post ID
     * @return bool Success
     */
    public static function clear_form_cache( $form_id ) {
        $keys = array(
            'config_' . $form_id,
            'fields_' . $form_id,
            'bg_' . $form_id,
            'complete_' . $form_id,
            'post_' . $form_id
        );
        
        $cleared = 0;
        foreach ( $keys as $key ) {
            if ( wp_cache_delete( $key, self::CACHE_GROUP ) ) {
                $cleared++;
            }
        }
        
        // Log cache clear
        if ( class_exists( 'FFC_Activity_Log' ) ) {
            FFC_Activity_Log::log( 'cache_cleared', FFC_Activity_Log::LEVEL_DEBUG, array(
                'form_id' => $form_id,
                'keys_cleared' => $cleared
            ), get_current_user_id() );
        }
        
        return $cleared > 0;
    }
    
    /**
     * Clear all form caches
     * Use sparingly - typically only needed during development or major updates
     * 
     * @return bool Success
     */
    public static function clear_all_cache() {
        // WordPress doesn't provide a way to clear a specific group
        // So we flush the entire cache (will affect other plugins too)
        // Only use in admin with confirmation
        
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }
        
        $result = wp_cache_flush();
        
        // Log cache flush
        if ( class_exists( 'FFC_Activity_Log' ) ) {
            FFC_Activity_Log::log( 'cache_flushed', FFC_Activity_Log::LEVEL_WARNING, array(
                'action' => 'all_caches_cleared'
            ), get_current_user_id() );
        }
        
        return $result;
    }
    
    /**
     * Warm up cache for a form
     * Pre-load all form data into cache
     * 
     * @param int $form_id Form post ID
     * @return bool Success
     */
    public static function warm_cache( $form_id ) {
        // Load all data to populate cache
        $data = self::get_form_complete( $form_id );
        
        return $data !== false;
    }
    
    /**
     * Warm up cache for all forms
     * Useful after cache flush or on cron schedule
     * 
     * @param int $limit Maximum number of forms to warm (default: 50)
     * @return int Number of forms cached
     */
    public static function warm_all_forms( $limit = 50 ) {
        $args = array(
            'post_type' => 'ffc_form',
            'posts_per_page' => $limit,
            'post_status' => 'publish',
            'fields' => 'ids'
        );
        
        $form_ids = get_posts( $args );
        $warmed = 0;
        
        foreach ( $form_ids as $form_id ) {
            if ( self::warm_cache( $form_id ) ) {
                $warmed++;
            }
        }
        
        return $warmed;
    }
    
    /**
     * Get cache statistics
     * Useful for monitoring cache performance
     * 
     * @return array Statistics with hit rate, misses, etc.
     */
    public static function get_stats() {
        // Note: WordPress core doesn't track cache stats by default
        // This would require a persistent cache backend like Redis
        // with stats enabled, or custom tracking
        
        return array(
            'enabled' => wp_using_ext_object_cache(),
            'backend' => wp_using_ext_object_cache() ? 'external' : 'database',
            'group' => self::CACHE_GROUP,
            'expiration' => self::CACHE_EXPIRATION . ' seconds',
            'note' => 'Detailed stats require Redis/Memcached with stats module'
        );
    }
    
    /**
     * Check if form cache exists
     * 
     * @param int $form_id Form post ID
     * @return array Status of each cache key
     */
    public static function check_form_cache_status( $form_id ) {
        $keys = array(
            'config' => 'config_' . $form_id,
            'fields' => 'fields_' . $form_id,
            'background' => 'bg_' . $form_id,
            'complete' => 'complete_' . $form_id,
            'post' => 'post_' . $form_id
        );
        
        $status = array();
        
        foreach ( $keys as $name => $cache_key ) {
            $cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
            $status[$name] = $cached !== false;
        }
        
        return $status;
    }
    
    /**
     * Get cache key for debugging
     * 
     * @param int $form_id Form post ID
     * @param string $type Type (config, fields, bg, complete, post)
     * @return string Cache key
     */
    public static function get_cache_key( $form_id, $type = 'config' ) {
        $keys = array(
            'config' => 'config_' . $form_id,
            'fields' => 'fields_' . $form_id,
            'bg' => 'bg_' . $form_id,
            'background' => 'bg_' . $form_id,
            'complete' => 'complete_' . $form_id,
            'post' => 'post_' . $form_id
        );
        
        return isset( $keys[$type] ) ? $keys[$type] : $keys['config'];
    }
    
    /**
     * Register hooks for automatic cache invalidation
     * Should be called during plugin initialization
     */
    public static function register_hooks() {
        // Clear cache when form is saved
        add_action( 'save_post_ffc_form', array( __CLASS__, 'on_form_saved' ), 10, 3 );
        
        // Clear cache when form is deleted
        add_action( 'before_delete_post', array( __CLASS__, 'on_form_deleted' ), 10, 2 );
        
        // Optional: Warm cache on schedule
        add_action( 'ffc_warm_cache_hook', array( __CLASS__, 'warm_all_forms' ) );
    }
    
    /**
     * Hook callback: Form saved
     * 
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     * @param bool $update Whether this is an update
     */
    public static function on_form_saved( $post_id, $post, $update ) {
        // Skip revisions and autosaves
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        
        // Clear cache for this form
        self::clear_form_cache( $post_id );
        
        // Warm cache immediately for published forms
        if ( $post->post_status === 'publish' ) {
            self::warm_cache( $post_id );
        }
    }
    
    /**
     * Hook callback: Form deleted
     * 
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     */
    public static function on_form_deleted( $post_id, $post ) {
        if ( $post && $post->post_type === 'ffc_form' ) {
            self::clear_form_cache( $post_id );
        }
    }
    
    /**
     * Schedule cache warming cron job
     * Run daily to keep cache fresh
     */
    public static function schedule_cache_warming() {
        if ( ! wp_next_scheduled( 'ffc_warm_cache_hook' ) ) {
            wp_schedule_event( time(), 'daily', 'ffc_warm_cache_hook' );
        }
    }
    
    /**
     * Unschedule cache warming cron job
     */
    public static function unschedule_cache_warming() {
        $timestamp = wp_next_scheduled( 'ffc_warm_cache_hook' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'ffc_warm_cache_hook' );
        }
    }
}

// Register hooks on load
add_action( 'init', array( 'FFC_Form_Cache', 'register_hooks' ), 5 );