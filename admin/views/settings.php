<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap">
    <h1>⚙️ <?php esc_html_e( 'Linkiya — Settings', 'linkiya' ); ?></h1>

    <?php if ( ! empty( $_GET['saved'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'linkiya' ); ?></p></div>
    <?php endif; ?>

    <?php if ( ! empty( $_GET['imported'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings imported successfully.', 'linkiya' ); ?></p></div>
    <?php endif; ?>

    <?php if ( ! empty( $_GET['import_error'] ) ) : ?>
    <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Import failed. Please upload a valid JSON file.', 'linkiya' ); ?></p></div>
    <?php endif; ?>

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

        </table>

        <?php
        // Allow Pro plugin to add extra settings fields
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

    <h2 style="margin-top:24px"><?php esc_html_e( 'Import Settings', 'linkiya' ); ?></h2>
    <p><?php esc_html_e( 'Upload a previously exported settings JSON file.', 'linkiya' ); ?></p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
        <?php wp_nonce_field( 'linkiya_import_settings' ); ?>
        <input type="hidden" name="action" value="linkiya_import_settings">
        <input type="file" name="linkiya_import_file" accept=".json" required style="margin-bottom:12px;display:block">
        <?php submit_button( __( 'Import Settings', 'linkiya' ), 'secondary', 'submit', false ); ?>
    </form>

    <!-- Upgrade to Pro card -->
    <hr>
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:24px;max-width:600px;margin-top:24px">
        <h2 style="margin-top:0">⭐ <?php esc_html_e( 'Unlock Linkiya Pro', 'linkiya' ); ?></h2>
        <p style="color:#6b7280"><?php esc_html_e( 'Get the full power of Linkiya with 21 Pro features:', 'linkiya' ); ?></p>
        <ul style="color:#374151;line-height:2;padding-left:20px">
            <li><?php esc_html_e( '🔁 Bulk linking across your entire site', 'linkiya' ); ?></li>
            <li><?php esc_html_e( '📊 Link report dashboard', 'linkiya' ); ?></li>
            <li><?php esc_html_e( '🔴 Broken link scanner with auto-fix', 'linkiya' ); ?></li>
            <li><?php esc_html_e( '🏚 Orphan post detection', 'linkiya' ); ?></li>
            <li><?php esc_html_e( '📋 Custom post type support', 'linkiya' ); ?></li>
            <li><?php esc_html_e( '🗂 Same-category filtering', 'linkiya' ); ?></li>
            <li><?php esc_html_e( '🔍 Inbound link analysis', 'linkiya' ); ?></li>
            <li><?php esc_html_e( '📈 Click analytics & reporting', 'linkiya' ); ?></li>
            <li><?php esc_html_e( '🎯 Link intent analysis (AI-powered)', 'linkiya' ); ?></li>
        </ul>
        <a href="https://www.mypluginstore.com/linkiya" target="_blank" rel="noopener noreferrer"
            class="button button-primary button-large" style="margin-top:8px">
            <?php esc_html_e( 'Get Linkiya Pro →', 'linkiya' ); ?>
        </a>
    </div>
</div>
