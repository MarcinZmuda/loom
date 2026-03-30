<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Loom_Settings {

	public static function init() {}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'loom' ) );
		}

		$settings = Loom_DB::get_settings();
		$has_key  = ! empty( Loom_DB::get_api_key() );

		// Count posts without embeddings.
		global $wpdb;
		$table = Loom_DB::index_table();
		$no_emb = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE embedding IS NULL AND clean_text IS NOT NULL AND clean_text != ''"
		);
		$total_emb = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE embedding IS NOT NULL"
		);
		?>
		<div class="wrap loom-wrap">
			<div class="loom-header">
				<div class="loom-logo">
					<img src="<?php echo esc_url( LOOM_URL . 'assets/img/logo-wide.png' ); ?>" alt="LOOM" style="height:30px;width:auto">
				</div>
				<div class="loom-header-meta">
					<span><?php esc_html_e( 'Ustawienia', 'loom' ); ?></span>
					<span>v<?php echo esc_html( LOOM_VERSION ); ?> · <a href="https://marcinzmuda.com" target="_blank" rel="noopener" style="color:var(--muted);text-decoration:none">Marcin Żmuda</a></span>
				</div>
			</div>

			<div class="loom-settings-grid">
				<!-- API Key -->
				<div class="loom-card" style="padding:20px 24px;">
					<h2><?php esc_html_e( 'Klucz API OpenAI', 'loom' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Wymagany do analizy AI. Klucz jest szyfrowany w bazie danych.', 'loom' ); ?></p>
					<div class="loom-field">
						<input type="password" id="loom-api-key" class="regular-text"
						       placeholder="sk-proj-..."
						       value="<?php echo $has_key ? '••••••••••••••••' : ''; ?>">
						<button type="button" class="button" id="loom-save-key">
							<?php echo $has_key ? esc_html__( 'Zmień klucz', 'loom' ) : esc_html__( 'Zapisz klucz', 'loom' ); ?>
						</button>
						<span id="loom-key-status" class="loom-status-text"></span>
					</div>
				</div>

				<!-- GSC Connection (Service Account) -->
				<div class="loom-card" style="padding:20px 24px;">
					<h2>📊 <?php esc_html_e( 'Google Search Console', 'loom' ); ?></h2>
					<?php $gsc_connected = Loom_GSC::is_connected(); ?>
					<?php if ( $gsc_connected ) : ?>
						<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
							<span style="width:10px;height:10px;border-radius:50%;background:var(--ok);display:inline-block"></span>
							<strong style="color:var(--ok)"><?php esc_html_e( 'Połączony', 'loom' ); ?></strong>
						</div>
						<p style="font-size:12px;color:var(--muted);margin:0 0 12px">
							Service Account: <code style="font-size:11px"><?php echo esc_html( Loom_GSC::get_sa_email() ); ?></code>
						</p>
						<div style="display:flex;gap:8px">
							<button type="button" class="loom-btn loom-btn-sm" id="loom-gsc-sync">🔄 <?php esc_html_e( 'Synchronizuj', 'loom' ); ?></button>
							<button type="button" class="loom-btn loom-btn-sm loom-btn-outline" style="border-color:var(--bad);color:var(--bad)" id="loom-gsc-disconnect"><?php esc_html_e( 'Rozłącz', 'loom' ); ?></button>
						</div>
						<div id="loom-gsc-status" style="margin-top:8px"></div>
					<?php else : ?>
						<p class="description" style="margin-bottom:8px"><?php esc_html_e( 'Dwa kroki  -  zero OAuth, zero kodów autoryzacji:', 'loom' ); ?></p>
						<ol style="font-size:12px;color:var(--txt);margin:0 0 12px;padding-left:20px;line-height:1.8">
							<li><a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener"><?php esc_html_e( 'Utwórz Service Account', 'loom' ); ?></a> <?php esc_html_e( 'w Google Cloud i pobierz klucz JSON', 'loom' ); ?></li>
							<li><?php esc_html_e( 'W', 'loom' ); ?> <a href="https://search.google.com/search-console/users" target="_blank" rel="noopener">GSC -> Ustawienia -> Użytkownicy</a> <?php esc_html_e( 'dodaj email Service Account jako użytkownika (Pełne uprawnienia lub Ograniczone)', 'loom' ); ?></li>
						</ol>

						<div class="loom-field">
							<label><?php esc_html_e( 'Wklej zawartość pliku JSON:', 'loom' ); ?></label>
							<textarea id="loom-gsc-json" rows="4" class="large-text code" style="font-size:11px;font-family:var(--mono)" placeholder='{"type":"service_account","client_email":"...","private_key":"..."}'></textarea>
						</div>
						<div class="loom-field">
							<label><?php esc_html_e( 'URL strony w GSC', 'loom' ); ?></label>
							<input type="url" id="loom-gsc-site-url" class="regular-text"
							       placeholder="<?php echo esc_attr( home_url() ); ?>"
							       value="<?php echo esc_attr( get_option( 'loom_gsc_site_url', home_url() ) ); ?>">
							<p class="description"><?php esc_html_e( 'Dokładnie jak w GSC (z https://, ze slashem lub bez).', 'loom' ); ?></p>
						</div>
						<button type="button" class="loom-btn loom-btn-sm" id="loom-gsc-connect">📊 <?php esc_html_e( 'Połącz GSC', 'loom' ); ?></button>
						<span id="loom-gsc-connect-status" class="loom-status-text"></span>
					<?php endif; ?>
				</div>


				<!-- Embeddings -->
				<div class="loom-card" style="padding:20px 24px;">
					<h2><?php esc_html_e( '🧠 Embeddingi', 'loom' ); ?></h2>
					<p><?php printf( esc_html__( 'Wygenerowane: %d | Brak: %d', 'loom' ), $total_emb, $no_emb ); ?></p>
					<?php if ( $no_emb > 0 && $has_key ) : ?>
						<button type="button" class="loom-btn" id="loom-generate-embeddings">
							<?php printf( esc_html__( '🧠 Generuj embeddingi (%d)', 'loom' ), $no_emb ); ?>
						</button>
						<div id="loom-emb-progress" style="display:none;">
							<div class="loom-progress"><div class="loom-progress-fill" id="loom-emb-fill"></div></div>
							<p id="loom-emb-text" class="loom-progress-label"></p>
						</div>
					<?php elseif ( $no_emb === 0 ) : ?>
						<p><span class="loom-badge loom-b-ok">✅ Wszystkie posty mają embeddingi</span></p>
					<?php endif; ?>
				</div>

				<!-- Keywords -->
				<?php
				$no_kw = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$table} WHERE (focus_keywords IS NULL OR focus_keywords = '') AND clean_text IS NOT NULL AND clean_text != ''"
				);
				$total_kw = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$table} WHERE focus_keywords IS NOT NULL AND focus_keywords != ''"
				);
				?>
				<div class="loom-card" style="padding:20px 24px;">
					<h2><?php esc_html_e( '🎯 Focus Keywords (auto)', 'loom' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Automatyczna ekstrakcja słów kluczowych z tytułu, nagłówków i TF-IDF. Opcjonalnie GPT.', 'loom' ); ?></p>
					<p><?php printf( esc_html__( 'Wykryte: %d | Brak: %d', 'loom' ), $total_kw, $no_kw ); ?></p>
					<?php if ( $no_kw > 0 ) : ?>
						<label class="loom-checkbox" style="margin-bottom:10px;">
							<input type="checkbox" id="loom-kw-use-api" <?php echo esc_attr( $has_key ? '' : 'disabled' ); ?>>
							<?php esc_html_e( 'Użyj GPT (warstwa 3, ~$0.001/post)', 'loom' ); ?>
						</label><br>
						<button type="button" class="loom-btn" id="loom-extract-keywords">
							<?php printf( esc_html__( '🎯 Wykryj keywords (%d)', 'loom' ), $no_kw ); ?>
						</button>
						<div id="loom-kw-progress" style="display:none;">
							<p id="loom-kw-text" class="loom-progress-label"></p>
						</div>
					<?php else : ?>
						<p><span class="loom-badge loom-b-ok">✅ Wszystkie posty mają keywords</span></p>
					<?php endif; ?>
				</div>

				<!-- Click Depth -->
				<div class="loom-card" style="padding:20px 24px;">
					<h2><?php esc_html_e( '📐 Struktura strony', 'loom' ); ?></h2>
					<?php
					$deep_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE click_depth > 3" );
					$unreach_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE click_depth IS NULL AND post_id > 0" );
					?>
					<p><?php printf( esc_html__( 'Głębokie (>3): %d | Nieosiągalne: %d', 'loom' ), $deep_count, $unreach_count ); ?></p>
					<button type="button" class="loom-btn loom-btn-outline" id="loom-recalc-depth">
						<?php esc_html_e( '📐 Przelicz Click Depth', 'loom' ); ?>
					</button>
				</div>

				<!-- General Settings -->
				<div class="loom-card">
					<h2><?php esc_html_e( 'Ustawienia ogólne', 'loom' ); ?></h2>

					<div class="loom-field">
						<label><?php esc_html_e( 'Typy postów', 'loom' ); ?></label><br>
						<?php
						$all_types = get_post_types( array( 'public' => true ), 'objects' );
						foreach ( $all_types as $type ) :
							if ( $type->name === 'attachment' ) continue;
						?>
							<label class="loom-checkbox">
								<input type="checkbox" name="loom_post_types[]"
								       value="<?php echo esc_attr( $type->name ); ?>"
								       <?php checked( in_array( $type->name, $settings['post_types'], true ) ); ?>>
								<?php echo esc_html( $type->labels->singular_name ); ?>
							</label>
						<?php endforeach; ?>
					</div>

					<div class="loom-field">
						<label><?php esc_html_e( 'Max sugestii per post', 'loom' ); ?></label>
						<input type="number" name="loom_max_suggestions" min="3" max="15"
						       value="<?php echo esc_attr( $settings['max_suggestions'] ); ?>">
					</div>

					<div class="loom-field">
						<label><?php esc_html_e( 'Min. similarity (próg trafności)', 'loom' ); ?></label>
						<input type="number" name="loom_min_similarity" min="0.05" max="0.8" step="0.01"
						       value="<?php echo esc_attr( $settings['min_similarity'] ?? 0.35 ); ?>">
						<p class="description"><?php esc_html_e( 'Posty z similarity poniżej tego progu nie będą proponowane jako targety. Domyślnie 0.35.', 'loom' ); ?></p>
					</div>

					<div class="loom-field">
						<label>
							<input type="checkbox" name="loom_rescan_on_save" value="1"
							       <?php checked( $settings['rescan_on_save'] ); ?>>
							<?php esc_html_e( 'Re-skanuj przy zapisie posta', 'loom' ); ?>
						</label>
					</div>

					<div class="loom-field">
						<label>
							<input type="checkbox" name="loom_admin_notices" value="1"
							       <?php checked( $settings['admin_notices'] ); ?>>
							<?php esc_html_e( 'Powiadomienia o orphanach', 'loom' ); ?>
						</label>
					</div>

					<button type="button" class="button button-primary" id="loom-save-settings" data-orig-text="<?php esc_attr_e( 'Zapisz ustawienia', 'loom' ); ?>">
						<?php esc_html_e( 'Zapisz ustawienia', 'loom' ); ?>
					</button>
					<span id="loom-settings-status" class="loom-status-text" style="margin-left:10px;font-weight:600"></span>
				</div>

				<!-- Composite Weights -->
				<div class="loom-card">
					<h2><?php esc_html_e( 'Wagi scoringu (zaawansowane)', 'loom' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Kontroluj priorytety algorytmu composite score. Suma powinna wynosić 1.00.', 'loom' ); ?></p>

					<?php
					$weight_labels = array(
						'weight_semantic' => __( 'Podobieństwo semantyczne', 'loom' ),
						'weight_orphan'   => __( 'Priorytet orphan pages', 'loom' ),
						'weight_depth'    => __( 'Priorytet głębokich stron', 'loom' ),
						'weight_tier'     => __( 'Priorytet wyższych tierów', 'loom' ),
						'weight_cluster'  => __( 'Priorytet klastra', 'loom' ),
						'weight_equity'   => __( 'Link velocity', 'loom' ),
						'weight_graph'    => __( 'Analiza grafu (PageRank)', 'loom' ),
						'weight_money'    => __( 'Priorytet money pages ⭐', 'loom' ),
						'weight_gsc'       => __( 'Google Search Console 📊', 'loom' ),
						'weight_authority' => __( 'Topical authority 🏆', 'loom' ),
						'weight_placement' => __( 'Placement quality 📍', 'loom' ),
					);
					foreach ( $weight_labels as $key => $label ) :
					?>
						<div class="loom-field loom-weight-field">
							<label><?php echo esc_html( $label ); ?></label>
							<input type="number" name="<?php echo esc_attr( $key ); ?>"
							       min="0" max="1" step="0.05"
							       value="<?php echo esc_attr( $settings[ $key ] ); ?>"
							       class="small-text loom-weight-input">
						</div>
					<?php endforeach; ?>

					<p class="loom-weight-sum">
						<?php esc_html_e( 'Suma:', 'loom' ); ?>
						<strong id="loom-weight-total"> - </strong>
					</p>
				</div>
			</div>

			<!-- Danger Zone -->
			<div class="loom-card" style="border-color:var(--bad);margin-top:20px">
				<div style="padding:20px 24px;">
					<h2 style="color:var(--bad)">⚠️ <?php esc_html_e( 'Strefa zagrożenia', 'loom' ); ?></h2>
					<p class="description" style="margin-bottom:12px"><?php esc_html_e( 'Usuń wszystkie linki wstawione przez LOOM z post_content. Anchor text zostanie zachowany, tag <a> usunięty. Kopia zapasowa treści zostanie zapisana.', 'loom' ); ?></p>
					<?php
					global $wpdb;
					$lnk = Loom_DB::links_table();
					$loom_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lnk} WHERE is_plugin_generated = 1" );
					?>
					<p style="font-size:13px;margin-bottom:12px"><?php printf( esc_html__( 'Aktualnie LOOM wstawił: %d linków', 'loom' ), $loom_count ); ?></p>
					<button type="button" class="loom-btn" style="background:var(--bad);border-color:var(--bad)" id="loom-remove-all-links" <?php echo $loom_count === 0 ? 'disabled' : ''; ?>>
						🗑️ <?php printf( esc_html__( 'Usuń wszystkie %d linki LOOM', 'loom' ), $loom_count ); ?>
					</button>
					<span id="loom-remove-status" class="loom-status-text"></span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: save settings.
	 */
	public static function ajax_save() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions', 403 );
		}

		$action_type = sanitize_text_field( $_POST['action_type'] ?? '' );

		if ( $action_type === 'save_key' ) {
			$key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
			if ( ! empty( $key ) && strpos( $key, '••' ) === false ) {
				Loom_DB::save_api_key( $key );
			}
			wp_send_json_success( __( 'Klucz zapisany.', 'loom' ) );
		}

		if ( $action_type === 'save_settings' ) {
			$raw_types = isset( $_POST['post_types'] )
				? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['post_types'] ) )
				: array( 'post' );
			$max_sugg = absint( wp_unslash( $_POST['max_suggestions'] ?? 8 ) );
			$max_sugg = max( 3, min( 15, $max_sugg ) );

			$settings = Loom_DB::get_settings();
			$old_types = $settings['post_types'] ?? array( 'post' );
			$types_changed = $raw_types !== $old_types;

			$settings['post_types']      = $raw_types;
			$settings['max_suggestions'] = $max_sugg;
			$settings['min_similarity']  = max( 0.05, min( 0.8, floatval(
				str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['min_similarity'] ?? '0.35' ) ) )
			) ) );
			$settings['rescan_on_save']  = ! empty( $_POST['rescan_on_save'] );
			$settings['admin_notices']   = ! empty( $_POST['admin_notices'] );

			// Weights  -  sanitize + normalize to sum 1.0.
			$weight_keys = array( 'weight_semantic', 'weight_orphan', 'weight_depth', 'weight_tier', 'weight_cluster', 'weight_equity', 'weight_graph', 'weight_money', 'weight_gsc', 'weight_authority', 'weight_placement' );
			$weight_sum  = 0;
			foreach ( $weight_keys as $wk ) {
				if ( isset( $_POST[ $wk ] ) ) {
					$val = max( 0, min( 1, floatval( sanitize_text_field( wp_unslash( $_POST[ $wk ] ) ) ) ) );
					$settings[ $wk ] = $val;
					$weight_sum += $val;
				}
			}
			// Normalize if sum deviates from 1.0.
			if ( $weight_sum > 0 && abs( $weight_sum - 1.0 ) > 0.01 ) {
				foreach ( $weight_keys as $wk ) {
					$settings[ $wk ] = round( $settings[ $wk ] / $weight_sum, 4 );
				}
			}

			update_option( 'loom_settings', $settings );

			if ( $types_changed ) {
				wp_send_json_success( array(
					'message'       => __( 'Ustawienia zapisane.', 'loom' ),
					'types_changed' => true,
					'new_types'     => $raw_types,
				) );
			}
			wp_send_json_success( __( 'Ustawienia zapisane.', 'loom' ) );
		}

		wp_send_json_error( 'Unknown action.' );
	}
}
