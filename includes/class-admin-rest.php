<?php
/**
 * REST API for the hub dashboard.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints the React dashboard uses (cookie authentication). Agents need the hub capability;
 * the policy, help desk and settings need manage_options.
 */
final class Helpdesk_Hero_Hub_Admin_REST {

	const NS = 'helpdesk-hero-hub/v1';

	/**
	 * Register routes.
	 */
	public static function register() {
		$agent = static function () {
			return current_user_can( 'helpdesk_hero_hub' );
		};
		$admin = static function () {
			return current_user_can( 'manage_options' );
		};
		// Replying, logging in to sites and other actions customers see: supporters only, when the team names them (Pro).
		$support = static function () {
			if ( ! current_user_can( 'helpdesk_hero_hub' ) ) {
				return false;
			}
			return Helpdesk_Hero_Hub::can_support() ? true : new WP_Error( 'helpdesk_hero_hub_not_supporter', __( 'Only the supporters your team has chosen can reply to customers and log in to their sites.', 'helpdesk-hero-hub' ), array( 'status' => 403 ) );
		};
		$routes = array(
			array( '/admin/tickets', 'GET', 'tickets', $agent ),
			array( '/admin/unread', 'GET', 'unread', $agent ),
			array( '/admin/tickets/(?P<id>\d+)', 'GET', 'ticket', $agent ),
			array( '/admin/tickets/(?P<id>\d+)/reply', 'POST', 'reply', $support ),
			array( '/admin/tickets/(?P<id>\d+)/status', 'POST', 'status', $support ),
			array( '/admin/tickets/(?P<id>\d+)/notice', 'POST', 'ticket_notice', $support ),
			array( '/admin/tickets/(?P<id>\d+)/extend', 'POST', 'extend', $support ),
			array( '/admin/tickets/(?P<id>\d+)/login', 'POST', 'login', $support ),
			array( '/admin/tickets/(?P<id>\d+)/sync', 'POST', 'sync', $agent ),
			array( '/admin/tickets/(?P<id>\d+)/activity', 'GET', 'activity', $agent ),
			array( '/admin/sites', 'GET', 'sites', $agent ),
			array( '/admin/sites', 'POST', 'invite', $agent ),
			array( '/admin/sites/(?P<id>\d+)', 'GET', 'site', $agent ),
			array( '/admin/sites/(?P<id>\d+)', 'POST', 'update_site', $admin ),
			array( '/admin/sites/(?P<id>\d+)', 'DELETE', 'revoke_site', $admin ),
			array( '/admin/sites/(?P<id>\d+)/ping', 'POST', 'ping', $agent ),
			array( '/admin/sites/(?P<id>\d+)/notice', 'POST', 'site_notice', $support ),
			array( '/admin/tickets/(?P<id>\d+)/tags', 'POST', 'ticket_tags', $support ),
			array( '/admin/sites/policy', 'POST', 'bulk_policy', $admin ),
			array( '/admin/tags', 'GET', 'tags', $agent ),
			array( '/admin/tags', 'POST', 'save_tags', $admin ),
			array( '/admin/templates', 'GET', 'templates', $agent ),
			array( '/admin/templates', 'POST', 'save_template', $admin ),
			array( '/admin/templates/(?P<id>[a-z0-9_-]+)', 'DELETE', 'delete_template', $admin ),
			array( '/admin/stats', 'GET', 'stats', $agent ),
			array( '/admin/onboarding', 'POST', 'onboarding', $admin ),
			array( '/admin/feedback', 'POST', 'feedback', $agent ),
			array( '/admin/policy', 'GET', 'policy', $agent ),
			array( '/admin/policy', 'POST', 'save_policy', $admin ),
			array( '/admin/settings', 'GET', 'settings', $admin ),
			array( '/admin/settings', 'POST', 'save_settings', $admin ),
			array( '/admin/backup', 'GET', 'backup', $admin ),
			array( '/admin/backup', 'POST', 'restore', $admin ),
		);
		foreach ( $routes as $r ) {
			register_rest_route(
				self::NS,
				$r[0],
				array(
					'methods'             => $r[1],
					'callback'            => array( __CLASS__, $r[2] ),
					'permission_callback' => $r[3],
				)
			);
		}
	}

