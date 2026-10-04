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
	const DELIVERED = array( 'reply', 'status', 'notice', 'extension_request', 'end_access', 'policy', 'reference', 'tags', 'merged', 'removed' );

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
			'link'    => self::connect_link( $token ),
		);
	}

	/**
	 * New code and link for a site that hasn't connected yet. The earlier ones stop working.
	 *
	 * @param int $site_id Site.
	 * @return array|WP_Error { site_id, code, link }
	 */
	public static function renew_invite( $site_id ) {
		global $wpdb;
		$site = self::site( $site_id );
		if ( ! $site || 'pending' !== $site['status'] ) {
			return new WP_Error( 'helpdesk_hero_hub_site', __( 'This site is already connected.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		$token = bin2hex( random_bytes( 20 ) );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Helpdesk_Hero_Hub_DB::table( 'sites' ),
			array(
				'invite_hash'    => hash( 'sha256', $token ),
				'invite_expires' => Helpdesk_Hero_Hub_DB::now( 7 * DAY_IN_SECONDS ),
			),
			array( 'id' => (int) $site_id )
		);
		return array(
			'site_id' => (int) $site_id,
			'code'    => self::make_code( $token ),
			'link'    => self::connect_link( $token ),
		);
	}

	/**
	 * Connection link: a page on this hub where the customer enters their site address and is
	 * taken to their dashboard with the code filled in.
	 *
	 * @param string $token Invite token.
	 * @return string
	 */
	public static function connect_link( $token ) {
		return add_query_arg( Helpdesk_Hero_Hub_Connect_Page::PARAM, $token, home_url( '/' ) );
	}

	/**
	 * The waiting site for an invite token (not expired), or null.
	 *
	 * @param string $token Invite token.
	 * @return array|null
	 */
	public static function invite_site( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{40}$/', (string) $token ) ) {
			return null;
		}
		$site = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE invite_hash = %s AND status = %s', Helpdesk_Hero_Hub_DB::table( 'sites' ), hash( 'sha256', $token ), 'pending' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $site && strtotime( $site['invite_expires'] . ' UTC' ) > time() ? $site : null;
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
		if ( ! Helpdesk_Hero_Hub_Signer::secure_url( $endpoint ) ) {
			return Helpdesk_Hero_Hub_Signer::insecure_error( $endpoint );
		}
		// Sites from version 2.1 send a public key: each side then signs with its own key pair.
		// Older sites get a shared key, and upgrade to key pairs once they update.
		$peer = (string) ( $info['public_key'] ?? '' );
		if ( Helpdesk_Hero_Hub_Crypto::valid_public_key( $peer ) ) {
			$creds         = Helpdesk_Hero_Hub_Crypto::keypair();
			$creds['peer'] = $peer;
		} else {
			$creds = array( 'hmac' => bin2hex( random_bytes( 32 ) ) );
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'name'           => sanitize_text_field( (string) ( $info['name'] ?? '' ) ),
				'url'            => esc_url_raw( (string) ( $info['url'] ?? '' ) ),
				'endpoint'       => $endpoint,
				'contact_email'  => $site['contact_email'] ? $site['contact_email'] : sanitize_email( (string) ( $info['email'] ?? '' ) ),
				'secret'         => Helpdesk_Hero_Hub_Crypto::store( $creds ),
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
		return array_merge(
			self::site( (int) $site['id'] ),
			array(
				'hmac'       => $creds['hmac'] ?? '',
				'public_key' => $creds['pk'] ?? '',
			)
		);
	}

	/**
	 * A site's connection keys (decrypted).
	 *
	 * @param array $site Site row.
	 * @return array Credentials, see Helpdesk_Hero_Hub_Crypto.
	 */
	public static function creds( array $site ) {
		return Helpdesk_Hero_Hub_Crypto::load( (string) $site['secret'] );
	}

	/**
	 * Save a site's connection keys (encrypted).
	 *
	 * @param int   $id    Site.
	 * @param array $creds Credentials.
	 */
	public static function save_creds( $id, array $creds ) {
		self::update_site( (int) $id, array( 'secret' => Helpdesk_Hero_Hub_Crypto::store( $creds ) ) );
	}

	/**
	 * How a site's connection is secured, for the dashboard.
	 *
	 * @param array $site Site row.
	 * @return array { type: keypair|upgrading|shared|none|unreadable, key_id, site_key_id }
	 */
	public static function security( array $site ) {
		if ( '' === (string) $site['secret'] ) {
			return array( 'type' => 'none' );
		}
		$creds = self::creds( $site );
		if ( ! $creds ) {
			return array( 'type' => 'unreadable' );
		}
		if ( ! empty( $creds['peer'] ) ) {
			return array(
				'type'        => ! empty( $creds['confirmed'] ) || ( empty( $creds['hmac'] ) && empty( $creds['old'] ) ) ? 'keypair' : 'upgrading',
				'key_id'      => Helpdesk_Hero_Hub_Crypto::key_id( (string) ( $creds['pk'] ?? '' ) ),
				'site_key_id' => Helpdesk_Hero_Hub_Crypto::key_id( (string) $creds['peer'] ),
			);
		}
		return array( 'type' => 'shared' );
	}

	/**
	 * A site asks to move from a shared key to key pairs (signed with the shared key).
	 *
	 * @param array  $site Site.
	 * @param string $peer The site's new public key.
	 * @return array|WP_Error { public_key }
	 */
	public static function upgrade_keys( array $site, $peer ) {
		$creds = self::creds( $site );
		if ( ! Helpdesk_Hero_Hub_Crypto::valid_public_key( $peer ) ) {
			return new WP_Error( 'helpdesk_hero_keys', __( 'The site sent a key that isn’t valid.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		if ( ! empty( $creds['confirmed'] ) || empty( $creds['hmac'] ) ) {
			return new WP_Error( 'helpdesk_hero_keys', __( 'This connection already uses key pairs.', 'helpdesk-hero-hub' ), array( 'status' => 409 ) );
		}
		// Keep the shared key until the site signs with its new key (proof it got ours).
		$pair  = Helpdesk_Hero_Hub_Crypto::keypair();
		$creds = array(
			'hmac' => $creds['hmac'],
			'sk'   => $pair['sk'],
			'pk'   => $pair['pk'],
			'peer' => $peer,
		);
		self::save_creds( (int) $site['id'], $creds );
		return array( 'public_key' => $pair['pk'] );
	}

	/**
	 * Replace a site's keys (the team asked for new ones). The current keys sign the change, and
	 * stay valid until the site has used the new ones once.
	 *
	 * @param array $site Site.
	 * @return true|WP_Error
	 */
	public static function rotate_keys( array $site ) {
		$current = self::creds( $site );
		if ( ! $current ) {
			return new WP_Error( 'helpdesk_hero_keys', __( 'This site’s keys can’t be read. Disconnect it and connect it again.', 'helpdesk-hero-hub' ) );
		}
		$pair   = Helpdesk_Hero_Hub_Crypto::keypair();
		$result = self::site_request( $site, '/helpdesk-hero/v1/client/keys', 'POST', array( 'public_key' => $pair['pk'] ) );
		if ( is_wp_error( $result ) ) {
			if ( 'rest_no_route' === $result->get_error_code() || false !== strpos( $result->get_error_message(), '404' ) ) {
				return new WP_Error( 'helpdesk_hero_keys', __( 'The site runs an older Helpdesk Hero. Ask the customer to update it to version 2.1 or later, then try again.', 'helpdesk-hero-hub' ) );
			}
			return $result;
		}
		$peer = (string) ( $result['public_key'] ?? '' );
		if ( ! Helpdesk_Hero_Hub_Crypto::valid_public_key( $peer ) ) {
			return new WP_Error( 'helpdesk_hero_keys', __( 'The site didn’t send a valid key. Nothing was changed.', 'helpdesk-hero-hub' ) );
		}
		unset( $current['old'] );
		self::save_creds(
			(int) $site['id'],
			array(
				'sk'        => $pair['sk'],
				'pk'        => $pair['pk'],
				'peer'      => $peer,
				'initiated' => true,
				'old'       => $current,
			)
		);
		self::log_raw( (int) $site['id'], 0, 'keys_rotated', array( 'by' => self::agent_name() ) );
		return true;
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
		$result = Helpdesk_Hero_Hub_Signer::request( $site['endpoint'], $route, $method, $data, (string) $site['id'], self::creds( $site ), $timeout );
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
	 * Custom statuses the team defined (Helpdesk Hero Pro). Each one belongs to a base status
	 * (open, pending or closed), which still decides what happens: closing ends access and asks
	 * for a rating, "waiting on customer" pauses the SLA clock.
	 *
	 * @return array[] { id, label, color, base }
	 */
	public static function custom_statuses() {
		/**
		 * Filters the custom ticket statuses.
		 *
		 * @param array[] $statuses { id, label, color, base }.
		 */
		$list = (array) apply_filters( 'helpdesk_hero_hub_custom_statuses', array() );
		$out  = array();
		foreach ( $list as $s ) {
			if ( ! empty( $s['id'] ) && ! empty( $s['label'] ) && in_array( $s['base'] ?? '', array( 'open', 'pending', 'closed' ), true ) ) {
				$out[] = array(
					'id'     => sanitize_key( $s['id'] ),
					'label'  => (string) $s['label'],
					'color'  => (string) ( $s['color'] ?? '' ),
					'base'   => $s['base'],
					'public' => ! empty( $s['public'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * What a customer may see about time spent for them (Helpdesk Hero Pro), or null.
	 *
	 * @param array $site Site.
	 * @return array|null { month, retainer_hours, used_minutes, month_minutes, tickets: { hub_ticket_id: minutes } }
	 */
	public static function site_usage( array $site ) {
		/**
		 * Filters the usage summary sent to a customer's site (null = not shown).
		 *
		 * @param array|null $usage Usage.
		 * @param array      $site  Site.
		 */
		$usage = apply_filters( 'helpdesk_hero_hub_site_usage', null, $site );
		return is_array( $usage ) ? $usage : null;
	}

	/**
	 * Services the team offers (Helpdesk Hero Pro: Time & billing), for labelling tickets.
	 *
	 * @return array[] { id, name, color }
	 */
	public static function services() {
		/**
		 * Filters the services tickets can be filed under.
		 *
		 * @param array[] $services { id, name, color }.
		 */
		return (array) apply_filters( 'helpdesk_hero_hub_services', array() );
	}

	/**
	 * A service by ID, for display (or null).
	 *
	 * @param string $id ID.
	 * @return array|null { id, name, color }
	 */
	public static function service( $id ) {
		if ( '' === (string) $id ) {
			return null;
		}
		foreach ( self::services() as $s ) {
			if ( ( $s['id'] ?? '' ) === $id ) {
				return array(
					'id'    => (string) $s['id'],
					'name'  => (string) $s['name'],
					'color' => (string) ( $s['color'] ?? '' ),
				);
			}
		}
		return null;
	}

	/**
	 * A custom status by ID (or null).
	 *
	 * @param string $id ID.
	 * @return array|null
	 */
	public static function custom_status( $id ) {
		if ( '' === (string) $id ) {
			return null;
		}
		foreach ( self::custom_statuses() as $s ) {
			if ( $s['id'] === $id ) {
				return $s;
			}
		}
		return null;
	}

	/**
	 * Where a ticket stands against its service level agreement. Deadlines are set by Helpdesk
	 * Hero Pro; without them there's nothing to show.
	 *
	 * @param array $t Ticket row.
	 * @return array|null { stage: respond|resolve, due (ISO), state: ok|soon|breached|paused|met }
	 */
	public static function sla_state( array $t ) {
		if ( empty( $t['respond_due'] ) && empty( $t['resolve_due'] ) ) {
			return null;
		}
		if ( 'closed' === $t['status'] ) {
			$late = ! empty( $t['resolve_due'] ) && ! empty( $t['closed_at'] ) && strcmp( (string) $t['closed_at'], (string) $t['resolve_due'] ) > 0;
			return array(
				'stage' => 'resolve',
				'due'   => ! empty( $t['resolve_due'] ) ? gmdate( 'c', strtotime( $t['resolve_due'] . ' UTC' ) ) : null,
				'state' => $late ? 'breached' : 'met',
			);
		}
		$stage = empty( $t['first_response_at'] ) && ! empty( $t['respond_due'] ) ? 'respond' : 'resolve';
		$due   = 'respond' === $stage ? $t['respond_due'] : $t['resolve_due'];
		if ( empty( $due ) ) {
			return null;
		}
		if ( 'resolve' === $stage && ! empty( $t['sla_paused_at'] ) ) {
			$state = 'paused';
		} else {
			$left  = strtotime( $due . ' UTC' ) - time();
			$state = $left < 0 ? 'breached' : ( $left < HOUR_IN_SECONDS ? 'soon' : 'ok' );
		}
		return array(
			'stage' => $stage,
			'due'   => gmdate( 'c', strtotime( $due . ' UTC' ) ),
			'state' => $state,
		);
	}

	/**
	 * Sort keys for the inbox.
	 *
	 * @return string[]
	 */
	public static function ticket_sorts() {
		return array( 'updated', 'created', 'priority', 'status', 'health', 'site', 'customer', 'tier', 'due' );
	}

	/**
	 * WHERE clause and values for an inbox query.
	 *
	 * @param array $args See tickets().
	 * @return array{0:string, 1:array}
	 */
	private static function ticket_where( array $args ) {
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
		if ( ! empty( $args['custom_status'] ) ) {
			$where[]  = 't.custom_status = %s';
			$params[] = (string) $args['custom_status'];
		}
		if ( ! empty( $args['site_id'] ) ) {
			$where[]  = 't.site_id = %d';
			$params[] = (int) $args['site_id'];
		}
		if ( isset( $args['assignee'] ) && '' !== (string) $args['assignee'] ) {
			$where[]  = 't.assignee_id = %d';
			$params[] = (int) $args['assignee'];
		}
		if ( ! empty( $args['closed_before'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', (string) $args['closed_before'] ) ) {
			$where[]  = "t.status = 'closed' AND COALESCE(t.closed_at, t.updated_at) < %s";
			$params[] = (string) $args['closed_before'];
		}
		if ( ! empty( $args['tag'] ) ) {
			$where[]  = 't.tags LIKE %s';
			$params[] = '%' . $wpdb->esc_like( '"' . sanitize_key( (string) $args['tag'] ) . '"' ) . '%';
		}
		if ( ! empty( $args['rating_max'] ) ) {
			$where[]  = 't.rating BETWEEN 1 AND %d';
			$params[] = (int) $args['rating_max'];
		}
		if ( ! empty( $args['rating_min'] ) ) {
			$where[]  = 't.rating >= %d';
			$params[] = (int) $args['rating_min'];
		}
		if ( isset( $args['category'] ) && '' !== (string) $args['category'] ) {
			// "none" = tickets without a category.
			$where[]  = 't.category = %s';
			$params[] = 'none' === $args['category'] ? '' : (string) $args['category'];
		}
		if ( 'ending' === ( $args['access'] ?? '' ) ) {
			// Access that ends within a day.
			$where[]  = "t.status <> 'closed' AND t.access_expires BETWEEN %s AND %s";
			$params[] = Helpdesk_Hero_Hub_DB::now();
			$params[] = Helpdesk_Hero_Hub_DB::now( DAY_IN_SECONDS );
		} elseif ( ! empty( $args['access'] ) ) {
			$where[]  = "t.status <> 'closed' AND t.access_expires > %s";
			$params[] = Helpdesk_Hero_Hub_DB::now();
		}
		if ( ! empty( $args['bookmarked'] ) ) {
			// The current person's bookmarks.
			$marks   = self::bookmarks();
			$where[] = $marks ? 't.id IN (' . implode( ',', array_map( 'intval', $marks ) ) . ')' : '0 = 1';
		}
		if ( ! empty( $args['unread'] ) ) {
			$where[] = "t.status <> 'closed' AND t.customer_at IS NOT NULL AND ( t.viewed_at IS NULL OR t.viewed_at < t.customer_at )";
		}
		if ( ! empty( $args['sla'] ) ) {
			$now = Helpdesk_Hero_Hub_DB::now();
			if ( 'overdue' === $args['sla'] ) {
				$where[]  = "t.status <> 'closed' AND ( ( t.first_response_at IS NULL AND t.respond_due < %s ) OR ( t.sla_paused_at IS NULL AND t.resolve_due < %s ) )";
				$params[] = $now;
				$params[] = $now;
			} elseif ( 'soon' === $args['sla'] ) {
				$soon     = Helpdesk_Hero_Hub_DB::now( HOUR_IN_SECONDS );
				$where[]  = "t.status <> 'closed' AND ( ( t.first_response_at IS NULL AND t.respond_due BETWEEN %s AND %s ) OR ( t.sla_paused_at IS NULL AND t.resolve_due BETWEEN %s AND %s ) )";
				$params   = array_merge( $params, array( $now, $soon, $now, $soon ) );
			}
		}
		if ( ! empty( $args['priority'] ) && in_array( $args['priority'], array( 'low', 'normal', 'high', 'urgent' ), true ) ) {
			$where[]  = 't.priority = %s';
			$params[] = $args['priority'];
		}
		foreach ( array( 'from' => '>=', 'to' => '<=' ) as $key => $op ) {
			if ( ! empty( $args[ $key ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args[ $key ] ) ) {
				$where[]  = "t.created_at $op %s";
				$params[] = $args[ $key ] . ( 'to' === $key ? ' 23:59:59' : ' 00:00:00' );
			}
		}
		if ( isset( $args['service'] ) && '' !== (string) $args['service'] ) {
			$where[]  = 't.service = %s';
			$params[] = 'none' === $args['service'] ? '' : (string) $args['service'];
		}
		if ( ! empty( $args['tier'] ) ) {
			$where[]  = 's.tier = %s';
			$params[] = (string) $args['tier'];
		}
		if ( empty( $args['merged'] ) ) {
			$where[] = 't.merged_into = 0';
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(t.subject LIKE %s OR t.customer_email LIKE %s OR t.customer_name LIKE %s OR s.name LIKE %s OR s.label LIKE %s OR s.url LIKE %s' . ( ctype_digit( (string) $args['search'] ) ? ' OR t.id = %d' : '' ) . ')';
			$params   = array_merge( $params, array( $like, $like, $like, $like, $like, $like ) );
			if ( ctype_digit( (string) $args['search'] ) ) {
				$params[] = (int) $args['search'];
			}
		}
		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * ORDER BY clause for an inbox query (fixed SQL plus values for prepare()).
	 *
	 * @param string $orderby Sort key.
	 * @param string $order   asc | desc.
	 * @return array{0:string, 1:array}
	 */
	private static function ticket_order( $orderby, $order ) {
		$dir    = 'asc' === strtolower( (string) $order ) ? 'ASC' : 'DESC';
		$params = array();
		switch ( $orderby ) {
			case 'created':
				$sql = "t.created_at $dir";
				break;
			case 'priority':
				$sql = "FIELD(t.priority, 'low', 'normal', 'high', 'urgent') $dir, t.updated_at DESC";
				break;
			case 'status':
				$sql = "FIELD(t.status, 'open', 'pending', 'closed') $dir, t.updated_at DESC";
				break;
			case 'health':
				$sql = "t.health $dir, t.updated_at DESC";
				break;
			case 'site':
				$sql = "COALESCE(NULLIF(s.name, ''), s.label) $dir";
				break;
			case 'customer':
				$sql = "COALESCE(NULLIF(t.customer_name, ''), t.customer_email) $dir";
				break;
			case 'tier':
				// Tiers sort by their place in the team's list (first = most important). Sites
				// without a tier come last.
				$ids = array_reverse( wp_list_pluck( Helpdesk_Hero_Hub_Tiers::all(), 'id' ) );
				if ( $ids ) {
					$sql    = 'FIELD(s.tier, ' . implode( ', ', array_fill( 0, count( $ids ), '%s' ) ) . ") $dir, t.updated_at DESC";
					$params = $ids;
				} else {
					$sql = 't.updated_at DESC';
				}
				break;
			case 'due':
				// Earliest SLA deadline first; tickets without one last.
				$sql = "(t.respond_due IS NULL AND t.resolve_due IS NULL) ASC, LEAST(COALESCE(t.respond_due, '9999-12-31'), COALESCE(t.resolve_due, '9999-12-31')) $dir";
				break;
			default:
				$sql = "t.updated_at $dir";
		}
		return array( $sql . ', t.id DESC', $params );
	}

	/**
	 * Tickets for the inbox.
	 *
	 * @param array $args status ('' | active | open | pending | closed), custom_status, site_id,
	 *                    assignee, tier, search, merged (include merged tickets), orderby, order,
	 *                    limit, offset.
	 * @return array[]
	 */
	public static function tickets( array $args = array() ) {
		global $wpdb;
		list( $where, $params )  = self::ticket_where( $args );
		list( $order, $oparams ) = self::ticket_order( (string) ( $args['orderby'] ?? 'updated' ), (string) ( $args['order'] ?? 'desc' ) );
		$params   = array_merge( array( Helpdesk_Hero_Hub_DB::table( 'tickets' ), Helpdesk_Hero_Hub_DB::table( 'sites' ) ), $params, $oparams, array( (int) ( $args['limit'] ?? 100 ), (int) ( $args['offset'] ?? 0 ) ) );
		$sql      = 'SELECT t.id, t.site_id, t.subject, t.status, t.custom_status, t.service, t.priority, t.health, t.assignee_id, t.merged_into, t.customer_name, t.customer_email, t.flags, t.helpdesk, t.helpdesk_id, t.helpdesk_number, t.access_expires, t.channel, t.tags, t.rating, t.category, t.customer_at, t.viewed_at, t.respond_due, t.resolve_due, t.sla_paused_at, t.first_response_at, t.closed_at, t.created_at, t.updated_at, s.name AS site_name, s.label AS site_label, s.url AS site_url, s.tier AS site_tier FROM %i t LEFT JOIN %i s ON s.id = t.site_id WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT %d OFFSET %d';
		// $sql is built only from fixed strings and placeholders; every value goes through prepare(), the table name through %i.
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $rows as &$row ) {
			$row['flags'] = $row['flags'] ? (array) json_decode( $row['flags'], true ) : array();
			$row['tags']  = $row['tags'] ? (array) json_decode( $row['tags'], true ) : array();
		}
		return $rows;
	}

	/**
	 * Number of tickets matching an inbox query.
	 *
	 * @param array $args See tickets().
	 * @return int
	 */
	public static function count_tickets( array $args = array() ) {
		global $wpdb;
		list( $where, $params ) = self::ticket_where( $args );
		$sql = 'SELECT COUNT(*) FROM %i t LEFT JOIN %i s ON s.id = t.site_id WHERE ' . $where;
		// $sql is built only from fixed strings and placeholders.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( Helpdesk_Hero_Hub_DB::table( 'tickets' ), Helpdesk_Hero_Hub_DB::table( 'sites' ) ), $params ) ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		$flags   = self::clean_flags( (array) ( $payload['flags'] ?? array() ) );
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
				'diagnostics'      => wp_json_encode( Helpdesk_Hero_Hub_Redactor::deep( array_merge( (array) ( $payload['diagnostics'] ?? array() ), array_filter( array( 'pinpoints' => self::clean_pinpoints( $payload, (string) $site['url'], 'opening' ) ) ) ) ) ),
				'flags'            => wp_json_encode( $flags ),
				'health'           => Helpdesk_Hero_Hub_DB::health_score( $flags ),
				'access_expires'   => ! empty( $access['active'] ) && ! empty( $access['expires_at'] ) ? sanitize_text_field( $access['expires_at'] ) : null,
				'channel'          => $channel,
				'fields'           => self::clean_fields( (array) ( $payload['fields'] ?? array() ) ),
				'customer_at'      => $now,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$ticket_id = (int) $wpdb->insert_id;
		$ticket    = self::ticket( $ticket_id );
		if ( ! empty( $payload['attachments'] ) && Helpdesk_Hero_Hub_Attachments::config()['enabled'] ) {
			Helpdesk_Hero_Hub_Attachments::from_site( $ticket, (array) $payload['attachments'], 'opening' );
		}

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
	 * Custom ticket fields the team defined (Helpdesk Hero Pro).
	 *
	 * @return array[] { id, label, type: text|textarea|number|url|select|checkbox, options[], required, help }
	 */
	public static function field_definitions() {
		/**
		 * Filters the custom fields customers fill in on new tickets.
		 *
		 * @param array[] $fields Definitions.
		 */
		return (array) apply_filters( 'helpdesk_hero_hub_ticket_fields', array() );
	}

	/**
	 * Keep answers to known fields, cleaned by type.
	 *
	 * @param array $in Answers { id: value }.
	 * @return string|null JSON.
	 */
	private static function clean_fields( array $in ) {
		$out = array();
		foreach ( self::field_definitions() as $f ) {
			$id = (string) ( $f['id'] ?? '' );
			if ( '' === $id || ! array_key_exists( $id, $in ) ) {
				continue;
			}
			$v = $in[ $id ];
			switch ( $f['type'] ?? 'text' ) {
				case 'checkbox':
					$v = ! empty( $v );
					break;
				case 'number':
					$v = is_numeric( $v ) ? $v + 0 : null;
					break;
				case 'url':
					$v = esc_url_raw( (string) $v );
					break;
				case 'select':
					$v = in_array( (string) $v, (array) ( $f['options'] ?? array() ), true ) ? (string) $v : null;
					break;
				case 'textarea':
					$v = substr( sanitize_textarea_field( (string) $v ), 0, 5000 );
					break;
				default:
					$v = substr( sanitize_text_field( (string) $v ), 0, 500 );
			}
			if ( null !== $v && '' !== $v ) {
				$out[ $id ] = $v;
			}
		}
		return $out ? wp_json_encode( $out ) : null;
	}

	/**
	 * A ticket's answers with their labels, in the order the fields are defined.
	 *
	 * @param array $ticket Ticket.
	 * @return array[] { id, label, value }
	 */
	public static function field_values( array $ticket ) {
		$values = $ticket['fields'] ? (array) json_decode( (string) $ticket['fields'], true ) : array();
		$out    = array();
		$known  = array();
		foreach ( self::field_definitions() as $f ) {
			$known[ $f['id'] ] = true;
			if ( array_key_exists( $f['id'], $values ) ) {
				$out[] = array(
					'id'    => $f['id'],
					'label' => $f['label'],
					'value' => is_bool( $values[ $f['id'] ] ) ? ( $values[ $f['id'] ] ? __( 'Yes', 'helpdesk-hero-hub' ) : __( 'No', 'helpdesk-hero-hub' ) ) : (string) $values[ $f['id'] ],
				);
			}
		}
		// Answers to fields that were removed since.
		foreach ( $values as $id => $value ) {
			if ( ! isset( $known[ $id ] ) ) {
				$out[] = array(
					'id'    => (string) $id,
					'label' => (string) $id,
					'value' => is_bool( $value ) ? ( $value ? __( 'Yes', 'helpdesk-hero-hub' ) : __( 'No', 'helpdesk-hero-hub' ) ) : (string) $value,
				);
			}
		}
		return $out;
	}

	/**
	 * Merge a ticket into another of the same site. The merged ticket closes; its conversation
	 * shows in the other, and the customer's later replies go there too. Support access tied
	 * to it stays as it is.
	 *
	 * @param array $source Ticket to merge.
	 * @param array $target Ticket to keep.
	 * @return true|WP_Error
	 */
	public static function merge( array $source, array $target ) {
		if ( (int) $source['id'] === (int) $target['id'] ) {
			return new WP_Error( 'helpdesk_hero_hub_merge', __( 'A ticket can’t be merged into itself.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		if ( (int) $source['site_id'] !== (int) $target['site_id'] ) {
			return new WP_Error( 'helpdesk_hero_hub_merge', __( 'Only tickets from the same site can be merged.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		if ( (int) $source['merged_into'] || (int) $target['merged_into'] ) {
			return new WP_Error( 'helpdesk_hero_hub_merge', __( 'One of these tickets was already merged.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		// Tickets merged into the source move along to the target.
		$wpdb->update( Helpdesk_Hero_Hub_DB::table( 'tickets' ), array( 'merged_into' => (int) $target['id'] ), array( 'merged_into' => (int) $source['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::update_ticket(
			(int) $source['id'],
			array(
				'merged_into' => (int) $target['id'],
				'status'      => 'closed',
				'closed_at'   => Helpdesk_Hero_Hub_DB::now(),
			)
		);
		$by = wp_get_current_user()->display_name;
		self::log(
			$source,
			'merged_into',
			array(
				'id'      => (int) $target['id'],
				'subject' => $target['subject'],
				'by'      => $by,
			)
		);
		self::log(
			$target,
			'merged_from',
			array(
				'id'      => (int) $source['id'],
				'subject' => $source['subject'],
				'by'      => $by,
			)
		);
		// The customer's site shows the merged ticket as closed, pointing to the other one.
		self::enqueue(
			(int) $source['site_id'],
			(int) $source['id'],
			'merged',
			array(
				'into'    => (int) $target['id'],
				'subject' => $target['subject'],
			)
		);
		self::update_ticket( (int) $target['id'], array( 'customer_at' => $target['customer_at'] ) );
		/**
		 * Fires after a ticket is merged into another.
		 *
		 * @param array $source Merged ticket.
		 * @param array $target Ticket kept.
		 */
		// What the merged ticket carried stays with the conversation: its tags join the kept
		// ticket's, and custom field answers fill in what the kept ticket doesn't have yet.
		// (Its messages, files and problem spots already show in the kept ticket.)
		$target = self::ticket( (int) $target['id'] );
		$tags   = array_values( array_unique( array_merge( (array) $target['tags'], (array) $source['tags'] ) ) );
		if ( $tags !== (array) $target['tags'] ) {
			self::set_tags( $target, $tags );
		}
		$mine   = $target['fields'] ? (array) json_decode( (string) $target['fields'], true ) : array();
		$theirs = $source['fields'] ? (array) json_decode( (string) $source['fields'], true ) : array();
		if ( array_diff_key( $theirs, $mine ) ) {
			self::update_ticket( (int) $target['id'], array( 'fields' => wp_json_encode( $mine + $theirs ) ) );
		}
		do_action( 'helpdesk_hero_hub_ticket_merged', $source, $target );
		return true;
	}

	/**
	 * Tickets merged into a ticket.
	 *
	 * @param int $id Ticket.
	 * @return array[] { id, subject }
	 */
	public static function merged_from( $id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, subject, customer_name, customer_email, description, created_at FROM %i WHERE merged_into = %d ORDER BY id ASC', Helpdesk_Hero_Hub_DB::table( 'tickets' ), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * The current person's bookmarked tickets.
	 *
	 * @return int[]
	 */
	public static function bookmarks() {
		$marks = get_user_meta( get_current_user_id(), 'helpdesk_hero_hub_bookmarks', true );
		return is_array( $marks ) ? array_values( array_map( 'intval', $marks ) ) : array();
	}

	/**
	 * Bookmark a ticket (or remove the bookmark) for the current person.
	 *
	 * @param int  $ticket_id Ticket.
	 * @param bool $on        Bookmarked.
	 * @return bool Bookmarked now.
	 */
	public static function set_bookmark( $ticket_id, $on ) {
		$marks = array_diff( self::bookmarks(), array( (int) $ticket_id ) );
		if ( $on ) {
			$marks[] = (int) $ticket_id;
		}
		update_user_meta( get_current_user_id(), 'helpdesk_hero_hub_bookmarks', array_slice( array_values( $marks ), -500 ) );
		return (bool) $on;
	}

	/**
	 * A ticket's problem spots (Pinpoint), oldest first. Tickets from before 2.1 had one.
	 *
	 * @param array $ticket Ticket row (diagnostics decoded).
	 * @return array[]
	 */
	public static function pinpoints( array $ticket ) {
		$d = (array) ( $ticket['diagnostics'] ?? array() );
		if ( ! empty( $d['pinpoints'] ) && is_array( $d['pinpoints'] ) ) {
			return array_values( $d['pinpoints'] );
		}
		return ! empty( $d['pinpoint']['url'] ) ? array( array_merge( $d['pinpoint'], array( 'source' => 'opening' ) ) ) : array();
	}

	/**
	 * Add problem spots to a ticket.
	 *
	 * @param int     $ticket_id Ticket.
	 * @param array[] $spots     Clean spots.
	 */
	public static function add_pinpoints( $ticket_id, array $spots ) {
		$ticket = self::ticket( (int) $ticket_id );
		if ( ! $ticket || ! $spots ) {
			return;
		}
		$d = (array) $ticket['diagnostics'];
		unset( $d['pinpoint'] );
		$d['pinpoints'] = array_slice( array_merge( self::pinpoints( $ticket ), $spots ), -50 );
		self::update_ticket( (int) $ticket_id, array( 'diagnostics' => $d ) );
	}

	/**
	 * Problem spots from a site's payload ("pinpoints", or "pinpoint" from 2.1 sites), cleaned,
	 * at most 5 at a time.
	 *
	 * @param array  $payload  Payload.
	 * @param string $site_url Site address.
	 * @param string $source   "opening" or the history item of the reply they came with.
	 * @return array[]
	 */
	public static function clean_pinpoints( array $payload, $site_url, $source ) {
		$raw = isset( $payload['pinpoints'] ) && is_array( $payload['pinpoints'] ) ? $payload['pinpoints'] : ( ! empty( $payload['pinpoint'] ) ? array( $payload['pinpoint'] ) : array() );
		$out = array();
		foreach ( array_slice( $raw, 0, 5 ) as $pin ) {
			$clean = self::clean_pinpoint( $pin, $site_url );
			if ( $clean ) {
				$out[] = array_merge(
					$clean,
					array(
						'source' => sanitize_key( (string) $source ),
						'time'   => gmdate( 'c' ),
					)
				);
			}
		}
		return $out;
	}

	/**
	 * Pinpoint from a site: the spot the customer picked. Only pages of that site are kept, so
	 * "Open and highlight" never sends support elsewhere.
	 *
	 * @param mixed  $pin      Raw.
	 * @param string $site_url Site address.
	 * @return array|null
	 */
	public static function clean_pinpoint( $pin, $site_url ) {
		if ( ! is_array( $pin ) || empty( $pin['id'] ) || empty( $pin['url'] ) ) {
			return null;
		}
		$url  = esc_url_raw( (string) $pin['url'], array( 'http', 'https' ) );
		$host = wp_parse_url( $site_url, PHP_URL_HOST );
		if ( '' === $url || ! $host || wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
			return null;
		}
		$text = static function ( $v, $len = 300 ) {
			return substr( sanitize_text_field( (string) $v ), 0, $len );
		};
		$list = static function ( $items, array $keys ) use ( $text ) {
			$out = array();
			foreach ( array_slice( (array) $items, 0, 20 ) as $item ) {
				$row = array();
				foreach ( $keys as $k => $type ) {
					$v         = ( (array) $item )[ $k ] ?? '';
					$row[ $k ] = 'int' === $type ? (int) $v : $text( $v );
				}
				$out[] = $row;
			}
			return $out;
		};
		$rect = (array) ( $pin['rect'] ?? array() );
		$view = (array) ( $pin['viewport'] ?? array() );
		return array(
			'id'       => substr( sanitize_key( (string) $pin['id'] ), 0, 32 ),
			'url'      => $url,
			'title'    => $text( $pin['title'] ?? '', 200 ),
			'selector' => $text( $pin['selector'] ?? '', 500 ),
			'kind'     => 'area' === ( $pin['kind'] ?? '' ) ? 'area' : 'element',
			'label'    => $text( $pin['label'] ?? '', 80 ),
			'text'     => $text( $pin['text'] ?? '', 200 ),
			'rect'     => array_map( 'intval', array_intersect_key( $rect + array( 'x' => 0, 'y' => 0, 'w' => 0, 'h' => 0 ), array_flip( array( 'x', 'y', 'w', 'h' ) ) ) ),
			'viewport' => array(
				'w'   => (int) ( $view['w'] ?? 0 ),
				'h'   => (int) ( $view['h'] ?? 0 ),
				'dpr' => (float) ( $view['dpr'] ?? 1 ),
			),
			'browser'  => $text( $pin['browser'] ?? '' ),
			'area'     => 'admin' === ( $pin['area'] ?? '' ) ? 'admin' : 'front',
			'errors'   => $list( $pin['errors'] ?? array(), array( 'message' => 'text', 'source' => 'text', 'line' => 'int' ) ),
			'requests' => $list( $pin['requests'] ?? array(), array( 'url' => 'text', 'status' => 'int', 'method' => 'text' ) ),
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
	 * @param array  $ticket      Ticket.
	 * @param string $body        Text.
	 * @param string $author      Name.
	 * @param array  $attachments Files the site sent: [ { id, name, size, type, sha256 } ].
	 * @return true|WP_Error
	 */
	public static function customer_reply( array $ticket, $body, $author, array $attachments = array(), array $pins = array() ) {
		$body = sanitize_textarea_field( $body );
		$item = self::log_raw( (int) $ticket['site_id'], (int) $ticket['id'], 'customer_reply', array( 'author' => sanitize_text_field( $author ), 'body' => $body, 'created_at' => gmdate( 'c' ) ) );
		if ( $pins ) {
			$site  = self::site( (int) $ticket['site_id'] );
			$spots = $site ? self::clean_pinpoints( array( 'pinpoints' => $pins ), (string) $site['url'], (string) $item ) : array();
			if ( $spots ) {
				self::add_pinpoints( (int) $ticket['id'], $spots );
				self::update_log( $item, array( 'spots' => count( $spots ) ) );
			}
		}
		if ( $attachments && Helpdesk_Hero_Hub_Attachments::config()['enabled'] ) {
			$files = Helpdesk_Hero_Hub_Attachments::from_site( $ticket, $attachments, (string) $item );
			if ( $files ) {
				self::update_log( $item, array( 'attachments' => $files ) );
			}
		}
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
		self::update_ticket( (int) $ticket['id'], array( 'customer_at' => Helpdesk_Hero_Hub_DB::now() ) );
		// The customer answered, so the ticket is back with the team (also when it was waiting on
		// them, which used to stay "waiting").
		if ( 'open' !== $ticket['status'] ) {
			self::set_status( self::ticket( (int) $ticket['id'] ), 'open', false );
		}
		/**
		 * Fires when a customer replies from their dashboard.
		 *
		 * @param array  $ticket Ticket (before the reply).
		 * @param string $body   Reply.
		 */
		do_action( 'helpdesk_hero_hub_customer_replied', $ticket, $body );
		return true;
	}

	/**
	 * An agent replied from the hub.
	 *
	 * @param array  $ticket      Ticket.
	 * @param string $body        Text.
	 * @param array  $attachments Attachment records (Pro).
	 * @return true|WP_Error
	 */
	public static function agent_reply( array $ticket, $body, array $attachments = array() ) {
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
			array_filter(
				array(
					'author'      => self::agent_name( $agent->ID ),
					'body'        => $body,
					'thread_id'   => (string) $thread,
					'created_at'  => gmdate( 'c' ),
					'attachments' => $attachments,
				)
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
			if ( '' !== (string) $ticket['custom_status'] ) {
				self::update_ticket( (int) $ticket['id'], array( 'custom_status' => '' ) );
			}
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
				'status'        => $status,
				'custom_status' => '',
				'closed_at'     => 'closed' === $status ? Helpdesk_Hero_Hub_DB::now() : null,
			)
		);
		self::enqueue( (int) $ticket['site_id'], (int) $ticket['id'], 'status', array( 'status' => $status ) );
		/**
		 * Fires after a ticket's status changes.
		 *
		 * @param array  $ticket Ticket (before the change).
		 * @param string $status New status: open | pending | closed.
		 */
		do_action( 'helpdesk_hero_hub_status_changed', $ticket, $status );
	}

	/**
	 * People on the team (they can open the hub), for assigning tickets.
	 *
	 * @return array[] { id, name, supporter }
	 */
	public static function team() {
		$cap   = (string) apply_filters( 'helpdesk_hero_hub_capability', 'manage_options' );
		$users = get_users(
			array(
				'capability__in' => array_unique( array( $cap, 'manage_options' ) ),
				'fields'         => array( 'ID', 'display_name' ),
				'number'         => 200,
				'orderby'        => 'display_name',
			)
		);
		$out = array();
		foreach ( $users as $u ) {
			if ( ! user_can( (int) $u->ID, 'helpdesk_hero_hub' ) ) {
				continue;
			}
			$out[] = array(
				'id'        => (int) $u->ID,
				'name'      => $u->display_name,
				'supporter' => self::can_support( (int) $u->ID ),
			);
		}
		/**
		 * Filters the people tickets can be assigned to.
		 *
		 * @param array[] $team { id, name, supporter }.
		 */
		return (array) apply_filters( 'helpdesk_hero_hub_team', $out );
	}

	/**
	 * Whether the current person wants unassigned tickets assigned to them when they reply
	 * (on unless they turned it off).
	 *
	 * @return bool
	 */
	public static function assign_on_reply() {
		return '0' !== (string) get_user_meta( get_current_user_id(), 'helpdesk_hero_hub_assign_on_reply', true );
	}

	/**
	 * Assign a ticket to a team member (0 = nobody).
	 *
	 * @param array $ticket  Ticket.
	 * @param int   $user_id Team member.
	 * @return true|WP_Error
	 */
	public static function assign( array $ticket, $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id && ! in_array( $user_id, wp_list_pluck( self::team(), 'id' ), true ) ) {
			return new WP_Error( 'helpdesk_hero_hub_assignee', __( 'That person isn’t on your team.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		if ( (int) $ticket['assignee_id'] === $user_id ) {
			return true;
		}
		self::update_ticket(
			(int) $ticket['id'],
			array(
				'assignee_id' => $user_id,
				'updated_at'  => $ticket['updated_at'],
			)
		);
		$to = $user_id ? get_userdata( $user_id ) : false;
		self::log(
			$ticket,
			'assigned',
			array(
				'to'   => $user_id,
				'name' => $to ? $to->display_name : '',
				'by'   => wp_get_current_user()->display_name,
			)
		);
		if ( $to && get_current_user_id() !== $user_id && $to->user_email ) {
			wp_mail(
				$to->user_email,
				/* translators: %s: ticket subject */
				sprintf( __( '[Assigned to you] %s', 'helpdesk-hero-hub' ), $ticket['subject'] ),
				sprintf(
					/* translators: 1: person, 2: ticket subject, 3: link */
					__( "%1\$s assigned you a ticket: %2\$s\n\n%3\$s", 'helpdesk-hero-hub' ),
					wp_get_current_user()->display_name,
					$ticket['subject'],
					self::ticket_url( (int) $ticket['id'] )
				)
			);
		}
		/**
		 * Fires when a ticket is assigned.
		 *
		 * @param array $ticket  Ticket (before the change).
		 * @param int   $user_id New assignee (0 = nobody).
		 */
		do_action( 'helpdesk_hero_hub_ticket_assigned', $ticket, $user_id );
		return true;
	}

	/**
	 * Internal note on a ticket: for the team only, never sent to the customer.
	 *
	 * @param array  $ticket Ticket.
	 * @param string $body   Text.
	 * @return int History item ID.
	 */
	public static function add_note( array $ticket, $body ) {
		$user = wp_get_current_user();
		$id   = self::log_raw(
			(int) $ticket['site_id'],
			(int) $ticket['id'],
			'internal_note',
			array(
				'author'    => $user->display_name,
				'author_id' => (int) $user->ID,
				'body'      => sanitize_textarea_field( $body ),
			)
		);
		/**
		 * Fires when someone adds an internal note.
		 *
		 * @param array  $ticket Ticket.
		 * @param string $body   Note.
		 */
		do_action( 'helpdesk_hero_hub_internal_note', $ticket, $body );
		return $id;
	}

	/**
	 * Set a custom status (Pro). Its base status applies as usual: closing still ends access.
	 *
	 * @param array  $ticket Ticket.
	 * @param string $id     Custom status ID.
	 * @return true|WP_Error
	 */
	public static function set_custom_status( array $ticket, $id ) {
		$custom = self::custom_status( sanitize_key( (string) $id ) );
		if ( ! $custom ) {
			return new WP_Error( 'helpdesk_hero_hub_status', __( 'Unknown status.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		self::set_status( $ticket, $custom['base'] );
		self::update_ticket(
			(int) $ticket['id'],
			array(
				'custom_status' => $custom['id'],
				'updated_at'    => Helpdesk_Hero_Hub_DB::now(),
			)
		);
		self::log( $ticket, 'custom_status', array( 'label' => $custom['label'] ) );
		// The customer sees this status's name only if the team chose to show it.
		self::enqueue(
			(int) $ticket['site_id'],
			(int) $ticket['id'],
			'status',
			array(
				'status' => $custom['base'],
				'label'  => $custom['public'] ? $custom['label'] : '',
			)
		);
		return true;
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
		/**
		 * Fires after a ticket's tags change.
		 *
		 * @param array    $ticket Ticket (before the change).
		 * @param string[] $ids    New tag IDs.
		 */
		do_action( 'helpdesk_hero_hub_tags_changed', $ticket, $ids );
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
		if ( $supporter ) {
			return $supporter['name'];
		}
		// Replies sent by automations (no one logged in) come from the team.
		return $user ? $user->display_name : Helpdesk_Hero_Hub_Settings::team_name();
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
	 * Add fields to a history item's payload.
	 *
	 * @param int   $id    Item.
	 * @param array $extra Fields.
	 */
	private static function update_log( $id, array $extra ) {
		global $wpdb;
		$table   = Helpdesk_Hero_Hub_DB::table( 'outbox' );
		$payload = (array) json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM %i WHERE id = %d', $table, $id ) ), true ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, array( 'payload' => wp_json_encode( array_merge( $payload, $extra ) ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		if ( ! $only_ticket ) {
			Helpdesk_Hero_Hub_Attachments::cleanup();
		}
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
