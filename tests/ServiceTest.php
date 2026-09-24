<?php

use PHPUnit\Framework\TestCase;

class ServiceTest extends TestCase {

	private CCC_Adapter $adapter;

	protected function setUp(): void {
		cc_reset_wp();
		$this->adapter = new CCC_Adapter( 'yoast', '_yoast_wpseo_title', '_yoast_wpseo_metadesc' );
		cc_add_post( 7, 'https://example.com/hello', 'Hello' );
		cc_add_post( 8, 'https://example.com/world', 'World' );
	}

	// ── resolve_url ────────────────────────────────────────────────

	public function test_resolve_rejects_empty_and_foreign_urls() {
		$this->assertSame( 'ccc_bad_url', CCC_Service::resolve_url( '' )->get_error_code() );
		$this->assertSame( 'ccc_wrong_site', CCC_Service::resolve_url( 'https://other-site.com/hello' )->get_error_code() );
	}

	public function test_resolve_maps_url_to_post_id() {
		$this->assertSame( 7, CCC_Service::resolve_url( 'https://example.com/hello' ) );
	}

	public function test_resolve_host_check_is_case_insensitive() {
		// Uppercase host must pass the same-site check (the stub's
		// url_to_postid is exact-match, so it then reports unresolvable —
		// the point is it is NOT ccc_wrong_site).
		$err = CCC_Service::resolve_url( 'https://EXAMPLE.com/hello' );
		$this->assertSame( 'ccc_unresolvable', $err->get_error_code() );
	}

	public function test_resolve_unresolvable_url_404s() {
		$err = CCC_Service::resolve_url( 'https://example.com/category/stuff/' );
		$this->assertSame( 'ccc_unresolvable', $err->get_error_code() );
	}

	public function test_resolve_rejects_a_url_to_id_match_with_no_such_post() {
		// Real WordPress's url_to_postid() pattern-matches "?p=N" straight out
		// of the query string and returns N even if no post N exists (confirmed
		// against a live install) — simulate that split here: the URL "resolves"
		// to an id that has no post record.
		$GLOBALS['cc_urls']['https://example.com/?p=999'] = 999;
		$err = CCC_Service::resolve_url( 'https://example.com/?p=999' );
		$this->assertSame( 'ccc_unresolvable', $err->get_error_code() );
	}

	public function test_resolve_root_url_is_the_homepage_when_no_static_front_page() {
		$this->assertSame( CCC_Service::HOME_ID, CCC_Service::resolve_url( 'https://example.com/' ) );
		$this->assertSame( CCC_Service::HOME_ID, CCC_Service::resolve_url( 'https://example.com' ) );
	}

	public function test_resolve_root_url_is_not_the_homepage_with_a_static_front_page() {
		$GLOBALS['cc_options']['show_on_front'] = 'page';
		cc_add_post( 3, 'https://example.com/' );
		$this->assertSame( 3, CCC_Service::resolve_url( 'https://example.com/' ) );
	}

	public function test_resolve_root_url_with_a_query_string_is_not_the_homepage() {
		// A "Plain" permalink post at the site root, e.g. "/?p=5" — must
		// resolve as that post, not be swallowed by the homepage match.
		$GLOBALS['cc_urls']['https://example.com/?p=5'] = 9;
		cc_add_post( 9, 'https://example.com/?p=5' );
		$this->assertSame( 9, CCC_Service::resolve_url( 'https://example.com/?p=5' ) );
	}

	// ── validate_change ────────────────────────────────────────────

	public function test_validate_needs_a_target_and_a_field() {
		$this->assertSame( 'ccc_no_target', CCC_Service::validate_change( array( 'title' => 'X' ) )->get_error_code() );
		$this->assertSame( 'ccc_nothing_to_do', CCC_Service::validate_change( array( 'post_id' => 7 ) )->get_error_code() );
		$this->assertSame( 'ccc_no_post', CCC_Service::validate_change( array( 'post_id' => 999, 'title' => 'X' ) )->get_error_code() );
		$this->assertSame( 'ccc_bad_change', CCC_Service::validate_change( 'not-an-object' )->get_error_code() );
	}

	public function test_validate_rejects_non_string_and_overlong_values() {
		$this->assertSame( 'ccc_bad_value', CCC_Service::validate_change( array( 'post_id' => 7, 'title' => array( 'x' ) ) )->get_error_code() );
		$long = str_repeat( 'a', CCC_Service::MAX_TITLE_LEN + 1 );
		$this->assertSame( 'ccc_too_long', CCC_Service::validate_change( array( 'post_id' => 7, 'title' => $long ) )->get_error_code() );
	}

