<?php
/**
 * Plain-text formatting of diagnostics received from sites.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a site's diagnostics bundle into the text file attached to help desk tickets.
 * Kept in step with Helpdesk_Hero_Diagnostics::to_text() in the customer plugin.
 */
final class Helpdesk_Hero_Hub_Format {

	/**
	 * Plain-text version of a bundle, for emails and attachments.
	 *
	 * @param array $data  Bundle.
	 * @param array $flags Health flags.
	 * @return string
	 */
	public static function diagnostics( array $data, array $flags = array() ) {
		$out   = array();
		$out[] = 'SITE DIAGNOSTICS — ' . ( $data['site']['name'] ?? '' );
		$out[] = ( $data['site']['url'] ?? '' ) . ' · generated ' . ( $data['generated_at'] ?? '' ) . ' by ' . ( $data['generator'] ?? '' );
		$out[] = '';

		if ( $flags ) {
			$out[] = '== Health flags ==';
			foreach ( $flags as $flag ) {
				$out[] = '[' . strtoupper( $flag['level'] ) . '] ' . $flag['title'] . ( ! empty( $flag['detail'] ) ? ' — ' . $flag['detail'] : '' );
			}
			$out[] = '';
		}

		if ( ! empty( $data['environment'] ) ) {
			$out[] = '== Environment ==';
			foreach ( $data['environment'] as $key => $value ) {
				$out[] = str_pad( $key, 22 ) . ': ' . self::scalar( $value );
			}
			$out[] = '';
		}

		if ( ! empty( $data['extensions'] ) ) {
			$ext   = $data['extensions'];
			$out[] = '== Theme ==';
			$out[] = self::ext_line( $ext['theme'] ) . ( ! empty( $ext['parent_theme'] ) ? '  (child of ' . self::ext_line( $ext['parent_theme'] ) . ')' : '' );
			$out[] = '';
			$out[] = '== Active plugins (' . count( $ext['active_plugins'] ) . ') ==';
			foreach ( $ext['active_plugins'] as $p ) {
				$out[] = '- ' . self::ext_line( $p ) . ' [' . $p['file'] . ']';
			}
			if ( $ext['mu_plugins'] ) {
				$out[] = '';
				$out[] = '== Must-use plugins ==';
				foreach ( $ext['mu_plugins'] as $p ) {
					$out[] = '- ' . self::ext_line( $p );
				}
			}
			if ( $ext['dropins'] ) {
				$out[] = '';
				$out[] = '== Drop-ins == ' . implode( ', ', $ext['dropins'] );
			}
			if ( $ext['inactive_plugins'] ) {
				$out[] = '';
				$out[] = '== Inactive plugins (' . count( $ext['inactive_plugins'] ) . ') ==';
				foreach ( $ext['inactive_plugins'] as $p ) {
					$out[] = '- ' . self::ext_line( $p );
				}
			}
			$out[] = '';
		}

		if ( isset( $data['errors'] ) ) {
			$out[] = '== Recent errors (14 days) ==';
			if ( ! $data['errors'] ) {
				$out[] = 'None recorded.';
			}
			foreach ( $data['errors'] as $e ) {
				$out[] = $e['time'] . ' · ' . $e['kind'] . ' · ' . $e['component'] . "\n  " . $e['message'] . ( $e['file'] ? "\n  at " . $e['file'] : '' ) . ( $e['url'] ? "\n  on " . $e['url'] : '' );
			}
			$out[] = '';
		}

		if ( isset( $data['changes'] ) ) {
			$out[] = '== Recent changes (30 days) ==';
			if ( ! $data['changes'] ) {
				$out[] = 'None recorded.';
			}
			foreach ( $data['changes'] as $c ) {
				$out[] = $c['time'] . ' · ' . $c['event'] . ( $c['support'] ? ' (by support)' : '' );
			}
			$out[] = '';
		}

		if ( ! empty( $data['debug_log'] ) ) {
			$out[] = '== ' . $data['debug_log']['path'] . ' (' . $data['debug_log']['size'] . ', last lines) ==';
			$out   = array_merge( $out, $data['debug_log']['lines'] );
			$out[] = '';
		}

		foreach ( $data as $key => $value ) {
			if ( in_array( $key, array( 'generated_at', 'generator', 'site', 'sections', 'environment', 'extensions', 'errors', 'changes', 'debug_log' ), true ) ) {
				continue;
			}
			$out[] = '== ' . $key . ' ==';
			$out[] = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			$out[] = '';
		}

		return implode( "\n", $out );
	}

	/**
	 * Plugin or theme as "Name 1.2 (update 1.3 available)".
	 *
	 * @param array $row Row.
	 * @return string
	 */
	private static function ext_line( $row ) {
		return $row['name'] . ' ' . $row['version'] . ( ! empty( $row['update'] ) ? ' (update ' . $row['update'] . ' available)' : '' ) . ( ! empty( $row['network'] ) ? ' [network]' : '' );
	}

	/**
	 * Scalar for display.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function scalar( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( is_array( $value ) ) {
			return implode( ', ', $value );
		}
		return (string) $value;
	}
}
