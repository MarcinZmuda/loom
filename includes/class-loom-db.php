<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Loom_DB {

	/* ── Tables ────────────────────────────────────── */

	public static function index_table() {
		global $wpdb;
		return $wpdb->prefix . 'loom_index';
	}

	public static function links_table() {
		global $wpdb;
		return $wpdb->prefix . 'loom_links';
	}

	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'loom_log';
	}

	public static function clusters_table() {
		global $wpdb;
		return $wpdb->prefix . 'loom_clusters';
	}

	/* ── Index CRUD ────────────────────────────────── */

	public static function upsert_index( $data ) {
		global $wpdb;
		$table = self::index_table();
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE post_id = %d", $data['post_id']
		) );

		$data['last_scanned'] = current_time( 'mysql' );

		if ( $exists ) {
			$wpdb->update( $table, $data, array( 'post_id' => $data['post_id'] ) );
		} else {
			$wpdb->insert( $table, $data );
		}
	}

	public static function get_index_row( $post_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::index_table() . " WHERE post_id = %d", $post_id
		), ARRAY_A );
	}

	public static function get_all_with_embeddings( $exclude_post_id = 0 ) {
		global $wpdb;
		$table = self::index_table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, post_title, post_url, post_type,
			        incoming_links_count, outgoing_links_count,
			        is_orphan, click_depth, cluster_id, site_tier,
			        embedding, focus_keywords,
			        internal_pagerank, is_dead_end, is_bridge, component_id, betweenness,
			        is_money_page, money_priority, target_links_goal,
			        gsc_clicks, gsc_impressions, gsc_ctr, gsc_position,
			        gsc_top_queries, is_striking_distance,
			        last_scanned,
			        SUBSTRING(clean_text, 1, 200) AS preview
			 FROM {$table}
			 WHERE post_id != %d AND embedding IS NOT NULL",
			$exclude_post_id
		), ARRAY_A );
	}

	public static function get_all_index_rows() {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM " . self::index_table() . " ORDER BY post_title ASC", ARRAY_A
		);
	}

	public static function delete_index( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::index_table(), array( 'post_id' => $post_id ) );
	}

	/* ── Links CRUD ────────────────────────────────── */

	public static function insert_link( $data ) {
		global $wpdb;
		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( self::links_table(), $data );
	}

	public static function delete_links_for_post( $post_id ) {
		global $wpdb;
		$table = self::links_table();
		// Only delete manually-found links. LOOM-generated links are managed separately.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE source_post_id = %d AND is_plugin_generated = 0",
			$post_id
		) );
	}

	public static function delete_links_involving_post( $post_id ) {
		global $wpdb;
		$table = self::links_table();
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE source_post_id = %d OR target_post_id = %d",
			$post_id, $post_id
		) );
	}

	public static function get_links_from( $post_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::links_table() . " WHERE source_post_id = %d", $post_id
		), ARRAY_A );
	}

	public static function get_links_to( $post_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::links_table() . " WHERE target_post_id = %d", $post_id
		), ARRAY_A );
	}

	public static function get_existing_target_urls( $post_id ) {
		global $wpdb;
		$table  = self::links_table();
		$itable = self::index_table();
		return $wpdb->get_col( $wpdb->prepare(
			"SELECT i.post_url FROM {$table} l
			 JOIN {$itable} i ON l.target_post_id = i.post_id
			 WHERE l.source_post_id = %d",
			$post_id
		) );
	}

	/* ── Metrics ───────────────────────────────────── */

	public static function recalc_counters() {
		global $wpdb;
		$idx = self::index_table();
		$lnk = self::links_table();

		// Outgoing.
		$wpdb->query( "UPDATE {$idx} i SET outgoing_links_count = (
			SELECT COUNT(*) FROM {$lnk} WHERE source_post_id = i.post_id
		)" );

		// Incoming.
		$wpdb->query( "UPDATE {$idx} i SET incoming_links_count = (
			SELECT COUNT(*) FROM {$lnk} WHERE target_post_id = i.post_id
		)" );

		// Orphans.
		$wpdb->query( "UPDATE {$idx} SET is_orphan = CASE WHEN incoming_links_count = 0 THEN 1 ELSE 0 END" );
	}

	public static function recalc_counters_for_post( $post_id ) {
		global $wpdb;
		$idx = self::index_table();
		$lnk = self::links_table();

		$out = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$lnk} WHERE source_post_id = %d", $post_id
		) );
		$in = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$lnk} WHERE target_post_id = %d", $post_id
		) );

		$wpdb->update( $idx, array(
			'outgoing_links_count' => $out,
			'incoming_links_count' => $in,
			'is_orphan'            => $in === 0 ? 1 : 0,
		), array( 'post_id' => $post_id ) );
	}

	public static function get_dashboard_stats() {
		global $wpdb;
		$idx = self::index_table();
		$lnk = self::links_table();
		$log = self::log_table();

		// Single aggregated query for loom_index (replaces 12 separate queries).
		$agg = $wpdb->get_row(
			"SELECT
				COUNT(*) AS total,
				SUM(is_orphan) AS orphans,
				SUM(is_dead_end) AS dead_ends,
				SUM(is_bridge) AS bridges,
				SUM(is_money_page) AS money,
				SUM(CASE WHEN is_money_page = 1 AND incoming_links_count < target_links_goal THEN 1 ELSE 0 END) AS money_deficit,
				SUM(is_striking_distance) AS striking,
				SUM(CASE WHEN gsc_position IS NOT NULL AND gsc_position > 0 THEN 1 ELSE 0 END) AS gsc_synced,
				SUM(CASE WHEN embedding IS NOT NULL THEN 1 ELSE 0 END) AS emb_done,
				SUM(CASE WHEN focus_keywords IS NOT NULL AND focus_keywords != '' THEN 1 ELSE 0 END) AS kw_done,
				SUM(CASE WHEN click_depth > 3 OR click_depth IS NULL THEN 1 ELSE 0 END) AS deep_pages,
				SUM(CASE WHEN incoming_links_count > 0 AND incoming_links_count < 3 THEN 1 ELSE 0 END) AS weak,
				ROUND(AVG(outgoing_links_count), 1) AS avg_out,
				ROUND(AVG(incoming_links_count), 1) AS avg_in,
				MAX(click_depth) AS max_depth,
				MAX(last_gsc_sync) AS last_sync
			 FROM {$idx}",
			ARRAY_A
		);

		// Single aggregated query for loom_links (replaces 3 separate queries).
		$lnk_agg = $wpdb->get_row(
			"SELECT
				COUNT(*) AS total_links,
				SUM(is_plugin_generated) AS loom_links,
				SUM(is_broken) AS broken,
				SUM(is_nofollow) AS nofollow
			 FROM {$lnk}",
			ARRAY_A
		);

		$api_cost  = round( (float) $wpdb->get_var( "SELECT SUM(api_cost_usd) FROM {$log}" ), 2 );
		$types_raw = $wpdb->get_results( "SELECT post_type, COUNT(*) as cnt FROM {$idx} GROUP BY post_type ORDER BY cnt DESC", ARRAY_A );

		$total = intval( $agg['total'] ?? 0 );

		return array(
			'total_posts'       => $total,
			'orphans'           => intval( $agg['orphans'] ?? 0 ),
			'weak_pages'        => intval( $agg['weak'] ?? 0 ),
			'avg_out_links'     => floatval( $agg['avg_out'] ?? 0 ),
			'avg_in_links'      => floatval( $agg['avg_in'] ?? 0 ),
			'total_links'       => intval( $lnk_agg['total_links'] ?? 0 ),
			'loom_links'        => intval( $lnk_agg['loom_links'] ?? 0 ),
			'api_cost'          => $api_cost,
			'deep_pages'        => intval( $agg['deep_pages'] ?? 0 ),
			'dead_ends'         => intval( $agg['dead_ends'] ?? 0 ),
			'bridges'           => intval( $agg['bridges'] ?? 0 ),
			'max_depth'         => intval( $agg['max_depth'] ?? 0 ),
			'embeddings_done'   => intval( $agg['emb_done'] ?? 0 ),
			'embeddings_miss'   => $total - intval( $agg['emb_done'] ?? 0 ),
			'keywords_done'     => intval( $agg['kw_done'] ?? 0 ),
			'money_pages'       => intval( $agg['money'] ?? 0 ),
			'money_deficit'     => intval( $agg['money_deficit'] ?? 0 ),
			'gsc_synced'        => intval( $agg['gsc_synced'] ?? 0 ),
			'striking_distance' => intval( $agg['striking'] ?? 0 ),
			'last_gsc_sync'     => $agg['last_sync'] ?? null,
			'broken_links'      => intval( $lnk_agg['broken'] ?? 0 ),
			'nofollow_links'    => intval( $lnk_agg['nofollow'] ?? 0 ),
			'post_types'        => $types_raw,
		);
	}

	/* ── Logging ───────────────────────────────────── */

	public static function log( $action, $post_id = null, $details = array(), $tokens = 0, $cost = 0.0 ) {
		global $wpdb;
		$wpdb->insert( self::log_table(), array(
			'action'          => sanitize_key( $action ),
			'post_id'         => $post_id ? absint( $post_id ) : null,
			'details'         => wp_json_encode( $details ),
			'api_tokens_used' => absint( $tokens ),
			'api_cost_usd'    => round( floatval( $cost ), 4 ),
			'created_at'      => current_time( 'mysql' ),
		) );
	}

	/* ── Settings helpers ──────────────────────────── */

	private static $settings_cache = null;

	public static function get_settings() {
		if ( self::$settings_cache === null ) {
			self::$settings_cache = wp_parse_args(
				get_option( 'loom_settings', array() ),
				Loom_Activator::default_settings()
			);
		}
		return self::$settings_cache;
	}

	public static function get_api_key() {
		$stored = get_option( 'loom_openai_key', '' );
		if ( empty( $stored ) ) {
			return '';
		}
		$key    = wp_salt( 'auth' );
		$raw    = base64_decode( $stored );
		if ( strlen( $raw ) <= 16 ) {
			// Legacy format (pre-2.0)  -  fixed IV.
			$iv  = substr( md5( wp_salt( 'secure_auth' ) ), 0, 16 );
			$dec = openssl_decrypt( $stored, 'AES-256-CBC', $key, 0, $iv );
			return $dec !== false ? $dec : '';
		}
		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$dec    = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return $dec !== false ? $dec : '';
	}

	public static function save_api_key( $raw_key ) {
		$key = wp_salt( 'auth' );
		$iv  = openssl_random_pseudo_bytes( 16 );
		$enc = openssl_encrypt( $raw_key, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		update_option( 'loom_openai_key', base64_encode( $iv . $enc ) );
	}

	/* ── Rejections (table helper  -  logic in Loom_Site_Analysis) ── */

	/**
	 * @return string Rejections table name.
	 */
	public static function rejections_table() {
		global $wpdb;
		return $wpdb->prefix . 'loom_rejections';
	}

	/* ── Anchor Distribution per target ────────────── */

	public static function get_anchor_distribution( $target_post_id ) {
		global $wpdb;
		$table = self::links_table();
		$anchors = $wpdb->get_results( $wpdb->prepare(
			"SELECT anchor_text, COUNT(*) as cnt FROM {$table}
			 WHERE target_post_id = %d
			 GROUP BY anchor_text ORDER BY cnt DESC",
			$target_post_id
		), ARRAY_A );

		$total = 0;
		foreach ( $anchors as $a ) $total += intval( $a['cnt'] );

		$result = array();
		foreach ( $anchors as $a ) {
			$result[] = array(
				'anchor'  => $a['anchor_text'],
				'count'   => intval( $a['cnt'] ),
				'percent' => $total > 0 ? round( ( intval( $a['cnt'] ) / $total ) * 100 ) : 0,
			);
		}
		return $result;
	}

	public static function get_existing_anchors_for_target( $target_post_id ) {
		global $wpdb;
		$table = self::links_table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT anchor_text, COUNT(*) as cnt FROM {$table}
			 WHERE target_post_id = %d GROUP BY anchor_text ORDER BY cnt DESC LIMIT 10",
			$target_post_id
		), ARRAY_A );
	}

	/* ── Money Pages ──────────────────────────────── */

	/**
	 * AJAX: Toggle money page status.
	 */
	public static function ajax_set_money_page() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		$post_id  = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
		$is_money = absint( wp_unslash( $_POST['is_money'] ?? 0 ) );
		$priority = max( 1, min( 5, absint( wp_unslash( $_POST['priority'] ?? 3 ) ) ) );
		$goal     = max( 3, min( 20, absint( wp_unslash( $_POST['goal'] ?? 10 ) ) ) );

		if ( ! $post_id ) wp_send_json_error( 'Invalid post ID.' );

		global $wpdb;
		$wpdb->update( self::index_table(), array(
			'is_money_page'    => $is_money ? 1 : 0,
			'money_priority'   => $is_money ? $priority : 0,
			'target_links_goal' => $goal,
		), array( 'post_id' => $post_id ) );

		wp_send_json_success();
	}

	/**
	 * AJAX: Get all money pages with health status.
	 */
	public static function ajax_get_money_pages() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Forbidden', 403 );

		wp_send_json_success( self::get_money_pages_health() );
	}

	/**
	 * Get money pages with link health data.
	 *
	 * @return array Money page health records.
	 */
	public static function get_money_pages_health() {
		global $wpdb;
		$idx = self::index_table();
		$lnk = self::links_table();

		$pages = $wpdb->get_results(
			"SELECT post_id, post_title, post_url, incoming_links_count,
			        money_priority, target_links_goal, internal_pagerank,
			        gsc_position, gsc_impressions, gsc_ctr
			 FROM {$idx}
			 WHERE is_money_page = 1
			 ORDER BY money_priority DESC, incoming_links_count ASC",
			ARRAY_A
		);

		$result = array();
		foreach ( $pages as $p ) {
			$pid     = intval( $p['post_id'] );
			$goal    = intval( $p['target_links_goal'] );
			$current = intval( $p['incoming_links_count'] );
			$deficit = max( 0, $goal - $current );

			// Anchor distribution for this money page.
			$dist = self::get_anchor_distribution( $pid );
			$max_pct = 0;
			foreach ( $dist as $d ) {
				if ( intval( $d['percent'] ) > $max_pct ) $max_pct = intval( $d['percent'] );
			}

			$status = 'ok';
			if ( $deficit >= 5 ) $status = 'critical';
			elseif ( $deficit > 0 ) $status = 'needs_more';

			$result[] = array(
				'post_id'         => $pid,
				'title'           => $p['post_title'],
				'url'             => $p['post_url'],
				'priority'        => intval( $p['money_priority'] ),
				'goal'            => $goal,
				'current'         => $current,
				'deficit'         => $deficit,
				'anchor_diversity' => 100 - $max_pct,
				'pagerank'        => floatval( $p['internal_pagerank'] ?? 0 ),
				'gsc_position'    => floatval( $p['gsc_position'] ?? 0 ),
				'gsc_impressions' => intval( $p['gsc_impressions'] ?? 0 ),
				'anchors'         => array_slice( $dist, 0, 5 ),
				'status'          => $status,
			);
		}

		return $result;
	}
}
