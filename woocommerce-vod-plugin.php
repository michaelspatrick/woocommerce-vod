<?php
/**
 * Plugin Name: WooCommerce VOD (Streaming Video)
 * Description: Streaming Video (VOD) support for WooCommerce: product type, purchase-to-access mapping, My Account endpoint, secure streaming handler, and admin UI.
 * Version: 2.0.0
 * Author: Michael Patrick
 * Text Domain: woocommerce_vod
 * Domain Path: /languages
 *
 * Requires at least: 6.3
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 9.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DSI_VOD_PLUGIN_VERSION', '2.0.0' );
define( 'DSI_VOD_PLUGIN_FILE', __FILE__ );
define( 'DSI_VOD_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'DSI_VOD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DSI_VOD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

if ( is_admin() ) {
    // Load this early so admin-post handlers are always registered.
    require_once plugin_dir_path( __FILE__ ) . 'admin-vod-access.php';
}

/**
 * i18n
 */
add_action( 'init', function() {
    load_plugin_textdomain( 'woocommerce_vod', false, dirname( DSI_VOD_PLUGIN_BASENAME ) . '/languages' );
}, 1 );

/**
 * WooCommerce HPOS compatibility declaration.
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DSI_VOD_PLUGIN_FILE, true );
    }
} );

/**
 * Activation/Deactivation hooks.
 * - Create/upgrade the mapping table.
 * - Register endpoint and flush rewrites once.
 */
function dsi_vod_activate() {
    // Create mapping table
    if ( ! function_exists( 'dsi_vod_maybe_create_table' ) ) {
        // Attempt to include the functions file if available
        $maybe = DSI_VOD_PLUGIN_PATH . 'woocommerce-vod-func.php';
        if ( file_exists( $maybe ) ) {
            require_once $maybe;
        }
    }
    if ( function_exists( 'dsi_vod_maybe_create_table' ) ) {
        dsi_vod_maybe_create_table();
    }

    // Register endpoint then flush
    $endpoint_file = DSI_VOD_PLUGIN_PATH . 'my-account.php';
    if ( file_exists( $endpoint_file ) ) {
        require_once $endpoint_file;
    }
    if ( function_exists( 'dsi_vod_get_endpoint' ) ) {
        add_rewrite_endpoint( dsi_vod_get_endpoint(), EP_ROOT | EP_PAGES );
    } else {
        add_rewrite_endpoint( 'vod', EP_ROOT | EP_PAGES );
    }
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'dsi_vod_activate' );

function dsi_vod_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'dsi_vod_deactivate' );

/**
 * Safe require helper: includes a file if present.
 */
function dsi_vod_safe_require( string $relative ) : void {
    $path = DSI_VOD_PLUGIN_PATH . ltrim( $relative, '/\\' );
    if ( file_exists( $path ) ) {
        require_once $path;
    }
}

/**
 * Load modules (order matters a bit for helpers).
 */
add_action( 'plugins_loaded', function() {
    // Core helpers + order handler + shortcode
    dsi_vod_safe_require( 'woocommerce-vod-func.php' );

    // Product type/class
    dsi_vod_safe_require( 'product-type.php' );

    // Product admin custom fields
    dsi_vod_safe_require( 'custom-fields.php' );
 
    // Frontend product tab
    dsi_vod_safe_require( 'product.php' );
    //dsi_vod_safe_require( 'product.tab.owners.php' );
    //dsi_vod_safe_require( 'product.tab.owners.noredirect.php' );

    // My Account endpoint
    dsi_vod_safe_require( 'my-account.php' );

    // Hide "Add to cart" if already owned
    dsi_vod_safe_require( 'hide.php' );

    // Secure streaming/guarded download handler
    dsi_vod_safe_require( 'download-video.php' );

    // Admin list + CRUD for VOD grants
    //dsi_vod_safe_require( 'admin.php' );

    // Settings page
    dsi_vod_safe_require( 'vod-admin.php' );

    // Revoke aaccess after refund or order cancel
    dsi_vod_safe_require( 'revoke-notice.php' );

    // add wp cli
    dsi_vod_safe_require( 'vod-cli.php' );

    dsi_vod_safe_require( 'watch-endpoint-stream-guard.php' );

    dsi_vod_safe_require( 'admin-vod-access.php' );
}, 5 );

/**
 * Admin row meta / quick links
 */
add_filter( 'plugin_action_links_' . DSI_VOD_PLUGIN_BASENAME, function( $links ) {
    $settings_url = admin_url( 'admin.php?page=dsi-vod-settings' );
    array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( $settings_url ), esc_html__( 'Settings', 'woocommerce_vod' ) ) );
    return $links;
}, 10, 1 );
