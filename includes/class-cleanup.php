<?php
/**
 * Deleting tickets, cleanup, and CSV export and import.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the hub's data only as long as it's needed.
 *
 * - Delete tickets one by one, selected, or all that match filters (date range, site,
 *   customer, priority, status…). Their history and files go too.
 * - Export tickets as CSV (one row per ticket, with the conversation and history as JSON) and
 *   import such a file again to bring them back.
 * - Cleanup: tickets closed longer than a set period can be exported and deleted by hand, or
 *   automatically every day. Automatic cleanup saves a CSV of what it removed first; those
 *   files are kept for 90 days in the protected files folder.
 *
 * Customers keep their own copy of their tickets on their site. A ticket deleted here shows
 * there as closed, with a note.
 */
final class Helpdesk_Hero_Hub_Cleanup {

	const OPTION = 'helpdesk_hero_hub_cleanup';
	const CRON   = 'helpdesk_hero_hub_cleanup';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Daily automatic cleanup while it's on.
	 */
	public static function schedule() {
		$on = self::settings()['auto'];
		if ( $on && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		} elseif ( ! $on && wp_next_scheduled( self::CRON ) ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/**
	 * Settings.
	 *
	 * @return array { auto: bool, months: int }
	 */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'auto'   => ! empty( $s['auto'] ),
			'months' => max( 1, min( 120, (int) ( $s['months'] ?? 12 ) ) ),
		);
	}

	/**
	 * Save settings.
	 *
	 * @param array $in Values.
	 * @return array
	 */
	public static function save( array $in ) {
		update_option(
			self::OPTION,
			array(
				'auto'   => ! empty( $in['auto'] ),
				'months' => max( 1, min( 120, (int) ( $in['months'] ?? 12 ) ) ),
			),
			false
		);
		self::schedule();
		return self::settings();
	}

	/* ---------------------------------------------------------------------------------------- *
	 * Deleting.
	 * ---------------------------------------------------------------------------------------- */

	/**
	 * Delete a ticket with its history, files, and the tickets merged into it.
	 *
	 * @param int $id Ticket.
	 * @return bool Deleted.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$ticket = Helpdesk_Hero_Hub::ticket( (int) $id );
		if ( ! $ticket ) {
			return false;
		}
		foreach ( Helpdesk_Hero_Hub::merged_from( (int) $id ) as $merged ) {
			self::delete( (int) $merged['id'] );
		}
		/**
		 * Fires before a ticket is deleted (Pro removes its unbilled time).
		 *
		 * @param array $ticket Ticket.
		 */
		do_action( 'helpdesk_hero_hub_ticket_deleted', $ticket );
		Helpdesk_Hero_Hub_Attachments::delete_for_tickets( array( (int) $id ) );
		$wpdb->delete( Helpdesk_Hero_Hub_DB::table( 'outbox' ), array( 'hub_ticket_id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Helpdesk_Hero_Hub_DB::table( 'tickets' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// The customer's copy closes, with a note (unless it was already closed).
		if ( (int) $ticket['site_id'] && 'closed' !== $ticket['status'] ) {
			Helpdesk_Hero_Hub::enqueue( (int) $ticket['site_id'], (int) $id, 'removed', array() );
		}
		return true;
	}

	/**
	 * Tickets matching filters (inbox filters plus a date range).
	 *
	 * @param array $f Inbox filters (see Helpdesk_Hero_Hub::tickets()), plus closed_before (Y-m-d H:i:s).
	 * @param int   $limit Limit (0 = count only).
	 * @return int[]|int IDs, or the count.
	 */
	public static function match( array $f, $limit = 500 ) {
		// The same filters as the Inbox (status '' = every status).
		$f['status'] = $f['status'] ?? '';
		if ( ! $limit ) {
			return Helpdesk_Hero_Hub::count_tickets( $f );
		}
		$rows = Helpdesk_Hero_Hub::tickets(
			array_merge(
				$f,
				array(
					'orderby' => 'created',
					'order'   => 'asc',
					'limit'   => (int) $limit,
				)
			)
		);
		return array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
	}

	/* ---------------------------------------------------------------------------------------- *
	 * CSV.
	 * ---------------------------------------------------------------------------------------- */

	/**
	 * CSV columns.
	 *
	 * @return string[]
	 */
	public static function columns() {
		return array( 'id', 'site_id', 'site_url', 'site_name', 'client_ticket_id', 'subject', 'status', 'custom_status', 'service', 'priority', 'category', 'customer_name', 'customer_email', 'description', 'tags', 'rating', 'rating_comment', 'fields', 'flags', 'diagnostics', 'channel', 'assignee_id', 'first_response_at', 'closed_at', 'created_at', 'updated_at', 'history' );
	}

	/**
	 * Write tickets as CSV.
	 *
	 * @param int[]    $ids    Tickets.
	 * @param resource $handle Open file handle.
	 * @return int Rows written.
	 */
	public static function write_csv( array $ids, $handle ) {
		global $wpdb;
		fputcsv( $handle, self::columns(), ',', '"', '\\' );
		$count = 0;
		$sites = array();
		foreach ( $ids as $id ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Helpdesk_Hero_Hub_DB::table( 'tickets' ), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! $row ) {
				continue;
			}
			$sid = (int) $row['site_id'];
			if ( ! isset( $sites[ $sid ] ) ) {
				$sites[ $sid ] = Helpdesk_Hero_Hub::site( $sid );
			}
			$history = array();
			foreach ( Helpdesk_Hero_Hub::history( (int) $id ) as $h ) {
				$history[] = array(
					'type'       => $h['type'],
					'payload'    => $h['payload'],
					'created_by' => (int) $h['created_by'],
					'created_at' => $h['created_at'],
				);
			}
			$out = array();
			foreach ( self::columns() as $col ) {
				switch ( $col ) {
					case 'site_url':
						$out[] = $sites[ $sid ] ? $sites[ $sid ]['url'] : '';
						break;
					case 'site_name':
						$out[] = $sites[ $sid ] ? ( $sites[ $sid ]['name'] ? $sites[ $sid ]['name'] : $sites[ $sid ]['label'] ) : '';
						break;
					case 'history':
						$out[] = (string) wp_json_encode( $history );
						break;
					default:
						$out[] = self::safe_cell( (string) ( $row[ $col ] ?? '' ) );
				}
			}
			fputcsv( $handle, $out, ',', '"', '\\' );
			++$count;
		}
		return $count;
	}

