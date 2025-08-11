<?php
/**
 * VOD handler — external redirect fix
 * - Uses wp_redirect (not wp_safe_redirect) for validated external URLs
 * - Whitelists common streaming/CDN hosts via allowed_redirect_hosts filter (optional)
 * - Keeps legacy meta key support and variation → parent fallback
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * (Optional) Allow common streaming hosts for wp_safe_redirect usage.
 * If you keep wp_redirect below, this filter is not strictly required,
 * but it doesn't hurt and can help other parts of the site.
 */
add_filter( 'allowed_redirect_hosts', function( $hosts ) {
    $extra = array(
        'vimeo.com', 'player.vimeo.com',
        'youtube.com', 'www.youtube.com', 'youtu.be',
        'wistia.com', 'fast.wistia.net', 'embed-ssl.wistia.com',
        'amazonaws.com', 's3.amazonaws.com',
        'cloudfront.net',
        'stream.mux.com',
        'video.ibm.com',
        'brightcove.com', 'players.brightcove.net',
        'kvcdn.com', 'akamaized.net',
    );
    return array_unique( array_merge( $hosts, $extra ) );
}, 10, 1 );

add_action( 'init', function () {

    if ( ! isset( $_GET['dsi_vod'] ) ) {
        return;
    }
    if ( ! function_exists( 'wc_get_product' ) ) {
        return;
    }

    if ( ! is_user_logged_in() && ! current_user_can( 'manage_woocommerce' ) ) {
        auth_redirect();
        exit;
    }

    $user_id   = get_current_user_id();
    $product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;

    if ( ! $product_id ) {
        wp_die( esc_html__( 'Missing product_id.', 'woocommerce_vod' ), 400 );
    }

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        wp_die( esc_html__( 'Invalid product.', 'woocommerce_vod' ), 404 );
    }

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_vod';
        $owned = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE user_id = %d AND product_id = %d LIMIT 1",
            $user_id, $product_id
        ) );
        if ( ! $owned ) {
            wp_die( esc_html__( 'You do not have access to this video.', 'woocommerce_vod' ), 403 );
        }
    }

    // Resolve streaming URL from known keys (product then parent)
    $meta_keys = array( '_vod_stream_url', '_video_url', '_video_src', '_stream_url' );

    $stream_url = '';
    foreach ( $meta_keys as $k ) {
        $v = (string) $product->get_meta( $k, true );
        if ( $v ) { $stream_url = $v; break; }
    }

    if ( ! $stream_url && $product->is_type( 'variation' ) ) {
        $parent_id = $product->get_parent_id();
        if ( $parent_id ) {
            $parent = wc_get_product( $parent_id );
            if ( $parent ) {
                foreach ( $meta_keys as $k ) {
                    $v = (string) $parent->get_meta( $k, true );
                    if ( $v ) { $stream_url = $v; break; }
                }
            }
        }
    }

    if ( $stream_url ) {
        // Validate scheme/URL and redirect externally (use wp_redirect to avoid safe-redirect host checks)
        $stream_url = trim( $stream_url );
        $is_valid = wp_http_validate_url( $stream_url );
        $scheme   = wp_parse_url( $stream_url, PHP_URL_SCHEME );

        if ( $is_valid && in_array( $scheme, array( 'https', 'http' ), true ) ) {
            // If you'd rather keep wp_safe_redirect, uncomment this and comment wp_redirect:
            // wp_safe_redirect( $stream_url, 302 );
            wp_redirect( $stream_url, 302 );
            exit;
        }
    }

    // Fallback to local file in uploads
    $file_keys = array( '_vod_download_file', '_download_file' );
    $download_rel = '';
    foreach ( $file_keys as $k ) {
        $v = (string) $product->get_meta( $k, true );
        if ( $v ) { $download_rel = $v; break; }
    }
    if ( ! $download_rel && $product->is_type( 'variation' ) ) {
        $parent_id = $product->get_parent_id();
        if ( $parent_id ) {
            $parent = wc_get_product( $parent_id );
            if ( $parent ) {
                foreach ( $file_keys as $k ) {
                    $v = (string) $parent->get_meta( $k, true );
                    if ( $v ) { $download_rel = $v; break; }
                }
            }
        }
    }

    if ( ! $download_rel ) {
        wp_die( esc_html__( 'No video URL/file is set for this product.', 'woocommerce_vod' ), 404 );
    }

    $uploads = wp_get_upload_dir();
    if ( ! $uploads || empty( $uploads['basedir'] ) ) {
        wp_die( esc_html__( 'Cannot resolve uploads directory.', 'woocommerce_vod' ), 500 );
    }

    $basedir = wp_normalize_path( $uploads['basedir'] );
    $filepath = wp_normalize_path( trailingslashit( $basedir ) . ltrim( $download_rel, '/\\' ) );

    if ( strpos( $filepath, $basedir ) !== 0 || ! file_exists( $filepath ) || ! is_readable( $filepath ) ) {
        wp_die( esc_html__( 'File not found or not accessible.', 'woocommerce_vod' ), 404 );
    }

    if ( apply_filters( 'dsi_vod_use_xsendfile', false, $filepath ) && ! headers_sent() ) {
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: inline; filename="' . basename( $filepath ) . '"' );
        header( 'X-Sendfile: ' . $filepath );
        exit;
    }
    if ( apply_filters( 'dsi_vod_use_xaccel', false, $filepath ) && ! headers_sent() ) {
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: inline; filename="' . basename( $filepath ) . '"' );
        $internal_uri = apply_filters( 'dsi_vod_xaccel_internal_uri', '/protected/' . ltrim( $download_rel, '/\\' ), $filepath );
        header( 'X-Accel-Redirect: ' . $internal_uri );
        exit;
    }

    if ( ! headers_sent() ) {
        $filesize = (string) filesize( $filepath );
        header( 'Content-Type: ' . ( function_exists('mime_content_type') ? mime_content_type( $filepath ) : 'application/octet-stream' ) );
        header( 'Content-Length: ' . $filesize );
        header( 'Content-Disposition: inline; filename="' . basename( $filepath ) . '"' );
        header( 'Accept-Ranges: bytes' );
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
    }

    $range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null;
    $fp = fopen( $filepath, 'rb' );
    if ( $fp === false ) {
        wp_die( esc_html__( 'Unable to open file.', 'woocommerce_vod' ), 500 );
    }

    $start = 0;
    $length = (int) filesize( $filepath );
    $end = $length - 1;

    if ( $range && preg_match('/bytes=(\d+)-(\d+)?/', $range, $m) ) {
        $start = (int) $m[1];
        if ( isset( $m[2] ) && $m[2] !== '' ) {
            $end = (int) $m[2];
        }
        if ( $start > $end || $start >= $length ) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header('Content-Range: bytes */' . $length);
            fclose($fp);
            exit;
        }
        $chunk_length = $end - $start + 1;
        header('HTTP/1.1 206 Partial Content');
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $length);
        header('Content-Length: ' . $chunk_length);
        fseek($fp, $start);
        $bytes_to_send = $chunk_length;
    } else {
        $bytes_to_send = $length;
    }

    $chunk_size = 8 * 1024 * 1024;
    while ( ! feof( $fp ) && $bytes_to_send > 0 ) {
        $read = ($bytes_to_send > $chunk_size) ? $chunk_size : $bytes_to_send;
        echo fread( $fp, $read );
        flush();
        $bytes_to_send -= $read;
        if ( connection_aborted() ) { break; }
    }
    fclose( $fp );
    exit;
});

