<?php
defined('ABSPATH') or die('No script kiddies please!');
/**
 * Register Admin Submenu Page for Migration & Meta Repair.
 * Optimized to prevent full table scans on every admin page load.
 */
function awpa_register_legacy_migration_menu()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    // Check transient cache first to avoid repetitive DB checks on every page load
    $show_menu = get_transient('awpa_show_legacy_migration_menu');

    if (false === $show_menu) {
        global $wpdb;
        $table_name  =$wpdb->prefix . "wpa_guest_authors";
        $table_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name);$guest_count = $table_exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}") : 0;
        
        $has_orphaned_meta = false;

        // Only check postmeta if no legacy guests exist in the custom table
        if ($guest_count === 0) {
            // Fast EXISTS check with LIMIT 1 instead of expensive COUNT(*) full table scan
            $has_orphaned_meta = (bool)$wpdb->get_var(
                "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_value LIKE '%guest-%' LIMIT 1"
            );
        }

        $show_menu = ($guest_count > 0 || $has_orphaned_meta) ? 'yes' : 'no';

        // Cache the menu status for 12 hours
        set_transient('awpa_show_legacy_migration_menu', $show_menu, 12 * HOUR_IN_SECONDS);
    }

    if ('yes' === $show_menu) {
        add_submenu_page(
            'wp-post-author',
            __('Migrate Legacy Guests', 'wp-post-author'),
            __('Migrate Guests', 'wp-post-author'),
            'manage_options',
            'awpa-legacy-migration',
            'awpa_render_legacy_migration_page'
        );
    }
}
add_action('admin_menu', 'awpa_register_legacy_migration_menu', 65);

/**
 * Safely unserialize data disabling PHP object instantiation.
 */
function awpa_safe_unserialize($data)
{
    if (!is_serialized($data)) {
        return $data;
    }
    return @unserialize($data, array('allowed_classes' => false));
}

/**
 * Helper: Find migrated WP User ID by legacy guest author ID.
 */
function awpa_get_user_id_by_legacy_guest_id($guest_id)
{
    $guest_id = (int)$guest_id;
    if ($guest_id <= 0) {
        return false;
    }

    $users = get_users(array(
        'meta_key'   => '_awpa_legacy_guest_id',
        'meta_value' => $guest_id,
        'number'     => 1,
        'fields'     => 'ID',
    ));

    return !empty($users) ? (int)$users[0] : false;
}

/**
 * Core Migration Engine for a Single Guest Author.
 */
