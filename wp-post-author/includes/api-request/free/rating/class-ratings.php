<?php
if (!class_exists('Awpa_Ratings_Rest_Controller')) {
    class Awpa_Ratings_Rest_Controller
    {
        private $namespace;

        public function __construct()
        {
            $this->namespace = 'awpa-pro-api/v1';
            add_action('init', array($this, 'awpa_pro_insert_rating_settings'));
        }

        public function awpa_ratings_register_routes()
        {
            register_rest_route(
                $this->namespace,
                '/awpa-pro-rating',
                array(
                    array(
                        'methods'             => WP_REST_Server::READABLE,
                        'callback'            => array($this, 'awpa_pro_api_get_rating_settings'),
                        'permission_callback' => array($this, 'awpa_permission_check'),
                    ),
                )
            );

            register_rest_route(
                $this->namespace,
                '/awpa-pro-rating',
                array(
                    array(
                        'methods'             => WP_REST_Server::EDITABLE,
                        'callback'            => array($this, 'awpa_pro_api_set_rating_settings'),
                        'permission_callback' => array($this, 'awpa_permission_check'),
                    ),
                )
            );
        }

        public function awpa_permission_check($request)
        {
            return current_user_can('edit_posts');
        }

        public function awpa_checkuser_permession($request)
        {
            return current_user_can('read');
        }

        public function awpa_pro_insert_rating_settings()
        {
            $options = get_option('awpa_pro_rating_settings');
            if (!$options) {
                $default_options = $this->awpa_pro_rating_setting_default_options();
                update_option('awpa_pro_rating_settings', $default_options);
            }
        }

        public function awpa_pro_rating_setting_default_options()
        {
            $default_options = array(
                'enable_pro_rating'   => false,
                'show_star_rating'    => false,
                'top_post_content'    => false,
                'bottom_post_content' => true,
                'post_types'          => array(
                    array('name' => 'post', 'label' => 'Posts', 'value' => true),
                ),
                'rating_color_front'  => '#ffb900',
                'rating_color_back'   => '#EEEEEE',
                'exclude_post'        => array(),
                'rating_review'       => '5_star',
                'send_review_email'   => true,
                'rating_heading'      => __('Love it or Not? Let us know!', 'wp-post-author'),
                'rating_text'         => __('Rate this', 'wp-post-author'),
                'button_text'         => __('Submit', 'wp-post-author'),
                'rating_display_on'   => 'bottom',
            );
            return apply_filters('awpa_pro_rating_setting_default_options', $default_options);
        }

        public function awpa_pro_get_rating_settings($key = '')
        {
            $options = get_option('awpa_pro_rating_settings');
            $default_options = $this->awpa_pro_rating_setting_default_options();

            if (!empty($key)) {
                if (isset($options[$key])) {
                    return $options[$key];
                }
                return isset($default_options[$key]) ? $default_options[$key] : false;
            } else {
                if (!is_array($options)) {
                    $options = array();
                }
                return array_merge($default_options, $options);
            }
        }

        public function awpa_pro_api_get_rating_settings()
        {
            $args_posts = array('public' => true);
            return new WP_REST_Response([
                'settings'       => $this->awpa_pro_get_rating_settings(),
                'new_post_types' => get_post_types($args_posts, 'objects', 'and'),
            ]);
        }

        public function awpa_pro_api_set_rating_settings(\WP_REST_Request $request)
        {
            $params = $request->get_params();
            if (isset($params['awpa_pro_rating']) && is_array($params['awpa_pro_rating'])) {
                $this->awpa_pro_set_rating_settings($params['awpa_pro_rating']);
            }

            return new WP_REST_Response(['status' => 200]);
        }

        public function awpa_pro_set_rating_settings($settings)
        {
            $setting_keys = array_keys($this->awpa_pro_rating_setting_default_options());
            $options = array();

            foreach ($settings as $key => $value) {
                if (in_array($key, $setting_keys, true)) {
                    switch ($key) {
                        case 'post_types':
                        case 'rating_color_front':
                        case 'rating_color_back':
                        case 'rating_review':
                        case 'rating_display_on':
                            $fvalue = $value;
                            break;
                        case 'rating_text':
                        case 'button_text':
                        case 'rating_heading':
                            $fvalue = sanitize_text_field($value);
                            break;
                        case 'enable_pro_rating':
                        case 'show_star_rating':
                        case 'top_post_content':
                        case 'bottom_post_content':
                        case 'send_review_email':
                            $fvalue = (bool) $value;
                            break;
                        default:
                            $fvalue = sanitize_key($value);
                            break;
                    }
                    $options[$key] = $fvalue;
                }
            }
            update_option('awpa_pro_rating_settings', $options);
        }

        public function awpa_pro_api_post_rating_review()
        {
            if (!is_user_logged_in()) {
                wp_send_json_error(['message' => 'User not authenticated'], 403);
            }

            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rating_nonce')) {
                wp_send_json_error(['message' => 'Invalid nonce'], 403);
            }

            $params = $_POST;
            $post_id     = isset($params['post_id']) ? intval($params['post_id']) : 0;
            $value       = isset($params['value']) ? intval($params['value']) : 0;
            $review_type = isset($params['review_type']) ? sanitize_key($params['review_type']) : '';

            if (empty($post_id) || empty($review_type) || $value < 1 || $value > 5) {
                wp_send_json_error(['message' => 'Invalid Parameters'], 400);
            }

            $post = get_post($post_id);
            if (!$post) {
                wp_send_json_error(['message' => 'Invalid Post'], 404);
            }

            if (post_password_required($post)) {
                wp_send_json_error(['message' => 'Post is password protected'], 403);
            }

            if (get_post_status($post) !== 'publish' && !current_user_can('read_private_posts', $post_id)) {
                wp_send_json_error(['message' => 'You do not have permission to read this post'], 403);
            }

            $user_id         = get_current_user_id();
            $search_meta_key = 'awpa_pro_post_' . $review_type . '_rating_review';
            $post_meta       = get_post_meta($post_id, $search_meta_key, true);
            $rating_settings = get_option('awpa_pro_rating_settings', []);
            $send_mail       = !empty($rating_settings['send_review_email']);

            if ($review_type === '5_star') {
                $data            = is_array($post_meta) ? $post_meta : array();
                $target_key      = 'id_' . $user_id;

                if (!empty($data) && isset($data['ratings'])) {
                    if (isset($data['ratings'][$target_key])) {
                        $data['ratings'][$target_key] = (int) $value;

                        $people_count = array();
                        $countstarsValues = array_count_values($data['ratings']);
                        foreach ($countstarsValues as $k => $val) {
                            if (!empty($k) && !empty($val)) {
                                $people_count['count_' . $k] = $val;
                            }
                        }
                        $data['people_count'] = $people_count;

                        $sum = array_sum($data['ratings']);
                        $data['sum']   = (int) $sum;
                        $data['count'] = count($data['ratings']);
                        $data['avg']   = $data['count'] > 0 ? ((int) $sum / $data['count']) : 0;
                    } else {
                        $sum = array_sum($data['ratings']);
                        $data['count'] = isset($data['count']) ? $data['count'] + 1 : 1;
                        $data['sum']   = (int) $sum + (int) $value;
                        $data['avg']   = $data['count'] > 0 ? ($data['sum'] / $data['count']) : 0;
                        $data['ratings'][$target_key] = (int) $value;

                        $people_count = isset($data['people_count']) && is_array($data['people_count']) ? $data['people_count'] : array();
                        if (array_key_exists('count_' . $value, $people_count)) {
                            $people_count['count_' . $value] += 1;
                        } else {
                            $people_count['count_' . $value] = 1;
                        }
                        $data['people_count'] = $people_count;
                    }
                } else {
                    $data = array(
                        'ratings'      => array($target_key => (int) $value),
                        'sum'          => (int) $value,
                        'count'        => 1,
                        'avg'          => (float) $value,
                        'people_count' => array('count_' . $value => 1),
                    );
                }

                update_post_meta($post_id, "awpa_pro_post_" . $review_type . "_rating_review", $data);
                update_post_meta($post_id, 'awpa_top_rated_posts_' . $review_type, $data['avg']);
                update_post_meta($post_id, "awpa_pro_post_" . $review_type . "_rating_reviewed_user", $user_id);

                if ($send_mail) {
                    $user = get_user_by('id', $user_id);
                    if ($user) {
                        do_action('awpa_pro_rating_review_mail_notification', array(
                            'name'       => $user->user_nicename,
                            'email'      => $user->user_email,
                            'post_title' => $post->post_title,
                        ));
                    }
                }

                wp_send_json_success([
                    'rating'  => $data,
                    'message' => __('Your rating has been submitted.', 'wp-post-author'),
                ], 200);
            }
        }

        /**
         * Check if a user has reviewed a post.
         * Hardened to use standard WordPress Meta API (prevents SQL injection).
         */
        public function checkIfUserReviewedPost($post_id, $user_id, $review_type)
        {
            $post_id     = absint($post_id);
            $user_id     = absint($user_id);
            $review_type = sanitize_key($review_type);

            if ($post_id <= 0 || $user_id <= 0 || empty($review_type)) {
                return false;
            }

            $search_meta_key = 'awpa_pro_post_' . $review_type . '_rating_reviewed_user';
            $reviewed_user   = get_post_meta($post_id, $search_meta_key, true);

            return (int) $reviewed_user === $user_id;
        }
    }
}