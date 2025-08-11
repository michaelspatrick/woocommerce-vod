<?php
/**
 * Admin UI: VOD Access Manager (Select2 + Pagination + Revoke Reason + Audit + Order Meta Box)
 *
 * - WooCommerce → VOD Access page (product autocomplete via Woo's built-in AJAX, pagination)
 * - User Profile box (grant/revoke)
 * - Order edit screen meta box: verify/grant/revoke access for VOD items in the order (per-item & bulk), with "Reason"
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ========= Helpers & Tables ========= */

if ( ! function_exists( 'dsi_vod_table_name' ) ) {
    function dsi_vod_table_name() { global $wpdb; return $wpdb->prefix . 'woocommerce_vod'; }
}
if ( ! function_exists( 'dsi_vod_audit_table_name' ) ) {
    function dsi_vod_audit_table_name() { global $wpdb; return $wpdb->prefix . 'woocommerce_vod_audit'; }
}

add_action( 'admin_init', function() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS " . dsi_vod_audit_table_name() . " (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(20) NOT NULL, /* grant|revoke */
        reason TEXT NULL,
        actor_id BIGINT UNSIGNED NOT NULL,
        ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user (user_id),
        KEY idx_product (product_id),
        KEY idx_action (action)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
} );

if ( ! function_exists( 'dsi_vod_find_user' ) ) {
    function dsi_vod_find_user( string $needle ) {
        $needle = trim( $needle );
        if ( $needle === '' ) return null;
        if ( ctype_digit( $needle ) ) { $u = get_user_by( 'id', (int) $needle ); if ( $u ) return $u; }
        if ( is_email( $needle ) ) { $u = get_user_by( 'email', $needle ); if ( $u ) return $u; }
        return get_user_by( 'login', $needle ); // may be null
    }
}

if ( ! function_exists( 'dsi_vod_has_access' ) ) {
    function dsi_vod_has_access( int $user_id, int $product_id ): bool {
        global $wpdb;
        $table = dsi_vod_table_name();
        $has = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE user_id=%d AND product_id=%d LIMIT 1",
            $user_id, $product_id
        ) );
        return ( $has === 1 );
    }
}
if ( ! function_exists( 'dsi_vod_grant' ) ) {
    function dsi_vod_grant( int $user_id, int $product_id, string $reason = '' ): bool {
        global $wpdb; $table = dsi_vod_table_name();
        $ok = $wpdb->insert( $table, array( 'user_id' => $user_id, 'product_id' => $product_id ), array( '%d','%d' ) );
        dsi_vod_audit( $user_id, $product_id, 'grant', $reason );
        return $ok !== false;
    }
}
if ( ! function_exists( 'dsi_vod_revoke' ) ) {
    function dsi_vod_revoke( int $user_id, int $product_id, string $reason = '' ): bool {
        global $wpdb; $table = dsi_vod_table_name();
        $ok = $wpdb->delete( $table, array( 'user_id' => $user_id, 'product_id' => $product_id ), array( '%d','%d' ) );
        dsi_vod_audit( $user_id, $product_id, 'revoke', $reason );
        return $ok !== false;
    }
}
if ( ! function_exists( 'dsi_vod_audit' ) ) {
    function dsi_vod_audit( int $user_id, int $product_id, string $action, string $reason = '' ) {
        global $wpdb;
        $wpdb->insert( dsi_vod_audit_table_name(), array(
            'user_id'    => $user_id,
            'product_id' => $product_id,
            'action'     => sanitize_key( $action ),
            'reason'     => $reason,
            'actor_id'   => (int) get_current_user_id(),
        ), array( '%d','%d','%s','%s','%d' ) );
    }
}
if ( ! function_exists( 'dsi_vod_get_user_vods_paginated' ) ) {
    function dsi_vod_get_user_vods_paginated( int $user_id, int $page, int $per_page ): array {
        global $wpdb; $table = dsi_vod_table_name();
        $offset = max( 0, ($page - 1) * $per_page );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT SQL_CALC_FOUND_ROWS product_id, ts
             FROM {$table}
             WHERE user_id=%d
             ORDER BY ts DESC
             LIMIT %d OFFSET %d",
            $user_id, $per_page, $offset
        ), ARRAY_A );
        $total = (int) $wpdb->get_var( "SELECT FOUND_ROWS()" );
        return array( 'rows' => $rows ?: array(), 'total' => $total, 'page' => $page, 'per_page' => $per_page );
    }
}

