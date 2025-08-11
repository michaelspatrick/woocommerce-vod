<?php
/**
 * VOD Secure Watch Endpoint + Stream Guard (Proxy)
 * - Adds /my-account/watch/{product_id}/ endpoint (hidden from menu)
 * - Verifies ownership, generates a short-lived HMAC token
 * - Streams video via SAME-ORIGIN proxy: <video src="/?dsi_vod_stream=1&token=...">
 *   -> Hides real S3 URL, supports byte-range, discourages downloads (UI only)
 *
 * Requirements: PHP cURL extension for proxying remote URLs.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** ===== Utilities ===== */

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

if ( ! function_exists( 'dsi_vod_resolve_stream_url' ) ) {
    function dsi_vod_resolve_stream_url( WC_Product $product ): string {
        $keys = array('_vod_stream_url','_video_url','_video_src','_stream_url');
        foreach ( $keys as $k ) {
            $v = (string) $product->get_meta($k, true);
            if ( $v ) return $v;
        }
        if ( $product->is_type('variation') ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent ) {
                foreach ( $keys as $k ) {
                    $v = (string) $parent->get_meta($k, true);
                    if ( $v ) return $v;
                }
            }
        }
        return '';
    }
}

/** ===== Token: short-lived HMAC tying user+product+expiry ===== */

if ( ! function_exists( 'dsi_vod_secret' ) ) {
    function dsi_vod_secret(): string {
        // Derive a stable secret from WP salts
        return hash( 'sha256', wp_salt( 'auth' ) . '|' . get_site_url() );
    }
}
if ( ! function_exists( 'dsi_vod_build_token' ) ) {
    function dsi_vod_build_token( int $user_id, int $product_id, int $ttl = 300 ): string {
        $payload = array(
            'u' => $user_id,
            'p' => $product_id,
            'e' => time() + max(60, $ttl),
            'n' => wp_generate_password(8, false, false), // nonce
        );
        $json = wp_json_encode( $payload );
        $b64  = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
        $sig  = hash_hmac( 'sha256', $b64, dsi_vod_secret() );
        return $b64 . '.' . $sig;
    }
}
if ( ! function_exists( 'dsi_vod_parse_token' ) ) {
    function dsi_vod_parse_token( string $token ) {
        $parts = explode( '.', $token );
        if ( count( $parts ) !== 2 ) return new WP_Error( 'bad_token', 'Malformed token' );
        list( $b64, $sig ) = $parts;
        $calc = hash_hmac( 'sha256', $b64, dsi_vod_secret() );
        if ( ! hash_equals( $calc, $sig ) ) return new WP_Error( 'bad_sig', 'Invalid signature' );
        $json = base64_decode( strtr( $b64, '-_', '+/' ), true );
        if ( false === $json ) return new WP_Error( 'bad_b64', 'Invalid base64' );
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) return new WP_Error( 'bad_json', 'Invalid payload' );
        if ( empty( $data['u'] ) || empty( $data['p'] ) || empty( $data['e'] ) ) return new WP_Error( 'bad_fields', 'Missing fields' );
        if ( time() > (int) $data['e'] ) return new WP_Error( 'expired', 'Token expired' );
        return $data;
    }
}

/** ===== Endpoint: /my-account/watch/{product_id}/ ===== */

add_action( 'init', function() {
    add_rewrite_endpoint( 'watch', EP_ROOT | EP_PAGES );
}, 9 );

