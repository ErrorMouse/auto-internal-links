<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'tdpl_ail_settings' );
delete_transient( 'tdpl_auto_links_cache' );
delete_transient( 'tdpl_auto_links_stats_data' );