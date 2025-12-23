<?php
/**
 * FFC_Activator
 * Manages plugin installation: Tables, Pages, Initial Forms, and Settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Activator {

    /**
     * Run the activation logic
     */
    public static function activate() {
        // 1. Create Tables
        self::create_tables();

        // 2. Create Validation Page
        self::create_validation_page();

        // 3. Create Default Form
        self::create_default_form();

        // 4. Set Initial Options
        self::set_default_options();

        // 5. Update Permalinks (Ensures /valid works immediately)
        flush_rewrite_rules();
    }

    /**
     * Manages the creation of the submissions table
     */
    private static function create_tables() {
        global $wpdb;
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        
        $table_name = $wpdb->prefix . 'ffc_submissions';

        $sql_submissions = "CREATE TABLE {$table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            form_id mediumint(9) NOT NULL,
            submission_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            data longtext NOT NULL,
            user_ip varchar(100) NOT NULL,
            email varchar(255) NOT NULL,
            status varchar(20) DEFAULT 'publish' NOT NULL,
            PRIMARY KEY  (id),
            KEY form_id (form_id),
            KEY email (email),
            KEY submission_date (submission_date),
            KEY status (status)
        ) {$wpdb->get_charset_collate()};";

        dbDelta( $sql_submissions );
        
        // Manual check for 'status' column (extra safety)
        $column = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'status'", 
            DB_NAME, $table_name
        ));

        if ( empty( $column ) ) {
            $wpdb->query("ALTER TABLE $table_name ADD status varchar(20) DEFAULT 'publish' NOT NULL");
        }

        update_option( 'ffc_db_version', '1.2' );
    }

    /**
     * Creates the /valid page with the required shortcode
     */
    private static function create_validation_page() {
        $slug = 'valid';
        $shortcode = '[ffc_verification]';
        
        $page_check = get_page_by_path($slug);

        if ( ! isset( $page_check->ID ) ) {
            // If page doesn't exist, create it from scratch
            wp_insert_post( array(
                'post_title'    => __( 'Certificate Validation', 'ffc' ),
                'post_content'  => $shortcode,
                'post_status'   => 'publish',
                'post_type'     => 'page',
                'post_name'     => $slug
            ) );
        } else {
            // If page exists, ensures the shortcode is present
            if ( strpos( $page_check->post_content, $shortcode ) === false ) {
                $page_check->post_content .= "\n" . $shortcode;
                wp_update_post( $page_check );
            }
        }
    }

    /**
     * Creates an initial form so the user doesn't start from scratch
     */
    private static function create_default_form() {
        $forms_query = new WP_Query( array( 
            'post_type'      => 'ffc_form', 
            'posts_per_page' => 1,
            'post_status'    => 'any' 
        ) );

        if ( ! $forms_query->have_posts() ) {
            $form_id = wp_insert_post( array(
                'post_title'   => __( 'Example Certificate', 'ffc' ),
                'post_status'  => 'publish',
                'post_type'    => 'ffc_form',
                'post_content' => __( 'This is an automatically generated form by the plugin.', 'ffc' )
            ) );

            if ( $form_id ) {
                // Default layout with dynamic variables
                $layout = '
                <div style="border:10px solid #2c3e50; padding:40px; text-align:center; font-family: Arial, sans-serif;">
                    <h1 style="color:#2c3e50; font-size:42px;">' . __( 'CERTIFICATE', 'ffc' ) . '</h1>
                    <p style="font-size:20px;">' . __( 'This certificate confirms that', 'ffc' ) . '</p>
                    <h2 style="font-size:32px; color:#e67e22;">{{name}}</h2>
                    <p style="font-size:18px;">' . __( 'has successfully completed the process on', 'ffc' ) . ' {{submission_date}}.</p>
                    <div style="margin-top:60px; padding-top:20px; border-top:1px solid #eee;">
                        <p style="margin:0;">' . __( 'Authenticity Code', 'ffc' ) . ': <strong>{{auth_code}}</strong></p>
                        <p style="font-size:12px; color:#7f8c8d;">' . __( 'Validate this document at', 'ffc' ) . ': {{validation_url}}</p>
                    </div>
                </div>';

                update_post_meta( $form_id, '_ffc_form_config', array(
                    'pdf_layout'      => $layout,
                    'email_subject'   => __( 'Your Certificate of Completion', 'ffc' ),
                    'send_user_email' => 1
                ) );
            }
        }
    }

    /**
     * Sets initial global settings if they do not exist
     */
    private static function set_default_options() {
        $settings = get_option( 'ffc_settings' );
        
        if ( ! $settings ) {
            update_option( 'ffc_settings', array( 
                'smtp_mode'    => 'wp', // Default to WordPress mail
                'cleanup_days' => 30 
            ) );
        }
    }
}