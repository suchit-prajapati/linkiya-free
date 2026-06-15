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
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall runs once; no caching needed. No WordPress API supports wildcard transient deletion.
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE %s
            OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_linkiya_rl_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_linkiya_rl_' ) . '%'
    )
);
