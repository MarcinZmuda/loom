<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Loom_Linker {

	/**
	 * AJAX: apply approved link suggestions to post_content.
	 */
	public static function ajax_apply_links() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Brak uprawnień.', 'loom' ), 403 );
		}

		$post_id      = absint( $_POST['post_id'] ?? 0 );
		$content_hash = sanitize_text_field( $_POST['content_hash'] ?? '' );
		$links_json   = wp_unslash( $_POST['links'] ?? '[]' );
		$links        = json_decode( $links_json, true );

		if ( ! $post_id || empty( $links ) ) {
			wp_send_json_error( __( 'Brak danych.', 'loom' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Brak uprawnień do edycji tego posta.', 'loom' ), 403 );
		}

		// Race condition check.
		$post    = get_post( $post_id );
		$current = md5( $post->post_content );
		if ( $current !== $content_hash ) {
			wp_send_json_error( __( 'Treść posta została zmieniona. Kliknij „Podlinkuj" ponownie.', 'loom' ) );
		}

		// Backup original content.
		update_post_meta( $post_id, '_loom_content_backup', $post->post_content );
		update_post_meta( $post_id, '_loom_backup_time', current_time( 'mysql' ) );

		$content  = $post->post_content;
		$inserted = 0;

		foreach ( $links as $link ) {
			$anchor = $link['anchor_text'] ?? '';
			$url    = $link['target_url']  ?? '';

			if ( empty( $anchor ) || empty( $url ) ) continue;

			$new_content = self::insert_link( $content, $anchor, $url, intval( $link["paragraph_number"] ?? 0 ) );
			if ( $new_content !== $content ) {
				$content = $new_content;
				$inserted++;

				// Record in loom_links.
				$target_id = url_to_postid( $url );
				Loom_DB::insert_link( array(
					'source_post_id'      => $post_id,
					'target_post_id'      => $target_id > 0 ? $target_id : 0,
					'anchor_text'         => mb_substr( $anchor, 0, 500 ),
					'link_position'       => $link['surfer_zone'] ?? 'middle',
					'position_percent'    => intval( $link['position_percent'] ?? 50 ),
					'is_plugin_generated' => 1,
					'anchor_match_score'  => isset( $link['match_score'] ) ? floatval( $link['match_score'] ) : null,
				) );
			}
		}

		if ( $inserted > 0 ) {
			// Save without triggering LOOM's save_post hook.
			remove_action( 'save_post', array( 'Loom_Scanner', 'on_save_post' ), 20 );
			remove_action( 'post_updated', 'wp_save_post_revision' );

			wp_update_post( array(
				'ID'           => $post_id,
				'post_content' => $content,
			) );

			add_action( 'post_updated', 'wp_save_post_revision' );
			add_action( 'save_post', array( 'Loom_Scanner', 'on_save_post' ), 20, 3 );

			// Update hash.
			update_post_meta( $post_id, '_loom_content_hash', md5( $content ) );

			// Recalculate counters.
			Loom_DB::recalc_counters_for_post( $post_id );

			Loom_DB::log( 'insert_links', $post_id, array( 'count' => $inserted ) );
		}

		wp_send_json_success( array(
			'inserted' => $inserted,
			'message'  => sprintf(
				/* translators: %d: number of links */
				__( 'Wstawiono %d linków.', 'loom' ),
				$inserted
			),
		) );
	}

	/**
	 * Insert a single link into content.
	 *
	 * @param string $content     Post content (HTML).
	 * @param string $anchor_text Exact text to wrap in <a>.
	 * @param string $target_url  URL to link to.
	 * @return string             Modified content (or unchanged if anchor not found / already linked).
	 */
	/**
	 * Insert a single link into content.
	 *
	 * @param string $content        Post content (HTML).
	 * @param string $anchor_text    Exact text to wrap in <a>.
	 * @param string $target_url     URL to link to.
	 * @param int    $paragraph_hint 1-based paragraph number hint (0 = use first occurrence).
	 * @return string                Modified content (or unchanged if anchor not found / already linked).
	 */
	public static function insert_link( $content, $anchor_text, $target_url, $paragraph_hint = 0 ) {
		// If we have a paragraph hint, try to find the occurrence nearest to that paragraph.
		$pos = false;
		if ( $paragraph_hint > 0 ) {
			// Split content by block-level tags to estimate paragraph positions.
			$parts = preg_split( '/(<\/?(?:p|div|li|blockquote|h[1-6])[^>]*>)/i', $content );
			$char_offset = 0;
			$para_count  = 0;
			$target_offset = 0;
			foreach ( $parts as $part ) {
				if ( preg_match( '/^<(p|div|li|blockquote)\b/i', $part ) ) {
					$para_count++;
				}
				if ( $para_count >= $paragraph_hint && $target_offset === 0 ) {
					$target_offset = $char_offset;
				}
				$char_offset += mb_strlen( $part );
			}
			// Search from the target offset.
			if ( $target_offset > 0 ) {
				$pos = mb_strpos( $content, $anchor_text, max( 0, $target_offset - 200 ) );
			}
		}

		// Fallback: first occurrence.
		if ( $pos === false ) {
			$pos = mb_strpos( $content, $anchor_text );
		}
		if ( $pos === false ) {
			return $content;
		}

		// Check: is anchor already inside an <a> tag?
		$before   = mb_substr( $content, 0, $pos );
		$lower_b  = strtolower( $before );
		$open_a   = substr_count( $lower_b, '<a ' ) + substr_count( $lower_b, '<a>' );
		$close_a  = substr_count( $lower_b, '</a>' );
		if ( $open_a > $close_a ) {
			return $content; // Already inside a link.
		}

		// Check: is anchor inside a heading? (PHP 8 safe  -  strrpos returns false)
		$h_open_positions = array_filter( array(
			strrpos( $lower_b, '<h1' ),
			strrpos( $lower_b, '<h2' ),
			strrpos( $lower_b, '<h3' ),
			strrpos( $lower_b, '<h4' ),
			strrpos( $lower_b, '<h5' ),
			strrpos( $lower_b, '<h6' ),
		), function ( $v ) { return $v !== false; } );

		$h_close_positions = array_filter( array(
			strrpos( $lower_b, '</h1>' ),
			strrpos( $lower_b, '</h2>' ),
			strrpos( $lower_b, '</h3>' ),
			strrpos( $lower_b, '</h4>' ),
			strrpos( $lower_b, '</h5>' ),
			strrpos( $lower_b, '</h6>' ),
		), function ( $v ) { return $v !== false; } );

		$last_h_open  = ! empty( $h_open_positions ) ? max( $h_open_positions ) : false;
		$last_h_close = ! empty( $h_close_positions ) ? max( $h_close_positions ) : false;

		if ( $last_h_open !== false && ( $last_h_close === false || $last_h_open > $last_h_close ) ) {
			return $content; // Inside heading.
		}

		// Build link HTML  -  anchor comes from existing content, no esc_html needed.
		$link_html = '<a href="' . esc_url( $target_url ) . '">' . $anchor_text . '</a>';

		// Replace first occurrence.
		$result = mb_substr( $content, 0, $pos )
		        . $link_html
		        . mb_substr( $content, $pos + mb_strlen( $anchor_text ) );

		return $result;
	}

	/**
	 * AJAX: Auto-Podlinkuj  -  one-click: generate suggestions + auto-apply high/medium.
	 * No user review needed. Used in Bulk mode and dashboard quick-link.
	 */
	public static function ajax_auto_podlinkuj() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Brak uprawnień.', 'loom' ), 403 );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Nieprawidłowy post lub brak uprawnień.', 'loom' ) );
		}

		$api_key = Loom_DB::get_api_key();
		if ( empty( $api_key ) ) {
			wp_send_json_error( __( 'Brak klucza API.', 'loom' ) );
		}

		// ─── Step 1: Get source ───
		$source = Loom_DB::get_index_row( $post_id );
		if ( ! $source ) {
			Loom_Scanner::scan_single_post( $post_id );
			$source = Loom_DB::get_index_row( $post_id );
		}
		if ( ! $source || empty( $source['clean_text'] ) ) {
			wp_send_json_error( __( 'Brak treści posta.', 'loom' ) );
		}

		// ─── Step 2: Embedding ───
		if ( empty( $source['embedding'] ) ) {
			$title  = $source['post_title'];
			$input  = $title . ' | ' . $title . ' | ' . $title
			        . ' | ' . mb_substr( $source['clean_text'], 0, 2500 );
			$vector = Loom_OpenAI::get_embedding( $input, $api_key );
			if ( is_wp_error( $vector ) ) {
				wp_send_json_error( $vector->get_error_message() );
			}
			global $wpdb;
			$wpdb->update( Loom_DB::index_table(), array(
				'embedding'       => wp_json_encode( $vector ),
				'embedding_model' => Loom_OpenAI::EMBED_MODEL,
				'last_embedding'  => current_time( 'mysql' ),
			), array( 'post_id' => $post_id ) );
			$source['embedding'] = wp_json_encode( $vector );
		}

		$source_emb = json_decode( $source['embedding'], true );

		// ─── Step 3: Similarity + Composite ───
		$all_targets = Loom_DB::get_all_with_embeddings( $post_id );
		$similar     = Loom_Similarity::find_similar( $source_emb, $all_targets, 50, 30 );

		if ( empty( $similar ) ) {
			wp_send_json_error( __( 'Brak postów z embeddingami do porównania.', 'loom' ) );
		}

		$ranked = Loom_Composite::rank_targets( $source, $similar, 15 );

		// ─── Step 4: GPT ───
		$system_prompt = Loom_Suggester::get_system_prompt();
		$user_prompt   = Loom_Suggester::build_user_prompt( $source, $ranked );
		$json_schema   = Loom_Suggester::get_json_schema();

		$result = Loom_OpenAI::chat( $system_prompt, $user_prompt, $json_schema, $api_key );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		// ─── Step 5: Validate ───
		$suggestions = $result['suggestions'] ?? array();
		$paragraphs  = Loom_Suggester::split_paragraphs( $source['clean_text'] );
		$validated   = Loom_Suggester::validate_suggestions( $suggestions, $paragraphs, $post_id );
		$analyzed    = Loom_Analyzer::enrich_suggestions( $validated, count( $paragraphs ) );

		// ─── Step 6: Auto-filter -> high + medium only ───
		$auto_links = array();
		foreach ( $analyzed as $s ) {
			if ( in_array( $s['priority'], array( 'high', 'medium' ), true ) ) {
				$auto_links[] = $s;
			}
		}

		if ( empty( $auto_links ) ) {
			wp_send_json_success( array(
				'inserted'    => 0,
				'suggested'   => count( $analyzed ),
				'post_id'     => $post_id,
				'post_title'  => $source['post_title'],
				'message'     => __( 'Brak sugestii o wystarczającym priorytecie.', 'loom' ),
			) );
			return;
		}

		// ─── Step 7: Auto-apply ───
		$post    = get_post( $post_id );
		$content = $post->post_content;

		// Backup.
		update_post_meta( $post_id, '_loom_content_backup', $content );
		update_post_meta( $post_id, '_loom_backup_time', current_time( 'mysql' ) );

		$inserted = 0;
		$link_details = array();

		foreach ( $auto_links as $link ) {
			$anchor = $link['anchor_text'] ?? '';
			$url    = $link['target_url']  ?? '';
			if ( empty( $anchor ) || empty( $url ) ) continue;

			$new_content = self::insert_link( $content, $anchor, $url, intval( $link["paragraph_number"] ?? 0 ) );
			if ( $new_content !== $content ) {
				$content = $new_content;
				$inserted++;

				$target_id = url_to_postid( $url );
				Loom_DB::insert_link( array(
					'source_post_id'      => $post_id,
					'target_post_id'      => $target_id > 0 ? $target_id : 0,
					'anchor_text'         => mb_substr( $anchor, 0, 500 ),
					'link_position'       => $link['surfer_zone'] ?? 'middle',
					'position_percent'    => intval( $link['position_percent'] ?? 50 ),
					'is_plugin_generated' => 1,
				) );

				$link_details[] = array(
					'anchor' => $anchor,
					'target' => $link['target_title'] ?? $url,
					'zone'   => $link['surfer_label'] ?? '',
				);
			}
		}

		if ( $inserted > 0 ) {
			remove_action( 'save_post', array( 'Loom_Scanner', 'on_save_post' ), 20 );
			remove_action( 'post_updated', 'wp_save_post_revision' );

			wp_update_post( array(
				'ID'           => $post_id,
				'post_content' => $content,
			) );

			add_action( 'post_updated', 'wp_save_post_revision' );
			add_action( 'save_post', array( 'Loom_Scanner', 'on_save_post' ), 20, 3 );

			update_post_meta( $post_id, '_loom_content_hash', md5( $content ) );
			Loom_DB::recalc_counters_for_post( $post_id );
			Loom_DB::log( 'auto_insert_links', $post_id, array(
				'count'   => $inserted,
				'details' => $link_details,
			) );
		}

		wp_send_json_success( array(
			'inserted'    => $inserted,
			'suggested'   => count( $analyzed ),
			'skipped'     => count( $analyzed ) - count( $auto_links ),
			'post_id'     => $post_id,
			'post_title'  => $source['post_title'],
			'links'       => $link_details,
			'message'     => sprintf(
				/* translators: %1$d: inserted, %2$d: total */
				__( 'Wstawiono %1$d z %2$d sugestii (high + medium).', 'loom' ),
				$inserted,
				count( $analyzed )
			),
		) );
	}

	/**
	 * AJAX: Remove ALL links inserted by LOOM across the entire site.
	 */
	public static function ajax_remove_all_loom_links() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Brak uprawnień.', 'loom' ), 403 );
		}

		global $wpdb;
		$lnk = Loom_DB::links_table();

		$loom_links = $wpdb->get_results(
			"SELECT id, source_post_id, anchor_text, target_post_id
			 FROM {$lnk} WHERE is_plugin_generated = 1
			 ORDER BY source_post_id", ARRAY_A
		);

		if ( empty( $loom_links ) ) {
			wp_send_json_success( array( 'removed' => 0, 'posts' => 0,
				'message' => __( 'Brak linków LOOM do usunięcia.', 'loom' ) ) );
		}

		$by_post = array();
		foreach ( $loom_links as $link ) {
			$by_post[ intval( $link['source_post_id'] ) ][] = $link;
		}

		$removed_total = 0;
		$posts_fixed   = 0;
		remove_action( 'save_post', array( 'Loom_Scanner', 'on_save_post' ), 20 );

		foreach ( $by_post as $post_id => $links ) {
			$post = get_post( $post_id );
			if ( ! $post ) continue;

			update_post_meta( $post_id, '_loom_content_backup', $post->post_content );

			$content = $post->post_content;
			$removed = 0;

			foreach ( $links as $link ) {
				$anchor = $link['anchor_text'];
				if ( empty( $anchor ) ) continue;

				$escaped = preg_quote( $anchor, '/' );
				// Match <a ...>anchor</a> — also handles nested tags like <strong>anchor</strong>.
				$pattern = '/<a\s[^>]*>(?:<[^>]*>)*' . $escaped . '(?:<\/[^>]*>)*<\/a>/i';
				$new     = preg_replace( $pattern, $anchor, $content, 1, $count );
				if ( $count > 0 ) { $content = $new; $removed++; }
			}

			if ( $removed > 0 ) {
				wp_update_post( array( 'ID' => $post_id, 'post_content' => $content ) );
				$posts_fixed++;
				$removed_total += $removed;
			}

			$link_ids = wp_list_pluck( $links, 'id' );
			$ids_str  = implode( ',', array_map( 'intval', $link_ids ) );
			$wpdb->query( "DELETE FROM {$lnk} WHERE id IN ({$ids_str})" ); // phpcs:ignore

			Loom_DB::recalc_counters_for_post( $post_id );
			foreach ( $links as $link ) {
				$tid = intval( $link['target_post_id'] );
				if ( $tid > 0 ) Loom_DB::recalc_counters_for_post( $tid );
			}
		}

		add_action( 'save_post', array( 'Loom_Scanner', 'on_save_post' ), 20, 3 );

		Loom_DB::log( 'remove_all_loom_links', null, array(
			'removed' => $removed_total, 'posts' => $posts_fixed,
		) );

		wp_send_json_success( array(
			'removed' => $removed_total,
			'posts'   => $posts_fixed,
			'message' => sprintf( __( 'Usunięto %d linków LOOM z %d postów.', 'loom' ), $removed_total, $posts_fixed ),
		) );
	}
}
