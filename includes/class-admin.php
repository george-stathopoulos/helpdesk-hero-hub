<?php
/**
 * Hub admin screen.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Support Hub page and loads the dashboard app.
 */
final class Helpdesk_Hero_Hub_Admin {

	const SLUG       = 'helpdesk-hero-hub';
	const ONBOARDING = 'helpdesk_hero_hub_onboarding';
	const REDIRECT   = 'helpdesk_hero_hub_activation_redirect';
	const SITE       = 'https://george-stathopoulos.github.io/helpdesk-hero/';
	const REPO       = 'https://github.com/george-stathopoulos/helpdesk-hero-hub';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_init', array( __CLASS__, 'activation_redirect' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( HELPDESK_HERO_HUB_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Open the setup guide once, right after the plugin is activated on its own
	 * (not during bulk activation, network activation or WP-CLI).
	 */
	public static function activation_redirect() {
		if ( ! get_transient( self::REDIRECT ) ) {
			return;
		}
		delete_transient( self::REDIRECT );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of WordPress's own bulk-activation flag.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) || 'done' === get_option( self::ONBOARDING ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '#/welcome' ) );
		exit;
	}

	/**
	 * Top-level menu.
	 */
	public static function menu() {
		self::$hook = add_menu_page(
			__( 'Support Hub', 'helpdesk-hero-hub' ),
			__( 'Support Hub', 'helpdesk-hero-hub' ),
			'helpdesk_hero_hub',
			self::SLUG,
			array( __CLASS__, 'render' ),
			self::menu_icon(),
			3
		);
	}

	/**
	 * Mount point for the app.
	 */
	public static function render() {
		echo '<div id="hdh-root" class="hdh-root"><div class="hdh-boot" role="status">' . esc_html__( 'Loading Support Hub…', 'helpdesk-hero-hub' ) . '</div></div>';
	}

	/**
	 * Full-bleed layout on our page.
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && self::$hook && $screen->id === self::$hook ? $classes . ' hdh-app' : $classes;
	}

	/**
	 * Enqueue the dashboard bundle on our page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		$asset_file = HELPDESK_HERO_HUB_DIR . 'build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_enqueue_script( 'helpdesk-hero-hub-admin', HELPDESK_HERO_HUB_URL . 'build/index.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'helpdesk-hero-hub-admin', HELPDESK_HERO_HUB_URL . 'build/style-index.css', array(), $asset['version'] );
		wp_style_add_data( 'helpdesk-hero-hub-admin', 'rtl', 'replace' );
		wp_set_script_translations( 'helpdesk-hero-hub-admin', 'helpdesk-hero-hub' );

		$helpdesk = Helpdesk_Hero_Hub_Helpdesk::current();
		wp_add_inline_script(
			'helpdesk-hero-hub-admin',
			'window.hdhBoot = ' . wp_json_encode(
				array(
					'version'    => HELPDESK_HERO_HUB_VERSION,
					'adminUrl'   => admin_url(),
					'teamName'   => Helpdesk_Hero_Hub_Settings::team_name(),
					'userName'   => wp_get_current_user()->display_name,
					'canManage'  => current_user_can( 'manage_options' ),
					'canSupport' => Helpdesk_Hero_Hub::can_support(),
					/**
					 * Filters whether "Log in" opens the customer's site in the same tab instead of a new one.
					 *
					 * @param bool $same_tab Same tab.
					 */
					'loginSameTab' => (bool) apply_filters( 'helpdesk_hero_hub_login_same_tab', false ),
					'helpdesk'   => $helpdesk ? $helpdesk->label() : '',
					'aiEnabled'  => Helpdesk_Hero_Hub_AI::available(),
					'localAi'    => Helpdesk_Hero_Hub_AI::local_ai(),
					'gmtOffset'  => (float) get_option( 'gmt_offset' ),
					'supportUrl' => 'https://wordpress.org/support/plugin/helpdesk-hero-hub/',
					'customerPluginUrl' => 'https://wordpress.org/plugins/helpdesk-hero/',
					/**
					 * Filters whether the Pro add-on is active (it registers its own pages).
					 *
					 * @param bool $active Active.
					 */
					'pro'        => (bool) apply_filters( 'helpdesk_hero_hub_pro_active', false ),
					'onboarded'  => 'pending' !== get_option( self::ONBOARDING, 'done' ),
					'feedback'   => ! get_user_meta( get_current_user_id(), 'helpdesk_hero_hub_feedback', true ),
					'installed'  => (int) get_option( 'helpdesk_hero_hub_installed', time() ),
					'siteUrl'    => self::SITE,
					'proUrl'     => self::SITE . 'pro/',
					'docsUrl'    => self::SITE . 'docs/',
					'reviewUrl'  => 'https://wordpress.org/support/plugin/helpdesk-hero-hub/reviews/#new-post',
					'issuesUrl'  => self::REPO . '/issues/new',
				)
			) . ';',
			'before'
		);

		/**
		 * Fires after the dashboard script is enqueued, so add-ons can enqueue theirs.
		 * Add-ons should depend on the `helpdesk-hero-hub-admin` handle and register pages and
		 * panels with the JavaScript filters `helpdeskHeroHub.routes`, `helpdeskHeroHub.ticketPanels`,
		 * `helpdeskHeroHub.replyTools` and `helpdeskHeroHub.sitesPanels`.
		 *
		 * @param string $handle Dashboard script handle.
		 */
		do_action( 'helpdesk_hero_hub_admin_enqueue', 'helpdesk-hero-hub-admin' );
	}

	/**
	 * Dashboard link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Open hub', 'helpdesk-hero-hub' ) . '</a>' );
		return $links;
	}

	/**
	 * Menu icon (a life ring) as a data URI SVG.
	 *
	 * @return string
	 */
	public static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" fill-rule="evenodd" d="M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm3.9 10.5 2 2a6 6 0 0 0 0-9l-2 2a3 3 0 0 1 0 5Zm-1.4 1.4a3 3 0 0 1-5 0l-2 2a6 6 0 0 0 9 0l-2-2ZM6.1 12.5a3 3 0 0 1 0-5l-2-2a6 6 0 0 0 0 9l2-2Zm1.4-6.4a3 3 0 0 1 5 0l2-2a6 6 0 0 0-9 0l2 2ZM10 8.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