function awpa_migrate_single_guest_author($guest_id)
{
    global $wpdb;
    $table_name =$wpdb->prefix . "wpa_guest_authors";

    $guest =$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $guest_id));
    if (!$guest) {
        return new WP_Error('not_found', __('Legacy guest author not found.', 'wp-post-author'));
    }

    // 1. Email & Username Sanitization
    $email = sanitize_email($guest->user_email);
    if (empty($email) || email_exists($email)) {
        $email = 'guest_' .$guest->id . '_' . wp_generate_password(8, false) . '@no-reply.local';
    }

    $username = sanitize_user($guest->user_nicename, true);
    if (empty($username) || username_exists($username)) {
        $username = 'guest_author_' .$guest->id;
    }

    // 2. Account Creation
    $user_data = array(
        'user_login'   => $username,
        'user_pass'    => wp_generate_password(32, true, true),
        'user_email'   => $email,
        'display_name' => !empty($guest->display_name) ? sanitize_text_field($guest->display_name) :$username,
        'first_name'   => sanitize_text_field($guest->first_name),
        'last_name'    => sanitize_text_field($guest->last_name),
        'user_url'     => esc_url_raw($guest->website),
        'description'  => sanitize_textarea_field($guest->description),
        'role'         => 'awpa-guest',
    );

    $user_id = wp_insert_user($user_data);
    if (is_wp_error($user_id)) {
        return $user_id;
    }

    // 3. User Meta & Security Safeguards
    update_user_meta($user_id, '_awpa_legacy_guest_id',$guest->id);
    update_user_meta($user_id, 'is_active', 'true');
    
    if (!empty($guest->avatar_name)) {
        update_user_meta($user_id, 'awpa_custom_avatar', sanitize_text_field($guest->avatar_name));
    }

    if (!empty($guest->user_meta)) {
        $meta = awpa_safe_unserialize($guest->user_meta);
        if (is_array($meta)) {$forbidden_keys = array(
                'wp_capabilities', 'wp_user_level', 'session_tokens',
                'wp_user_roles', 'primary_blog', 'source_domain', 'user_pass'
            );

            foreach ($meta as $key =>$val) {
                $sanitized_key = sanitize_key($key);
                if (in_array($sanitized_key, $forbidden_keys, true) || strpos($sanitized_key, 'capabilities') !== false) {
                    continue;
                }
                update_user_meta($user_id, $sanitized_key, wp_kses_post_deep($val));
            }
        }
    }

    // 4. Update Post Meta References & Post Author
    $legacy_key = 'guest-' .$guest->id;

    $affected_posts = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_value = %s",
        $legacy_key
    ));

    // Direct string value replacement
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_value = %s",
        (string) $user_id,$legacy_key
    ));

    // Serialized value replacement
    $serialized_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
        '%' . $wpdb->esc_like('"' . $legacy_key . '"') . '%'
    ));

    foreach ($serialized_rows as$row) {
        $val = awpa_safe_unserialize($row->meta_value);
        if (is_array($val)) {$modified = false;
            foreach ($val as $k =>$v) {
                if ($v === $legacy_key) {$val[$k] = (string)$user_id;
                    $modified = true;
                }
            }
            if ($modified) {
                update_metadata_by_mid('post', $row->meta_id,$val);
            }
        }
    }

    // Reassign unassigned posts
    if (!empty($affected_posts)) {
        foreach ($affected_posts as$post_id) {
            $current_author = (int) get_post_field('post_author',$post_id);
            if ($current_author === 0 || !get_userdata($current_author)) {
                $wpdb->update($wpdb->posts,
                    array('post_author' => $user_id),
                    array('ID' => $post_id),
                    array('%d'),
                    array('%d')
                );
            }
        }
    }

    // Delete migrated record from legacy database table
    $wpdb->delete($table_name, array('id' =>$guest->id), array('%d'));

    // Clear menu transient cache after a migration run
    delete_transient('awpa_show_legacy_migration_menu');

    return $user_id;
}

/**
 * Render Legacy Migration & Postmeta Repair Control Panel.
 * Heavy count queries run ONLY on this dedicated admin screen.
 */
function awpa_render_legacy_migration_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized access.', 'wp-post-author'));
    }

    global $wpdb;
    $table_name   =$wpdb->prefix . "wpa_guest_authors";
    $table_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) ===$table_name);
    
    $guests =$table_exists ? $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY id ASC") : array();
    
    // Heavy full count runs ONLY when the admin views this specific page
    $orphaned_meta_count = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE '%guest-%'"
    );

    // If no data left to migrate/repair, clear transient so menu disappears on next page load
    if (empty($guests) &&$orphaned_meta_count === 0) {
        delete_transient('awpa_show_legacy_migration_menu');
    }

    $js_vars = array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('awpa_migration_nonce'),
    );