	public function test_validate_sanitizes_values() {
		$valid = CCC_Service::validate_change( array( 'post_id' => 7, 'title' => "  New <b>title</b>\nline  " ) );
		$this->assertSame( 'New title line', $valid['fields']['title'] );
	}

	public function test_validate_resolves_url_targets() {
		$valid = CCC_Service::validate_change( array( 'url' => 'https://example.com/world', 'description' => 'D' ) );
		$this->assertSame( 8, $valid['post_id'] );
	}

	public function test_validate_accepts_explicit_post_id_zero_as_the_homepage() {
		$valid = CCC_Service::validate_change( array( 'post_id' => 0, 'title' => 'Home title' ) );
		$this->assertSame( CCC_Service::HOME_ID, $valid['post_id'] );
	}

	public function test_validate_rejects_post_id_zero_with_a_static_front_page() {
		$GLOBALS['cc_options']['show_on_front'] = 'page';
		$err = CCC_Service::validate_change( array( 'post_id' => 0, 'title' => 'X' ) );
		$this->assertSame( 'ccc_no_homepage_target', $err->get_error_code() );
	}

	public function test_validate_rejects_non_numeric_post_id_rather_than_treating_it_as_home() {
		// (int) 'abc' === 0 in PHP — must not silently become the homepage.
		$err = CCC_Service::validate_change( array( 'post_id' => 'abc', 'title' => 'X' ) );
		$this->assertSame( 'ccc_no_target', $err->get_error_code() );
	}

	// ── apply ──────────────────────────────────────────────────────

	public function test_apply_rejects_empty_and_oversized_batches() {
		$this->assertSame( 'ccc_empty_batch', CCC_Service::apply( array(), false, $this->adapter, 'u' )->get_error_code() );
		$batch = array_fill( 0, CCC_Service::MAX_BATCH + 1, array( 'post_id' => 7, 'title' => 'X' ) );
		$this->assertSame( 'ccc_batch_too_big', CCC_Service::apply( $batch, false, $this->adapter, 'u' )->get_error_code() );
	}

	public function test_dry_run_diffs_without_writing() {
		$this->adapter->set_title( 7, 'Old title' );
		$res = CCC_Service::apply(
			array( array( 'post_id' => 7, 'title' => 'New title', 'description' => 'New desc' ) ),
			true, $this->adapter, 'bloo'
		);
		$this->assertTrue( $res[0]['ok'] );
		$this->assertTrue( $res[0]['applied']['title']['changed'] );
		$this->assertSame( 'Old title', $res[0]['applied']['title']['from'] );
		$this->assertSame( 'Old title', $this->adapter->get_title( 7 ) );
		$this->assertSame( array(), CCC_Change_Log::all() );
		$this->assertSame( array(), $GLOBALS['cc_saved'] );
	}

	public function test_apply_writes_logs_and_resaves_posts() {
		$this->adapter->set_title( 7, 'Old title' );
		$res = CCC_Service::apply(
			array( array( 'url' => 'https://example.com/hello', 'title' => 'New title', 'description' => 'New desc' ) ),
			false, $this->adapter, 'bloo'
		);
		$this->assertTrue( $res[0]['ok'] );
		$this->assertSame( 'New title', $this->adapter->get_title( 7 ) );
		$this->assertSame( 'New desc', $this->adapter->get_description( 7 ) );
		$this->assertArrayHasKey( 'change_id', $res[0]['applied']['title'] );
		$this->assertCount( 2, CCC_Change_Log::all() );
		$this->assertSame( array( 7 ), $GLOBALS['cc_saved'] );

		$log = CCC_Change_Log::all();
		$this->assertSame( 'bloo', $log[0]['source'] );
	}

	public function test_apply_skips_unchanged_values() {
		$this->adapter->set_title( 7, 'Same' );
		$res = CCC_Service::apply(
			array( array( 'post_id' => 7, 'title' => 'Same' ) ),
			false, $this->adapter, 'bloo'
		);
		$this->assertFalse( $res[0]['applied']['title']['changed'] );
		$this->assertArrayNotHasKey( 'change_id', $res[0]['applied']['title'] );
		$this->assertSame( array(), CCC_Change_Log::all() );
		$this->assertSame( array(), $GLOBALS['cc_saved'] );
	}

