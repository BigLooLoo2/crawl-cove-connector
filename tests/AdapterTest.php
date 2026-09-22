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
}
