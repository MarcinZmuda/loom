<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Loom_Keywords  -  Self-sufficient keyword extraction (no Yoast/Rank Math dependency).
 *
 * Layer 0: SEO plugin import (optional bonus if Yoast/Rank Math present)
 * Layer 1: Title + H2/H3 headings (free, instant)
 * Layer 2: TF-IDF over corpus with bigram support (free, ~5ms with cache)
 * Layer 3: GPT extraction (gpt-4o-mini, ~$0.001/post)
 */
class Loom_Keywords {

	/* ================================================================
	   MASTER: extract keywords from all layers
	   ================================================================ */

	/**
	 * Extract focus keywords for a post using up to 3 layers.
	 *
	 * @param int  $post_id  The post ID.
	 * @param bool $use_api  Whether to invoke GPT (layer 3).
	 * @return array          Array of keyword objects.
	 */
	public static function extract( $post_id, $use_api = false ) {
		$merged = array();

		// Layer 0: Import from SEO plugin (optional bonus).
		$seo_kw = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
		if ( empty( $seo_kw ) ) {
			$seo_kw = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
		}
		if ( ! empty( $seo_kw ) ) {
			$merged[] = array(
				'phrase' => mb_strtolower( trim( $seo_kw ) ),
				'type'   => 'primary',
				'source' => 'seo_plugin',
				'score'  => 1.0,
			);
		}

		// Layer 1: Title + headings (always, free, instant).
		foreach ( self::from_title_headings( $post_id ) as $phrase => $score ) {
			if ( ! self::phrase_already_covered( $phrase, $merged ) ) {
				$merged[] = array(
					'phrase' => $phrase,
					'type'   => empty( $merged ) ? 'primary' : 'secondary',
					'source' => 'title',
					'score'  => min( 1.0, $score / 12 ),
				);
			}
		}

		// Layer 2: TF-IDF (needs >= 5 posts in index).
		if ( self::indexed_posts_count() >= 5 ) {
			$tfidf  = self::tfidf( $post_id, 5 );
			$max_tf = ! empty( $tfidf ) ? max( $tfidf ) : 1;
			foreach ( $tfidf as $phrase => $score ) {
				if ( ! self::phrase_already_covered( $phrase, $merged ) ) {
					$merged[] = array(
						'phrase' => $phrase,
						'type'   => 'secondary',
						'source' => 'tfidf',
						'score'  => min( 1.0, $score / $max_tf ),
					);
				}
			}
		}

		// Layer 3: GPT extraction (if API key and requested).
		if ( $use_api && ! empty( Loom_DB::get_api_key() ) ) {
			$gpt_kw = self::from_gpt( $post_id );
			if ( is_array( $gpt_kw ) ) {
				foreach ( $gpt_kw as $kw ) {
					if ( ! self::phrase_already_covered( $kw['phrase'], $merged ) ) {
						$merged[] = array(
							'phrase' => mb_strtolower( $kw['phrase'] ),
							'type'   => $kw['type'] ?? 'semantic',
							'source' => 'gpt',
							'score'  => ( $kw['type'] ?? '' ) === 'primary' ? 0.95 : 0.75,
						);
					}
				}
			}
		}

		// Ensure at least one primary.
		$has_primary = false;
		foreach ( $merged as $m ) {
			if ( $m['type'] === 'primary' ) { $has_primary = true; break; }
		}
		if ( ! $has_primary && ! empty( $merged ) ) {
			$merged[0]['type'] = 'primary';
		}

		// Sort by score, limit to 5.
		usort( $merged, function ( $a, $b ) { return $b['score'] <=> $a['score']; } );
		$merged = array_slice( $merged, 0, 5 );

		// Save to index.
		global $wpdb;
		$wpdb->update(
			Loom_DB::index_table(),
			array( 'focus_keywords' => wp_json_encode( $merged ) ),
			array( 'post_id' => $post_id )
		);

		return $merged;
	}

	/* ================================================================
	   LAYER 1: Title + Headings
	   ================================================================ */

	/**
	 * Extract keywords from post title and H2/H3 headings.
	 *
	 * @param int $post_id
	 * @return array<string, int> phrase => score (sorted desc, max 3).
	 */
	public static function from_title_headings( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) return array();

		$stop  = self::get_stop_words();
		$words = self::tokenize( mb_strtolower( $post->post_title ), $stop );
		if ( empty( $words ) ) return array();

