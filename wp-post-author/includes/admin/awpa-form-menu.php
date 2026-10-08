<?php

/**
 * Implement plugin menu.
 *
 * @package CoverNews
 */

//Exit if directly acess
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Add new registration menu items.
 */
function awpa_settings_menu($hook)
{

  add_submenu_page(
    'wp-post-author',
    __('Dashboard', 'wp-post-author'),
    __('Dashboard', 'wp-post-author'),
    'manage_options',
    'wp-post-author',
    'awpa_settings_page'
  );

  add_submenu_page(
    'wp-post-author',
    __('Form Builder', 'wp-post-author'),
    __('Form Builder', 'wp-post-author'),
    'manage_options',
    'awpa-registration-form',
    'awpa_user_registration_page'
  );

  $author_metabox = awpa_get_author_metabox_setting();
  if ($author_metabox && $author_metabox['enable_author_metabox']) {
    add_submenu_page(
      'wp-post-author',
      __('All Users', 'wp-post-author'),
      __('All Users', 'wp-post-author'),
      'manage_options',
      'awpa-multi-authors',
      'awpa_multi_authors_page'
    );
  }

  add_submenu_page(
    'wp-post-author',
    __('Free Themes ↗', 'wp-post-author'),
    __('Free Themes ↗', 'wp-post-author'),
    'manage_options',
    esc_url('https://afthemes.com/products/category/free/')
  );
  add_submenu_page(
    'wp-post-author',
    __('Pro Themes ↗', 'wp-post-author'),
    __('Pro Themes ↗', 'wp-post-author'),
    'manage_options',
    esc_url('https://afthemes.com/products/category/pro/')
  );

  add_submenu_page(
    'wp-post-author',
    __('Starter Sites ↗', 'wp-post-author'),
    __('Starter Sites ↗', 'wp-post-author'),
    'manage_options',
    esc_url('https://afthemes.com/starter-sites/')
  );
  
}
add_action('admin_menu', 'awpa_settings_menu', 60);


function awpa_settings_page()
{
?><br />
  <div id="afwrap-react"></div>
<?php
}
function awpa_user_registration_page()
{
?>
  <div id="awpa-actions" data-for="textarea"></div>
  <div id="awpa-form-listing" class="awpa-all-forms-container">
  </div>
<?php
}

function awpa_add_registration_page()
{
?>
  <div id="awpa-form-builder-container" class="awpa-form-builder-container"></div>
<?php
}




function awpa_multi_authors_page()
{
?>
  <div id="awpa-guest-authors" class="awpa-multi-authors"></div>
<?php
}
