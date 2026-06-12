<?php
/**
 * Linkiya — Uninstall
 * Runs when user clicks Delete in WordPress plugins list.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Delete free plugin options
delete_option( 'linkiya_settings' );
delete_option( 'linkiya_db_version' );

// Delete rate-limit transients for all users.
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_linkiya_rl_%'
        OR option_name LIKE '_transient_timeout_linkiya_rl_%'"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
