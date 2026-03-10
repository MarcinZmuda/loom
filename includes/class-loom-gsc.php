<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Loom_GSC  -  Google Search Console integration.
 *
 * Auth: Service Account (JSON key file). Zero OAuth dance.
 * User flow: paste JSON -> connected. That's it.
 *
 * Internally: JSON key -> JWT signed with RS256 -> exchange for access_token.
 * Zero external dependencies  -  pure openssl_sign + wp_remote_post.
 */
class Loom_GSC {

	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const API_BASE  = 'https://www.googleapis.com/webmasters/v3/';
	const SCOPE     = 'https://www.googleapis.com/auth/webmasters.readonly';

	/* ================================================================
	   SERVICE ACCOUNT AUTH  -  JWT -> Access Token
	   ================================================================ */

	/**
	 * Check if GSC is connected (service account stored).
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$sa = self::get_service_account();
		return ! empty( $sa['client_email'] ) && ! empty( $sa['private_key'] );
	}

	/**
	 * Get valid access token via Service Account JWT.
	 *
	 * Flow: build JWT claim -> sign with private_key (RS256) -> POST to Google -> access_token.
	 * Cached in transient for ~55 minutes.
	 *
	 * @return string|WP_Error Access token or error.
	 */
	public static function get_access_token() {
		// Check transient cache first.
		$cached = get_transient( 'loom_gsc_access_token' );
		if ( $cached ) return $cached;

		$sa = self::get_service_account();
		if ( empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
			return new WP_Error( 'no_sa', __( 'GSC nie połączony. Wklej JSON Service Account w ustawieniach.', 'loom' ) );
		}

		// Build JWT.
		$now    = time();
		$header = self::base64url_encode( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claim  = self::base64url_encode( wp_json_encode( array(
			'iss'   => $sa['client_email'],
			'scope' => self::SCOPE,
			'aud'   => self::TOKEN_URL,
			'exp'   => $now + 3600,
			'iat'   => $now,
		) ) );

		$signature_input = $header . '.' . $claim;

		// Sign with private key (RS256 = RSASSA-PKCS1-v1_5 using SHA-256).
		$signed = '';
		$ok     = openssl_sign( $signature_input, $signed, $sa['private_key'], 'SHA256' );
		if ( ! $ok ) {
			return new WP_Error( 'sign_error', __( 'Nie udało się podpisać JWT. Sprawdź private_key w JSON.', 'loom' ) );
		}

		$jwt = $signature_input . '.' . self::base64url_encode( $signed );

		// Exchange JWT for access token.
		$response = wp_remote_post( self::TOKEN_URL, array(
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) return $response;

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['error'] ) ) {
			return new WP_Error( 'token_error', ( $body['error_description'] ?? $body['error'] ) );
		}

		$token   = $body['access_token'] ?? '';
		$expires = intval( $body['expires_in'] ?? 3600 );

		if ( empty( $token ) ) {
			return new WP_Error( 'no_token', __( 'Google nie zwrócił access token.', 'loom' ) );
		}

		// Cache for slightly less than expiry.
		set_transient( 'loom_gsc_access_token', $token, $expires - 60 );

		return $token;
	}

	/* ================================================================
	   SERVICE ACCOUNT STORAGE (encrypted)
	   ================================================================ */

