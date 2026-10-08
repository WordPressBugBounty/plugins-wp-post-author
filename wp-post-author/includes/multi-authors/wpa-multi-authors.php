<?php
defined('ABSPATH') or die('No script kiddies please!');

class WPAMultiAuthors
{
    public $to_be_filtered_caps = array();

    public function __construct()
    {
        $author_metabox = awpa_get_author_metabox_setting();
        if ($author_metabox && !empty($author_metabox['enable_author_metabox'])) {
            add_filter('manage_posts_columns', array($this, 'awpa_ma_custom_column_head'), 1, 1);
            add_action('manage_posts_custom_column', array($this, 'awpa_ma_custom_columns_content'), 10, 2);
            add_action('add_meta_boxes', array($this, 'awpa_add_metaboxes'), 10, 1);
            add_action('save_post', array($this, 'awpa_ma_save_metabox'), 10, 1);
            add_action('admin_enqueue_scripts', array($this, 'awpa_ma_register_backend_scripts'));
            add_filter('user_has_cap', array($this, 'awpa_ma_change_user_capabilities'), 10, 3);
        }
        add_filter('posts_where', array($this, 'awpa_ma_posts_where_filter'), 10, 2);
        add_filter('posts_join', array($this, 'awpa_ma_posts_join_filter'), 10, 2);
        add_filter('posts_distinct', array($this, 'awpa_ma_search_distinct'), 10);
    }

    public function awpa_ma_custom_column_head($columns)
    {
        $post_type = get_post_type();
        if ($post_type !== 'post') {
            return $columns;
        }
        return $this->awpa_ma_replace_key($columns, 'author', 'authors');
    }

    public function awpa_ma_replace_key($arr, $oldkey, $newkey)
    {
        if (array_key_exists($oldkey, $arr)) {
            $keys = array_keys($arr);
            $keys[array_search($oldkey, $keys)] = $newkey;
            return array_combine($keys, $arr);
        }
        return $arr;
    }

    public function awpa_ma_custom_columns_content($column_name, $post_id)
    {
        if ($column_name === 'authors') {
            $this->awpa_get_authors($post_id);
        }
    }

    public function awpa_add_metaboxes()
    {
        add_meta_box(
            'awpa-multi-author',
            __('Authors', 'wp-post-author'),
            array($this, 'awpa_ma_render_metabox_multiauthor'),
            array('post', 'page', 'movie'),
            'side',
            'high'
        );
    }

    public function awpa_ma_render_metabox_multiauthor($post)
    {
        $coauthors = get_post_meta($post->ID, 'wpma_author');
        $all_authors = $this->awpa_get_all_users();
        $guest_authors = $this->awpa_get_all_guest_authors();

        if (!is_array($coauthors)) {
            $coauthors = array();
        } else {
            $coauthors = array_merge(array($post->post_author), $coauthors);
            $coauthors = array_values(array_unique($coauthors));
        }

        $author_metabox = awpa_get_author_metabox_setting();
        if (!empty($author_metabox['enable_author_metabox'])) { ?>
            <style>
                .wp-admin .components-select-control.post-author-selector { display: none; }
            </style>
        <?php } ?>

        <input type="hidden" name="wpma_meta_box_nonce" value="<?php echo esc_attr(wp_create_nonce(basename(__FILE__))); ?>">
        <input type="hidden" class="wpmma-current-post-id" value="<?php echo esc_attr($post->ID); ?>">
        <div id="awpa_authors_metabox" 
             name="awpa_authors_list" 
             multi_author_addon="<?php echo esc_attr(class_exists('AWPA_Multi_Authors_Addon') ? 'true' : 'false'); ?>" 
             all_authors="<?php echo esc_attr(wp_json_encode($all_authors)); ?>" 
             coauthors="<?php echo esc_attr(wp_json_encode($coauthors)); ?>"></div>
        <input type="hidden" id="wpma_metabox_authors_list" name="wpma_metabox_authors_list">
        <?php
    }

