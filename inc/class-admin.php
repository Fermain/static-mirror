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
		// Settings page is now under top-level menu; no Tools submenu for settings
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		// Reschedule cron when settings change
		add_action( 'update_option_static_mirror_settings', array( __CLASS__, 'handle_settings_update' ), 10, 3 );
	}

	/**
	 * Add the Tools page page
	 */
	public function add_tools_page() {
		$hook = add_submenu_page( 'tools.php', 'Static Mirrors', 'Static Mirror', 'static_mirror_manage_mirrors', 'static-mirror-tools-page', array( $this, 'output_tools_page' ) );
		add_action( 'load-' . $hook, array( $this, 'setup_tools_screen' ) );
	}

	public function setup_tools_screen() {
		add_screen_option( 'per_page', array(
			'label' => __( 'Mirrors per page', 'static-mirror' ),
			'default' => 20,
			'option' => 'edit_static-mirror_per_page',
		) );

		$screen = get_current_screen();
		$screen->add_help_tab( array(
			'id' => 'sm_overview',
			'title' => __( 'Overview', 'static-mirror' ),
			'content' => '<p>' . esc_html__( 'Use "Create Mirror Now" to generate a new snapshot. Configure crawling and exclusions under Tools → Static Mirror Settings. Filter the list by date range.', 'static-mirror' ) . '</p>',
		) );
	}

	/**
	 * Add top-level admin menu with submenus
	 */
	public function add_admin_menu() {
		$mirrors_hook = add_menu_page(
			__( 'Static Mirror', 'static-mirror' ),
			__( 'Static Mirror', 'static-mirror' ),
			'static_mirror_manage_mirrors',
			'static-mirror',
			array( $this, 'render_mirrors_page' ),
			'dashicons-migrate'
		);

		add_action( 'load-' . $mirrors_hook, array( $this, 'setup_tools_screen' ) );

		$settings_hook = add_submenu_page(
			'static-mirror',
			__( 'Settings', 'static-mirror' ),
			__( 'Settings', 'static-mirror' ),
			'static_mirror_manage_mirrors',
			'static-mirror-settings',
			array( $this, 'render_settings_page' )
		);

		// Ensure assets load for settings when needed
		add_action( 'load-' . $settings_hook, function() {} );
	}

	public function render_mirrors_page() {
		include dirname( __FILE__ ) . '/../templates/admin-tools-page.php';
	}

	// Persist Screen Options per-page setting
	public static function set_screen_option( $status, $option, $value ) {
		if ( 'edit_static-mirror_per_page' === $option ) {
			return (int) $value;
		}
		return $status;
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
		add_settings_section( 'sm_section_performance', __( 'Performance', 'static-mirror' ), '__return_false', 'static-mirror-settings' );
		add_settings_section( 'sm_section_behavior', __( 'Behavior', 'static-mirror' ), '__return_false', 'static-mirror-settings' );
		add_settings_section( 'sm_section_schedule', __( 'Schedule', 'static-mirror' ), '__return_false', 'static-mirror-settings' );

		add_settings_field( 'starting_urls', __( 'Starting URLs', 'static-mirror' ), array( $this, 'field_textarea' ), 'static-mirror-settings', 'sm_section_general', array( 'key' => 'starting_urls', 'desc' => __( 'One per line.', 'static-mirror' ) ) );

		add_settings_field( 'user_agent', __( 'User-Agent', 'static-mirror' ), array( $this, 'field_input' ), 'static-mirror-settings', 'sm_section_crawling', array( 'key' => 'user_agent' ) );
		add_settings_field( 'crawler_cookies', __( 'Crawler cookies', 'static-mirror' ), array( $this, 'field_textarea' ), 'static-mirror-settings', 'sm_section_crawling', array( 'key' => 'crawler_cookies', 'desc' => __( 'key=value per line', 'static-mirror' ) ) );
		add_settings_field( 'robots_on', __( 'Respect robots.txt', 'static-mirror' ), array( $this, 'field_checkbox' ), 'static-mirror-settings', 'sm_section_crawling', array( 'key' => 'robots_on' ) );
		add_settings_field( 'no_check_certificate', __( 'Skip TLS certificate verification', 'static-mirror' ), array( $this, 'field_checkbox' ), 'static-mirror-settings', 'sm_section_crawling', array( 'key' => 'no_check_certificate' ) );

		add_settings_field( 'reject_patterns', __( 'URL Exclusion Patterns', 'static-mirror' ), array( $this, 'field_textarea' ), 'static-mirror-settings', 'sm_section_exclusions', array( 'key' => 'reject_patterns', 'desc' => __( 'Regex (with delimiters) or substring, one per line.', 'static-mirror' ) ) );

		add_settings_field( 'resource_domains', __( 'Allowed resource domains', 'static-mirror' ), array( $this, 'field_textarea' ), 'static-mirror-settings', 'sm_section_resources', array( 'key' => 'resource_domains', 'desc' => __( 'One host per line.', 'static-mirror' ) ) );

		add_settings_field( 'wait_seconds', __( 'Wait between requests (seconds)', 'static-mirror' ), array( $this, 'field_input_number' ), 'static-mirror-settings', 'sm_section_performance', array( 'key' => 'wait_seconds', 'min' => 0 ) );
		add_settings_field( 'random_wait', __( 'Randomize wait', 'static-mirror' ), array( $this, 'field_checkbox' ), 'static-mirror-settings', 'sm_section_performance', array( 'key' => 'random_wait' ) );
		add_settings_field( 'level', __( 'Crawl depth (recursive only)', 'static-mirror' ), array( $this, 'field_input_number' ), 'static-mirror-settings', 'sm_section_performance', array( 'key' => 'level', 'min' => 0 ) );

		add_settings_field( 'recursive_scheduled', __( 'Recursive on scheduled mirrors', 'static-mirror' ), array( $this, 'field_checkbox' ), 'static-mirror-settings', 'sm_section_behavior', array( 'key' => 'recursive_scheduled' ) );
		add_settings_field( 'recursive_immediate', __( 'Recursive on immediate mirrors', 'static-mirror' ), array( $this, 'field_checkbox' ), 'static-mirror-settings', 'sm_section_behavior', array( 'key' => 'recursive_immediate' ) );

		add_settings_field( 'schedule_time', __( 'Schedule time (HH:MM)', 'static-mirror' ), array( $this, 'field_input_time' ), 'static-mirror-settings', 'sm_section_schedule', array( 'key' => 'schedule_time' ) );
		add_settings_field( 'schedule_frequency', __( 'Frequency', 'static-mirror' ), array( $this, 'field_select' ), 'static-mirror-settings', 'sm_section_schedule', array( 'key' => 'schedule_frequency', 'options' => [ 'daily' => __( 'Daily', 'static-mirror' ), 'weekly' => __( 'Weekly', 'static-mirror' ) ] ) );
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
		$output['wait_seconds'] = isset( $input['wait_seconds'] ) ? max( 0, intval( $input['wait_seconds'] ) ) : 0;
		$output['random_wait'] = ! empty( $input['random_wait'] ) ? 1 : 0;
		$output['level'] = isset( $input['level'] ) ? max( 0, intval( $input['level'] ) ) : 0;
		$output['recursive_scheduled'] = ! empty( $input['recursive_scheduled'] ) ? 1 : 0;
		$output['recursive_immediate'] = ! empty( $input['recursive_immediate'] ) ? 1 : 0;
		// schedule
		$time = isset( $input['schedule_time'] ) ? preg_replace( '/[^0-9:]/', '', (string) $input['schedule_time'] ) : '';
		if ( preg_match( '/^\d{2}:\d{2}$/', $time ) ) { $output['schedule_time'] = $time; }
		$freq = isset( $input['schedule_frequency'] ) ? (string) $input['schedule_frequency'] : 'daily';
		$output['schedule_frequency'] = in_array( $freq, [ 'daily', 'weekly' ], true ) ? $freq : 'daily';

		return $output;
	}

	private function get_settings() {
		$defaults = array(
			'starting_urls' => array( home_url() ),
			'user_agent' => 'WordPress/Static-Mirror; ' . home_url(),
			'crawler_cookies' => (string) get_option( 'static_mirror_crawler_cookies', '' ),
			'robots_on' => (int) get_option( 'static_mirror_robots_on', 0 ),
			'no_check_certificate' => (int) get_option( 'static_mirror_no_check_certificate', 0 ),
			'reject_patterns' => (string) get_option( 'static_mirror_reject_patterns', '' ),
			'resource_domains' => (string) get_option( 'static_mirror_resource_domains', '' ),
			'wait_seconds' => 0,
			'random_wait' => 0,
			'level' => 0,
			'recursive_scheduled' => 1,
			'recursive_immediate' => 0,
			'schedule_time' => '23:59',
			'schedule_frequency' => 'daily',
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
		echo '<textarea id="sm-field-' . esc_attr( $key ) . '" name="static_mirror_settings[' . esc_attr( $key ) . ']" class="large-text" rows="6">' . esc_textarea( (string) $value ) . '</textarea>';
		if ( ! empty( $args['desc'] ) ) {
			echo '<p class="description">' . esc_html( $args['desc'] ) . '</p>';
		}

		// Preset buttons for exclusion patterns (handled by enqueued JS)
		if ( $key === 'reject_patterns' ) {
			$preset_patterns = [
				[ 'label' => __( 'Pagination', 'static-mirror' ), 'pattern' => '#/page/\\d+/#' ],
				[ 'label' => __( 'Year archives', 'static-mirror' ), 'pattern' => '#/\\d{4}/(?:$|page/\\d+/)#' ],
				[ 'label' => __( 'Search (query)', 'static-mirror' ), 'pattern' => '#[?&]s=#' ],
				[ 'label' => __( 'Admin paths', 'static-mirror' ), 'pattern' => '/wp-admin/' ],
				[ 'label' => __( 'WP JSON API', 'static-mirror' ), 'pattern' => '#/wp-json(?:/.*)?$#' ],
				[ 'label' => __( 'Uploads', 'static-mirror' ), 'pattern' => '#/wp-content/uploads/#' ],
			];
			echo '<p>';
			foreach ( $preset_patterns as $pp ) {
				echo '<button class="button sm-preset-button" type="button" data-target="sm-field-reject_patterns" data-pattern="' . esc_attr( $pp['pattern'] ) . '">' . esc_html( $pp['label'] ) . '</button> ';
			}
			echo '</p>';
		}
	}

	public function field_input( $args ) {
		$settings = $this->get_settings();
		$key = $args['key'];
		$value = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
		echo '<input type="text" class="regular-text" name="static_mirror_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '" placeholder="' . esc_attr( 'WordPress/Static-Mirror; ' . home_url() ) . '" />';
	}

	public function field_checkbox( $args ) {
		$settings = $this->get_settings();
		$key = $args['key'];
		$checked = ! empty( $settings[ $key ] ) ? 'checked' : '';
		echo '<label><input type="checkbox" name="static_mirror_settings[' . esc_attr( $key ) . ']" value="1" ' . $checked . ' /> ' . esc_html__( 'Enabled', 'static-mirror' ) . '</label>';
	}

	public function field_input_number( $args ) {
		$settings = $this->get_settings();
		$key = $args['key'];
		$min = isset( $args['min'] ) ? intval( $args['min'] ) : 0;
		$value = isset( $settings[ $key ] ) ? intval( $settings[ $key ] ) : 0;
		echo '<input type="number" min="' . esc_attr( (string) $min ) . '" class="small-text" name="static_mirror_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '" />';
	}

	public function field_input_time( $args ) {
		$settings = $this->get_settings();
		$key = $args['key'];
		$value = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
		echo '<input type="time" class="regular-text" name="static_mirror_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" />';
	}

	public function field_select( $args ) {
		$settings = $this->get_settings();
		$key = $args['key'];
		$options = isset( $args['options'] ) ? (array) $args['options'] : [];
		$current = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
		echo '<select name="static_mirror_settings[' . esc_attr( $key ) . ']">';
		foreach ( $options as $val => $label ) {
			$sel = selected( $current, (string) $val, false );
			echo '<option value="' . esc_attr( (string) $val ) . '" ' . $sel . '>' . esc_html( (string) $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Handle settings update: reschedule mirror cron according to schedule settings
	 */
	public static function handle_settings_update( $old_value, $value, $option ) : void {
		// Clear existing mirror schedule
		wp_clear_scheduled_hook( 'static_mirror_create_mirror' );

		$settings = get_option( 'static_mirror_settings', [] );
		$time = isset( $settings['schedule_time'] ) ? (string) $settings['schedule_time'] : '';
		$freq = isset( $settings['schedule_frequency'] ) ? (string) $settings['schedule_frequency'] : 'daily';

		// Default timestamp: today at 23:59 or next day if past
		$timestamp = apply_filters( 'static_mirror_daily_schedule_time', strtotime( 'today 11:59pm' ) );
		if ( preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			list( $h, $m ) = array_map( 'intval', explode( ':', $time ) );
			$today = current_time( 'timestamp' );
			$target = mktime( $h, $m, 0, (int) date( 'n', $today ), (int) date( 'j', $today ), (int) date( 'Y', $today ) );
			if ( $target <= $today ) { $target = strtotime( '+1 day', $target ); }
			$timestamp = $target;
		}

		// Ensure weekly recurrence exists
		add_filter( 'cron_schedules', function( $s ) {
			if ( ! isset( $s['weekly'] ) ) {
				$s['weekly'] = [ 'interval' => 7 * DAY_IN_SECONDS, 'display' => __( 'Once Weekly' ) ];
			}
			return $s;
		} );

		$recurrence = ( $freq === 'weekly' ) ? 'weekly' : 'daily';
		wp_schedule_event( $timestamp, $recurrence, 'static_mirror_create_mirror' );
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
		// Enqueue small inline script to handle preset buttons safely.
		$handle = 'static-mirror-settings';
		wp_register_script( $handle, false, [], false, true );
		wp_enqueue_script( $handle );
		$inline = "(function(){function addLine(id,pattern){var ta=document.getElementById(id);if(!ta)return;var val=ta.value||'';var lines=val?val.split(/\\r?\\n/):[];if(lines.indexOf(pattern)===-1){ta.value=(val?val+'\\n':'')+pattern;}}document.addEventListener('click',function(e){var t=e.target;if(t && t.classList && t.classList.contains('sm-preset-button')){e.preventDefault();addLine(t.getAttribute('data-target'),t.getAttribute('data-pattern'));}});})();";
		wp_add_inline_script( $handle, $inline, 'after' );
	}

	/**
	 * Add Settings link on Plugins page row
	 */
	public static function plugin_action_links( $links ) {
		$settings_url = admin_url( 'tools.php?page=static-mirror-settings' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'static-mirror' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
}