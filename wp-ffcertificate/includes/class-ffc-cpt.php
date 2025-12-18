<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FFC_CPT
 * * Responsável apenas por registrar o Custom Post Type 'ffc_form'
 * e gerenciar a funcionalidade de duplicação de formulários.
 * * A renderização de Meta Boxes e o salvamento de dados foram movidos 
 * para FFC_Admin e FFC_Admin_UI.
 */
class FFC_CPT {

    public function __construct() {
        // Registra o CPT ao iniciar o WordPress
        add_action( 'init', array( $this, 'register_form_cpt' ) );

        // Adiciona o link "Duplicar" na listagem de posts
        add_filter( 'post_row_actions', array( $this, 'add_duplicate_link' ), 10, 2 );
        
        // Ação para processar a duplicação
        add_action( 'admin_action_ffc_duplicate_form', array( $this, 'handle_form_duplication' ) );
    }
    
    public function register_form_cpt() {
        $labels = array(
            'name'                  => _x( 'WP Free Form Certificates', 'Post Type General Name', 'ffc' ),
            'singular_name'         => _x( 'WP Form', 'Post Type Singular Name', 'ffc' ),
            'menu_name'             => __( 'WP Free Form Certificate', 'ffc' ), // O nome no menu lateral
            'name_admin_bar'        => __( 'WP Certificate Form', 'ffc' ),
            'archives'              => __( 'Form Archives', 'ffc' ),
            'attributes'            => __( 'Form Attributes', 'ffc' ),
            'parent_item_colon'     => __( 'Parent Form:', 'ffc' ),
            'all_items'             => __( 'All Forms', 'ffc' ),
            'add_new_item'          => __( 'Add New Form', 'ffc' ),
            'add_new'               => __( 'Add New', 'ffc' ),
            'new_item'              => __( 'New Form', 'ffc' ),
            'edit_item'             => __( 'Edit Form', 'ffc' ),
            'update_item'           => __( 'Update Form', 'ffc' ),
            'view_item'             => __( 'View Form', 'ffc' ),
            'view_items'            => __( 'View Forms', 'ffc' ),
            'search_items'          => __( 'Search Form', 'ffc' ),
            'not_found'             => __( 'Not found', 'ffc' ),
            'not_found_in_trash'    => __( 'Not found in Trash', 'ffc' ),
            'featured_image'        => __( 'Featured Image', 'ffc' ),
            'set_featured_image'    => __( 'Set featured image', 'ffc' ),
            'remove_featured_image' => __( 'Remove featured image', 'ffc' ),
            'use_featured_image'    => __( 'Use as featured image', 'ffc' ),
            'insert_into_item'      => __( 'Insert into form', 'ffc' ),
            'uploaded_to_this_item' => __( 'Uploaded to this form', 'ffc' ),
            'items_list'            => __( 'Forms list', 'ffc' ),
            'items_list_navigation' => __( 'Forms list navigation', 'ffc' ),
            'filter_items_list'     => __( 'Filter forms list', 'ffc' ),
        );

        $args = array(
            'label'                 => __( 'Form', 'ffc' ),
            'description'           => __( 'Certificate Forms', 'ffc' ),
            'labels'                => $labels,
            'supports'              => array( 'title' ), // Suporta apenas título (os campos são meta boxes)
            'taxonomies'            => array(),
            'hierarchical'          => false,
            'public'                => false, // Não é acessível diretamente via URL frontend (usa shortcode)
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 50,
            'menu_icon'             => 'dashicons-awards', // Ícone de prêmio/certificado
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => false,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false, // Importante: só acessível via shortcode
            'capability_type'       => 'post',
            'rewrite'               => array( 'slug' => 'ffc-form' ),
        );

        register_post_type( 'ffc_form', $args );
    }

    /**
     * Adiciona o link "Duplicar" ao passar o mouse sobre o formulário na lista
     */
    public function add_duplicate_link( $actions, $post ) {
        if ( $post->post_type !== 'ffc_form' ) {
            return $actions;
        }
        
        $url = wp_nonce_url(
            admin_url( 'admin.php?action=ffc_duplicate_form&post=' . $post->ID ),
            'ffc_duplicate_form_nonce'
        );
        
        $actions['duplicate'] = '<a href="' . esc_url( $url ) . '" title="' . esc_attr__( 'Duplicate this form', 'ffc' ) . '">' . __( 'Duplicate', 'ffc' ) . '</a>';
        
        return $actions;
    }

    /**
     * Lógica para duplicar o formulário e todos os seus metadados
     */
    public function handle_form_duplication() {
        if ( ! ( isset( $_GET['post'] ) || isset( $_POST['post'] ) ) || ! isset( $_REQUEST['action'] ) ) {
            wp_die( esc_html__( 'No post to duplicate has been supplied!', 'ffc' ) );
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'You do not have permission to duplicate this post.', 'ffc' ) );
        }

        $post_id = ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : absint( $_POST['post'] ) );
        
        check_admin_referer( 'ffc_duplicate_form_nonce' );

        $post = get_post( $post_id );
        
        if ( ! $post || $post->post_type !== 'ffc_form' ) {
            wp_die( esc_html__( 'Invalid post.', 'ffc' ) );
        }

        $new_post_args = array(
            'post_title'  => sprintf( __( '%s (Copy)', 'ffc' ), $post->post_title ),
            'post_status' => 'draft', // Cria como rascunho por segurança
            'post_type'   => $post->post_type,
            'post_author' => get_current_user_id(),
        );

        $new_post_id = wp_insert_post( $new_post_args );

        if ( is_wp_error( $new_post_id ) ) {
            wp_die( $new_post_id->get_error_message() );
        }

        // Duplica os Meta Fields essenciais
        $fields = get_post_meta( $post_id, '_ffc_form_fields', true );
        $config = get_post_meta( $post_id, '_ffc_form_config', true );

        if ( $fields ) update_post_meta( $new_post_id, '_ffc_form_fields', $fields );
        if ( $config ) update_post_meta( $new_post_id, '_ffc_form_config', $config );

        // Redireciona para a lista
        wp_redirect( admin_url( 'edit.php?post_type=ffc_form' ) );
        exit;
    }
}