    public function awpa_ma_save_metabox($post_id)
    {
        // 1. Guard against Autosaves
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // 2. CSRF Nonce Verification Gate
        if (!isset($_POST['wpma_meta_box_nonce']) || !wp_verify_nonce($_POST['wpma_meta_box_nonce'], basename(__FILE__))) {
            return;
        }

        // 3. Authorization Check
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $author_list = isset($_POST['wpma_metabox_authors_list']) ? sanitize_text_field($_POST['wpma_metabox_authors_list']) : '';

        if (!empty($author_list) && is_string($author_list)) {
            $data = explode(',', $author_list);
            delete_post_meta($post_id, 'wpma_author');

            $first_author = false;
            foreach ($data as $user_id) {
                $user_id = trim($user_id);

                // 4. Strict Validation Gate
                if (preg_match('/^guest-(\d+)$/', $user_id, $matches)) {
                    $sanitized_id = 'guest-' . intval($matches[1]);
                } elseif (is_numeric($user_id)) {
                    $sanitized_id = intval($user_id);
                } else {
                    continue;
                }

                add_post_meta($post_id, 'wpma_author', $sanitized_id);

                if (is_numeric($sanitized_id)) {
                    $user = get_user_by('ID', $sanitized_id);
                    if ($user && !$first_author) {
                        $roles = $user->roles;
                        $allowed_roles = array('administrator', 'editor', 'author');
                        if (array_intersect($allowed_roles, $roles) && !$first_author) {
                            remove_action('save_post', array($this, 'awpa_ma_save_metabox'), 10);
                            
                            wp_update_post(array(
                                'ID'          => intval($post_id),
                                'post_author' => intval($sanitized_id),
                            ));

                            $first_author = true;
                            add_action('save_post', array($this, 'awpa_ma_save_metabox'), 10, 1);
                        }
                    }
                }
            }
            // Optimization: Clear object cache for this post's author metadata
            wp_cache_delete($post_id, 'post_meta');
        }
    }

    /**
     * Performance Optimization: Cache guest authors using Transients (12 hour expiration)
     */
    public function awpa_get_all_guest_authors()
    {
        $all_guests = get_transient('awpa_all_guest_authors_cache');
        if (false !== $all_guests) {
            return $all_guests;
        }

        $guests = $this->awpa_get_guest();
        if (!$guests) {
            return array();
        }

        $all_guests = array();
        foreach ($guests as $guest) {
            $all_guests[] = array(
                'user'      => $guest,
                'id'        => 'guest-' . intval($guest->id),
                'is_active' => $guest->is_active == "1",
                'type'      => 'guest',
            );
        }

        set_transient('awpa_all_guest_authors_cache', $all_guests, 12 * HOUR_IN_SECONDS);
        return $all_guests;
    }

