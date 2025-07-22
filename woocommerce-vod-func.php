<?php
//---------------------------------------------------------
// Init
//---------------------------------------------------------
  add_action('init', 'woocommerce_vod_init');

  function woocommerce_vod_init() {
    global $wpdb;
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    $table_name = $wpdb->prefix . "woocommerce_vod";
    $SQL  = "CREATE TABLE wp_woocommerce_vod(";
    $SQL .= "id INT AUTO_INCREMENT NOT NULL PRIMARY KEY, ";
    $SQL .= "user_id INT NOT NULL, ";
    $SQL .= "product_id INT NOT NULL, ";
    $SQL .= "ts TIMESTAMP";
    $SQL .= ");";
    //dbDelta($SQL);
  }

  // Check user's membership level
  function member_is_level($level) {
    // Get USER ID
    $userid = get_current_user_id();

    // If Admin, pass them along
    if ($userid == 1) return true;

    // Get their level ID(s) & name(s)
    if(function_exists("get_user_levels_list")) {
      $lid = get_user_levels_list($userid);

      // If more than one level id, they will be separated by commas
      if(function_exists("ihc_get_level_by_id")) {
        $lids = explode(",", $lid);
        for ($i=0; $i < count($lids); $i++) {
          // Get the level details, including the name
          $levels = ihc_get_level_by_id($lids[$i]);

          // If the level matches the requested level, pass them along.  Otherwise, reject them.
          if ($levels['name'] == $level) return true;
        }
      }
    }
    // Nothing matched so reject them
    return false;
  }

//---------------------------------------------------------
// WooCommerce Hooks
//---------------------------------------------------------

  // Tell wordpress to accept additional variables in URL
  add_filter('query_vars', 'vod_parameter_queryvars' );
  function vod_parameter_queryvars( $qvars ) {
    $qvars[] = 'vid';
    return $qvars;
  }

  // make creating an account option on the checkout page checked by default
  add_filter('woocommerce_create_account_default_checked', 'woocommerce_checkout_page_is_checked');

  // when the payment is completed, process any VODs
  //add_action('woocommerce_order_status_completed', 'woocommerce_process_vod');
  add_action('woocommerce_payment_complete', 'woocommerce_process_vod');

  // Add the VOD table to the My Account page
  //add_action( 'woocommerce_after_my_account', 'woocommerce_my_account_vod_table', 9, 0 );


