<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Loom_Composite {

	/**
	 * Calculate composite score for a target post.
	 *
	 * @param array $source  Source post data from loom_index.
	 * @param array $target  Target post data from loom_index (with cosine_similarity).
	 * @return float          Composite score (higher = better target).
	 */
	public static function score( $source, $target ) {
		$settings = self::$settings_cache ?? Loom_DB::get_settings();
		$weights  = array(
			'semantic'  => floatval( $settings['weight_semantic']  ?? 0.22 ),
			'orphan'    => floatval( $settings['weight_orphan']    ?? 0.08 ),
			'depth'     => floatval( $settings['weight_depth']     ?? 0.06 ),
			'tier'      => floatval( $settings['weight_tier']      ?? 0.06 ),
			'cluster'   => floatval( $settings['weight_cluster']   ?? 0.06 ),
			'equity'    => floatval( $settings['weight_equity']    ?? 0.04 ),
			'graph'     => floatval( $settings['weight_graph']     ?? 0.10 ),
			'money'     => floatval( $settings['weight_money']     ?? 0.10 ),
			'gsc'       => floatval( $settings['weight_gsc']       ?? 0.08 ),
			'authority' => floatval( $settings['weight_authority']  ?? 0.10 ),
			'placement' => floatval( $settings['weight_placement'] ?? 0.10 ),
		);

		// 1. Semantic similarity (0.0 – 1.0).
		$semantic = max( 0, min( 1, floatval( $target['cosine_similarity'] ?? 0 ) ) );

		// 2. Orphan boost (0.0 – 1.0).
		$incoming = intval( $target['incoming_links_count'] ?? 0 );
		if ( $incoming === 0 ) {
			$orphan = 1.0;
		} elseif ( $incoming < 3 ) {
			$orphan = 0.5;
		} elseif ( $incoming < 5 ) {
			$orphan = 0.2;
		} else {
			$orphan = 0.0;
		}

		// 3. Depth boost (0.0 – 1.0).
		$depth = $target['click_depth'];
		if ( $depth === null || $depth === '' ) {
			$depth_boost = 1.0;
		} elseif ( intval( $depth ) > 4 ) {
			$depth_boost = 1.0;
		} elseif ( intval( $depth ) > 3 ) {
			$depth_boost = 0.7;
		} elseif ( intval( $depth ) == 3 ) {
			$depth_boost = 0.3;
		} else {
			$depth_boost = 0.0;
		}

		// 4. Tier boost (0.0 – 1.0).
		$source_tier = intval( $source['site_tier'] ?? 3 );
		$target_tier = intval( $target['site_tier'] ?? 3 );

		if ( $target_tier < $source_tier ) {
			$tier = 1.0;
		} elseif ( $target_tier === $source_tier ) {
			$tier = 0.3;
		} else {
			$tier = 0.1;
		}

		// 5. Cluster boost (0.0 – 1.0).
		$src_cluster   = $source['cluster_id'] ?? null;
		$tgt_cluster   = $target['cluster_id'] ?? null;
		$tgt_post_id   = intval( $target['post_id'] ?? 0 );

		// Check if target is the pillar page of its cluster (batch-cached in rank_targets).
		$tgt_is_pillar = false;
		if ( $tgt_cluster && $tgt_post_id > 0 ) {
			$pillar_pid = self::$pillar_cache[ intval( $tgt_cluster ) ] ?? 0;
			if ( ! $pillar_pid ) {
				// Fallback for direct score() calls outside rank_targets().
				global $wpdb;
				$pillar_pid = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT pillar_post_id FROM {$wpdb->prefix}loom_clusters WHERE id = %d",
					intval( $tgt_cluster )
				) );
			}
			$tgt_is_pillar = ( $pillar_pid === $tgt_post_id );
		}

		if ( $tgt_is_pillar && $src_cluster && $src_cluster == $tgt_cluster ) {
			$cluster = 1.0; // Pillar page of same cluster = maximum.
		} elseif ( $src_cluster && $src_cluster == $tgt_cluster ) {
			$cluster = 0.8; // Same cluster = high.
		} elseif ( $tgt_cluster ) {
			$cluster = 0.2;
		} else {
			$cluster = 0.0;
		}

		// 6. Link velocity (0.0 – 1.0) — pages with few links relative to age need boost.
		// Unlike orphan_boost (binary: has/hasn't links), this measures RATE:
		// old page with few links = high need, new page with few links = normal.
		// Uses actual post_date (publication date), NOT last_scanned (which resets on every scan).
		$tgt_pid   = intval( $target['post_id'] ?? 0 );
		$post_date = self::$post_date_cache[ $tgt_pid ] ?? null;
		if ( ! $post_date ) {
			$tgt_post  = get_post( $tgt_pid );
			$post_date = $tgt_post ? $tgt_post->post_date : null;
		}
		$age_days  = $post_date ? max( 1, ( time() - strtotime( $post_date ) ) / 86400 ) : 90;
		$age_months = max( 1, $age_days / 30 );
		$links_per_month = $incoming / $age_months;

		if ( $incoming === 0 ) {
			$velocity = 0.8; // No links at all — but not 1.0 (orphan_boost handles that).
		} elseif ( $age_days > 180 && $links_per_month < 0.5 ) {
			$velocity = 0.7; // 6+ months old, less than 1 link every 2 months — stagnant.
		} elseif ( $age_days > 90 && $links_per_month < 1.0 ) {
			$velocity = 0.5; // 3+ months old, ~1 link per month — slow.
		} elseif ( $links_per_month < 1.0 ) {
			$velocity = 0.2; // Newer post, still low — slight boost.
		} else {
			$velocity = 0.0; // Growing healthily.
		}

		// 7. Graph need (0.0 – 1.0)  -  PageRank, dead ends, components.
		$graph_need = Loom_Graph::graph_need( $source, $target );

		// 8. Money page boost (0.0 – 1.0)  -  equity funnel toward conversion pages.
		$money = self::money_page_boost( $source, $target );

		// 9. GSC boost (0.0 – 1.0)  -  striking distance, impressions, CTR.
		$gsc = Loom_GSC::is_connected() ? Loom_GSC::gsc_boost( $target ) : 0.0;

		// 10. Topical authority (0.0 – 1.0)  -  how authoritative is target within its topic.
		$authority = self::topical_authority( $source, $target );

		// 11. Placement quality (0.0 – 1.0)  -  does target match early/middle content of source?
		$placement = 0.0;
		$best_para = intval( $target['best_paragraph'] ?? 0 );
		$para_sim  = floatval( $target['paragraph_sim'] ?? 0 );
		if ( $best_para > 0 && $para_sim > 0.3 ) {
			// Boost if target matches a paragraph in the first 2/3 of article.
			$total_paras = intval( $source['word_count'] ?? 500 ) / 100; // Rough para estimate.
			$position_ratio = $total_paras > 0 ? $best_para / max( 1, $total_paras ) : 0.5;
			if ( $position_ratio <= 0.33 ) {
				$placement = min( 1.0, $para_sim * 1.5 ); // First third = max boost.
			} elseif ( $position_ratio <= 0.66 ) {
				$placement = min( 1.0, $para_sim * 1.0 ); // Middle = moderate.
			} else {
				$placement = min( 1.0, $para_sim * 0.5 ); // Last third = reduced.
			}
		}

		// Composite.
		$score = ( $semantic    * $weights['semantic'] )
		       + ( $orphan      * $weights['orphan'] )
		       + ( $depth_boost * $weights['depth'] )
		       + ( $tier        * $weights['tier'] )
		       + ( $cluster     * $weights['cluster'] )
		       + ( $velocity    * $weights['equity'] )
		       + ( $graph_need  * $weights['graph'] )
		       + ( $money       * $weights['money'] )
		       + ( $gsc         * $weights['gsc'] )
		       + ( $authority   * $weights['authority'] )
		       + ( $placement   * $weights['placement'] );

		return round( $score, 4 );
	}

	/**
	 * Money page boost dimension.
	 *
	 * Funnels equity toward money pages (product/service/conversion pages).
	 * Penalizes linking FROM money pages to supporting content.
	 *
	 * @param array $source Source post row.
	 * @param array $target Target post row.
	 * @return float         0.0 – 1.0
	 */
	private static function money_page_boost( $source, $target ) {
		$target_is_money = ! empty( $target['is_money_page'] );
		$source_is_money = ! empty( $source['is_money_page'] );

		if ( ! $target_is_money && ! $source_is_money ) {
			return 0.0; // Neither is money page  -  neutral.
		}

		$score = 0.0;

		if ( $target_is_money ) {
			// Base boost: money pages should attract links.
			$score += 0.50;

			// Priority bonus (1-5 scale, higher = more important).
			$priority = intval( $target['money_priority'] ?? 1 );
			$score += $priority * 0.05; // +0.05 to +0.25

			// Deficit bonus: money page needs more links.
			$goal    = intval( $target['target_links_goal'] ?? 10 );
			$current = intval( $target['incoming_links_count'] ?? 0 );
			$deficit = max( 0, $goal - $current );

			if ( $deficit > 0 ) {
				$score += min( 0.25, $deficit * 0.05 ); // Up to +0.25 for 5+ deficit.
			} else {
				$score -= 0.20; // Already saturated  -  reduce priority.
			}
		}

		if ( $source_is_money ) {
			// Money pages should NOT leak equity to blog posts.
			if ( ! $target_is_money ) {
				$score -= 0.30;
			}
			// Exception: money -> money (cross-sell) is OK.
		}

		return max( 0.0, min( 1.0, $score ) );
	}

	/**
	 * Topical authority score for target page.
	 *
	 * Measures how authoritative a target is within its topic cluster:
	 * - Incoming links from same cluster = strong topic signal
	 * - PageRank relative to cluster average = topic leader
	 * - Keyword depth (more keywords = broader topic coverage)
	 *
	 * @param array $source Source post row.
	 * @param array $target Target post row.
	 * @return float 0.0 – 1.0
	 */
	private static function topical_authority( $source, $target ) {
		$score      = 0.0;
		$tgt_id     = intval( $target['post_id'] ?? 0 );
		$cluster_id = $target['cluster_id'] ?? null;
		$pr         = floatval( $target['internal_pagerank'] ?? 0 );
		$avg_pr     = Loom_Graph::get_avg_pr();

		// 1. Incoming links from same cluster (max 0.4).
		if ( $cluster_id ) {
			$cache_key = $tgt_id . '-' . intval( $cluster_id );
			$cluster_links = self::$cluster_link_cache[ $cache_key ] ?? 0;
			if ( ! $cluster_links ) {
				// Fallback for direct calls outside rank_targets().
				global $wpdb;
				$lnk = Loom_DB::links_table();
				$idx = Loom_DB::index_table();
				$cluster_links = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$lnk} l
					 JOIN {$idx} i ON l.source_post_id = i.post_id
					 WHERE l.target_post_id = %d AND i.cluster_id = %d",
					$tgt_id, intval( $cluster_id )
				) );
			}
			$score += min( 0.4, $cluster_links * 0.1 );
		}

		// 2. PageRank relative to average (max 0.3).
		if ( $avg_pr > 0 ) {
			$ratio = $pr / $avg_pr;
			if ( $ratio >= 3.0 ) $score += 0.3;       // 3x average = topic leader.
			elseif ( $ratio >= 1.5 ) $score += 0.2;
			elseif ( $ratio >= 1.0 ) $score += 0.1;
		}

		// 3. Keyword depth  -  more keywords = broader coverage (max 0.3).
		$kw = json_decode( $target['focus_keywords'] ?? '[]', true );
		$kw_count = is_array( $kw ) ? count( $kw ) : 0;
		if ( $kw_count >= 5 ) $score += 0.3;
		elseif ( $kw_count >= 3 ) $score += 0.2;
		elseif ( $kw_count >= 1 ) $score += 0.1;

		return max( 0.0, min( 1.0, $score ) );
	}

	/** @var array Batch caches, populated by rank_targets(). */
	private static $post_date_cache   = array();
	private static $cluster_link_cache = array();
	private static $pillar_cache       = array();
	private static $settings_cache     = null;

	/**
	 * Build TOP N targets sorted by composite score.
	 *
	 * @param array $source_data     Source post data.
	 * @param array $similar_targets Results from Loom_Similarity::find_similar().
	 * @param int   $top_n           Number of targets to return.
	 * @return array
	 */
	public static function rank_targets( $source_data, $similar_targets, $top_n = 15 ) {
		// ── Batch prefetch to eliminate N+1 queries ──
		self::$settings_cache = Loom_DB::get_settings();

		$ids = wp_list_pluck( $similar_targets, 'post_id' );
		$ids = array_map( 'intval', $ids );

		// PERF-1: Prefetch post dates (for velocity dimension).
		_prime_post_caches( $ids, false, false );
		foreach ( $ids as $pid ) {
			$p = get_post( $pid );
			self::$post_date_cache[ $pid ] = $p ? $p->post_date : null;
		}

		// PERF-2: Batch cluster links count.
		if ( ! empty( $ids ) ) {
			global $wpdb;
			$lnk = Loom_DB::links_table();
			$idx  = Loom_DB::index_table();
			$ids_str = implode( ',', $ids );
			$rows = $wpdb->get_results(
				"SELECT l.target_post_id AS pid, i.cluster_id AS cid, COUNT(*) AS cnt
				 FROM {$lnk} l
				 JOIN {$idx} i ON l.source_post_id = i.post_id
				 WHERE l.target_post_id IN ({$ids_str}) AND i.cluster_id IS NOT NULL
				 GROUP BY l.target_post_id, i.cluster_id", ARRAY_A
			); // phpcs:ignore -- $ids are intval'd above.
			foreach ( $rows as $r ) {
				$key = intval( $r['pid'] ) . '-' . intval( $r['cid'] );
				self::$cluster_link_cache[ $key ] = intval( $r['cnt'] );
			}
		}

		// PERF-3: Batch pillar page lookup.
		$cluster_ids = array_filter( array_unique( wp_list_pluck( $similar_targets, 'cluster_id' ) ) );
		if ( ! empty( $cluster_ids ) ) {
			global $wpdb;
			$cids_str = implode( ',', array_map( 'intval', $cluster_ids ) );
			$pillars = $wpdb->get_results(
				"SELECT id, pillar_post_id FROM {$wpdb->prefix}loom_clusters WHERE id IN ({$cids_str})",
				ARRAY_A
			); // phpcs:ignore
			foreach ( $pillars as $pc ) {
				self::$pillar_cache[ intval( $pc['id'] ) ] = intval( $pc['pillar_post_id'] );
			}
		}

		// ── Score each target ──
		$scored = array();

		foreach ( $similar_targets as $target ) {
			$target['composite_score'] = self::score( $source_data, $target );
			$scored[] = $target;
		}

		usort( $scored, function ( $a, $b ) {
			return $b['composite_score'] <=> $a['composite_score'];
		} );

		return array_slice( $scored, 0, $top_n );
	}

	/**
	 * Format targets into prompt text with keywords, anchors, warnings.
	 */
	public static function format_for_prompt( $targets ) {
		$out = "### RECOMMENDED TARGET PAGES\nSorted by LOOM optimization score. Priority targets marked with alerts.\n---\n\n";

		foreach ( $targets as $i => $t ) {
			$num   = $i + 1;
			$score = $t['composite_score'];
			$pid   = intval( $t['post_id'] ?? 0 );

			// Alerts.
			$alerts = array();
			if ( ! empty( $t['is_orphan'] ) ) $alerts[] = 'ORPHAN';
			if ( ! empty( $t['is_money_page'] ) ) $alerts[] = 'MONEY PAGE ⭐';
			$depth = $t['click_depth'];
			if ( $depth === null || $depth === '' ) {
				$alerts[] = 'UNREACHABLE';
			} elseif ( intval( $depth ) > 3 ) {
				$alerts[] = 'DEEP (depth ' . intval( $depth ) . ')';
			}
			$alert_str = ! empty( $alerts ) ? ' ⚠️ ' . implode( ' + ', $alerts ) : '';

			$tier_labels = array( 0 => 'Homepage', 1 => 'Pillar', 2 => 'Category', 3 => 'Article' );
			$tier_label  = $tier_labels[ intval( $t['site_tier'] ?? 3 ) ] ?? 'Article';
			$depth_str   = ( $depth !== null && $depth !== '' ) ? intval( $depth ) : 'N/A';

			$out .= "{$num}. [SCORE: {$score}]{$alert_str}\n";
			$out .= "   \"{$t['post_title']}\" | {$t['post_url']}\n";
			$out .= "   Tier: {$tier_label} | IN: {$t['incoming_links_count']} | Depth: {$depth_str}\n";

			// Keywords + anchor data via Site_Analysis.
			if ( $pid > 0 ) {
				$out .= Loom_Site_Analysis::format_for_prompt( $pid );
				$out .= Loom_Graph::target_graph_line( $t );
				$out .= Loom_GSC::target_gsc_line( $t );

				// Paragraph intent match (from paragraph-level embeddings).
				$best_para = intval( $t['best_paragraph'] ?? 0 );
				$para_sim  = floatval( $t['paragraph_sim'] ?? 0 );
				if ( $best_para > 0 && $para_sim > 0.3 ) {
					$out .= "   📍 Best match: Paragraph {$best_para} (sim: {$para_sim})\n";
				}

				// Anchor diversity analysis for ALL targets (not just money pages).
				$dist = Loom_DB::get_anchor_distribution( $pid );
				$incoming_ct = intval( $t['incoming_links_count'] ?? 0 );

				if ( ! empty( $dist ) && $incoming_ct >= 2 ) {
					// Classify anchors into categories.
					$target_kw = json_decode( $t['focus_keywords'] ?? '[]', true );
					$primary_kw = '';
					if ( is_array( $target_kw ) ) {
						foreach ( $target_kw as $kw ) {
							if ( ( $kw['type'] ?? '' ) === 'primary' ) { $primary_kw = mb_strtolower( $kw['phrase'] ); break; }
						}
					}

					$exact = 0; $partial = 0; $generic = 0; $other = 0;
					$generic_words = array( 'tutaj', 'kliknij', 'więcej', 'sprawdź', 'czytaj', 'here', 'click', 'read', 'more', 'learn', 'check' );
					foreach ( $dist as $d ) {
						$a = mb_strtolower( $d['anchor'] );
						$cnt = intval( $d['count'] );
						if ( $primary_kw && $a === $primary_kw ) { $exact += $cnt; }
						elseif ( $primary_kw && ( mb_stripos( $a, $primary_kw ) !== false || mb_stripos( $primary_kw, $a ) !== false ) ) { $partial += $cnt; }
						elseif ( self::is_generic_anchor( $a, $generic_words ) ) { $generic += $cnt; }
						else { $other += $cnt; }
					}
					$total_anchors = $exact + $partial + $generic + $other;
					if ( $total_anchors > 0 ) {
						$e_pct = round( $exact / $total_anchors * 100 );
						$p_pct = round( $partial / $total_anchors * 100 );
						$g_pct = round( $generic / $total_anchors * 100 );
						$o_pct = round( $other / $total_anchors * 100 );
						$out .= "   🔤 Anchor profile: exact {$e_pct}% | partial {$p_pct}% | contextual {$o_pct}% | generic {$g_pct}%\n";

						// Specific advice based on profile.
						if ( $e_pct >= 40 ) {
							$out .= "   ⚠️ TOO MANY EXACT MATCH  -  use partial or contextual anchor!\n";
						} elseif ( $g_pct >= 30 ) {
							$out .= "   ⚠️ TOO MANY GENERIC  -  use topical/descriptive anchor!\n";
						}
					}
				}

				// Money page specific warnings (on top of general anchor data).
				if ( ! empty( $t['is_money_page'] ) ) {
					$goal = intval( $t['target_links_goal'] ?? 10 );
					$deficit = max( 0, $goal - $incoming_ct );
					$out .= "   ⭐ MONEY PAGE  -  needs {$deficit} more links (goal: {$goal})\n";
				}
			}

			$out .= "   Preview: \"" . mb_substr( $t['preview'] ?? '', 0, 140 ) . "\"\n\n";
		}

		$out .= "---\n";
		return $out;
	}

	/**
	 * Check if an anchor text is generic/uninformative.
	 *
	 * @param string $anchor     Lowercased anchor text.
	 * @param array  $stop_words Generic words list.
	 * @return bool
	 */
	private static function is_generic_anchor( $anchor, $stop_words ) {
		$words = preg_split( '/\s+/', $anchor );
		if ( count( $words ) <= 2 ) {
			foreach ( $words as $w ) {
				if ( in_array( $w, $stop_words, true ) ) return true;
			}
		}
		return false;
	}
}