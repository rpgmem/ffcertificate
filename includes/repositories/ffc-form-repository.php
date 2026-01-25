<?php
/**
 * Form Repository
 * Handles form metadata queries
 *
 * @since 3.0.0
 * @version 3.3.0 - Added strict types and type hints for better code safety
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/ffc-abstract-repository.php';

class FFC_Form_Repository extends FFC_Abstract_Repository {
    
    protected function get_table_name(): string {
        return $this->wpdb->posts;
    }

    protected function get_cache_group(): string {
        return 'ffc_forms';
    }
    
    /**
     * Find published forms
     *
     * @param int $limit
     * @return array
     */
    public function findPublished( int $limit = -1 ): array {
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
     *
     * @param int $form_id
     * @return mixed
     */
    public function getConfig( int $form_id ) {
        if (class_exists('FFC_Form_Cache')) {
            return FFC_Form_Cache::get_form_config($form_id);
        }
        
        return get_post_meta($form_id, '_ffc_form_config', true);
    }
    
    /**
     * Get form fields
     *
     * @param int $form_id
     * @return mixed
     */
    public function getFields( int $form_id ) {
        if (class_exists('FFC_Form_Cache')) {
            return FFC_Form_Cache::get_form_fields($form_id);
        }
        
        return get_post_meta($form_id, '_ffc_form_fields', true);
    }
    
    /**
     * Get form background
     *
     * @param int $form_id
     * @return mixed
     */
    public function getBackground( int $form_id ) {
        if (class_exists('FFC_Form_Cache')) {
            return FFC_Form_Cache::get_form_background($form_id);
        }
        
        return get_post_meta($form_id, '_ffc_form_bg', true);
    }
}