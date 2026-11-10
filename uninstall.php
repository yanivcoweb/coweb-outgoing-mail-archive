<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
( static function () {
global $wpdb;
$outgoing_mail_archive_sites = is_multisite() ? get_sites(
	array(
		'fields' => 'ids',
		'number' => 0,
	)
) : array( get_current_blog_id() );
foreach ( $outgoing_mail_archive_sites as $outgoing_mail_archive_site_id ) {
	if ( is_multisite() ) {
		switch_to_blog( $outgoing_mail_archive_site_id ); }
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uninstall permanently removes the plugin-owned table.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'site_mailbox' ) );
	wp_clear_scheduled_hook( 'site_mailbox_cleanup' );
	delete_option( 'site_mailbox_limit' );
	delete_option( 'site_mailbox_retention_days' );
	delete_option( 'site_mailbox_block_test_orders' );
	delete_option( 'site_mailbox_schema' );
	if ( is_multisite() ) {
		restore_current_blog(); }
}

} )();

