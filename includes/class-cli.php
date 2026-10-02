<?php
/**
 * WP-CLI commands for the hub.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage the support hub from the command line.
 */
final class Helpdesk_Hero_Hub_CLI {

	/**
	 * Show connected sites and open tickets.
	 */
	public function status() {
		$sites = Helpdesk_Hero_Hub::sites();
		WP_CLI::log( 'Team: ' . Helpdesk_Hero_Hub_Settings::team_name() );
		$helpdesk = Helpdesk_Hero_Hub_Helpdesk::current();
		WP_CLI::log( 'Help desk: ' . ( $helpdesk ? $helpdesk->label() : 'none (email)' ) );
		WP_CLI::log( 'Sites: ' . count( $sites ) );
		foreach ( $sites as $s ) {
			WP_CLI::log( sprintf( '  #%d %-30s %-8s %s', $s['id'], $s['name'] ? $s['name'] : $s['label'], $s['status'], $s['url'] ) );
		}
		WP_CLI::log( 'Open tickets: ' . count( Helpdesk_Hero_Hub::tickets( array( 'status' => 'active', 'limit' => 1000 ) ) ) );
	}

	/**
	 * Create a connection code for a customer site.
	 *
	 * ## OPTIONS
	 *
	 * <label>
	 * : Customer or site name.
	 *
	 * [--email=<email>]
	 * : Customer email.
	 *
	 * @param array $args       Args.
	 * @param array $assoc_args Options.
	 */
	public function invite( $args, $assoc_args ) {
		$invite = Helpdesk_Hero_Hub::create_invite( $args[0], (string) ( $assoc_args['email'] ?? '' ) );
		WP_CLI::log( $invite['code'] );
	}

	/**
	 * Relay new help desk replies to sites now.
	 */
	public function sync() {
		WP_CLI::success( sprintf( 'Relayed %d update(s).', Helpdesk_Hero_Hub::sync() ) );
	}
}
