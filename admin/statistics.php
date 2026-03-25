<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Lấy dữ liệu thống kê từ Database (Option)
$stats_data = get_option( 'tdpl_auto_links_stats_data', [] );

// Kiểm tra xem tiến trình quét ngầm có đang chạy không
$scan_offset = get_option( 'tdpl_ail_scan_offset', false );
$is_scanning = ( $scan_offset !== false );

?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Auto Internal Links Statistics', 'auto-internal-links' ); ?></h1>
	
    <?php if ( ! $is_scanning ) : ?>
        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=auto-internal-links&refresh_stats=1' ), 'refresh_ail_stats' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Re-scan Data', 'auto-internal-links' ); ?></a>
    <?php else: ?>
        <a href="javascript:void(0);" class="page-title-action" style="background: #f0f0f1; border-color: #dcdcde; color: #a7aaad; cursor: not-allowed;"><?php esc_html_e( 'Scanning in progress...', 'auto-internal-links' ); ?></a>
    <?php endif; ?>
    
	<hr class="wp-header-end">

    <?php if ( $is_scanning ) : ?>
        <div class="notice notice-info">
            <p>
                <?php 
                printf(
                    /* translators: %d: number of scanned articles */
                    wp_kses( 
                        __( '<strong>The system is scanning the data...</strong> Approximately %d articles have been scanned. <br>Please reload the page after a few minutes to see the results when complete.', 'auto-internal-links' ), 
                        [ 'strong' => [], 'br' => [] ] 
                    ),
                    (int) $scan_offset
                );
                ?>
            </p>
        </div>
    <?php else: ?>
	    <p><?php esc_html_e( 'The statistics table lists the Titles (Keywords) that have been automatically linked to other posts. Data is compiled via background scan.', 'auto-internal-links' ); ?></p>
    <?php endif; ?>
	
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Title / Keyword (Linked)', 'auto-internal-links' ); ?></th>
				<th style="width: 150px;"><?php esc_html_e( 'Number of linked posts', 'auto-internal-links' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $stats_data ) && ! $is_scanning ) : ?>
			    <tr>
					<td colspan="2"><?php esc_html_e( 'No internal link statistics data available. Click Re-scan Data to start generating.', 'auto-internal-links' ); ?></td>
				</tr>
            <?php elseif ( empty( $stats_data ) && $is_scanning ) : ?>
                <tr>
					<td colspan="2"><?php esc_html_e( 'Scanning in progress... Data will appear here once the scan is complete.', 'auto-internal-links' ); ?></td>
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
								<?php 
                                    $count = count( $posts );
                                    printf( 
                                        esc_html( _n( '%d link', '%d links', $count, 'auto-internal-links' ) ), 
                                        $count 
                                    ); 
                                ?>
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
			html += '<td><a href="' + post.edit_link + '" target="_blank" class="button button-small"><?php esc_html_e( 'Edit', 'auto-internal-links' ); ?></a></td>';
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

    <?php if ( $is_scanning ) : ?>
	// Tự động làm mới trang mỗi 30 giây nếu đang trong quá trình quét
	setTimeout(function() {
		window.location.reload();
	}, 30000);
    <?php endif; ?>
});
</script>