		// Build n-grams (1, 2, 3 words) from title.
		$ngrams = array();
		$w      = array_values( $words );
		foreach ( $w as $x ) $ngrams[] = $x;
		for ( $i = 0; $i < count( $w ) - 1; $i++ ) $ngrams[] = $w[ $i ] . ' ' . $w[ $i + 1 ];
		for ( $i = 0; $i < count( $w ) - 2; $i++ ) $ngrams[] = $w[ $i ] . ' ' . $w[ $i + 1 ] . ' ' . $w[ $i + 2 ];

		// Parse H2/H3 headings.
		preg_match_all( '/<h[23][^>]*>(.+?)<\/h[23]>/is', $post->post_content, $matches );
		$headings_text = mb_strtolower( implode( ' ', array_map( 'wp_strip_all_tags', $matches[1] ?? array() ) ) );
		$body_text     = mb_strtolower( wp_strip_all_tags( $post->post_content ) );

		// Score each n-gram.
		$scored = array();
		foreach ( $ngrams as $phrase ) {
			$score = 3; // Base: in title.
			$wc = self::count_words( $phrase );
			if ( $wc === 2 ) $score += 2;
			if ( $wc === 3 ) $score += 1;
			if ( mb_strpos( $headings_text, $phrase ) !== false ) $score += 3;
			$score += min( 3, mb_substr_count( $body_text, $phrase ) );
			$scored[ $phrase ] = $score;
		}

