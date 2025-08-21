<?php

namespace Static_Mirror;

global $current_screen;

$current_screen->post_type = 'static-mirror';

$list_table = new List_Table( array(
	'screen' => $current_screen
) );

$list_table->enqueue_scripts();
$list_table->prepare_items();

?>
<div class="wrap">
	<h2 class="page-title">
		Static Mirrors
		<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'static-mirror-create-mirror' ), 'static-mirror-create' ) ); ?>" class="add-new-h2">Create Mirror Now</a>
	</h2>

	<?php
	// Build a preview of wget command using current settings
	$sm_settings = get_option( 'static_mirror_settings', [] );
	$ua = ! empty( $sm_settings['user_agent'] ) ? $sm_settings['user_agent'] : 'WordPress/Static-Mirror; ' . home_url();
	$cookies = [];
	if ( ! empty( $sm_settings['crawler_cookies'] ) ) {
		foreach ( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $sm_settings['crawler_cookies'] ) ) ) as $line ) {
			if ( strpos( $line, '=' ) !== false ) { $cookies[] = $line; }
		}
	}
	$cookie_header = $cookies ? sprintf( "--header %s", esc_html( escapeshellarg( 'Cookie: ' . implode( ';', $cookies ) ) ) ) : '';
	$robots = ! empty( $sm_settings['robots_on'] ) ? '--execute robots=on' : '--execute robots=off';
	$no_check = ! empty( $sm_settings['no_check_certificate'] ) ? '--no-check-certificate' : '';
	$wait = ! empty( $sm_settings['wait_seconds'] ) ? sprintf( '--wait=%d', (int) $sm_settings['wait_seconds'] ) : '';
	$rand = ! empty( $sm_settings['random_wait'] ) ? '--random-wait' : '';
	$level = ! empty( $sm_settings['level'] ) ? sprintf( '--level=%d', (int) $sm_settings['level'] ) : '';
	$reject_pattern = \Static_Mirror\Mirrorer::build_reject_regex_pattern();
	$reject = $reject_pattern !== '' ? sprintf( '--reject-regex %s', esc_html( escapeshellarg( $reject_pattern ) ) ) : '';
	$ua_arg = sprintf( '--user-agent=%s', esc_html( escapeshellarg( $ua ) ) );
	$preview_parts = array_filter( [ 'wget', $ua_arg, '--no-clobber', '--page-requisites', '--convert-links', '--backup-converted', $robots, '--restrict-file-names=windows', $reject, '--html-extension', '--content-on-error', '--trust-server-names', $cookie_header, $wait, $rand, $level ] );
	?>
	<div class="notice notice-info" style="padding:10px 12px;">
		<strong>Preview:</strong>
		<code style="display:block; overflow:auto; white-space:pre-wrap; word-break:break-all; margin-top:6px;">
			<?php echo implode( ' ', $preview_parts ) . ' ' . esc_html( escapeshellarg( home_url( '/' ) ) ); ?>
		</code>
	</div>

	<?php $list_table->display(); ?>
</div>
