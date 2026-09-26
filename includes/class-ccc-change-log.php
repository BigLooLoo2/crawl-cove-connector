<?php
/**
 * Change log: every applied fix is recorded with its previous value so any
 * change can be reverted — from the desktop app or the admin page.
 *
 * Stored as a single capped option: this plugin is a low-volume conduit for
 * reviewed fixes, not a bulk editor, so a custom table would be overkill.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applied-change log, capped, with revert support.
 */
class CCC_Change_Log {

	const OPTION      = 'ccc_change_log';
	const SEQ_OPTION  = 'ccc_change_seq';
	const MAX_ENTRIES = 200;

	/**
	 * All logged changes, newest first.
	 *
	 * @return array[]
	 */
	public static function all() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Find one logged change by id.
	 *
	 * @param int $change_id Change id.
	 * @return array|null
	 */
	public static function find( $change_id ) {
		foreach ( self::all() as $entry ) {
			if ( (int) $entry['id'] === (int) $change_id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Record one applied change and return its entry.
	 *
	 * @param int    $post_id Post the change was applied to.
	 * @param string $field   'title' or 'description'.
	 * @param string $from    Previous stored value ('' = was unset).
	 * @param string $to      New stored value ('' = override removed).
	 * @param string $source  Who applied it (REST user login).
	 * @return array The stored entry, including its id.
	 */
	public static function record( $post_id, $field, $from, $to, $source ) {
		$id = (int) get_option( self::SEQ_OPTION, 0 ) + 1;
		update_option( self::SEQ_OPTION, $id, false );

		$entry = array(
			'id'       => $id,
			'post_id'  => (int) $post_id,
			'field'    => $field,
			'from'     => $from,
			'to'       => $to,
			'source'   => $source,
			'time'     => time(),
			'reverted' => false,
		);

		$log = self::all();
		array_unshift( $log, $entry );
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, 0, self::MAX_ENTRIES );
		}
		update_option( self::OPTION, $log, false );

		return $entry;
	}

	/**
	 * Revert one change: write its previous value back through the adapter.
	 *
	 * @param int         $change_id Change id.
	 * @param CCC_Adapter $adapter   Active SEO adapter to write the reverted value through.
	 * @return true|WP_Error
	 */
	public static function revert( $change_id, CCC_Adapter $adapter ) {
		$entry = self::find( $change_id );
		if ( ! $entry ) {
			return new WP_Error( 'ccc_not_found', __( 'No change with that id.', 'crawl-cove-connector' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $entry['reverted'] ) ) {
			return new WP_Error( 'ccc_already_reverted', __( 'That change has already been reverted.', 'crawl-cove-connector' ), array( 'status' => 409 ) );
		}
		// The homepage (post_id 0) always "exists" — it has no post to check.
		$target_id = (int) $entry['post_id'];
		if ( CCC_Service::HOME_ID !== $target_id ) {
			if ( $target_id < 0 ) {
				$term = get_term( -$target_id );
				if ( ! $term || is_wp_error( $term ) ) {
					return new WP_Error( 'ccc_term_gone', __( 'The term this change belongs to no longer exists.', 'crawl-cove-connector' ), array( 'status' => 410 ) );
				}
			} elseif ( ! get_post( $target_id ) ) {
				return new WP_Error( 'ccc_post_gone', __( 'The post this change belongs to no longer exists.', 'crawl-cove-connector' ), array( 'status' => 410 ) );
			}
		}

		if ( 'title' === $entry['field'] ) {
			$adapter->set_title( $entry['post_id'], $entry['from'] );
		} else {
			$adapter->set_description( $entry['post_id'], $entry['from'] );
		}
		// Same cache/indexable invalidation apply() runs after a real write —
		// without this a revert silently leaves stale cached HTML (or, for a
		// real post, a stale Yoast indexable) exactly like an un-invalidated
		// apply would. See CCC_Service::invalidate_caches_for() for why HOME_ID
		// and a negative term id can't go through wp_update_post().
		CCC_Service::invalidate_caches_for( (int) $entry['post_id'] );

		$log = self::all();
		foreach ( $log as $i => $e ) {
			if ( (int) $e['id'] === (int) $change_id ) {
				$log[ $i ]['reverted'] = true;
			}
		}
		update_option( self::OPTION, $log, false );

		return true;
	}
}
