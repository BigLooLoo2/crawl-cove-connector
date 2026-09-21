<?php
/**
 * Uninstall: remove everything the plugin stored. Applied SEO meta stays —
 * those are the site owner's reviewed choices, not plugin state.
 *
 * @package crawl-cove-connector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ccc_change_log' );
delete_option( 'ccc_change_seq' );
