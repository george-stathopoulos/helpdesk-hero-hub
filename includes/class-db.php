<?php
/**
 * Hub tables.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Connected sites, tickets and the outbox of updates for sites.
 */
final class Helpdesk_Hero_Hub_DB {

	const VERSION = '5';
	const OPTION  = 'helpdesk_hero_hub_db';

	/**
	 * Full table name.
	 *
	 * @param string $name sites | tickets | outbox.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'helpdesk_hero_hub_' . $name;
	}

	/**
	 * Install or upgrade when the stored version is behind.
	 */
	public static function maybe_install() {
		$from = get_option( self::OPTION );
		if ( $from !== self::VERSION ) {
			self::install();
			if ( $from && version_compare( (string) $from, '4', '<' ) ) {
				self::backfill_health();
				self::encrypt_keys();
			}
		}
	}

	/**
	 * Health score for sorting: critical flags count most, then warnings.
	 *
	 * @param array $flags Flags.
	 * @return int
	 */
	public static function health_score( array $flags ) {
		$levels = array_count_values( array_column( $flags, 'level' ) );
		return (int) ( $levels['critical'] ?? 0 ) * 100 + (int) ( $levels['warning'] ?? 0 );
	}

	/**
	 * Encrypt connection keys stored in plain text before version 4.
	 */
	private static function encrypt_keys() {
		global $wpdb;
		$table = self::table( 'sites' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, secret FROM %i WHERE secret <> ''", $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $rows as $row ) {
			if ( 0 !== strpos( (string) $row['secret'], Helpdesk_Hero_Hub_Crypto::PREFIX ) ) {
				$wpdb->update( $table, array( 'secret' => Helpdesk_Hero_Hub_Crypto::store( Helpdesk_Hero_Hub_Crypto::load( $row['secret'] ) ) ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}
		}
	}

	/**
	 * Fill the health column for tickets from before version 4.
	 */
	private static function backfill_health() {
		global $wpdb;
		$table = self::table( 'tickets' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, flags FROM %i WHERE flags IS NOT NULL AND flags <> '[]'", $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $rows as $row ) {
			$flags = (array) json_decode( (string) $row['flags'], true );
			$wpdb->update( $table, array( 'health' => self::health_score( $flags ) ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}

	/**
	 * Create the tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table( 'sites' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			label varchar(190) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL DEFAULT '',
			url varchar(255) NOT NULL DEFAULT '',
			endpoint varchar(255) NOT NULL DEFAULT '',
			contact_email varchar(190) NOT NULL DEFAULT '',
			secret varchar(2000) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			invite_hash varchar(64) NOT NULL DEFAULT '',
			invite_expires datetime NULL,
			policy longtext NULL,
			info longtext NULL,
			tier varchar(40) NOT NULL DEFAULT '',
			notes text NULL,
			created_at datetime NOT NULL,
			last_seen datetime NULL,
			PRIMARY KEY  (id),
			KEY invite_hash (invite_hash),
			KEY status (status),
			KEY tier (tier)
			) $charset;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'tickets' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL DEFAULT 0,
			client_ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
			subject varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'open',
			custom_status varchar(40) NOT NULL DEFAULT '',
			service varchar(40) NOT NULL DEFAULT '',
			priority varchar(10) NOT NULL DEFAULT 'normal',
			health int(11) NOT NULL DEFAULT 0,
			assignee_id bigint(20) unsigned NOT NULL DEFAULT 0,
			merged_into bigint(20) unsigned NOT NULL DEFAULT 0,
			fields longtext NULL,
			respond_due datetime NULL,
			resolve_due datetime NULL,
			sla_paused_at datetime NULL,
			category varchar(60) NOT NULL DEFAULT '',
			customer_name varchar(190) NOT NULL DEFAULT '',
			customer_email varchar(190) NOT NULL DEFAULT '',
			description longtext NULL,
			diagnostics longtext NULL,
			flags longtext NULL,
			helpdesk varchar(20) NOT NULL DEFAULT '',
			helpdesk_id varchar(64) NOT NULL DEFAULT '',
			helpdesk_number varchar(64) NOT NULL DEFAULT '',
			seen_threads longtext NULL,
			access_expires datetime NULL,
			channel varchar(10) NOT NULL DEFAULT 'hub',
			tags longtext NULL,
			rating tinyint(1) unsigned NOT NULL DEFAULT 0,
			rating_comment text NULL,
			rated_at datetime NULL,
			first_response_at datetime NULL,
			closed_at datetime NULL,
			customer_at datetime NULL,
			viewed_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			synced_at datetime NULL,
			PRIMARY KEY  (id),
			KEY site_id (site_id),
			KEY status (status),
			KEY assignee_id (assignee_id),
			KEY created_at (created_at),
			KEY updated_at (updated_at)
			) $charset;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'outbox' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL DEFAULT 0,
			hub_ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(30) NOT NULL DEFAULT '',
			payload longtext NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY site_id (site_id,id),
			KEY hub_ticket_id (hub_ticket_id)
			) $charset;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'attachments' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
			site_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ref varchar(20) NOT NULL DEFAULT '',
			origin varchar(10) NOT NULL DEFAULT 'hub',
			remote_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(190) NOT NULL DEFAULT '',
			size bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(100) NOT NULL DEFAULT '',
			sha256 varchar(64) NOT NULL DEFAULT '',
			path varchar(255) NOT NULL DEFAULT '',
			uploaded_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY ticket_id (ticket_id),
			KEY site_remote (site_id,remote_id)
			) $charset;"
		);
		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * Current UTC time in MySQL format.
	 *
	 * @param int $offset Seconds to add.
	 * @return string
	 */
	public static function now( $offset = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + $offset );
	}
}