    public function awpa_get_guest()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "wpa_guest_authors";
        return $wpdb->get_results("SELECT id, display_name, user_nicename, user_email, is_active FROM {$table_name}", OBJECT);
    }

    /**
     * Security & Performance: Prepared Query + Object Caching per Guest ID
     */
    public function awpa_ma_get_guest_author($guest_id)
    {
        $guest_id = intval($guest_id);
        if ($guest_id <= 0) {
            return false;
        }

        $cache_key = 'awpa_guest_author_' . $guest_id;
        $guest_author = wp_cache_get($cache_key, 'awpa_authors');

        if (false === $guest_author) {
            global $wpdb;
            $table_name = $wpdb->prefix . "wpa_guest_authors";
            $query = $wpdb->prepare(
                "SELECT id, user_email, display_name, user_nicename FROM {$table_name} WHERE id = %d",
                $guest_id
            );
            $guest_author = $wpdb->get_row($query, OBJECT);

            if ($guest_author) {
                wp_cache_set($cache_key, $guest_author, 'awpa_authors', 3600);
            } else {
                wp_cache_set($cache_key, false, 'awpa_authors', 600);
            }
        }

        return $guest_author;
    }

    public function awpa_is_guest_linked_with_author($user_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "wpa_guest_authors";
        return $wpdb->get_results($wpdb->prepare("SELECT id, linked_user_id FROM {$table_name} WHERE linked_user_id = %d", intval($user_id)), OBJECT);
    }

    public function awpa_get_all_users()
    {
        $args = array(
            'role__in' => array('administrator', 'editor', 'author', 'contributor'),
            'fields'   => array('user_login', 'user_nicename', 'user_email', 'display_name', 'ID'),
        );
        $users = get_users($args);
        $users_id_array = array();

        foreach ($users as $user) {
            $user_meta = get_userdata($user->ID);
            $user_roles = (!empty($user_meta->roles) && is_array($user_meta->roles)) ? $user_meta->roles : array('author');
            $primary_role = !empty($user_roles[0]) ? $user_roles[0] : 'author';

            $users_id_array[] = array(
                'user'      => $user,
                'id'        => $user->ID,
                'is_active' => true,
                'type'      => ucfirst($primary_role),
            );
        }
        return $users_id_array;
    }

    public function awpa_get_authors($post_id)
    {
        global $post;
        $coauthors = get_post_meta($post_id, 'wpma_author');
        $main_author = $post ? $post->post_author : 0;
        
        if (is_array($coauthors)) {
            $coauthors[] = $main_author;
            $coauthors = array_values(array_unique($coauthors));
        } else {
            $coauthors = array($main_author);
        }

        $count = 1;
        $total = count($coauthors);

        foreach ($coauthors as $author_id) {
            if (preg_match('/^guest-(\d+)$/', $author_id, $matches)) {
                $guest_id = intval($matches[1]);
                $guest_author = $this->awpa_ma_get_guest_author($guest_id);
                if ($guest_author) {
                    $author_filter_url = add_query_arg(array('author_name' => rawurlencode($guest_author->user_nicename)), admin_url('edit.php'));
                    ?>
                    <a href="<?php echo esc_url($author_filter_url); ?>" 
                       data-nice_name="<?php echo esc_attr($guest_author->user_nicename); ?>" 
                       data-user_email="<?php echo esc_attr($guest_author->user_email); ?>" 
                       data-display_name="<?php echo esc_attr($guest_author->display_name); ?>" 
                       data-avatar="<?php echo esc_url(get_avatar_url($guest_author->id)); ?>"><?php echo esc_html($guest_author->display_name); ?></a><?php echo ($count < $total) ? ', ' : ''; ?>
                    <?php
                }
            } else {
                $author = get_user_by('id', intval($author_id));
                if ($author) {
                    $args = array('author_name' => $author->user_nicename);
                    if ($post && 'post' !== $post->post_type) {
                        $args['post_type'] = $post->post_type;
                    }
                    $author_filter_url = add_query_arg(array_map('rawurlencode', $args), admin_url('edit.php'));
                    ?>
                    <a href="<?php echo esc_url($author_filter_url); ?>" 
                       data-user_nicename="<?php echo esc_attr($author->user_nicename); ?>" 
                       data-user_email="<?php echo esc_attr($author->user_email); ?>" 
                       data-display_name="<?php echo esc_attr($author->display_name); ?>" 
                       data-user_login="<?php echo esc_attr($author->user_login); ?>" 
                       data-avatar="<?php echo esc_url(get_avatar_url($author->ID)); ?>"><?php echo esc_html($author->display_name); ?></a><?php echo ($count < $total) ? ', ' : ''; ?>
                    <?php
                }
            }
            $count++;
        }
    }

    public function awpa_get_authors_frontend($post_id)
    {
        global $post;
        $coauthors = get_post_meta($post_id, 'wpma_author');
        $main_author = $post ? $post->post_author : 0;

        if (is_array($coauthors)) {
            $coauthors[] = $main_author;
            $coauthors = array_values(array_unique($coauthors));
        } else {
            $coauthors = array($main_author);
        }

        $count = 1;
        $total = count($coauthors);

        foreach ($coauthors as $author_id) {
            if (preg_match('/^guest-(\d+)$/', $author_id, $matches)) {
                $guest_id = intval($matches[1]);
                $guest_author = $this->awpa_ma_get_guest_author($guest_id);
                if ($guest_author) {
                    $permalink_structure = get_option('permalink_structure');
                    $author_url = empty($permalink_structure) 
                        ? add_query_arg('author_name', rawurlencode($guest_author->user_nicename), site_url()) 
                        : home_url('/author/' . sanitize_title($guest_author->user_nicename));
                    ?>
                    <a href="<?php echo esc_url($author_url); ?>" 
                       data-nice_name="<?php echo esc_attr($guest_author->user_nicename); ?>" 
                       data-user_email="<?php echo esc_attr($guest_author->user_email); ?>" 
                       data-display_name="<?php echo esc_attr($guest_author->display_name); ?>" 
                       data-avatar="<?php echo esc_url(get_avatar_url($guest_author->id)); ?>"><?php echo esc_html($guest_author->display_name); ?></a><?php echo ($count < $total) ? ', ' : ''; ?>
                    <?php
                }
            } else {
                $author = get_user_by('id', intval($author_id));
                if ($author) {
                    $author_url = get_author_posts_url($author->ID, $author->user_nicename);
                    ?>
                    <a href="<?php echo esc_url($author_url); ?>"><?php echo esc_html($author->display_name); ?></a><?php echo ($count < $total) ? ', ' : ''; ?>
                    <?php
                }
            }
            $count++;
        }
    }

    public function awpa_ma_change_user_capabilities($allcaps, $caps, $args)
    {
        $cap = isset($args[0]) ? $args[0] : '';
        $user_id = isset($args[1]) ? intval($args[1]) : 0;
        $post_id = isset($args[2]) ? intval($args[2]) : 0;

        if (!$post_id || !$user_id) {
            return $allcaps;
        }

        $obj = get_post_type_object(get_post_type($post_id));
        if (!$obj || 'revision' === $obj->name) {
            return $allcaps;
        }

        $caps_to_modify = array(
            $obj->cap->edit_post,
            'edit_post',
            $obj->cap->edit_others_posts,
            'read_post',
            $obj->cap->read_post,
        );

        if (!in_array($cap, $caps_to_modify, true)) {
            return $allcaps;
        }

        $multiauthors = get_post_meta($post_id, 'wpma_author');
        $current_user = wp_get_current_user();

        if (is_user_logged_in() && $current_user->ID === $user_id && is_array($multiauthors) && in_array($user_id, $multiauthors, true)) {
            $allcaps[$obj->cap->edit_others_posts] = true;
        }

        $post_status = get_post_status($post_id);
        if ('publish' === $post_status && !empty($current_user->allcaps[$obj->cap->edit_published_posts])) {
            $allcaps[$obj->cap->edit_published_posts] = true;
        } elseif ('private' === $post_status && !empty($current_user->allcaps[$obj->cap->edit_private_posts])) {
            $allcaps[$obj->cap->edit_private_posts] = true;
        }

        return $allcaps;
    }

    public function awpa_ma_search_distinct()
    {
        return "DISTINCT";
    }

    public function awpa_ma_posts_join_filter($join, $query)
    {
        global $wpdb;

        $query_author_name = $query->get('author_name');
        $author = array();
        if ($query_author_name) {
            $author = $this->awpa_ma_get_user_by_nicename($query_author_name);
        }
        $query_author_name_id = $query->get('author');
        if ($query_author_name_id) {
            $author = $this->awpa_ma_get_user_by_id($query_author_name_id);
        }

        if (!$author) {
            $guest_author = $this->awap_ma_get_guest_by_nicename($query_author_name);
            if ($guest_author) {
                if ($guest_author->linked_user_id != "0" && $guest_author->is_linked == "1") {
                    $join .= " LEFT JOIN {$wpdb->postmeta} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id ";
                    $linked_author = $wpdb->get_row($wpdb->prepare("SELECT user_nicename FROM {$wpdb->users} WHERE ID = %d", intval($guest_author->linked_user_id)));
                    if (is_admin()) {
                        if ($linked_author) {
                            wp_redirect(admin_url("edit.php?author_name=" . rawurlencode($linked_author->user_nicename)), 301);
                            exit;
                        }
                    } else {
                        if ($linked_author) {
                            wp_redirect(home_url('/author/' . sanitize_title($linked_author->user_nicename)), 301);
                            exit;
                        }
                    }
                } else {
                    $join .= " INNER JOIN {$wpdb->postmeta} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id ";
                }
                return $join;
            }
            return $join;
        }

        $join .= " LEFT JOIN {$wpdb->postmeta} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id ";
        return $join;
    }

    public function awpa_ma_posts_where_filter($where, $query)
    {
        global $wpdb;
        if (is_author() && $query->is_main_query() && !is_admin()) {
            $author = array();
            $query_author_name = $query->get('author_name');
            if ($query_author_name) {
                $author = $this->awpa_ma_get_user_by_nicename($query_author_name);
            }
            $query_author_name_id = $query->get('author');
            if ($query_author_name_id) {
                $author = $this->awpa_ma_get_user_by_id($query_author_name_id);
            }

            if (!$author) {
                $guest_author = $this->awap_ma_get_guest_by_nicename($query_author_name);

                if ($guest_author && $guest_author->is_active == "1") {
                    $where = preg_replace('/AND\s*\((?:' . $wpdb->posts . '\.)?post_author\s*\=\s*\d+\)/', ' ', $where);
                    $where = preg_replace('/AND\s*' . $wpdb->posts . '\.post_author\s*IN\s*\([0-9]*\)/', ' ', $where, 1);

                    $clean_guest_id = intval($guest_author->id);
                    $clean_linked_user_id = intval($guest_author->linked_user_id);

                    if ($guest_author->linked_user_id != "0" && $guest_author->is_linked == "1") {
                        $where .= " AND ($wpdb->postmeta.meta_value = 'guest-{$clean_guest_id}'
                                    OR ($wpdb->postmeta.meta_key = 'wpma_author' AND $wpdb->postmeta.meta_value = '{$clean_linked_user_id}')
                                    OR $wpdb->posts.post_author = '{$clean_linked_user_id}')";
                    } else {
                        $where .= " AND ($wpdb->postmeta.meta_value = 'guest-{$clean_guest_id}')";
                    }
                    return $where;
                }
                return $where;
            } else {
                $where = preg_replace('/AND\s*\((?:' . $wpdb->posts . '\.)?post_author\s*\=\s*\d+\)/', ' ', $where, 1);
                $where = preg_replace('/AND\s*' . $wpdb->posts . '\.post_author\s*IN\s*\([0-9]*\)/', ' ', $where, 1);
                $author_id = intval($author->ID);
                $guest_author = $this->apwa_ma_get_guest_by_linked_user_id($author_id);
                if ($guest_author && $guest_author->id == $author_id && $guest_author->is_linked == "1" && $guest_author->is_active == "1") {
                    $where .= " AND (
                                ($wpdb->posts.post_author = {$author_id}) OR
                                ($wpdb->postmeta.meta_key = 'wpma_author' AND $wpdb->postmeta.meta_value = {$author_id})
                                OR ($wpdb->postmeta.meta_key = 'wpma_author' AND $wpdb->postmeta.meta_value = 'guest-{$author_id}'))";
                } else {
                    $where .= " AND (
                                ($wpdb->posts.post_author = {$author_id}) OR
                                ($wpdb->postmeta.meta_key = 'wpma_author' AND $wpdb->postmeta.meta_value = {$author_id}))";
                }
            }
        }

        add_filter('the_posts', array($this, 'awpa_reset_post_author'), 10, 2);
        return $where;
    }

    public function awpa_reset_post_author($posts, $query)
    {
        if ($query->is_author() && $query->is_main_query() && !is_admin()) {
            foreach ($posts as &$post) {
                $post->post_author = $query->get_queried_object_id();
            }
        }
        return $posts;
    }

    public function awpa_ma_get_user_by_nicename($value)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT ID, user_nicename FROM {$wpdb->users} WHERE user_nicename = %s", $value));
    }

    public function awpa_ma_get_user_by_id($value)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT ID, user_nicename FROM {$wpdb->users} WHERE ID = %d", intval($value)));
    }

    public function awpa_ma_get_guest_by_email($email)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "wpa_guest_authors";
        return $wpdb->get_row($wpdb->prepare("SELECT id, user_email FROM {$table_name} WHERE user_email = %s", sanitize_email($email)));
    }

    /**
     * Performance Optimization: Cache Table existence checks via Transient
     */
    public function awap_ma_get_guest_by_nicename($value)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpa_guest_authors';
        
        $table_exists = get_transient('awpa_guest_table_exists');
        if (false === $table_exists) {
            $table_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name);
            set_transient('awpa_guest_table_exists', $table_exists ? 1 : 0, DAY_IN_SECONDS);
        }

        if ($table_exists) {
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE user_nicename = %s", $value));
        }
        return false;
    }

    public function apwa_ma_get_guest_by_linked_user_id($author_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wpa_guest_authors WHERE linked_user_id = %d", intval($author_id)));
    }

    public function awpa_ma_register_backend_scripts()
    {
        wp_enqueue_style('admin-metabox-css', AWPA_PLUGIN_URL . '/assets/css/admin.metabox.css', array(), AWPA_VERSION);

        $awpa_current_screen = get_current_screen();
        if ($awpa_current_screen && $awpa_current_screen->post_type === 'post') {
            if ($this->awpa_is_edit_page('new') || $this->awpa_is_edit_page('edit')) {
                wp_enqueue_script('authors-metabox', AWPA_PLUGIN_URL . 'assets/dist/authors_metabox.build.js', array(), AWPA_VERSION, true);
            }
        }
    }

    private function awpa_is_edit_page($new_edit = null)
    {
        global $pagenow;
        if (!is_admin()) {
            return false;
        }
        if ($new_edit === "edit") {
            return in_array($pagenow, array('post.php'), true);
        } elseif ($new_edit === "new") {
            return in_array($pagenow, array('post-new.php'), true);
        } else {
            return in_array($pagenow, array('post.php', 'post-new.php'), true);
        }
    }
}

$wpaMultiAuthors = new WPAMultiAuthors();