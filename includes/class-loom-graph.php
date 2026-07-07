<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Loom_Graph  -  Internal link graph engine.
 *
 * Builds a directed graph from loom_links and computes:
 * - Internal PageRank (iterative power method, damping 0.85)
 * - Dead ends (IN > 0, OUT = 0)
 * - Bridge nodes (betweenness centrality approximation)
 * - Connected components (weakly connected, BFS on undirected view)
 * - Graph health metrics and structural link suggestions
 *
 * Pure PHP, zero external dependencies. ~2s for 500 nodes.
 */
class Loom_Graph {

	/** @var array<int, int[]> node_id => [target_ids] */
	private static $outlinks = array();

	/** @var array<int, int[]> node_id => [source_ids] */
	private static $inlinks = array();

	/** @var int[] All node IDs. */
	private static $nodes = array();

	/** @var int Node count. */
	private static $N = 0;

	/** @var float|null Cached average PageRank (reset on analyze). */
	private static $avg_pr_cache = null;

	/** @var float|null Cached average outgoing links. */
	private static $avg_out_cache = null;

	/* ================================================================
	   BUILD GRAPH FROM DB
	   ================================================================ */

	/**
	 * Build adjacency lists from loom_links table.
	 *
	 * @return bool True if graph has >= 2 nodes.
	 */
	private static function build() {
		global $wpdb;
		$idx = Loom_DB::index_table();
		$lnk = Loom_DB::links_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching  -  internal tables, no user input
		self::$nodes = $wpdb->get_col( "SELECT post_id FROM {$idx}" );
		self::$N     = count( self::$nodes );
		if ( self::$N < 2 ) return false;

		self::$outlinks = array();
		self::$inlinks  = array();
		foreach ( self::$nodes as $n ) {
			$n = intval( $n );
			self::$outlinks[ $n ] = array();
			self::$inlinks[ $n ]  = array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery  -  table names are from Loom_DB constants, no user input
		$edges = $wpdb->get_results(
			"SELECT DISTINCT source_post_id, target_post_id FROM {$lnk}
			 WHERE target_post_id > 0 AND is_broken = 0 AND is_nofollow = 0",
			ARRAY_A
		);

		foreach ( $edges as $e ) {
			$s = intval( $e['source_post_id'] );
			$t = intval( $e['target_post_id'] );
			if ( $s === $t ) continue;
			if ( isset( self::$outlinks[ $s ] ) && isset( self::$inlinks[ $t ] ) ) {
				if ( ! in_array( $t, self::$outlinks[ $s ], true ) ) self::$outlinks[ $s ][] = $t;
				if ( ! in_array( $s, self::$inlinks[ $t ], true ) )  self::$inlinks[ $t ][]  = $s;
			}
		}
		return true;
	}

	/* ================================================================
	   PAGERANK  -  Iterative Power Method
	   ================================================================ */

	/**
	 * Calculate internal PageRank.
	 *
	 * @param float $damping  Damping factor (0.85 standard).
	 * @param int   $max_iter Maximum iterations.
	 * @param float $eps      Convergence threshold.
	 * @return array<int, float> post_id => rank
	 */
	private static function pagerank( $damping = 0.85, $max_iter = 100, $eps = 0.0001 ) {
		$N  = self::$N;
		$pr = array();
		foreach ( self::$nodes as $n ) { $pr[ intval( $n ) ] = 1.0 / $N; }

		for ( $iter = 0; $iter < $max_iter; $iter++ ) {
			$new  = array();
			$diff = 0.0;

			// Dead end redistribution: PR from nodes with no outlinks.
			$dead_sum = 0.0;
			foreach ( self::$nodes as $n ) {
				$n = intval( $n );
				if ( empty( self::$outlinks[ $n ] ) ) $dead_sum += $pr[ $n ];
			}

			foreach ( self::$nodes as $n ) {
				$n   = intval( $n );
				$sum = 0.0;
				foreach ( self::$inlinks[ $n ] as $src ) {
					$od = count( self::$outlinks[ $src ] );
					if ( $od > 0 ) $sum += $pr[ $src ] / $od;
				}
				$new[ $n ] = ( 1 - $damping ) / $N + $damping * ( $sum + $dead_sum / $N );
				$diff += abs( $new[ $n ] - $pr[ $n ] );
			}

			$pr = $new;
			if ( $diff < $eps ) break;
		}

		// Normalize to sum = 1.
		$total = array_sum( $pr );
		if ( $total > 0 ) {
			foreach ( $pr as &$v ) { $v /= $total; }
			unset( $v );
		}
		return $pr;
	}

	/* ================================================================
	   DEAD ENDS
	   ================================================================ */

	/**
	 * Find dead end nodes (has incoming links but no outgoing).
	 *
	 * @return int[] Post IDs that are dead ends.
	 */
	private static function dead_ends() {
		$de = array();
		foreach ( self::$nodes as $n ) {
			$n = intval( $n );
			if ( ! empty( self::$inlinks[ $n ] ) && empty( self::$outlinks[ $n ] ) ) {
				$de[] = $n;
			}
		}
		return $de;
	}

	/* ================================================================
	   BRIDGES  -  Betweenness Centrality Approximation
	   ================================================================ */

	/**
	 * Approximate betweenness centrality via BFS from sampled nodes.
	 * Full computation is O(V*E); we sample max 60 for performance.
	 *
	 * @return array<int, float> post_id => betweenness score
	 */
	private static function betweenness() {
		$N  = self::$N;
		$bw = array();
		foreach ( self::$nodes as $n ) { $bw[ intval( $n ) ] = 0.0; }

		$sample_size = min( 60, $N );
		$keys = (array) array_rand( self::$nodes, max( 1, $sample_size ) );

		foreach ( $keys as $k ) {
			$source = intval( self::$nodes[ $k ] );
			$dist = array(); $paths = array(); $pred = array();
			foreach ( self::$nodes as $n ) {
				$n = intval( $n );
				$dist[ $n ] = -1; $paths[ $n ] = 0; $pred[ $n ] = array();
			}
			$dist[ $source ] = 0; $paths[ $source ] = 1;
			$queue = array( $source ); $order = array();

			// BFS forward pass (index pointer  -  array_shift() is O(n) per call).
			$qh = 0;
			while ( $qh < count( $queue ) ) {
				$v = $queue[ $qh++ ];
				$order[] = $v;
				foreach ( self::$outlinks[ $v ] ?? array() as $w ) {
					if ( $dist[ $w ] === -1 ) { $dist[ $w ] = $dist[ $v ] + 1; $queue[] = $w; }
					if ( $dist[ $w ] === $dist[ $v ] + 1 ) { $paths[ $w ] += $paths[ $v ]; $pred[ $w ][] = $v; }
				}
			}

			// Dependency accumulation (backward pass).
			$dep = array();
			foreach ( self::$nodes as $n ) { $dep[ intval( $n ) ] = 0.0; }
			while ( ! empty( $order ) ) {
				$w = array_pop( $order );
				foreach ( $pred[ $w ] as $v ) {
					if ( $paths[ $w ] > 0 ) $dep[ $v ] += ( $paths[ $v ] / $paths[ $w ] ) * ( 1 + $dep[ $w ] );
				}
				if ( $w !== $source ) $bw[ $w ] += $dep[ $w ];
			}
		}

		// Scale by sampling ratio.
		$scale = $N / max( 1, $sample_size );
		foreach ( $bw as &$b ) { $b = round( $b * $scale, 4 ); }
		unset( $b );
		return $bw;
	}

	/* ================================================================
	   CONNECTED COMPONENTS (weakly connected, undirected BFS)
	   Note: This finds WEAKLY connected components  -  appropriate for
	   detecting isolated clusters regardless of link direction.
	   ================================================================ */

	/**
	 * Find weakly connected components using BFS on undirected view.
	 *
	 * @return array<int, int> post_id => component_id
	 */
	private static function components() {
		$adj = array();
		foreach ( self::$nodes as $n ) {
			$n = intval( $n );
			$adj[ $n ] = array_unique( array_merge(
				self::$outlinks[ $n ] ?? array(),
				self::$inlinks[ $n ]  ?? array()
			) );
		}
		$visited = array(); $comp = array(); $cid = 0;
		foreach ( self::$nodes as $n ) {
			$n = intval( $n );
			if ( isset( $visited[ $n ] ) ) continue;
			$cid++; $queue = array( $n ); $visited[ $n ] = true;
			$qh = 0;
			while ( $qh < count( $queue ) ) {
				$v = $queue[ $qh++ ];
				$comp[ $v ] = $cid;
				foreach ( $adj[ $v ] ?? array() as $w ) {
					if ( ! isset( $visited[ $w ] ) ) { $visited[ $w ] = true; $queue[] = $w; }
				}
			}
		}
		return $comp;
	}

	/* ================================================================
	   ANALYZE  -  full recalculation (called after scan)
	   ================================================================ */

	/**
	 * Run complete graph analysis: PageRank, dead ends, bridges, components.
	 * Resets cached aggregates.
	 */
	public static function analyze() {
		if ( ! self::build() ) return;

		self::$avg_pr_cache  = null;
		self::$avg_out_cache = null;

		global $wpdb;
		$table = Loom_DB::index_table();

		// PageRank  -  batched CASE update (per-row UPDATE was ~4N queries total).
		$pr = self::pagerank();
		self::batch_update_column( 'internal_pagerank', array_map( function ( $v ) {
			return round( $v, 8 );
		}, $pr ) );

		// Dead ends  -  single query.
		$de = array_map( 'intval', self::dead_ends() );
		if ( empty( $de ) ) {
			$wpdb->query( "UPDATE {$table} SET is_dead_end = 0" );
		} else {
			$de_str = implode( ',', $de );
			$wpdb->query( "UPDATE {$table} SET is_dead_end = IF( post_id IN ({$de_str}), 1, 0 )" ); // phpcs:ignore -- IDs are intval'd.
		}

		// Betweenness + bridges (top 10%).
		$bw   = self::betweenness();
		$vals = array_values( $bw );
		sort( $vals );
		$p90  = ! empty( $vals ) ? $vals[ max( 0, intval( count( $vals ) * 0.9 ) ) ] : 0;

		self::batch_update_column( 'betweenness', $bw );
		$bridges = array();
		foreach ( $bw as $pid => $score ) {
			if ( $score >= $p90 && $p90 > 0 ) $bridges[] = intval( $pid );
		}
		if ( empty( $bridges ) ) {
			$wpdb->query( "UPDATE {$table} SET is_bridge = 0" );
		} else {
			$br_str = implode( ',', $bridges );
			$wpdb->query( "UPDATE {$table} SET is_bridge = IF( post_id IN ({$br_str}), 1, 0 )" ); // phpcs:ignore -- IDs are intval'd.
		}

		// Weakly connected components.
		self::batch_update_column( 'component_id', self::components() );
	}

	/**
	 * Batched numeric column update via CASE WHEN (chunks of 200).
	 *
	 * Replaces per-row $wpdb->update() loops  -  on a 5k-page site analyze()
	 * previously issued ~20k UPDATE queries.
	 *
	 * @param string           $column Column name (internal, not user input).
	 * @param array<int,float> $values post_id => numeric value.
	 */
	private static function batch_update_column( $column, $values ) {
		global $wpdb;
		$table = Loom_DB::index_table();

		foreach ( array_chunk( $values, 200, true ) as $chunk ) {
			$cases = '';
			$ids   = array();
			foreach ( $chunk as $pid => $val ) {
				$pid     = intval( $pid );
				$ids[]   = $pid;
				$cases  .= ' WHEN ' . $pid . ' THEN ' . floatval( $val );
			}
			$ids_str = implode( ',', $ids );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- IDs/values cast to int/float above, column internal.
			$wpdb->query( "UPDATE {$table} SET {$column} = CASE post_id{$cases} END WHERE post_id IN ({$ids_str})" );
		}
	}

	/* ================================================================
	   CACHED AGGREGATES (PERF-1 fix: no N+1 queries)
	   ================================================================ */

	/**
	 * Get cached average PageRank. Computed once per request.
	 *
	 * @return float
	 */
	public static function get_avg_pr() {
		if ( self::$avg_pr_cache !== null ) return self::$avg_pr_cache;
		global $wpdb;
		self::$avg_pr_cache = (float) $wpdb->get_var(
			"SELECT AVG(internal_pagerank) FROM " . Loom_DB::index_table() . " WHERE internal_pagerank IS NOT NULL"
		);
		return self::$avg_pr_cache;
	}

	/**
	 * Get cached average outgoing links. Computed once per request.
	 *
	 * @return float
	 */
	private static function get_avg_out() {
		if ( self::$avg_out_cache !== null ) return self::$avg_out_cache;
		global $wpdb;
		self::$avg_out_cache = (float) $wpdb->get_var(
			"SELECT AVG(outgoing_links_count) FROM " . Loom_DB::index_table()
		);
		return self::$avg_out_cache;
	}

	/* ================================================================
	   GRAPH NEED  -  composite score dimension
	   ================================================================ */

	/**
	 * Calculate graph_need score for a target (0.0 – 1.0).
	 *
	 * @param array $source Source post row.
	 * @param array $target Target post row.
	 * @return float
	 */
	public static function graph_need( $source, $target ) {
		$score  = 0.0;
		$tpr    = floatval( $target['internal_pagerank'] ?? 0 );
		$spr    = floatval( $source['internal_pagerank'] ?? 0 );
		$avg_pr = self::get_avg_pr();

		// Target is orphan -> critical need.
		if ( ! empty( $target['is_orphan'] ) ) $score += 0.4;

		// Target has low PR -> needs equity.
		if ( $avg_pr > 0 && $tpr < $avg_pr * 0.5 ) $score += 0.3;

		// Target is dead end with high PR -> penalty (don't feed it more).
		if ( ! empty( $target['is_dead_end'] ) && $tpr > $avg_pr ) $score -= 0.15;

		// Same component -> cluster bonus (LOGIC-7 fix: intval cast).
		$sc = intval( $source['component_id'] ?? 0 );
		$tc = intval( $target['component_id'] ?? 0 );
		if ( $sc > 0 && $tc > 0 && $sc === $tc ) $score += 0.15;

		// Source has high PR -> its outgoing links carry more weight.
		if ( $avg_pr > 0 && $spr > $avg_pr * 2 ) $score += 0.1;

		return max( 0.0, min( 1.0, $score ) );
	}

	/* ================================================================
	   PROMPT HELPERS
	   ================================================================ */

	/**
	 * One-line graph info for a target in GPT prompt.
	 *
	 * @param array $target Target post row.
	 * @return string Prompt line or empty.
	 */
	public static function target_graph_line( $target ) {
		$pr = $target['internal_pagerank'] ?? null;
		if ( $pr === null ) return '';

		$pr     = floatval( $pr );
		$avg    = self::get_avg_pr();
		$parts  = array( 'PR: ' . number_format( $pr, 6 ) );

		if ( $avg > 0 && $pr < $avg * 0.5 ) $parts[] = 'LOW  -  needs equity';
		elseif ( $avg > 0 && $pr > $avg * 2 ) $parts[] = 'HIGH  -  already strong';
		else $parts[] = 'average';

		if ( ! empty( $target['is_dead_end'] ) ) $parts[] = '⚠️ DEAD END';
		if ( ! empty( $target['is_bridge'] ) )   $parts[] = '🌉 BRIDGE';

		return '   📊 ' . implode( ' | ', $parts ) . "\n";
	}

	/**
	 * Source page graph context block for GPT prompt.
	 *
	 * @param array $source Source post row.
	 * @return string Multi-line prompt section or empty.
	 */
	public static function source_graph_context( $source ) {
		$pr = $source['internal_pagerank'] ?? null;
		if ( $pr === null ) return '';

		$pr      = floatval( $pr );
		$out     = intval( $source['outgoing_links_count'] ?? 0 );
		$avg_out = self::get_avg_out();

		// Percentile calculation.
		global $wpdb;
		$idx    = Loom_DB::index_table();
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$idx} WHERE internal_pagerank IS NOT NULL" );
		$higher = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$idx} WHERE internal_pagerank > %f", $pr
		) );
		$pctl = $total > 0 ? round( ( 1 - $higher / $total ) * 100 ) : 50;

		$role = 'NORMAL';
		if ( $pctl >= 75 ) $role = 'AUTHORITY  -  your links carry significant weight';
		elseif ( $pctl <= 25 ) $role = 'LOW AUTHORITY  -  consider adding more incoming links';

		$out_note = $out < $avg_out ? 'below avg (' . round( $avg_out, 1 ) . ')  -  room for more' : 'at/above avg';

		$lines  = "### SOURCE PAGE GRAPH CONTEXT\n";
		$lines .= "- PageRank: " . number_format( $pr, 6 ) . " (top {$pctl}%)\n";
		$lines .= "- Outgoing links: {$out} ({$out_note})\n";
		$lines .= "- Role: {$role}\n";

		if ( ! empty( $source['is_dead_end'] ) ) {
			$lines .= "- ⚠️ THIS PAGE IS A DEAD END  -  adding outgoing links is critical\n";
		}

		return $lines;
	}

	/* ================================================================
	   GRAPH HEALTH (dashboard)
	   ================================================================ */

	/**
	 * Calculate graph health metrics for dashboard.
	 *
	 * @return array Health stats or empty if < 2 nodes.
	 */
	public static function get_health() {
		global $wpdb;
		$idx = Loom_DB::index_table();
		$lnk = Loom_DB::links_table();

		$N = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$idx}" );
		if ( $N < 2 ) return array();

		$edges = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT CONCAT(source_post_id,'-',target_post_id))
			 FROM {$lnk} WHERE target_post_id > 0 AND is_broken = 0"
		);

		$max_e   = $N * ( $N - 1 );
		$density = $max_e > 0 ? round( $edges / $max_e, 4 ) : 0;

		$dead_ends  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$idx} WHERE is_dead_end = 1" );
		$bridges    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$idx} WHERE is_bridge = 1" );
		$components = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT component_id) FROM {$idx} WHERE component_id IS NOT NULL" );
		$biggest    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$idx} WHERE component_id IS NOT NULL GROUP BY component_id ORDER BY COUNT(*) DESC LIMIT 1" );
		$isolated   = $N - $biggest;

		$top10_limit = max( 1, intval( $N * 0.1 ) );
		$top10_pr = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(pr) FROM (SELECT internal_pagerank AS pr FROM {$idx}
				 WHERE internal_pagerank IS NOT NULL ORDER BY internal_pagerank DESC LIMIT %d) t",
				$top10_limit
			)
		);

		// Bottom 50% equity.
		$bot50_limit = max( 1, intval( $N * 0.5 ) );
		$bot50_pr = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(pr) FROM (SELECT internal_pagerank AS pr FROM {$idx}
				 WHERE internal_pagerank IS NOT NULL ORDER BY internal_pagerank ASC LIMIT %d) t",
				$bot50_limit
			)
		);

		// Max click depth.
		$max_depth = (int) $wpdb->get_var( "SELECT MAX(click_depth) FROM {$idx}" );

		return array(
			'nodes'        => $N,
			'edges'        => $edges,
			'density'      => $density,
			'dead_ends'    => $dead_ends,
			'bridges'      => $bridges,
			'components'   => $components,
			'isolated'     => $isolated,
			'equity_top10' => round( $top10_pr * 100 ),
			'bot50_equity' => round( $bot50_pr * 100 ),
			'max_depth'    => $max_depth,
		);
	}

	/* ================================================================
	   STRUCTURAL SUGGESTIONS
	   ================================================================ */

	/**
	 * Generate prioritized structural link suggestions from graph.
	 *
	 * @return array List of suggestion arrays (max 25).
	 */
	public static function structural_suggestions() {
		global $wpdb;
		$idx = Loom_DB::index_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery  -  no user input in query
		$all = $wpdb->get_results( "SELECT * FROM {$idx} ORDER BY internal_pagerank DESC", ARRAY_A );
		if ( empty( $all ) ) return array();

		$suggestions = array();
		$pr_vals = array_filter( array_column( $all, 'internal_pagerank' ), function( $v ) { return $v !== null; } );
		sort( $pr_vals );
		$cnt    = count( $pr_vals );
		$pr_p25 = $cnt > 0 ? floatval( $pr_vals[ max( 0, intval( $cnt * 0.25 ) ) ] ) : 0;
		$pr_p75 = $cnt > 0 ? floatval( $pr_vals[ max( 0, intval( $cnt * 0.75 ) ) ] ) : 0;

		foreach ( $all as $r ) {
			$pid = intval( $r['post_id'] );
			$in  = intval( $r['incoming_links_count'] );
			$out = intval( $r['outgoing_links_count'] );
			$pr  = floatval( $r['internal_pagerank'] ?? 0 );

			if ( $r['is_orphan'] ) {
				$suggestions[] = array( 'type' => 'orphan', 'priority' => 'critical', 'post_id' => $pid,
					'title' => $r['post_title'], 'reason' => __( 'Orphan  -  zero linków IN. Niewidoczny.', 'loom' ), 'icon' => '🔴' );
			}
			if ( $r['is_dead_end'] && $in > 0 ) {
				$suggestions[] = array( 'type' => 'dead_end', 'priority' => 'high', 'post_id' => $pid,
					'title' => $r['post_title'],
					'reason' => sprintf( __( 'Dead end  -  %d IN, 0 OUT. Pochłania equity.', 'loom' ), $in ), 'icon' => '⚫' );
			}
			if ( $r['is_bridge'] ) {
				$suggestions[] = array( 'type' => 'bridge', 'priority' => 'medium', 'post_id' => $pid,
					'title' => $r['post_title'],
					'reason' => sprintf( __( 'Bottleneck  -  betweenness %.0f.', 'loom' ), floatval( $r['betweenness'] ) ), 'icon' => '🌉' );
			}
			if ( $pr > 0 && $pr < $pr_p25 && in_array( $r['post_type'], array( 'page', 'product' ), true ) ) {
				$suggestions[] = array( 'type' => 'boost', 'priority' => 'high', 'post_id' => $pid,
					'title' => $r['post_title'],
					'reason' => sprintf( __( 'Niski PR (%.6f) dla %s.', 'loom' ), $pr, $r['post_type'] ), 'icon' => '📉' );
			}
			if ( $pr > $pr_p75 && $out < 3 && $in > 3 ) {
				$suggestions[] = array( 'type' => 'redistribute', 'priority' => 'medium', 'post_id' => $pid,
					'title' => $r['post_title'],
					'reason' => sprintf( __( 'Wysoki PR (%.6f), tylko %d OUT.', 'loom' ), $pr, $out ), 'icon' => '💎' );
			}
		}

		$po = array( 'critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3 );
		usort( $suggestions, function ( $a, $b ) use ( $po ) {
			return ( $po[ $a['priority'] ] ?? 9 ) <=> ( $po[ $b['priority'] ] ?? 9 );
		} );
		return array_slice( $suggestions, 0, 25 );
	}

	/* ================================================================
	   AJAX HANDLERS
	   ================================================================ */

	/**
	 * Recalculate graph (manual trigger from dashboard).
	 */
	public static function ajax_recalc_graph() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
		self::analyze();
		wp_send_json_success( __( 'Graf przeliczony.', 'loom' ) );
	}

	/**
	 * Get structural suggestions + health metrics.
	 */
	public static function ajax_structural_suggestions() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Forbidden', 403 );
		wp_send_json_success( array(
			'health'      => self::get_health(),
			'suggestions' => self::structural_suggestions(),
		) );
	}
}
