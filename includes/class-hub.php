<?php
/**
 * Support hub: connected sites, tickets, the outbox and help desk sync.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * The hub runs on the support team's own WordPress site. It holds the help desk credentials
 * (so customer sites never see them), receives signed tickets from connected sites, creates
 * them in Help Scout or Zendesk, and relays replies, status changes and messages back.
 *
 * Updates for a site are queued in an outbox with increasing IDs. The site pulls them (and the
 * hub also pushes them right away when it can), so nothing is lost if a site is briefly offline.
 */
final class Helpdesk_Hero_Hub {

	const CRON = 'helpdesk_hero_hub_sync';

	/**
	 * Outbox types delivered to sites. Other types are hub-only history.
	 *
	 * @var string[]
	 */
	const DELIVERED = array( 'reply', 'status', 'notice', 'extension_request', 'policy', 'reference', 'tags' );

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'sync' ) );
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_cap' ), 10, 2 );
	}

	/**
	 * Hub capability maps to manage_options unless filtered (Pro adds an Agent role).
	 *
	 * @param string[] $caps Caps.
	 * @param string   $cap  Cap.
	 * @return string[]
	 */
	public static function map_cap( $caps, $cap ) {
		if ( 'helpdesk_hero_hub' === $cap ) {
			/**
			 * Filters the primitive capability needed to use the support hub.
			 *
			 * @param string $capability Capability.
			 */
			return array( (string) apply_filters( 'helpdesk_hero_hub_capability', 'manage_options' ) );
		}
		return $caps;
	}

	/**
	 * Schedule help desk sync.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'helpdesk_hero_5min', self::CRON );
		}
	}

	/**
	 * Branding sent to sites.
	 *
	 * @return array
	 */
	public static function branding() {
		/**
		 * Filters the branding the hub sends to connected sites (name, logo, color, url, intro).
		 *
		 * @param array $branding Branding.
		 */
		return (array) apply_filters( 'helpdesk_hero_hub_branding', array( 'name' => Helpdesk_Hero_Hub_Settings::team_name() ) );
	}

	/* ---------------------------------------------------------------------------------------- *
	 * Sites.
	 * ---------------------------------------------------------------------------------------- */

	/**
	 * Create an invite and return its connection code. Codes work once and expire after 7 days.
	 *
	 * @param string $label Label for the hub's records (customer or site name).
	 * @param string $email Customer email (optional).
	 * @param array  $policy Site policy ({ template: id }, a custom policy, or null for the team default).
	 * @return array { site_id, code }
	 */
	public static function create_invite( $label, $email = '', $policy = null ) {
		global $wpdb;
		$token = bin2hex( random_bytes( 20 ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Helpdesk_Hero_Hub_DB::table( 'sites' ),
			array(
				'label'          => sanitize_text_field( $label ),
				'contact_email'  => sanitize_email( $email ),
				'status'         => 'pending',
				'invite_hash'    => hash( 'sha256', $token ),
				'invite_expires' => Helpdesk_Hero_Hub_DB::now( 7 * DAY_IN_SECONDS ),
				'policy'         => is_array( $policy ) ? wp_json_encode( $policy ) : null,
				'created_at'     => Helpdesk_Hero_Hub_DB::now(),
			)
		);
		return array(
			'site_id' => (int) $wpdb->insert_id,
			'code'    => self::make_code( $token ),
		);
	}

	/**
	 * Connection code: "hdh1." + base64url JSON with the hub's REST root, the invite token and the team name.
	 *
	 * @param string $token Invite token.
	 * @return string
	 */
	public static function make_code( $token ) {
		$json = wp_json_encode(
			array(
				'u' => get_rest_url(),
				't' => $token,
				'n' => Helpdesk_Hero_Hub_Settings::team_name(),
			)
		);
		return 'hdh1.' . rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe transport encoding of a JSON connection code.
	}

	/**
	 * Accept a pairing request from a site.
	 *
	 * @param string $token Invite token.
	 * @param array  $info  name, url, endpoint, email, wordpress, version.
	 * @return array|WP_Error Site row with the new secret.
	 */
	public static function pair( $token, array $info ) {
		global $wpdb;
		$table = Helpdesk_Hero_Hub_DB::table( 'sites' );
		$site  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE invite_hash = %s AND status = %s", $table, hash( 'sha256', (string) $token ), 'pending' ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $site || strtotime( $site['invite_expires'] . ' UTC' ) < time() ) {
			return new WP_Error( 'helpdesk_hero_invite', __( 'This connection code is not valid or has expired. Ask your support team for a new one.', 'helpdesk-hero-hub' ), array( 'status' => 403 ) );
		}
		$endpoint = esc_url_raw( (string) ( $info['endpoint'] ?? '' ) );
		if ( ! Helpdesk_Hero_Hub_Signer::valid_url( $endpoint ) ) {
			return new WP_Error( 'helpdesk_hero_endpoint', __( 'The site did not send a valid address.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		$secret = bin2hex( random_bytes( 32 ) );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'name'           => sanitize_text_field( (string) ( $info['name'] ?? '' ) ),
				'url'            => esc_url_raw( (string) ( $info['url'] ?? '' ) ),
				'endpoint'       => $endpoint,
				'contact_email'  => $site['contact_email'] ? $site['contact_email'] : sanitize_email( (string) ( $info['email'] ?? '' ) ),
				'secret'         => $secret,
				'status'         => 'active',
				'invite_hash'    => '',
				'invite_expires' => null,
				'info'           => wp_json_encode(
					array(
						'wordpress' => sanitize_text_field( (string) ( $info['wordpress'] ?? '' ) ),
						'version'   => sanitize_text_field( (string) ( $info['version'] ?? '' ) ),
						'ack'       => 0,
					)
				),
				'last_seen'      => Helpdesk_Hero_Hub_DB::now(),
			),
			array( 'id' => (int) $site['id'] )
		);
		do_action( 'helpdesk_hero_hub_site_connected', (int) $site['id'] );
		return self::site( (int) $site['id'] );
	}

	/**
	 * A site row (info decoded).
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function site( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Helpdesk_Hero_Hub_DB::table( 'sites' ), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $row ? self::decode_site( $row ) : null;
	}

	/**
	 * All sites.
	 *
	 * @return array[]
	 */
	public static function sites() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status <> 'revoked' ORDER BY id DESC", Helpdesk_Hero_Hub_DB::table( 'sites' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( array( __CLASS__, 'decode_site' ), $rows );
	}

	/**
	 * Decode a site row's JSON columns.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function decode_site( array $row ) {
		$row['info']   = $row['info'] ? (array) json_decode( $row['info'], true ) : array();
		$row['policy'] = $row['policy'] ? (array) json_decode( $row['policy'], true ) : null;
		return $row;
	}

	/**
	 * Update a site.
	 *
	 * @param int   $id   ID.
	 * @param array $data Columns (info as array is merged).
	 */
	public static function update_site( $id, array $data ) {
		global $wpdb;
		if ( array_key_exists( 'policy', $data ) ) {
			$data['policy'] = is_array( $data['policy'] ) ? wp_json_encode( $data['policy'] ) : null;
		}
		if ( isset( $data['info'] ) && is_array( $data['info'] ) ) {
			$site         = self::site( $id );
			$data['info'] = wp_json_encode( array_merge( $site ? $site['info'] : array(), $data['info'] ) );
		}
		$wpdb->update( Helpdesk_Hero_Hub_DB::table( 'sites' ), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Disconnect a site. Its secret stops working immediately.
	 *
	 * @param int $id ID.
	 */
	public static function revoke_site( $id ) {
		self::update_site(
			$id,
			array(
				'status'      => 'revoked',
				'secret'      => '',
				'invite_hash' => '',
			)
		);
	}

	/**
	 * Signed request to a site.
	 *
	 * @param array  $site    Site.
	 * @param string $route   Route.
	 * @param string $method  Method.
	 * @param array  $data    Data.
	 * @param int    $timeout Timeout.
	 * @return array|WP_Error
	 */
	public static function site_request( array $site, $route, $method, array $data = array(), $timeout = 15 ) {
		if ( 'active' !== $site['status'] || '' === $site['secret'] ) {
			return new WP_Error( 'helpdesk_hero_site', __( 'This site is not connected.', 'helpdesk-hero-hub' ) );
		}
		$result = Helpdesk_Hero_Hub_Signer::request( $site['endpoint'], $route, $method, $data, (string) $site['id'], $site['secret'], $timeout );
		if ( ! is_wp_error( $result ) ) {
			self::update_site( (int) $site['id'], array( 'last_seen' => Helpdesk_Hero_Hub_DB::now() ) );
		}
		return $result;
	}

	/* ---------------------------------------------------------------------------------------- *
	 * Tickets.
	 * ---------------------------------------------------------------------------------------- */

	/**
	 * A hub ticket (JSON columns decoded).
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function ticket( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Helpdesk_Hero_Hub_DB::table( 'tickets' ), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $row ? self::decode_ticket( $row ) : null;
	}

	/**
	 * Whether a ticket is waiting for the team to look at it: the customer opened it, or replied,
	 * since anyone on the team last opened it in the hub.
	 *
	 * @param array $ticket Ticket row.
	 * @return string '' | new | reply
	 */
	public static function unread( array $ticket ) {
		if ( empty( $ticket['customer_at'] ) || 'closed' === $ticket['status'] ) {
			return '';
		}
		if ( empty( $ticket['viewed_at'] ) ) {
			return 'new';
		}
		return strcmp( (string) $ticket['customer_at'], (string) $ticket['viewed_at'] ) > 0 ? 'reply' : '';
	}

	/**
	 * Unread tickets, newest first.
	 *
	 * @param int $limit Limit.
	 * @return array{count:int, latest:array[]}
	 */
	public static function unread_tickets( $limit = 5 ) {
		global $wpdb;
		$where = "status <> 'closed' AND customer_at IS NOT NULL AND ( viewed_at IS NULL OR viewed_at < customer_at )";
		$table = Helpdesk_Hero_Hub_DB::table( 'tickets' );
		// $where is a fixed string; the table name goes through %i.
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", $table ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$joined = "t.status <> 'closed' AND t.customer_at IS NOT NULL AND ( t.viewed_at IS NULL OR t.viewed_at < t.customer_at )";
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT t.id, t.subject, t.status, t.customer_at, t.viewed_at, t.customer_name, s.label AS site_name FROM %i t LEFT JOIN %i s ON s.id = t.site_id WHERE $joined ORDER BY t.customer_at DESC LIMIT %d", $table, Helpdesk_Hero_Hub_DB::table( 'sites' ), (int) $limit ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$latest = array();
		foreach ( $rows as $r ) {
			$latest[] = array(
				'id'       => (int) $r['id'],
				'subject'  => $r['subject'],
				'site'     => (string) $r['site_name'],
				'customer' => (string) $r['customer_name'],
				'kind'     => self::unread( $r + array( 'status' => $r['status'] ) ),
				'at'       => $r['customer_at'],
			);
		}
		return array(
			'count'  => $count,
			'latest' => $latest,
		);
	}

	/**
	 * Someone on the team opened a ticket.
	 *
	 * @param int $id Ticket.
	 */
	public static function mark_viewed( $id ) {
		global $wpdb;
		$wpdb->update( Helpdesk_Hero_Hub_DB::table( 'tickets' ), array( 'viewed_at' => Helpdesk_Hero_Hub_DB::now() ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Decode JSON columns.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function decode_ticket( array $row ) {
		foreach ( array( 'diagnostics', 'flags', 'seen_threads', 'tags' ) as $col ) {
			$row[ $col ] = $row[ $col ] ? (array) json_decode( $row[ $col ], true ) : array();
		}
		return $row;
	}

	/**
	 * Tickets for the inbox.
	 *
	 * @param array $args status ('' | active | closed), site_id, search, limit.
	 * @return array[]
	 */
	public static function tickets( array $args = array() ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		$status = $args['status'] ?? 'active';
		if ( 'active' === $status ) {
			$where[]  = 't.status <> %s';
			$params[] = 'closed';
		} elseif ( '' !== $status ) {
			$where[]  = 't.status = %s';
			$params[] = $status;
		}
		if ( ! empty( $args['site_id'] ) ) {
			$where[]  = 't.site_id = %d';
			$params[] = (int) $args['site_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(t.subject LIKE %s OR t.customer_email LIKE %s OR s.name LIKE %s OR s.url LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like, $like ) );
		}
		$params[] = (int) ( $args['limit'] ?? 100 );
		$params   = array_merge( array( Helpdesk_Hero_Hub_DB::table( 'tickets' ), Helpdesk_Hero_Hub_DB::table( 'sites' ) ), $params );
		$sql      = 'SELECT t.id, t.site_id, t.subject, t.status, t.priority, t.customer_name, t.customer_email, t.flags, t.helpdesk, t.helpdesk_id, t.helpdesk_number, t.access_expires, t.channel, t.tags, t.rating, t.category, t.customer_at, t.viewed_at, t.created_at, t.updated_at, s.name AS site_name, s.url AS site_url FROM %i t LEFT JOIN %i s ON s.id = t.site_id WHERE ' . implode( ' AND ', $where ) . ' ORDER BY t.updated_at DESC LIMIT %d';
		// $sql is built only from fixed strings and placeholders; every value goes through prepare(), the table name through %i.
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $rows as &$row ) {
			$row['flags'] = $row['flags'] ? (array) json_decode( $row['flags'], true ) : array();
			$row['tags']  = $row['tags'] ? (array) json_decode( $row['tags'], true ) : array();
		}
		return $rows;
	}

	/**
	 * Update a ticket.
	 *
	 * @param int   $id   ID.
	 * @param array $data Columns (arrays are JSON-encoded).
	 */
	public static function update_ticket( $id, array $data ) {
		global $wpdb;
		foreach ( array( 'diagnostics', 'flags', 'seen_threads', 'tags' ) as $col ) {
			if ( isset( $data[ $col ] ) && is_array( $data[ $col ] ) ) {
				$data[ $col ] = wp_json_encode( $data[ $col ] );
			}
		}
		$data['updated_at'] = $data['updated_at'] ?? Helpdesk_Hero_Hub_DB::now();
		$wpdb->update( Helpdesk_Hero_Hub_DB::table( 'tickets' ), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Store a ticket from a site and create it in the help desk.
	 *
	 * @param array $site    Site.
	 * @param array $payload Ticket payload from the site.
	 * @return array|WP_Error { hub_ticket_id, reference }
	 */
	public static function receive_ticket( array $site, array $payload ) {
		global $wpdb;
		$payload = self::unpack_bundle( $payload );
		$subject  = sanitize_text_field( (string) ( $payload['subject'] ?? '' ) );
		$body     = sanitize_textarea_field( (string) ( $payload['description'] ?? '' ) );
		$contact  = (array) ( $payload['contact'] ?? array() );
		$priority = in_array( $payload['priority'] ?? '', array( 'low', 'normal', 'high', 'urgent' ), true ) ? $payload['priority'] : 'normal';
		if ( '' === $subject || '' === $body ) {
			return new WP_Error( 'helpdesk_hero_ticket', __( 'Subject and description are required.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		$access  = (array) ( $payload['access'] ?? array() );
		$now     = Helpdesk_Hero_Hub_DB::now();
		$channel = 'email' === ( $payload['channel'] ?? '' ) ? 'email' : 'hub';
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Helpdesk_Hero_Hub_DB::table( 'tickets' ),
			array(
				'site_id'          => (int) $site['id'],
				'client_ticket_id' => (int) ( $payload['client_ticket_id'] ?? 0 ),
				'subject'          => $subject,
				'status'           => 'open',
				'priority'         => $priority,
				'category'         => substr( sanitize_text_field( (string) ( $payload['category'] ?? '' ) ), 0, 60 ),
				'customer_name'    => sanitize_text_field( (string) ( $contact['name'] ?? '' ) ),
				'customer_email'   => sanitize_email( (string) ( $contact['email'] ?? '' ) ),
				'description'      => $body . ( ! empty( $payload['page_url'] ) ? "\n\n" . __( 'Page:', 'helpdesk-hero-hub' ) . ' ' . esc_url_raw( $payload['page_url'] ) : '' ),
				'diagnostics'      => wp_json_encode( Helpdesk_Hero_Hub_Redactor::deep( (array) ( $payload['diagnostics'] ?? array() ) ) ),
				'flags'            => wp_json_encode( self::clean_flags( (array) ( $payload['flags'] ?? array() ) ) ),
				'access_expires'   => ! empty( $access['active'] ) && ! empty( $access['expires_at'] ) ? sanitize_text_field( $access['expires_at'] ) : null,
				'channel'          => $channel,
				'customer_at'      => $now,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$ticket_id = (int) $wpdb->insert_id;
		$ticket    = self::ticket( $ticket_id );

		/**
		 * Fires when a ticket arrives at the hub, before it is sent to the help desk.
		 *
		 * @param array $ticket Hub ticket.
		 * @param array $site   Site.
		 */
		do_action( 'helpdesk_hero_hub_ticket_received', $ticket, $site );

		$reference = '';
		$helpdesk  = Helpdesk_Hero_Hub_Helpdesk::current();
		if ( 'email' === $channel ) {
			// The customer emailed this ticket themselves, so it is already in the team's inbox or
			// help desk. Record it here (diagnostics, access, replies) without creating it twice.
			self::log( $ticket, 'emailed', array() );
		} elseif ( $helpdesk ) {
			$created = $helpdesk->create_ticket(
				array(
					'subject'    => $subject,
					'body'       => self::ticket_body( $ticket, $site ),
					'name'       => $ticket['customer_name'],
					'email'      => $ticket['customer_email'],
					'priority'   => $priority,
					'tags'       => array_values( array_filter( array( 'helpdesk-hero', $ticket['category'] ? sanitize_title( $ticket['category'] ) : '' ) ) ),
					'attachment' => array(
						'name'    => 'diagnostics-' . sanitize_file_name( (string) wp_parse_url( $site['url'], PHP_URL_HOST ) ) . '.txt',
						'content' => Helpdesk_Hero_Hub_Format::diagnostics( $ticket['diagnostics'], $ticket['flags'] ),
					),
					'note'       => self::agent_note( $ticket, $site ),
				)
			);
			if ( is_wp_error( $created ) ) {
				// Keep the ticket on the hub and tell the team; the customer's ticket is not lost.
				self::notify_team( $ticket, $site, $created->get_error_message() );
			} else {
				$reference = $helpdesk->reference( $created );
				self::update_ticket(
					$ticket_id,
					array(
						'helpdesk'        => $helpdesk->key(),
						'helpdesk_id'     => (string) $created['id'],
						'helpdesk_number' => (string) ( $created['number'] ?? '' ),
						'seen_threads'    => array_map( 'strval', (array) ( $created['thread_ids'] ?? array() ) ),
					)
				);
			}
		} else {
			self::notify_team( $ticket, $site );
		}

		do_action( 'helpdesk_hero_hub_ticket_created', self::ticket( $ticket_id ), $site );

		return array(
			'hub_ticket_id' => $ticket_id,
			'reference'     => $reference,
		);
	}

	/**
	 * Unpack diagnostics sent as a gzipped, base64-encoded bundle (customer plugin 2.0.1+).
	 *
	 * @param array $payload Ticket payload.
	 * @return array
	 */
	public static function unpack_bundle( array $payload ) {
		if ( empty( $payload['bundle'] ) || ! is_string( $payload['bundle'] ) || ! function_exists( 'gzdecode' ) ) {
			return $payload;
		}
		$raw  = base64_decode( $payload['bundle'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport encoding of diagnostics, see Helpdesk_Hero_Connection::pack_bundle().
		$json = false !== $raw ? gzdecode( $raw, 8 * MB_IN_BYTES ) : false;
		$data = false !== $json ? json_decode( $json, true ) : null;
		if ( is_array( $data ) ) {
			$payload['diagnostics'] = (array) ( $data['diagnostics'] ?? array() );
			$payload['flags']       = (array) ( $data['flags'] ?? array() );
		}
		unset( $payload['bundle'] );
		return $payload;
	}

	/**
	 * Flags from a site, sanitized.
	 *
	 * @param array $flags Raw.
	 * @return array
	 */
	private static function clean_flags( array $flags ) {
		$out = array();
		foreach ( array_slice( $flags, 0, 30 ) as $flag ) {
			$out[] = array(
				'level'  => in_array( $flag['level'] ?? '', array( 'critical', 'warning', 'info' ), true ) ? $flag['level'] : 'info',
				'code'   => sanitize_key( $flag['code'] ?? '' ),
				'title'  => sanitize_text_field( $flag['title'] ?? '' ),
				'detail' => sanitize_text_field( $flag['detail'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Customer-visible ticket body for the help desk.
	 *
	 * @param array $ticket Ticket.
	 * @param array $site   Site.
	 * @return string Plain text.
	 */
	private static function ticket_body( array $ticket, array $site ) {
		return $ticket['description'] . "\n\n— " . sprintf( /* translators: 1: site name, 2: URL */ __( 'Sent from %1$s (%2$s)', 'helpdesk-hero-hub' ), $site['name'], $site['url'] );
	}

	/**
	 * Internal note for agents: health flags, access state and the hub link.
	 *
	 * @param array $ticket Ticket.
	 * @param array $site   Site.
	 * @return string Plain text.
	 */
	public static function agent_note( array $ticket, array $site ) {
		$env   = $ticket['diagnostics']['environment'] ?? array();
		$lines = array( 'Helpdesk Hero' );
		/* translators: 1: site, 2: URL */
		$lines[] = sprintf( __( 'Site: %1$s (%2$s)', 'helpdesk-hero-hub' ), $site['name'], $site['url'] );
		if ( $env ) {
			$lines[] = sprintf( 'WordPress %s · PHP %s · %s', $env['wordpress'] ?? '?', $env['php'] ?? '?', $env['server'] ?? '' );
		}
		if ( $ticket['flags'] ) {
			$lines[] = '';
			$lines[] = __( 'Health flags:', 'helpdesk-hero-hub' );
			foreach ( array_slice( $ticket['flags'], 0, 8 ) as $flag ) {
				$lines[] = '• [' . strtoupper( $flag['level'] ) . '] ' . $flag['title'] . ( $flag['detail'] ? ' — ' . $flag['detail'] : '' );
			}
		}
		$lines[] = '';
		$lines[] = $ticket['access_expires']
			/* translators: %s: date */
			? sprintf( __( 'Support access granted until %s UTC.', 'helpdesk-hero-hub' ), $ticket['access_expires'] )
			: __( 'No support access granted.', 'helpdesk-hero-hub' );
		$lines[] = __( 'Log in, see diagnostics and support activity:', 'helpdesk-hero-hub' ) . ' ' . self::ticket_url( (int) $ticket['id'] );

		/**
		 * Filters the internal note added to new help desk tickets (Pro adds an AI triage brief).
		 *
		 * @param string $note   Note.
		 * @param array  $ticket Ticket.
		 * @param array  $site   Site.
		 */
		return (string) apply_filters( 'helpdesk_hero_hub_agent_note', implode( "\n", $lines ), $ticket, $site );
	}

	/**
	 * Hub admin URL for a ticket.
	 *
	 * @param int $id Ticket.
	 * @return string
	 */
	public static function ticket_url( $id ) {
		return admin_url( 'admin.php?page=helpdesk-hero-hub#/ticket/' . (int) $id );
	}

	/**
	 * Email the team when no help desk is connected (or it failed).
	 *
	 * @param array  $ticket Ticket.
	 * @param array  $site   Site.
	 * @param string $error  Help desk error, if any.
	 */
	private static function notify_team( array $ticket, array $site, $error = '' ) {
		$to = Helpdesk_Hero_Hub_Settings::notify_email();
		$body = $ticket['description'] . "\n\n" . self::agent_note( $ticket, $site );
		if ( $error ) {
			/* translators: %s: error */
			$body = sprintf( __( 'The ticket could not be created in your help desk: %s', 'helpdesk-hero-hub' ), $error ) . "\n\n" . $body;
		}
		wp_mail(
			$to,
			/* translators: 1: site name, 2: subject */
			sprintf( __( '[Ticket] %1$s: %2$s', 'helpdesk-hero-hub' ), $site['name'], $ticket['subject'] ),
			$body,
			$ticket['customer_email'] ? array( 'Reply-To: ' . $ticket['customer_name'] . ' <' . $ticket['customer_email'] . '>' ) : array()
		);
	}

	/**
	 * A customer replied from their dashboard.
	 *
	 * @param array  $ticket Ticket.
	 * @param string $body   Text.
	 * @param string $author Name.
	 * @return true|WP_Error
	 */
	public static function customer_reply( array $ticket, $body, $author ) {
		$body = sanitize_textarea_field( $body );
		self::log( $ticket, 'customer_reply', array( 'author' => sanitize_text_field( $author ), 'body' => $body, 'created_at' => gmdate( 'c' ) ) );
		$status   = 'closed' === $ticket['status'] ? 'open' : $ticket['status'];
		$helpdesk = Helpdesk_Hero_Hub_Helpdesk::for_ticket( $ticket );
		if ( $helpdesk ) {
			$thread = $helpdesk->customer_reply( $ticket, $body );
			if ( is_wp_error( $thread ) ) {
				return $thread;
			}
			self::mark_seen( $ticket, $thread );
		} else {
			$site = self::site( (int) $ticket['site_id'] );
			self::notify_team( array_merge( $ticket, array( 'description' => $body ) ), $site ? $site : array( 'name' => '', 'url' => '' ) );
		}
		self::update_ticket(
			(int) $ticket['id'],
			array(
				'status'      => 'open' === $status ? 'open' : $status,
				'customer_at' => Helpdesk_Hero_Hub_DB::now(),
			)
		);
		return true;
	}

	/**
	 * An agent replied from the hub.
	 *
	 * @param array  $ticket Ticket.
	 * @param string $body   Text.
	 * @return true|WP_Error
	 */
	public static function agent_reply( array $ticket, $body ) {
		$body     = sanitize_textarea_field( $body );
		$agent    = wp_get_current_user();
		$thread   = 'hub-' . wp_generate_password( 12, false );
		$helpdesk = Helpdesk_Hero_Hub_Helpdesk::for_ticket( $ticket );
		if ( $helpdesk ) {
			$thread = $helpdesk->agent_reply( $ticket, $body );
			if ( is_wp_error( $thread ) ) {
				return $thread;
			}
			self::mark_seen( $ticket, $thread );
		}
		self::enqueue(
			(int) $ticket['site_id'],
			(int) $ticket['id'],
			'reply',
			array(
				'author'     => self::agent_name( $agent->ID ),
				'body'       => $body,
				'thread_id'  => (string) $thread,
				'created_at' => gmdate( 'c' ),
			)
		);
		self::first_response( $ticket );
		// Waiting on the customer, in the help desk too, so the next sync doesn't flip it back.
		self::set_status( $ticket, 'pending', true );
		return true;
	}

	/**
	 * Change status; optionally tell the help desk; always tell the site.
	 *
	 * @param array  $ticket      Ticket.
	 * @param string $status      open | pending | closed.
	 * @param bool   $to_helpdesk Also update the help desk.
	 */
	public static function set_status( array $ticket, $status, $to_helpdesk = true ) {
		$status = in_array( $status, array( 'open', 'pending', 'closed' ), true ) ? $status : 'open';
		if ( $status === $ticket['status'] ) {
			return;
		}
		if ( $to_helpdesk ) {
			$helpdesk = Helpdesk_Hero_Hub_Helpdesk::for_ticket( $ticket );
			if ( $helpdesk ) {
				$helpdesk->set_status( $ticket, $status );
			}
		}
		self::update_ticket(
			(int) $ticket['id'],
			array(
				'status'    => $status,
				'closed_at' => 'closed' === $status ? Helpdesk_Hero_Hub_DB::now() : null,
			)
		);
		self::enqueue( (int) $ticket['site_id'], (int) $ticket['id'], 'status', array( 'status' => $status ) );
	}

	/**
	 * Record the first support reply (for response-time statistics).
	 *
	 * @param array $ticket Ticket.
	 */
	public static function first_response( array $ticket ) {
		if ( empty( $ticket['first_response_at'] ) ) {
			self::update_ticket(
				(int) $ticket['id'],
				array(
					'first_response_at' => Helpdesk_Hero_Hub_DB::now(),
					'updated_at'        => $ticket['updated_at'],
				)
			);
		}
	}

	/**
	 * Set a ticket's tags and show them to the customer.
	 *
	 * @param array $ticket  Ticket.
	 * @param array $tag_ids Tag IDs.
	 */
	public static function set_tags( array $ticket, array $tag_ids ) {
		$known = wp_list_pluck( Helpdesk_Hero_Hub_Tags::all(), 'id' );
		$ids   = array_values( array_intersect( array_map( 'sanitize_key', $tag_ids ), $known ) );
		self::update_ticket( (int) $ticket['id'], array( 'tags' => $ids ) );
		self::enqueue( (int) $ticket['site_id'], (int) $ticket['id'], 'tags', array( 'tags' => Helpdesk_Hero_Hub_Tags::resolve( $ids ) ) );
	}

	/**
	 * Store the customer's rating of a ticket.
	 *
	 * @param array  $ticket  Ticket.
	 * @param int    $rating  1–5.
	 * @param string $comment Comment.
	 */
	public static function rate( array $ticket, $rating, $comment ) {
		$rating  = max( 1, min( 5, (int) $rating ) );
		$comment = substr( sanitize_textarea_field( (string) $comment ), 0, 2000 );
		self::update_ticket(
			(int) $ticket['id'],
			array(
				'rating'         => $rating,
				'rating_comment' => $comment,
				'rated_at'       => Helpdesk_Hero_Hub_DB::now(),
			)
		);
		self::log(
			$ticket,
			'rating',
			array(
				'rating'  => $rating,
				'comment' => $comment,
			)
		);
		/**
		 * Fires when a customer rates a ticket (Pro adds it to the help desk as a note).
		 *
		 * @param array  $ticket  Ticket.
		 * @param int    $rating  Rating.
		 * @param string $comment Comment.
		 */
		do_action( 'helpdesk_hero_hub_ticket_rated', self::ticket( (int) $ticket['id'] ), $rating, $comment );
	}

	/**
	 * Remember a help desk thread ID so sync does not relay it again.
	 *
	 * @param array  $ticket Ticket.
	 * @param string $thread Thread ID.
	 */
	private static function mark_seen( array $ticket, $thread ) {
		$fresh = self::ticket( (int) $ticket['id'] );
		$seen  = $fresh ? $fresh['seen_threads'] : array();
		$seen[] = (string) $thread;
		self::update_ticket( (int) $ticket['id'], array( 'seen_threads' => array_values( array_unique( $seen ) ) ) );
	}

	/**
	 * Whether a hub user may act for the team (reply, log in to sites, message customers).
	 *
	 * @param int $user_id User (default: current).
	 * @return bool
	 */
	public static function can_support( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		/**
		 * Filters whether a hub user may act as a supporter. Helpdesk Hero Pro limits this to the
		 * supporters chosen by the team.
		 *
		 * @param bool $can     Can support.
		 * @param int  $user_id User.
		 */
		return (bool) apply_filters( 'helpdesk_hero_hub_can_support', user_can( $user_id, 'helpdesk_hero_hub' ), $user_id );
	}

	/**
	 * The supporter identity shown on customer sites for a hub user, if the team uses named
	 * supporters (Pro). Customer sites then give each supporter their own temporary account.
	 *
	 * @param int $user_id User (default: current).
	 * @return array|null { id, name }
	 */
	public static function supporter( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		/**
		 * Filters the supporter identity of a hub user.
		 *
		 * @param array|null $supporter { id: string, name: string } or null.
		 * @param int        $user_id   User.
		 */
		$supporter = apply_filters( 'helpdesk_hero_hub_supporter', null, $user_id );
		return is_array( $supporter ) && ! empty( $supporter['id'] ) && ! empty( $supporter['name'] ) ? array( 'id' => (string) $supporter['id'], 'name' => (string) $supporter['name'] ) : null;
	}

	/**
	 * The name customers see for a hub user.
	 *
	 * @param int $user_id User (default: current).
	 * @return string
	 */
	public static function agent_name( $user_id = 0 ) {
		$user_id   = $user_id ? (int) $user_id : get_current_user_id();
		$supporter = self::supporter( $user_id );
		$user      = get_userdata( $user_id );
		return $supporter ? $supporter['name'] : ( $user ? $user->display_name : '' );
	}

	/**
	 * Ask the site for a one-time login link for the current agent.
	 *
	 * @param array $ticket Ticket.
	 * @return string|WP_Error URL.
	 */
	public static function login_url( array $ticket ) {
		$site = self::site( (int) $ticket['site_id'] );
		if ( ! $site ) {
			return new WP_Error( 'helpdesk_hero_site', __( 'Site not found.', 'helpdesk-hero-hub' ) );
		}
		$result = self::site_request(
			$site,
			'/helpdesk-hero/v1/client/login-link',
			'POST',
			array_filter(
				array(
					'hub_ticket_id' => (int) $ticket['id'],
					'agent'         => self::agent_name(),
					'supporter'     => self::supporter(),
				)
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::log( $ticket, 'agent_login', array( 'agent' => self::agent_name() ) );
		return esc_url_raw( (string) ( $result['url'] ?? '' ) );
	}

	/**
	 * Support activity from the site.
	 *
	 * @param array $ticket Ticket.
	 * @return array|WP_Error
	 */
	public static function activity( array $ticket ) {
		$site = self::site( (int) $ticket['site_id'] );
		if ( ! $site ) {
			return new WP_Error( 'helpdesk_hero_site', __( 'Site not found.', 'helpdesk-hero-hub' ) );
		}
		return self::site_request( $site, '/helpdesk-hero/v1/client/activity', 'GET', array( 'hub_ticket_id' => (int) $ticket['id'] ) );
	}

	/* ---------------------------------------------------------------------------------------- *
	 * Outbox.
	 * ---------------------------------------------------------------------------------------- */

	/**
	 * Queue an update for a site and try to deliver it now.
	 *
	 * @param int    $site_id   Site.
	 * @param int    $ticket_id Hub ticket (0 for site-wide).
	 * @param string $type      Type.
	 * @param array  $payload   Payload.
	 * @return int Item ID.
	 */
	public static function enqueue( $site_id, $ticket_id, $type, array $payload ) {
		$id = self::log_raw( $site_id, $ticket_id, $type, $payload );
		if ( in_array( $type, self::DELIVERED, true ) ) {
			self::push( (int) $site_id );
		}
		return $id;
	}

	/**
	 * Hub-only history entry for a ticket (not delivered).
	 *
	 * @param array  $ticket  Ticket.
	 * @param string $type    Type.
	 * @param array  $payload Payload.
	 */
	public static function log( array $ticket, $type, array $payload ) {
		self::log_raw( (int) $ticket['site_id'], (int) $ticket['id'], $type, $payload );
	}

	/**
	 * Insert an outbox row.
	 *
	 * @param int    $site_id   Site.
	 * @param int    $ticket_id Ticket.
	 * @param string $type      Type.
	 * @param array  $payload   Payload.
	 * @return int
	 */
	private static function log_raw( $site_id, $ticket_id, $type, array $payload ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Helpdesk_Hero_Hub_DB::table( 'outbox' ),
			array(
				'site_id'       => (int) $site_id,
				'hub_ticket_id' => (int) $ticket_id,
				'type'          => $type,
				'payload'       => wp_json_encode( $payload ),
				'created_by'    => get_current_user_id(),
				'created_at'    => Helpdesk_Hero_Hub_DB::now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delivered items for a site after a cursor.
	 *
	 * @param int $site_id Site.
	 * @param int $cursor  Last ID the site has.
	 * @param int $limit   Limit.
	 * @return array[]
	 */
	public static function pending( $site_id, $cursor, $limit = 100 ) {
		global $wpdb;
		$types = self::DELIVERED;
		$sql   = 'SELECT id, hub_ticket_id, type, payload, created_at FROM %i WHERE site_id = %d AND id > %d AND type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') ORDER BY id ASC LIMIT %d';
		// $sql is built only from fixed strings and placeholders; every value goes through prepare(), the table name through %i.
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( Helpdesk_Hero_Hub_DB::table( 'outbox' ), (int) $site_id, (int) $cursor ), $types, array( (int) $limit ) ) ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $rows as &$row ) {
			$row['id']            = (int) $row['id'];
			$row['hub_ticket_id'] = (int) $row['hub_ticket_id'];
			$row['payload']       = (array) json_decode( $row['payload'], true );
		}
		return $rows;
	}

	/**
	 * History for a ticket (all types), oldest first.
	 *
	 * @param int $ticket_id Ticket.
	 * @return array[]
	 */
	public static function history( $ticket_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE hub_ticket_id = %d ORDER BY id ASC', Helpdesk_Hero_Hub_DB::table( 'outbox' ), $ticket_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $rows as &$row ) {
			$row['payload'] = (array) json_decode( $row['payload'], true );
		}
		return $rows;
	}

	/**
	 * Push pending items to a site. Failure is fine: the site pulls them later.
	 *
	 * @param int $site_id Site.
	 */
	public static function push( $site_id ) {
		$site = self::site( $site_id );
		if ( ! $site || 'active' !== $site['status'] ) {
			return;
		}
		$items = self::pending( $site_id, (int) ( $site['info']['ack'] ?? 0 ) );
		if ( ! $items ) {
			return;
		}
		$result = self::site_request( $site, '/helpdesk-hero/v1/client/push', 'POST', array( 'items' => $items ), 6 );
		if ( ! is_wp_error( $result ) ) {
			self::update_site( $site_id, array( 'info' => array( 'ack' => (int) end( $items )['id'] ) ) );
		}
	}

	/* ---------------------------------------------------------------------------------------- *
	 * Help desk sync.
	 * ---------------------------------------------------------------------------------------- */

	/**
	 * Relay new agent replies and status changes from the help desk to sites.
	 *
	 * @param int $only_ticket Sync one ticket only.
	 * @return int Updates relayed.
	 */
	public static function sync( $only_ticket = 0 ) {
		global $wpdb;
		if ( ! Helpdesk_Hero_Hub_Helpdesk::current() ) {
			return 0;
		}
		$table = Helpdesk_Hero_Hub_DB::table( 'tickets' );
		if ( $only_ticket ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d AND helpdesk_id <> ''", $table, $only_ticket ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			// Open tickets, plus closed ones touched in the last 3 days (they may be reopened).
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE helpdesk_id <> '' AND (status <> 'closed' OR updated_at > %s) ORDER BY synced_at ASC LIMIT 40", $table, Helpdesk_Hero_Hub_DB::now( -3 * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		$relayed = 0;
		foreach ( $rows as $row ) {
			$ticket   = self::decode_ticket( $row );
			$helpdesk = Helpdesk_Hero_Hub_Helpdesk::for_ticket( $ticket );
			if ( ! $helpdesk ) {
				continue;
			}
			$state = $helpdesk->fetch( $ticket );
			if ( is_wp_error( $state ) ) {
				continue;
			}
			$seen = array_map( 'strval', $ticket['seen_threads'] );
			foreach ( $state['threads'] as $thread ) {
				$tid = (string) $thread['id'];
				if ( in_array( $tid, $seen, true ) ) {
					continue;
				}
				$seen[] = $tid;
				if ( 'agent' === $thread['type'] && $thread['public'] ) {
					self::enqueue(
						(int) $ticket['site_id'],
						(int) $ticket['id'],
						'reply',
						array(
							'author'     => $thread['author'],
							'body'       => $thread['body'],
							'thread_id'  => $tid,
							'created_at' => $thread['created_at'],
						)
					);
					++$relayed;
					self::first_response( $ticket );
					$ticket['first_response_at'] = Helpdesk_Hero_Hub_DB::now();
				}
			}
			self::update_ticket(
				(int) $ticket['id'],
				array(
					'seen_threads' => $seen,
					'synced_at'    => Helpdesk_Hero_Hub_DB::now(),
					'updated_at'   => $ticket['updated_at'],
				)
			);
			if ( $state['status'] && $state['status'] !== $ticket['status'] ) {
				self::set_status( $ticket, $state['status'], false );
				++$relayed;
			}
		}
		return $relayed;
	}
}