add_action( 'wp', function(){
    add_action( 'woocommerce_account_watch_endpoint', function( $product_id = null ) {
        if ( ! is_user_logged_in() ) {
            echo '<p>' . esc_html__( 'You must be logged in to view this video.', 'woocommerce_vod' ) . '</p>';
            return;
        }

        $user_id = get_current_user_id();
        $pid = absint( get_query_var( 'watch', 0 ) );
        if ( ! $pid && is_numeric( $product_id ) ) $pid = absint( $product_id );
        if ( ! $pid ) {
            echo '<p>' . esc_html__( 'Missing product.', 'woocommerce_vod' ) . '</p>';
            return;
        }

        $product = wc_get_product( $pid );
        if ( ! $product ) { echo '<p>' . esc_html__( 'Invalid product.', 'woocommerce_vod' ) . '</p>'; return; }

        if ( ! dsi_vod_user_owns_any( $user_id, $product ) ) {
            echo '<p>' . esc_html__( 'You do not have access to this video.', 'woocommerce_vod' ) . '</p>';
            return;
        }

        // Build short-lived token for streaming
        $token = dsi_vod_build_token( $user_id, $pid, apply_filters( 'dsi_vod_token_ttl', 300, $pid, $user_id ) );
        $stream_src = add_query_arg( array( 'dsi_vod_stream' => 1, 't' => rawurlencode( $token ) ), home_url( '/' ) );

        echo '<div class="woocommerce"><div class="woocommerce-MyAccount-content">';
        echo '<h2>' . esc_html__( 'Watch Video', 'woocommerce_vod' ) . '</h2>';
        // Minimal, distraction-free player
        $attrs = array(
            'controls' => true,
            'preload'  => 'metadata',
            'playsinline' => true,
            'controlsList' => 'nodownload noplaybackrate',
            'disablepictureinpicture' => true,
            'crossorigin' => 'anonymous',
            'style'    => 'max-width:100%;height:auto;',
            'oncontextmenu' => 'return false;'
        );
        $attr_html = '';
        foreach ($attrs as $k=>$v) { $attr_html .= is_bool($v) ? ( $v ? ' ' . esc_attr($k) : '' ) : ' ' . esc_attr($k) . '="' . esc_attr((string)$v) . '"'; }
        printf(
            '<video%s><source src="%s" type="video/mp4" />%s</video>',
            $attr_html,
            esc_url( $stream_src ),
            esc_html__( 'Your browser does not support the video tag.', 'woocommerce_vod' )
        );
        echo '</div></div>';
    } );
}, 9 );

/** ===== Stream guard: same-origin proxy so the true URL is never revealed ===== */

add_action( 'init', function () {
    if ( ! isset( $_GET['dsi_vod_stream'] ) ) return;

    // Basic auth
    if ( ! is_user_logged_in() ) { auth_redirect(); exit; }

    $token = isset($_GET['t']) ? (string) $_GET['t'] : '';
    $data  = dsi_vod_parse_token( $token );
    if ( is_wp_error( $data ) ) {
        wp_die( esc_html__( 'Invalid or expired token.', 'woocommerce_vod' ), 403 );
    }

    $user_id = get_current_user_id();
    if ( (int) $data['u'] !== (int) $user_id ) {
        wp_die( esc_html__( 'Token does not match user.', 'woocommerce_vod' ), 403 );
    }
    $pid = (int) $data['p'];

    $product = wc_get_product( $pid );
    if ( ! $product ) wp_die( esc_html__( 'Invalid product.', 'woocommerce_vod' ), 404 );
    if ( ! dsi_vod_user_owns_any( $user_id, $product ) ) {
        wp_die( esc_html__( 'No access.', 'woocommerce_vod' ), 403 );
    }

    $url = dsi_vod_resolve_stream_url( $product );
    if ( ! $url ) {
        wp_die( esc_html__( 'No video source set.', 'woocommerce_vod' ), 404 );
    }

    // Proxy the remote file via cURL with Range support (hide true origin)
    if ( ! function_exists('curl_init') ) {
        wp_die( esc_html__( 'Server missing cURL extension.', 'woocommerce_vod' ), 500 );
    }

    $range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null;

    $ch = curl_init();
    curl_setopt( $ch, CURLOPT_URL, $url );
    curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false ); // stream to output
    curl_setopt( $ch, CURLOPT_HEADER, false );
    curl_setopt( $ch, CURLOPT_TIMEOUT, 0 );
    curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
    curl_setopt( $ch, CURLOPT_USERAGENT, 'DSI-VOD-Proxy' );

    // Pass Range for seeking
    if ( $range ) {
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Range: ' . $range ) );
    }

    // Clean headers and set streaming headers
    if ( ! headers_sent() ) {
        header_remove( 'Content-Type' );
        header( 'Content-Type: video/mp4' );
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'Accept-Ranges: bytes' );
        // Avoid revealing source via Referer
        header( 'Referrer-Policy: no-referrer' );
    }

    // Stream
    $ok = curl_exec( $ch );
    if ( $ok === false ) {
        status_header( 502 );
        echo esc_html__( 'Upstream error while fetching video.', 'woocommerce_vod' );
    }
    curl_close( $ch );
    exit;
});

