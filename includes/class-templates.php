<?php
/**
 * Saved support policy templates.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Named policies the team can apply to sites one by one or in bulk. A site that uses a template
 * follows it: editing the template updates every site that uses it.
 *
 * A site's policy column holds null (team default), { "template": id } or a full custom policy.
 */
final class Helpdesk_Hero_Hub_Templates {

	const OPTION = 'helpdesk_hero_hub_templates';

	/**
	 * Starter templates (saved on first use, then fully editable).
	 *
	 * @return array[]
	 */
	public static function starters() {
		$d = Helpdesk_Hero_Hub_Policy::defaults();

		$hands_off                   = $d;
		$hands_off['access']['mode'] = 'off';

		$strict                                = $d;
		$strict['access']['default_hours']     = 4;
		$strict['access']['max_hours']         = 24;
		$strict['access']['customer_duration'] = false;
		$strict['access']['customer_extend']   = false;
		$strict['diagnostics']['debug_log']    = 'on';
		$strict['diagnostics']['errors']       = 'required';

		$full                                 = $d;
		$full['access']['mode']               = 'always';
		$full['access']['roles']              = array( 'restricted_admin', 'administrator' );
		$full['access']['customer_role']      = true;
		$full['access']['default_hours']      = 72;
		$full['access']['max_hours']          = 336;
		$full['access']['plugin_installs']    = true;
		$full['access']['extension']          = 'auto';
		$full['diagnostics']['errors']        = 'required';
		$full['diagnostics']['changes']       = 'required';
		$full['diagnostics']['debug_log']     = 'on';

		return array(
			array(
				'id'          => 'standard',
				'name'        => __( 'Standard', 'helpdesk-hero-hub' ),
				'description' => __( 'Customers choose whether to give access (ticked for them): a restricted administrator for 24 hours, up to 7 days.', 'helpdesk-hero-hub' ),
				'policy'      => $d,
			),
			array(
				'id'          => 'hands-off',
				'name'        => __( 'Hands-off', 'helpdesk-hero-hub' ),
				'description' => __( 'Your team never logs in. Tickets with diagnostics only, for advice and how-to support.', 'helpdesk-hero-hub' ),
				'policy'      => $hands_off,
			),
			array(
				'id'          => 'strict',
				'name'        => __( 'Strict', 'helpdesk-hero-hub' ),
				'description' => __( 'Short, fixed 4-hour access that can’t be extended past 24 hours. Errors always included.', 'helpdesk-hero-hub' ),
				'policy'      => $strict,
			),
			array(
				'id'          => 'full-service',
				'name'        => __( 'Full service', 'helpdesk-hero-hub' ),
				'description' => __( 'Managed sites: access with every ticket, 3 days by default, plugin installs allowed, extensions granted automatically.', 'helpdesk-hero-hub' ),
				'policy'      => $full,
			),
		);
	}

	/**
	 * All templates.
	 *
	 * @return array[] { id, name, description, policy }
	 */
	public static function all() {
		$stored = get_option( self::OPTION, null );
		$list   = is_array( $stored ) ? $stored : self::starters();
		foreach ( $list as &$tpl ) {
			$tpl['policy'] = Helpdesk_Hero_Hub_Policy::sanitize( (array) $tpl['policy'] );
		}
		return array_values( $list );
	}

	/**
	 * One template.
	 *
	 * @param string $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( self::all() as $tpl ) {
			if ( $tpl['id'] === $id ) {
				return $tpl;
			}
		}
		return null;
	}

	/**
	 * Create or update a template; sites that use it receive the new policy.
	 *
	 * @param array $tpl id (empty for new), name, description, policy.
	 * @return array|WP_Error Saved template.
	 */
	public static function save( array $tpl ) {
		$name = trim( sanitize_text_field( (string) ( $tpl['name'] ?? '' ) ) );
		if ( '' === $name ) {
			return new WP_Error( 'helpdesk_hero_hub_template', __( 'Give the template a name.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		$list = self::all();
		$id   = sanitize_key( (string) ( $tpl['id'] ?? '' ) );
		$new  = array(
			'id'          => '' !== $id ? $id : self::unique_id( $name, $list ),
			'name'        => substr( $name, 0, 60 ),
			'description' => substr( sanitize_textarea_field( (string) ( $tpl['description'] ?? '' ) ), 0, 300 ),
			'policy'      => Helpdesk_Hero_Hub_Policy::sanitize( (array) ( $tpl['policy'] ?? array() ) ),
		);
		$found = false;
		foreach ( $list as $i => $existing ) {
			if ( $existing['id'] === $new['id'] ) {
				$list[ $i ] = $new;
				$found      = true;
			}
		}
		if ( ! $found ) {
			$list[] = $new;
		}
		update_option( self::OPTION, $list, false );
		self::push_sites( $new['id'] );
		return $new;
	}

	/**
	 * Delete a template. Sites that used it go back to the team default.
	 *
	 * @param string $id ID.
	 */
	public static function delete( $id ) {
		$list = array_values(
			array_filter(
				self::all(),
				static function ( $tpl ) use ( $id ) {
					return $tpl['id'] !== $id;
				}
			)
		);
		update_option( self::OPTION, $list, false );
		foreach ( self::sites_using( $id ) as $site_id ) {
			Helpdesk_Hero_Hub::update_site( $site_id, array( 'policy' => null ) );
			Helpdesk_Hero_Hub_Policy::push( $site_id );
		}
	}

	/**
	 * Sites that follow a template.
	 *
	 * @param string $id Template.
	 * @return int[]
	 */
	public static function sites_using( $id ) {
		$ids = array();
		foreach ( Helpdesk_Hero_Hub::sites() as $site ) {
			if ( is_array( $site['policy'] ) && ( $site['policy']['template'] ?? '' ) === $id ) {
				$ids[] = (int) $site['id'];
			}
		}
		return $ids;
	}

	/**
	 * Send a template's policy to its sites.
	 *
	 * @param string $id Template.
	 */
	private static function push_sites( $id ) {
		foreach ( self::sites_using( $id ) as $site_id ) {
			$site = Helpdesk_Hero_Hub::site( $site_id );
			if ( $site && 'active' === $site['status'] ) {
				Helpdesk_Hero_Hub_Policy::push( $site_id );
			}
		}
	}

	/**
	 * Unique ID from a name.
	 *
	 * @param string $name Name.
	 * @param array  $list Existing templates.
	 * @return string
	 */
	private static function unique_id( $name, array $list ) {
		$base = sanitize_title( $name );
		$base = '' !== $base ? $base : 'template';
		$ids  = wp_list_pluck( $list, 'id' );
		$id   = $base;
		$n    = 2;
		while ( in_array( $id, $ids, true ) ) {
			$id = $base . '-' . $n++;
		}
		return $id;
	}
}
