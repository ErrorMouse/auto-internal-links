<?php
/**
 * Plugin Name: 		Auto Internal Links
 * Description: 		Automatically find keywords matching post titles and add internal links.
 * Version: 			1.0.0
 * Requires at least: 	5.2
 * Requires PHP:      	7.2
 * Author: 				Err
 * Author URI: 			https://profiles.wordpress.org/nmtnguyen56/
 * Contributors: 		nmtnguyen56
 * License: 			GPLv2 or later
 * License URI:    		https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: 		auto-internal-links
 * Domain Path:         /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TDPL_AIL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

class TDPL_Auto_Internal_Links {

	private $cache_key = 'tdpl_auto_links_cache';

	public function __construct() {
		// Apply content filter
		add_filter( 'the_content', [ $this, 'auto_link_content' ], 10 );

		// Clear cache when a post is changed to update the new title list
		add_action( 'save_post', [ $this, 'clear_cache' ] );
		add_action( 'deleted_post', [ $this, 'clear_cache' ] );
		add_action( 'update_option_tdpl_ail_settings', [ $this, 'clear_cache' ] ); // Update cache when saving settings

		// Add admin menu
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );

		// Handle refreshing statistics data early to avoid header errors
		add_action( 'admin_init', [ $this, 'handle_refresh_stats' ] );

		// Register plugin settings
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		// Đăng ký chu kỳ thời gian cho Cron
		add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );
		
		// Đăng ký hook chạy ngầm
		add_action( 'tdpl_ail_batch_scan_event', [ $this, 'process_batch_scan' ] );

		// Khởi tạo Cron nếu chưa có
		if ( ! wp_next_scheduled( 'tdpl_ail_batch_scan_event' ) ) {
			wp_schedule_event( time(), 'tdpl_ail_5_min', 'tdpl_ail_batch_scan_event' );
		}
	}	

	/**
	 * Clear cache when adding/editing/deleting posts
	 */
	public function clear_cache() {
		delete_transient( $this->cache_key );
		delete_transient( 'tdpl_auto_links_stats_data' );
	}

	/**
	 * Get the list of titles and links (Using Cache Transient)
	 */
	private function get_post_titles_data() {
		$data = get_transient( $this->cache_key );

		if ( false === $data ) {
			$data = [];
			
			$options = get_option( 'tdpl_ail_settings', [] );
			$post_types = ! empty( $options['post_types'] ) ? $options['post_types'] : [ 'post' ];
			$exclude_ids = ! empty( $options['exclude_posts'] ) ? array_map( 'intval', explode( ',', $options['exclude_posts'] ) ) : [];
			$min_length = ! empty( $options['min_title_length'] ) ? intval( $options['min_title_length'] ) : 4;

			$posts = get_posts( [
				'numberposts' => -1, // Get all
				'post_type'   => $post_types,
				'post_status' => 'publish',
				'exclude'     => $exclude_ids,
			] );

			foreach ( $posts as $p ) {
				$title = trim( $p->post_title );
				// Only get titles according to the length configuration
				if ( mb_strlen( $title, 'UTF-8' ) >= $min_length ) {
					$data[ $title ] = get_permalink( $p->ID );
				}
			}

			// Sort the array by title length (longest first) so that Regex is not inserted incorrectly
			uksort( $data, function( $a, $b ) {
				return mb_strlen( $b, 'UTF-8' ) - mb_strlen( $a, 'UTF-8' );
			} );

			// Cache for 12 hours
			set_transient( $this->cache_key, $data, 12 * HOUR_IN_SECONDS );
		}

		return $data;
	}

	/**
	 * Handle automatic insertion of links into content
	 */
	public function auto_link_content( $content ) {
		// Only run on detail posts, in the main query and in the loop
		if ( !is_singular() || !is_main_query() || !in_the_loop() ) {
			return $content;
		}

		$options = get_option( 'tdpl_ail_settings', [] );
		$exclude_ids = ! empty( $options['exclude_posts'] ) ? array_map( 'intval', explode( ',', $options['exclude_posts'] ) ) : [];
		
		// Do not link excluded posts
		if ( in_array( get_the_ID(), $exclude_ids ) ) {
			return $content;
		}

		$post_titles = $this->get_post_titles_data();
		if ( empty( $post_titles ) ) {
			return $content;
		}

		// Remove the current post from the list (Don't link itself)
		$current_title = trim( get_the_title() );
		if ( isset( $post_titles[ $current_title ] ) ) {
			unset( $post_titles[ $current_title ] );
		}
		
		// Split HTML into text nodes and HTML tag nodes to avoid incorrectly replacing HTML code
		$chunks = wp_html_split( $content );
		
		$linked_urls = []; // Array to store inserted URLs (to meet the requirement: insert only once)
		$ignore_tags = [ 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'script', 'style', 'pre', 'code', 'button', 'iframe' ];
		$is_ignored  = false;

		foreach ( $chunks as &$chunk ) {
			// If it's an HTML tag
			if ( strpos( $chunk, '<' ) === 0 ) {
				if ( preg_match( '/^<(\/)?([a-zA-Z0-9]+)/', $chunk, $tag_match ) ) {
					$tag_name   = strtolower( $tag_match[2] );
					$is_closing = ( $tag_match[1] === '/' );
					
					if ( in_array( $tag_name, $ignore_tags, true ) ) {
						// Start ignoring region if it's an opening tag, end if it's a closing tag
						$is_ignored = ! $is_closing; 
					}
				}
				continue;
			}

			// If inside a restricted region (like <a>, <h1>...), skip this text chunk
			if ( $is_ignored ) {
				continue;
			}

			// If it's a normal text chunk, search for titles
			foreach ( $post_titles as $title => $url ) {
				// If this URL has already been linked in the post, skip it (link only once)
				if ( in_array( $url, $linked_urls, true ) ) {
					continue;
				}

				// Check case-sensitive configuration
				$case_sensitive = ! empty( $options['case_sensitive'] ) ? true : false;
				$regex_modifier = $case_sensitive ? 'u' : 'iu';

				// Regex: Find exact phrase (Unicode)
				// Use lookbehind and lookahead to ensure matching the exact word, not joined characters.
				$pattern = '/(^|[^\p{L}\p{N}])(' . preg_quote( $title, '/' ) . ')(?=[^\p{L}\p{N}]|$)/' . $regex_modifier;

				// Overwrite only once in the current chunk
				$chunk = preg_replace_callback( $pattern, function( $matches ) use ( $url, &$linked_urls ) {
					// Mark this URL as linked
					$linked_urls[] = $url;
					
					// $matches[1] is the preceding character (punctuation, whitespace...)
					// $matches[2] is the original title phrase in the post (preserving original case)
					return $matches[1] . '<a href="' . esc_url( $url ) . '" class="auto-internal-link" title="' . esc_attr( $matches[2] ) . '">' . $matches[2] . '</a>';

				}, $chunk, 1, $count );
                
                // If regex worked and linked, $linked_urls array has been appended inside callback,
                // we will continue searching for other titles.
			}
		}

		// Reassemble into complete HTML content
		return implode( '', $chunks );
	}

	/**
	 * Initialize Admin Menu
	 */
    public function add_admin_menu() {
        add_menu_page(
            __( 'Auto Links Statistics', 'auto-internal-links' ),
            __( 'Auto Links', 'auto-internal-links' ),
            'manage_options',
            'auto-internal-links',
            [ $this, 'admin_statistics_page' ],
            'dashicons-admin-links'
        );

        // Add "Statistics" menu and use the parent menu's slug to make it the main page, replacing the default menu.
        add_submenu_page(
            'auto-internal-links',
            __( 'Statistics', 'auto-internal-links' ),
            __( 'Statistics', 'auto-internal-links' ),
            'manage_options',
            'auto-internal-links', // Dùng lại slug của menu cha
            [ $this, 'admin_statistics_page' ]
        );

        add_submenu_page(
            'auto-internal-links',
            __( 'Settings', 'auto-internal-links' ),
            __( 'Settings', 'auto-internal-links' ),
            'manage_options',
            'auto-internal-links-settings', // Slug mới cho trang cài đặt
            [ $this, 'admin_settings_page' ]
        );
    }

	/**
	 * Register settings fields
	 */
	public function register_settings() {
		register_setting(
			'tdpl_ail_settings_group',
			'tdpl_ail_settings',
			[ $this, 'sanitize_settings' ]
		);

		add_settings_section(
			'tdpl_ail_general_section',
			__( 'General Settings', 'auto-internal-links' ),
			null,
			'auto-internal-links-settings' // Cập nhật slug tương ứng với menu Cài đặt
		);

		add_settings_field( 'post_types', __( 'Apply to post types', 'auto-internal-links' ), [ $this, 'field_post_types_html' ], 'auto-internal-links-settings', 'tdpl_ail_general_section' );
		add_settings_field( 'exclude_posts', __( 'Exclude posts (ID)', 'auto-internal-links' ), [ $this, 'field_exclude_posts_html' ], 'auto-internal-links-settings', 'tdpl_ail_general_section' );
		add_settings_field( 'min_title_length', __( 'Minimum title length', 'auto-internal-links' ), [ $this, 'field_min_title_length_html' ], 'auto-internal-links-settings', 'tdpl_ail_general_section' );
		add_settings_field( 'case_sensitive', __( 'Case sensitive', 'auto-internal-links' ), [ $this, 'field_case_sensitive_html' ], 'auto-internal-links-settings', 'tdpl_ail_general_section' );
	}

	/**
	 * Process and sanitize settings data before saving
	 */
	public function sanitize_settings( $input ) {
		$sanitized_input = [];

		$sanitized_input['post_types'] = isset( $input['post_types'] ) ? array_map( 'sanitize_text_field', $input['post_types'] ) : [];

		if ( isset( $input['exclude_posts'] ) ) {
			$ids = array_map( 'absint', explode( ',', $input['exclude_posts'] ) );
			$ids = array_filter( $ids );
			$sanitized_input['exclude_posts'] = implode( ',', $ids );
		}

		if ( isset( $input['min_title_length'] ) ) {
			$sanitized_input['min_title_length'] = absint( $input['min_title_length'] );
		}

		$sanitized_input['case_sensitive'] = isset( $input['case_sensitive'] ) ? 1 : 0;

		return $sanitized_input;
	}

	/**
	 * Render HTML for settings fields
	 */
	public function field_post_types_html() {
		$options             = get_option( 'tdpl_ail_settings', [] );
		$selected_post_types = ! empty( $options['post_types'] ) ? $options['post_types'] : [ 'post' ];
		$post_types          = get_post_types( [ 'public' => true ], 'objects' );

		foreach ( $post_types as $post_type ) {
			$is_checked = in_array( $post_type->name, $selected_post_types, true );
			?>
			<label>
				<input type="checkbox" name="tdpl_ail_settings[post_types][]" value="<?php echo esc_attr( $post_type->name ); ?>" <?php checked( $is_checked ); ?>>
				<?php echo esc_html( $post_type->labels->name ); ?>
			</label><br>
			<?php
		}
	}

	public function field_exclude_posts_html() {
		$options = get_option( 'tdpl_ail_settings', [] );
		$value   = isset( $options['exclude_posts'] ) ? $options['exclude_posts'] : '';
		?>
		<input type="text" name="tdpl_ail_settings[exclude_posts]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Enter post IDs, separated by commas (e.g., 1, 2, 3).', 'auto-internal-links' ); ?></p>
		<?php
	}

	public function field_min_title_length_html() {
		$options = get_option( 'tdpl_ail_settings', [] );
		$value   = isset( $options['min_title_length'] ) ? intval( $options['min_title_length'] ) : 4;
		?>
		<select name="tdpl_ail_settings[min_title_length]">
			<?php for ( $i = 2; $i <= 10; $i ++ ) : ?>
				<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $value, $i ); ?>>
					<?php echo esc_html( $i ); ?> <?php esc_html_e( 'characters', 'auto-internal-links' ); ?>
				</option>
			<?php endfor; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Shorter titles will not be used as keywords.', 'auto-internal-links' ); ?></p>
		<?php
	}

	public function field_case_sensitive_html() {
		$options = get_option( 'tdpl_ail_settings', [] );
		$is_checked = isset( $options['case_sensitive'] ) && $options['case_sensitive'];
		?>
		<label>
			<input type="checkbox" name="tdpl_ail_settings[case_sensitive]" value="1" <?php checked( $is_checked ); ?> />
			<?php esc_html_e( 'Enable to only add links when the case exactly matches the title.', 'auto-internal-links' ); ?>
		</label>
		<?php
	}
	
	/**
	 * Load Statistics View
	 */
	public function admin_statistics_page() {
		if ( file_exists( TDPL_AIL_PLUGIN_DIR . 'admin/statistics.php' ) ) {
			include_once TDPL_AIL_PLUGIN_DIR . 'admin/statistics.php';
		}
	}

    /**
	 * Load Settings View
	 */
	public function admin_settings_page() {
		if ( file_exists( TDPL_AIL_PLUGIN_DIR . 'admin/settings.php' ) ) {
			include_once TDPL_AIL_PLUGIN_DIR . 'admin/settings.php';
		}
	}

	/**
	 * Handles the request to refresh statistics data.
	 * Runs on admin_init to ensure no output is sent before redirect.
	 */
	public function handle_refresh_stats() {
		if ( isset( $_GET['page'], $_GET['refresh_stats'] ) && 'auto-internal-links' === $_GET['page'] && '1' === $_GET['refresh_stats'] ) {
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'refresh_ail_stats' ) ) {
				wp_die( esc_html__( 'Security check failed. Please refresh the page and try again.', 'auto-internal-links' ) );
			}

			// Reset tiến trình quét
			delete_option( 'tdpl_auto_links_stats_data' );
			delete_option( 'tdpl_ail_temp_stats' );
			update_option( 'tdpl_ail_scan_offset', 0, false );

			wp_safe_redirect( admin_url( 'admin.php?page=auto-internal-links' ) );
			exit;
		}
	}

	/**
	 * Tạo mốc thời gian Cron chạy mỗi 5 phút
	 */
	public function add_cron_interval( $schedules ) {
		$schedules['tdpl_ail_5_min'] = [
			'interval' => 300,
			'display'  => __( 'Every 5 Minutes', 'auto-internal-links' )
		];
		return $schedules;
	}

	/**
	 * Tiến trình quét ngầm chạy theo Batch
	 */
	public function process_batch_scan() {
		$batch_size = 150; // Quét 150 bài mỗi 5 phút để tránh timeout (có thể tăng lên nếu server khỏe)
		$offset     = (int) get_option( 'tdpl_ail_scan_offset', false );
		
		// Nếu offset là false, nghĩa là tiến trình quét đang không được kích hoạt
		if ( $offset === false ) {
			return; 
		}

		$temp_stats = get_option( 'tdpl_ail_temp_stats', [] );
		$options    = get_option( 'tdpl_ail_settings', [] );
		
		$post_types     = ! empty( $options['post_types'] ) ? $options['post_types'] : [ 'post' ];
		$exclude_ids    = ! empty( $options['exclude_posts'] ) ? array_map( 'intval', explode( ',', $options['exclude_posts'] ) ) : [];
		$case_sensitive = ! empty( $options['case_sensitive'] ) ? true : false;
		$regex_modifier = $case_sensitive ? 'u' : 'iu';

		// Tái sử dụng hàm lấy keywords từ cache của bạn
		$keywords = $this->get_post_titles_data();
		if ( empty( $keywords ) ) {
			update_option( 'tdpl_ail_scan_offset', false, false ); // Dừng quét
			return;
		}

		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		// Chỉ lấy một lượng bài viết nhất định (LIMIT & OFFSET)
		$query = $wpdb->prepare(
			"SELECT ID, post_title, post_content FROM {$wpdb->posts} 
			 WHERE post_status = 'publish' AND post_type IN ($placeholders) 
			 ORDER BY ID ASC LIMIT %d OFFSET %d",
			array_merge( $post_types, [ $batch_size, $offset ] )
		);

		$posts = $wpdb->get_results( $query );

		// Nếu không còn bài viết nào để lấy -> ĐÃ QUÉT XONG
		if ( empty( $posts ) ) {
			// Lọc các keyword không có link
			$temp_stats = array_filter( $temp_stats, function( $data ) {
				return count( $data ) > 0;
			} );
			
			// Sắp xếp keyword nhiều link nhất lên đầu
			uasort( $temp_stats, function( $a, $b ) {
				return count( $b ) - count( $a );
			} );

			// Lưu thành dữ liệu chính thức và tắt autoload để không làm nặng web
			update_option( 'tdpl_auto_links_stats_data', $temp_stats, false );
			
			// Dọn dẹp dữ liệu tạm và dừng tiến trình
			delete_option( 'tdpl_ail_temp_stats' );
			update_option( 'tdpl_ail_scan_offset', false, false );
			return;
		}

		// Xử lý quét các bài viết trong batch (Logic cũ của bạn)
		foreach ( $posts as $p ) {
			if ( in_array( (int)$p->ID, $exclude_ids ) ) continue;

			$content = $p->post_content;
			if ( empty( $content ) ) continue;

			$current_title = trim( $p->post_title );
			$chunks        = wp_html_split( $content );
			$linked_urls   = [];
			$ignore_tags   = [ 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'script', 'style', 'pre', 'code', 'button', 'iframe' ];
			$is_ignored    = false;

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
					if ( $title === $current_title ) continue;
					if ( in_array( $url, $linked_urls, true ) ) continue;

					$search_func = $case_sensitive ? 'mb_strpos' : 'mb_stripos';
					if ( $search_func( $chunk, $title, 0, 'UTF-8' ) !== false ) {
						$pattern = '/(^|[^\p{L}\p{N}])(' . preg_quote( $title, '/' ) . ')(?=[^\p{L}\p{N}]|$)/' . $regex_modifier;
						
						if ( preg_match( $pattern, $chunk ) ) {
							$linked_urls[] = $url;
							
							if ( ! isset( $temp_stats[ $title ] ) ) {
								$temp_stats[ $title ] = [];
							}

							$temp_stats[ $title ][] = [
								'id'        => $p->ID,
								'title'     => $current_title,
								'edit_link' => admin_url( 'post.php?post=' . $p->ID . '&action=edit' ),
								'view_link' => get_permalink( $p->ID )
							];
						}
					}
				}
			}
		}

		// Lưu tiến trình cho lượt chạy tiếp theo
		update_option( 'tdpl_ail_temp_stats', $temp_stats, false );
		update_option( 'tdpl_ail_scan_offset', $offset + $batch_size, false );
	}
}

