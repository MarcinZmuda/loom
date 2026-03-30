<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Loom_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
		add_action( 'admin_notices', array( __CLASS__, 'orphan_notice' ) );
	}

	public static function register_menu() {
		add_menu_page(
			'LOOM',
			'LOOM',
			'edit_posts',
			'loom',
			array( 'Loom_Dashboard', 'render' ),
			'data:image/png;base64,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',
			30
		);

		add_submenu_page( 'loom', __( 'Dashboard', 'loom' ), __( 'Dashboard', 'loom' ), 'edit_posts', 'loom', array( 'Loom_Dashboard', 'render' ) );
		add_submenu_page( 'loom', __( 'Bulk Mode', 'loom' ), __( 'Bulk Mode', 'loom' ), 'edit_posts', 'loom-bulk', array( 'Loom_Bulk', 'render' ) );
		add_submenu_page( 'loom', __( 'Ustawienia', 'loom' ), __( 'Ustawienia', 'loom' ), 'manage_options', 'loom-settings', array( 'Loom_Settings', 'render' ) );
	}

	public static function enqueue_assets( $hook ) {
		$loom_pages = array( 'toplevel_page_loom', 'loom_page_loom-bulk', 'loom_page_loom-settings' );
		$is_edit    = in_array( $hook, array( 'post.php', 'post-new.php' ), true );

		if ( ! in_array( $hook, $loom_pages, true ) && ! $is_edit ) {
			return;
		}

		wp_enqueue_style(
			'loom-admin',
			LOOM_URL . 'assets/css/loom-admin.css',
			array(),
			LOOM_VERSION
		);

		wp_enqueue_script(
			'loom-admin',
			LOOM_URL . 'assets/js/loom-admin.js',
			array( 'jquery' ),
			LOOM_VERSION,
			true
		);

		wp_localize_script( 'loom-admin', 'loom_ajax', array(
			'ajaxurl'  => admin_url( 'admin-ajax.php' ),
			'adminurl' => admin_url(),
			'nonce'    => wp_create_nonce( 'loom_nonce' ),
			'i18n'    => array(
				'scanning'    => __( 'Skanowanie...', 'loom' ),
				'generating'  => __( 'Generowanie embeddingów...', 'loom' ),
				'analyzing'   => __( 'Analizowanie...', 'loom' ),
				'applying'    => __( 'Wstawianie linków...', 'loom' ),
				'done'        => __( 'Gotowe!', 'loom' ),
				'error'       => __( 'Błąd', 'loom' ),
				'confirm'     => __( 'Czy na pewno chcesz zastosować wybrane linki?', 'loom' ),
				'auto_processing' => __( 'Przetwarzanie...', 'loom' ),
				'auto_done'       => __( 'Auto-linkowanie zakończone!', 'loom' ),
			),
		) );
	}

	public static function maybe_redirect() {
		if ( get_transient( 'loom_activation_redirect' ) ) {
			delete_transient( 'loom_activation_redirect' );
			if ( ! isset( $_GET['activate-multi'] ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=loom' ) );
				exit;
			}
		}
	}

	public static function orphan_notice() {
		if ( ! get_option( 'loom_scan_completed' ) ) return;

		$settings = Loom_DB::get_settings();
		if ( empty( $settings['admin_notices'] ) ) return;

		// Only show once per session.
		$dismissed = get_user_meta( get_current_user_id(), '_loom_notice_dismissed', true );
		if ( $dismissed === date( 'Y-m-d' ) ) return;

		$stats = Loom_DB::get_dashboard_stats();
		if ( $stats['orphans'] > 0 ) {
			echo '<div class="notice notice-warning is-dismissible" id="loom-orphan-notice">';
			echo '<p><strong>LOOM:</strong> ';
			printf(
				/* translators: %d: number of orphan pages */
				esc_html__( 'Masz %d stron bez linków przychodzących (orphan pages).', 'loom' ),
				$stats['orphans']
			);
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=loom-bulk&filter=orphans' ) ) . '">';
			echo esc_html__( 'Napraw w Bulk Mode ->', 'loom' );
			echo '</a></p></div>';
			echo '<script>jQuery(function($){$("#loom-orphan-notice").on("click",".notice-dismiss",function(){$.post(ajaxurl,{action:"loom_dismiss_notice",nonce:"' . esc_js( wp_create_nonce( 'loom_nonce' ) ) . '"})})});</script>';
		}
	}
}

// Dismiss notice AJAX.
add_action( 'wp_ajax_loom_dismiss_notice', function () {
	check_ajax_referer( 'loom_nonce', 'nonce' );
	update_user_meta( get_current_user_id(), '_loom_notice_dismissed', date( 'Y-m-d' ) );
	wp_send_json_success();
} );
