<?php
/**
 * FFC_Utils
 * Utility class shared between Frontend and Admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Utils {

    /**
     * Returns the list of allowed HTML tags and attributes.
     * Centralized here so Frontend, Email, and PDF Generator use the same validation rules.
     */
    public static function get_allowed_html_tags() {
        $allowed = array(
            'b'      => array(),
            'strong' => array(),
            'i'      => array(),
            'em'     => array(),
            'u'      => array(),
            'br'     => array(),
            'hr'     => array(
                'style' => array(),
                'class' => array(),
            ),
            'p'      => array(
                'style' => array(),
                'class' => array(),
                'align' => array(),
            ),
            'span'   => array(
                'style' => array(),
                'class' => array(),
            ),
            'div'    => array(
                'style' => array(),
                'class' => array(),
                'id'    => array(),
            ),
            'font'   => array(
                'color' => array(),
                'size'  => array(),
                'face'  => array(),
            ),
            'img'    => array(
                'src'    => array(),
                'alt'    => array(),
                'style'  => array(),
                'width'  => array(),
                'height' => array(),
            ),
            // Table tags (essential for signature alignment)
            'table'  => array(
                'style'       => array(),
                'class'       => array(),
                'width'       => array(),
                'border'      => array(),
                'cellpadding' => array(),
                'cellspacing' => array(),
            ),
            'tr'     => array(
                'style' => array(),
                'class' => array(),
            ),
            'td'     => array(
                'style'   => array(),
                'width'   => array(),
                'colspan' => array(),
                'rowspan' => array(),
                'align'   => array(),
                'valign'  => array(),
            ),
            // Headings
            'h1' => array('style' => array(), 'class' => array()),
            'h2' => array('style' => array(), 'class' => array()),
            'h3' => array('style' => array(), 'class' => array()),
            'h4' => array('style' => array(), 'class' => array()),
            
            // Lists (useful for syllabus content on the back or body)
            'ul' => array('style' => array(), 'class' => array()),
            'ol' => array('style' => array(), 'class' => array()),
            'li' => array('style' => array(), 'class' => array()),
        );

        /**
         * Allows developers to filter or add new tags 
         * without modifying the plugin core.
         */
        return apply_filters( 'ffc_allowed_html_tags', $allowed );
    }
}