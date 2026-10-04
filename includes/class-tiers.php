<?php
/**
 * Customer tiers.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tiers the team defines for its customers ("VIP", "Pro customer"…), each with a colour and a
 * short description. Their order is their importance: the first tier is the most important.
 * Each site has at most one tier. Tickets show their site's tier and can be sorted by it.
 *
 * Add-ons can store more on a tier (Helpdesk Hero Pro adds a service level agreement) through
 * the helpdesk_hero_hub_tier filter.
 */
final class Helpdesk_Hero_Hub_Tiers {

	const OPTION = 'helpdesk_hero_hub_tiers';

	/**
	 * All tiers, most important first.
	 *
	 * @return array[] { id, name, color, description, ... }
	 */
	public static function all() {
		$tiers = get_option( self::OPTION, array() );
		return is_array( $tiers ) ? array_values( $tiers ) : array();
	}

	/**
	 * One tier.
	 *
	 * @param string $id Tier ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		if ( '' === (string) $id ) {
			return null;
		}
		foreach ( self::all() as $tier ) {
			if ( $tier['id'] === $id ) {
				return $tier;
			}
		}
		return null;
	}

	/**
	 * Save all tiers (replaces the list, in order). Sites with a deleted tier lose it.
	 *
	 * @param array $tiers Tiers.
	 * @return array[] Saved tiers.
	 */
	public static function save( array $tiers ) {
		global $wpdb;
		$clean   = self::sanitize( $tiers );
		$removed = array_diff( wp_list_pluck( self::all(), 'id' ), wp_list_pluck( $clean, 'id' ) );
		update_option( self::OPTION, $clean, false );
		foreach ( $removed as $id ) {
			$wpdb->update( Helpdesk_Hero_Hub_DB::table( 'sites' ), array( 'tier' => '' ), array( 'tier' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		/**
		 * Fires after tiers are saved (Pro recalculates SLA deadlines).
		 *
		 * @param array[] $tiers Tiers.
		 */
		do_action( 'helpdesk_hero_hub_tiers_saved', self::all() );
		// A tier's policy template may have changed: sites get their policy again.
		Helpdesk_Hero_Hub_Policy::push_all();
		return self::all();
	}

	/**
	 * Clean a list of tiers.
	 *
	 * @param array $tiers Tiers as sent.
	 * @return array[]
	 */
	public static function sanitize( array $tiers ) {
		$colors = Helpdesk_Hero_Hub_Tags::colors();
		$clean  = array();
		foreach ( $tiers as $tier ) {
			$name = trim( sanitize_text_field( (string) ( $tier['name'] ?? '' ) ) );
			if ( '' === $name ) {
				continue;
			}
			$id = sanitize_key( (string) ( $tier['id'] ?? '' ) );
			$id = '' !== $id ? $id : sanitize_title( $name );
			$id = substr( '' !== $id ? $id : 'tier', 0, 40 );
			while ( isset( $clean[ $id ] ) ) {
				$id = substr( $id, 0, 37 ) . '-' . wp_rand( 10, 99 );
			}
			$color = sanitize_hex_color( (string) ( $tier['color'] ?? '' ) );
			$item  = array(
				'id'          => $id,
				'name'        => substr( $name, 0, 40 ),
				'color'       => $color ? $color : $colors[ count( $clean ) % count( $colors ) ],
				'description' => substr( sanitize_textarea_field( (string) ( $tier['description'] ?? '' ) ), 0, 300 ),
				// Policy template for sites in this tier (unless a site has its own policy).
				'policy'      => Helpdesk_Hero_Hub_Templates::get( sanitize_key( (string) ( $tier['policy'] ?? '' ) ) ) ? sanitize_key( (string) $tier['policy'] ) : '',
			);
			/**
			 * Filters a tier before it is saved. Add-ons keep their own fields here.
			 *
			 * @param array $item Clean tier.
			 * @param array $tier Tier as sent.
			 */
			$clean[ $id ] = (array) apply_filters( 'helpdesk_hero_hub_tier', $item, (array) $tier );
		}
		return array_values( $clean );
	}

	/**
	 * Tier summary for lists (or null).
	 *
	 * @param string $id Tier ID.
	 * @return array|null { id, name, color, rank }
	 */
	public static function badge( $id ) {
		foreach ( self::all() as $rank => $tier ) {
			if ( $tier['id'] === $id ) {
				return array(
					'id'          => $tier['id'],
					'name'        => $tier['name'],
					'color'       => $tier['color'],
					'description' => $tier['description'],
					'rank'        => $rank + 1,
				);
			}
		}
		return null;
	}
}
