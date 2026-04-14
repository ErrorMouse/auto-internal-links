<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'auli_ail_settings' );
delete_transient( 'auli_auto_links_cache' );
delete_transient( 'auli_auto_links_stats_data' );