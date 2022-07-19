<?php
  // http://www.remicorson.com/mastering-woocommerce-products-custom-fields/

  //add_action('woocommerce_product_options_advanced', 'woo_add_custom_advanced_fields');
  add_action('woocommerce_product_data_panels', 'woo_add_custom_advanced_fields');
  function woo_add_custom_advanced_fields() {
    Global $post;
    echo "<div id='vod_options' class='panel woocommerce_options_panel'>";
    echo '<div class="options_group">';
    // Text Field
    woocommerce_wp_text_input(
	array(
		'id'          => '_video_url',
		'label'       => __( 'Video URL', 'woocommerce' ),
		'placeholder' => 'http://path/to/video.mp4',
		'desc_tip'    => 'true',
		'description' => __( 'Enter the URL for a Video-On-Demand video here.', 'woocommerce' )
	)
    );

    woocommerce_wp_text_input(
	array(
		'id'          => '_video_poster',
		'label'       => __( 'Video Poster', 'woocommerce' ),
		'placeholder' => 'http://path/to/poster.jpg',
		'desc_tip'    => 'true',
		'description' => __( 'Enter the URL for the poster image for a Video-On-Demand video here.', 'woocommerce' )
	)
    );

    woocommerce_wp_text_input(
	array(
		'id'          => '_duration_formatted',
		'label'       => __( 'Video Duration', 'woocommerce' ),
		'placeholder' => 'HH:MM:SS',
		'desc_tip'    => 'true',
		'description' => __( 'Enter the duration of the Video-On-Demand video here.', 'woocommerce' )
	)
    );

    echo "</div>";
    echo "</div>";
  }

  add_action('woocommerce_process_product_meta', 'woo_add_custom_advanced_fields_save');
  function woo_add_custom_advanced_fields_save($post_id){
    if(!empty( $_POST['_video_url'])) update_post_meta( $post_id, '_video_url', esc_attr( $_POST['_video_url'] ) );
      else delete_post_meta( $post_id, '_video_url' );
    if(!empty( $_POST['_video_poster'])) update_post_meta( $post_id, '_video_poster', esc_attr( $_POST['_video_poster'] ) );
      else delete_post_meta( $post_id, '_video_poster' );
    if(!empty( $_POST['_duration_formatted'])) update_post_meta( $post_id, '_duration_formatted', esc_attr( $_POST['_duration_formatted'] ) );
      else delete_post_meta( $post_id, '_duration_formatted' );
  }
?>
