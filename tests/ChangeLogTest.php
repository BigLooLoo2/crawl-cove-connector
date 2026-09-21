<?php

use PHPUnit\Framework\TestCase;

class ChangeLogTest extends TestCase {

	private CCC_Adapter $adapter;

	protected function setUp(): void {
		cc_reset_wp();
		$this->adapter = new CCC_Adapter( 'yoast', '_yoast_wpseo_title', '_yoast_wpseo_metadesc' );
		cc_add_post( 7, 'https://example.com/hello' );
	}

	public function test_record_assigns_incrementing_ids_newest_first() {
		$a = CCC_Change_Log::record( 7, 'title', 'Old', 'New', 'bloo' );
		$b = CCC_Change_Log::record( 7, 'description', '', 'Desc', 'bloo' );
		$this->assertSame( 1, $a['id'] );
		$this->assertSame( 2, $b['id'] );
		$log = CCC_Change_Log::all();
		$this->assertCount( 2, $log );
		$this->assertSame( 2, $log[0]['id'] );
	}

	public function test_log_is_capped_and_drops_oldest() {
		for ( $i = 1; $i <= CCC_Change_Log::MAX_ENTRIES + 5; $i++ ) {
			CCC_Change_Log::record( 7, 'title', "old$i", "new$i", 'bloo' );
		}
		$log = CCC_Change_Log::all();
		$this->assertCount( CCC_Change_Log::MAX_ENTRIES, $log );
		$this->assertSame( CCC_Change_Log::MAX_ENTRIES + 5, $log[0]['id'] );
		$this->assertNull( CCC_Change_Log::find( 1 ) );
	}

	public function test_revert_restores_previous_value_and_marks_entry() {
		$this->adapter->set_title( 7, 'Original' );
		$entry = CCC_Change_Log::record( 7, 'title', 'Original', 'Pushed', 'bloo' );
		$this->adapter->set_title( 7, 'Pushed' );

		$this->assertTrue( CCC_Change_Log::revert( $entry['id'], $this->adapter ) );
		$this->assertSame( 'Original', $this->adapter->get_title( 7 ) );
		$this->assertTrue( CCC_Change_Log::find( $entry['id'] )['reverted'] );
	}

	public function test_revert_of_unset_previous_value_deletes_override() {
		$entry = CCC_Change_Log::record( 7, 'description', '', 'Pushed desc', 'bloo' );
		$this->adapter->set_description( 7, 'Pushed desc' );

		CCC_Change_Log::revert( $entry['id'], $this->adapter );
		$this->assertArrayNotHasKey( '_yoast_wpseo_metadesc', $GLOBALS['cc_meta'][7] ?? array() );
	}

	public function test_double_revert_is_refused() {
		$entry = CCC_Change_Log::record( 7, 'title', 'A', 'B', 'bloo' );
		CCC_Change_Log::revert( $entry['id'], $this->adapter );
		$err = CCC_Change_Log::revert( $entry['id'], $this->adapter );
		$this->assertTrue( is_wp_error( $err ) );
		$this->assertSame( 'ccc_already_reverted', $err->get_error_code() );
	}

	public function test_revert_unknown_id_and_deleted_post() {
		$err = CCC_Change_Log::revert( 999, $this->adapter );
		$this->assertSame( 'ccc_not_found', $err->get_error_code() );

		$entry = CCC_Change_Log::record( 42, 'title', 'A', 'B', 'bloo' ); // post 42 never registered
		$err   = CCC_Change_Log::revert( $entry['id'], $this->adapter );
		$this->assertSame( 'ccc_post_gone', $err->get_error_code() );
	}
}
