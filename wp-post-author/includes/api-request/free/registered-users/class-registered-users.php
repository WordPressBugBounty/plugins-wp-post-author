<?php
if (!class_exists('Awpa_Registered_Users_Rest_Controller')) {
  class Awpa_Registered_Users_Rest_Controller
  {

    private $namespace;
    public function __construct()
    {
      $this->namespace = 'aft-wp-post-author/v1';
    }
    private function awpa_get_orderby_map()
    {
      global $wpdb;
      $subs  = $wpdb->prefix . 'wpa_subscriptions';
      $users = $wpdb->users;

      return array(
        'user_id'         => "$subs.user_id",
        'membership_type' => "$subs.membership_type",
        'user_email'      => "$users.user_email",
        'display_name'    => "$users.display_name",
        'user_nicename'   => "$users.user_nicename",
        'user_registered' => "$users.user_registered",
      );
    }

    public function awpa_registered_user_register_routes()
    {
      register_rest_route(
        $this->namespace,
        '/membership-listing',
        array(
          array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'awpa_registered_users_listing'),
            'permission_callback' => array($this, 'awpa_permission_check'),
            'args'                => array(
              'per_page' => array(
                'type'              => 'integer',
                'default'           => 10,
                'minimum'           => 1,
                'maximum'           => 100,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
              ),
              'page' => array(
                'type'              => 'integer',
                'default'           => 1,
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
              ),
              'order_by' => array(
                'type'              => 'string',
                'default'           => 'user_id',
                'enum'              => array_keys($this->awpa_get_orderby_map()),
                'validate_callback' => 'rest_validate_request_arg',
              ),
              'order' => array(
                'type'              => 'string',
                'default'           => 'DESC',
                'sanitize_callback' => 'strtoupper',
                'validate_callback' => function ($value) {
                  return in_array(strtoupper((string) $value), array('ASC', 'DESC'), true);
                },
              ),
              'search' => array(
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => 'rest_validate_request_arg',
              ),
            ),

          ),
        )
      );
    }

    public function awpa_permission_check($request)
    {
      return current_user_can('manage_options');
    }

    public function awpa_registered_users_listing(\WP_REST_Request $request)
    {
      global $wpdb;

      // Re-validate here as well, so the method is safe even if called
      // outside the REST layer or if the route args are ever removed.
      $per_page = min(100, max(1, absint($request->get_param('per_page') ?: 10)));
      $page     = max(1, absint($request->get_param('page') ?: 1));
      $offset   = ($page - 1) * $per_page;

      $orderby_map = $this->awpa_get_orderby_map();
      $orderby_key = (string) $request->get_param('order_by');
      $orderby_sql = isset($orderby_map[$orderby_key]) ? $orderby_map[$orderby_key] : $orderby_map['user_id'];

      $order = strtoupper((string) $request->get_param('order'));
      $order = ($order === 'ASC') ? 'ASC' : 'DESC';

      $search_term = sanitize_text_field((string) $request->get_param('search'));

      $subscription_table = $wpdb->prefix . 'wpa_subscriptions';
      $users              = $wpdb->users;

      $where      = '';
      $where_args = array();

      if ($search_term !== '') {
        $like  = '%' . $wpdb->esc_like($search_term) . '%';
        $where = "WHERE ($users.user_email LIKE %s
                    OR $users.display_name LIKE %s
                    OR $users.user_nicename LIKE %s
                    OR $subscription_table.membership_type LIKE %s)";
        $where_args = array($like, $like, $like, $like);
      }

      $total_query = "SELECT COUNT(*) FROM $subscription_table
                INNER JOIN $users ON $users.ID = $subscription_table.user_id
                $where";

      // Explicit user columns: never select user_pass or user_activation_key.
      $query = "SELECT $subscription_table.*,
                    $users.ID, $users.user_login, $users.user_nicename, $users.user_email,
                    $users.user_url, $users.user_registered, $users.display_name
                FROM $subscription_table
                INNER JOIN $users ON $users.ID = $subscription_table.user_id
                $where
                ORDER BY $orderby_sql $order
                LIMIT %d, %d";

      $total_args = $where_args;
      $query_args = array_merge($where_args, array($offset, $per_page));

      if (!empty($total_args)) {
        $total_query = $wpdb->prepare($total_query, $total_args);
      }
      $query = $wpdb->prepare($query, $query_args);

      $response = array();
      $response['posts_count'] = (int) $wpdb->get_var($total_query);

      $posts = $wpdb->get_results($query, OBJECT);
      foreach ($posts as $key => $post) {
        $userdata = get_userdata($post->user_id);
        $posts[$key]->author   = ucfirst(get_the_author_meta('display_name', $post->user_id));
        $posts[$key]->role     = $userdata ? $userdata->roles : array();
        $posts[$key]->nickname = get_user_meta($post->user_id, 'nickname', true);
      }

      $response['posts'] = $posts;
      $response['membership_addon_active'] = class_exists('WP_Post_Author_Membership_Plans_Addon');

      return $response;
    }
  }
}
