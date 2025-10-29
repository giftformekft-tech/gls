<?php
/**
 * WooCommerce Checkout Block Integration
 * Implements Checkout Block Extensibility API for parcelshop selector
 */

namespace MyGLS\Blocks;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

if (!defined('ABSPATH')) {
    exit;
}

class CheckoutIntegration implements IntegrationInterface {

    /**
     * The name of the integration.
     *
     * @return string
     */
    public function get_name() {
        return 'mygls-parcelshop';
    }

    /**
     * When called invokes any initialization/setup for the integration.
     */
    public function initialize() {
        $this->register_checkout_block_frontend_scripts();
        $this->register_checkout_block_editor_scripts();
        $this->register_main_integration();

        // Register Store API endpoint extensions
        $this->extend_store_api();
    }

    /**
     * Register scripts for the checkout block frontend
     */
    private function register_checkout_block_frontend_scripts() {
        $script_path = '/assets/js/checkout-block.js';
        $script_url = plugins_url($script_path, dirname(__DIR__, 1));
        $script_asset_path = dirname(__DIR__, 1) . '/assets/js/checkout-block.asset.php';

        // Check if asset file exists, otherwise use defaults
        $script_asset = file_exists($script_asset_path)
            ? require($script_asset_path)
            : ['dependencies' => [], 'version' => filemtime(dirname(__DIR__, 1) . $script_path)];

        wp_register_script(
            'mygls-checkout-block-frontend',
            $script_url,
            array_merge($script_asset['dependencies'], [
                'wc-blocks-checkout',
                'wc-settings',
                'wp-element',
                'wp-i18n',
                'wp-data',
                'wp-hooks'
            ]),
            $script_asset['version'],
            true
        );

        // Localize script with settings
        $settings = get_option('mygls_settings', []);
        wp_localize_script('mygls-checkout-block-frontend', 'myglsCheckoutBlock', [
            'country' => strtolower($settings['country'] ?? 'hu'),
            'language' => strtolower($settings['language'] ?? ''),
            'enabledMethods' => $settings['parcelshop_enabled_methods'] ?? [],
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mygls_checkout_nonce'),
            'i18n' => [
                'selectParcelshop' => __('Select GLS Parcelshop', 'mygls-woocommerce'),
                'parcelshopRequired' => __('Please select a parcelshop', 'mygls-woocommerce'),
                'selectedParcelshop' => __('Selected Parcelshop', 'mygls-woocommerce'),
                'changeParcelshop' => __('Change', 'mygls-woocommerce'),
                'parcelshopDelivery' => __('Parcel Shop Delivery', 'mygls-woocommerce'),
            ]
        ]);
    }

    /**
     * Register scripts for the checkout block editor
     */
    private function register_checkout_block_editor_scripts() {
        $script_path = '/assets/js/checkout-block.js';
        $script_url = plugins_url($script_path, dirname(__DIR__, 1));

        wp_register_script(
            'mygls-checkout-block-editor',
            $script_url,
            [
                'wc-blocks-checkout',
                'wp-element',
                'wp-i18n'
            ],
            filemtime(dirname(__DIR__, 1) . $script_path),
            true
        );
    }

    /**
     * Returns an array of script handles to enqueue in the frontend context.
     *
     * @return string[]
     */
    public function get_script_handles() {
        return ['mygls-checkout-block-frontend'];
    }

    /**
     * Returns an array of script handles to enqueue in the editor context.
     *
     * @return string[]
     */
    public function get_editor_script_handles() {
        return ['mygls-checkout-block-editor'];
    }

    /**
     * An array of key, value pairs of data made available to the block on the client side.
     *
     * @return array
     */
    public function get_script_data() {
        $settings = get_option('mygls_settings', []);

        return [
            'country' => strtolower($settings['country'] ?? 'hu'),
            'language' => strtolower($settings['language'] ?? ''),
            'enabledMethods' => $settings['parcelshop_enabled_methods'] ?? [],
        ];
    }

    /**
     * Register the main integration with WooCommerce Blocks
     */
    private function register_main_integration() {
        add_action(
            'woocommerce_blocks_checkout_block_registration',
            function($integration_registry) {
                $integration_registry->register($this);
            }
        );
    }

    /**
     * Extend the Store API to include parcelshop data
     */
    private function extend_store_api() {
        // Only extend if Store API is available
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }

        // Register extension data for checkout
        woocommerce_store_api_register_endpoint_data([
            'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
            'namespace' => 'mygls',
            'data_callback' => [$this, 'extend_checkout_data'],
            'schema_callback' => [$this, 'extend_checkout_schema'],
            'schema_type' => ARRAY_A,
        ]);

        // Save parcelshop data when order is processed
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'save_parcelshop_to_order'], 10, 2);
    }

    /**
     * Extend checkout data callback
     */
    public function extend_checkout_data() {
        $selected_parcelshop = WC()->session ? WC()->session->get('mygls_selected_parcelshop') : null;

        return [
            'parcelshop' => $selected_parcelshop,
        ];
    }

    /**
     * Extend checkout schema
     */
    public function extend_checkout_schema() {
        return [
            'parcelshop' => [
                'description' => __('Selected GLS parcelshop', 'mygls-woocommerce'),
                'type' => ['object', 'null'],
                'context' => ['view', 'edit'],
                'readonly' => false,
            ],
        ];
    }

    /**
     * Save parcelshop data to order
     */
    public function save_parcelshop_to_order($order, $request) {
        $data = $request->get_param('extensions');

        if (isset($data['mygls']['parcelshop']) && !empty($data['mygls']['parcelshop'])) {
            $parcelshop = $data['mygls']['parcelshop'];

            // Save to order meta
            $order->update_meta_data('_mygls_parcelshop_id', $parcelshop['id'] ?? '');
            $order->update_meta_data('_mygls_parcelshop_data', $parcelshop);

            // Save to session as well for consistency
            if (WC()->session) {
                WC()->session->set('mygls_selected_parcelshop', $parcelshop);
            }

            // Add order note
            if (!empty($parcelshop['name'])) {
                $order->add_order_note(
                    sprintf(
                        __('GLS Parcelshop selected: %s - %s', 'mygls-woocommerce'),
                        $parcelshop['name'] ?? '',
                        $parcelshop['address'] ?? ''
                    )
                );
            }
        }
    }
}
