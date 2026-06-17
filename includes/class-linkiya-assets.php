<?php
/**
 * Linkiya Assets — script and style enqueuing.
 *
 * @package Linkiya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Linkiya_Assets
 *
 * Handles loading of scripts and styles for the editor and admin screens.
 */
class Linkiya_Assets {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
	}

	/**
	 * Enqueue Gutenberg editor assets.
	 *
	 * Loads the Linkiya sidebar JavaScript and CSS inside the block editor.
	 *
	 * @return void
	 */
	public static function enqueue_editor(): void {

		$asset_file = LINKIYA_PLUGIN_DIR . 'build/sidebar.asset.php';

		// Bail if build files are missing.
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'linkiya-sidebar',
			LINKIYA_PLUGIN_URL . 'build/sidebar.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Enable JavaScript translations.
		wp_set_script_translations(
			'linkiya-sidebar',
			'linkiya',
			LINKIYA_PLUGIN_DIR . 'languages'
		);

		/**
		 * Sidebar configuration.
		 *
		 * Pro add-ons can modify this data via the filter.
		 *
		 * @param array $sidebar_data Sidebar data passed to JavaScript.
		 */
		$sidebar_data = apply_filters(
			'linkiya_sidebar_data',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'linkiya/v1' ) ),
				'wpRestUrl' => esc_url_raw( rest_url( 'wp/v2' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'postId'    => (int) ( get_the_ID() ? get_the_ID() : get_queried_object_id() ),
				'adminUrl'  => esc_url_raw( admin_url() ),
			)
		);

		wp_localize_script(
			'linkiya-sidebar',
			'linkiyaData',
			$sidebar_data
		);

		$style_file = LINKIYA_PLUGIN_DIR . 'build/sidebar.css';

		if ( file_exists( $style_file ) ) {

			wp_enqueue_style(
				'linkiya-sidebar',
				LINKIYA_PLUGIN_URL . 'build/sidebar.css',
				array(),
				$asset['version']
			);

			// Automatically load sidebar-rtl.css on RTL sites.
			wp_style_add_data(
				'linkiya-sidebar',
				'rtl',
				'replace'
			);
		}
	}

	/**
	 * Enqueue admin assets.
	 *
	 * Loads admin CSS only on Linkiya admin pages.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_admin( string $hook ): void {

		if ( false === strpos( $hook, 'linkiya' ) ) {
			return;
		}

		wp_enqueue_style(
			'linkiya-admin',
			LINKIYA_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			LINKIYA_VERSION
		);
	}
}
