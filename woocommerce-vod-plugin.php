<?php
/*
Plugin Name: WooCommerce VOD
Plugin URI:
Description: Extends WooCommerce to allow for Video on Demand.
Author: DSI
Author URI: http://codex.wordpress.org
Version: 1.1.0
Groups: Custom, Video, e-Commerce
*/

  // include the functions
  $membership_plugin = plugin_dir_path( __FILE__ )."../indeed-membership-pro/indeed-membership-pro.php";
  if(file_exists($membership_plugin)) require_once($membership_plugin);
  include(plugin_dir_path( __FILE__ )."/woocommerce-vod-func.php");
  include(plugin_dir_path( __FILE__ )."/vod-admin.php");
  include(plugin_dir_path( __FILE__ )."/custom-fields.php");
  include(plugin_dir_path( __FILE__ )."/hide.php");
  include(plugin_dir_path( __FILE__ )."/product.php");
  include(plugin_dir_path( __FILE__ )."/product-type.php");
  include(plugin_dir_path( __FILE__ )."/my-account.php");
  include(plugin_dir_path( __FILE__ )."/admin.php");

  // Retrieve VOD Category ID from wp_options Table
  $option = get_option("vod_category_id");
  define ("VOD_CATEGORY_ID", $option['vod_category_id']);

  // Load stylesheet
  function wpse_vod_load_plugin_scripts() {
    // load plugin stylesheet
    wp_enqueue_style('vod', plugin_dir_url( __FILE__ ).'vod.css');
  }
  add_action('wp_enqueue_scripts', 'wpse_vod_load_plugin_scripts');
?>
