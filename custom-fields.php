<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_filter( 'woocommerce_product_class', function( $classname, $product_type ) {
    if ( $product_type === 'vod' ) {
        $classname = 'WC_Product_VOD';
    }
    return $classname;
}, 10, 2 );

if ( ! class_exists( 'WC_Product_VOD' ) ) {
    class WC_Product_VOD extends WC_Product_Simple { public function get_type() { return 'vod'; } }
}

/**
 * Add VOD tab.
 */
add_filter( 'woocommerce_product_data_tabs', function( $tabs ) {
    $tabs['vod_data'] = array(
        'label'    => __( 'VOD', 'woocommerce_vod' ),
        'target'   => 'dsi_vod_product_data',
        'class'    => array( 'show_if_vod' ),
        'priority' => 50,
    );
    return $tabs;
} );

/**
 * VOD panel contents (fields preserved).
 */
add_action( 'woocommerce_product_data_panels', function () {
    echo '<div id="dsi_vod_product_data" class="panel woocommerce_options_panel hidden">';
    echo '<div class="options_group">';

    woocommerce_wp_text_input( array(
    'id' => '_video_url',
    'label' => __( 'Video URL', 'woocommerce_vod' ),
    'desc_tip' => true,
    'description' => __( '', 'woocommerce_vod' ),
    'wrapper_class' => 'show_if_vod',
) );

    woocommerce_wp_text_input( array(
    'id' => '_video_poster',
    'label' => __( 'Video Poster', 'woocommerce_vod' ),
    'desc_tip' => true,
    'description' => __( '', 'woocommerce_vod' ),
    'wrapper_class' => 'show_if_vod',
) );

    woocommerce_wp_text_input( array(
    'id' => '_duration_formatted',
    'label' => __( 'Duration (formatted HH:MM:SS or MM:SS)', 'woocommerce_vod' ),
    'desc_tip' => true,
    'description' => __( '', 'woocommerce_vod' ),
    'wrapper_class' => 'show_if_vod',
) );

    echo '</div>';
    echo '</div>';
} );

/**
 * Save using product CRUD (HPOS-safe).
 */
add_action( 'woocommerce_admin_process_product_object', function( $product ) {
    if ( $product->get_type() !== 'vod' ) { return; }
    $product->update_meta_data( '_video_url', isset( $_POST['_video_url'] ) ? esc_url_raw( wp_unslash( $_POST['_video_url'] ) ) : '' );
    $product->update_meta_data( '_video_poster', isset( $_POST['_video_poster'] ) ? sanitize_text_field( wp_unslash( $_POST['_video_poster'] ) ) : '' );
    $product->update_meta_data( '_duration_formatted', isset( $_POST['_duration_formatted'] ) ? sanitize_text_field( wp_unslash( $_POST['_duration_formatted'] ) ) : '' );
}, 10, 1 );

/**
 * Optional: expose meta in REST.
 */
add_action( 'init', function() {
    register_post_meta( 'product', '_video_url', array('single'=>true,'type'=>'string','show_in_rest'=>true,'auth_callback'=>function(){return current_user_can('manage_woocommerce');},) );
    register_post_meta( 'product', '_video_poster', array('single'=>true,'type'=>'string','show_in_rest'=>true,'auth_callback'=>function(){return current_user_can('manage_woocommerce');},) );
    register_post_meta( 'product', '_duration_formatted', array('single'=>true,'type'=>'string','show_in_rest'=>true,'auth_callback'=>function(){return current_user_can('manage_woocommerce');},) );
} );
?>
