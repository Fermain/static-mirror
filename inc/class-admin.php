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
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ), 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Add the Tools page page
	 */
	public function add_tools_page() {
		add_submenu_page( 'tools.php', 'Static Mirrors', 'Static Mirror', 'static_mirror_manage_mirrors', 'static-mirror-tools-page', array( $this, 'output_tools_page' ) );
	}

	/**
	 * Add the Settings subpage under Tools
	 */
	public function add_settings_page() {
		add_submenu_page( 'tools.php', 'Static Mirror Settings', 'Static Mirror Settings', 'static_mirror_manage_mirrors', 'static-mirror-settings', array( $this, 'render_settings_page' ) );
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

	/**
	 * Register settings/sections/fields via Settings API
	 */
	public function register_settings() {
		register_setting( 'static_mirror', 'static_mirror_settings', array( $this, 'sanitize_settings' ) );

		add_settings_section( 'sm_section_general', __( 'General', 'static-mirror' ), '__return_false', 'static-mirror-settings' );
		add_settings_section( 'sm_section_crawling', __( 'Crawling', 'static-mirror' ), '__return_false', 'static-mirror-settings' );
		add_settings_section( 'sm_section_exclusions', __( 'Exclusions', 'static-mirror' ), '__return_false', 'static-mirror-settings' );
		add_settings_section( 'sm_section_resources', __( 'Resources', 'static-mirror' ), '__return_false', 'static-mirror-settings' );

		add_settings_field( 'starting_urls', __( 'Starting URLs', 'static-mirror' ), array( $this, 'field_textarea' ), 'static-mirror-settings', 'sm_section_general', array( 'key' => 'starting_urls', 'desc' => __( 'One per line.', 'static-mirror' ) ) );

		add_settings_field( 'user_agent', __( 'User-Agent', 'static-mirror' ), array( $this, 'field_input' ), 'static-mirror-settings', 'sm_section_crawling', array( 'key' => 'user_agent' ) );
		add_settings_field( 'crawler_cookies', __( 'Crawler cookies', 'static-mirror' ), array( $this, 'field_textarea' ), 'static-mirror-settings', 'sm_section_crawling', array( 'key' => 'crawler_cookies', 'desc' => __( 'key=value per line', 'static-mirror' ) ) );
		add_settings_field( 'robots_on', __( 'Respect robots.txt', 'static-mirror' ), array( $this, 'field_checkbox' ), 'static-mirror-settings', 'sm_section_crawling', array( 'key' => 'robots_on' ) );
		add_settings_field( 'no_check_certificate', __( 'Skip TLS certificate verification', 'static-mirror' ), array( $this, 'field_checkbox' ), 'static-mirror-settings', 'sm_section_crawling', array( 'key' => 'no_check_certificate' ) );

		add_settings_field( 'reject_patterns', __( 'URL Exclusion Patterns', 'static-mirror' ), array( $this, 'field_textarea' ), 'static-mirror-settings', 'sm_section_exclusions', array( 'key' => 'reject_patterns', 'desc' => __( 'Regex (with delimiters) or substring, one per line.', 'static-mirror' ) ) );

		add_settings_field( 'resource_domains', __( 'Allowed resource domains', 'static-mirror' ), array( $this, 'field_textarea' ), 'static-mirror-settings', 'sm_section_resources', array( 'key' => 'resource_domains', 'desc' => __( 'One host per line.', 'static-mirror' ) ) );
	}

	public function sanitize_settings( $input ) {
		$output = $this->get_settings();

		$output['starting_urls'] = isset( $input['starting_urls'] ) ? array_values( array_filter( array_map( 'esc_url_raw', preg_split( '/\r\n|\r|\n/', (string) $input['starting_urls'] ) ) ) ) : array();
		$output['user_agent'] = isset( $input['user_agent'] ) ? sanitize_text_field( (string) $input['user_agent'] ) : '';
		$output['crawler_cookies'] = isset( $input['crawler_cookies'] ) ? trim( (string) $input['crawler_cookies'] ) : '';
		$output['robots_on'] = ! empty( $input['robots_on'] ) ? 1 : 0;
		$output['no_check_certificate'] = ! empty( $input['no_check_certificate'] ) ? 1 : 0;
		$output['reject_patterns'] = isset( $input['reject_patterns'] ) ? trim( (string) $input['reject_patterns'] ) : '';
		$output['resource_domains'] = isset( $input['resource_domains'] ) ? trim( (string) $input['resource_domains'] ) : '';

		return $output;
	}

	private function get_settings() {
		$defaults = array(
			'starting_urls' => array( home_url() ),
			'user_agent' => 'WordPress/Static-Mirror; ' . get_bloginfo( 'url' ),
			'crawler_cookies' => (string) get_option( 'static_mirror_crawler_cookies', '' ),
			'robots_on' => (int) get_option( 'static_mirror_robots_on', 0 ),
			'no_check_certificate' => (int) get_option( 'static_mirror_no_check_certificate', 0 ),
			'reject_patterns' => (string) get_option( 'static_mirror_reject_patterns', '' ),
			'resource_domains' => (string) get_option( 'static_mirror_resource_domains', '' ),
		);
		$settings = get_option( 'static_mirror_settings', array() );
		return wp_parse_args( $settings, $defaults );
	}

	public function field_textarea( $args ) {
		$settings = $this->get_settings();
		$key = $args['key'];
		$value = $settings[ $key ];
		if ( is_array( $value ) ) {
			$value = implode( "\n", $value );
		}
		echo '<textarea name="static_mirror_settings[' . esc_attr( $key ) . ']" class="large-text" rows="6">' . esc_textarea( (string) $value ) . '</textarea>';
		if ( ! empty( $args['desc'] ) ) {
			echo '<p class="description">' . esc_html( $args['desc'] ) . '</p>';
		}
	}

	public function field_input( $args ) {
		$settings = $this->get_settings();
		$key = $args['key'];
		$value = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
		echo '<input type="text" class="regular-text" name="static_mirror_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '" />';
	}

	public function field_checkbox( $args ) {
		$settings = $this->get_settings();
		$key = $args['key'];
		$checked = ! empty( $settings[ $key ] ) ? 'checked' : '';
		echo '<label><input type="checkbox" name="static_mirror_settings[' . esc_attr( $key ) . ']" value="1" ' . $checked . ' /> ' . esc_html__( 'Enabled', 'static-mirror' ) . '</label>';
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'static_mirror_manage_mirrors' ) ) {
			wp_die( __( 'You do not have permission to access this page.', 'static-mirror' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Static Mirror Settings', 'static-mirror' ); ?></h1>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'static_mirror' ); ?>
				<?php do_settings_sections( 'static-mirror-settings' ); ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function enqueue_admin_assets( $hook ) {
		if ( $hook !== 'tools_page_static-mirror-settings' ) {
			return;
		}
		// Placeholder for future styles/scripts; keep minimal & scoped.
	}
}