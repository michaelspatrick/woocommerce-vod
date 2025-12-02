<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'dsi_vod_is_vod_product' ) ) {
    function dsi_vod_is_vod_product( $product ) {
        if ( ! $product instanceof WC_Product ) return false;
        if ( $product->get_type() === 'vod' ) return true;
        $attr = $product->get_attribute( 'pa_video_format' );
        $val  = is_string( $attr ) ? strtolower( trim( $attr ) ) : '';
        return in_array( $val, array( 'video_format_streaming', 'video_format_dvd_plus_streaming' ), true );
    }
}
if ( ! function_exists( 'dsi_vod_is_order_item_vod' ) ) {
    function dsi_vod_is_order_item_vod( WC_Order_Item_Product $item, WC_Product $product = null ) {
        foreach ( array('attribute_pa_video_format','pa_video_format') as $k ) {
            $v = (string) $item->get_meta( $k, true );
            if ( $v !== '' ) {
                $v = strtolower( trim( $v ) );
                if ( $v === 'video_format_streaming' || $v === 'video_format_dvd_plus_streaming' ) return true;
            }
        }
        if ( $product && dsi_vod_is_vod_product( $product ) ) return true;
        return false;
    }
}

if ( ! function_exists( 'dsi_vod_revoke_access_for_order' ) ) {
    function dsi_vod_revoke_access_for_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $user_id = (int) $order->get_user_id();
        if ( $user_id <= 0 ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_vod';

        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) continue;
            $product = $item->get_product();
            if ( ! $product instanceof WC_Product ) continue;

            $variation_id = (int) $item->get_variation_id();
            $product_id   = (int) $item->get_product_id();
            $effective_id = $variation_id ?: $product_id;

            if ( dsi_vod_is_order_item_vod( $item, $product ) && $effective_id ) {
                $wpdb->delete( $table, array( 'user_id' => $user_id, 'product_id' => $effective_id ), array( '%d','%d' ) );
            }
        }
    }
}
add_action( 'woocommerce_order_status_cancelled', 'dsi_vod_revoke_access_for_order' );
add_action( 'woocommerce_order_status_refunded', 'dsi_vod_revoke_access_for_order' );
add_action( 'woocommerce_order_refunded', 'dsi_vod_revoke_access_for_order' );

if ( ! function_exists( 'dsi_vod_owned_badge' ) ) {
    function dsi_vod_owned_badge( $product = null ) {
        if ( ! is_user_logged_in() ) return;
        if ( ! $product ) {
            global $product;
            $global_product = $product;
            $product = $global_product instanceof WC_Product ? $global_product : null;
        }
        if ( ! $product instanceof WC_Product ) return;
        if ( ! dsi_vod_is_vod_product( $product ) ) return;

        $user_id = get_current_user_id();
        $owned = false;
        if ( $product->is_type('variation') ) {
            if ( function_exists('dsi_vod_user_owns_product') ) $owned = dsi_vod_user_owns_product( $user_id, $product->get_id() );
        } elseif ( $product->is_type('variable') ) {
            if ( method_exists($product,'get_children') ) {
                $ids = $product->get_children();
                if ( $ids ) {
                    global $wpdb;
                    $table = $wpdb->prefix . 'woocommerce_vod';
                    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                    $row = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT 1 FROM {$table} WHERE user_id=%d AND product_id IN ($placeholders) LIMIT 1",
                        array_merge( array($user_id), array_map('intval',$ids) )
                    ) );
                    $owned = ($row === 1);
                }
            }
        } else {
            if ( function_exists('dsi_vod_user_owns_product') ) $owned = dsi_vod_user_owns_product( $user_id, $product->get_id() );
        }

        if ( $owned ) {
            echo '<span class="dsi-vod-owned" style="display:inline-block;margin-left:8px;padding:2px 6px;border-radius:999px;background:#e6f6ea;color:#1f7a3d;font-size:12px;vertical-align:middle;">' . esc_html__('Owned','woocommerce_vod') . '</span>';
        }
    }
}
add_action( 'woocommerce_single_product_summary', function(){ dsi_vod_owned_badge(); }, 6 );
add_action( 'woocommerce_shop_loop_item_title', function(){ dsi_vod_owned_badge(); }, 20 );

