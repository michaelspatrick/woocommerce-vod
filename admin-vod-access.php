<?php
/**
 * Admin UI: VOD Access Manager
 * - WooCommerce → VOD Access admin page (AJAX product search, pagination)
 * - User Profile box (grant/revoke)
 * - Order edit screen meta box (status + Grant All / Revoke All only)
 * - Audit table (grants/revokes with reason)
 * - Uses ONLY `ts` column in wp_woocommerce_vod
 * - All CSS moved to vod.css (enqueue below)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ====================== Helpers & Tables ====================== */

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
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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
        return get_user_by( 'login', $needle );
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

if ( ! function_exists( 'dsi_vod_audit' ) ) {
    function dsi_vod_audit( int $user_id, int $product_id, string $action, string $reason = '' ): void {
        global $wpdb;
        $wpdb->insert(
            dsi_vod_audit_table_name(),
            array(
                'user_id'    => $user_id,
                'product_id' => $product_id,
                'action'     => sanitize_key( $action ),
                'reason'     => $reason,
                'actor_id'   => (int) get_current_user_id(),
            ),
            array( '%d','%d','%s','%s','%d' )
        );
    }
}

if ( ! function_exists( 'dsi_vod_grant' ) ) {
    function dsi_vod_grant( int $user_id, int $product_id, string $reason = '' ): bool {
        global $wpdb;
        $ok = $wpdb->insert( dsi_vod_table_name(), array( 'user_id' => $user_id, 'product_id' => $product_id ), array( '%d','%d' ) );
        dsi_vod_audit( $user_id, $product_id, 'grant', $reason );
        return $ok !== false;
    }
}

if ( ! function_exists( 'dsi_vod_revoke' ) ) {
    function dsi_vod_revoke( int $user_id, int $product_id, string $reason = '' ): bool {
        global $wpdb;
        $ok = $wpdb->delete( dsi_vod_table_name(), array( 'user_id' => $user_id, 'product_id' => $product_id ), array( '%d','%d' ) );
        dsi_vod_audit( $user_id, $product_id, 'revoke', $reason );
        return $ok !== false;
    }
}


if ( ! function_exists( 'dsi_vod_grant_access' ) ) {
    /**
     * Backward‑compatible wrapper used by the order meta box bulk actions.
     * Includes the order ID in the audit reason (if provided).
     */
    function dsi_vod_grant_access( int $user_id, int $product_id, int $order_id = 0, string $reason = '' ): bool {
        if ( ! function_exists( 'dsi_vod_grant' ) ) {
            return false;
        }

        $full_reason = $reason;
        if ( $order_id > 0 ) {
            $prefix = sprintf( '[order #%d] ', $order_id );
            $full_reason = $prefix . ( $reason !== '' ? $reason : '' );
        }

        return dsi_vod_grant( $user_id, $product_id, $full_reason );
    }
}

if ( ! function_exists( 'dsi_vod_revoke_access' ) ) {
    /**
     * Backward‑compatible wrapper used by the order meta box bulk actions.
     * Includes the order ID in the audit reason (if provided).
     */
    function dsi_vod_revoke_access( int $user_id, int $product_id, int $order_id = 0, string $reason = '' ): bool {
        if ( ! function_exists( 'dsi_vod_revoke' ) ) {
            return false;
        }

        $full_reason = $reason;
        if ( $order_id > 0 ) {
            $prefix = sprintf( '[order #%d] ', $order_id );
            $full_reason = $prefix . ( $reason !== '' ? $reason : '' );
        }

        return dsi_vod_revoke( $user_id, $product_id, $full_reason );
    }
}

if ( ! function_exists( 'dsi_vod_get_user_vods_paginated' ) ) {
    function dsi_vod_get_user_vods_paginated( int $user_id, int $page, int $per_page ): array {
        global $wpdb;
        $offset = max( 0, ($page - 1) * $per_page );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT SQL_CALC_FOUND_ROWS product_id, ts
             FROM " . dsi_vod_table_name() . "
             WHERE user_id=%d
             ORDER BY ts DESC
             LIMIT %d OFFSET %d",
            $user_id, $per_page, $offset
        ), ARRAY_A );
        $total = (int) $wpdb->get_var( "SELECT FOUND_ROWS()" );
        return array( 'rows' => $rows ?: array(), 'total' => $total, 'page' => $page, 'per_page' => $per_page );
    }
}

