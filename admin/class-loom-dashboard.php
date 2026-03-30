<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Loom_Dashboard {

	public static function init() {}

	public static function ajax_link_map() {
		check_ajax_referer( 'loom_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Forbidden', 403 );

		global $wpdb;
		$idx = Loom_DB::index_table();
		$lnk = Loom_DB::links_table();

		$nodes_raw = $wpdb->get_results(
			"SELECT post_id, post_title, post_type, incoming_links_count, outgoing_links_count,
			        is_orphan, is_structural, site_tier, cluster_id, internal_pagerank, is_dead_end, is_bridge,
			        is_money_page, is_striking_distance,
			        gsc_position, gsc_impressions, gsc_clicks, gsc_ctr, gsc_top_queries
			 FROM {$idx} ORDER BY incoming_links_count DESC LIMIT 100", ARRAY_A
		);
		$node_ids = wp_list_pluck( $nodes_raw, 'post_id' );
		if ( empty( $node_ids ) ) { wp_send_json_success( array( 'nodes' => array(), 'edges' => array() ) ); }

		$nodes = array();
		foreach ( $nodes_raw as $n ) {
			$nodes[] = array(
				'id' => intval( $n['post_id'] ), 'label' => mb_substr( $n['post_title'], 0, 60 ),
				'type' => $n['post_type'], 'in' => intval( $n['incoming_links_count'] ),
				'out' => intval( $n['outgoing_links_count'] ), 'orphan' => intval( $n['is_orphan'] ),
				'structural' => intval( $n['is_structural'] ?? 0 ),
				'tier' => intval( $n['site_tier'] ?? 3 ), 'pr' => floatval( $n['internal_pagerank'] ?? 0 ),
				'dead_end' => intval( $n['is_dead_end'] ?? 0 ), 'bridge' => intval( $n['is_bridge'] ?? 0 ),
				'money' => intval( $n['is_money_page'] ?? 0 ), 'striking' => intval( $n['is_striking_distance'] ?? 0 ),
				'cluster' => $n['cluster_id'] ?? null,
				'gsc_pos' => floatval( $n['gsc_position'] ?? 0 ),
				'gsc_impr' => intval( $n['gsc_impressions'] ?? 0 ),
				'gsc_clicks' => intval( $n['gsc_clicks'] ?? 0 ),
				'gsc_ctr' => floatval( $n['gsc_ctr'] ?? 0 ),
				'queries' => json_decode( $n['gsc_top_queries'] ?? '[]', true ) ?: array(),
				'primary_kw' => '',
			);
		}

		// Extract primary keyword per node.
		foreach ( $nodes as &$nd ) {
			$kw_row = Loom_DB::get_index_row( $nd['id'] );
			if ( $kw_row && ! empty( $kw_row['focus_keywords'] ) ) {
				$kws = json_decode( $kw_row['focus_keywords'], true );
				if ( is_array( $kws ) ) {
					foreach ( $kws as $kw ) {
						if ( ( $kw['type'] ?? '' ) === 'primary' ) { $nd['primary_kw'] = $kw['phrase'] ?? ''; break; }
					}
					if ( empty( $nd['primary_kw'] ) && ! empty( $kws[0]['phrase'] ) ) {
						$nd['primary_kw'] = $kws[0]['phrase'];
					}
				}
			}
		}
		unset( $nd );

		$ids_str = implode( ',', array_map( 'intval', $node_ids ) );
		$edges_raw = $wpdb->get_results(
			"SELECT source_post_id, target_post_id, anchor_text, link_position, is_plugin_generated FROM {$lnk}
			 WHERE source_post_id IN ({$ids_str}) AND target_post_id IN ({$ids_str}) AND target_post_id > 0", ARRAY_A
		);
		$edges = array();
		foreach ( $edges_raw as $e ) {
			$edges[] = array(
				'from'   => intval( $e['source_post_id'] ),
				'to'     => intval( $e['target_post_id'] ),
				'loom'   => intval( $e['is_plugin_generated'] ),
				'anchor' => $e['anchor_text'] ?? '',
				'pos'    => $e['link_position'] ?? 'middle',
			);
		}
		wp_send_json_success( array( 'nodes' => $nodes, 'edges' => $edges ) );
	}

	public static function render() {
		if ( ! current_user_can( 'edit_posts' ) ) { wp_die( esc_html__( 'Brak uprawnień.', 'loom' ) ); }

		$has_key  = ! empty( Loom_DB::get_api_key() );
		$scanned  = get_option( 'loom_scan_completed' );
		$s        = Loom_DB::get_dashboard_stats();
		$posts    = Loom_DB::get_all_index_rows();
		$filter   = sanitize_text_field( $_GET['filter'] ?? '' );
		$tab      = sanitize_text_field( $_GET['tab'] ?? 'overview' );
		$settings = Loom_DB::get_settings();
		$gh       = Loom_Graph::get_health();
		$gsc_on   = Loom_GSC::is_connected();
		?>
		<div class="wrap loom-wrap">

		<!-- HEADER -->
		<div class="loom-header">
			<div class="loom-logo">
				<img src="<?php echo esc_url( LOOM_URL . 'assets/img/logo-wide.png' ); ?>" alt="LOOM" style="height:30px;width:auto">
			</div>
			<div class="loom-header-meta">
				<?php if ( $scanned ) : ?>
				<span><span class="loom-dot" style="background:var(--ok)"></span> Scan: <?php echo esc_html( $s['total_posts'] ); ?> stron</span>
				<?php if ( $gsc_on ) : ?>
				<span><span class="loom-dot" style="background:var(--purple)"></span> GSC: <?php echo esc_html( $s['gsc_synced'] ); ?> stron</span>
				<?php endif; ?>
				<span>v<?php echo esc_html( LOOM_VERSION ); ?> · <a href="https://marcinzmuda.com" target="_blank" rel="noopener" style="color:var(--muted);text-decoration:none">Marcin Żmuda</a></span>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( ! $scanned ) : ?>
		<div class="loom-hero">
			<h2><?php esc_html_e( 'Witaj w LOOM', 'loom' ); ?></h2>
			<p><?php esc_html_e( 'Przeskanuj stronę, aby zbudować indeks treści i linków.', 'loom' ); ?></p>
			<button class="loom-btn loom-btn-lg" id="loom-start-scan">🔍 <?php esc_html_e( 'Skanuj stronę', 'loom' ); ?></button>
			<div id="loom-scan-progress" style="display:none;">
				<div class="loom-progress loom-progress-lg"><div class="loom-progress-fill" id="loom-progress-fill"></div></div>
				<p id="loom-progress-text" class="loom-progress-label"></p>
			</div>
		</div>
		<?php else : ?>

		<!-- TABS -->
		<div class="loom-tabs">
			<?php
			$tabs = array(
				'overview' => array( '📊 ' . __( 'Przegląd', 'loom' ), __( 'Podsumowanie stanu linkowania wewnętrznego: metryki, equity, szybkie akcje.', 'loom' ) ),
				'money'    => array( '💰 Money Pages', __( 'Strony konwersji (usługi, produkty). Monitoruj cel linkowania, anchor diversity, pozycję w Google.', 'loom' ) ),
				'striking' => array( '🎯 Striking', __( 'Strony na pozycji 5-20 w Google. Jeden link wewnętrzny może przesunąć je na stronę 1.', 'loom' ) ),
				'graph'    => array( '🕸️ Graf', __( 'Wizualizacja grafu linków wewnętrznych: pierścienie, scatter, keywords, anchory.', 'loom' ) ),
				'posts'    => array( '📋 Posty', __( 'Tabela wszystkich stron z metrykami IN/OUT, statusem, filtrami.', 'loom' ) ),
				'settings' => array( '⚙️ ' . __( 'Ustawienia', 'loom' ), __( 'Wagi scoringu, klucze API, GSC, zarządzanie danymi.', 'loom' ) ),
			);
			foreach ( $tabs as $tid => $tdata ) :
			?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', $tid, admin_url( 'admin.php?page=loom' ) ) ); ?>"
			   class="loom-tab <?php echo $tab === $tid ? 'active' : ''; ?>"
			   title="<?php echo esc_attr( $tdata[1] ); ?>"><?php echo esc_html( $tdata[0] ); ?></a>
			<?php endforeach; ?>
		</div>

		<?php // ═══ OVERVIEW TAB ═══
		if ( $tab === 'overview' ) : ?>

		<!-- Top metrics -->
		<div class="loom-metrics">
			<?php
			$metrics = array(
				array( '📄', $s['total_posts'], __( 'Stron', 'loom' ), false,
					__( 'Łączna liczba przeskanowanych stron i postów w indeksie LOOM.', 'loom' ) ),
				array( '🔴', $s['orphans'], __( 'Orphany', 'loom' ), $s['orphans'] > 0,
					__( 'Strony bez żadnego linka przychodzącego. Google może ich nie znaleźć. Dodaj linki do tych stron z powiązanych artykułów.', 'loom' ) ),
				array( '🟡', $s['near_orphans'] ?? 0, 'Near-orphany', ( $s['near_orphans'] ?? 0 ) > 0,
					__( 'Strony z tylko 1-2 linkami przychodzącymi. Potrzebują wzmocnienia.', 'loom' ) ),
				array( '🏗️', $s['structural'] ?? 0, __( 'Strukturalne', 'loom' ), false,
					__( 'Strony nawigacyjne (menu, footer) — wykluczone z metryk orphanów.', 'loom' ) ),
				array( '⚫', $s['dead_ends'], 'Dead Ends', $s['dead_ends'] > 0,
					__( 'Strony bez żadnego linka wychodzącego. Użytkownik trafia w ślepy zaułek. Dodaj linki do powiązanych treści.', 'loom' ) ),
				array( '🌉', $s['bridges'], 'Bridges', false,
					__( 'Strony-mosty łączące odizolowane grupy treści. Usunięcie takiej strony rozspójniłoby strukturę serwisu.', 'loom' ) ),
				array( '🔗', $s['loom_links'], __( 'Linki LOOM', 'loom' ), false,
					__( 'Liczba linków wewnętrznych wstawionych przez LOOM (zatwierdzonych przez Ciebie lub auto-linkowanie).', 'loom' ) ),
				array( '🎯', $s['striking_distance'], 'Striking', false,
					__( 'Strony na pozycji 5-20 w Google. Jeden dodatkowy link wewnętrzny może przesunąć je na stronę 1 wyników.', 'loom' ) ),
				array( '⭐', $s['money_pages'], 'Money Pages', false,
					__( 'Strony konwersji (usługi, produkty, kontakt). LOOM kieruje equity (wartość linków) w ich stronę.', 'loom' ) ),
				array( '⚠️', $s['overlinked'] ?? 0, 'Overlinked', ( $s['overlinked'] ?? 0 ) > 0,
					__( 'Strony z ponad 20 linkami wychodzącymi. Zbyt wiele linków rozrzedza equity.', 'loom' ) ),
				array( '💰', '$' . $s['api_cost'], __( 'Koszt API', 'loom' ), false,
					__( 'Łączny koszt wywołań OpenAI API (embeddingi + sugestie GPT). Typowy koszt: ~$0.002 za jedno „Podlinkuj".', 'loom' ) ),
			);
			foreach ( $metrics as $m ) : ?>
			<div class="loom-m <?php echo $m[3] ? 'loom-m-alert' : ''; ?>" title="<?php echo esc_attr( $m[4] ); ?>">
				<div class="loom-m-icon"><?php echo $m[0]; ?></div>
				<div class="loom-m-val"><?php echo esc_html( $m[1] ); ?></div>
				<div class="loom-m-lbl"><?php echo esc_html( $m[2] ); ?></div>
			</div>
			<?php endforeach; ?>
		</div>

		<div class="loom-grid-3">
			<!-- Linking stats -->
			<div class="loom-card"><div class="loom-card-body">
				<h3 style="font-size:11px;text-transform:uppercase;color:var(--muted);letter-spacing:.4px;margin-bottom:10px">📊 <?php esc_html_e( 'Linkowanie', 'loom' ); ?></h3>
				<?php foreach ( array(
					array( '↗️ Śr. OUT', $s['avg_out_links'],
						__( 'Średnia liczba linków wychodzących na stronę. Optymalna wartość: 3-8 dla artykułów, więcej dla pillar pages.', 'loom' ) ),
					array( '↙️ Śr. IN', $s['avg_in_links'],
						__( 'Średnia liczba linków przychodzących na stronę. Im wyższa, tym lepsza dystrybucja equity. Cel: ≥3 dla każdej strony.', 'loom' ) ),
					array( '🔗 Density', $gh['density'] ?? ' - ',
						__( 'Gęstość grafu: stosunek istniejących linków do wszystkich możliwych. Typowy zdrowy zakres: 2-8%. Zbyt niski = słabe linkowanie, zbyt wysoki = spam.', 'loom' ) ),
					array( '🧩 Komponenty', $gh['components'] ?? ' - ',
						__( 'Liczba odizolowanych grup stron. Idealnie: 1 (cała strona jest połączona). >1 = istnieją grupy stron bez połączeń między sobą.', 'loom' ) ),
					array( '📐 Max depth', $s['max_depth'],
						__( 'Najgłębsza strona mierzona liczbą kliknięć od homepage. Google zaleca ≤3 kliknięcia. >4 = strona trudno dostępna.', 'loom' ) ),
					array( '❌ Broken', $s['broken_links'],
						__( 'Linki prowadzące do nieistniejących stron (post usunięty lub zmieniony URL). Napraw lub usuń te linki.', 'loom' ) ),
				) as $row ) : ?>
				<div class="loom-stat-row" title="<?php echo esc_attr( $row[2] ); ?>"><span class="loom-stat-label"><?php echo esc_html( $row[0] ); ?></span><span class="loom-stat-value"><?php echo esc_html( $row[1] ); ?></span></div>
				<?php endforeach; ?>
			</div></div>

			<!-- Equity distribution -->
			<div class="loom-card"><div class="loom-card-body">
				<h3 style="font-size:11px;text-transform:uppercase;color:var(--muted);letter-spacing:.4px;margin-bottom:10px" title="<?php esc_attr_e( 'Equity (link juice) to wartość SEO przekazywana przez linki. Powinna być rozłożona równomiernie — nie skoncentrowana na kilku stronach.', 'loom' ); ?>">⚖️ <?php esc_html_e( 'Equity', 'loom' ); ?></h3>
				<?php if ( ! empty( $gh['equity_top10'] ) ) : $t10 = $gh['equity_top10']; $b50 = $gh['bot50_equity'] ?? 0; ?>
				<div class="loom-eq-label" title="<?php esc_attr_e( 'Jaki % całego PageRank trafia do 10% najsilniejszych stron. Poniżej 50% = zdrowy rozkład.', 'loom' ); ?>"><?php printf( esc_html__( 'Top 10%%: %d%% equity', 'loom' ), $t10 ); ?></div>
				<div class="loom-progress" style="margin-bottom:10px"><div class="loom-progress-fill" style="width:<?php echo esc_attr( $t10 ); ?>%"></div></div>
				<div class="loom-eq-label"><?php printf( esc_html__( 'Bottom 50%%: %d%% equity', 'loom' ), $b50 ); ?></div>
				<div class="loom-progress"><div class="loom-progress-fill <?php echo $b50 < 20 ? 'loom-progress-fill-bad' : 'loom-progress-fill-ok'; ?>" style="width:<?php echo esc_attr( $b50 ); ?>%"></div></div>
				<p style="font-size:10px;color:var(--muted);margin-top:8px"><?php echo $b50 < 20 ? '⚠️ Zbyt duża koncentracja equity' : '✅ Rozkład akceptowalny'; ?></p>
				<?php else : ?>
				<p class="loom-muted" style="font-size:12px"><?php esc_html_e( 'Przelicz graf aby zobaczyć equity.', 'loom' ); ?></p>
				<?php endif; ?>
			</div></div>

			<!-- Quick actions -->
			<div class="loom-card"><div class="loom-card-body">
				<h3 style="font-size:11px;text-transform:uppercase;color:var(--muted);letter-spacing:.4px;margin-bottom:10px">⚡ <?php esc_html_e( 'Szybkie akcje', 'loom' ); ?></h3>
				<div style="display:flex;flex-direction:column;gap:6px">
				<?php if ( $s['orphans'] > 0 ) : ?><button class="loom-action-btn loom-action-red" title="<?php esc_attr_e( 'Orphany to strony bez linków przychodzących — Google może ich nie zaindeksować. LOOM zasugeruje linki z powiązanych artykułów.', 'loom' ); ?>">🔴 <?php printf( esc_html__( 'Napraw %d orphanów', 'loom' ), $s['orphans'] ); ?></button><?php endif; ?>
				<?php if ( ( $s['near_orphans'] ?? 0 ) > 0 ) : ?><button class="loom-action-btn" style="background:#fef3c7;color:#92400e">🟡 <?php printf( '%d near-orphanów', $s['near_orphans'] ); ?></button><?php endif; ?>
				<?php if ( $s['dead_ends'] > 0 ) : ?><button class="loom-action-btn loom-action-amber" title="<?php esc_attr_e( 'Dead ends to strony bez linków wychodzących. Użytkownik nie ma gdzie dalej iść. Dodaj linki do powiązanych treści.', 'loom' ); ?>">⚫ <?php printf( esc_html__( 'Napraw %d dead endów', 'loom' ), $s['dead_ends'] ); ?></button><?php endif; ?>
				<?php if ( $s['striking_distance'] > 0 ) : ?><button class="loom-action-btn loom-action-purple" title="<?php esc_attr_e( 'Strony na pozycji 5-20 w Google. Jeden link wewnętrzny może przesunąć je na stronę 1 i znacząco zwiększyć ruch.', 'loom' ); ?>">🎯 <?php printf( esc_html__( 'Boost %d striking distance', 'loom' ), $s['striking_distance'] ); ?></button><?php endif; ?>
				<?php if ( $s['money_deficit'] > 0 ) : ?><button class="loom-action-btn loom-action-yellow" title="<?php esc_attr_e( 'Money pages z deficytem linków — nie osiągnęły celu linkowania. Więcej linków = więcej equity = wyższa pozycja.', 'loom' ); ?>">⭐ <?php esc_html_e( 'Wzmocnij money pages', 'loom' ); ?></button><?php endif; ?>
				<button class="loom-action-btn" style="background:#f0fdfa;color:#0d9488" id="loom-run-diagnostics" title="<?php esc_attr_e( 'Sprawdź kanibalizację, duplikaty linków, overlinked pages, integralność silo.', 'loom' ); ?>">🩺 Diagnostyka</button>
				<button class="loom-action-btn" style="background:#f0fdfa;color:#0d9488" id="loom-silo-check" title="<?php esc_attr_e( 'Sprawdź czy pillary linkują do wszystkich artykułów w klastrze i odwrotnie.', 'loom' ); ?>">🏗️ Sprawdź silo</button>
				<button class="loom-action-btn" style="background:#f1f5f9;color:#374151" id="loom-start-scan" title="<?php esc_attr_e( 'Ponowne skanowanie odświeża indeks treści i linków. Uruchom po dodaniu nowych postów lub zmianach w strukturze.', 'loom' ); ?>">🔄 <?php esc_html_e( 'Przeskanuj ponownie', 'loom' ); ?></button>
				</div>
				<div id="loom-scan-progress" style="display:none;margin-top:8px">
					<div class="loom-progress"><div class="loom-progress-fill" id="loom-progress-fill"></div></div>
					<p id="loom-progress-text" class="loom-progress-label"></p>
				</div>
				<div id="loom-diagnostics-result" style="display:none;margin-top:10px;font-size:11px"></div>
				<div id="loom-silo-result" style="display:none;margin-top:10px;font-size:11px"></div>
			</div></div>
		</div>

		<?php if ( ! $has_key ) : ?>
		<div class="loom-card loom-card-warn"><p>⚠️ <?php esc_html_e( 'Dodaj klucz API OpenAI w', 'loom' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=loom-settings' ) ); ?>"><?php esc_html_e( 'ustawieniach', 'loom' ); ?></a></p></div>
		<?php endif; ?>

		<?php if ( $s['broken_links'] > 0 ) : ?>
		<div class="loom-card">
			<div class="loom-card-header">
				<h2>❌ <?php printf( esc_html__( 'Broken links (%d)', 'loom' ), $s['broken_links'] ); ?></h2>
				<span class="loom-badge loom-b-bad" title="<?php esc_attr_e( 'Linki wskazujące na nieistniejące strony. Szkodzą SEO i UX. Napraw je usuwając link lub podmieniając URL.', 'loom' ); ?>"><?php esc_html_e( 'Wymaga naprawy', 'loom' ); ?></span>
			</div>
			<div id="loom-broken-list" style="padding:16px">
				<p class="loom-muted"><?php esc_html_e( 'Ładowanie...', 'loom' ); ?></p>
			</div>
		</div>
		<?php endif; ?>

		<!-- Orphan Trend Chart (v2.4) -->
		<?php
		$trend_data = Loom_DB::get_orphan_trend( 15 );
		if ( ! empty( $trend_data ) && count( $trend_data ) >= 2 ) :
			$trend_data = array_reverse( $trend_data ); // Oldest first.
		?>
		<div class="loom-card"><div class="loom-card-body">
			<h3 style="font-size:11px;text-transform:uppercase;color:var(--muted);letter-spacing:.4px;margin-bottom:10px">📈 <?php echo 'Trend orphanów (ostatnie ' . count( $trend_data ) . ' skanów)'; ?></h3>
			<div id="loom-trend-chart" style="height:140px" data-trend="<?php echo esc_attr( wp_json_encode( array_map( function( $t ) {
				$d = json_decode( $t['details'], true );
				return array(
					'date'    => substr( $t['created_at'], 0, 10 ),
					'orphans' => intval( $d['orphans'] ?? 0 ),
					'near'    => intval( $d['near_orphans'] ?? 0 ),
				);
			}, $trend_data ) ) ); ?>"></div>
		</div></div>
		<?php endif; ?>

		<?php // ═══ MONEY PAGES TAB ═══
		elseif ( $tab === 'money' ) :
			$money_pages = Loom_DB::get_money_pages_health();
		?>
		<div class="loom-card">
			<div class="loom-card-header"><h2>⭐ <?php esc_html_e( 'Money Pages  -  status linkowania', 'loom' ); ?></h2><span class="loom-badge loom-b-neutral"><?php echo esc_html( count( $money_pages ) ); ?> stron</span></div>
			<?php if ( ! empty( $money_pages ) ) : ?>
			<table class="loom-tbl"><thead><tr>
				<th><?php esc_html_e( 'Strona', 'loom' ); ?></th>
				<th class="loom-tc" title="<?php esc_attr_e( 'Priorytet 1-5 — im wyższy, tym więcej equity LOOM kieruje do tej strony.', 'loom' ); ?>">Prio</th>
				<th title="<?php esc_attr_e( 'Aktualna liczba linków przychodzących / cel do osiągnięcia. Pasek pokazuje postęp.', 'loom' ); ?>">Linki / Cel</th>
				<th class="loom-tc" title="<?php esc_attr_e( 'Zróżnicowanie anchorów: 100% = każdy link ma inny anchor. Poniżej 60% = ryzyko over-optymalizacji.', 'loom' ); ?>">Anchor%</th>
				<th class="loom-tc" title="<?php esc_attr_e( 'Średnia pozycja w Google z Google Search Console. Im niższa, tym lepiej.', 'loom' ); ?>">GSC Pos</th>
				<th class="loom-tc" title="<?php esc_attr_e( 'Liczba wyświetleń w wynikach Google w ciągu ostatnich 28 dni.', 'loom' ); ?>">Impr</th>
				<th class="loom-tc" title="<?php esc_attr_e( 'Krytyczny = brakuje 5+ linków do celu. Potrzebuje = brakuje 1-4 linków. OK = cel osiągnięty.', 'loom' ); ?>">Status</th>
			</tr></thead><tbody>
			<?php foreach ( $money_pages as $mp ) :
				$pct = $mp['goal'] > 0 ? round( $mp['current'] / $mp['goal'] * 100 ) : 0;
				$bar_cls = $mp['deficit'] > 4 ? 'loom-progress-fill-bad' : ( $mp['deficit'] > 0 ? 'loom-progress-fill-warn' : 'loom-progress-fill-ok' );
			?>
			<tr>
				<td><a href="<?php echo esc_url( get_edit_post_link( $mp['post_id'] ) ); ?>" class="loom-link"><?php echo esc_html( mb_substr( $mp['title'], 0, 40 ) ); ?></a></td>
				<td class="loom-tc"><?php echo esc_html( str_repeat( '⭐', intval( $mp['priority'] ) ) ); ?></td>
				<td>
					<div style="display:flex;align-items:center;gap:6px">
						<strong><?php echo esc_html( $mp['current'] ); ?></strong>
						<div class="loom-progress" style="flex:1"><div class="loom-progress-fill <?php echo esc_attr( $bar_cls ); ?>" style="width:<?php echo esc_attr( $pct ); ?>%"></div></div>
						<span class="loom-muted"><?php echo esc_html( $mp['goal'] ); ?></span>
					</div>
				</td>
				<td class="loom-tc <?php echo intval( $mp['anchor_diversity'] ) < 60 ? 'style="color:var(--bad);font-weight:700"' : ''; ?>"><?php echo esc_html( $mp['anchor_diversity'] ); ?>%</td>
				<td class="loom-tc loom-tn"><?php echo floatval( $mp['gsc_position'] ?? 0 ) > 0 ? esc_html( number_format( $mp['gsc_position'], 1 ) ) : ' - '; ?></td>
				<td class="loom-tc loom-tn"><?php echo intval( $mp['gsc_impressions'] ?? 0 ) > 0 ? esc_html( number_format( $mp['gsc_impressions'] ) ) : ' - '; ?></td>
				<td class="loom-tc">
					<?php if ( $mp['status'] === 'critical' ) : ?><span class="loom-badge loom-b-bad">🔴 Krytyczny</span>
					<?php elseif ( $mp['status'] === 'needs_more' ) : ?><span class="loom-badge loom-b-warn">🟡 Potrzebuje</span>
					<?php else : ?><span class="loom-badge loom-b-ok">🟢 OK</span><?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody></table>
			<?php else : ?>
			<div class="loom-card-body"><p class="loom-muted"><?php esc_html_e( 'Oznacz strony jako money pages klikając ⭐ w tabeli postów.', 'loom' ); ?></p></div>
			<?php endif; ?>
		</div>

		<?php // ═══ STRIKING DISTANCE TAB ═══
		elseif ( $tab === 'striking' ) :
			global $wpdb;
			$idx = Loom_DB::index_table();
			$striking_pages = $wpdb->get_results(
				"SELECT post_id, post_title, post_url, gsc_position, gsc_impressions, gsc_ctr, gsc_clicks, gsc_top_queries
				 FROM {$idx} WHERE is_striking_distance = 1 ORDER BY gsc_position ASC LIMIT 30", ARRAY_A
			);
		?>
		<div class="loom-card">
			<div class="loom-card-header">
				<h2>🎯 <?php esc_html_e( 'Striking Distance  -  strony o krok od page 1', 'loom' ); ?></h2>
				<?php if ( $gsc_on ) : ?>
				<button class="loom-btn loom-btn-sm loom-btn-purple" id="loom-gsc-sync">🔄 <?php esc_html_e( 'Sync GSC', 'loom' ); ?></button>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $striking_pages ) ) : ?>
			<table class="loom-tbl"><thead><tr>
				<th><?php esc_html_e( 'Strona', 'loom' ); ?></th>
				<th class="loom-tc" title="<?php esc_attr_e( 'Średnia pozycja w Google. 5-10 = blisko strony 1. 11-20 = strona 2, do przesunięcia jednym linkiem.', 'loom' ); ?>">Pozycja</th>
				<th class="loom-tc" title="<?php esc_attr_e( 'Ile razy strona pojawiła się w wynikach Google w ciągu 28 dni. Więcej = większy potencjał ruchu.', 'loom' ); ?>">Impressions</th>
				<th class="loom-tc" title="<?php esc_attr_e( 'Click-Through Rate: % osób, które kliknęły po zobaczeniu wyniku. Typowo 1-5% dla pozycji 5-20.', 'loom' ); ?>">CTR</th>
				<th class="loom-tc" title="<?php esc_attr_e( 'Rzeczywista liczba kliknięć z Google w ciągu 28 dni.', 'loom' ); ?>">Clicks</th>
				<th title="<?php esc_attr_e( 'Najczęstsze zapytanie z Google, na które ta strona się wyświetla. Użyj go jako anchor text w linkach.', 'loom' ); ?>">Top query</th>
				<th class="loom-tc" title="<?php esc_attr_e( 'Potencjalny zwrot z linkowania: Wysoki = dużo impressions, link może dać duży wzrost ruchu.', 'loom' ); ?>">ROI</th>
			</tr></thead><tbody>
			<?php foreach ( $striking_pages as $sp ) :
				$pos   = floatval( $sp['gsc_position'] );
				$impr  = intval( $sp['gsc_impressions'] );
				$roi   = $impr > 5000 ? 'high' : ( $impr > 1000 ? 'medium' : 'low' );
				$query = '';
				$qdata = json_decode( $sp['gsc_top_queries'] ?? '[]', true );
				if ( ! empty( $qdata[0]['query'] ) ) $query = $qdata[0]['query'];
			?>
			<tr>
				<td><a href="<?php echo esc_url( get_edit_post_link( $sp['post_id'] ) ); ?>" class="loom-link"><?php echo esc_html( mb_substr( $sp['post_title'], 0, 40 ) ); ?></a></td>
				<td class="loom-tc"><span class="loom-tn" style="font-size:16px;font-weight:800;color:<?php echo $pos <= 12 ? 'var(--ok)' : 'var(--purple)'; ?>"><?php echo esc_html( number_format( $pos, 1 ) ); ?></span></td>
				<td class="loom-tc loom-tn"><?php echo esc_html( number_format( $impr ) ); ?></td>
				<td class="loom-tc"><?php echo esc_html( number_format( floatval( $sp['gsc_ctr'] ) * 100, 1 ) ); ?>%</td>
				<td class="loom-tc loom-tn" style="font-weight:700"><?php echo esc_html( $sp['gsc_clicks'] ); ?></td>
				<td><?php if ( $query ) : ?><span class="loom-code"><?php echo esc_html( $query ); ?></span><?php else : ?> - <?php endif; ?></td>
				<td class="loom-tc">
					<?php if ( $roi === 'high' ) : ?><span class="loom-badge loom-b-ok">🚀 Wysoki</span>
					<?php elseif ( $roi === 'medium' ) : ?><span class="loom-badge loom-b-warn">📈 Średni</span>
					<?php else : ?><span class="loom-badge loom-b-neutral">📊 Niski</span><?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody></table>
			<?php elseif ( ! $gsc_on ) : ?>
			<div class="loom-card-body"><p class="loom-muted">🔌 <?php esc_html_e( 'Połącz Google Search Console w ustawieniach aby zobaczyć dane.', 'loom' ); ?></p></div>
			<?php else : ?>
			<div class="loom-card-body"><p class="loom-muted"><?php esc_html_e( 'Brak stron w striking distance. Kliknij Sync GSC.', 'loom' ); ?></p></div>
			<?php endif; ?>
		</div>

		<?php // ═══ GRAPH TAB ═══
		elseif ( $tab === 'graph' ) : ?>
		<div class="loom-card">
			<div class="loom-card-header">
				<h2>🕸️ <?php esc_html_e( 'Mapa powiązań', 'loom' ); ?></h2>
				<div style="display:flex;gap:8px;align-items:center">
					<div class="loom-graph-views">
						<button class="loom-btn loom-btn-sm loom-graph-view-btn active" data-view="rings" title="<?php esc_attr_e( 'Struktura hierarchiczna. Kliknij = połączenia, przeciągnij = przesuń.', 'loom' ); ?>">🎯 <?php esc_html_e( 'Pierścienie', 'loom' ); ?></button>
						<button class="loom-btn loom-btn-sm loom-graph-view-btn" data-view="table" title="<?php esc_attr_e( 'Lista stron z panelem połączeń IN/OUT.', 'loom' ); ?>">📋 <?php esc_html_e( 'Lista', 'loom' ); ?></button>
						<button class="loom-btn loom-btn-sm loom-graph-view-btn" data-view="bubble" title="<?php esc_attr_e( 'X=IN, Y=OUT, rozmiar=PageRank. Orphany i huby widoczne natychmiast.', 'loom' ); ?>">🫧 Scatter</button>
						<button class="loom-btn loom-btn-sm loom-graph-view-btn" data-view="keywords" title="<?php esc_attr_e( 'Zapytania z Google Search Console. Rozmiar=impressions. Użyj jako anchor text.', 'loom' ); ?>">🔑 Keywords</button>
						<button class="loom-btn loom-btn-sm loom-graph-view-btn" data-view="anchors" title="<?php esc_attr_e( 'Profil anchorów per strona: exact/partial/contextual/generic %, health score.', 'loom' ); ?>">🔗 <?php esc_html_e( 'Anchory', 'loom' ); ?></button>
					</div>
					<button class="loom-btn loom-btn-sm loom-btn-outline" id="loom-recalc-graph">🔄</button>
				</div>
			</div>
			<!-- Rings view -->
			<div id="loom-view-rings" class="loom-graph-panel">
				<canvas id="loom-link-map" width="1100" height="460"></canvas>
				<p class="loom-map-hint"><?php esc_html_e( 'Kliknij = pokaż połączenia · Przeciągnij = przesuń · Podwójne kliknięcie = reset pozycji', 'loom' ); ?></p>
			</div>
			<!-- Table view -->
			<div id="loom-view-table" class="loom-graph-panel" style="display:none">
				<div style="display:flex;gap:16px;padding:16px;min-height:440px">
					<div id="loom-table-list" style="flex:0 0 420px;max-height:440px;overflow-y:auto"></div>
					<div id="loom-table-detail" style="flex:1;min-width:280px"></div>
				</div>
			</div>
			<!-- Bubble Scatter view -->
			<div id="loom-view-bubble" class="loom-graph-panel" style="display:none">
				<div id="loom-bubble-container" style="padding:16px"></div>
			</div>
			<!-- Keywords view -->
			<div id="loom-view-keywords" class="loom-graph-panel" style="display:none">
				<div id="loom-keywords-container" style="display:flex;gap:16px;padding:16px;min-height:420px">
					<div id="loom-kw-pages" style="flex:0 0 200px;max-height:440px;overflow-y:auto"></div>
					<div id="loom-kw-cloud" style="flex:1;display:flex;flex-wrap:wrap;gap:6px;align-content:flex-start"></div>
				</div>
			</div>
			<!-- Anchor Explorer view -->
			<div id="loom-view-anchors" class="loom-graph-panel" style="display:none">
				<div id="loom-anchors-container" style="display:flex;gap:16px;padding:16px;min-height:420px">
					<div id="loom-anchor-pages" style="flex:0 0 200px;max-height:440px;overflow-y:auto"></div>
					<div id="loom-anchor-detail" style="flex:1"></div>
				</div>
			</div>
		</div>

		<?php // ═══ POSTS TAB ═══
		elseif ( $tab === 'posts' ) : ?>
		<div class="loom-card">
			<div class="loom-filters">
				<?php
				$filters = array(
					''          => array( sprintf( __( 'Wszystkie (%d)', 'loom' ), $s['total_posts'] ), __( 'Wszystkie strony w indeksie LOOM.', 'loom' ) ),
					'orphans'   => array( '🔴 Orphany (' . $s['orphans'] . ')', __( 'Strony bez żadnego linka przychodzącego. Google może ich nie zaindeksować.', 'loom' ) ),
					'near'      => array( '🟡 Near-orphany (' . ( $s['near_orphans'] ?? 0 ) . ')', __( 'Strony z 1-2 linkami IN — za słabe wsparcie. Potrzebują wzmocnienia.', 'loom' ) ),
					'structural'=> array( '🏗️ Strukturalne (' . ( $s['structural'] ?? 0 ) . ')', __( 'Strony nawigacyjne wykluczone z metryk orphanów. Linkowane z menu/footera.', 'loom' ) ),
					'weak'      => array( '🟡 Słabe', __( 'Strony z tylko 1-2 linkami przychodzącymi. Potrzebują wzmocnienia.', 'loom' ) ),
					'money'     => array( '⭐ Money', __( 'Strony konwersji oznaczone jako money pages.', 'loom' ) ),
					'striking'  => array( '🎯 Striking', __( 'Strony na pozycji 5-20 w Google — blisko strony 1 wyników.', 'loom' ) ),
					'deadends'  => array( '⚫ Dead Ends', __( 'Strony bez linków wychodzących — ślepy zaułek dla użytkownika.', 'loom' ) ),
					'overlinked'=> array( '⚠️ Overlinked (' . ( $s['overlinked'] ?? 0 ) . ')', __( 'Strony z ponad 20 linkami wychodzącymi — equity się rozrzedza.', 'loom' ) ),
					'broken'    => array( '❌ Broken', __( 'Strony zawierające zepsute linki wewnętrzne (prowadzące do nieistniejących stron).', 'loom' ) ),
				);
				foreach ( $filters as $fv => $fdata ) :
					$href = add_query_arg( array( 'tab' => 'posts', 'filter' => $fv ), admin_url( 'admin.php?page=loom' ) );
				?>
				<a href="<?php echo esc_url( $href ); ?>" class="loom-filter <?php echo $filter === $fv ? 'active' : ''; ?>" title="<?php echo esc_attr( $fdata[1] ); ?>"><?php echo esc_html( $fdata[0] ); ?></a>
				<?php endforeach; ?>
			</div>
			<table class="loom-tbl"><thead><tr>
				<th><?php esc_html_e( 'Tytuł', 'loom' ); ?></th>
				<th class="loom-tc" title="<?php esc_attr_e( 'Linki przychodzące — ile stron linkuje DO tej strony. Im więcej, tym silniejsza strona.', 'loom' ); ?>">IN</th>
				<th class="loom-tc" title="<?php esc_attr_e( 'Linki wychodzące — ile linków wewnętrznych jest W treści tej strony. 0 = dead end.', 'loom' ); ?>">OUT</th>
				<th class="loom-tc" title="<?php esc_attr_e( 'Internal PageRank — siła strony obliczona algorytmem PageRank na grafie Twojej witryny. Wyższa = ważniejsza strona.', 'loom' ); ?>">PR</th>
				<th class="loom-tc" title="<?php esc_attr_e( 'Click depth — ile kliknięć od strony głównej. Google zaleca ≤3. Większa głębokość = gorzej zaindeksowana.', 'loom' ); ?>">Depth</th>
				<th class="loom-tc" title="<?php esc_attr_e( 'Średnia pozycja w Google z Google Search Console. Dane z ostatnich 28 dni.', 'loom' ); ?>">GSC</th>
				<th class="loom-tc" title="<?php esc_attr_e( 'Impressions z Google — ile razy strona wyświetliła się w wynikach wyszukiwania.', 'loom' ); ?>">Impr</th>
				<th title="<?php esc_attr_e( 'Główne słowa kluczowe wyodrębnione z treści (SEO plugin + tytuł + TF-IDF + opcjonalnie GPT + GSC queries).', 'loom' ); ?>">Keywords</th>
				<th class="loom-tc" title="<?php esc_attr_e( 'Status strony: Orphan (0 linków IN), Słaby (1-2 IN), Dead End (0 linków OUT), Striking (pozycja 5-20), Hub (10+ IN).', 'loom' ); ?>">Status</th>
				<th class="loom-tc"><?php esc_html_e( 'Akcja', 'loom' ); ?></th>
			</tr></thead><tbody>
			<?php foreach ( $posts as $row ) :
				if ( $filter === 'orphans' && empty( $row['is_orphan'] ) ) continue;
				if ( $filter === 'near' && ( intval( $row['incoming_links_count'] ) < 1 || intval( $row['incoming_links_count'] ) > 2 || ! empty( $row['is_structural'] ) ) ) continue;
				if ( $filter === 'structural' && empty( $row['is_structural'] ) ) continue;
				if ( $filter === 'weak' && ( intval( $row['incoming_links_count'] ) >= 3 || ! empty( $row['is_orphan'] ) ) ) continue;
				if ( $filter === 'money' && empty( $row['is_money_page'] ) ) continue;
				if ( $filter === 'striking' && empty( $row['is_striking_distance'] ) ) continue;
				if ( $filter === 'deadends' && empty( $row['is_dead_end'] ) ) continue;
				if ( $filter === 'overlinked' && intval( $row['outgoing_links_count'] ) <= 20 ) continue;
				if ( $filter === 'broken' ) {
					global $wpdb;
					$has_broken = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM " . Loom_DB::links_table() . " WHERE source_post_id = %d AND is_broken = 1",
						$row['post_id']
					) );
					if ( ! $has_broken ) continue;
				}

				$in      = intval( $row['incoming_links_count'] );
				$is_mp   = ! empty( $row['is_money_page'] );
				$gsc_pos = floatval( $row['gsc_position'] ?? 0 );
				$gsc_imp = intval( $row['gsc_impressions'] ?? 0 );
				$pr_val  = isset( $row['internal_pagerank'] ) && $row['internal_pagerank'] !== null ? number_format( floatval( $row['internal_pagerank'] ) * 100, 1 ) : ' - ';
				$d       = $row['click_depth'];
				$ds      = ( $d !== null && $d !== '' ) ? intval( $d ) : ' - ';

				// Keywords
				$kw_data = ! empty( $row['focus_keywords'] ) ? json_decode( $row['focus_keywords'], true ) : array();
			?>
			<tr<?php echo ! empty( $row['is_structural'] ) ? ' style="opacity:.6"' : ''; ?>>
				<td>
					<button class="loom-structural-toggle <?php echo ! empty( $row['is_structural'] ) ? 'active' : ''; ?>" data-post-id="<?php echo esc_attr( $row['post_id'] ); ?>" data-is-structural="<?php echo ! empty( $row['is_structural'] ) ? '1' : '0'; ?>" title="<?php esc_attr_e( 'Oznacz jako stronę strukturalną (menu/footer) — wykluczona z orphan metryk', 'loom' ); ?>" style="background:none;border:1px solid <?php echo ! empty( $row['is_structural'] ) ? 'var(--teal)' : '#e5e7eb'; ?>;border-radius:5px;cursor:pointer;font-size:11px;padding:1px 4px"><?php echo ! empty( $row['is_structural'] ) ? '🏗️' : '·'; ?></button>
					<button class="loom-money-toggle <?php echo $is_mp ? 'active' : ''; ?>" data-post-id="<?php echo esc_attr( $row['post_id'] ); ?>" data-is-money="<?php echo $is_mp ? '1' : '0'; ?>"><?php echo $is_mp ? '⭐' : '☆'; ?></button>
					<a href="<?php echo esc_url( get_edit_post_link( $row['post_id'] ) ); ?>" class="loom-link"><?php echo esc_html( mb_substr( $row['post_title'], 0, 45 ) ); ?></a>
					<span class="loom-muted" style="font-size:10px;margin-left:4px"><?php echo esc_html( $row['post_type'] ); ?></span>
				</td>
				<td class="loom-tc" style="font-weight:700"><?php echo esc_html( $in ); ?></td>
				<td class="loom-tc loom-muted"><?php echo intval( $row['outgoing_links_count'] ) > 20 ? '<span style="color:var(--bad);font-weight:700">' . esc_html( $row['outgoing_links_count'] ) . '</span>' : esc_html( $row['outgoing_links_count'] ); ?></td>
				<td class="loom-tc loom-tn"><?php echo esc_html( $pr_val ); ?></td>
				<td class="loom-tc"><?php echo esc_html( $ds ); ?></td>
				<td class="loom-tc"><?php
					if ( $gsc_pos > 0 ) {
						$gc = $gsc_pos <= 10 ? 'var(--ok)' : ( $gsc_pos <= 20 ? 'var(--purple)' : 'var(--muted)' );
						echo '<span class="loom-tn" style="font-weight:700;color:' . esc_attr( $gc ) . '">' . esc_html( number_format( $gsc_pos, 1 ) ) . '</span>';
					} else { echo '<span class="loom-muted"> - </span>'; }
				?></td>
				<td class="loom-tc loom-tn" style="font-size:10px"><?php echo $gsc_imp > 0 ? esc_html( number_format( $gsc_imp ) ) : '<span class="loom-muted"> - </span>'; ?></td>
				<td><?php if ( ! empty( $kw_data ) ) : ?><div style="display:flex;flex-wrap:wrap;gap:2px"><?php foreach ( array_slice( $kw_data, 0, 2 ) as $kw ) : ?><span class="loom-code" style="font-size:9px"><?php echo esc_html( mb_substr( $kw['phrase'], 0, 20 ) ); ?></span><?php endforeach; ?></div><?php endif; ?></td>
				<td class="loom-tc">
					<?php
					if ( ! empty( $row['is_structural'] ) ) echo '<span class="loom-badge" style="background:#f1f5f9;color:#64748b">🏗️</span>';
					elseif ( $row['is_orphan'] )   echo '<span class="loom-badge loom-b-bad">Orphan</span>';
					elseif ( $in > 0 && $in <= 2 ) echo '<span class="loom-badge" style="background:#fef3c7;color:#92400e">Near-orphan</span>';
					if ( $row['is_dead_end'] ) echo '<span class="loom-badge loom-b-warn">DE</span>';
					if ( $row['is_bridge'] )   echo '<span class="loom-badge loom-b-neutral">🌉</span>';
					if ( ! empty( $row['is_striking_distance'] ) ) echo '<span class="loom-badge loom-b-striking">🎯</span>';
					if ( $is_mp )              echo '<span class="loom-badge loom-b-money">💰</span>';
					if ( intval( $row['outgoing_links_count'] ) > 20 ) echo '<span class="loom-badge" style="background:#fef2f2;color:#dc2626">⚠️ OL</span>';
					if ( ! $row['is_orphan'] && empty( $row['is_structural'] ) && ! $row['is_dead_end'] && $in >= 3 ) echo '<span class="loom-badge loom-b-ok">OK</span>';
					?>
				</td>
				<td class="loom-tc"><?php if ( $has_key && empty( $row['is_structural'] ) ) : ?>
					<?php if ( $row['is_orphan'] ) : ?>
						<button class="loom-btn loom-btn-sm loom-rescue-btn" data-post-id="<?php echo esc_attr( $row['post_id'] ); ?>" style="background:#fef2f2;border-color:#dc2626;color:#dc2626" title="<?php esc_attr_e( 'Reverse Rescue — znajdź artykuły które mogą linkować do tej strony', 'loom' ); ?>">🔍 Rescue</button>
					<?php else : ?>
						<button class="loom-btn loom-btn-sm loom-podlinkuj-btn" data-post-id="<?php echo esc_attr( $row['post_id'] ); ?>">🔗</button>
						<button class="loom-btn loom-btn-sm loom-btn-ok loom-auto-btn" data-post-id="<?php echo esc_attr( $row['post_id'] ); ?>">⚡</button>
					<?php endif; ?>
				<?php elseif ( ! empty( $row['is_structural'] ) ) : ?>
					<span class="loom-muted" style="font-size:10px">—</span>
				<?php endif; ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody></table>
		</div>

		<?php // ═══ SETTINGS TAB ═══
		elseif ( $tab === 'settings' ) : ?>
		<div class="loom-grid-2">
			<!-- Weights -->
			<div class="loom-card"><div class="loom-card-body">
				<h3 style="font-size:13px;font-weight:700;margin-bottom:12px"><?php esc_html_e( 'Wagi composite score (11 wymiarów)', 'loom' ); ?></h3>
				<?php
				$dims = array(
					array( 'semantic', '🧠', 'Semantic', 'Cosine similarity' ),
					array( 'orphan', '🔴', 'Orphan', 'Brak linków IN' ),
					array( 'depth', '📐', 'Depth', 'Click depth' ),
					array( 'tier', '🏛️', 'Tier', 'Hierarchia strony' ),
					array( 'cluster', '🧩', 'Cluster', 'Topic cluster' ),
					array( 'equity', '📈', 'Velocity', 'Tempo linkowania' ),
					array( 'graph', '🕸️', 'Graph', 'PageRank + topology' ),
					array( 'money', '💰', 'Money', 'Money page boost' ),
					array( 'gsc', '📊', 'GSC', 'Search Console data' ),
					array( 'authority', '🏆', 'Authority', 'Topical authority' ),
					array( 'placement', '📍', 'Placement', 'Paragraph match quality' ),
				);
				foreach ( $dims as $d ) :
					$val = round( floatval( $settings[ 'weight_' . $d[0] ] ?? 0.1 ) * 100 );
				?>
				<div class="loom-weight-row">
					<span class="loom-weight-icon"><?php echo $d[1]; ?></span>
					<div class="loom-weight-info"><div class="loom-weight-name"><?php echo esc_html( $d[2] ); ?></div><div class="loom-weight-desc"><?php echo esc_html( $d[3] ); ?></div></div>
					<input type="range" class="loom-weight-slider" min="0" max="50" step="1" value="<?php echo esc_attr( $val ); ?>" data-key="weight_<?php echo esc_attr( $d[0] ); ?>">
					<span class="loom-weight-pct"><?php echo esc_html( $val ); ?>%</span>
				</div>
				<?php endforeach; ?>
				<div class="loom-weight-sum">
					<span style="font-size:11px;color:var(--muted)">Suma:</span>
					<span id="loom-weight-sum" style="font-size:12px;font-weight:700;font-family:var(--mono)">100%</span>
				</div>
				<div style="display:flex;gap:6px;margin-top:8px">
					<button class="loom-btn loom-btn-sm" id="loom-save-weights"><?php esc_html_e( 'Zapisz', 'loom' ); ?></button>
					<button class="loom-btn loom-btn-sm loom-btn-gray" id="loom-normalize-weights"><?php esc_html_e( 'Normalizuj', 'loom' ); ?></button>
				</div>
			</div></div>

			<!-- GSC + General -->
			<div style="display:flex;flex-direction:column;gap:14px">
				<div class="loom-card"><div class="loom-card-body">
					<h3 style="font-size:13px;font-weight:700;margin-bottom:10px">📊 Google Search Console</h3>
					<div class="loom-gsc-status" style="margin-bottom:10px">
						<span class="loom-gsc-dot <?php echo $gsc_on ? 'loom-gsc-dot-on' : 'loom-gsc-dot-off'; ?>"></span>
						<span><?php echo $gsc_on ? esc_html__( 'Połączony', 'loom' ) : esc_html__( 'Niepołączony', 'loom' ); ?></span>
						<?php if ( $s['last_gsc_sync'] ) : ?><span class="loom-muted" style="margin-left:auto;font-size:10px">Sync: <?php echo esc_html( $s['last_gsc_sync'] ); ?></span><?php endif; ?>
					</div>
					<div style="display:flex;gap:6px">
						<?php if ( $gsc_on ) : ?>
						<button class="loom-btn loom-btn-sm loom-btn-purple" id="loom-gsc-sync">🔄 Sync</button>
						<button class="loom-btn loom-btn-sm loom-btn-danger" id="loom-gsc-disconnect"><?php esc_html_e( 'Rozłącz', 'loom' ); ?></button>
						<?php else : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=loom-settings' ) ); ?>" class="loom-btn loom-btn-sm loom-btn-purple"><?php esc_html_e( 'Połącz GSC', 'loom' ); ?></a>
						<?php endif; ?>
					</div>
				</div></div>

				<div class="loom-card"><div class="loom-card-body">
					<h3 style="font-size:13px;font-weight:700;margin-bottom:10px">🎛️ <?php esc_html_e( 'Ogólne', 'loom' ); ?></h3>
					<div class="loom-settings-row"><label>Min. similarity</label><input type="number" class="loom-settings-input" name="loom_min_similarity" value="<?php echo esc_attr( $settings['min_similarity'] ?? 0.35 ); ?>" min="0.05" max="0.8" step="0.01"></div>
					<div class="loom-settings-row"><label>Max sugestii</label><input type="number" class="loom-settings-input" name="loom_max_suggestions" value="<?php echo esc_attr( $settings['max_suggestions'] ?? 8 ); ?>" min="3" max="15"></div>
					<div class="loom-settings-row"><label>Język</label>
						<select class="loom-settings-select" name="loom_language">
							<option value="pl" <?php selected( $settings['language'] ?? 'pl', 'pl' ); ?>>🇵🇱 Polski</option>
							<option value="en" <?php selected( $settings['language'] ?? 'pl', 'en' ); ?>>🇬🇧 English</option>
							<option value="de" <?php selected( $settings['language'] ?? 'pl', 'de' ); ?>>🇩🇪 Deutsch</option>
						</select>
					</div>
				</div></div>
			</div>
		</div>

		<?php endif; // end tabs ?>
		<?php endif; // end scanned check ?>

		<div id="loom-suggestions-panel" style="display:none;"></div>
		</div>
		<?php
	}
}