?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php _e('Guest Author Migration & Repair Suite', 'wp-post-author'); ?></h1>
        <hr class="wp-header-end">

        <!-- SECTION 1: ACCOUNT MIGRATION -->
        <div class="card" style="max-width: 100%; margin-top: 20px;">
            <h2><?php _e('1. Account Migration', 'wp-post-author'); ?></h2>
            <p><?php _e('Convert database guest author records into real WordPress User accounts under the Guest Author role.', 'wp-post-author'); ?></p>

            <?php if (!empty($guests)): ?>
                <div style="margin: 15px 0;">
                    <button id="awpa-migrate-all-btn" class="button button-primary">
                        <?php printf(__('Migrate All (%d Guests)', 'wp-post-author'), count($guests)); ?>
                    </button>
                    <span id="awpa-migration-status" style="margin-left: 10px; font-weight: 600;"></span>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th>Display Name</th>
                            <th>Email</th>
                            <th>Nicename</th>
                            <th style="width: 150px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="awpa-migration-rows">
                        <?php foreach ($guests as$guest): ?>
                            <tr id="guest-row-<?php echo esc_attr($guest->id); ?>">
                                <td><?php echo esc_html($guest->id); ?></td>
                                <td><strong><?php echo esc_html($guest->display_name); ?></strong></td>
                                <td><?php echo esc_html($guest->user_email ?: 'N/A'); ?></td>
                                <td><?php echo esc_html($guest->user_nicename); ?></td>
                                <td>
                                    <button class="button awpa-migrate-single-btn" data-id="<?php echo esc_attr($guest->id); ?>">
                                        <?php _e('Migrate to User', 'wp-post-author'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="notice notice-success inline">
                    <p><?php _e('All legacy database guest author accounts are fully migrated!', 'wp-post-author'); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- SECTION 2: FALLBACK POSTMETA REPAIR ENGINE -->
        <div class="card" style="max-width: 100%; margin-top: 20px;">
            <h2><?php _e('2. Postmeta Cleanup & Repair Tool (Fallback Engine)', 'wp-post-author'); ?></h2>
            <p><?php _e('Scans wp_postmeta for unmapped legacy "guest-X" keys and converts them to valid User IDs. Useful if guest records were manually cleared or linked outside the standard flow.', 'wp-post-author'); ?></p>

            <div style="margin: 15px 0;">
                <p><strong><?php printf(__('Orphaned/Legacy Postmeta Keys Found: %d', 'wp-post-author'), $orphaned_meta_count); ?></strong></p>
                <?php if ($orphaned_meta_count > 0): ?>
                    <button id="awpa-repair-postmeta-btn" class="button button-secondary">
                        <?php _e('Run Postmeta Repair Batch', 'wp-post-author'); ?>
                    </button>
                    <span id="awpa-repair-status" style="margin-left: 10px; font-weight: 600;"></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            const config = <?php echo wp_json_encode($js_vars); ?>;

            // Single Guest Migration
            $(document).on('click', '.awpa-migrate-single-btn', function(e) {
                e.preventDefault();
                const btn = $(this);
                const guestId = parseInt(btn.data('id'), 10);
                if (!guestId) return;

                btn.prop('disabled', true).text('Migrating...');

                $.post(config.ajax_url, {
                    action: 'awpa_ajax_migrate_single_guest',
                    guest_id: guestId,
                    _ajax_nonce: config.nonce
                }, function(res) {
                    if (res.success) {
                        $('#guest-row-' + guestId).fadeOut(400, function() {
                            $(this).remove();
                        });
                    } else {
                        alert('Error: ' + (res.data ? res.data.message : 'Unknown error'));
                        btn.prop('disabled', false).text('Migrate to User');
                    }
                }).fail(function() {
                    alert('Server error encountered while migrating guest #' + guestId);
                    btn.prop('disabled', false).text('Migrate to User');
                });
            });

            // Batch Guest Migration
            $('#awpa-migrate-all-btn').on('click', function(e) {
                e.preventDefault();
                const btn = $(this);
                const rows = $('.awpa-migrate-single-btn');
                if (!rows.length) return;

                btn.prop('disabled', true);
                let successCount = 0;
                const total = rows.length;

                function processNext(index) {
                    if (index >= total) {
                        $('#awpa-migration-status').text(`Migration complete! Successfully converted ${successCount} of ${total} authors. Reloading...`);
                        setTimeout(() => location.reload(), 1200);
                        return;
                    }
                    const guestId = parseInt($(rows[index]).data('id'), 10);$('#awpa-migration-status').text(`Migrating ${index + 1} of ${total}...`);

                    $.post(config.ajax_url, {
                        action: 'awpa_ajax_migrate_single_guest',
                        guest_id: guestId,
                        _ajax_nonce: config.nonce
                    }, function(res) {
                        if (res.success) {
                            successCount++;
                            $('#guest-row-' + guestId).remove();
                        } else {
                            $('#guest-row-' + guestId).css('background-color', '#ffebe9');
                        }
                        processNext(index + 1);
                    }).fail(function() {
                        $('#guest-row-' + guestId).css('background-color', '#ffebe9');
                        processNext(index + 1);
                    });
                }
                processNext(0);
            });

            // Batch Postmeta Repair Sweep
            $('#awpa-repair-postmeta-btn').on('click', function(e) {
                e.preventDefault();
                const btn = $(this);
                btn.prop('disabled', true);

                let totalProcessed = 0;

                function runBatch() {
                    $('#awpa-repair-status').text(`Processing postmeta batch... (${totalProcessed} repaired so far)`);

                    $.post(config.ajax_url, {
                        action: 'awpa_ajax_batch_repair_postmeta',
                        _ajax_nonce: config.nonce
                    }, function(res) {
                        if (res.success) {
                            totalProcessed += res.data.repaired;
                            if (res.data.remaining > 0 && res.data.repaired > 0) {
                                runBatch();
                            } else {
                                $('#awpa-repair-status').text(`Postmeta repair complete! Cleaned ${totalProcessed} total meta records.`);
                                setTimeout(() => location.reload(), 1500);
                            }
                        } else {
                            alert('Repair error: ' + (res.data ? res.data.message : 'Unknown error'));
                            btn.prop('disabled', false);
                        }
                    }).fail(function() {
                        alert('Server error encountered during postmeta batch repair.');
                        btn.prop('disabled', false);
                    });
                }
                runBatch();
            });
        });
    </script>
