<?php
  // https://iconicwp.com/blog/add-custom-page-account-area-woocommerce/

  /**
  * Account menu items
  *
  * @param arr $items
  * @return arr
  */


  function vod_account_menu_items( $items ) {
    //$items['vod'] = __( 'Streaming Video', 'dsi' );
    $menu_position = 5;
    $items = array_slice( $items, 0, $menu_position, true ) 
	+ array( 'vod' => 'Streaming Video' )
	+ array_slice( $items, $menu_position, NULL, true );
    return $items;
  }
  add_filter( 'woocommerce_account_menu_items', 'vod_account_menu_items', 10, 1 );

  /**
  * Add endpoint
  */
  function vod_add_my_account_endpoint() {
    add_rewrite_endpoint( 'vod', EP_PAGES );
  }
  add_action( 'init', 'vod_add_my_account_endpoint' );

  /**
  * Information content
  */
  function dsi_vod_endpoint_content() {
    echo do_shortcode("[customer_vods_table]");
  }
  add_action( 'woocommerce_account_vod_endpoint', 'dsi_vod_endpoint_content' );


  // change Type to Author
  // Replaces the My Content "Type" column with an "Author" column
  function sv_members_area_content_table_columns( $columns ) {
    // unset the "type" column, which shows post, page, etc
    unset( $columns['membership-content-type'] );
    $new_columns = array();
    foreach( $columns as $column_id => $column_name ) {
        $new_columns[$column_id] = $column_name;
        // insert our new column after the "Title" column
        if ( 'membership-content-title' === $column_id ) {
            $new_columns['membership-content-author'] = __( 'Author', 'my-theme-text-domain' );
        }
    }
    return $new_columns;
  }
  add_filter( 'wc_memberships_members_area_my_membership_content_column_names', 'sv_members_area_content_table_columns', 11 );

  // Fills the "Author" column with the post author
  function sv_members_area_content_author( $post ) {
    $author = get_user_by( 'ID', $post->post_author );
    echo $author->display_name;
  }
  add_action( 'wc_memberships_members_area_my_membership_content_column_membership-content-author', 'sv_members_area_content_author' );

  // Add Streaming Video to Membership Tab
  function my_custom_members_area_sections( $sections ) {
    $sections['streaming'] = __( 'Streaming Video', 'my-textdomain' );
    return $sections;
  }
  add_filter( 'wc_membership_plan_members_area_sections', 'my_custom_members_area_sections' );
?>