/* ========= Detect VOD items in an order ========= */

if ( ! function_exists( 'dsi_vod_is_order_item_vod' ) ) {
    function dsi_vod_is_order_item_vod( WC_Order_Item_Product $item ): bool {
        // Prefer line item meta (variation attribute) first
        foreach ( array( 'attribute_pa_video_format', 'pa_video_format' ) as $k ) {
            $v = (string) $item->get_meta( $k, true );
            if ( $v !== '' ) {
                $v = strtolower( trim( $v ) );
                if ( $v === 'video_format_streaming' || $v === 'video_format_dvd_plus_streaming' ) return true;
            }
        }
        // Fallback: product type "vod"
        $product = $item->get_product();
        return ( $product instanceof WC_Product && $product->get_type() === 'vod' );
    }
}

/* ========= Admin menus, assets & CSS ========= */

add_action( 'admin_menu', function() {
    add_submenu_page(
        'woocommerce',
        __( 'VOD Access', 'woocommerce_vod' ),
        __( 'VOD Access', 'woocommerce_vod' ),
        'manage_woocommerce',
        'dsi-vod-access',
        'dsi_vod_render_access_page'
    );
} );

add_action( 'admin_enqueue_scripts', function( $hook ) {
    // Load Woo's selectWoo on both our page and the user edit page
    if ( in_array( $hook, array( 'woocommerce_page_dsi-vod-access', 'user-edit.php', 'profile.php', 'post.php' ), true ) ) {
        if ( function_exists( 'WC' ) ) {
            wp_enqueue_script( 'selectWoo' );
            wp_enqueue_style( 'select2' );
            wp_enqueue_script( 'wc-enhanced-select' );
            wp_enqueue_style( 'woocommerce_admin_styles' );
        }

add_action( 'admin_head', function() {
    $screen = get_current_screen();
    ?>
    <style>
        /* Ensure product search select is wide on the VOD Access page */
        .woocommerce_page_dsi-vod-access .select2-container {
            min-width: 450px !important;
            max-width: 800px !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }

        /* Make the select field itself expand */
        .woocommerce_page_dsi-vod-access .wc-product-search {
            width: 100% !important;
            max-width: 800px;
        }
    </style>
    <?php
} );

add_action( 'admin_head', function() {
    ?>
        <style>
          /* Scope to the VOD meta box on the Order edit screen */
          .post-type-shop_order #dsi_vod_order_access .dsi-vod-meta .dsi-inline-form {
            display: block !important;
            width: 100% !important;
            margin: 6px 0 !important;
          }

          .post-type-shop_order #dsi_vod_order_access .dsi-reason,
          .post-type-shop_order #dsi_vod_order_access .vod-reason-field,
          .post-type-shop_order #dsi_vod_order_access input[type="text"].dsi-reason,
          .post-type-shop_order #dsi_vod_order_access textarea.vod-reason-field {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
           }

           /* Keep buttons from forcing horizontal overflow */
           .post-type-shop_order #dsi_vod_order_access .dsi-inline-form .button,
           .post-type-shop_order #dsi_vod_order_access .dsi-inline-form .button-primary,
           .post-type-shop_order #dsi_vod_order_access .dsi-inline-form .button-secondary {
             margin-top: 6px !important;
             width: auto;
           }

           /* Make Select2 fit the meta box too */
           .post-type-shop_order #dsi_vod_order_access .select2-container {
             width: 100% !important;
             min-width: 0 !important;
             max-width: 100% !important;
             box-sizing: border-box !important;
           }

           /* Optional: tighten the table so fields don’t wrap oddly */
           .post-type-shop_order #dsi_vod_order_access .widefat td,
           .post-type-shop_order #dsi_vod_order_access .widefat th {
              padding: 6px 8px;
            }

            /* Small screens fallback */
            @media (max-width: 782px) {
              .post-type-shop_order #dsi_vod_order_access .dsi-reason { font-size: 14px; }
            }
          </style>
          <?php
        });


        // Inline JS to init selectWoo with Woo's built-in search endpoint
        add_action( 'admin_footer', function() use ( $hook ) {
          ?>
<script>
(function($){
    function init($ctx){ $ctx.find('.wc-product-search').filter(':not(.enhanced)').each(function(){
        var $el=$(this);
        $el.addClass('enhanced').selectWoo({
            minimumInputLength: 2,
            allowClear: true,
            placeholder: $el.attr('data-placeholder') || '<?php echo esc_js( __( 'Search for a product…', 'woocommerce_vod' ) ); ?>',
            ajax: {
                url: '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',
                dataType: 'json',
                delay: 250,
                data: function(params){
                    return { term: params.term || '', action: 'woocommerce_json_search_products_and_variations', security: '<?php echo esc_js( wp_create_nonce( 'search-products' ) ); ?>' };
                },
                processResults: function(data){
                    var results=[];
                    if (data && data.results){
                        $.each(data.results, function(id, text){ results.push({id:id, text:text}); });
                    }
                    return {results:results};
                },
                cache: true
            }
        });
    });}
    $(document).ready(function(){ init($(document)); });
})(jQuery);
</script>
            <?php
        } );
    }
} );

