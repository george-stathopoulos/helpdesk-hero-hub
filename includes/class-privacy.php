<?php
/**
 * Privacy policy text and personal data tools for the hub.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Suggested privacy policy text, and export/erase of tickets by the customer's email address.
 */
final class Helpdesk_Hero_Hub_Privacy {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	/**
	 * Suggested policy text.
	 */
	public static function policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p>' . esc_html__( 'We provide support for our customers’ WordPress sites with Helpdesk Hero. When a customer opens a support ticket from their site, we receive the ticket text, the name and email address of the person who opened it, and technical information about their site (software versions, plugins and themes, recent errors and changes). Passwords and keys are removed, and email addresses in logs are masked, before the information leaves their site.', 'helpdesk-hero-hub' ) . '</p>';
		$text .= '<p>' . esc_html__( 'We keep tickets to provide support and to improve our service. If we use a help desk service (such as Help Scout or Zendesk), tickets are also stored there. When our team has temporary access to a customer’s site, everything it does there is logged on that site for the customer to review.', 'helpdesk-hero-hub' ) . '</p>';
		wp_add_privacy_policy_content( 'Helpdesk Hero Hub', wp_kses_post( $text ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function exporters( $exporters ) {
		$exporters['helpdesk-hero-hub'] = array(
			'exporter_friendly_name' => __( 'Support tickets (hub)', 'helpdesk-hero-hub' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function erasers( $erasers ) {
		$erasers['helpdesk-hero-hub'] = array(
			'eraser_friendly_name' => __( 'Support tickets (hub)', 'helpdesk-hero-hub' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Tickets opened with this email address.
	 *
	 * @param string $email Email.
	 * @return array[]
	 */
	private static function tickets_for( $email ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, subject, customer_name, customer_email, description, rating_comment, created_at FROM %i WHERE customer_email = %s ORDER BY id ASC', Helpdesk_Hero_Hub_DB::table( 'tickets' ), $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Export.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function export( $email ) {
		$data = array();
		foreach ( self::tickets_for( $email ) as $ticket ) {
			$data[] = array(
				'group_id'    => 'helpdesk-hero-hub',
				'group_label' => __( 'Support tickets', 'helpdesk-hero-hub' ),
				'item_id'     => 'hub-ticket-' . $ticket['id'],
				'data'        => array(
					array(
						'name'  => __( 'Subject', 'helpdesk-hero-hub' ),
						'value' => $ticket['subject'],
					),
					array(
						'name'  => __( 'Name', 'helpdesk-hero-hub' ),
						'value' => $ticket['customer_name'],
					),
					array(
						'name'  => __( 'Created', 'helpdesk-hero-hub' ),
						'value' => $ticket['created_at'],
					),
					array(
						'name'  => __( 'Description', 'helpdesk-hero-hub' ),
						'value' => (string) $ticket['description'],
					),
					array(
						'name'  => __( 'Rating comment', 'helpdesk-hero-hub' ),
						'value' => (string) $ticket['rating_comment'],
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase: anonymise the person on their tickets. The tickets stay, so statistics stay correct;
	 * copies in an external help desk must be removed there.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function erase( $email ) {
		global $wpdb;
		$tickets = self::tickets_for( $email );
		foreach ( $tickets as $ticket ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				Helpdesk_Hero_Hub_DB::table( 'tickets' ),
				array(
					'customer_name'  => __( 'Deleted user', 'helpdesk-hero-hub' ),
					'customer_email' => '',
					'description'    => __( '(Removed at the customer’s request.)', 'helpdesk-hero-hub' ),
					'rating_comment' => null,
				),
				array( 'id' => (int) $ticket['id'] )
			);
		}
		return array(
			'items_removed'  => count( $tickets ),
			'items_retained' => false,
			'messages'       => $tickets && Helpdesk_Hero_Hub_Helpdesk::current() ? array( __( 'Copies in your help desk (Help Scout or Zendesk) must be removed there.', 'helpdesk-hero-hub' ) ) : array(),
			'done'           => true,
		);
	}
}
