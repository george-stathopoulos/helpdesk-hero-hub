<?php
/**
 * Hub bootstrap.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the hub together.
 */
final class Helpdesk_Hero_Hub_Plugin {

	/**
	 * Singleton.
	 *
	 * @var Helpdesk_Hero_Hub_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the instance.
	 *
	 * @return Helpdesk_Hero_Hub_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- a 5 minute help desk sync is intended.
		add_action( 'init', array( 'Helpdesk_Hero_Hub_DB', 'maybe_install' ), 1 );
		add_action( 'init', array( 'Helpdesk_Hero_Hub', 'schedule' ) );
		add_action( 'rest_api_init', array( 'Helpdesk_Hero_Hub_Site_API', 'register' ) );
		add_action( 'rest_api_init', array( 'Helpdesk_Hero_Hub_Admin_REST', 'register' ) );
		Helpdesk_Hero_Hub::init();
		Helpdesk_Hero_Hub_Admin::init();
		Helpdesk_Hero_Hub_Privacy::init();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once HELPDESK_HERO_HUB_DIR . 'includes/class-cli.php';
			WP_CLI::add_command( 'helpdesk-hero-hub', 'Helpdesk_Hero_Hub_CLI' );
		}
	}

	/**
	 * Five-minute schedule for help desk sync.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		$schedules['helpdesk_hero_5min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Helpdesk Hero)', 'helpdesk-hero-hub' ),
		);
		return $schedules;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		Helpdesk_Hero_Hub_DB::install();
		if ( false === get_option( Helpdesk_Hero_Hub_Admin::ONBOARDING ) ) {
			update_option( Helpdesk_Hero_Hub_Admin::ONBOARDING, 'pending', false );
			update_option( 'helpdesk_hero_hub_installed', time(), false );
		}
		if ( 'done' !== get_option( Helpdesk_Hero_Hub_Admin::ONBOARDING ) ) {
			set_transient( Helpdesk_Hero_Hub_Admin::REDIRECT, 1, MINUTE_IN_SECONDS );
		}
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		Helpdesk_Hero_Hub::schedule();
	}

	/**
	 * Deactivation: stop syncing. Sites, tickets and settings are kept.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Helpdesk_Hero_Hub::CRON );
	}
}
