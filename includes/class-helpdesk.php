<?php
/**
 * Help desk connector base.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * A help desk the hub creates tickets in. Implementations: Help Scout, Zendesk.
 * Add-ons can register more with the helpdesk_hero_hub_helpdesks filter.
 */
abstract class Helpdesk_Hero_Hub_Helpdesk {

	/**
	 * Registered connectors.
	 *
	 * @return array Key => class name.
	 */
	public static function registry() {
		/**
		 * Filters the available help desk connectors. Helpdesk Hero Pro adds Help Scout and Zendesk.
		 *
		 * @param array $connectors Key => class name extending Helpdesk_Hero_Hub_Helpdesk.
		 */
		return (array) apply_filters( 'helpdesk_hero_hub_helpdesks', array() );
	}

	/**
	 * The configured connector, if it has credentials.
	 *
	 * @return Helpdesk_Hero_Hub_Helpdesk|null
	 */
	public static function current() {
		return self::make( (string) Helpdesk_Hero_Hub_Settings::get( 'helpdesk' ) );
	}

	/**
	 * The connector a ticket was created in.
	 *
	 * @param array $ticket Hub ticket.
	 * @return Helpdesk_Hero_Hub_Helpdesk|null
	 */
	public static function for_ticket( array $ticket ) {
		return '' !== $ticket['helpdesk_id'] ? self::make( (string) $ticket['helpdesk'] ) : null;
	}

	/**
	 * Build a connector by key.
	 *
	 * @param string $key Key.
	 * @return Helpdesk_Hero_Hub_Helpdesk|null
	 */
	public static function make( $key ) {
		$registry = self::registry();
		if ( ! isset( $registry[ $key ] ) || ! class_exists( $registry[ $key ] ) ) {
			return null;
		}
		$connector = new $registry[ $key ]();
		return $connector->configured() ? $connector : null;
	}

	/**
	 * Key.
	 *
	 * @return string
	 */
	abstract public function key();

	/**
	 * Display name.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Whether credentials are present.
	 *
	 * @return bool
	 */
	abstract public function configured();

	/**
	 * Check the credentials.
	 *
	 * @return string|WP_Error Success message.
	 */
	abstract public function test();

	/**
	 * Create a ticket.
	 *
	 * @param array $t subject, body, name, email, priority, tags, attachment {name, content}, note.
	 * @return array|WP_Error { id, number, thread_ids }
	 */
	abstract public function create_ticket( array $t );

	/**
	 * Add a reply from the customer.
	 *
	 * @param array  $ticket Hub ticket.
	 * @param string $body   Text.
	 * @return string|WP_Error Thread ID.
	 */
	abstract public function customer_reply( array $ticket, $body );

	/**
	 * Add a public reply from the support team.
	 *
	 * @param array  $ticket Hub ticket.
	 * @param string $body   Text.
	 * @return string|WP_Error Thread ID.
	 */
	abstract public function agent_reply( array $ticket, $body );

	/**
	 * Add an internal note.
	 *
	 * @param array  $ticket Hub ticket.
	 * @param string $body   Text.
	 * @return string|WP_Error Thread ID.
	 */
	abstract public function note( array $ticket, $body );

	/**
	 * Change status.
	 *
	 * @param array  $ticket Hub ticket.
	 * @param string $status open | pending | closed.
	 * @return true|WP_Error
	 */
	abstract public function set_status( array $ticket, $status );

	/**
	 * Current status and threads.
	 *
	 * @param array $ticket Hub ticket.
	 * @return array|WP_Error { status: open|pending|closed|'', threads: [{ id, type: agent|customer|note, public, author, body, created_at }] }
	 */
	abstract public function fetch( array $ticket );

	/**
	 * Link to the ticket in the help desk.
	 *
	 * @param array $ticket Hub ticket.
	 * @return string
	 */
	abstract public function url( array $ticket );

	/**
	 * Short reference shown to the customer, e.g. "Help Scout #1234".
	 *
	 * @param array $created Result of create_ticket().
	 * @return string
	 */
	public function reference( array $created ) {
		return $this->label() . ' #' . ( ! empty( $created['number'] ) ? $created['number'] : $created['id'] );
	}

	/**
	 * HTML help desk text to plain text.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	protected static function to_text( $html ) {
		$html = preg_replace( '#<br\s*/?>#i', "\n", (string) $html );
		$html = preg_replace( '#</(p|div|li|h[1-6])>#i', "\n\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	/**
	 * Plain text to simple HTML.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected static function to_html( $text ) {
		return nl2br( esc_html( (string) $text ) );
	}

	/**
	 * JSON HTTP request.
	 *
	 * @param string $method  Method.
	 * @param string $url     URL.
	 * @param array  $headers Headers.
	 * @param mixed  $body    Array (sent as JSON), string (sent raw) or null.
	 * @return array|WP_Error { code, data, headers }
	 */
	protected static function http( $method, $url, array $headers, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array_merge( array( 'Accept' => 'application/json' ), $headers ),
		);
		if ( is_array( $body ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		} elseif ( null !== $body ) {
			$args['body'] = $body;
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 ) {
			$message = '';
			if ( is_array( $data ) ) {
				$message = (string) ( $data['message'] ?? $data['error_description'] ?? ( is_string( $data['error'] ?? null ) ? $data['error'] : '' ) ?? '' );
				if ( isset( $data['description'] ) && is_string( $data['description'] ) ) {
					$message = $data['description'];
				}
			}
			/* translators: 1: HTTP status, 2: message */
			return new WP_Error( 'helpdesk_hero_helpdesk', sprintf( __( 'Help desk error %1$d: %2$s', 'helpdesk-hero-hub' ), $code, '' !== $message ? $message : wp_remote_retrieve_response_message( $response ) ) );
		}
		return array(
			'code'    => $code,
			'data'    => is_array( $data ) ? $data : array(),
			'headers' => wp_remote_retrieve_headers( $response ),
		);
	}
}
