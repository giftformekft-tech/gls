<?php
/**
 * Plugin Name: MyGLS WooCommerce Integration
 * Plugin URI: https://github.com/yourusername/mygls-woocommerce
 * Description: Teljes MyGLS API integráció WooCommerce-hez interaktív térképes csomagpont választóval, automatikus és bulk címkegenerálással, valamint valós idejű státusz követéssel.
 * Version: 1.0.6
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mygls-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MYGLS_VERSION', '1.0.6');
define('MYGLS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MYGLS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MYGLS_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Check if WooCommerce is active
 */
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>';
        echo __('MyGLS WooCommerce Integration requires WooCommerce to be installed and active.', 'mygls-woocommerce');
        echo '</p></div>';
    });
    return;
}

/**
 * Autoloader for plugin classes
 */
spl_autoload_register(function($class) {
    // Check if the class belongs to our plugin
    if (strpos($class, 'MyGLS\\') !== 0) {
        return;
    }

    // Remove namespace prefix
    $class = str_replace('MyGLS\\', '', $class);

    // Convert namespace separators to directory separators
    $class = str_replace('\\', '/', $class);

    // Build the file path
    $file = MYGLS_PLUGIN_DIR . 'includes/' . $class . '.php';

    // If the file exists, load it
    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Declare WooCommerce HPOS compatibility
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Initialize the plugin
 */
function mygls_init() {
    // Load text domain for translations
    load_plugin_textdomain('mygls-woocommerce', false, dirname(MYGLS_PLUGIN_BASENAME) . '/languages');

    // Initialize API Client
    if (class_exists('MyGLS\\API\\Client')) {
        // API Client is loaded on demand
    }

    // Initialize Admin Settings
    if (is_admin() && class_exists('MyGLS\\Admin\\Settings')) {
        new MyGLS\Admin\Settings();
    }

    // Initialize Order MetaBox
    if (is_admin() && class_exists('MyGLS\\Admin\\OrderMetaBox')) {
        new MyGLS\Admin\OrderMetaBox();
    }

    // Initialize Bulk Actions
    if (is_admin() && class_exists('MyGLS\\Admin\\BulkActions')) {
        new MyGLS\Admin\BulkActions();
    }

    // Initialize Parcelshop Selector
    if (class_exists('MyGLS\\Parcelshop\\Selector')) {
        new MyGLS\Parcelshop\Selector();
    }

    // Register WooCommerce Blocks integration
    add_action('woocommerce_blocks_checkout_block_registration', 'mygls_register_blocks_integration');
}
add_action('plugins_loaded', 'mygls_init');

/**
 * Register parcelshop selector with WooCommerce Blocks
 */
function mygls_register_blocks_integration($integration_registry) {
    if (!class_exists('MyGLS\\Parcelshop\\BlocksIntegration')) {
        return;
    }

    $integration_registry->register(new MyGLS\Parcelshop\BlocksIntegration());
}


/**
 * Register shipping method
 */
function mygls_register_shipping_method($methods) {
    require_once MYGLS_PLUGIN_DIR . 'includes/Shipping/Method.php';
    $methods['mygls_shipping'] = 'MyGLS\\Shipping\\Method';
    return $methods;
}
add_filter('woocommerce_shipping_methods', 'mygls_register_shipping_method');

/**
 * Get API Client instance
 */
function mygls_get_api_client() {
    if (!class_exists('MyGLS\\API\\Client')) {
        return null;
    }
    return new MyGLS\API\Client();
}

/**
 * Get plugin settings
 */
function mygls_get_settings() {
    return get_option('mygls_settings', []);
}

/**
 * Plugin activation hook
 */
function mygls_activate() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'mygls_labels';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        order_id bigint(20) NOT NULL,
        parcel_id bigint(20) NOT NULL,
        parcel_number bigint(20) NOT NULL,
        tracking_url varchar(255) DEFAULT NULL,
        label_pdf longblob,
        status varchar(50) DEFAULT 'pending',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY order_id (order_id),
        KEY parcel_number (parcel_number)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Set default options
    if (!get_option('mygls_settings')) {
        update_option('mygls_settings', array(
            'country' => 'HU',
            'auto_generate_labels' => false,
            'auto_status_sync' => false,
            'sync_interval' => 60,
            'printer_type' => 'A4_2x2'
        ));
    }
}
register_activation_hook(__FILE__, 'mygls_activate');

/**
 * Plugin deactivation hook
 */
function mygls_deactivate() {
    // Clean up scheduled events
    wp_clear_scheduled_hook('mygls_sync_statuses');
}
register_deactivation_hook(__FILE__, 'mygls_deactivate');

/**
 * Enqueue admin styles and scripts
 */
function mygls_admin_enqueue_scripts($hook) {
    // Load on plugin settings page, order edit pages (both traditional and HPOS)
    $allowed_hooks = [
        'toplevel_page_mygls-settings',
        'post.php',
        'edit.php',
        'woocommerce_page_wc-orders', // HPOS order list
    ];

    // Also check if we're on an order edit page with HPOS
    $is_order_page = false;
    if (isset($_GET['page']) && $_GET['page'] === 'wc-orders' && isset($_GET['action']) && $_GET['action'] === 'edit') {
        $is_order_page = true;
    }

    if (!in_array($hook, $allowed_hooks) && !$is_order_page) {
        return;
    }

    wp_enqueue_style(
        'mygls-admin-css',
        MYGLS_PLUGIN_URL . 'assets/css/admin.css',
        array(),
        MYGLS_VERSION
    );

    wp_enqueue_script(
        'mygls-admin-js',
        MYGLS_PLUGIN_URL . 'assets/js/admin.js',
        array('jquery'),
        MYGLS_VERSION,
        true
    );

    wp_localize_script('mygls-admin-js', 'myglsAdmin', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('mygls_admin_nonce'),
        'i18n' => array(
            'processing' => __('Processing...', 'mygls-woocommerce'),
            'error' => __('Connection failed', 'mygls-woocommerce'),
            'confirmGenerate' => __('Are you sure you want to generate a shipping label?', 'mygls-woocommerce'),
            'confirmDelete' => __('Are you sure you want to delete this label? This action cannot be undone.', 'mygls-woocommerce')
        )
    ));
}
add_action('admin_enqueue_scripts', 'mygls_admin_enqueue_scripts');

