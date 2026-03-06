<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Loom_OpenAI {

	const EMBED_MODEL  = 'text-embedding-3-small';
	const EMBED_DIMS   = 512;
	const CHAT_MODEL   = 'gpt-4o-mini';
	const API_BASE     = 'https://api.openai.com/v1/';

	/**
	 * Generate embedding for text.
	 *
	 * @return array|WP_Error  Vector of floats or error.
	 */
	public static function get_embedding( $text, $api_key = '' ) {
		if ( empty( $api_key ) ) {
			$api_key = Loom_DB::get_api_key();
		}
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_key', __( 'Brak klucza API OpenAI.', 'loom' ) );
		}

		// Truncate to ~8000 tokens (~6000 words).
		$words = explode( ' ', $text );
		if ( count( $words ) > 2000 ) {
			$text = implode( ' ', array_slice( $words, 0, 2000 ) );
		}

		$response = self::request( 'embeddings', array(
			'model'      => self::EMBED_MODEL,
			'input'      => $text,
			'dimensions' => self::EMBED_DIMS,
		), $api_key );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$vector = $response['data'][0]['embedding'] ?? null;
		if ( ! is_array( $vector ) ) {
			return new WP_Error( 'bad_response', __( 'Brak embeddingu w odpowiedzi.', 'loom' ) );
		}

		// Log cost.
		$tokens = $response['usage']['total_tokens'] ?? 0;
		$cost   = $tokens * 0.00000002; // $0.02 / 1M tokens.
		Loom_DB::log( 'embed', null, array( 'tokens' => $tokens ), $tokens, $cost );

		return $vector;
	}

	/**
	 * Send chat completion request.
	 *
	 * @return array|WP_Error  Parsed response or error.
	 */
	public static function chat( $system_prompt, $user_prompt, $json_schema = null, $api_key = '' ) {
		if ( empty( $api_key ) ) {
			$api_key = Loom_DB::get_api_key();
		}
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_key', __( 'Brak klucza API OpenAI.', 'loom' ) );
		}

		$body = array(
			'model'       => self::CHAT_MODEL,
			'messages'    => array(
				array( 'role' => 'system', 'content' => $system_prompt ),
				array( 'role' => 'user',   'content' => $user_prompt ),
			),
			'temperature' => 0.3,
			'max_tokens'  => 3000,
		);

		if ( $json_schema ) {
			$body['response_format'] = $json_schema;
		}

		$response = self::request( 'chat/completions', $body, $api_key );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$message = $response['choices'][0]['message'] ?? null;
		if ( ! $message ) {
			return new WP_Error( 'bad_response', __( 'Brak odpowiedzi z GPT.', 'loom' ) );
		}

		if ( isset( $message['refusal'] ) ) {
			return new WP_Error( 'refusal', $message['refusal'] );
		}

		$content = $message['content'] ?? '';
		$parsed  = json_decode( $content, true );

		// Log cost.
		$usage  = $response['usage'] ?? array();
		$tokens = $usage['total_tokens'] ?? 0;
		$cost   = ( ( $usage['prompt_tokens'] ?? 0 ) * 0.00000015 )
		        + ( ( $usage['completion_tokens'] ?? 0 ) * 0.0000006 );
		Loom_DB::log( 'suggest', null, array( 'usage' => $usage ), $tokens, $cost );

		if ( $parsed === null && json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'json_error', __( 'Nieprawidłowy JSON z GPT.', 'loom' ) );
		}

		return $parsed;
	}

	/**
	 * Core HTTP request to OpenAI with retry on 429/500+.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $body     Request body.
	 * @param string $api_key  API key.
	 * @param int    $attempt  Current attempt (internal).
	 * @return array|WP_Error  Parsed response or error.
	 */
	private static function request( $endpoint, $body, $api_key, $attempt = 1 ) {
		$url = self::API_BASE . $endpoint;

		$response = wp_remote_post( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 25,
		) );

		if ( is_wp_error( $response ) ) {
			Loom_DB::log( 'error', null, array(
				'type'    => 'transport',
				'message' => $response->get_error_message(),
			) );
			return new WP_Error( 'transport_error',
				__( 'Nie udało się połączyć z OpenAI.', 'loom' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		// Retry on rate limit or server error (max 2 retries).
		if ( ( $code === 429 || $code >= 500 ) && $attempt < 3 ) {
			sleep( $attempt * 2 ); // 2s, 4s backoff.
			return self::request( $endpoint, $body, $api_key, $attempt + 1 );
		}

		if ( $code === 429 ) {
			return new WP_Error( 'rate_limit', __( 'Limit API wyczerpany. Spróbuj za chwilę.', 'loom' ) );
		}
		if ( $code === 401 ) {
			return new WP_Error( 'auth_error', __( 'Klucz API nieprawidłowy lub wygasł.', 'loom' ) );
		}
		if ( $code >= 500 ) {
			return new WP_Error( 'server_error', __( 'OpenAI tymczasowo niedostępne.', 'loom' ) );
		}
		if ( $code !== 200 ) {
			$err = json_decode( $raw, true );
			$msg = $err['error']['message'] ?? "HTTP {$code}";
			return new WP_Error( 'api_error', $msg );
		}

		$data = json_decode( $raw, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'json_error', __( 'Nieprawidłowa odpowiedź z OpenAI.', 'loom' ) );
		}

		return $data;
	}

	/**
	 * AJAX: batch generate embeddings for posts that don't have them.
	 */
	public static function ajax_generate_embeddings() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions', 403 );
		}

		$api_key = Loom_DB::get_api_key();
		if ( empty( $api_key ) ) {
			wp_send_json_error( __( 'Brak klucza API.', 'loom' ) );
		}

		global $wpdb;
		$table = Loom_DB::index_table();
		$batch = 5; // Reduced from 10 — safer for timeout.

		$total_missing = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE embedding IS NULL AND clean_text IS NOT NULL AND clean_text != ''"
		);

		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, post_title, clean_text FROM {$table}
			 WHERE embedding IS NULL AND clean_text IS NOT NULL AND clean_text != ''
			 LIMIT %d",
			$batch
		), ARRAY_A );

		if ( empty( $posts ) ) {
			wp_send_json_success( array(
				'generated' => 0,
				'remaining' => 0,
				'status'    => 'complete',
			) );
		}

		$generated   = 0;
		$last_error  = '';

		foreach ( $posts as $p ) {
			$title = $p['post_title'];
			$input = $title . ' | ' . $title . ' | ' . $title
			       . ' | ' . mb_substr( $p['clean_text'], 0, 2500 );
			$vector = self::get_embedding( $input, $api_key );

			if ( is_wp_error( $vector ) ) {
				$last_error = $vector->get_error_message();
				break; // Stop batch on first error — don't burn API calls.
			}

			$wpdb->update( $table, array(
				'embedding'       => wp_json_encode( $vector ),
				'embedding_model' => self::EMBED_MODEL,
				'last_embedding'  => current_time( 'mysql' ),
			), array( 'post_id' => $p['post_id'] ) );
			$generated++;
		}

		$still_remaining = $total_missing - $generated;

		// If zero generated AND we had an error → report it, don't loop.
		if ( $generated === 0 && ! empty( $last_error ) ) {
			wp_send_json_error( $last_error );
		}

		wp_send_json_success( array(
			'generated' => $generated,
			'remaining' => max( 0, $still_remaining ),
			'total'     => $total_missing,
			'status'    => $still_remaining <= 0 ? 'complete' : 'next',
			'error'     => $last_error, // Partial error (some succeeded, then error).
		) );
	}
}
