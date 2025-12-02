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

/**
 * List orphan VOD rows.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    class DSI_VOD_CLI_Orphans {
        /**
         * List orphan rows in wp_woocommerce_vod.
         *
         * ## EXAMPLES
         *
         *     wp vod list --orphans
         *
         * @when after_wp_load
         */
        public function list( $args, $assoc_args ) {
            global $wpdb;

            if ( ! isset( $assoc_args['orphans'] ) ) {
                WP_CLI::error( "Please specify --orphans to list orphaned VOD rows." );
                return;
            }


            $table = $wpdb->prefix . 'woocommerce_vod';

            $only_published = ! isset( $assoc_args['include-unpublished'] ); // default true
            $results = dsi_vod_get_orphans( $only_published );

            if ( empty( $results ) ) {
                WP_CLI::success( "No orphan rows found." );
                return;
            }

            $items = [];
            foreach ( $results as $row ) {
                $items[] = [
                    'id'         => $row['id'],
                    'user_id'    => $row['user_id'],
                    'product_id' => $row['product_id'],
                    'ts'         => $row['ts'],
                ];
            }

            WP_CLI\Utils\format_items( 'table', $items, [ 'id', 'user_id', 'product_id', 'ts' ] );
            WP_CLI::log( "Total orphan rows: " . count( $items ) );
        }
    }

    WP_CLI::add_command( 'vod list', [ 'DSI_VOD_CLI_Orphans', 'list' ] );
}