<?php
}

/**
 * AJAX Handler: Single Guest Migration.
 */
function awpa_ajax_migrate_single_guest_handler()
{
    check_ajax_referer('awpa_migration_nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'wp-post-author')));
    }

    $guest_id = isset($_POST['guest_id']) ? absint($_POST['guest_id']) : 0;
    if ($guest_id <= 0) {
        wp_send_json_error(array('message' => __('Invalid Guest ID.', 'wp-post-author')));
    }

    $result = awpa_migrate_single_guest_author($guest_id);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success(array('user_id' => $result));
}
add_action('wp_ajax_awpa_ajax_migrate_single_guest', 'awpa_ajax_migrate_single_guest_handler');

/**
 * AJAX Handler: Batch Postmeta Repair Engine.
 * Sweeps wp_postmeta in safe chunks (100 rows per request).
 */
function awpa_ajax_batch_repair_postmeta_handler()
{
    check_ajax_referer('awpa_migration_nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'wp-post-author')));
    }

    global $wpdb;

    $rows =$wpdb->get_results(
        "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE '%guest-%' LIMIT 100"
    );

    $repaired_count = 0;

    foreach ($rows as$row) {
        $meta_val = awpa_safe_unserialize($row->meta_value);

        // 1. Handle plain string 'guest-X'
        if (is_string($meta_val) && strpos($meta_val, 'guest-') !== false) {
            $guest_id = (int) preg_replace('/[^0-9]/', '',$meta_val);
            if ($guest_id > 0) {
                $user_id = awpa_get_user_id_by_legacy_guest_id($guest_id);
                if ($user_id) {
                    update_metadata_by_mid('post', $row->meta_id, (string) $user_id);$repaired_count++;
                }
            }
        }
        // 2. Handle serialized arrays containing ['guest-X', 'guest-Y']
        elseif (is_array($meta_val)) {$modified = false;
            foreach ($meta_val as $k =>$v) {
                if (is_string($v) && strpos($v, 'guest-') !== false) {
                    $guest_id = (int) preg_replace('/[^0-9]/', '',$v);
                    if ($guest_id > 0) {
                        $user_id = awpa_get_user_id_by_legacy_guest_id($guest_id);
                        if ($user_id) {$meta_val[$k] = (string)$user_id;
                            $modified = true;
                        }
                    }
                }
            }
            if ($modified) {
                update_metadata_by_mid('post', $row->meta_id, $meta_val);$repaired_count++;
            }
        }
    }

    $remaining = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE '%guest-%'"
    );

    // Reset transient if repair is complete
    if ($remaining === 0) {
        delete_transient('awpa_show_legacy_migration_menu');
    }

    wp_send_json_success(array(
        'repaired'  => $repaired_count,
        'remaining' => $remaining
    ));
}
add_action('wp_ajax_awpa_ajax_batch_repair_postmeta', 'awpa_ajax_batch_repair_postmeta_handler');