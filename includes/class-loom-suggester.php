<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Loom_Suggester {

	/**
	 * AJAX handler for "Podlinkuj" button.
	 */
	public static function ajax_podlinkuj() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Brak uprawnień.', 'loom' ), 403 );
		}

		// Rate limit: 1 request per 5 seconds per user.
		$rate_key = 'loom_rate_' . get_current_user_id();
		if ( get_transient( $rate_key ) ) {
			wp_send_json_error( __( 'Zbyt szybko. Odczekaj kilka sekund.', 'loom' ) );
		}
		set_transient( $rate_key, 1, 5 );

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( __( 'Nieprawidłowy ID posta.', 'loom' ) );
		}

		$api_key = Loom_DB::get_api_key();
		if ( empty( $api_key ) ) {
			wp_send_json_error( __( 'Dodaj klucz API OpenAI w ustawieniach.', 'loom' ) );
		}

		// Step 1: Get source post data.
		$source = Loom_DB::get_index_row( $post_id );
		if ( ! $source ) {
			// Post not scanned yet  -  scan now.
			Loom_Scanner::scan_single_post( $post_id );
			$source = Loom_DB::get_index_row( $post_id );
		}
		if ( ! $source || empty( $source['clean_text'] ) ) {
			wp_send_json_error( __( 'Nie udało się odczytać treści posta.', 'loom' ) );
		}

		// Step 2: Generate embedding if missing (same formula as batch: title 3x).
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

		// Step 3: Find similar posts (two-stage cosine).
		$all_targets = Loom_DB::get_all_with_embeddings( $post_id );
		$similar     = Loom_Similarity::find_similar( $source_emb, $all_targets, 50, 30 );

		if ( empty( $similar ) ) {
			wp_send_json_error( __( 'Brak postów z embeddingami do porównania. Wygeneruj embeddingi w ustawieniach.', 'loom' ) );
		}

		// Step 3b: Paragraph-level intent matching.
		// Generate embeddings for top paragraphs of source -> match against each target.
		// This tells GPT which paragraph best matches which target = precise placement.
		$paragraphs = self::split_paragraphs( $source['clean_text'] );
		$para_embeddings = self::get_paragraph_embeddings( $paragraphs, $api_key, 5 );

		if ( ! empty( $para_embeddings ) ) {
			foreach ( $similar as &$target ) {
				$tid = intval( $target['post_id'] ?? 0 );
				if ( $tid <= 0 ) continue;

				// Load target embedding from DB (unset during similarity search).
				$tr = Loom_DB::get_index_row( $tid );
				$target_emb = $tr ? json_decode( $tr['embedding'] ?? '[]', true ) : null;
				if ( empty( $target_emb ) ) continue;

				$best_para = 0;
				$best_sim  = 0.0;
				foreach ( $para_embeddings as $pi => $pe ) {
					$sim = Loom_Similarity::dot_product( $pe, $target_emb, 512 );
					if ( $sim > $best_sim ) {
						$best_sim  = $sim;
						$best_para = $pi + 1; // 1-based.
					}
				}
				$target['best_paragraph']    = $best_para;
				$target['paragraph_sim']     = round( $best_sim, 3 );
			}
			unset( $target );
		}

		// Step 4: Composite scoring.
		$ranked = Loom_Composite::rank_targets( $source, $similar, 15 );

		// Step 4a: Filter out rejected targets (3+ rejections).
		$rejected_ids = Loom_Site_Analysis::get_rejected_targets( $post_id );
		if ( ! empty( $rejected_ids ) ) {
			$ranked = array_values( array_filter( $ranked, function ( $t ) use ( $rejected_ids ) {
				return ! in_array( intval( $t['post_id'] ), $rejected_ids, true );
			} ) );
		}

		// Step 4b: Extract keywords for source if missing.
		if ( empty( $source['focus_keywords'] ) ) {
			Loom_Keywords::extract( $post_id, true );
			$source = Loom_DB::get_index_row( $post_id ); // Refresh.
		}

		// Step 4c: Extract keywords for top targets if missing (layers 1+2 only, cheap).
		foreach ( array_slice( $ranked, 0, 8 ) as $rt ) {
			$tid = intval( $rt['post_id'] ?? 0 );
			if ( $tid <= 0 ) continue;
			$tr = Loom_DB::get_index_row( $tid );
			if ( $tr && empty( $tr['focus_keywords'] ) ) {
				Loom_Keywords::extract( $tid, false ); // Layers 1+2 only (no API cost).
			}
			// Refresh target data with keywords.
			$fresh = Loom_DB::get_index_row( $tid );
			if ( $fresh ) {
				foreach ( $ranked as &$r ) {
					if ( intval( $r['post_id'] ) === $tid ) {
						$r['focus_keywords'] = $fresh['focus_keywords'];
						break;
					}
				}
				unset( $r );
			}
		}

		// Step 5: Build prompts.
		$system_prompt = self::get_system_prompt();
		$user_prompt   = self::build_user_prompt( $source, $ranked );
		$json_schema   = self::get_json_schema();

		// Step 6: Call GPT.
		$result = Loom_OpenAI::chat( $system_prompt, $user_prompt, $json_schema, $api_key );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		// Step 7: Validate suggestions against clean_text paragraphs.
		$suggestions = $result['suggestions'] ?? array();
		$paragraphs  = self::split_paragraphs( $source['clean_text'] );
		$validated   = self::validate_suggestions( $suggestions, $paragraphs, $post_id );

		// Step 7b: Cross-validate anchors exist in post_content (LOGIC-4 fix).
		$post = get_post( $post_id );
		$validated = array_filter( $validated, function ( $s ) use ( $post ) {
			return mb_strpos( $post->post_content, $s['anchor_text'] ) !== false;
		} );
		$validated = array_values( $validated );

		// Step 8: Analyze (Reasonable Surfer + mismatch scores).
		$analyzed = Loom_Analyzer::enrich_suggestions( $validated, count( $paragraphs ) );

		// Save content hash for race condition detection.
		$content_hash = md5( $post->post_content );

		wp_send_json_success( array(
			'suggestions'  => $analyzed,
			'analysis'     => $result['analysis'] ?? array(),
			'content_hash' => $content_hash,
			'post_id'      => $post_id,
		) );
	}

	/**
	 * System prompt (constant, English).
	 */
	public static function get_system_prompt() {
		return <<<'PROMPT'
# Identity
You are LOOM, an expert SEO internal linking analyst embedded in a WordPress plugin.
Your ONLY task is to analyze article content and suggest optimal internal links.

# Core Knowledge
You operate based on four proven SEO principles:
1. REASONABLE SURFER MODEL (Google Patent US8117209B1): Links higher in the content pass more PageRank than links lower. Links in main body content pass more value than navigation/footer. Links with visually distinct styling (bold, color) have higher click probability.
2. ANCHOR MISMATCH DEMOTION (Google API Leak 2024): Links whose anchor text doesn't semantically match the target page content are penalized.
3. TOPICAL AUTHORITY (Google Patent US20210004416A1): Internal links between semantically related pages strengthen the entire site's authority on that topic.
4. TOPIC CLUSTER MODEL: When an article belongs to a defined topic cluster, it MUST link to its pillar page.

# Analysis Process
Before suggesting links, follow this reasoning sequence:
Step 1  -  TOPIC EXTRACTION: Identify the 3-5 main topics/themes of the article.
Step 2  -  TARGET EVALUATION: Review the pre-sorted target list. For each target in the top 5, check if any natural phrase in the article could serve as anchor. If yes, mark as linkable. Then fill remaining slots from targets 6-15.
Step 3  -  ANCHOR SELECTION: Find natural phrases IN THE EXISTING TEXT. The phrase must already exist verbatim in the article  -  never invent new text.
Step 4  -  POSITION OPTIMIZATION: Prioritize placing links early in the article. Links in the first third are most valuable, middle third moderate, final third least valuable.
Step 5  -  CONFLICT CHECK: No duplicate targets, no links in headings, no links inside existing <a> tags, no overlapping anchors.

# Target Selection Strategy
Target pages are PRE-SORTED by LOOM's optimization algorithm. Pages with higher [SCORE] and warning markers are priority targets. Select primarily from the top of the list. Do NOT ignore ORPHAN, DEEP, or ⭐ MONEY PAGE markers.

# Money Page Priority
Pages marked ⭐ MONEY PAGE are conversion/revenue pages. PRIORITIZE them as link targets when semantically relevant.
- When a money page has ⚠️ ANCHOR OVER-OPTIMIZED warning: use a DIFFERENT anchor variation.
- When a money page has 🔗 Existing anchors listed: do NOT repeat any of them. Use partial match, semantic variation, or natural phrase.
- When choosing between a money page and a regular blog post of similar relevance, PREFER the money page.

# Striking Distance Priority (Google Search Console)
Pages marked 🎯 STRIKING DISTANCE (position 5-20 in Google) are close to page 1 of search results. An internal link can push them onto page 1, dramatically increasing their traffic.
- PRIORITIZE striking distance targets  -  they have the highest ROI from internal links.
- When 🔍 GSC queries are listed, use those queries to guide anchor text selection. These are the ACTUAL search terms Google associates with the page.
- When a target has high impressions but low CTR, it needs authority boost from internal links.

# Paragraph-Level Placement
When a target shows 📍 Best match: Paragraph X  -  this means LOOM has computed which paragraph of the article is semantically closest to the target.
- PREFER placing the link in or near the indicated paragraph. That's where the context matches best.
- If no natural anchor exists in that paragraph, check adjacent paragraphs (X-1, X+1).
- This is a suggestion, not a hard rule. A natural anchor in a different paragraph is better than a forced one.

# Anchor Diversity Control
When a target shows 🔤 Anchor profile  -  this is the distribution of existing anchor texts pointing to that page.
- A healthy profile: 10-15% exact match, 40-50% partial match, 20-30% contextual, 5-10% generic.
- If exact match is high (⚠️ TOO MANY EXACT MATCH): use a PARTIAL or CONTEXTUAL anchor variation.
- If generic is high (⚠️ TOO MANY GENERIC): use a TOPICAL/DESCRIPTIVE anchor.
- NEVER repeat an anchor that already exists in high concentration.

# Anchor Text Rules
- anchor_text MUST be an exact substring of the paragraph text. Do not modify, extend, or rephrase.
- Ideal length: 2-6 words. Maximum 8 words.
- NEVER use generic anchors: "click here", "read more", "this article", "learn more", "check out"
- NEVER use the exact title of the target page as anchor.
- Each target page may appear only ONCE in suggestions.
- Anchors must NOT overlap with each other in the same paragraph.
- If two potential anchors share words in the same sentence, choose the more specific one.

# Exclusion Rules
- Do NOT place links inside H1-H6 headings.
- Do NOT place links inside existing <a> tags.
- Do NOT suggest linking to pages already linked in the article.
- AVOID links in the very first sentence UNLESS it naturally introduces a key concept.
- Links inside bold/strong text ARE acceptable  -  bold phrases often make excellent anchors.

# Link Density
- Maximum 1 link per paragraph. If multiple targets match the same paragraph, choose the strongest match.
- Spread links across different paragraphs for natural distribution.
- 5-10 links for articles >1000 words, 3-5 for <1000 words.

# Output Quality
- Every suggestion must include a reason (max 15 words, format: "[article topic] -> [target topic]").
- priority: "high" if in the first third of content AND high relevance, "medium" if one condition met, "low" if final third or low relevance.
- If NO target is semantically relevant to the article, return an EMPTY suggestions array. Do not force irrelevant links.

# Examples

Example 1 (English):
Article about "dog training basics", target "Positive reinforcement techniques for puppies":
GOOD: "positive reinforcement methods" (natural phrase, partial match, in text)
BAD: "Positive reinforcement techniques for puppies" (exact title copy)
BAD: "click here to learn more" (generic, not in text)

Example 2 (Polish):
Article about "pozycjonowanie stron", target "Audyt SEO krok po kroku":
GOOD: "regularny audyt techniczny" (partial match, natural phrase existing in text)
BAD: "Audyt SEO krok po kroku" (exact title copy)
BAD: "dowiedz się więcej o audycie" (generic + phrase may not exist in text)

Example 3 (Keyword-Aware):
Target has 🎯 Keywords: "hosting wordpress" (primary). Existing anchors: "hosting" (2x).
Article paragraph contains: "Wybór odpowiedniego serwera to podstawa sukcesu."
GOOD: "odpowiedniego serwera" (semantic variant of hosting, avoids existing anchors)
BAD: "hosting wordpress" (exact primary keyword  -  over-optimization risk)
BAD: "hosting" (already used 2x  -  would create dominant anchor)

# Anchor Text Optimization (Keyword-Aware)
When a target page has 🎯 Keywords listed:
- Your anchor MUST be semantically related to the primary keyword.
- Do NOT use the exact primary keyword as anchor (use partial match or semantic variant).
- If "Existing anchors" shows 3+ similar phrases, use a COMPLETELY DIFFERENT semantic angle.
- If "⚠️ Over-optimized" warning appears, choose a different approach entirely.

Anchor distribution goals per target page:
- Exact match: max 1-2 links across entire site
- Partial match: preferred (keyword + extra context words)
- Semantic variant: encouraged (synonym, related concept)
- Generic: NEVER

When the surrounding sentence is about a different topic than the target page, do NOT place a link there even if the anchor phrase looks good.

# Common Mistakes to Avoid
- DO NOT invent anchor text that doesn't exist verbatim in the paragraph.
- DO NOT suggest the same target page twice.
- DO NOT use anchor text longer than 8 words.
- DO NOT place multiple links in the same paragraph.
- DO NOT place multiple links in the same sentence.
- DO NOT suggest a link if the semantic connection is weak or forced.
- DO NOT repeat an anchor that already exists in the target's anchor profile.
PROMPT;
	}

	/**
	 * Build dynamic user prompt.
	 */
	public static function build_user_prompt( $source, $ranked_targets ) {
		$paragraphs  = self::split_paragraphs( $source['clean_text'] );
		$total_paras = count( $paragraphs );
		$word_count  = intval( $source['word_count'] );

		// Numbered paragraphs.
		$numbered = '';
		foreach ( $paragraphs as $i => $para ) {
			$numbered .= 'Paragraph ' . ( $i + 1 ) . ': ' . $para . "\n\n";
		}

		// Existing links.
		$existing = Loom_DB::get_existing_target_urls( $source['post_id'] );
		$existing_str = '';
		foreach ( $existing as $url ) {
			$existing_str .= "- {$url}\n";
		}
		if ( empty( $existing_str ) ) {
			$existing_str = "- (none)\n";
		}

		// Targets (with keywords, anchors, warnings).
		$targets_str = Loom_Composite::format_for_prompt( $ranked_targets );

		// Stats.
		$stats     = Loom_DB::get_dashboard_stats();
		$avg_links = $stats['avg_out_links'];
		$out_count = intval( $source['outgoing_links_count'] );

		if ( $word_count < 500 ) {
			$recommended = max( 0, 3 - $out_count );
		} elseif ( $word_count < 1500 ) {
			$recommended = max( 0, 5 - $out_count );
		} else {
			$recommended = max( 0, 8 - $out_count );
		}

		// Count orphans in target list.
		$orphan_count = 0;
		$striking_count = 0;
		foreach ( $ranked_targets as $t ) {
			if ( ! empty( $t['is_orphan'] ) ) $orphan_count++;
			if ( ! empty( $t['is_striking_distance'] ) ) $striking_count++;
		}

		// Graph context for source.
		$graph_context = Loom_Graph::source_graph_context( $source );

		// GSC context (only if connected and data available).
		$gsc_context = '';
		if ( Loom_GSC::is_connected() && $striking_count > 0 ) {
			$gsc_context = "- 🎯 Striking distance targets: {$striking_count} (internal link can push to page 1!)\n";
		}

		// Settings for language.
		$settings = Loom_DB::get_settings();
		$lang     = $settings['language'] ?? 'pl';

		$prompt = <<<PROMPT
### ARTICLE TO ANALYZE
---
Title: {$source['post_title']}
URL: {$source['post_url']}
Word count: {$word_count}
Content language: {$lang}
---

{$numbered}

{$targets_str}

### EXISTING LINKS (do NOT duplicate these targets)
---
{$existing_str}---

### SITE CONTEXT
- Average outgoing links per post: {$avg_links}
- This post currently has: {$out_count} outgoing links
- Total paragraphs: {$total_paras}
- Recommended additional links: {$recommended}
- Orphan targets in list: {$orphan_count} (prioritize these)
{$gsc_context}{$graph_context}
### INSTRUCTIONS REMINDER
CRITICAL: Do NOT generate anchor text that doesn't exist verbatim in the paragraph. Every anchor_text must be a real substring you can find in the paragraph above.
Select targets from top of sorted list. Prioritize ⭐ MONEY PAGE and ⚠️ warning-marked targets.
Place most important links in the first third of the article (paragraphs 1-3).
Maximum 1 link per paragraph. Do NOT place two links in the same paragraph.
If no target is semantically relevant, return empty suggestions array.
PROMPT;

		return $prompt;
	}

	/**
	 * JSON Schema for Structured Outputs.
	 */
	public static function get_json_schema() {
		return array(
			'type'        => 'json_schema',
			'json_schema' => array(
				'name'   => 'loom_link_suggestions',
				'strict' => true,
				'schema' => array(
					'type'       => 'object',
					'properties' => array(
						'analysis'    => array(
							'type'        => 'object',
							'description' => 'Chain-of-thought reasoning. Fill this BEFORE generating suggestions.',
							'properties'  => array(
								'article_topics'   => array(
									'type'        => 'array',
									'items'       => array( 'type' => 'string' ),
									'description' => '3-5 main topics/themes identified in the article.',
								),
								'selected_targets' => array(
									'type'        => 'array',
									'description' => 'Evaluation of top targets from the sorted list.',
									'items'       => array(
										'type'       => 'object',
										'properties' => array(
											'target_rank' => array(
												'type'        => 'integer',
												'description' => 'Rank number of the target from the provided list.',
											),
											'is_linkable' => array(
												'type'        => 'boolean',
												'description' => 'true if a natural anchor phrase exists in the article text.',
											),
											'skip_reason' => array(
												'type'        => 'string',
												'description' => 'Why this target was skipped. Empty string if linkable.',
											),
										),
										'required'             => array( 'target_rank', 'is_linkable', 'skip_reason' ),
										'additionalProperties' => false,
									),
								),
							),
							'required'             => array( 'article_topics', 'selected_targets' ),
							'additionalProperties' => false,
						),
						'suggestions' => array(
							'type'        => 'array',
							'description' => 'Link suggestions. May be empty if no targets are semantically relevant.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'paragraph_number' => array(
										'type'        => 'integer',
										'description' => '1-based paragraph number from the article. Must be valid.',
									),
									'anchor_text'      => array(
										'type'        => 'string',
										'description' => 'EXACT verbatim substring from the paragraph. 2-6 words. Must exist as-is in the text.',
									),
									'target_url'       => array(
										'type'        => 'string',
										'description' => 'URL of the target page. Must be from the provided list.',
									),
									'target_title'     => array(
										'type'        => 'string',
										'description' => 'Title of the target page.',
									),
									'reason'           => array(
										'type'        => 'string',
										'description' => 'Max 15 words. Format: "[article topic] relates to [target topic]".',
									),
									'priority'         => array(
										'type'        => 'string',
										'enum'        => array( 'high', 'medium', 'low' ),
										'description' => 'high=first third + high relevance, medium=one condition, low=neither.',
									),
									'is_replacement'   => array(
										'type'        => 'boolean',
										'description' => 'true if this replaces an existing weak/generic link.',
									),
								),
								'required'             => array( 'paragraph_number', 'anchor_text', 'target_url', 'target_title', 'reason', 'priority', 'is_replacement' ),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array( 'analysis', 'suggestions' ),
					'additionalProperties' => false,
				),
			),
		);
	}

	/**
	 * Split text into paragraphs.
	 */
	public static function split_paragraphs( $text ) {
		$parts = preg_split( '/\n\s*\n|\r\n\s*\r\n/', $text );
		$result = array();
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( mb_strlen( $p ) >= 20 ) {
				$result[] = $p;
			}
		}
		if ( empty( $result ) ) {
			// Single block of text: split by sentences (every ~200 words).
			$words  = explode( ' ', $text );
			$chunks = array_chunk( $words, 200 );
			foreach ( $chunks as $c ) {
				$result[] = implode( ' ', $c );
			}
		}
		return $result;
	}

	/**
	 * Validate GPT suggestions against actual content.
	 */
	public static function validate_suggestions( $suggestions, $paragraphs, $post_id ) {
		$valid = array();
		$used_urls = array();

		foreach ( $suggestions as $s ) {
			$para_num = intval( $s['paragraph_number'] ?? 0 );

			// Check paragraph exists.
			if ( $para_num < 1 || $para_num > count( $paragraphs ) ) {
				continue;
			}

			// Check anchor text exists in paragraph.
			$para_text = $paragraphs[ $para_num - 1 ];
			if ( mb_strpos( $para_text, $s['anchor_text'] ) === false ) {
				continue;
			}

			// Check no duplicate targets.
			$url = $s['target_url'] ?? '';
			if ( in_array( $url, $used_urls, true ) ) {
				continue;
			}

			// Check anchor cannibalization  -  add warning but don't block.
			$target_id = url_to_postid( $url );
			$s['cannibalization'] = null;
			if ( $target_id > 0 ) {
				// Rejection check.
				if ( Loom_Site_Analysis::is_rejected( $post_id, $target_id, $s['anchor_text'] ) ) {
					continue;
				}
				// Cannibalization check.
				$conflict = Loom_Site_Analysis::check_cannibalization( $s['anchor_text'], $target_id );
				if ( $conflict ) {
					$s['cannibalization'] = $conflict;
				}
			}

			$used_urls[] = $url;
			$valid[]     = $s;
		}

		return $valid;
	}

	/**
	 * Generate embeddings for the top N longest paragraphs.
	 *
	 * Used for paragraph-level intent matching: finding which paragraph
	 * of the source article best matches each target page.
	 *
	 * @param array  $paragraphs All paragraphs from split_paragraphs().
	 * @param string $api_key    OpenAI API key.
	 * @param int    $max_paras  Max paragraphs to embed.
	 * @return array             [ paragraph_index => embedding_vector ]
	 */
	private static function get_paragraph_embeddings( $paragraphs, $api_key, $max_paras = 5 ) {
		if ( empty( $paragraphs ) || empty( $api_key ) ) return array();

		// Pick top N longest paragraphs (short ones aren't worth embedding).
		$indexed = array();
		foreach ( $paragraphs as $i => $p ) {
			$wc = str_word_count( $p );
			if ( $wc >= 20 ) {
				$indexed[] = array( 'index' => $i, 'text' => $p, 'wc' => $wc );
			}
		}
		usort( $indexed, function( $a, $b ) { return $b['wc'] <=> $a['wc']; } );
		$to_embed = array_slice( $indexed, 0, $max_paras );

		if ( empty( $to_embed ) ) return array();

		// Single batch API call instead of N separate calls.
		$texts = array_map( function( $item ) {
			return mb_substr( $item['text'], 0, 500 );
		}, $to_embed );

		$vectors = Loom_OpenAI::get_embeddings_batch( $texts, $api_key );
		if ( is_wp_error( $vectors ) || empty( $vectors ) ) return array();

		$result = array();
		foreach ( $to_embed as $i => $item ) {
			if ( ! empty( $vectors[ $i ] ) ) {
				$result[ $item['index'] ] = $vectors[ $i ];
			}
		}

		return $result;
	}
}
