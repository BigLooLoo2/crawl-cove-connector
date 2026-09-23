<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

class AdapterTest extends TestCase {

	protected function setUp(): void {
		cc_reset_wp();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_detect_returns_null_without_seo_plugin() {
		$this->assertNull( CCC_Adapter::detect() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_detect_yoast() {
		define( 'WPSEO_VERSION', '23.9' );
		$a = CCC_Adapter::detect();
		$this->assertSame( 'yoast', $a->id );
		$this->assertSame( '_yoast_wpseo_title', $a->title_key );
		$this->assertSame( '_yoast_wpseo_metadesc', $a->description_key );
		$this->assertSame( '23.9', $a->plugin_version );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_detect_rankmath() {
		define( 'RANK_MATH_VERSION', '1.0.230' );
		$a = CCC_Adapter::detect();
		$this->assertSame( 'rankmath', $a->id );
		$this->assertSame( 'rank_math_title', $a->title_key );
		$this->assertSame( 'rank_math_description', $a->description_key );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_detect_prefers_yoast_when_both_present() {
		define( 'WPSEO_VERSION', '23.9' );
		define( 'RANK_MATH_VERSION', '1.0.230' );
		$this->assertSame( 'yoast', CCC_Adapter::detect()->id );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_detect_seopress() {
		define( 'SEOPRESS_VERSION', '10.2' );
		$a = CCC_Adapter::detect();
		$this->assertSame( 'seopress', $a->id );
		$this->assertSame( '_seopress_titles_title', $a->title_key );
		$this->assertSame( '_seopress_titles_desc', $a->description_key );
		$this->assertSame( '10.2', $a->plugin_version );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_detect_prefers_rankmath_over_seopress() {
		define( 'RANK_MATH_VERSION', '1.0.230' );
		define( 'SEOPRESS_VERSION', '10.2' );
		$this->assertSame( 'rankmath', CCC_Adapter::detect()->id );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_detect_aioseo() {
		function aioseo() {}
		define( 'AIOSEO_VERSION', '4.9.6' );
		$a = CCC_Adapter::detect();
		$this->assertSame( 'aioseo', $a->id );
		$this->assertSame( '4.9.6', $a->plugin_version );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_detect_prefers_seopress_over_aioseo() {
		function aioseo() {}
		define( 'SEOPRESS_VERSION', '10.2' );
		define( 'AIOSEO_VERSION', '4.9.6' );
		$this->assertSame( 'seopress', CCC_Adapter::detect()->id );
	}

	public function test_label_for_each_adapter() {
		$this->assertSame( 'Yoast SEO', ( new CCC_Adapter( 'yoast', 'a', 'b' ) )->label() );
		$this->assertSame( 'Rank Math', ( new CCC_Adapter( 'rankmath', 'a', 'b' ) )->label() );
		$this->assertSame( 'SEOPress', ( new CCC_Adapter( 'seopress', 'a', 'b' ) )->label() );
		$this->assertSame( 'All in One SEO', ( new CCC_Adapter( 'aioseo', 'a', 'b' ) )->label() );
	}

	public function test_set_and_get_roundtrip() {
		$a = new CCC_Adapter( 'yoast', '_yoast_wpseo_title', '_yoast_wpseo_metadesc' );
		$a->set_title( 7, 'New title' );
		$a->set_description( 7, 'New description' );
		$this->assertSame( 'New title', $a->get_title( 7 ) );
		$this->assertSame( 'New description', $a->get_description( 7 ) );
	}

	public function test_empty_value_deletes_the_override() {
		$a = new CCC_Adapter( 'rankmath', 'rank_math_title', 'rank_math_description' );
		$a->set_title( 7, 'Something' );
		$a->set_title( 7, '' );
		$this->assertSame( '', $a->get_title( 7 ) );
		$this->assertArrayNotHasKey( 'rank_math_title', $GLOBALS['cc_meta'][7] ?? array() );
	}

	public function test_seopress_set_and_get_roundtrip() {
		$a = new CCC_Adapter( 'seopress', '_seopress_titles_title', '_seopress_titles_desc' );
		$a->set_title( 7, 'New title' );
		$a->set_description( 7, 'New description' );
		$this->assertSame( 'New title', $a->get_title( 7 ) );
		$this->assertSame( 'New description', $a->get_description( 7 ) );
		$a->set_description( 7, '' );
		$this->assertArrayNotHasKey( '_seopress_titles_desc', $GLOBALS['cc_meta'][7] ?? array() );
	}

	public function test_aioseo_set_and_get_roundtrip() {
		$a = new CCC_Adapter( 'aioseo', '_aioseo_title', '_aioseo_description' );
		$a->set_title( 7, 'New title' );
		$a->set_description( 7, 'New description' );
		$this->assertSame( 'New title', $a->get_title( 7 ) );
		$this->assertSame( 'New description', $a->get_description( 7 ) );
	}

	public function test_aioseo_empty_value_clears_the_override() {
		$a = new CCC_Adapter( 'aioseo', '_aioseo_title', '_aioseo_description' );
		$a->set_title( 7, 'Something' );
		$a->set_title( 7, '' );
		$this->assertSame( '', $a->get_title( 7 ) );
	}

	public function test_aioseo_does_not_touch_postmeta() {
		$a = new CCC_Adapter( 'aioseo', '_aioseo_title', '_aioseo_description' );
		$a->set_title( 7, 'New title' );
		$this->assertArrayNotHasKey( 7, $GLOBALS['cc_meta'] );
	}

	// ── homepage (HOME_ID = 0) ────────────────────────────────────

	public function test_supports_home_only_for_yoast_and_rankmath() {
		$this->assertTrue( ( new CCC_Adapter( 'yoast', 'a', 'b' ) )->supports_home() );
		$this->assertTrue( ( new CCC_Adapter( 'rankmath', 'a', 'b' ) )->supports_home() );
		$this->assertFalse( ( new CCC_Adapter( 'seopress', 'a', 'b' ) )->supports_home() );
		$this->assertFalse( ( new CCC_Adapter( 'aioseo', 'a', 'b' ) )->supports_home() );
	}

	public function test_only_rankmath_disallows_clearing_the_home_title() {
		$this->assertTrue( ( new CCC_Adapter( 'yoast', 'a', 'b' ) )->can_clear_home_title() );
		$this->assertFalse( ( new CCC_Adapter( 'rankmath', 'a', 'b' ) )->can_clear_home_title() );
	}

	public function test_yoast_home_title_writes_through_wpseo_titles_option() {
		$a = new CCC_Adapter( 'yoast', '_yoast_wpseo_title', '_yoast_wpseo_metadesc' );
		$a->set_title( 0, 'New home title' );
		$a->set_description( 0, 'New home desc' );
		$this->assertSame( 'New home title', $a->get_title( 0 ) );
		$this->assertSame( 'New home desc', $a->get_description( 0 ) );
		$this->assertSame( 'New home title', $GLOBALS['cc_options']['wpseo_titles']['title-home-wpseo'] );
		// The whole option round-trips through the same read-modify-write
		// helper: other keys already in wpseo_titles must survive untouched.
		$this->assertArrayNotHasKey( 7, $GLOBALS['cc_meta'] );
	}

	public function test_yoast_home_write_preserves_unrelated_option_keys() {
		$GLOBALS['cc_options']['wpseo_titles'] = array( 'title-author-wpseo' => 'keep me' );
		$a = new CCC_Adapter( 'yoast', '_yoast_wpseo_title', '_yoast_wpseo_metadesc' );
		$a->set_title( 0, 'New home title' );
		$this->assertSame( 'keep me', $GLOBALS['cc_options']['wpseo_titles']['title-author-wpseo'] );
	}

	public function test_rankmath_home_title_writes_through_titles_option() {
		$a = new CCC_Adapter( 'rankmath', 'rank_math_title', 'rank_math_description' );
		$a->set_title( 0, 'New home title' );
		$a->set_description( 0, 'New home desc' );
		$this->assertSame( 'New home title', $a->get_title( 0 ) );
		$this->assertSame( 'New home desc', $a->get_description( 0 ) );
		$this->assertSame( 'New home title', $GLOBALS['cc_options']['rank-math-options-titles']['homepage_title'] );
	}

	public function test_rankmath_home_write_preserves_unrelated_option_keys() {
		$GLOBALS['cc_options']['rank-math-options-titles'] = array( 'homepage_robots' => array( 'index' ) );
		$a = new CCC_Adapter( 'rankmath', 'rank_math_title', 'rank_math_description' );
		$a->set_title( 0, 'New home title' );
		$this->assertSame( array( 'index' ), $GLOBALS['cc_options']['rank-math-options-titles']['homepage_robots'] );
	}

	public function test_unsupported_adapters_return_empty_home_values() {
		$a = new CCC_Adapter( 'seopress', '_seopress_titles_title', '_seopress_titles_desc' );
		$this->assertSame( '', $a->get_title( 0 ) );
		$this->assertSame( '', $a->get_description( 0 ) );
	}
}
