<?php
/**
 * URL Shortener Activator
 *
 * Creates and migrates the ffc_short_urls database table.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since 5.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Core\DatabaseHelperTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin activation tasks for url shortener.
 */
class UrlShortenerActivator {

	use DatabaseHelperTrait;

	/**
	 * Get the short URLs table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'ffc_short_urls';
	}

	/**
	 * Create the short URLs table.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		if ( self::table_exists( $table_name ) ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            short_code varchar(10) NOT NULL,
            target_url text NOT NULL,
            post_id bigint(20) unsigned DEFAULT NULL,
            title varchar(255) DEFAULT '',
            click_count bigint(20) unsigned DEFAULT 0,
            created_by bigint(20) unsigned DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            status varchar(20) DEFAULT 'active',
            qr_cache longtext NULL COMMENT 'Cached QR code payload',
            PRIMARY KEY (id),
            UNIQUE KEY idx_short_code (short_code),
            KEY idx_post_id (post_id),
            KEY idx_status (status)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Run on plugins_loaded to handle schema updates.
	 */
	/**
	 * Guarda por versao: a cadeia abaixo so precisa rodar uma vez por
	 * `FFC_VERSION` (#1231).
	 *
	 * Sem ela, `UrlShortenerActivator::maybe_migrate()` sondava o schema a CADA requisicao -- frontend anonimo
	 * incluido -- porque `table_exists()` e um `SHOW TABLES LIKE` sem cache e
	 * todo `add_column_if_missing()` dispara um `SHOW COLUMNS` antes de
	 * decidir nao fazer nada. Somadas as quatro cadeias do `Loader`, eram 48
	 * queries DDL por pagina numa instalacao sem nada a migrar.
	 *
	 * **A guarda e `FFC_VERSION`, e NAO um marcador one-shot, de proposito.**
	 * Estas chamadas existem porque um update in-place do plugin (o botao
	 * "Atualizar" do wp-admin) NAO dispara `register_activation_hook` -- a
	 * propriedade a preservar e "o schema se cura depois de um update", nao
	 * "roda a cada request". Com `FFC_VERSION` a constante muda no update e a
	 * cadeia roda uma vez no primeiro request seguinte, identica ao que fazia
	 * antes. Com um booleano one-shot, uma coluna introduzida numa release
	 * futura nunca alcancaria quem ja tivesse o marcador gravado.
	 *
	 * A opcao e escrita **depois** do corpo, para que uma falha no meio nao
	 * trave a cadeia numa versao que ela nao chegou a aplicar.
	 */
	public static function maybe_migrate(): void {
		// Guarda por versao (#1231) -- ver a nota logo acima da assinatura.
		$ffc_schema_option = 'ffc_url_shortener_schema_version';
		if ( get_option( $ffc_schema_option, '' ) === FFC_VERSION ) {
			return;
		}

		$table_name = self::get_table_name();

		if ( ! self::table_exists( $table_name ) ) {
			self::create_tables();
		}

		// Add qr_cache column for QR code caching (avoids regeneration on every admin load).
		self::add_column_if_missing( $table_name, 'qr_cache', 'LONGTEXT NULL', 'status' );

		update_option( $ffc_schema_option, FFC_VERSION );
	}
}
