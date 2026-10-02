<?php
/**
 * Plugin Name:       Helpdesk Hero Hub
 * Plugin URI:        https://wordpress.org/plugins/helpdesk-hero-hub/
 * Description:       Support hub for agencies and plugin vendors: connect your customers' WordPress sites, receive their tickets with diagnostics in Help Scout or Zendesk, set the support policy, and log in to their sites with one-time links.
 * Version:           2.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            George Stathopoulos
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       helpdesk-hero-hub
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

define( 'HELPDESK_HERO_HUB_VERSION', '2.0.0' );
define( 'HELPDESK_HERO_HUB_FILE', __FILE__ );
define( 'HELPDESK_HERO_HUB_DIR', plugin_dir_path( __FILE__ ) );
define( 'HELPDESK_HERO_HUB_URL', plugin_dir_url( __FILE__ ) );

require_once HELPDESK_HERO_HUB_DIR . 'includes/class-db.php';
require_once HELPDESK_HERO_HUB_DIR . 'includes/class-settings.php';
require_once HELPDESK_HERO_HUB_DIR . 'includes/class-signer.php';
require_once HELPDESK_HERO_HUB_DIR . 'includes/class-redactor.php';
require_once HELPDESK_HERO_HUB_DIR . 'includes/class-format.php';
require_once HELPDESK_HERO_HUB_DIR . 'includes/class-ai.php';
require_once HELPDESK_HERO_HUB_DIR . 'includes/class-policy.php';
require_once HELPDESK_HERO_HUB_DIR . 'includes/class-templates.php';
require_once HELPDESK_HERO_HUB_DIR . 'includes/class-tags.php';
require_once HELPDESK_HERO_HUB_DIR . 'includes/class-stats.php';
require_once HELPDESK_HERO_HUB_DIR . 'includes/class-helpdesk.php';
require_once HELPDESK_HERO_HUB_DIR . 'includes/class-hub.php';
require_once HELPDESK_HERO_HUB_DIR . 'includes/class-site-api.php';
require_once HELPDESK_HERO_HUB_DIR . 'includes/class-admin-rest.php';
require_once HELPDESK_HERO_HUB_DIR . 'includes/class-admin.php';
require_once HELPDESK_HERO_HUB_DIR . 'includes/class-privacy.php';
require_once HELPDESK_HERO_HUB_DIR . 'includes/class-backup.php';
require_once HELPDESK_HERO_HUB_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Helpdesk_Hero_Hub_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Helpdesk_Hero_Hub_Plugin', 'deactivate' ) );

Helpdesk_Hero_Hub_Plugin::instance();
