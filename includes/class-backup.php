<?php
/**
 * Backup and restore of the whole hub as one JSON file.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exports settings, policy, templates, tags, sites (with their connection keys), tickets and their
 * history, and restores them on this or another install. Row IDs are kept, because connected
 * sites refer to them (their site ID, ticket IDs and the position of the last update they got),
 * so a restored hub at the same address carries on where it left off.
 */
final class Helpdesk_Hero_Hub_Backup {

	const FORMAT  = 'helpdesk-hero-hub-backup';
	const VERSION = 1;

	/**
	 * Columns restored per table (anything else in a file is ignored).
	 *
	 * @return array<string, string[]>
	 */
	public static function columns() {
		return array(
			'sites'   => array( 'id', 'label', 'name', 'url', 'endpoint', 'contact_email', 'secret', 'status', 'invite_hash', 'invite_expires', 'policy', 'info', 'created_at', 'last_seen' ),
			'tickets' => array( 'id', 'site_id', 'client_ticket_id', 'subject', 'status', 'priority', 'category', 'customer_name', 'customer_email', 'description', 'diagnostics', 'flags', 'helpdesk', 'helpdesk_id', 'helpdesk_number', 'seen_threads', 'access_expires', 'channel', 'tags', 'rating', 'rating_comment', 'rated_at', 'first_response_at', 'closed_at', 'created_at', 'updated_at', 'synced_at' ),
			'outbox'  => array( 'id', 'site_id', 'hub_ticket_id', 'type', 'payload', 'created_by', 'created_at' ),
		);
	}

	/**
	 * Options in a backup. Secret ones are left out unless asked for.
	 *
	 * @return array<string, bool> Option => is secret.
	 */
	public static function options() {
		return array(
			Helpdesk_Hero_Hub_Settings::OPTION  => false,
			Helpdesk_Hero_Hub_Settings::SECRETS => true,
			Helpdesk_Hero_Hub_Policy::OPTION    => false,
			Helpdesk_Hero_Hub_Templates::OPTION => false,
			Helpdesk_Hero_Hub_Tags::OPTION      => false,
		);
	}