/* ========= Admin page: WooCommerce → VOD Access ========= */

function dsi_vod_render_access_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to access this page.', 'woocommerce_vod' ) ); }

    $user_q    = isset($_GET['user']) ? sanitize_text_field( wp_unslash($_GET['user']) ) : '';
    $product_q = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
    $paged     = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
    $per_page  = 20;

    $user    = $user_q ? dsi_vod_find_user( $user_q ) : null;
    $product = $product_q ? wc_get_product( $product_q ) : null;

    if ( isset($_GET['dsi_msg']) ) printf('<div class="notice notice-success"><p>%s</p></div>', esc_html( sanitize_text_field( wp_unslash($_GET['dsi_msg']) ) ) );
    if ( isset($_GET['dsi_err']) ) printf('<div class="notice notice-error"><p>%s</p></div>', esc_html( sanitize_text_field( wp_unslash($_GET['dsi_err']) ) ) );

    echo '<div class="wrap"><h1>' . esc_html__( 'VOD Access', 'woocommerce_vod' ) . '</h1>';

    echo '<form method="get" action="" class="dsi-inline-form">';
    echo '<input type="hidden" name="page" value="dsi-vod-access" />';
    echo '<table class="form-table"><tbody>';

    echo '<tr><th><label for="dsi_user">' . esc_html__( 'User (email, login, or ID)', 'woocommerce_vod' ) . '</label></th><td>';
    printf( '<input type="text" class="regular-text dsi-field" id="dsi_user" name="user" value="%s" />', esc_attr( $user_q ) );
    echo '</td></tr>';

    echo '<tr><th><label for="dsi_product">' . esc_html__( 'Product', 'woocommerce_vod' ) . '</label></th><td>';
    printf(
        '<select id="dsi_product" class="wc-product-search dsi-field" name="product_id" data-placeholder="%s" data-allow_clear="true">%s</select>',
        esc_attr__( 'Search for a product…', 'woocommerce_vod' ),
        $product ? sprintf( '<option value="%d" selected="selected">%s (#%d)</option>', $product->get_id(), esc_html( get_the_title( $product->get_id() ) ?: 'Product' ), $product->get_id() ) : ''
    );
    echo '</td></tr>';

    echo '</tbody></table>';
    submit_button( __( 'Lookup', 'woocommerce_vod' ) );
    echo '</form>';

    if ( $user ) {
        echo '<hr />';
        printf( '<h2>%s</h2>', esc_html( sprintf( __( 'User: %s (ID %d)', 'woocommerce_vod' ), $user->user_email, $user->ID ) ) );

        $data  = dsi_vod_get_user_vods_paginated( (int) $user->ID, $paged, $per_page );
        $rows  = $data['rows']; $total = $data['total']; $pages = max(1, (int) ceil( $total / $per_page ) );

        if ( empty( $rows ) ) {
            echo '<p>' . esc_html__( 'This user has no VOD access rows.', 'woocommerce_vod' ) . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>' . esc_html__( 'Product', 'woocommerce_vod' ) . '</th>';
            echo '<th>' . esc_html__( 'Granted', 'woocommerce_vod' ) . '</th>';
            echo '<th style="width:460px">' . esc_html__( 'Revoke', 'woocommerce_vod' ) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( $rows as $r ) {
                $pid   = (int) $r['product_id'];
                $title = get_the_title( $pid ) ?: ( 'Product #' . $pid );
                $when  = $r['ts'] ? mysql2date( get_option('date_format').' '.get_option('time_format'), $r['ts'], true ) : '—';
                $url   = get_permalink( $pid );

                echo '<tr>';
                printf( '<td><a href="%s">%s</a> (#%d)</td>', esc_url( $url ), esc_html( $title ), $pid );
                echo '<td>' . esc_html( $when ) . '</td>';
                echo '<td>';
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dsi-inline-form">';
                echo '<input type="hidden" name="action" value="dsi_vod_revoke" />';
                echo '<input type="hidden" name="user_id" value="' . esc_attr( $user->ID ) . '" />';
                echo '<input type="hidden" name="product_id" value="' . esc_attr( $pid ) . '" />';
                wp_nonce_field( 'dsi_vod_revoke_' . $user->ID . '_' . $pid );
                echo '<input type="text" class="regular-text dsi-reason" name="reason" placeholder="' . esc_attr__( 'Reason (optional)', 'woocommerce_vod' ) . '" /> ';
                submit_button( __( 'Revoke', 'woocommerce_vod' ), 'secondary', '', false );
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            if ( $pages > 1 ) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                $base = add_query_arg( array(
                    'page'       => 'dsi-vod-access',
                    'user'       => rawurlencode( $user_q ),
                    'product_id' => $product_q ?: null,
                    'paged'      => '%#%',
                ), admin_url( 'admin.php' ) );
                echo paginate_links( array( 'base' => $base, 'format' => '', 'current' => $paged, 'total' => $pages ) );
                echo '</div></div>';
            }
        }

        echo '<h3>' . esc_html__( 'Grant access', 'woocommerce_vod' ) . '</h3>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dsi-inline-form">';
        echo '<input type="hidden" name="action" value="dsi_vod_grant" />';
        echo '<input type="hidden" name="user_id" value="' . esc_attr( $user->ID ) . '" />';
        wp_nonce_field( 'dsi_vod_grant_' . $user->ID );
        printf( '<select name="product_id" class="wc-product-search dsi-field" data-placeholder="%s" data-allow_clear="true" required></select> ', esc_attr__( 'Search for a product…', 'woocommerce_vod' ) );
        echo '<input type="text" name="reason" class="regular-text dsi-reason" placeholder="' . esc_attr__( 'Reason (optional)', 'woocommerce_vod' ) . '" /> ';
        submit_button( __( 'Grant Access', 'woocommerce_vod' ), 'primary', '', false );
        echo '</form>';
    }

    if ( $user && $product instanceof WC_Product ) {
        echo '<hr />';
        printf( '<h2>%s</h2>', esc_html( sprintf( __( 'Selected Product: %s (ID %d)', 'woocommerce_vod' ), get_the_title( $product->get_id() ), $product->get_id() ) ) );
        $has = dsi_vod_has_access( (int) $user->ID, (int) $product->get_id() );
        echo $has ? '<p><strong style="color:#1f7a3d">' . esc_html__( 'User currently HAS access.', 'woocommerce_vod' ) . '</strong></p>'
                  : '<p><strong style="color:#b00">'     . esc_html__( 'User does NOT have access.', 'woocommerce_vod' ) . '</strong></p>';

        echo '<p>';
        // Quick GRANT
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dsi-inline-form" style="margin-right:10px">';
        echo '<input type="hidden" name="action" value="dsi_vod_grant" />';
        echo '<input type="hidden" name="user_id" value="' . esc_attr( $user->ID ) . '" />';
        echo '<input type="hidden" name="product_id" value="' . esc_attr( $product->get_id() ) . '" />';
        wp_nonce_field( 'dsi_vod_grant_' . $user->ID );
        echo '<input type="text" name="reason" class="regular-text dsi-reason" placeholder="' . esc_attr__( 'Reason (optional)', 'woocommerce_vod' ) . '" /> ';
        submit_button( __( 'Grant', 'woocommerce_vod' ), 'primary', '', false );
        echo '</form>';
        // Quick REVOKE
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dsi-inline-form">';
        echo '<input type="hidden" name="action" value="dsi_vod_revoke" />';
        echo '<input type="hidden" name="user_id" value="' . esc_attr( $user->ID ) . '" />';
        echo '<input type="hidden" name="product_id" value="' . esc_attr( $product->get_id() ) . '" />';
        wp_nonce_field( 'dsi_vod_revoke_' . $user->ID . '_' . $product->get_id() );
        echo '<input type="text" name="reason" class="regular-text dsi-reason" placeholder="' . esc_attr__( 'Reason (optional)', 'woocommerce_vod' ) . '" /> ';
        submit_button( __( 'Revoke', 'woocommerce_vod' ), 'secondary', '', false );
        echo '</form>';
        echo '</p>';
    }

    echo '</div>';
}