	public function test_apply_enforces_per_post_capability() {
		$GLOBALS['cc_deny'] = array( 7 );
		$res = CCC_Service::apply(
			array(
				array( 'post_id' => 7, 'title' => 'Blocked' ),
				array( 'post_id' => 8, 'title' => 'Allowed' ),
			),
			false, $this->adapter, 'bloo'
		);
		$this->assertFalse( $res[0]['ok'] );
		$this->assertSame( 'ccc_forbidden', $res[0]['error'] );
		$this->assertSame( '', $this->adapter->get_title( 7 ) );
		$this->assertTrue( $res[1]['ok'] );
		$this->assertSame( 'Allowed', $this->adapter->get_title( 8 ) );
	}

	public function test_one_bad_item_does_not_block_the_rest() {
		$res = CCC_Service::apply(
			array(
				array( 'url' => 'https://elsewhere.com/x', 'title' => 'X' ),
				array( 'post_id' => 8, 'title' => 'Good' ),
			),
			false, $this->adapter, 'bloo'
		);
		$this->assertFalse( $res[0]['ok'] );
		$this->assertSame( 'ccc_wrong_site', $res[0]['error'] );
		$this->assertTrue( $res[1]['ok'] );
		$this->assertSame( 'Good', $this->adapter->get_title( 8 ) );
	}

	public function test_empty_string_removes_override_and_is_revertable() {
		$this->adapter->set_description( 7, 'Bad copy' );
		$res = CCC_Service::apply(
			array( array( 'post_id' => 7, 'description' => '' ) ),
			false, $this->adapter, 'bloo'
		);
		$this->assertTrue( $res[0]['ok'] );
		$this->assertSame( '', $this->adapter->get_description( 7 ) );

		CCC_Change_Log::revert( $res[0]['applied']['description']['change_id'], $this->adapter );
		$this->assertSame( 'Bad copy', $this->adapter->get_description( 7 ) );
	}

	// ── homepage (HOME_ID = 0) ────────────────────────────────────

	public function test_apply_writes_the_homepage_title_and_does_not_resave_a_post() {
		$res = CCC_Service::apply(
			array( array( 'post_id' => 0, 'title' => 'New home title' ) ),
			false, $this->adapter, 'bloo'
		);
		$this->assertTrue( $res[0]['ok'] );
		$this->assertSame( 'New home title', $this->adapter->get_title( 0 ) );
		$this->assertArrayHasKey( 'change_id', $res[0]['applied']['title'] );
		// Yoast's own wpseo_titles watcher rebuilds the home indexable on the
		// option write itself; wp_update_post( ['ID' => 0] ) must never run —
		// WordPress core treats ID 0 as "insert a new post", not "no-op".
		$this->assertSame( array(), $GLOBALS['cc_saved'] );
	}

	public function test_apply_rejects_homepage_changes_for_an_adapter_without_home_support() {
		$aioseo = new CCC_Adapter( 'aioseo', '_aioseo_title', '_aioseo_description' );
		$res    = CCC_Service::apply(
			array( array( 'post_id' => 0, 'title' => 'New home title' ) ),
			false, $aioseo, 'bloo'
		);
		$this->assertFalse( $res[0]['ok'] );
		$this->assertSame( 'ccc_home_unsupported', $res[0]['error'] );
	}

	public function test_apply_blocks_clearing_the_rankmath_home_title_but_allows_description() {
		$rankmath = new CCC_Adapter( 'rankmath', 'rank_math_title', 'rank_math_description' );
		$rankmath->set_title( 0, 'Existing home title' );
		$rankmath->set_description( 0, 'Existing home description' );
		$res = CCC_Service::apply(
			array( array( 'post_id' => 0, 'title' => '', 'description' => '' ) ),
			false, $rankmath, 'bloo'
		);
		$this->assertTrue( $res[0]['ok'] );
		$this->assertSame( 'ccc_home_title_clear_unsupported', $res[0]['applied']['title']['error'] );
		$this->assertSame( 'Existing home title', $rankmath->get_title( 0 ), 'blocked field must not be written' );
		$this->assertArrayNotHasKey( 'error', $res[0]['applied']['description'] );
		$this->assertTrue( $res[0]['applied']['description']['changed'] );
	}

