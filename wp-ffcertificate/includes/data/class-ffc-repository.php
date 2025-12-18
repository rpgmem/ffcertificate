<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FFC_Submission_Repository
 * * Camada de abstração do Banco de Dados.
 * Centraliza todo o SQL para garantir segurança e desacoplamento.
 */
class FFC_Submission_Repository {

    private $table_name;
    private $db;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->table_name = $wpdb->prefix . 'ffc_submissions';
    }

    /**
     * Busca submissões com filtros complexos (usado no Admin List Table).
     * * @param array $args Argumentos de filtro (status, search, orderby, limit, offset, form_id).
     * @return array Lista de submissões.
     */
    public function find_all( $args = array() ) {
        $defaults = array(
            'status'  => 'publish',
            'form_id' => '',
            'search'  => '',
            'orderby' => 'submission_date',
            'order'   => 'DESC',
            'limit'   => 20,
            'offset'  => 0,
        );

        $args = wp_parse_args( $args, $defaults );

        // 1. Constrói o WHERE (reutilizável)
        $where_query = $this->_build_where_query( $args );
        $sql = "SELECT * FROM {$this->table_name} {$where_query['sql']}";
        $params = $where_query['params'];

        // 2. Ordenação (Allowlist para segurança)
        $allowed_orderby = array( 'id', 'submission_date', 'email' );
        $orderby = in_array( $args['orderby'], $allowed_orderby ) ? $args['orderby'] : 'submission_date';
        $order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
        
        // Nota: Order By e Order são inseridos diretamente pois são validados acima (prepare não aceita nomes de colunas)
        $sql .= " ORDER BY $orderby $order";

        // 3. Limite e Offset
        if ( $args['limit'] > 0 ) {
            $sql .= " LIMIT %d OFFSET %d";
            $params[] = $args['limit'];
            $params[] = $args['offset'];
        }

        // 4. Execução
        if ( ! empty( $params ) ) {
            return $this->db->get_results( $this->db->prepare( $sql, $params ), ARRAY_A );
        } else {
            return $this->db->get_results( $sql, ARRAY_A );
        }
    }

    /**
     * Conta o total de itens baseados nos filtros (para paginação).
     * * @param array $args Mesmos argumentos de filtro do find_all.
     * @return int Total de registros.
     */
    public function count( $args = array() ) {
        $defaults = array(
            'status'  => 'publish',
            'form_id' => '',
            'search'  => ''
        );
        $args = wp_parse_args( $args, $defaults );

        $where_query = $this->_build_where_query( $args );
        
        $sql = "SELECT COUNT(id) FROM {$this->table_name} {$where_query['sql']}";
        
        if ( ! empty( $where_query['params'] ) ) {
            return (int) $this->db->get_var( $this->db->prepare( $sql, $where_query['params'] ) );
        } else {
            return (int) $this->db->get_var( $sql );
        }
    }

    /**
     * Auxiliar privado para montar a cláusula WHERE.
     * Evita duplicação de lógica entre find_all e count.
     */
    private function _build_where_query( $args ) {
        $conditions = array();
        $params = array();

        // Filtro de Status
        if ( ! empty( $args['status'] ) ) {
            $conditions[] = "status = %s";
            $params[] = $args['status'];
        }

        // Filtro de Formulário
        if ( ! empty( $args['form_id'] ) ) {
            $conditions[] = "form_id = %d";
            $params[] = absint( $args['form_id'] );
        }

        // Busca (Email ou JSON Data)
        if ( ! empty( $args['search'] ) ) {
            $search_term = '%' . $this->db->esc_like( $args['search'] ) . '%';
            $conditions[] = "(email LIKE %s OR data LIKE %s)";
            $params[] = $search_term;
            $params[] = $search_term;
        }

        $sql = '';
        if ( ! empty( $conditions ) ) {
            $sql = 'WHERE ' . implode( ' AND ', $conditions );
        }

        return array( 'sql' => $sql, 'params' => $params );
    }

    /**
     * Insere uma nova submissão.
     */
    public function insert( $form_id, $data, $email, $ip ) {
        if ( is_array( $data ) ) {
            $data = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
        }

        $inserted = $this->db->insert(
            $this->table_name,
            array(
                'form_id'         => $form_id,
                'submission_date' => current_time( 'mysql' ),
                'data'            => $data,
                'user_ip'         => $ip,
                'email'           => $email,
                'status'          => 'publish' // Definindo explicitamente o padrão
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        return $inserted ? $this->db->insert_id : false;
    }

    /**
     * Atualiza os dados (JSON) de uma submissão.
     */
    public function update( $submission_id, $data ) {
        if ( is_array( $data ) ) {
            $data = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
        }

        return $this->db->update(
            $this->table_name,
            array( 'data' => $data ),
            array( 'id' => $submission_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /**
     * Move para a Lixeira (Soft Delete).
     */
    public function trash( $submission_id ) {
        return $this->db->update(
            $this->table_name,
            array( 'status' => 'trash' ),
            array( 'id' => $submission_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /**
     * Restaura da Lixeira.
     */
    public function restore( $submission_id ) {
        return $this->db->update(
            $this->table_name,
            array( 'status' => 'publish' ),
            array( 'id' => $submission_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /**
     * Deleta permanentemente (Hard Delete).
     */
    public function delete( $submission_id ) {
        return $this->db->delete(
            $this->table_name,
            array( 'id' => $submission_id ),
            array( '%d' )
        );
    }

    public function get_by_id( $id ) {
        return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table_name} WHERE id = %d", $id ), ARRAY_A );
    }

    // Mantido para compatibilidade, mas find_all poderia substituir
    public function get_by_form_id( $form_id ) {
        return $this->db->get_results( $this->db->prepare( 
            "SELECT * FROM {$this->table_name} WHERE form_id = %d AND status = 'publish' ORDER BY submission_date DESC", 
            $form_id 
        ), ARRAY_A );
    }

    public function cleanup_old_submissions( $days ) {
        $days = intval( $days );
        if ( $days <= 0 ) return;

        $date_cutoff = date( 'Y-m-d H:i:s', strtotime( "-$days days" ) );

        $this->db->query( $this->db->prepare( 
            "DELETE FROM {$this->table_name} WHERE submission_date < %s", 
            $date_cutoff 
        ) );
    }

    public function get_table_name() {
        return $this->table_name;
    }
}