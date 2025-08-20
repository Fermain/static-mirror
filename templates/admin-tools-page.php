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

	<?php $list_table->display(); ?>
</div>