	/**
	 * Save service account JSON (encrypted).
	 *
	 * @param string $json_string Raw JSON from downloaded key file.
	 * @return true|WP_Error
	 */
	public static function save_service_account( $json_string ) {
		$data = json_decode( $json_string, true );
		if ( ! $data || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
			return new WP_Error( 'invalid_json', __( 'Nieprawidłowy JSON. Potrzebne pola: client_email, private_key.', 'loom' ) );
		}

		// Validate it's actually a service account key.
		if ( ( $data['type'] ?? '' ) !== 'service_account' ) {
			return new WP_Error( 'wrong_type', __( 'To nie jest klucz Service Account. Sprawdź typ w JSON.', 'loom' ) );
		}

		// Store only what we need, encrypted.
		$to_store = wp_json_encode( array(
			'client_email' => $data['client_email'],
			'private_key'  => $data['private_key'],
			'project_id'   => $data['project_id'] ?? '',
		) );

		$key = wp_salt( 'auth' );
		$iv  = openssl_random_pseudo_bytes( 16 );
		$enc = openssl_encrypt( $to_store, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		update_option( 'loom_gsc_service_account', base64_encode( $iv . $enc ) );

		// Clear any cached tokens.
		delete_transient( 'loom_gsc_access_token' );

		return true;
	}

	/**
	 * Get stored service account data (decrypted).
	 *
	 * @return array { client_email, private_key, project_id } or empty.
	 */
	private static function get_service_account() {
		$stored = get_option( 'loom_gsc_service_account', '' );
		if ( empty( $stored ) ) return array();

		$key = wp_salt( 'auth' );
		$raw = base64_decode( $stored );
		if ( strlen( $raw ) <= 16 ) return array();

		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$dec    = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		if ( $dec === false ) return array();
		return json_decode( $dec, true ) ?: array();
	}

	/**
	 * Get the service account email (for display / GSC user add).
	 *
	 * @return string Email or empty.
	 */
	public static function get_sa_email() {
		$sa = self::get_service_account();
		return $sa['client_email'] ?? '';
	}

	/**
	 * Disconnect GSC (remove service account + tokens).
	 */
	public static function disconnect() {
		delete_option( 'loom_gsc_service_account' );
		delete_option( 'loom_gsc_site_url' );
		delete_transient( 'loom_gsc_access_token' );
	}

	/* ================================================================
	   DATA FETCHING  -  Search Analytics API
	   ================================================================ */

	/**
	 * Fetch per-page performance data from GSC (last 28 days).
	 *
	 * @return array|WP_Error [ url => { clicks, impressions, ctr, position } ]
	 */
	public static function fetch_page_performance() {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) return $token;

		$site_url   = self::get_site_url();
		$end_date   = gmdate( 'Y-m-d', strtotime( '-3 days' ) );
		$start_date = gmdate( 'Y-m-d', strtotime( '-31 days' ) );

		$all_rows  = array();
		$start_row = 0;

		do {
			$body = array(
				'startDate'  => $start_date,
				'endDate'    => $end_date,
				'dimensions' => array( 'page' ),
				'rowLimit'   => 25000,
				'startRow'   => $start_row,
			);

			$result = self::api_request( "sites/{$site_url}/searchAnalytics/query", $body, $token );
			if ( is_wp_error( $result ) ) return $result;

			$rows = $result['rows'] ?? array();
			$all_rows = array_merge( $all_rows, $rows );
			$start_row += 25000;
		} while ( count( $rows ) >= 25000 );

		$pages = array();
		foreach ( $all_rows as $row ) {
			$url = $row['keys'][0] ?? '';
			$pages[ $url ] = array(
				'clicks'      => intval( $row['clicks'] ?? 0 ),
				'impressions' => intval( $row['impressions'] ?? 0 ),
				'ctr'         => round( floatval( $row['ctr'] ?? 0 ), 4 ),
				'position'    => round( floatval( $row['position'] ?? 0 ), 2 ),
			);
		}

		return $pages;
	}

	/**
	 * Fetch top queries per page from GSC.
	 *
	 * @param array $page_urls URLs to fetch queries for.
	 * @return array|WP_Error [ url => [ { query, clicks, impressions, position } ] ]
	 */
	public static function fetch_page_queries( $page_urls ) {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) return $token;

		$site_url   = self::get_site_url();
		$end_date   = gmdate( 'Y-m-d', strtotime( '-3 days' ) );
		$start_date = gmdate( 'Y-m-d', strtotime( '-31 days' ) );
		$result_map = array();

