<?php
/**
 * Ticket statistics.
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Overview numbers for the hub dashboard: tickets over time, categories, recurring issues and
 * response times, across all sites for the last 30 days. Helpdesk Hero Pro builds its advanced
 * analytics (any range, per site, tags, ratings, exports) on the helpers here.
 */
final class Helpdesk_Hero_Hub_Stats {

	/**
	 * Tickets created in the last N days (optionally for one site), with the columns stats need.
	 *
	 * @param int $days    Days.
	 * @param int $site_id Site (0 = all).
	 * @return array[]
	 */
	public static function rows( $days, $site_id = 0 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d 00:00:00', time() - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS );
		if ( $site_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, site_id, status, priority, category, channel, flags, tags, rating, created_at, first_response_at, closed_at FROM %i WHERE created_at >= %s AND site_id = %d ORDER BY id DESC LIMIT 20000', Helpdesk_Hero_Hub_DB::table( 'tickets' ), $since, $site_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, site_id, status, priority, category, channel, flags, tags, rating, created_at, first_response_at, closed_at FROM %i WHERE created_at >= %s ORDER BY id DESC LIMIT 20000', Helpdesk_Hero_Hub_DB::table( 'tickets' ), $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		foreach ( $rows as &$row ) {
			$row['flags'] = $row['flags'] ? (array) json_decode( $row['flags'], true ) : array();
			$row['tags']  = $row['tags'] ? (array) json_decode( $row['tags'], true ) : array();
		}
		return $rows;
	}

	/**
	 * Days in the range, oldest first (YYYY-MM-DD, UTC).
	 *
	 * @param int $days Days.
	 * @return string[]
	 */
	public static function dates( $days ) {
		$out = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$out[] = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
		}
		return $out;
	}

	/**
	 * Tickets opened and closed per day.
	 *
	 * @param array[] $rows Rows.
	 * @param int     $days Days.
	 * @return array { dates, opened, closed }
	 */
	public static function series( array $rows, $days ) {
		$dates  = self::dates( $days );
		$index  = array_flip( $dates );
		$opened = array_fill( 0, count( $dates ), 0 );
		$closed = array_fill( 0, count( $dates ), 0 );
		foreach ( $rows as $r ) {
			$d = substr( $r['created_at'], 0, 10 );
			if ( isset( $index[ $d ] ) ) {
				++$opened[ $index[ $d ] ];
			}
			$c = $r['closed_at'] ? substr( $r['closed_at'], 0, 10 ) : '';
			if ( isset( $index[ $c ] ) ) {
				++$closed[ $index[ $c ] ];
			}
		}
		return compact( 'dates', 'opened', 'closed' );
	}

	/**
	 * Count rows by a key.
	 *
	 * @param array[]  $rows  Rows.
	 * @param callable $key   Returns the group label(s) for a row (string or string[]).
	 * @param int      $limit Max groups (rest is summed as "Other").
	 * @return array[] { label, value }, largest first.
	 */
	public static function count_by( array $rows, callable $key, $limit = 8 ) {
		$counts = array();
		foreach ( $rows as $r ) {
			foreach ( (array) call_user_func( $key, $r ) as $label ) {
				if ( '' === (string) $label ) {
					continue;
				}
				$counts[ $label ] = ( $counts[ $label ] ?? 0 ) + 1;
			}
		}
		arsort( $counts );
		$out   = array();
		$other = 0;
		foreach ( $counts as $label => $n ) {
			if ( count( $out ) < $limit ) {
				$out[] = array(
					'label' => (string) $label,
					'value' => $n,
				);
			} else {
				$other += $n;
			}
		}
		if ( $other ) {
			$out[] = array(
				'label' => __( 'Other', 'helpdesk-hero-hub' ),
				'value' => $other,
			);
		}
		return $out;
	}

	/**
	 * Recurring issues: health flags that appear on the most tickets.
	 *
	 * @param array[] $rows  Rows.
	 * @param int     $limit Limit.
	 * @return array[] { label, value, level }
	 */
	public static function recurring_issues( array $rows, $limit = 6 ) {
		$counts = array();
		$levels = array();
		foreach ( $rows as $r ) {
			$seen = array();
			foreach ( $r['flags'] as $flag ) {
				// "3 errors from WooCommerce" and "1 error from WooCommerce" are the same issue.
				$label = trim( preg_replace( '/^\d+\s+/', '', (string) ( $flag['title'] ?? '' ) ) );
				$label = '' !== $label ? ucfirst( $label ) : '';
				if ( '' === $label || isset( $seen[ $label ] ) || 'info' === ( $flag['level'] ?? '' ) ) {
					continue;
				}
				$seen[ $label ]   = true;
				$counts[ $label ] = ( $counts[ $label ] ?? 0 ) + 1;
				$levels[ $label ] = ( $flag['level'] ?? 'warning' );
			}
		}
		arsort( $counts );
		$out = array();
		foreach ( array_slice( $counts, 0, $limit, true ) as $label => $n ) {
			$out[] = array(
				'label' => $label,
				'value' => $n,
				'level' => $levels[ $label ],
			);
		}
		return $out;
	}

	/**
	 * Median hours between two timestamps over rows that have both.
	 *
	 * @param array[] $rows Rows.
	 * @param string  $to   Column: first_response_at | closed_at.
	 * @return float|null
	 */
	public static function median_hours( array $rows, $to ) {
		$values = array();
		foreach ( $rows as $r ) {
			if ( ! empty( $r[ $to ] ) ) {
				$values[] = max( 0, ( strtotime( $r[ $to ] . ' UTC' ) - strtotime( $r['created_at'] . ' UTC' ) ) / HOUR_IN_SECONDS );
			}
		}
		if ( ! $values ) {
			return null;
		}
		sort( $values );
		$mid = (int) floor( count( $values ) / 2 );
		$med = count( $values ) % 2 ? $values[ $mid ] : ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
		return round( $med, 1 );
	}

	/**
	 * Overview for the dashboard.
	 *
	 * @param int $days Days.
	 * @return array
	 */
	public static function overview( $days = 30 ) {
		global $wpdb;
		$rows  = self::rows( $days );
		$table = Helpdesk_Hero_Hub_DB::table( 'tickets' );
		$now   = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status = 'open') AS open, SUM(status = 'pending') AS pending, SUM(status <> 'closed' AND access_expires > %s) AS access FROM %i", Helpdesk_Hero_Hub_DB::now(), $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sites = 0;
		foreach ( Helpdesk_Hero_Hub::sites() as $s ) {
			$sites += 'active' === $s['status'] ? 1 : 0;
		}
		return array(
			'days'       => $days,
			'now'        => array(
				'open'    => (int) ( $now['open'] ?? 0 ),
				'pending' => (int) ( $now['pending'] ?? 0 ),
				'access'  => (int) ( $now['access'] ?? 0 ),
				'sites'   => $sites,
			),
			'totals'     => array(
				'tickets'        => count( $rows ),
				'closed'         => count(
					array_filter(
						$rows,
						static function ( $r ) {
							return 'closed' === $r['status'];
						}
					)
				),
				'first_response' => self::median_hours( $rows, 'first_response_at' ),
				'resolution'     => self::median_hours( $rows, 'closed_at' ),
			),
			'series'     => self::series( $rows, $days ),
			'categories' => self::count_by(
				$rows,
				static function ( $r ) {
					return '' !== $r['category'] ? $r['category'] : __( 'No category', 'helpdesk-hero-hub' );
				},
				6
			),
			'issues'     => self::recurring_issues( $rows, 6 ),
		);
	}
}