// Initialize Plugin
new TDPL_Auto_Internal_Links();

/* Donate */
add_action( 'admin_enqueue_scripts', 'auto_link_enqueue_admin_scripts' );
function auto_link_enqueue_admin_scripts( $hook_suffix ) {
	
	$is_plugins_page  = ( 'plugins.php' === $hook_suffix );

	if ( $is_plugins_page ) {
		$donate_css = "
            .err-donate-link {
                font-weight: bold;
                background: linear-gradient(90deg, #0066ff, #00a1ff, rgb(255, 0, 179), #0066ff);
                background-size: 200% auto;
                color: #fff;
                -webkit-background-clip: text;
                -moz-background-clip: text;
                background-clip: text;
                -webkit-text-fill-color: transparent;
                animation: alphaGradientText 2s linear infinite;
            }
            @keyframes errGradientText {
                to { background-position: -200% center; }
            }";
		wp_add_inline_style( 'wp-admin', $donate_css );
	}
}
function auto_link_donate_link_html() {
	$donate_url = 'https://err-mouse.id.vn/donate';
	printf(
		'<a href="%1$s" target="_blank" rel="noopener noreferrer" class="err-donate-link" aria-label="%2$s"><span>%3$s 🚀</span></a>',
		esc_url( $donate_url ),
		esc_attr__( 'Donate to support this plugin', 'auto-internal-links' ), //
		esc_html__( 'Donate', 'auto-internal-links' ) //
	);
}

add_filter( 'plugin_row_meta', 'auto_link_plugin_row_meta', 10, 2 );
function auto_link_plugin_row_meta( $links, $file ) {
	if ( plugin_basename( __FILE__ ) === $file ) {
		ob_start();
		auto_link_donate_link_html();
		$links['donate'] = ob_get_clean();
	}
	return $links;
}