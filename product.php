<?php
/**
 * Product page tab (owners) using guarded tokenized stream.
 * - Embeds <video src="/?dsi_vod_stream=1&t=..."> from the same stream guard.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

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

if ( ! function_exists( 'dsi_vod_user_owns_any' ) ) {
    function dsi_vod_user_owns_any( int $user_id, WC_Product $product ): bool {
        if ( $product->is_type('variation') ) {
            return dsi_vod_user_owns_product( $user_id, $product->get_id() )
                || dsi_vod_user_owns_product( $user_id, $product->get_parent_id() );
        }
        $ids = array( $product->get_id() );
        if ( $product->is_type('variable') && method_exists($product,'get_children') ) {
            $ids = array_merge( $ids, (array) $product->get_children() );
        }
        $ids = array_filter( array_map('intval', $ids) );
        if ( empty($ids) ) return false;

        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_vod';
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $row = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE user_id=%d AND product_id IN ($placeholders) LIMIT 1",
            array_merge( array( $user_id ), $ids )
        ) );
        return ( $row === 1 );
    }
}

if ( ! function_exists( 'dsi_vod_build_token' ) ) {
    function dsi_vod_build_token( int $user_id, int $product_id, int $ttl = 300 ): string {
        $payload = array(
            'u' => $user_id,
            'p' => $product_id,
            'e' => time() + max(60, $ttl),
            'n' => wp_generate_password(8, false, false),
        );
        $json = wp_json_encode( $payload );
        $b64  = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
        $sig  = hash_hmac( 'sha256', $b64, hash( 'sha256', wp_salt('auth') . '|' . get_site_url() ) );
        return $b64 . '.' . $sig;
    }
}

add_filter( 'woocommerce_product_tabs', function( $tabs ) {
    if ( ! is_product() || ! is_user_logged_in() ) return $tabs;
    global $product;
    if ( ! $product instanceof WC_Product ) return $tabs;

    if ( ! dsi_vod_user_owns_any( get_current_user_id(), $product ) ) return $tabs;

    $tabs['dsi_vod'] = array(
        'title'    => apply_filters('dsi_vod_product_tab_label', __('Streaming Video','woocommerce_vod')),
        'priority' => (int) apply_filters('dsi_vod_product_tab_priority', 25),
        'callback' => 'dsi_vod_render_product_tab_guarded',
    );
    return $tabs;
}, 99 );

if ( ! function_exists( 'dsi_vod_render_product_tab_guarded' ) ) {
    function dsi_vod_render_product_tab_guarded() {
        global $product;
        if ( ! $product instanceof WC_Product ) {
            echo '<p>' . esc_html__( 'Product not found.', 'woocommerce_vod' ) . '</p>';
            return;
        }

        // Resolve the direct media URL (same keys as your watch page)
        $src = '';
        if ( function_exists( 'dsi_vod_resolve_stream_url' ) ) {
          $src = (string) dsi_vod_resolve_stream_url( $product );
        }

        if ( ! $src ) {
          // Fallback keys if your resolver isn't present
          foreach ( ['_vod_stream_url','_video_url','_video_src','_stream_url','_vod_mp4_url','_vod_s3_url','_dsi_vod_stream_url'] as $k ) {
            $v = (string) $product->get_meta( $k, true );
            if ( $v ) { $src = $v; break; }
          }
        }

        if ( ! $src ) {
            echo '<p>' . esc_html__( 'Video not configured for this product.', 'woocommerce_vod' ) . '</p>';
            return;
        }

        $attrs = array(
            'controls' => true,
            'preload'  => 'auto',
            'playsinline' => true,
            'controlsList' => 'nodownload noplaybackrate',
            'disablepictureinpicture' => true,
            'style'    => 'max-width:100%;height:auto;',
            'oncontextmenu' => 'return false;'
        );

        $attr_html = '';
        foreach ($attrs as $k=>$v) { $attr_html .= is_bool($v) ? ( $v ? ' ' . esc_attr($k) : '' ) : ' ' . esc_attr($k) . '="' . esc_attr((string)$v) . '"'; }

        echo '<div class="dsi-vod-player">';
        echo '<h2>' . esc_html__('Streaming Video','woocommerce_vod') . '</h2>';
        printf(
            '<video%s><source src="%s" type="video/mp4" />%s</video>',
            $attr_html,
            esc_url( $src ),
            esc_html__('Your browser does not support the video tag.','woocommerce_vod')
        );
        echo '</div>';
    }
}
