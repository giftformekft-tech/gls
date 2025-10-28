<?php
/**
 * WooCommerce Blocks Integration for Parcelshop Selector
 * Adds parcelshop selection support to block-based checkout
 */

namespace MyGLS\Parcelshop;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

if (!defined('ABSPATH')) {
    exit;
}

class BlocksIntegration implements IntegrationInterface {

    /**
     * The name of the integration.
     */
    public function get_name() {
        return 'mygls-parcelshop';
    }

    /**
     * When called invokes any initialization/setup for the integration.
     */
    public function initialize() {
        $this->register_block_editor_script();
        $this->register_block_frontend_script();
        $this->extend_store_api();
    }

    /**
     * Register script for block editor
     */
    private function register_block_editor_script() {
        $script_path = '/assets/js/blocks/parcelshop-block.js';
        $script_url = MYGLS_PLUGIN_URL . 'assets/js/blocks/parcelshop-block.js';
        $script_asset_path = MYGLS_PLUGIN_DIR . 'assets/js/blocks/parcelshop-block.asset.php';

        $script_asset = file_exists($script_asset_path)
            ? require $script_asset_path
            : ['dependencies' => [], 'version' => MYGLS_VERSION];

        wp_register_script(
            'mygls-parcelshop-blocks-integration',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );
    }

    /**
     * Register script for block frontend
     */
    private function register_block_frontend_script() {
        $script_url = MYGLS_PLUGIN_URL . 'assets/js/parcelshop-map.js';

        wp_register_script(
            'mygls-parcelshop-frontend',
            $script_url,
            ['jquery', 'wp-element', 'wp-api-fetch'],
            MYGLS_VERSION,
            true
        );

        wp_localize_script('mygls-parcelshop-frontend', 'myglsCheckout', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mygls_checkout_nonce'),
            'i18n' => [
                'selectParcelshop' => __('Select Parcelshop', 'mygls-woocommerce'),
                'searching' => __('Searching...', 'mygls-woocommerce'),
                'noResults' => __('No parcelshops found', 'mygls-woocommerce'),
                'error' => __('Error loading parcelshops', 'mygls-woocommerce')
            ]
        ]);
    }

    /**
     * Extend Store API to include parcelshop data
     */
    private function extend_store_api() {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }

        woocommerce_store_api_register_endpoint_data([
            'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
            'namespace' => 'mygls-parcelshop',
            'data_callback' => [$this, 'extend_checkout_data'],
            'schema_callback' => [$this, 'extend_checkout_schema'],
        ]);
    }

    /**
     * Extend checkout data
     */
    public function extend_checkout_data() {
        $selected_parcelshop = WC()->session ? WC()->session->get('mygls_selected_parcelshop') : null;

        return [
            'selected_parcelshop' => $selected_parcelshop ?: null,
        ];
    }

    /**
     * Extend checkout schema
     */
    public function extend_checkout_schema() {
        return [
            'selected_parcelshop' => [
                'description' => __('Selected GLS Parcelshop', 'mygls-woocommerce'),
                'type' => ['object', 'null'],
                'readonly' => true,
            ],
        ];
    }

    /**
     * Returns an array of script handles to enqueue in the frontend context.
     */
    public function get_script_handles() {
        return ['mygls-parcelshop-blocks-integration', 'mygls-parcelshop-frontend'];
    }

    /**
     * Returns an array of script handles to enqueue in the editor context.
     */
    public function get_editor_script_handles() {
        return ['mygls-parcelshop-blocks-integration'];
    }

    /**
     * An array of key, value pairs of data made available to the block on the client side.
     */
    public function get_script_data() {
        $settings = get_option('mygls_settings', []);

        return [
            'enabled_methods' => $settings['parcelshop_enabled_methods'] ?? [],
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mygls_checkout_nonce'),
        ];
    }
}
