<?php
/**
 * Plugin Name: LOOM
 * Plugin URI:  https://marcinzmuda.com/loom
 * Description: AI-powered internal linking engine. Semantic embeddings, PageRank, 11-dimensional scoring, GPT-4o-mini, Google Search Console integration.
 * Version:     2.4.0
 * Author:      Marcin Żmuda
 * Author URI:  https://marcinzmuda.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: loom
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LOOM_VERSION', '2.4.0' );
define( 'LOOM_DB_VERSION', '2.4' );
define( 'LOOM_FILE', __FILE__ );
define( 'LOOM_PATH', plugin_dir_path( __FILE__ ) );
define( 'LOOM_URL', plugin_dir_url( __FILE__ ) );
define( 'LOOM_BASENAME', plugin_basename( __FILE__ ) );

// Core includes (always loaded).
require_once LOOM_PATH . 'includes/class-loom-activator.php';

// Load translations.
add_action( 'init', function() {
	load_plugin_textdomain( 'loom', false, dirname( LOOM_BASENAME ) . '/languages' );
} );

require_once LOOM_PATH . 'includes/class-loom-db.php';
require_once LOOM_PATH . 'includes/class-loom-scanner.php';
require_once LOOM_PATH . 'includes/class-loom-openai.php';
require_once LOOM_PATH . 'includes/class-loom-similarity.php';
require_once LOOM_PATH . 'includes/class-loom-composite.php';
require_once LOOM_PATH . 'includes/class-loom-suggester.php';
require_once LOOM_PATH . 'includes/class-loom-linker.php';
require_once LOOM_PATH . 'includes/class-loom-analyzer.php';
require_once LOOM_PATH . 'includes/class-loom-keywords.php';
require_once LOOM_PATH . 'includes/class-loom-site-analysis.php';
require_once LOOM_PATH . 'includes/class-loom-graph.php';
require_once LOOM_PATH . 'includes/class-loom-gsc.php';

// Admin-only includes.
if ( is_admin() ) {
	require_once LOOM_PATH . 'admin/class-loom-admin.php';
	require_once LOOM_PATH . 'admin/class-loom-dashboard.php';
	require_once LOOM_PATH . 'admin/class-loom-metabox.php';
	require_once LOOM_PATH . 'admin/class-loom-bulk.php';
	require_once LOOM_PATH . 'admin/class-loom-settings.php';
	require_once LOOM_PATH . 'admin/class-loom-onboarding.php';
}

// Activation & deactivation.
register_activation_hook( __FILE__, array( 'Loom_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Loom_Activator', 'deactivate' ) );

// DB version check on every admin load (catches auto-updates).
add_action( 'plugins_loaded', array( 'Loom_Activator', 'maybe_upgrade' ) );

// Initialize admin UI.
if ( is_admin() ) {
	add_action( 'plugins_loaded', function () {
		Loom_Admin::init();
		Loom_Dashboard::init();
		Loom_Metabox::init();
		Loom_Bulk::init();
		Loom_Settings::init();
		Loom_Onboarding::init();
	} );
}

// Register AJAX handlers (admin only).
add_action( 'wp_ajax_loom_batch_scan', array( 'Loom_Scanner', 'ajax_batch_scan' ) );
add_action( 'wp_ajax_loom_podlinkuj', array( 'Loom_Suggester', 'ajax_podlinkuj' ) );
add_action( 'wp_ajax_loom_apply_links', array( 'Loom_Linker', 'ajax_apply_links' ) );
add_action( 'wp_ajax_loom_auto_podlinkuj', array( 'Loom_Linker', 'ajax_auto_podlinkuj' ) );
add_action( 'wp_ajax_loom_save_settings', array( 'Loom_Settings', 'ajax_save' ) );
add_action( 'wp_ajax_loom_generate_embeddings', array( 'Loom_OpenAI', 'ajax_generate_embeddings' ) );
add_action( 'wp_ajax_loom_get_link_map', array( 'Loom_Dashboard', 'ajax_link_map' ) );
add_action( 'wp_ajax_loom_extract_keywords', array( 'Loom_Keywords', 'ajax_extract_keywords' ) );
add_action( 'wp_ajax_loom_reject_suggestion', array( 'Loom_Site_Analysis', 'ajax_reject' ) );
add_action( 'wp_ajax_loom_recalc_depth', array( 'Loom_Site_Analysis', 'ajax_recalc_depth' ) );
add_action( 'wp_ajax_loom_recalc_graph', array( 'Loom_Graph', 'ajax_recalc_graph' ) );
add_action( 'wp_ajax_loom_structural_suggestions', array( 'Loom_Graph', 'ajax_structural_suggestions' ) );
add_action( 'wp_ajax_loom_gsc_save_credentials', array( 'Loom_GSC', 'ajax_save_credentials' ) );
add_action( 'wp_ajax_loom_gsc_sync', array( 'Loom_GSC', 'ajax_sync' ) );
add_action( 'wp_ajax_loom_gsc_disconnect', array( 'Loom_GSC', 'ajax_disconnect' ) );
add_action( 'wp_ajax_loom_remove_all_links', array( 'Loom_Linker', 'ajax_remove_all_loom_links' ) );
add_action( 'wp_ajax_loom_get_broken_links', array( 'Loom_Linker', 'ajax_get_broken_links' ) );
add_action( 'wp_ajax_loom_fix_broken_link', array( 'Loom_Linker', 'ajax_fix_broken_link' ) );
add_action( 'wp_ajax_loom_set_money_page', array( 'Loom_DB', 'ajax_set_money_page' ) );
add_action( 'wp_ajax_loom_get_money_pages', array( 'Loom_DB', 'ajax_get_money_pages' ) );

// v2.4 endpoints.
add_action( 'wp_ajax_loom_set_structural', array( 'Loom_DB', 'ajax_set_structural' ) );
add_action( 'wp_ajax_loom_reverse_rescue', array( 'Loom_Suggester', 'ajax_reverse_rescue' ) );
add_action( 'wp_ajax_loom_silo_check', array( 'Loom_Site_Analysis', 'ajax_silo_check' ) );
add_action( 'wp_ajax_loom_diagnostics', array( 'Loom_Site_Analysis', 'ajax_diagnostics' ) );

// Content hooks.
add_action( 'save_post', array( 'Loom_Scanner', 'on_save_post' ), 20, 3 );
add_action( 'before_delete_post', array( 'Loom_Scanner', 'on_delete_post' ) );

// WP Cron: weekly auto-rescan.
add_action( 'loom_weekly_rescan', array( 'Loom_Scanner', 'cron_weekly_rescan' ) );
if ( ! wp_next_scheduled( 'loom_weekly_rescan' ) ) {
	wp_schedule_event( time(), 'weekly', 'loom_weekly_rescan' );
}
