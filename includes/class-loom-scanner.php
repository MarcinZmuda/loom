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
				$is_broken = 1;
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
		if ( empty( $settings['rescan_on_save'] ) ) return;
		if ( ! in_array( $post->post_type, $settings['post_types'], true ) ) return;

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
}
