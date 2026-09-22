<?php
/**
 * SEO-plugin adapter: one object that knows which post-meta keys the active
 * SEO plugin uses for the title and meta description.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * One SEO-plugin adapter instance: which post-meta keys to read/write.
 */
class CCC_Adapter {

	/**
	 * 'yoast' or 'rankmath'.
	 *
	 * @var string
	 */
	public $id;

	/**
	 * Post meta key the active SEO plugin stores its title override under.
	 *
	 * @var string
	 */
	public $title_key;

	/**
	 * Post meta key the active SEO plugin stores its meta description under.
	 *
	 * @var string
	 */
	public $description_key;

	/**
	 * Version of the detected SEO plugin ('' if unknown).
	 *
	 * @var string
	 */
	public $plugin_version;

	/**
	 * Build an adapter for one detected SEO plugin.
	 *
	 * @param string $id               'yoast' or 'rankmath'.
	 * @param string $title_key        Post meta key for the title override.
	 * @param string $description_key  Post meta key for the meta description.
	 * @param string $plugin_version   Detected SEO plugin version, '' if unknown.
	 */
	public function __construct( $id, $title_key, $description_key, $plugin_version = '' ) {
		$this->id              = $id;
		$this->title_key       = $title_key;
		$this->description_key = $description_key;
		$this->plugin_version  = $plugin_version;
	}

	/**
	 * Detect the active SEO plugin. Yoast wins if both are somehow active,
	 * matching the order the crawler reports.
	 *
	 * @return CCC_Adapter|null Null when no supported SEO plugin is active.
	 */
	public static function detect() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return new self( 'yoast', '_yoast_wpseo_title', '_yoast_wpseo_metadesc', WPSEO_VERSION );
		}
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			$ver = defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : '';
			return new self( 'rankmath', 'rank_math_title', 'rank_math_description', $ver );
		}
		return null;
	}

	/**
	 * Currently stored SEO title override ('' = plugin default template).
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function get_title( $post_id ) {
		return (string) get_post_meta( $post_id, $this->title_key, true );
	}

	/**
	 * Currently stored meta description ('' = none set).
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function get_description( $post_id ) {
		return (string) get_post_meta( $post_id, $this->description_key, true );
	}

	/**
	 * Write a new title override.
	 *
	 * @param int    $post_id Post id.
	 * @param string $value   New title override; '' removes it.
	 */
	public function set_title( $post_id, $value ) {
		$this->set_meta( $post_id, $this->title_key, $value );
	}

	/**
	 * Write a new meta description.
	 *
	 * @param int    $post_id Post id.
	 * @param string $value   New meta description; '' removes it.
	 */
	public function set_description( $post_id, $value ) {
		$this->set_meta( $post_id, $this->description_key, $value );
	}

	/**
	 * An empty string means "remove the override, fall back to the SEO
	 * plugin's template" — both Yoast and Rank Math treat absent meta that way.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Post meta key to write.
	 * @param string $value   New value; '' deletes the meta key instead.
	 */
	private function set_meta( $post_id, $key, $value ) {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}
}
