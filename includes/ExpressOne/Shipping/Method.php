<?php
/**
 * Express One Shipping Method
 */

namespace ExpressOne\Shipping;

if (!defined('ABSPATH')) {
    exit;
}

class Method extends \WC_Shipping_Method {
    
    public function __construct($instance_id = 0) {
        $this->id = 'expressone';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Express One Szállítás', 'mygls-woocommerce');
        $this->method_description = __('Express One szállítás csomagpont (pick-up point) támogatással', 'mygls-woocommerce');
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
                'title' => __('Engedélyezés/Letiltás', 'mygls-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Szállítási mód engedélyezése', 'mygls-woocommerce'),
                'default' => 'yes'
            ],
            'title' => [
                'title' => __('Megnevezés', 'mygls-woocommerce'),
                'type' => 'text',
                'description' => __('Ezt a nevet látja a vásárló a pénztár oldalon.', 'mygls-woocommerce'),
                'default' => __('Express One Szállítás', 'mygls-woocommerce'),
                'desc_tip' => true
            ],
            'shipping_type' => [
                'title' => __('Szállítási típus', 'mygls-woocommerce'),
                'type' => 'select',
                'description' => __('Válaszd a Csomagpontos szállítást, hogy a vásárló választhasson a térképről.', 'mygls-woocommerce'),
                'default' => 'home',
                'desc_tip' => true,
                'options' => [
                    'home' => __('Házhozszállítás', 'mygls-woocommerce'),
                    'parcelshop' => __('Csomagpontos szállítás (térképes választóval)', 'mygls-woocommerce')
                ]
            ],
            'cost' => [
                'title' => __('Költség', 'mygls-woocommerce'),
                'type' => 'text',
                'description' => __('Szállítási költség.', 'mygls-woocommerce'),
                'default' => '0',
                'desc_tip' => true
            ],
            'free_shipping_threshold' => [
                'title' => __('Ingyenes szállítás értékhatára', 'mygls-woocommerce'),
                'type' => 'text',
                'description' => __('Rendelési érték, ami felett ingyenes a szállítás. Hagyd üresen a kikapcsoláshoz.', 'mygls-woocommerce'),
                'default' => '',
                'desc_tip' => true
            ],
            'tax_status' => [
                'title' => __('Adózási státusz', 'mygls-woocommerce'),
                'type' => 'select',
                'default' => 'taxable',
                'options' => [
                    'taxable' => __('Adóköteles', 'mygls-woocommerce'),
                    'none' => __('Nincs', 'mygls-woocommerce')
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
