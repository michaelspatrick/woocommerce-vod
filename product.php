<?php
  // Display Video tab for VOD product
  function woo_vod_product_tab( $tabs ) {
    // Adds the new tab
    $tabs['vod_tab'] = array(
	'title' 	=> __('Streaming Video', 'woocommerce'),
	'priority' 	=> 1,
	'callback' 	=> 'woo_vod_product_tab_content'
    );
    return $tabs;
  }

  // Content for Video Tab for VOD product
  function woo_vod_product_tab_content() {
    // This function does not check whether the user owns the video or not since that is checked in hide.php
    Global $product;

    $user_id = get_current_user_id();

    // Get the video URL based on $product->ID;
    $video_url = get_post_meta( $product->get_id(), '_video_url', true );
    $poster_url = get_post_meta( $product->get_id(), '_video_poster', true );
    $title = apply_filters('the_name', $product->post->post_name);
    if ($video_url) {
      echo '<h2>View the Video</h2>';
      $OUT  = "<div id='vod-video'>";
      if($poster_url) $OUT .= "<video poster='".$poster_url."' controls preload='metadata'><source src='".$video_url."' type='video/mp4' data-res='Full'>Your browser does not support the video tag.</video>";
        else $OUT .= "<video controls preload='metadata'><source src='".$video_url."' type='video/mp4' data-res='Full'>Your browser does not support the video tag.</video>";
      $OUT .= "</div>";

      if (member_is_level("certified-instructor-membership")) {
      // prepare a link for download
        $f = rtrim(str_replace(array('+', '/'), array('-', '_'), base64_encode($video_url)), '=');
      }
    }
    $OUT .= "Be sure to review the video when you finish watching it.  We appreciate your feedback as it helps us provide the content you want and need!";
    echo $OUT;
  }

  // The hook in function $availability is passed via the filter!
  function custom_override_get_availability( $availability, $_product ) {
    if ( $_product->is_in_stock() ) $availability['availability'] = __('In Stock', 'woocommerce');
    return $availability;
  }

  // Show the add to cart button (works)
  if (! function_exists('woocommerce_vod_add_to_cart') ) {
    function vod_add_to_cart() {
      wc_get_template('single-product/add-to-cart/simple.php');
    }
    add_action('woocommerce_vod_add_to_cart',  'vod_add_to_cart');
  }
?>