/* Detect if an order line is a VOD */
if ( ! function_exists( 'dsi_vod_is_order_item_vod' ) ) {
    function dsi_vod_is_order_item_vod( WC_Order_Item_Product $item ): bool {
        foreach ( array( 'attribute_pa_video_format', 'pa_video_format' ) as $k ) {
            $v = (string) $item->get_meta( $k, true );
            if ( $v !== '' ) {
                $v = strtolower( trim( $v ) );
                if ( $v === 'video_format_streaming' || $v === 'video_format_dvd_plus_streaming' ) return true;
            }
        }
        $product = $item->get_product();
        return ( $product instanceof WC_Product && $product->get_type() === 'vod' );
    }
}

/* ====================== Assets (enqueue CSS/JS) ====================== */
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( in_array( $hook, ['user-edit.php','profile.php','woocommerce_page_dsi-vod-access','post.php','post-new.php'], true ) ) {
        if ( function_exists('WC') ) {
            wp_enqueue_script('selectWoo');
            wp_enqueue_style('select2');
            wp_enqueue_script('wc-enhanced-select');
            wp_enqueue_style('woocommerce_admin_styles');
        }
        wp_enqueue_style('dsi-vod-admin', plugins_url('vod.css', __FILE__), [], '1.0.0');
    }
});

/* Init selectWoo on pages where we render product pickers using Woo's endpoint */
add_action( 'admin_footer', function() {
    $screen = get_current_screen();
    if ( ! $screen ) return;
    if ( ! in_array( $screen->id, array( 'woocommerce_page_dsi-vod-access', 'user-edit', 'profile', 'shop_order' ), true ) ) return;
    ?>
<script>
(function($){
    function init($ctx){
        $ctx.find('.wc-product-search').filter(':not(.enhanced)').each(function(){
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
                        return {
                            term: params.term || '',
                            action: 'woocommerce_json_search_products_and_variations',
                            security: '<?php echo esc_js( wp_create_nonce( 'search-products' ) ); ?>'
                        };
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
        });
    }
    $(document).ready(function(){ init($(document)); });
})(jQuery);
</script>
<?php
} );

/* ====================== Admin Menu (VOD Access) ====================== */

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
                echo '<input type="text" class="regular-text vod-reason-field" name="reason" placeholder="' . esc_attr__( 'Reason (optional)', 'woocommerce_vod' ) . '" /> ';
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
        echo '<input type="text" name="reason" class="regular-text vod-reason-field" placeholder="' . esc_attr__( 'Reason (optional)', 'woocommerce_vod' ) . '" /> ';
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
        echo '<input type="text" name="reason" class="regular-text vod-reason-field" placeholder="' . esc_attr__( 'Reason (optional)', 'woocommerce_vod' ) . '" /> ';
        submit_button( __( 'Grant', 'woocommerce_vod' ), 'primary', '', false );
        echo '</form>';
        // Quick REVOKE
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dsi-inline-form">';
        echo '<input type="hidden" name="action" value="dsi_vod_revoke" />';
        echo '<input type="hidden" name="user_id" value="' . esc_attr( $user->ID ) . '" />';
        echo '<input type="hidden" name="product_id" value="' . esc_attr( $product->get_id() ) . '" />';
        wp_nonce_field( 'dsi_vod_revoke_' . $user->ID . '_' . $product->get_id() );
        echo '<input type="text" name="reason" class="regular-text vod-reason-field" placeholder="' . esc_attr__( 'Reason (optional)', 'woocommerce_vod' ) . '" /> ';
        submit_button( __( 'Revoke', 'woocommerce_vod' ), 'secondary', '', false );
        echo '</form>';
        echo '</p>';
    }

    echo '</div>';
}

/* ====================== Form handlers ====================== */

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

/* ====================== User Profile box ====================== */

