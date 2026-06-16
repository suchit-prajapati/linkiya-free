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
	<h1>⚙️ <?php esc_html_e( 'Linkiya — Settings', 'linkiya-free' ); ?></h1>

    <?php // phpcs:disable WordPress.Security.NonceVerification.Recommended -- These are read-only redirect flags set by wp_safe_redirect() after nonce-verified form handlers; no data is processed here. ?>
	<?php if ( isset( $_GET['saved'] ) && '1' === sanitize_key( $_GET['saved'] ) ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'linkiya-free' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['imported'] ) && '1' === sanitize_key( $_GET['imported'] ) ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings imported successfully.', 'linkiya-free' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['import_error'] ) && '1' === sanitize_key( $_GET['import_error'] ) ) : ?>
	<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Import failed. Please upload a valid JSON file.', 'linkiya-free' ); ?></p></div>
	<?php endif; ?>
    <?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'linkiya_settings_nonce' ); ?>
		<input type="hidden" name="action" value="linkiya_save_settings">

		<table class="form-table" role="presentation">

			<!-- F9: Min word length -->
			<tr>
				<th scope="row">
					<label for="linkiya_min_word_length"><?php esc_html_e( 'Minimum Word Length', 'linkiya-free' ); ?></label>
				</th>
				<td>
					<input type="number" id="linkiya_min_word_length"
						name="linkiya_settings[min_word_length]"
						min="2" max="10"
						value="<?php echo esc_attr( $settings['min_word_length'] ); ?>">
					<p class="description"><?php esc_html_e( 'Keywords shorter than this are ignored. Default: 4.', 'linkiya-free' ); ?></p>
				</td>
			</tr>

			<!-- F5: Max links per post -->
			<tr>
				<th scope="row">
					<label for="linkiya_max_links"><?php esc_html_e( 'Max Links Per Post', 'linkiya-free' ); ?></label>
				</th>
				<td>
					<input type="number" id="linkiya_max_links"
						name="linkiya_settings[max_links_per_post]"
						min="0" max="50"
						value="<?php echo esc_attr( $settings['max_links_per_post'] ); ?>">
					<p class="description"><?php esc_html_e( 'Maximum suggestions per scan. 0 = unlimited.', 'linkiya-free' ); ?></p>
				</td>
			</tr>

			<!-- F8: Link target -->
			<tr>
				<th scope="row">
					<label for="linkiya_link_target"><?php esc_html_e( 'Link Target', 'linkiya-free' ); ?></label>
				</th>
				<td>
					<select id="linkiya_link_target" name="linkiya_settings[link_target]">
						<option value="_self"  <?php selected( $settings['link_target'], '_self' ); ?>><?php esc_html_e( 'Same tab (_self)', 'linkiya-free' ); ?></option>
						<option value="_blank" <?php selected( $settings['link_target'], '_blank' ); ?>><?php esc_html_e( 'New tab (_blank)', 'linkiya-free' ); ?></option>
					</select>
				</td>
			</tr>

			<!-- F7: Link rel -->
			<tr>
				<th scope="row">
					<label for="linkiya_link_rel"><?php esc_html_e( 'Link Rel', 'linkiya-free' ); ?></label>
				</th>
				<td>
					<input type="text" id="linkiya_link_rel"
						name="linkiya_settings[link_rel]"
						class="regular-text"
						value="<?php echo esc_attr( $settings['link_rel'] ); ?>"
						placeholder="<?php esc_attr_e( 'e.g. noopener noreferrer', 'linkiya-free' ); ?>">
					<p class="description"><?php esc_html_e( 'Leave blank for standard internal links.', 'linkiya-free' ); ?></p>
				</td>
			</tr>

			<!-- F6: Post exclusion -->
			<tr>
				<th scope="row">
					<label for="linkiya_excluded_post_ids"><?php esc_html_e( 'Exclude Posts', 'linkiya-free' ); ?></label>
				</th>
				<td>
					<textarea id="linkiya_excluded_post_ids"
						name="linkiya_settings[excluded_post_ids]"
						rows="5" class="large-text"
						placeholder="<?php esc_attr_e( "e.g.\n42\n87\n124", 'linkiya-free' ); ?>"><?php echo esc_textarea( $settings['excluded_post_ids'] ?? '' ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Post IDs added here will never be linked to. One ID per line.', 'linkiya-free' ); ?></p>
				</td>
			</tr>

		</table>

		<?php
		// Allow Pro plugin to add extra settings fields.
		do_action( 'linkiya_settings_fields', $settings );
		?>

		<?php submit_button( __( 'Save Settings', 'linkiya-free' ) ); ?>
	</form>

	<!-- F10: Import / Export -->
	<hr>
	<h2><?php esc_html_e( 'Export Settings', 'linkiya-free' ); ?></h2>
	<p><?php esc_html_e( 'Download your current settings as a JSON file.', 'linkiya-free' ); ?></p>
	<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=linkiya_export_settings' ), 'linkiya_export_settings' ) ); ?>"
		class="button"><?php esc_html_e( 'Download Settings JSON', 'linkiya-free' ); ?></a>

	<h2 class="linkiya-section-heading"><?php esc_html_e( 'Import Settings', 'linkiya-free' ); ?></h2>
	<p><?php esc_html_e( 'Upload a previously exported settings JSON file.', 'linkiya-free' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
		<?php wp_nonce_field( 'linkiya_import_settings' ); ?>
		<input type="hidden" name="action" value="linkiya_import_settings">
		<input type="file" name="linkiya_import_file" accept=".json" required class="linkiya-import-file">
		<?php submit_button( __( 'Import Settings', 'linkiya-free' ), 'secondary', 'submit', false ); ?>
	</form>

	<!-- Pro status / upsell card -->
	<hr>
	<?php
	$linkiya_pro_is_active = class_exists( 'Linkiya_License' ) && Linkiya_License::is_active();
	if ( $linkiya_pro_is_active ) :
		?>
	<div class="linkiya-pro-card" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:28px 32px;">
		<h2 style="color:#15803d;margin-top:0">🎉 <?php esc_html_e( 'Linkiya Pro is Active!', 'linkiya-free' ); ?></h2>
		<ul style="margin-bottom:20px">
		<?php
		$linkiya_pro_features = array(
			'Bulk linking across your entire site',
			'Link report dashboard',
			'Broken link scanner with auto-fix',
			'Orphan post detection',
			'Custom post type support',
			'Same-category filtering',
			'Inbound link analysis',
			'Click analytics and reporting',
			'Link intent analysis (AI-powered)',
			'AI suggestions — semantic, context-aware links via Claude or OpenAI',
		);
		foreach ( $linkiya_pro_features as $linkiya_pro_feature ) :
			?>
			<li>✅ <?php echo esc_html( $linkiya_pro_feature ); ?></li>
		<?php endforeach; ?>
		</ul>
		<div style="background:#dcfce7;border-radius:8px;padding:14px 20px;display:inline-block;font-size:15px;font-weight:600;color:#166534;">
			🚀 <?php esc_html_e( 'Hurray! You have activated Linkiya Pro. All features are unlocked.', 'linkiya-free' ); ?>
		</div>
	</div>
	<?php else : ?>
	<div class="linkiya-pro-card">
		<h2><?php esc_html_e( 'Unlock Linkiya Pro', 'linkiya-free' ); ?></h2>
		<p><?php esc_html_e( 'Get the full power of Linkiya with Pro features:', 'linkiya-free' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'Bulk linking across your entire site', 'linkiya-free' ); ?></li>
			<li><?php esc_html_e( 'Link report dashboard', 'linkiya-free' ); ?></li>
			<li><?php esc_html_e( 'Broken link scanner with auto-fix', 'linkiya-free' ); ?></li>
			<li><?php esc_html_e( 'Orphan post detection', 'linkiya-free' ); ?></li>
			<li><?php esc_html_e( 'Custom post type support', 'linkiya-free' ); ?></li>
			<li><?php esc_html_e( 'Same-category filtering', 'linkiya-free' ); ?></li>
			<li><?php esc_html_e( 'Inbound link analysis', 'linkiya-free' ); ?></li>
			<li><?php esc_html_e( 'Click analytics and reporting', 'linkiya-free' ); ?></li>
			<li><?php esc_html_e( 'Link intent analysis (AI-powered)', 'linkiya-free' ); ?></li>
			<li><?php esc_html_e( 'AI suggestions — semantic, context-aware links via Claude or OpenAI', 'linkiya-free' ); ?></li>
		</ul>
		<a href="https://www.mypluginstore.com/linkiya" target="_blank" rel="noopener noreferrer"
			class="button button-primary button-large">
		<?php esc_html_e( 'Get Linkiya Pro', 'linkiya-free' ); ?>
		</a>
	</div>
	<?php endif; ?>
</div>
