<?php
/**
 * Register and support the custom WooCommerce product type: VOD.
 * - PHP 8+ / WooCommerce 8+ compatible
 * - Uses product_type_selector + woocommerce_product_class
 * - Class extends WC_Product_Simple
 * - Adds admin JS to show VOD-related panels
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add 'VOD' to the product type dropdown.
 */
add_filter( 'product_type_selector', function( $types ) {
    $types['vod'] = __( 'VOD (Streaming Video)', 'woocommerce_vod' );
    return $types;
} );

/**
 * Map product type to a class that extends WC_Product_Simple.
 */
add_filter( 'woocommerce_product_class', function( $classname, $product_type ) {
    if ( $product_type === 'vod' ) {
        return 'WC_Product_Vod';
    }
    return $classname;
}, 10, 2 );

/**
 * Define the product class for 'vod' products.
 */
if ( ! class_exists( 'WC_Product_Vod' ) ) {
    class WC_Product_Vod extends WC_Product_Simple {
        public function get_type() {
            return 'vod';
        }
    }
}

/**
 * Admin: ensure panels/fields show for VOD products.
 * This mirrors how WooCommerce toggles panels via CSS classes like show_if_simple.
 */
add_action( 'admin_footer', function () {
    $screen = get_current_screen();
    if ( ! $screen || 'product' !== $screen->id ) {
        return;
    }
    ?>
    <script type="text/javascript">
    jQuery(function($){
        function dsiVodTogglePanels(){
            var isVOD = ($('#product-type').val() === 'vod');
            // Show standard panels used by simple products when VOD is selected.
            var classes = [
                '.show_if_simple',
                '.pricing', // pricing group
                '.inventory_options',
                '.shipping_options',
                '.linked_product_options',
                '.attribute_options',
                '.advanced_options'
            ];
            // Toggle WooCommerce's native "show_if_vod" class as well.
            $('.show_if_vod').toggle(isVOD);
            // Also show typical simple-product sections for convenience when VOD is selected.
            classes.forEach(function(sel){
                $(sel).toggle(isVOD);
            });
        }
        $('#product-type').on('change', dsiVodTogglePanels);
        dsiVodTogglePanels();
    });
    </script>
    <?php
} );
