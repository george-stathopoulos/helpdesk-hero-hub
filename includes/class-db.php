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

	const VERSION = '2';
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
		if ( get_option( self::OPTION ) !== self::VERSION ) {
			self::install();
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
			secret varchar(128) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			invite_hash varchar(64) NOT NULL DEFAULT '',
			invite_expires datetime NULL,
			policy longtext NULL,
			info longtext NULL,
			created_at datetime NOT NULL,
			last_seen datetime NULL,
			PRIMARY KEY  (id),
			KEY invite_hash (invite_hash),
			KEY status (status)
			) $charset;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'tickets' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL DEFAULT 0,
			client_ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
			subject varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'open',
			priority varchar(10) NOT NULL DEFAULT 'normal',
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
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			synced_at datetime NULL,
			PRIMARY KEY  (id),
			KEY site_id (site_id),
			KEY status (status),
			KEY created_at (created_at)
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
