<?php
/**
 * Support policy: what customers may do, decided by the support team.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * The team's default policy applies to every connected site unless a site has its own.
 * Connected sites receive their effective policy when they pair and whenever it changes,
 * and the customer plugin only offers what the policy allows.
 */
final class Helpdesk_Hero_Hub_Policy {

	const OPTION = 'helpdesk_hero_hub_policy';

	/**
	 * Roles a policy can allow.
	 *
	 * @return array Key => label.
	 */
	public static function roles() {
		return array(
			'restricted_admin' => __( 'Administrator without user management or code editing', 'helpdesk-hero-hub' ),
			'administrator'    => __( 'Full administrator', 'helpdesk-hero-hub' ),
			'editor'           => __( 'Editor', 'helpdesk-hero-hub' ),
			'shop_manager'     => __( 'Shop manager (WooCommerce)', 'helpdesk-hero-hub' ),
		);
	}

	/**
	 * Diagnostics sections.
	 *
	 * @return array Key => label.
	 */
	public static function sections() {
		return array(
			'environment' => __( 'WordPress, PHP, server and configuration', 'helpdesk-hero-hub' ),
			'extensions'  => __( 'Plugins and themes', 'helpdesk-hero-hub' ),
			'errors'      => __( 'Recent PHP and JavaScript errors', 'helpdesk-hero-hub' ),
			'changes'     => __( 'Recent changes (updates, activations, settings)', 'helpdesk-hero-hub' ),
			'debug_log'   => __( 'Last lines of debug.log', 'helpdesk-hero-hub' ),
		);
	}

