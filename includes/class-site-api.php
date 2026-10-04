<?php
/**
 * REST endpoints connected sites call on the hub.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pairing uses a single-use invite token. Everything else must be signed with the site's secret,
 * and a site can only touch its own tickets.
 */
final class Helpdesk_Hero_Hub_Site_API {

	const NS = 'helpdesk-hero-hub/v1';

	/**
	 * Register routes (only in hub mode).
	 */
	public static function register() {
		$auth = array( __CLASS__, 'authorize' );
		register_rest_route(
			self::NS,
			'/pair',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'pair' ),
				'permission_callback' => '__return_true', // Authenticated by the single-use invite token in the body.
			)
		);
		$routes = array(
			'/tickets'                          => array( 'POST', 'create_ticket' ),
			'/tickets/(?P<id>\d+)/reply'        => array( 'POST', 'reply' ),
			'/tickets/(?P<id>\d+)/status'       => array( 'POST', 'status' ),
			'/tickets/(?P<id>\d+)/access'       => array( 'POST', 'access' ),
			'/tickets/(?P<id>\d+)/rating'       => array( 'POST', 'rating' ),
			'/updates'                          => array( 'GET', 'updates' ),
			'/ping'                             => array( 'GET', 'ping' ),
			'/unpair'                           => array( 'POST', 'unpair' ),
			'/keys'                             => array( 'POST', 'keys' ),
			'/attachments/(?P<id>\d+)'         => array( 'GET', 'attachment' ),
		);
		foreach ( $routes as $route => $def ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					'methods'             => $def[0],
					'callback'            => array( __CLASS__, $def[1] ),
					'permission_callback' => $auth,
				)
			);
		}
	}

	/**
	 * Verify the signature and remember which site is calling.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function authorize( WP_REST_Request $request ) {
		$id = Helpdesk_Hero_Hub_Signer::verify(
			$request,
			static function ( $id ) {
				$site = ctype_digit( (string) $id ) ? Helpdesk_Hero_Hub::site( (int) $id ) : null;
				return $site && 'active' === $site['status'] ? Helpdesk_Hero_Hub::creds( $site ) : array();
			},
			static function ( $id, $creds ) {
				Helpdesk_Hero_Hub::save_creds( (int) $id, $creds );
			}
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$request->set_param( '_hh_site', (int) $id );
		Helpdesk_Hero_Hub::update_site( (int) $id, array( 'last_seen' => Helpdesk_Hero_Hub_DB::now() ) );
		return true;
	}

	/**
	 * Calling site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private static function site( WP_REST_Request $request ) {
		return Helpdesk_Hero_Hub::site( (int) $request->get_param( '_hh_site' ) );
	}

	/**
	 * The site's own ticket.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private static function own_ticket( WP_REST_Request $request ) {
		$ticket = Helpdesk_Hero_Hub::ticket( (int) $request['id'] );
		if ( ! $ticket || (int) $ticket['site_id'] !== (int) $request->get_param( '_hh_site' ) ) {
			return new WP_Error( 'helpdesk_hero_ticket', __( 'Ticket not found.', 'helpdesk-hero-hub' ), array( 'status' => 404 ) );
		}
		// A merged ticket continues in the ticket it was merged into (same site).
		if ( (int) $ticket['merged_into'] ) {
			$target = Helpdesk_Hero_Hub::ticket( (int) $ticket['merged_into'] );
			if ( $target && (int) $target['site_id'] === (int) $ticket['site_id'] ) {
				return $target;
			}
		}
		return $ticket;
	}

	/**
	 * A file the team attached to a reply, for the site to download (its own tickets only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function attachment( WP_REST_Request $request ) {
		$row = Helpdesk_Hero_Hub_Attachments::get( (int) $request['id'] );
		if ( ! $row || 'hub' !== $row['origin'] || ! (int) $row['ticket_id'] || (int) $row['site_id'] !== (int) $request->get_param( '_hh_site' ) ) {
			return new WP_Error( 'helpdesk_hero_file_gone', __( 'File not found.', 'helpdesk-hero-hub' ), array( 'status' => 404 ) );
		}
		return Helpdesk_Hero_Hub_Attachments::export( $row );
	}

	/**
	 * Pair a site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function pair( WP_REST_Request $request ) {
		// Slow down guessing: 20 attempts per IP per hour.
		$key      = 'helpdesk_hero_hub_pair_' . md5( self::ip() );
		$attempts = (int) get_transient( $key );
		if ( $attempts >= 20 ) {
			return new WP_Error( 'helpdesk_hero_rate', __( 'Too many attempts. Try again later.', 'helpdesk-hero-hub' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $attempts + 1, HOUR_IN_SECONDS );

		$params = (array) $request->get_json_params();
		$site   = Helpdesk_Hero_Hub::pair( (string) ( $params['token'] ?? '' ), $params );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		return array_merge(
			array(
				'site_id'        => (int) $site['id'],
				'secret'         => $site['hmac'],
				'hub_public_key' => $site['public_key'],
				'hub_name'       => Helpdesk_Hero_Hub_Settings::team_name(),
			),
			Helpdesk_Hero_Hub_Policy::payload( $site )
		);
	}

	/**
	 * The site moves from a shared key to key pairs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function keys( WP_REST_Request $request ) {
		return Helpdesk_Hero_Hub::upgrade_keys( self::site( $request ), (string) $request->get_param( 'public_key' ) );
	}

	/**
	 * New ticket.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function create_ticket( WP_REST_Request $request ) {
		return Helpdesk_Hero_Hub::receive_ticket( self::site( $request ), (array) $request->get_json_params() );
	}

	/**
	 * Customer reply.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function reply( WP_REST_Request $request ) {
		$ticket = self::own_ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}
		if ( ! Helpdesk_Hero_Hub_Policy::for_site( self::site( $request ) )['tickets']['customer_replies'] ) {
			return new WP_Error( 'helpdesk_hero_hub_policy', __( 'Your support team does not accept replies from the dashboard. Contact them directly.', 'helpdesk-hero-hub' ), array( 'status' => 403 ) );
		}
		$params = (array) $request->get_json_params();
		$body   = trim( (string) ( $params['body'] ?? '' ) );
		if ( '' === $body ) {
			return new WP_Error( 'helpdesk_hero_reply', __( 'Empty reply.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		$result = Helpdesk_Hero_Hub::customer_reply( $ticket, $body, (string) ( $params['author'] ?? '' ), (array) ( $params['attachments'] ?? array() ), (array) ( $params['pinpoints'] ?? array() ) );
		return is_wp_error( $result ) ? $result : array( 'ok' => true );
	}

	/**
	 * Customer closed or reopened.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function status( WP_REST_Request $request ) {
		$ticket = self::own_ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}
		if ( ! Helpdesk_Hero_Hub_Policy::for_site( self::site( $request ) )['tickets']['customer_close'] ) {
			return new WP_Error( 'helpdesk_hero_hub_policy', __( 'Your support team closes tickets.', 'helpdesk-hero-hub' ), array( 'status' => 403 ) );
		}
		$params = (array) $request->get_json_params();
		$status = 'closed' === ( $params['status'] ?? '' ) ? 'closed' : 'open';
		$helpdesk = Helpdesk_Hero_Hub_Helpdesk::for_ticket( $ticket );
		if ( $helpdesk ) {
			$helpdesk->set_status( $ticket, $status );
			$helpdesk->note( $ticket, 'closed' === $status ? __( 'The customer marked this ticket as solved in their dashboard.', 'helpdesk-hero-hub' ) : __( 'The customer reopened this ticket.', 'helpdesk-hero-hub' ) );
		}
		Helpdesk_Hero_Hub::log( $ticket, 'customer_status', array( 'status' => $status ) );
		// Through set_status so the SLA clock and notifications follow (the site already knows).
		Helpdesk_Hero_Hub::set_status( $ticket, $status, false );
		return array( 'ok' => true );
	}

	/**
	 * Access granted, extended, ended or used.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function access( WP_REST_Request $request ) {
		$ticket = self::own_ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}
		$params  = (array) $request->get_json_params();
		$active  = ! empty( $params['active'] );
		$event   = sanitize_key( (string) ( $params['event'] ?? '' ) );
		$expires = $active && ! empty( $params['expires_at'] ) ? sanitize_text_field( $params['expires_at'] ) : null;
		Helpdesk_Hero_Hub::update_ticket( (int) $ticket['id'], array( 'access_expires' => $expires ) );
		Helpdesk_Hero_Hub::log( $ticket, 'access', array( 'event' => $event, 'active' => $active, 'expires_at' => $expires ) );

		$messages = array(
			/* translators: %s: date */
			'granted'            => __( 'The customer granted support access until %s UTC.', 'helpdesk-hero-hub' ),
			/* translators: %s: date */
			'extended'           => __( 'The customer extended support access until %s UTC.', 'helpdesk-hero-hub' ),
			'revoked'            => __( 'The customer ended support access.', 'helpdesk-hero-hub' ),
			/* translators: %s: date */
			'changed'            => __( 'The customer changed support access; it now ends %s UTC.', 'helpdesk-hero-hub' ),
			'support_ended'      => __( 'Support ended access to the site. The support accounts were deleted.', 'helpdesk-hero-hub' ),
			'closed'             => __( 'Support access ended because no open tickets need it.', 'helpdesk-hero-hub' ),
			'expired'            => __( 'Support access expired.', 'helpdesk-hero-hub' ),
			'deleted'            => __( 'Support access ended because the customer deleted a support account. The support accounts were deleted.', 'helpdesk-hero-hub' ),
			'extension_declined' => __( 'The customer declined the request for more time.', 'helpdesk-hero-hub' ),
		);
		$helpdesk = Helpdesk_Hero_Hub_Helpdesk::for_ticket( $ticket );
		if ( $helpdesk && isset( $messages[ $event ] ) ) {
			$helpdesk->note( $ticket, sprintf( $messages[ $event ], (string) $expires ) . ( $active ? "\n" . Helpdesk_Hero_Hub::ticket_url( (int) $ticket['id'] ) : '' ) );
		}
		return array( 'ok' => true );
	}

	/**
	 * Updates for the site after its cursor.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function updates( WP_REST_Request $request ) {
		$site   = self::site( $request );
		$cursor = (int) $request->get_param( 'cursor' );
		// The site has everything up to its cursor; pushes resume from there.
		$info   = array( 'ack' => $cursor );
		// Sites from 2.1 report their versions, so the hub knows what they support.
		foreach ( array( 'version', 'wordpress' ) as $key ) {
			$value = sanitize_text_field( (string) $request->get_param( $key ) );
			if ( '' !== $value && preg_match( '/^[0-9][0-9a-z.\-]{0,19}$/i', $value ) ) {
				$info[ $key ] = $value;
			}
		}
		Helpdesk_Hero_Hub::update_site( (int) $site['id'], array( 'info' => $info ) );
		return array(
			'items' => Helpdesk_Hero_Hub::pending( (int) $site['id'], $cursor ),
			'usage' => Helpdesk_Hero_Hub::site_usage( $site ),
		);
	}

	/**
	 * The customer rated a ticket.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function rating( WP_REST_Request $request ) {
		$ticket = self::own_ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}
		$params = (array) $request->get_json_params();
		$rating = (int) ( $params['rating'] ?? 0 );
		if ( $rating < 1 || $rating > 5 ) {
			return new WP_Error( 'helpdesk_hero_hub_rating', __( 'Rate from 1 to 5.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		Helpdesk_Hero_Hub::rate( $ticket, $rating, (string) ( $params['comment'] ?? '' ) );
		return array( 'ok' => true );
	}

	/**
	 * Connection check from a site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function ping( WP_REST_Request $request ) {
		unset( $request );
		return array(
			'ok'       => true,
			'hub_name' => Helpdesk_Hero_Hub_Settings::team_name(),
			'version'  => HELPDESK_HERO_HUB_VERSION,
			'time'     => time(),
		);
	}

	/**
	 * The site disconnected itself.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function unpair( WP_REST_Request $request ) {
		Helpdesk_Hero_Hub::revoke_site( (int) $request->get_param( '_hh_site' ) );
		return array( 'ok' => true );
	}

	/**
	 * Caller IP (for rate limiting pairing).
	 *
	 * @return string
	 */
	private static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
