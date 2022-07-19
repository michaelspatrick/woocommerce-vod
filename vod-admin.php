<?php
class WooCommerceVODSettingsPage
{
    /**
     * Holds the values to be used in the fields callbacks
     */
    private $options;

    /**
     * Start up
     */
    public function __construct()
    {
        add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'page_init' ) );
    }

    /**
     * Add options page
     */
    public function add_plugin_page()
    {
        // This page will be under "Settings"
        add_options_page(
            'WooCommerce VOD Settings Admin', 
            'WooCommerce VOD Settings', 
            'manage_options', 
            'vod-admin', 
            array( $this, 'create_admin_page' )
        );
    }

    /**
     * Options page callback
     */
    public function create_admin_page()
    {
        // Set class property
        $this->options = get_option( 'vod_category_id' );
        ?>
        <div class="wrap">
            <h2>WooCommerce VOD Settings</h2>           
            <form method="post" action="options.php">
            <?php
                // This prints out all hidden setting fields
                settings_fields( 'vod_category_group' );   
                do_settings_sections( 'vod-admin' );
                submit_button(); 
            ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register and add settings
     */
    public function page_init()
    {        
        register_setting(
            'vod_category_group', // Option group
            'vod_category_id', // Option name
            array( $this, 'sanitize' ) // Sanitize
        );

        add_settings_section(
            'setting_section_id', // ID
            'Video-On-Demand Settings', // Title
            array( $this, 'print_section_info' ), // Callback
            'vod-admin' // Page
        );  

        add_settings_field(
            'vod_category_id', // ID
            'VOD Product Category ID', // Title 
            array( $this, 'vod_category_id_callback' ), // Callback
            'vod-admin', // Page
            'setting_section_id' // Section           
        );      
    }

    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize( $input )
    {
        $new_input = array();
        if( isset( $input['vod_category_id'] ) )
            $new_input['vod_category_id'] = absint( $input['vod_category_id'] );

        return $new_input;
    }

    /** 
     * Print the Section text
     */
    public function print_section_info()
    {
        print 'Enter the product category ID number for Video-On-Demand:';
    }

    /** 
     * Get the settings option array and print one of its values
     */
    public function vod_category_id_callback()
    {
        printf(
            '<input type="text" id="vod_category_id" name="vod_category_id[vod_category_id]" value="%s" />',
            isset( $this->options['vod_category_id'] ) ? esc_attr( $this->options['vod_category_id']) : ''
        );
    }
}

if( is_admin() )
    $woocommerce_vod_page = new WooCommerceVODSettingsPage();