	/**
	 * Default policy.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'access'      => array(
				// ask: customers decide per ticket (pre-ticked) · always: access comes with every ticket · off: never.
				'mode'               => 'ask',
				'roles'              => array( 'restricted_admin' ),
				'default_role'       => 'restricted_admin',
				'customer_role'      => false,
				'default_hours'      => 24,
				'max_hours'          => 168,
				'customer_duration'  => true,
				'plugin_installs'    => false,
				// approve: the customer approves support's requests for more time · auto: granted up to max_hours.
				'extension'          => 'approve',
				'customer_extend'    => true,
				'log_page_views'     => true,
				'troubleshooting'    => true,
				'end_on_close'       => true,
			),
			'tickets'     => array(
				'customer_replies' => true,
				'customer_close'   => true,
				'priorities'       => true,
				'categories'       => array(),
				'intro'            => '',
				'manual_email'     => '',
				'ai_assistant'     => true,
				'ratings'          => true,
			),
			'diagnostics' => array(
				'environment' => 'required',
				'extensions'  => 'required',
				'errors'      => 'on',
				'changes'     => 'on',
				'debug_log'   => 'off',
			),
		);
	}

	/**
	 * The team's default policy.
	 *
	 * @return array
	 */
	public static function team() {
		$stored = get_option( self::OPTION, array() );
		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Save the team policy and send it to every site that uses it.
	 *
	 * @param array $policy Policy.
	 * @return array Saved policy.
	 */
	public static function save_team( array $policy ) {
		$clean = self::sanitize( $policy );
		update_option( self::OPTION, $clean, false );
		foreach ( Helpdesk_Hero_Hub::sites() as $site ) {
			if ( 'active' === $site['status'] && 'default' === self::source( $site )['type'] ) {
				self::push( (int) $site['id'] );
			}
		}
		return $clean;
	}

	/**
	 * Effective policy for a site: its own policy if it has one, otherwise the team's.
	 *
	 * @param array|null $site Site row.
	 * @return array
	 */
	public static function for_site( $site ) {
		$policy = self::resolve( $site );
		if ( ! self::ratings_available() ) {
			$policy['tickets']['ratings'] = false;
		}
		return $policy;
	}

	/**
	 * Whether customer ratings are available (Helpdesk Hero Pro).
	 *
	 * @return bool
	 */
	public static function ratings_available() {
		/**
		 * Filters whether customers can be asked to rate tickets (Helpdesk Hero Pro).
		 *
		 * @param bool $available Available.
		 */
		return (bool) apply_filters( 'helpdesk_hero_hub_ratings_available', false );
	}

	/**
	 * The stored policy that applies to a site.
	 *
	 * @param array|null $site Site row.
	 * @return array
	 */
	private static function resolve( $site ) {
		$source = self::source( $site );
		if ( 'template' === $source['type'] ) {
			$template = Helpdesk_Hero_Hub_Templates::get( $source['template'] );
			if ( $template ) {
				return $template['policy'];
			}
		} elseif ( 'custom' === $source['type'] ) {
			return self::sanitize( $site['policy'] );
		}
		return self::team();
	}

	/**
	 * Where a site's policy comes from.
	 *
	 * @param array|null $site Site row.
	 * @return array { type: default|template|custom, template: id, name: label }
	 */
	public static function source( $site ) {
		$policy = $site['policy'] ?? null;
		if ( is_array( $policy ) && ! empty( $policy['template'] ) ) {
			$template = Helpdesk_Hero_Hub_Templates::get( (string) $policy['template'] );
			if ( $template ) {
				return array(
					'type'     => 'template',
					'template' => $template['id'],
					'name'     => $template['name'],
				);
			}
		} elseif ( is_array( $policy ) && isset( $policy['access'] ) ) {
			return array(
				'type'     => 'custom',
				'template' => '',
				'name'     => __( 'Custom', 'helpdesk-hero-hub' ),
			);
		}
		return array(
			'type'     => 'default',
			'template' => '',
			'name'     => __( 'Team default', 'helpdesk-hero-hub' ),
		);
	}

	/**
	 * Send a site its effective policy.
	 *
	 * @param int $site_id Site.
	 */
	public static function push( $site_id ) {
		Helpdesk_Hero_Hub::enqueue( (int) $site_id, 0, 'policy', self::payload( Helpdesk_Hero_Hub::site( (int) $site_id ) ) );
	}

	/**
	 * Policy as sent to a site (with the team name and branding).
	 *
	 * @param array|null $site Site.
	 * @return array
	 */
	public static function payload( $site ) {
		return array(
			'policy'   => self::for_site( $site ),
			'branding' => Helpdesk_Hero_Hub::branding(),
		);
	}

	/**
	 * Clean a policy, filling gaps with defaults and keeping values consistent.
	 *
	 * @param array $in Raw policy.
	 * @return array
	 */
	public static function sanitize( array $in ) {
		$d   = self::defaults();
		$a   = array_merge( $d['access'], (array) ( $in['access'] ?? array() ) );
		$t   = array_merge( $d['tickets'], (array) ( $in['tickets'] ?? array() ) );
		$dg  = array_merge( $d['diagnostics'], (array) ( $in['diagnostics'] ?? array() ) );
		$out = array();

		$roles = array_values( array_intersect( array_map( 'sanitize_key', (array) $a['roles'] ), array_keys( self::roles() ) ) );
		if ( ! $roles ) {
			$roles = array( 'restricted_admin' );
		}
		$max     = max( 1, min( 24 * 30, (int) $a['max_hours'] ) );
		$default = max( 1, min( $max, (int) $a['default_hours'] ) );

		$out['access'] = array(
			'mode'              => in_array( $a['mode'], array( 'ask', 'always', 'off' ), true ) ? $a['mode'] : 'ask',
			'roles'             => $roles,
			'default_role'      => in_array( $a['default_role'], $roles, true ) ? $a['default_role'] : $roles[0],
			'customer_role'     => (bool) $a['customer_role'] && count( $roles ) > 1,
			'default_hours'     => $default,
			'max_hours'         => $max,
			'customer_duration' => (bool) $a['customer_duration'],
			'plugin_installs'   => (bool) $a['plugin_installs'],
			'extension'         => 'auto' === $a['extension'] ? 'auto' : 'approve',
			'customer_extend'   => (bool) $a['customer_extend'],
			'log_page_views'    => (bool) $a['log_page_views'],
			'troubleshooting'   => (bool) $a['troubleshooting'],
			'end_on_close'      => (bool) $a['end_on_close'],
		);

		$categories = array();
		foreach ( (array) $t['categories'] as $cat ) {
			$cat = trim( sanitize_text_field( (string) $cat ) );
			if ( '' !== $cat && ! in_array( $cat, $categories, true ) ) {
				$categories[] = substr( $cat, 0, 60 );
			}
		}
		$out['tickets'] = array(
			'customer_replies' => (bool) $t['customer_replies'],
			'customer_close'   => (bool) $t['customer_close'],
			'priorities'       => (bool) $t['priorities'],
			'categories'       => array_slice( $categories, 0, 20 ),
			'intro'            => substr( sanitize_textarea_field( (string) $t['intro'] ), 0, 1000 ),
			'manual_email'     => is_email( $t['manual_email'] ) ? sanitize_email( $t['manual_email'] ) : '',
			'ai_assistant'     => (bool) $t['ai_assistant'],
			'ratings'          => (bool) $t['ratings'],
		);

		$out['diagnostics'] = array();
		foreach ( array_keys( self::sections() ) as $key ) {
			$out['diagnostics'][ $key ] = in_array( $dg[ $key ] ?? '', array( 'required', 'on', 'off', 'never' ), true ) ? $dg[ $key ] : $d['diagnostics'][ $key ];
		}

		/**
		 * Filters a sanitized policy (Pro or custom code can add keys).
		 *
		 * @param array $out Policy.
		 * @param array $in  Raw input.
		 */
		return (array) apply_filters( 'helpdesk_hero_hub_policy', $out, $in );
	}
}