	/**
	 * Stop spreadsheet programs from running text as a formula.
	 *
	 * @param string $value Cell.
	 * @return string
	 */
	private static function safe_cell( $value ) {
		return preg_match( '/^[=+\-@\t\r]/', $value ) && ! is_numeric( $value ) ? "'" . $value : $value;
	}

	/**
	 * Undo safe_cell().
	 *
	 * @param string $value Cell.
	 * @return string
	 */
	private static function unsafe_cell( $value ) {
		return preg_match( '/^\'[=+\-@\t\r]/', $value ) ? substr( $value, 1 ) : $value;
	}

	/**
	 * Import tickets from a CSV made by write_csv(). Tickets whose number already exists are
	 * skipped. Imported history is for reading only: it's never sent to customer sites again.
	 *
	 * @param string $path CSV file.
	 * @return array|WP_Error { imported, skipped }
	 */
	public static function import_csv( $path ) {
		global $wpdb;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading an uploaded file.
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'helpdesk_hero_hub_csv', __( 'The file could not be read.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		$head = fgetcsv( $handle, 0, ',', '"', '\\' );
		if ( ! $head || array_slice( $head, 0, 3 ) !== array( 'id', 'site_id', 'site_url' ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'helpdesk_hero_hub_csv', __( 'This isn’t a ticket export from Helpdesk Hero Hub.', 'helpdesk-hero-hub' ), array( 'status' => 400 ) );
		}
		$table    = Helpdesk_Hero_Hub_DB::table( 'tickets' );
		$allowed  = array_diff( self::columns(), array( 'site_url', 'site_name', 'history' ) );
		$imported = 0;
		$skipped  = 0;
		// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( ( $cells = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
			if ( count( $cells ) !== count( $head ) ) {
				++$skipped;
				continue;
			}
			$row = array_combine( $head, array_map( array( __CLASS__, 'unsafe_cell' ), $cells ) );
			$id  = (int) $row['id'];
			if ( ! $id || '' === trim( (string) $row['subject'] ) || Helpdesk_Hero_Hub::ticket( $id ) ) {
				++$skipped;
				continue;
			}
			$data = array();
			foreach ( $allowed as $col ) {
				if ( array_key_exists( $col, $row ) ) {
					$data[ $col ] = '' === $row[ $col ] && in_array( $col, array( 'first_response_at', 'closed_at', 'fields', 'diagnostics', 'flags', 'tags', 'rating_comment' ), true ) ? null : $row[ $col ];
				}
			}
			$data['subject']        = sanitize_text_field( (string) $data['subject'] );
			$data['description']    = sanitize_textarea_field( (string) $data['description'] );
			$data['customer_name']  = sanitize_text_field( (string) $data['customer_name'] );
			$data['customer_email'] = sanitize_email( (string) $data['customer_email'] );
			$data['status']         = in_array( $data['status'], array( 'open', 'pending', 'closed' ), true ) ? $data['status'] : 'closed';
			$data['priority']       = in_array( $data['priority'], array( 'low', 'normal', 'high', 'urgent' ), true ) ? $data['priority'] : 'normal';
			foreach ( array( 'tags', 'fields', 'flags', 'diagnostics' ) as $json ) {
				if ( null !== $data[ $json ] && ! is_array( json_decode( (string) $data[ $json ], true ) ) ) {
					$data[ $json ] = null;
				}
			}
			// Keep the site only if it's still connected to this hub.
			$site            = (int) $data['site_id'] ? Helpdesk_Hero_Hub::site( (int) $data['site_id'] ) : null;
			$data['site_id'] = $site ? (int) $site['id'] : 0;
			$data['id']      = $id;
			if ( false === $wpdb->insert( $table, $data ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				++$skipped;
				continue;
			}
			foreach ( (array) json_decode( (string) ( $row['history'] ?? '' ), true ) as $h ) {
				if ( ! is_array( $h ) || empty( $h['type'] ) ) {
					continue;
				}
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					Helpdesk_Hero_Hub_DB::table( 'outbox' ),
					array(
						// Site 0: history is shown on the ticket but never delivered to a site again.
						'site_id'       => 0,
						'hub_ticket_id' => $id,
						'type'          => sanitize_key( (string) $h['type'] ),
						'payload'       => wp_json_encode( (array) ( $h['payload'] ?? array() ) ),
						'created_by'    => (int) ( $h['created_by'] ?? 0 ),
						'created_at'    => preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ( $h['created_at'] ?? '' ) ) ? $h['created_at'] : Helpdesk_Hero_Hub_DB::now(),
					)
				);
			}
			++$imported;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
		);
	}

	/* ---------------------------------------------------------------------------------------- *
	 * Automatic cleanup.
	 * ---------------------------------------------------------------------------------------- */

	/**
	 * The date before which closed tickets are cleaned up.
	 *
	 * @param int $months Months.
	 * @return string UTC MySQL date.
	 */
	public static function cutoff( $months ) {
		return gmdate( 'Y-m-d H:i:s', strtotime( '-' . (int) $months . ' months' ) );
	}

	/**
	 * Daily: export closed tickets older than the period to a CSV file, then delete them.
	 * Also removes cleanup files older than 90 days.
	 *
	 * @return int Tickets deleted.
	 */
	public static function run() {
		$s = self::settings();
		if ( ! $s['auto'] ) {
			return 0;
		}
		$dir = Helpdesk_Hero_Hub_Attachments::dir();
		if ( is_wp_error( $dir ) ) {
			return 0;
		}
		$folder = $dir . 'cleanup/';
		wp_mkdir_p( $folder );
		foreach ( (array) glob( $folder . '*.csv' ) as $old ) {
			if ( filemtime( $old ) < time() - 90 * DAY_IN_SECONDS ) {
				wp_delete_file( $old );
			}
		}
		$ids = self::match( array( 'closed_before' => self::cutoff( $s['months'] ) ), 1000 );
		if ( ! $ids ) {
			return 0;
		}
		$file = $folder . 'cleanup-' . gmdate( 'Y-m-d-His' ) . '-' . strtolower( wp_generate_password( 8, false ) ) . '.csv';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- writing our own export.
		$handle = fopen( $file, 'w' );
		if ( ! $handle ) {
			return 0;
		}
		self::write_csv( $ids, $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( self::delete( $id ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	/**
	 * Files saved by automatic cleanup.
	 *
	 * @return array[] { name, size, time }
	 */
	public static function files() {
		$dir = Helpdesk_Hero_Hub_Attachments::dir();
		if ( is_wp_error( $dir ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) glob( $dir . 'cleanup/*.csv' ) as $file ) {
			$out[] = array(
				'name' => basename( $file ),
				'size' => (int) filesize( $file ),
				'time' => gmdate( 'c', (int) filemtime( $file ) ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $b['time'], $a['time'] );
			}
		);
		return $out;
	}

	/**
	 * Send a CSV to the browser and stop.
	 *
	 * @param string   $name   File name.
	 * @param int[]    $ids    Tickets (when exporting now).
	 * @param string   $path   Existing file (when downloading a saved export).
	 */
	public static function send_csv( $name, array $ids = array(), $path = '' ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', "\r", "\n" ), '', $name ) . '"' );
		if ( '' !== $path ) {
			readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming our own export.
		} else {
			$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			self::write_csv( $ids, $out );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		exit;
	}
}
