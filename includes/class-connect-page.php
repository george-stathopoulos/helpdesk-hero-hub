<?php
/**
 * Connection link page.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * The page a connection link opens (https://hub.example/?hdh-connect=…). The customer enters
 * their site address and is sent to their own dashboard, where Get Help opens with the code
 * filled in; they click Connect. Nothing connects on this page: the token in the link is only
 * used on their site, by the Helpdesk Hero plugin, exactly like a pasted code.
 */
final class Helpdesk_Hero_Hub_Connect_Page {

	const PARAM = 'hdh-connect';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
	}

	/**
	 * Show the page (or send the customer on to their site).
	 */
	public static function maybe_render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the invite token is the credential; nothing is changed here.
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return;
		}
		$token = sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) );
		$site  = Helpdesk_Hero_Hub::invite_site( $token );
		$url   = isset( $_GET['site'] ) ? self::site_url( sanitize_text_field( wp_unslash( $_GET['site'] ) ) ) : '';
		$error = isset( $_GET['site'] ) && '' === $url ? __( 'That doesn’t look like a site address. Enter it like example.com.', 'helpdesk-hero-hub' ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// The token must not travel to other sites in the Referer header.
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		nocache_headers();

		if ( $site && '' !== $url ) {
			$target = add_query_arg(
				array(
					'page'     => 'helpdesk-hero',
					'hdh_code' => rawurlencode( Helpdesk_Hero_Hub::make_code( $token ) ),
				),
				trailingslashit( $url ) . 'wp-admin/admin.php'
			);
			wp_redirect( $target ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the customer's own site, which they just typed in.
			exit;
		}

		self::render( $site, $token, $error );
		exit;
	}

	/**
	 * A site address typed by the customer, as a clean http(s) URL ('' if it isn't one).
	 *
	 * @param string $raw Typed address.
	 * @return string
	 */
	public static function site_url( $raw ) {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			$raw = 'https://' . $raw;
		}
		// Drop anything after the site's path that people paste by accident (wp-admin, query strings).
		$raw   = preg_replace( '#/(wp-admin|wp-login\.php).*$#i', '', $raw );
		$parts = wp_parse_url( $raw );
		if ( empty( $parts['host'] ) || ! preg_match( '/^[a-z0-9.-]+$/i', $parts['host'] ) ) {
			return '';
		}
		$url = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . ( ! empty( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '' );
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * The page.
	 *
	 * @param array|null $site  Waiting site (null when the link expired or was used).
	 * @param string     $token Token.
	 * @param string     $error Message for a bad address.
	 */
	private static function render( $site, $token, $error ) {
		$team   = Helpdesk_Hero_Hub_Settings::team_name();
		$plugin = 'https://github.com/george-stathopoulos/helpdesk-hero/releases/latest'; // Until the WordPress.org listing is live.
		/**
		 * Filters the link where customers get the Helpdesk Hero plugin, on the connection page.
		 *
		 * @param string $plugin URL.
		 */
		$plugin = (string) apply_filters( 'helpdesk_hero_hub_customer_plugin_url', $plugin );
		status_header( $site ? 200 : 410 );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?php /* translators: %s: support team name */ echo esc_html( sprintf( __( 'Connect your site to %s', 'helpdesk-hero-hub' ), $team ) ); ?></title>
<style>
:root{--bg:#f4f3f0;--card:#fff;--ink:#1a1a1a;--muted:#6b6b6b;--line:#e4e2dc;--accent:#4a3aa7;--accent-ink:#fff;--bad:#c62a2f}
@media (prefers-color-scheme:dark){:root{--bg:#141414;--card:#1e1e1e;--ink:#eee;--muted:#a0a0a0;--line:#333;--accent:#8b7cf0;--accent-ink:#111;--bad:#ff8a8a}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
main{max-width:560px;margin:8vh auto;padding:0 16px}.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:28px}
h1{font-size:22px;line-height:1.3;margin:0 0 6px}p{margin:0 0 14px}.muted{color:var(--muted);font-size:13.5px}
ol{margin:18px 0 0;padding:0;list-style:none;counter-reset:s}ol>li{counter-increment:s;position:relative;padding:0 0 18px 40px}
ol>li::before{content:counter(s);position:absolute;left:0;top:0;width:26px;height:26px;border-radius:50%;background:var(--accent);color:var(--accent-ink);font-weight:700;font-size:13px;display:grid;place-items:center}
label{display:block;font-weight:600;margin:0 0 6px}input{width:100%;font:inherit;padding:10px 12px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)}
button,.btn{display:inline-block;margin-top:10px;padding:10px 16px;border:0;border-radius:9px;background:var(--accent);color:var(--accent-ink);font:inherit;font-weight:600;cursor:pointer;text-decoration:none}
a{color:var(--accent)}.error{color:var(--bad);margin:8px 0 0}details{margin-top:6px}summary{cursor:pointer;color:var(--muted);font-size:13.5px}
textarea{width:100%;margin-top:8px;font:12.5px/1.5 ui-monospace,Menlo,monospace;padding:10px;border:1px solid var(--line);border-radius:9px;background:var(--bg);color:var(--ink)}
</style>
</head>
<body>
<main>
	<div class="card">
		<?php if ( ! $site ) : ?>
			<h1><?php esc_html_e( 'This link doesn’t work any more', 'helpdesk-hero-hub' ); ?></h1>
			<p>
				<?php
				/* translators: %s: support team name */
				echo esc_html( sprintf( __( 'It was already used, or it expired (links work for 7 days). Ask %s for a new one.', 'helpdesk-hero-hub' ), $team ) );
				?>
			</p>
		<?php else : ?>
			<h1>
				<?php
				/* translators: %s: support team name */
				echo esc_html( sprintf( __( 'Connect your site to %s', 'helpdesk-hero-hub' ), $team ) );
				?>
			</h1>
			<p class="muted">
				<?php
				/* translators: %s: support team name */
				echo esc_html( sprintf( __( 'Then you can ask %s for help from your WordPress dashboard, with your site’s details attached. You decide if and when they can log in.', 'helpdesk-hero-hub' ), $team ) );
				?>
			</p>
			<ol>
				<li>
					<strong><?php esc_html_e( 'Install the free Helpdesk Hero plugin on your site', 'helpdesk-hero-hub' ); ?></strong><br>
					<span class="muted">
						<a href="<?php echo esc_url( $plugin ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Download it', 'helpdesk-hero-hub' ); ?></a>,
						<?php esc_html_e( 'then in your dashboard go to Plugins → Add New → Upload Plugin, and activate it. Already installed? Go to step 2.', 'helpdesk-hero-hub' ); ?>
					</span>
				</li>
				<li>
					<form method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
						<input type="hidden" name="<?php echo esc_attr( self::PARAM ); ?>" value="<?php echo esc_attr( $token ); ?>">
						<label for="hdh-site"><?php esc_html_e( 'Your site address', 'helpdesk-hero-hub' ); ?></label>
						<input id="hdh-site" name="site" type="text" inputmode="url" autocomplete="url" placeholder="example.com" required>
						<?php if ( $error ) : ?>
							<p class="error" role="alert"><?php echo esc_html( $error ); ?></p>
						<?php endif; ?>
						<button type="submit"><?php esc_html_e( 'Continue to my dashboard', 'helpdesk-hero-hub' ); ?></button>
					</form>
				</li>
				<li>
					<strong><?php esc_html_e( 'Click Connect', 'helpdesk-hero-hub' ); ?></strong><br>
					<span class="muted"><?php esc_html_e( 'You may need to log in first. The connection code is filled in for you.', 'helpdesk-hero-hub' ); ?></span>
				</li>
			</ol>
			<details>
				<summary><?php esc_html_e( 'Prefer to paste a code?', 'helpdesk-hero-hub' ); ?></summary>
				<p class="muted" style="margin-top:8px"><?php esc_html_e( 'In your dashboard, open Get Help and paste this code:', 'helpdesk-hero-hub' ); ?></p>
				<textarea rows="4" readonly onclick="this.select()"><?php echo esc_textarea( Helpdesk_Hero_Hub::make_code( $token ) ); ?></textarea>
			</details>
		<?php endif; ?>
	</div>
</main>
</body>
</html>
		<?php
	}
}
