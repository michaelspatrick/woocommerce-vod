<?php
  // remove add to cart for VOD that have been purchased
  function vod_woocommerce_is_purchasable($is_purchasable, $product) {
    Global $wpdb;

    // Check whether this product is a VOD or not
    $is_vod = false;
    $is_bundle = false;

    $terms = get_the_terms( $product->get_id(), 'product_type' );
    if ($terms && ! is_wp_error($terms)) {
      foreach ($terms as $term) {
        $product_type = $term->name;

        if ($product_type == "bundle") {
          $is_vod = false;
          $is_bundle = true;
        } elseif ($product_type == "vod") {
          // the product type is VOD
          $is_vod = true;
        } elseif ($product_type == "variable") {
          // If this is a variable product, we need to check the attributes
          $terms = get_the_terms( $product->get_id(), 'pa_video_format' );
          if ($terms && ! is_wp_error($terms)) {
            foreach ($terms as $term) {
              $video_url = get_post_meta( $product->get_id(), '_video_url', true );
              if ($video_url == "") $is_vod = false;
              if ($term->name == "Streaming") $is_vod = true;
            }
          }
        }
      }
    }

    if ($is_vod && (!$is_bundle)) {
      // Look for product in table associated with this user_id
      $current_user = wp_get_current_user();
      $user_id = get_current_user_id();
      $table_name = $wpdb->prefix . "woocommerce_vod";
      $purchased = false;

      // CIs and above get to see all VODs
      if (member_is_level("certified-instructor-membership")) {
        $purchased = true;
      } else {
        // If not a CI or above, check vod purchase table to see if this user bought it
        // Treat variable products a little different
        if ( $product->is_type( 'variable' ) ) {
          //look for any children if it is a variable product
          $my_str = "AND product_id IN (";
          $children = $product->get_children();
          if (count($children) > 0) {
            $my_str .= implode(",", $children);
            $and_str = $my_str.")";
          }
        } else {
          $and_str = "AND product_id=".$product->get_id();
        }

        $SQL = "SELECT COUNT(*) FROM ".$table_name." WHERE user_id=".$current_user->ID." ".$and_str.";";
        $count = $wpdb->get_var($SQL);
        if ($count == 0) $purchased = false; else $purchased = true;
      }

      if ($purchased) {
        // Display the Video tab and default it
        add_filter( 'woocommerce_product_tabs', 'woo_vod_product_tab' );
        return true;
      } else {
        // let the system determine whether product can be purchased
        return $is_purchasable;
      }
    } else {
      // let the system determine whether product can be purchased
      return true;
    }  // end is vod
  }
  add_filter('woocommerce_is_purchasable', 'vod_woocommerce_is_purchasable', 10, 2);

  // Remove unwanted product tabs
  function woo_remove_product_tabs( $tabs ) {
    unset( $tabs['additional_information'] );  	// Remove the additional information tab
    unset( $tabs['wd_custom'] );
    return $tabs;
  }
  add_filter( 'woocommerce_product_tabs', 'woo_remove_product_tabs', 98 );
?>
