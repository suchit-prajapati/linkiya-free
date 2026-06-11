<?php
/**
 * Linkiya — Uninstall
 * Runs when user clicks Delete in WordPress plugins list.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Delete free plugin options
delete_option( 'linkiya_settings' );
delete_option( 'linkiya_db_version' );