	/**
	 * A ticket or a 404.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private static function find( WP_REST_Request $request ) {
		$ticket = Helpdesk_Hero_Hub::ticket( (int) $request['id'] );
		return $ticket ? $ticket : new WP_Error( 'helpdesk_hero_hub_ticket', __( 'Ticket not found.', 'helpdesk-hero-hub' ), array( 'status' => 404 ) );
	}

	/**
	 * Whether access is active.
	 *
	 * @param string|null $expires UTC expiry.
	 * @return bool
	 */
	private static function access_active( $expires ) {
		return $expires && strtotime( $expires . ' UTC' ) > time();
	}

	/**
	 * ISO 8601 from a UTC MySQL date.
	 *
	 * @param string|null $mysql Date.
	 * @return string|null
	 */
	private static function iso( $mysql ) {
		return $mysql ? gmdate( 'c', strtotime( $mysql . ' UTC' ) ) : null;
	}

	/* ------------------------------------------------------------------------------------ */

	/**
	 * Inbox.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function tickets( WP_REST_Request $request ) {
		global $wpdb;
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$rows   = Helpdesk_Hero_Hub::tickets(
			array(
				'status'  => in_array( $status, array( 'open', 'pending', 'closed' ), true ) ? $status : ( 'all' === $status ? '' : 'active' ),
				'search'  => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'site_id' => (int) $request->get_param( 'site' ),
				'limit'   => 200,
			)
		);
		$tickets = array();
		foreach ( $rows as $t ) {
			$levels    = array_count_values( array_column( $t['flags'], 'level' ) );
			$tickets[] = array(
				'id'             => (int) $t['id'],
				'subject'        => $t['subject'],
				'status'         => $t['status'],
				'priority'       => $t['priority'],
				'customer'       => $t['customer_name'] ? $t['customer_name'] : $t['customer_email'],
				'site_id'        => (int) $t['site_id'],
				'site_name'      => (string) $t['site_name'],
				'site_host'      => (string) wp_parse_url( (string) $t['site_url'], PHP_URL_HOST ),
				'critical'       => (int) ( $levels['critical'] ?? 0 ),
				'warnings'       => (int) ( $levels['warning'] ?? 0 ),
				'access_active'  => self::access_active( $t['access_expires'] ),
				'access_expires' => self::iso( $t['access_expires'] ),
				'reference'      => $t['helpdesk_number'] ? ucfirst( $t['helpdesk'] ) . ' #' . $t['helpdesk_number'] : '',
				'channel'        => $t['channel'],
				'unread'         => Helpdesk_Hero_Hub::unread( $t ),
				'category'       => $t['category'],
				'tags'           => Helpdesk_Hero_Hub_Tags::resolve( $t['tags'] ),
				'rating'         => (int) $t['rating'],
				'updated_at'     => self::iso( $t['updated_at'] ),
			);
		}
		$table  = Helpdesk_Hero_Hub_DB::table( 'tickets' );
		$counts = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status = 'open') AS open, SUM(status = 'pending') AS pending, SUM(status <> 'closed' AND access_expires > %s) AS access FROM %i", Helpdesk_Hero_Hub_DB::now(), $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array(
			'tickets' => $tickets,
			'counts'  => array(
				'open'    => (int) ( $counts['open'] ?? 0 ),
				'pending' => (int) ( $counts['pending'] ?? 0 ),
				'access'  => (int) ( $counts['access'] ?? 0 ),
				'sites'   => count(
					array_filter(
						Helpdesk_Hero_Hub::sites(),
						static function ( $s ) {
							return 'active' === $s['status'];
						}
					)
				),
			),
		);
	}

	/**
	 * One ticket.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function ticket( WP_REST_Request $request ) {
		$t = self::find( $request );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		if ( 'GET' === $request->get_method() ) {
			Helpdesk_Hero_Hub::mark_viewed( (int) $t['id'] );
		}
		$site     = Helpdesk_Hero_Hub::site( (int) $t['site_id'] );
		$helpdesk = Helpdesk_Hero_Hub_Helpdesk::for_ticket( $t );
		$policy   = Helpdesk_Hero_Hub_Policy::for_site( $site );

		$thread = array(
			array(
				'id'     => 'opening',
				'kind'   => 'customer',
				'author' => $t['customer_name'] ? $t['customer_name'] : $t['customer_email'],
				'body'   => (string) $t['description'],
				'time'   => self::iso( $t['created_at'] ),
			),
		);
		foreach ( Helpdesk_Hero_Hub::history( (int) $t['id'] ) as $event ) {
			$p = $event['payload'];
			if ( 'reply' === $event['type'] || 'customer_reply' === $event['type'] ) {
				$thread[] = array(
					'id'     => (int) $event['id'],
					'kind'   => 'reply' === $event['type'] ? 'support' : 'customer',
					'author' => (string) ( $p['author'] ?? '' ),
					'body'   => (string) ( $p['body'] ?? '' ),
					'time'   => self::iso( $event['created_at'] ),
				);
			} else {
				$text = self::describe_event( $event );
				if ( '' !== $text ) {
					$thread[] = array(
						'id'   => (int) $event['id'],
						'kind' => 'event',
						'type' => $event['type'],
						'body' => $text,
						'time' => self::iso( $event['created_at'] ),
					);
				}
			}
		}

		return array(
			'id'          => (int) $t['id'],
			'subject'     => $t['subject'],
			'status'      => $t['status'],
			'priority'    => $t['priority'],
			'category'    => $t['category'],
			'channel'     => $t['channel'],
			'tags'        => Helpdesk_Hero_Hub_Tags::resolve( $t['tags'] ),
			'rating'      => (int) $t['rating'] ? array(
				'stars'   => (int) $t['rating'],
				'comment' => (string) $t['rating_comment'],
				'time'    => self::iso( $t['rated_at'] ),
			) : null,
			'customer'    => array(
				'name'  => $t['customer_name'],
				'email' => $t['customer_email'],
			),
			'site'        => $site ? array(
				'id'        => (int) $site['id'],
				'name'      => $site['name'] ? $site['name'] : $site['label'],
				'url'       => $site['url'],
				'wordpress' => $site['info']['wordpress'] ?? '',
			) : null,
			'helpdesk'    => $helpdesk ? array(
				'label'     => $helpdesk->label(),
				'url'       => $helpdesk->url( $t ),
				'reference' => $helpdesk->label() . ' #' . ( $t['helpdesk_number'] ? $t['helpdesk_number'] : $t['helpdesk_id'] ),
			) : null,
			'access'      => array(
				'active'     => self::access_active( $t['access_expires'] ),
				'expires_at' => self::iso( $t['access_expires'] ),
				'extension'  => $policy['access']['extension'],
				'max_hours'  => $policy['access']['max_hours'],
			),
			'flags'       => $t['flags'],
			'environment' => $t['diagnostics']['environment'] ?? null,
			'diagnostics' => Helpdesk_Hero_Hub_Format::diagnostics( $t['diagnostics'] ),
			'thread'      => $thread,
			'created_at'  => self::iso( $t['created_at'] ),
			'updated_at'  => self::iso( $t['updated_at'] ),
		);
	}

	/**
	 * One-line description of a history event ('' hides it).
	 *
	 * @param array $event Event.
	 * @return string
	 */
	public static function describe_event( array $event ) {
		$p = $event['payload'];
		switch ( $event['type'] ) {
			case 'status':
				$labels = array(
					'open'    => __( 'Open', 'helpdesk-hero-hub' ),
					'pending' => __( 'Waiting on customer', 'helpdesk-hero-hub' ),
					'closed'  => __( 'Closed', 'helpdesk-hero-hub' ),
				);
				/* translators: %s: status */
				return sprintf( __( 'Status changed to %s', 'helpdesk-hero-hub' ), $labels[ $p['status'] ?? '' ] ?? '' );
			case 'customer_status':
				return 'closed' === ( $p['status'] ?? '' ) ? __( 'Customer marked the ticket as solved', 'helpdesk-hero-hub' ) : __( 'Customer reopened the ticket', 'helpdesk-hero-hub' );
			case 'notice':
				/* translators: %s: title */
				return sprintf( __( 'Dashboard message sent: %s', 'helpdesk-hero-hub' ), $p['title'] ?? '' );
			case 'extension_request':
				/* translators: 1: agent, 2: hours */
				return sprintf( __( '%1$s asked for %2$d more hours', 'helpdesk-hero-hub' ), $p['by'] ?? '', (int) ( $p['hours'] ?? 0 ) );
			case 'agent_login':
				/* translators: %s: agent */
				return sprintf( __( '%s logged in to the site', 'helpdesk-hero-hub' ), $p['agent'] ?? '' );
			case 'access':
				$events = array(
					'granted'            => __( 'Customer granted access', 'helpdesk-hero-hub' ),
					'extended'           => __( 'Access extended', 'helpdesk-hero-hub' ),
					'revoked'            => __( 'Customer ended access', 'helpdesk-hero-hub' ),
					'expired'            => __( 'Access expired', 'helpdesk-hero-hub' ),
					'login'              => __( 'Support login used', 'helpdesk-hero-hub' ),
					'extension_declined' => __( 'Customer declined the request for more time', 'helpdesk-hero-hub' ),
				);
				return $events[ $p['event'] ?? '' ] ?? '';
			case 'emailed':
				return __( 'The customer emailed this ticket and registered it here', 'helpdesk-hero-hub' );
			case 'rating':
				/* translators: %d: stars from 1 to 5 */
				return sprintf( __( 'Customer rated the support %d/5', 'helpdesk-hero-hub' ), (int) ( $p['rating'] ?? 0 ) ) . ( ! empty( $p['comment'] ) ? ': “' . $p['comment'] . '”' : '' );
			case 'policy':
			case 'reference':
			case 'tags':
				return '';
		}
		/**
		 * Filters the description of a custom history event ('' hides it).
		 *
		 * @param string $text  Text.
		 * @param array  $event Event.
		 */
		return (string) apply_filters( 'helpdesk_hero_hub_describe_event', '', $event );
	}