// --- WP-CLI: VOD check + backfill ---
if ( defined('WP_CLI') && WP_CLI ) {

    if ( ! function_exists( 'dsi_vod_table_name' ) ) {
        function dsi_vod_table_name() { global $wpdb; return $wpdb->prefix . 'woocommerce_vod'; }
    }

    // Fallback: detect if an order line is a VOD (same logic as admin)
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

    class DSI_VOD_CLI_Check {
        /**
         * Check VOD table consistency, optionally backfill and/or delete orphans.
         *
         * ## OPTIONS
         *
         * [--fix]
         * : Delete orphan rows from the VOD table.
         *
         * [--backfill]
         * : Insert missing rows from historical paid orders (processing/completed/refunded).
         *
         * [--since=<date>]
         * : Only consider orders created on/after this date (YYYY-mm-dd). Speeds up backfill.
         *
         * [--limit=<n>]
         * : Max number of orders to scan when backfilling (default: no limit).
         *
         * [--dry-run]
         * : Show what would change without writing to the database.
         *
         * ## EXAMPLES
         *
         *   # Just see counts
         *   wp vod check
         *
         *   # Delete orphans
         *   wp vod check --fix
         *
         *   # Backfill missing rows from this year (no DB writes)
         *   wp vod check --backfill --since=2025-01-01 --dry-run
         *
         *   # Backfill and delete orphans
         *   wp vod check --backfill --fix
         *
         * @when after_wp_load
         */

public function __invoke( $args, $assoc ) {
    global $wpdb;

    $do_fix       = isset( $assoc['fix'] );
    $do_backfill  = isset( $assoc['backfill'] );
    $since        = isset( $assoc['since'] ) ? sanitize_text_field( $assoc['since'] ) : '';
    $limit        = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 0;
    $dry_run      = isset( $assoc['dry-run'] );
    $only_pub     = ! isset( $assoc['include-unpublished'] );

    $table = dsi_vod_table_name();

    // --- 1) ORPHANS (to delete) ---
    $orphans = dsi_vod_get_orphans( $only_pub ); // returns ARRAY_A
    $orphan_ids = array_map( static function( $r ){ return (int) $r['id']; }, $orphans );
    sort( $orphan_ids, SORT_NUMERIC );

    // Print preview of deletes
    if ( $do_fix ) {
        WP_CLI::log( 'Orphans detected: ' . count( $orphans ) );
        if ( $dry_run ) {
            if ( ! empty( $orphans ) ) {
                WP_CLI\Utils\format_items( 'table', $orphans, [ 'id','user_id','product_id','ts' ] );
            }
            WP_CLI::log( 'DELETE checksum: ' . sha1( implode( ',', $orphan_ids ) ) );
        }
    }

    // --- 2) MISSING (to insert) ---
    $missing_pairs = []; // [ "user:product" => [user_id, product_id] ]

    if ( $do_backfill ) {
        $existing = $wpdb->get_results( "SELECT user_id, product_id FROM {$table}", ARRAY_A ) ?: [];
        $has = [];
        foreach ( $existing as $r ) { $has[ (int)$r['user_id'] ][ (int)$r['product_id'] ] = true; }

        $paged = 1; $per = 100; $scanned = 0;
        $status = [ 'processing','completed','refunded' ];

        WP_CLI::log( sprintf( 'Scanning orders%s…', $since ? ' since ' . $since : '' ) );

        while ( true ) {
            $q = [
                'type'     => 'shop_order',
                'status'   => $status,
                'paginate' => true,
                'limit'    => $per,
                'page'     => $paged,
                'orderby'  => 'date',
                'order'    => 'ASC',
            ];
            if ( $since ) { $q['date_created'] = '>=' . $since; }

            $res = wc_get_orders( $q );
            if ( empty( $res->orders ) ) break;

            foreach ( $res->orders as $order ) {
                /** @var WC_Order $order */
                $scanned++;
                if ( $limit && $scanned > $limit ) { break 2; }

                $uid = (int) $order->get_user_id();
                if ( ! $uid ) continue; // skip guests

                foreach ( $order->get_items() as $item ) {
                    if ( ! $item instanceof WC_Order_Item_Product ) continue;
                    if ( ! dsi_vod_is_order_item_vod( $item ) ) continue;

                    $pid = (int) ( $item->get_variation_id() ?: $item->get_product_id() );
                    if ( $pid <= 0 ) continue;

                    if ( empty( $has[$uid][$pid] ) ) {
                        $key = $uid . ':' . $pid;
                        $missing_pairs[ $key ] = [ 'user_id' => $uid, 'product_id' => $pid ];
                    }
                }
            }
            $paged++;
        }

        // Deterministic order & checksum for preview
        ksort( $missing_pairs, SORT_STRING );
        $missing_list = array_values( $missing_pairs );
        $insert_sig = sha1( implode( ',', array_map( static function( $r ){ return $r['user_id'] . ':' . $r['product_id']; }, $missing_list ) ) );

        WP_CLI::log( 'Missing rows (needed): ' . count( $missing_list ) );
        if ( $dry_run && ! empty( $missing_list ) ) {
            WP_CLI\Utils\format_items( 'table', $missing_list, [ 'user_id', 'product_id' ] );
            WP_CLI::log( 'INSERT checksum: ' . $insert_sig );
        }

        // Perform inserts only if not dry-run
        if ( ! $dry_run && ! empty( $missing_list ) ) {
            $inserted = 0;
            foreach ( $missing_list as $row ) {
                $ok = $wpdb->insert( $table, [ 'user_id' => (int)$row['user_id'], 'product_id' => (int)$row['product_id'] ], [ '%d','%d' ] );
                if ( $ok !== false ) $inserted++;
            }
            WP_CLI::log( 'Backfilled rows: ' . $inserted );
        }
    }

    // --- 3) Perform deletes only if requested and not dry-run ---
    if ( $do_fix && ! $dry_run && ! empty( $orphan_ids ) ) {
        $deleted = 0;
        foreach ( array_chunk( $orphan_ids, 500 ) as $chunk ) {
            $in = implode( ',', array_map( 'intval', $chunk ) );
            $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" );
            $deleted += count( $chunk );
        }
        WP_CLI::log( 'Deleted orphans: ' . $deleted );
    }

    // --- 4) Summary ---
    WP_CLI::log( '---' );
    WP_CLI::log( 'Orphan rows: ' . count( $orphan_ids ) . ( $do_fix && $dry_run ? ' [DRY RUN]' : '' ) );
    if ( $do_backfill ) {
        WP_CLI::log( 'Missing rows: ' . ( isset( $missing_list ) ? count( $missing_list ) : 0 ) . ( $dry_run ? ' [DRY RUN]' : '' ) );
    }
    WP_CLI::success( 'Check complete.' );
}

    }

    WP_CLI::add_command( 'vod check', 'DSI_VOD_CLI_Check' );
}

function dsi_vod_get_orphans( $only_published = true ) {
    global $wpdb;
    $table = $wpdb->prefix . 'woocommerce_vod';

    // Which statuses count as "valid visibility"
    $valid_status = $only_published ? "('publish','private')" : "('publish','private','draft','pending','future')";
    // A row is orphan if:
    // - There is no post for product_id, OR
    // - The post_type isn’t product/product_variation, OR
    // - The post is a product but not in valid status, OR
    // - The post is a product_variation and is not in valid status, OR
    // - The post is a product_variation whose PARENT product is missing or not in valid status.
    $sql = "
        SELECT v.id, v.user_id, v.product_id, v.ts
        FROM {$table} v
        LEFT JOIN {$wpdb->posts} p
            ON p.ID = v.product_id
        LEFT JOIN {$wpdb->posts} parent
            ON (p.post_type = 'product_variation' AND parent.ID = p.post_parent)
        WHERE
            p.ID IS NULL
            OR p.post_type NOT IN ('product','product_variation')
            OR (p.post_type = 'product' AND p.post_status NOT IN {$valid_status})
            OR (p.post_type = 'product_variation' AND (
                    p.post_status NOT IN {$valid_status}
                    OR parent.ID IS NULL
                    OR parent.post_type <> 'product'
                    OR parent.post_status NOT IN {$valid_status}
               ))
    ";

    return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
}