//---------------------------------------------------------
// Custom Functions
//---------------------------------------------------------
  function woocommerce_my_account_vod_table() {
    $OUT  = "";
    $OUT .= "<div class='wd_vods'>\n";
    $OUT .= "<h2 class='my-account-title'>My Video-On-Demands</h2>\n";
    $OUT .= "</div>\n";
    $OUT .= do_shortcode("[vod_customer_purchase_table]");
    $OUT .= "<br>\n";
    echo $OUT;
  }

  function woocommerce_checkout_page_is_checked($isChecked) {
    return true;
  }

  function woocommerce_process_vod($order_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . "woocommerce_vod";
    $is_vod = false;
    $num_items = 0;
    $num_vods = 0;

    // order object (optional but handy)
    $order = new WC_Order( $order_id );

    if ( count( $order->get_items() ) > 0 ) {
      // get user id
      $user_id = get_post_meta( $order_id, '_customer_user', true );

      // get user data
      $user = get_userdata( $user_id );
      $email = $user->billing_email;
      $name = $user->first_name." ".$user->last_name;

      // get order items
      $items = $order->get_items();

      // loop through each item in the order
      foreach( $items as $order_item_id => $item ) {
        $num_items++;
        $product = new WC_Product( $item['product_id'] );
        $productID = $item['product_id'];
        $productName = get_the_title($productID);

        // Check whether this product is a VOD or not?
        if (isset($item[variation_id])) {
          // This must be a variable product
          if ($item[pa_video_format] == "video_format_streaming") $is_vod = true;
          elseif ($item[pa_video_format] == "video_format_dvd_plus_streaming") $is_vod = true;
          $productID = $item[variation_id];
        } else {
          // Need to make sure the product is a VOD (based on product type)!
          $terms = get_the_terms( $item['product_id'], 'product_type' );
          foreach ($terms as $term) {
            $product_type = $term->name;
            if ($product_type == "vod") $is_vod = true;
            break;
          }
        }

        // If it is in fact a VOD, then do the following
        if ($is_vod) {
          $num_vods++;

          // retrieve video url
          //$video_url = get_option('siteurl')."/vod/?vid=".$productID;

          // insert into the VOD table
          if ($productID <> 0) {
            $INSERT  = "INSERT INTO ".$table_name." (user_id, product_id) VALUES (";
            $INSERT .= $user_id.",".$productID.");";
            //$INSERT .= get_current_user_id().",".$productID.");";
            $results = $wpdb->query($INSERT);

            // add note to order
            $order->add_order_note("Thank you for purchasing a Video-On-Demand video.  You may view your video by visiting the page where you purchased it.");
          }
        } // close if vod
      }  // close foreach order item
      // if all items in order are VODs then go ahead and complete the order since payment is already completed or this function would not have been called
      if (($num_items == $num_vods) && ($num_vods != 0)) $order->update_status('completed');
    } // close if count of items greater than zero
  }  // close woocommerce_process_vod

  // Shortcode function to get all customer VOD purchases and show in a table
  function get_vod_purchases_table() {
    global $wpdb;
    $user_id = get_current_user_id();
    $table_name = $wpdb->prefix . "woocommerce_vod";

    // Get all VOD purchases by the current user from product type: Video on Demand
    // CIs and above get to see all VODs

    if (member_is_level("certified-intructor-membership")) {
      $query_type = "CI";
      $number_posts = 99999;
      $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $number_posts,
            'orderby' => 'date',
            'order' => 'DESC',
            'tax_query' => array(
              array(
                'taxonomy' => 'product_type',
                'field' => 'name',
                'terms' => array('vod')
              )
            ),
            'numberposts' => $number_posts
      );
      $result = get_posts($args);
    } else {
      $query_type = "NORMAL";
      $SQL = "SELECT * FROM ".$table_name." WHERE user_id=".get_current_user_id();
      $result = $wpdb->get_results($SQL, ARRAY_A);
    }

    if (count($result) > 0) {
      for ($i=0; $i < count($result); $i++) {
        if ($query_type == "CI") {
          $product_id = $result[$i]->ID;
        } else {
          $product_id = $result[$i]['product_id'];
        }
        $product = new WC_Product($product_id);

        $thumb = wp_get_attachment_image_src( get_post_thumbnail_id($product_id), 'thumbnail_size' );
        $product_image = $thumb['0'];

        $excerpt = $product->post->post_excerpt;
        $description = $product->post->post_content;
        $title = $product->post->post_title;

        $duration = get_metadata('post', $product_id, "_duration_formatted", true);

        $view_link = get_post_permalink( $product->get_id(), false, false );

        $OUT .= "<div class='premier-thumbnail-block'>";
        $OUT .= "<div class='premier-thumbnail'>";
        $OUT .= "<a href='".$view_link."' class='premier-video'>";
        $OUT .= "<span></span>";  // for button overlay
        $OUT .= "<img src='".$product_image."' width=280 height=187 border=0>";
        $OUT .= "<div class='duration'>".$duration."</div>";
        if ($title) $OUT .= "<div class='premier-title-overlay'>".$title."</div>";
        $OUT .= "</a>";
        //if ($excerpt) $OUT .= "<h5>".$excerpt."</h5>";
        $OUT .= "</div>";
        $OUT .= "</div>";
      }
      $OUT .= "<div id='premier-footer'></div>";
    } else {
      $OUT .= "You do not seem to have any purchased Video-On-Demand videos or you are not logged in.<br><br>";
    }
    return $OUT;
  }
  add_shortcode('vod_customer_purchase_table', 'get_vod_purchases_table');

  // Shortcode function to retrieve VOD
  function view_vod() {
    global $wpdb, $wp_query;

    $table_name = $wpdb->prefix . "woocommerce_vod";
    $OUT = "";

    if (isset($wp_query->query_vars['vid'])) {
      $product_id = $wp_query->query_vars['vid'];
    }

    $current_user = get_current_user_id();

    $SQL = "SELECT * FROM ".$table_name." WHERE user_id=".$current_user." AND product_id=".$product_id;
    $result = $wpdb->get_results($SQL, ARRAY_A);

    if (count($result) > 0) {
      $product = new WC_Product($product_id);
      $post = $product->post;
      $product_image = $product->get_image(); // accepts 2 arguments ( size, attr )
      $excerpt = $post->post_excerpt;
      $description = $post->post_content;

      $product_post = $product->post;
      $title = apply_filters('the_name', $product_post->post_name);
      $thumbnail_url = get_post_meta( $product_post->get_id(), '_video_poster', true );
      $video_url = get_post_meta( $product_post->get_id(), '_video_url', true );

      $OUT .= "<div id='premier-video'>";
      if (member_is_level("certified-instructor-membership")) $download = "true"; else $downoad = "false";
      $download = "false";
      if($thumbnail_url) $OUT .= "<video poster='".$thumbnail_url."' controls preload='metadata'><source src='".$video_url."' type='video/mp4' data-res='Full'>Your browser does not support the video tag.</video>";
        elseif($video_url) $OUT .= "<video controls preload='metadata'><source src='".$video_url."' type='video/mp4' data-res='Full'>Your browser does not support the video tag.</video>";
      $OUT .= "</div>";
      $OUT .= "<div class='premier-video-details'>";
      if ($description) $OUT .= $description."<br>";
      $OUT .= "</div>";
    } else {
      $OUT .= "This does not seem to be a valid Video-On-Demand.";
    }
    return $OUT;
  }
  add_shortcode('vod_viewer', 'view_vod');


  // show category images on archives page
  add_action( 'woocommerce_archive_description', 'woocommerce_category_image', 2 );
  function woocommerce_category_image() {
    if ( is_product_category() ){
	    global $wp_query;
	    $cat = $wp_query->get_queried_object();
	    $thumbnail_id = get_woocommerce_term_meta( $cat->term_id, 'thumbnail_id', true );
	    $image = wp_get_attachment_url( $thumbnail_id );
	    if ( $image ) {
		    echo '<img src="' . $image . '" alt="" />';
		}
	}
  }

  // Show purchased VODs in table (from varable product)
  // Shortcode function to get all customer VOD purchases and show in a table
  function get_vod_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . "woocommerce_vod";
    $OUT = "";
    $user_id = get_current_user_id();

    // Get all VOD purchases by the current user
    // CIs and above get to see all VODs
    if (member_is_level("certified-instructor-membership")) {
      $query_type = "CI";
      $number_posts = 99999;
      $args = array(
            'post_type' => 'product_variation',
            'post_status' => 'publish',
            'posts_per_page' => $number_posts,
            'orderby' => 'post_title',
            'order' => 'ASC',
            'meta_query' => array(
              'relation' => 'AND',
              array(
                'key' => '_stock_status',
                'value' => 'instock',
                'compare' => '='
              ),
              array(
                'key' => 'attribute_pa_video_format',
                'value' => 'video_format_streaming',
                'compare' => '='
              ),
              array(
                'key' => '_wp_trash_meta_time',
                'compare' => 'NOT EXISTS',
                'value' => ''
              )
            ),
            'numberposts' => $number_posts
      );
      $result = get_posts($args);
    } else {
      $query_type = "NORMAL";
      $SQL = "SELECT * FROM ".$table_name." WHERE user_id=".get_current_user_id();
      // for testing: $SQL = "SELECT * FROM ".$table_name." WHERE user_id=12155";
      $result = $wpdb->get_results($SQL, ARRAY_A);
    }

    $videos = array();
    $count= 0;
    if (count($result) > 0) {
      for ($i=0; $i < count($result); $i++) {
        if ($query_type == "CI") {
          $product_id = $result[$i]->post_parent;
        } else {
          // Get the product id, which is actually the variation id and use it to get the parent product id
          $variation_id = $result[$i]['product_id'];
          $product_id = wp_get_post_parent_id($variation_id);
        }
        $product = new WC_Product($product_id);
        $thumb = wp_get_attachment_image_src( get_post_thumbnail_id($product_id), 'thumbnail_size' );
        $product_image = $thumb['0'];

        //$view_link = get_post_permalink( $product->post->ID, false, false );
        $view_link = get_post_permalink( $product->get_id(), false, false );
        $videos[$count]['title'] = $product->post->post_title;
        $videos[$count]['view_link'] = $view_link;
        $videos[$count]['product_image'] = $product_image;
        $count ++;
      }

      // Sort the videos
      usort($videos, "custom_sort");

      // display the sorted array of videos
      for ($i=0; $i < count($videos); $i++) {
        $OUT .= "<div class='vod-block'>";
        $OUT .= "<div class='vod-block-inner'>";
        $OUT .= "<a href='".$videos[$i]['view_link']."'>";
        $OUT .= "<img src='".$videos[$i]['product_image']."' height='250' alt='Click to view Video' border=0 alt=\"".$videos[$i]['title']."\" class='vod-thumbnail vod-grow'>";
        $OUT .= "</a>";
        $OUT .= "</div>";
        $OUT .= "<div class='vod-title'>".$videos[$i]['title']."</div>";
        $OUT .= "</div>";
     }
     $OUT .= "<br clear=all><br>";
    } else {
      $OUT .= "You do not seem to have any purchased Video-On-Demand videos or you are not logged in.<br><br>";
    }
    return $OUT;
  }
  add_shortcode('customer_vods_table', 'get_vod_table');


  // custom function for video sort
  function custom_sort($a,$b) {
     return $a['title'] > $b['title'];
  }
?>
