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
			array( '/admin/tickets/(?P<id>\d+)/end-access', 'POST', 'end_access', $support ),
			array( '/admin/tickets/(?P<id>\d+)/bookmark', 'POST', 'bookmark', $agent ),
			array( '/admin/me', 'POST', 'save_me', $agent ),
			array( '/admin/tickets/(?P<id>\d+)/sync', 'POST', 'sync', $agent ),
			array( '/admin/tickets/(?P<id>\d+)/assign', 'POST', 'assign', $agent ),
			array( '/admin/tickets/(?P<id>\d+)/note', 'POST', 'note', $agent ),
			array( '/admin/tickets/bulk', 'POST', 'bulk', $agent ),
			array( '/admin/tickets/(?P<id>\d+)', 'DELETE', 'delete_ticket', $admin ),
			array( '/admin/tickets/delete', 'POST', 'delete_tickets', $admin ),
			array( '/admin/tickets/export', 'GET', 'export_tickets', $admin ),
			array( '/admin/tickets/import', 'POST', 'import_tickets', $admin ),
			array( '/admin/cleanup', 'GET', 'cleanup', $admin ),
			array( '/admin/cleanup', 'POST', 'save_cleanup', $admin ),
			array( '/admin/cleanup/files/(?P<name>[a-z0-9-]+\.csv)', 'GET', 'cleanup_file', $admin ),
			array( '/admin/team', 'GET', 'team', $agent ),
			array( '/admin/attachments', 'POST', 'upload', $support ),
			array( '/admin/attachments/(?P<id>\d+)', 'GET', 'attachment', $agent ),
			array( '/admin/tickets/(?P<id>\d+)/activity', 'GET', 'activity', $agent ),
			array( '/admin/sites', 'GET', 'sites', $agent ),
			array( '/admin/sites', 'POST', 'invite', $agent ),
			array( '/admin/sites/(?P<id>\d+)', 'GET', 'site', $agent ),
			array( '/admin/sites/(?P<id>\d+)', 'POST', 'update_site', $admin ),
			array( '/admin/sites/(?P<id>\d+)', 'DELETE', 'revoke_site', $admin ),
			array( '/admin/sites/(?P<id>\d+)/ping', 'POST', 'ping', $agent ),
			array( '/admin/sites/(?P<id>\d+)/invite', 'POST', 'renew_invite', $agent ),
			array( '/admin/sites/(?P<id>\d+)/keys', 'POST', 'rotate_keys', $admin ),
			array( '/admin/sites/(?P<id>\d+)/notice', 'POST', 'site_notice', $support ),
			array( '/admin/tickets/(?P<id>\d+)/tags', 'POST', 'ticket_tags', $support ),
			array( '/admin/sites/policy', 'POST', 'bulk_policy', $admin ),
			array( '/admin/tags', 'GET', 'tags', $agent ),
			array( '/admin/tiers', 'GET', 'tiers', $agent ),
			array( '/admin/tiers', 'POST', 'save_tiers', $admin ),
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
			array( '/admin/backup/export', 'POST', 'backup', $admin ),
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
		$per_page = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 20 ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$orderby  = sanitize_key( (string) $request->get_param( 'orderby' ) );
		$args     = self::inbox_args( $request->get_params() );
		// Counts per status tab, with the other filters applied.
		$tabs = array();
		foreach ( array( 'active' => 'active', 'open' => 'open', 'pending' => 'pending', 'closed' => 'closed', 'all' => '' ) as $tab => $value ) {
			$tabs[ $tab ] = Helpdesk_Hero_Hub::count_tickets( array_merge( $args, array( 'status' => $value ) ) );
		}
		$total    = Helpdesk_Hero_Hub::count_tickets( $args );
		$page     = min( $page, max( 1, (int) ceil( $total / $per_page ) ) );
		$rows     = Helpdesk_Hero_Hub::tickets(
			array_merge(
				$args,
				array(
					'orderby' => in_array( $orderby, Helpdesk_Hero_Hub::ticket_sorts(), true ) ? $orderby : 'updated',
					'order'   => 'asc' === $request->get_param( 'order' ) ? 'asc' : 'desc',
					'limit'   => $per_page,
					'offset'  => ( $page - 1 ) * $per_page,
				)
			)
		);
		// A failing query shows its error instead of an empty list (it was silently empty).
		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error(
				'helpdesk_hero_hub_query',
				/* translators: %s: database error */
				sprintf( __( 'The tickets could not be loaded: %s', 'helpdesk-hero-hub' ), $wpdb->last_error ),
				array( 'status' => 500 )
			);
		}
		$tickets = array();
		foreach ( $rows as $t ) {
			$tickets[] = self::ticket_row( $t );
		}
		$tickets = self::row_extras( $tickets );
		$table   = Helpdesk_Hero_Hub_DB::table( 'tickets' );
		$counts = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status = 'open') AS open, SUM(status = 'pending') AS pending, SUM(status <> 'closed' AND access_expires > %s) AS access FROM %i", Helpdesk_Hero_Hub_DB::now(), $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// "My work": the shortcuts at the top of the Inbox, whatever the filters.
		$mywork = array();
		foreach (
			array(
				'mine'       => array( 'assignee' => get_current_user_id() ),
				'unassigned' => array( 'assignee' => 0 ),
				'unread'     => array( 'unread' => true ),
				'bookmarked' => array( 'bookmarked' => true ),
				'overdue'    => array( 'sla' => 'overdue' ),
				'soon'       => array( 'sla' => 'soon' ),
				'ending'     => array( 'access' => 'ending' ),
			) as $key => $extra
		) {
			$mywork[ $key ] = Helpdesk_Hero_Hub::count_tickets( array_merge( array( 'status' => 'active' ), $extra ) );
		}
		return array(
			'tickets'  => $tickets,
			'tabs'     => $tabs,
			'mywork'   => $mywork,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'counts'   => array(
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
	 * Inbox filters from request parameters (also used by Pro's views).
	 *
	 * @param array $p Parameters: status, custom_status, search, site, tier, service, priority,
	 *                 from, to, assignee (me | 0 | user ID), tag, rating_max, rating_min, access,
	 *                 unread, sla.
	 * @return array Arguments for Helpdesk_Hero_Hub::tickets() and count_tickets().
	 */
	public static function inbox_args( array $p ) {
		$status   = sanitize_key( (string) ( $p['status'] ?? '' ) );
		$assignee = $p['assignee'] ?? null;
		$args     = array(
			'status'        => in_array( $status, array( 'open', 'pending', 'closed' ), true ) ? $status : ( 'all' === $status ? '' : 'active' ),
			'custom_status' => sanitize_key( (string) ( $p['custom_status'] ?? '' ) ),
			'search'        => sanitize_text_field( (string) ( $p['search'] ?? '' ) ),
			'site_id'       => (int) ( $p['site'] ?? 0 ),
			'tier'          => sanitize_key( (string) ( $p['tier'] ?? '' ) ),
			'service'       => sanitize_key( (string) ( $p['service'] ?? '' ) ),
			'priority'      => sanitize_key( (string) ( $p['priority'] ?? '' ) ),
			'from'          => sanitize_text_field( (string) ( $p['from'] ?? '' ) ),
			'to'            => sanitize_text_field( (string) ( $p['to'] ?? '' ) ),
			'assignee'      => 'me' === $assignee ? get_current_user_id() : ( null === $assignee || '' === $assignee ? '' : (int) $assignee ),
			'tag'           => sanitize_key( (string) ( $p['tag'] ?? '' ) ),
			'category'      => sanitize_text_field( (string) ( $p['category'] ?? '' ) ),
			'rating_max'    => (int) ( $p['rating_max'] ?? 0 ),
			'rating_min'    => (int) ( $p['rating_min'] ?? 0 ),
			'access'        => 'ending' === ( $p['access'] ?? '' ) ? 'ending' : ! empty( $p['access'] ) && 'false' !== $p['access'],
			'unread'        => ! empty( $p['unread'] ) && 'false' !== $p['unread'],
			'bookmarked'    => ! empty( $p['bookmarked'] ) && 'false' !== $p['bookmarked'],
			'sla'           => sanitize_key( (string) ( $p['sla'] ?? '' ) ),
		);
		return $args;
	}

	/**
	 * Extra facts for ticket rows, looked up for all rows at once: tickets merged into them,
	 * whether the current person bookmarked them, and anything add-ons add (Pro: billing state).
	 *
	 * @param array[] $rows Rows with an id.
	 * @return array[]
	 */
	public static function row_extras( array $rows ) {
		global $wpdb;
		$ids = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		if ( ! $ids ) {
			return $rows;
		}
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$merged = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, merged_into FROM %i WHERE merged_into IN ($in) ORDER BY id ASC", array_merge( array( Helpdesk_Hero_Hub_DB::table( 'tickets' ) ), $ids ) ), ARRAY_A ) as $m ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$merged[ (int) $m['merged_into'] ][] = (int) $m['id'];
		}
		$marks = Helpdesk_Hero_Hub::bookmarks();
		foreach ( $rows as &$row ) {
			$row['merged_from'] = $merged[ (int) $row['id'] ] ?? array();
			$row['bookmarked']  = in_array( (int) $row['id'], $marks, true );
		}
		unset( $row );
		/**
		 * Filters ticket rows before they go to the hub's screens (Pro adds `billing`).
		 *
		 * @param array[] $rows Rows.
		 */
		return (array) apply_filters( 'helpdesk_hero_hub_ticket_rows', $rows );
	}

	/**
	 * The current person's own preferences.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function save_me( WP_REST_Request $request ) {
		if ( null !== $request->get_param( 'assign_on_reply' ) ) {
			update_user_meta( get_current_user_id(), 'helpdesk_hero_hub_assign_on_reply', rest_sanitize_boolean( $request->get_param( 'assign_on_reply' ) ) ? '1' : '0' );
		}
		return array( 'assign_on_reply' => Helpdesk_Hero_Hub::assign_on_reply() );
	}

	/**
	 * Bookmark or un-bookmark a ticket for the current person.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function bookmark( WP_REST_Request $request ) {
		$t = self::find( $request );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		return array( 'bookmarked' => Helpdesk_Hero_Hub::set_bookmark( (int) $t['id'], rest_sanitize_boolean( $request->get_param( 'on' ) ) ) );
	}

	/**
	 * A ticket as an inbox row.
	 *
	 * @param array $t Ticket row from Helpdesk_Hero_Hub::tickets().
	 * @return array
	 */
	private static function ticket_row( array $t ) {
		$levels = array_count_values( array_column( $t['flags'], 'level' ) );
		return array(
			'id'             => (int) $t['id'],
			'subject'        => $t['subject'],
			'status'         => $t['status'],
			'custom_status'  => Helpdesk_Hero_Hub::custom_status( (string) $t['custom_status'] ),
			'service'        => Helpdesk_Hero_Hub::service( (string) $t['service'] ),
			'priority'       => $t['priority'],
			'customer'       => $t['customer_name'] ? $t['customer_name'] : $t['customer_email'],
			'site_id'        => (int) $t['site_id'],
			'site_name'      => (string) ( $t['site_name'] ? $t['site_name'] : $t['site_label'] ),
			'site_host'      => (string) wp_parse_url( (string) $t['site_url'], PHP_URL_HOST ),
			'tier'           => Helpdesk_Hero_Hub_Tiers::badge( (string) $t['site_tier'] ),
			'assignee'       => self::user_badge( (int) $t['assignee_id'] ),
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
			'sla'            => Helpdesk_Hero_Hub::sla_state( $t ),
			'created_at'     => self::iso( $t['created_at'] ),
			'updated_at'     => self::iso( $t['updated_at'] ),
		);
	}

	/**
	 * Attachments of a message, for the dashboard.
	 *
	 * @param array $list Attachment records.
	 * @return array[] { id, name, size, type, url, image }
	 */
	private static function attachments_json( array $list ) {
		$out = array();
		foreach ( $list as $a ) {
			if ( empty( $a['id'] ) ) {
				continue;
			}
			$out[] = array(
				'id'    => (int) $a['id'],
				'name'  => (string) ( $a['name'] ?? '' ),
				'size'  => (int) ( $a['size'] ?? 0 ),
				'type'  => (string) ( $a['type'] ?? '' ),
				'url'   => Helpdesk_Hero_Hub_Attachments::url( (int) $a['id'] ),
				'image' => 0 === strpos( (string) ( $a['type'] ?? '' ), 'image/' ),
			);
		}
		return $out;
	}

	/**
	 * A hub user for display (or null).
	 *
	 * @param int $user_id User.
	 * @return array|null { id, name }
	 */
	private static function user_badge( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : false;
		return $user ? array(
			'id'   => (int) $user->ID,
			'name' => $user->display_name,
		) : null;
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

		// Problem spots (Pinpoint), and how many came with each message.
		$spots    = Helpdesk_Hero_Hub::pinpoints( $t );
		$by_msg   = array_count_values( array_map( 'strval', wp_list_pluck( $spots, 'source' ) ) );
		$thread   = array(
			array(
				'id'          => 'opening',
				'kind'        => 'customer',
				'author'      => $t['customer_name'] ? $t['customer_name'] : $t['customer_email'],
				'body'        => (string) $t['description'],
				'time'        => self::iso( $t['created_at'] ),
				'attachments' => self::attachments_json( Helpdesk_Hero_Hub_Attachments::opening( (int) $t['id'] ) ),
				'spots'       => (int) ( $by_msg['opening'] ?? 0 ),
			),
		);
		// Tickets merged into this one: their messages join the conversation.
		$merged  = Helpdesk_Hero_Hub::merged_from( (int) $t['id'] );
		$history = Helpdesk_Hero_Hub::history( (int) $t['id'] );
		foreach ( $merged as $m ) {
			// Problem spots of merged tickets show here too, each still with its own message.
			$theirs = Helpdesk_Hero_Hub::ticket( (int) $m['id'] );
			foreach ( $theirs ? Helpdesk_Hero_Hub::pinpoints( $theirs ) : array() as $pin ) {
				$pin['source'] = 'opening' === $pin['source'] ? 'opening-' . (int) $m['id'] : $pin['source'];
				$pin['from']   = (int) $m['id'];
				$spots[]       = $pin;
			}
			$by_msg   = array_count_values( array_map( 'strval', wp_list_pluck( $spots, 'source' ) ) );
			$thread[] = array(
				'id'          => 'opening-' . (int) $m['id'],
				'kind'        => 'customer',
				'author'      => $m['customer_name'] ? $m['customer_name'] : $m['customer_email'],
				/* translators: 1: ticket number, 2: subject, 3: message */
				'body'        => sprintf( __( "From #%1\$d “%2\$s”:\n\n%3\$s", 'helpdesk-hero-hub' ), (int) $m['id'], $m['subject'], $m['description'] ),
				'time'        => self::iso( $m['created_at'] ),
				'attachments' => self::attachments_json( Helpdesk_Hero_Hub_Attachments::opening( (int) $m['id'] ) ),
				'spots'       => (int) ( $by_msg[ 'opening-' . (int) $m['id'] ] ?? 0 ),
			);
			foreach ( Helpdesk_Hero_Hub::history( (int) $m['id'] ) as $event ) {
				if ( in_array( $event['type'], array( 'reply', 'customer_reply', 'internal_note' ), true ) ) {
					$history[] = $event;
				}
			}
		}
		if ( $merged ) {
			usort(
				$history,
				static function ( $a, $b ) {
					return strcmp( $a['created_at'], $b['created_at'] ) ?: $a['id'] <=> $b['id']; // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
				}
			);
			usort(
				$thread,
				static function ( $a, $b ) {
					return strcmp( (string) $a['time'], (string) $b['time'] );
				}
			);
		}
		foreach ( $history as $event ) {
			$p = $event['payload'];
			if ( in_array( $event['type'], array( 'reply', 'customer_reply', 'internal_note' ), true ) ) {
				$kinds    = array(
					'reply'          => 'support',
					'customer_reply' => 'customer',
					'internal_note'  => 'note',
				);
				$thread[] = array(
					'id'          => (int) $event['id'],
					'kind'        => $kinds[ $event['type'] ],
					'author'      => (string) ( $p['author'] ?? '' ),
					'body'        => (string) ( $p['body'] ?? '' ),
					'time'        => self::iso( $event['created_at'] ),
					'attachments' => self::attachments_json( (array) ( $p['attachments'] ?? array() ) ),
					'spots'       => (int) ( $by_msg[ (string) $event['id'] ] ?? 0 ),
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

		$extras = self::row_extras( array( array( 'id' => (int) $t['id'] ) ) )[0];
		return array(
			'id'          => (int) $t['id'],
			'subject'     => $t['subject'],
			'status'      => $t['status'],
			'bookmarked'  => $extras['bookmarked'],
			'billing'     => $extras['billing'] ?? null,
			'custom_status' => Helpdesk_Hero_Hub::custom_status( (string) $t['custom_status'] ),
			'service'     => Helpdesk_Hero_Hub::service( (string) $t['service'] ),
			'assignee'    => self::user_badge( (int) $t['assignee_id'] ),
			'sla'         => Helpdesk_Hero_Hub::sla_state( $t ),
			'merged_into' => (int) $t['merged_into'] ? array(
				'id'      => (int) $t['merged_into'],
				'subject' => (string) ( Helpdesk_Hero_Hub::ticket( (int) $t['merged_into'] )['subject'] ?? '' ),
			) : null,
			'merged_from' => array_map(
				static function ( $m ) {
					return array(
						'id'      => (int) $m['id'],
						'subject' => $m['subject'],
					);
				},
				Helpdesk_Hero_Hub::merged_from( (int) $t['id'] )
			),
			'fields'      => Helpdesk_Hero_Hub::field_values( $t ),
			'attachments' => Helpdesk_Hero_Hub_Attachments::config(),
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
				'tier'      => Helpdesk_Hero_Hub_Tiers::badge( (string) $site['tier'] ),
				'notes'     => (string) $site['notes'],
				'version'   => $site['info']['version'] ?? '',
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
			'pinpoints'   => array_map(
				static function ( $pin ) {
					return array_merge( $pin, array( 'highlight_url' => add_query_arg( 'hdh_pin', $pin['id'], $pin['url'] ) ) );
				},
				$spots
			),
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
			case 'end_access':
				/* translators: %s: agent */
				return sprintf( __( '%s ended site access', 'helpdesk-hero-hub' ), $p['by'] ?? '' );
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
					'changed'            => __( 'Customer changed access', 'helpdesk-hero-hub' ),
					'support_ended'      => __( 'Support ended access', 'helpdesk-hero-hub' ),
					'closed'             => __( 'Access ended because the tickets were closed', 'helpdesk-hero-hub' ),
					'expired'            => __( 'Access expired', 'helpdesk-hero-hub' ),
					'deleted'            => __( 'Access ended: the customer deleted a support account', 'helpdesk-hero-hub' ),
					'login'              => __( 'Support login used', 'helpdesk-hero-hub' ),
					'extension_declined' => __( 'Customer declined the request for more time', 'helpdesk-hero-hub' ),
				);
				return $events[ $p['event'] ?? '' ] ?? '';
			case 'emailed':
				return __( 'The customer emailed this ticket and registered it here', 'helpdesk-hero-hub' );
			case 'merged_into':
				/* translators: 1: ticket number, 2: subject */
				return sprintf( __( 'Merged into #%1$d “%2$s”', 'helpdesk-hero-hub' ), (int) ( $p['id'] ?? 0 ), $p['subject'] ?? '' );
			case 'merged_from':
				/* translators: 1: ticket number, 2: subject */
				return sprintf( __( '#%1$d “%2$s” was merged into this ticket', 'helpdesk-hero-hub' ), (int) ( $p['id'] ?? 0 ), $p['subject'] ?? '' );
			case 'merged':
				return '';
			case 'custom_status':
				/* translators: %s: status */
				return sprintf( __( 'Status changed to %s', 'helpdesk-hero-hub' ), $p['label'] ?? '' );
			case 'sla_alert':
				return 'breached' === ( $p['kind'] ?? '' )
					? ( 'respond' === ( $p['stage'] ?? '' ) ? __( 'SLA missed: no first reply in time', 'helpdesk-hero-hub' ) : __( 'SLA missed: not resolved in time', 'helpdesk-hero-hub' ) )
					: ( 'respond' === ( $p['stage'] ?? '' ) ? __( 'SLA: first reply due within the hour', 'helpdesk-hero-hub' ) : __( 'SLA: resolution due within the hour', 'helpdesk-hero-hub' ) );
			case 'assigned':
				return ! empty( $p['to'] )
					/* translators: 1: person who assigned, 2: assignee */
					? sprintf( __( '%1$s assigned the ticket to %2$s', 'helpdesk-hero-hub' ), $p['by'] ?? '', $p['name'] ?? '' )
					/* translators: %s: person */
					: sprintf( __( '%s unassigned the ticket', 'helpdesk-hero-hub' ), $p['by'] ?? '' );
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
		$result = Helpdesk_Hero_Hub::agent_reply( $t, $body, self::reply_attachments( $request ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Unassigned tickets become yours when you reply, unless you turned that off.
		if ( ! (int) $t['assignee_id'] && Helpdesk_Hero_Hub::assign_on_reply() ) {
			Helpdesk_Hero_Hub::assign( $t, get_current_user_id() );
		}
		/**
		 * Fires after a supporter replies from the hub (Pro applies a macro's status and tags).
		 *
		 * @param array           $ticket  Ticket (before the reply).
		 * @param WP_REST_Request $request Request.
		 */
		do_action( 'helpdesk_hero_hub_reply_sent', $t, $request );
		return self::ticket( $request );
	}

	/**
	 * Files to attach to a reply (Pro uploads them first and sends their IDs).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array[] Attachment records.
	 */
	private static function reply_attachments( WP_REST_Request $request ) {
		/**
		 * Filters the attachments of a reply from the hub.
		 *
		 * @param array[]         $attachments Records { id, name, size, type }.
		 * @param WP_REST_Request $request     Request.
		 */
		$ids  = (array) $request->get_param( 'attachments' );
		$list = $ids && Helpdesk_Hero_Hub_Attachments::config()['enabled'] ? Helpdesk_Hero_Hub_Attachments::claim( self::find( $request ), $ids ) : array();
		return (array) apply_filters( 'helpdesk_hero_hub_reply_attachments', $list, $request );
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
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( 0 === strpos( $status, 'custom-' ) || $request->get_param( 'custom_status' ) ) {
			$done = Helpdesk_Hero_Hub::set_custom_status( $t, (string) ( $request->get_param( 'custom_status' ) ? $request->get_param( 'custom_status' ) : substr( $status, 7 ) ) );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		} else {
			Helpdesk_Hero_Hub::set_status( $t, $status );
		}
		return self::ticket( $request );
	}

	/**
	 * Assign a ticket.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function assign( WP_REST_Request $request ) {
		$t = self::find( $request );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$user = $request->get_param( 'user' );
		$done = Helpdesk_Hero_Hub::assign( $t, 'me' === $user ? get_current_user_id() : (int) $user );
		return is_wp_error( $done ) ? $done : self::ticket( $request );
	}

	/**
	 * Add an internal note.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function note( WP_REST_Request $request ) {
		$t = self::find( $request );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$body = trim( sanitize_textarea_field( (string) $request->get_param( 'body' ) ) );
		if ( '' === $body ) {
			return new WP_Error( 'helpdesk_hero_hub_empty', __( 'Write a note first.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		Helpdesk_Hero_Hub::add_note( $t, $body );
		return self::ticket( $request );
	}

	/**
	 * Upload a file to send with a reply.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error Record.
	 */
	public static function upload( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new WP_Error( 'helpdesk_hero_files', __( 'Choose a file.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		return Helpdesk_Hero_Hub_Attachments::upload( $files['file'] );
	}

	/**
	 * Open a file (fetched from the customer's site the first time).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|void Streams the file.
	 */
	public static function attachment( WP_REST_Request $request ) {
		$row = Helpdesk_Hero_Hub_Attachments::get( (int) $request['id'] );
		if ( ! $row || ( ! (int) $row['ticket_id'] && (int) $row['uploaded_by'] !== get_current_user_id() ) ) {
			return new WP_Error( 'helpdesk_hero_file_gone', __( 'File not found.', 'helpdesk-hero-hub' ), array( 'status' => 404 ) );
		}
		$path = Helpdesk_Hero_Hub_Attachments::local_path( $row );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		Helpdesk_Hero_Hub_Attachments::send_file( $row, $path );
	}

	/**
	 * Filters for deleting and exporting, from a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private static function ticket_filters( WP_REST_Request $request ) {
		$f   = (array) $request->get_param( 'filters' );
		$out = array();
		foreach ( array( 'status', 'custom_status', 'priority', 'tier', 'service', 'from', 'to', 'search', 'closed_before', 'tag', 'sla' ) as $k ) {
			if ( isset( $f[ $k ] ) && '' !== (string) $f[ $k ] ) {
				$out[ $k ] = sanitize_text_field( (string) $f[ $k ] );
			}
		}
		if ( isset( $out['status'] ) && 'all' === $out['status'] ) {
			$out['status'] = '';
		}
		foreach ( array( 'rating_max', 'rating_min' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$out[ $k ] = (int) $f[ $k ];
			}
		}
		foreach ( array( 'access', 'unread' ) as $k ) {
			if ( ! empty( $f[ $k ] ) && 'false' !== $f[ $k ] ) {
				$out[ $k ] = true;
			}
		}
		if ( ! empty( $f['site'] ) ) {
			$out['site_id'] = (int) $f['site'];
		}
		if ( isset( $f['assignee'] ) && '' !== (string) $f['assignee'] ) {
			$out['assignee'] = 'me' === $f['assignee'] ? get_current_user_id() : (int) $f['assignee'];
		}
		if ( ! empty( $f['older_than_months'] ) ) {
			$out['closed_before'] = Helpdesk_Hero_Hub_Cleanup::cutoff( (int) $f['older_than_months'] );
		}
		return $out;
	}

	/**
	 * Delete one ticket.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function delete_ticket( WP_REST_Request $request ) {
		return Helpdesk_Hero_Hub_Cleanup::delete( (int) $request['id'] ) ? array( 'deleted' => 1 ) : new WP_Error( 'helpdesk_hero_hub_ticket', __( 'Ticket not found.', 'helpdesk-hero-hub' ), array( 'status' => 404 ) );
	}

	/**
	 * Delete selected tickets, or up to 200 matching filters per call (call again until
	 * "remaining" is 0). With dry_run, only counts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array { deleted, remaining } or { count }
	 */
	public static function delete_tickets( WP_REST_Request $request ) {
		$ids = array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) );
		if ( ! $ids ) {
			$filters = self::ticket_filters( $request );
			if ( ! $filters ) {
				return new WP_Error( 'helpdesk_hero_hub_filters', __( 'Choose at least one filter, so not everything is deleted by mistake.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
			}
			if ( $request->get_param( 'dry_run' ) ) {
				return array( 'count' => Helpdesk_Hero_Hub_Cleanup::match( $filters, 0 ) );
			}
			$ids = Helpdesk_Hero_Hub_Cleanup::match( $filters, 200 );
		}
		$deleted = 0;
		foreach ( array_slice( $ids, 0, 500 ) as $id ) {
			if ( Helpdesk_Hero_Hub_Cleanup::delete( $id ) ) {
				++$deleted;
			}
		}
		return array(
			'deleted'   => $deleted,
			'remaining' => isset( $filters ) ? Helpdesk_Hero_Hub_Cleanup::match( $filters, 0 ) : 0,
		);
	}

	/**
	 * Download tickets as CSV: selected (ids=1,2,3) or matching filters (filters[...]).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|void Streams the file.
	 */
	public static function export_tickets( WP_REST_Request $request ) {
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) );
		if ( ! $ids ) {
			$ids = Helpdesk_Hero_Hub_Cleanup::match( self::ticket_filters( $request ), 20000 );
		}
		Helpdesk_Hero_Hub_Cleanup::send_csv( 'helpdesk-hero-tickets-' . gmdate( 'Y-m-d' ) . '.csv', $ids );
	}

	/**
	 * Import tickets from an export.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function import_tickets( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file']['tmp_name'] ) ) {
			return new WP_Error( 'helpdesk_hero_hub_csv', __( 'Choose a CSV file.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		return Helpdesk_Hero_Hub_Cleanup::import_csv( (string) $files['file']['tmp_name'] );
	}

	/**
	 * Cleanup settings, what they'd remove now, and saved cleanup files.
	 *
	 * @return array
	 */
	public static function cleanup() {
		$s = Helpdesk_Hero_Hub_Cleanup::settings();
		return array(
			'settings' => $s,
			'matching' => Helpdesk_Hero_Hub_Cleanup::match( array( 'closed_before' => Helpdesk_Hero_Hub_Cleanup::cutoff( $s['months'] ) ), 0 ),
			'files'    => array_map(
				static function ( $f ) {
					return array_merge( $f, array( 'url' => add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'helpdesk-hero-hub/v1/admin/cleanup/files/' . $f['name'] ) ) ) );
				},
				Helpdesk_Hero_Hub_Cleanup::files()
			),
		);
	}

	/**
	 * Save cleanup settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function save_cleanup( WP_REST_Request $request ) {
		Helpdesk_Hero_Hub_Cleanup::save( (array) $request->get_json_params() );
		return self::cleanup();
	}

	/**
	 * Download a file saved by automatic cleanup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|void Streams the file.
	 */
	public static function cleanup_file( WP_REST_Request $request ) {
		$name = (string) $request['name'];
		foreach ( Helpdesk_Hero_Hub_Cleanup::files() as $f ) {
			if ( $f['name'] === $name ) {
				$dir = Helpdesk_Hero_Hub_Attachments::dir();
				Helpdesk_Hero_Hub_Cleanup::send_csv( $name, array(), $dir . 'cleanup/' . $name );
			}
		}
		return new WP_Error( 'helpdesk_hero_hub_csv', __( 'File not found.', 'helpdesk-hero-hub' ), array( 'status' => 404 ) );
	}

	/**
	 * The team, for assigning.
	 *
	 * @return array
	 */
	public static function team() {
		return array( 'team' => Helpdesk_Hero_Hub::team() );
	}

	/**
	 * One action on many tickets: status, assign, add_tag, remove_tag (and add-on actions).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error { updated, skipped }
	 */
	public static function bulk( WP_REST_Request $request ) {
		$ids    = array_slice( array_unique( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ), 0, 500 );
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$value  = $request->get_param( 'value' );
		// Changes customers see need a supporter, as on a single ticket.
		if ( in_array( $action, array( 'status', 'custom_status', 'add_tag', 'remove_tag' ), true ) && ! Helpdesk_Hero_Hub::can_support() ) {
			return new WP_Error( 'helpdesk_hero_hub_not_supporter', __( 'Only the supporters your team has chosen can change what customers see.', 'helpdesk-hero-hub' ), array( 'status' => 403 ) );
		}
		$updated = 0;
		$skipped = 0;
		foreach ( $ids as $id ) {
			$t = $id ? Helpdesk_Hero_Hub::ticket( $id ) : null;
			if ( ! $t ) {
				++$skipped;
				continue;
			}
			$result = true;
			switch ( $action ) {
				case 'status':
					Helpdesk_Hero_Hub::set_status( $t, sanitize_key( (string) $value ) );
					break;
				case 'custom_status':
					$result = Helpdesk_Hero_Hub::set_custom_status( $t, (string) $value );
					break;
				case 'assign':
					$result = Helpdesk_Hero_Hub::assign( $t, 'me' === $value ? get_current_user_id() : (int) $value );
					break;
				case 'add_tag':
				case 'remove_tag':
					$tag  = sanitize_key( (string) $value );
					$tags = array_values( array_diff( (array) $t['tags'], array( $tag ) ) );
					if ( 'add_tag' === $action ) {
						$tags[] = $tag;
					}
					Helpdesk_Hero_Hub::set_tags( $t, $tags );
					break;
				default:
					/**
					 * Filters the result of a bulk action add-ons handle (true, or a WP_Error).
					 *
					 * @param true|WP_Error|null $result null when nobody handled it.
					 * @param string             $action Action.
					 * @param array              $ticket Ticket.
					 * @param mixed              $value  Value.
					 */
					$result = apply_filters( 'helpdesk_hero_hub_bulk_action', null, $action, $t, $value );
					if ( null === $result ) {
						return new WP_Error( 'helpdesk_hero_hub_bulk', __( 'Unknown bulk action.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
					}
			}
			if ( is_wp_error( $result ) ) {
				++$skipped;
			} else {
				++$updated;
			}
		}
		return array(
			'updated' => $updated,
			'skipped' => $skipped,
		);
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
	 * Support ends the site's access (for every ticket of that site). The site deletes the
	 * support accounts when it receives this.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function end_access( WP_REST_Request $request ) {
		$t = self::find( $request );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		// Sent to the site straight away. If the site can't be reached right now, it picks the
		// message up the next time it checks in (and access still ends on its own when it expires).
		$item      = Helpdesk_Hero_Hub::enqueue( (int) $t['site_id'], (int) $t['id'], 'end_access', array( 'by' => Helpdesk_Hero_Hub::agent_name() ) );
		$site      = Helpdesk_Hero_Hub::site( (int) $t['site_id'] );
		$delivered = $site && (int) ( $site['info']['ack'] ?? 0 ) >= (int) $item;
		$ticket    = self::ticket( $request );
		return is_wp_error( $ticket ) ? $ticket : array_merge( $ticket, array( 'end_access_delivered' => $delivered ) );
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
			'tier'           => Helpdesk_Hero_Hub_Tiers::badge( (string) $s['tier'] ),
			'security'       => Helpdesk_Hero_Hub::security( $s ),
			'notes'          => (string) $s['notes'],
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
		if ( ! Helpdesk_Hero_Hub_Signer::secure_url( get_rest_url() ) ) {
			return new WP_Error( 'helpdesk_hero_insecure', __( 'Your hub doesn’t use HTTPS yet. Customer sites only connect over HTTPS, so connection keys and login links can’t be read on the way. Turn on HTTPS for this site (most hosts offer free certificates), then create the code.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		$invite = Helpdesk_Hero_Hub::create_invite( $label, sanitize_email( (string) $request->get_param( 'email' ) ), self::policy_choice( (string) $request->get_param( 'policy' ) ) );
		$extra  = array();
		if ( current_user_can( 'manage_options' ) && Helpdesk_Hero_Hub_Tiers::get( sanitize_key( (string) $request->get_param( 'tier' ) ) ) ) {
			$extra['tier'] = sanitize_key( (string) $request->get_param( 'tier' ) );
		}
		if ( '' !== trim( (string) $request->get_param( 'notes' ) ) ) {
			$extra['notes'] = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
		}
		if ( $extra ) {
			Helpdesk_Hero_Hub::update_site( $invite['site_id'], $extra );
		}
		return array(
			'site' => self::site_json( Helpdesk_Hero_Hub::site( $invite['site_id'] ) ),
			'code' => $invite['code'],
			'link' => $invite['link'],
		);
	}

	/**
	 * New code and link for a site that hasn't connected yet.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function renew_invite( WP_REST_Request $request ) {
		$invite = Helpdesk_Hero_Hub::renew_invite( (int) $request['id'] );
		if ( is_wp_error( $invite ) ) {
			return $invite;
		}
		return array(
			'site' => self::site_json( Helpdesk_Hero_Hub::site( $invite['site_id'] ) ),
			'code' => $invite['code'],
			'link' => $invite['link'],
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
			$label = trim( sanitize_text_field( (string) $params['label'] ) );
			if ( '' === $label ) {
				return new WP_Error( 'helpdesk_hero_hub_label', __( 'Enter the customer or site name.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
			}
			$data['label'] = $label;
		}
		if ( isset( $params['contact_email'] ) ) {
			$email = sanitize_email( (string) $params['contact_email'] );
			if ( '' !== trim( (string) $params['contact_email'] ) && '' === $email ) {
				return new WP_Error( 'helpdesk_hero_hub_email', __( 'Enter a valid email address.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
			}
			$data['contact_email'] = $email;
		}
		if ( isset( $params['notes'] ) ) {
			$data['notes'] = substr( sanitize_textarea_field( (string) $params['notes'] ), 0, 5000 );
		}
		if ( isset( $params['tier'] ) ) {
			$tier         = sanitize_key( (string) $params['tier'] );
			$data['tier'] = Helpdesk_Hero_Hub_Tiers::get( $tier ) ? $tier : '';
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
			if ( isset( $data['tier'] ) && $data['tier'] !== $s['tier'] ) {
				/**
				 * Fires when a site's tier changes (Pro recalculates SLA deadlines).
				 *
				 * @param int    $site_id Site.
				 * @param string $tier    New tier ('' for none).
				 * @param string $before  Previous tier.
				 */
				do_action( 'helpdesk_hero_hub_site_tier_changed', (int) $s['id'], $data['tier'], (string) $s['tier'] );
				if ( 'active' === $s['status'] ) {
					// The tier may come with a policy template.
					Helpdesk_Hero_Hub_Policy::push( (int) $s['id'] );
				}
			}
		}
		if ( array_key_exists( 'policy', $data ) && 'active' === $s['status'] ) {
			Helpdesk_Hero_Hub_Policy::push( (int) $s['id'] );
		}
		return self::site( $request );
	}

	/**
	 * Replace a site's connection keys.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function rotate_keys( WP_REST_Request $request ) {
		$s = Helpdesk_Hero_Hub::site( (int) $request['id'] );
		if ( ! $s || 'active' !== $s['status'] ) {
			return new WP_Error( 'helpdesk_hero_hub_site', __( 'Site not found.', 'helpdesk-hero-hub' ), array( 'status' => 404 ) );
		}
		$done = Helpdesk_Hero_Hub::rotate_keys( $s );
		return is_wp_error( $done ) ? $done : self::site( $request );
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
	 * Customer tiers.
	 *
	 * @return array
	 */
	public static function tiers() {
		return array( 'tiers' => Helpdesk_Hero_Hub_Tiers::all() );
	}

	/**
	 * Save customer tiers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function save_tiers( WP_REST_Request $request ) {
		return array( 'tiers' => Helpdesk_Hero_Hub_Tiers::save( (array) $request->get_param( 'tiers' ) ) );
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
		$default_sites = 0;
		foreach ( Helpdesk_Hero_Hub::sites() as $site ) {
			if ( 'default' === Helpdesk_Hero_Hub_Policy::source( $site )['type'] ) {
				++$default_sites;
			}
		}
		return array(
			'templates'     => $out,
			'default_sites' => $default_sites,
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
		$secrets    = '0' !== (string) $request->get_param( 'secrets' ) && false !== $request->get_param( 'secrets' );
		$passphrase = (string) $request->get_param( 'passphrase' );
		if ( 'GET' === $request->get_method() ) {
			// Passphrases never travel in URLs; GET gives a backup without keys.
			return Helpdesk_Hero_Hub_Backup::export( false );
		}
		if ( $secrets && strlen( $passphrase ) < 10 ) {
			return new WP_Error( 'helpdesk_hero_hub_passphrase', __( 'A backup with connection keys must be encrypted. Choose a passphrase of at least 10 characters.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		$data = Helpdesk_Hero_Hub_Backup::export( $secrets );
		return '' !== $passphrase ? Helpdesk_Hero_Hub_Backup::seal( $data, $passphrase ) : $data;
	}

	/**
	 * Restore a backup (replaces everything in the hub).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function restore( WP_REST_Request $request ) {
		$file = $request->get_param( 'backup' );
		$data = is_array( $file ) ? Helpdesk_Hero_Hub_Backup::open( $file, (string) $request->get_param( 'passphrase' ) ) : $file;
		return is_wp_error( $data ) ? $data : Helpdesk_Hero_Hub_Backup::import( $data );
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
