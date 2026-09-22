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
	 * 'yoast', 'rankmath', 'seopress' or 'aioseo'.
	 *
	 * @var string
	 */
	public $id;

	/**
	 * Post meta key the active SEO plugin stores its title override under.
	 * For 'aioseo' this is a legacy compatibility mirror, not the
	 * authoritative value — see get_title()/set_title().
	 *
	 * @var string
	 */
	public $title_key;

	/**
	 * Post meta key the active SEO plugin stores its meta description under.
	 * For 'aioseo' this is a legacy compatibility mirror, not the
	 * authoritative value — see get_description()/set_description().
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
	 * @param string $id               'yoast', 'rankmath', 'seopress' or 'aioseo'.
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
	 * Detect the active SEO plugin. Yoast wins over Rank Math, which wins
	 * over SEOPress, which wins over AIOSEO, if more than one is somehow
	 * active — matching the order the crawler reports.
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
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return new self( 'seopress', '_seopress_titles_title', '_seopress_titles_desc', SEOPRESS_VERSION );
		}
		if ( function_exists( 'aioseo' ) && defined( 'AIOSEO_VERSION' ) ) {
			return new self( 'aioseo', '_aioseo_title', '_aioseo_description', AIOSEO_VERSION );
		}
		return null;
	}

	/**
	 * Human-readable name of the detected SEO plugin, for admin display.
	 *
	 * @return string
	 */
	public function label() {
		$labels = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'seopress' => 'SEOPress',
			'aioseo'   => 'All in One SEO',
		);
		return isset( $labels[ $this->id ] ) ? $labels[ $this->id ] : $this->id;
	}

	/**
	 * Currently stored SEO title override ('' = plugin default template).
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function get_title( $post_id ) {
		if ( 'aioseo' === $this->id ) {
			return (string) $this->aioseo_post( $post_id )->title;
		}
		return (string) get_post_meta( $post_id, $this->title_key, true );
	}

	/**
	 * Currently stored meta description ('' = none set).
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function get_description( $post_id ) {
		if ( 'aioseo' === $this->id ) {
			return (string) $this->aioseo_post( $post_id )->description;
		}
		return (string) get_post_meta( $post_id, $this->description_key, true );
	}

	/**
	 * Write a new title override.
	 *
	 * @param int    $post_id Post id.
	 * @param string $value   New title override; '' removes it.
	 */
	public function set_title( $post_id, $value ) {
		if ( 'aioseo' === $this->id ) {
			$this->aioseo_save( $post_id, 'title', $value );
			return;
		}
		$this->set_meta( $post_id, $this->title_key, $value );
	}

	/**
	 * Write a new meta description.
	 *
	 * @param int    $post_id Post id.
	 * @param string $value   New meta description; '' removes it.
	 */
	public function set_description( $post_id, $value ) {
		if ( 'aioseo' === $this->id ) {
			$this->aioseo_save( $post_id, 'description', $value );
			return;
		}
		$this->set_meta( $post_id, $this->description_key, $value );
	}

	/**
	 * An empty string means "remove the override, fall back to the SEO
	 * plugin's template" — Yoast, Rank Math and SEOPress all treat absent
	 * meta that way.
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

	/**
	 * AIOSEO stores title/description in a custom `wp_aioseo_posts` table
	 * (columns, not postmeta), via its own Model class rather than core WP
	 * functions — confirmed against AIOSEO 4.9 source, `Models\Post` is a
	 * public, patch-style API (`@since 4.0.3`, not `@internal` like the
	 * REST-controller wrapper around it): `getPost()`/`savePost()` only
	 * touch the columns you pass, filling in every other column's default
	 * when the row doesn't exist yet, and leave everything else (noindex
	 * flags, schema, keywords — 38+ other columns) untouched either way.
	 *
	 * @param int $post_id Post id.
	 * @return \AIOSEO\Plugin\Common\Models\Post
	 */
	private function aioseo_post( $post_id ) {
		return \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
	}

	/**
	 * Patch one AIOSEO field ('title' or 'description'). An empty string is
	 * passed straight through, not specially handled: AIOSEO's own title/
	 * description generator uses PHP's empty() on the stored value, which
	 * is true for both '' and null, so an empty string already falls back
	 * to the plugin's default template exactly like the postmeta adapters'
	 * delete-on-empty behaviour (verified against `Meta\Title::getTitle()`
	 * in AIOSEO 4.9 source).
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   'title' or 'description'.
	 * @param string $value   New value.
	 */
	private function aioseo_save( $post_id, $field, $value ) {
		\AIOSEO\Plugin\Common\Models\Post::savePost( $post_id, array( $field => $value ) );
	}
}
