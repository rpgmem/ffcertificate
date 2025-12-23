<?php
/**
 * FFC_Utils
 * Classe de utilitários compartilhada entre Frontend e Admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Utils {

    /**
     * Retorna a lista de tags HTML e atributos permitidos.
     * Centralizamos aqui para que o Frontend, E-mail e Gerador de PDF falem a mesma língua.
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
            // Tags de tabela (essenciais para alinhamento de assinaturas)
            'table'  => array(
                'style'  => array(),
                'class'  => array(),
                'width'  => array(),
                'border' => array(),
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
            // Cabeçalhos
            'h1' => array('style' => array(), 'class' => array()),
            'h2' => array('style' => array(), 'class' => array()),
            'h3' => array('style' => array(), 'class' => array()),
            'h4' => array('style' => array(), 'class' => array()),
            
            // Listas (úteis para conteúdo programático no verso ou corpo)
            'ul' => array('style' => array(), 'class' => array()),
            'ol' => array('style' => array(), 'class' => array()),
            'li' => array('style' => array(), 'class' => array()),
        );

        /**
         * Permite que outros desenvolvedores ou você mesmo adicione tags 
         * sem precisar mexer no core do plugin.
         */
        return apply_filters( 'ffc_allowed_html_tags', $allowed );
    }
}