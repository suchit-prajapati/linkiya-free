<?php
/**
 * Linkiya settings page template.
 *
 * @package  Linkiya
 * @category Admin
 * @author   Linkiya
 * @license  GPL-2.0-or-later
 * @link     https://linkiya.com
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1>⚙️ <?php esc_html_e( 'Linkiya — Settings', 'linkiya' ); ?></h1>

    <?php // phpcs:disable WordPress.Security.NonceVerification.Recommended -- These are read-only redirect flags set by wp_safe_redirect() after nonce-verified form handlers; no data is processed here. ?>
	<?php if ( isset( $_GET['saved'] ) && '1' === sanitize_key( $_GET['saved'] ) ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'linkiya' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['imported'] ) && '1' === sanitize_key( $_GET['imported'] ) ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings imported successfully.', 'linkiya' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['import_error'] ) && '1' === sanitize_key( $_GET['import_error'] ) ) : ?>
	<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Import failed. Please upload a valid JSON file.', 'linkiya' ); ?></p></div>
	<?php endif; ?>
    <?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'linkiya_settings_nonce' ); ?>
		<input type="hidden" name="action" value="linkiya_save_settings">

		<table class="form-table" role="presentation">

			<!-- F9: Min word length -->
			<tr>
				<th scope="row">
					<label for="linkiya_min_word_length"><?php esc_html_e( 'Minimum Word Length', 'linkiya' ); ?></label>
				</th>
				<td>
					<input type="number" id="linkiya_min_word_length"
						name="linkiya_settings[min_word_length]"
						min="2" max="10"
						value="<?php echo esc_attr( $settings['min_word_length'] ); ?>">
					<p class="description"><?php esc_html_e( 'Keywords shorter than this are ignored. Default: 4.', 'linkiya' ); ?></p>
				</td>
			</tr>

			<!-- F5: Max links per post -->
			<tr>
				<th scope="row">
					<label for="linkiya_max_links"><?php esc_html_e( 'Max Links Per Post', 'linkiya' ); ?></label>
				</th>
				<td>
					<input type="number" id="linkiya_max_links"
						name="linkiya_settings[max_links_per_post]"
						min="0" max="50"
						value="<?php echo esc_attr( $settings['max_links_per_post'] ); ?>">
					<p class="description"><?php esc_html_e( 'Maximum suggestions per scan. 0 = unlimited.', 'linkiya' ); ?></p>
				</td>
			</tr>

			<!-- F8: Link target -->
			<tr>
				<th scope="row">
					<label for="linkiya_link_target"><?php esc_html_e( 'Link Target', 'linkiya' ); ?></label>
				</th>
				<td>
					<select id="linkiya_link_target" name="linkiya_settings[link_target]">
						<option value="_self"  <?php selected( $settings['link_target'], '_self' ); ?>><?php esc_html_e( 'Same tab (_self)', 'linkiya' ); ?></option>
						<option value="_blank" <?php selected( $settings['link_target'], '_blank' ); ?>><?php esc_html_e( 'New tab (_blank)', 'linkiya' ); ?></option>
					</select>
				</td>
			</tr>

			<!-- F7: Link rel -->
			<tr>
				<th scope="row">
					<label for="linkiya_link_rel"><?php esc_html_e( 'Link Rel', 'linkiya' ); ?></label>
				</th>
				<td>
					<input type="text" id="linkiya_link_rel"
						name="linkiya_settings[link_rel]"
						class="regular-text"
						value="<?php echo esc_attr( $settings['link_rel'] ); ?>"
						placeholder="<?php esc_attr_e( 'e.g. noopener noreferrer', 'linkiya' ); ?>">
					<p class="description"><?php esc_html_e( 'Leave blank for standard internal links.', 'linkiya' ); ?></p>
				</td>
			</tr>

			<!-- F6: Post exclusion -->
			<tr>
				<th scope="row">
					<label for="linkiya_excluded_post_ids"><?php esc_html_e( 'Exclude Posts', 'linkiya' ); ?></label>
				</th>
				<td>
					<textarea id="linkiya_excluded_post_ids"
						name="linkiya_settings[excluded_post_ids]"
						rows="5" class="large-text"
						placeholder="<?php esc_attr_e( "e.g.\n42\n87\n124", 'linkiya' ); ?>"><?php echo esc_textarea( $settings['excluded_post_ids'] ?? '' ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Post IDs added here will never be linked to. One ID per line.', 'linkiya' ); ?></p>
				</td>
			</tr>

			<!-- Suggest pages on posts -->
			<tr>
				<th scope="row"><?php esc_html_e( 'Suggest Pages on Posts', 'linkiya' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="linkiya_settings[suggest_pages_on_posts]" value="1"
							<?php checked( $settings['suggest_pages_on_posts'] ?? '1', '1' ); ?>>
						<?php esc_html_e( 'Include Pages as link targets when editing a Post', 'linkiya' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Disable this to avoid linking to About, Editorial, or other static pages from your posts.', 'linkiya' ); ?></p>
				</td>
			</tr>

			<!-- Suggest posts on pages -->
			<tr>
				<th scope="row"><?php esc_html_e( 'Suggest Posts on Pages', 'linkiya' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="linkiya_settings[suggest_posts_on_pages]" value="1"
							<?php checked( $settings['suggest_posts_on_pages'] ?? '1', '1' ); ?>>
						<?php esc_html_e( 'Include Posts as link targets when editing a Page', 'linkiya' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Disable this to prevent blog posts from being suggested inside static pages.', 'linkiya' ); ?></p>
				</td>
			</tr>

			<!-- Stop words -->
			<tr>
				<th scope="row">
					<label for="linkiya_stop_words"><?php esc_html_e( 'Stop Words', 'linkiya' ); ?></label>
				</th>
				<td>
					<textarea id="linkiya_stop_words"
						name="linkiya_settings[stop_words]"
						rows="8" class="large-text"
						placeholder="<?php esc_attr_e( "e.g.\nlife\nself\nways\ntips", 'linkiya' ); ?>"><?php echo esc_textarea( $settings['stop_words'] ?? '' ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Words added here will never be used as link anchor text. One word per line. Case-insensitive.', 'linkiya' ); ?></p>
				</td>
			</tr>

		</table>

		<?php
		// Allow Pro plugin to add extra settings fields.
		do_action( 'linkiya_settings_fields', $settings );
		?>

		<?php submit_button( __( 'Save Settings', 'linkiya' ) ); ?>
	</form>

	<!-- F10: Import / Export -->
	<hr>
	<h2><?php esc_html_e( 'Export Settings', 'linkiya' ); ?></h2>
	<p><?php esc_html_e( 'Download your current settings as a JSON file.', 'linkiya' ); ?></p>
	<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=linkiya_export_settings' ), 'linkiya_export_settings' ) ); ?>"
		class="button"><?php esc_html_e( 'Download Settings JSON', 'linkiya' ); ?></a>

	<h2 class="linkiya-section-heading"><?php esc_html_e( 'Import Settings', 'linkiya' ); ?></h2>
	<p><?php esc_html_e( 'Upload a previously exported settings JSON file.', 'linkiya' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
		<?php wp_nonce_field( 'linkiya_import_settings' ); ?>
		<input type="hidden" name="action" value="linkiya_import_settings">
		<input type="file" name="linkiya_import_file" accept=".json" required class="linkiya-import-file">
		<?php submit_button( __( 'Import Settings', 'linkiya' ), 'secondary', 'submit', false ); ?>
	</form>

	<?php do_action( 'linkiya_settings_after_import' ); ?>
</div>