/**
 * Enqueue frontend styles and scripts
 */
function mygls_frontend_enqueue_scripts() {
    // Only load on checkout page
    if (!is_checkout()) {
        return;
    }

    wp_enqueue_style(
        'mygls-frontend-css',
        MYGLS_PLUGIN_URL . 'assets/css/frontend.css',
        array(),
        MYGLS_VERSION
    );

    // Leaflet CSS for map
    wp_enqueue_style(
        'leaflet-css',
        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
        array(),
        '1.9.4'
    );

    // Leaflet JS for map
    wp_enqueue_script(
        'leaflet-js',
        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
        array(),
        '1.9.4',
        true
    );

    wp_enqueue_script(
        'mygls-parcelshop-map',
        MYGLS_PLUGIN_URL . 'assets/js/parcelshop-map.js',
        array('jquery', 'leaflet-js'),
        MYGLS_VERSION,
        true
    );

    wp_localize_script('mygls-parcelshop-map', 'myglsCheckout', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('mygls_checkout_nonce'),
        'i18n' => array(
            'searching' => __('Searching...', 'mygls-woocommerce'),
            'noResults' => __('No parcelshops found', 'mygls-woocommerce'),
            'error' => __('Error loading parcelshops', 'mygls-woocommerce')
        )
    ));
}
add_action('wp_enqueue_scripts', 'mygls_frontend_enqueue_scripts');

/**
 * Add settings link to plugin page
 */
function mygls_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=mygls-settings') . '">' . __('Settings', 'mygls-woocommerce') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . MYGLS_PLUGIN_BASENAME, 'mygls_plugin_action_links');

/**
 * Logging function
 */
function mygls_log($message, $level = 'info') {
    if (function_exists('wc_get_logger')) {
        $logger = wc_get_logger();
        $logger->log($level, $message, array('source' => 'mygls'));
    }
}