add_action( 'show_user_profile', 'dsi_vod_user_profile_box' );
add_action( 'edit_user_profile', 'dsi_vod_user_profile_box' );
function dsi_vod_user_profile_box( $user ) {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    $data = dsi_vod_get_user_vods_paginated( (int) $user->ID, 1, 50 );
    $rows = isset( $data['rows'] ) ? $data['rows'] : array();

    echo '<div class="dsi-vod-meta">';
    echo '<h2>' . esc_html__( 'VOD Access', 'woocommerce_vod' ) . '</h2>';

    $admin_page_url = add_query_arg(
        array( 'user_id' => (int) $user->ID ),
        admin_url( 'admin.php?page=dsi-vod-access' )
    );

    echo '<p>' . sprintf(
        esc_html__( 'To grant or revoke VOD access for this user, use the %s screen.', 'woocommerce_vod' ),
        '<a href="' . esc_url( $admin_page_url ) . '">' . esc_html__( 'VOD Access', 'woocommerce_vod' ) . '</a>'
    ) . '</p>';

    if ( empty( $rows ) ) {
        echo '<p>' . esc_html__( 'This user currently has no VOD access entries.', 'woocommerce_vod' ) . '</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__( 'Product', 'woocommerce_vod' ) . '</th>';
        echo '<th>' . esc_html__( 'Granted on', 'woocommerce_vod' ) . '</th>';
        echo '<th>' . esc_html__( 'Reason', 'woocommerce_vod' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $product = wc_get_product( $row['product_id'] );
            if ( ! $product ) {
                continue;
            }

            echo '<tr>';
            echo '<td><a href="' . esc_url( get_edit_post_link( $product->get_id() ) ) . '">' . esc_html( $product->get_name() ) . '</a></td>';
            echo '<td>' . esc_html( isset( $row['granted_at'] ) ? $row['granted_at'] : '' ) . '</td>';
            echo '<td>' . esc_html( isset( $row['reason'] ) ? $row['reason'] : '' ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '</div>';
}

/* ====================== Order edit screen ====================== */

add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'dsi_vod_order_access',
        __( 'VOD Access', 'woocommerce_vod' ),
        'dsi_vod_render_order_meta_box',
        'shop_order',
        'side',
        'high'
    );
} );