	public function test_apply_enforces_manage_options_for_the_homepage_not_edit_post() {
		$GLOBALS['cc_deny_manage_options'] = true;
		$res = CCC_Service::apply(
			array( array( 'post_id' => 0, 'title' => 'X' ) ),
			false, $this->adapter, 'bloo'
		);
		$this->assertFalse( $res[0]['ok'] );
		$this->assertSame( 'ccc_forbidden', $res[0]['error'] );
	}

	public function test_homepage_change_is_revertable() {
		$this->adapter->set_title( 0, 'Old home title' );
		$res = CCC_Service::apply(
			array( array( 'post_id' => 0, 'title' => 'New home title' ) ),
			false, $this->adapter, 'bloo'
		);
		$done = CCC_Change_Log::revert( $res[0]['applied']['title']['change_id'], $this->adapter );
		$this->assertTrue( $done );
		$this->assertSame( 'Old home title', $this->adapter->get_title( 0 ) );
	}

	public function test_describe_homepage_target() {
		$this->adapter->set_title( 0, 'Home title' );
		$d = CCC_Service::describe( CCC_Service::HOME_ID, $this->adapter );
		$this->assertSame( 0, $d['post_id'] );
		$this->assertSame( 'https://example.com/', $d['permalink'] );
		$this->assertTrue( $d['editable'] );
		$this->assertSame( 'Home title', $d['current']['title'] );
	}

	public function test_describe_homepage_not_editable_when_adapter_lacks_support() {
		$aioseo = new CCC_Adapter( 'aioseo', '_aioseo_title', '_aioseo_description' );
		$d      = CCC_Service::describe( CCC_Service::HOME_ID, $aioseo );
		$this->assertFalse( $d['editable'] );
	}

	public function test_can_edit_target_uses_manage_options_for_the_homepage() {
		$this->assertTrue( CCC_Service::can_edit_target( CCC_Service::HOME_ID ) );
		$GLOBALS['cc_deny_manage_options'] = true;
		$this->assertFalse( CCC_Service::can_edit_target( CCC_Service::HOME_ID ) );
		// Unaffected: post-level checks still key off edit_post, not manage_options.
		$this->assertTrue( CCC_Service::can_edit_target( 7 ) );
	}

	// ── taxonomy terms (negative post_id sentinel) ─────────────────

	public function test_resolve_falls_back_to_term_resolution_after_post_lookup_fails() {
		cc_add_term( 5, 'category', 'News', 'https://example.com/category/news/' );
		$this->assertSame( -5, CCC_Service::resolve_url( 'https://example.com/category/news/' ) );
	}

	public function test_resolve_prefers_a_real_post_match_over_a_term_match() {
		// url_to_postid() is checked first; only its miss falls through to
		// term resolution — a URL that resolves as a post never reaches the
		// term resolver at all.
		cc_add_post( 7, 'https://example.com/hello' );
		$this->assertSame( 7, CCC_Service::resolve_url( 'https://example.com/hello' ) );
	}

	public function test_resolve_term_url_for_a_term_that_no_longer_exists_is_unresolvable() {
		// CCC_Term_Resolver "matched" a term id, but CCC_Service re-verifies
		// it with get_term() before trusting it — belt and suspenders against
		// a resolver returning a stale/deleted term id.
		$GLOBALS['cc_term_urls']['https://example.com/category/gone/'] = 999;
		$err = CCC_Service::resolve_url( 'https://example.com/category/gone/' );
		$this->assertSame( 'ccc_unresolvable', $err->get_error_code() );
	}

	public function test_validate_accepts_explicit_negative_post_id_as_a_term() {
		cc_add_term( 5, 'category', 'News' );
		$valid = CCC_Service::validate_change( array( 'post_id' => -5, 'title' => 'X' ) );
		$this->assertSame( -5, $valid['post_id'] );
	}

	public function test_validate_rejects_a_term_id_with_no_such_term() {
		$err = CCC_Service::validate_change( array( 'post_id' => -999, 'title' => 'X' ) );
		$this->assertSame( 'ccc_no_term', $err->get_error_code() );
	}

	public function test_apply_writes_a_term_title_for_a_supporting_adapter() {
		cc_add_term( 5, 'category', 'News' );
		$rankmath = new CCC_Adapter( 'rankmath', 'rank_math_title', 'rank_math_description' );
		$res      = CCC_Service::apply(
			array( array( 'post_id' => -5, 'title' => 'New term title' ) ),
			false, $rankmath, 'bloo'
		);
		$this->assertTrue( $res[0]['ok'] );
		$this->assertSame( 'New term title', $rankmath->get_title( -5 ) );
		$this->assertArrayHasKey( 'change_id', $res[0]['applied']['title'] );
		// A term is not a post — must never trigger the Yoast-indexable
		// re-save path (this is the exact class of bug wp_update_post(['ID'
		// => 0]) was for the homepage; a negative "ID" would be nonsense to
		// core entirely).
		$this->assertSame( array(), $GLOBALS['cc_saved'] );
	}

