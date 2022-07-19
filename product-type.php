<?php
/*
  // add a product type
  function add_vod_product_type( $types ){
    $types[ 'vod' ] = __( 'Video-On-Demand product' );
    return $types;
  }
  add_filter( 'product_type_selector', 'add_vod_product_type' );

  // Create the VOD product type
  function create_vod_product_type(){
     // declare the product class
     class WC_Product_Vod extends WC_Product{
        public function __construct( $product ) {
           $this->product_type = 'vod';
           parent::__construct( $product );
           // add additional functions here
        }
    }
  }
  //add_action( 'plugins_loaded', 'create_vod_product_type' );
  add_action( 'init', 'create_vod_product_type' );
*/

  // show pricing
  function vod_custom_js() {
	if ( 'product' != get_post_type() ) :
		return;
	endif;

	?><script type='text/javascript'>
		jQuery( document ).ready( function() {
			jQuery( '.options_group.pricing' ).addClass( 'show_if_vod' ).show();
		});
	</script><?php
  }
  add_action( 'admin_footer', 'vod_custom_js' );

  function showType(){
    echo "<script>jQuery('.show_if_simple').addClass('show_if_vod');</script>";
  }
  add_action('woocommerce_product_options_general_product_data','showType');

  // Show VOD tab in admin
  function vod_product_tabs( $tabs) {
	$tabs['vod'] = array(
		'label'		=> __( 'Streaming Video', 'woocommerce' ),
                'priority'      => 10,
		'target'	=> 'vod_options',
//		'class'		=> array( 'show_if_vod'),
		'class'		=> array(),
	);
	return $tabs;
  }
  add_filter( 'woocommerce_product_data_tabs', 'vod_product_tabs' );

  // hide unwanted tabs in admin
  function hide_vod_unwanted_data_panels( $tabs) {
	// Other default values for 'attribute' are:
        // general, inventory, shipping, linked_product, variations, advanced
	$tabs['attribute']['class'][] = 'hide_if_vod';
	$tabs['shipping']['class'][] = 'hide_if_vod';
	//$tabs['inventory']['class'][] = 'hide_if_vod';
	return $tabs;

  }
  add_filter( 'woocommerce_product_data_tabs', 'hide_vod_unwanted_data_panels' );
?>
