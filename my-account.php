<?php
/**
 * WooCommerce My Account: "Streaming Video" endpoint.
 * - PHP 8+ / WooCommerce 8+ compatible
 * - Adds a "Streaming Video" tab and endpoint to My Account
 * - Uses filters so endpoint slug, label, and position are customizable
 * - Flushes rewrites only on activation (not every page load)
 *
 * Filters:
 *   - dsi_vod_endpoint (string)        Default: 'vod'
 *   - dsi_vod_menu_label (string)      Default: 'Streaming Video'
 *   - dsi_vod_menu_position (int)      Default: 5 (after Dashboard)
 *   - dsi_vod_endpoint_cap (string)    Default: 'read' (capability to view endpoint)
 *   - dsi_vod_endpoint_content (callable|string) If set to string, treated as shortcode to render.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'dsi_vod_get_endpoint' ) ) {
    function dsi_vod_get_endpoint(): string {
        $slug = apply_filters( 'dsi_vod_endpoint', 'vod' );
        $slug = sanitize_title( $slug );
        return ( $slug !== '' ) ? $slug : 'vod';
    }
}

/**
 * Register the endpoint on init.
 */
add_action( 'init', function () {
    $endpoint = dsi_vod_get_endpoint();
    add_rewrite_endpoint( $endpoint, EP_ROOT | EP_PAGES );
}, 9 );

/**
 * Optionally add query var (good practice though WP handles endpoints without it).
 */
add_filter( 'query_vars', function( $vars ) {
    $vars[] = dsi_vod_get_endpoint();
    return $vars;
} );

/**
 * Activation hook: Flush rewrites once on activation.
 * IMPORTANT: If this file is not the main plugin file, ensure the activation hook
 * is registered from your main plugin bootstrap:
 *
 *   register_activation_hook( __FILE__, 'dsi_vod_activate' );
 */
if ( ! function_exists( 'dsi_vod_activate' ) ) {
    function dsi_vod_activate() {
        // Ensure endpoint exists before flushing
        $endpoint = dsi_vod_get_endpoint();
        add_rewrite_endpoint( $endpoint, EP_ROOT | EP_PAGES );
        flush_rewrite_rules();
    }
}

/**
 * Insert the menu item in My Account.
 */
add_filter( 'woocommerce_account_menu_items', function( $items ) {
    $endpoint   = dsi_vod_get_endpoint();
    $label      = apply_filters( 'dsi_vod_menu_label', __( 'Streaming Video', 'woocommerce_vod' ) );
    $position   = (int) apply_filters( 'dsi_vod_menu_position', 5 );

    // Build new pair
    $new = array( $endpoint => $label );

    // Rebuild items sticking new item near desired position.
    // Default Woo items order example: dashboard, orders, downloads, edit-address, payment-methods, edit-account, customer-logout
    $out = array();
    $i   = 0;
    foreach ( $items as $key => $text ) {
        if ( $i === $position ) {
            $out = array_merge( $out, $new );
        }
        $out[ $key ] = $text;
        $i++;
    }
    // If position is beyond the array length, append
    if ( ! isset( $out[ $endpoint ] ) ) {
        $out = array_merge( $out, $new );
    }

    return $out;
}, 20 );

/**
 * Endpoint content renderer.
 * - Requires user to be logged in and have capability (default: 'read')
 * - Renders the shortcode [customer_vods_table] by default (if present)
 *   or a minimal fallback table if the shortcode is not registered.
 */
add_action( 'wp', function() {
    $endpoint = dsi_vod_get_endpoint();
    $hook     = "woocommerce_account_{$endpoint}_endpoint";

    add_action( $hook, function() {
        if ( ! is_user_logged_in() ) {
            echo '<p>' . esc_html__( 'You need to be logged in to view your streaming videos.', 'woocommerce_vod' ) . '</p>';
            return;
        }
        $cap = apply_filters( 'dsi_vod_endpoint_cap', 'read' );
        if ( ! current_user_can( $cap ) ) {
            echo '<p>' . esc_html__( 'You do not have permission to view this page.', 'woocommerce_vod' ) . '</p>';
            return;
        }

        // Allow integrators to override with a callable or a shortcode
        $content_filter = apply_filters( 'dsi_vod_endpoint_content', null );
        if ( is_callable( $content_filter ) ) {
            call_user_func( $content_filter );
            return;
        } elseif ( is_string( $content_filter ) && $content_filter !== '' ) {
            echo do_shortcode( $content_filter );
            return;
        }

        // Default behavior: try known shortcode, fallback to internal renderer
        if ( shortcode_exists( 'customer_vods_table' ) ) {
            echo do_shortcode( '[customer_vods_table]' );
        } else {
            // Fallback: minimal list of owned VODs from {prefix}woocommerce_vod
            dsi_vod_render_fallback_table();
        }
    } );
}, 9 );

if ( ! function_exists( 'dsi_vod_render_fallback_table' ) ) {
    function dsi_vod_render_fallback_table() {
        global $wpdb;

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            echo '<p>' . esc_html__( 'No videos found.', 'woocommerce_vod' ) . '</p>';
            return;
        }

        $table  = $wpdb->prefix . 'woocommerce_vod';
        $rows   = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT product_id, ts FROM {$table} WHERE user_id = %d ORDER BY ts DESC",
                $user_id
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            echo '<p>' . esc_html__( 'No videos found.', 'woocommerce_vod' ) . '</p>';
            return;
        }

        echo '<table class="shop_table shop_table_responsive my_account_orders">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Video', 'woocommerce_vod' ) . '</th>';
        echo '<th>' . esc_html__( 'Access Granted', 'woocommerce_vod' ) . '</th>';
        echo '<th>' . esc_html__( 'Action', 'woocommerce_vod' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $pid   = (int) $row['product_id'];
            $title = get_the_title( $pid );
            $date  = ! empty( $row['ts'] ) ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row['ts'], true ) : '&mdash;';

            // Prefer stream URL if present; otherwise a guarded handler link if you use one.
            $watch_url = get_post_meta( $pid, '_vod_stream_url', true );
            if ( empty( $watch_url ) || ! wp_http_validate_url( $watch_url ) ) {
                // Fallback to your guarded handler (download-video.php secure handler)
                $watch_url = add_query_arg( array( 'dsi_vod' => 1, 'product_id' => $pid ), home_url( '/' ) );
            }

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
    }
}

/**
 * Optional: adjust WooCommerce Memberships/other My Account columns if needed.
 * Keep these off by default; enable with a filter or editing as desired.
 */
add_filter( 'woocommerce_account_content', function( $content ) {
    // Placeholder for any post-processing of the content if needed.
    return $content;
}, 10, 1 );