	public function test_apply_rejects_term_changes_for_an_adapter_without_term_support() {
		cc_add_term( 5, 'category', 'News' );
		$seopress = new CCC_Adapter( 'seopress', '_seopress_titles_title', '_seopress_titles_desc' );
		$res      = CCC_Service::apply(
			array( array( 'post_id' => -5, 'title' => 'X' ) ),
			false, $seopress, 'bloo'
		);
		$this->assertFalse( $res[0]['ok'] );
		$this->assertSame( 'ccc_term_unsupported', $res[0]['error'] );
	}

	public function test_apply_enforces_edit_term_capability() {
		cc_add_term( 5, 'category', 'News' );
		$GLOBALS['cc_deny_terms'] = array( 5 );
		$rankmath                 = new CCC_Adapter( 'rankmath', 'rank_math_title', 'rank_math_description' );
		$res                      = CCC_Service::apply(
			array( array( 'post_id' => -5, 'title' => 'X' ) ),
			false, $rankmath, 'bloo'
		);
		$this->assertFalse( $res[0]['ok'] );
		$this->assertSame( 'ccc_forbidden', $res[0]['error'] );
	}

	public function test_term_change_is_revertable() {
		cc_add_term( 5, 'category', 'News' );
		$rankmath = new CCC_Adapter( 'rankmath', 'rank_math_title', 'rank_math_description' );
		$rankmath->set_title( -5, 'Old term title' );
		$res  = CCC_Service::apply(
			array( array( 'post_id' => -5, 'title' => 'New term title' ) ),
			false, $rankmath, 'bloo'
		);
		$done = CCC_Change_Log::revert( $res[0]['applied']['title']['change_id'], $rankmath );
		$this->assertTrue( $done );
		$this->assertSame( 'Old term title', $rankmath->get_title( -5 ) );
	}

	public function test_revert_fails_when_the_term_no_longer_exists() {
		cc_add_term( 5, 'category', 'News' );
		$rankmath = new CCC_Adapter( 'rankmath', 'rank_math_title', 'rank_math_description' );
		$res      = CCC_Service::apply(
			array( array( 'post_id' => -5, 'title' => 'New term title' ) ),
			false, $rankmath, 'bloo'
		);
		unset( $GLOBALS['cc_terms'][5] );
		$err = CCC_Change_Log::revert( $res[0]['applied']['title']['change_id'], $rankmath );
		$this->assertSame( 'ccc_term_gone', $err->get_error_code() );
	}

	public function test_describe_term_target() {
		cc_add_term( 5, 'category', 'News', null, 'https://example.com/category/news/' );
		$rankmath = new CCC_Adapter( 'rankmath', 'rank_math_title', 'rank_math_description' );
		$rankmath->set_title( -5, 'Term title' );
		$d = CCC_Service::describe( -5, $rankmath );
		$this->assertSame( -5, $d['post_id'] );
		$this->assertSame( 'News', $d['post_title'] );
		$this->assertSame( 'https://example.com/category/news/', $d['permalink'] );
		$this->assertTrue( $d['editable'] );
		$this->assertSame( 'Term title', $d['current']['title'] );
	}

	public function test_describe_term_not_editable_when_adapter_lacks_support() {
		cc_add_term( 5, 'category', 'News' );
		$seopress = new CCC_Adapter( 'seopress', '_seopress_titles_title', '_seopress_titles_desc' );
		$d        = CCC_Service::describe( -5, $seopress );
		$this->assertFalse( $d['editable'] );
	}

	public function test_can_edit_target_uses_edit_term_for_terms() {
		cc_add_term( 5, 'category', 'News' );
		$this->assertTrue( CCC_Service::can_edit_target( -5 ) );
		$GLOBALS['cc_deny_terms'] = array( 5 );
		$this->assertFalse( CCC_Service::can_edit_target( -5 ) );
		// Unaffected: post-level checks still key off edit_post.
		$this->assertTrue( CCC_Service::can_edit_target( 7 ) );
	}
}