/* ========= Form handlers ========= */

add_action( 'admin_post_dsi_vod_grant', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );
    $user_id    = isset($_REQUEST['user_id']) ? (int) $_REQUEST['user_id'] : 0;
    $product_id = isset($_REQUEST['product_id']) ? (int) $_REQUEST['product_id'] : 0;
    $reason     = isset($_REQUEST['reason']) ? sanitize_text_field( wp_unslash($_REQUEST['reason']) ) : '';
    check_admin_referer( 'dsi_vod_grant_' . $user_id );
    $back = wp_get_referer() ?: admin_url( 'admin.php?page=dsi-vod-access' );
    if ( $user_id <= 0 || $product_id <= 0 ) { wp_safe_redirect( add_query_arg( 'dsi_err', rawurlencode( __( 'Missing user or product.', 'woocommerce_vod' ) ), $back ) ); exit; }
    $msg = dsi_vod_grant( $user_id, $product_id, $reason ) ? __( 'Access granted.', 'woocommerce_vod' ) : __( 'Failed to grant access.', 'woocommerce_vod' );
    wp_safe_redirect( add_query_arg( 'dsi_msg', rawurlencode( $msg ), $back ) ); exit;
} );

add_action( 'admin_post_dsi_vod_revoke', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );
    $user_id    = isset($_REQUEST['user_id']) ? (int) $_REQUEST['user_id'] : 0;
    $product_id = isset($_REQUEST['product_id']) ? (int) $_REQUEST['product_id'] : 0;
    $reason     = isset($_REQUEST['reason']) ? sanitize_text_field( wp_unslash($_REQUEST['reason']) ) : '';
    check_admin_referer( 'dsi_vod_revoke_' . $user_id . '_' . $product_id );
    $back = wp_get_referer() ?: admin_url( 'admin.php?page=dsi-vod-access' );
    if ( $user_id <= 0 || $product_id <= 0 ) { wp_safe_redirect( add_query_arg( 'dsi_err', rawurlencode( __( 'Missing user or product.', 'woocommerce_vod' ) ), $back ) ); exit; }
    $msg = dsi_vod_revoke( $user_id, $product_id, $reason ) ? __( 'Access revoked.', 'woocommerce_vod' ) : __( 'Failed to revoke access.', 'woocommerce_vod' );
    wp_safe_redirect( add_query_arg( 'dsi_msg', rawurlencode( $msg ), $back ) ); exit;
} );