		foreach ( $page_urls as $url ) {
			$body = array(
				'startDate'             => $start_date,
				'endDate'               => $end_date,
				'dimensions'            => array( 'query' ),
				'dimensionFilterGroups' => array(
					array(
						'filters' => array(
							array(
								'dimension'  => 'page',
								'expression' => $url,
							),
						),
					),
				),
				'rowLimit' => 20,
			);

			$result = self::api_request( "sites/{$site_url}/searchAnalytics/query", $body, $token );
			if ( is_wp_error( $result ) ) continue;

			$queries = array();
			foreach ( $result['rows'] ?? array() as $row ) {
				$queries[] = array(
					'query'       => $row['keys'][0] ?? '',
					'clicks'      => intval( $row['clicks'] ?? 0 ),
					'impressions' => intval( $row['impressions'] ?? 0 ),
					'position'    => round( floatval( $row['position'] ?? 0 ), 1 ),
				);
			}
			$result_map[ $url ] = $queries;

			usleep( 250000 ); // Rate limit: ~4 req/s.
		}

		return $result_map;
	}

	/* ================================================================
	   SYNC  -  Save GSC data to loom_index
	   ================================================================ */

	/**
	 * Full GSC sync.
	 *
	 * @return array|WP_Error Stats about sync.
	 */
	public static function sync() {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'not_connected', __( 'GSC nie połączony.', 'loom' ) );
		}

		global $wpdb;
		$table = Loom_DB::index_table();

		$pages = self::fetch_page_performance();
		if ( is_wp_error( $pages ) ) return $pages;

		$index_rows = $wpdb->get_results( "SELECT post_id, post_url FROM {$table}", ARRAY_A );
		$url_to_pid = array();
		foreach ( $index_rows as $r ) {
			$url_to_pid[ untrailingslashit( $r['post_url'] ) ] = intval( $r['post_id'] );
			$url_to_pid[ trailingslashit( $r['post_url'] ) ]   = intval( $r['post_id'] );
		}

		$updated     = 0;
		$striking    = 0;
		$matched_urls = array();

		foreach ( $pages as $gsc_url => $data ) {
			$norm_url = untrailingslashit( $gsc_url );
			$pid = $url_to_pid[ $norm_url ] ?? $url_to_pid[ trailingslashit( $norm_url ) ] ?? 0;
			if ( ! $pid ) continue;

			$is_striking = ( $data['position'] >= 5 && $data['position'] <= 20 ) ? 1 : 0;
			if ( $is_striking ) $striking++;

			$wpdb->update( $table, array(
				'gsc_clicks'           => $data['clicks'],
				'gsc_impressions'      => $data['impressions'],
				'gsc_ctr'              => $data['ctr'],
				'gsc_position'         => $data['position'],
				'is_striking_distance' => $is_striking,
				'last_gsc_sync'        => current_time( 'mysql' ),
			), array( 'post_id' => $pid ) );

			$matched_urls[ $gsc_url ] = $pid;
			$updated++;
		}

		// Fetch queries for top 50 pages.
		$top_pages = array_slice( array_keys( $matched_urls ), 0, 50 );
		if ( ! empty( $top_pages ) ) {
			$queries_map = self::fetch_page_queries( $top_pages );
			if ( ! is_wp_error( $queries_map ) ) {
				foreach ( $queries_map as $url => $queries ) {
					$pid = $matched_urls[ $url ] ?? 0;
					if ( ! $pid || empty( $queries ) ) continue;
					$wpdb->update( $table, array(
						'gsc_top_queries' => wp_json_encode( array_slice( $queries, 0, 10 ) ),
					), array( 'post_id' => $pid ) );
				}
			}
		}

		Loom_DB::log( 'gsc_sync', null, array( 'pages_updated' => $updated, 'striking' => $striking ) );

		return array( 'updated' => $updated, 'striking' => $striking, 'total' => count( $pages ) );
	}

	/* ================================================================
	   SCORING  -  Integration with Composite Score
	   ================================================================ */

	/**
	 * GSC boost score for composite scoring.
	 *
	 * @param array $target Target post row from loom_index.
	 * @return float 0.0 – 1.0
	 */
	public static function gsc_boost( $target ) {
		$score    = 0.0;
		$position = floatval( $target['gsc_position'] ?? 0 );
		$impr     = intval( $target['gsc_impressions'] ?? 0 );
		$striking = ! empty( $target['is_striking_distance'] );

		if ( $position <= 0 ) return 0.0;

		if ( $striking ) {
			$score += 0.40;
			if ( $position >= 8 && $position <= 15 ) $score += 0.15;
		}

		if ( $impr > 500 )  $score += 0.10;
		if ( $impr > 2000 ) $score += 0.10;

		$ctr = floatval( $target['gsc_ctr'] ?? 0 );
		if ( $impr > 100 && $ctr < 0.02 ) $score += 0.15;

		if ( $position > 0 && $position <= 3 ) $score -= 0.20;

		return max( 0.0, min( 1.0, $score ) );
	}

	/**
	 * Format GSC data for GPT prompt.
	 *
	 * @param array $target Target post row.
	 * @return string
	 */
	public static function target_gsc_line( $target ) {
		$position = floatval( $target['gsc_position'] ?? 0 );
		if ( $position <= 0 ) return '';

		$clicks   = intval( $target['gsc_clicks'] ?? 0 );
		$impr     = intval( $target['gsc_impressions'] ?? 0 );
		$ctr_pct  = round( floatval( $target['gsc_ctr'] ?? 0 ) * 100, 1 );
		$striking = ! empty( $target['is_striking_distance'] );

		$parts = array();
		$parts[] = 'Pos: ' . number_format( $position, 1 );
		$parts[] = 'Impr: ' . number_format( $impr );
		$parts[] = 'CTR: ' . $ctr_pct . '%';
		if ( $striking ) $parts[] = '🎯 STRIKING DISTANCE  -  internal link can push to page 1';

		$line = '   📈 GSC: ' . implode( ' | ', $parts ) . "\n";

		$queries = $target['gsc_top_queries'] ?? '';
		if ( ! empty( $queries ) ) {
			$parsed = is_string( $queries ) ? json_decode( $queries, true ) : $queries;
			if ( is_array( $parsed ) && ! empty( $parsed ) ) {
				$top3 = array_slice( $parsed, 0, 3 );
				$qstr = array_map( function( $q ) {
					return '"' . $q['query'] . '" (pos ' . $q['position'] . ')';
				}, $top3 );
				$line .= '   🔍 GSC queries: ' . implode( ', ', $qstr ) . "\n";
			}
		}

		return $line;
	}

	/**
	 * Merge GSC queries into focus_keywords.
	 *
	 * @param int $post_id
	 * @return array Updated keywords.
	 */
	public static function enrich_keywords_from_gsc( $post_id ) {
		global $wpdb;
		$table = Loom_DB::index_table();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT focus_keywords, gsc_top_queries FROM {$table} WHERE post_id = %d", $post_id
		), ARRAY_A );
		if ( ! $row ) return array();

		$existing = json_decode( $row['focus_keywords'] ?? '[]', true ) ?: array();
		$queries  = json_decode( $row['gsc_top_queries'] ?? '[]', true ) ?: array();
		if ( empty( $queries ) ) return $existing;

		$changed = false;
		foreach ( array_slice( $queries, 0, 3 ) as $q ) {
			$phrase = mb_strtolower( trim( $q['query'] ) );
			if ( mb_strlen( $phrase ) < 3 ) continue;

			$covered = false;
			foreach ( $existing as $kw ) {
				if ( $kw['phrase'] === $phrase || mb_stripos( $kw['phrase'], $phrase ) !== false || mb_stripos( $phrase, $kw['phrase'] ) !== false ) {
					$covered = true;
					break;
				}
			}

			if ( ! $covered ) {
				$existing[] = array(
					'phrase' => $phrase,
					'type'   => count( $existing ) === 0 ? 'primary' : 'secondary',
					'source' => 'gsc',
					'score'  => 0.90,
				);
				$changed = true;
			}
		}

		if ( $changed ) {
			usort( $existing, function( $a, $b ) { return $b['score'] <=> $a['score']; } );
			$existing = array_slice( $existing, 0, 7 );
			$wpdb->update( $table, array(
				'focus_keywords' => wp_json_encode( $existing ),
			), array( 'post_id' => $post_id ) );
		}

		return $existing;
	}

	/* ================================================================
	   API REQUEST HELPER
	   ================================================================ */

	private static function api_request( $endpoint, $body, $token ) {
		$url = self::API_BASE . $endpoint;

		$response = wp_remote_post( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) return $response;

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( $code === 401 || $code === 403 ) {
			delete_transient( 'loom_gsc_access_token' );
			$err = json_decode( $raw, true );
			$msg = $err['error']['message'] ?? "HTTP {$code}";
			return new WP_Error( 'auth_error', $msg . '  -  ' . __( 'Sprawdź czy email SA jest dodany jako użytkownik w GSC.', 'loom' ) );
		}
		if ( $code !== 200 ) {
			$err = json_decode( $raw, true );
			return new WP_Error( 'gsc_error', $err['error']['message'] ?? "HTTP {$code}" );
		}

		return json_decode( $raw, true );
	}

	/* ================================================================
	   HELPERS
	   ================================================================ */

	private static function get_site_url() {
		$url = get_option( 'loom_gsc_site_url', '' );
		if ( empty( $url ) ) $url = home_url();
		// Normalize: trim whitespace, ensure trailing slash, then encode.
		$url = rtrim( trim( $url ), '/' ) . '/';
		return urlencode( $url );
	}

	/**
	 * URL-safe base64 encode (no padding, +/ replaced with -_).
	 */
	private static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/* ================================================================
	   AJAX HANDLERS
	   ================================================================ */

	/**
	 * AJAX: Save service account JSON + site URL.
	 */
	public static function ajax_save_credentials() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		$json_raw = wp_unslash( $_POST['gsc_json'] ?? '' );
		$site_url = esc_url_raw( wp_unslash( $_POST['gsc_site_url'] ?? '' ) );

		if ( empty( $json_raw ) ) wp_send_json_error( __( 'Wklej zawartość pliku JSON.', 'loom' ) );

		$result = self::save_service_account( $json_raw );
		if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

		if ( ! empty( $site_url ) ) update_option( 'loom_gsc_site_url', $site_url );

		// Test connection immediately.
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			self::disconnect();
			wp_send_json_error( __( 'JSON zapisany, ale autoryzacja nie powiodła się: ', 'loom' ) . $token->get_error_message() );
		}

		wp_send_json_success( array(
			'message' => __( 'GSC połączony!', 'loom' ),
			'email'   => self::get_sa_email(),
		) );
	}

	/**
	 * AJAX: Sync GSC data.
	 */
	public static function ajax_sync() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		$result = self::sync();
		if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

		// Enrich keywords.
		global $wpdb;
		$table = Loom_DB::index_table();
		$pids  = $wpdb->get_col( "SELECT post_id FROM {$table} WHERE gsc_top_queries IS NOT NULL AND gsc_top_queries != ''" );
		foreach ( $pids as $pid ) {
			self::enrich_keywords_from_gsc( intval( $pid ) );
		}

		wp_send_json_success( array(
			'message'  => sprintf( __( 'Zsynchronizowano %d stron, %d w striking distance.', 'loom' ), $result['updated'], $result['striking'] ),
			'updated'  => $result['updated'],
			'striking' => $result['striking'],
		) );
	}

	/**
	 * AJAX: Disconnect GSC.
	 */
	public static function ajax_disconnect() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
		self::disconnect();
		wp_send_json_success( __( 'GSC rozłączony.', 'loom' ) );
	}
}
