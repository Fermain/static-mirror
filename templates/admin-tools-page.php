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

	<form method="post" action="<?php echo esc_url( add_query_arg( 'page', $_GET['page'], 'tools.php' ) ) ?>">
		<input type="hidden" name="action" value="update-static-mirror" />
		<?php wp_nonce_field( 'static-mirror.update' ) ?>
		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row">
						<label for="static-mirror-urls">Starting URLs</label></th>
					<td>
						<textarea name="static-mirror-urls" id="static-mirror-urls" style="width: 300px; min-height: 100px" class="regular-text"><?php echo esc_textarea( implode("\n", Plugin::get_instance()->get_base_urls() ) ) ?></textarea>
						<p class="description">All the different "sites" you want to create mirrors of.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="static-mirror-reject-patterns">URL Exclusion Patterns</label></th>
					<td>
						<textarea name="static-mirror-reject-patterns" id="static-mirror-reject-patterns" style="width: 300px; min-height: 100px" class="regular-text"><?php echo esc_textarea( get_option( 'static_mirror_reject_patterns', "" ) ); ?></textarea>
						<p class="description">One per line. Regex (with delimiters) or substring. Merged into wget --reject-regex.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="static-mirror-resource-domains">Allowed resource domains</label></th>
					<td>
						<textarea name="static-mirror-resource-domains" id="static-mirror-resource-domains" style="width: 300px; min-height: 80px" class="regular-text"><?php echo esc_textarea( get_option( 'static_mirror_resource_domains', "" ) ); ?></textarea>
						<p class="description">One host per line. Used with --span-hosts and --domains. Include primary host for safety.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="static-mirror-crawler-cookies">Crawler cookies (key=value per line)</label></th>
					<td>
						<textarea name="static-mirror-crawler-cookies" id="static-mirror-crawler-cookies" style="width: 300px; min-height: 80px" class="regular-text"><?php echo esc_textarea( get_option( 'static_mirror_crawler_cookies', "" ) ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="static-mirror-user-agent">User-Agent</label></th>
					<td>
						<input type="text" name="static-mirror-user-agent" id="static-mirror-user-agent" class="regular-text" value="<?php echo esc_attr( get_option( 'static_mirror_user_agent', '' ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="static-mirror-no-check-certificate">Skip TLS certificate verification</label></th>
					<td>
						<label>
							<input type="checkbox" id="static-mirror-no-check-certificate" name="static-mirror-no-check-certificate" value="1" <?php \checked( (int) get_option( 'static_mirror_no_check_certificate', 0 ), 1 ); ?> />
							Add --no-check-certificate to wget (useful for internal CAs)
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="static-mirror-robots-on">Respect robots.txt</label></th>
					<td>
						<label>
							<input type="checkbox" id="static-mirror-robots-on" name="static-mirror-robots-on" value="1" <?php \checked( (int) get_option( 'static_mirror_robots_on', 0 ), 1 ); ?> />
							Use wget robots=on (unchecked uses -erobots=off)
						</label>
					</td>
				</tr>
			</tbody>

		</table>

		<p class="submit">
			<input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes">
		</p>
	</form>

	<?php $list_table->display(); ?>
</div>