	/**
	 * The backup.
	 *
	 * @param bool $secrets Include connection keys and help desk keys (needed for sites to reconnect on their own).
	 * @return array
	 */
	public static function export( $secrets = true ) {
		global $wpdb;
		$options = array();
		foreach ( self::options() as $name => $is_secret ) {
			if ( $secrets || ! $is_secret ) {
				$options[ $name ] = get_option( $name, null );
			}
		}
		$tables = array();
		foreach ( self::columns() as $table => $columns ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', Helpdesk_Hero_Hub_DB::table( $table ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $rows as &$row ) {
				$row = array_intersect_key( $row, array_flip( $columns ) );
				if ( ! $secrets && 'sites' === $table ) {
					$row['secret']      = '';
					$row['invite_hash'] = '';
				}
			}
			$tables[ $table ] = $rows;
		}
		$data = array(
			'format'   => self::FORMAT,
			'version'  => self::VERSION,
			'plugin'   => HELPDESK_HERO_HUB_VERSION,
			'created'  => gmdate( 'c' ),
			'home'     => home_url( '/' ),
			'rest'     => get_rest_url(),
			'secrets'  => (bool) $secrets,
			'options'  => $options,
			'tables'   => $tables,
			'addons'   => array(),
		);
		/**
		 * Filters the hub backup, so add-ons can include their own data under addons.
		 *
		 * @param array $data    Backup.
		 * @param bool  $secrets Whether secrets are included.
		 */
		return (array) apply_filters( 'helpdesk_hero_hub_backup', $data, (bool) $secrets );
	}

	/**
	 * Restore a backup. Everything in the hub is replaced.
	 *
	 * @param mixed $data Decoded backup file.
	 * @return array|WP_Error Summary: sites, tickets, history, moved (the hub's address changed).
	 */
	public static function import( $data ) {
		global $wpdb;
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? '' ) || ! isset( $data['tables'] ) || ! is_array( $data['tables'] ) ) {
			return new WP_Error( 'helpdesk_hero_hub_backup', __( 'This is not a Helpdesk Hero Hub backup file.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		if ( (int) ( $data['version'] ?? 0 ) > self::VERSION ) {
			return new WP_Error( 'helpdesk_hero_hub_backup', __( 'This backup was made by a newer version of Helpdesk Hero Hub. Update the plugin, then import it again.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}

		Helpdesk_Hero_Hub_DB::maybe_install();
		$counts = array();
		foreach ( self::columns() as $table => $columns ) {
			$name = Helpdesk_Hero_Hub_DB::table( $table );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$counts[ $table ] = 0;
			foreach ( (array) ( $data['tables'][ $table ] ?? array() ) as $row ) {
				if ( ! is_array( $row ) || empty( $row['id'] ) ) {
					continue;
				}
				$row = array_intersect_key( $row, array_flip( $columns ) );
				foreach ( $row as $key => $value ) {
					// Values are stored as they were exported: strings, numbers or NULL.
					$row[ $key ] = null === $value ? null : ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
				}
				if ( false !== $wpdb->insert( $name, $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					++$counts[ $table ];
				}
			}
		}

		foreach ( self::options() as $option => $is_secret ) {
			if ( ! array_key_exists( $option, (array) ( $data['options'] ?? array() ) ) ) {
				continue;
			}
			$value = $data['options'][ $option ];
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, self::clean_option( $option, $value ), false );
			}
		}
		update_option( Helpdesk_Hero_Hub_Admin::ONBOARDING, 'done', false );

		/**
		 * Fires after a backup is restored, so add-ons can restore their data from addons.
		 *
		 * @param array $data Backup.
		 */
		do_action( 'helpdesk_hero_hub_restore', $data );

		Helpdesk_Hero_Hub::schedule();
		return array(
			'sites'   => $counts['sites'],
			'tickets' => $counts['tickets'],
			'history' => $counts['outbox'],
			'secrets' => ! empty( $data['secrets'] ),
			'moved'   => isset( $data['rest'] ) && untrailingslashit( (string) $data['rest'] ) !== untrailingslashit( get_rest_url() ),
		);
	}

	/**
	 * Sanitize a restored option through the plugin's own rules.
	 *
	 * @param string $option Option.
	 * @param mixed  $value  Value.
	 * @return mixed
	 */
	private static function clean_option( $option, $value ) {
		switch ( $option ) {
			case Helpdesk_Hero_Hub_Policy::OPTION:
				return Helpdesk_Hero_Hub_Policy::sanitize( (array) $value );
			case Helpdesk_Hero_Hub_Settings::OPTION:
				return array_intersect_key( array_map( 'sanitize_text_field', array_filter( (array) $value, 'is_scalar' ) ), Helpdesk_Hero_Hub_Settings::defaults() );
			case Helpdesk_Hero_Hub_Settings::SECRETS:
				return array_map( 'strval', array_filter( (array) $value, 'is_scalar' ) );
			case Helpdesk_Hero_Hub_Templates::OPTION:
				$list = array();
				foreach ( (array) $value as $tpl ) {
					if ( is_array( $tpl ) && ! empty( $tpl['id'] ) && ! empty( $tpl['name'] ) ) {
						$list[] = array(
							'id'          => sanitize_key( $tpl['id'] ),
							'name'        => sanitize_text_field( $tpl['name'] ),
							'description' => sanitize_textarea_field( (string) ( $tpl['description'] ?? '' ) ),
							'policy'      => Helpdesk_Hero_Hub_Policy::sanitize( (array) ( $tpl['policy'] ?? array() ) ),
						);
					}
				}
				return $list;
			case Helpdesk_Hero_Hub_Tags::OPTION:
				$tags = array();
				foreach ( (array) $value as $tag ) {
					if ( is_array( $tag ) && ! empty( $tag['id'] ) && ! empty( $tag['name'] ) ) {
						$color  = sanitize_hex_color( (string) ( $tag['color'] ?? '' ) );
						$tags[] = array(
							'id'    => sanitize_key( $tag['id'] ),
							'name'  => sanitize_text_field( $tag['name'] ),
							'color' => $color ? $color : '#6b6963',
						);
					}
				}
				return $tags;
		}
		return $value;
	}
}
