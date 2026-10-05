<?php
if (!class_exists('Awpa_Multiauthor_Rest_Controller')) {
  class Awpa_Multiauthor_Rest_Controller
  {

    private $namespace;
    public function __construct()
    {
      $this->namespace = 'aft-wp-post-author/v1';
    }

    public function awpa_multiahuthors_register_routes()
    {
      register_rest_route(
        $this->namespace,
        '/list-guest-authors',
        array(
          array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'awpa_list_guest_authors'),
            'permission_callback' => array($this, 'awpa_permission_check'),

          ),
        )
      );

      register_rest_route(
        $this->namespace,
        '/new-guest-author',
        array(
          array(
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => array($this, 'awpa_add_new_guest_author'),
            'permission_callback' => array($this, 'awpa_permission_check'),

          ),
        )
      );

      register_rest_route(
        $this->namespace,
        '/status-change-membership-plan',
        array(
          array(
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => array($this, 'awpa_new_guest_link_to_user'),
            'permission_callback' => function ($request) {
              $nonce = $request->get_header('X-WP-Nonce');
              if (! wp_verify_nonce($nonce, 'wp_rest')) {
                return new WP_Error('rest_forbidden', 'Validation Failed', array('status' => 200));
              }

              return true;
            }

          ),
        )
      );

      register_rest_route(
        $this->namespace,
        '/delete-guest-author',
        array(
          array(
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => array($this, 'awpa_delete_guest_author'),
            'permission_callback' => array($this, 'awpa_permission_check'),

          ),
        )
      );

      register_rest_route(
        $this->namespace,
        '/get-users',
        array(
          array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'awpa_get_user'),
            'permission_callback' => array($this, 'awpa_permission_check'),

          ),
        )
      );
    }


    public function awpa_permission_check(WP_REST_Request $request)
    {
      $nonce = $request->get_header('X-WP-Nonce');

      if (!$nonce) {
        return new WP_Error(
          'rest_missing_nonce',
          __('Nonce is required.', 'wp-post-author'),
          ['status' => 403]
        );
      }

      if (!wp_verify_nonce($nonce, 'wp_rest')) {
        return new WP_Error(
          'rest_invalid_nonce',
          __('Invalid nonce.', 'wp-post-author'),
          ['status' => 403]
        );
      }

      if (!current_user_can('manage_options')) {
        return new WP_Error(
          'rest_forbidden',
          __('You do not have permission to access this endpoint.', 'wp-post-author'),
          ['status' => 403]
        );
      }

      return true;
    }

    public function awpa_list_guest_authors(\WP_REST_Request $request)
    {
      $authors_per_page = sanitize_text_field($request['per_page']);
      $paged = sanitize_text_field($request['page']);
      $orderby = sanitize_text_field($request['order_by']);
      $order = sanitize_text_field($request['order']);
      $search_term = sanitize_text_field($request['search']);
      $page = isset($paged) ? abs((int) $paged) : 1;
      $offset = (int) ($page * $authors_per_page) - $authors_per_page;

      $guest_authors = get_users(
        array(
          //'role__in' =>array( 'awpa-guest' ),
          // 'role__not_in'=>'administrator',
          'orderby' => $orderby,
          'order' => $order,
          'number' => $authors_per_page,
          'search' => "*" . $search_term . "*",
          'offset' => $offset,
          'paged' => $page

        )
      );


      $guest_authorss = [];
      foreach ($guest_authors as  $guest_author) {
        $user_info = get_userdata($guest_author->ID);
        $user_roles = $user_info->roles;
        $guest_authorss[] = array(
          'id' => $guest_author->ID,
          'display_name' => $guest_author->display_name,
          'user_email' => $guest_author->user_email,
          'user_registered' => $guest_author->user_registered,
          'is_active' => $guest_author->is_active,
          'first_name' => $guest_author->first_name,
          'last_name' => $guest_author->last_name,
          'description' => $guest_author->description,
          'user_role' => $user_roles[0],
          'user_meta' => array(
            'facebook' => get_user_meta($guest_author->ID, 'awpa_contact_facebook', true),
            'instagram' => get_user_meta($guest_author->ID, 'awpa_contact_instagram', true),
            'youtube' => get_user_meta($guest_author->ID, 'awpa_contact_youtube', true),
            'twitter' => get_user_meta($guest_author->ID, 'awpa_contact_twitter', true),
            'linkedin' => get_user_meta($guest_author->ID, 'awpa_contact_linkedin', true),
            'website' => get_user_meta($guest_author->ID, 'website', true),
          )


        );
      }

      $response['guest_authors'] = $guest_authorss;
      $response['guest_authors_count'] = $page;
      $response['wp_upload_dir'] = wp_upload_dir();

      return $response;
    }

    public function awpa_add_new_guest_author(\WP_REST_Request $request)
    {
      $params = $request->get_params();
      $guest_author = json_decode($params['guest_author'], true);
      $view = $request['view'];

      $guest = get_user_by('email', $guest_author['user_email']);


      if ($view == 'new') {
        $require_input = array(
          'user_email',
          'display_name',
          'first_name',
          'last_name',
          //'is_active',
          'linked_user_id',
          'convert_guest_to_author'
        );
        $error = false;
        $error_message = array();
        foreach ($require_input as $key => $input) {
          if (!array_key_exists($input, $guest_author) || $guest_author[$input] === "") {
            $error = true;
            $string = str_replace('_', ' ', $input);
            $string = strtolower($string);
            $string = ucfirst($string);
            $error_message[] = array(
              'key' => $input,
              'value' => sprintf(__('%s is required', 'wp-post-author'), $string)
            );
          }
          if ($input == 'user_email') {
            if (!is_email($guest_author['user_email'])) {
              $error = true;
              $error_message[] = array(
                'key' => $input,
                'value' => __('Not valid email', 'wp-post-author')
              );
            }
          }
        }


        $user_email_exists  = get_user_by('email', $guest_author['user_email']);

        if ($user_email_exists) {
          $error = true;
          $error_message[] = array(
            'key' => 'user_email',
            'value' => __('Guest email registered, please use different email', 'wp-post-author'),
          );
        }
        //check if meta has valid URL if inputted
        foreach ($guest_author['user_meta'] as $key => $value) {
          if ($value || $value == '0') {
            if (!filter_var($value, FILTER_VALIDATE_URL)) {
              $error = true;
              $error_message[] = array(
                'key' => $key,
                'value' => __('Not valid URL', 'wp-post-author')
              );
            }
          }
        }
        if ($error) {
          return array(
            'message' => __('Input missing', 'wp-post-author'),
            'data' => $error_message,
            'status'  => 424
          );
        }
        $user_type  = $guest_author['user_type'];


        $email_exists  = email_exists($guest_author['user_email']);
        if (!$email_exists) {

          $nicename  =  $guest_author['display_name'];
          $author_exists = get_user_by('user_nicename', $nicename);
          $nicename = $author_exists ? $nicename  : $nicename;
          if (isset($_FILES['image']) && $_FILES['image'] && $_FILES['image']['size'] != 0) {
            $file_name = $_FILES['image']['name'];
            $path_parts = pathinfo($file_name);
            $image_name = strtotime('now') . "." . $path_parts['extension'];
            $_FILES['image']['name'] = $image_name;
          }


          $user_id = wp_create_user(
            $guest_author['user_login'] ?
              sanitize_text_field($guest_author['user_login']) :
              sanitize_email($guest_author['user_email']),
            wp_generate_password(8),
            sanitize_email($guest_author['user_email'])

          );

          if ($user_id) {
            $user = new WP_User($user_id);
            $user_role  = $guest_author['user_role'] ? $guest_author['user_role'] : 'awpa-guest';
            $user->remove_role('subscriber');
            $user->remove_role('adminstrator');
            $user->remove_role('editor');
            $user->remove_role('author');
            $user->remove_role('translator');
            $user->remove_role('contributor');
            $user->remove_role('awpa-guest');
            $user->set_role($user_role);
            if (array_key_exists('display_name', $guest_author)) {
              wp_update_user(['ID' => $user_id, 'display_name' => sanitize_text_field($guest_author['display_name'])]);
            }

            if (array_key_exists('first_name', $guest_author)) {
              update_user_meta($user_id, 'first_name', sanitize_text_field($guest_author['first_name']));
            }
            if (array_key_exists('last_name', $guest_author)) {
              update_user_meta($user_id, 'last_name', sanitize_text_field($guest_author['last_name']));
            }
            if (array_key_exists('description', $guest_author)) {
              update_user_meta($user_id, 'description', sanitize_text_field($guest_author['description']));
            }
            if (array_key_exists('is_active', $guest_author)) {
              update_user_meta($user_id, 'is_active', sanitize_text_field($guest_author['is_active']));
            }
            foreach ($guest_author['user_meta'] as $key => $value) {
              if ($value || $value == '0') {
                if ($key == 'website') {
                  wp_update_user(array('ID' => $user_id, 'user_url' => sanitize_url($value)));
                  update_user_meta($user_id, 'website', sanitize_url($value));
                } else {
                  update_user_meta($user_id, 'awpa_contact_' . $key, $value);
                }
              }
            }
            return array(
              'message' => __('New guest created', 'wp-post-author'),
              'status'  => 200
            );
          }
        } else {
          return array(
            'message' => __('User guest email already registered, please use different email!', 'wp-post-author'),
            'data' => array(),
            'status'  => 424
          );
        }
      }

      if ('edit' == $view) {
        $require_input = array(
          'display_name',
          'first_name',
          'last_name',
          'user_email'
        );

        $guestdata = get_userdata($guest_author['id']);
        $guest =  $guestdata->data;

        $error = false;
        $error_message = array();
        foreach ($require_input as $key => $input) {
          if (!array_key_exists($input, $guest_author) || $guest_author[$input] === "") {
            $error = true;
            $string = str_replace('_', ' ', $input);
            $string = strtolower($string);
            $string = ucfirst($string);
            $error_message[] = array(
              'key' => $input,
              'value' => sprintf(__('%s is required', 'wp-post-author'), $string)
            );
          }
          if ($input == 'user_email') {
            if (!is_email($guest_author['user_email'])) {
              $error = true;
              $error_message[] = array(
                'key' => $input,
                'value' => __('Not valid email', 'wp-post-author')
              );
            }
          }
        }
        foreach ($guest_author['user_meta'] as $key => $value) {


          if ($value || $value == '0') {
            if (!filter_var($value, FILTER_VALIDATE_URL)) {
              $error = true;
              $error_message[] = array(
                'key' => $key,
                'value' => __('Not valid URL', 'wp-post-author')
              );
            }
          }
        }
        if ($error) {
          return array(
            'message' => __('Input missing', 'wp-post-author'),
            'data' => $error_message,
            'status'  => 424
          );
        }
        $user_email_exists = email_exists($guest_author['user_email']);
        if ($user_email_exists) {
          $new_data = array(
            'ID' => $guest_author['id'],
            'display_name' => $guest_author['display_name']
          );
        } else {
          $new_data['user_email'] = $guest_author['user_email'];
        }


        $result = wp_update_user($new_data);
        $user = new WP_User($guest_author['id']);
        $user->set_role($guest_author['user_role']);
        $fname = ($guest_author['first_name']) ? sanitize_text_field($guest_author['first_name']) : $guest->first_name;
        $lname = ($guest_author['last_name']) ? sanitize_text_field($guest_author['last_name']) : $guest->last_name;
        $desc = ($guest_author['description']) ? sanitize_text_field($guest_author['description']) : $guest->description;
        $is_active = ($guest_author['is_active']) ? sanitize_text_field($guest_author['is_active']) : $guest->is_active;

        if (array_key_exists('first_name', $guest_author)) {

          update_user_meta($guest_author['id'], 'first_name', $fname);
        }
        if (array_key_exists('last_name', $guest_author)) {
          update_user_meta($guest_author['id'], 'last_name', $lname);
        }
        if (array_key_exists('description', $guest_author)) {
          update_user_meta($guest_author['id'], 'description', $desc);
        }

        if (array_key_exists('is_active', $guest_author)) {
          update_user_meta($guest_author['id'], 'is_active', $is_active);
        }

        foreach ($guest_author['user_meta'] as $key => $value) {

          if ($value !== '0') {
            if ($key == 'website') {
              wp_update_user(array('ID' => $guest_author['id'], 'user_url' => sanitize_url($value)));
              update_user_meta($user_id, 'website', sanitize_url($value));
            } else {
              update_user_meta($guest_author['id'], 'awpa_contact_' . $key, sanitize_text_field($value));
            }
          }
        }

        return array(
          'message' => $result ? __('Guest updated', 'wp-post-author')  : __('Error occured', 'wp-post-author'),
          'status'  => 200
        );
      }
    }

    public function awpa_new_guest_link_to_user(\WP_REST_Request $request)
    {
      $params = $request->get_params();
      $plan_id = absint(array_key_exists('plan_id', $params) ? $params['plan_id'] : 0);
      $status = sanitize_text_field($params['status']);
      global $wpdb;
      $table_name = $wpdb->prefix . "wpa_membership_plan";
      $dbpost = $wpdb->query($wpdb->prepare("UPDATE " . $table_name . " SET status = %d WHERE id = %d", $status, $plan_id));
      return $dbpost;
    }

    public function awpa_delete_guest_author(\WP_REST_Request $request)
    {

      $params = $request->get_params();

      require_once(ABSPATH . 'wp-admin/includes/user.php');
      $current_id = $params['guest_id'];

      return  wp_delete_user($current_id);
    }

    public function awpa_get_user()
    {
      $args = array(
        // 'role__in' => array('author', 'contributor', 'editor', 'subscriber'),
        'role__in' => array('author', 'editor', 'awpa-guest'),
        'fields' => array('ID', 'display_name', 'user_nicename', 'user_login'),
      );
      $response['users'] = get_users($args);

      return $response;
    }

    public function awpa_guest_avatar_upload_dir($dir)
    {
      $awpadir = '/wpa-post-author/guest-avatar';
      $dir['path'] = $dir['basedir'] . $awpadir;
      $dir['url'] = $dir['baseurl'] . $awpadir;
      return $dir;
    }
  }
}
