<?php
/**
 * Ticket tags.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tags the team creates and adds to tickets. Customers see a ticket's tags in their dashboard.
 */
final class Helpdesk_Hero_Hub_Tags {

	const OPTION = 'helpdesk_hero_hub_tags';

	/**
	 * Colours offered for tags.
	 *
	 * @return string[]
	 */
	public static function colors() {
		return array( '#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#4a3aa7', '#e34948', '#6b6963' );
	}

	/**
	 * All tags.
	 *
	 * @return array[] { id, name, color }
	 */
	public static function all() {
		$tags = get_option( self::OPTION, null );
		if ( null === $tags ) {
			$tags = array(
				array(
					'id'    => 'bug',
					'name'  => __( 'Bug', 'helpdesk-hero-hub' ),
					'color' => '#e34948',
				),
				array(
					'id'    => 'conflict',
					'name'  => __( 'Plugin conflict', 'helpdesk-hero-hub' ),
					'color' => '#eb6834',
				),
				array(
					'id'    => 'how-to',
					'name'  => __( 'How-to', 'helpdesk-hero-hub' ),
					'color' => '#2a78d6',
				),
				array(
					'id'    => 'hosting',
					'name'  => __( 'Hosting', 'helpdesk-hero-hub' ),
					'color' => '#6b6963',
				),
			);
		}
		return is_array( $tags ) ? array_values( $tags ) : array();
	}

	/**
	 * Save all tags (replaces the list) and refresh tickets that use a changed tag.
	 *
	 * @param array $tags Tags.
	 * @return array[] Saved tags.
	 */
	public static function save( array $tags ) {
		$colors = self::colors();
		$clean  = array();
		foreach ( $tags as $tag ) {
			$name = trim( sanitize_text_field( (string) ( $tag['name'] ?? '' ) ) );
			if ( '' === $name ) {
				continue;
			}
			$id    = sanitize_key( (string) ( $tag['id'] ?? '' ) );
			$id    = '' !== $id ? $id : sanitize_title( $name );
			$color = sanitize_hex_color( (string) ( $tag['color'] ?? '' ) );
			while ( isset( $clean[ $id ] ) ) {
				$id .= '-2';
			}
			$clean[ $id ] = array(
				'id'    => $id,
				'name'  => substr( $name, 0, 40 ),
				'color' => $color ? $color : $colors[ count( $clean ) % count( $colors ) ],
			);
		}
		$before = array_column( self::all(), null, 'id' );
		update_option( self::OPTION, array_values( $clean ), false );
		// Tickets whose tags were renamed, recoloured or deleted show the new tags to customers.
		$changed = array();
		foreach ( $before as $id => $tag ) {
			if ( ! isset( $clean[ $id ] ) || $clean[ $id ]['name'] !== $tag['name'] || $clean[ $id ]['color'] !== $tag['color'] ) {
				$changed[] = $id;
			}
		}
		if ( $changed ) {
			self::refresh_tickets( $changed );
		}
		return self::all();
	}

	/**
	 * Tag details for IDs (unknown IDs are skipped).
	 *
	 * @param string[] $ids IDs.
	 * @return array[]
	 */
	public static function resolve( array $ids ) {
		$by_id = array_column( self::all(), null, 'id' );
		$out   = array();
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$out[] = array(
					'id'    => $by_id[ $id ]['id'],
					'name'  => $by_id[ $id ]['name'],
					'color' => $by_id[ $id ]['color'],
				);
			}
		}
		return $out;
	}

	/**
	 * Re-send tags for tickets that use any of these tags.
	 *
	 * @param string[] $tag_ids Tags.
	 */
	private static function refresh_tickets( array $tag_ids ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, site_id, tags FROM %i WHERE tags IS NOT NULL AND tags <> '[]'", Helpdesk_Hero_Hub_DB::table( 'tickets' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $rows as $row ) {
			$ids = (array) json_decode( (string) $row['tags'], true );
			if ( array_intersect( $ids, $tag_ids ) ) {
				$keep = array_values( array_intersect( $ids, wp_list_pluck( self::all(), 'id' ) ) );
				Helpdesk_Hero_Hub::update_ticket( (int) $row['id'], array( 'tags' => $keep ) );
				Helpdesk_Hero_Hub::enqueue( (int) $row['site_id'], (int) $row['id'], 'tags', array( 'tags' => self::resolve( $keep ) ) );
			}
		}
	}
}
