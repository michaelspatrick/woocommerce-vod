<?php
/**
 * WooCommerce VOD — core functions (v3)
 * - Backward compatible with tables using either `ts` timestamp column.
 * - Inserts only user_id/product_id (let DB default set timestamp on either column)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Product detection unchanged from v2 */
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

if ( ! function_exists( 'dsi_vod_user_owns_product' ) ) {
    function dsi_vod_user_owns_product( int $user_id, int $product_or_variation_id ): bool {
        if ( $user_id <= 0 || $product_or_variation_id <= 0 ) return false;
        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_vod';
        $has = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE user_id = %d AND product_id = %d LIMIT 1",
            $user_id, $product_or_variation_id
        ) );
        return ( $has === 1 );
    }
}

/** Grant access on payment complete */
if ( ! function_exists( 'woocommerce_process_vod' ) ) {
    function woocommerce_process_vod( $order_id ) {
        if ( empty( $order_id ) ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_vod';

        $user_id   = (int) $order->get_user_id();
        $num_items = 0;
        $num_vods  = 0;

        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) continue;
            $num_items++;

            $product      = $item->get_product();
            if ( ! $product instanceof WC_Product ) continue;

            $variation_id = (int) $item->get_variation_id();
            $product_id   = (int) $item->get_product_id();
            $effective_id = $variation_id ?: $product_id;

            if ( dsi_vod_is_order_item_vod( $item, $product ) && $effective_id ) {
                $num_vods++;
                // Insert without timestamp column to support both schemas (ts with default)
                $ok = $wpdb->insert( $table, array(
                    'user_id' => $user_id,
                    'product_id' => $effective_id,
                ), array( '%d', '%d' ) );
                if ( false === $ok && $wpdb->last_error ) {
                    error_log( sprintf('[VOD] Insert failed u=%d p=%d order=%d: %s', $user_id, $effective_id, $order_id, $wpdb->last_error ) );
                }
            }
        }

        if ( $num_items > 0 && $num_items === $num_vods ) {
            $order->update_status( 'completed' );
        }
    }
}
add_action( 'woocommerce_payment_complete', 'woocommerce_process_vod' );

if ( ! function_exists( 'dsi_vod_shortcode_customer_vods_table' ) ) {
    function dsi_vod_shortcode_customer_vods_table( $atts = array() ) {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'Please log in to view your videos.', 'woocommerce_vod' ) . '</p>';
        }
        $user_id = get_current_user_id();
        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_vod';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT product_id, ts FROM {$table} WHERE user_id = %d ORDER BY ts DESC",
                $user_id
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return '<p>' . esc_html__( 'You have no streaming videos yet.', 'woocommerce_vod' ) . '</p>';
        }

        ob_start();
        echo '<table class="shop_table shop_table_responsive my_account_orders">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Video', 'woocommerce_vod' ) . '</th>';
        echo '<th>' . esc_html__( 'Access Granted', 'woocommerce_vod' ) . '</th>';
        echo '<th>' . esc_html__( 'Action', 'woocommerce_vod' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $pid   = (int) $row['product_id'];
            $title = get_the_title( $pid );
            $date_raw = $row['ts'] ?? null;
            $date = $date_raw ? mysql2date( get_option('date_format').' '.get_option('time_format'), $date_raw, true ) : '&mdash;';

            $watch_url = trailingslashit( wc_get_account_endpoint_url( 'watch' ) ) . $pid . '/';

            echo '<tr>';
            echo '<td data-title="' . esc_attr__( 'Video', 'woocommerce_vod' ) . '">';
            echo '<a href="' . esc_url( get_permalink( $pid ) ) . '">' . esc_html( $title ? $title : sprintf( __( 'Product #%d', 'woocommerce_vod' ), $pid ) ) . '</a>';
            echo '</td>';
            echo '<td data-title="' . esc_attr__( 'Access Granted', 'woocommerce_vod' ) . '">' . esc_html( $date ) . '</td>';
            echo '<td data-title="' . esc_attr__( 'Action', 'woocommerce_vod' ) . '">';
            echo '<a class="button" href="' . esc_url( $watch_url ) . '">' . esc_html__( 'Watch', 'woocommerce_vod' ) . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        return ob_get_clean();
    }
}
add_shortcode( 'customer_vods_table', 'dsi_vod_shortcode_customer_vods_table' );

