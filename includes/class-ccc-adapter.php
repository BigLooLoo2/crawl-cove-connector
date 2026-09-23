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
	 * @param int $post_id Post id, or CCC_Service::HOME_ID for the homepage.
	 * @return string
	 */
	public function get_title( $post_id ) {
		if ( 0 === $post_id ) {
			return $this->get_home_field( 'title' );
		}
		if ( 'aioseo' === $this->id ) {
			return (string) $this->aioseo_post( $post_id )->title;
		}
		return (string) get_post_meta( $post_id, $this->title_key, true );
	}

	/**
	 * Currently stored meta description ('' = none set).
	 *
	 * @param int $post_id Post id, or CCC_Service::HOME_ID for the homepage.
	 * @return string
	 */
	public function get_description( $post_id ) {
		if ( 0 === $post_id ) {
			return $this->get_home_field( 'description' );
		}
		if ( 'aioseo' === $this->id ) {
			return (string) $this->aioseo_post( $post_id )->description;
		}
		return (string) get_post_meta( $post_id, $this->description_key, true );
	}

	/**
	 * Write a new title override.
	 *
	 * @param int    $post_id Post id, or CCC_Service::HOME_ID for the homepage.
	 * @param string $value   New title override; '' removes it.
	 */
	public function set_title( $post_id, $value ) {
		if ( 0 === $post_id ) {
			$this->set_home_field( 'title', $value );
			return;
		}
		if ( 'aioseo' === $this->id ) {
			$this->aioseo_save( $post_id, 'title', $value );
			return;
		}
		$this->set_meta( $post_id, $this->title_key, $value );
	}

	/**
	 * Write a new meta description.
	 *
	 * @param int    $post_id Post id, or CCC_Service::HOME_ID for the homepage.
	 * @param string $value   New meta description; '' removes it.
	 */
	public function set_description( $post_id, $value ) {
		if ( 0 === $post_id ) {
			$this->set_home_field( 'description', $value );
			return;
		}
		if ( 'aioseo' === $this->id ) {
			$this->aioseo_save( $post_id, 'description', $value );
			return;
		}
		$this->set_meta( $post_id, $this->description_key, $value );
	}

	/**
	 * Whether this adapter can read/write the homepage title/description at
	 * all. True only for Yoast and Rank Math — verified against real plugin
	 * source (23 Sept 2026): both store a "your latest posts" homepage's
	 * title/description in a plugin options array (not postmeta), read via a
	 * safe read-modify-write helper. SEOPress and AIOSEO's equivalents are
	 * unresearched; report unsupported rather than guess.
	 *
	 * @return bool
	 */
	public function supports_home() {
		return in_array( $this->id, array( 'yoast', 'rankmath' ), true );
	}

	/**
	 * Whether writing '' to the homepage title actually falls back to a
	 * sensible default, or leaves a genuinely blank <title>. Verified
	 * against real plugin source (23 Sept 2026):
	 * - Yoast: `Indexable_Home_Page_Presentation::generate_title()` falls
	 *   back to `Options_Helper::get_title_default()` whenever the stored
	 *   value is empty — '' is safe.
	 * - Rank Math: `Blog::title()` calls
	 *   `Paper::get_from_options( 'homepage_title' )` with no fallback
	 *   argument, so an empty stored value renders as a literally empty
	 *   <title> tag — no separate "default template" is re-applied at read
	 *   time (the template text you see in Rank Math's settings UI is only
	 *   ever a seeded initial value, not a live fallback). Rank Math's
	 *   homepage DESCRIPTION is unaffected: `Blog::description()` passes
	 *   `get_bloginfo( 'description' )` as its fallback, so clearing it is
	 *   safe.
	 *
	 * @return bool
	 */
	public function can_clear_home_title() {
		return 'rankmath' !== $this->id;
	}

	/**
	 * Read one homepage field ('title' or 'description') from the active
	 * SEO plugin's own storage. '' for an adapter without homepage support
	 * (caller must gate writes on supports_home() first).
	 *
	 * @param string $field 'title' or 'description'.
	 * @return string
	 */
	private function get_home_field( $field ) {
		if ( 'yoast' === $this->id ) {
			$key = ( 'title' === $field ) ? 'title-home-wpseo' : 'metadesc-home-wpseo';
			return (string) \WPSEO_Options::get( $key, '' );
		}
		if ( 'rankmath' === $this->id ) {
			$titles = (array) get_option( 'rank-math-options-titles', array() );
			$key    = ( 'title' === $field ) ? 'homepage_title' : 'homepage_description';
			return isset( $titles[ $key ] ) ? (string) $titles[ $key ] : '';
		}
		return '';
	}

	/**
	 * Write one homepage field through the active SEO plugin's own safe
	 * read-modify-write path — never a bare `update_option()` on the whole
	 * settings array, which would silently wipe every other setting it
	 * holds (title templates, social settings, etc.) alongside it.
	 *
	 * @param string $field 'title' or 'description'.
	 * @param string $value New value.
	 */
	private function set_home_field( $field, $value ) {
		if ( 'yoast' === $this->id ) {
			$key = ( 'title' === $field ) ? 'title-home-wpseo' : 'metadesc-home-wpseo';
			// WPSEO_Options::save_option() reads the full 'wpseo_titles'
			// option, patches this one key, writes the whole array back —
			// the safe pattern this option needs (confirmed at
			// inc/options/class-wpseo-options.php:516 in Yoast 28.6 source).
			\WPSEO_Options::save_option( 'wpseo_titles', $key, $value );
			return;
		}
		if ( 'rankmath' === $this->id ) {
			$key            = ( 'title' === $field ) ? 'homepage_title' : 'homepage_description';
			$titles         = (array) get_option( 'rank-math-options-titles', array() );
			$titles[ $key ] = $value;
			update_option( 'rank-math-options-titles', $titles );
			// Rank Math caches its parsed settings for the rest of the
			// request in a runtime singleton; reset it so anything reading
			// settings later in the same request (e.g. a subsequent /resolve
			// call in the same batch) sees the new value, matching what
			// Rank Math's own Abilities API does after a settings write.
			if ( function_exists( 'rank_math' ) ) {
				$rank_math = rank_math();
				if ( isset( $rank_math->settings ) && is_object( $rank_math->settings ) && method_exists( $rank_math->settings, 'reset' ) ) {
					$rank_math->settings->reset();
				}
			}
		}
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
