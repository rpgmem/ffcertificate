<?php
declare(strict_types=1);

/**
 * FormCache
 * Caching layer for form configurations to improve performance
 *
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @since 2.9.1
 */

namespace FreeFormCertificate\Submissions;

if ( ! defined( 'ABSPATH' ) ) exit;

class FormCache {
    
    const CACHE_GROUP = 'ffc_forms';
    
    /**
     * Get cache expiration from settings
     */
    public static function get_expiration(): int {
        $settings = get_option('ffc_settings', array());
        return isset($settings['cache_expiration']) ? intval($settings['cache_expiration']) : 3600;
    }
    
    /**
     * Get form configuration with caching
     */
    public static function get_form_config( int $form_id ) {
        $cache_key = 'config_' . $form_id;
        $config = wp_cache_get( $cache_key, self::CACHE_GROUP );
        
        if ( false === $config ) {
            $config = get_post_meta( $form_id, '_ffc_form_config', true );
            
            if ( $config && is_array( $config ) ) {
                wp_cache_set( $cache_key, $config, self::CACHE_GROUP, self::get_expiration() );
                
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    \FreeFormCertificate\Core\Utils::debug_log( 'Form config cache MISS', array( 'form_id' => $form_id ) );
                }
            } else {
                return false;
            }
        } else {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                \FreeFormCertificate\Core\Utils::debug_log( 'Form config cache HIT', array( 'form_id' => $form_id ) );
            }
        }
        
        return $config;
    }
    
    /**
     * Get form fields with caching
     */
    public static function get_form_fields( int $form_id ) {
        $cache_key = 'fields_' . $form_id;
        $fields = wp_cache_get( $cache_key, self::CACHE_GROUP );
        
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
     * Get form background image with caching
     */
    public static function get_form_background( int $form_id ): string {
        $cache_key = 'bg_' . $form_id;
        $bg = wp_cache_get( $cache_key, self::CACHE_GROUP );

        if ( false === $bg ) {
            $bg = get_post_meta( $form_id, '_ffc_form_bg', true );

            if ( $bg ) {
                wp_cache_set( $cache_key, $bg, self::CACHE_GROUP, self::get_expiration() );
            }
        }

        return $bg ? (string) $bg : '';  // Return empty string instead of false
    }
    
    /**
     * Get complete form data
     */
    public static function get_form_complete( int $form_id ): array {
        $cache_key = 'complete_' . $form_id;
        $data = wp_cache_get( $cache_key, self::CACHE_GROUP );
        
        if ( false === $data ) {
            $data = array(
                'config' => self::get_form_config( $form_id ),
                'fields' => self::get_form_fields( $form_id ),
                'background' => self::get_form_background( $form_id )
            );
            
            wp_cache_set( $cache_key, $data, self::CACHE_GROUP, self::get_expiration() );
        }
        
        return $data;
    }
    
    /**
     * Get form post object with caching
     */
    public static function get_form_post( int $form_id ) {
        $cache_key = 'post_' . $form_id;
        $post = wp_cache_get( $cache_key, self::CACHE_GROUP );
        
        if ( false === $post ) {
            $post = get_post( $form_id );
            
            if ( $post && $post->post_type === 'ffc_form' ) {
                wp_cache_set( $cache_key, $post, self::CACHE_GROUP, self::get_expiration() );
            } else {
                return false;
            }
        }
        
        return $post;
    }
    
    /**
     * Clear cache for specific form
     */
    public static function clear_form_cache( int $form_id ): bool {
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
        
        return $cleared > 0;
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
     * Warm up cache for a form
     */
    public static function warm_cache( int $form_id ): bool {
        $data = self::get_form_complete( $form_id );
        return $data !== false;
    }
    
    /**
     * Warm up cache for all forms
     */
    public static function warm_all_forms( int $limit = 50 ): int {
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
     */
    public static function get_stats(): array {
        return array(
            'enabled' => wp_using_ext_object_cache(),
            'backend' => wp_using_ext_object_cache() ? 'external' : 'database',
            'group' => self::CACHE_GROUP,
            'expiration' => self::get_expiration() . ' seconds',
            'note' => 'Detailed stats require Redis/Memcached with stats module'
        );
    }
    
    /**
     * Check if form cache exists
     */
    public static function check_form_cache_status( int $form_id ): array {
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
     */
    public static function get_cache_key( int $form_id, string $type = 'config' ): string {
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
     */
    public static function register_hooks(): void {
        add_action( 'save_post_ffc_form', array( __CLASS__, 'on_form_saved' ), 10, 3 );
        add_action( 'before_delete_post', array( __CLASS__, 'on_form_deleted' ), 10, 2 );
        add_action( 'ffcertificate_warm_cache_hook', array( __CLASS__, 'warm_all_forms' ) );
    }
    
    /**
     * Hook callback: Form saved
     */
    public static function on_form_saved( int $post_id, $post, bool $update ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        
        self::clear_form_cache( $post_id );
        
        if ( $post->post_status === 'publish' ) {
            self::warm_cache( $post_id );
        }
    }
    
    /**
     * Hook callback: Form deleted
     */
    public static function on_form_deleted( int $post_id, $post ): void {
        if ( $post && $post->post_type === 'ffc_form' ) {
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
}

// Register hooks on load
add_action( 'init', array( 'FFC_Form_Cache', 'register_hooks' ), 5 );