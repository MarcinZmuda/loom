<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Loom_Site_Analysis {

	public static function calculate_click_depths() {
		global $wpdb;
		$idx = Loom_DB::index_table();
		$lnk = Loom_DB::links_table();
		$homepage_id = intval( get_option( 'page_on_front', 0 ) );
		$wpdb->query( "UPDATE {$idx} SET click_depth = NULL" );
		if ( ! $homepage_id ) $homepage_id = intval( get_option( 'page_for_posts', 0 ) );
		if ( $homepage_id ) $wpdb->update( $idx, array( 'click_depth' => 0 ), array( 'post_id' => $homepage_id ) );

		for ( $depth = 0; $depth < 10; $depth++ ) {
			$affected = $wpdb->query( $wpdb->prepare(
				"UPDATE {$idx} i
				 JOIN {$lnk} l ON l.target_post_id = i.post_id
				 JOIN {$idx} src ON l.source_post_id = src.post_id AND src.click_depth = %d
				 SET i.click_depth = %d
				 WHERE i.click_depth IS NULL",
				$depth, $depth + 1
			) );
			if ( $affected === 0 ) break;
		}
		self::assign_tiers();
	}

	private static function assign_tiers() {
		global $wpdb;
		$idx = Loom_DB::index_table();
		$wpdb->query( "UPDATE {$idx} SET site_tier = 3" );
		$hid = intval( get_option( 'page_on_front', 0 ) );
		if ( $hid ) $wpdb->update( $idx, array( 'site_tier' => 0 ), array( 'post_id' => $hid ) );
		$wpdb->query( "UPDATE {$idx} SET site_tier = 1 WHERE click_depth = 1 AND post_type = 'page'" );
		$wpdb->query( "UPDATE {$idx} SET site_tier = 2 WHERE site_tier = 3 AND click_depth <= 2 AND incoming_links_count >= 8" );
	}

	public static function check_cannibalization( $anchor_text, $intended_target_id ) {
		global $wpdb;
		$lnk = Loom_DB::links_table();
		$idx = Loom_DB::index_table();
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT l.target_post_id, i.post_title, COUNT(*) AS link_count
			 FROM {$lnk} l JOIN {$idx} i ON l.target_post_id = i.post_id
			 WHERE l.anchor_text = %s AND l.target_post_id != %d AND l.target_post_id > 0
			 GROUP BY l.target_post_id ORDER BY link_count DESC LIMIT 1",
			$anchor_text, $intended_target_id
		), ARRAY_A );
	}

	public static function anchor_distribution( $target_post_id ) {
		global $wpdb;
		$anchors = $wpdb->get_results( $wpdb->prepare(
			"SELECT anchor_text, COUNT(*) AS cnt
			 FROM " . Loom_DB::links_table() . "
			 WHERE target_post_id = %d AND anchor_text != ''
			 GROUP BY anchor_text ORDER BY cnt DESC",
			$target_post_id
		), ARRAY_A );
		$total = array_sum( array_column( $anchors, 'cnt' ) );
		$warnings = array();
		if ( ! empty( $anchors ) && $total >= 3 ) {
			$top_pct = round( ( intval( $anchors[0]['cnt'] ) / $total ) * 100 );
			if ( $top_pct > 50 ) $warnings[] = 'dominant';
		}
		return array( 'total' => $total, 'anchors' => $anchors, 'warnings' => $warnings );
	}

	public static function format_for_prompt( $target_post_id ) {
		$out = '';
		$target_post_id = intval( $target_post_id );
		if ( $target_post_id <= 0 ) return $out;

		$row = Loom_DB::get_index_row( $target_post_id );

		// Keywords.
		if ( ! empty( $row['focus_keywords'] ) ) {
			$kws = json_decode( $row['focus_keywords'], true );
			if ( is_array( $kws ) && ! empty( $kws ) ) {
				$labels = array();
				foreach ( $kws as $k ) {
					$labels[] = '"' . $k['phrase'] . '" (' . $k['type'] . ')';
				}
				$out .= '   🎯 Keywords: ' . implode( ', ', $labels ) . "\n";
			}
		}

		// Anchors.
		$dist = self::anchor_distribution( $target_post_id );
		if ( $dist['total'] > 0 ) {
			$parts = array();
			foreach ( array_slice( $dist['anchors'], 0, 4 ) as $a ) {
				$parts[] = '"' . $a['anchor_text'] . '" (' . $a['cnt'] . 'x)';
			}
			$out .= '   🔗 Existing anchors: ' . implode( ', ', $parts ) . "\n";
			if ( in_array( 'dominant', $dist['warnings'], true ) ) {
				$out .= "   ⚠️ Over-optimized: dominant anchor  -  use different variant\n";
			}
		}

		return $out;
	}

	// ── Rejections ──

	public static function reject_suggestion( $post_id, $target_post_id, $anchor_text ) {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'loom_rejections', array(
			'post_id'        => absint( $post_id ),
			'target_post_id' => absint( $target_post_id ),
			'anchor_text'    => sanitize_text_field( $anchor_text ),
			'rejected_at'    => current_time( 'mysql' ),
		) );
	}

	public static function get_rejected_targets( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'loom_rejections';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return array(); // Table doesn't exist yet.
		}
		return $wpdb->get_col( $wpdb->prepare(
			"SELECT target_post_id FROM {$table}
			 WHERE post_id = %d GROUP BY target_post_id HAVING COUNT(*) >= 3",
			$post_id
		) );
	}

	public static function is_rejected( $post_id, $target_post_id, $anchor_text ) {
		global $wpdb;
		$table = $wpdb->prefix . 'loom_rejections';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			 WHERE post_id = %d AND target_post_id = %d AND anchor_text = %s",
			$post_id, $target_post_id, $anchor_text
		) );
	}

	public static function ajax_reject() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Forbidden', 403 );
		$post_id   = absint( $_POST['post_id'] ?? 0 );
		$target_id = absint( $_POST['target_id'] ?? 0 );
		$anchor    = sanitize_text_field( $_POST['anchor_text'] ?? '' );
		if ( $post_id && $target_id ) {
			self::reject_suggestion( $post_id, $target_id, $anchor );
		}
		wp_send_json_success();
	}

	public static function ajax_recalc_depth() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
		self::calculate_click_depths();
		wp_send_json_success( __( 'Click depth przeliczony.', 'loom' ) );
	}

	/* ===================================================================
	   v2.4: Silo Integrity Check
	   Verifies bidirectional linking within topic clusters.
	   =================================================================== */
	public static function ajax_silo_check() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
		wp_send_json_success( self::silo_integrity() );
	}

	public static function silo_integrity() {
		global $wpdb;
		$cls = $wpdb->prefix . 'loom_clusters';
		$idx = Loom_DB::index_table();
		$lnk = Loom_DB::links_table();

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$cls}'" ) !== $cls ) {
			return array( 'clusters' => array(), 'issues' => 0 );
		}

		$clusters = $wpdb->get_results( "SELECT c.id, c.cluster_name, c.pillar_post_id, i.post_title AS pillar_title
			FROM {$cls} c LEFT JOIN {$idx} i ON c.pillar_post_id = i.post_id", ARRAY_A );

		$results = array();
		$total_issues = 0;

		foreach ( $clusters as $cl ) {
			$pillar_id = intval( $cl['pillar_post_id'] );
			$members   = $wpdb->get_results( $wpdb->prepare(
				"SELECT post_id, post_title FROM {$idx} WHERE cluster_id = %d AND post_id != %d",
				intval( $cl['id'] ), $pillar_id
			), ARRAY_A );

			$issues = array();
			foreach ( $members as $m ) {
				$mid = intval( $m['post_id'] );

				// Check: pillar → member link exists?
				$p2m = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$lnk} WHERE source_post_id = %d AND target_post_id = %d",
					$pillar_id, $mid
				) );

				// Check: member → pillar link exists?
				$m2p = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$lnk} WHERE source_post_id = %d AND target_post_id = %d",
					$mid, $pillar_id
				) );

				if ( ! $p2m ) {
					$issues[] = array(
						'type'   => 'pillar_missing',
						'from'   => $cl['pillar_title'],
						'to'     => $m['post_title'],
						'to_id'  => $mid,
					);
				}
				if ( ! $m2p ) {
					$issues[] = array(
						'type'   => 'member_missing',
						'from'   => $m['post_title'],
						'to'     => $cl['pillar_title'],
						'to_id'  => $pillar_id,
					);
				}
			}

			$total_issues += count( $issues );
			$results[] = array(
				'cluster_name' => $cl['cluster_name'],
				'pillar'       => $cl['pillar_title'],
				'members'      => count( $members ),
				'issues'       => $issues,
			);
		}

		return array( 'clusters' => $results, 'issues' => $total_issues );
	}

	/* ===================================================================
	   v2.4: Diagnostics  -  combined health check.
	   Returns: cannibalization, anchor cannib., duplicates, overlinked, near-orphans.
	   =================================================================== */
	public static function ajax_diagnostics() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
		wp_send_json_success( self::run_diagnostics() );
	}

	public static function run_diagnostics() {
		global $wpdb;
		$idx = Loom_DB::index_table();
		$lnk = Loom_DB::links_table();

		// 1. Keyword cannibalization: 2+ pages with same top GSC query.
		$kw_cannibal = $wpdb->get_results(
			"SELECT gsc_top_queries, GROUP_CONCAT(post_id) AS post_ids,
			        GROUP_CONCAT(post_title SEPARATOR ' | ') AS titles,
			        COUNT(*) AS page_count
			 FROM {$idx}
			 WHERE gsc_top_queries IS NOT NULL AND gsc_top_queries != '' AND gsc_top_queries != '[]'
			 GROUP BY SUBSTRING_INDEX(
			     SUBSTRING_INDEX(
			         REPLACE(REPLACE(gsc_top_queries, '[{\"query\":\"', ''), '\",', '|'),
			         '|', 1
			     ), '\"', 1
			 )
			 HAVING page_count > 1
			 LIMIT 20",
			ARRAY_A
		);

		// Simplified approach: extract first query per page and group.
		$kw_cannibal_clean = array();
		$pages_with_gsc = $wpdb->get_results(
			"SELECT post_id, post_title, gsc_top_queries, gsc_position
			 FROM {$idx} WHERE gsc_top_queries IS NOT NULL AND gsc_top_queries != '' AND gsc_top_queries != '[]'",
			ARRAY_A
		);

		$query_to_pages = array();
		foreach ( $pages_with_gsc as $p ) {
			$queries = json_decode( $p['gsc_top_queries'], true );
			if ( ! is_array( $queries ) || empty( $queries ) ) continue;
			$top_query = $queries[0]['query'] ?? '';
			if ( empty( $top_query ) ) continue;
			$key = mb_strtolower( trim( $top_query ) );
			if ( ! isset( $query_to_pages[ $key ] ) ) $query_to_pages[ $key ] = array();
			$query_to_pages[ $key ][] = array(
				'post_id'  => intval( $p['post_id'] ),
				'title'    => $p['post_title'],
				'position' => floatval( $p['gsc_position'] ?? 0 ),
			);
		}
		foreach ( $query_to_pages as $query => $pages ) {
			if ( count( $pages ) > 1 ) {
				$kw_cannibal_clean[] = array( 'query' => $query, 'pages' => $pages );
			}
		}

		// 2. Anchor cannibalization: same anchor → different targets.
		$anchor_cannibal = $wpdb->get_results(
			"SELECT l.anchor_text,
			        COUNT(DISTINCT l.target_post_id) AS target_count,
			        GROUP_CONCAT(DISTINCT l.target_post_id) AS target_ids,
			        GROUP_CONCAT(DISTINCT i.post_title SEPARATOR ' | ') AS target_titles
			 FROM {$lnk} l
			 JOIN {$idx} i ON l.target_post_id = i.post_id
			 WHERE l.anchor_text != '' AND l.target_post_id > 0
			 GROUP BY l.anchor_text
			 HAVING target_count > 1
			 ORDER BY target_count DESC
			 LIMIT 30",
			ARRAY_A
		);

		// 3. Duplicate links.
		$duplicates = Loom_DB::find_duplicate_links();

		// 4. Overlinked pages.
		$overlinked = Loom_DB::find_overlinked_pages( 20 );

		// 5. Near-orphans (1-2 IN, not structural).
		$near_orphans = $wpdb->get_results(
			"SELECT post_id, post_title, incoming_links_count, outgoing_links_count, internal_pagerank, gsc_position
			 FROM {$idx}
			 WHERE incoming_links_count > 0 AND incoming_links_count <= 2 AND is_structural = 0
			 ORDER BY incoming_links_count ASC, internal_pagerank DESC
			 LIMIT 30",
			ARRAY_A
		);

		return array(
			'keyword_cannibalization' => $kw_cannibal_clean,
			'anchor_cannibalization'  => $anchor_cannibal,
			'duplicate_links'         => $duplicates,
			'overlinked_pages'        => $overlinked,
			'near_orphans'            => $near_orphans,
			'counts'                  => array(
				'kw_cannibal'     => count( $kw_cannibal_clean ),
				'anchor_cannibal' => count( $anchor_cannibal ),
				'duplicates'      => count( $duplicates ),
				'overlinked'      => count( $overlinked ),
				'near_orphans'    => count( $near_orphans ),
			),
		);
	}
}
