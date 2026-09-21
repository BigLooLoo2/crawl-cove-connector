<?php
/**
 * SEO-plugin adapter: one object that knows which post-meta keys the active
 * SEO plugin uses for the title and meta description.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

class CCC_Adapter {

	/** @var string 'yoast' or 'rankmath' */
	public $id;

	/** @var string */
	public $title_key;

	/** @var string */
	public $description_key;

	/** @var string Version of the detected SEO plugin ('' if unknown). */
	public $plugin_version;

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

	/** @return string Currently stored SEO title override ('' = plugin default template). */
	public function get_title( $post_id ) {
		return (string) get_post_meta( $post_id, $this->title_key, true );
	}

	/** @return string Currently stored meta description ('' = none set). */
	public function get_description( $post_id ) {
		return (string) get_post_meta( $post_id, $this->description_key, true );
	}

	public function set_title( $post_id, $value ) {
		$this->set_meta( $post_id, $this->title_key, $value );
	}

	public function set_description( $post_id, $value ) {
		$this->set_meta( $post_id, $this->description_key, $value );
	}

	/**
	 * An empty string means "remove the override, fall back to the SEO
	 * plugin's template" — both Yoast and Rank Math treat absent meta that way.
	 */
	private function set_meta( $post_id, $key, $value ) {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}
}
