<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap">
    <h1><?php esc_html_e( 'Auto Internal Links Settings', 'auto-internal-links' ); ?></h1>
    <p><?php esc_html_e( 'Configure options for automatically adding internal links.', 'auto-internal-links' ); ?></p>
    <form method="post" action="options.php">
        <?php
        settings_fields( 'auli_ail_settings_group' );
        do_settings_sections( 'auto-internal-links-settings' );
        submit_button();
        ?>
    </form>
</div>