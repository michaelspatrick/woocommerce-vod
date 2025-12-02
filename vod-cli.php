<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( defined('WP_CLI') && WP_CLI ) {

    if ( ! function_exists( 'dsi_vod_is_vod_product' ) ) {
        function dsi_vod_is_vod_product( $product ) {
            if ( ! $product instanceof WC_Product ) return false;
            if ( $product->get_type() === 'vod' ) return true;
            $attr = $product->get_attribute( 'pa_video_format' );
            $val  = is_string( $attr ) ? strtolower( trim( $attr ) ) : '';
            return in_array( $val, array( 'video_format_streaming','video_format_dvd_plus_streaming' ), true );
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

    class DSI_VOD_CLI {

        public function backfill( $args, $assoc_args ) {
            $since   = isset( $assoc_args['since'] ) ? sanitize_text_field( $assoc_args['since'] ) : null;
            $dry_run = isset( $assoc_args['dry-run'] );

            $statuses = array( 'processing', 'completed' );
            $page = 1;
            $total_insert = 0;

            global $wpdb;
            $table = $wpdb->prefix . 'woocommerce_vod';

            while ( true ) {
                $query = array( 'status'=>$statuses, 'limit'=>100, 'page'=>$page, 'paginate'=>true, 'return'=>'objects' );
                if ( $since ) $query['date_created'] = '>=' . $since;

                $results = wc_get_orders( $query );
                if ( empty( $results->orders ) ) break;

                foreach ( $results->orders as $order ) {
                    if ( ! $order instanceof WC_Order ) continue;
                    $user_id = (int) $order->get_user_id();
                    if ( $user_id <= 0 ) continue;

                    foreach ( $order->get_items() as $item ) {
                        if ( ! $item instanceof WC_Order_Item_Product ) continue;
                        $product = $item->get_product();
                        if ( ! $product instanceof WC_Product ) continue;

                        $variation_id = (int) $item->get_variation_id();
                        $product_id   = (int) $item->get_product_id();
                        $effective_id = $variation_id ?: $product_id;

                        if ( dsi_vod_is_order_item_vod( $item, $product ) && $effective_id ) {
                            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                                "SELECT 1 FROM {$table} WHERE user_id=%d AND product_id=%d LIMIT 1",
                                $user_id, $effective_id
                            ) );
                            if ( $exists !== 1 ) {
                                if ( $dry_run ) {
                                    \WP_CLI::line( sprintf('Would insert: u=%d p=%d order=%d', $user_id, $effective_id, $order->get_id() ) );
                                } else {
                                    $ok = $wpdb->insert( $table, array(
                                        'user_id'=>$user_id, 'product_id'=>$effective_id, 'ts'=>current_time('mysql',1)
                                    ), array('%d','%d','%s') );
                                    if ( false === $ok ) {
                                        \WP_CLI::warning( sprintf('Insert failed u=%d p=%d order=%d: %s', $user_id, $effective_id, $order->get_id(), $wpdb->last_error ) );
                                    } else { $total_insert++; }
                                }
                            }
                        }
                    }
                }
                $page++;
            }

            if ( $dry_run ) \WP_CLI::success( 'Dry run complete.' );
            else \WP_CLI::success( sprintf('Backfill complete. Inserted %d rows.', $total_insert) );
        }

        public function check( $args, $assoc_args ) {
            $since = isset( $assoc_args['since'] ) ? sanitize_text_field( $assoc_args['since'] ) : null;
            $fix   = isset( $assoc_args['fix'] );

            global $wpdb;
            $table = $wpdb->prefix . 'woocommerce_vod';

            $orders_set = [];
            $page = 1;
            $statuses = array( 'processing', 'completed' );

            while ( true ) {
                $query = array( 'status'=>$statuses, 'limit'=>100, 'page'=>$page, 'paginate'=>true, 'return'=>'objects' );
                if ( $since ) $query['date_created'] = '>=' . $since;

                $results = wc_get_orders( $query );
                if ( empty( $results->orders ) ) break;

                foreach ( $results->orders as $order ) {
                    if ( ! $order instanceof WC_Order ) continue;
                    $user_id = (int) $order->get_user_id();
                    if ( $user_id <= 0 ) continue;

                    foreach ( $order->get_items() as $item ) {
                        if ( ! $item instanceof WC_Order_Item_Product ) continue;
                        $product = $item->get_product();
                        if ( ! $product instanceof WC_Product ) continue;

                        $variation_id = (int) $item->get_variation_id();
                        $product_id   = (int) $item->get_product_id();
                        $effective_id = $variation_id ?: $product_id;

                        if ( dsi_vod_is_order_item_vod( $item, $product ) && $effective_id ) {
                            $orders_set[ $user_id . ':' . $effective_id ] = true;
                        }
                    }
                }
                $page++;
            }

            $rows = $wpdb->get_results( "SELECT user_id, product_id FROM {$table}", ARRAY_A );
            $table_set = [];
            foreach ( (array)$rows as $r ) {
                $table_set[ (int)$r['user_id'] . ':' . (int)$r['product_id'] ] = true;
            }

            $missing = array_diff_key( $orders_set, $table_set );
            $orphan  = array_diff_key( $table_set, $orders_set );

            \WP_CLI::line( sprintf('Missing rows: %d', count($missing)) );
            \WP_CLI::line( sprintf('Orphan rows: %d', count($orphan)) );

            if ( ! $fix ) {
                if ( $missing ) \WP_CLI::line('Use --fix to insert missing rows.');
                if ( $orphan )  \WP_CLI::line('Use --fix to delete orphan rows.');
                \WP_CLI::success('Check complete.');
                return;
            }

            $inserted=0; $deleted=0;
            foreach ( array_keys($missing) as $key ) {
                list($u,$p) = array_map('intval', explode(':', $key));
                $ok = $wpdb->insert( $table, array('user_id'=>$u,'product_id'=>$p,'ts'=>current_time('mysql',1)), array('%d','%d','%s') );
                if ( false !== $ok ) $inserted++;
            }
            foreach ( array_keys($orphan) as $key ) {
                list($u,$p) = array_map('intval', explode(':', $key));
                $ok = $wpdb->delete( $table, array('user_id'=>$u,'product_id'=>$p), array('%d','%d') );
                if ( false !== $ok ) $deleted++;
            }
            \WP_CLI::success( sprintf('Fix complete. Inserted %d, deleted %d.', $inserted, $deleted) );
        }
    }

    \WP_CLI::add_command( 'vod', 'DSI_VOD_CLI' );
}

