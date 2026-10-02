<?php
/**
 * Uninstall: remove the hub's tables and options. Connected sites stop working with this hub.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'sites', 'tickets', 'outbox' ) as $helpdesk_hero_hub_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'helpdesk_hero_hub_' . $helpdesk_hero_hub_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
foreach ( array( 'helpdesk_hero_hub_settings', 'helpdesk_hero_hub_secrets', 'helpdesk_hero_hub_policy', 'helpdesk_hero_hub_db', 'helpdesk_hero_hub_templates', 'helpdesk_hero_hub_tags', 'helpdesk_hero_hub_onboarding', 'helpdesk_hero_hub_installed' ) as $helpdesk_hero_hub_option ) {
	delete_option( $helpdesk_hero_hub_option );
}
delete_metadata( 'user', 0, 'helpdesk_hero_hub_feedback', '', true );
wp_clear_scheduled_hook( 'helpdesk_hero_hub_sync' );