/* ========= User Profile box ========= */

add_action( 'show_user_profile', 'dsi_vod_user_profile_box' );
add_action( 'edit_user_profile', 'dsi_vod_user_profile_box' );
function dsi_vod_user_profile_box( $user ) {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;

    $data = dsi_vod_get_user_vods_paginated( (int) $user->ID, 1, 20 );
    $rows = $data['rows'];

    echo '<h2>' . esc_html__( 'VOD Access', 'woocommerce_vod' ) . '</h2>';
    if ( empty( $rows ) ) {
        echo '<p>' . esc_html__( 'No VOD access for this user.', 'woocommerce_vod' ) . '</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__( 'Product', 'woocommerce_vod' ) . '</th>';
        echo '<th>' . esc_html__( 'Granted', 'woocommerce_vod' ) . '</th>';
        echo '<th>' . esc_html__( 'Revoke', 'woocommerce_vod' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $pid   = (int) $r['product_id'];
            $title = get_the_title( $pid ) ?: ( 'Product #' . $pid );
            $when  = $r['ts'] ? mysql2date( get_option('date_format').' '.get_option('time_format'), $r['ts'], true ) : '—';
            $url   = get_permalink( $pid );
            echo '<tr>';
            printf( '<td><a href="%s">%s</a> (#%d)</td>', esc_url( $url ), esc_html( $title ), $pid );
            echo '<td>' . esc_html( $when ) . '</td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dsi-inline-form">';
            echo '<input type="hidden" name="action" value="dsi_vod_revoke" />';
            echo '<input type="hidden" name="user_id" value="' . esc_attr( $user->ID ) . '" />';
            echo '<input type="hidden" name="product_id" value="' . esc_attr( $pid ) . '" />';
            wp_nonce_field( 'dsi_vod_revoke_' . $user->ID . '_' . $pid );
            echo '<input type="text" name="reason" class="regular-text dsi-reason" placeholder="' . esc_attr__( 'Reason (optional)', 'woocommerce_vod' ) . '" /> ';
            submit_button( __( 'Revoke', 'woocommerce_vod' ), 'secondary', '', false );
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '<h3>' . esc_html__( 'Grant access', 'woocommerce_vod' ) . '</h3>';
    echo '<table class="form-table"><tbody><tr><th>' . esc_html__( 'Product', 'woocommerce_vod' ) . '</th><td>';
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dsi-inline-form">';
    echo '<input type="hidden" name="action" value="dsi_vod_grant" />';
    echo '<input type="hidden" name="user_id" value="' . esc_attr( $user->ID ) . '" />';
    wp_nonce_field( 'dsi_vod_grant_' . $user->ID );
    printf( '<select name="product_id" class="wc-product-search dsi-field" data-placeholder="%s" data-allow_clear="true" required></select> ', esc_attr__( 'Search for a product…', 'woocommerce_vod' ) );
    echo '<input type="text" name="reason" class="regular-text dsi-reason" placeholder="' . esc_attr__( 'Reason (optional)', 'woocommerce_vod' ) . '" /> ';
    submit_button( __( 'Grant Access', 'woocommerce_vod' ), 'primary', '', false );
    echo '</form>';
    echo '</td></tr></tbody></table>';
}

/* ========= Order edit screen meta box ========= */

add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'dsi_vod_order_access',
        __( 'VOD Access', 'woocommerce_vod' ),
        'dsi_vod_render_order_meta_box',
        'shop_order',
        'side', // or 'normal' if you want more space
        'high'
    );
} );

