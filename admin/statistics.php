<?php

// Bỏ qua cảnh báo biến toàn cục
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get data from Cache (Transient) so the website doesn't freeze if there are thousands of posts
$stats_data = get_transient( 'tdpl_auto_links_stats_data' );

if ( false === $stats_data ) {
	$stats_data = [];
	$options = get_option( 'tdpl_ail_settings', [] );
	
	$post_types     = ! empty( $options['post_types'] ) ? $options['post_types'] : [ 'post' ];
	$exclude_ids    = ! empty( $options['exclude_posts'] ) ? array_map( 'intval', explode( ',', $options['exclude_posts'] ) ) : [];
	$min_length     = ! empty( $options['min_title_length'] ) ? intval( $options['min_title_length'] ) : 4;
	$case_sensitive = ! empty( $options['case_sensitive'] ) ? true : false;
	$regex_modifier = $case_sensitive ? 'u' : 'iu';

	global $wpdb;

	// Tạo chuỗi các placeholder %s dựa trên số lượng post_types (ví dụ: %s, %s, %s)
	$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

	// Chuẩn bị truy vấn an toàn. Dùng phpcs:ignore để báo cho Plugin Check biết đoạn này đã được xử lý an toàn
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$query = $wpdb->prepare( "SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($placeholders)", ...$post_types );

	// Thực thi truy vấn
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$posts = $wpdb->get_results( $query );	

	$keywords = [];
	foreach ( $posts as $p ) {
		$title = trim( $p->post_title );
		if ( mb_strlen( $title, 'UTF-8' ) >= $min_length ) {
			$keywords[$title] = get_permalink( $p->ID );
			$stats_data[$title] = []; // Initialize empty array for each keyword
		}
	}

	// Sort keywords by descending length
	uksort( $keywords, function ( $a, $b ) {
		return mb_strlen( $b, 'UTF-8' ) - mb_strlen( $a, 'UTF-8' );
	} );

	// Proceed to simulate scanning the content of the posts
	foreach ( $posts as $p ) {
		if ( in_array( (int)$p->ID, $exclude_ids ) ) {
			continue;
		}

		$content = $p->post_content;
		if ( empty( $content ) ) {
			continue;
		}

		$current_title = trim( $p->post_title );
		$chunks = wp_html_split( $content );
		$linked_urls = [];
		$ignore_tags = [ 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'script', 'style', 'pre', 'code', 'button', 'iframe' ];
		$is_ignored  = false;

		foreach ( $chunks as $chunk ) {
			if ( strpos( $chunk, '<' ) === 0 ) {
				if ( preg_match( '/^<(\/)?([a-zA-Z0-9]+)/', $chunk, $tag_match ) ) {
					$tag_name   = strtolower( $tag_match[2] );
					$is_closing = ( $tag_match[1] === '/' );
					if ( in_array( $tag_name, $ignore_tags, true ) ) {
						$is_ignored = ! $is_closing;
					}
				}
				continue;
			}

			if ( $is_ignored ) continue;

			foreach ( $keywords as $title => $url ) {
				if ( $title === $current_title ) continue; // Do not link to itself
				if ( in_array( $url, $linked_urls, true ) ) continue; // Only count once per post

				// Optimize performance: Quick check using strpos before running Regex
				$search_func = $case_sensitive ? 'mb_strpos' : 'mb_stripos';
				if ( $search_func( $chunk, $title, 0, 'UTF-8' ) !== false ) {
					
					$pattern = '/(^|[^\p{L}\p{N}])(' . preg_quote( $title, '/' ) . ')(?=[^\p{L}\p{N}]|$)/' . $regex_modifier;
					if ( preg_match( $pattern, $chunk ) ) {
						$linked_urls[] = $url;
						
						// Add post information to this keyword's list
						$stats_data[ $title ][] = [
							'id'        => $p->ID,
							'title'     => $current_title,
							'edit_link' => get_edit_post_link( $p->ID, 'raw' ),
							'view_link' => get_permalink( $p->ID )
						];
					}
				}
			}
		}
	}

	// Filter out keywords that have no links to make the table more compact
	$stats_data = array_filter($stats_data, function($data) {
		return count($data) > 0;
	});

	// Sort keywords with the highest number of links to the top
	uasort($stats_data, function($a, $b) {
		return count($b) - count($a);
	});

	// Cache for 1 minute (60 seconds)
	set_transient( 'tdpl_auto_links_stats_data', $stats_data, 60 );
}
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Auto Internal Links Statistics', 'auto-internal-links' ); ?></h1>
	<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=auto-internal-links&refresh_stats=1' ), 'refresh_ail_stats' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Refresh Data', 'auto-internal-links' ); ?></a>
	<hr class="wp-header-end">

	<p><?php esc_html_e( 'The statistics table lists the Titles (Keywords) that have been automatically linked to other posts. The data is cached, please click "Refresh Data" if you have just written a new post.', 'auto-internal-links' ); ?></p>
	
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Title / Keyword (Linked)', 'auto-internal-links' ); ?></th>
				<th style="width: 150px;"><?php esc_html_e( 'Number of linked posts', 'auto-internal-links' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $stats_data ) ) : ?>
			<tr>
					<td colspan="2"><?php esc_html_e( 'No internal link statistics data available yet.', 'auto-internal-links' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $stats_data as $keyword => $posts ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $keyword ); ?></strong></td>
						<td>
							<a href="javascript:void(0);" 
								class="tdpl-show-posts-btn button button-small" 
								data-keyword="<?php echo esc_attr( $keyword ); ?>" 
								data-posts="<?php echo esc_attr( wp_json_encode( $posts ) ); ?>">
								<?php echo (int) count( $posts ); ?> <?php esc_html_e( 'links', 'auto-internal-links' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>

<div id="tdpl-stats-modal" style="display: none; position: fixed; z-index: 99999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.6);">
	<div style="background-color: #fff; margin: 5% auto; padding: 0; border-radius: 4px; width: 90%; max-width: 800px; max-height: 80vh; display: flex; flex-direction: column; box-shadow: 0 5px 15px rgba(0,0,0,0.3);">
	<div style="padding: 15px 20px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center;">
			<h2 style="margin: 0;"><?php esc_html_e( 'Posts containing the keyword:', 'auto-internal-links' ); ?> <span id="tdpl-modal-keyword" style="color: #2271b1;"></span></h2>
			<span class="tdpl-close-modal" style="font-size: 24px; cursor: pointer; color: #666;">&times;</span>
		</div>
	<div id="tdpl-modal-body" style="padding: 20px; overflow-y: auto;">
			</div>
	</div>
</div>

<script>
jQuery(document).ready(function($) {
	// Open Popup
	$('.tdpl-show-posts-btn').on('click', function() {		
		var keyword = $(this).data('keyword');
		var posts = $(this).data('posts');
		
		var html = '<table class="wp-list-table widefat fixed striped"><thead><tr><th><?php esc_html_e( 'Post Title (Where link is placed)', 'auto-internal-links' ); ?></th><th style="width: 120px;"><?php esc_html_e( 'Action', 'auto-internal-links' ); ?></th></tr></thead><tbody>';
		$.each(posts, function(index, post) {
			html += '<tr>';
			html += '<td><a href="' + post.view_link + '" target="_blank">' + post.title + '</a></td>';
			html += '<td><a href="' + post.edit_link + '" target="_blank" class="button button-small">Edit</a></td>';
			html += '</tr>';
		});
		html += '</tbody></table>';

		$('#tdpl-modal-keyword').text(keyword);
		$('#tdpl-modal-body').html(html);
		$('#tdpl-stats-modal').fadeIn('fast');
	});

	// Close Popup
	$('.tdpl-close-modal').on('click', function() {
		$('#tdpl-stats-modal').fadeOut('fast');
	});

	// Automatically reload the statistics page every 60 seconds to update with new data
	setTimeout(function() {
		window.location.reload();
	}, 60000);
});
</script>