		arsort( $scored );
		return array_slice( $scored, 0, 3, true );
	}

	/* ================================================================
	   LAYER 2: TF-IDF over corpus
	   ================================================================ */

	/**
	 * Extract keywords using TF-IDF with bigram support.
	 *
	 * @param int $post_id
	 * @param int $top_n   Number of keywords to return.
	 * @return array<string, float> phrase => TF-IDF score (sorted desc).
	 */
	public static function tfidf( $post_id, $top_n = 5 ) {
		global $wpdb;
		$table = Loom_DB::index_table();

		$source_text = $wpdb->get_var( $wpdb->prepare(
			"SELECT clean_text FROM {$table} WHERE post_id = %d", $post_id
		) );
		if ( empty( $source_text ) ) return array();

		$stop   = self::get_stop_words();
		$tokens = self::tokenize( $source_text, $stop );
		if ( empty( $tokens ) ) return array();

		// Term frequency in source document.
		$tf     = array_count_values( $tokens );
		$max_tf = max( $tf ) ?: 1;

		// Get or build DF cache.
		$df_cache = get_option( 'loom_df_cache', array() );
		$df_total = intval( get_option( 'loom_df_total', 0 ) );
		if ( empty( $df_cache ) || $df_total < 2 ) {
			self::build_df_cache();
			$df_cache = get_option( 'loom_df_cache', array() );
			$df_total = intval( get_option( 'loom_df_total', 0 ) );
		}
		if ( $df_total < 2 ) return array();

		// TF-IDF for unigrams.
		$results = array();
		foreach ( $tf as $term => $freq ) {
			if ( mb_strlen( $term ) < 3 ) continue;
			$df    = $df_cache[ $term ] ?? 1;
			$score = ( $freq / $max_tf ) * log( $df_total / max( 1, $df ) );
			if ( $score > 0.1 ) {
				$results[ $term ] = round( $score, 4 );
			}
		}

		// TF-IDF for bigrams with 1.5x boost.
		// LOGIC-2 FIX: Use actual bigram DF from cache (not min of unigram DFs).
		$bigrams   = self::make_bigrams( $tokens );
		$bigram_tf = array_count_values( $bigrams );
		$max_bg    = ! empty( $bigram_tf ) ? max( $bigram_tf ) : 1;

		foreach ( $bigram_tf as $bigram => $freq ) {
			$df    = $df_cache[ $bigram ] ?? 1; // Actual bigram DF from cache.
			$score = ( $freq / $max_bg ) * log( $df_total / max( 1, $df ) ) * 1.5;
			if ( $score > 0.1 && mb_strlen( $bigram ) > 5 ) {
				$results[ $bigram ] = round( $score, 4 );
			}
		}

		// Filter noise.
		$results = array_filter( $results, function ( $score, $term ) {
			return ! is_numeric( $term ) && mb_strlen( $term ) > 2;
		}, ARRAY_FILTER_USE_BOTH );

		arsort( $results );
		return array_slice( $results, 0, $top_n, true );
	}

	/**
	 * Build Document Frequency cache (unigrams + bigrams).
	 * PERF-3/4 FIX: Process in batches of 100 to limit memory.
	 */
	public static function build_df_cache() {
		global $wpdb;
		$table = Loom_DB::index_table();
		$stop  = self::get_stop_words();
		$df    = array();
		$total = 0;

		$batch_size = 100;
		$offset     = 0;

		while ( true ) {
			$batch = $wpdb->get_col( $wpdb->prepare(
				"SELECT clean_text FROM {$table}
				 WHERE clean_text IS NOT NULL AND clean_text != ''
				 ORDER BY id ASC
				 LIMIT %d OFFSET %d",
				$batch_size, $offset
			) );

			if ( empty( $batch ) ) break;

			foreach ( $batch as $text ) {
				$tokens = self::tokenize( $text, $stop );

				// Unigram DF.
				foreach ( array_unique( $tokens ) as $w ) {
					if ( mb_strlen( $w ) >= 3 ) {
						$df[ $w ] = ( $df[ $w ] ?? 0 ) + 1;
					}
				}

				// Bigram DF (LOGIC-2 FIX: store actual bigram frequency).
				foreach ( array_unique( self::make_bigrams( $tokens ) ) as $bg ) {
					$df[ $bg ] = ( $df[ $bg ] ?? 0 ) + 1;
				}

				$total++;
			}

			$offset += $batch_size;
		}

		// Keep only terms appearing in 2+ documents (reduces size).
		$df = array_filter( $df, function ( $count ) { return $count >= 2; } );

		update_option( 'loom_df_cache', $df, false ); // autoload = false.
		update_option( 'loom_df_total', $total );
	}

	/* ================================================================
	   LAYER 3: GPT extraction
	   ================================================================ */

	/**
	 * Extract keywords via GPT-4o-mini with Structured Outputs.
	 *
	 * @param int $post_id
	 * @return array|WP_Error Array of keyword objects or error.
	 */
	public static function from_gpt( $post_id ) {
		$row = Loom_DB::get_index_row( $post_id );
		if ( ! $row ) return array();

		$input = $row['post_title'] . "\n\n" . mb_substr( $row['clean_text'] ?? '', 0, 2000 );

		$schema = array(
			'type'        => 'json_schema',
			'json_schema' => array(
				'name'   => 'keyword_extraction',
				'strict' => true,
				'schema' => array(
					'type'       => 'object',
					'properties' => array(
						'keywords' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'phrase' => array( 'type' => 'string' ),
									'type'   => array( 'type' => 'string', 'enum' => array( 'primary', 'secondary', 'semantic' ) ),
								),
								'required'             => array( 'phrase', 'type' ),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array( 'keywords' ),
					'additionalProperties' => false,
				),
			),
		);

		$system = 'Extract 3-5 focus keywords. primary=most important, secondary=supporting, semantic=synonyms. Respond in content language.';
		$result = Loom_OpenAI::chat( $system, $input, $schema );

		return is_wp_error( $result ) ? array() : ( $result['keywords'] ?? array() );
	}

	/* ================================================================
	   AJAX: batch extract keywords
	   ================================================================ */

	/**
	 * AJAX handler for batch keyword extraction.
	 */
	public static function ajax_extract_keywords() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		$use_api = ! empty( $_POST['use_api'] );

		global $wpdb;
		$table = Loom_DB::index_table();

		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id FROM {$table}
			 WHERE (focus_keywords IS NULL OR focus_keywords = '')
			 AND clean_text IS NOT NULL AND clean_text != ''
			 LIMIT %d",
			10
		), ARRAY_A );

		$remaining = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}
			 WHERE (focus_keywords IS NULL OR focus_keywords = '')
			 AND clean_text IS NOT NULL AND clean_text != ''"
		);

		$processed = 0;
		foreach ( $posts as $p ) {
			self::extract( intval( $p['post_id'] ), $use_api );
			$processed++;
		}

		wp_send_json_success( array(
			'processed' => $processed,
			'remaining' => max( 0, $remaining - $processed ),
			'status'    => ( $remaining - $processed ) <= 0 ? 'complete' : 'next',
		) );
	}

	/* ================================================================
	   HELPERS
	   ================================================================ */

	/** @var int|null Per-request cache for indexed posts count. */
	private static $posts_count_cache = null;

	/**
	 * Cheap indexed-posts count (replaces full get_dashboard_stats() call).
	 *
	 * @return int
	 */
	public static function indexed_posts_count() {
		if ( self::$posts_count_cache === null ) {
			global $wpdb;
			self::$posts_count_cache = (int) $wpdb->get_var(
				'SELECT COUNT(*) FROM ' . Loom_DB::index_table()
			);
		}
		return self::$posts_count_cache;
	}

	/**
	 * Multibyte-safe word count.
	 *
	 * str_word_count() is not UTF-8 aware  -  Polish/German diacritics split
	 * words and inflate counts. Counts sequences of letters/digits instead.
	 *
	 * @param string $text Input text.
	 * @return int
	 */
	public static function count_words( $text ) {
		if ( ! is_string( $text ) || $text === '' ) {
			return 0;
		}
		return (int) preg_match_all( '/[\p{L}\p{N}]+/u', $text );
	}

	/**
	 * Tokenize text: lowercase, remove non-alpha, filter stop words.
	 *
	 * @param string $text      Input text.
	 * @param array  $stop_words Stop word list.
	 * @return string[]          Clean tokens.
	 */
	private static function tokenize( $text, $stop_words ) {
		$text  = mb_strtolower( $text );
		$text  = preg_replace( '/[^a-ząćęłńóśźżäöüß\s]/u', ' ', $text );
		$words = preg_split( '/\s+/', trim( $text ) );
		return array_values( array_filter( $words, function ( $w ) use ( $stop_words ) {
			return mb_strlen( $w ) > 2 && ! in_array( $w, $stop_words, true );
		} ) );
	}

	/**
	 * Build bigrams from token array.
	 *
	 * @param string[] $tokens
	 * @return string[]
	 */
	private static function make_bigrams( $tokens ) {
		$bigrams = array();
		for ( $i = 0; $i < count( $tokens ) - 1; $i++ ) {
			$bigrams[] = $tokens[ $i ] . ' ' . $tokens[ $i + 1 ];
		}
		return $bigrams;
	}

	/**
	 * Check if a phrase is already covered by existing keywords (substring match).
	 *
	 * @param string $phrase  Candidate phrase.
	 * @param array  $list    Existing keyword list.
	 * @return bool
	 */
	private static function phrase_already_covered( $phrase, $list ) {
		foreach ( $list as $item ) {
			$existing = $item['phrase'];
			if ( $existing === $phrase
				|| mb_stripos( $existing, $phrase ) !== false
				|| mb_stripos( $phrase, $existing ) !== false
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Polish + English stop words.
	 *
	 * @return string[]
	 */
	public static function get_stop_words() {
		return array(
			// Polish.
			'aby', 'ale', 'aż', 'bez', 'bo', 'by', 'być', 'był', 'była', 'było', 'były',
			'będzie', 'co', 'czy', 'czyli', 'dla', 'do', 'go', 'i', 'ich', 'jak', 'już',
			'jest', 'jego', 'jej', 'jako', 'każdy', 'kiedy', 'kto', 'która', 'które', 'który',
			'ku', 'lecz', 'lub', 'ma', 'mi', 'między', 'mnie', 'może', 'można', 'mu', 'my',
			'na', 'nad', 'nam', 'nas', 'nie', 'nich', 'nim', 'niż', 'no', 'nasz', 'od', 'on',
			'ona', 'one', 'oni', 'ono', 'oraz', 'po', 'pod', 'ponieważ', 'przed', 'przez',
			'przy', 'roku', 'się', 'są', 'ta', 'tak', 'to', 'ten', 'tej', 'tego', 'tu', 'ty',
			'tych', 'tylko', 'tym', 'u', 'w', 'we', 'więc', 'wszystko', 'z', 'za', 'ze', 'że',
			'żeby',
			// English.
			'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had',
			'do', 'does', 'did', 'will', 'would', 'could', 'should', 'can', 'this', 'that',
			'for', 'and', 'but', 'or', 'not', 'with', 'from', 'about', 'it', 'its', 'he',
			'she', 'they', 'we', 'you', 'your', 'our', 'their', 'his', 'her', 'my', 'of',
			'in', 'on', 'at', 'by', 'as', 'to',
		);
	}
}