function dsi_vod_render_order_meta_box( WP_Post $post ) {
    if ( ! current_user_can( 'manage_woocommerce' ) ) { echo '<p>'.esc_html__( 'No permission.', 'woocommerce_vod' ).'</p>'; return; }
    $order = wc_get_order( $post->ID );
    if ( ! $order ) { echo '<p>'.esc_html__( 'Invalid order.', 'woocommerce_vod' ).'</p>'; return; }

    $user_id = (int) $order->get_user_id();
    if ( ! $user_id ) { echo '<p>'.esc_html__( 'Guest order (no user).', 'woocommerce_vod' ).'</p>'; return; }

    // Collect VOD items (variation id if present, else product id)
    $vod_items = array();
    foreach ( $order->get_items() as $item ) {
        if ( ! $item instanceof WC_Order_Item_Product ) continue;
        if ( ! dsi_vod_is_order_item_vod( $item ) ) continue;

        $pid = (int) ( $item->get_variation_id() ?: $item->get_product_id() );
        if ( $pid && ! isset( $vod_items[ $pid ] ) ) {
            $vod_items[ $pid ] = array(
                'title' => get_the_title( $pid ) ?: ( 'Product #'.$pid ),
                'has'   => dsi_vod_has_access( $user_id, $pid ),
            );
        }
    }

    if ( empty( $vod_items ) ) { echo '<p>'.esc_html__( 'No streaming videos detected in this order.', 'woocommerce_vod' ).'</p>'; return; }

    echo '<div class="dsi-vod-meta">';

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>'.esc_html__( 'Item', 'woocommerce_vod' ).'</th>';
    echo '<th>'.esc_html__( 'Access', 'woocommerce_vod' ).'</th>';
    echo '</tr></thead><tbody>';

    foreach ( $vod_items as $pid => $row ) {
        echo '<tr>';
        echo '<td>'.esc_html( $row['title'] ).' (#'.(int)$pid.')</td>';
        echo '<td>'.( $row['has']
            ? '<span style="color:#1f7a3d">'.esc_html__( 'Granted', 'woocommerce_vod' ).'</span>'
            : '<span style="color:#b00">'.esc_html__( 'Not granted', 'woocommerce_vod' ).'</span>'
        ).'</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    // Bulk grant/revoke for all VOD items in this order
    echo '<h4>'.esc_html__( 'Bulk action (all VOD items)', 'woocommerce_vod' ).'</h4>';
    echo '<form method="post" action="'.esc_url( admin_url('admin-post.php') ).'" class="dsi-inline-form">';
    echo '<input type="hidden" name="action" value="dsi_vod_order_bulk" />';
    echo '<input type="hidden" name="order_id" value="'.esc_attr( $order->get_id() ).'" />';
    echo '<input type="hidden" name="user_id" value="'.esc_attr( $user_id ).'" />';
    foreach ( array_keys( $vod_items ) as $pid ) {
        echo '<input type="hidden" name="product_ids[]" value="'.esc_attr( $pid ).'" />';
    }
    wp_nonce_field( 'dsi_vod_order_bulk_'.$order->get_id() );
    echo '<input type="text" name="reason" class="regular-text dsi-reason" placeholder="'.esc_attr__( 'Reason (optional)', 'woocommerce_vod' ).'" /> ';
    submit_button( __( 'Grant All', 'woocommerce_vod' ), 'primary', 'grant_all', false );
    echo ' ';
    submit_button( __( 'Revoke All', 'woocommerce_vod' ), 'secondary', 'revoke_all', false );
    echo '</form>';

    echo '</div>';
}

/* ========= Order actions handlers ========= */

// Bulk: grant_all or revoke_all buttons
add_action( 'admin_post_dsi_vod_order_bulk', function() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden', 403 );
    $order_id   = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
    $user_id    = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $product_ids = isset($_POST['product_ids']) ? array_map( 'intval', (array) $_POST['product_ids'] ) : array();
    $reason     = isset($_POST['reason']) ? sanitize_text_field( wp_unslash($_POST['reason']) ) : '';
    check_admin_referer( 'dsi_vod_order_bulk_'.$order_id );
    $back = wp_get_referer() ?: admin_url( 'post.php?post='.$order_id.'&action=edit' );
    if ( $order_id<=0 || $user_id<=0 || empty($product_ids) ) { wp_safe_redirect( add_query_arg( 'dsi_err', rawurlencode( __( 'Missing fields.', 'woocommerce_vod' ) ), $back ) ); exit; }

    $granting = isset($_POST['grant_all']) && ! isset($_POST['revoke_all']);
    $revoking = isset($_POST['revoke_all']);

    $ok = 0;
    foreach ( $product_ids as $pid ) {
        if ( $granting ) {
            if ( dsi_vod_has_access( $user_id, $pid ) ) { continue; }
            if ( dsi_vod_grant( $user_id, $pid, $reason ) ) $ok++;
        } elseif ( $revoking ) {
            if ( ! dsi_vod_has_access( $user_id, $pid ) ) { continue; }
            if ( dsi_vod_revoke( $user_id, $pid, $reason ) ) $ok++;
        }
    }
    $msg = $granting ? sprintf( _n( '%d item granted.', '%d items granted.', $ok, 'woocommerce_vod' ), $ok )
                     : sprintf( _n( '%d item revoked.', '%d items revoked.', $ok, 'woocommerce_vod' ), $ok );
    wp_safe_redirect( add_query_arg( 'dsi_msg', rawurlencode( $msg ), $back ) ); exit;
} );