function dsi_vod_render_order_meta_box( WP_Post $post ) {
    if ( ! current_user_can( 'manage_woocommerce' ) ) { echo '<p>'.esc_html__( 'No permission.', 'woocommerce_vod' ).'</p>'; return; }
    $order = wc_get_order( $post->ID );
    if ( ! $order ) { echo '<p>'.esc_html__( 'Invalid order.', 'woocommerce_vod' ).'</p>'; return; }

    $user_id = (int) $order->get_user_id();
    if ( ! $user_id ) { echo '<p>'.esc_html__( 'Guest order (no user).', 'woocommerce_vod' ).'</p>'; return; }

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
    echo '<table class="widefat striped"><thead><tr><th>'.esc_html__( 'Item', 'woocommerce_vod' ).'</th><th>'.esc_html__( 'Access', 'woocommerce_vod' ).'</th></tr></thead><tbody>';
    foreach ( $vod_items as $pid => $row ) {
        echo '<tr>';
        echo '<td>'.esc_html( $row['title'] ).' (#'.(int)$pid.')</td>';
        echo '<td>'.( $row['has'] ? '<span style="color:#1f7a3d">'.esc_html__( 'Granted', 'woocommerce_vod' ).'</span>' : '<span style="color:#b00">'.esc_html__( 'Not granted', 'woocommerce_vod' ).'</span>' ).'</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<h4>'.esc_html__( 'Bulk action (all VOD items)', 'woocommerce_vod' ).'</h4>';
    
    if ( ! empty( $vod_items ) ) {
        $ids      = implode( ',', array_map( 'intval', array_keys( $vod_items ) ) );
        $base_url = admin_url( 'admin-post.php' );

        $grant_url  = wp_nonce_url(
            add_query_arg(
                array(
                    'action'      => 'dsi_vod_order_bulk',
                    'mode'        => 'grant',
                    'order_id'    => $order->get_id(),
                    'user_id'     => $user_id,
                    'product_ids' => $ids,
                ),
                $base_url
            ),
            'dsi_vod_order_bulk_' . $order->get_id()
        );

        $revoke_url = wp_nonce_url(
            add_query_arg(
                array(
                    'action'      => 'dsi_vod_order_bulk',
                    'mode'        => 'revoke',
                    'order_id'    => $order->get_id(),
                    'user_id'     => $user_id,
                    'product_ids' => $ids,
                ),
                $base_url
            ),
            'dsi_vod_order_bulk_' . $order->get_id()
        );

        echo '<p>';
        echo '<a href="' . esc_url( $grant_url ) . '" class="button button-primary">' . esc_html__( 'Grant All', 'woocommerce_vod' ) . '</a> ';
        echo '<a href="' . esc_url( $revoke_url ) . '" class="button">' . esc_html__( 'Revoke All', 'woocommerce_vod' ) . '</a>';
        echo '</p>';
    }


    echo '</div>';
}

/* Order bulk actions handler */
add_action( 'admin_post_dsi_vod_order_bulk', function () {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'You do not have permission to manage VOD access.', 'woocommerce_vod' ) );
    }

    $request   = wp_unslash( $_REQUEST );
    $order_id  = isset( $request['order_id'] ) ? absint( $request['order_id'] ) : 0;
    $user_id   = isset( $request['user_id'] ) ? absint( $request['user_id'] ) : 0;
    $products  = isset( $request['product_ids'] ) ? $request['product_ids'] : array();
    $reason    = isset( $request['reason'] ) ? wc_clean( $request['reason'] ) : '';
    $mode      = '';

    // Support both ?mode=grant / ?mode=revoke and old grant_all / revoke_all submit buttons.
    if ( ! empty( $request['mode'] ) ) {
        $mode = sanitize_key( $request['mode'] );
    } elseif ( ! empty( $request['grant_all'] ) ) {
        $mode = 'grant';
    } elseif ( ! empty( $request['revoke_all'] ) ) {
        $mode = 'revoke';
    }

    if ( is_string( $products ) ) {
        $product_ids = array_filter( array_map( 'absint', array_map( 'trim', explode( ',', $products ) ) ) );
    } else {
        $product_ids = array_map( 'absint', (array) $products );
    }

    check_admin_referer( 'dsi_vod_order_bulk_' . $order_id );

    if ( ! $order_id || ! $user_id || empty( $product_ids ) ) {
        $back = wp_get_referer() ? wp_get_referer() : admin_url( 'post.php?post=' . $order_id . '&action=edit' );
        $back = add_query_arg( 'dsi_err', rawurlencode( __( 'Missing order, user or product information for VOD bulk action.', 'woocommerce_vod' ) ), $back );
        wp_safe_redirect( $back );
        exit;
    }

    if ( 'grant' === $mode ) {
        foreach ( $product_ids as $pid ) {
            dsi_vod_grant_access( $user_id, $pid, $order_id, $reason );
        }
        $message = __( 'VOD access granted for all matching products in this order.', 'woocommerce_vod' );
    } elseif ( 'revoke' === $mode ) {
        foreach ( $product_ids as $pid ) {
            dsi_vod_revoke_access( $user_id, $pid, $order_id, $reason );
        }
        $message = __( 'VOD access revoked for all matching products in this order.', 'woocommerce_vod' );
    } else {
        $back = wp_get_referer() ? wp_get_referer() : admin_url( 'post.php?post=' . $order_id . '&action=edit' );
        $back = add_query_arg( 'dsi_err', rawurlencode( __( 'Unknown VOD bulk action.', 'woocommerce_vod' ) ), $back );
        wp_safe_redirect( $back );
        exit;
    }

    $back = wp_get_referer() ? wp_get_referer() : admin_url( 'post.php?post=' . $order_id . '&action=edit' );
    $back = add_query_arg( 'dsi_msg', rawurlencode( $message ), $back );
    wp_safe_redirect( $back );
    exit;
} );

