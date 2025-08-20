<?php

namespace Static_Mirror;


class Admin {

	static $instance;

	public static function get_instance() {

		if ( ! self::$instance ) {
			$class = get_called_class();
			self::$instance = new $class();
		}

		return self::$instance;
	}

	public function __construct() {
		add_action( 'admin_init', array( $this, 'check_form_submission' ) );
		add_action( 'admin_init', array( $this, 'check_manual_mirror' ) );
	}

	/**
	 * Add the Tools page page
	 */
	public function add_tools_page() {
		add_submenu_page( 'tools.php', 'Static Mirrors', 'Static Mirror', 'static_mirror_manage_mirrors', 'static-mirror-tools-page', array( $this, 'output_tools_page' ) );
	}

	public function output_tools_page() {

		include dirname( __FILE__ ) . '/../templates/admin-tools-page.php';
	}

	public function check_manual_mirror() {

		if ( empty( $_GET['action'] ) || $_GET['action'] !== 'static-mirror-create-mirror' ) {
			return;
		}

		if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'static-mirror-create' ) ) {
			wp_die( 'Failed to verify nonce, sorry' );
		}

		Plugin::get_instance()->queue_complete_mirror( 'Manually triggered mirror', 0 );

		wp_safe_redirect( remove_query_arg( array( '_wpnonce', 'action' ) ) );
		exit;
	}

	public function check_form_submission() {

		if ( empty( $_POST['action'] ) || $_POST['action'] !== 'update-static-mirror' ) {
			return;
		}

		check_admin_referer( 'static-mirror.update' );

		$urls = array_filter(
			array_map( 'esc_url_raw', explode( "\n", $_POST['static-mirror-urls'] ) )
		);

		Plugin::get_instance()->set_base_urls( $urls );

		$no_check = isset( $_POST['static-mirror-no-check-certificate'] ) ? 1 : 0;
		update_option( 'static_mirror_no_check_certificate', $no_check );

		$reject_patterns = isset( $_POST['static-mirror-reject-patterns'] ) ? (string) $_POST['static-mirror-reject-patterns'] : '';
		$reject_patterns = preg_replace( '/^\s+|\s+$/m', '', $reject_patterns );
		update_option( 'static_mirror_reject_patterns', $reject_patterns );

		$resource_domains = isset( $_POST['static-mirror-resource-domains'] ) ? (string) $_POST['static-mirror-resource-domains'] : '';
		$resource_domains = preg_replace( '/^\s+|\s+$/m', '', $resource_domains );
		update_option( 'static_mirror_resource_domains', $resource_domains );

		$crawler_cookies = isset( $_POST['static-mirror-crawler-cookies'] ) ? (string) $_POST['static-mirror-crawler-cookies'] : '';
		$crawler_cookies = preg_replace( '/^\s+|\s+$/m', '', $crawler_cookies );
		update_option( 'static_mirror_crawler_cookies', $crawler_cookies );

		$user_agent = isset( $_POST['static-mirror-user-agent'] ) ? (string) $_POST['static-mirror-user-agent'] : '';
		update_option( 'static_mirror_user_agent', sanitize_text_field( $user_agent ) );

		$robots_on = isset( $_POST['static-mirror-robots-on'] ) ? 1 : 0;
		update_option( 'static_mirror_robots_on', $robots_on );
	}
}