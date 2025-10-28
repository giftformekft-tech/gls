<?php
/**
 * MyGLS Shipping Method
 */

namespace MyGLS\Shipping;

if (!defined('ABSPATH')) {
    exit;
}

class Method extends \WC_Shipping_Method {
    
    public function __construct($instance_id = 0) {
        $this->id = 'mygls';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('GLS Shipping', 'mygls-woocommerce');
        $this->method_description = __('GLS shipping with parcelshop support', 'mygls-woocommerce');
        $this->supports = [
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        ];
        
        $this->init();
    }
    
    public function init() {
        $this->init_form_fields();
        $this->init_settings();
        
        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        
        add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
    }
    
    public function init_form_fields() {
        $this->instance_form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'mygls-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable this shipping method', 'mygls-woocommerce'),
                'default' => 'yes'
            ],
            'title' => [
                'title' => __('Method Title', 'mygls-woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'mygls-woocommerce'),
                'default' => __('GLS Shipping', 'mygls-woocommerce'),
                'desc_tip' => true
            ],
            'shipping_type' => [
                'title' => __('Shipping Type', 'mygls-woocommerce'),
                'type' => 'select',
                'default' => 'home',
                'options' => [
                    'home' => __('Home Delivery', 'mygls-woocommerce'),
                    'parcelshop' => __('ParcelShop Delivery', 'mygls-woocommerce')
                ]
            ],
            'cost' => [
                'title' => __('Cost', 'mygls-woocommerce'),
                'type' => 'text',
                'description' => __('Shipping cost. Leave empty to calculate dynamically.', 'mygls-woocommerce'),
                'default' => '0',
                'desc_tip' => true
            ],
            'free_shipping_threshold' => [
                'title' => __('Free Shipping Threshold', 'mygls-woocommerce'),
                'type' => 'text',
                'description' => __('Order total for free shipping. Leave empty to disable.', 'mygls-woocommerce'),
                'default' => '',
                'desc_tip' => true
            ],
            'tax_status' => [
                'title' => __('Tax Status', 'mygls-woocommerce'),
                'type' => 'select',
                'default' => 'taxable',
                'options' => [
                    'taxable' => __('Taxable', 'mygls-woocommerce'),
                    'none' => __('None', 'mygls-woocommerce')
                ]
            ]
        ];
    }
    
    public function calculate_shipping($package = []) {
        $cost = $this->get_option('cost', 0);
        $free_threshold = $this->get_option('free_shipping_threshold', '');
        
        // Check for free shipping
        if (!empty($free_threshold)) {
            $cart_total = WC()->cart->get_displayed_subtotal();
            
            if ($cart_total >= floatval($free_threshold)) {
                $cost = 0;
            }
        }
        
        // Add the rate
        $this->add_rate([
            'id' => $this->id . ':' . $this->instance_id,
            'label' => $this->title,
            'cost' => $cost,
            'package' => $package,
        ]);
    }
    
    /**
     * Check if this method uses parcelshop
     */
    public function is_parcelshop_delivery() {
        return $this->get_option('shipping_type') === 'parcelshop';
    }
}