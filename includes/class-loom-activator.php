<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Loom_Activator {

	public static function activate() {
		self::create_tables();
		add_option( 'loom_db_version', LOOM_DB_VERSION );
		add_option( 'loom_onboarding_done', false );
		add_option( 'loom_scan_completed', false );
		add_option( 'loom_settings', self::default_settings() );

		// Redirect to onboarding after activation.
		set_transient( 'loom_activation_redirect', true, 30 );
	}

	public static function deactivate() {
		delete_transient( 'loom_activation_redirect' );
		wp_clear_scheduled_hook( 'loom_weekly_rescan' );
	}

	public static function maybe_upgrade() {
		$installed = get_option( 'loom_db_version', '0' );
		if ( version_compare( $installed, LOOM_DB_VERSION, '<' ) ) {
			self::create_tables();

			// Merge new default settings into existing (preserves user values, adds missing keys).
			$existing = get_option( 'loom_settings', array() );
			$defaults = self::default_settings();
			$merged   = array_merge( $defaults, $existing );

			// Re-normalize weights if new dimensions were added.
			$weight_keys = array_filter( array_keys( $defaults ), function( $k ) {
				return strpos( $k, 'weight_' ) === 0;
			} );
			$weight_sum = 0;
			foreach ( $weight_keys as $wk ) {
				$weight_sum += floatval( $merged[ $wk ] ?? 0 );
			}
			if ( $weight_sum > 0 && abs( $weight_sum - 1.0 ) > 0.01 ) {
				foreach ( $weight_keys as $wk ) {
					$merged[ $wk ] = round( floatval( $merged[ $wk ] ?? 0 ) / $weight_sum, 4 );
				}
			}

			update_option( 'loom_settings', $merged );
			update_option( 'loom_db_version', LOOM_DB_VERSION );

			// Migration: add target_url column if missing (v2.3+).
			global $wpdb;
			$lnk = $wpdb->prefix . 'loom_links';
			$col = $wpdb->get_results( "SHOW COLUMNS FROM {$lnk} LIKE 'target_url'" );
			if ( empty( $col ) ) {
				$wpdb->query( "ALTER TABLE {$lnk} ADD COLUMN target_url varchar(500) NOT NULL DEFAULT '' AFTER target_post_id" );
				$wpdb->query( "ALTER TABLE {$lnk} ADD KEY is_broken (is_broken)" );
			}

			// Migration: add is_structural column if missing (v2.4+).
			$idx = $wpdb->prefix . 'loom_index';
			$col2 = $wpdb->get_results( "SHOW COLUMNS FROM {$idx} LIKE 'is_structural'" );
			if ( empty( $col2 ) ) {
				$wpdb->query( "ALTER TABLE {$idx} ADD COLUMN is_structural tinyint(1) DEFAULT 0 AFTER is_orphan" );
			}
		}
	}

	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$sql_index = "CREATE TABLE {$wpdb->prefix}loom_index (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			post_title varchar(500) NOT NULL DEFAULT '',
			post_url varchar(500) NOT NULL DEFAULT '',
			post_type varchar(20) NOT NULL DEFAULT 'post',
			clean_text longtext,
			clean_text_hash char(32) DEFAULT '',
			word_count int(10) unsigned DEFAULT 0,
			incoming_links_count int(11) DEFAULT 0,
			outgoing_links_count int(11) DEFAULT 0,
			is_orphan tinyint(1) DEFAULT 0,
			is_structural tinyint(1) DEFAULT 0,
			click_depth tinyint(3) unsigned DEFAULT NULL,
			cluster_id bigint(20) unsigned DEFAULT NULL,
			site_tier tinyint(3) unsigned DEFAULT NULL,
			embedding longtext,
			embedding_model varchar(50) DEFAULT NULL,
			focus_keywords text DEFAULT NULL,
			internal_pagerank decimal(10,8) DEFAULT NULL,
			betweenness decimal(8,4) DEFAULT NULL,
			is_dead_end tinyint(1) DEFAULT 0,
			is_bridge tinyint(1) DEFAULT 0,
			component_id int(11) DEFAULT NULL,
			is_money_page tinyint(1) DEFAULT 0,
			money_priority tinyint(1) DEFAULT 0,
			target_links_goal tinyint(3) unsigned DEFAULT 10,
			gsc_clicks int(11) DEFAULT 0,
			gsc_impressions int(11) DEFAULT 0,
			gsc_ctr decimal(5,4) DEFAULT NULL,
			gsc_position decimal(5,2) DEFAULT NULL,
			gsc_top_queries text DEFAULT NULL,
			is_striking_distance tinyint(1) DEFAULT 0,
			last_gsc_sync datetime DEFAULT NULL,
			last_scanned datetime DEFAULT NULL,
			last_embedding datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_id (post_id),
			KEY is_orphan (is_orphan),
			KEY incoming_links_count (incoming_links_count),
			KEY post_type (post_type),
			KEY click_depth (click_depth),
			KEY cluster_id (cluster_id),
			KEY is_money_page (is_money_page)
		) {$charset};";

		$sql_links = "CREATE TABLE {$wpdb->prefix}loom_links (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_post_id bigint(20) unsigned NOT NULL,
			target_post_id bigint(20) unsigned NOT NULL,
				target_url varchar(500) NOT NULL DEFAULT '',
			anchor_text varchar(500) NOT NULL DEFAULT '',
			link_position enum('top','middle','bottom') DEFAULT 'middle',
			position_percent tinyint(3) unsigned DEFAULT 50,
			is_plugin_generated tinyint(1) DEFAULT 0,
			is_broken tinyint(1) DEFAULT 0,
			is_nofollow tinyint(1) DEFAULT 0,
			anchor_match_score decimal(3,2) DEFAULT NULL,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY source_post_id (source_post_id),
			KEY target_post_id (target_post_id),
			KEY is_plugin_generated (is_plugin_generated),
				KEY is_broken (is_broken)
		) {$charset};";

		$sql_log = "CREATE TABLE {$wpdb->prefix}loom_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			action varchar(50) NOT NULL DEFAULT '',
			post_id bigint(20) unsigned DEFAULT NULL,
			details text,
			api_tokens_used int(11) DEFAULT 0,
			api_cost_usd decimal(8,4) DEFAULT 0.0000,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY action (action),
			KEY created_at (created_at)
		) {$charset};";

		$sql_clusters = "CREATE TABLE {$wpdb->prefix}loom_clusters (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			cluster_name varchar(255) NOT NULL,
			pillar_post_id bigint(20) unsigned NOT NULL,
			description text DEFAULT NULL,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY pillar_post_id (pillar_post_id),
			KEY cluster_name (cluster_name)
		) {$charset};";

		$sql_rejections = "CREATE TABLE {$wpdb->prefix}loom_rejections (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			target_post_id bigint(20) unsigned NOT NULL,
			anchor_text varchar(500) DEFAULT '',
			rejected_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY post_target (post_id,target_post_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_index );
		dbDelta( $sql_links );
		dbDelta( $sql_log );
		dbDelta( $sql_clusters );
		dbDelta( $sql_rejections );
	}

	public static function default_settings() {
		return array(
			'post_types'          => array( 'post', 'page' ),
			'min_similarity'      => 0.35,
			'max_suggestions'     => 8,
			'language'            => 'pl',
			'weight_semantic'     => 0.22,
			'weight_orphan'       => 0.08,
			'weight_depth'        => 0.06,
			'weight_tier'         => 0.06,
			'weight_cluster'      => 0.06,
			'weight_equity'       => 0.04,
			'weight_graph'        => 0.10,
			'weight_money'        => 0.10,
			'weight_gsc'          => 0.08,
			'weight_authority'    => 0.10,
			'weight_placement'    => 0.10,
			'rescan_on_save'      => true,
			'admin_notices'       => true,
		);
	}
}
