<?php
/**
 * WooCommerce VOD — Settings Page (modernized)
 * - PHP 8.2+ safe (no dynamic properties)
 * - Uses WordPress Settings API with sanitize_callback
 * - Escaped output and capability checks
 * - Lets you choose a WooCommerce product category (product_cat) used for VOD
 *
 * Adds submenu under WooCommerce: WooCommerce → VOD Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WooCommerce_VOD_Settings_Page' ) ) {

    class WooCommerce_VOD_Settings_Page {

        /** @var array<string,mixed> */
        private array $options = [];

        public function __construct() {
            // Load current option snapshot
            $this->options = get_option( 'dsi_vod_settings', [] );
            if ( ! is_array( $this->options ) ) {
                $this->options = [];
            }

            add_action( 'admin_menu', [ $this, 'add_plugin_page' ] );
            add_action( 'admin_init', [ $this, 'page_init' ] );
        }

        /**
         * Add submenu under WooCommerce.
         */
        public function add_plugin_page(): void {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                return;
            }
            add_submenu_page(
                'woocommerce',
                __( 'VOD Settings', 'woocommerce_vod' ),
                __( 'VOD Settings', 'woocommerce_vod' ),
                'manage_woocommerce',
                'dsi-vod-settings',
                [ $this, 'create_admin_page' ]
            );
        }

        /**
         * Render settings page.
         */
        public function create_admin_page(): void {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'You do not have permission to access this page.', 'woocommerce_vod' ) );
            }

            // Refresh snapshot (in case updates just happened)
            $this->options = get_option( 'dsi_vod_settings', [] );
            if ( ! is_array( $this->options ) ) {
                $this->options = [];
            }

            echo '<div class="wrap">';
            echo '<h1>' . esc_html__( 'WooCommerce VOD Settings', 'woocommerce_vod' ) . '</h1>';
            echo '<form method="post" action="options.php">';

            settings_fields( 'dsi_vod_settings_group' );
            do_settings_sections( 'dsi-vod-settings-admin' );
            submit_button();

            echo '</form>';
            echo '</div>';
        }

        /**
         * Register settings, sections, and fields.
         */
        public function page_init(): void {

            register_setting(
                'dsi_vod_settings_group',
                'dsi_vod_settings',
                [
                    'type'              => 'array',
                    'sanitize_callback' => [ $this, 'sanitize' ],
                    'default'           => [],
                    'show_in_rest'      => false,
                ]
            );

            add_settings_section(
                'dsi_vod_main_section',
                __( 'General', 'woocommerce_vod' ),
                function () {
                    echo '<p>' . esc_html__( 'Configure how your streaming video (VOD) products are organized and discovered.', 'woocommerce_vod' ) . '</p>';
                },
                'dsi-vod-settings-admin'
            );

            add_settings_field(
                'vod_category_id',
                __( 'VOD Product Category', 'woocommerce_vod' ),
                [ $this, 'vod_category_field_cb' ],
                'dsi-vod-settings-admin',
                'dsi_vod_main_section',
                [
                    'label_for' => 'vod_category_id',
                    'class'     => 'dsi-row',
                ]
            );

            add_settings_field(
                'vod_endpoint_slug',
                __( 'My Account Endpoint Slug', 'woocommerce_vod' ),
                [ $this, 'vod_endpoint_slug_field_cb' ],
                'dsi-vod-settings-admin',
                'dsi_vod_main_section',
                [
                    'label_for' => 'vod_endpoint_slug',
                    'class'     => 'dsi-row',
                ]
            );
        }

        /**
         * Sanitize all fields.
         *
         * @param mixed $input
         * @return array<string,mixed>
         */
        public function sanitize( $input ): array {
            $clean = [];

            if ( is_array( $input ) ) {
                // Category ID
                if ( isset( $input['vod_category_id'] ) ) {
                    $clean['vod_category_id'] = absint( $input['vod_category_id'] );
                }

                // Endpoint slug
                if ( isset( $input['vod_endpoint_slug'] ) ) {
                    $slug = sanitize_title( (string) $input['vod_endpoint_slug'] );
                    $clean['vod_endpoint_slug'] = ( $slug !== '' ) ? $slug : 'vod';
                }
            }

            return $clean;
        }

        /**
         * Field: VOD category selector (product_cat terms).
         */
        public function vod_category_field_cb( array $args ): void {
            $current = isset( $this->options['vod_category_id'] ) ? absint( $this->options['vod_category_id'] ) : 0;

            // Fetch categories (can be large; limit depth for performance or implement AJAX select if needed)
            $terms = get_terms( [
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'number'     => 0,
            ] );

            echo '<select id="' . esc_attr( $args['label_for'] ) . '" name="dsi_vod_settings[vod_category_id]">';
            echo '<option value="0">' . esc_html__( '— Select a category —', 'woocommerce_vod' ) . '</option>';

            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                foreach ( $terms as $term ) {
                    if ( ! $term instanceof WP_Term ) { continue; }
                    printf(
                        '<option value="%d"%s>%s</option>',
                        (int) $term->term_id,
                        selected( $current, (int) $term->term_id, false ),
                        esc_html( $term->name . ' (ID ' . $term->term_id . ')' )
                    );
                }
            }

            echo '</select>';
            echo '<p class="description">' . esc_html__( 'Pick the WooCommerce category that groups your streaming videos.', 'woocommerce_vod' ) . '</p>';
        }

        /**
         * Field: Endpoint slug for My Account page (align with my-account.php filter).
         */
        public function vod_endpoint_slug_field_cb( array $args ): void {
            $slug = isset( $this->options['vod_endpoint_slug'] ) ? (string) $this->options['vod_endpoint_slug'] : 'vod';
            echo '<input type="text" id="' . esc_attr( $args['label_for'] ) . '" name="dsi_vod_settings[vod_endpoint_slug]" value="' . esc_attr( $slug ) . '" class="regular-text" />';
            echo '<p class="description">' . esc_html__( 'Slug used for the “Streaming Video” tab under My Account (e.g., vod).', 'woocommerce_vod' ) . '</p>';
        }
    }
}

// Bootstrap
add_action( 'plugins_loaded', function() {
    // Only load in admin
    if ( is_admin() ) {
        new WooCommerce_VOD_Settings_Page();
    }
} );
