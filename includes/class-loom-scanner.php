<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Loom_Scanner {

	/**
	 * AJAX: batch scan posts for onboarding / re-scan.
	 */
	public static function ajax_batch_scan() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions', 403 );
		}

		$offset     = absint( $_POST['offset'] ?? 0 );
		$batch_size = 20;
		$settings   = Loom_DB::get_settings();
		$types      = $settings['post_types'];
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		global $wpdb;
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})",
			...$types
		) );

		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_name, post_content, post_type
			 FROM {$wpdb->posts}
			 WHERE post_status = 'publish' AND post_type IN ({$placeholders})
			 ORDER BY ID ASC LIMIT %d OFFSET %d",
			...array_merge( $types, array( $batch_size, $offset ) )
		) );

		// Disable oEmbed during scanning to avoid external HTTP requests.
		if ( isset( $GLOBALS['wp_embed'] ) ) {
			remove_filter( 'the_content', array( $GLOBALS['wp_embed'], 'autoembed' ), 8 );
		}

		foreach ( $posts as $post ) {
			self::scan_single_post( $post );
		}

		if ( isset( $GLOBALS['wp_embed'] ) ) {
			add_filter( 'the_content', array( $GLOBALS['wp_embed'], 'autoembed' ), 8 );
		}

		$new_offset = $offset + count( $posts );

		if ( $new_offset >= $total ) {
			Loom_DB::recalc_counters();
			Loom_Keywords::build_df_cache();
			Loom_Site_Analysis::calculate_click_depths();
			Loom_Graph::analyze();
			Loom_DB::log_orphan_trend();
			update_option( 'loom_scan_completed', true );
		}

		wp_send_json_success( array(
			'processed' => $new_offset,
			'total'     => $total,
			'status'    => $new_offset >= $total ? 'complete' : 'next',
			'offset'    => $new_offset,
		) );
	}

	/**
	 * Scan a single post: render, clean, parse links, save to index.
	 */
	public static function scan_single_post( $post ) {
		if ( is_numeric( $post ) ) {
			$post = get_post( $post );
		}
		if ( ! $post ) return;

		// Render content (Gutenberg blocks, shortcodes).
		try {
			$rendered = apply_filters( 'the_content', $post->post_content );
		} catch ( \Throwable $e ) {
			$rendered = $post->post_content; // Fallback to raw content.
			Loom_DB::log( 'scan_error', $post->ID, array( 'error' => $e->getMessage() ) );
		}
		$clean_text = wp_strip_all_tags( $rendered );
		$clean_text = preg_replace( '/\s+/', ' ', trim( $clean_text ) );
		$word_count = str_word_count( $clean_text );

		// Save to index.
		Loom_DB::upsert_index( array(
			'post_id'         => $post->ID,
			'post_title'      => $post->post_title,
			'post_url'        => get_permalink( $post->ID ),
			'post_type'       => $post->post_type,
			'clean_text'      => $clean_text,
			'clean_text_hash' => md5( $post->post_content ),
			'word_count'      => $word_count,
		) );

		// Parse internal links.
		self::parse_links( $post->ID, $rendered );

		// Extract keywords (layer 1+2 only  -  fast, no API cost).
		Loom_Keywords::extract( $post->ID, false );
	}

	/**
	 * Parse internal links from rendered HTML.
	 */
	private static $url_cache = array();

	private static function parse_links( $post_id, $html ) {
		// Clear existing links from this source.
		Loom_DB::delete_links_for_post( $post_id );

		if ( empty( $html ) ) return;

		$dom = new DOMDocument( '1.0', 'UTF-8' );
		@$dom->loadHTML(
			'<?xml encoding="UTF-8"><div>' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
		);

		$anchors    = $dom->getElementsByTagName( 'a' );
		$home_host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$full_text  = wp_strip_all_tags( $html );
		$text_len   = mb_strlen( $full_text );

		foreach ( $anchors as $a ) {
			$href = $a->getAttribute( 'href' );
			if ( empty( $href ) ) continue;

			// Internal only.
			$parsed = wp_parse_url( $href );
			$link_host = $parsed['host'] ?? '';
			if ( ! empty( $link_host ) && $link_host !== $home_host ) continue;

			// Skip non-content URLs (prevents false broken links).
			$path = $parsed['path'] ?? '';
			if ( preg_match( '#^/(wp-admin|wp-login|wp-content|wp-includes|wp-json|feed|comments|xmlrpc)(/|$|\?)#i', $path ) ) continue;
			if ( preg_match( '#\.(css|js|jpg|jpeg|png|gif|svg|webp|pdf|xml|json|ico|woff|woff2|ttf|eot)(\?|$)#i', $path ) ) continue;
			if ( $href === '#' || strpos( $href, '#' ) === 0 || strpos( $href, 'javascript:' ) === 0 || strpos( $href, 'mailto:' ) === 0 || strpos( $href, 'tel:' ) === 0 ) continue;

			// Resolve relative URLs.
			if ( empty( $link_host ) && isset( $parsed['path'] ) ) {
				$href = home_url( $parsed['path'] );
			}

			// Cached url_to_postid (PERF-5).
			if ( ! isset( self::$url_cache[ $href ] ) ) {
				self::$url_cache[ $href ] = url_to_postid( $href );
			}
			$target_id   = self::$url_cache[ $href ];
			$anchor_text = trim( $a->textContent );
			$rel         = $a->getAttribute( 'rel' );
			$is_nofollow = strpos( $rel, 'nofollow' ) !== false ? 1 : 0;

			// Check if broken.
			$is_broken = 0;
			if ( $target_id === 0 ) {
				// Before marking broken, check if it's a valid WP URL that isn't a post/page.
				$is_known_url = false;
				$url_path = trim( wp_parse_url( $href, PHP_URL_PATH ) ?: '', '/' );

				if ( ! empty( $url_path ) ) {
					// 1. Category by slug (handles clean URLs like /technologie).
					if ( get_category_by_slug( $url_path ) ) $is_known_url = true;
					if ( ! $is_known_url && get_category_by_slug( basename( $url_path ) ) ) $is_known_url = true;

					// 2. Tag by slug.
					if ( ! $is_known_url && get_term_by( 'slug', $url_path, 'post_tag' ) ) $is_known_url = true;
					if ( ! $is_known_url && get_term_by( 'slug', basename( $url_path ), 'post_tag' ) ) $is_known_url = true;

					// 3. Custom taxonomies (all public).
					if ( ! $is_known_url ) {
						$slug = basename( $url_path );
						foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
							if ( get_term_by( 'slug', $slug, $tax ) ) { $is_known_url = true; break; }
						}
					}

					// 4. Nested page slug.
					if ( ! $is_known_url && get_page_by_path( $url_path ) ) $is_known_url = true;

					// 5. Author archive (/author/slug or custom author base).
					if ( ! $is_known_url ) {
						$parts = explode( '/', $url_path );
						if ( count( $parts ) >= 2 && $parts[0] === 'author' ) {
							if ( get_user_by( 'slug', $parts[1] ) ) $is_known_url = true;
						}
						// Also check if the full path is an author slug (custom author base).
						if ( ! $is_known_url && get_user_by( 'slug', basename( $url_path ) ) ) $is_known_url = true;
					}

					// 6. Post type archive (e.g. /portfolio, /events).
					if ( ! $is_known_url ) {
						foreach ( get_post_types( array( 'public' => true, 'has_archive' => true ), 'objects' ) as $pt ) {
							$archive_slug = $pt->has_archive === true ? $pt->rewrite['slug'] ?? $pt->name : $pt->has_archive;
							if ( $archive_slug && trim( $archive_slug, '/' ) === $url_path ) { $is_known_url = true; break; }
						}
					}

					// 7. Homepage / blog page.
					if ( ! $is_known_url && ( $url_path === '' || $href === home_url( '/' ) || $href === home_url() ) ) {
						$is_known_url = true;
					}

					// 8. Date archives (/2024/, /2024/01/).
					if ( ! $is_known_url && preg_match( '#^\d{4}(/\d{2})?(/\d{2})?$#', $url_path ) ) {
						$is_known_url = true;
					}
				}

				if ( ! $is_known_url ) {
					$is_broken = 1;
				}
			} else {
				$status = get_post_status( $target_id );
				if ( $status !== 'publish' ) {
					$is_broken = 1;
				}
			}

			// Calculate position.
			$anchor_pos = mb_strpos( $full_text, $anchor_text );
			$percent    = ( $text_len > 0 && $anchor_pos !== false )
				? round( ( $anchor_pos / $text_len ) * 100 )
				: 50;

			if ( $percent <= 30 ) {
				$position = 'top';
			} elseif ( $percent <= 70 ) {
				$position = 'middle';
			} else {
				$position = 'bottom';
			}

			Loom_DB::insert_link( array(
				'source_post_id'    => $post_id,
				'target_post_id'    => $target_id > 0 ? $target_id : 0,
				'target_url'        => mb_substr( $href, 0, 500 ),
				'anchor_text'       => mb_substr( $anchor_text, 0, 500 ),
				'link_position'     => $position,
				'position_percent'  => min( 100, max( 0, $percent ) ),
				'is_plugin_generated' => 0,
				'is_broken'         => $is_broken,
				'is_nofollow'       => $is_nofollow,
			) );
		}
	}

	/**
	 * Hook: save_post  -  re-scan if content changed.
	 */
	public static function on_save_post( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( wp_is_post_revision( $post_id ) ) return;
		if ( $post->post_status !== 'publish' ) return;

		$settings = Loom_DB::get_settings();
		if ( ! in_array( $post->post_type, $settings['post_types'], true ) ) return;

		// v2.4: Publish-time orphan alert for new posts (or posts with 0 IN).
		if ( ! $update || ! get_post_meta( $post_id, '_loom_content_hash', true ) ) {
			global $wpdb;
			$idx = Loom_DB::index_table();
			$in  = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT incoming_links_count FROM {$idx} WHERE post_id = %d", $post_id
			) );
			if ( $in === 0 ) {
				set_transient( 'loom_orphan_alert_' . $post_id, true, 60 );
				add_action( 'admin_notices', function() use ( $post_id ) {
					if ( get_transient( 'loom_orphan_alert_' . $post_id ) ) {
						$title = get_the_title( $post_id );
						echo '<div class="notice notice-warning is-dismissible"><p>';
						echo '<strong>🕸️ LOOM:</strong> ';
						printf(
							/* translators: %s: post title */
							esc_html__( '„%s" nie ma linków przychodzących (orphan). Otwórz LOOM aby wygenerować sugestie linkowania.', 'loom' ),
							esc_html( $title )
						);
						echo '</p></div>';
						delete_transient( 'loom_orphan_alert_' . $post_id );
					}
				} );
			}
		}

		if ( empty( $settings['rescan_on_save'] ) ) return;

		$old_hash = get_post_meta( $post_id, '_loom_content_hash', true );
		$new_hash = md5( $post->post_content );
		if ( $old_hash === $new_hash ) return;

		// Prevent infinite loop.
		remove_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20 );

		self::scan_single_post( $post );
		Loom_DB::recalc_counters_for_post( $post_id );
		update_post_meta( $post_id, '_loom_content_hash', $new_hash );

		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 3 );
	}

	/**
	 * Hook: before_delete_post  -  clean up index.
	 */
	public static function on_delete_post( $post_id ) {
		Loom_DB::delete_index( $post_id );
		Loom_DB::delete_links_involving_post( $post_id );
	}

	/**
	 * v2.4: WP Cron weekly rescan  -  recalc counters, graph, orphan trend.
	 */
	public static function cron_weekly_rescan() {
		if ( ! get_option( 'loom_scan_completed' ) ) return;

		Loom_DB::recalc_counters();
		Loom_Graph::analyze();
		Loom_Site_Analysis::calculate_click_depths();
		Loom_DB::log_orphan_trend();

		Loom_DB::log( 'cron_rescan', null, array( 'type' => 'weekly' ) );
	}
}