	/**
	 * Reply as an agent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function reply( WP_REST_Request $request ) {
		$t = self::find( $request );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$body = trim( sanitize_textarea_field( (string) $request->get_param( 'body' ) ) );
		if ( '' === $body ) {
			return new WP_Error( 'helpdesk_hero_hub_empty', __( 'Write a reply first.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		$result = Helpdesk_Hero_Hub::agent_reply( $t, $body );
		return is_wp_error( $result ) ? $result : self::ticket( $request );
	}

	/**
	 * Change status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function status( WP_REST_Request $request ) {
		$t = self::find( $request );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		Helpdesk_Hero_Hub::set_status( $t, sanitize_key( (string) $request->get_param( 'status' ) ) );
		return self::ticket( $request );
	}

	/**
	 * Notice payload from a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private static function notice_payload( WP_REST_Request $request ) {
		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		if ( '' === $title ) {
			return new WP_Error( 'helpdesk_hero_hub_empty', __( 'Give the message a title.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		return array(
			'title'  => $title,
			'body'   => sanitize_textarea_field( (string) $request->get_param( 'body' ) ),
			'level'  => in_array( $request->get_param( 'level' ), array( 'info', 'success', 'warning', 'error' ), true ) ? $request->get_param( 'level' ) : 'info',
			'action' => array(
				'label' => sanitize_text_field( (string) $request->get_param( 'action_label' ) ),
				'url'   => sanitize_text_field( (string) $request->get_param( 'action_url' ) ),
			),
		);
	}

	/**
	 * Dashboard message tied to a ticket.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function ticket_notice( WP_REST_Request $request ) {
		$t = self::find( $request );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$payload = self::notice_payload( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		Helpdesk_Hero_Hub::enqueue( (int) $t['site_id'], (int) $t['id'], 'notice', $payload );
		return self::ticket( $request );
	}

	/**
	 * Ask for more time.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function extend( WP_REST_Request $request ) {
		$t = self::find( $request );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		Helpdesk_Hero_Hub::enqueue(
			(int) $t['site_id'],
			(int) $t['id'],
			'extension_request',
			array(
				'hours'  => max( 1, min( 24 * 30, (int) $request->get_param( 'hours' ) ) ),
				'reason' => sanitize_text_field( (string) $request->get_param( 'reason' ) ),
				'by'     => Helpdesk_Hero_Hub::agent_name(),
			)
		);
		return self::ticket( $request );
	}

	/**
	 * Fresh one-time login link for the agent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function login( WP_REST_Request $request ) {
		$t = self::find( $request );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$url = Helpdesk_Hero_Hub::login_url( $t );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		return array( 'url' => $url );
	}

	/**
	 * Sync one ticket with the help desk.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function sync( WP_REST_Request $request ) {
		$t = self::find( $request );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$relayed = Helpdesk_Hero_Hub::sync( (int) $t['id'] );
		return array_merge( self::ticket( $request ), array( 'relayed' => $relayed ) );
	}

	/**
	 * Support activity from the site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function activity( WP_REST_Request $request ) {
		$t = self::find( $request );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$result = Helpdesk_Hero_Hub::activity( $t );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$entries = array();
		foreach ( (array) ( $result['entries'] ?? array() ) as $e ) {
			$entries[] = array(
				'time' => self::iso( sanitize_text_field( (string) ( $e['time'] ?? '' ) ) ),
				'type' => sanitize_key( (string) ( $e['type'] ?? '' ) ),
				'text' => sanitize_text_field( (string) ( $e['text'] ?? '' ) ),
			);
		}
		return array( 'entries' => array_reverse( $entries ) );
	}

	/* ------------------------------------------------------------------------------------ */

