<?php
/**
 * Form Repository
 * Handles form metadata queries
 * 
 * @since 3.0.0
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/abstract-repository.php';

class FFC_Form_Repository extends FFC_Abstract_Repository {
    
    protected function get_table_name() {
        return $this->wpdb->posts;
    }
    
    protected function get_cache_group() {
        return 'ffc_forms';
    }
    
    /**
     * Find published forms
     */
    public function findPublished($limit = -1) {
        $args = [
            'post_type' => 'ffc_form',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'title',
            'order' => 'ASC'
        ];
        
        return get_posts($args);
    }
    
    /**
     * Get form config
     */
    public function getConfig($form_id) {
        if (class_exists('FFC_Form_Cache')) {
            return FFC_Form_Cache::get_form_config($form_id);
        }
        
        return get_post_meta($form_id, '_ffc_form_config', true);
    }
    
    /**
     * Get form fields
     */
    public function getFields($form_id) {
        if (class_exists('FFC_Form_Cache')) {
            return FFC_Form_Cache::get_form_fields($form_id);
        }
        
        return get_post_meta($form_id, '_ffc_form_fields', true);
    }
    
    /**
     * Get form background
     */
    public function getBackground($form_id) {
        if (class_exists('FFC_Form_Cache')) {
            return FFC_Form_Cache::get_form_background($form_id);
        }
        
        return get_post_meta($form_id, '_ffc_form_bg', true);
    }
}