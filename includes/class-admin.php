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
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 80 );
		add_action( 'admin_notices', array( __CLASS__, 'new_tickets_notice' ) );
		add_action( 'admin_post_helpdesk_hero_hub_dismiss', array( __CLASS__, 'dismiss_notice' ) );
	}

	/**
	 * Unread tickets, once per request.
	 *
	 * @return array{count:int, latest:array[]}
	 */
	private static function unread() {
		static $unread = null;
		if ( null === $unread ) {
			$unread = get_option( Helpdesk_Hero_Hub_DB::OPTION ) === Helpdesk_Hero_Hub_DB::VERSION ? Helpdesk_Hero_Hub::unread_tickets( 3 ) : array(
				'count'  => 0,
				'latest' => array(),
			);
		}
		return $unread;
	}

	/**
	 * "Support Hub" in the admin bar, with the number of unread tickets.
	 *
	 * @param WP_Admin_Bar $bar Bar.
	 */
	public static function admin_bar( $bar ) {
		if ( ! current_user_can( 'helpdesk_hero_hub' ) ) {
			return;
		}
		$count = self::unread()['count'];
		if ( ! $count ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'helpdesk-hero-hub',
				'title' => '<span class="ab-icon dashicons dashicons-sos" style="top:2px"></span><span class="ab-label">' . esc_html(
					/* translators: %d: number of tickets */
					sprintf( _n( '%d new ticket', '%d new tickets', $count, 'helpdesk-hero-hub' ), $count )
				) . '</span>',
				'href'  => admin_url( 'admin.php?page=' . self::SLUG . '#/inbox' ),
			)
		);
	}

	/**
	 * On other admin screens: a customer opened a ticket or replied. Dismissed until the next one.
	 */
	public static function new_tickets_notice() {
		if ( ! current_user_can( 'helpdesk_hero_hub' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && self::$hook && $screen->id === self::$hook ) {
			return;
		}
		$unread = self::unread();
		if ( ! $unread['count'] || (string) get_user_meta( get_current_user_id(), 'helpdesk_hero_hub_notice_seen', true ) >= (string) $unread['latest'][0]['at'] ) {
			return;
		}
		$t = $unread['latest'][0];
		echo '<div class="notice notice-info"><p><strong>';
		if ( 1 === $unread['count'] ) {
			echo esc_html(
				'reply' === $t['kind']
					/* translators: 1: customer site, 2: ticket subject */
					? sprintf( __( '%1$s replied to “%2$s”', 'helpdesk-hero-hub' ), $t['site'], $t['subject'] )
					/* translators: 1: customer site, 2: ticket subject */
					: sprintf( __( 'New ticket from %1$s: “%2$s”', 'helpdesk-hero-hub' ), $t['site'], $t['subject'] )
			);
		} else {
			echo esc_html(
				/* translators: 1: number of tickets, 2: customer site of the newest one */
				sprintf( _n( '%1$d ticket needs a look, the newest from %2$s.', '%1$d tickets need a look, the newest from %2$s.', $unread['count'], 'helpdesk-hero-hub' ), $unread['count'], $t['site'] )
			);
		}
		echo '</strong> ';
		$open = 1 === $unread['count'] ? '#/ticket/' . (int) $t['id'] : '#/inbox';
		echo '<a class="button button-primary button-small" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . $open ) ) . '">' . esc_html( 1 === $unread['count'] ? __( 'Open the ticket', 'helpdesk-hero-hub' ) : __( 'Open the inbox', 'helpdesk-hero-hub' ) ) . '</a> ';
		echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=helpdesk_hero_hub_dismiss&until=' . rawurlencode( $t['at'] ) ), 'helpdesk_hero_hub_dismiss' ) ) . '">' . esc_html__( 'Dismiss', 'helpdesk-hero-hub' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Hide the new-ticket notice until another ticket or reply arrives.
	 */
	public static function dismiss_notice() {
		check_admin_referer( 'helpdesk_hero_hub_dismiss' );
		if ( current_user_can( 'helpdesk_hero_hub' ) && isset( $_GET['until'] ) ) {
			update_user_meta( get_current_user_id(), 'helpdesk_hero_hub_notice_seen', sanitize_text_field( wp_unslash( $_GET['until'] ) ) );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
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
		$count      = current_user_can( 'helpdesk_hero_hub' ) ? self::unread()['count'] : 0;
		$badge      = $count ? ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . (int) $count . '</span></span>' : '';
		self::$hook = add_menu_page(
			__( 'Support Hub', 'helpdesk-hero-hub' ),
			__( 'Support Hub', 'helpdesk-hero-hub' ) . $badge,
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
					'customerPluginUrl' => 'https://github.com/george-stathopoulos/helpdesk-hero/releases/latest', // Until the WordPress.org listing is live.
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