	/**
	 * Site as JSON.
	 *
	 * @param array $s Site.
	 * @return array
	 */
	private static function site_json( array $s ) {
		return array(
			'id'             => (int) $s['id'],
			'label'          => $s['label'],
			'name'           => $s['name'] ? $s['name'] : $s['label'],
			'url'            => $s['url'],
			'contact_email'  => $s['contact_email'],
			'status'         => $s['status'],
			'invite_expires' => self::iso( $s['invite_expires'] ),
			'wordpress'      => $s['info']['wordpress'] ?? '',
			'version'        => $s['info']['version'] ?? '',
			'custom_policy'  => 'custom' === Helpdesk_Hero_Hub_Policy::source( $s )['type'],
			'policy_source'  => Helpdesk_Hero_Hub_Policy::source( $s ),
			'last_seen'      => self::iso( $s['last_seen'] ),
			'created_at'     => self::iso( $s['created_at'] ),
		);
	}

	/**
	 * Sites with open ticket counts.
	 *
	 * @return array
	 */
	public static function sites() {
		global $wpdb;
		$open = $wpdb->get_results( $wpdb->prepare( "SELECT site_id, COUNT(*) AS n FROM %i WHERE status <> 'closed' GROUP BY site_id", Helpdesk_Hero_Hub_DB::table( 'tickets' ) ), OBJECT_K ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$out  = array();
		foreach ( Helpdesk_Hero_Hub::sites() as $s ) {
			$out[] = array_merge( self::site_json( $s ), array( 'open_tickets' => isset( $open[ $s['id'] ] ) ? (int) $open[ $s['id'] ]->n : 0 ) );
		}
		return array( 'sites' => $out );
	}

	/**
	 * Create a connection code.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function invite( WP_REST_Request $request ) {
		$label = trim( sanitize_text_field( (string) $request->get_param( 'label' ) ) );
		if ( '' === $label ) {
			return new WP_Error( 'helpdesk_hero_hub_label', __( 'Enter the customer or site name.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		$invite = Helpdesk_Hero_Hub::create_invite( $label, sanitize_email( (string) $request->get_param( 'email' ) ), self::policy_choice( (string) $request->get_param( 'policy' ) ) );
		return array(
			'site' => self::site_json( Helpdesk_Hero_Hub::site( $invite['site_id'] ) ),
			'code' => $invite['code'],
		);
	}

	/**
	 * One site with its policy.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function site( WP_REST_Request $request ) {
		$s = Helpdesk_Hero_Hub::site( (int) $request['id'] );
		if ( ! $s ) {
			return new WP_Error( 'helpdesk_hero_hub_site', __( 'Site not found.', 'helpdesk-hero-hub' ), array( 'status' => 404 ) );
		}
		return array_merge(
			self::site_json( $s ),
			array( 'policy' => Helpdesk_Hero_Hub_Policy::for_site( $s ) )
		);
	}

	/**
	 * Update a site's label or policy (policy null = use the team policy).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function update_site( WP_REST_Request $request ) {
		$s = Helpdesk_Hero_Hub::site( (int) $request['id'] );
		if ( ! $s ) {
			return new WP_Error( 'helpdesk_hero_hub_site', __( 'Site not found.', 'helpdesk-hero-hub' ), array( 'status' => 404 ) );
		}
		$data   = array();
		$params = $request->get_json_params();
		if ( isset( $params['label'] ) ) {
			$data['label'] = sanitize_text_field( (string) $params['label'] );
		}
		if ( array_key_exists( 'policy', (array) $params ) ) {
			if ( is_string( $params['policy'] ) ) {
				$data['policy'] = self::policy_choice( $params['policy'] );
			} else {
				$data['policy'] = is_array( $params['policy'] ) ? Helpdesk_Hero_Hub_Policy::sanitize( $params['policy'] ) : null;
			}
		}
		if ( $data ) {
			Helpdesk_Hero_Hub::update_site( (int) $s['id'], $data );
		}
		if ( array_key_exists( 'policy', $data ) && 'active' === $s['status'] ) {
			Helpdesk_Hero_Hub_Policy::push( (int) $s['id'] );
		}
		return self::site( $request );
	}

	/**
	 * Disconnect a site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function revoke_site( WP_REST_Request $request ) {
		Helpdesk_Hero_Hub::revoke_site( (int) $request['id'] );
		return array( 'ok' => true );
	}

	/**
	 * Check a site's connection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function ping( WP_REST_Request $request ) {
		$s = Helpdesk_Hero_Hub::site( (int) $request['id'] );
		if ( ! $s ) {
			return new WP_Error( 'helpdesk_hero_hub_site', __( 'Site not found.', 'helpdesk-hero-hub' ), array( 'status' => 404 ) );
		}
		$result = Helpdesk_Hero_Hub::site_request( $s, '/helpdesk-hero/v1/client/ping', 'GET' );
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'helpdesk_hero_hub_unreachable',
				/* translators: %s: error */
				sprintf( __( 'The site could not be reached: %s It can still send tickets; updates are delivered when it next checks in.', 'helpdesk-hero-hub' ), $result->get_error_message() ),
				array( 'status' => 502 )
			);
		}
		Helpdesk_Hero_Hub::update_site(
			(int) $s['id'],
			array(
				'name' => sanitize_text_field( (string) ( $result['name'] ?? $s['name'] ) ),
				'info' => array(
					'wordpress' => sanitize_text_field( (string) ( $result['wordpress'] ?? '' ) ),
					'version'   => sanitize_text_field( (string) ( $result['version'] ?? '' ) ),
				),
			)
		);
		Helpdesk_Hero_Hub::push( (int) $s['id'] );
		return self::site( $request );
	}

	/**
	 * Dashboard message to a site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function site_notice( WP_REST_Request $request ) {
		$payload = self::notice_payload( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		Helpdesk_Hero_Hub::enqueue( (int) $request['id'], 0, 'notice', $payload );
		return array( 'ok' => true );
	}

	/* ------------------------------------------------------------------------------------ */

	/**
	 * Team policy with the choices the editor needs.
	 *
	 * @return array
	 */
	public static function policy() {
		return array(
			'policy'    => Helpdesk_Hero_Hub_Policy::team(),
			'roles'     => Helpdesk_Hero_Hub_Policy::roles(),
			'sections'  => Helpdesk_Hero_Hub_Policy::sections(),
			'ratings'   => (bool) apply_filters( 'helpdesk_hero_hub_ratings_available', false ),
			'templates' => array_map(
				static function ( $tpl ) {
					return array(
						'id'     => $tpl['id'],
						'name'   => $tpl['name'],
						'policy' => $tpl['policy'],
					);
				},
				Helpdesk_Hero_Hub_Templates::all()
			),
		);
	}

	/**
	 * Save the team policy.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function save_policy( WP_REST_Request $request ) {
		Helpdesk_Hero_Hub_Policy::save_team( (array) $request->get_json_params() );
		return self::policy();
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$s        = Helpdesk_Hero_Hub_Settings::all();
		$helpdesk = Helpdesk_Hero_Hub_Helpdesk::current();
		return array(
			'settings' => array(
				'team_name'    => $s['team_name'],
				'notify_email' => $s['notify_email'],
			),
			'helpdesk' => $helpdesk ? $helpdesk->label() : '',
			'defaults' => array(
				'team_name'    => Helpdesk_Hero_Hub_Settings::team_name(),
				'notify_email' => (string) get_option( 'admin_email' ),
			),
		);
	}

	/**
	 * Save settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function save_settings( WP_REST_Request $request ) {
		$p        = (array) $request->get_json_params();
		$old_name = Helpdesk_Hero_Hub_Settings::team_name();
		Helpdesk_Hero_Hub_Settings::update(
			array(
				'team_name'    => sanitize_text_field( (string) ( $p['team_name'] ?? '' ) ),
				'notify_email' => sanitize_email( (string) ( $p['notify_email'] ?? '' ) ),
			)
		);
		// A new team name reaches customers with the policy.
		if ( Helpdesk_Hero_Hub_Settings::team_name() !== $old_name ) {
			foreach ( Helpdesk_Hero_Hub::sites() as $site ) {
				if ( 'active' === $site['status'] ) {
					Helpdesk_Hero_Hub_Policy::push( (int) $site['id'] );
				}
			}
		}
		return self::settings();
	}

	/* ------------------------------------------------------------------------------------ */

	/**
	 * Policy choice from a string: "default", or "template:<id>".
	 *
	 * @param string $choice Choice.
	 * @return array|null Site policy column value.
	 */
	private static function policy_choice( $choice ) {
		if ( 0 === strpos( $choice, 'template:' ) ) {
			$id = sanitize_key( substr( $choice, 9 ) );
			return Helpdesk_Hero_Hub_Templates::get( $id ) ? array( 'template' => $id ) : null;
		}
		return null;
	}

	/**
	 * Apply the team default or a template to several sites.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function bulk_policy( WP_REST_Request $request ) {
		$policy = self::policy_choice( (string) $request->get_param( 'policy' ) );
		$count  = 0;
		foreach ( array_map( 'intval', (array) $request->get_param( 'ids' ) ) as $id ) {
			$site = Helpdesk_Hero_Hub::site( $id );
			if ( ! $site || 'revoked' === $site['status'] ) {
				continue;
			}
			Helpdesk_Hero_Hub::update_site( $id, array( 'policy' => $policy ) );
			if ( 'active' === $site['status'] ) {
				Helpdesk_Hero_Hub_Policy::push( $id );
			}
			++$count;
		}
		return array_merge( self::sites(), array( 'updated' => $count ) );
	}

	/**
	 * Set a ticket's tags.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function ticket_tags( WP_REST_Request $request ) {
		$t = self::find( $request );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		Helpdesk_Hero_Hub::set_tags( $t, (array) $request->get_param( 'tags' ) );
		return self::ticket( $request );
	}

	/**
	 * Tags.
	 *
	 * @return array
	 */
	public static function tags() {
		return array(
			'tags'   => Helpdesk_Hero_Hub_Tags::all(),
			'colors' => Helpdesk_Hero_Hub_Tags::colors(),
		);
	}

	/**
	 * Save the tag list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function save_tags( WP_REST_Request $request ) {
		Helpdesk_Hero_Hub_Tags::save( (array) $request->get_param( 'tags' ) );
		return self::tags();
	}

	/**
	 * Templates with the sites that use them.
	 *
	 * @return array
	 */
	public static function templates() {
		$out = array();
		foreach ( Helpdesk_Hero_Hub_Templates::all() as $tpl ) {
			$out[] = array_merge( $tpl, array( 'sites' => count( Helpdesk_Hero_Hub_Templates::sites_using( $tpl['id'] ) ) ) );
		}
		return array(
			'templates' => $out,
			'roles'     => Helpdesk_Hero_Hub_Policy::roles(),
			'sections'  => Helpdesk_Hero_Hub_Policy::sections(),
			'ratings'   => (bool) apply_filters( 'helpdesk_hero_hub_ratings_available', false ),
		);
	}

	/**
	 * Create or update a template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function save_template( WP_REST_Request $request ) {
		$saved = Helpdesk_Hero_Hub_Templates::save( (array) $request->get_json_params() );
		return is_wp_error( $saved ) ? $saved : array_merge( self::templates(), array( 'saved' => $saved['id'] ) );
	}

	/**
	 * Delete a template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function delete_template( WP_REST_Request $request ) {
		Helpdesk_Hero_Hub_Templates::delete( sanitize_key( (string) $request['id'] ) );
		return self::templates();
	}

	/**
	 * Overview statistics (last 30 days, all sites).
	 *
	 * @return array
	 */
	public static function stats() {
		return Helpdesk_Hero_Hub_Stats::overview( 30 );
	}

	/**
	 * Finish or restart the setup guide.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function onboarding( WP_REST_Request $request ) {
		update_option( Helpdesk_Hero_Hub_Admin::ONBOARDING, $request->get_param( 'done' ) ? 'done' : 'pending', false );
		return array( 'done' => 'done' === get_option( Helpdesk_Hero_Hub_Admin::ONBOARDING ) );
	}

	/**
	 * Hide the feedback invitation for this user.
	 *
	 * @return array
	 */
	public static function feedback() {
		update_user_meta( get_current_user_id(), 'helpdesk_hero_hub_feedback', time() );
		return array( 'ok' => true );
	}

	/**
	 * Download a backup of the whole hub.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function backup( WP_REST_Request $request ) {
		return Helpdesk_Hero_Hub_Backup::export( '0' !== (string) $request->get_param( 'secrets' ) );
	}

	/**
	 * Restore a backup (replaces everything in the hub).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function restore( WP_REST_Request $request ) {
		return Helpdesk_Hero_Hub_Backup::import( $request->get_param( 'backup' ) );
	}

	/**
	 * Unread tickets, for the badge and live notices in the hub.
	 *
	 * @return array
	 */
	public static function unread() {
		$u = Helpdesk_Hero_Hub::unread_tickets( 5 );
		foreach ( $u['latest'] as &$t ) {
			$t['at'] = self::iso( $t['at'] );
		}
		return $u;
	}
}
