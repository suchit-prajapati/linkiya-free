<?php
/**
 * Plugin Name:       Linkiya
 * Plugin URI:        https://www.mypluginstore.com/linkiya
 * Description:       Automatically find and apply internal links inside the Gutenberg editor. Link kiya? Done.
 * Version:           1.0.0
 * Author:            My Plugin Store Team
 * Author URI:        https://www.mypluginstore.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       linkiya-free
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Tested up to:      7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'LINKIYA_VERSION',     '1.0.0' );
define( 'LINKIYA_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'LINKIYA_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'LINKIYA_PLUGIN_FILE', __FILE__ );

// Free core classes
require_once LINKIYA_PLUGIN_DIR . 'includes/class-linkiya-keyword-extractor.php';
require_once LINKIYA_PLUGIN_DIR . 'includes/class-linkiya-matcher.php';
require_once LINKIYA_PLUGIN_DIR . 'includes/class-linkiya-rest-api.php';
require_once LINKIYA_PLUGIN_DIR . 'includes/class-linkiya-assets.php';
require_once LINKIYA_PLUGIN_DIR . 'includes/class-linkiya-settings.php';

add_action( 'plugins_loaded', function () {
    Linkiya_Keyword_Extractor::init(); // cache invalidation hooks
    Linkiya_REST_API::init();
    Linkiya_Assets::init();
    Linkiya_Settings::init();

    // Allow Pro plugin to hook in
    do_action( 'linkiya_loaded' );
} );

register_activation_hook( LINKIYA_PLUGIN_FILE, function () {
    do_action( 'linkiya_activate' );
} );

register_deactivation_hook( LINKIYA_PLUGIN_FILE, function () {
    do_action( 'linkiya_deactivate' );
} );
