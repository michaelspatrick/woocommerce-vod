<?php
/**
 * Hide purchase options for VOD products already owned by the current user.
 * - PHP 8+ / WooCommerce 8+ compatible
 * - Uses safe $wpdb->prepare() and WC CRUD APIs
 * - Applies to simple, variable, and variation products
 *
 * Behavior:
 * - If product is identified as VOD and the logged-in user already owns it via {prefix}woocommerce_vod,
 *   mark it as NOT purchasable and show a friendly notice on product pages.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'dsi_vod_is_vod_product' ) ) {
    /**
     * Determine if a product should be treated as VOD.
     * We treat a product as VOD if:
     *  - Product type is 'vod' (custom type), OR
     *  - Attribute 'pa_video_format' equals 'video_format_streaming' or 'video_format_dvd_plus_streaming'.
     *
     * @param WC_Product $product
     * @return bool
     */
    function dsi_vod_is_vod_product( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return false;
        }

        // Custom product type?
        if ( $product->get_type() === 'vod' ) {
            return true;
        }

        // Attribute flag (works for both simple and variations)
        $attr = $product->get_attribute( 'pa_video_format' );
        if ( is_string( $attr ) ) {
            $attr = trim( strtolower( $attr ) );
            if ( $attr === 'video_format_streaming' || $attr === 'video_format_dvd_plus_streaming' ) {
                return true;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'dsi_vod_user_owns_product' ) ) {
    /**
     * Check if a user owns a specific product/variation according to woocommerce_vod table.
     *
     * @param int $user_id
     * @param int $product_or_variation_id
     * @return bool
     */
    function dsi_vod_user_owns_product( $user_id, $product_or_variation_id ) {
        if ( ! $user_id || ! $product_or_variation_id ) {
            return false;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_vod';
        $owned = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM {$table} WHERE user_id = %d AND product_id = %d LIMIT 1",
                $user_id,
                $product_or_variation_id
            )
        );
        return ( $owned === 1 );
    }
}

/**
 * Core filter: mark a VOD product not purchasable if the current user already owns it.
 */
add_filter( 'woocommerce_is_purchasable', function( $purchasable, $product ) {
    if ( ! is_user_logged_in() ) {
        return $purchasable; // only enforce for logged-in (can't know ownership otherwise)
    }
    if ( ! $product instanceof WC_Product ) {
        return $purchasable;
    }

    if ( dsi_vod_is_vod_product( $product ) ) {
        $user_id = get_current_user_id();

        $check_ids = array();
        if ( $product->is_type( 'variation' ) ) {
            $check_ids[] = $product->get_id();
        } else {
            // For variable product, check its variations during variation purchasable filter.
            $check_ids[] = $product->get_id();
        }

        foreach ( $check_ids as $pid ) {
            if ( dsi_vod_user_owns_product( $user_id, $pid ) ) {
                return false;
            }
        }
    }

    return $purchasable;
}, 10, 2 );

/**
 * Also handle variation purchasability (e.g., in variation dropdowns).
 */
add_filter( 'woocommerce_variation_is_purchasable', function( $purchasable, $variation ) {
    if ( ! is_user_logged_in() ) {
        return $purchasable;
    }
    if ( ! $variation instanceof WC_Product_Variation ) {
        return $purchasable;
    }

    if ( dsi_vod_is_vod_product( $variation ) ) {
        $user_id = get_current_user_id();
        if ( dsi_vod_user_owns_product( $user_id, $variation->get_id() ) ) {
            return false;
        }
    }

    return $purchasable;
}, 10, 2 );

/**
 * Optional UX: show a message on single product page when user already owns the VOD,
 * and provide a link to watch (if _vod_stream_url is set on the product/variation).
 */
add_action( 'woocommerce_single_product_summary', function() {
    if ( ! is_product() || ! is_user_logged_in() ) {
        return;
    }

    global $product;
    if ( ! $product instanceof WC_Product ) {
        return;
    }

    // Resolve the effective product to check (variation if selected)
    $effective = $product;
    if ( $product->is_type( 'variable' ) ) {
        // On initial load we may not have a chosen variation yet; skip message until variation selected.
        return;
    }

    if ( dsi_vod_is_vod_product( $effective ) && dsi_vod_user_owns_product( get_current_user_id(), $effective->get_id() ) ) {
        $watch = $effective->get_meta( '_vod_stream_url', true );
        echo '<div class="woocommerce-info">';
        echo esc_html__( 'You already own streaming access to this video.', 'woocommerce_vod' ) . ' ';
        if ( $watch && wp_http_validate_url( $watch ) ) {
            printf(
                '<a href="%s" class="button">%s</a>',
                esc_url( $watch ),
                esc_html__( 'Watch now', 'woocommerce_vod' )
            );
        }
        echo '</div>';
    }
}, 25 );

