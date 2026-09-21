<?php
/**
 * Core service: URL resolution, validation and applying changes.
 * Kept free of WP_REST_* types so the logic is unit-testable.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

class CCC_Service {

	const MAX_TITLE_LEN       = 512;
	const MAX_DESCRIPTION_LEN = 1024;
	const MAX_BATCH           = 50;

	/**
	 * Resolve a URL on this site to a post id.
	 *
	 * @return int|WP_Error Post id (> 0) or an error explaining why not.
	 */
	public static function resolve_url( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return new WP_Error( 'ccc_bad_url', __( 'Empty URL.', 'crawl-cove-connector' ), array( 'status' => 400 ) );
		}

		$url_host  = wp_parse_url( $url, PHP_URL_HOST );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $url_host || strtolower( $url_host ) !== strtolower( (string) $site_host ) ) {
			return new WP_Error(
				'ccc_wrong_site',
				/* translators: %s: the hostname of this WordPress site */
				sprintf( __( 'URL is not on this site (%s) — check the site profile in Crawl Cove.', 'crawl-cove-connector' ), $site_host ),
				array( 'status' => 400 )
			);
		}

		$post_id = url_to_postid( $url );
		if ( ! $post_id ) {
			return new WP_Error(
				'ccc_unresolvable',
				__( 'URL does not map to a post or page. Archives, taxonomy and virtual pages are not supported yet.', 'crawl-cove-connector' ),
				array( 'status' => 404 )
			);
		}

		return $post_id;
	}

	/**
	 * Describe one post's current SEO values for the desktop app's diff view.
	 *
	 * @return array
	 */
	public static function describe( $post_id, CCC_Adapter $adapter ) {
		return array(
			'post_id'     => (int) $post_id,
			'post_title'  => get_the_title( $post_id ),
			'permalink'   => get_permalink( $post_id ),
			'editable'    => current_user_can( 'edit_post', $post_id ),
			'current'     => array(
				'title'       => $adapter->get_title( $post_id ),
				'description' => $adapter->get_description( $post_id ),
			),
		);
	}

	/**
	 * Validate one change payload item. Returns a normalised array or WP_Error.
	 *
	 * Accepted shape: { url? , post_id?, title?, description? } — at least one
	 * of url/post_id, at least one of title/description.
	 *
	 * @return array|WP_Error { post_id, fields: { title?: string, description?: string } }
	 */
	public static function validate_change( $item ) {
		if ( ! is_array( $item ) ) {
			return new WP_Error( 'ccc_bad_change', __( 'Each change must be an object.', 'crawl-cove-connector' ), array( 'status' => 400 ) );
		}

		if ( isset( $item['post_id'] ) && (int) $item['post_id'] > 0 ) {
			$post_id = (int) $item['post_id'];
			if ( ! get_post( $post_id ) ) {
				return new WP_Error( 'ccc_no_post', __( 'No post with that id.', 'crawl-cove-connector' ), array( 'status' => 404 ) );
			}
		} elseif ( isset( $item['url'] ) ) {
			$post_id = self::resolve_url( $item['url'] );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
		} else {
			return new WP_Error( 'ccc_no_target', __( 'A change needs a url or a post_id.', 'crawl-cove-connector' ), array( 'status' => 400 ) );
		}

		$fields = array();
		foreach ( array( 'title' => self::MAX_TITLE_LEN, 'description' => self::MAX_DESCRIPTION_LEN ) as $field => $max ) {
			if ( ! array_key_exists( $field, $item ) ) {
				continue;
			}
			if ( ! is_string( $item[ $field ] ) ) {
				return new WP_Error( 'ccc_bad_value', sprintf( __( '%s must be a string.', 'crawl-cove-connector' ), $field ), array( 'status' => 400 ) );
			}
			$value = sanitize_text_field( $item[ $field ] );
			if ( strlen( $value ) > $max ) {
				return new WP_Error(
					'ccc_too_long',
					sprintf( __( '%1$s is longer than %2$d characters.', 'crawl-cove-connector' ), $field, $max ),
					array( 'status' => 400 )
				);
			}
			$fields[ $field ] = $value;
		}

		if ( ! $fields ) {
			return new WP_Error( 'ccc_nothing_to_do', __( 'A change needs a title and/or a description.', 'crawl-cove-connector' ), array( 'status' => 400 ) );
		}

		return array(
			'post_id' => $post_id,
			'fields'  => $fields,
		);
	}

	/**
	 * Apply a batch of changes. Per-item results; one bad item never blocks
	 * the rest. Dry-run validates and diffs without writing anything.
	 *
	 * @param array       $changes Raw items from the request body.
	 * @param bool        $dry_run
	 * @param CCC_Adapter $adapter
	 * @param string      $source  Actor recorded in the change log.
	 * @return array|WP_Error Per-item results, or WP_Error for a bad batch.
	 */
	public static function apply( $changes, $dry_run, CCC_Adapter $adapter, $source ) {
		if ( ! is_array( $changes ) || ! $changes ) {
			return new WP_Error( 'ccc_empty_batch', __( 'changes must be a non-empty array.', 'crawl-cove-connector' ), array( 'status' => 400 ) );
		}
		if ( count( $changes ) > self::MAX_BATCH ) {
			return new WP_Error(
				'ccc_batch_too_big',
				sprintf( __( 'At most %d changes per request.', 'crawl-cove-connector' ), self::MAX_BATCH ),
				array( 'status' => 400 )
			);
		}

		$results        = array();
		$touched_posts  = array();

		foreach ( array_values( $changes ) as $i => $item ) {
			$valid = self::validate_change( $item );
			if ( is_wp_error( $valid ) ) {
				$results[] = array(
					'index' => $i,
					'ok'    => false,
					'error' => $valid->get_error_code(),
					'message' => $valid->get_error_message(),
				);
				continue;
			}

			$post_id = $valid['post_id'];
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$results[] = array(
					'index' => $i,
					'ok'    => false,
					'error' => 'ccc_forbidden',
					'message' => __( 'This user may not edit that post.', 'crawl-cove-connector' ),
				);
				continue;
			}

			$applied = array();
			foreach ( $valid['fields'] as $field => $to ) {
				$from = ( 'title' === $field ) ? $adapter->get_title( $post_id ) : $adapter->get_description( $post_id );
				$step = array(
					'from'    => $from,
					'to'      => $to,
					'changed' => ( $from !== $to ),
				);
				if ( ! $dry_run && $from !== $to ) {
					if ( 'title' === $field ) {
						$adapter->set_title( $post_id, $to );
					} else {
						$adapter->set_description( $post_id, $to );
					}
					$entry = CCC_Change_Log::record( $post_id, $field, $from, $to, $source );
					$step['change_id'] = $entry['id'];
					$touched_posts[ $post_id ] = true;
				}
				$applied[ $field ] = $step;
			}

			$results[] = array(
				'index'   => $i,
				'ok'      => true,
				'post_id' => $post_id,
				'dry_run' => (bool) $dry_run,
				'applied' => $applied,
			);
		}

		// Yoast rebuilds its indexables on save_post, so re-save each touched
		// post; without this the new meta can sit unused until the next manual
		// edit. Filterable off for hosts that object to the modified-date bump.
		if ( $touched_posts && apply_filters( 'ccc_touch_post_after_apply', true ) ) {
			foreach ( array_keys( $touched_posts ) as $post_id ) {
				wp_update_post( array( 'ID' => $post_id ) );
			}
		}

		return $results;
	}
}
