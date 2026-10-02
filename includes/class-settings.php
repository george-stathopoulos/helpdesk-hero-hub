<?php
/**
 * Hub settings storage.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everyday settings, and secrets (help desk keys) that are never autoloaded or sent to the browser.
 */
final class Helpdesk_Hero_Hub_Settings {

	const OPTION  = 'helpdesk_hero_hub_settings';
	const SECRETS = 'helpdesk_hero_hub_secrets';

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'team_name'         => '',
			'notify_email'      => '',
			'helpdesk'          => 'none',
			'helpscout_app_id'  => '',
			'helpscout_mailbox' => '',
			'zendesk_subdomain' => '',
			'zendesk_email'     => '',
		);
	}

	/**
	 * All settings.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Save settings (unknown keys dropped).
	 *
	 * @param array $values Values.
	 */
	public static function update( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), array_intersect_key( $values, self::defaults() ) ) );
	}

	/**
	 * A secret.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function secret( $key ) {
		$stored = get_option( self::SECRETS, array() );
		return is_array( $stored ) && isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';
	}

	/**
	 * Save a secret ('' removes it).
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 */
	public static function set_secret( $key, $value ) {
		$stored = get_option( self::SECRETS, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( '' === (string) $value ) {
			unset( $stored[ $key ] );
		} else {
			$stored[ $key ] = (string) $value;
		}
		update_option( self::SECRETS, $stored, false );
	}

	/**
	 * Team name shown to customers.
	 *
	 * @return string
	 */
	public static function team_name() {
		$name = (string) self::get( 'team_name' );
		return '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ' ' . __( 'Support', 'helpdesk-hero-hub' );
	}

	/**
	 * Team email for notifications.
	 *
	 * @return string
	 */
	public static function notify_email() {
		$email = (string) self::get( 'notify_email' );
		return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
	}
}
